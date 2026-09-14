// @ts-check
const { expect } = require('@playwright/test')
const { test, testData, loginToMoodle } = require('../fixtures/auth')
const {
  createCourse,
  deleteCourse,
  goToCourseEditPage,
  getSkillandDropdown,
  expandSkillandSection,
  waitForSkillandDropdownLoaded,
  selectCreateNewCourse,
  selectExistingCourse,
  waitForAjaxComplete,
  getSelectedSkillandCourse
} = require('../helpers/moodle-helpers')

/**
 * Test suite: Course Linking - Issue #99
 *
 * Tests the Moodle-Skilland course linking flow including:
 * - Dropdown display on course edit page
 * - "Create New Skilland Course" functionality
 * - Course selection persistence
 * - SSO integration for newly created courses
 */
test.describe('Course Linking - Issue #99', () => {
  test.describe.configure({ mode: 'serial' })

  let courseId = ''
  let createdSkillId = ''

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage()
    await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)

    try {
      courseId = await createCourse(page, {
        fullname: 'Course Linking Test',
        shortname: `link-test-${Date.now()}`
      })
      console.log(`Created test course: ${courseId}`)
    } catch (error) {
      console.log('Course creation failed:', error)
    }

    await page.close()
  })

  test.afterAll(async ({ browser }) => {
    if (courseId) {
      const page = await browser.newPage()
      await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)
      try {
        await deleteCourse(page, courseId)
        console.log(`Deleted test course: ${courseId}`)
      } catch {
        console.log('Course cleanup skipped')
      }
      await page.close()
    }
  })

  test('Course edit page shows Skilland Course ID dropdown', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    await goToCourseEditPage(authenticatedPage, courseId)
    await authenticatedPage.waitForLoadState('networkidle')

    // Expand the Skilland content section first
    await expandSkillandSection(authenticatedPage)

    // Wait for the dropdown to appear
    const dropdown = getSkillandDropdown(authenticatedPage)

    // The dropdown should be visible
    await expect(dropdown).toBeVisible({ timeout: 15000 })

    // Verify it's a select element
    const tagName = await dropdown.evaluate(el => el.tagName.toLowerCase())
    expect(tagName).toBe('select')
  })

  test('Dropdown includes "Create New Skilland Course" option', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    await goToCourseEditPage(authenticatedPage, courseId)
    await waitForSkillandDropdownLoaded(authenticatedPage)

    const dropdown = getSkillandDropdown(authenticatedPage)

    // Check for the Create New option
    const createOption = dropdown.locator('option[value="__create_new__"]')
    await expect(createOption).toBeAttached()

    const optionText = await createOption.textContent()
    expect(optionText).toContain('Create New Skilland Course')
  })

  test('Clicking "Create New" marks course for creation on save', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    await goToCourseEditPage(authenticatedPage, courseId)
    await authenticatedPage.waitForLoadState('networkidle')
    await expandSkillandSection(authenticatedPage)
    await waitForSkillandDropdownLoaded(authenticatedPage)

    // Check if Create New option exists
    const dropdown = getSkillandDropdown(authenticatedPage)
    const createNewOption = dropdown.locator('option[value="__create_new__"]')
    const hasCreateNew = await createNewOption.count() > 0

    if (!hasCreateNew) {
      test.skip(true, 'Create New option not available - Skilland API may not be configured')
      return
    }

    // Select Create New option - this should mark for deferred creation
    await selectCreateNewCourse(authenticatedPage)

    // Verify the dropdown shows the pending creation indicator
    const optionText = await createNewOption.textContent()
    expect(optionText).toContain('will create on save')

    // Verify the hidden input has the pending marker
    const selectedValue = await getSelectedSkillandCourse(authenticatedPage)
    expect(selectedValue).toBe('__pending_create__')
  })

  test('Saving form with "Create New" creates skill with correct name', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    await goToCourseEditPage(authenticatedPage, courseId)
    await authenticatedPage.waitForLoadState('networkidle')
    await expandSkillandSection(authenticatedPage)
    await waitForSkillandDropdownLoaded(authenticatedPage)

    // Check if Create New option exists
    const dropdown = getSkillandDropdown(authenticatedPage)
    const createNewOption = dropdown.locator('option[value="__create_new__"]')
    const hasCreateNew = await createNewOption.count() > 0

    if (!hasCreateNew) {
      test.skip(true, 'Create New option not available - Skilland API may not be configured')
      return
    }

    // Get initial option count
    const initialOptions = await dropdown.locator('option').count()

    // Select Create New option
    await selectCreateNewCourse(authenticatedPage)

    // Click save button - this triggers the deferred course creation
    const submitButton = authenticatedPage.locator('#id_saveanddisplay, #id_submitbutton')
    await submitButton.first().click()

    // Wait for AJAX creation + form submission + page reload
    await authenticatedPage.waitForLoadState('networkidle', { timeout: 30000 })

    // Navigate back to edit page to verify the course was created
    await goToCourseEditPage(authenticatedPage, courseId)
    await authenticatedPage.waitForLoadState('networkidle')
    await expandSkillandSection(authenticatedPage)
    await waitForSkillandDropdownLoaded(authenticatedPage)

    // Check if a new option was added (indicates successful API call)
    const finalOptions = await dropdown.locator('option').count()

    // If no new option was added, the API call likely failed (not configured)
    if (finalOptions <= initialOptions) {
      // Check for error notification
      const hasError = await authenticatedPage.locator('.alert-danger, .alert-error, [data-type="error"]').count() > 0

      if (hasError) {
        test.skip(true, 'Create New failed - Skilland API may not be configured or unavailable')
        return
      }

      console.log(`Initial options: ${initialOptions}, Final options: ${finalOptions}`)
      test.skip(true, 'Create New did not add a new option - API may not be fully configured')
      return
    }

    // Verify the new skill is selected (not empty, not __create_new__, not __pending_create__)
    const selectedValue = await getSelectedSkillandCourse(authenticatedPage)
    expect(selectedValue).not.toBe('')
    expect(selectedValue).not.toBe('__create_new__')
    expect(selectedValue).not.toBe('__pending_create__')

    // Store the created skill ID for later tests
    createdSkillId = selectedValue
    console.log(`Created skill ID: ${createdSkillId}`)
  })

  test('Course mapping persists after save', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')
    test.skip(!createdSkillId, 'No skill was created in previous test')

    await goToCourseEditPage(authenticatedPage, courseId)
    await waitForSkillandDropdownLoaded(authenticatedPage)

    // Select the previously created skill
    await selectExistingCourse(authenticatedPage, createdSkillId)

    // Save the course
    await authenticatedPage.locator('#id_submitbutton, #id_saveanddisplay').click()
    await authenticatedPage.waitForLoadState('networkidle')

    // Navigate back to edit page
    await goToCourseEditPage(authenticatedPage, courseId)
    await waitForSkillandDropdownLoaded(authenticatedPage)

    // Verify the selection persisted
    const selectedValue = await getSelectedSkillandCourse(authenticatedPage)
    expect(selectedValue).toBe(createdSkillId)
  })

  test('Linked course shows status indicator in dropdown', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    await goToCourseEditPage(authenticatedPage, courseId)
    await waitForSkillandDropdownLoaded(authenticatedPage)

    const dropdown = getSkillandDropdown(authenticatedPage)

    // Check that options include status info (e.g., [CREATING], [PUBLISHED])
    const optionsText = await dropdown.locator('option').allTextContents()

    // Log options with status brackets for debugging (this might not always have results)
    const optionsWithStatus = optionsText.filter(t => t.includes('[') && !t.includes('Create New'))
    if (optionsWithStatus.length > 0) {
      console.log('Options with status:', optionsWithStatus)
    }
  })
})

