/**
 * The suite runs against a LIVE deployment, as a real admin, so the configuration is
 * deliberately conservative:
 *
 *   workers: 1        — one browser at a time. The sweep touches shared state (the
 *                       selected project is account-wide), and parallel workers would
 *                       race each other through it.
 *   chromium only     — this suite checks links, labels, permissions and logs. None of
 *                       those vary by rendering engine, and five engines would put five
 *                       times the load on a production site for no extra signal.
 *   retries: 0        — a flaky pass is worse than a failure here. If something is
 *                       intermittent, that IS the finding.
 */
const { defineConfig, devices } = require('@playwright/test');
const env = require('./lib/env');

module.exports = defineConfig({
  testDir: './specs',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,
  timeout: 90_000,
  expect: { timeout: 10_000 },

  globalSetup: require.resolve('./lib/global-setup'),
  globalTeardown: require.resolve('./lib/global-teardown'),

  reporter: [
    ['list'],
    ['html', { outputFolder: 'test-results/html-report', open: 'never' }],
    ['json', { outputFile: 'test-results/results.json' }],
  ],

  use: {
    baseURL: env.BASE_URL,
    storageState: env.STORAGE_STATE,
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },

  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],

  outputDir: 'test-results/',
});
