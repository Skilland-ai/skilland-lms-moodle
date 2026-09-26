// @ts-check
const { expect } = require('@playwright/test')
const { test } = require('../fixtures/auth')
const {
  goToCourseEditPage,
  getSkillandDropdown,
  waitForSkillandDropdownLoaded,
  selectCreateNewCourse,
  selectExistingCourse,
  saveCourseForm,
  getSelectedSkillandCourse
} = require('../helpers/moodle-helpers')
const skillandData = require('../fixtures/skilland-data')

/**
 * Test suite: Course Linking
 *
 * The course settings form replaces the "Skilland Course ID" custom field with a
 * dropdown fed by mod_skilland_fetch_courses_ajax, plus a "+ Create in Skilland"
 * option that calls mod_skilland_create_course_ajax right away. Every test creates
 * its own Moodle course, so each one runs alone and in parallel.
 */
test.describe('Course Linking', () => {
  test.describe.configure({ mode: 'parallel' })

  test('Course edit page lists SkilLand courses in the dropdown', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const courseId = await moodleCourse.create()

    await goToCourseEditPage(authenticatedPage, courseId)
    await waitForSkillandDropdownLoaded(authenticatedPage)

    const options = getSkillandDropdown(authenticatedPage).locator('option')
    await expect(options).toHaveText([
      'Select a Skilland course...',
      '+ Create in Skilland',
      'E2E Skill One (E2E1) [PUBLISHED]',
      'E2E Skill Two (E2E2) [DRAFT]'
    ])
    expect(skillandMock.calls('mod_skilland_fetch_courses_ajax')).toEqual([{ moodlecourseid: Number(courseId) }])
  })

  test('"+ Create in Skilland" creates the SkilLand course and selects it', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const courseId = await moodleCourse.create()
    skillandMock.on('mod_skilland_create_course_ajax', skillandData.createdCourse())

    await goToCourseEditPage(authenticatedPage, courseId)
    await waitForSkillandDropdownLoaded(authenticatedPage)
    await selectCreateNewCourse(authenticatedPage)

    const dropdown = getSkillandDropdown(authenticatedPage)
    await expect(dropdown).toHaveValue(skillandData.CREATED_SKILL_ID)
    await expect(dropdown).toBeEnabled()
    await expect(dropdown.locator(`option[value="${skillandData.CREATED_SKILL_ID}"]`)).toHaveText('E2E Created Skill')
    await expect(dropdown.locator('option[value="__create_new__"]')).toHaveText('+ Create in Skilland')
    expect(skillandMock.calls('mod_skilland_create_course_ajax')).toEqual([{ moodlecourseid: Number(courseId) }])
  })

  test('Saving after "+ Create in Skilland" keeps the mapping and opens SkilLand', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const courseId = await moodleCourse.create()
    const created = skillandData.createdCourse()
    skillandMock.on('mod_skilland_create_course_ajax', created)

    await goToCourseEditPage(page, courseId)
    await waitForSkillandDropdownLoaded(page)
    await selectCreateNewCourse(page)
    await expect(getSkillandDropdown(page)).toHaveValue(created.skillid)

    // The form opens the SkilLand redirect in a new tab when it is submitted.
    const [popup] = await Promise.all([
      page.context().waitForEvent('page'),
      saveCourseForm(page)
    ])
    await expect(popup.locator('#skilland-stub')).toBeVisible()
    expect(skillandMock.navigations()).toContain(created.redirect_url)

    // SkilLand now lists the new course, and Moodle has stored it on the course.
    skillandMock.on('mod_skilland_fetch_courses_ajax', skillandData.courses([
      { id: created.skillid, name: created.name, status: 'CREATING' }
    ]))
    await goToCourseEditPage(page, courseId)
    await waitForSkillandDropdownLoaded(page)
    expect(await getSelectedSkillandCourse(page)).toBe(created.skillid)
  })

  test('A failed "+ Create in Skilland" reports the error and resets the dropdown', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const courseId = await moodleCourse.create()
    skillandMock.on('mod_skilland_create_course_ajax', {
      ...skillandData.createdCourse({ skillid: '', name: '', redirect_url: '' }),
      error: 'Organization quota reached'
    })

    await goToCourseEditPage(authenticatedPage, courseId)
    await waitForSkillandDropdownLoaded(authenticatedPage)
    await selectCreateNewCourse(authenticatedPage)

    await expect(authenticatedPage.locator('.alert-danger').filter({ hasText: 'Organization quota reached' }))
      .toBeVisible()
    const dropdown = getSkillandDropdown(authenticatedPage)
    await expect(dropdown).toHaveValue('')
    await expect(dropdown).toBeEnabled()
  })

  test('Selecting an existing SkilLand course persists after save', async ({
    authenticatedPage,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const courseId = await moodleCourse.create()

    await goToCourseEditPage(page, courseId)
    await waitForSkillandDropdownLoaded(page)
    await selectExistingCourse(page, skillandData.SECOND_SKILL_ID)
    await saveCourseForm(page)

    await goToCourseEditPage(page, courseId)
    await waitForSkillandDropdownLoaded(page)
    expect(await getSelectedSkillandCourse(page)).toBe(skillandData.SECOND_SKILL_ID)
  })
})

/**
 * Test suite: Teacher Course Linking
 *
 * The Skilland Course ID custom field is locked: only users with
 * moodle/course:changelockedcustomfields (managers, admins) can remap a course.
 */
test.describe('Teacher Course Linking', () => {
  test('Teacher can edit the course but cannot remap its SkilLand course', async ({
    teacherPage,
    skillandMock,
    moodleCourse
  }) => {
    const courseId = await moodleCourse.create({ skillId: skillandData.SKILL_ID, enrolTeacher: true })

    await goToCourseEditPage(teacherPage, courseId)
    await expect(teacherPage.locator('#id_fullname')).toBeVisible()

    await expect(getSkillandDropdown(teacherPage)).toHaveCount(0)
    await expect(teacherPage.locator('input[type="text"]#id_customfield_skilland_course_id')).toHaveCount(0)
    expect(skillandMock.calls()).toEqual([])
  })
})
