'use strict';

/**
 * set-source.js
 * Updates SOURCE_DIR in the local .env file.
 *
 * Usage:
 *   node command-router.js /set-source "C:\projects\zakinfo\dist"
 *   node command-router.js /set-source "../build"
 *
 * Only touches the SOURCE_DIR line — all other .env values are preserved.
 */

const path = require('path');
const fs   = require('fs');
require('dotenv').config({ path: path.join(__dirname, '.env') });

const ENV_FILE = path.join(__dirname, '.env');

function fail(msg) { console.error(`  ✗ ${msg}`); }
function ok(msg)   { console.log(`  ✓ ${msg}`); }

async function runSetSource(newSource) {
  console.log('\n=== AI Deploy Agent :: Set Source ===\n');

  if (!newSource) {
    fail('Usage: /set-source <path>');
    fail('Example: /set-source "C:\\projects\\zakinfo\\dist"');
    process.exit(1);
  }

  // Resolve and verify the path exists
  const resolved = path.resolve(__dirname, newSource);
  if (!fs.existsSync(resolved)) {
    fail(`Directory does not exist: ${resolved}`);
    fail('Check the path and try again.');
    process.exit(1);
  }
  if (!fs.statSync(resolved).isDirectory()) {
    fail(`Not a directory: ${resolved}`);
    process.exit(1);
  }

  // Read current .env
  if (!fs.existsSync(ENV_FILE)) {
    fail('.env file not found. Copy .env.example to .env first.');
    process.exit(1);
  }

  const raw   = fs.readFileSync(ENV_FILE, 'utf8');
  const lines = raw.split('\n');

  let updated = false;
  const newLines = lines.map(line => {
    // Match SOURCE_DIR= line (with optional leading spaces/comments removed)
    if (/^\s*SOURCE_DIR\s*=/.test(line)) {
      updated = true;
      return `SOURCE_DIR=${newSource}`;
    }
    return line;
  });

  // If SOURCE_DIR didn't exist in .env, append it
  if (!updated) {
    newLines.push(`SOURCE_DIR=${newSource}`);
  }

  fs.writeFileSync(ENV_FILE, newLines.join('\n'), 'utf8');

  const prev = process.env.SOURCE_DIR || '(not set)';
  console.log(`  Previous: ${prev}`);
  ok(`SOURCE_DIR updated to: ${newSource}`);
  ok(`Resolved path: ${resolved}`);
}

module.exports = { runSetSource };
