'use strict';

/**
 * status.js — v2
 * Queries ai-deploy.php for deployment status.
 * Displays both deploy-state.json and deploy-config.json data.
 */

const path = require('path');
const axios = require('axios');
const FormData = require('form-data');
require('dotenv').config({ path: path.join(__dirname, '.env') });

const DEPLOY_URL   = process.env.DEPLOY_URL;
const DEPLOY_TOKEN = process.env.DEPLOY_TOKEN;

function fail(msg) { console.error(`  ✗ ${msg}`); }

async function runStatus() {
  console.log('\n=== AI Deploy Agent :: Status ===\n');

  if (!DEPLOY_URL || !DEPLOY_TOKEN) {
    fail('Missing DEPLOY_URL or DEPLOY_TOKEN in .env');
    process.exit(1);
  }

  const form = new FormData();
  form.append('token',  DEPLOY_TOKEN);
  form.append('action', 'status');

  try {
    const response = await axios.post(DEPLOY_URL, form, {
      headers: { ...form.getHeaders() },
      timeout: 30_000,
    });

    const d = response.data;
    if (d.status !== 'ok') {
      fail(`Server error: ${d.message || 'unknown'}`);
      process.exitCode = 1;
      return;
    }

    const s = d.data || {};
    const col = (label, val) => console.log(`  ${label.padEnd(24)} ${val ?? '—'}`);
    const bar = () => console.log('  ' + '─'.repeat(48));

    bar();
    console.log('  PROJECT & CONFIG');
    bar();
    col('Project:',          s.project_name    || 'unknown');
    col('Target root:',      s.target_root     || '?');
    col('Deployment mode:',  s.deployment_mode || '?');
    col('Pinned package:',   s.pinned_package  || '(none — uses newest)');

    bar();
    console.log('  DEPLOYMENT STATE');
    bar();
    col('Status:',           s.deploy_status   || 'unknown');
    col('Last deployed:',    s.last_deployment || 'never');
    col('Last package:',     s.last_package    || 'none');
    col('Current version:',  s.current_version || '?');
    col('Deploy count:',     s.deploy_count    ?? 0);
    col('Rollback count:',   s.rollback_count  ?? 0);
    col('Last rollback:',    s.last_rollback   || 'never');

    bar();
    console.log('  SERVER');
    bar();
    col('Backup count:',     s.backup_count    ?? 0);
    col('Package count:',    s.package_count   ?? 0);
    col('Newest backup:',    s.newest_backup   || 'none');
    col('Server time:',      s.server_time     || '');

    if (s.last_error) {
      bar();
      console.log('  ⚠  Last Error:');
      console.log(`     ${s.last_error}`);
    }
    bar();

  } catch (err) {
    fail(`Request error: ${err.message}`);
    process.exitCode = 1;
  }
}

module.exports = { runStatus };
