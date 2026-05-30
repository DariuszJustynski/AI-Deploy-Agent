<?php
/**
 * ai-deploy.php  — v3
 * AI Deploy Agent · Config-driven deployment engine.
 *
 * Token loaded from config.php — no hardcoded secrets.
 * Installed by install-ai-deploy.php automatically.
 *
 * Supported POST actions:
 *   upload          — receive & queue a ZIP package
 *   install         — extract the latest (or configured) package
 *   rollback        — restore the latest backup
 *   status          — return config + state JSON
 *   logs            — return last N log lines
 *   update-config   — remotely update safe config fields
 *   clean_root_dry  — list files that would be deleted (no changes)
 *   clean_root      — delete all non-protected files from targetRoot
 */

// ── Token & install metadata loaded from config.php ──────────
require_once __DIR__ . '/config.php';

// ═══════════════════════════════════════════════════════════════
// OPERATIONAL CONSTANTS
// ═══════════════════════════════════════════════════════════════

define('MAX_UPLOAD_BYTES', 64 * 1024 * 1024);
define('LOG_MAX_LINES',    500);

// Fields that may be updated via the update-config action
define('MUTABLE_CONFIG_FIELDS', ['projectName', 'latestPackage', 'deploymentMode']);

// Valid values for deploymentMode
define('VALID_DEPLOY_MODES', ['upload_only', 'upload_install', 'manual']);

// ═══════════════════════════════════════════════════════════════
// BOOTSTRAP — load config, resolve all paths
// ═══════════════════════════════════════════════════════════════

$BASE        = __DIR__;           // /_deploy/ directory
$CONFIG_FILE = $BASE . '/deploy-config.json';
$STATE_FILE  = $BASE . '/_state/deploy-state.json';  // v3: state lives in _state/

// Default config — used when deploy-config.json is missing
$DEFAULT_CONFIG = [
    'projectName'    => 'my-website',
    'targetRoot'     => '',            // empty = dirname(__DIR__)
    'packageDir'     => '_packages',   // relative to $BASE
    'backupDir'      => '_backups',
    'logsDir'        => '_logs',
    'incomingDir'    => '_incoming',
    'latestPackage'  => '',
    'deploymentMode' => 'upload_install',
    'maxBackups'     => 10,
    'maxPackages'    => 5,
    'preserveFiles'  => [
        'ai-deploy.php',
        'panel.php',
        'config.php',
        'deploy-config.json',
    ],
];

// Default state
$DEFAULT_STATE = [
    'lastDeployment'   => null,
    'lastPackage'      => null,
    'lastRollback'     => null,
    'deploymentStatus' => 'pending',
    'currentVersion'   => null,
    'lastError'        => null,
    'lastBackup'       => null,
    'deployCount'      => 0,
    'rollbackCount'    => 0,
    'updatedAt'        => null,
];

/** Load deploy-config.json, creating it with defaults if absent. */
function loadConfig(): array {
    global $CONFIG_FILE, $DEFAULT_CONFIG;
    if (!file_exists($CONFIG_FILE)) {
        @file_put_contents($CONFIG_FILE, json_encode($DEFAULT_CONFIG, JSON_PRETTY_PRINT));
        return $DEFAULT_CONFIG;
    }
    $raw  = @file_get_contents($CONFIG_FILE);
    $data = json_decode($raw, true);
    return array_merge($DEFAULT_CONFIG, is_array($data) ? $data : []);
}

/**
 * Resolve a config path value.
 * Empty → $fallback | Relative → $base/$value | Absolute → as-is
 */
function resolveDir(string $value, string $fallback, string $base): string {
    $v = trim($value);
    if ($v === '') return $fallback;
    if ($v[0] === '/' || preg_match('/^[A-Za-z]:[\\/]/', $v)) return rtrim($v, '/\\');
    return $base . '/' . ltrim($v, '/');
}

