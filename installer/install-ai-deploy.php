<?php
/**
 * install-ai-deploy.php
 * AI Deploy Agent v3 — One-Click Installer
 *
 * Upload this single file to your website root.
 * Open it in a browser: https://yourdomain.com/install-ai-deploy.php
 * Click "Install AI Deploy Agent".
 * Save the generated token.
 * Delete this file.
 *
 * What the installer does:
 *   1. Checks PHP version, ZipArchive, write permissions.
 *   2. Creates the complete /_deploy/ directory structure.
 *   3. Generates a cryptographically secure DEPLOY_SECRET.
 *   4. Writes config.php, ai-deploy.php, panel.php, .htaccess.
 *   5. Shows the generated token ONCE — save it immediately.
 *   6. Writes an installation log to /_deploy/_logs/install.log.
 *
 * Security:
 *   - GET requests only show diagnostics — never install.
 *   - POST required to trigger installation.
 *   - Reinstalling an existing _deploy requires typing REINSTALL.
 *   - Delete this file after installation.
 */

// ═══════════════════════════════════════════════════════════════
// INSTALLER CONSTANTS
// ═══════════════════════════════════════════════════════════════

define('INSTALLER_VERSION', '3.0.0');
define('MIN_PHP_VERSION',   '7.4.0');
define('DEPLOY_DIR_NAME',   '_deploy');

// ═══════════════════════════════════════════════════════════════
// SYSTEM REQUIREMENTS CHECK
// ═══════════════════════════════════════════════════════════════

function checkRequirements(): array {
    $webRoot   = dirname(__FILE__);
    $deployDir = $webRoot . '/' . DEPLOY_DIR_NAME;

    $checks = [
        [
            'name'   => 'PHP version ≥ ' . MIN_PHP_VERSION,
            'pass'   => version_compare(PHP_VERSION, MIN_PHP_VERSION, '>='),
            'detail' => 'Current: ' . PHP_VERSION,
        ],
        [
            'name'   => 'ZipArchive extension',
            'pass'   => class_exists('ZipArchive'),
            'detail' => class_exists('ZipArchive') ? 'Available' : 'Missing — required for ZIP extraction',
        ],
        [
            'name'   => 'Website root writable',
            'pass'   => is_writable($webRoot),
            'detail' => $webRoot,
        ],
        [
            'name'   => 'cURL extension (for panel)',
            'pass'   => function_exists('curl_init'),
            'detail' => function_exists('curl_init') ? 'Available' : 'Missing — panel will not function',
            'warn'   => true,   // warning only, not fatal
        ],
        [
            'name'   => 'random_bytes() available',
            'pass'   => function_exists('random_bytes'),
            'detail' => 'Required for secure token generation',
        ],
        [
            'name'   => '_deploy already exists',
            'pass'   => null,   // informational
            'detail' => is_dir($deployDir) ? 'YES — reinstall will require confirmation' : 'No — fresh install',
            'info'   => true,
        ],
    ];

    $fatal = false;
    foreach ($checks as $c) {
        if ($c['pass'] === false && empty($c['warn'])) {
            $fatal = true;
        }
    }

    return ['checks' => $checks, 'fatal' => $fatal, 'deployExists' => is_dir($deployDir)];
}

// ═══════════════════════════════════════════════════════════════
// FILE TEMPLATES
// ═══════════════════════════════════════════════════════════════

/** Generate config.php content with the provided secret. */
function tplConfig(string $secret, string $installId, string $date): string {
    return <<<PHPEOF
<?php
/**
 * config.php — AI Deploy Agent
 * Generated: {$date}
 * Installation ID: {$installId}
 *
 * ⚠ Never expose this file publicly.
 * ⚠ Never commit this file to version control.
 * ⚠ Both ai-deploy.php and panel.php require this file.
 */

define('DEPLOY_SECRET', '{$secret}');
define('INSTALL_ID',    '{$installId}');
define('INSTALL_DATE',  '{$date}');
PHPEOF;
}

/** Main .htaccess for /_deploy/ root — blocks config + sensitive files. */
function tplHtaccess(): string {
    return <<<'HTEOF'
# AI Deploy Agent — _deploy directory access control

# Block direct access to configuration and state files
<Files "config.php">
  Order deny,allow
  Deny from all
</Files>

<Files "deploy-config.json">
  Order deny,allow
  Deny from all
</Files>

<Files "deploy-state.json">
  Order deny,allow
  Deny from all
</Files>

# Block access to internal subdirectories via mod_rewrite
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^(_backups|_logs|_packages|_state|_tmp|_incoming)(/.*)?$ - [F,L]
</IfModule>

# Options hardening
Options -Indexes
HTEOF;
}

/** Per-subdirectory .htaccess — deny all direct web access. */
function tplSubHtaccess(): string {
    return "Deny from all\n";
}

