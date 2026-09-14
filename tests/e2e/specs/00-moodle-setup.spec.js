// @ts-check
const { expect } = require('@playwright/test')
const { test, testData } = require('../fixtures/auth')
const {
  goToEdukmiSettings,
  isEdukmiPluginInstalled,
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
    await page.waitForLoadState('domcontentloaded')

    const title = await page.title()
    expect(title).toBeTruthy()

    const loginLink = page.locator('a[href*="login"]')
    const isLoginPage = await loginLink.count() > 0 || page.url().includes('login')
    expect(isLoginPage).toBeTruthy()
  })

  test('Admin can login to Moodle', async ({ page }) => {
    await page.goto('/login/index.php')
    await page.waitForLoadState('networkidle')

    // Wait for login form to be fully loaded
    await page.waitForSelector('#username', { state: 'visible' })
    await page.waitForSelector('#login input[name="logintoken"]', { state: 'attached' })

    await page.locator('#username').fill(testData.moodle.admin.username)
    await page.locator('#password').fill(testData.moodle.admin.password)
    await page.locator('#loginbtn').click()

    await page.waitForLoadState('networkidle')

    // Check for login errors
    const errorMessage = page.locator('.loginerrors, .alert-danger, #loginerrormessage')
    if (await errorMessage.count() > 0) {
      const errorText = await errorMessage.textContent()
      console.log(`Login failed with error: ${errorText}`)
      console.log(`URL after login attempt: ${page.url()}`)
    }

    const loggedIn = await isLoggedIn(page)
    expect(loggedIn).toBeTruthy()
  })

  test('Skilland plugin is installed', async ({ authenticatedPage }) => {
    const installed = await isEdukmiPluginInstalled(authenticatedPage)
    expect(installed).toBeTruthy()
  })

  test('Skilland plugin settings page is accessible', async ({ authenticatedPage }) => {
    await goToEdukmiSettings(authenticatedPage)

    const pageContent = await authenticatedPage.content()
    const hasSettings =
      pageContent.includes('skilland') ||
      pageContent.includes('Skilland') ||
      pageContent.includes('Organization')

    expect(hasSettings).toBeTruthy()
  })

  test('Skilland activity type is available', async ({ authenticatedPage }) => {
    await authenticatedPage.goto('/course/view.php?id=1')
    await authenticatedPage.waitForLoadState('domcontentloaded')

    const editModeToggle = authenticatedPage.locator('[data-action="setmode"]')
    if (await editModeToggle.isVisible()) {
      await editModeToggle.click()
      await authenticatedPage.waitForLoadState('networkidle')
    }

    await authenticatedPage.goto('/course/modedit.php?add=skilland&course=1&section=0')
    await authenticatedPage.waitForLoadState('domcontentloaded')

    const pageContent = await authenticatedPage.content()
    const hasEdukmiForm =
      pageContent.includes('skilland') ||
      pageContent.includes('Skilland') ||
      authenticatedPage.url().includes('skilland')

    expect(hasEdukmiForm).toBeTruthy()
  })
})