// Resolve operational paths from config
$config     = loadConfig();
$PARENT     = resolveDir($config['targetRoot'],  dirname($BASE), $BASE);
$DIR_PKG    = resolveDir($config['packageDir'],  $BASE . '/_packages',  $BASE);
$DIR_BACKUP = resolveDir($config['backupDir'],   $BASE . '/_backups',   $BASE);
$DIR_LOGS   = resolveDir($config['logsDir'],     $BASE . '/_logs',      $BASE);
$DIR_TMP    = resolveDir($config['incomingDir'], $BASE . '/_incoming',  $BASE);
$LOG_FILE   = $DIR_LOGS . '/deploy.log';
$MAX_BKUP   = max(1, (int)($config['maxBackups']  ?? 10));
$MAX_PKGS   = max(1, (int)($config['maxPackages'] ?? 5));

// Ensure directories exist + block web access
foreach ([$DIR_TMP, $DIR_PKG, $DIR_BACKUP, $DIR_LOGS, $BASE . '/_state'] as $_dir) {
    if (!is_dir($_dir)) @mkdir($_dir, 0755, true);
    $_ht = $_dir . '/.htaccess';
    if (!file_exists($_ht)) @file_put_contents($_ht, "Deny from all\n");
}

// ═══════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════

/** Send JSON response and exit. */
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Append a timestamped line to deploy.log, rotate when needed. */
function writeLog(string $message): void {
    global $LOG_FILE, $DIR_LOGS, $LOG_MAX_LINES;
    if (!is_dir($DIR_LOGS)) @mkdir($DIR_LOGS, 0755, true);
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ts   = date('Y-m-d H:i:s');
    $line = "[{$ts}] [IP:{$ip}] {$message}";
    if (file_exists($LOG_FILE)) {
        $lines = file($LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) >= $LOG_MAX_LINES) {
            $lines = array_slice($lines, -($LOG_MAX_LINES - 50));
            file_put_contents($LOG_FILE, implode("\n", $lines) . "\n");
        }
    }
    file_put_contents($LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Load deploy-state.json, initialising with defaults if missing. */
function loadState(): array {
    global $STATE_FILE, $DEFAULT_STATE;
    if (!file_exists($STATE_FILE)) {
        @file_put_contents($STATE_FILE, json_encode($DEFAULT_STATE, JSON_PRETTY_PRINT));
        return $DEFAULT_STATE;
    }
    $raw  = @file_get_contents($STATE_FILE);
    $data = json_decode($raw, true);
    return array_merge($DEFAULT_STATE, is_array($data) ? $data : []);
}

/** Merge $updates into deploy-state.json and persist. */
function saveState(array $updates): void {
    global $STATE_FILE;
    $state = loadState();
    $state = array_merge($state, $updates);
    $state['updatedAt'] = date('Y-m-d H:i:s');
    @file_put_contents($STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/** Return newest ZIP path in $dir, or null. */
function newestZip(string $dir): ?string {
    $files = glob($dir . '/*.zip') ?: [];
    if (empty($files)) return null;
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    return $files[0];
}

/** Decide which package to install: config-pinned or newest uploaded. */
function resolveInstallPackage(array $config, string $pkgDir): ?string {
    $pinned = trim($config['latestPackage'] ?? '');
    if ($pinned !== '') {
        $candidate = $pkgDir . '/' . basename($pinned);
        if (file_exists($candidate)) return $candidate;
    }
    return newestZip($pkgDir);
}

/** Validate all ZIP entries for path safety. Returns array of problem strings. */
function validateZipEntries(ZipArchive $zip, string $deployRoot): array {
    $problems = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (strpos($entry, '../') !== false || strpos($entry, '..\\') !== false) {
            $problems[] = "Path traversal: {$entry}"; continue;
        }
        if ($entry !== '' && ($entry[0] === '/' || preg_match('/^[A-Za-z]:[\\/]/', $entry))) {
            $problems[] = "Absolute path: {$entry}"; continue;
        }
        $norm = str_replace('\\', '/', $deployRoot . '/' . $entry);
        $root = str_replace('\\', '/', $deployRoot);
        if (strpos($norm, $root) !== 0) {
            $problems[] = "Escapes root: {$entry}";
        }
    }
    return $problems;
}

/** True if this entry's basename matches any item in the preserveFiles list. */
function isPreserved(string $entry, array $preserveFiles): bool {
    $base = basename($entry);
    foreach ($preserveFiles as $pf) {
        if ($base === basename($pf)) return true;
    }
    return false;
}

// ═══════════════════════════════════════════════════════════════
// GATE: POST only
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    writeLog('REJECTED: non-POST request');
    respond(['status' => 'error', 'message' => 'POST required'], 405);
}

// ═══════════════════════════════════════════════════════════════
// GATE: Token (DEPLOY_SECRET loaded from config.php)
// ═══════════════════════════════════════════════════════════════

$token = trim($_POST['token'] ?? '');
if ($token === '' || !hash_equals(DEPLOY_SECRET, $token)) {
    writeLog('REJECTED: invalid token');
    respond(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$action = trim($_POST['action'] ?? '');
writeLog("ACTION: {$action} [project:{$config['projectName']}]");

// ═══════════════════════════════════════════════════════════════
// ACTION: UPLOAD
// ═══════════════════════════════════════════════════════════════

if ($action === 'upload') {
    if (!isset($_FILES['package'])) {
        writeLog('UPLOAD FAILED: no file in request');
        respond(['status' => 'error', 'message' => 'No file uploaded'], 400);
    }
    $file = $_FILES['package'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $phpErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in form',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing server temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension',
        ];
        $msg = $phpErrors[$file['error']] ?? 'Upload error code ' . $file['error'];
        writeLog("UPLOAD FAILED: {$msg}");
        respond(['status' => 'error', 'message' => $msg], 400);
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        writeLog('UPLOAD FAILED: file exceeds MAX_UPLOAD_BYTES');
        respond(['status' => 'error', 'message' => 'File too large (max ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB)'], 413);
    }
    $origName = basename($file['name']);
    if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'zip') {
        writeLog("UPLOAD FAILED: not a .zip: {$origName}");
        respond(['status' => 'error', 'message' => 'Only .zip files are accepted'], 415);
    }
    if (function_exists('finfo_open')) {
        $fi   = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
        $okMimes = ['application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream'];
        if (!in_array($mime, $okMimes, true)) {
            writeLog("UPLOAD FAILED: bad MIME {$mime}");
            respond(['status' => 'error', 'message' => 'Invalid file type (MIME: ' . $mime . ')'], 415);
        }
    }
    $ts       = date('Y-m-d_H-i-s');
    $safeName = $ts . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
    $pkgPath  = $DIR_PKG . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $pkgPath)) {
        writeLog('UPLOAD FAILED: could not move uploaded file');
        respond(['status' => 'error', 'message' => 'Failed to store package'], 500);
    }
    $all = glob($DIR_PKG . '/*.zip') ?: [];
    usort($all, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($all, $MAX_PKGS) as $old) { @unlink($old); }
    $sizeKb = round($file['size'] / 1024);
    writeLog("UPLOAD OK: {$safeName} ({$sizeKb} KB)");
    saveState(['lastPackage' => $safeName]);
    respond(['status' => 'ok', 'message' => 'Package uploaded', 'package' => $safeName, 'size_kb' => $sizeKb, 'project' => $config['projectName']]);
}

