#!/usr/bin/env node
'use strict';

/**
 * command-router.js — v2
 * Entry point for all AI Deploy Agent commands.
 *
 * Usage:
 *   node command-router.js /deploy
 *   node command-router.js /deploy:upload
 *   node command-router.js /deploy:install
 *   node command-router.js /rollback
 *   node command-router.js /status
 *   node command-router.js /logs [lines]
 *   node command-router.js /set-source <path>
 *   node command-router.js /set-package <filename.zip>
 *   node command-router.js /set-target <project-name>
 *   node command-router.js /clean-root:dry-run
 *   node command-router.js /clean-root
 */

const { runDeploy, runUpload, runInstall }     = require('./deploy');
const { runRollback }                          = require('./rollback');
const { runStatus }                            = require('./status');
const { runLogs }                              = require('./logs');
const { runSetSource }                         = require('./set-source');
const { runSetPackage }                        = require('./set-package');
const { runSetTarget }                         = require('./set-target');
const { runCleanRoot, runCleanRootDryRun }     = require('./clean-root');

const command = (process.argv[2] || '').toLowerCase();
const arg2    = process.argv[3]; // first positional argument after command
const arg3    = process.argv[4]; // second positional argument (reserved)

// ── Command dispatch table ────────────────────────────────────
const COMMANDS = {
  // ── Deployment lifecycle ────────────────────────────────────
  '/deploy':         () => runDeploy(),
  '/deploy:upload':  () => runUpload(),
  '/deploy:install': () => runInstall(),
  '/rollback':       () => runRollback(),

  // ── Inspection ──────────────────────────────────────────────
  '/status':         () => runStatus(),
  '/logs':           () => runLogs(arg2 ? parseInt(arg2, 10) : 50),

  // ── Configuration ───────────────────────────────────────────
  '/set-source':          () => runSetSource(arg2),
  '/set-package':         () => runSetPackage(arg2 ?? ''),
  '/set-target':          () => runSetTarget(arg2),

  // ── Destructive operations ───────────────────────────────────
  '/clean-root:dry-run':  () => runCleanRootDryRun(),
  '/clean-root':          () => runCleanRoot(),
};

// ── Help screen ───────────────────────────────────────────────
function printHelp() {
  console.log(`
  ⚡ AI Deploy Agent — v2

  ── Deployment ──────────────────────────────────────────────
    /deploy                  Full deploy (upload + install)
    /deploy:upload           Upload ZIP only (queued on server)
    /deploy:install          Install latest queued package
    /rollback                Restore latest backup

  ── Inspection ──────────────────────────────────────────────
    /status                  Show project + deployment status
    /logs [N]                Show last N log lines (default: 50)

  ── Configuration ───────────────────────────────────────────
    /set-source <path>       Set local SOURCE_DIR in .env
    /set-package <file.zip>  Pin a specific package on server
    /set-package ""          Clear pin (use newest uploaded)
    /set-target <name>       Switch active project name

  ── Destructive ─────────────────────────────────────────────
    /clean-root:dry-run      Preview what would be deleted (safe)
    /clean-root              Delete non-protected files from root

  ── Examples ────────────────────────────────────────────────
    node command-router.js /deploy
    node command-router.js /set-source "C:\\projects\\zakinfo\\dist"
    node command-router.js /set-package "2026-05-27_21-00-00_site.zip"
    node command-router.js /set-target "zakinfo"
    node command-router.js /logs 100
`);
}

if (!command || command === '/help' || !COMMANDS[command]) {
  printHelp();
  if (command && command !== '/help' && !COMMANDS[command]) {
    console.error(`  Unknown command: ${command}\n`);
    process.exit(1);
  }
  process.exit(0);
}

(async () => {
  try {
    await COMMANDS[command]();
  } catch (err) {
    console.error(`\n  ✗ Unexpected error: ${err.message}`);
    if (process.env.DEBUG) console.error(err.stack);
    process.exit(1);
  }
})();
