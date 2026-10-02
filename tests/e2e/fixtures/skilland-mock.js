// @ts-check
const {
  isSkillandMethod,
  parseBatch,
  resolveBatch,
  coreRequestBody,
  mergeResponses
} = require('./skilland-batch')
const skillandData = require('./skilland-data')

/**
 * Skilland mock for the E2E suite.
 *
 * The suite never talks to a Skilland backend. Every mod_skilland_* call that the
 * browser sends through Moodle's AJAX endpoint is answered here from a per-test
 * handler map, and every browser navigation to a Skilland origin (the SSO form POST,
 * "Edit in Skilland" tabs) lands on a stub page.
 *
 * Fail-loud guards, checked when the test ends:
 * - a mod_skilland_* call without a handler fails the test (it is also answered
 *   with an error so the page does not hang);
 * - a console `error` or an uncaught page error fails the test unless it matches
 *   a pattern registered with `expectConsoleError(regex)` in that test.
 */

/** Origins answered with the stub page. The plugin's production default is included so a
 * test can never reach the real app by accident. */
const DEFAULT_STUB_ORIGINS = [skillandData.SKILLAND_URL, 'https://app.skilland.ai']

const STUB_HTML = '<!doctype html><html><head><meta charset="utf-8"><title>Skilland stub</title></head>' +
  '<body><main id="skilland-stub">Skilland stub page</main></body></html>'

/**
 * mod_skilland_fetch_courses_ajax runs on every course form (including "Add a new
 * course"), so it gets a default answer. Every other method must be mocked by the test.
 */
const DEFAULT_HANDLERS = {
  mod_skilland_fetch_courses_ajax: () => skillandData.courses()
}

/**
 * @typedef {{ method: string, url: string, form: Record<string, string> }} SsoRequest
 */

/**
 * @param {string} url
 * @returns {string}
 */
function originOf(url) {
  return new URL(url).origin
}

/**
 * @param {import('@playwright/test').BrowserContext} context
 */
async function installSkillandMock(context) {
  /** @type {Map<string, import('./skilland-batch').Handler>} */
  const handlers = new Map()
  /** @type {Array<{ methodname: string, args: Record<string, any> }>} */
  const sentCalls = []
  /** @type {string[]} */
  const unmocked = []
  /** @type {string[]} */
  const stubNavigations = []
  /** @type {SsoRequest[]} */
  const ssoRequests = []
  /** @type {string[]} */
  const pageErrors = []
  /** @type {RegExp[]} */
  const expectedErrors = []
  const stubOrigins = new Set(DEFAULT_STUB_ORIGINS.map(originOf))

  for (const [method, value] of Object.entries(DEFAULT_HANDLERS)) {
    handlers.set(method, { type: 'data', value })
  }

  /** @param {import('@playwright/test').Page} page */
  const watchPage = page => {
    page.on('console', message => {
      if (message.type() === 'error') {
        pageErrors.push(`console.error on ${page.url()}: ${message.text()}`)
      }
    })
    page.on('pageerror', error => {
      pageErrors.push(`uncaught error on ${page.url()}: ${error.message}`)
    })
  }
  context.pages().forEach(watchPage)
  context.on('page', watchPage)

  await context.route('**/lib/ajax/service.php**', async route => {
    const request = route.request()
    const body = request.postData() ?? new URL(request.url()).searchParams.get('args')

    let entries
    try {
      entries = parseBatch(body || '[]')
    } catch {
      await route.continue()
      return
    }

    for (const entry of entries) {
      if (isSkillandMethod(entry.methodname)) {
        sentCalls.push({ methodname: entry.methodname, args: entry.args })
      }
    }

    const plan = await resolveBatch(entries, handlers, entry => unmocked.push(entry.methodname))
    if (plan.kind === 'passthrough') {
      await route.continue()
      return
    }
    if (plan.kind === 'abort') {
      await route.abort('failed')
      return
    }

    let coreResponses
    if (plan.core.length > 0) {
      const response = await route.fetch({ postData: coreRequestBody(plan.core) })
      coreResponses = await response.json()
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mergeResponses(entries, plan.answers, coreResponses))
    })
  })

  // sso_redirect.php answers with a self-submitting form that POSTs the token to
  // Skilland's /sso-login (SKL-687), so that navigation reaches the stub route below.
  await context.route(url => stubOrigins.has(url.origin), async route => {
    const request = route.request()
    if (request.isNavigationRequest()) {
      stubNavigations.push(request.url())
      if (new URL(request.url()).pathname === '/sso-login') {
        ssoRequests.push({
          method: request.method(),
          url: request.url(),
          form: Object.fromEntries(new URLSearchParams(request.postData() || ''))
        })
      }
    }
    await route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: STUB_HTML })
  })

  const mock = {
    /**
     * Answer `method` with `dataOrFn`, or with what `dataOrFn(args, entry)` returns.
     * @param {string} method
     * @param {any} dataOrFn
     */
    on(method, dataOrFn) {
      handlers.set(method, { type: 'data', value: dataOrFn })
      return mock
    },

    /**
     * Make `method` fail the way Moodle reports a web service exception.
     * @param {string} method
     * @param {string} message
     */
    fail(method, message) {
      handlers.set(method, { type: 'fail', message })
      return mock
    },

    /**
     * Make any batch containing `method` fail at the network level. Chrome logs the
     * aborted request as a console error, so that one message is expected.
     * @param {string} method
     */
    abort(method) {
      handlers.set(method, { type: 'abort' })
      expectedErrors.push(/net::ERR_FAILED/)
      return mock
    },

    /**
     * Args of every call sent for `method` so far (all Skilland calls when omitted).
     * @param {string} [method]
     * @returns {Array<Record<string, any>>}
     */
    calls(method) {
      return sentCalls
        .filter(call => !method || call.methodname === method)
        .map(call => call.args)
    },

    /**
     * URLs of every navigation that landed on the Skilland stub page.
     * @returns {string[]}
     */
    navigations() {
      return [...stubNavigations]
    },

    /**
     * Every navigation to Skilland's /sso-login: method, URL and the form fields it posted.
     * @returns {SsoRequest[]}
     */
    ssoRequests() {
      return ssoRequests.map(request => ({ ...request, form: { ...request.form } }))
    },

    /**
     * Also answer navigations to `url`'s origin with the stub page.
     * @param {string} url
     */
    stubOrigin(url) {
      stubOrigins.add(originOf(url))
      return mock
    },

    /**
     * Allow console errors or uncaught page errors matching `pattern` in this test.
     * @param {RegExp} pattern
     */
    expectConsoleError(pattern) {
      expectedErrors.push(pattern)
      return mock
    },

    /**
     * Throw when an unmocked Skilland call or an unexpected page error happened.
     */
    assertClean() {
      const problems = []
      if (unmocked.length > 0) {
        problems.push(`Unmocked Skilland AJAX calls: ${[...new Set(unmocked)].join(', ')}. ` +
          'Register them with skillandMock.on(method, data).')
      }
      const unexpected = pageErrors.filter(text => !expectedErrors.some(pattern => pattern.test(text)))
      if (unexpected.length > 0) {
        problems.push('Unexpected browser errors (allow with expectConsoleError(regex)):\n  ' +
          unexpected.join('\n  '))
      }
      if (problems.length > 0) {
        throw new Error(problems.join('\n'))
      }
    }
  }

  return mock
}

module.exports = {
  installSkillandMock,
  DEFAULT_STUB_ORIGINS
}
