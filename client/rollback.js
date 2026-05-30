'use strict';

/**
 * rollback.js
 * Sends a rollback request to ai-deploy.php.
 * The server restores the latest backup ZIP.
 */

const path = require('path');
const axios = require('axios');
const FormData = require('form-data');
require('dotenv').config({ path: path.join(__dirname, '.env') });

const DEPLOY_URL   = process.env.DEPLOY_URL;
const DEPLOY_TOKEN = process.env.DEPLOY_TOKEN;

function fail(msg) { console.error(`  ✗ ${msg}`); }
function ok(msg)   { console.log(`  ✓ ${msg}`); }

async function runRollback() {
  console.log('\n=== AI Deploy Agent :: Rollback ===\n');

  if (!DEPLOY_URL || !DEPLOY_TOKEN) {
    fail('Missing DEPLOY_URL or DEPLOY_TOKEN in .env');
    process.exit(1);
  }

  const form = new FormData();
  form.append('token',  DEPLOY_TOKEN);
  form.append('action', 'rollback');

  try {
    const response = await axios.post(DEPLOY_URL, form, {
      headers: { ...form.getHeaders() },
      timeout: 120_000,
    });

    const result = response.data;
    console.log('\n--- Rollback Report ---');
    console.log(JSON.stringify(result, null, 2));

    if (result.status === 'ok') {
      ok('Rollback successful.');
      if (result.restored) ok(`Restored from: ${result.restored}`);
    } else {
      fail(`Rollback failed: ${result.message || 'unknown error'}`);
      process.exitCode = 1;
    }
  } catch (err) {
    fail(`Request error: ${err.message}`);
    process.exitCode = 1;
  }
}

module.exports = { runRollback };
