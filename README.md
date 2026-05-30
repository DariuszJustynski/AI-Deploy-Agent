# ⚡ AI Deploy Agent

> **One-click installer + ZIP-based deployment for PHP shared hosting.**  
> Upload one file. Click Install. Deploy any project with a single command.

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Node.js](https://img.shields.io/badge/Node.js-16%2B-339933?logo=node.js&logoColor=white)](https://nodejs.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-3.0.0-blue)](VERSION)

---

## What is AI Deploy Agent?

AI Deploy Agent is a lightweight deployment toolkit designed for developers who work on iterative projects hosted on **standard shared PHP hosting** — where you don't have SSH access, can't run Docker, and just want to deploy without friction.

**The full workflow becomes:**

```
claude code finishes iteration
  → deploy.bat /deploy
  → site updated in seconds
  → deploy.bat /rollback    ← if something breaks
```

Instead of manually:
- Compressing files into a ZIP
- Logging into cPanel
- Deleting old files
- Uploading the archive
- Extracting manually
- Checking if everything works

---

## Features

- 🚀 **One-click installer** — upload one PHP file, click Install in the browser
- 🔒 **Secure token auth** — 256-bit secret, `hash_equals()`, POST-only API
- 📦 **ZIP-based deployment** — build locally, compress, upload, extract
- 🔄 **Automatic backups** — full site backup before every deployment
- ↩ **One-command rollback** — restore the previous version instantly
- 🔍 **Dry-run clean root** — preview what would be deleted before doing it
- 📋 **Deployment logs** — timestamped, IP-logged, auto-rotating
- 🌐 **Web panel** — dark UI, one-click deploy/rollback, live log viewer
- 🧩 **Config-driven** — all paths in `deploy-config.json`, no hardcoded PHP
- 🤖 **AI-workflow ready** — designed for Claude Code and similar AI coding assistants

---

## Architecture

```
┌─────────────────────────────┐        ┌──────────────────────────────┐
│   LOCAL MACHINE (Windows)   │        │   PHP SHARED HOSTING          │
│                             │        │                               │
│  client/                    │  HTTP  │  /_deploy/                    │
│    command-router.js        │ ──────▶│    ai-deploy.php  ← engine    │
│    deploy.js (ZIP + upload) │        │    panel.php      ← web UI    │
│    rollback.js              │        │    config.php     ← token     │
│    status.js                │        │    deploy-config.json         │
│    set-source.js            │        │    _state/deploy-state.json   │
│    set-package.js           │        │    _backups/   _packages/     │
│    set-target.js            │        │    _logs/      _incoming/     │
│    clean-root.js            │        │                               │
│    .env  deploy.bat         │        │  /public_html/  ← website     │
└─────────────────────────────┘        └──────────────────────────────┘
```

The **installer** (`install-ai-deploy.php`) is a single self-contained file that creates the entire `/_deploy/` system from scratch on your hosting.

---

## Quick Start

### Server Side — One-Click Install

**1. Upload the installer to your website root:**

```
public_html/
  install-ai-deploy.php    ← upload only this
```

**2. Open it in your browser:**

```
https://yourdomain.com/install-ai-deploy.php
```

**3. Check the diagnostics, click Install:**

The installer checks PHP version, ZipArchive, and write permissions before doing anything.

**4. Save your token — shown only once:**

```
DEPLOY_TOKEN=feb1e003feb282cc1227074bb067a3ae...
```

Copy this immediately. It's your deployment key.

**5. Delete the installer:**

```
⚠ Delete install-ai-deploy.php from your server.
```

What was created on your server:

```
/_deploy/
  config.php              ← your DEPLOY_SECRET lives here
  ai-deploy.php           ← deployment API endpoint
  panel.php               ← web panel
  .htaccess               ← protects config + internal dirs
  deploy-config.json      ← edit this to configure paths
  _state/
    deploy-state.json     ← auto-maintained deployment history
  _backups/               ← automatic pre-deploy backups
  _logs/                  ← deployment + install logs
  _packages/              ← uploaded ZIP packages (kept last 5)
  _incoming/  _tmp/       ← operational scratch space
```

---

### Local Client — Setup

**Clone or download this repository:**

```cmd
git clone https://github.com/DariuszJustynski/AI-Deploy-Agent.git
cd AI-Deploy-Agent\client
```

**Install dependencies:**

```cmd
npm install
```

**Configure `.env`:**

```cmd
copy .env.example .env
```

Edit `.env`:

```env
# Full URL to ai-deploy.php on your hosting
DEPLOY_URL=https://yourdomain.com/_deploy/ai-deploy.php

# The token shown during installation
DEPLOY_TOKEN=feb1e003feb282cc1227074bb067a3ae...

# Local build output directory to deploy
SOURCE_DIR=../dist

# Temporary ZIP filename (auto-deleted after upload)
ZIP_NAME=site-deploy.zip

# Max upload size in MB (must not exceed server PHP limit)
MAX_UPLOAD_MB=64

# Label shown in logs
DEPLOY_LABEL=my-website
```

**Test the connection:**

```cmd
deploy.bat /status
```

---

## Commands

```cmd
cd AI-Deploy-Agent\client

:: ── Deployment ──────────────────────────────────────────────
deploy.bat /deploy                   Full deploy: compress → upload → install
deploy.bat /deploy:upload            Upload ZIP only (queue on server, install later)
deploy.bat /deploy:install           Install the latest (or pinned) queued package
deploy.bat /rollback                 Restore the latest automatic backup

:: ── Inspection ─────────────────────────────────────────────
deploy.bat /status                   Show project + deployment state
deploy.bat /logs                     Show last 50 deployment log entries
deploy.bat /logs 100                 Show last 100 entries

:: ── Configuration ──────────────────────────────────────────
deploy.bat /set-source "C:\projects\my-site\dist"   Change local build directory
deploy.bat /set-package "2026-05-27_21-00-00_s.zip" Pin a specific package for install
deploy.bat /set-package ""           Clear pin (use newest uploaded)
deploy.bat /set-target "my-website"  Switch active project name

:: ── ⚠ Destructive ──────────────────────────────────────────
deploy.bat /clean-root:dry-run       Preview what would be deleted (safe, no changes)
deploy.bat /clean-root               Delete all non-protected files from website root
```

---

## Deployment Flows

### Standard: full deploy

```
1. Your build process produces output in dist/
2. You run: deploy.bat /deploy
3. Client compresses dist/ → site-deploy.zip
4. ZIP uploaded to /_deploy/ai-deploy.php
5. Server reads deploy-config.json for target directory
6. Server creates backup → /_deploy/_backups/YYYY-MM-DD_backup.zip
7. Server extracts ZIP into website root
8. Server updates deploy-state.json
9. Server writes timestamped log entry
10. Client prints deployment report
11. Local ZIP deleted
```

### Staged: upload now, install later

```
deploy.bat /deploy:upload    → ZIP uploaded and queued, nothing deployed yet
  ... review in panel ...
deploy.bat /deploy:install   → server extracts the queued package
```

### Clean deploy (new structure)

```
deploy.bat /clean-root:dry-run   → preview what gets deleted
deploy.bat /clean-root           → remove old files (backup created first)
deploy.bat /deploy               → install fresh build onto clean root
```

### Rollback

```
deploy.bat /rollback
```

Restores the most recent automatic backup. The server keeps the last **10 backups** by default.

---

## Web Panel

```
https://yourdomain.com/_deploy/panel.php
```

Login with your `DEPLOY_TOKEN`.

**Panel features:**
- Live deployment status (status, last deploy, package, deploy count)
- Config strip (project name, mode, target root, pinned package)
- ▶ Deploy Latest — one-click deploy
- ↩ Rollback — one-click restore
- Upload ZIP manually
- Pin a specific package for next install
- 🔍 Dry Run — preview clean-root without deleting anything
- 🗑 Clean Root — with `CLEAN` confirmation input
- Deployment log viewer (last 40 entries, colour-coded)

---

## Configuration

### deploy-config.json (server)

Edit `/_deploy/deploy-config.json` after installation:

```json
{
  "projectName": "my-website",
  "targetRoot": "/home/user/public_html",
  "packageDir": "_packages",
  "backupDir":  "_backups",
  "logsDir":    "_logs",
  "incomingDir": "_incoming",
  "latestPackage": "",
  "deploymentMode": "upload_install",
  "maxBackups":  10,
  "maxPackages": 5,
  "preserveFiles": [
    "ai-deploy.php",
    "panel.php",
    "config.php",
    "deploy-config.json"
  ]
}
```

| Field | Description |
|---|---|
| `projectName` | Label for logs and panel |
| `targetRoot` | Absolute server path to website root. Empty = parent of `/_deploy/` |
| `packageDir` / `backupDir` / `logsDir` | Relative to `/_deploy/` or absolute |
| `latestPackage` | Specific ZIP to install (pin). Empty = use newest uploaded |
| `deploymentMode` | `upload_install`, `upload_only`, or `manual` |
| `maxBackups` | Number of backups to keep (oldest auto-deleted) |
| `preserveFiles` | Files never overwritten or deleted during deployment |

### Path resolution

- **Empty string** → built-in default (relative to `/_deploy/`)
- **Relative path** (e.g. `"_backups"`) → `/_deploy/_backups/`
- **Absolute path** (e.g. `"/home/user/backups"`) → used as-is

---

## Security

| Threat | Protection |
|---|---|
| Unauthenticated access | Token required on every request |
| Token brute force | `hash_equals()` constant-time comparison |
| GET-based attacks | POST method enforced on API |
| Malicious uploads | ZIP extension + MIME type validation |
| Path traversal in ZIP | `../` and absolute paths rejected on every entry |
| Extraction outside root | All paths validated against `targetRoot` |
| Overwriting agent/config | `config.php`, `ai-deploy.php`, `panel.php` in `preserveFiles` |
| Direct ZIP/log access | `.htaccess Deny from all` in all internal subdirectories |
| Config.php browser access | `.htaccess` blocks direct HTTP access |
| Installer misuse | GET never installs; reinstall requires `REINSTALL` typed |
| Clean-root misuse | Requires `confirm=CLEAN` POST field + confirmation in panel |

### Token storage

The `DEPLOY_SECRET` is stored **only** in `/_deploy/config.php` on the server and in your local `.env` file. It is never:
- Hardcoded in `ai-deploy.php` or `panel.php`
- Committed to version control (`.gitignore` excludes both files)
- Logged or exposed in any API response

---

## Token Rotation

Rotate your token any time you suspect it has been compromised, or as routine maintenance:

**Method 1 — Reinstall (recommended):**

```
1. Upload installer/install-ai-deploy.php to your server
2. Open: https://yourdomain.com/install-ai-deploy.php
3. Type REINSTALL in the confirmation field
4. Click Reinstall — new token generated
5. Save the new token → update DEPLOY_TOKEN in local .env
6. Delete install-ai-deploy.php
7. Test: deploy.bat /status
```

Your backups, packages, and logs are preserved during reinstall.

**Method 2 — Manual (advanced):**

```
1. Generate token: node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
2. Edit /_deploy/config.php on server: replace DEPLOY_SECRET value
3. Update DEPLOY_TOKEN in local .env
4. Test: deploy.bat /status
```

---

## Multi-Project Setup

For deploying to multiple sites from one machine:

**Option A — Multiple .env files:**

```
client/
  .env.site-one
  .env.site-two
```

```cmd
copy .env.site-one .env
deploy.bat /deploy
```

**Option B — set-target command:**

```cmd
deploy.bat /set-target "site-one"
deploy.bat /deploy
```

This updates `projectName` on the server and `DEPLOY_LABEL` in your local `.env`.

**Option C — Separate hosting accounts:**

Each hosting account has its own `/_deploy/deploy-config.json` with its own `targetRoot`. You switch by updating `DEPLOY_URL` in `.env`.

---

## Hosting Compatibility

Tested and designed for:

- **cPanel shared hosting** (GoDaddy, SiteGround, Bluehost, Hostinger, etc.)
- **DirectAdmin hosting**
- Any host running **PHP 7.4+** with `ZipArchive` enabled

**PHP extensions required:**
- `ZipArchive` — for ZIP creation and extraction (enabled by default on most hosts)
- `cURL` — for the web panel proxy calls (usually available)
- `finfo` — for MIME type validation (optional, falls back to extension check)

**No requirements for:**
- SSH access
- Composer
- Database
- Root/sudo permissions
- Docker or containers

---

## Troubleshooting

### "Could not load status from agent"
- Verify `DEPLOY_URL` points to `/_deploy/ai-deploy.php` (not the panel)
- Confirm `DEPLOY_TOKEN` in `.env` matches `DEPLOY_SECRET` in `/_deploy/config.php`
- Check `curl_init` is available on your host

### Upload fails with 413
Add to `/_deploy/.htaccess`:
```apache
php_value upload_max_filesize 64M
php_value post_max_size 64M
```

### "Cannot create directory" during install
- Check folder permissions via cPanel File Manager
- The web root must be writable by PHP (usually 755)

### "No package available" on install
- Run `/deploy:upload` or `/deploy` first
- If a package is pinned but the file doesn't exist: `deploy.bat /set-package ""`

### Lost your token
- Re-run the installer in Reinstall mode — generates a fresh token
- Backups and packages are preserved

### deploy-config.json not loading
- Check file permissions (644)
- Validate JSON syntax at [jsonlint.com](https://jsonlint.com)

---

## Project Structure

```
AI-Deploy-Agent/
├── VERSION                        v3.0.0
├── README.md
├── .gitignore
│
├── installer/
│   └── install-ai-deploy.php      ← ONE FILE — upload this to your server
│
├── server/                        ← reference source (embedded in installer)
│   ├── ai-deploy.php              ← deployment API (loads config.php)
│   ├── panel.php                  ← web panel (loads config.php)
│   ├── config.php.example         ← template showing generated config
│   ├── deploy-config.json         ← default server configuration
│   └── deploy-state.json          ← default state template
│
└── client/                        ← local Windows deployment client
    ├── command-router.js          ← entry point for all commands
    ├── deploy.js                  ← ZIP compression + upload + install
    ├── rollback.js                ← rollback command
    ├── status.js                  ← status display
    ├── logs.js                    ← log fetcher
    ├── set-source.js              ← update local SOURCE_DIR
    ├── set-package.js             ← pin a specific package on server
    ├── set-target.js              ← switch active project
    ├── clean-root.js              ← root cleanup (dry-run + actual)
    ├── package.json
    ├── .env.example               ← copy to .env and fill in
    └── deploy.bat                 ← Windows launcher
```

---

## Changelog

### v3.0.0
- **One-click installer** — `install-ai-deploy.php` creates everything from scratch
- **config.php** — token no longer hardcoded in PHP files; generated by installer
- **State file** moved to `/_deploy/_state/deploy-state.json`
- `config.php` added to `preserveFiles` — protected from deployment overwrites
- `PANEL_SECRET` removed — both files now use `DEPLOY_SECRET` from `config.php`
- Token rotation via reinstall flow (no manual PHP editing)

### v2.0.0
- Config-driven architecture: `deploy-config.json` + `deploy-state.json`
- New commands: `/set-source`, `/set-package`, `/set-target`
- Clean-root feature with dry-run mode
- Deploy counter, rollback counter, version tracking in state

### v1.0.0
- Initial release: upload, install, rollback, status, logs
- ZIP-based deployment pipeline
- Automatic pre-deploy backups
- Web panel with dark UI

---

## License

MIT — use freely, modify as needed, attribution appreciated.

---

## Contributing

Issues and pull requests welcome. This project is intentionally kept simple — no build tools, no frameworks, plain PHP and plain Node.js.

---

*Built for iterative AI-assisted web development workflows.*  
*Works on the cheapest shared hosting you can find.*
