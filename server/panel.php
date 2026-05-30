<?php
/**
 * panel.php — v3
 * AI Deploy Agent · Lightweight Web Panel
 * Token loaded from config.php — no hardcoded secret.
 */

// ── Token loaded from config.php ─────────────────────────────
require_once __DIR__ . '/config.php';

define('SESSION_LIFETIME', 3600);
define('AGENT_URL',        ''); // auto-detected if empty

session_start();

$agentUrl = AGENT_URL ?: (
    (isset($_SERVER['HTTPS']) ? 'https' : 'http') .
    '://' . $_SERVER['HTTP_HOST'] .
    rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/ai-deploy.php'
);

// ── Auth (uses DEPLOY_SECRET from config.php) ─────────────────
$loggedIn = (
    isset($_SESSION['panel_auth']) &&
    $_SESSION['panel_auth'] === true &&
    isset($_SESSION['panel_time']) &&
    (time() - $_SESSION['panel_time']) < SESSION_LIFETIME
);

if (isset($_POST['panel_login'])) {
    if (hash_equals(DEPLOY_SECRET, trim($_POST['panel_token'] ?? ''))) {
        $_SESSION['panel_auth'] = true;
        $_SESSION['panel_time'] = time();
        $loggedIn = true;
    } else {
        $loginError = 'Invalid token.';
    }
}

