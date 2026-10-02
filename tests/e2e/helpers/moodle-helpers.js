// @ts-check
const { expect } = require('@playwright/test')
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
}

/**
 * Navigate to plugin management page
 * @param {import('@playwright/test').Page} page
 */
async function goToPluginManagement(page) {
  await page.goto('/admin/plugins.php')
}

/**
 * Navigate to Skilland plugin settings
 * @param {import('@playwright/test').Page} page
 */
async function goToSkillandSettings(page) {
  await page.goto('/admin/settings.php?section=modsettingskilland')
}

/**
 * Make sure the plugin can sign SSO tokens: set an organization id and, unless
 * config.php forces one, an SSO secret. Returns the URL SSO redirects to (the Frontend URL, else the Skilland URL),
 * so the caller can stub that origin.
 * @param {import('@playwright/test').Page} page Admin page
 * @returns {Promise<{ orgId: string, frontendUrl: string }>}
 */
async function configureSkillandSso(page) {
  await goToSkillandSettings(page)

  const orgInput = page.locator('#id_s_mod_skilland_orgid')
  const secretInput = page.locator('input#id_s_mod_skilland_sso_secret')
  const frontendInput = page.locator('input#id_s_mod_skilland_frontend_url')
  await expect(orgInput).toBeAttached()

  let changed = false
  if (!await orgInput.inputValue()) {
    await orgInput.fill('e2e-org')
    changed = true
  }
  if (await secretInput.count() > 0 && await secretInput.isEnabled() && !await secretInput.inputValue()) {
    const secret = Buffer.from(Array.from({ length: 48 }, () => Math.floor(Math.random() * 256))).toString('base64')
    await secretInput.evaluate((input, value) => { /** @type {HTMLInputElement} */ (input).value = value }, secret)
    changed = true
  }
  if (changed) {
    await page.locator('#adminsettings button[type="submit"]').first().click()
    await expect(page.locator('.alert-success').first()).toBeVisible()
  }

  const orgId = await orgInput.inputValue()
  const skillandUrlInput = page.locator('input#id_s_mod_skilland_graphql_endpoint')
  // An empty Frontend URL means the Skilland URL (SKL-991).
  const frontendUrl = (await frontendInput.count() > 0 && await frontendInput.inputValue()) ||
    (await skillandUrlInput.count() > 0 && await skillandUrlInput.inputValue()) ||
    'https://app.skilland.ai'
  return { orgId, frontendUrl }
}

/**
 * Check if Skilland plugin is installed
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>}
 */
async function isSkillandPluginInstalled(page) {
  await goToPluginManagement(page)

  const pluginRow = page.locator('tr:has-text("skilland")')
  return await pluginRow.count() > 0
}

/**
 * Create a new course in Moodle. Throws when Moodle does not land on the new course.
 * The course form triggers mod_skilland_fetch_courses_ajax, which the Skilland mock answers.
 * @param {import('@playwright/test').Page} page
 * @param {{ fullname: string, shortname: string }} courseData
 * @returns {Promise<string>} Course ID
 */
async function createCourse(page, courseData = testData.testCourse) {
  await page.goto('/course/edit.php?category=1')

  await expect(page.locator('#id_fullname')).toBeVisible()
  await page.locator('#id_fullname').fill(courseData.fullname)
  await page.locator('#id_shortname').fill(courseData.shortname)

  // Moodle 4.x uses #id_saveanddisplay instead of #id_submitbutton
  await page.locator('#id_saveanddisplay, #id_submitbutton').first().click()
  await page.waitForURL(/\/course\/view\.php\?id=\d+/)

  const match = page.url().match(/[?&]id=(\d+)/)
  if (!match) {
    throw new Error(`Course "${courseData.shortname}" was not created (landed on ${page.url()})`)
  }
  return match[1]
}

/**
 * Link a Moodle course to a Skilland skill through the course form dropdown.
 * `skillId` must be one of the courses the Skilland mock returns for
 * mod_skilland_fetch_courses_ajax.
 * @param {import('@playwright/test').Page} page
 * @param {string} courseId
 * @param {string} skillId
 */