/** Default deploy-config.json */
function tplDeployConfig(): string {
    return json_encode([
        '_comment'       => 'AI Deploy Agent — server configuration. Edit projectName and targetRoot.',
        'projectName'    => 'my-website',
        'targetRoot'     => '',
        'packageDir'     => '_packages',
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
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

/** Default deploy-state.json */
function tplDeployState(): string {
    return json_encode([
        '_comment'         => 'Auto-maintained by ai-deploy.php. Do not edit manually.',
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
    ], JSON_PRETTY_PRINT) . "\n";
}

/** Complete ai-deploy.php source (v3 — loads config.php for token). */
function tplAiDeploy(): string {
// NOTE: The closing AI_DEPLOY_END must be at column 0 with no leading whitespace.
return <<<'AI_DEPLOY_END'
<?php
/**
 * ai-deploy.php — v3
 * AI Deploy Agent · Config-driven deployment engine.
 * Token loaded from config.php — no hardcoded secrets.
 */
require_once __DIR__ . '/config.php';

define('MAX_UPLOAD_BYTES', 64 * 1024 * 1024);
define('LOG_MAX_LINES',    500);
define('MUTABLE_CONFIG_FIELDS', ['projectName', 'latestPackage', 'deploymentMode']);
define('VALID_DEPLOY_MODES', ['upload_only', 'upload_install', 'manual']);

$BASE        = __DIR__;
$CONFIG_FILE = $BASE . '/deploy-config.json';
$STATE_FILE  = $BASE . '/_state/deploy-state.json';

$DEFAULT_CONFIG = [
    'projectName' => 'my-website', 'targetRoot' => '', 'packageDir' => '_packages',
    'backupDir' => '_backups', 'logsDir' => '_logs', 'incomingDir' => '_incoming',
    'latestPackage' => '', 'deploymentMode' => 'upload_install', 'maxBackups' => 10,
    'maxPackages' => 5,
    'preserveFiles' => ['ai-deploy.php','panel.php','config.php','deploy-config.json'],
];
$DEFAULT_STATE = [
    'lastDeployment' => null, 'lastPackage' => null, 'lastRollback' => null,
    'deploymentStatus' => 'pending', 'currentVersion' => null, 'lastError' => null,
    'lastBackup' => null, 'deployCount' => 0, 'rollbackCount' => 0, 'updatedAt' => null,
];

function loadConfig(): array {
    global $CONFIG_FILE, $DEFAULT_CONFIG;
    if (!file_exists($CONFIG_FILE)) { @file_put_contents($CONFIG_FILE, json_encode($DEFAULT_CONFIG, JSON_PRETTY_PRINT)); return $DEFAULT_CONFIG; }
    $raw = @file_get_contents($CONFIG_FILE); $data = json_decode($raw, true);
    return array_merge($DEFAULT_CONFIG, is_array($data) ? $data : []);
}
function resolveDir(string $v, string $fallback, string $base): string {
    $v = trim($v); if ($v === '') return $fallback;
    if ($v[0] === '/' || preg_match('/^[A-Za-z]:[\\/]/', $v)) return rtrim($v, '/\\');
    return $base . '/' . ltrim($v, '/');
}

$config     = loadConfig();
$PARENT     = resolveDir($config['targetRoot'], dirname($BASE), $BASE);
$DIR_PKG    = resolveDir($config['packageDir'],  $BASE . '/_packages', $BASE);
$DIR_BACKUP = resolveDir($config['backupDir'],   $BASE . '/_backups',  $BASE);
$DIR_LOGS   = resolveDir($config['logsDir'],     $BASE . '/_logs',     $BASE);
$DIR_TMP    = resolveDir($config['incomingDir'], $BASE . '/_incoming', $BASE);
$LOG_FILE   = $DIR_LOGS . '/deploy.log';
$MAX_BKUP   = max(1, (int)($config['maxBackups']  ?? 10));
$MAX_PKGS   = max(1, (int)($config['maxPackages'] ?? 5));

foreach ([$DIR_TMP, $DIR_PKG, $DIR_BACKUP, $DIR_LOGS, $BASE . '/_state'] as $_dir) {
    if (!is_dir($_dir)) @mkdir($_dir, 0755, true);
    $_ht = $_dir . '/.htaccess';
    if (!file_exists($_ht)) @file_put_contents($_ht, "Deny from all\n");
}

function respond(array $data, int $code = 200): void {
    http_response_code($code); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); exit;
}
function writeLog(string $message): void {
    global $LOG_FILE, $DIR_LOGS, $LOG_MAX_LINES;
    if (!is_dir($DIR_LOGS)) @mkdir($DIR_LOGS, 0755, true);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown'; $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] [IP:{$ip}] {$message}";
    if (file_exists($LOG_FILE)) {
        $lines = file($LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) >= $LOG_MAX_LINES) { $lines = array_slice($lines, -($LOG_MAX_LINES - 50)); file_put_contents($LOG_FILE, implode("\n", $lines) . "\n"); }
    }
    file_put_contents($LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
}
function loadState(): array {
    global $STATE_FILE, $DEFAULT_STATE;
    if (!file_exists($STATE_FILE)) { @file_put_contents($STATE_FILE, json_encode($DEFAULT_STATE, JSON_PRETTY_PRINT)); return $DEFAULT_STATE; }
    $raw = @file_get_contents($STATE_FILE); $data = json_decode($raw, true);
    return array_merge($DEFAULT_STATE, is_array($data) ? $data : []);
}
function saveState(array $updates): void {
    global $STATE_FILE; $state = loadState(); $state = array_merge($state, $updates);
    $state['updatedAt'] = date('Y-m-d H:i:s');
    @file_put_contents($STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
function newestZip(string $dir): ?string {
    $files = glob($dir . '/*.zip') ?: []; if (empty($files)) return null;
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a)); return $files[0];
}
function resolveInstallPackage(array $config, string $pkgDir): ?string {
    $pinned = trim($config['latestPackage'] ?? '');
    if ($pinned !== '') { $c = $pkgDir . '/' . basename($pinned); if (file_exists($c)) return $c; }
    return newestZip($pkgDir);
}
function validateZipEntries(ZipArchive $zip, string $deployRoot): array {
    $problems = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (strpos($entry, '../') !== false || strpos($entry, '..\\') !== false) { $problems[] = "Traversal: {$entry}"; continue; }
        if ($entry !== '' && ($entry[0] === '/' || preg_match('/^[A-Za-z]:[\\/]/', $entry))) { $problems[] = "Absolute: {$entry}"; continue; }
        $norm = str_replace('\\', '/', $deployRoot . '/' . $entry); $root = str_replace('\\', '/', $deployRoot);
        if (strpos($norm, $root) !== 0) $problems[] = "Escapes root: {$entry}";
    }
    return $problems;
}
function isPreserved(string $entry, array $list): bool {
    $b = basename($entry); foreach ($list as $p) { if ($b === basename($p)) return true; } return false;
}

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { writeLog('REJECTED: non-POST'); respond(['status'=>'error','message'=>'POST required'], 405); }
$token = trim($_POST['token'] ?? '');
if ($token === '' || !hash_equals(DEPLOY_SECRET, $token)) { writeLog('REJECTED: invalid token'); respond(['status'=>'error','message'=>'Unauthorized'], 401); }
$action = trim($_POST['action'] ?? '');
writeLog("ACTION: {$action} [project:{$config['projectName']}]");

// ── UPLOAD ───────────────────────────────────────────────────
if ($action === 'upload') {
    if (!isset($_FILES['package'])) { respond(['status'=>'error','message'=>'No file uploaded'], 400); }
    $file = $_FILES['package'];
    if ($file['error'] !== UPLOAD_ERR_OK) { $msgs=[UPLOAD_ERR_INI_SIZE=>'Exceeds upload_max_filesize',UPLOAD_ERR_PARTIAL=>'Partial upload',UPLOAD_ERR_NO_FILE=>'No file',UPLOAD_ERR_CANT_WRITE=>'Write failed']; respond(['status'=>'error','message'=>$msgs[$file['error']]??'Upload error '.$file['error']], 400); }
    if ($file['size'] > MAX_UPLOAD_BYTES) respond(['status'=>'error','message'=>'File too large'], 413);
    $origName = basename($file['name']);
    if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'zip') respond(['status'=>'error','message'=>'Only .zip accepted'], 415);
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $file['tmp_name']); finfo_close($fi);
        if (!in_array($mime, ['application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream'], true)) respond(['status'=>'error','message'=>'Invalid MIME: '.$mime], 415);
    }
    $ts = date('Y-m-d_H-i-s'); $safe = $ts . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName); $path = $DIR_PKG . '/' . $safe;
    if (!move_uploaded_file($file['tmp_name'], $path)) respond(['status'=>'error','message'=>'Failed to store package'], 500);
    $all = glob($DIR_PKG.'/*.zip') ?: []; usort($all, fn($a,$b)=>filemtime($b)-filemtime($a));
    foreach (array_slice($all, $MAX_PKGS) as $old) @unlink($old);
    $sizeKb = round($file['size']/1024); writeLog("UPLOAD OK: {$safe} ({$sizeKb} KB)"); saveState(['lastPackage'=>$safe]);
    respond(['status'=>'ok','message'=>'Package uploaded','package'=>$safe,'size_kb'=>$sizeKb,'project'=>$config['projectName']]);
}

