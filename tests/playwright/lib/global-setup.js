/**
 * Global setup: sign in once, and remember what the account looked like before we
 * touched it.
 *
 * The suite runs against a LIVE site as a real admin, so it has to be a good guest.
 * Selecting a project is a persistent, account-wide change — the sidecars all follow
 * that selection — which means a sweep that picks its disposable project and walks away
 * has silently rearranged the owner's desk. We record the prior selection here and
 * global-teardown puts it back.
 */
const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const env = require('./env');

module.exports = async () => {
  fs.mkdirSync(env.STATE_DIR, { recursive: true });

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ baseURL: env.BASE_URL, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();

  await page.goto('/auth/login', { waitUntil: 'domcontentloaded' });
  await page.locator('#username').fill(env.USER);
  await page.locator('#password').fill(env.PASS);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.getByRole('button', { name: 'Login' }).click(),
  ]);

  // 2FA is OPTIONAL on this deployment ([security] two_factor_enforce = false), so an
  // admin login lands on the setup prompt with a way past it. If enforcement is ever
  // turned on, this is the line that will fail — and it should, loudly, rather than the
  // suite silently testing a logged-out site.
  if (/\/auth\/twofa(setup|verify)/.test(page.url())) {
    const skip = page.getByRole('button', { name: /skip for now/i });
    if (await skip.count()) {
      await skip.click();
      await page.waitForLoadState('domcontentloaded');
    }
  }

  if (!/\/dashboard/.test(page.url())) {
    const body = (await page.locator('body').innerText().catch(() => '')).slice(0, 300);
    throw new Error(`Login did not reach /dashboard (at ${page.url()}).\n${body}`);
  }

  // What was selected before us? The active card's button reads "Continue".
  await page.goto('/projects', { waitUntil: 'domcontentloaded' });
  const priorProjectId = await page.evaluate(() => {
    const btn = [...document.querySelectorAll('.proj-pick')]
      .find(b => /continue/i.test(b.innerText));
    return btn ? Number(btn.dataset.id) : 0;
  });

  await ctx.storageState({ path: env.STORAGE_STATE });

  const run = {
    startedAt: new Date().toISOString(),
    priorProjectId,
    project: null,          // filled in by the lifecycle spec the moment one is created
  };
  fs.writeFileSync(env.RUN_FILE, JSON.stringify(run, null, 2));

  console.log(`[e2e] signed in as ${env.USER}; prior project id = ${priorProjectId || 'none'}`);
  console.log(`[e2e] hosted publish: ${env.HOSTED ? 'ON (will create a container)' : 'off'}; ` +
              `github publish: ${env.GITHUB ? 'ON (will push a repo)' : 'off'}`);

  await browser.close();
};

module.exports.readRun = () => {
  try { return JSON.parse(fs.readFileSync(env.RUN_FILE, 'utf8')); } catch { return {}; }
};

module.exports.writeRun = (patch) => {
  const cur = module.exports.readRun();
  const next = { ...cur, ...patch };
  fs.mkdirSync(path.dirname(env.RUN_FILE), { recursive: true });
  fs.writeFileSync(env.RUN_FILE, JSON.stringify(next, null, 2));
  return next;
};
