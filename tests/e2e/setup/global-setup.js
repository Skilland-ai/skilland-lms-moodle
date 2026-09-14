// @ts-check
const { execSync } = require('child_process')

/**
 * Global setup for Playwright tests
 * Verifies Docker services are running before tests start
 */
async function globalSetup() {
  const moodleUrl = process.env.MOODLE_URL || 'http://localhost:8081'
  const skillandUrl = process.env.SKILLAND_URL || 'http://localhost:3000'

  console.log('\n=== Moodle-Skilland E2E Test Setup ===\n')

  console.log('Checking Docker services...')
  try {
    execSync('docker compose ps --format json', {
      cwd: process.cwd().replace('/moodle/tests/e2e', ''),
      encoding: 'utf-8'
    })
    console.log('Docker services status retrieved')
  } catch {
    console.warn('Warning: Could not check Docker services status')
  }

  console.log(`\nChecking Moodle availability at ${moodleUrl}...`)
  const moodleReady = await waitForService(moodleUrl, 'Moodle', 60)

  if (!moodleReady) {
    console.error('\nMoodle is not available. Please ensure Docker services are running:')
    console.error('  cd skillandUniverse && docker compose up -d')
    throw new Error('Moodle service not available')
  }

  console.log(`\nChecking Skilland availability at ${skillandUrl}...`)
  const skillandReady = await waitForService(skillandUrl, 'Skilland', 30)

  if (!skillandReady) {
    console.warn('\nWarning: Skilland is not available. SSO tests may fail.')
    console.warn('To start Skilland: cd skillandUniverse && docker compose up skilland-back skilland-front -d')
  }

  console.log('\n=== Setup Complete ===\n')
}

/**
 * Wait for a service to be available
 * @param {string} url
 * @param {string} serviceName
 * @param {number} timeoutSeconds
 * @returns {Promise<boolean>}
 */
async function waitForService(url, serviceName, timeoutSeconds = 30) {
  const startTime = Date.now()
  const timeout = timeoutSeconds * 1000

  while (Date.now() - startTime < timeout) {
    try {
      const controller = new AbortController()
      const timeoutId = setTimeout(() => controller.abort(), 5000)

      const response = await fetch(url, {
        method: 'GET',
        signal: controller.signal
      })

      clearTimeout(timeoutId)

      if (response.ok || response.status === 302 || response.status === 303) {
        console.log(`  ${serviceName} is ready (status: ${response.status})`)
        return true
      }
    } catch {
      // Service not ready yet
    }

    await sleep(2000)
    process.stdout.write('.')
  }

  console.log(`\n  ${serviceName} not available after ${timeoutSeconds}s`)
  return false
}

/**
 * Sleep for specified milliseconds
 * @param {number} ms
 */
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms))
}

module.exports = globalSetup
