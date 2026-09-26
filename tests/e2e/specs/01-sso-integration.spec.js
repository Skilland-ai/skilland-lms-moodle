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
 * sso_redirect.php signs a token and answers with a self-submitting form that POSTs
 * it to SkilLand's /sso-login, so the token never appears in a URL (SKL-687). The
 * SkilLand origin is stubbed, so these tests assert the request Moodle produces,
 * never what SkilLand does with it.
 */
test.describe('SSO Integration', () => {
  test('SSO redirect without a sesskey is rejected', async ({ authenticatedPage, skillandMock, expectConsoleError }) => {
    // Moodle answers the missing-parameter error page with HTTP 404.
    expectConsoleError(/status of 404/)
    await authenticatedPage.goto('/mod/skilland/sso_redirect.php?topicid=test')

    await expect(authenticatedPage.getByText('A required parameter (sesskey) was missing')).toBeVisible()
    expect(skillandMock.navigations()).toEqual([])
  })

  test('SSO handoff POSTs a signed, short-lived token and the topic path to SkilLand', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const sso = await configureSkillandSso(page)
    skillandMock.stubOrigin(sso.frontendUrl)
    const courseId = await moodleCourse.create({ skillId: SKILL_ID })

    const sesskey = await getSesskey(page)
    // @ts-ignore M is Moodle's page global.
    const wwwroot = await page.evaluate(() => M.cfg.wwwroot)
    await page.goto(`/mod/skilland/sso_redirect.php?topicid=${TOPIC_ID}&courseid=${courseId}&sesskey=${sesskey}`)

    await expect(page.locator('#skilland-stub')).toBeVisible()
    const requests = skillandMock.ssoRequests()
    expect(requests).toHaveLength(1)
    const request = requests[0]
    expect(request.method).toBe('POST')
    const ssoUrl = new URL(request.url)
    expect(ssoUrl.origin).toBe(new URL(sso.frontendUrl).origin)
    expect(ssoUrl.pathname).toBe('/sso-login')
    expect(ssoUrl.search).toBe('')
    expect(request.url).not.toContain('token')
    expect(Object.keys(request.form).sort()).toEqual(['redirect', 'token'])
    expect(extractRedirectPath(request)).toBe(`/skills-studio/${SKILL_ID}/topics/${TOPIC_ID}`)

    const token = extractSsoToken(request)
    expect(isValidJwtStructure(token)).toBe(true)
    const payload = decodeJwtPayload(/** @type {string} */ (token))
    expect(payload).toMatchObject({ source: 'moodle', orgId: sso.orgId, role: 'Expert' })
    expect(payload?.email).toBeTruthy()
    expect(payload?.nonce).toMatch(/^[0-9a-f]{32}$/)
    expect(payload?.exp - payload?.iat).toBe(60)
    expect(payload?.aud).toBe(new URL(sso.frontendUrl).origin)
    expect(payload?.iss).toBe(wwwroot)
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
    const href = new URL(await goToSkilland.getAttribute('href') || '', page.url())
    expect(href.searchParams.get('courseid')).toBe(String(courseId))

    const [popup] = await Promise.all([
      page.context().waitForEvent('page'),
      goToSkilland.click()
    ])
    await expect(popup.locator('#skilland-stub')).toBeVisible()
    const request = skillandMock.ssoRequests()[0]
    expect(request.method).toBe('POST')
    expect(new URL(request.url).pathname).toBe('/sso-login')
    expect(new URL(request.url).search).toBe('')
    expect(extractRedirectPath(request)).toBe('/skills-studio')
    expect(isValidJwtStructure(extractSsoToken(request))).toBe(true)
  })
})