// ═══════════════════════════════════════════════════════════════
// ACTION: INSTALL
// ═══════════════════════════════════════════════════════════════

if ($action === 'install') {
    $pkgPath = resolveInstallPackage($config, $DIR_PKG);
    if ($pkgPath === null) {
        writeLog('INSTALL FAILED: no package found');
        respond(['status' => 'error', 'message' => 'No package available. Run /deploy:upload first.'], 404);
    }
    $pkgName      = basename($pkgPath);
    $preserveList = $config['preserveFiles'] ?? $DEFAULT_CONFIG['preserveFiles'];

    $zip = new ZipArchive();
    if ($zip->open($pkgPath) !== true) {
        writeLog("INSTALL FAILED: cannot open ZIP {$pkgName}");
        respond(['status' => 'error', 'message' => 'Cannot open ZIP archive'], 500);
    }
    $problems = validateZipEntries($zip, $PARENT);
    $zip->close();
    if (!empty($problems)) {
        writeLog('INSTALL BLOCKED: ' . implode('; ', $problems));
        respond(['status' => 'error', 'message' => 'ZIP validation failed', 'details' => $problems], 400);
    }

    // Backup
    $backupName = date('Y-m-d_H-i-s') . '_backup.zip';
    $backupPath = $DIR_BACKUP . '/' . $backupName;
    $backupOk   = false;
    $bz = new ZipArchive();
    if ($bz->open($backupPath, ZipArchive::CREATE) === true) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($PARENT, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $fi) {
            $fp  = $fi->getRealPath();
            if (realpath($BASE) !== false && strpos($fp, realpath($BASE)) === 0) continue;
            $rel = ltrim(substr($fp, strlen($PARENT)), '/\\');
            $fi->isDir() ? $bz->addEmptyDir($rel) : $bz->addFile($fp, $rel);
        }
        $bz->close();
        $backupOk = true;
        writeLog("BACKUP CREATED: {$backupName}");
    } else {
        writeLog('BACKUP WARNING: could not create backup — proceeding anyway');
    }
    $allBk = glob($DIR_BACKUP . '/*.zip') ?: [];
    usort($allBk, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($allBk, $MAX_BKUP) as $old) { @unlink($old); }

    // Extract
    $zipEx = new ZipArchive();
    if ($zipEx->open($pkgPath) !== true) {
        writeLog("INSTALL FAILED: cannot re-open ZIP {$pkgName}");
        saveState(['deploymentStatus' => 'failed', 'lastError' => 'Cannot re-open ZIP']);
        respond(['status' => 'error', 'message' => 'Cannot open ZIP for extraction'], 500);
    }
    $extracted = 0; $skipped = 0;
    for ($i = 0; $i < $zipEx->numFiles; $i++) {
        $entry    = $zipEx->getNameIndex($i);
        $destPath = $PARENT . '/' . $entry;
        if (isPreserved($entry, $preserveList)) { $skipped++; continue; }
        if (substr($entry, -1) === '/') { if (!is_dir($destPath)) @mkdir($destPath, 0755, true); continue; }
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        $contents = $zipEx->getFromIndex($i);
        if ($contents === false) { writeLog("INSTALL WARNING: unreadable entry {$entry}"); continue; }
        file_put_contents($destPath, $contents);
        $extracted++;
    }
    $zipEx->close();
    $ts = date('Y-m-d H:i:s');
    writeLog("INSTALL OK: {$pkgName} — {$extracted} files, {$skipped} preserved");
    $state = loadState();
    saveState(['deploymentStatus' => 'success', 'lastDeployment' => $ts, 'lastPackage' => $pkgName,
               'lastBackup' => $backupOk ? $backupName : ($state['lastBackup'] ?? null),
               'lastError' => null, 'deployCount' => ($state['deployCount'] ?? 0) + 1]);
    respond(['status' => 'ok', 'message' => 'Deployment successful', 'project' => $config['projectName'],
             'package' => $pkgName, 'backup' => $backupOk ? $backupName : null,
             'extracted' => $extracted, 'preserved' => $skipped, 'deployed_at' => $ts]);
}

