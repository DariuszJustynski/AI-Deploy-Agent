'use strict';

/**
 * clean-root.js
 * Removes all non-protected files from the website root on the server.
 *
 * ⚠  DESTRUCTIVE OPERATION — always creates a backup first.
 *
 * Usage:
 *   node command-router.js /clean-root:dry-run   ← safe preview, no changes
 *   node command-router.js /clean-root           ← actually deletes files
 *
 * The server will:
 *   1. (dry-run) Return a report of what WOULD be deleted.
 *   2. (clean)   Create a full backup → delete unprotected files → log.
 *
 * Protected items (never deleted):
 *   _deploy/ directory and all contents
 *   ai-deploy.php, panel.php, deploy-config.json, deploy-state.json
 *   Any filenames listed in deploy-config.json preserveFiles[]
 */

const path = require('path');
const axios = require('axios');
const FormData = require('form-data');
require('dotenv').config({ path: path.join(__dirname, '.env') });

const DEPLOY_URL   = process.env.DEPLOY_URL;
const DEPLOY_TOKEN = process.env.DEPLOY_TOKEN;

function fail(msg)  { console.error(`  ✗ ${msg}`); }
function ok(msg)    { console.log(`  ✓ ${msg}`); }
function warn(msg)  { console.log(`  ⚠  ${msg}`); }
function info(msg)  { console.log(`  ${msg}`); }

function checkConfig() {
  if (!DEPLOY_URL || !DEPLOY_TOKEN) {
    fail('Missing DEPLOY_URL or DEPLOY_TOKEN in .env');
    process.exit(1);
  }
}

// ── Dry-run ───────────────────────────────────────────────────

async function runCleanRootDryRun() {
  console.log('\n=== AI Deploy Agent :: Clean Root — DRY RUN ===\n');
  console.log('  No files will be changed. Preview only.\n');
  checkConfig();

  const form = new FormData();
  form.append('token',  DEPLOY_TOKEN);
  form.append('action', 'clean_root_dry');

  try {
    const response = await axios.post(DEPLOY_URL, form, {
      headers: { ...form.getHeaders() },
      timeout: 30_000,
    });

    const r = response.data;

    if (r.status !== 'ok') {
      fail(`Server error: ${r.message || 'unknown'}`);
      process.exitCode = 1;
      return;
    }

    console.log('  ' + '─'.repeat(60));
    warn('WOULD BE DELETED:');
    console.log('  ' + '─'.repeat(60));

    const items = r.top_level || [];
    if (items.length === 0) {
      info('(nothing to delete — root already clean)');
    } else {
      items.sort().forEach(f => info('  DELETE  ' + f));
    }

    console.log('  ' + '─'.repeat(60));
    warn('PROTECTED (will NOT be touched):');
    console.log('  ' + '─'.repeat(60));
    (r.protected || []).sort().forEach(f => info('  KEEP    ' + f));

    console.log('  ' + '─'.repeat(60));
    console.log(`\n  Summary: ${r.item_count} top-level items, ${r.file_count} files total would be deleted.`);
    console.log('  To proceed:  node command-router.js /clean-root\n');

  } catch (err) {
    fail(`Request error: ${err.message}`);
    process.exitCode = 1;
  }
}

// ── Actual clean ──────────────────────────────────────────────

async function runCleanRoot() {
  console.log('\n=== AI Deploy Agent :: Clean Root ===\n');

  warn('⚠⚠⚠  WARNING: THIS WILL DELETE FILES FROM THE WEBSITE ROOT  ⚠⚠⚠');
  warn('A backup will be created first. Run /clean-root:dry-run to preview.\n');
  checkConfig();

  const form = new FormData();
  form.append('token',   DEPLOY_TOKEN);
  form.append('action',  'clean_root');
  form.append('confirm', 'CLEAN');   // required by server

  try {
    const response = await axios.post(DEPLOY_URL, form, {
      headers: { ...form.getHeaders() },
      timeout: 120_000,
    });

    const r = response.data;
    console.log('\n--- Server Response ---');
    console.log(JSON.stringify(r, null, 2));

    if (r.status === 'ok') {
      ok(`Cleaned: ${r.deleted_files} files, ${r.deleted_dirs} directories removed.`);
      ok(`Backup created: ${r.backup}`);
      if (r.protected?.length) {
        info(`Protected (kept): ${r.protected.join(', ')}`);
      }
    } else {
      fail(`Clean failed: ${r.message || 'unknown error'}`);
      process.exitCode = 1;
    }
  } catch (err) {
    fail(`Request error: ${err.message}`);
    process.exitCode = 1;
  }
}

module.exports = { runCleanRoot, runCleanRootDryRun };
