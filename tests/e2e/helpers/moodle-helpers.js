// @ts-check
const testData = require('../fixtures/test-data.json')

/**
 * Moodle-specific helper functions for E2E tests
 */

/**
 * Navigate to Moodle admin site administration
 * @param {import('@playwright/test').Page} page
 */
async function goToSiteAdmin(page) {
  await page.goto('/admin/search.php')
  await page.waitForLoadState('domcontentloaded')
}

/**
 * Navigate to plugin management page
 * @param {import('@playwright/test').Page} page
 */
async function goToPluginManagement(page) {
  await page.goto('/admin/plugins.php')
  await page.waitForLoadState('domcontentloaded')
}

/**
 * Navigate to Skilland plugin settings
 * @param {import('@playwright/test').Page} page
 */
async function goToEdukmiSettings(page) {
  await page.goto('/admin/settings.php?section=modsettingskilland')
  await page.waitForLoadState('domcontentloaded')
}

/**
 * Check if Skilland plugin is installed
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>}
 */
async function isEdukmiPluginInstalled(page) {
  await goToPluginManagement(page)

  const pluginRow = page.locator('tr:has-text("skilland")')
  return await pluginRow.count() > 0
}

/**
 * Create a new course in Moodle
 * @param {import('@playwright/test').Page} page
 * @param {object} courseData
 * @returns {Promise<string>} Course ID
 */
async function createCourse(page, courseData = testData.testCourse) {
  await page.goto('/course/edit.php?category=1')
  await page.waitForLoadState('networkidle')  // Wait for form JS to load

  // Wait for form fields to be visible and interactive
  await page.locator('#id_fullname').waitFor({ state: 'visible' })
  await page.locator('#id_fullname').fill(courseData.fullname)
  await page.locator('#id_shortname').fill(courseData.shortname)

  // Wait for submit button to be visible before clicking
  // Note: Moodle 4.x uses #id_saveanddisplay instead of #id_submitbutton
  const submitButton = page.locator('#id_saveanddisplay, #id_submitbutton')
  await submitButton.first().waitFor({ state: 'visible' })
  await submitButton.first().click()
  await page.waitForLoadState('networkidle')

  const url = page.url()
  const match = url.match(/id=(\d+)/)
  return match ? match[1] : ''
}

/**
 * Add Skilland activity to a course
 * @param {import('@playwright/test').Page} page
 * @param {string} courseId
 * @param {object} activityData
 * @returns {Promise<string>} Activity ID
 */
async function addEdukmiActivity(page, courseId, activityData = testData.testActivity) {
  await page.goto(`/course/view.php?id=${courseId}`)
  await page.waitForLoadState('domcontentloaded')

  await page.locator('[data-action="setmode"]').click()

  const addActivityLink = page.locator('a.aalink.editing_section')
  if (await addActivityLink.count() > 0) {
    await addActivityLink.first().click()
  }

  const skillandOption = page.locator('div.option:has-text("Skilland")')
  if (await skillandOption.isVisible()) {
    await skillandOption.click()
  } else {
    const activityChooser = page.locator('[data-action="open-chooser"]').first()
    await activityChooser.click()
    await page.waitForLoadState('domcontentloaded')

    const skillandItem = page.locator('.activity:has-text("Skilland"), .optionname:has-text("Skilland")')
    await skillandItem.click()
  }

  await page.waitForLoadState('domcontentloaded')

  await page.locator('#id_name').fill(activityData.name)

  const introField = page.locator('#id_introeditor')
  if (await introField.isVisible()) {
    await introField.fill(activityData.intro)
  }

  // Note: Moodle 4.x uses #id_submitbutton2 for activity forms
  const submitButton = page.locator('#id_submitbutton2, #id_submitbutton')
  await submitButton.first().waitFor({ state: 'visible' })
  await submitButton.first().click()
  await page.waitForLoadState('networkidle')

  const url = page.url()
  const match = url.match(/id=(\d+)/)
  return match ? match[1] : ''
}

/**
 * Navigate to a Skilland activity
 * @param {import('@playwright/test').Page} page
 * @param {string} activityId
 */
async function goToEdukmiActivity(page, activityId) {
  await page.goto(`/mod/skilland/view.php?id=${activityId}`)
  await page.waitForLoadState('domcontentloaded')
}

/**
 * Get the current sesskey from Moodle page
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
async function getSesskey(page) {
  const sesskey = await page.evaluate(() => {
    // @ts-ignore
    return typeof M !== 'undefined' && M.cfg ? M.cfg.sesskey : null
  })
  return sesskey || ''
}

/**
 * Delete a course
 * @param {import('@playwright/test').Page} page
 * @param {string} courseId
 */
async function deleteCourse(page, courseId) {
  await page.goto(`/course/delete.php?id=${courseId}`)
  await page.waitForLoadState('domcontentloaded')

  const deleteButton = page.locator('button[type="submit"]:has-text("Delete")')
  if (await deleteButton.isVisible()) {
    await deleteButton.click()
    await page.waitForLoadState('networkidle')
  }
}

/**
 * Check if user is logged in
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>}
 */
async function isLoggedIn(page) {
  // Check for user menu (standard indicator on most pages)
  const userMenu = page.locator('.usermenu, #user-menu-toggle, .userbutton')
  if (await userMenu.count() > 0) {
    return true
  }

  // Check if on admin page (means admin is logged in)
  const currentUrl = page.url()
  if (currentUrl.includes('/admin/')) {
    return true
  }

  // Check for logout link as fallback
  const logoutLink = page.locator('a[href*="logout"]')
  if (await logoutLink.count() > 0) {
    return true
  }

  return false
}