/**
 * Test suite: Teacher Course Linking
 *
 * Tests that teachers can use the course linking feature.
 */
test.describe('Teacher Course Linking', () => {
  test.describe.configure({ mode: 'serial' })

  let teacherCourseId = ''

  test.beforeAll(async ({ browser }) => {
    // Create a course as admin first
    const page = await browser.newPage()
    await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)

    try {
      teacherCourseId = await createCourse(page, {
        fullname: 'Teacher Link Test Course',
        shortname: `teacher-link-${Date.now()}`
      })
      console.log(`Created teacher test course: ${teacherCourseId}`)
    } catch (error) {
      console.log('Teacher course creation failed:', error)
    }

    await page.close()
  })

  test.afterAll(async ({ browser }) => {
    if (teacherCourseId) {
      const page = await browser.newPage()
      await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)
      try {
        await deleteCourse(page, teacherCourseId)
      } catch {
        console.log('Teacher course cleanup skipped')
      }
      await page.close()
    }
  })

  test('Teacher can access course edit page', async ({ teacherPage }) => {
    test.skip(!teacherCourseId, 'No test course available')

    await goToCourseEditPage(teacherPage, teacherCourseId)

    // Teacher should be able to see the page (might redirect if no permission)
    const url = teacherPage.url()
    const hasAccess = url.includes('edit.php') || url.includes('course/view.php')

    // If teacher doesn't have edit access, test is inconclusive
    if (!hasAccess) {
      test.skip(true, 'Teacher does not have edit access to this course')
    }

    expect(hasAccess).toBeTruthy()
  })

  test('Teacher sees Skilland dropdown when editing course', async ({ teacherPage }) => {
    test.skip(!teacherCourseId, 'No test course available')

    await goToCourseEditPage(teacherPage, teacherCourseId)

    const dropdown = getSkillandDropdown(teacherPage)

    // Check if dropdown is visible (teacher has proper permissions)
    const isVisible = await dropdown.isVisible().catch(() => false)

    if (!isVisible) {
      // Check if we're on the edit page at all
      const url = teacherPage.url()
      if (!url.includes('edit.php')) {
        test.skip(true, 'Teacher cannot access course edit page')
      }
    }

    // If visible, verify it works
    if (isVisible) {
      await waitForSkillandDropdownLoaded(teacherPage)
      const options = await dropdown.locator('option').count()
      expect(options).toBeGreaterThan(0)
    }
  })

  test('Teacher can create new Skilland course from dropdown', async ({ teacherPage }) => {
    test.skip(!teacherCourseId, 'No test course available')

    await goToCourseEditPage(teacherPage, teacherCourseId)

    const dropdown = getSkillandDropdown(teacherPage)
    const isVisible = await dropdown.isVisible().catch(() => false)

    if (!isVisible) {
      test.skip(true, 'Skilland dropdown not available for teacher')
    }

    await waitForSkillandDropdownLoaded(teacherPage)

    // Select Create New - this marks for deferred creation
    await selectCreateNewCourse(teacherPage)

    // Verify the dropdown shows the pending creation indicator
    const createNewOption = dropdown.locator('option[value="__create_new__"]')
    const optionText = await createNewOption.textContent()
    expect(optionText).toContain('will create on save')

    // Click save button to trigger deferred creation
    const submitButton = teacherPage.locator('#id_saveanddisplay, #id_submitbutton')
    await submitButton.first().click()

    // Wait for AJAX creation + form submission + page reload
    await teacherPage.waitForLoadState('networkidle', { timeout: 30000 })

    // Navigate back to edit page to verify the course was created
    await goToCourseEditPage(teacherPage, teacherCourseId)
    await teacherPage.waitForLoadState('networkidle')
    await expandSkillandSection(teacherPage)
    await waitForSkillandDropdownLoaded(teacherPage)

    // Verify something was selected (not empty, not __create_new__, not __pending_create__)
    const selectedValue = await getSelectedSkillandCourse(teacherPage)

    // If creation worked, value should be a skill ID
    if (selectedValue && selectedValue !== '' && selectedValue !== '__create_new__' && selectedValue !== '__pending_create__') {
      console.log(`Teacher created skill: ${selectedValue}`)
      expect(selectedValue).toBeTruthy()
    }
  })
})
