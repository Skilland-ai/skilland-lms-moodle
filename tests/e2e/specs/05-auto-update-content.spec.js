// @ts-check
const { expect } = require('@playwright/test')
const { test, testData, loginToMoodle } = require('../fixtures/auth')
const {
  createCourse,
  deleteCourse,
  goToCourseEditPage,
  expandSkillandSection,
  waitForSkillandDropdownLoaded,
  getSkillandDropdown,
  getSelectedSkillandCourse,
  goToEdukmiSettings
} = require('../helpers/moodle-helpers')
const {
  skillandLogin,
  createSkill,
  generateTopic,
  generateLesson,
  updateLesson,
  getTopicScorm,
  getSkill,
  deleteSkill
} = require('../helpers/skilland-api-helpers')

/**
 * Test suite: Auto-Update Content — Issue #18 (skillandUniverse-moodle)
 *
 * The "Automatic content update" checkbox in Skilland activities can be
 * toggled but has no effect. These tests verify the expected behaviour:
 * when a skill's content changes in Skilland, Moodle activities with
 * autoupdate=1 should automatically refresh their SCORM packages.
 *
 * Tests marked [EXPECT FAIL] are intentionally written to assert the
 * correct behaviour that is currently missing — they prove the bug.
 */
test.describe('Auto-Update Content — Issue #18', () => {
  test.describe.configure({ mode: 'serial' })

  // Shared state across tests
  let courseId = ''
  let skillandToken = ''
  let skillId = ''
  let topicId = ''
  let lessonId = ''
  let activityCmId = ''

  // ───────────────────────────────────────────────────────────
  // Setup: Create Skilland content + Moodle course
  // ───────────────────────────────────────────────────────────

  test.beforeAll(async ({ browser }) => {
    // 1. Login to Skilland via API and create test content
    try {
      const auth = await skillandLogin(
        testData.moodle.teacher.email || 'test@reboot.academy',
        testData.moodle.teacher.password || 'reboot'
      )
      skillandToken = auth.token
      console.log('[setup] Logged in to Skilland')
    } catch (error) {
      console.warn('[setup] Skilland login failed — some tests will be skipped:', error.message)
    }

    // 2. Create a Moodle course
    const page = await browser.newPage()
    await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)

    try {
      courseId = await createCourse(page, {
        fullname: 'Auto-Update Test Course',
        shortname: `autoupdate-test-${Date.now()}`
      })
      console.log(`[setup] Created Moodle course: ${courseId}`)
    } catch (error) {
      console.warn('[setup] Course creation failed:', error.message)
    }

    await page.close()
  })

  test.afterAll(async ({ browser }) => {
    // Cleanup Moodle course
    if (courseId) {
      const page = await browser.newPage()
      await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)
      try {
        await deleteCourse(page, courseId)
        console.log(`[cleanup] Deleted Moodle course: ${courseId}`)
      } catch {
        console.log('[cleanup] Course cleanup skipped')
      }
      await page.close()
    }

    // Cleanup Skilland skill
    if (skillandToken && skillId) {
      try {
        await deleteSkill(skillandToken, skillId)
        console.log(`[cleanup] Deleted Skilland skill: ${skillId}`)
      } catch {
        console.log('[cleanup] Skill cleanup skipped')
      }
    }
  })

  // ───────────────────────────────────────────────────────────
  // Group 1: Autoupdate checkbox UI (these should PASS)
  // ───────────────────────────────────────────────────────────

  test.describe('Autoupdate checkbox UI', () => {
    test('Activity form shows the autoupdate checkbox', async ({ authenticatedPage }) => {
      test.skip(!courseId, 'No test course available')

      // Navigate to add Skilland activity form
      await authenticatedPage.goto(
        `/course/modedit.php?add=skilland&course=${courseId}&section=0`
      )
      await authenticatedPage.waitForLoadState('networkidle')

      // The form might redirect if no Skilland Course ID is set — that's OK,
      // we just need to verify the checkbox exists when the form renders.
      const nameField = authenticatedPage.locator('#id_name')
      if (!(await nameField.isVisible().catch(() => false))) {
        test.skip(true, 'Activity form not accessible — course may need Skilland linking first')
        return
      }

      // Expand the Behaviour section
      const behaviourHeader = authenticatedPage.locator(
        'a:has-text("Behaviour"), [data-toggle="collapse"]:has-text("Behaviour"), ' +
        'a:has-text("Comportamiento"), [data-toggle="collapse"]:has-text("Comportamiento")'
      )
      if (await behaviourHeader.count() > 0) {
        const isExpanded = await authenticatedPage.locator('#id_autoupdate').isVisible().catch(() => false)
        if (!isExpanded) {
          await behaviourHeader.first().click()
          await authenticatedPage.waitForTimeout(500)
        }
      }

      // Verify the autoupdate checkbox exists
      const autoupdateCheckbox = authenticatedPage.locator('#id_autoupdate')
      await expect(autoupdateCheckbox).toBeVisible({ timeout: 10000 })

      // Verify it's unchecked by default
      const isChecked = await autoupdateCheckbox.isChecked()
      expect(isChecked).toBe(false)
    })

    test('Autoupdate checkbox can be toggled on', async ({ authenticatedPage }) => {
      test.skip(!courseId, 'No test course available')

      await authenticatedPage.goto(
        `/course/modedit.php?add=skilland&course=${courseId}&section=0`
      )
      await authenticatedPage.waitForLoadState('networkidle')

      const nameField = authenticatedPage.locator('#id_name')
      if (!(await nameField.isVisible().catch(() => false))) {
        test.skip(true, 'Activity form not accessible')
        return
      }

      // Expand Behaviour section
      const behaviourHeader = authenticatedPage.locator(
        'a:has-text("Behaviour"), [data-toggle="collapse"]:has-text("Behaviour"), ' +
        'a:has-text("Comportamiento"), [data-toggle="collapse"]:has-text("Comportamiento")'
      )
      if (await behaviourHeader.count() > 0) {
        const isExpanded = await authenticatedPage.locator('#id_autoupdate').isVisible().catch(() => false)
        if (!isExpanded) {
          await behaviourHeader.first().click()
          await authenticatedPage.waitForTimeout(500)
        }
      }

      // Check the autoupdate checkbox
      const autoupdateCheckbox = authenticatedPage.locator('#id_autoupdate')
      await autoupdateCheckbox.check()

      const isChecked = await autoupdateCheckbox.isChecked()
      expect(isChecked).toBe(true)
    })
  })

  // ───────────────────────────────────────────────────────────
  // Group 2: Scheduled task existence (should FAIL — proves bug)
  // ───────────────────────────────────────────────────────────

  test.describe('Auto-update mechanism', () => {
    test('[EXPECT FAIL] Moodle has a scheduled task for Skilland content sync', async ({ authenticatedPage }) => {
      // Navigate to Moodle's scheduled tasks page
      await authenticatedPage.goto('/admin/tool/task/scheduledtasks.php')
      await authenticatedPage.waitForLoadState('domcontentloaded')

      const pageContent = await authenticatedPage.content()

      // Look for a Skilland scheduled task that would handle auto-updates.
      // Expected: A task like \mod_skilland\task\sync_content or
      // \mod_skilland\task\check_updates should exist.
      const hasEdukmiSyncTask =
        pageContent.includes('mod_skilland\\task\\sync_content') ||
        pageContent.includes('mod_skilland\\task\\check_updates') ||
        pageContent.includes('mod_skilland\\task\\auto_update') ||
        pageContent.includes('mod_skilland\\task\\refresh_scorm')

      // This SHOULD be true if auto-update were implemented
      expect(hasEdukmiSyncTask).toBe(true)
    })

    test('[EXPECT FAIL] Running Moodle cron triggers Skilland content check', async ({ authenticatedPage }) => {
      // Moodle exposes a web-based cron runner at /admin/cron.php
      // If a scheduled task existed, running cron would execute it.
      //
      // We check the cron output for any Skilland-related task execution.
      await authenticatedPage.goto('/admin/cron.php')
      await authenticatedPage.waitForLoadState('domcontentloaded', { timeout: 60000 })

      const pageContent = await authenticatedPage.content()

      // Look for evidence that a Skilland sync task ran
      const hasEdukmiCronOutput =
        pageContent.includes('mod_skilland') ||
        pageContent.includes('skilland') && (
          pageContent.includes('sync') ||
          pageContent.includes('update') ||
          pageContent.includes('check')
        )

      // This SHOULD be true if a cron task existed for auto-update
      expect(hasEdukmiCronOutput).toBe(true)
    })

    test('sync_content task executes without undefined logger method (Issue #20)', async ({ authenticatedPage }) => {
      // Bug #20: sync_content calls logger::info() which does not exist,
      // causing "Scheduled task failed: ...Call to undefined method mod_skilland\logger::info()".

      // Step 1: Verify the task is registered in Moodle.
      await authenticatedPage.goto('/admin/tool/task/scheduledtasks.php')
      await authenticatedPage.waitForLoadState('domcontentloaded')

      const tasksPage = await authenticatedPage.content()
      const taskRegistered = tasksPage.includes('sync_content') ||
        tasksPage.includes('Sync Skilland content')
      test.skip(!taskRegistered, 'sync_content task not registered — plugin may need upgrade')

      // Step 2: Queue the task for immediate execution via "Run now".
      const sesskey = await authenticatedPage.evaluate(() => M.cfg?.sesskey || '')
      expect(sesskey).toBeTruthy()

      await authenticatedPage.goto(
        '/admin/tool/task/scheduledtasks.php?action=runnow' +
        '&task=mod_skilland%5Ctask%5Csync_content&sesskey=' + sesskey
      )
      await authenticatedPage.waitForLoadState('domcontentloaded')

      // Confirm the "Run now" action if Moodle shows a confirmation page.
      const confirmBtn = authenticatedPage.locator(
        'button:has-text("Run now"), input[value="Run now"], button:has-text("Continue")'
      )
      if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await confirmBtn.click()
        await authenticatedPage.waitForLoadState('domcontentloaded')
      }

      // Step 3: Run cron to execute the queued task.
      await authenticatedPage.goto('/admin/cron.php')
      await authenticatedPage.waitForLoadState('domcontentloaded', { timeout: 120000 })

      const cronOutput = await authenticatedPage.textContent('body')

      // Step 4: Assert the task did not crash with undefined method error.
      expect(cronOutput).not.toContain('Call to undefined method mod_skilland\\logger::info')
    })
  })

  // ───────────────────────────────────────────────────────────
  // Group 3: View page update detection (should FAIL — proves bug)
  // ───────────────────────────────────────────────────────────

  test.describe('View page update detection', () => {
    test('[EXPECT FAIL] Activity view page checks for content updates when autoupdate is enabled', async ({ authenticatedPage }) => {
      test.skip(!courseId, 'No test course available')

      // Create a Skilland activity with autoupdate=1
      await authenticatedPage.goto(
        `/course/modedit.php?add=skilland&course=${courseId}&section=0`
      )
      await authenticatedPage.waitForLoadState('networkidle')

      const nameField = authenticatedPage.locator('#id_name')
      if (!(await nameField.isVisible().catch(() => false))) {
        test.skip(true, 'Activity form not accessible — course needs Skilland linking')
        return
      }

      await nameField.fill('Auto-Update Test Activity')

      // Enable autoupdate
      const behaviourHeader = authenticatedPage.locator(
        'a:has-text("Behaviour"), [data-toggle="collapse"]:has-text("Behaviour"), ' +
        'a:has-text("Comportamiento"), [data-toggle="collapse"]:has-text("Comportamiento")'
      )
      if (await behaviourHeader.count() > 0) {
        const isExpanded = await authenticatedPage.locator('#id_autoupdate').isVisible().catch(() => false)
        if (!isExpanded) {
          await behaviourHeader.first().click()
          await authenticatedPage.waitForTimeout(500)
        }
      }

      await authenticatedPage.locator('#id_autoupdate').check()

      // Save the activity
      const submitButton = authenticatedPage.locator('#id_submitbutton2, #id_submitbutton')
      await submitButton.first().click()
      await authenticatedPage.waitForLoadState('networkidle')

      // Find the created activity in the course
      await authenticatedPage.goto(`/course/view.php?id=${courseId}`)
      await authenticatedPage.waitForLoadState('domcontentloaded')

      const activityLink = authenticatedPage.locator(
        'a:has-text("Auto-Update Test Activity"), a[href*="mod/skilland/view"]'
      )

      if (await activityLink.count() === 0) {
        test.skip(true, 'Activity was not created — form submission may have failed')
        return
      }

      // Intercept network requests when visiting the activity view page
      const apiRequests = []
      authenticatedPage.on('request', request => {
        const url = request.url()
        // Look for any outgoing requests to Skilland API to check for updates
        if (url.includes('localhost:8000') || url.includes('skilland') && url.includes('api')) {
          apiRequests.push(url)
        }
      })

      await activityLink.first().click()
      await authenticatedPage.waitForLoadState('networkidle')

      // Store the cmid for later tests
      const viewUrl = authenticatedPage.url()
      const cmMatch = viewUrl.match(/id=(\d+)/)
      if (cmMatch) {
        activityCmId = cmMatch[1]
      }

      // Check page content for update-related UI elements
      const pageContent = await authenticatedPage.content()

      const hasUpdateCheck =
        // API calls to check for content changes
        apiRequests.length > 0 ||
        // UI indicating an update is available
        pageContent.includes('update available') ||
        pageContent.includes('Update available') ||
        pageContent.includes('new version') ||
        pageContent.includes('content has changed') ||
        // A button to trigger manual update
        pageContent.includes('Update content') ||
        pageContent.includes('Refresh content')

      // When autoupdate is enabled, the view page SHOULD check for updates
      // or at least show an indicator when content is stale.
      expect(hasUpdateCheck).toBe(true)
    })
  })

  // ───────────────────────────────────────────────────────────
  // Group 4: Full E2E auto-update flow (should FAIL — proves bug)
  // ───────────────────────────────────────────────────────────

  test.describe('End-to-end auto-update flow', () => {
    test.describe.configure({ timeout: 180000 }) // 3 min for AI generation

    test('Setup: Create Skilland skill with content via API', async () => {
      test.skip(!skillandToken, 'Skilland auth not available')

      // Create a skill
      const skill = await createSkill(skillandToken, `Auto-Update E2E Test ${Date.now()}`)
      skillId = skill.id
      console.log(`[e2e] Created skill: ${skillId}`)
      expect(skillId).toBeTruthy()

      // Generate a topic
      const topics = await generateTopic(skillandToken, skillId, 'Introduction to testing')
      expect(topics.length).toBeGreaterThan(0)
      topicId = topics[0].id
      console.log(`[e2e] Created topic: ${topicId}`)

      // Generate a lesson
      const topic = await generateLesson(
        skillandToken, skillId, topicId, 'What is automated testing?'
      )
      const lessons = topic.content?.filter(c => c.__typename === 'LessonContent') || []
      expect(lessons.length).toBeGreaterThan(0)
      lessonId = lessons[0].id
      console.log(`[e2e] Created lesson: ${lessonId}`)
    })

    test('Setup: Link Moodle course to Skilland skill', async ({ authenticatedPage }) => {
      test.skip(!courseId, 'No Moodle course')
      test.skip(!skillId, 'No Skilland skill')

      // Navigate to course edit and set the Skilland Course ID
      await goToCourseEditPage(authenticatedPage, courseId)
      await authenticatedPage.waitForLoadState('networkidle')
      await expandSkillandSection(authenticatedPage)

      const dropdown = getSkillandDropdown(authenticatedPage)
      const dropdownVisible = await dropdown.isVisible().catch(() => false)

      if (!dropdownVisible) {
        test.skip(true, 'Skilland dropdown not visible — plugin may not be configured')
        return
      }

      await waitForSkillandDropdownLoaded(authenticatedPage)

      // Look for our skill in the dropdown
      const options = await dropdown.locator('option').all()
      let found = false
      for (const option of options) {
        const value = await option.getAttribute('value')
        if (value === skillId) {
          await dropdown.selectOption({ value: skillId })
          found = true
          break
        }
      }

      if (!found) {
        // Skill might not be in dropdown if API key isn't configured
        test.skip(true, 'Skill not found in dropdown — API integration may not be configured')
        return
      }

      // Save
      const saveButton = authenticatedPage.locator('#id_saveanddisplay, #id_submitbutton')
      await saveButton.first().click()
      await authenticatedPage.waitForLoadState('networkidle')

      // Verify it persisted
      await goToCourseEditPage(authenticatedPage, courseId)
      await waitForSkillandDropdownLoaded(authenticatedPage)
      const selectedValue = await getSelectedSkillandCourse(authenticatedPage)
      expect(selectedValue).toBe(skillId)
    })

    test('Setup: Create Skilland activity with autoupdate enabled and provision SCORM', async ({ authenticatedPage }) => {
      test.skip(!courseId, 'No Moodle course')
      test.skip(!topicId, 'No Skilland topic')

      // Add Skilland activity
      await authenticatedPage.goto(
        `/course/modedit.php?add=skilland&course=${courseId}&section=0`
      )
      await authenticatedPage.waitForLoadState('networkidle')

      const nameField = authenticatedPage.locator('#id_name')
      if (!(await nameField.isVisible().catch(() => false))) {
        test.skip(true, 'Activity form not accessible')
        return
      }

      await nameField.fill('E2E Auto-Update Activity')

      // Select the topic in the topic dropdown (if available)
      const topicDropdown = authenticatedPage.locator('#id_skilland_topicid, select[name="skilland_topicid"]')
      if (await topicDropdown.isVisible().catch(() => false)) {
        await topicDropdown.selectOption({ value: topicId }).catch(() => {
          console.log('[e2e] Could not select topic in dropdown')
        })
      }

      // Enable autoupdate
      const behaviourHeader = authenticatedPage.locator(
        'a:has-text("Behaviour"), [data-toggle="collapse"]:has-text("Behaviour"), ' +
        'a:has-text("Comportamiento"), [data-toggle="collapse"]:has-text("Comportamiento")'
      )
      if (await behaviourHeader.count() > 0) {
        const isExpanded = await authenticatedPage.locator('#id_autoupdate').isVisible().catch(() => false)
        if (!isExpanded) {
          await behaviourHeader.first().click()
          await authenticatedPage.waitForTimeout(500)
        }
      }

      await authenticatedPage.locator('#id_autoupdate').check()
      expect(await authenticatedPage.locator('#id_autoupdate').isChecked()).toBe(true)

      // Save
      const submitButton = authenticatedPage.locator('#id_submitbutton2, #id_submitbutton')
      await submitButton.first().click()
      await authenticatedPage.waitForLoadState('networkidle')

      // Navigate to the activity to provision SCORM
      await authenticatedPage.goto(`/course/view.php?id=${courseId}`)
      await authenticatedPage.waitForLoadState('domcontentloaded')

      const activityLink = authenticatedPage.locator('a:has-text("E2E Auto-Update Activity")')
      if (await activityLink.count() > 0) {
        await activityLink.first().click()
        await authenticatedPage.waitForLoadState('domcontentloaded')

        // Store cmid
        const url = authenticatedPage.url()
        const match = url.match(/id=(\d+)/)
        if (match) activityCmId = match[1]

        // Click provision button if visible
        const provisionBtn = authenticatedPage.locator('#skilland-provision-btn')
        if (await provisionBtn.isVisible().catch(() => false)) {
          await provisionBtn.click()
          // Wait for provisioning to complete
          await authenticatedPage.waitForSelector(
            '.skilland-lessons-container, .alert-success',
            { timeout: 60000 }
          ).catch(() => {
            console.log('[e2e] Provisioning may not have completed')
          })
        }
      }
    })

    test('[EXPECT FAIL] SCORM package auto-refreshes after lesson content is updated in Skilland', async ({ authenticatedPage }) => {
      test.skip(!skillandToken, 'Skilland auth not available')
      test.skip(!topicId, 'No Skilland topic')
      test.skip(!activityCmId, 'No Moodle activity')

      // Step 1: Get current SCORM state from Skilland
      let initialScorm
      try {
        initialScorm = await getTopicScorm(skillandToken, topicId)
        console.log(`[e2e] Initial SCORM hash: ${initialScorm.packageHash}`)
        console.log(`[e2e] Initial SCORM generatedAt: ${initialScorm.generatedAt}`)
      } catch (error) {
        test.skip(true, `Could not get initial SCORM info: ${error.message}`)
        return
      }

      // Step 2: Get current lesson list from the Moodle activity page
      await authenticatedPage.goto(`/mod/skilland/view.php?id=${activityCmId}`)
      await authenticatedPage.waitForLoadState('domcontentloaded')

      const initialPageContent = await authenticatedPage.content()
      const initialLessonCards = await authenticatedPage.locator('.skilland-lesson-card').count()
      console.log(`[e2e] Initial lesson cards in Moodle: ${initialLessonCards}`)

      // Step 3: Update lesson content in Skilland
      if (lessonId) {
        const updatedContent = `<h1>Updated Content</h1><p>This content was modified at ${new Date().toISOString()} to test auto-update.</p>`
        await updateLesson(skillandToken, lessonId, {
          content: updatedContent,
          name: 'Updated: What is automated testing?'
        })
        console.log(`[e2e] Updated lesson content in Skilland: ${lessonId}`)
      }

      // Step 4: Verify Skilland's SCORM package has a new hash
      // (Content change should invalidate the SCORM cache)
      let updatedScorm
      try {
        updatedScorm = await getTopicScorm(skillandToken, topicId)
        console.log(`[e2e] Updated SCORM hash: ${updatedScorm.packageHash}`)
        console.log(`[e2e] Updated SCORM generatedAt: ${updatedScorm.generatedAt}`)
      } catch (error) {
        console.log(`[e2e] Could not get updated SCORM info: ${error.message}`)
      }

      // Step 5: Wait for Moodle to detect the change and auto-update
      // Give it a reasonable window — if there were a cron task running
      // every minute, 90 seconds should be enough.
      console.log('[e2e] Waiting 30s for auto-update mechanism to trigger...')
      await authenticatedPage.waitForTimeout(30000)

      // Step 6: Reload the activity view page
      await authenticatedPage.goto(`/mod/skilland/view.php?id=${activityCmId}`)
      await authenticatedPage.waitForLoadState('domcontentloaded')

      const updatedPageContent = await authenticatedPage.content()

      // Step 7: Check if the content was updated in Moodle
      // Look for evidence that the SCORM package was refreshed:
      const contentWasUpdated =
        // The updated lesson name should appear
        updatedPageContent.includes('Updated: What is automated testing?') ||
        // Or the lesson update timestamp should be recent
        updatedPageContent.includes(new Date().toISOString().slice(0, 10)) ||
        // Or the page content is different from before
        (initialPageContent !== updatedPageContent &&
         updatedPageContent.includes('Updated Content'))

      // This assertion SHOULD pass if auto-update worked.
      // It will FAIL because Moodle never detects the Skilland content change.
      expect(contentWasUpdated).toBe(true)
    })

    test('[EXPECT FAIL] Autoupdate setting is checked when Moodle serves the activity', async ({ authenticatedPage }) => {
      test.skip(!activityCmId, 'No Moodle activity')

      // Access the activity's edit form to verify autoupdate is stored
      // Then verify the view page uses it by checking for update-related
      // database queries or API calls.

      // First, verify autoupdate=1 is persisted in the form
      await authenticatedPage.goto(`/course/modedit.php?update=${activityCmId}`)
      await authenticatedPage.waitForLoadState('networkidle')

      // Expand Behaviour section
      const behaviourHeader = authenticatedPage.locator(
        'a:has-text("Behaviour"), [data-toggle="collapse"]:has-text("Behaviour"), ' +
        'a:has-text("Comportamiento"), [data-toggle="collapse"]:has-text("Comportamiento")'
      )
      if (await behaviourHeader.count() > 0) {
        const isExpanded = await authenticatedPage.locator('#id_autoupdate').isVisible().catch(() => false)
        if (!isExpanded) {
          await behaviourHeader.first().click()
          await authenticatedPage.waitForTimeout(500)
        }
      }

      const autoupdateCheckbox = authenticatedPage.locator('#id_autoupdate')
      if (!(await autoupdateCheckbox.isVisible().catch(() => false))) {
        test.skip(true, 'Cannot access activity settings')
        return
      }

      const isChecked = await autoupdateCheckbox.isChecked()
      expect(isChecked).toBe(true) // Verify it was saved

      // Now visit the activity view page and check if view.php reads
      // the autoupdate setting to decide whether to check for updates.
      //
      // We intercept Moodle's own web service calls and Skilland API requests
      // to see if the page queries for newer content when autoupdate=1.
      const outgoingRequests = []
      authenticatedPage.on('request', request => {
        const url = request.url()
        if (
          url.includes('webservice') ||
          url.includes('skilland') && url.includes('api') ||
          url.includes('localhost:8000') ||
          url.includes('topicScorm') ||
          url.includes('check_update') ||
          url.includes('snapshot')
        ) {
          outgoingRequests.push({ url, method: request.method() })
        }
      })

      await authenticatedPage.goto(`/mod/skilland/view.php?id=${activityCmId}`)
      await authenticatedPage.waitForLoadState('networkidle')

      // The view page SHOULD make an API call to check if content changed
      // when autoupdate is enabled. This is the missing mechanism.
      const madeUpdateCheck = outgoingRequests.some(r =>
        r.url.includes('topicScorm') ||
        r.url.includes('check_update') ||
        r.url.includes('snapshot') ||
        r.url.includes('localhost:8000')
      )

      expect(madeUpdateCheck).toBe(true)
    })
  })
})
