# AI Deploy Agent — v3

One-click installer + config-driven deployment toolkit.  
Upload one file. Open in browser. Click install. Deploy in seconds.

> **v3 changes from v2:**
> - `install-ai-deploy.php` — self-contained one-click installer (single file upload)
> - `config.php` — generated on install, holds the token (never hardcoded again)
> - `ai-deploy.php` and `panel.php` no longer contain any secrets
> - State file moved to `/_deploy/_state/deploy-state.json`
> - `config.php` added to `preserveFiles` — never overwritten by deployment

---

## Project structure

```
AI-Deploy-Agent-v3/
  VERSION
  README.md

  installer/
    install-ai-deploy.php    ← one-click installer (upload this)

  server/                    ← reference source files
    ai-deploy.php            ← deployment engine (loads config.php)
    panel.php                ← web panel (loads config.php)
    config.php.example       ← template showing what config.php looks like
    deploy-config.json
    deploy-state.json

  client/                    ← local Windows deployment client
    command-router.js
    deploy.js  rollback.js  status.js  logs.js
    set-source.js  set-package.js  set-target.js  clean-root.js
    package.json  .env.example  deploy.bat
```

---

## 1. Installation (server side)

### Step 1 — Upload the installer

Upload **only** `installer/install-ai-deploy.php` to your website root.

```
public_html/
  install-ai-deploy.php    ← upload this
  (your existing site files)
```

### Step 2 — Open in browser

```
https://yourdomain.com/install-ai-deploy.php
```

The installer shows:
- PHP version check
- ZipArchive availability
- Write permission check
- Whether `_deploy/` already exists

### Step 3 — Click Install

Click **⚡ Install AI Deploy Agent**.

The installer creates:

```
/_deploy/
  config.php               ← generated with random DEPLOY_SECRET
  ai-deploy.php            ← deployment engine
  panel.php                ← web panel
  .htaccess                ← access control
  deploy-config.json       ← deployment settings
  _state/
    deploy-state.json
  _backups/  _logs/  _packages/  _tmp/  _incoming/
```

### Step 4 — Save your token

The installer shows your `DEPLOY_SECRET` **once**. Copy it immediately.

```
DEPLOY_TOKEN=your_generated_token_here
```

It goes into your local `.env` file (see section 2).

### Step 5 — Delete the installer

```
⚠ Delete install-ai-deploy.php from your server immediately.
```

It is no longer needed and should not remain publicly accessible.

---

## 2. Local client setup

```cmd
cd AI-Deploy-Agent-v3\client
npm install
copy .env.example .env
```

Edit `.env`:

```env
DEPLOY_URL=https://yourdomain.com/_deploy/ai-deploy.php
DEPLOY_TOKEN=paste_your_generated_token_here
SOURCE_DIR=../dist
ZIP_NAME=site-deploy.zip
```

---

## 3. Configuration (server side)

### deploy-config.json

Edit `/_deploy/deploy-config.json` on the server:

```json
{
  "projectName": "my-website",
  "targetRoot": "/home/user/public_html",
  "packageDir": "_packages",
  "backupDir": "_backups",
  "logsDir": "_logs",
  "incomingDir": "_incoming",
  "latestPackage": "",
  "deploymentMode": "upload_install",
  "maxBackups": 10,
  "maxPackages": 5,
  "preserveFiles": [
    "ai-deploy.php",
    "panel.php",
    "config.php",
    "deploy-config.json"
  ]
}
```

Set `targetRoot` to your website root absolute path (e.g., `/home/user/public_html`).  
Leave empty to use the parent of `/_deploy/` automatically.

### config.php

Generated automatically by installer. Contains only:

```php
define('DEPLOY_SECRET', 'your_token');
define('INSTALL_ID',    'aid-xxxxxxxxxx');
define('INSTALL_DATE',  '2026-05-21 00:00:00');
```

Never edit manually. Never commit to version control.

---

## 4. Reinstallation

If you need to reinstall (e.g. to rotate your token):

1. Open `install-ai-deploy.php` in your browser.
2. The installer detects the existing `/_deploy/` and shows a reinstall warning.
3. Type `REINSTALL` in the confirmation field.
4. Click Reinstall — a new token is generated.
5. Save the new token → update your local `.env`.
6. Delete the installer again.

