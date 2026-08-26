/**
 * Engine terminal regression test.
 *
 * Grew out of a four-cause bug where picking a non-default engine silently landed you in
 * the default one's session. Every check here is a cause that actually shipped:
 *
 *   1. the picker renders the engine that was asked for
 *   2. the tmux status line names that engine's session (aib-<engine>), not the default's
 *   3. no engine-mismatch or jail refusal text appears
 *
 * Check 2 is the one that matters. The page looked correct for all four bugs; the status
 * line was the only place the truth showed.
 *
 * Credentials come from the environment — never commit them:
 *   TIKNIX_TEST_USER=admin TIKNIX_TEST_PASS='…' node engine-terminal.mjs [engine] [project]
 *
 * A project already signed in to that engine is required, since this asserts a live
 * session rather than a login prompt.
 */
import { chromium } from 'playwright';

const BASE    = process.env.TIKNIX_BASE     || 'https://tiknix.com';
const WB      = process.env.TIKNIX_WB_BASE  || 'https://workbench.tiknix.com';
const USER    = process.env.TIKNIX_TEST_USER;
const PASS    = process.env.TIKNIX_TEST_PASS;
const ENGINE  = process.argv[2] || 'zai';

// Fail loudly rather than running a test that silently proves nothing.
if (!USER || !PASS) {
  console.error('FAIL: set TIKNIX_TEST_USER and TIKNIX_TEST_PASS in the environment.');
  process.exit(2);
}

const fails = [];
const check = (ok, label, detail = '') => {
  console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${label}${detail ? ' — ' + detail : ''}`);
  if (!ok) fails.push(label);
};

const browser = await chromium.launch();
const ctx = await browser.newContext({ baseURL: BASE, ignoreHTTPSErrors: true });
const page = await ctx.newPage();
const pageErrors = [];
page.on('pageerror', e => pageErrors.push(String(e).slice(0, 160)));

try {
  await page.goto('/auth/login', { waitUntil: 'domcontentloaded' });
  await page.locator('#username').fill(USER);
  await page.locator('#password').fill(PASS);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.getByRole('button', { name: 'Login' }).click(),
  ]);

  // 2FA may be optional (policy) — skip when offered, so the test works either way.
  const skip = page.getByRole('button', { name: /skip for now/i });
  if (await skip.count()) {
    await skip.click();
    await page.waitForLoadState('domcontentloaded');
  }
  check(!/\/auth\/login/.test(page.url()), 'logged in', page.url());

  // The sidecar keeps its OWN session; going straight to it just bounces to /workbench.
  await page.goto('/sidecar/launch/workbench', { waitUntil: 'domcontentloaded' });
  await page.goto(`${WB}/aibuilder?engine=${encodeURIComponent(ENGINE)}`, { waitUntil: 'domcontentloaded' });
  check(/\/aibuilder/.test(page.url()), 'reached the builder page', page.url());

  const picker = page.locator('#ab-engine');
  check(await picker.count() > 0 && await picker.inputValue() === ENGINE,
        'picker shows the requested engine');

  // Let the PTY attach and tmux paint its status line.
  await page.waitForTimeout(6000);
  const screen = (await page.locator('#ab-terminal').innerText()).trim();

  check(screen !== '', 'terminal produced output');
  check(screen.includes(`aib-${ENGINE}`), `attached to the ${ENGINE} tmux session`,
        (screen.match(/\[aib-[a-z0-9-]+\]?/) || ['no session marker'])[0]);
  check(!/is already running for this instance/.test(screen), 'no engine-mismatch note');
  check(!/jail-run:/.test(screen), 'jail did not refuse',
        (screen.match(/jail-run: .{0,90}/) || [''])[0]);
  check(!/duplicate session/.test(screen), 'no duplicate tmux session');
  check(pageErrors.length === 0, 'no page errors', pageErrors.join(' | '));
} finally {
  await browser.close();
}

console.log(fails.length ? `\nFAILED: ${fails.join(', ')}` : '\nAll checks passed.');
process.exit(fails.length ? 1 : 0);
