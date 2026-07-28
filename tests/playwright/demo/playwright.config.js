/**
 * Config for the RECORDED DEMO — not the regression suite.
 *
 * It lives HERE, inside demo/, rather than beside the suite's config: Playwright
 * auto-discovers `playwright*.config.*` in the project root and tried to load both,
 * which broke collection for the suite. Nested, it is only ever loaded on purpose:
 *
 *     npm run demo
 *
 * Different goals, so different settings. The suite is fast, disposable and asserts;
 * this one is slow on purpose, keeps what it builds, and its output is a video.
 *
 *   video: 'on'        every step is recorded, pass or fail
 *   slowMo             a click that lands instantly reads as a glitch on camera
 *   1280x720           16:9, so the footage does not need reframing in the edit
 *   no teardown        the demo project SURVIVES — you just watched it get built
 *   one long timeout   a real decompose-and-build takes tens of minutes
 *
 * Video lands in test-results/demo/**\/video.webm
 */
const { defineConfig, devices } = require('@playwright/test');
const env = require('../lib/env');

module.exports = defineConfig({
  testDir: '.',
  testMatch: '*.demo.js',
  fullyParallel: false,
  workers: 1,
  retries: 0,

  // A decompose is minutes and a build is tens of minutes. One cap for the whole run,
  // generous enough that the recording is never cut off mid-build.
  timeout: 90 * 60_000,
  expect: { timeout: 30_000 },

  globalSetup: require.resolve('../lib/global-setup'),
  // Deliberately NO globalTeardown: the whole point is that the project it builds is
  // still there when the recording stops.

  reporter: [['list']],

  use: {
    baseURL: env.BASE_URL,
    storageState: env.STORAGE_STATE,
    ignoreHTTPSErrors: true,
    viewport: { width: 1280, height: 720 },
    video: { mode: 'on', size: { width: 1280, height: 720 } },
    screenshot: 'only-on-failure',
    trace: 'off',
    actionTimeout: 60_000,
    navigationTimeout: 60_000,
    launchOptions: { slowMo: Number(process.env.DEMO_SLOWMO || 350) },
  },

  projects: [{ name: 'demo', use: { ...devices['Desktop Chrome'] } }],
  outputDir: '../test-results/demo',
});
