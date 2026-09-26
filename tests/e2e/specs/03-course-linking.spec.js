// @ts-check
const { expect } = require('@playwright/test')
const { test } = require('../fixtures/auth')
const {
  goToCourseEditPage,
  getSkillandDropdown,
  getCreateCourseButton,
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
 * The course settings form replaces the SkilLand course ID custom field with a
 * dropdown fed by mod_skilland_fetch_courses_ajax, plus a "Create in SkilLand"
 * button that calls mod_skilland_create_course_ajax once its confirmation dialog is
 * accepted. Every test creates its own Moodle course, so each one runs alone and in parallel.
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
      'E2E Skill One (E2E1) [PUBLISHED]',
      'E2E Skill Two (E2E2) [DRAFT]'
    ])
    await expect(getCreateCourseButton(authenticatedPage)).toHaveText('Create in SkilLand')
    await expect(getCreateCourseButton(authenticatedPage)).toBeEnabled()
    expect(skillandMock.calls('mod_skilland_fetch_courses_ajax')).toEqual([{ moodlecourseid: Number(courseId) }])
  })

  test('The form submits exactly one SkilLand course value and has no duplicate ids', async ({
    authenticatedPage,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const courseId = await moodleCourse.create()

    await goToCourseEditPage(page, courseId)
    await waitForSkillandDropdownLoaded(page)

    const named = page.locator('[name="customfield_skilland_course_id"]')
    await expect(named.and(page.locator(':enabled'))).toHaveCount(1)
    await expect(page.locator('select[name="customfield_skilland_course_id"]')).toBeEnabled()
    await expect(page.locator('#id_customfield_skilland_course_id_raw')).toBeDisabled()
    const duplicateIds = await page.evaluate(() => {
      const seen = new Set()
      const dupes = []
      document.querySelectorAll('[id]').forEach(el => {
        if (seen.has(el.id)) dupes.push(el.id)
        seen.add(el.id)
      })
      return dupes
    })
    expect(duplicateIds).toEqual([])
  })

  test('Cancelling the confirmation creates nothing', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const courseId = await moodleCourse.create()

    await goToCourseEditPage(authenticatedPage, courseId)
    await waitForSkillandDropdownLoaded(authenticatedPage)
    await selectCreateNewCourse(authenticatedPage, { confirm: false })

    await expect(getSkillandDropdown(authenticatedPage)).toHaveValue('')
    expect(skillandMock.calls('mod_skilland_create_course_ajax')).toEqual([])
  })

  test('"Create in SkilLand" asks first, then creates the SkilLand course and selects it', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const fullname = `E2E create ${Date.now()}`
    const courseId = await moodleCourse.create({ fullname })
    skillandMock.on('mod_skilland_create_course_ajax', skillandData.createdCourse())

    await goToCourseEditPage(page, courseId)
    await waitForSkillandDropdownLoaded(page)
    await getCreateCourseButton(page).click()
    const dialog = page.locator('.modal.show .modal-dialog')
    await expect(dialog).toContainText(`Create “${fullname}” in SkilLand?`)
    expect(skillandMock.calls('mod_skilland_create_course_ajax')).toEqual([])
    await dialog.locator('[data-action="save"]').click()

    const dropdown = getSkillandDropdown(page)
    await expect(dropdown).toHaveValue(skillandData.CREATED_SKILL_ID)
    await expect(dropdown.locator(`option[value="${skillandData.CREATED_SKILL_ID}"]`)).toHaveText('E2E Created Skill')
    await expect(getCreateCourseButton(page)).toBeEnabled()
    await expect(getCreateCourseButton(page)).toHaveText('Create in SkilLand')
    expect(skillandMock.calls('mod_skilland_create_course_ajax')).toEqual([{ moodlecourseid: Number(courseId) }])
  })

  test('Saving after "Create in SkilLand" keeps the mapping and opens no tab', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const courseId = await moodleCourse.create()
    const created = skillandData.createdCourse({ redirect_url: '' })
    skillandMock.on('mod_skilland_create_course_ajax', created)

    await goToCourseEditPage(page, courseId)
    await waitForSkillandDropdownLoaded(page)
    await selectCreateNewCourse(page)
    await expect(getSkillandDropdown(page)).toHaveValue(created.skillid)

    // The Studio link is offered only by the notification queued after a successful save
    // (observer::course_updated, from the pending path create_course stores server-side);
    // the form itself never opens SkilLand.
    /** @type {import('@playwright/test').Page[]} */
    const popups = []
    page.context().on('page', popup => popups.push(popup))
    await saveCourseForm(page)
    expect(popups).toEqual([])
    expect(skillandMock.navigations()).toEqual([])
    // The mocked create never reached the server, so nothing is pending for this course.
    await expect(page.locator('a[href*="/mod/skilland/sso_redirect.php"][href*="pending=1"]')).toHaveCount(0)

    // SkilLand now lists the new course, and Moodle has stored it on the course.
    skillandMock.on('mod_skilland_fetch_courses_ajax', skillandData.courses([
      { id: created.skillid, name: created.name, status: 'CREATING' }
    ]))
    await goToCourseEditPage(page, courseId)
    await waitForSkillandDropdownLoaded(page)
    expect(await getSelectedSkillandCourse(page)).toBe(created.skillid)
  })

  test('A failed "Create in SkilLand" reports the error and leaves the dropdown alone', async ({
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
    await expect(getCreateCourseButton(authenticatedPage)).toBeEnabled()
  })

  test('A linked course SkilLand no longer lists shows as unknown, with a warning', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const page = authenticatedPage
    const courseId = await moodleCourse.create({ skillId: skillandData.SECOND_SKILL_ID })
    skillandMock.on('mod_skilland_fetch_courses_ajax', {
      courses: [{ id: skillandData.SKILL_ID, name: 'E2E Skill One', code: 'E2E1', status: 'PUBLISHED' }]
    })

    await goToCourseEditPage(page, courseId)
    await waitForSkillandDropdownLoaded(page)

    const dropdown = getSkillandDropdown(page)
    await expect(dropdown).toHaveValue(skillandData.SECOND_SKILL_ID)
    await expect(dropdown.locator(`option[value="${skillandData.SECOND_SKILL_ID}"]`))
      .toHaveText(`Unknown course (ID ${skillandData.SECOND_SKILL_ID})`)
    await expect(page.locator('.skilland-course-mapping-field .alert-warning[role="status"]'))
      .toHaveText('The linked SkilLand course is no longer available to this site. Choose another course or clear the selection.')
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