// ── INSTALL ───────────────────────────────────────────────────
if ($action === 'install') {
    $pkgPath = resolveInstallPackage($config, $DIR_PKG);
    if (!$pkgPath) { writeLog('INSTALL FAILED: no package'); respond(['status'=>'error','message'=>'No package available'], 404); }
    $pkgName = basename($pkgPath); $preserveList = $config['preserveFiles'] ?? $DEFAULT_CONFIG['preserveFiles'];
    $zip = new ZipArchive(); if ($zip->open($pkgPath) !== true) respond(['status'=>'error','message'=>'Cannot open ZIP'], 500);
    $probs = validateZipEntries($zip, $PARENT); $zip->close();
    if (!empty($probs)) { writeLog('INSTALL BLOCKED: '.implode('; ',$probs)); respond(['status'=>'error','message'=>'ZIP validation failed','details'=>$probs], 400); }
    $bkName = date('Y-m-d_H-i-s').'_backup.zip'; $bkPath = $DIR_BACKUP.'/'.$bkName; $bkOk = false;
    $bz = new ZipArchive();
    if ($bz->open($bkPath, ZipArchive::CREATE) === true) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PARENT, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $fi) { $fp=$fi->getRealPath(); if(realpath($BASE)!==false&&strpos($fp,realpath($BASE))===0) continue; $rel=ltrim(substr($fp,strlen($PARENT)),'/\\'); $fi->isDir()?$bz->addEmptyDir($rel):$bz->addFile($fp,$rel); }
        $bz->close(); $bkOk = true; writeLog("BACKUP: {$bkName}");
    }
    $allBk = glob($DIR_BACKUP.'/*.zip') ?: []; usort($allBk,fn($a,$b)=>filemtime($b)-filemtime($a));
    foreach (array_slice($allBk,$MAX_BKUP) as $old) @unlink($old);
    $zipEx = new ZipArchive(); if ($zipEx->open($pkgPath)!==true) respond(['status'=>'error','message'=>'Cannot re-open ZIP'], 500);
    $extracted=0; $skipped=0;
    for ($i=0;$i<$zipEx->numFiles;$i++) {
        $entry=$zipEx->getNameIndex($i); $dest=$PARENT.'/'.$entry;
        if (isPreserved($entry,$preserveList)) { $skipped++; continue; }
        if (substr($entry,-1)==='/') { if(!is_dir($dest))@mkdir($dest,0755,true); continue; }
        if(!is_dir(dirname($dest)))@mkdir(dirname($dest),0755,true);
        $c=$zipEx->getFromIndex($i); if($c===false) continue; file_put_contents($dest,$c); $extracted++;
    }
    $zipEx->close(); $ts=date('Y-m-d H:i:s'); writeLog("INSTALL OK: {$pkgName} — {$extracted} files");
    $st=loadState(); saveState(['deploymentStatus'=>'success','lastDeployment'=>$ts,'lastPackage'=>$pkgName,'lastBackup'=>$bkOk?$bkName:($st['lastBackup']??null),'lastError'=>null,'deployCount'=>($st['deployCount']??0)+1]);
    respond(['status'=>'ok','message'=>'Deployment successful','project'=>$config['projectName'],'package'=>$pkgName,'backup'=>$bkOk?$bkName:null,'extracted'=>$extracted,'preserved'=>$skipped,'deployed_at'=>$ts]);
}

// ── ROLLBACK ──────────────────────────────────────────────────
if ($action === 'rollback') {
    $bkPath = newestZip($DIR_BACKUP); if (!$bkPath) respond(['status'=>'error','message'=>'No backup available'], 404);
    $bkName = basename($bkPath); $preserveList = $config['preserveFiles'] ?? $DEFAULT_CONFIG['preserveFiles'];
    $bz = new ZipArchive(); if ($bz->open($bkPath)!==true) respond(['status'=>'error','message'=>'Cannot open backup ZIP'], 500);
    $probs = validateZipEntries($bz, $PARENT); $bz->close();
    if (!empty($probs)) respond(['status'=>'error','message'=>'Backup ZIP validation failed','details'=>$probs], 400);
    $bz2=new ZipArchive(); $bz2->open($bkPath); $restored=0;
    for ($i=0;$i<$bz2->numFiles;$i++) {
        $entry=$bz2->getNameIndex($i); $dest=$PARENT.'/'.$entry;
        if (isPreserved($entry,$preserveList)) continue;
        if (substr($entry,-1)==='/') { if(!is_dir($dest))@mkdir($dest,0755,true); continue; }
        if(!is_dir(dirname($dest)))@mkdir(dirname($dest),0755,true);
        $c=$bz2->getFromIndex($i); if($c!==false){file_put_contents($dest,$c);$restored++;}
    }
    $bz2->close(); $ts=date('Y-m-d H:i:s'); writeLog("ROLLBACK OK: {$bkName} — {$restored} files");
    $st=loadState(); saveState(['deploymentStatus'=>'rolled_back','lastRollback'=>$ts,'lastError'=>null,'rollbackCount'=>($st['rollbackCount']??0)+1]);
    respond(['status'=>'ok','message'=>'Rollback successful','restored'=>$bkName,'files'=>$restored,'rolled_back_at'=>$ts]);
}

// ── STATUS ────────────────────────────────────────────────────
if ($action === 'status') {
    $state=loadState(); $backups=glob($DIR_BACKUP.'/*.zip')?:[]; $pkgs=glob($DIR_PKG.'/*.zip')?:[];
    respond(['status'=>'ok','data'=>[
        'deploy_status'=>$state['deploymentStatus']??'pending','last_deployment'=>$state['lastDeployment']??null,
        'last_package'=>$state['lastPackage']??null,'last_backup'=>$state['lastBackup']??null,
        'last_rollback'=>$state['lastRollback']??null,'current_version'=>$state['currentVersion']??null,
        'deploy_count'=>$state['deployCount']??0,'rollback_count'=>$state['rollbackCount']??0,
        'last_error'=>$state['lastError']??null,'project_name'=>$config['projectName']??'unknown',
        'target_root'=>$PARENT,'deployment_mode'=>$config['deploymentMode']??'upload_install',
        'pinned_package'=>$config['latestPackage']?:null,'backup_count'=>count($backups),
        'package_count'=>count($pkgs),'newest_backup'=>newestZip($DIR_BACKUP)?basename(newestZip($DIR_BACKUP)):null,
        'server_time'=>date('Y-m-d H:i:s'),'install_id'=>defined('INSTALL_ID')?INSTALL_ID:null,
    ]]);
}

// ── LOGS ──────────────────────────────────────────────────────
if ($action === 'logs') {
    $n=max(1,min((int)($_POST['lines']??50),500));
    if (!file_exists($LOG_FILE)) respond(['status'=>'ok','count'=>0,'logs'=>[]]);
    $all=file($LOG_FILE,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];
    respond(['status'=>'ok','count'=>count(array_slice($all,-$n)),'logs'=>array_slice($all,-$n)]);
}

