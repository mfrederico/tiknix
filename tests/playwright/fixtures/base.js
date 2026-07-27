/**
 * The base test: nothing in this suite runs without a watcher attached.
 *
 * Every test gets a `watch` fixture that brackets it — browser console, page errors,
 * failed network responses, and everything the servers wrote to their own logs while it
 * ran. The fixture asserts at teardown, so a spec author cannot forget to check: a page
 * that renders perfectly while logging a fatal is a failure here, which is exactly the
 * class of defect that survived every previous manual sweep.
 *
 * Noise is handled by declaring it, not by lowering the bar. A test that expects a 403
 * says so (`watch.allowConsole(/403/)`), and that declaration is visible in the test.
 */
const base = require('@playwright/test');
const path = require('path');
const env = require('../lib/env');
const { ServerLogs } = require('../lib/serverlogs');

/** Third-party and browser-level chatter that says nothing about our app. */
const CONSOLE_NOISE = [
  /favicon\.ico/i,
  /Download the React DevTools/i,
  /\[DOM\] Found 2 elements with non-unique id/i,          // bootstrap collapse markup
  /net::ERR_(BLOCKED_BY_CLIENT|CONNECTION_REFUSED) .*(google|doubleclick|analytics)/i,
];

class Watcher {
  constructor(testInfo) {
    this.testInfo = testInfo;
    this.console = [];
    this.pageErrors = [];
    this.badResponses = [];
    this.consoleAllow = [...CONSOLE_NOISE];
    this.serverAllow = [];
    this.logs = new ServerLogs([path.join(env.CORE_DIR, 'log'), ...env.EXTRA_LOGS]);
  }

  /** Watch a project's own log directory too, once its slug is known. */
  watchInstance(slug) {
    this.logs.targets.push(path.join(env.INSTANCE_ROOT, `${slug}.${env.APP_NAMESPACE}`, 'log'));
    this.logs.mark();     // from here, not from the start of the test
  }

  allowConsole(re) { this.consoleAllow.push(re); return this; }
  allowServer(re) { this.serverAllow.push(re); return this; }

  attach(page) {
    page.on('console', msg => {
      if (msg.type() !== 'error' && msg.type() !== 'warning') return;
      this.console.push(`${msg.type()}: ${msg.text()}`.slice(0, 400));
    });
    page.on('pageerror', err => this.pageErrors.push(String(err).slice(0, 400)));
    page.on('response', res => {
      const s = res.status();
      if (s < 400) return;
      // Only our own hosts — a third-party beacon failing is not our defect.
      const host = new URL(res.url()).hostname;
      if (!host.endsWith('tiknix.com') && host !== new URL(env.BASE_URL).hostname) return;
      this.badResponses.push(`HTTP ${s} ${res.request().method()} ${res.url()}`.slice(0, 400));
    });
  }

  findings() {
    const keep = (list, allow) => list.filter(l => !allow.some(re => re.test(l)));
    const { errors, warnings, watched } = this.logs.harvest();
    return {
      console: keep(this.console.filter(c => c.startsWith('error')), this.consoleAllow),
      pageErrors: keep(this.pageErrors, this.consoleAllow),
      badResponses: keep(this.badResponses, this.consoleAllow),
      serverErrors: keep(errors, this.serverAllow),
      serverWarnings: keep(warnings, this.serverAllow),
      logFilesWatched: watched,
    };
  }
}

const test = base.test.extend({
  watch: [async ({ page }, use, testInfo) => {
    const w = new Watcher(testInfo);
    w.logs.mark();
    w.attach(page);

    await use(w);

    const f = w.findings();
    const hard = [
      ...f.pageErrors.map(x => `page error: ${x}`),
      ...f.console.map(x => `console ${x}`),
      ...f.badResponses.map(x => `response: ${x}`),
      ...f.serverErrors.map(x => `server log: ${x}`),
    ];

    if (f.serverWarnings.length) {
      await testInfo.attach('server-warnings', {
        body: f.serverWarnings.join('\n'), contentType: 'text/plain',
      });
    }
    if (hard.length) {
      await testInfo.attach('watch-findings', { body: hard.join('\n'), contentType: 'text/plain' });
      // Don't pile onto an already-failing test — the first failure is the useful one.
      if (testInfo.status === 'passed' || testInfo.status === undefined) {
        throw new Error(
          `The page worked but the system complained (${hard.length}):\n  ` + hard.join('\n  ')
        );
      }
    }
  }, { auto: true }],
});

module.exports = { test, expect: base.expect, env };
