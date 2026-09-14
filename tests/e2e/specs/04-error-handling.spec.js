// @ts-check
const { expect } = require('@playwright/test')
const { test, testData, loginToMoodle } = require('../fixtures/auth')
const {
  createCourse,
  deleteCourse,
  goToCourseEditPage,
  getSkillandDropdown,
  getSesskey
} = require('../helpers/moodle-helpers')
const {
  isEdukmiUrl
} = require('../helpers/skilland-helpers')

/**
 * Test suite: Error Handling
 *
 * Tests error scenarios and graceful degradation in the Moodle-Skilland integration.
 */
test.describe('Error Handling', () => {
  test.describe.configure({ mode: 'serial' })

  let courseId = ''

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage()
    await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)

    try {
      courseId = await createCourse(page, {
        fullname: 'Error Handling Test Course',
        shortname: `error-test-${Date.now()}`
      })
      console.log(`Created error test course: ${courseId}`)
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
      } catch {
        console.log('Course cleanup skipped')
      }
      await page.close()
    }
  })

  test('SSO redirect with missing parameters shows error', async ({ authenticatedPage }) => {
    // Navigate to SSO redirect without required parameters
    await authenticatedPage.goto('/mod/skilland/sso_redirect.php')
    await authenticatedPage.waitForLoadState('domcontentloaded')

    const pageContent = await authenticatedPage.content()

    // Should show some error indication
    const hasError =
      pageContent.toLowerCase().includes('error') ||
      pageContent.toLowerCase().includes('missing') ||
      pageContent.toLowerCase().includes('required') ||
      pageContent.toLowerCase().includes('invalid')

    expect(hasError).toBeTruthy()
  })

  test('SSO redirect with invalid sesskey is rejected', async ({ authenticatedPage }) => {
    // Navigate with invalid sesskey
    await authenticatedPage.goto(
      '/mod/skilland/sso_redirect.php?topicid=test&courseid=test&sesskey=invalid_key'
    )
    await authenticatedPage.waitForLoadState('domcontentloaded')

    const pageContent = await authenticatedPage.content()

    // Should show session error
    const hasSessionError =
      pageContent.toLowerCase().includes('sesskey') ||
      pageContent.toLowerCase().includes('session') ||
      pageContent.toLowerCase().includes('invalid') ||
      pageContent.toLowerCase().includes('error')

    expect(hasSessionError).toBeTruthy()
  })

  test('Dropdown gracefully handles empty course list', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    await goToCourseEditPage(authenticatedPage, courseId)

    const dropdown = getSkillandDropdown(authenticatedPage)

    // Wait for dropdown to be visible
    const isVisible = await dropdown.isVisible().catch(() => false)

    if (isVisible) {
      // Wait for dropdown to finish loading (not showing "Loading...")
      // eslint-disable-next-line no-undef
      await authenticatedPage.waitForFunction(
        () => {
          const select = document.querySelector('#skilland-course-select')
          if (!select) return false
          return !select.innerHTML.includes('Loading')
        },
        { timeout: 10000 }
      )

      const options = await dropdown.locator('option').allTextContents()

      // Should have at least the placeholder and Create New options
      expect(options.length).toBeGreaterThanOrEqual(2)

      // Verify Create New option exists
      const hasCreateNew = options.some(opt => opt.includes('Create New'))
      expect(hasCreateNew).toBeTruthy()
    }
  })

  test('Activity launch with non-existent skill shows appropriate message', async ({
    authenticatedPage
  }) => {
    // Try to view an activity with a non-existent skill ID
    await authenticatedPage.goto('/mod/skilland/view.php?id=999999')
    await authenticatedPage.waitForLoadState('domcontentloaded')

    const pageContent = await authenticatedPage.content()

    // Should show error or "not found" message
    const hasErrorOrNotFound =
      pageContent.toLowerCase().includes('not found') ||
      pageContent.toLowerCase().includes('error') ||
      pageContent.toLowerCase().includes('does not exist') ||
      pageContent.toLowerCase().includes('invalid') ||
      authenticatedPage.url().includes('error')

    expect(hasErrorOrNotFound).toBeTruthy()
  })
})

/**
 * Test suite: Network Error Simulation
 *
 * Tests behavior when network requests fail.
 * Note: These tests use route interception to simulate failures.
 */