// ── UPDATE-CONFIG ─────────────────────────────────────────────
if ($action === 'update-config') {
    $updates=[];
    foreach (MUTABLE_CONFIG_FIELDS as $field) {
        if (!array_key_exists($field,$_POST)) continue; $val=trim($_POST[$field]);
        if ($field==='deploymentMode'&&!in_array($val,VALID_DEPLOY_MODES,true)) respond(['status'=>'error','message'=>'Invalid deploymentMode'],400);
        if ($field==='latestPackage'&&$val!=='') { $safe=basename($val); if(!preg_match('/^[a-zA-Z0-9._-]+\.zip$/i',$safe)) respond(['status'=>'error','message'=>'Invalid package filename'],400); $val=$safe; }
        if ($field==='projectName'&&!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$val)) respond(['status'=>'error','message'=>'Invalid projectName'],400);
        $updates[$field]=$val;
    }
    if (empty($updates)) respond(['status'=>'error','message'=>'No valid fields'],400);
    $newConfig=array_merge($config,$updates);
    if (@file_put_contents($CONFIG_FILE,json_encode($newConfig,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false) respond(['status'=>'error','message'=>'Failed to write config'],500);
    writeLog('CONFIG UPDATED: '.json_encode($updates));
    respond(['status'=>'ok','message'=>'Config updated','updated'=>$updates,'project'=>$newConfig['projectName']]);
}

// ── CLEAN_ROOT ────────────────────────────────────────────────
if ($action==='clean_root'||$action==='clean_root_dry') {
    $isDryRun=($action==='clean_root_dry'); $parentReal=realpath($PARENT); $baseReal=realpath($BASE);
    if (!$parentReal||!is_dir($parentReal)) respond(['status'=>'error','message'=>'Target root not accessible'],500);
    if (!$isDryRun&&trim($_POST['confirm']??'')!=='CLEAN') respond(['status'=>'error','message'=>'Confirmation required: confirm=CLEAN'],400);
    $protAbs=array_values(array_filter(array_map('realpath',[$BASE,$DIR_PKG,$DIR_BACKUP,$DIR_LOGS,$DIR_TMP])));
    $norm=fn(string $p):string=>rtrim(str_replace('\\','/',$p),'/');
    $protAbsN=array_map($norm,$protAbs); $parentN=$norm($parentReal);
    $hardN=['ai-deploy.php','panel.php','config.php','deploy-config.json'];
    $cfgN=array_map('basename',$config['preserveFiles']??[]);
    $protNames=array_unique(array_merge($hardN,$cfgN));
    $isProt=function(string $r) use ($protAbsN,$protNames,$norm):bool {
        $n=$norm($r); if(in_array(basename($r),$protNames,true)) return true;
        foreach($protAbsN as $pd){if($n===$pd||str_starts_with($n,$pd.'/')) return true;} return false;
    };
    $cntRec=function(string $d) use (&$cntRec):int { $c=0; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,RecursiveDirectoryIterator::SKIP_DOTS)); foreach($it as $_) $c++; return $c; };
    $delDir=function(string $d) use (&$delDir):int { $c=0; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){$f->isDir()?@rmdir($f->getRealPath()):($c+=(int)@unlink($f->getRealPath()));} @rmdir($d); return $c; };
    $wDel=[]; $wProt=[];
    $di=new DirectoryIterator($PARENT);
    foreach($di as $item){
        if($item->isDot()) continue; $real=$item->getRealPath(); if(!$real) continue;
        $rel=ltrim(substr($norm($real),strlen($parentN)+1),'/'); $type=$item->isDir()?'dir':'file';
        $isProt($real)?($wProt[]=$rel.($type==='dir'?'/':'')):(  $wDel[]=['rel'=>$rel,'type'=>$type,'real'=>$real]);
    }
    if($isDryRun){
        $total=0; $top=[];
        foreach($wDel as $it){if($it['type']==='dir'){$n=$cntRec($it['real']);$top[]=$it['rel'].'/ ('.$n.' files)';$total+=$n;}else{$top[]=$it['rel'];$total++;}}
        sort($top);sort($wProt);$msg=count($wDel).' items ('.$total.' files) would be deleted';
        writeLog('CLEAN-ROOT DRY-RUN: '.$msg);
        respond(['status'=>'ok','dry_run'=>true,'message'=>$msg,'top_level'=>$top,'protected'=>$wProt,'item_count'=>count($wDel),'file_count'=>$total]);
    }
    $bkN=date('Y-m-d_H-i-s').'_pre-clean_backup.zip'; $bkP=$DIR_BACKUP.'/'.$bkN;
    $bz=new ZipArchive();
    if($bz->open($bkP,ZipArchive::CREATE)===true){
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PARENT,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $fi){$fp=$fi->getRealPath();if(realpath($BASE)!==false&&strpos($fp,realpath($BASE))===0) continue;$rel=ltrim(substr($norm($fp),strlen($parentN)+1),'/');$fi->isDir()?$bz->addEmptyDir($rel):$bz->addFile($fp,$rel);}
        $bz->close(); writeLog("CLEAN-ROOT BACKUP: {$bkN}");
    } else { writeLog('CLEAN-ROOT ABORTED: backup failed'); respond(['status'=>'error','message'=>'Backup failed. Clean aborted.'],500); }
    $allBk=glob($DIR_BACKUP.'/*.zip')?:[]; usort($allBk,fn($a,$b)=>filemtime($b)-filemtime($a)); foreach(array_slice($allBk,$MAX_BKUP) as $old) @unlink($old);
    $dF=0;$dD=0;
    foreach($wDel as $it){if($it['type']==='dir'){$dF+=$delDir($it['real']);$dD++;}else{if(@unlink($it['real']))$dF++;}}
    $ts=date('Y-m-d H:i:s'); writeLog("CLEAN-ROOT OK: {$dF} files, {$dD} dirs. Backup: {$bkN}");
    saveState(['deploymentStatus'=>'cleaned','lastBackup'=>$bkN,'lastError'=>null]);
    respond(['status'=>'ok','message'=>'Website root cleaned','deleted_files'=>$dF,'deleted_dirs'=>$dD,'backup'=>$bkN,'protected'=>array_values($wProt),'cleaned_at'=>$ts]);
}

writeLog("REJECTED: unknown action '{$action}'");
respond(['status'=>'error','message'=>"Unknown action: {$action}"],400);
AI_DEPLOY_END;
}

/** Complete panel.php source (v3 — loads config.php for token). */
function tplPanel(): string {
return <<<'PANEL_END'
<?php
/**
 * panel.php — v3
 * AI Deploy Agent · Web Panel. Token loaded from config.php.
 */
require_once __DIR__ . '/config.php';

define('SESSION_LIFETIME', 3600);
define('AGENT_URL', '');

session_start();

$agentUrl = AGENT_URL ?: ((isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['PHP_SELF']),'/\\').'/ai-deploy.php');

$loggedIn = (isset($_SESSION['panel_auth'])&&$_SESSION['panel_auth']===true&&isset($_SESSION['panel_time'])&&(time()-$_SESSION['panel_time'])<SESSION_LIFETIME);

if (isset($_POST['panel_login'])) {
    if (hash_equals(DEPLOY_SECRET, trim($_POST['panel_token']??''))) { $_SESSION['panel_auth']=true; $_SESSION['panel_time']=time(); $loggedIn=true; }
    else $loginError='Invalid token.';
}
if (isset($_POST['panel_logout'])) { session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit; }

function agentCall(string $url, array $fields, ?array $uploadFile=null): array {
    $ch=curl_init($url); curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); curl_setopt($ch,CURLOPT_TIMEOUT,120); curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    if($uploadFile){$cf=new CURLFile($uploadFile['tmp_name'],$uploadFile['type']?:'application/zip',$uploadFile['name']);$fields['package']=$cf;curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,$fields);}
    else{curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($fields));curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/x-www-form-urlencoded']);}
    $raw=curl_exec($ch);$err=curl_error($ch);curl_close($ch);
    if($err) return['status'=>'error','message'=>'cURL: '.$err];
    $d=json_decode($raw,true); return $d?:['status'=>'error','message'=>'Invalid JSON'];
}

