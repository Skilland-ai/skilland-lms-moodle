// @ts-check
const { expect } = require('@playwright/test')
const { test, testData } = require('../fixtures/auth')
const {
  goToSkillandSettings,
  isSkillandPluginInstalled,
  isLoggedIn
} = require('../helpers/moodle-helpers')

/**
 * Test suite: Moodle Setup Verification
 *
 * Verifies that Moodle is properly configured and the Skilland plugin is installed.
 * These tests should run first to ensure the environment is ready.
 */
test.describe('Moodle Setup Verification', () => {
  test.describe.configure({ mode: 'serial' })

  test('Moodle is accessible', async ({ page }) => {
    await page.goto('/')

    const title = await page.title()
    expect(title).toBeTruthy()

    const loginLink = page.locator('a[href*="login"]')
    const isLoginPage = await loginLink.count() > 0 || page.url().includes('login')
    expect(isLoginPage).toBeTruthy()
  })

  test('Admin can login to Moodle', async ({ page }) => {
    await page.goto('/login/index.php')
    await expect(page.locator('#login input[name="logintoken"]')).toBeAttached()

    await page.locator('#username').fill(testData.moodle.admin.username)
    await page.locator('#password').fill(testData.moodle.admin.password)
    await page.locator('#loginbtn').click()
    await expect(page.locator('#user-menu-toggle')).toBeVisible()

    const loggedIn = await isLoggedIn(page)
    expect(loggedIn).toBeTruthy()
  })

  test('Skilland plugin is installed', async ({ authenticatedPage }) => {
    const installed = await isSkillandPluginInstalled(authenticatedPage)
    expect(installed).toBeTruthy()
  })

  test('Skilland plugin settings page is accessible', async ({ authenticatedPage }) => {
    await goToSkillandSettings(authenticatedPage)
    await expect(authenticatedPage.locator('#id_s_mod_skilland_orgid')).toBeAttached()

    const pageContent = await authenticatedPage.content()
    const hasSettings =
      pageContent.includes('skilland') ||
      pageContent.includes('Skilland') ||
      pageContent.includes('Organization')

    expect(hasSettings).toBeTruthy()
  })

  test('Skilland activity type is available', async ({ authenticatedPage }) => {
    await authenticatedPage.goto('/course/modedit.php?add=skilland&course=1&section=0')

    const pageContent = await authenticatedPage.content()
    const hasSkillandForm =
      pageContent.includes('skilland') ||
      pageContent.includes('Skilland') ||
      authenticatedPage.url().includes('skilland')

    expect(hasSkillandForm).toBeTruthy()
  })
})
