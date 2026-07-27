/**
 * Guaranteed teardown, through core's HMAC provisioning door.
 *
 * The suite deletes its disposable project the way a person does — the Delete button in
 * the builder's danger zone — because that path is one of the things under test. This
 * module is the BACKSTOP for when that test never gets to run: a spec that fails at step
 * three leaves a provisioned instance, a clone, a jail and a registry row behind, and
 * the next run then starts dirty. So global-teardown calls this, unconditionally, for
 * anything the run created and did not remove.
 *
 * It signs the same {member_id, op, params, exp} envelope the workbench sidecar signs
 * (controls/Provision.php), using the shared secret from core's own config — the suite
 * runs on the host, so it can read what the sidecar reads.
 */
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const env = require('./env');

/** One value out of one section of an ini file. No dependency for eight lines. */
function iniValue(file, section, key) {
  let inSection = false;
  for (const raw of fs.readFileSync(file, 'utf8').split('\n')) {
    const line = raw.trim();
    if (line.startsWith('[')) { inSection = line === `[${section}]`; continue; }
    if (!inSection || !line || line.startsWith(';') || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq < 0) continue;
    if (line.slice(0, eq).trim() !== key) continue;
    return line.slice(eq + 1).split(';')[0].trim().replace(/^["']|["']$/g, '');
  }
  return '';
}

function secret() {
  const f = path.join(env.CORE_DIR, 'conf', 'config.ini');
  const s = iniValue(f, 'sidecar.workbench', 'sso_secret');
  if (!s) throw new Error(`No [sidecar.workbench] sso_secret in ${f} — cannot reach the provisioning door.`);
  return s;
}

async function call(op, params, memberId) {
  const payload = JSON.stringify({
    member_id: memberId,
    op,
    params,
    exp: Math.floor(Date.now() / 1000) + 120,
  });
  const sig = crypto.createHmac('sha256', secret()).update(payload).digest('hex');

  const res = await fetch(`${env.BASE_URL}/provision/call`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ payload, sig }).toString(),
  });
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* keep the raw body for the error */ }
  return { status: res.status, json, text: text.slice(0, 500) };
}

/**
 * Remove an instance. Idempotent by design: a 404 means the UI path already did it,
 * which is the happy case, not a failure.
 *
 * `is_root` is what the door itself uses to let an operator delete an instance they do
 * not own; anyone holding the sidecar secret already has provisioning authority, so this
 * grants nothing new — it just avoids needing to look up the owner's member id.
 */
async function deleteInstance({ id, slug, memberId = Number(process.env.E2E_MEMBER_ID || 1) }) {
  const confirm = `${slug}.${env.APP_NAMESPACE}.com`;
  const res = await call('delete', { id, confirm, is_root: true }, memberId);
  if (res.status === 404 || /No such instance/i.test(res.text)) {
    return { ok: true, alreadyGone: true };
  }
  if (!res.json || !res.json.success) {
    return { ok: false, error: `HTTP ${res.status}: ${res.text}` };
  }
  return { ok: true, steps: (res.json.data && res.json.data.steps) || [] };
}

module.exports = { deleteInstance, call, iniValue };