$panelMessage='';$panelResult=null;$bannerClass='banner-ok';$dryRunResult=null;
if($loggedIn&&isset($_POST['panel_action'])){
    $act=$_POST['panel_action'];$base=['token'=>DEPLOY_SECRET];
    if($act==='install'){$panelResult=agentCall($agentUrl,array_merge($base,['action'=>'install']));$panelMessage=$panelResult['status']==='ok'?"✓ Deployed: {$panelResult['package']} ({$panelResult['extracted']} files)":'Error: '.($panelResult['message']??'');$bannerClass=$panelResult['status']==='ok'?'banner-ok':'banner-fail';}
    if($act==='rollback'){$panelResult=agentCall($agentUrl,array_merge($base,['action'=>'rollback']));$panelMessage=$panelResult['status']==='ok'?"✓ Rolled back to: {$panelResult['restored']} ({$panelResult['files']} files)":'Error: '.($panelResult['message']??'');$bannerClass=$panelResult['status']==='ok'?'banner-ok':'banner-fail';}
    if($act==='upload'&&isset($_FILES['zip_file'])&&$_FILES['zip_file']['error']===UPLOAD_ERR_OK){$f=$_FILES['zip_file'];if(strtolower(pathinfo($f['name'],PATHINFO_EXTENSION))!=='zip'){$panelMessage='Error: Only .zip files.';$bannerClass='banner-fail';}else{$panelResult=agentCall($agentUrl,array_merge($base,['action'=>'upload']),$f);$panelMessage=$panelResult['status']==='ok'?"✓ Uploaded: {$panelResult['package']} ({$panelResult['size_kb']} KB)":'Error: '.($panelResult['message']??'');$bannerClass=$panelResult['status']==='ok'?'banner-ok':'banner-fail';}}
    if($act==='set-package'){$pkg=trim($_POST['pin_package']??'');$panelResult=agentCall($agentUrl,array_merge($base,['action'=>'update-config','latestPackage'=>$pkg]));$panelMessage=$panelResult['status']==='ok'?"✓ Pinned: ".($pkg?:'(cleared)'):'Error: '.($panelResult['message']??'');$bannerClass=$panelResult['status']==='ok'?'banner-ok':'banner-fail';}
    if($act==='clean-root-dry'){$dryRunResult=agentCall($agentUrl,array_merge($base,['action'=>'clean_root_dry']));if($dryRunResult['status']==='ok'){$panelMessage="Dry-run: {$dryRunResult['message']}";$bannerClass='banner-warn';}else{$panelMessage='Dry-run error: '.($dryRunResult['message']??'');$bannerClass='banner-fail';$dryRunResult=null;}}
    if($act==='clean-root'){$confirm=trim($_POST['clean_confirm']??'');if($confirm!=='CLEAN'){$panelMessage='Type CLEAN in the field.';$bannerClass='banner-fail';}else{$panelResult=agentCall($agentUrl,array_merge($base,['action'=>'clean_root','confirm'=>'CLEAN']));$panelMessage=$panelResult['status']==='ok'?"✓ Cleaned: {$panelResult['deleted_files']} files, backup: {$panelResult['backup']}":'Error: '.($panelResult['message']??'');$bannerClass=$panelResult['status']==='ok'?'banner-ok':'banner-fail';}}
}
$statusData=[];$logsData=[];
if($loggedIn){$r=agentCall($agentUrl,['token'=>DEPLOY_SECRET,'action'=>'status']);if(($r['status']??'')==='ok')$statusData=$r['data']??[];$r2=agentCall($agentUrl,['token'=>DEPLOY_SECRET,'action'=>'logs','lines'=>'40']);if(($r2['status']??'')==='ok')$logsData=$r2['logs']??[];}
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function logClass(string $l):string{if(strpos($l,' OK')!==false)return'log-ok';if(strpos($l,'FAILED')!==false||strpos($l,'BLOCKED')!==false)return'log-fail';if(strpos($l,'REJECTED')!==false||strpos($l,'WARNING')!==false)return'log-warn';if(strpos($l,'ROLLBACK')!==false)return'log-roll';if(strpos($l,'CONFIG')!==false)return'log-cfg';return'';}
function statusBadge(string $s):string{return match($s){'success'=>'<span class="badge badge-green">success</span>','rolled_back'=>'<span class="badge badge-yellow">rolled back</span>','failed'=>'<span class="badge badge-red">failed</span>','cleaned'=>'<span class="badge badge-orange">cleaned</span>',default=>'<span class="badge badge-gray">'.h($s).'</span>'};}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI Deploy · <?=h($statusData['project_name']??'panel')?></title>
<style>:root{--bg:#0f1117;--surface:#1a1d27;--surface2:#22263a;--border:#2a2d3a;--text:#d4d8e8;--muted:#6b7280;--accent:#4f8ef7;--green:#22c55e;--red:#ef4444;--yellow:#f59e0b;--purple:#a78bfa;--cyan:#22d3ee;--radius:8px}
*{box-sizing:border-box;margin:0;padding:0}body{background:var(--bg);color:var(--text);font:14px/1.6 'Segoe UI',system-ui,sans-serif}
.container{max-width:960px;margin:0 auto;padding:28px 16px}.topbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:24px}
.logo{font-size:18px;font-weight:700;color:var(--accent)}.logo .project{color:var(--text)}.logo .v{color:var(--muted);font-size:12px;margin-left:6px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:14px}
.card-title{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:10px}
.stat{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:11px 14px}
.stat-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.stat-value{font-size:14px;font-weight:600;word-break:break-all;line-height:1.3}
.cfg-strip{display:flex;flex-wrap:wrap;gap:8px}.cfg-pill{background:var(--surface2);border:1px solid var(--border);border-radius:20px;padding:4px 12px;font-size:12px;color:var(--muted)}.cfg-pill span{color:var(--text);font-weight:600}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700}
.badge-green{background:rgba(34,197,94,.15);color:var(--green)}.badge-yellow{background:rgba(245,158,11,.15);color:var(--yellow)}.badge-red{background:rgba(239,68,68,.15);color:var(--red)}.badge-gray{background:rgba(107,114,128,.15);color:var(--muted)}.badge-orange{background:rgba(249,115,22,.15);color:#f97316}
.btn{display:inline-block;padding:9px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;transition:opacity .12s;white-space:nowrap}.btn:hover{opacity:.82}
.btn-primary{background:var(--accent);color:#fff}.btn-success{background:var(--green);color:#fff}.btn-danger{background:var(--red);color:#fff}.btn-ghost{background:var(--border);color:var(--text)}.btn-sm{padding:6px 12px;font-size:12px}
.action-row{display:flex;gap:10px;flex-wrap:wrap}
input[type=password],input[type=file],input[type=text]{background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:8px 12px;font-size:13px;width:100%}
input:focus{outline:2px solid var(--accent);border-color:transparent}
.form-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}.form-group{flex:1;min-width:180px}
.form-group label{font-size:11px;color:var(--muted);display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em}
.banner{padding:10px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;font-weight:500}
.banner-ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:var(--green)}
.banner-fail{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--red)}
.banner-warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:var(--yellow)}
.log-box{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:12px 14px;font:12px/1.75 'Cascadia Code','Fira Code',monospace;max-height:380px;overflow-y:auto}
.log-ok{color:var(--green)}.log-fail{color:var(--red)}.log-warn{color:var(--yellow)}.log-roll{color:var(--purple)}.log-cfg{color:var(--cyan)}
details summary{cursor:pointer;color:var(--muted);font-size:11px;margin-top:8px}pre{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px;font-size:11px;overflow-x:auto;margin-top:6px}
.divider{border:none;border-top:1px solid var(--border);margin:14px 0}
.card-danger{border-color:rgba(239,68,68,.4)}.card-danger .card-title{color:var(--red)}
.danger-note{font-size:12px;color:var(--muted);margin-bottom:14px;line-height:1.5}.danger-note strong{color:var(--yellow)}
.dry-run-box{background:var(--bg);border:1px solid rgba(245,158,11,.3);border-radius:6px;padding:12px 14px;font:12px/1.75 'Cascadia Code','Fira Code',monospace;max-height:260px;overflow-y:auto;margin-top:12px}
.dry-del{color:var(--red)}.dry-keep{color:var(--green)}
.login-wrap{max-width:380px;margin:72px auto}.login-logo{text-align:center;font-size:22px;font-weight:700;color:var(--accent);margin-bottom:24px}
@media(max-width:520px){.action-row{flex-direction:column}}</style>
</head><body><div class="container">
<?php if(!$loggedIn): ?>
<div class="login-wrap"><div class="login-logo">⚡ AI Deploy Agent</div>
<div class="card"><div class="card-title">Authentication</div>
<?php if(!empty($loginError)): ?><div class="banner banner-fail"><?=h($loginError)?></div><?php endif ?>
<form method="post"><div class="form-group" style="margin-bottom:14px;"><label>Deployment Token</label><input type="password" name="panel_token" placeholder="Enter token…" autofocus required></div>
<button class="btn btn-primary" style="width:100%;" name="panel_login" value="1">Sign in</button></form></div></div>
<?php else: ?>
<div class="topbar"><div><div class="logo">⚡ AI Deploy <span class="project"><?=h($statusData['project_name']??'Agent')?></span><span class="v">v3</span></div><div style="font-size:11px;color:var(--muted);margin-top:2px;"><?=h($agentUrl)?></div></div>
<form method="post"><button class="btn btn-ghost btn-sm" name="panel_logout" value="1">Sign out</button></form></div>
<?php if($panelMessage): ?><div class="banner <?=h($bannerClass)?>"><?=h($panelMessage)?><?php if($panelResult): ?><details><summary>Details</summary><pre><?=h(json_encode($panelResult,JSON_PRETTY_PRINT))?></pre></details><?php endif ?></div><?php endif ?>
<div class="card"><div class="card-title">Deployment Status</div>
<?php if(!empty($statusData)): ?>
<div class="stat-grid">
<div class="stat"><div class="stat-label">Status</div><div class="stat-value"><?=statusBadge($statusData['deploy_status']??'')?></div></div>
<div class="stat"><div class="stat-label">Last Deployed</div><div class="stat-value"><?=h($statusData['last_deployment']??'—')?></div></div>
<div class="stat"><div class="stat-label">Last Package</div><div class="stat-value" style="font-size:12px;"><?=h($statusData['last_package']??'—')?></div></div>
<div class="stat"><div class="stat-label">Deploys/Rollbacks</div><div class="stat-value"><?=(int)($statusData['deploy_count']??0)?>/<?=(int)($statusData['rollback_count']??0)?></div></div>
<div class="stat"><div class="stat-label">Backups</div><div class="stat-value"><?=(int)($statusData['backup_count']??0)?></div></div>
<div class="stat"><div class="stat-label">Server Time</div><div class="stat-value"><?=h($statusData['server_time']??'')?></div></div>
<?php if(!empty($statusData['install_id'])): ?><div class="stat"><div class="stat-label">Install ID</div><div class="stat-value" style="font-size:11px;"><?=h($statusData['install_id'])?></div></div><?php endif ?>
<?php if(!empty($statusData['last_error'])): ?><div class="stat" style="border-color:rgba(239,68,68,.4);"><div class="stat-label" style="color:var(--red);">Last Error</div><div class="stat-value" style="color:var(--red);font-size:12px;"><?=h($statusData['last_error'])?></div></div><?php endif ?>
</div><hr class="divider">
<div class="cfg-strip">
<div class="cfg-pill">project <span><?=h($statusData['project_name']??'?')?></span></div>
<div class="cfg-pill">mode <span><?=h($statusData['deployment_mode']??'?')?></span></div>
<div class="cfg-pill">target <span><?=h($statusData['target_root']??'?')?></span></div>
<?php if(!empty($statusData['pinned_package'])): ?><div class="cfg-pill">pinned <span><?=h($statusData['pinned_package'])?></span></div><?php endif ?>
<div class="cfg-pill">packages <span><?=(int)($statusData['package_count']??0)?></span></div>
</div><?php else: ?><p style="color:var(--muted);">Could not load status.</p><?php endif ?></div>
<div class="card"><div class="card-title">Quick Actions</div><div class="action-row">
<form method="post" onsubmit="return confirm('Deploy latest package?');"><button class="btn btn-success" name="panel_action" value="install">▶ Deploy Latest</button></form>
<form method="post" onsubmit="return confirm('Restore latest backup?');"><button class="btn btn-danger" name="panel_action" value="rollback">↩ Rollback</button></form>
</div></div>
<div class="card"><div class="card-title">Upload Package</div><form method="post" enctype="multipart/form-data"><div class="form-row"><div class="form-group"><label>ZIP file</label><input type="file" name="zip_file" accept=".zip" required></div><button class="btn btn-primary" name="panel_action" value="upload">Upload</button></div></form></div>
<div class="card"><div class="card-title">Pin Package</div><form method="post"><div class="form-row"><div class="form-group"><label>Filename (blank = use newest)</label><input type="text" name="pin_package" placeholder="2026-05-27_21-00-00_site.zip" value="<?=h($statusData['pinned_package']??'')?>"></div><button class="btn btn-ghost" name="panel_action" value="set-package">Pin</button></div></form></div>
<div class="card card-danger"><div class="card-title">⚠ Danger Zone — Clean Website Root</div>
<div class="danger-note">Removes <strong>all files</strong> from website root except <code>_deploy/</code> and preserveFiles.<br><strong>Full backup created automatically before deletion.</strong></div>
<div class="action-row" style="margin-bottom:14px;"><form method="post"><button class="btn btn-ghost" name="panel_action" value="clean-root-dry">🔍 Dry Run</button></form></div>
<?php if($dryRunResult!==null): ?><div class="dry-run-box"><div style="color:var(--yellow);font-weight:700;margin-bottom:6px;">WOULD DELETE (<?=(int)($dryRunResult['item_count']??0)?> items, <?=(int)($dryRunResult['file_count']??0)?> files):</div><?php foreach($dryRunResult['top_level']??[] as $f): ?><div class="dry-del">  ✗ <?=h($f)?></div><?php endforeach ?><?php if(!empty($dryRunResult['protected'])): ?><div style="color:var(--muted);margin-top:8px;font-weight:700;">PROTECTED:</div><?php foreach($dryRunResult['protected'] as $f): ?><div class="dry-keep">  ✓ <?=h($f)?></div><?php endforeach ?><?php endif ?></div><?php endif ?>
<form method="post" style="margin-top:16px;" onsubmit="var v=this.querySelector('[name=clean_confirm]').value;if(v!=='CLEAN'){alert('Type CLEAN exactly.');return false;}return confirm('⚠ Delete files from website root?\nBackup created first.');"><div class="form-row"><div class="form-group"><label style="color:var(--red);">Type CLEAN to confirm</label><input type="text" name="clean_confirm" placeholder="CLEAN" autocomplete="off" spellcheck="false" style="border-color:rgba(239,68,68,.4);"></div><button class="btn btn-danger" name="panel_action" value="clean-root">🗑 Clean Root</button></div></form></div>
<div class="card"><div class="card-title">Deployment Log (last 40, newest first)</div><div class="log-box"><?php if(empty($logsData)): ?><span style="color:var(--muted);">(no entries)</span><?php else: foreach(array_reverse($logsData) as $line): ?><div class="<?=logClass($line)?>"><?=h($line)?></div><?php endforeach;endif ?></div></div>
<?php endif ?></div></body></html>
PANEL_END;
}

