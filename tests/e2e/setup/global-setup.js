// @ts-check

/**
 * Global setup for Playwright tests.
 *
 * The suite only needs a running Moodle with the plugin installed: SkilLand is
 * always mocked in the browser (see fixtures/skilland-mock.js), so nothing here
 * probes a SkilLand backend.
 */
async function globalSetup() {
  const moodleUrl = process.env.MOODLE_URL || 'http://localhost:8081'

  console.log(`\nChecking Moodle availability at ${moodleUrl}...`)
  const moodleReady = await waitForService(`${moodleUrl}/login/index.php`, 60)

  if (!moodleReady) {
    throw new Error(
      `Moodle is not reachable at ${moodleUrl}. Start it (see docs/development.md, "E2E tests") ` +
      'or point MOODLE_URL at a running instance.'
    )
  }
}

/**
 * Poll `url` until it answers with a non-error status.
 * @param {string} url
 * @param {number} timeoutSeconds
 * @returns {Promise<boolean>}
 */
async function waitForService(url, timeoutSeconds) {
  const deadline = Date.now() + timeoutSeconds * 1000

  while (Date.now() < deadline) {
    try {
      const response = await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(5000) })
      if (response.status < 400) {
        console.log(`  Moodle is ready (status: ${response.status})`)
        return true
      }
    } catch {
      // Not ready yet.
    }
    await new Promise(resolve => setTimeout(resolve, 2000))
  }

  return false
}

module.exports = globalSetup
