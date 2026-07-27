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

test("the builder's terminal bridge is reachable through the front door", async ({ page }) => {
  // The xterm connects to a node bridge on CORE: wss://<core>/aibuilder/ws, proxied by
  // openresty to 127.0.0.1:3990. Three ways that goes wrong, and they look different:
  //
  //   502  the proxy is routing and the bridge is not running
  //   404  something is routing this path somewhere that is not the bridge
  //   303  it fell through to the app, which sent it to a login page
  //
  // So "not a 5xx" is NOT good enough — the terminal is equally dead in all three, and a
  // check that accepts them would go green while nothing works. A bridge actually being
  // reached answers 401 (it wants the HMAC token this request does not carry) or, with
  // one, 101. Those are the only two passes.
  //
  // Probe over HTTP/1.1, which is what playwright's request context and a real browser's
  // websocket handshake both use. The same URL answers 404 over HTTP/2, where Upgrade is
  // not a legal header at all — an artifact of the probe, not a broken route.
  const res = await page.request.get(`${env.BASE_URL}/aibuilder/ws`, {
    maxRedirects: 0,
    headers: {
      Connection: 'Upgrade',
      Upgrade: 'websocket',
      'Sec-WebSocket-Version': '13',
      'Sec-WebSocket-Key': 'dGhlIHNhbXBsZSBub25jZQ==',
    },
  });

  const status = res.status();
  const why =
    status >= 500 ? 'the proxy is routing but the bridge is not answering — is it running?'
    : status === 404 ? 'nothing is routing /aibuilder/ws to the bridge'
    : status >= 300 && status < 400 ? 'it fell through to the app and redirected — no proxy for it'
    : `unexpected status ${status}`;

  expect([101, 401], `the builder's terminal is dead: ${why}`).toContain(status);
});
