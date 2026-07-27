/**
 * Global teardown — the part that must run even when everything else went wrong.
 *
 * Two obligations, in order of how much they would cost to skip:
 *   1. destroy the disposable project, if the run made one and did not remove it;
 *   2. put the owner's selected project back the way we found it.
 *
 * Both are best-effort in the sense that they never throw — a teardown that fails the
 * run would mask the actual test failure that caused it — but both LOG loudly, because
 * a leaked instance is something a human has to go clean up.
 */
const { chromium } = require('@playwright/test');
const fs = require('fs');
const env = require('./env');
const { deleteInstance } = require('./provision');

module.exports = async () => {
  let run = {};
  try { run = JSON.parse(fs.readFileSync(env.RUN_FILE, 'utf8')); } catch { return; }

  // 1. The disposable project.
  const p = run.project;
  if (p && p.id && !p.deleted) {
    process.stdout.write(`[e2e] teardown: removing ${p.slug} (id ${p.id})… `);
    try {
      const res = await deleteInstance({ id: p.id, slug: p.slug });
      if (res.ok) {
        console.log(res.alreadyGone ? 'already gone' : 'removed');
        run.project = { ...p, deleted: true };
      } else {
        console.error(`FAILED — ${res.error}\n[e2e] LEAKED INSTANCE: ${p.slug} (id ${p.id}) needs manual removal.`);
      }
    } catch (e) {
      console.error(`FAILED — ${e.message}\n[e2e] LEAKED INSTANCE: ${p.slug} (id ${p.id}) needs manual removal.`);
    }
  }

  // 2. The owner's project selection, which our run changed account-wide.
  if (run.priorProjectId) {
    let browser;
    try {
      browser = await chromium.launch();
      const ctx = await browser.newContext({
        baseURL: env.BASE_URL, storageState: env.STORAGE_STATE, ignoreHTTPSErrors: true,
      });
      const page = await ctx.newPage();
      await page.goto('/projects', { waitUntil: 'domcontentloaded' });
      const restored = await page.evaluate(async (id) => {
        const btn = document.querySelector(`.proj-pick[data-id="${id}"]`);
        if (!btn) return 'card is gone';
        if (/continue/i.test(btn.innerText)) return 'already selected';
        const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
        const r = await fetch('/projects/select', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
          body: new URLSearchParams({ csrf_token: csrf, id: String(id) }).toString(),
        });
        const j = await r.json().catch(() => null);
        return j && j.success ? 'restored' : `failed: ${(j && j.message) || r.status}`;
      }, run.priorProjectId);
      console.log(`[e2e] teardown: project selection -> ${restored} (id ${run.priorProjectId})`);
    } catch (e) {
      console.error(`[e2e] teardown: could not restore project selection — ${e.message}`);
    } finally {
      if (browser) await browser.close();
    }
  }

  try { fs.writeFileSync(env.RUN_FILE, JSON.stringify(run, null, 2)); } catch { /* nothing left to do */ }
};
