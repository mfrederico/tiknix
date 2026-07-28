/**
 * The core sweep: every page of tiknix.com an admin can reach, and every control on it.
 *
 * For each page: it renders, its links resolve, its links tell the truth about where
 * they go, and its buttons have names. The `watch` fixture adds the invisible half —
 * console errors, failed XHRs, and anything the server logged while we were there.
 *
 * PAGES is deliberately a written-down list rather than a crawl, so it doubles as the
 * answer to "what does this app have?". The last test in the file guards it: if the nav
 * grows a link that is not swept here, the suite fails rather than quietly ignoring the
 * new surface.
 */
const { test, expect } = require('../fixtures/base');
const { auditLinks, unlabelledButtons, unlabelledLinks, report } = require('../lib/audit');

/** One line per unnamed control, with enough context to find it in a template. */
const nameless = c =>
  `${c.icon || c.className || '(no class)'}${c.id ? ' #' + c.id : ''}` +
  `${c.count > 1 ? ` x${c.count}` : ''}` +
  `${c.href ? ` -> ${c.href}` : ''}` +
  `${c.context ? `\n      in: "${c.context}"` : ''}`;
const env = require('../lib/env');

/** [path, what the page must show] — the heading/text that proves the right page loaded. */
const PAGES = [
  ['/dashboard',        /welcome back/i],
  ['/projects',         /projects/i],
  ['/connections',      /connections/i],
  ['/integrations',     /integrations/i],
  ['/apikeys',          /api keys/i],
  ['/teams',            /teams/i],
  ['/communications',   /communications|messages/i],
  ['/agentsetup',       /agent/i],
  ['/security',         /security/i],
  ['/member/profile',   /profile/i],
  ['/member/settings',  /settings/i],
  ['/member/edit',      /edit/i],
  ['/admin',            /admin/i],
  ['/leads',            /leads/i],
  ['/help',             /help/i],
  ['/docs',             /doc/i],
  ['/contact',          /contact/i],
];

for (const [url, expected] of PAGES) {
  test(`page ${url} renders, and every link on it resolves and is honest`, async ({ page, watch }) => {
    const res = await page.goto(url, { waitUntil: 'domcontentloaded' });
    expect(res.status(), `${url} returned HTTP ${res.status()}`).toBeLessThan(400);

    const body = await page.locator('body').innerText();
    expect(body, `${url} did not look like the page its route promises`).toMatch(expected);

    const audit = await auditLinks(page, { origin: env.BASE_URL });

    const problems =
      report('Dead links', audit.dead, d => `"${d.label || '(no label)'}" -> ${d.url || d.href} (${d.why})`) +
      report('Links whose label does not match their destination', audit.mislabelled,
        m => `"${m.label}" -> ${m.url}\n      ${m.why}`) +
      report('Buttons with no accessible name', unlabelledButtons(audit.controls), nameless) +
      report('Links with no accessible name', unlabelledLinks(audit.controls), nameless);

    expect(problems, `${url}: ${audit.checked} links checked\n${problems}`).toBe('');
  });
}

test('the nav offers nothing this suite does not sweep', async ({ page }) => {
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });

  const navLinks = await page.evaluate(() =>
    [...document.querySelectorAll('.ui-nav-link, .dropdown-item')]
      .map(a => ({ label: a.innerText.replace(/\s+/g, ' ').trim(), href: a.getAttribute('href') }))
      .filter(x => x.href && x.href.startsWith('/'))
  );

  const swept = new Set(PAGES.map(([p]) => p));
  // Sidecars have their own spec; sign-out has its own test; project-scoped links are
  // covered by the lifecycle spec.
  const elsewhere = /^\/sidecar\/app\/|^\/auth\/logout$/;

  const unswept = navLinks
    .filter(l => !swept.has(l.href) && !elsewhere.test(l.href))
    .map(l => `"${l.label}" -> ${l.href}`);

  expect(unswept.join('\n'),
    'The nav grew links this suite does not visit. Add them to PAGES in 01-core-sweep.spec.js.')
    .toBe('');
});

test('the nav shows the control-plane tools on the control plane', async ({ page }) => {
  // The flip side of hiding what an instance does not have: on the control plane those
  // links must actually be there. Gating is one edit away from hiding something
  // everywhere, and a nav that quietly loses Projects is the kind of regression nobody
  // reports — they just assume it moved.
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });

  const nav = await page.locator('.ui-nav').innerText();
  for (const label of ['Projects', 'Teams', 'Communications']) {
    expect(nav, `the nav lost "${label}" on the control plane`).toContain(label);
  }
});

test('the topbar is the same height on every page', async ({ page }) => {
  // It was not. --ui-topbar-height is 62px, but .ui-topbar sits in a flex column
  // (.ui-main) and had no flex-shrink, so on any page whose content was tall enough the
  // browser squashed it — 62px on short pages like /leads, 42.59px on /dashboard. The
  // fractional height was the tell: nothing declares 42.59, it is what shrinking leaves.
  //
  // Measured, not asserted against a number, so the check survives a deliberate change to
  // the variable: what matters is that every page agrees.
  const heights = {};
  for (const [url] of PAGES) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    const h = await page.evaluate(() => {
      const t = document.querySelector('.ui-topbar');
      if (!t) return null;
      const declared = getComputedStyle(document.documentElement)
        .getPropertyValue('--ui-topbar-height').trim();
      return { actual: Math.round(t.getBoundingClientRect().height * 100) / 100, declared };
    });
    if (h) heights[url] = h;
  }

  const distinct = [...new Set(Object.values(heights).map(h => h.actual))];
  const detail = Object.entries(heights).map(([u, h]) => `  ${u}: ${h.actual}px`).join('\n');
  expect(distinct.length, `the topbar is not a consistent height:\n${detail}`).toBe(1);

  // And it should be the height the design system declares, not a squashed approximation.
  const first = Object.values(heights)[0];
  if (first && first.declared) {
    expect(`${first.actual}px`, 'the topbar is not the height --ui-topbar-height declares')
      .toBe(first.declared);
  }
});
