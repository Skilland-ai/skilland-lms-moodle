// @ts-check
const testData = require('../fixtures/test-data.json')

/**
 * Skilland-specific helper functions for E2E tests
 */

const SKILLAND_BASE_URL = process.env.SKILLAND_URL || testData.skilland.baseUrl
const SKILLAND_API_URL = process.env.SKILLAND_API_URL || testData.skilland.apiUrl

/**
 * Check if URL is a Skilland URL
 * @param {string} url
 * @returns {boolean}
 */
function isEdukmiUrl(url) {
  return url.includes(SKILLAND_BASE_URL) || url.includes('localhost:3000')
}

/**
 * Extract SSO token from URL
 * @param {string} url
 * @returns {string | null}
 */
function extractSsoToken(url) {
  const urlObj = new URL(url)
  return urlObj.searchParams.get('token')
}

/**
 * Extract redirect path from URL
 * @param {string} url
 * @returns {string | null}
 */
function extractRedirectPath(url) {
  const urlObj = new URL(url)
  return urlObj.searchParams.get('redirect')
}

/**
 * Validate JWT token structure
 * @param {string} token
 * @returns {boolean}
 */
function isValidJwtStructure(token) {
  if (!token) return false
  const parts = token.split('.')
  return parts.length === 3
}

/**
 * Decode JWT token payload (without verification)
 * @param {string} token
 * @returns {object | null}
 */
function decodeJwtPayload(token) {
  try {
    const parts = token.split('.')
    if (parts.length !== 3) return null

    const payload = parts[1]
    const decoded = Buffer.from(payload, 'base64').toString('utf-8')
    return JSON.parse(decoded)
  } catch {
    return null
  }
}

/**
 * Wait for Skilland page to load
 * @param {import('@playwright/test').Page} page
 */
async function waitForEdukmiLoad(page) {
  await page.waitForLoadState('networkidle')
  await page.waitForSelector('#app, #__nuxt, [data-testid="app-root"]', {
    state: 'attached',
    timeout: 30000
  }).catch(() => {})
}

/**
 * Check if user is authenticated in Skilland
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>}
 */
async function isAuthenticatedInEdukmi(page) {
  const authIndicators = [
    '[data-testid="user-menu"]',
    '.user-avatar',
    '[data-testid="logout-button"]'
  ]

  for (const selector of authIndicators) {
    const element = page.locator(selector)
    if (await element.count() > 0) {
      return true
    }
  }

  return false
}

/**
 * Check if we're on the Skills Studio page
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>}
 */
async function isOnSkillsStudio(page) {
  const url = page.url()
  return url.includes('/skills-studio')
}

/**
 * Check if we're on a topic page
 * @param {import('@playwright/test').Page} page
 * @param {string} [topicId]
 * @returns {Promise<boolean>}
 */
async function isOnTopicPage(page, topicId) {
  const url = page.url()
  if (topicId) {
    return url.includes(`/topics/${topicId}`)
  }
  return url.includes('/topics/')
}

/**
 * Extract skill ID from current URL
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string | null>}
 */
async function extractSkillIdFromUrl(page) {
  const url = page.url()
  const match = url.match(/\/skills-studio\/([^/]+)/)
  return match ? match[1] : null
}

/**
 * Extract topic ID from current URL
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string | null>}
 */
async function extractTopicIdFromUrl(page) {
  const url = page.url()
  const match = url.match(/\/topics\/([^/]+)/)
  return match ? match[1] : null
}

/**
 * Make API call to Skilland
 * @param {string} endpoint
 * @param {object} options
 * @returns {Promise<Response>}
 */
async function skillandApiCall(endpoint, options = {}) {
  const url = `${SKILLAND_API_URL}${endpoint}`
  return fetch(url, {
    headers: {
      'Content-Type': 'application/json',
      ...options.headers
    },
    ...options
  })
}

/**
 * Verify SSO token with Skilland API
 * @param {string} token
 * @returns {Promise<{valid: boolean, user?: object, error?: string}>}
 */
async function verifySsoToken(token) {
  try {
    const response = await skillandApiCall('/auth/sso/verify', {
      method: 'POST',
      body: JSON.stringify({ token })
    })

    if (response.ok) {
      const data = await response.json()
      return { valid: true, user: data.user }
    }

    return { valid: false, error: 'Token verification failed' }
  } catch (error) {
    return { valid: false, error: String(error) }
  }
}

module.exports = {
  SKILLAND_BASE_URL,
  SKILLAND_API_URL,
  isEdukmiUrl,
  extractSsoToken,
  extractRedirectPath,
  isValidJwtStructure,
  decodeJwtPayload,
  waitForEdukmiLoad,
  isAuthenticatedInEdukmi,
  isOnSkillsStudio,
  isOnTopicPage,
  extractSkillIdFromUrl,
  extractTopicIdFromUrl,
  skillandApiCall,
  verifySsoToken
}
