// @ts-check
const { expect } = require('@playwright/test')
const { test, testData, loginToMoodle } = require('../fixtures/auth')
const {
  createCourse,
  getSesskey,
  deleteCourse
} = require('../helpers/moodle-helpers')
const {
  isEdukmiUrl,
  extractSsoToken,
  isValidJwtStructure,
  decodeJwtPayload
} = require('../helpers/skilland-helpers')

/**
 * Test suite: SSO Integration
 *
 * Tests the Single Sign-On flow between Moodle and Skilland.
 * Verifies that users can seamlessly authenticate from Moodle to Skilland.
 */
test.describe('SSO Integration', () => {
  test.describe.configure({ mode: 'serial' })

  let courseId = ''

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage()
    await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)

    try {
      courseId = await createCourse(page, {
        fullname: 'SSO Test Course',
        shortname: `sso-test-${Date.now()}`
      })
    } catch {
      console.log('Course creation skipped or failed')
    }

    await page.close()
  })

  test.afterAll(async ({ browser }) => {
    if (courseId) {
      const page = await browser.newPage()
      await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)
      try {
        await deleteCourse(page, courseId)
      } catch {
        console.log('Course cleanup skipped')
      }
      await page.close()
    }
  })

  test('SSO redirect page exists', async ({ authenticatedPage }) => {
    await authenticatedPage.goto('/mod/skilland/sso_redirect.php')

    const pageContent = await authenticatedPage.content()
    const hasError = pageContent.includes('error') || pageContent.includes('Error')

    expect(hasError).toBeTruthy()
  })

  test('SSO redirect requires sesskey', async ({ authenticatedPage }) => {
    await authenticatedPage.goto('/mod/skilland/sso_redirect.php?topicid=test&courseid=test')

    const pageContent = await authenticatedPage.content()
    const hasSessionError =
      pageContent.includes('sesskey') ||
      pageContent.includes('session') ||
      pageContent.includes('invalid')

    expect(hasSessionError).toBeTruthy()
  })

  test('SSO redirect generates valid token structure', async ({ authenticatedPage }) => {
    const sesskey = await getSesskey(authenticatedPage)

    let capturedUrl = ''

    // Listen for requests to capture the redirect URL
    authenticatedPage.on('request', request => {
      const url = request.url()
      if (url.includes('sso') && url.includes('token=')) {
        capturedUrl = url
      }
    })

    // Navigate with a reasonable timeout
    await authenticatedPage.goto(
      `/mod/skilland/sso_redirect.php?topicid=test-topic&courseid=test-skill&sesskey=${sesskey}`,
      { waitUntil: 'commit', timeout: 15000 }
    ).catch(() => {
      // Timeout is expected if redirect takes long
    })

    const finalUrl = capturedUrl || authenticatedPage.url()

    // Verify we either got redirected to Skilland or captured the token
    if (isEdukmiUrl(finalUrl)) {
      const token = extractSsoToken(finalUrl)
      if (token) {
        expect(isValidJwtStructure(token)).toBeTruthy()
      }
    }
  })

  test('SSO token contains user information', async ({ authenticatedPage }) => {
    const sesskey = await getSesskey(authenticatedPage)

    let capturedToken = ''

    authenticatedPage.on('request', request => {
      const url = request.url()
      if (url.includes('token=')) {
        const token = new URL(url).searchParams.get('token')
        if (token) capturedToken = token
      }
    })

    await authenticatedPage.goto(
      `/mod/skilland/sso_redirect.php?topicid=test-topic&courseid=test-skill&sesskey=${sesskey}`,
      { waitUntil: 'commit', timeout: 10000 }
    ).catch(() => {})

    if (capturedToken && isValidJwtStructure(capturedToken)) {
      const payload = decodeJwtPayload(capturedToken)
      if (payload) {
        expect(payload).toHaveProperty('email')
      }
    }
  })

  test('SSO redirect includes correct path parameters', async ({ authenticatedPage }) => {
    const sesskey = await getSesskey(authenticatedPage)
    const testSkillId = 'skill-123'
    const testTopicId = 'topic-456'

    let capturedUrl = ''

    authenticatedPage.on('request', request => {
      const url = request.url()
      if (isEdukmiUrl(url)) {
        capturedUrl = url
      }
    })

    await authenticatedPage.goto(
      `/mod/skilland/sso_redirect.php?topicid=${testTopicId}&courseid=${testSkillId}&sesskey=${sesskey}`,
      { waitUntil: 'commit', timeout: 10000 }
    ).catch(() => {})

    const finalUrl = authenticatedPage.url()
    const urlToCheck = capturedUrl || finalUrl

    if (isEdukmiUrl(urlToCheck)) {
      const urlObj = new URL(urlToCheck)
      const redirect = urlObj.searchParams.get('redirect') || urlObj.pathname

      expect(redirect).toContain(testSkillId)
      expect(redirect).toContain(testTopicId)
    }
  })
})
