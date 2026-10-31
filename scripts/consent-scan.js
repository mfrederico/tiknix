// The consent plugin's page scan, run on the QA host by scripts/consent-scan.php (CONFIG is put in front of this file).
// Visits a site as a stranger, then signed in, and NEVER answers the cookie question: all it sees is what happens before consent.
// It cannot change anything: every request that is not a GET is noted and stopped, and addresses that look like actions are skipped.
const { chromium } = require(require.resolve('playwright', {paths: [process.cwd(), process.cwd() + '/node_modules/@playwright/mcp']}));
const ACTION = /(^|\/)[^/]*(logout|signout|delete|remove|destroy|cancel|unsubscribe|revoke|disconnect|reset|save|submit|store|update|create|send|webhook|callback|hook|report|choose|launch|download|export)[^/]*(\/|$)/i;
const SKIP_EXT = /\.(pdf|zip|csv|xlsx?|docx?|png|jpe?g|gif|webp|svg|ico|mp4|webm|mp3|css|js|json|xml|txt)$/i;

async function crawl(context, start, base, limits) {
  const own = new URL(base).host;
  const seen = new Set(), shape = {}, queue = start.map(p => p);
  const out = {pages: [], hosts: {}, cookies: {}, storage: {}, stopped: 0};
  const page = await context.newPage();
  let current = '/';
  await page.route('**/*', route => {
    const r = route.request(), host = new URL(r.url()).host;
    if (/^https?:/.test(r.url()) && host !== own) {
      const h = out.hosts[host] = out.hosts[host] || {types: [], pages: [], count: 0};
      h.count++;
      if (!h.types.includes(r.resourceType())) h.types.push(r.resourceType());
      if (h.pages.length < 3 && !h.pages.includes(current)) h.pages.push(current);
    }
    if (r.method() !== 'GET' && r.method() !== 'HEAD') { out.stopped++; return route.abort(); }
    return route.continue();
  });
  const started = Date.now();
  while (queue.length && out.pages.length < limits.pages && Date.now() - started < limits.ms) {
    const path = queue.shift();
    if (seen.has(path) || ACTION.test(path) || SKIP_EXT.test(path)) continue;
    // /community/5 and /community/6 are one page to look at: two of a kind is enough
    const kind = path.replace(/\/\d+(?=\/|$)/g, '/N');
    if ((shape[kind] = (shape[kind] || 0) + 1) > 2) continue;
    seen.add(path); current = path;
    let status = 0, final = path;
    try {
      const resp = await page.goto(base + path, {waitUntil: 'networkidle', timeout: 12000});
      status = resp ? resp.status() : 0; final = new URL(page.url()).pathname;
    } catch (e) { status = /Timeout/.test(e.message) ? 'slow' : 'failed'; }
    out.pages.push({path, status, final});
    if (new URL(page.url()).host !== own) continue;
    for (const c of await context.cookies()) { if (!out.cookies[c.name]) out.cookies[c.name] = {domain: c.domain, page: path, session: c.expires === -1}; }
    const found = await page.evaluate(() => ({
      local: Object.keys(localStorage), session: Object.keys(sessionStorage),
      links: Array.from(document.querySelectorAll('a[href]')).map(a => a.href)
    })).catch(() => ({local: [], session: [], links: []}));
    for (const k of found.local) out.storage['local:' + k] = out.storage['local:' + k] || path;
    for (const k of found.session) out.storage['session:' + k] = out.storage['session:' + k] || path;
    for (const l of found.links) { try { const u = new URL(l); if (u.host === own && !seen.has(u.pathname)) queue.push(u.pathname); } catch (e) {} }
  }
  await page.close();
  return out;
}

(async () => {
  const b = await chromium.launch({headless: true});
  const result = {ok: true, host: new URL(CONFIG.base).host, visitor: null, member: null};
  // signed in first: the sign-in token lives a minute, and the stranger's walk can take longer
  let signedIn = null;
  if (CONFIG.token) {
    signedIn = await b.newContext({viewport: {width: 1280, height: 900}});
    const p = await signedIn.newPage();
    await p.goto(CONFIG.base + '/auth/login');
    await p.evaluate(t => { const f = document.createElement('form'); f.method = 'POST'; f.action = '/auth/launch'; const i = document.createElement('input'); i.name = 't'; i.value = t; f.appendChild(i); document.body.appendChild(f); f.submit(); }, CONFIG.token);
    await p.waitForLoadState('networkidle').catch(() => {});
    await p.close();
  }
  const stranger = await b.newContext({viewport: {width: 1280, height: 900}});
  result.visitor = await crawl(stranger, ['/', ...CONFIG.visitorPages], CONFIG.base, {pages: CONFIG.maxPages, ms: 200000});
  await stranger.close();
  if (signedIn) { result.member = await crawl(signedIn, ['/', ...CONFIG.memberPages], CONFIG.base, {pages: CONFIG.maxPages, ms: 200000}); await signedIn.close(); }
  await b.close();
  console.log('CONSENT-SCAN-RESULT ' + JSON.stringify(result));
})().catch(e => { console.log('CONSENT-SCAN-RESULT ' + JSON.stringify({ok: false, error: e.message.slice(0, 500)})); process.exit(0); });