// ═══════════════════════════════════════════════════════════════
// INSTALLATION LOGIC
// ═══════════════════════════════════════════════════════════════

function runInstall(bool $isReinstall): array {
    $webRoot   = dirname(__FILE__);
    $deployDir = $webRoot . '/' . DEPLOY_DIR_NAME;
    $log       = [];
    $errors    = [];
    $date      = date('Y-m-d H:i:s');

    // 1. Create directory structure
    $dirs = [
        $deployDir,
        $deployDir . '/_incoming',
        $deployDir . '/_tmp',
        $deployDir . '/_backups',
        $deployDir . '/_logs',
        $deployDir . '/_packages',
        $deployDir . '/_state',
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (@mkdir($dir, 0755, true)) { $log[] = ['ok', 'Created directory: ' . basename($dir)]; }
            else { $errors[] = 'Cannot create directory: ' . $dir; }
        } else {
            $log[] = ['info', 'Directory exists: ' . basename($dir)];
        }
    }

    if (!empty($errors)) {
        return ['success' => false, 'log' => $log, 'errors' => $errors, 'secret' => null, 'installId' => null];
    }

    // 2. Generate credentials
    $secret    = bin2hex(random_bytes(32));
    $installId = 'aid-' . substr(bin2hex(random_bytes(6)), 0, 10);

    // 3. Write all files
    $files = [
        $deployDir . '/config.php'                => tplConfig($secret, $installId, $date),
        $deployDir . '/ai-deploy.php'             => tplAiDeploy(),
        $deployDir . '/panel.php'                 => tplPanel(),
        $deployDir . '/.htaccess'                 => tplHtaccess(),
        $deployDir . '/deploy-config.json'        => tplDeployConfig(),
        $deployDir . '/_state/deploy-state.json'  => tplDeployState(),
    ];

    // Per-directory access block
    foreach (['_backups','_logs','_packages','_state','_tmp','_incoming'] as $sub) {
        $files[$deployDir . '/' . $sub . '/.htaccess'] = tplSubHtaccess();
    }

    foreach ($files as $filePath => $content) {
        if (@file_put_contents($filePath, $content) !== false) {
            $log[] = ['ok', 'Written: ' . ltrim(str_replace($deployDir, '', $filePath), '/')];
        } else {
            $errors[] = 'Cannot write: ' . $filePath;
        }
    }

    // 4. Write install log
    $installLog   = $deployDir . '/_logs/install.log';
    $logLines     = ["[{$date}] AI Deploy Agent v3 — Installation"];
    $logLines[]   = "[{$date}] Install ID: {$installId}";
    $logLines[]   = "[{$date}] Reinstall: " . ($isReinstall ? 'YES' : 'NO');
    $logLines[]   = "[{$date}] Web root: {$webRoot}";
    foreach ($log as [$type, $msg]) { $logLines[] = "[{$date}] [{$type}] {$msg}"; }
    if (empty($errors)) { $logLines[] = "[{$date}] Installation completed successfully"; }
    else {
        foreach ($errors as $e) { $logLines[] = "[{$date}] [ERROR] {$e}"; }
        $logLines[] = "[{$date}] Installation completed with errors";
    }
    @file_put_contents($installLog, implode("\n", $logLines) . "\n");
    $log[] = ['ok', 'install.log written'];

    return [
        'success'   => empty($errors),
        'log'       => $log,
        'errors'    => $errors,
        'secret'    => empty($errors) ? $secret : null,
        'installId' => $installId,
        'panelUrl'  => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                       . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/_deploy/panel.php',
        'agentUrl'  => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                       . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/_deploy/ai-deploy.php',
    ];
}

