'use strict';

/**
 * set-target.js
 * Switches the active deployment project by updating:
 *   1. projectName in deploy-config.json on the server.
 *   2. DEPLOY_LABEL in local .env (for log/status display).
 *
 * Usage:
 *   node command-router.js /set-target "zakinfo"
 *   node command-router.js /set-target "bakery-site"
 *
 * Note: targetRoot (the server filesystem path) is intentionally NOT
 * changeable via this command — it must be edited manually in
 * deploy-config.json on the server for security reasons.
 *
 * For multi-project setups, maintain separate .env files:
 *   .env.zakinfo
 *   .env.bakery-site
 * and copy the relevant one to .env before deploying.
 */

const path = require('path');
const fs   = require('fs');
const axios = require('axios');
const FormData = require('form-data');
require('dotenv').config({ path: path.join(__dirname, '.env') });

const DEPLOY_URL   = process.env.DEPLOY_URL;
const DEPLOY_TOKEN = process.env.DEPLOY_TOKEN;
const ENV_FILE     = path.join(__dirname, '.env');

function fail(msg) { console.error(`  ✗ ${msg}`); }
function ok(msg)   { console.log(`  ✓ ${msg}`); }

/** Update a single KEY=value line in .env, appending if not present. */
function updateEnvKey(file, key, value) {
  if (!fs.existsSync(file)) return; // .env may not exist in tests
  const raw   = fs.readFileSync(file, 'utf8');
  const lines = raw.split('\n');
  let found   = false;
  const updated = lines.map(line => {
    if (new RegExp(`^\\s*${key}\\s*=`).test(line)) {
      found = true;
      return `${key}=${value}`;
    }
    return line;
  });
  if (!found) updated.push(`${key}=${value}`);
  fs.writeFileSync(file, updated.join('\n'), 'utf8');
}

async function runSetTarget(projectName) {
  console.log('\n=== AI Deploy Agent :: Set Target ===\n');

  if (!projectName) {
    fail('Usage: /set-target <project-name>');
    fail('Example: /set-target "zakinfo"');
    fail('');
    fail('Project name must be letters, numbers, hyphens, underscores — max 64 chars.');
    process.exit(1);
  }

  if (!DEPLOY_URL || !DEPLOY_TOKEN) {
    fail('Missing DEPLOY_URL or DEPLOY_TOKEN in .env');
    process.exit(1);
  }

  if (!/^[a-zA-Z0-9_-]{1,64}$/.test(projectName)) {
    fail(`Invalid project name: "${projectName}"`);
    fail('Allowed: letters, numbers, hyphens, underscores (max 64 chars).');
    process.exit(1);
  }

  // 1. Update server-side deploy-config.json
  const form = new FormData();
  form.append('token',       DEPLOY_TOKEN);
  form.append('action',      'update-config');
  form.append('projectName', projectName);

  try {
    const response = await axios.post(DEPLOY_URL, form, {
      headers: { ...form.getHeaders() },
      timeout: 15_000,
    });

    const result = response.data;

    if (result.status !== 'ok') {
      fail(`Server error: ${result.message || 'unknown'}`);
      process.exitCode = 1;
      return;
    }

    ok(`Server config updated: projectName = "${projectName}"`);

    // 2. Update local .env DEPLOY_LABEL
    updateEnvKey(ENV_FILE, 'DEPLOY_LABEL', projectName);
    ok(`Local .env: DEPLOY_LABEL = "${projectName}"`);

    console.log('');
    console.log('  ─────────────────────────────────────────────────');
    console.log('  Active project:', projectName);
    console.log('  Deploy URL:', DEPLOY_URL);
    console.log('  ─────────────────────────────────────────────────');
    console.log('');
    console.log('  Note: targetRoot in deploy-config.json on the server');
    console.log('  controls which directory gets deployed into.');
    console.log('  Edit it manually there if you need to switch root paths.');

  } catch (err) {
    fail(`Request failed: ${err.message}`);
    process.exitCode = 1;
  }
}

module.exports = { runSetTarget };
