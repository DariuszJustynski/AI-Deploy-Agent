'use strict';

/**
 * deploy.js
 * Handles /deploy:upload and /deploy:install and the combined /deploy flow.
 * Reads config from .env, compresses SOURCE_DIR, uploads to ai-deploy.php.
 */

const path = require('path');
const fs = require('fs');
const axios = require('axios');
const FormData = require('form-data');
const archiver = require('archiver');
const fse = require('fs-extra');
require('dotenv').config({ path: path.join(__dirname, '.env') });

// ── Config ──────────────────────────────────────────────────────────────────

const DEPLOY_URL   = process.env.DEPLOY_URL;
const DEPLOY_TOKEN = process.env.DEPLOY_TOKEN;
const SOURCE_DIR   = path.resolve(__dirname, process.env.SOURCE_DIR || '../dist');
const ZIP_NAME     = process.env.ZIP_NAME || 'site-deploy.zip';
const ZIP_PATH     = path.join(__dirname, ZIP_NAME);
const MAX_MB       = parseInt(process.env.MAX_UPLOAD_MB || '64', 10);

// ── Helpers ──────────────────────────────────────────────────────────────────

function log(msg)  { console.log(`  [deploy] ${msg}`); }
function ok(msg)   { console.log(`  ✓ ${msg}`); }
function fail(msg) { console.error(`  ✗ ${msg}`); }

function validateConfig() {
  const missing = [];
  if (!DEPLOY_URL)   missing.push('DEPLOY_URL');
  if (!DEPLOY_TOKEN) missing.push('DEPLOY_TOKEN');
  if (missing.length) {
    fail(`Missing .env values: ${missing.join(', ')}`);
    fail('Copy .env.example to .env and fill in the required fields.');
    process.exit(1);
  }
  if (!fs.existsSync(SOURCE_DIR)) {
    fail(`SOURCE_DIR does not exist: ${SOURCE_DIR}`);
    process.exit(1);
  }
  ok(`Source directory: ${SOURCE_DIR}`);
}

// ── ZIP compression ──────────────────────────────────────────────────────────

function createZip() {
  return new Promise((resolve, reject) => {
    log(`Compressing ${SOURCE_DIR} → ${ZIP_NAME}`);
    const output  = fs.createWriteStream(ZIP_PATH);
    const archive = archiver('zip', { zlib: { level: 9 } });

    output.on('close', () => {
      const sizeMb = (archive.pointer() / 1024 / 1024).toFixed(2);
      if (archive.pointer() > MAX_MB * 1024 * 1024) {
        reject(new Error(`ZIP size ${sizeMb} MB exceeds MAX_UPLOAD_MB (${MAX_MB} MB)`));
        return;
      }
      ok(`Compressed: ${sizeMb} MB`);
      resolve(ZIP_PATH);
    });

    archive.on('error', reject);
    archive.pipe(output);
    archive.directory(SOURCE_DIR, false);
    archive.finalize();
  });
}

// ── Upload ───────────────────────────────────────────────────────────────────

async function uploadZip() {
  log(`Uploading to ${DEPLOY_URL}`);
  const form = new FormData();
  form.append('token',   DEPLOY_TOKEN);
  form.append('action',  'upload');
  form.append('package', fs.createReadStream(ZIP_PATH), {
    filename:    ZIP_NAME,
    contentType: 'application/zip',
  });

  const response = await axios.post(DEPLOY_URL, form, {
    headers: { ...form.getHeaders() },
    maxContentLength: Infinity,
    maxBodyLength:    Infinity,
    timeout: 120_000,
  });

  return response.data;
}

// ── Install ───────────────────────────────────────────────────────────────────

async function triggerInstall() {
  log(`Triggering install on ${DEPLOY_URL}`);
  const form = new FormData();
  form.append('token',  DEPLOY_TOKEN);
  form.append('action', 'install');

  const response = await axios.post(DEPLOY_URL, form, {
    headers: { ...form.getHeaders() },
    timeout: 120_000,
  });

  return response.data;
}

// ── Cleanup ───────────────────────────────────────────────────────────────────

function cleanupZip() {
  try {
    if (fs.existsSync(ZIP_PATH)) {
      fs.unlinkSync(ZIP_PATH);
      log('Temporary ZIP deleted.');
    }
  } catch (_) { /* non-fatal */ }
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Upload only — no extraction on server.
 */
async function runUpload() {
  console.log('\n=== AI Deploy Agent :: Upload ===\n');
  validateConfig();
  try {
    await createZip();
    const result = await uploadZip();
    console.log('\n--- Server Response ---');
    console.log(JSON.stringify(result, null, 2));
    if (result.status === 'ok') {
      ok('Upload successful. Package is waiting on server.');
      ok(`Use /deploy:install or panel to deploy: ${result.package || ''}`);
    } else {
      fail(`Upload failed: ${result.message || 'unknown error'}`);
      process.exitCode = 1;
    }
  } finally {
    cleanupZip();
  }
}

/**
 * Install only — server deploys the latest uploaded package.
 */
async function runInstall() {
  console.log('\n=== AI Deploy Agent :: Install ===\n');
  validateConfig();
  const result = await triggerInstall();
  console.log('\n--- Server Response ---');
  console.log(JSON.stringify(result, null, 2));
  if (result.status === 'ok') {
    ok('Installation successful.');
  } else {
    fail(`Install failed: ${result.message || 'unknown error'}`);
    process.exitCode = 1;
  }
}

/**
 * Full deploy — upload then immediately install.
 */
async function runDeploy() {
  console.log('\n=== AI Deploy Agent :: Full Deploy ===\n');
  validateConfig();
  try {
    await createZip();
    const uploadResult = await uploadZip();
    if (uploadResult.status !== 'ok') {
      fail(`Upload failed: ${uploadResult.message}`);
      process.exitCode = 1;
      return;
    }
    ok('Upload complete.');
    const installResult = await triggerInstall();
    console.log('\n--- Deployment Report ---');
    console.log(JSON.stringify(installResult, null, 2));
    if (installResult.status === 'ok') {
      ok('Deployment complete!');
      if (installResult.backup) ok(`Backup created: ${installResult.backup}`);
    } else {
      fail(`Install failed: ${installResult.message}`);
      process.exitCode = 1;
    }
  } finally {
    cleanupZip();
  }
}

module.exports = { runDeploy, runUpload, runInstall };
