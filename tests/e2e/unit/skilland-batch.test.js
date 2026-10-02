// @ts-check
const { test } = require('node:test')
const assert = require('node:assert/strict')
const {
  parseBatch,
  resolveBatch,
  coreRequestBody,
  mergeResponses,
  success,
  failure
} = require('../fixtures/skilland-batch')

/**
 * Unit tests for the pure part of the Skilland AJAX mock.
 * Run with: node --test tests/e2e/unit/*.test.js
 */

const body = JSON.stringify([
  { index: 0, methodname: 'core_fetch_notifications', args: { contextid: 1 } },
  { index: 1, methodname: 'mod_skilland_fetch_courses_ajax', args: { moodlecourseid: 7 } },
  { index: 2, methodname: 'core_get_string', args: { stringid: 'yes' } }
])

test('parseBatch keeps position, index, method and args', () => {
  const entries = parseBatch(body)
  assert.equal(entries.length, 3)
  assert.deepEqual(entries[1], {
    position: 1,
    index: 1,
    methodname: 'mod_skilland_fetch_courses_ajax',
    args: { moodlecourseid: 7 }
  })
})

test('parseBatch rejects a body that is not an array', () => {
  assert.throws(() => parseBatch('{"methodname":"x"}'), /must be a JSON array/)
})

test('a core-only batch passes through', async () => {
  const entries = parseBatch([{ index: 0, methodname: 'core_get_string', args: {} }])
  assert.deepEqual(await resolveBatch(entries, new Map(), () => {}), { kind: 'passthrough' })
})

test('Skilland entries are answered and core entries are forwarded re-indexed', async () => {
  const entries = parseBatch(body)
  const handlers = new Map([['mod_skilland_fetch_courses_ajax', {
    type: /** @type {const} */ ('data'),
    value: (/** @type {any} */ args) => ({ courses: [], for: args.moodlecourseid })
  }]])
  const plan = await resolveBatch(entries, handlers, () => assert.fail('nothing is unhandled'))
  assert.equal(plan.kind, 'answer')
  if (plan.kind !== 'answer') return

  assert.deepEqual(plan.answers.get(1), success({ courses: [], for: 7 }))
  assert.deepEqual(JSON.parse(coreRequestBody(plan.core)), [
    { index: 0, methodname: 'core_fetch_notifications', args: { contextid: 1 } },
    { index: 1, methodname: 'core_get_string', args: { stringid: 'yes' } }
  ])

  const merged = mergeResponses(entries, plan.answers, [success('n'), success('Yes')])
  assert.deepEqual(merged, [success('n'), success({ courses: [], for: 7 }), success('Yes')])
})

test('an unhandled Skilland call is reported and answered with an error', async () => {
  const entries = parseBatch(body)
  /** @type {string[]} */
  const unhandled = []
  const plan = await resolveBatch(entries, new Map(), entry => unhandled.push(entry.methodname))
  assert.deepEqual(unhandled, ['mod_skilland_fetch_courses_ajax'])
  assert.equal(plan.kind, 'answer')
  if (plan.kind !== 'answer') return
  assert.deepEqual(plan.answers.get(1), failure('Unmocked Skilland AJAX call: mod_skilland_fetch_courses_ajax'))
})

test('fail() answers with a Moodle exception and nothing after it is returned', async () => {
  const entries = parseBatch(body)
  const handlers = new Map([['mod_skilland_fetch_courses_ajax', { type: /** @type {const} */ ('fail'), message: 'boom' }]])
  const plan = await resolveBatch(entries, handlers, () => {})
  if (plan.kind !== 'answer') return assert.fail(`unexpected plan ${plan.kind}`)
  const merged = mergeResponses(entries, plan.answers, [success('n'), success('Yes')])
  assert.deepEqual(merged, [
    success('n'),
    { error: true, exception: { message: 'boom', errorcode: 'skillandmock', exception: 'moodle_exception' } }
  ])
})

test('abort() fails the whole batch', async () => {
  const entries = parseBatch(body)
  const handlers = new Map([['mod_skilland_fetch_courses_ajax', { type: /** @type {const} */ ('abort') }]])
  assert.deepEqual(await resolveBatch(entries, handlers, () => {}), {
    kind: 'abort',
    methodname: 'mod_skilland_fetch_courses_ajax'
  })
})

test('a handler that throws becomes a Moodle exception', async () => {
  const entries = parseBatch([{ index: 0, methodname: 'mod_skilland_fetch_topics_ajax', args: {} }])
  const handlers = new Map([['mod_skilland_fetch_topics_ajax', {
    type: /** @type {const} */ ('data'),
    value: () => { throw new Error('bad fixture') }
  }]])
  const plan = await resolveBatch(entries, handlers, () => {})
  if (plan.kind !== 'answer') return assert.fail(`unexpected plan ${plan.kind}`)
  assert.deepEqual(mergeResponses(entries, plan.answers, undefined), [failure('bad fixture')])
})

test('a whole-request error from Moodle is returned unchanged', () => {
  const entries = parseBatch(body)
  const wholeError = { error: 'Invalid sesskey', errorcode: 'invalidsesskey' }
  assert.deepEqual(mergeResponses(entries, new Map([[1, success({})]]), wholeError), wholeError)
})
