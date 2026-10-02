// @ts-check
const { expect } = require('@playwright/test')
const { test } = require('../fixtures/auth')
const {
  goToAddSkillandActivity,
  waitForTopicsLoaded,
  expandFormSection
} = require('../helpers/moodle-helpers')
const skillandData = require('../fixtures/skilland-data')

/**
 * Test suite: Auto-update content
 *
 * The "Auto-update content" setting of a Skilland activity and the scheduled task
 * that acts on it. Detecting a changed Skilland snapshot and re-provisioning the
 * SCORM package run server-side against Skilland's REST API, so they are
 * covered by PHPUnit (tests/phpunit/task_sync_content_test.php and
 * tests/phpunit/locallib_provision_scorm_test.php), not here.
 */
test.describe('Auto-update content', () => {
  test.describe.configure({ mode: 'parallel' })

  /**
   * Open the add-activity form of a Skilland-linked course with its Behaviour section expanded.
   * @param {import('@playwright/test').Page} page
   * @param {any} skillandMock
   * @param {{ create: (options?: object) => Promise<string> }} moodleCourse
   */
  async function openActivityForm(page, skillandMock, moodleCourse) {
    const courseId = await moodleCourse.create({ skillId: skillandData.SKILL_ID })
    skillandMock.on('mod_skilland_fetch_topics_ajax', skillandData.topics())

    await goToAddSkillandActivity(page, courseId)
    await waitForTopicsLoaded(page, skillandData.TOPIC_ID)
    await expandFormSection(page, '#id_autoupdate')
    return page.locator('#id_autoupdate')
  }

  test('Activity form shows the autoupdate checkbox, unchecked by default', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const autoupdate = await openActivityForm(authenticatedPage, skillandMock, moodleCourse)

    await expect(autoupdate).toBeVisible()
    await expect(autoupdate).not.toBeChecked()
  })

  test('Autoupdate checkbox can be toggled on', async ({
    authenticatedPage,
    skillandMock,
    moodleCourse
  }) => {
    const autoupdate = await openActivityForm(authenticatedPage, skillandMock, moodleCourse)

    await autoupdate.check()
    await expect(autoupdate).toBeChecked()
  })

  test('The sync_content scheduled task is registered', async ({ authenticatedPage }) => {
    await authenticatedPage.goto('/admin/tool/task/scheduledtasks.php')

    await expect(authenticatedPage.locator('#region-main')).toContainText('\\mod_skilland\\task\\sync_content')
  })
})