// ═══════════════════════════════════════════════════════════════
// ACTION: ROLLBACK
// ═══════════════════════════════════════════════════════════════

if ($action === 'rollback') {
    $backupPath = newestZip($DIR_BACKUP);
    if ($backupPath === null) {
        writeLog('ROLLBACK FAILED: no backups found');
        respond(['status' => 'error', 'message' => 'No backup available'], 404);
    }
    $backupName   = basename($backupPath);
    $preserveList = $config['preserveFiles'] ?? $DEFAULT_CONFIG['preserveFiles'];
    $bz = new ZipArchive();
    if ($bz->open($backupPath) !== true) {
        writeLog("ROLLBACK FAILED: cannot open backup {$backupName}");
        respond(['status' => 'error', 'message' => 'Cannot open backup ZIP'], 500);
    }
    $problems = validateZipEntries($bz, $PARENT);
    $bz->close();
    if (!empty($problems)) {
        writeLog('ROLLBACK BLOCKED: ' . implode('; ', $problems));
        respond(['status' => 'error', 'message' => 'Backup ZIP validation failed', 'details' => $problems], 400);
    }
    $bz2 = new ZipArchive(); $bz2->open($backupPath); $restored = 0;
    for ($i = 0; $i < $bz2->numFiles; $i++) {
        $entry    = $bz2->getNameIndex($i);
        $destPath = $PARENT . '/' . $entry;
        if (isPreserved($entry, $preserveList)) continue;
        if (substr($entry, -1) === '/') { if (!is_dir($destPath)) @mkdir($destPath, 0755, true); continue; }
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        $contents = $bz2->getFromIndex($i);
        if ($contents !== false) { file_put_contents($destPath, $contents); $restored++; }
    }
    $bz2->close();
    $ts = date('Y-m-d H:i:s');
    writeLog("ROLLBACK OK: {$backupName} — {$restored} files restored");
    $state = loadState();
    saveState(['deploymentStatus' => 'rolled_back', 'lastRollback' => $ts, 'lastError' => null,
               'rollbackCount' => ($state['rollbackCount'] ?? 0) + 1]);
    respond(['status' => 'ok', 'message' => 'Rollback successful', 'restored' => $backupName,
             'files' => $restored, 'rolled_back_at' => $ts]);
}

