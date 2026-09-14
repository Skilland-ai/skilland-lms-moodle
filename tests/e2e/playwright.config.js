// @ts-check
const { defineConfig, devices } = require('@playwright/test')

/**
 * Playwright configuration for Moodle-Skilland E2E tests
 *
 * These tests verify the integration between Moodle LMS and Skilland platform,
 * including SSO authentication, plugin functionality, and SCORM provisioning.
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
  ],

  webServer: {
    command: 'echo "Using existing Docker services"',
    url: process.env.MOODLE_URL || 'http://localhost:8081',
    reuseExistingServer: true,
    timeout: 120000
  }
})
