// @ts-check
const { test: base } = require('@playwright/test')
const testData = require('./test-data.json')

/**
 * Custom test fixture that provides authenticated Moodle session
 */
const test = base.extend({
  /**
   * Authenticated page - logs in before each test
   */
  authenticatedPage: async ({ page }, use) => {
    await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)
    await use(page)
  },

  /**
   * Teacher page - logs in as teacher (editing teacher role)
   */
  teacherPage: async ({ page }, use) => {
    await loginToMoodle(page, testData.moodle.teacher.username, testData.moodle.teacher.password)
    await use(page)
  },

  /**
   * Student page - logs in as student
   */
  studentPage: async ({ page }, use) => {
    await loginToMoodle(page, testData.moodle.student.username, testData.moodle.student.password)
    await use(page)
  },

  /**
   * Test data available in tests
   */
  testData: async ({}, use) => {
    await use(testData)
  }
})

/**
 * Login to Moodle with credentials
 * @param {import('@playwright/test').Page} page
 * @param {string} username
 * @param {string} password
 * @param {number} retries - Number of retry attempts (default: 3)
 */
async function loginToMoodle(page, username, password, retries = 3) {
  const baseUrl = process.env.MOODLE_URL || testData.moodle.baseUrl

  for (let attempt = 1; attempt <= retries; attempt++) {
    await page.goto(`${baseUrl}/login/index.php`)
    await page.waitForLoadState('networkidle')

    const usernameInput = page.locator('#username')
    const passwordInput = page.locator('#password')

    if (await usernameInput.isVisible()) {
      // Wait for login token to be present (CSRF protection)
      await page.waitForSelector('#login input[name="logintoken"]', { state: 'attached' })

      await usernameInput.fill(username)
      await passwordInput.fill(password)
      await page.locator('#loginbtn').click()
      await page.waitForLoadState('networkidle')

      // Check for successful login - multiple indicators
      const currentUrl = page.url()

      // Admin redirected to admin page means login succeeded
      if (currentUrl.includes('/admin/')) {
        return
      }

      // Check for user menu (standard indicator)
      const userMenu = page.locator('.usermenu, #user-menu-toggle, .userbutton')
      if (await userMenu.count() > 0) {
        return
      }

      // Check for logout link as fallback
      const logoutLink = page.locator('a[href*="logout"]')
      if (await logoutLink.count() > 0) {
        return
      }

      // Check for error message
      const errorMessage = page.locator('.loginerrors, .alert-danger, #loginerrormessage')
      if (await errorMessage.count() > 0 && attempt < retries) {
        console.log(`Login attempt ${attempt} failed, retrying...`)
        await page.waitForTimeout(2000)
        continue
      }
    }
  }

  console.warn(`Login may have failed after ${retries} attempts`)
}

/**
 * Logout from Moodle
 * @param {import('@playwright/test').Page} page
 */
async function logoutFromMoodle(page) {
  await page.goto('/login/logout.php')
  const logoutButton = page.locator('button[type="submit"]')
  if (await logoutButton.isVisible()) {
    await logoutButton.click()
  }
}

module.exports = {
  test,
  loginToMoodle,
  logoutFromMoodle,
  testData
}