// ═══════════════════════════════════════════════════════════════
// ACTION: STATUS
// ═══════════════════════════════════════════════════════════════

if ($action === 'status') {
    $state   = loadState();
    $backups = glob($DIR_BACKUP . '/*.zip') ?: [];
    $pkgs    = glob($DIR_PKG    . '/*.zip') ?: [];
    $latest  = newestZip($DIR_BACKUP);
    respond(['status' => 'ok', 'data' => [
        'deploy_status'   => $state['deploymentStatus'] ?? 'pending',
        'last_deployment' => $state['lastDeployment']   ?? null,
        'last_package'    => $state['lastPackage']      ?? null,
        'last_backup'     => $state['lastBackup']       ?? null,
        'last_rollback'   => $state['lastRollback']     ?? null,
        'current_version' => $state['currentVersion']   ?? null,
        'deploy_count'    => $state['deployCount']      ?? 0,
        'rollback_count'  => $state['rollbackCount']    ?? 0,
        'last_error'      => $state['lastError']        ?? null,
        'project_name'    => $config['projectName']     ?? 'unknown',
        'target_root'     => $PARENT,
        'deployment_mode' => $config['deploymentMode']  ?? 'upload_install',
        'pinned_package'  => $config['latestPackage']   ?: null,
        'backup_count'    => count($backups),
        'package_count'   => count($pkgs),
        'newest_backup'   => $latest ? basename($latest) : null,
        'server_time'     => date('Y-m-d H:i:s'),
        'install_id'      => defined('INSTALL_ID') ? INSTALL_ID : null,
    ]]);
}

// ═══════════════════════════════════════════════════════════════
// ACTION: LOGS
// ═══════════════════════════════════════════════════════════════

