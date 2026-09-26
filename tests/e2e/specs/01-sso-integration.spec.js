// @ts-check
const { expect } = require('@playwright/test')
const { test } = require('../fixtures/auth')
const {
  configureSkillandSso,
  expandSkillandSection,
  getSesskey,
  goToCourseEditPage
} = require('../helpers/moodle-helpers')
const {
  extractSsoToken,
  extractRedirectPath,
  isValidJwtStructure,
  decodeJwtPayload
} = require('../helpers/skilland-helpers')
const { SKILL_ID, TOPIC_ID } = require('../fixtures/skilland-data')

/**
 * Test suite: SSO Integration
 *
 * sso_redirect.php signs a token and redirects the browser to SkilLand's
 * /sso-login. The SkilLand origin is stubbed, so these tests assert the redirect
 * Moodle produces, never what SkilLand does with it.
 */
test.describe('SSO Integration', () => {
  test('SSO redirect without a sesskey is rejected', async ({ authenticatedPage, skillandMock, expectConsoleError }) => {
    // Moodle answers the missing-parameter error page with HTTP 404.
    expectConsoleError(/status of 404/)
    await authenticatedPage.goto('/mod/skilland/sso_redirect.php?topicid=test')

    await expect(authenticatedPage.getByText('A required parameter (sesskey) was missing')).toBeVisible()
    expect(skillandMock.navigations()).toEqual([])
  })

  test('SSO redirect lands on SkilLand with a signed token and the topic path', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const sso = await configureSkillandSso(page)
    skillandMock.stubOrigin(sso.frontendUrl)
    const courseId = await moodleCourse.create({ skillId: SKILL_ID })

    const sesskey = await getSesskey(page)
    await page.goto(`/mod/skilland/sso_redirect.php?topicid=${TOPIC_ID}&courseid=${courseId}&sesskey=${sesskey}`)

    await expect(page.locator('#skilland-stub')).toBeVisible()
    const redirects = skillandMock.navigations()
    expect(redirects).toHaveLength(1)
    const ssoUrl = redirects[0]
    expect(new URL(ssoUrl).origin).toBe(new URL(sso.frontendUrl).origin)
    expect(new URL(ssoUrl).pathname).toBe('/sso-login')
    expect(extractRedirectPath(ssoUrl)).toBe(`/skills-studio/${SKILL_ID}/topics/${TOPIC_ID}`)

    const token = extractSsoToken(ssoUrl)
    expect(isValidJwtStructure(token)).toBe(true)
    const payload = decodeJwtPayload(/** @type {string} */ (token))
    expect(payload).toMatchObject({ source: 'moodle', orgId: sso.orgId, role: 'Expert' })
    expect(payload?.email).toBeTruthy()
    expect(payload?.nonce).toMatch(/^[0-9a-f]{32}$/)
  })

  test('"Go to Skilland" on the course form opens SkilLand in a new tab', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const sso = await configureSkillandSso(page)
    skillandMock.stubOrigin(sso.frontendUrl)
    const courseId = await moodleCourse.create()

    await goToCourseEditPage(page, courseId)
    await expandSkillandSection(page)
    const goToSkilland = page.locator('#skilland-goto-btn a')
    await expect(goToSkilland).toBeVisible()

    const [popup] = await Promise.all([
      page.context().waitForEvent('page'),
      goToSkilland.click()
    ])
    await expect(popup.locator('#skilland-stub')).toBeVisible()
    const ssoUrl = skillandMock.navigations()[0]
    expect(new URL(ssoUrl).pathname).toBe('/sso-login')
    expect(extractRedirectPath(ssoUrl)).toBe('/skills-studio')
    expect(isValidJwtStructure(extractSsoToken(ssoUrl))).toBe(true)
  })
})
