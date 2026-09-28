// @ts-check
const { expect } = require('@playwright/test')
const { test } = require('../fixtures/auth')
const {
  configureSkillandSso,
  goToCourseEditPage,
  getSkillandDropdown,
  getSkillandCourseIdInput,
  expandSkillandSection,
  waitForSkillandDropdownLoaded,
  selectCreateNewCourse,
  getSesskey,
  notification
} = require('../helpers/moodle-helpers')
const { extractRedirectPath } = require('../helpers/skilland-helpers')

/**
 * Test suite: Error Handling
 *
 * Error scenarios and graceful degradation in the Moodle-Skilland integration.
 * SkilLand failures are simulated with skillandMock.fail / .abort / an `error` field.
 */
test.describe('Error Handling', () => {
  test.describe.configure({ mode: 'parallel' })

  test('SSO redirect with missing parameters shows an error', async ({ authenticatedPage, skillandMock, expectConsoleError }) => {
    // Moodle serves its error page with HTTP 404.
    expectConsoleError(/status of 404/)

    await authenticatedPage.goto('/mod/skilland/sso_redirect.php')

    await expect(authenticatedPage.getByText('A required parameter (sesskey) was missing')).toBeVisible()
    expect(skillandMock.navigations()).toEqual([])
  })

  test('SSO redirect with an invalid sesskey is rejected', async ({ authenticatedPage, skillandMock, expectConsoleError }) => {
    expectConsoleError(/status of 404/)

    await authenticatedPage.goto('/mod/skilland/sso_redirect.php?topicid=test&sesskey=invalid_key')

    await expect(authenticatedPage.locator('#region-main')).toContainText(/sesskey|session/i)
    expect(skillandMock.navigations()).toEqual([])
  })

  test('Activity view for a missing course module shows an error', async ({ authenticatedPage, expectConsoleError }) => {
    expectConsoleError(/status of 404/)

    await authenticatedPage.goto('/mod/skilland/view.php?id=999999')

    await expect(authenticatedPage.locator('#region-main')).toContainText(/Can't find data record|Invalid course module ID/i)
  })
})

/**
 * Test suite: SkilLand failures on the course form
 */
test.describe('Network Error Handling', () => {
  test.describe.configure({ mode: 'parallel' })

  test('Course list request failure falls back to the plain text input', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse,
    expectConsoleError
  }) => {
    const courseId = await moodleCourse.create()
    skillandMock.abort('mod_skilland_fetch_courses_ajax')
    expectConsoleError(/Skilland: AJAX error/)

    await goToCourseEditPage(authenticatedPage, courseId)
    await expandSkillandSection(authenticatedPage)

    await expect(notification(authenticatedPage, 'error')
      .filter({ hasText: 'Failed to fetch courses from Skilland. Please check your API configuration.' }))
      .toBeVisible()
    await expect(getSkillandCourseIdInput(authenticatedPage)).toBeVisible()
    await expect(getSkillandCourseIdInput(authenticatedPage)).toBeEnabled()
    await expect(getSkillandDropdown(authenticatedPage)).toHaveCount(0)
    await expect(authenticatedPage.locator('[name="customfield_skilland_course_id"]')).toHaveCount(1)
  })

  test('Course list error from SkilLand is shown and falls back to the text input', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const courseId = await moodleCourse.create()
    skillandMock.on('mod_skilland_fetch_courses_ajax', { courses: [], error: 'API key rejected' })

    await goToCourseEditPage(authenticatedPage, courseId)
    await expandSkillandSection(authenticatedPage)

    await expect(notification(authenticatedPage, 'error')
      .filter({ hasText: 'Failed to fetch courses from Skilland: API key rejected' }))
      .toBeVisible()
    await expect(getSkillandCourseIdInput(authenticatedPage)).toBeVisible()
  })

  test('Create course recovers from a network failure', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const courseId = await moodleCourse.create()
    skillandMock.abort('mod_skilland_create_course_ajax')

    await goToCourseEditPage(authenticatedPage, courseId)
    await waitForSkillandDropdownLoaded(authenticatedPage)
    await selectCreateNewCourse(authenticatedPage)

    await expect(notification(authenticatedPage, 'error').filter({ hasText: 'Failed to create course in Skilland.' }))
      .toBeVisible()
    const dropdown = getSkillandDropdown(authenticatedPage)
    await expect(dropdown).toBeEnabled()
    await expect(dropdown).toHaveValue('')
    expect(skillandMock.calls('mod_skilland_create_course_ajax')).toHaveLength(1)
  })

  test('Create course recovers from a web service exception', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const courseId = await moodleCourse.create()
    skillandMock.fail('mod_skilland_create_course_ajax', 'SkilLand GraphQL error')

    await goToCourseEditPage(authenticatedPage, courseId)
    await waitForSkillandDropdownLoaded(authenticatedPage)
    await selectCreateNewCourse(authenticatedPage)

    await expect(notification(authenticatedPage, 'error').filter({ hasText: 'Failed to create course in Skilland.' }))
      .toBeVisible()
    await expect(getSkillandDropdown(authenticatedPage)).toBeEnabled()
    await expect(getSkillandDropdown(authenticatedPage)).toHaveValue('')
  })
})