if ($action === 'logs') {
    $n = max(1, min((int)($_POST['lines'] ?? 50), 500));
    if (!file_exists($LOG_FILE)) { respond(['status' => 'ok', 'count' => 0, 'logs' => []]); }
    $all   = file($LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    respond(['status' => 'ok', 'count' => count(array_slice($all, -$n)), 'logs' => array_slice($all, -$n)]);
}

// ═══════════════════════════════════════════════════════════════
// ACTION: UPDATE-CONFIG
// ═══════════════════════════════════════════════════════════════

if ($action === 'update-config') {
    $updates = [];
    foreach (MUTABLE_CONFIG_FIELDS as $field) {
        if (!array_key_exists($field, $_POST)) continue;
        $val = trim($_POST[$field]);
        if ($field === 'deploymentMode' && !in_array($val, VALID_DEPLOY_MODES, true)) {
            respond(['status' => 'error', 'message' => 'Invalid deploymentMode. Allowed: ' . implode(', ', VALID_DEPLOY_MODES)], 400);
        }
        if ($field === 'latestPackage' && $val !== '') {
            $safe = basename($val);
            if (!preg_match('/^[a-zA-Z0-9._-]+\.zip$/i', $safe)) {
                respond(['status' => 'error', 'message' => 'Invalid package filename'], 400);
            }
            $val = $safe;
        }
        if ($field === 'projectName' && !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $val)) {
            respond(['status' => 'error', 'message' => 'Invalid projectName'], 400);
        }
        $updates[$field] = $val;
    }
    if (empty($updates)) {
        respond(['status' => 'error', 'message' => 'No valid fields provided. Mutable: ' . implode(', ', MUTABLE_CONFIG_FIELDS)], 400);
    }
    $newConfig = array_merge($config, $updates);
    if (@file_put_contents($CONFIG_FILE, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        respond(['status' => 'error', 'message' => 'Failed to write deploy-config.json'], 500);
    }
    writeLog('CONFIG UPDATED: ' . json_encode($updates));
    respond(['status' => 'ok', 'message' => 'Config updated', 'updated' => $updates, 'project' => $newConfig['projectName']]);
}

// ═══════════════════════════════════════════════════════════════
// ACTION: CLEAN_ROOT / CLEAN_ROOT_DRY
// ═══════════════════════════════════════════════════════════════

if ($action === 'clean_root' || $action === 'clean_root_dry') {
    $isDryRun   = ($action === 'clean_root_dry');
    $parentReal = realpath($PARENT);
    $baseReal   = realpath($BASE);

    if ($parentReal === false || !is_dir($parentReal)) {
        writeLog('CLEAN-ROOT FAILED: target root not accessible: ' . $PARENT);
        respond(['status' => 'error', 'message' => 'Target root directory is not accessible'], 500);
    }
    if (!$isDryRun) {
        $confirm = trim($_POST['confirm'] ?? '');
        if ($confirm !== 'CLEAN') {
            writeLog('CLEAN-ROOT REJECTED: missing confirmation field');
            respond(['status' => 'error', 'message' => 'Confirmation required. Send POST field: confirm=CLEAN'], 400);
        }
    }

    $protectedAbsPaths = array_values(array_filter(array_map('realpath', [$BASE, $DIR_PKG, $DIR_BACKUP, $DIR_LOGS, $DIR_TMP])));
    $normalise = fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/');
    $protectedAbsNorm  = array_map($normalise, $protectedAbsPaths);
    $parentNorm        = $normalise($parentReal);
    $hardNames         = ['ai-deploy.php','panel.php','config.php','deploy-config.json'];
    $cfgNames          = array_map('basename', $config['preserveFiles'] ?? []);
    $protectedNames    = array_unique(array_merge($hardNames, $cfgNames));

    $isProtected = function(string $realPath) use ($protectedAbsNorm, $protectedNames, $normalise): bool {
        $norm = $normalise($realPath);
        $base = basename($realPath);
        if (in_array($base, $protectedNames, true)) return true;
        foreach ($protectedAbsNorm as $pd) {
            if ($norm === $pd || str_starts_with($norm, $pd . '/')) return true;
        }
        return false;
    };

    $countRecursive = function(string $dir) use (&$countRecursive): int {
        $count = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $_) { $count++; }
        return $count;
    };

    $deleteDir = function(string $dir) use (&$deleteDir): int {
        $deleted = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getRealPath()) : ($deleted += (int)@unlink($f->getRealPath())); }
        @rmdir($dir); return $deleted;
    };

    $willDelete = []; $willProtect = [];
    $di = new DirectoryIterator($PARENT);
    foreach ($di as $item) {
        if ($item->isDot()) continue;
        $real = $item->getRealPath(); if ($real === false) continue;
        $rel  = ltrim(substr($normalise($real), strlen($parentNorm) + 1), '/');
        $type = $item->isDir() ? 'dir' : 'file';
        $isProtected($real)
            ? ($willProtect[] = $rel . ($type === 'dir' ? '/' : ''))
            : ($willDelete[]  = ['rel' => $rel, 'type' => $type, 'real' => $real]);
    }

    if ($isDryRun) {
        $totalFiles = 0; $topList = [];
        foreach ($willDelete as $item) {
            if ($item['type'] === 'dir') {
                $n = $countRecursive($item['real']); $topList[] = $item['rel'] . '/ (' . $n . ' files)'; $totalFiles += $n;
            } else { $topList[] = $item['rel']; $totalFiles++; }
        }
        sort($topList); sort($willProtect);
        $msg = count($willDelete) . ' top-level items (' . $totalFiles . ' files) would be deleted';
        writeLog('CLEAN-ROOT DRY-RUN: ' . $msg);
        respond(['status' => 'ok', 'dry_run' => true, 'message' => $msg,
                 'top_level' => $topList, 'protected' => $willProtect,
                 'item_count' => count($willDelete), 'file_count' => $totalFiles]);
    }

    // Create backup before deleting
    $backupName = date('Y-m-d_H-i-s') . '_pre-clean_backup.zip';
    $backupPath = $DIR_BACKUP . '/' . $backupName;
    $bz = new ZipArchive();
    if ($bz->open($backupPath, ZipArchive::CREATE) === true) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($PARENT, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $fi) {
            $fp = $fi->getRealPath();
            if (realpath($BASE) !== false && strpos($fp, realpath($BASE)) === 0) continue;
            $rel = ltrim(substr($normalise($fp), strlen($parentNorm) + 1), '/');
            $fi->isDir() ? $bz->addEmptyDir($rel) : $bz->addFile($fp, $rel);
        }
        $bz->close();
        writeLog("CLEAN-ROOT BACKUP: {$backupName}");
    } else {
        writeLog('CLEAN-ROOT ABORTED: backup creation failed');
        respond(['status' => 'error', 'message' => 'Could not create backup. Clean aborted for safety.'], 500);
    }

    $allBk = glob($DIR_BACKUP . '/*.zip') ?: [];
    usort($allBk, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($allBk, $MAX_BKUP) as $old) { @unlink($old); }

    $deletedFiles = 0; $deletedDirs = 0;
    foreach ($willDelete as $item) {
        if ($item['type'] === 'dir') { $deletedFiles += $deleteDir($item['real']); $deletedDirs++; }
        else { if (@unlink($item['real'])) $deletedFiles++; }
    }

    $ts = date('Y-m-d H:i:s');
    writeLog("CLEAN-ROOT OK: {$deletedFiles} files, {$deletedDirs} dirs removed. Backup: {$backupName}");
    saveState(['deploymentStatus' => 'cleaned', 'lastBackup' => $backupName, 'lastError' => null]);
    respond(['status' => 'ok', 'message' => 'Website root cleaned', 'deleted_files' => $deletedFiles,
             'deleted_dirs' => $deletedDirs, 'backup' => $backupName,
             'protected' => array_values($willProtect), 'cleaned_at' => $ts]);
}

// ═══════════════════════════════════════════════════════════════
// FALLBACK
// ═══════════════════════════════════════════════════════════════

writeLog("REJECTED: unknown action '{$action}'");
respond(['status' => 'error', 'message' => "Unknown action: {$action}"], 400);
