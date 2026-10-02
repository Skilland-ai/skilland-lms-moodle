// @ts-check
const { expect } = require('@playwright/test')
const { test } = require('../fixtures/auth')
const {
  goToAddSkillandActivity,
  waitForTopicsLoaded,
  getEditInSkillandButton
} = require('../helpers/moodle-helpers')
const skillandData = require('../fixtures/skilland-data')

/**
 * Test suite: SCORM provisioning setup (activity form)
 *
 * Adding a Skilland activity starts in the activity form: the topic dropdown is fed
 * by mod_skilland_fetch_topics_ajax and the lesson picker by
 * mod_skilland_fetch_lessons_ajax, both answered by the Skilland mock.
 *
 * Saving the form and provisioning the SCORM package validate the topic against
 * Skilland's REST API from PHP, which the browser mock cannot answer; that part
 * is covered by PHPUnit (tests/phpunit).
 */
test.describe('SCORM provisioning setup', () => {
  test.describe.configure({ mode: 'parallel' })

  test('Activity form asks to link the course to Skilland first', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const courseId = await moodleCourse.create()

    await goToAddSkillandActivity(authenticatedPage, courseId)

    const warning = authenticatedPage.locator('.alert-warning')
    await expect(warning).toContainText('you must first set the Skilland Course ID')
    await expect(warning.getByRole('link', { name: 'Set Skilland Course ID in course settings' })).toBeVisible()
    await expect(authenticatedPage.locator('select#id_skilland_topicid')).toHaveCount(0)
    expect(skillandMock.calls('mod_skilland_fetch_topics_ajax')).toEqual([])
  })

  test('Activity form lists the Skilland topics of the linked course', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const courseId = await moodleCourse.create({ skillId: skillandData.SKILL_ID })
    skillandMock
      .on('mod_skilland_fetch_topics_ajax', skillandData.topics())
      .on('mod_skilland_fetch_lessons_ajax', skillandData.lessons())

    await goToAddSkillandActivity(authenticatedPage, courseId)
    await waitForTopicsLoaded(authenticatedPage, skillandData.TOPIC_ID)

    await expect(authenticatedPage.locator('select#id_skilland_topicid option')).toHaveText([
      /Select/,
      `T1 - Getting started (${skillandData.TOPIC_ID})`,
      `T2 - Going further (${skillandData.SECOND_TOPIC_ID})`
    ])
    await expect(authenticatedPage.locator('.skilland-course-name')).toHaveText('E2E Skill One')
    expect(skillandMock.calls('mod_skilland_fetch_topics_ajax')).toEqual([
      // The activity form passes the Moodle course id as a string.
      { courseid: skillandData.SKILL_ID, moodlecourseid: courseId }
    ])
  })

  test('Choosing a topic loads its lessons and selects all of them', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const courseId = await moodleCourse.create({ skillId: skillandData.SKILL_ID })
    const lessons = skillandData.lessons().lessons
    skillandMock
      .on('mod_skilland_fetch_topics_ajax', skillandData.topics())
      .on('mod_skilland_fetch_lessons_ajax', skillandData.lessons())

    await goToAddSkillandActivity(page, courseId)
    await waitForTopicsLoaded(page, skillandData.TOPIC_ID)
    await page.locator('select#id_skilland_topicid').selectOption(skillandData.TOPIC_ID)

    const cards = page.locator('#id_lessons_container .skilland-lesson-card')
    await expect(cards).toHaveCount(2)
    await expect(cards.locator('.skilland-lesson-title')).toHaveText([
      'L1.1 - What is testing?',
      'L1.2 - Writing a first test'
    ])
    await expect(page.locator('#id_lessons_container .skilland-lesson-checkbox:checked')).toHaveCount(2)
    await expect(page.locator('#id_name')).toHaveValue('T1 - Getting started')

    const selected = JSON.parse(await page.locator('#id_selected_lessons').inputValue())
    expect(Object.keys(selected)).toEqual(lessons.map(lesson => lesson.id))
    expect(skillandMock.calls('mod_skilland_fetch_lessons_ajax')).toEqual([
      { topicid: skillandData.TOPIC_ID, moodlecourseid: courseId }
    ])

    // Unticking a lesson drops it from the selection that is saved with the form.
    await page.locator(`#lesson_${lessons[0].id}`).uncheck()
    await expect.poll(async () =>
      Object.keys(JSON.parse(await page.locator('#id_selected_lessons').inputValue()))
    ).toEqual([lessons[1].id])
  })

  test('"Edit in Skilland" points the SSO redirect at the chosen topic', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const courseId = await moodleCourse.create({ skillId: skillandData.SKILL_ID })
    skillandMock
      .on('mod_skilland_fetch_topics_ajax', skillandData.topics())
      .on('mod_skilland_fetch_lessons_ajax', skillandData.lessons())

    await goToAddSkillandActivity(page, courseId)
    await waitForTopicsLoaded(page, skillandData.TOPIC_ID)
    await page.locator('select#id_skilland_topicid').selectOption(skillandData.SECOND_TOPIC_ID)

    const editLink = getEditInSkillandButton(page)
    await expect(editLink).toBeVisible()
    const href = new URL(/** @type {string} */ (await editLink.getAttribute('href')))
    expect(href.pathname).toBe('/mod/skilland/sso_redirect.php')
    expect(href.searchParams.get('topicid')).toBe(skillandData.SECOND_TOPIC_ID)
    expect(href.searchParams.get('courseid')).toBe(courseId)
    expect(href.searchParams.get('sesskey')).toBeTruthy()
  })

  test('A lessons error from Skilland is shown in the lesson picker', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const courseId = await moodleCourse.create({ skillId: skillandData.SKILL_ID })
    skillandMock
      .on('mod_skilland_fetch_topics_ajax', skillandData.topics())
      .on('mod_skilland_fetch_lessons_ajax', { lessons: [], error: 'Topic has no published lessons' })

    await goToAddSkillandActivity(page, courseId)
    await waitForTopicsLoaded(page, skillandData.TOPIC_ID)
    await page.locator('select#id_skilland_topicid').selectOption(skillandData.TOPIC_ID)

    await expect(page.locator('#id_lessons_container .text-danger')).toHaveText('Topic has no published lessons')
    await expect(page.locator('#id_lessons_container .skilland-lesson-card')).toHaveCount(0)
  })
})
