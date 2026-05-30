'use strict';

/**
 * set-package.js
 * Pins a specific package filename on the server by updating
 * latestPackage in deploy-config.json via the update-config action.
 *
 * Usage:
 *   node command-router.js /set-package "zakinfo-build-102.zip"
 *   node command-router.js /set-package ""          ← clears pin (use newest)
 *
 * After pinning, the next /deploy:install or /deploy will install
 * exactly that package instead of the most recently uploaded one.
 */

const path = require('path');
const axios = require('axios');
const FormData = require('form-data');
require('dotenv').config({ path: path.join(__dirname, '.env') });

const DEPLOY_URL   = process.env.DEPLOY_URL;
const DEPLOY_TOKEN = process.env.DEPLOY_TOKEN;

function fail(msg) { console.error(`  ✗ ${msg}`); }
function ok(msg)   { console.log(`  ✓ ${msg}`); }

async function runSetPackage(pkgName) {
  console.log('\n=== AI Deploy Agent :: Set Package ===\n');

  if (!DEPLOY_URL || !DEPLOY_TOKEN) {
    fail('Missing DEPLOY_URL or DEPLOY_TOKEN in .env');
    process.exit(1);
  }

  // pkgName can be empty string (clears pin) or a filename
  const name = (pkgName ?? '').trim();

  if (name !== '') {
    // Must be a safe .zip filename — no paths allowed
    if (!/^[a-zA-Z0-9._-]+\.zip$/i.test(name)) {
      fail(`Invalid package name: "${name}"`);
      fail('Must be a plain .zip filename — no directories, no special characters.');
      process.exit(1);
    }
  }

  const form = new FormData();
  form.append('token',         DEPLOY_TOKEN);
  form.append('action',        'update-config');
  form.append('latestPackage', name);

  try {
    const response = await axios.post(DEPLOY_URL, form, {
      headers: { ...form.getHeaders() },
      timeout: 15_000,
    });

    const result = response.data;

    if (result.status === 'ok') {
      if (name === '') {
        ok('Package pin cleared — next install will use newest uploaded package.');
      } else {
        ok(`Package pinned: ${name}`);
        console.log('  Next /deploy:install will use this specific package.');
      }
      console.log(`  Project: ${result.project || '?'}`);
    } else {
      fail(`Server error: ${result.message || 'unknown'}`);
      process.exitCode = 1;
    }
  } catch (err) {
    fail(`Request failed: ${err.message}`);
    process.exitCode = 1;
  }
}

module.exports = { runSetPackage };
