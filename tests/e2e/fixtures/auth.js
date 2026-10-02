// @ts-check
const { test: base, expect } = require('@playwright/test')
const testData = require('./test-data.json')
const { installSkillandMock } = require('./skilland-mock')
const { createCourse, deleteCourse, enrolUser, linkCourseToSkill } = require('../helpers/moodle-helpers')

const MOODLE_URL = process.env.MOODLE_URL || testData.moodle.baseUrl

/**
 * Test fixtures for the Moodle side of the suite.
 *
 * `skillandMock` is set up for every test (auto fixture): Skilland is always mocked
 * at the Moodle AJAX boundary, and the test fails on unmocked Skilland calls,
 * console errors or uncaught page errors (see fixtures/skilland-mock.js).
 */
const test = base.extend({
  skillandMock: [async ({ context }, use) => {
    const mock = await installSkillandMock(context)
    await use(mock)
    await context.unrouteAll({ behavior: 'ignoreErrors' })
    mock.assertClean()
  }, { auto: true }],

  /**
   * Allow browser errors matching `pattern` in the current test.
   */
  expectConsoleError: async ({ skillandMock }, use) => {
    await use(/** @param {RegExp} pattern */ pattern => { skillandMock.expectConsoleError(pattern) })
  },

  /**
   * Page logged in as the site admin.
   */
  authenticatedPage: async ({ page, skillandMock: _mock }, use) => {
    await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)
    await use(page)
  },

  /**
   * Page logged in as teacher1 (editing teacher at system level).
   */
  teacherPage: async ({ page, skillandMock: _mock }, use) => {
    await loginToMoodle(page, testData.moodle.teacher.username, testData.moodle.teacher.password)
    await use(page)
  },

  /**
   * Page logged in as student1.
   */
  studentPage: async ({ page, skillandMock: _mock }, use) => {
    await loginToMoodle(page, testData.moodle.student.username, testData.moodle.student.password)
    await use(page)
  },

  /**
   * Creates Moodle courses for the current test from a separate admin session and
   * deletes them when the test ends. That session has its own Skilland mock, so
   * creating a course never reaches a Skilland backend either.
   */
  moodleCourse: async ({ browser }, use) => {
    const context = await browser.newContext()
    const adminMock = await installSkillandMock(context)
    const page = await context.newPage()
    /** @type {string[]} */
    const created = []
    let loggedIn = false

    await use({
      /**
       * @param {{ fullname?: string, shortname?: string, skillId?: string, enrolTeacher?: boolean }} [options]
       *   `skillId` links the course to that Skilland skill (it must be in the
       *   default mod_skilland_fetch_courses_ajax answer); `enrolTeacher` enrols
       *   the test teacher as an editing teacher.
       * @returns {Promise<string>} The Moodle course id
       */
      async create(options = {}) {
        if (!loggedIn) {
          await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)
          loggedIn = true
        }
        const suffix = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`
        const courseId = await createCourse(page, {
          fullname: options.fullname || `E2E course ${suffix}`,
          shortname: options.shortname || `e2e-${suffix}`
        })
        created.push(courseId)
        if (options.skillId) {
          await linkCourseToSkill(page, courseId, options.skillId)
        }
        if (options.enrolTeacher) {
          const { firstname, lastname } = testData.moodle.teacher
          await enrolUser(page, courseId, `${firstname} ${lastname}`, 'Teacher')
        }
        return courseId
      }
    })

    try {
      for (const courseId of created) {
        await deleteCourse(page, courseId)
      }
    } finally {
      await context.unrouteAll({ behavior: 'ignoreErrors' })
      await context.close()
      adminMock.assertClean()
    }
  },

  /**
   * Test data available in tests
   */
  testData: async ({}, use) => {
    await use(testData)
  }
})

/**
 * Log in to Moodle and assert that the session is really open.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} username
 * @param {string} password
 * @param {number} [attempts]
 */
async function loginToMoodle(page, username, password, attempts = 3) {
  const loginForm = page.locator('#login')
  const loggedInMarker = page.locator('#user-menu-toggle')
  const loginErrors = page.locator('#loginerrormessage, .loginerrors')

  for (let attempt = 1; attempt <= attempts; attempt++) {
    await page.goto(`${MOODLE_URL}/login/index.php`)
    await expect(loginForm).toBeVisible()
    await expect(loginForm.locator('input[name="logintoken"]')).toBeAttached()

    await page.locator('#username').fill(username)
    await page.locator('#password').fill(password)
    await page.locator('#loginbtn').click()
    await expect(loggedInMarker.or(loginErrors)).toBeVisible().catch(() => {})

    const onAdminPage = new URL(page.url()).pathname.includes('/admin/')
    if (onAdminPage || await loggedInMarker.isVisible()) {
      return
    }
  }

  throw new Error(`Moodle login failed for ${username} after ${attempts} attempts (last URL: ${page.url()})`)
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
  expect,
  loginToMoodle,
  logoutFromMoodle,
  testData
}
