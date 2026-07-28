/**
 * THE LOOP CLOSING: how you find out a build finished.
 *
 * The other clips end when the work ends. This one is what a real user actually
 * experiences — they were not watching the board, and something has to tell them. The
 * bell in the shell, the thread it opens, and what the message says when a build did not
 * fully land.
 *
 * Short by design: it is a closing beat, not a story.
 *
 *   DEMO_TAKE=seg4-notify npm run demo:notify
 */
const { test, expect } = require('@playwright/test');
const env = require('../lib/env');

const BEAT = Number(process.env.DEMO_BEAT || 2500);
const hold = (page, ms = BEAT) => page.waitForTimeout(ms);
const beat = (n, what) => console.log(`\n[demo ${n}] ${what}`);

test('the build tells you it finished', async ({ page }) => {
  // ---- 1. the bell, with something in it -----------------------------------
  beat(1, 'the shell, with an unread notice');
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
  await hold(page);

  const bell = page.locator('#notify-bell');
  await expect(bell, 'the shell has no notification bell').toBeVisible({ timeout: 30_000 });

  const badge = bell.locator('.notify-bell-count');
  await expect(badge, 'the bell shows no unread count — is there an unread thread?')
    .toBeVisible({ timeout: 30_000 });
  console.log('        unread badge: ' + (await badge.innerText()).trim());

  // ---- 2. open it ----------------------------------------------------------
  beat(2, 'opening the bell');
  await bell.locator('a[data-bs-toggle="dropdown"], [role=button]').first().click();
  await hold(page);

  const menu = bell.locator('.notify-bell-menu');
  await expect(menu, 'the bell dropdown did not open').toBeVisible({ timeout: 15_000 });
  const items = await bell.locator('.notify-bell-list li').count();
  console.log(`        ${items} notice(s) in the bell`);

  // ---- 3. the thread itself ------------------------------------------------
  beat(3, 'the notice: what finished, what did not, and what you can do about it');
  const first = bell.locator('.notify-bell-list a').first();
  if (await first.count()) {
    await first.click();
  } else {
    await page.goto('/communications', { waitUntil: 'domcontentloaded' });
    await page.locator('a[href*="/communications/thread/"]').first().click();
  }
  await page.waitForLoadState('domcontentloaded');
  await hold(page, BEAT * 2);

  const body = await page.locator('body').innerText();
  expect(body, 'the thread does not look like a build notice').toMatch(/build (finished|needs attention)/i);
  console.log('        ' + (body.match(/Build (finished|needs attention):.*/i) || ['(no subject line)'])[0]);

  // Let the reader actually read it — this is the payoff shot.
  await hold(page, BEAT * 3);
});
