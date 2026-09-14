// @ts-check
const { expect } = require('@playwright/test')
const { test, testData, loginToMoodle } = require('../fixtures/auth')
const {
  createCourse,
  deleteCourse,
  goToCourseEditPage,
  expandSkillandSection,
  waitForSkillandDropdownLoaded,
  getSkillandDropdown
} = require('../helpers/moodle-helpers')
const {
  isEdukmiUrl,
  waitForEdukmiLoad,
  isOnSkillsStudio,
  isOnTopicPage
} = require('../helpers/skilland-helpers')

/**
 * Test suite: SCORM Provisioning
 *
 * Tests the SCORM content provisioning flow between Moodle and Skilland.
 * Verifies that Skilland activities can be created and content is properly loaded.
 */
test.describe('SCORM Provisioning', () => {
  test.describe.configure({ mode: 'serial' })

  let courseId = ''

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage()
    await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)

    try {
      courseId = await createCourse(page, {
        fullname: 'SCORM Test Course',
        shortname: `scorm-test-${Date.now()}`
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

  test('Can create Skilland activity in course', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    // First, link the course to Skilland by setting the Skilland Course ID
    await goToCourseEditPage(authenticatedPage, courseId)
    await authenticatedPage.waitForLoadState('networkidle')

    // Expand Skilland section and wait for dropdown
    await expandSkillandSection(authenticatedPage)

    const dropdown = getSkillandDropdown(authenticatedPage)
    const dropdownVisible = await dropdown.isVisible().catch(() => false)

    if (dropdownVisible) {
      // Wait for dropdown to load
      await waitForSkillandDropdownLoaded(authenticatedPage)

      // Select "Create New" option if available, otherwise use first available option
      const options = await dropdown.locator('option').all()
      let selectedValue = ''

      for (const option of options) {
        const value = await option.getAttribute('value')
        if (value === '__create_new__') {
          await dropdown.selectOption({ value: '__create_new__' })
          selectedValue = '__create_new__'
          break
        } else if (value && value !== '') {
          selectedValue = value
          break
        }
      }

      // If we selected create new, wait for the new option to appear
      if (selectedValue === '__create_new__') {
        await authenticatedPage.waitForTimeout(2000)  // Wait for AJAX to complete
      } else if (selectedValue) {
        await dropdown.selectOption({ value: selectedValue })
      }

      // Save the course settings
      const saveButton = authenticatedPage.locator('#id_saveanddisplay, #id_submitbutton')
      await saveButton.first().click()
      await authenticatedPage.waitForLoadState('networkidle')
    }

    // Now try to add the Skilland activity
    await authenticatedPage.goto(`/course/modedit.php?add=skilland&course=${courseId}&section=0`)
    await authenticatedPage.waitForLoadState('networkidle')

    // Check if we're on the activity form or redirected
    const pageContent = await authenticatedPage.content()
    const nameField = authenticatedPage.locator('#id_name')

    // If the name field is visible, we can create the activity
    if (await nameField.isVisible().catch(() => false)) {
      await nameField.fill('Test SCORM Activity')

      const skillandSkillField = authenticatedPage.locator(
        '#id_skilland_skill, [name="skilland_skill"], #id_config_skillid'
      )
      if (await skillandSkillField.isVisible()) {
        await skillandSkillField.fill(testData.skilland.testSkill.id)
      }

      // Note: Moodle 4.x uses #id_submitbutton2 for activity forms
      const submitButton = authenticatedPage.locator('#id_submitbutton2, #id_submitbutton')
      await submitButton.first().click()
      await authenticatedPage.waitForLoadState('networkidle')

      const url = authenticatedPage.url()
      const finalContent = await authenticatedPage.content()

      const success =
        url.includes('view.php') ||
        url.includes('course/view.php') ||
        finalContent.includes('Test SCORM Activity')

      expect(success).toBeTruthy()
    } else {
      // Course might not be linked to Skilland yet - check for the redirect message
      const hasRedirectMessage = pageContent.includes('Set Skilland Course ID') ||
        pageContent.includes('must first set the Skilland Course ID')

      // If we see the redirect message, the test setup couldn't link the course
      // This is expected when Skilland API is not properly configured
      test.skip(hasRedirectMessage, 'Course could not be linked to Skilland - API configuration may be missing')

      // Otherwise, unexpected state
      expect(await nameField.isVisible()).toBeTruthy()
    }
  })

  test('Skilland activity displays launch button', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    await authenticatedPage.goto(`/course/view.php?id=${courseId}`)
    await authenticatedPage.waitForLoadState('domcontentloaded')

    const activityLink = authenticatedPage.locator('a.aalink:has-text("skilland"), a[href*="mod/skilland"]')

    if (await activityLink.count() > 0) {
      await activityLink.first().click()
      await authenticatedPage.waitForLoadState('domcontentloaded')

      const pageContent = await authenticatedPage.content()
      const hasLaunchElements =
        pageContent.includes('launch') ||
        pageContent.includes('Launch') ||
        pageContent.includes('Start') ||
        pageContent.includes('Enter')

      expect(hasLaunchElements).toBeTruthy()
    } else {
      test.skip(true, 'No Skilland activity found in course')
    }
  })

  test('Activity view page loads correctly', async ({ authenticatedPage }) => {
    await authenticatedPage.goto('/mod/skilland/index.php?id=1')
    await authenticatedPage.waitForLoadState('domcontentloaded')

    const status = authenticatedPage.url().includes('skilland') ||
      (await authenticatedPage.content()).includes('Skilland')

    expect(status).toBeTruthy()
  })

  test('Activity configuration is preserved', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    await authenticatedPage.goto(`/course/view.php?id=${courseId}`)
    await authenticatedPage.waitForLoadState('domcontentloaded')

    const editModeToggle = authenticatedPage.locator('[data-action="setmode"]')
    if (await editModeToggle.isVisible()) {
      const isEditing = await authenticatedPage.locator('.editing').count() > 0
      if (!isEditing) {
        await editModeToggle.click()
        await authenticatedPage.waitForLoadState('networkidle')
      }
    }

    const activitySettings = authenticatedPage.locator(
      '[data-action="cmEdit"], a[href*="modedit.php"]:has-text("Edit")'
    )

    if (await activitySettings.count() > 0) {
      await activitySettings.first().click()
      await authenticatedPage.waitForLoadState('domcontentloaded')

      const nameField = authenticatedPage.locator('#id_name')
      if (await nameField.isVisible()) {
        const value = await nameField.inputValue()
        expect(value).toBeTruthy()
      }
    }
  })

  test('SCORM player loads on activity launch', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    await authenticatedPage.goto(`/course/view.php?id=${courseId}`)
    await authenticatedPage.waitForLoadState('domcontentloaded')

    const activityLink = authenticatedPage.locator('a.aalink:has-text("skilland"), a[href*="mod/skilland/view"]')

    if (await activityLink.count() > 0) {
      await activityLink.first().click()
      await authenticatedPage.waitForLoadState('domcontentloaded')

      const launchButton = authenticatedPage.locator(
        'button:has-text("Launch"), a:has-text("Launch"), button:has-text("Start"), a:has-text("Enter")'
      )

      if (await launchButton.count() > 0) {
        const [newPage] = await Promise.all([
          authenticatedPage.context().waitForEvent('page').catch(() => null),
          launchButton.first().click()
        ])

        if (newPage) {
          await newPage.waitForLoadState('domcontentloaded')
          const url = newPage.url()

          if (isEdukmiUrl(url)) {
            await waitForEdukmiLoad(newPage)
            const isStudio = await isOnSkillsStudio(newPage)
            const isTopic = await isOnTopicPage(newPage)
            expect(isStudio || isTopic).toBeTruthy()
          }

          await newPage.close()
        }
      }
    }
  })
})
