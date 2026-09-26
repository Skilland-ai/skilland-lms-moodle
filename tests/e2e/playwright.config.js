// @ts-check
const { defineConfig, devices } = require('@playwright/test')

/**
 * Playwright configuration for Moodle-Skilland E2E tests
 *
 * The suite runs against a local Moodle only (MOODLE_URL). SkilLand is always
 * mocked in the browser by fixtures/skilland-mock.js; setup/global-setup.js fails
 * the run when Moodle is not reachable.
 */
module.exports = defineConfig({
  testDir: './specs',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [
    ['html', { outputFolder: '../../../test-results/e2e-report' }],
    ['list']
  ],
  outputDir: '../../../test-results/e2e-output',

  use: {
    baseURL: process.env.MOODLE_URL || 'http://localhost:8081',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'on-first-retry',
    actionTimeout: 30000,
    navigationTimeout: 60000
  },

  timeout: 120000,

  expect: {
    timeout: 30000
  },

  globalSetup: require.resolve('./setup/global-setup.js'),

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] }
    }
  ]
})
