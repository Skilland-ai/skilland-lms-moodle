// @ts-check
/* global window */
const { expect } = require('@playwright/test')
const { test, testData, loginToMoodle } = require('../fixtures/auth')
const {
  createCourse,
  deleteCourse,
  goToCourseEditPage
} = require('../helpers/moodle-helpers')

/**
 * Test suite: XSS escaping in the teacher UI (SKL-674)
 *
 * SkilLand API data (course name, topic description, error messages) must be
 * rendered as text in the activity form, never parsed as HTML. The Moodle AJAX
 * endpoint is mocked so the payloads reach the browser unchanged.
 */
const SKILL_ID = 'xss-test-skill'
const TOPIC_ID = 'xss-topic-1'
const MALICIOUS_COURSE_NAME = '<img src=x onerror="window.__xss=1">Evil course'
// The AJAX mock bypasses the server, where clean_text() purifies descriptions
// (covered by tests/phpunit/xss_sinks_test.php), so the mock returns the already
// purified HTML: a raw onerror payload here would reach Atto's setHTML unfiltered.
const PURIFIED_DESCRIPTION = '<p>Intro <strong>bold</strong></p><img src="x">'
const MALICIOUS_ERROR = '<b>boom</b>'

/**
 * Answer mod_skilland_* AJAX calls with canned payloads, pass everything else through.
 * @param {import('@playwright/test').Page} page
 * @param {Record<string, object>} responses methodname => data
 */
async function mockSkillandAjax(page, responses) {
  await page.route('**/lib/ajax/service.php**', route => {
    const postData = route.request().postData() || ''
    const method = Object.keys(responses).find(name => postData.includes(name))
    if (!method) {
      route.continue()
      return
    }
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify([{ error: false, data: responses[method] }])
    })
  })
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} courseId
 */
async function goToAddActivity(page, courseId) {
  await page.goto(`/course/modedit.php?add=skilland&type=&course=${courseId}&section=0&return=0&sr=0`)
  await page.waitForLoadState('domcontentloaded')
}

test.describe('XSS escaping of SkilLand API data', () => {
  test.describe.configure({ mode: 'serial' })

  let courseId = ''

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage()
    await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)

    try {
      courseId = await createCourse(page, {
        fullname: 'XSS Escaping Test Course',
        shortname: `xss-test-${Date.now()}`
      })

      // Link the course to a SkilLand course id. Failing the course fetch keeps
      // the plain text input visible so it can be filled directly.
      await page.route('**/lib/ajax/service.php**', route => {
        const postData = route.request().postData() || ''
        if (postData.includes('mod_skilland_fetch_courses_ajax')) {
          route.abort('failed')
        } else {
          route.continue()
        }
      })
      await goToCourseEditPage(page, courseId)
      const courseIdInput = page.locator('input#id_customfield_skilland_course_id')
      await courseIdInput.waitFor({ state: 'visible', timeout: 10000 })
      await courseIdInput.fill(SKILL_ID)
      await page.locator('#id_saveanddisplay, #id_saveandreturn').first().click()
      await page.waitForLoadState('networkidle')
    } catch (error) {
      console.log('XSS test course setup failed:', error)
      courseId = ''
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

  test('course name renders as text and the purified description as HTML', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    const page = authenticatedPage
    /** @type {string[]} */
    const dialogs = []
    page.on('dialog', async dialog => {
      dialogs.push(dialog.message())
      await dialog.dismiss()
    })

    await mockSkillandAjax(page, {
      mod_skilland_fetch_topics_ajax: {
        course: { id: SKILL_ID, name: MALICIOUS_COURSE_NAME, code: 'XSS' },
        topics: [{ id: TOPIC_ID, name: 'Topic one', code: 'T1', description: PURIFIED_DESCRIPTION }],
        error: null
      },
      mod_skilland_fetch_lessons_ajax: { lessons: [], error: null }
    })

    await goToAddActivity(page, courseId)

    const courseName = page.locator('.skilland-course-name')
    await expect(courseName).toHaveText(MALICIOUS_COURSE_NAME, { timeout: 10000 })
    await expect(page.locator('#fitem_id_skilland_course_id_display img')).toHaveCount(0)

    const topicSelect = page.locator('select#id_skilland_topicid')
    await expect(topicSelect.locator(`option[value="${TOPIC_ID}"]`)).toHaveCount(1)
    await topicSelect.selectOption(TOPIC_ID)
    await page.waitForTimeout(1000)

    // Purified description HTML is still meant to render in the editor.
    const editorHtml = await page.evaluate(() => {
      const w = /** @type {any} */ (window)
      if (w.tinyMCE && w.tinyMCE.get('id_intro')) {
        return w.tinyMCE.get('id_intro').getContent()
      }
      const atto = document.getElementById('id_introeditable')
      return atto ? atto.innerHTML : ''
    })
    expect(editorHtml).toMatch(/<strong>bold<\/strong>/)
    expect(editorHtml).not.toMatch(/onerror/i)

    const xssFired = await page.evaluate(() => /** @type {any} */ (window).__xss)
    expect(xssFired).toBeUndefined()
    expect(dialogs).toEqual([])
  })

  test('lessons error message is shown literally', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    const page = authenticatedPage
    /** @type {string[]} */
    const dialogs = []
    page.on('dialog', async dialog => {
      dialogs.push(dialog.message())
      await dialog.dismiss()
    })

    await mockSkillandAjax(page, {
      mod_skilland_fetch_topics_ajax: {
        course: { id: SKILL_ID, name: 'Safe course', code: 'SAFE' },
        topics: [{ id: TOPIC_ID, name: 'Topic one', code: 'T1', description: '<p>Intro</p>' }],
        error: null
      },
      mod_skilland_fetch_lessons_ajax: { lessons: [], error: MALICIOUS_ERROR }
    })

    await goToAddActivity(page, courseId)

    const topicSelect = page.locator('select#id_skilland_topicid')
    await expect(topicSelect.locator(`option[value="${TOPIC_ID}"]`)).toHaveCount(1, { timeout: 10000 })
    await topicSelect.selectOption(TOPIC_ID)

    const errorSpan = page.locator('#id_lessons_container .text-danger')
    await expect(errorSpan).toHaveText(MALICIOUS_ERROR, { timeout: 10000 })
    await expect(page.locator('#id_lessons_container b')).toHaveCount(0)
    expect(dialogs).toEqual([])
  })
})