// ═══════════════════════════════════════════════════════════════
// REQUEST HANDLING
// ═══════════════════════════════════════════════════════════════

$req         = checkRequirements();
$installResult = null;
$formError     = null;
$isPost        = ($_SERVER['REQUEST_METHOD'] === 'POST');
$action        = $_POST['action'] ?? '';

if ($isPost && $action === 'install') {
    // Safety: never install on GET
    if ($req['deployExists']) {
        $confirm = trim($_POST['reinstall_confirm'] ?? '');
        if ($confirm !== 'REINSTALL') {
            $formError = 'Existing installation detected. Type <strong>REINSTALL</strong> in the confirmation field to overwrite it.';
        } else {
            $installResult = runInstall(true);
        }
    } else {
        $installResult = runInstall(false);
    }
}

// ═══════════════════════════════════════════════════════════════
// HTML OUTPUT
// ═══════════════════════════════════════════════════════════════

function he(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Deploy Agent — Installer</title>
<style>
  :root{--bg:#0f1117;--surface:#1a1d27;--surface2:#22263a;--border:#2a2d3a;--text:#d4d8e8;--muted:#6b7280;--accent:#4f8ef7;--green:#22c55e;--red:#ef4444;--yellow:#f59e0b;--radius:8px}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font:14px/1.6 'Segoe UI',system-ui,sans-serif;padding:32px 16px}
  .wrap{max-width:680px;margin:0 auto}
  h1{font-size:24px;font-weight:700;color:var(--accent);margin-bottom:6px}
  .sub{color:var(--muted);font-size:13px;margin-bottom:28px}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:22px;margin-bottom:16px}
  .card-title{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px}
  .check-row{display:flex;align-items:flex-start;gap:10px;padding:7px 0;border-bottom:1px solid var(--border)}
  .check-row:last-child{border-bottom:none}
  .check-icon{width:18px;flex-shrink:0;font-size:14px;margin-top:2px}
  .check-name{flex:1;font-weight:500}
  .check-detail{font-size:12px;color:var(--muted);margin-top:1px}
  .pass{color:var(--green)}.fail{color:var(--red)}.warn{color:var(--yellow)}.info-c{color:var(--muted)}
  input[type=text]{background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:9px 12px;font-size:13px;width:100%}
  input:focus{outline:2px solid var(--accent);border-color:transparent}
  .btn{display:inline-block;padding:11px 22px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;transition:opacity .12s}
  .btn:hover{opacity:.85}.btn:disabled{opacity:.4;cursor:not-allowed}
  .btn-primary{background:var(--accent);color:#fff;width:100%}
  .alert{padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:14px}
  .alert-warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:var(--yellow)}
  .alert-fail{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--red)}
  .alert-ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:var(--green)}
  .token-box{background:var(--bg);border:2px solid var(--yellow);border-radius:8px;padding:16px 20px;font-family:'Cascadia Code','Fira Code',monospace;font-size:15px;font-weight:700;letter-spacing:.04em;word-break:break-all;color:var(--yellow);margin:12px 0;text-align:center}
  .log-box{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:12px;font:12px/1.7 'Cascadia Code','Fira Code',monospace;max-height:240px;overflow-y:auto;margin-top:10px}
  .log-ok{color:var(--green)}.log-info{color:var(--muted)}.log-err{color:var(--red)}
  .url-box{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:10px 14px;font:13px monospace;word-break:break-all;margin:6px 0}
  .steps{counter-reset:step;list-style:none;padding:0}
  .steps li{counter-increment:step;padding:8px 0 8px 36px;position:relative;border-bottom:1px solid var(--border)}
  .steps li:last-child{border-bottom:none}
  .steps li::before{content:counter(step);position:absolute;left:0;top:8px;width:24px;height:24px;background:var(--accent);color:#fff;border-radius:50%;text-align:center;line-height:24px;font-size:12px;font-weight:700}
  .warning-box{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.3);border-radius:6px;padding:14px 16px;margin-top:14px}
  .warning-box h3{color:var(--red);font-size:13px;margin-bottom:8px}
  label{font-size:12px;color:var(--muted);display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em}
</style>
</head>
<body>
<div class="wrap">

<h1>⚡ AI Deploy Agent</h1>
<p class="sub">One-click installer · v<?= INSTALLER_VERSION ?> · PHP <?= PHP_VERSION ?></p>

