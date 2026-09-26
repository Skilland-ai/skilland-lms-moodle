// @ts-check
/* global window */
const { expect } = require('@playwright/test')
const { test } = require('../fixtures/auth')
const { goToAddSkillandActivity, waitForTopicsLoaded } = require('../helpers/moodle-helpers')
const { SKILL_ID } = require('../fixtures/skilland-data')

/**
 * Test suite: XSS escaping in the teacher UI (SKL-674)
 *
 * SkilLand API data (course name, topic description, error messages) must be
 * rendered as text in the activity form, never parsed as HTML. The SkilLand mock
 * delivers the payloads to the browser unchanged.
 */
const TOPIC_ID = 'xss-topic-1'
const MALICIOUS_COURSE_NAME = '<img src=x onerror="window.__xss=1">Evil course'
// Descriptions are purified server-side by clean_text(), which the AJAX mock bypasses;
// that sink is covered by tests/phpunit/xss_sinks_test.php.
const MALICIOUS_ERROR = '<b>boom</b>'

/**
 * Collect dialogs, which would mean an injected script ran.
 * @param {import('@playwright/test').Page} page
 * @returns {string[]}
 */
function recordDialogs(page) {
  /** @type {string[]} */
  const dialogs = []
  page.on('dialog', async dialog => {
    dialogs.push(dialog.message())
    await dialog.dismiss()
  })
  return dialogs
}

test.describe('XSS escaping of SkilLand API data', () => {
  test.describe.configure({ mode: 'parallel' })

  test('course name renders as text', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const courseId = await moodleCourse.create({ skillId: SKILL_ID })
    const dialogs = recordDialogs(page)
    skillandMock
      .on('mod_skilland_fetch_topics_ajax', {
        course: { id: SKILL_ID, name: MALICIOUS_COURSE_NAME, code: 'XSS' },
        topics: [{ id: TOPIC_ID, name: 'Topic one', code: 'T1', description: '<p>Intro</p>' }]
      })
      .on('mod_skilland_fetch_lessons_ajax', { lessons: [] })

    await goToAddSkillandActivity(page, courseId)

    await expect(page.locator('.skilland-course-name')).toHaveText(MALICIOUS_COURSE_NAME)
    await expect(page.locator('#fitem_id_skilland_course_id_display img')).toHaveCount(0)

    await waitForTopicsLoaded(page, TOPIC_ID)
    await page.locator('select#id_skilland_topicid').selectOption(TOPIC_ID)
    await expect(page.locator('#id_name')).toHaveValue('T1 - Topic one')

    const xssFired = await page.evaluate(() => /** @type {any} */ (window).__xss)
    expect(xssFired).toBeUndefined()
    expect(dialogs).toEqual([])
  })

  test('lessons error message is shown literally', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const courseId = await moodleCourse.create({ skillId: SKILL_ID })
    const dialogs = recordDialogs(page)
    skillandMock
      .on('mod_skilland_fetch_topics_ajax', {
        course: { id: SKILL_ID, name: 'Safe course', code: 'SAFE' },
        topics: [{ id: TOPIC_ID, name: 'Topic one', code: 'T1', description: '<p>Intro</p>' }]
      })
      .on('mod_skilland_fetch_lessons_ajax', { lessons: [], error: MALICIOUS_ERROR })

    await goToAddSkillandActivity(page, courseId)
    await waitForTopicsLoaded(page, TOPIC_ID)
    await page.locator('select#id_skilland_topicid').selectOption(TOPIC_ID)

    await expect(page.locator('#id_lessons_container .text-danger')).toHaveText(MALICIOUS_ERROR)
    await expect(page.locator('#id_lessons_container b')).toHaveCount(0)
    expect(dialogs).toEqual([])
  })
})