if (isset($_POST['panel_logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Internal cURL proxy to ai-deploy.php ─────────────────────
function agentCall(string $url, array $fields, ?array $uploadFile = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if ($uploadFile) {
        $cf = new CURLFile($uploadFile['tmp_name'], $uploadFile['type'] ?: 'application/zip', $uploadFile['name']);
        $fields['package'] = $cf;
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) return ['status' => 'error', 'message' => 'cURL error: ' . $err];
    $d = json_decode($raw, true);
    return $d ?: ['status' => 'error', 'message' => 'Invalid JSON response'];
}

// ── Panel action handler ──────────────────────────────────────
$panelMessage  = '';
$panelResult   = null;
$bannerClass   = 'banner-ok';
$dryRunResult  = null;

if ($loggedIn && isset($_POST['panel_action'])) {
    $act  = $_POST['panel_action'];
    $base = ['token' => DEPLOY_SECRET];

    if ($act === 'install') {
        $panelResult  = agentCall($agentUrl, array_merge($base, ['action' => 'install']));
        $panelMessage = $panelResult['status'] === 'ok'
            ? "✓ Deployed: {$panelResult['package']} ({$panelResult['extracted']} files)"
            : 'Error: ' . ($panelResult['message'] ?? 'unknown');
        $bannerClass = $panelResult['status'] === 'ok' ? 'banner-ok' : 'banner-fail';
    }
    if ($act === 'rollback') {
        $panelResult  = agentCall($agentUrl, array_merge($base, ['action' => 'rollback']));
        $panelMessage = $panelResult['status'] === 'ok'
            ? "✓ Rolled back to: {$panelResult['restored']} ({$panelResult['files']} files)"
            : 'Error: ' . ($panelResult['message'] ?? 'unknown');
        $bannerClass = $panelResult['status'] === 'ok' ? 'banner-ok' : 'banner-fail';
    }
    if ($act === 'upload' && isset($_FILES['zip_file']) && $_FILES['zip_file']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['zip_file'];
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'zip') {
            $panelMessage = 'Error: Only .zip files are accepted.'; $bannerClass = 'banner-fail';
        } else {
            $panelResult  = agentCall($agentUrl, array_merge($base, ['action' => 'upload']), $f);
            $panelMessage = $panelResult['status'] === 'ok'
                ? "✓ Uploaded: {$panelResult['package']} ({$panelResult['size_kb']} KB). Ready to install."
                : 'Error: ' . ($panelResult['message'] ?? 'unknown');
            $bannerClass = $panelResult['status'] === 'ok' ? 'banner-ok' : 'banner-fail';
        }
    }
    if ($act === 'set-package') {
        $pkg = trim($_POST['pin_package'] ?? '');
        $panelResult = agentCall($agentUrl, array_merge($base, ['action' => 'update-config', 'latestPackage' => $pkg]));
        $panelMessage = $panelResult['status'] === 'ok'
            ? "✓ Pinned: " . ($pkg ?: '(cleared — will use newest)')
            : 'Error: ' . ($panelResult['message'] ?? 'unknown');
        $bannerClass = $panelResult['status'] === 'ok' ? 'banner-ok' : 'banner-fail';
    }
    if ($act === 'clean-root-dry') {
        $dryRunResult = agentCall($agentUrl, array_merge($base, ['action' => 'clean_root_dry']));
        if ($dryRunResult['status'] === 'ok') {
            $panelMessage = "Dry-run: {$dryRunResult['message']}"; $bannerClass = 'banner-warn';
        } else {
            $panelMessage = 'Dry-run error: ' . ($dryRunResult['message'] ?? 'unknown');
            $bannerClass = 'banner-fail'; $dryRunResult = null;
        }
    }
    if ($act === 'clean-root') {
        $confirm = trim($_POST['clean_confirm'] ?? '');
        if ($confirm !== 'CLEAN') {
            $panelMessage = 'Confirmation required: type CLEAN in the field.'; $bannerClass = 'banner-fail';
        } else {
            $panelResult  = agentCall($agentUrl, array_merge($base, ['action' => 'clean_root', 'confirm' => 'CLEAN']));
            $panelMessage = $panelResult['status'] === 'ok'
                ? "✓ Root cleaned: {$panelResult['deleted_files']} files, {$panelResult['deleted_dirs']} dirs. Backup: {$panelResult['backup']}"
                : 'Error: ' . ($panelResult['message'] ?? 'unknown');
            $bannerClass = $panelResult['status'] === 'ok' ? 'banner-ok' : 'banner-fail';
        }
    }
}

// ── Fetch status + logs ───────────────────────────────────────
$statusData = []; $logsData = [];
if ($loggedIn) {
    $r = agentCall($agentUrl, ['token' => DEPLOY_SECRET, 'action' => 'status']);
    if (($r['status'] ?? '') === 'ok') $statusData = $r['data'] ?? [];
    $r2 = agentCall($agentUrl, ['token' => DEPLOY_SECRET, 'action' => 'logs', 'lines' => '40']);
    if (($r2['status'] ?? '') === 'ok') $logsData = $r2['logs'] ?? [];
}

// ── Helpers ───────────────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function logClass(string $line): string {
    if (strpos($line, ' OK')      !== false) return 'log-ok';
    if (strpos($line, 'FAILED')   !== false) return 'log-fail';
    if (strpos($line, 'BLOCKED')  !== false) return 'log-fail';
    if (strpos($line, 'REJECTED') !== false) return 'log-warn';
    if (strpos($line, 'WARNING')  !== false) return 'log-warn';
    if (strpos($line, 'ROLLBACK') !== false) return 'log-roll';
    if (strpos($line, 'CONFIG')   !== false) return 'log-cfg';
    if (strpos($line, 'INSTALL')  !== false) return 'log-inst';
    return '';
}
function statusBadge(string $s): string {
    return match($s) {
        'success'     => '<span class="badge badge-green">success</span>',
        'rolled_back' => '<span class="badge badge-yellow">rolled back</span>',
        'failed'      => '<span class="badge badge-red">failed</span>',
        'pending'     => '<span class="badge badge-gray">pending</span>',
        'cleaned'     => '<span class="badge badge-orange">cleaned</span>',
        default       => '<span class="badge badge-gray">' . h($s) . '</span>',
    };
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Deploy · <?= h($statusData['project_name'] ?? 'panel') ?></title>
<style>
  :root {
    --bg:#0f1117;--surface:#1a1d27;--surface2:#22263a;--border:#2a2d3a;
    --text:#d4d8e8;--muted:#6b7280;--accent:#4f8ef7;--green:#22c55e;
    --red:#ef4444;--yellow:#f59e0b;--purple:#a78bfa;--cyan:#22d3ee;--radius:8px;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font:14px/1.6 'Segoe UI',system-ui,sans-serif}
  .container{max-width:960px;margin:0 auto;padding:28px 16px}
  .topbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:24px}
  .logo{font-size:18px;font-weight:700;color:var(--accent)}
  .logo .project{color:var(--text)} .logo .v{color:var(--muted);font-size:12px;margin-left:6px}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:14px}
  .card-title{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px}
  .stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:10px}
  .stat{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:11px 14px}
  .stat-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
  .stat-value{font-size:14px;font-weight:600;word-break:break-all;line-height:1.3}
  .cfg-strip{display:flex;flex-wrap:wrap;gap:8px}
  .cfg-pill{background:var(--surface2);border:1px solid var(--border);border-radius:20px;padding:4px 12px;font-size:12px;color:var(--muted)}
  .cfg-pill span{color:var(--text);font-weight:600}
  .badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700}
  .badge-green{background:rgba(34,197,94,.15);color:var(--green)}
  .badge-yellow{background:rgba(245,158,11,.15);color:var(--yellow)}
  .badge-red{background:rgba(239,68,68,.15);color:var(--red)}
  .badge-gray{background:rgba(107,114,128,.15);color:var(--muted)}
  .badge-orange{background:rgba(249,115,22,.15);color:#f97316}
  .btn{display:inline-block;padding:9px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;transition:opacity .12s;white-space:nowrap}
  .btn:hover{opacity:.82}
  .btn-primary{background:var(--accent);color:#fff}
  .btn-success{background:var(--green);color:#fff}
  .btn-danger{background:var(--red);color:#fff}
  .btn-ghost{background:var(--border);color:var(--text)}
  .btn-sm{padding:6px 12px;font-size:12px}
  .action-row{display:flex;gap:10px;flex-wrap:wrap}
  input[type=password],input[type=file],input[type=text]{background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:8px 12px;font-size:13px;width:100%}
  input:focus{outline:2px solid var(--accent);border-color:transparent}
  .form-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
  .form-group{flex:1;min-width:180px}
  .form-group label{font-size:11px;color:var(--muted);display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em}
  .banner{padding:10px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;font-weight:500}
  .banner-ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:var(--green)}
  .banner-fail{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--red)}
  .banner-warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:var(--yellow)}
  .log-box{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:12px 14px;font:12px/1.75 'Cascadia Code','Fira Code',monospace;max-height:380px;overflow-y:auto}
  .log-ok{color:var(--green)} .log-fail{color:var(--red)} .log-warn{color:var(--yellow)}
  .log-roll{color:var(--purple)} .log-cfg{color:var(--cyan)} .log-inst{color:#60a5fa}
  details summary{cursor:pointer;color:var(--muted);font-size:11px;margin-top:8px}
  pre{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px;font-size:11px;overflow-x:auto;margin-top:6px}
  .divider{border:none;border-top:1px solid var(--border);margin:14px 0}
  .card-danger{border-color:rgba(239,68,68,.4)}
  .card-danger .card-title{color:var(--red)}
  .danger-note{font-size:12px;color:var(--muted);margin-bottom:14px;line-height:1.5}
  .danger-note strong{color:var(--yellow)}
  .dry-run-box{background:var(--bg);border:1px solid rgba(245,158,11,.3);border-radius:6px;padding:12px 14px;font:12px/1.75 'Cascadia Code','Fira Code',monospace;max-height:260px;overflow-y:auto;margin-top:12px}
  .dry-del{color:var(--red)} .dry-keep{color:var(--green)}
  .login-wrap{max-width:380px;margin:72px auto}
  .login-logo{text-align:center;font-size:22px;font-weight:700;color:var(--accent);margin-bottom:24px}
  @media(max-width:520px){.action-row{flex-direction:column}}
</style>
</head>
<body><div class="container">

<?php if (!$loggedIn): ?>
<div class="login-wrap">
  <div class="login-logo">⚡ AI Deploy Agent</div>
  <div class="card">
    <div class="card-title">Authentication</div>
    <?php if (!empty($loginError)): ?><div class="banner banner-fail"><?= h($loginError) ?></div><?php endif ?>
    <form method="post">
      <div class="form-group" style="margin-bottom:14px;">
        <label>Deployment Token</label>
        <input type="password" name="panel_token" placeholder="Enter token…" autofocus required>
      </div>
      <button class="btn btn-primary" style="width:100%;" name="panel_login" value="1">Sign in</button>
    </form>
  </div>
</div>

<?php else: ?>
<div class="topbar">
  <div>
    <div class="logo">⚡ AI Deploy <span class="project"><?= h($statusData['project_name'] ?? 'Agent') ?></span><span class="v">v3</span></div>
    <div style="font-size:11px;color:var(--muted);margin-top:2px;"><?= h($agentUrl) ?></div>
  </div>
  <form method="post"><button class="btn btn-ghost btn-sm" name="panel_logout" value="1">Sign out</button></form>
</div>

<?php if ($panelMessage): ?>
<div class="banner <?= h($bannerClass) ?>">
  <?= h($panelMessage) ?>
  <?php if ($panelResult): ?>
  <details><summary>Details</summary><pre><?= h(json_encode($panelResult, JSON_PRETTY_PRINT)) ?></pre></details>
  <?php endif ?>
</div>
<?php endif ?>

<!-- Status -->
<div class="card">
  <div class="card-title">Deployment Status</div>
  <?php if (!empty($statusData)): ?>
  <div class="stat-grid">
    <div class="stat"><div class="stat-label">Status</div><div class="stat-value"><?= statusBadge($statusData['deploy_status'] ?? '') ?></div></div>
    <div class="stat"><div class="stat-label">Last Deployed</div><div class="stat-value"><?= h($statusData['last_deployment'] ?? '—') ?></div></div>
    <div class="stat"><div class="stat-label">Last Package</div><div class="stat-value" style="font-size:12px;"><?= h($statusData['last_package'] ?? '—') ?></div></div>
    <div class="stat"><div class="stat-label">Version</div><div class="stat-value"><?= h($statusData['current_version'] ?? '—') ?></div></div>
    <div class="stat"><div class="stat-label">Deploys / Rollbacks</div><div class="stat-value"><?= (int)($statusData['deploy_count'] ?? 0) ?> / <?= (int)($statusData['rollback_count'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">Backups</div><div class="stat-value"><?= (int)($statusData['backup_count'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">Server Time</div><div class="stat-value"><?= h($statusData['server_time'] ?? '') ?></div></div>
    <?php if (!empty($statusData['install_id'])): ?>
    <div class="stat"><div class="stat-label">Install ID</div><div class="stat-value" style="font-size:11px;"><?= h($statusData['install_id']) ?></div></div>
    <?php endif ?>
    <?php if (!empty($statusData['last_error'])): ?>
    <div class="stat" style="border-color:rgba(239,68,68,.4);">
      <div class="stat-label" style="color:var(--red);">Last Error</div>
      <div class="stat-value" style="color:var(--red);font-size:12px;"><?= h($statusData['last_error']) ?></div>
    </div>
    <?php endif ?>
  </div>
  <hr class="divider">
  <div class="cfg-strip">
    <div class="cfg-pill">project <span><?= h($statusData['project_name'] ?? '?') ?></span></div>
    <div class="cfg-pill">mode <span><?= h($statusData['deployment_mode'] ?? '?') ?></span></div>
    <div class="cfg-pill">target <span><?= h($statusData['target_root'] ?? '?') ?></span></div>
    <?php if (!empty($statusData['pinned_package'])): ?>
    <div class="cfg-pill">pinned <span><?= h($statusData['pinned_package']) ?></span></div>
    <?php endif ?>
    <div class="cfg-pill">packages <span><?= (int)($statusData['package_count'] ?? 0) ?></span></div>
  </div>
  <?php else: ?>
  <p style="color:var(--muted);">Could not load status from agent.</p>
  <?php endif ?>
</div>

<!-- Quick Actions -->
<div class="card">
  <div class="card-title">Quick Actions</div>
  <div class="action-row">
    <form method="post" onsubmit="return confirm('Deploy the latest package now?');">
      <button class="btn btn-success" name="panel_action" value="install">▶ Deploy Latest</button>
    </form>
    <form method="post" onsubmit="return confirm('Restore the latest backup?');">
      <button class="btn btn-danger" name="panel_action" value="rollback">↩ Rollback</button>
    </form>
  </div>
</div>

<!-- Upload -->
<div class="card">
  <div class="card-title">Upload Package</div>
  <form method="post" enctype="multipart/form-data">
    <div class="form-row">
      <div class="form-group"><label>Select ZIP file</label><input type="file" name="zip_file" accept=".zip" required></div>
      <button class="btn btn-primary" name="panel_action" value="upload">Upload</button>
    </div>
  </form>
</div>

<!-- Pin Package -->
<div class="card">
  <div class="card-title">Pin Package <span style="color:var(--muted);text-transform:none;font-size:11px;">— force next install to use a specific file</span></div>
  <form method="post">
    <div class="form-row">
      <div class="form-group">
        <label>Package filename (blank = use newest)</label>
        <input type="text" name="pin_package" placeholder="2026-05-27_21-00-00_site-deploy.zip" value="<?= h($statusData['pinned_package'] ?? '') ?>">
      </div>
      <button class="btn btn-ghost" name="panel_action" value="set-package">Pin</button>
    </div>
  </form>
</div>

<!-- Danger Zone -->
<div class="card card-danger">
  <div class="card-title">⚠ Danger Zone — Clean Website Root</div>
  <div class="danger-note">
    Removes <strong>all files and folders</strong> from the website root except <code>_deploy/</code> and <code>preserveFiles</code>.<br>
    <strong>A full backup is created automatically before any deletion.</strong>
  </div>
  <div class="action-row" style="margin-bottom:14px;">
    <form method="post">
      <button class="btn btn-ghost" name="panel_action" value="clean-root-dry">🔍 Dry Run</button>
    </form>
  </div>
  <?php if ($dryRunResult !== null): ?>
  <div class="dry-run-box">
    <div style="color:var(--yellow);font-weight:700;margin-bottom:6px;">WOULD DELETE (<?= (int)($dryRunResult['item_count'] ?? 0) ?> items, <?= (int)($dryRunResult['file_count'] ?? 0) ?> files):</div>
    <?php foreach ($dryRunResult['top_level'] ?? [] as $f): ?><div class="dry-del">  ✗ <?= h($f) ?></div><?php endforeach ?>
    <?php if (!empty($dryRunResult['protected'])): ?>
    <div style="color:var(--muted);margin-top:8px;font-weight:700;">PROTECTED (will NOT be touched):</div>
    <?php foreach ($dryRunResult['protected'] as $f): ?><div class="dry-keep">  ✓ <?= h($f) ?></div><?php endforeach ?>
    <?php endif ?>
  </div>
  <?php endif ?>
  <form method="post" style="margin-top:16px;"
    onsubmit="var v=this.querySelector('[name=clean_confirm]').value;if(v!=='CLEAN'){alert('You must type CLEAN exactly.');return false;}return confirm('⚠ This will delete files from the website root.\nBackup will be created first.\n\nContinue?');">
    <div class="form-row">
      <div class="form-group">
        <label style="color:var(--red);">Type CLEAN to confirm</label>
        <input type="text" name="clean_confirm" placeholder="CLEAN" autocomplete="off" spellcheck="false" style="border-color:rgba(239,68,68,.4);">
      </div>
      <button class="btn btn-danger" name="panel_action" value="clean-root">🗑 Clean Root</button>
    </div>
  </form>
</div>

<!-- Logs -->
<div class="card">
  <div class="card-title">Deployment Log <span style="color:var(--muted);text-transform:none;font-size:11px;">(last 40 entries, newest first)</span></div>
  <div class="log-box">
    <?php if (empty($logsData)): ?>
    <span style="color:var(--muted);">(no entries)</span>
    <?php else: foreach (array_reverse($logsData) as $line): ?>
    <div class="<?= logClass($line) ?>"><?= h($line) ?></div>
    <?php endforeach; endif ?>
  </div>
</div>

<?php endif ?>
</div></body></html>