Your backups, packages, and logs are preserved during reinstall.

---

## 5. Commands

```cmd
cd AI-Deploy-Agent-v3\client

deploy.bat /deploy                                Full deploy (upload + install)
deploy.bat /deploy:upload                         Upload ZIP, queue on server
deploy.bat /deploy:install                        Install latest/pinned package
deploy.bat /rollback                              Restore latest backup
deploy.bat /status                                Show project + deployment status
deploy.bat /logs [N]                              Show last N log lines

deploy.bat /set-source "C:\projects\site\dist"   Change local source directory
deploy.bat /set-package "build-102.zip"           Pin specific package on server
deploy.bat /set-package ""                        Clear pin (use newest)
deploy.bat /set-target "my-website"               Switch active project name

deploy.bat /clean-root:dry-run                    Preview root cleanup
deploy.bat /clean-root                            ⚠ Delete non-protected files
```

---

## 6. First deploy

```
1. Install via installer (see section 1)
2. Configure deploy-config.json on server
3. Copy token to local .env
4. Build your project: npm run build
5. Deploy: deploy.bat /deploy
6. Check: deploy.bat /status
```

---

## 7. Rollback

```
deploy.bat /rollback
```

Restores the latest backup. Backups are created automatically before every deployment.

---

## 8. Web panel

```
https://yourdomain.com/_deploy/panel.php
```

Login with your `DEPLOY_SECRET` token. Features:
- Deployment status + counters
- Deploy Latest button
- Rollback button
- Upload ZIP
- Pin specific package
- Clean Root (dry-run + actual)
- Deployment log (last 40 entries)

---

## 9. Security

| Topic | Detail |
|---|---|
| Token storage | In `config.php` only — never in source code or committed files |
| Token generation | `bin2hex(random_bytes(32))` — 256-bit entropy |
| Token comparison | `hash_equals()` — constant-time, timing-attack resistant |
| Transport | Always use HTTPS |
| File protection | `.htaccess Deny from all` in all internal subdirectories |
| config.php protection | `.htaccess` denies direct browser access |
| ZIP validation | Extension + MIME + path traversal check on every entry |
| preserveFiles | `config.php` is in this list — can never be overwritten by deployment |
| Installer safety | GET never installs; POST requires action=install; reinstall requires REINSTALL |
| Installer cleanup | Delete `install-ai-deploy.php` immediately after install |

---

## 10. Token rotation

1. Upload `installer/install-ai-deploy.php` to your server.
2. Open it, type `REINSTALL` to confirm.
3. Click Reinstall — new token generated.
4. Save new token → update `DEPLOY_TOKEN` in local `.env`.
5. Delete `install-ai-deploy.php`.
6. Test: `deploy.bat /status`

---

## 11. Troubleshooting

### "Could not load status from agent" in panel
- Confirm `DEPLOY_SECRET` in `config.php` matches `DEPLOY_TOKEN` in `.env`.
- Check that `curl_init` is available on your host.

### "Cannot create directory: _deploy"
- The web root is not writable by PHP.
- Check folder permissions via cPanel File Manager.

### Upload 413 error
Add to `/_deploy/.htaccess`:
```apache
php_value upload_max_filesize 64M
php_value post_max_size 64M
```

### Lost your token
- Re-run the installer (reinstall mode) to generate a new one.
- Old backups and packages are preserved.

### deploy-config.json not loading
- Check file permissions (644).
- Validate JSON syntax at jsonlint.com.

---

## Quick reference

| Command | What it does |
|---|---|
| `deploy.bat /deploy` | Full deploy |
| `deploy.bat /rollback` | Restore latest backup |
| `deploy.bat /status` | Show status |
| `deploy.bat /logs` | Show logs |
| `deploy.bat /clean-root:dry-run` | Preview root cleanup |
| `deploy.bat /clean-root` | ⚠ Clean root (backup first) |

Panel: `https://yourdomain.com/_deploy/panel.php`  
Agent: `https://yourdomain.com/_deploy/ai-deploy.php`
