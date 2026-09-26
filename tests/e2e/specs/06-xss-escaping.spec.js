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
// Descriptions are also purified server-side by clean_text() (tests/phpunit/xss_sinks_test.php),
// which the AJAX mock bypasses, so the form must reduce them to escaped text itself (SKL-759).
const MALICIOUS_DESCRIPTION = '<p>Intro <strong>bold</strong> R&amp;D</p><img src=x onerror="window.__xss=2">'
const DESCRIPTION_TEXT = 'Intro bold R&D'
const MALICIOUS_ERROR = '<b>boom</b>'

/**
 * HTML of the activity intro editor: TinyMCE, Atto or the bare textarea.
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
async function readIntroHtml(page) {
  return page.evaluate(() => {
    const w = /** @type {any} */ (window)
    const tiny = w.tinymce || w.tinyMCE
    const editor = tiny && tiny.get ? tiny.get('id_introeditor') : null
    if (editor) {
      return editor.getContent()
    }
    const atto = document.getElementById('id_introeditoreditable')
    if (atto) {
      return atto.innerHTML
    }
    const textarea = /** @type {HTMLTextAreaElement | null} */ (document.getElementById('id_introeditor'))
    return textarea ? textarea.value : ''
  })
}

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

  test('topic description is copied into the intro editor as escaped text', async ({
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
        topics: [{ id: TOPIC_ID, name: 'Topic one', code: 'T1', description: MALICIOUS_DESCRIPTION }]
      })
      .on('mod_skilland_fetch_lessons_ajax', { lessons: [] })

    await goToAddSkillandActivity(page, courseId)
    await waitForTopicsLoaded(page, TOPIC_ID)
    await page.locator('select#id_skilland_topicid').selectOption(TOPIC_ID)

    await expect(page.locator('#id_introeditor')).toHaveValue('<p>Intro bold R&amp;D</p>')
    await expect.poll(() => readIntroHtml(page)).toContain('Intro bold R&amp;D')
    const introHtml = await readIntroHtml(page)
    expect(introHtml).not.toMatch(/<(img|strong)\b/i)
    expect(introHtml).not.toMatch(/onerror/i)
    await expect(page.locator('#fitem_id_introeditor')).toContainText(DESCRIPTION_TEXT)

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
