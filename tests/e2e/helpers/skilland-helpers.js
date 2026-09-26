// @ts-check
const { SKILLAND_URL } = require('../fixtures/skilland-data')

/**
 * SkilLand-side helpers. The suite never reaches a SkilLand backend: navigations to a
 * SkilLand origin land on the stub page served by fixtures/skilland-mock.js.
 */

const SKILLAND_BASE_URL = SKILLAND_URL

/**
 * Check if URL is a Skilland URL
 * @param {string} url
 * @param {string} [baseUrl]
 * @returns {boolean}
 */
function isSkillandUrl(url, baseUrl = SKILLAND_BASE_URL) {
  return new URL(url).origin === new URL(baseUrl).origin
}

/**
 * SSO token from the form body Moodle POSTed to /sso-login (skillandMock.ssoRequests()).
 * @param {import('../fixtures/skilland-mock').SsoRequest | undefined} request
 * @returns {string | null}
 */
function extractSsoToken(request) {
  return request?.form.token ?? null
}

/**
 * Redirect path from the form body Moodle POSTed to /sso-login (skillandMock.ssoRequests()).
 * @param {import('../fixtures/skilland-mock').SsoRequest | undefined} request
 * @returns {string | null}
 */
function extractRedirectPath(request) {
  return request?.form.redirect ?? null
}

/**
 * Validate JWT token structure
 * @param {string | null} token
 * @returns {boolean}
 */
function isValidJwtStructure(token) {
  if (!token) return false
  return token.split('.').length === 3
}

/**
 * Decode JWT token payload (without verification)
 * @param {string} token
 * @returns {Record<string, any> | null}
 */
function decodeJwtPayload(token) {
  try {
    const parts = token.split('.')
    if (parts.length !== 3) return null
    return JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf-8'))
  } catch {
    return null
  }
}

module.exports = {
  SKILLAND_BASE_URL,
  isSkillandUrl,
  extractSsoToken,
  extractRedirectPath,
  isValidJwtStructure,
  decodeJwtPayload
}
