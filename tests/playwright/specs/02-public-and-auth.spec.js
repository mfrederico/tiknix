/**
 * The logged-out surface, and the doors between it and the rest.
 *
 * Runs in a signed-out context on purpose: everything else in this suite reuses one
 * admin session, and signing out inside it would break every spec that follows. Here we
 * check the two halves of the promise a permission system makes — public pages are
 * reachable by anyone, and private ones are not reachable by no-one — plus the thing
 * that is easy to get wrong at the boundary: failing gracefully rather than leaking a
 * stack trace.
 */
const { test, expect } = require('../fixtures/base');
const { auditLinks, unlabelledButtons, unlabelledLinks, report } = require('../lib/audit');
const env = require('../lib/env');

test.use({ storageState: { cookies: [], origins: [] } });

const PUBLIC_PAGES = [
  ['/',               /tiknix/i],
  ['/pricing',        /pricing|per instance|\$/i],
  ['/privacy',        /privacy/i],
  ['/terms',          /terms/i],
  ['/contact',        /contact/i],
  ['/help',           /help/i],
  ['/docs',           /doc|tiknix/i],
  ['/auth/login',     /login|sign in/i],
  ['/auth/register',  /register|create/i],
  ['/auth/forgot',    /forgot|reset/i],
];

for (const [url, expected] of PUBLIC_PAGES) {
  test(`signed out, ${url} is public and its links resolve`, async ({ page }) => {
    const res = await page.goto(url, { waitUntil: 'domcontentloaded' });
    expect(res.status(), `${url} returned HTTP ${res.status()} to a signed-out visitor`).toBe(200);

    const body = await page.locator('body').innerText();
    expect(body, `${url} did not look like the page its route promises`).toMatch(expected);

    const audit = await auditLinks(page, { origin: env.BASE_URL });
    const problems =
      report('Dead links', audit.dead, d => `"${d.label || '(no label)'}" -> ${d.url || d.href} (${d.why})`) +
      report('Links whose label does not match their destination', audit.mislabelled,
        m => `"${m.label}" -> ${m.url}\n      ${m.why}`) +
      report('Controls with no accessible name',
        [...unlabelledButtons(audit.controls), ...unlabelledLinks(audit.controls)],
        c => `${c.icon || c.className || '(no class)'}${c.href ? ' -> ' + c.href : ''}`);

    expect(problems, `${url}: ${audit.checked} links checked\n${problems}`).toBe('');
  });
}

for (const url of ['/dashboard', '/projects', '/admin', '/connections', '/apikeys']) {
  test(`signed out, ${url} sends you to sign in rather than showing you anything`, async ({ page }) => {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    expect(page.url(), `${url} did not redirect a signed-out visitor to the login page`)
      .toMatch(/\/auth\/login/);
  });
}

test('a route that does not exist gives an error page, not a stack trace', async ({ page, watch }) => {
  // This test asks for a 404 on purpose, so the 404 is the expected result rather than
  // a finding. Everything else the watcher looks at still applies.
  watch.allowConsole(/nope-not-a-route|status of 404/);
  watch.allowServer(/Controller not found/);

  const res = await page.goto('/nope-not-a-route-' + Date.now(), { waitUntil: 'domcontentloaded' });
  expect(res.status()).toBe(404);

  const body = await page.locator('body').innerText();
  // A leaked trace names the file it came from; that is the tell we check for.
  expect(body, 'the 404 page leaked internals').not.toMatch(/Stack trace|#0 \/var\/www|Fatal error|on line \d+/i);
  expect(body, 'the 404 page did not say anything useful').toMatch(/not found|404|does not exist/i);
});

test('signing in and out both work, and out means out', async ({ page }) => {
  await page.goto('/auth/login', { waitUntil: 'domcontentloaded' });
  await page.locator('#username').fill(env.USER);
  await page.locator('#password').fill(env.PASS);
  await page.getByRole('button', { name: 'Login' }).click();
  await page.waitForLoadState('domcontentloaded');

  if (/\/auth\/twofa(setup|verify)/.test(page.url())) {
    await page.getByRole('button', { name: /skip for now/i }).click();
    await page.waitForLoadState('domcontentloaded');
  }
  expect(page.url(), 'signing in did not reach the dashboard').toMatch(/\/dashboard/);

  await page.goto('/auth/logout', { waitUntil: 'domcontentloaded' });
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
  expect(page.url(), 'the dashboard was still reachable after signing out').toMatch(/\/auth\/login/);
});

test('a wrong password is refused without saying which half was wrong', async ({ page, watch }) => {
  await page.goto('/auth/login', { waitUntil: 'domcontentloaded' });
  await page.locator('#username').fill(env.USER);
  await page.locator('#password').fill('definitely-not-the-password');
  await page.getByRole('button', { name: 'Login' }).click();
  await page.waitForLoadState('domcontentloaded');

  expect(page.url(), 'a wrong password got in').toMatch(/\/auth\/login/);

  const body = await page.locator('body').innerText();
  // Telling an attacker the username was right is a free enumeration oracle.
  expect(body, 'the login error distinguished a bad username from a bad password')
    .not.toMatch(/password is (in)?correct|no such user|user (does )?not (exist|found)/i);

  // A refused login is a warning by design — the point is that it IS logged.
  watch.allowServer(/login|auth/i);
});