/**
 * Test suite: Input Validation
 */
test.describe('Input Validation', () => {
  test.describe.configure({ mode: 'parallel' })

  test('SSO redirect strips a script from the topic id', async ({ authenticatedPage, skillandMock, moodleCourse }) => {
    const page = authenticatedPage
    const sso = await configureSkillandSso(page)
    skillandMock.stubOrigin(sso.frontendUrl)
    const courseId = await moodleCourse.create()
    const maliciousInput = '<script>alert("xss")</script>'

    /** @type {string[]} */
    const dialogs = []
    page.on('dialog', async dialog => {
      dialogs.push(dialog.message())
      await dialog.dismiss()
    })

    const sesskey = await getSesskey(page)
    await page.goto(`/mod/skilland/sso_redirect.php?topicid=${encodeURIComponent(maliciousInput)}&courseid=${courseId}&sesskey=${sesskey}`)
    await expect(page.locator('#skilland-stub')).toBeVisible()

    const redirect = extractRedirectPath(skillandMock.ssoRequests()[0]) || ''
    // PARAM_TEXT strips the tags; what is left is URL-encoded into the path, posted as a form field.
    expect(decodeURIComponent(redirect)).toBe('/skills-studio/topics/alert("xss")')
    expect(dialogs).toEqual([])
  })

  test('SSO redirect refuses a non-numeric course id', async ({ authenticatedPage, skillandMock, expectConsoleError }) => {
    expectConsoleError(/status of 404/)
    const page = authenticatedPage
    const sso = await configureSkillandSso(page)
    skillandMock.stubOrigin(sso.frontendUrl)
    const maliciousInput = "'; DROP TABLE users;--"

    const sesskey = await getSesskey(page)
    await page.goto(`/mod/skilland/sso_redirect.php?topicid=test&courseid=${encodeURIComponent(maliciousInput)}&sesskey=${sesskey}`)

    // PARAM_INT turns it into course 0, which does not exist: no token is minted (SKL-645).
    await expect(page.locator('#region-main')).toContainText(/Can't find data record|Invalid course/i)
    await expect(page.locator('#skilland-stub')).toHaveCount(0)
    expect(skillandMock.ssoRequests()).toEqual([])
  })

  test('SSO redirect without a course id is refused', async ({ authenticatedPage, skillandMock, expectConsoleError }) => {
    expectConsoleError(/status of 404/)
    const page = authenticatedPage
    const sso = await configureSkillandSso(page)
    skillandMock.stubOrigin(sso.frontendUrl)

    const sesskey = await getSesskey(page)
    await page.goto(`/mod/skilland/sso_redirect.php?topicid=test&sesskey=${sesskey}`)

    // SKL-645: without a course there is no context to check accessstudio in, so no token.
    await expect(page.getByText('A required parameter (courseid) was missing')).toBeVisible()
    expect(skillandMock.ssoRequests()).toEqual([])
  })
})
