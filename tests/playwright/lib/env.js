/**
 * Every knob the suite has, in one place.
 *
 * Credentials come from the environment (or an untracked .env beside this file), never
 * from a tracked file: this repo is the template every instance is cloned from, so a
 * password committed here would ship to every customer's app. See .env.example.
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

// Minimal .env reader — the suite has one dependency (playwright) and is not worth a
// second one for eight lines of parsing.
(function loadDotEnv() {
  const f = path.join(ROOT, '.env');
  if (!fs.existsSync(f)) return;
  for (const line of fs.readFileSync(f, 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (!m) continue;
    if (process.env[m[1]] === undefined) {
      process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
    }
  }
})();

const env = {
  ROOT,
  STATE_DIR: path.join(ROOT, '.state'),
  STORAGE_STATE: path.join(ROOT, '.state', 'admin.json'),
  RUN_FILE: path.join(ROOT, '.state', 'run.json'),

  BASE_URL: process.env.E2E_BASE_URL || 'https://tiknix.com',
  USER: process.env.E2E_USER || 'admin',
  PASS: process.env.E2E_PASS || '',

  // Where the working copies live on this host. The suite runs ON the host, so its
  // "build a small app" step edits the project's files exactly as a developer would.
  INSTANCE_ROOT: process.env.E2E_INSTANCE_ROOT || '/var/www/html/default',
  CORE_DIR: process.env.E2E_CORE_DIR || '/var/www/html/default/tiknix',
  APP_NAMESPACE: process.env.E2E_APP_NAMESPACE || 'tiknix',

  /**
   * OPT-IN targets. A container deploy spends real hypervisor capacity and a real repo
   * push spends a real repo, so a routine run does neither. Default publish coverage is
   * the local rsync target, which costs a directory.
   */
  HOSTED: process.env.E2E_HOSTED === '1',
  GITHUB: process.env.E2E_GITHUB === '1',

  // rsync publish target for the disposable project (must already accept the instance's
  // key — the suite asserts the handshake rather than creating trust it does not have).
  RSYNC_HOST: process.env.E2E_RSYNC_HOST || 'localhost',
  RSYNC_USER: process.env.E2E_RSYNC_USER || 'ubuntu',
  RSYNC_PATH: process.env.E2E_RSYNC_PATH || '/var/www/html/default/test-destination-rsync.tiknix',

  // Extra log files to watch. nginx/openresty logs are usually root-owned; add them here
  // when the runner can read them (E2E_EXTRA_LOGS=/var/log/nginx/error.log,...).
  EXTRA_LOGS: (process.env.E2E_EXTRA_LOGS || '').split(',').map(s => s.trim()).filter(Boolean),

  // Sidecars to sweep. Reached through core at /sidecar/app/<name> so the SSO hop is
  // part of what gets tested.
  SIDECARS: (process.env.E2E_SIDECARS || 'workbench,pipelines,publisher,explorer,shop')
    .split(',').map(s => s.trim()).filter(Boolean),
};

if (!env.PASS) {
  throw new Error(
    'E2E_PASS is not set. Copy tests/playwright/.env.example to .env and fill it in ' +
    '(the file is gitignored), or export E2E_PASS before running.'
  );
}

module.exports = env;
