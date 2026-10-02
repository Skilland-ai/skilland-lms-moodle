// @ts-check

/**
 * Pure logic behind the Skilland AJAX mock: parse a Moodle AJAX batch, answer its
 * mod_skilland_* entries from a handler map, and merge those answers with Moodle's
 * own responses for the core entries of the same batch.
 *
 * Moodle's core/ajax posts `[{index, methodname, args}, ...]` to
 * /lib/ajax/service.php and expects an array aligned by position, where each item
 * is `{error: false, data}` or `{error: true, exception: {message, errorcode}}`.
 * The server stops at the first failing call, so nothing follows an error.
 *
 * No Playwright import here, so `node --test tests/e2e/unit/*.test.js` can exercise it.
 */

const SKILLAND_METHOD_PREFIX = 'mod_skilland_'

/**
 * @typedef {{ position: number, index: number, methodname: string, args: Record<string, any> }} BatchEntry
 * @typedef {{ error: false, data: any } | { error: true, exception: { message: string, errorcode: string, exception: string } }} BatchResponse
 * @typedef {{ type: 'data', value: any } | { type: 'fail', message: string } | { type: 'abort' }} Handler
 */

/**
 * @param {unknown} methodname
 * @returns {boolean}
 */
function isSkillandMethod(methodname) {
  return typeof methodname === 'string' && methodname.startsWith(SKILLAND_METHOD_PREFIX)
}

/**
 * @param {string | unknown[]} body Raw JSON body or an already parsed array
 * @returns {BatchEntry[]}
 */
function parseBatch(body) {
  const parsed = typeof body === 'string' ? JSON.parse(body) : body
  if (!Array.isArray(parsed)) {
    throw new Error('Moodle AJAX batch must be a JSON array')
  }
  return parsed.map((entry, position) => ({
    position,
    index: typeof entry.index === 'number' ? entry.index : position,
    methodname: entry.methodname,
    args: entry.args || {}
  }))
}

/**
 * @param {any} data
 * @returns {BatchResponse}
 */
function success(data) {
  return { error: false, data }
}

/**
 * @param {string} message
 * @param {string} [errorcode]
 * @returns {BatchResponse}
 */
function failure(message, errorcode = 'skillandmock') {
  return { error: true, exception: { message, errorcode, exception: 'moodle_exception' } }
}

/**
 * Decide how to answer a batch.
 *
 * - no Skilland entry: `passthrough` (the request goes to Moodle untouched)
 * - a Skilland entry whose handler is `abort`: `abort` (the whole request fails)
 * - otherwise `answer`: one response per Skilland entry, keyed by position, plus the
 *   core entries that still have to be sent to Moodle
 *
 * @param {BatchEntry[]} entries
 * @param {Map<string, Handler>} handlers
 * @param {(entry: BatchEntry) => void} onUnhandled Called for each Skilland entry without a handler
 * @returns {Promise<{ kind: 'passthrough' } | { kind: 'abort', methodname: string } | { kind: 'answer', answers: Map<number, BatchResponse>, core: BatchEntry[] }>}
 */
async function resolveBatch(entries, handlers, onUnhandled) {
  const skilland = entries.filter(entry => isSkillandMethod(entry.methodname))
  if (skilland.length === 0) {
    return { kind: 'passthrough' }
  }

  const aborted = skilland.find(entry => handlers.get(entry.methodname)?.type === 'abort')
  if (aborted) {
    return { kind: 'abort', methodname: aborted.methodname }
  }

  /** @type {Map<number, BatchResponse>} */
  const answers = new Map()
  for (const entry of skilland) {
    const handler = handlers.get(entry.methodname)
    if (!handler) {
      onUnhandled(entry)
      answers.set(entry.position, failure(`Unmocked Skilland AJAX call: ${entry.methodname}`))
    } else if (handler.type === 'fail') {
      answers.set(entry.position, failure(handler.message))
    } else if (handler.type === 'data') {
      try {
        const data = typeof handler.value === 'function'
          ? await handler.value(entry.args, entry)
          : handler.value
        answers.set(entry.position, success(data))
      } catch (error) {
        answers.set(entry.position, failure(error instanceof Error ? error.message : String(error)))
      }
    }
  }

  return {
    kind: 'answer',
    answers,
    core: entries.filter(entry => !isSkillandMethod(entry.methodname))
  }
}

/**
 * Body for the core-only sub-batch forwarded to Moodle, re-indexed from 0.
 * @param {BatchEntry[]} core
 * @returns {string}
 */
function coreRequestBody(core) {
  return JSON.stringify(core.map((entry, index) => ({
    index,
    methodname: entry.methodname,
    args: entry.args
  })))
}

/**
 * Put Skilland answers and Moodle's core responses back in batch order.
 * A whole-request error from Moodle (an object, not an array) is returned as is.
 *
 * @param {BatchEntry[]} entries The original batch
 * @param {Map<number, BatchResponse>} answers Skilland answers keyed by position
 * @param {unknown} coreResponses Moodle's response to `coreRequestBody(core)`
 * @returns {unknown}
 */
function mergeResponses(entries, answers, coreResponses) {
  if (coreResponses !== undefined && !Array.isArray(coreResponses)) {
    return coreResponses
  }
  const core = Array.isArray(coreResponses) ? coreResponses : []

  /** @type {unknown[]} */
  const merged = []
  let next = 0
  for (const entry of entries) {
    const response = isSkillandMethod(entry.methodname) ? answers.get(entry.position) : core[next++]
    if (response === undefined) {
      break
    }
    merged.push(response)
    if (/** @type {{ error?: unknown }} */ (response).error) {
      break
    }
  }
  return merged
}

module.exports = {
  SKILLAND_METHOD_PREFIX,
  isSkillandMethod,
  parseBatch,
  success,
  failure,
  resolveBatch,
  coreRequestBody,
  mergeResponses
}