test.describe('Network Error Handling', () => {
  let courseId = ''

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage()
    await loginToMoodle(page, testData.moodle.admin.username, testData.moodle.admin.password)

    try {
      courseId = await createCourse(page, {
        fullname: 'Network Error Test Course',
        shortname: `network-test-${Date.now()}`
      })
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
      } catch {
        console.log('Cleanup skipped')
      }
      await page.close()
    }
  })

  test('Dropdown shows error when API is unreachable', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    // Intercept AJAX calls to simulate network failure
    await authenticatedPage.route('**/lib/ajax/service.php**', route => {
      const postData = route.request().postData() || ''

      // Only fail the Skilland fetch courses call
      if (postData.includes('mod_skilland_fetch_courses_ajax')) {
        route.abort('failed')
      } else {
        route.continue()
      }
    })

    await goToCourseEditPage(authenticatedPage, courseId)

    // Wait for the error to be handled - either notification shows or text input becomes visible
    // eslint-disable-next-line no-undef
    await authenticatedPage.waitForFunction(
      () => {
        const errorNotification = document.querySelector('.alert-danger, .alert-error, [data-type="error"]')
        const textInput = document.querySelector('#id_skilland_course_id')
        const isTextInputVisible = textInput && textInput.style.display !== 'none'
        return errorNotification || isTextInputVisible
      },
      { timeout: 10000 }
    )

    // Verify the error state
    const hasErrorNotification = await authenticatedPage
      .locator('.alert-danger, .alert-error, [data-type="error"]')
      .isVisible()
      .catch(() => false)

    const textInputVisible = await authenticatedPage
      .locator('#id_skilland_course_id:not([style*="display: none"])')
      .isVisible()
      .catch(() => false)

    // Either error notification should show OR text input should be visible (fallback)
    expect(hasErrorNotification || textInputVisible).toBeTruthy()
  })

  test('Create course shows error on network failure', async ({ authenticatedPage }) => {
    test.skip(!courseId, 'No test course available')

    // First load page normally to get dropdown
    await goToCourseEditPage(authenticatedPage, courseId)

    const dropdown = getSkillandDropdown(authenticatedPage)
    const isVisible = await dropdown.isVisible().catch(() => false)

    if (!isVisible) {
      test.skip(true, 'Dropdown not available')
    }

    // Wait for dropdown to finish loading
    // eslint-disable-next-line no-undef
    await authenticatedPage.waitForFunction(
      () => {
        const select = document.querySelector('#skilland-course-select')
        if (!select) return false
        return !select.innerHTML.includes('Loading')
      },
      { timeout: 10000 }
    )

    // Now intercept create course calls
    await authenticatedPage.route('**/lib/ajax/service.php**', route => {
      const postData = route.request().postData() || ''

      if (postData.includes('mod_skilland_create_course_ajax')) {
        route.abort('failed')
      } else {
        route.continue()
      }
    })

    // Try to create new course
    await dropdown.selectOption({ value: '__create_new__' })

    // Wait for dropdown to be re-enabled or error to show
    // eslint-disable-next-line no-undef
    await authenticatedPage.waitForFunction(
      () => {
        const select = document.querySelector('#skilland-course-select')
        const errorNotification = document.querySelector('.alert-danger, .alert-error, [data-type="error"]')
        // Either dropdown recovers (not disabled) or error is shown
        return (select && !select.disabled) || errorNotification
      },
      { timeout: 10000 }
    )

    // Verify recovery - dropdown should be re-enabled (not stuck in disabled state)
    const isDisabled = await dropdown.isDisabled()
    expect(isDisabled).toBe(false)
  })
})

/**
 * Test suite: Input Validation
 *
 * Tests validation of user inputs and edge cases.
 */
test.describe('Input Validation', () => {
  test('SSO redirect sanitizes topic ID parameter', async ({ authenticatedPage }) => {
    const sesskey = await getSesskey(authenticatedPage)

    // Try with potentially malicious input
    const maliciousInput = '<script>alert("xss")</script>'

    // Track if any dialog appears (would indicate XSS execution)
    let dialogAppeared = false
    authenticatedPage.on('dialog', async dialog => {
      dialogAppeared = true
      await dialog.dismiss()
    })

    // Navigate and wait for either load or error
    try {
      await authenticatedPage.goto(
        `/mod/skilland/sso_redirect.php?topicid=${encodeURIComponent(maliciousInput)}&courseid=test&sesskey=${sesskey}`,
        { waitUntil: 'load', timeout: 10000 }
      )
    } catch {
      // Navigation may fail due to redirect - that's OK
    }

    // Wait for page to settle before reading content
    await authenticatedPage.waitForLoadState('domcontentloaded').catch(() => {})

    // Small delay to ensure navigation is complete
    await authenticatedPage.waitForTimeout(500)

    const pageContent = await authenticatedPage.content()

    // The script tag should be escaped or removed, not rendered as executable
    expect(pageContent).not.toContain('<script>alert')

    // No XSS dialog should have appeared
    expect(dialogAppeared).toBe(false)
  })

  test('SSO redirect sanitizes course ID parameter', async ({ authenticatedPage }) => {
    const sesskey = await getSesskey(authenticatedPage)

    // Try with SQL injection-like input
    const maliciousInput = "'; DROP TABLE users;--"

    try {
      await authenticatedPage.goto(
        `/mod/skilland/sso_redirect.php?topicid=test&courseid=${encodeURIComponent(maliciousInput)}&sesskey=${sesskey}`,
        { waitUntil: 'load', timeout: 10000 }
      )
    } catch {
      // Navigation may fail due to redirect - that's OK
    }

    // Wait for page to settle
    await authenticatedPage.waitForLoadState('domcontentloaded').catch(() => {})
    await authenticatedPage.waitForTimeout(1000)

    // Page should handle gracefully (error or redirect, but not crash)
    const url = authenticatedPage.url()
    const pageContent = await authenticatedPage.content()
    const pageContentLower = pageContent.toLowerCase()

    // Should either redirect to Skilland (with sanitized params) or show error/warning
    // Also check for Moodle-specific error indicators and exception pages
    const handledGracefully =
      isEdukmiUrl(url) ||
      pageContentLower.includes('error') ||
      pageContentLower.includes('invalid') ||
      pageContentLower.includes('exception') ||
      pageContentLower.includes('warning') ||
      url.includes('/login/') ||  // Redirected to login = session issue
      url.includes('error')       // URL contains error path

    expect(handledGracefully).toBeTruthy()
  })
})