<?php if ($installResult !== null): /* ══════════════ RESULT SCREEN ══════════════ */ ?>

  <?php if ($installResult['success']): ?>

  <div class="alert alert-ok" style="font-size:15px;font-weight:700;">✓ Installation complete!</div>

  <!-- Token — shown once -->
  <div class="card">
    <div class="card-title">🔑 Your Deployment Token — Save This Now</div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:10px;">This token is shown <strong style="color:var(--yellow)">only once</strong>. Copy it to your local <code>.env</code> file as <code>DEPLOY_TOKEN</code>. If you lose it, reinstall.</p>
    <div class="token-box"><?= he($installResult['secret']) ?></div>
    <p style="font-size:12px;color:var(--muted);margin-top:8px;">Installation ID: <code><?= he($installResult['installId']) ?></code></p>
  </div>

  <!-- URLs -->
  <div class="card">
    <div class="card-title">Deployment Endpoints</div>
    <label>Web Panel (bookmark this)</label>
    <div class="url-box"><a href="<?= he($installResult['panelUrl']) ?>" target="_blank" style="color:var(--accent);"><?= he($installResult['panelUrl']) ?></a></div>
    <label style="margin-top:10px;">Deploy Agent URL (goes in .env as DEPLOY_URL)</label>
    <div class="url-box"><?= he($installResult['agentUrl']) ?></div>
  </div>

  <!-- Next steps -->
  <div class="card">
    <div class="card-title">Next Steps</div>
    <ol class="steps">
      <li>Copy the token above into your local <code>.env</code> as <code>DEPLOY_TOKEN</code>.</li>
      <li>Copy the agent URL into <code>.env</code> as <code>DEPLOY_URL</code>.</li>
      <li>Edit <code>/_deploy/deploy-config.json</code> — set <code>projectName</code> and <code>targetRoot</code>.</li>
      <li>Open the panel: <a href="<?= he($installResult['panelUrl']) ?>" style="color:var(--accent);">panel.php</a></li>
      <li><strong style="color:var(--red)">Delete <code>install-ai-deploy.php</code> from your server.</strong></li>
    </ol>
  </div>

  <!-- Install log -->
  <div class="card">
    <div class="card-title">Installation Log</div>
    <div class="log-box">
    <?php foreach ($installResult['log'] as [$type, $msg]): ?>
      <div class="log-<?= $type === 'ok' ? 'ok' : 'info' ?>"><?= he($msg) ?></div>
    <?php endforeach ?>
    </div>
  </div>

  <?php else: /* install failed */ ?>

  <div class="alert alert-fail"><strong>Installation failed.</strong> See errors below.</div>
  <div class="card">
    <div class="card-title">Errors</div>
    <?php foreach ($installResult['errors'] as $e): ?>
    <div style="color:var(--red);padding:4px 0;font-size:13px;">✗ <?= he($e) ?></div>
    <?php endforeach ?>
  </div>

  <?php endif ?>

<?php else: /* ══════════════ INSTALL FORM SCREEN ══════════════ */ ?>

  <!-- System Checks -->
  <div class="card">
    <div class="card-title">System Requirements</div>
    <?php foreach ($req['checks'] as $check): ?>
    <div class="check-row">
      <div class="check-icon">
        <?php if ($check['pass'] === null || !empty($check['info'])): ?>
          <span class="info-c">ℹ</span>
        <?php elseif ($check['pass']): ?>
          <span class="pass">✓</span>
        <?php elseif (!empty($check['warn'])): ?>
          <span class="warn">⚠</span>
        <?php else: ?>
          <span class="fail">✗</span>
        <?php endif ?>
      </div>
      <div>
        <div class="check-name <?= ($check['pass'] === false && empty($check['warn'])) ? 'fail' : ($check['pass'] === false ? 'warn' : '') ?>"><?= he($check['name']) ?></div>
        <div class="check-detail"><?= he($check['detail']) ?></div>
      </div>
    </div>
    <?php endforeach ?>
  </div>

  <?php if ($formError): ?>
  <div class="alert alert-fail"><?= $formError /* already contains safe HTML */ ?></div>
  <?php endif ?>

  <?php if ($req['fatal']): ?>

  <!-- Fatal error — cannot install -->
  <div class="alert alert-fail">
    <strong>Cannot install.</strong> One or more requirements are not met.<br>
    Fix the issues above and reload this page.
  </div>

  <?php elseif ($req['deployExists']): ?>

  <!-- Reinstall warning -->
  <div class="card">
    <div class="card-title">Reinstall — Confirmation Required</div>
    <div class="alert alert-warn" style="margin-bottom:14px;">
      <strong>⚠ An existing <code>/_deploy/</code> installation was detected.</strong><br>
      Reinstalling will overwrite <code>ai-deploy.php</code>, <code>panel.php</code>, and <code>config.php</code>.<br>
      A new secret token will be generated. Your existing backups and packages are preserved.
    </div>
    <form method="post">
      <input type="hidden" name="action" value="install">
      <div style="margin-bottom:14px;">
        <label>Type <strong>REINSTALL</strong> to confirm overwrite</label>
        <input type="text" name="reinstall_confirm" placeholder="REINSTALL" autocomplete="off" spellcheck="false"
          style="border-color:rgba(245,158,11,.5);">
      </div>
      <button type="submit" class="btn btn-primary" style="background:var(--yellow);color:#000;">
        ⚡ Reinstall AI Deploy Agent
      </button>
    </form>
  </div>

  <?php else: ?>

  <!-- Fresh install -->
  <div class="card">
    <div class="card-title">Fresh Installation</div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">
      No existing installation found. Click below to create the complete <code>/_deploy/</code> structure,
      generate your deployment token, and write all configuration files.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="install">
      <button type="submit" class="btn btn-primary">⚡ Install AI Deploy Agent</button>
    </form>
  </div>

  <!-- What gets created -->
  <div class="card">
    <div class="card-title">What Will Be Created</div>
    <div style="font:12px/1.9 'Cascadia Code','Fira Code',monospace;color:var(--muted);">
      /_deploy/<br>
      &nbsp;&nbsp;config.php &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span style="color:var(--green);">← generated token</span><br>
      &nbsp;&nbsp;ai-deploy.php &nbsp;&nbsp;<span style="color:var(--accent);">← deployment engine</span><br>
      &nbsp;&nbsp;panel.php &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span style="color:var(--accent);">← web panel</span><br>
      &nbsp;&nbsp;.htaccess &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span style="color:var(--muted);">← access control</span><br>
      &nbsp;&nbsp;deploy-config.json<br>
      &nbsp;&nbsp;_state/deploy-state.json<br>
      &nbsp;&nbsp;_backups/ &nbsp;_logs/ &nbsp;_packages/ &nbsp;_tmp/ &nbsp;_incoming/
    </div>
  </div>

  <?php endif ?>

  <!-- Security reminder -->
  <div class="card" style="border-color:rgba(239,68,68,.3);">
    <div class="card-title" style="color:var(--red);">Security Reminder</div>
    <ul style="font-size:13px;color:var(--muted);padding-left:18px;line-height:2;">
      <li>After installation, <strong style="color:var(--red);">delete this file</strong> from your server.</li>
      <li>Never commit <code>config.php</code> or <code>.env</code> to version control.</li>
      <li>Use HTTPS when sending the deployment token.</li>
    </ul>
  </div>

<?php endif ?>

<p style="text-align:center;color:var(--muted);font-size:12px;margin-top:24px;">
  AI Deploy Agent v<?= INSTALLER_VERSION ?> · Installer
</p>

</div><!-- /wrap -->
</body>
</html>