async function linkCourseToSkill(page, courseId, skillId) {
  await goToCourseEditPage(page, courseId)
  await waitForSkillandDropdownLoaded(page)
  await selectExistingCourse(page, skillId)
  await saveCourseForm(page)
}

/**
 * Enrol an existing user in a course through the manual enrolment page.
 * @param {import('@playwright/test').Page} page Admin page
 * @param {string} courseId
 * @param {string} fullname The user's full name as Moodle lists it
 * @param {string} [roleLabel] Role name in the "Assign roles" select
 */
async function enrolUser(page, courseId, fullname, roleLabel = 'Teacher') {
  await page.goto(`/enrol/instances.php?id=${courseId}`)
  const manageUrl = await page.locator('a[href*="/enrol/manual/manage.php?enrolid="]').first().getAttribute('href')
  if (!manageUrl) {
    throw new Error(`Course ${courseId} has no manual enrolment instance`)
  }
  await page.goto(manageUrl)

  const candidate = page.locator('#addselect option', { hasText: fullname }).first()
  const userId = await candidate.getAttribute('value')
  if (!userId) {
    throw new Error(`User "${fullname}" is not available for enrolment in course ${courseId}`)
  }
  await page.locator('#addselect').selectOption(userId)
  await page.locator('#menuroleid').selectOption({ label: roleLabel })
  await page.locator('#add').click()
  await expect(page.locator(`#removeselect option[value="${userId}"]`)).toBeAttached()
}

/**
 * Submit the course settings form and wait for the course page.
 * @param {import('@playwright/test').Page} page
 */
async function saveCourseForm(page) {
  await page.locator('#id_saveanddisplay, #id_submitbutton').first().click()
  await page.waitForURL(/\/course\/view\.php\?id=\d+/)
}

/**
 * Add Skilland activity to a course
 * @param {import('@playwright/test').Page} page
 * @param {string} courseId
 */
async function goToAddSkillandActivity(page, courseId) {
  await page.goto(`/course/modedit.php?add=skilland&type=&course=${courseId}&section=0&return=0&sr=0`)
}

/**
 * Navigate to a Skilland activity
 * @param {import('@playwright/test').Page} page
 * @param {string} activityId
 */
async function goToSkillandActivity(page, activityId) {
  await page.goto(`/mod/skilland/view.php?id=${activityId}`)
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
  if (!sesskey) {
    throw new Error(`No Moodle sesskey on ${page.url()}`)
  }
  return sesskey
}

/**
 * Delete a course
 * @param {import('@playwright/test').Page} page
 * @param {string} courseId
 */
async function deleteCourse(page, courseId) {
  await page.goto(`/course/delete.php?id=${courseId}`)

  const deleteButton = page.locator('button[type="submit"]:has-text("Delete"), input[type="submit"][value="Delete"]')
  await deleteButton.first().click()
  await expect(page.locator('#region-main')).toContainText(/has been completely deleted|deleted/i)
}

/**
 * Check if user is logged in
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>}
 */
async function isLoggedIn(page) {
  if (new URL(page.url()).pathname.includes('/admin/')) {
    return true
  }
  return await page.locator('#user-menu-toggle').isVisible()
}

/**
 * Navigate to course edit/settings page
 * @param {import('@playwright/test').Page} page
 * @param {string} courseId
 */
async function goToCourseEditPage(page, courseId) {
  await page.goto(`/course/edit.php?id=${courseId}`)
}

/**
 * Get the Skilland course dropdown element
 * The plugin replaces the custom field text input with a select that has the same id.
 * @param {import('@playwright/test').Page} page
 * @returns {import('@playwright/test').Locator}
 */
function getSkillandDropdown(page) {
  return page.locator('select#id_customfield_skilland_course_id')
}

/**
 * The original custom field text input (shown again, enabled and under its own id, when the
 * course list fails to load; while the dropdown is up it is disabled as `#<id>_raw`).
 * @param {import('@playwright/test').Page} page
 * @returns {import('@playwright/test').Locator}
 */
function getSkillandCourseIdInput(page) {
  return page.locator('input#id_customfield_skilland_course_id')
}