/**
 * Get logged in user info
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{username: string, email: string} | null>}
 */
async function getLoggedInUser(page) {
  const userInfo = await page.evaluate(() => {
    // @ts-ignore
    if (typeof M !== 'undefined' && M.cfg) {
      return {
        // @ts-ignore
        username: M.cfg.username || '',
        // @ts-ignore
        email: M.cfg.useremail || ''
      }
    }
    return null
  })
  return userInfo
}

/**
 * Navigate to course edit/settings page
 * @param {import('@playwright/test').Page} page
 * @param {string} courseId
 */
async function goToCourseEditPage(page, courseId) {
  await page.goto(`/course/edit.php?id=${courseId}`)
  await page.waitForLoadState('domcontentloaded')
}

/**
 * Get the Skilland course dropdown element
 * Note: The field is inside the collapsed "Skilland content" section
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<import('@playwright/test').Locator>}
 */
function getSkillandDropdown(page) {
  // The dropdown uses Moodle's custom field system with this ID
  // There may be both a text input and select with same ID - we want the visible select
  return page.locator('select#id_customfield_skilland_course_id')
}

/**
 * Expand the Skilland content section on course edit page
 * @param {import('@playwright/test').Page} page
 */
async function expandSkillandSection(page) {
  // Find the collapsed section header
  const sectionHeader = page.locator('a:has-text("Skilland content"), [data-toggle="collapse"]:has-text("Skilland")')

  if (await sectionHeader.count() > 0) {
    // Check if section is already expanded by looking for visible dropdown
    const dropdown = page.locator('select#id_customfield_skilland_course_id')
    const isExpanded = await dropdown.isVisible().catch(() => false)

    if (!isExpanded) {
      await sectionHeader.click()
      await page.waitForTimeout(500)  // Wait for collapse animation
    }
  }
}

/**
 * Wait for Skilland dropdown to be populated with courses
 * @param {import('@playwright/test').Page} page
 * @param {number} timeout
 */
async function waitForSkillandDropdownLoaded(page, timeout = 10000) {
  // First expand the section if needed
  await expandSkillandSection(page)

  const dropdown = getSkillandDropdown(page)
  await dropdown.waitFor({ state: 'visible', timeout })

  // Wait for loading to complete (dropdown has options)
  // eslint-disable-next-line no-undef
  await page.waitForFunction(
    () => {
      const select = document.querySelector('select#id_customfield_skilland_course_id')
      if (!select) return false
      const options = select.querySelectorAll('option')
      // Has at least placeholder + one real option, and not in loading state
      return options.length >= 1 && !select.innerHTML.includes('Loading')
    },
    { timeout }
  )
}

/**
 * Select "Create New Skilland Course" option from dropdown
 * @param {import('@playwright/test').Page} page
 */
async function selectCreateNewCourse(page) {
  const dropdown = getSkillandDropdown(page)
  await dropdown.selectOption({ value: '__create_new__' })
}

/**
 * Select an existing course from the Skilland dropdown
 * @param {import('@playwright/test').Page} page
 * @param {string} courseId
 */
async function selectExistingCourse(page, courseId) {
  const dropdown = getSkillandDropdown(page)
  await dropdown.selectOption({ value: courseId })
}

/**
 * Wait for AJAX operations to complete
 * @param {import('@playwright/test').Page} page
 * @param {number} timeout
 */
async function waitForAjaxComplete(page, timeout = 10000) {
  await page.waitForLoadState('networkidle', { timeout })
}

/**
 * Get the "Edit in Skilland" button/link
 * @param {import('@playwright/test').Page} page
 * @returns {import('@playwright/test').Locator}
 */
function getEditInSkillandButton(page) {
  return page.locator('a:has-text("Edit in Skilland"), button:has-text("Edit in Skilland")')
}

/**
 * Check if a Moodle notification of a specific type exists
 * @param {import('@playwright/test').Page} page
 * @param {string} type - 'success' or 'error'
 * @param {string} [textContains] - Optional text to check for
 * @returns {Promise<boolean>}
 */
async function hasNotification(page, type, textContains) {
  const selector = type === 'success'
    ? '.alert-success, [data-type="success"]'
    : '.alert-danger, .alert-error, [data-type="error"]'

  const notification = page.locator(selector)
  if (await notification.count() === 0) return false

  if (textContains) {
    const text = await notification.textContent()
    return text?.includes(textContains) ?? false
  }

  return true
}

/**
 * Get the current selected value from Skilland dropdown
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
async function getSelectedSkillandCourse(page) {
  const dropdown = getSkillandDropdown(page)
  return await dropdown.inputValue()
}

module.exports = {
  goToSiteAdmin,
  goToPluginManagement,
  goToEdukmiSettings,
  isEdukmiPluginInstalled,
  createCourse,
  addEdukmiActivity,
  goToEdukmiActivity,
  getSesskey,
  deleteCourse,
  isLoggedIn,
  getLoggedInUser,
  goToCourseEditPage,
  getSkillandDropdown,
  expandSkillandSection,
  waitForSkillandDropdownLoaded,
  selectCreateNewCourse,
  selectExistingCourse,
  waitForAjaxComplete,
  getEditInSkillandButton,
  hasNotification,
  getSelectedSkillandCourse
}
