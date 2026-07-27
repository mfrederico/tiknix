/**
 * The sidecars: five separate applications reached through core's SSO hop.
 *
 * This is the seam where things have actually broken. Each sidecar is its own repo with
 * its own session, embedded in core's shell through an iframe, and the extraction that
 * put them there left behind links pointing at routes that had moved. So the sweep
 * treats what is inside the frame exactly as it treats core: every link resolves, every
 * label tells the truth, every control has a name.
 */
const { test, expect } = require('../fixtures/base');
const { auditLinks, unlabelledButtons, unlabelledLinks, report } = require('../lib/audit');
const env = require('../lib/env');

/** The frame a sidecar renders into, once it has actually loaded something. */
async function sidecarFrame(page, name) {
  await page.goto(`/sidecar/app/${name}`, { waitUntil: 'domcontentloaded' });

  const embedded = page.frameLocator('.sidecar-embed iframe');
  await expect(embedded.locator('body'), `${name} never rendered inside its frame`)
    .not.toBeEmpty({ timeout: 30_000 });

  // frameLocator drives the frame; the Frame object is what the audit evaluates in.
  const frame = page.frames().find(f => f !== page.mainFrame() && /https?:/.test(f.url()));
  expect(frame, `${name} produced no loaded frame`).toBeTruthy();
  return frame;
}

for (const name of env.SIDECARS) {
  test(`${name} loads through SSO, and every control inside it holds up`, async ({ page, watch }) => {
    // The builder's terminal talks to a bridge on core; it has its own test below.
    watch.allowConsole(/aibuilder\/(chat-)?ws/);

    const frame = await sidecarFrame(page, name);

    const text = await frame.locator('body').innerText();
    expect(text.trim(), `${name} loaded an empty page`).not.toBe('');
    expect(text, `${name} rendered an error instead of its app`)
      .not.toMatch(/Fatal error|Stack trace|Uncaught \w*Error|SQLSTATE/i);

    const audit = await auditLinks(frame, { origin: env.BASE_URL });
    const problems =
      report('Dead links', audit.dead, d => `"${d.label || '(no label)'}" -> ${d.url || d.href} (${d.why})`) +
      report('Links whose label does not match their destination', audit.mislabelled,
        m => `"${m.label}" -> ${m.url}\n      ${m.why}`) +
      report('Controls with no accessible name',
        [...unlabelledButtons(audit.controls), ...unlabelledLinks(audit.controls)],
        c => `${c.icon || c.className || '(no class)'}${c.href ? ' -> ' + c.href : ''}` +
             `${c.count > 1 ? ` x${c.count}` : ''}${c.context ? `\n      in: "${c.context}"` : ''}`);

    expect(problems, `${name}: ${audit.checked} links checked\n${problems}`).toBe('');
  });
}

test('a sidecar cannot be reached without going through core', async ({ browser }) => {
  // Signed out and straight at the sidecar's own host: SSO is the only door, so this
  // must not hand over an app session.
  const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    const res = await ctx.request.get('https://publisher.tiknix.com/publish', { maxRedirects: 0 });
    expect([301, 302, 303, 401, 403], `the publisher answered ${res.status()} to a stranger`)
      .toContain(res.status());
  } finally {
    await ctx.close();
  }
});

test("the builder's terminal bridge is up", async ({ page }) => {
  // The xterm connects to a node bridge on CORE (wss://<core>/aibuilder/ws -> 127.0.0.1:3990),
  // so a builder page that renders perfectly still has a dead terminal if that service is
  // not running. Probed directly rather than by listening for a failed websocket: a
  // handshake refused at the HTTP layer does not reliably raise a socket error, and a
  // check that only sometimes notices is worse than no check.
  const res = await page.request.get(`${env.BASE_URL}/aibuilder/ws`, { maxRedirects: 0 });
  expect(res.status(),
    'the terminal bridge is not answering — the builder renders but its terminal is dead')
    .toBeLessThan(500);
});