/**
 * Expand the collapsible form section (fieldset) that contains `selector`.
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 */
async function expandFormSection(page, selector) {
  const fieldset = page.locator(`fieldset:has(${selector})`).last()
  await expect(fieldset).toBeAttached()

  const toggle = fieldset.locator('a[data-toggle="collapse"], a[data-bs-toggle="collapse"]').first()
  if (await toggle.count() > 0 && await toggle.getAttribute('aria-expanded') === 'false') {
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-expanded', 'true')
  }
}

/**
 * Expand the collapsible form section holding the Skilland course field.
 * @param {import('@playwright/test').Page} page
 */
async function expandSkillandSection(page) {
  await expandFormSection(page, '#id_customfield_skilland_course_id')
}

/**
 * Wait for the Skilland dropdown to leave its loading state.
 * @param {import('@playwright/test').Page} page
 */
async function waitForSkillandDropdownLoaded(page) {
  await expandSkillandSection(page)

  const dropdown = getSkillandDropdown(page)
  await expect(dropdown).toBeVisible()
  await expect(dropdown.locator('option').first()).not.toHaveText(/Loading/)
}

/**
 * The "Create in Skilland" button placed after the dropdown.
 * @param {import('@playwright/test').Page} page
 * @returns {import('@playwright/test').Locator}
 */
function getCreateCourseButton(page) {
  return page.locator('#skilland-create-course-btn')
}

/**
 * Click "Create in Skilland" and answer the confirmation dialog. Confirming creates the
 * Skilland course right away (and links it server-side); cancelling sends no request.
 * @param {import('@playwright/test').Page} page
 * @param {{ confirm?: boolean }} [options]
 */
async function selectCreateNewCourse(page, options = {}) {
  const confirm = options.confirm !== false
  await getCreateCourseButton(page).click()
  const dialog = page.locator('.modal.show .modal-dialog')
  await expect(dialog).toBeVisible()
  await dialog.locator(confirm ? '[data-action="save"]' : '[data-action="cancel"]').click()
  await expect(dialog).toBeHidden()
}

/**
 * Select an existing course from the Skilland dropdown
 * @param {import('@playwright/test').Page} page
 * @param {string} courseId
 */
async function selectExistingCourse(page, courseId) {
  await getSkillandDropdown(page).selectOption({ value: courseId })
}

/**
 * Get the "Edit in Skilland" button/link
 * @param {import('@playwright/test').Page} page
 * @returns {import('@playwright/test').Locator}
 */
function getEditInSkillandButton(page) {
  return page.locator('#skilland-edit-link')
}

/**
 * Moodle notification locator of a given type
 * @param {import('@playwright/test').Page} page
 * @param {'success' | 'error'} type
 * @returns {import('@playwright/test').Locator}
 */
function notification(page, type) {
  return page.locator(type === 'success'
    ? '.alert-success, [data-type="success"]'
    : '.alert-danger, .alert-error, [data-type="error"]')
}

/**
 * Get the current selected value from Skilland dropdown
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
async function getSelectedSkillandCourse(page) {
  return await getSkillandDropdown(page).inputValue()
}

/**
 * Wait until the activity form's topic select has loaded topics.
 * @param {import('@playwright/test').Page} page
 * @param {string} topicId A topic the mock returns
 */
async function waitForTopicsLoaded(page, topicId) {
  await expect(page.locator(`select#id_skilland_topicid option[value="${topicId}"]`)).toHaveCount(1)
  await expect(page.locator('select#id_skilland_topicid')).toBeEnabled()
}

module.exports = {
  goToSiteAdmin,
  goToPluginManagement,
  goToSkillandSettings,
  configureSkillandSso,
  isSkillandPluginInstalled,
  createCourse,
  linkCourseToSkill,
  enrolUser,
  saveCourseForm,
  goToAddSkillandActivity,
  goToSkillandActivity,
  getSesskey,
  deleteCourse,
  isLoggedIn,
  goToCourseEditPage,
  getSkillandDropdown,
  getSkillandCourseIdInput,
  expandFormSection,
  expandSkillandSection,
  waitForSkillandDropdownLoaded,
  getCreateCourseButton,
  selectCreateNewCourse,
  selectExistingCourse,
  getEditInSkillandButton,
  notification,
  getSelectedSkillandCourse,
  waitForTopicsLoaded
}
