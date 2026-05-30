'use strict';

/**
 * logs.js
 * Fetches and displays deployment logs from ai-deploy.php.
 */

const path = require('path');
const axios = require('axios');
const FormData = require('form-data');
require('dotenv').config({ path: path.join(__dirname, '.env') });

const DEPLOY_URL   = process.env.DEPLOY_URL;
const DEPLOY_TOKEN = process.env.DEPLOY_TOKEN;

function fail(msg) { console.error(`  ✗ ${msg}`); }

async function runLogs(lines = 50) {
  console.log('\n=== AI Deploy Agent :: Logs ===\n');

  if (!DEPLOY_URL || !DEPLOY_TOKEN) {
    fail('Missing DEPLOY_URL or DEPLOY_TOKEN in .env');
    process.exit(1);
  }

  const form = new FormData();
  form.append('token',  DEPLOY_TOKEN);
  form.append('action', 'logs');
  form.append('lines',  String(lines));

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

    const logLines = d.logs || [];
    if (logLines.length === 0) {
      console.log('  (no log entries found)');
      return;
    }

    console.log(`  Last ${logLines.length} entries:\n`);
    console.log('─'.repeat(70));
    logLines.forEach(line => console.log(line));
    console.log('─'.repeat(70));

  } catch (err) {
    fail(`Request error: ${err.message}`);
    process.exitCode = 1;
  }
}

module.exports = { runLogs };
