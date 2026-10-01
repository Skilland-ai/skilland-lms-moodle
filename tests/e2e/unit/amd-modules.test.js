const { test, describe } = require('node:test')
const assert = require('node:assert/strict')
const { loadModule, Event } = require('./support/amd-harness')

/**
 * Executes the activity form (mod_form.js) and course mapping (course_mapping.js) AMD modules
 * against fakes of core/str, core/ajax and core/notification and a small DOM (SKL-681).
 * Run with: npm run test:e2e:unit
 */

const TOPICS = [
  { id: 'topic-1', name: 'Topic one', position: 1, description: 'First' },
  { id: 'topic-2', name: 'Topic two', position: 2, description: 'Second' }
]

const LESSONS = {
  'topic-1': [
    { id: 'l1', name: 'Lesson one', updatedAt: '2026-09-01T10:00:00Z' },
    { id: 'l2', name: 'Lesson <two>', updatedAt: '2026-09-02T10:00:00Z' }
  ],
  'topic-2': [
    { id: 'l3', name: 'Lesson three', updatedAt: '2026-09-03T10:00:00Z' }
  ]
}

/** The activity form markup mod_form.php renders, reduced to what mod_form.js reads. */
function buildActivityForm (env, { savedTopic = '', savedLessons = {}, selectedLessons = '{}' } = {}) {
  const { h, document } = env
  const form = h('form', { class: 'mform' }, [
    h('div', { id: 'fitem_id_skilland_course_id_display', class: 'fitem' }, [
      h('div', { class: 'felement' }, ['skill-1'])
    ]),
    h('div', { class: 'fitem', id: 'fitem_id_skilland_topicid' }, [
      h('select', { id: 'id_skilland_topicid', name: 'skilland_topicid' })
    ]),
    h('input', { type: 'hidden', id: 'id_skilland_topicid_saved', name: 'skilland_topicid_saved', value: savedTopic }),
    h('input', { type: 'hidden', id: 'id_selected_lessons', name: 'selected_lessons', value: selectedLessons }),
    h('input', { type: 'hidden', id: 'id_topic_orderindex', name: 'topic_orderindex', value: '1' }),
    h('input', { type: 'text', id: 'id_name', name: 'name', value: '' }),
    h('div', { id: 'skilland-edit-button-container', class: 'd-none' }, [
      h('a', { id: 'skilland-edit-link', href: '#' })
    ]),
    h('div', { id: 'skilland-select-actions', class: 'mb-2 d-none' }, [
      h('button', { type: 'button', id: 'skilland-select-all' }, ['All']),
      h('button', { type: 'button', id: 'skilland-select-none' }, ['None'])
    ]),
    h('div', { id: 'id_lessons_container' }),
    h('div', { id: 'skilland-update-container', class: 'd-none' }, [
      h('button', { type: 'button', id: 'skilland-update-btn' }, ['Update']),
      h('span', { id: 'skilland-update-loading', class: 'd-none' })
    ]),
    h('div', { id: 'skilland-current-lessons', class: 'd-none', 'data-lessons': JSON.stringify(savedLessons) }),
    h('input', { type: 'submit', id: 'id_submitbutton' })
  ])
  document.body.appendChild(form)
  return form
}

function formConfig (overrides) {
  return Object.assign({
    skillandcourseid: 'skill-1',
    moodlecourseid: 3,
    currenttopicid: '',
    currentlessonsid: 'skilland-current-lessons',
    hasscorm: false,
    studentattemptcount: 0,
    skillandinstanceid: 0,
    cmid: 0,
    ssourl: 'https://moodle.test/mod/skilland/sso_redirect.php',
    editlinkhtml: '<a href="https://moodle.test/course/edit.php?id=3">Edit course settings</a>'
  }, overrides)
}

function topicsAjax (overrides) {
  return Object.assign({
    mod_skilland_fetch_topics_ajax: () => ({ course: { name: 'Fixture skill', code: 'FIX' }, topics: TOPICS }),
    mod_skilland_fetch_lessons_ajax: args => ({ lessons: LESSONS[args.topicid] || [] })
  }, overrides)
}

function ajaxCalls (env, methodname) {
  return env.calls.ajax.filter(call => call.methodname === methodname)
}

function select (env) {
  return env.document.getElementById('id_skilland_topicid')
}

function hiddenSelection (env) {
  return JSON.parse(env.document.getElementById('id_selected_lessons').value)
}

function change (element, value) {
  element.value = value
  element.dispatchEvent(new Event('change', { bubbles: true }))
}

async function startForm (options, formOptions, config) {
  const env = loadModule('mod_form', options)
  buildActivityForm(env, formOptions)
  env.module.init(formConfig(config))
  await env.flush()
  return env
}

describe('mod_form.js', () => {
  test('binds nothing and fetches nothing until the strings resolve', async () => {
    const env = loadModule('mod_form', { strings: 'manual', ajax: topicsAjax() })
    buildActivityForm(env, { savedTopic: 'topic-1' })
    env.module.init(formConfig({ currenttopicid: 'topic-1', skillandinstanceid: 7, cmid: 42 }))
    await env.flush()

    assert.equal(env.calls.strings.length, 1)
    assert.equal(select(env).disabled, true)
    assert.equal(env.document.getElementById('skilland-update-btn').disabled, true)
    assert.deepEqual(env.calls.ajax, [])

    env.fakes.resolveStrings()
    await env.flush()

    assert.deepEqual(ajaxCalls(env, 'mod_skilland_fetch_topics_ajax').map(call => call.args),
      [{ courseid: 'skill-1', moodlecourseid: 3 }])
    assert.equal(select(env).disabled, false)
    assert.equal(select(env).value, 'topic-1')
    assert.deepEqual(select(env).options.map(option => option.textContent), [
      'S:select_topic...', 'T1 - Topic one', 'T2 - Topic two'
    ])
    assert.deepEqual(ajaxCalls(env, 'mod_skilland_fetch_lessons_ajax').map(call => call.args),
      [{ topicid: 'topic-1', moodlecourseid: 3 }])
    assert.equal(env.document.getElementById('skilland-update-btn').disabled, false)
    // Restoring the saved topic never writes the Name; the option carries the id as its tooltip.
    assert.equal(env.document.getElementById('id_name').value, '')
    assert.equal(select(env).options[1].title, 'topic-1')
  })

  test('opening the form keeps a custom name; picking a topic fills it only while it is empty or auto-generated', async () => {
    const env = await startForm({ ajax: topicsAjax() }, { savedTopic: 'topic-1' }, { currenttopicid: 'topic-1' })
    const name = env.document.getElementById('id_name')
    assert.equal(name.value, '')

    change(select(env), 'topic-2')
    await env.flush()
    assert.equal(name.value, 'T2 - Topic two')

    change(select(env), 'topic-1')
    await env.flush()
    assert.equal(name.value, 'T1 - Topic one')

    name.value = 'My custom name'
    change(select(env), 'topic-2')
    await env.flush()
    assert.equal(name.value, 'My custom name')

    const restored = loadModule('mod_form', { ajax: topicsAjax() })
    buildActivityForm(restored, { savedTopic: 'topic-1' })
    restored.document.getElementById('id_name').value = 'Teacher title'
    restored.module.init(formConfig({ currenttopicid: 'topic-1' }))
    await restored.flush()
    assert.equal(restored.document.getElementById('id_name').value, 'Teacher title')
  })

  test('a get_strings failure is reported and the form still loads, with the keys as text', async () => {
    const failure = new Error('lang cache down')
    const env = await startForm({ strings: 'reject', stringsError: failure, ajax: topicsAjax() })

    assert.deepEqual(env.calls.exceptions, [failure])
    assert.equal(ajaxCalls(env, 'mod_skilland_fetch_topics_ajax').length, 1)
    assert.equal(select(env).disabled, false)
    assert.equal(select(env).options[0].textContent, 'select_topic...')

    change(select(env), 'topic-2')
    await env.flush()
    assert.deepEqual(hiddenSelection(env), { l3: { updatedAt: '2026-09-03T10:00:00Z', name: 'Lesson three' } })
    const meta = env.document.querySelector('#id_lessons_container .skilland-lesson-meta span')
    assert.ok(meta.textContent.startsWith('updated_on'), meta.textContent)
  })

  test('runtime values reach {$a} strings literally, "$&" and "$\'" included', async () => {
    const env = await startForm({
      ajax: { mod_skilland_fetch_topics_ajax: () => ({ error: 'bad $& and $\' value' }) }
    })

    assert.equal(env.calls.notifications.length, 1)
    assert.deepEqual(env.calls.notifications[0], {
      message: 'S:error_fetch_topics_detail [bad $&amp; and $&#39; value]',
      type: 'error'
    })
    // The placeholders were requested as each string's {$a}, not the runtime value.
    const requested = env.calls.strings[0].filter(request => request.param !== undefined)
    assert.deepEqual(requested.map(request => [request.key, request.param]), [
      ['error_fetch_topics_detail', '__SKILLAND_ERROR__'],
      ['updated_on', '__SKILLAND_DATE__'],
      ['update_confirm_message_students', 0],
      ['topic_change_confirm_students', 0],
      ['lockafterfirstaccess_hint', '__SKILLAND_SETTING__']
    ])
  })

  test('the lesson date and the lock hint are filled in, with no placeholder left', async () => {
    const env = await startForm({ ajax: topicsAjax() }, { savedTopic: 'topic-1' },
      { currenttopicid: 'topic-1', hasscorm: true, studentattemptcount: 4 })

    const metas = env.document.querySelectorAll('#id_lessons_container .skilland-lesson-meta span')
    assert.equal(metas.length, 2)
    metas.forEach(meta => {
      assert.match(meta.textContent, /^S:updated_on \[.+\]$/)
      assert.doesNotMatch(meta.textContent, /__SKILLAND_|\{\$a\}/)
    })

    change(select(env), 'topic-2')
    assert.equal(env.calls.confirms.length, 1)
    assert.deepEqual(
      [env.calls.confirms[0].message, env.calls.confirms[0].yesLabel],
      ['S:topic_change_confirm_students [4] S:lockafterfirstaccess_hint [S:lockafterfirstaccess]',
        'S:destructive_confirm_action'])
  })

  test('leaving the saved topic of a provisioned activity waits for the confirmation', async () => {
    const env = await startForm({ ajax: topicsAjax() }, { savedTopic: 'topic-1' },
      { currenttopicid: 'topic-1', hasscorm: true })
    const lessonsFetched = () => ajaxCalls(env, 'mod_skilland_fetch_lessons_ajax').map(call => call.args.topicid)
    assert.deepEqual(lessonsFetched(), ['topic-1'])

    change(select(env), 'topic-2')
    await env.flush()
    assert.equal(select(env).value, 'topic-1')
    assert.deepEqual(lessonsFetched(), ['topic-1'])
    assert.equal(env.calls.confirms[0].yesLabel, 'yes')

    env.calls.confirms[0].onNo()
    assert.equal(select(env).value, 'topic-1')

    change(select(env), 'topic-2')
    env.calls.confirms[1].onYes()
    await env.flush()
    assert.equal(select(env).value, 'topic-2')
    assert.equal(env.document.getElementById('id_skilland_topicid_saved').value, 'topic-2')
    assert.deepEqual(lessonsFetched(), ['topic-1', 'topic-2'])
  })

  test('a saved topic missing from the API stays as a disabled option and its lessons are kept', async () => {
    const saved = { old1: { name: '<b>Old lesson</b>', updatedAt: 100 } }
    const env = await startForm({ ajax: topicsAjax() },
      { savedTopic: 'topic-gone', savedLessons: saved, selectedLessons: JSON.stringify(saved) },
      { currenttopicid: 'topic-gone' })

    const stale = select(env).options.find(option => option.value === 'topic-gone')
    assert.ok(stale, 'the stale topic is kept as an option')
    assert.equal(stale.disabled, true)
    assert.equal(stale.selected, true)
    assert.equal(stale.textContent, 'S:topic_no_longer_available')
    assert.equal(select(env).value, 'topic-gone')
    assert.deepEqual(env.calls.notifications, [{ message: 'S:topic_no_longer_available_warning', type: 'warning' }])
    assert.deepEqual(ajaxCalls(env, 'mod_skilland_fetch_lessons_ajax'), [])

    const title = env.document.querySelector('#id_lessons_container .skilland-lesson-title')
    assert.equal(title.textContent, '<b>Old lesson</b>')
    assert.equal(env.document.querySelectorAll('#id_lessons_container b').length, 0)
    assert.equal(env.document.getElementById('id_selected_lessons').value, JSON.stringify(saved))
    assert.equal(env.document.getElementById('id_skilland_topicid_saved').value, 'topic-gone')
  })

  test('the course name and code from the API are rendered as text', async () => {
    const hostile = '<img src=x onerror=alert(1)>'
    const env = await startForm({
      ajax: topicsAjax({
        mod_skilland_fetch_topics_ajax: () => ({ course: { name: hostile, code: '<i>C</i>' }, topics: TOPICS })
      })
    })

    const display = env.document.querySelector('#fitem_id_skilland_course_id_display .felement')
    assert.equal(display.querySelector('.skilland-course-name').textContent, hostile)
    assert.equal(display.querySelector('.skilland-course-code').textContent, ' (<i>C</i>)')
    assert.equal(env.document.querySelectorAll('img').length, 0)
    assert.equal(env.document.querySelectorAll('i').length, 0)
    assert.ok(display.innerHTML.includes('&lt;img src=x onerror=alert(1)&gt;'), display.innerHTML)
    // The trusted edit link built by html_writer is the only HTML that goes in as markup.
    const link = display.querySelector('.skilland-course-edit-link a')
    assert.equal(link.href, 'https://moodle.test/course/edit.php?id=3')
    assert.equal(link.textContent, 'Edit course settings')
  })

  test('topic and lesson names from the API are rendered as text', async () => {
    const env = await startForm({
      ajax: topicsAjax({
        mod_skilland_fetch_topics_ajax: () => ({ topics: [{ id: 'topic-1', name: '<svg onload=x>', position: 1 }] })
      })
    })
    assert.equal(select(env).options[1].textContent, 'T1 - <svg onload=x>')
    assert.equal(env.document.querySelectorAll('svg').length, 0)

    change(select(env), 'topic-1')
    await env.flush()
    const titles = env.document.querySelectorAll('#id_lessons_container .skilland-lesson-title')
    assert.deepEqual(titles.map(title => title.textContent), ['L1.1 - Lesson one', 'L1.2 - Lesson <two>'])
    assert.equal(env.document.querySelectorAll('two').length, 0)
  })

  test('the Edit in SkilLand link carries the Moodle course id (SKL-645)', async () => {
    const env = await startForm({ ajax: topicsAjax() })
    const container = env.document.getElementById('skilland-edit-button-container')
    assert.equal(container.classList.contains('d-none'), true)

    change(select(env), 'topic-2')
    await env.flush()

    assert.equal(container.classList.contains('d-none'), false)
    const href = new URL(env.document.getElementById('skilland-edit-link').getAttribute('href'))
    assert.equal(href.origin + href.pathname, 'https://moodle.test/mod/skilland/sso_redirect.php')
    assert.equal(href.searchParams.get('courseid'), '3')
    assert.equal(href.searchParams.get('topicid'), 'topic-2')
    assert.ok(href.searchParams.get('sesskey'), 'the link carries a sesskey')
  })

  test('a new topic ticks every lesson and the hidden value follows the checkboxes', async () => {
    const env = await startForm({ ajax: topicsAjax() })

    change(select(env), 'topic-1')
    await env.flush()
    assert.deepEqual(Object.keys(hiddenSelection(env)), ['l1', 'l2'])
    assert.equal(env.document.getElementById('skilland-select-actions').classList.contains('d-none'), false)
    assert.equal(env.document.getElementById('id_topic_orderindex').value, '1')

    const second = env.document.getElementById('lesson_l2')
    second.checked = false
    second.dispatchEvent(new Event('change', { bubbles: true }))
    assert.deepEqual(hiddenSelection(env), { l1: { updatedAt: '2026-09-01T10:00:00Z', name: 'Lesson one' } })

    env.document.getElementById('skilland-select-none').click()
    assert.deepEqual(hiddenSelection(env), {})
    env.document.getElementById('skilland-select-all').click()
    assert.deepEqual(Object.keys(hiddenSelection(env)), ['l1', 'l2'])

    // Another topic and back: the first topic's ticks are restored exactly.
    second.checked = false
    second.dispatchEvent(new Event('change', { bubbles: true }))
    change(select(env), 'topic-2')
    await env.flush()
    assert.deepEqual(Object.keys(hiddenSelection(env)), ['l3'])
    change(select(env), 'topic-1')
    await env.flush()
    assert.deepEqual(Object.keys(hiddenSelection(env)), ['l1'])
  })

  test('a saved lesson missing from the API stays selected until the teacher removes it', async () => {
    const saved = {
      l1: { name: 'Lesson one', updatedAt: '2026-09-01T10:00:00Z' },
      lgone: { name: 'Gone lesson', updatedAt: '2026-01-01T00:00:00Z' }
    }
    const env = await startForm({ ajax: topicsAjax() },
      { savedTopic: 'topic-1', savedLessons: saved, selectedLessons: JSON.stringify(saved) },
      { currenttopicid: 'topic-1' })

    const warning = env.document.querySelector('.skilland-missing-lessons-warning')
    assert.ok(warning, 'the missing lesson is flagged')
    assert.equal(warning.querySelector('.skilland-missing-lessons-title').textContent, 'S:missing_lessons_warning')
    assert.deepEqual(Object.keys(hiddenSelection(env)), ['l1', 'lgone'])
    assert.equal(env.document.getElementById('lesson_l2').checked, false)

    warning.querySelector('.skilland-remove-missing-lesson').click()
    assert.deepEqual(Object.keys(hiddenSelection(env)), ['l1'])
    assert.equal(warning.querySelectorAll('.skilland-missing-lesson-row').length, 0)
  })

  test('a topic fetch failure keeps the saved topic selectable and Retry fetches again', async () => {
    let attempts = 0
    const env = await startForm({
      ajax: topicsAjax({
        mod_skilland_fetch_topics_ajax: () => {
          attempts++
          if (attempts === 1) {
            throw new Error('network down')
          }
          return { topics: TOPICS }
        }
      })
    }, { savedTopic: 'topic-1' }, { currenttopicid: 'topic-1' })

    assert.equal(select(env).disabled, false)
    assert.deepEqual(select(env).options.map(option => [option.value, option.textContent]),
      [['topic-1', 'S:current_topic_unavailable']])
    assert.deepEqual(env.calls.notifications,
      [{ message: 'S:error_fetch_topics_detail [network down]', type: 'error' }])
    const retry = env.document.getElementById('skilland-topic-retry')
    assert.equal(retry.textContent, 'S:retry')

    retry.click()
    await env.flush()
    assert.equal(attempts, 2)
    assert.equal(retry.style.display, 'none')
    assert.equal(select(env).value, 'topic-1')
    assert.equal(ajaxCalls(env, 'mod_skilland_fetch_lessons_ajax').length, 1)
  })

  test('a failed fetch for a new activity leaves the select disabled with the error text', async () => {
    const env = await startForm({
      ajax: { mod_skilland_fetch_topics_ajax: () => ({ error: 'API offline' }) }
    })
    assert.equal(select(env).disabled, true)
    assert.deepEqual(select(env).options.map(option => option.textContent), ['S:error_fetch_topics'])
    assert.ok(env.document.getElementById('skilland-topic-retry'))
  })

  test('a lessons response for a topic no longer selected is dropped', async () => {
    const pending = {}
    const env = await startForm({
      ajax: topicsAjax({
        mod_skilland_fetch_lessons_ajax: args => new Promise(resolve => {
          pending[args.topicid] = () => resolve({ lessons: LESSONS[args.topicid] })
        })
      })
    })

    change(select(env), 'topic-1')
    change(select(env), 'topic-2')
    pending['topic-2']()
    await env.flush()
    pending['topic-1']()
    await env.flush()

    assert.deepEqual(Object.keys(hiddenSelection(env)), ['l3'])
    assert.equal(env.document.getElementById('lesson_l1'), null)
  })

  test('the Update button confirms, then calls the update service and reloads', async () => {
    const saved = { l1: { name: 'Lesson one', updatedAt: '2026-01-01T00:00:00Z' } }
    const env = await startForm({
      ajax: topicsAjax({ mod_skilland_update_topic_scorm_ajax: () => ({ success: true }) })
    }, { savedTopic: 'topic-1', savedLessons: saved }, { currenttopicid: 'topic-1', skillandinstanceid: 7, cmid: 42 })

    // The API lesson is newer than the stored one: the pill and the Update button show.
    assert.equal(env.document.querySelector('.skilland-new-content-pill').textContent, 'S:new_content_available')
    assert.equal(env.document.getElementById('skilland-update-container').classList.contains('d-none'), false)

    env.document.getElementById('skilland-update-btn').click()
    assert.equal(env.calls.confirms.length, 1)
    assert.equal(env.calls.confirms[0].message,
      'S:update_confirm_message S:lockafterfirstaccess_hint [S:lockafterfirstaccess]')
    env.calls.confirms[0].onYes()
    await env.flush()

    assert.deepEqual(ajaxCalls(env, 'mod_skilland_update_topic_scorm_ajax').map(call => call.args),
      [{ skillandid: 7, cmid: 42 }])
    assert.deepEqual(env.calls.notifications, [{ message: 'S:update_success', type: 'success' }])
    assert.equal(env.window.location.reloads, 1)
  })

  test('an unmapped course only hides the form around the warning and fetches nothing', async () => {
    const env = loadModule('mod_form', {})
    const { h } = env
    const form = h('form', { class: 'mform' }, [
      h('div', { class: 'fitem', id: 'warning-item' }, [h('div', { class: 'alert alert-warning' }, ['Set it'])]),
      h('div', { class: 'fitem', id: 'other-item' }),
      h('fieldset', { id: 'id_generalheader', class: 'collapsible' }, [h('h3', {}, ['General'])])
    ])
    env.document.body.appendChild(form)

    env.module.init({ missingcourseid: true })
    await env.flush()

    assert.equal(env.document.getElementById('warning-item').style.display, undefined)
    assert.equal(env.document.getElementById('other-item').style.display, 'none')
    assert.equal(env.document.getElementById('id_generalheader').style.display, 'none')
    assert.deepEqual(env.calls.strings, [])
    assert.deepEqual(env.calls.ajax, [])
  })
})

// ---------------------------------------------------------------------------
// course_mapping.js
// ---------------------------------------------------------------------------

const MAPPING_CONFIG = {
  courseid: 5,
  coursename: 'Maths <101> & $& more',
  linked: false,
  ssourl: 'https://moodle.test/mod/skilland/sso_redirect.php?courseid=5',
  debug: false
}

function buildCourseSettings (env, value) {
  const { h } = env
  const input = h('input', { type: 'text', id: 'id_customfield_skilland_course_id', name: 'customfield_skilland_course_id',
    value: value || '' })
  const form = h('form', { class: 'mform' }, [
    h('div', { class: 'fitem', id: 'fitem_id_customfield_skilland_course_id' }, [h('div', { class: 'felement' }, [input])])
  ])
  env.document.body.appendChild(form)
  return input
}

function coursesAjax (overrides) {
  return Object.assign({
    mod_skilland_fetch_courses_ajax: () => ({
      courses: [
        { id: 'skill-1', name: 'Skill one', code: 'S1', status: 'published' },
        { id: 'skill-2', name: '<b>Skill two</b>', code: '', status: '' }
      ]
    })
  }, overrides)
}

async function startMapping (options, value, config) {
  const env = loadModule('course_mapping', options)
  const input = buildCourseSettings(env, value)
  env.module.init(Object.assign({}, MAPPING_CONFIG, config))
  await env.flush()
  return { env, input, field: () => env.document.getElementById('id_customfield_skilland_course_id') }
}

describe('course_mapping.js', () => {
  test('builds the dropdown and the buttons only once the strings resolve', async () => {
    const env = loadModule('course_mapping', { strings: 'manual', ajax: coursesAjax() })
    const input = buildCourseSettings(env, 'skill-1')
    env.module.init(MAPPING_CONFIG)
    await env.flush()

    assert.equal(env.calls.strings.length, 1)
    assert.equal(env.document.querySelector('select'), null)
    assert.equal(env.document.getElementById('skilland-goto-btn'), null)
    assert.deepEqual(env.calls.ajax, [])
    assert.equal(input.disabled, false)

    env.fakes.resolveStrings()
    await env.flush()

    const field = env.document.getElementById('id_customfield_skilland_course_id')
    assert.equal(field.tagName, 'SELECT')
    assert.equal(field.name, 'customfield_skilland_course_id')
    assert.equal(field.value, 'skill-1')
    assert.equal(input.id, 'id_customfield_skilland_course_id_raw')
    assert.equal(input.disabled, true)
    assert.deepEqual(field.options.map(option => option.textContent),
      ['S:select_skilland_course', 'Skill one (S1) [published]', '<b>Skill two</b>'])
    assert.equal(env.document.querySelectorAll('b').length, 0)
    assert.deepEqual(env.calls.ajax, [{ methodname: 'mod_skilland_fetch_courses_ajax', args: { moodlecourseid: 5 } }])

    const goTo = env.document.querySelector('#skilland-goto-btn a')
    assert.equal(goTo.href, MAPPING_CONFIG.ssourl)
    assert.equal(goTo.textContent.trim(), 'S:go_to_skilland')
    assert.equal(env.document.getElementById('skilland-goto-btn').nextSibling.id,
      'fitem_id_customfield_skilland_course_id')
    assert.equal(env.document.getElementById('skilland-create-course-btn').disabled, false)
  })

  test('a get_strings failure is reported and leaves the plain text input in place', async () => {
    const failure = new Error('lang cache down')
    const { env, input } = await startMapping({ strings: 'reject', stringsError: failure, ajax: coursesAjax() },
      'skill-1')

    assert.deepEqual(env.calls.exceptions, [failure])
    assert.equal(env.document.querySelector('select'), null)
    assert.equal(env.document.getElementById('skilland-goto-btn'), null)
    assert.equal(input.id, 'id_customfield_skilland_course_id')
    assert.equal(input.disabled, false)
    assert.equal(input.value, 'skill-1')
    assert.deepEqual(env.calls.ajax, [])
  })

  test('a stale mapping stays selected, with its id filled in literally, and a warning', async () => {
    const staleId = 'gone-$&-$\'-id'
    const { env, field } = await startMapping({ ajax: coursesAjax() }, staleId)

    assert.equal(field().value, staleId)
    const stale = field().options.find(option => option.value === staleId)
    assert.equal(stale.textContent, `S:course_unknown [${staleId}]`)
    const warning = env.document.querySelector('.skilland-course-unknown-warning')
    assert.equal(warning.textContent, 'S:course_unknown_warning')
    assert.equal(warning.getAttribute('role'), 'status')
    assert.equal(env.calls.strings[0].find(request => request.key === 'course_unknown').param,
      '__SKILLAND_COURSE_ID__')
  })

  test('a known mapping shows no warning', async () => {
    const { env, field } = await startMapping({ ajax: coursesAjax() }, 'skill-2')
    assert.equal(field().value, 'skill-2')
    assert.equal(env.document.querySelector('.skilland-course-unknown-warning'), null)
  })

  test('a failed course fetch restores the text input as the submitted control', async () => {
    const { env, input } = await startMapping({
      ajax: { mod_skilland_fetch_courses_ajax: () => { throw new Error('offline') } }
    }, 'skill-1')

    assert.equal(env.document.querySelector('select'), null)
    assert.equal(input.id, 'id_customfield_skilland_course_id')
    assert.equal(input.disabled, false)
    assert.equal(input.style.display, '')
    assert.deepEqual(env.calls.notifications, [{ message: 'S:error_fetch_courses', type: 'error' }])
  })

  test('a course fetch error reply is shown escaped, "$&" kept literally', async () => {
    const { env, input } = await startMapping({
      ajax: { mod_skilland_fetch_courses_ajax: () => ({ error: '<b>no</b> $& access' }) }
    }, '')

    assert.equal(input.disabled, false)
    assert.deepEqual(env.calls.notifications,
      [{ message: 'S:error_fetch_courses_detail [&lt;b&gt;no&lt;/b&gt; $&amp; access]', type: 'error' }])
  })

  test('Create in SkilLand confirms with the escaped course name, then links the new course', async () => {
    const { env, field } = await startMapping({
      ajax: coursesAjax({ mod_skilland_create_course_ajax: () => ({ skillid: 'skill-new', name: 'New $& course' }) })
    }, 'skill-1')

    env.document.getElementById('skilland-create-course-btn').click()
    await env.flush()

    assert.equal(env.calls.saveCancels.length, 1)
    assert.deepEqual(env.calls.saveCancels[0], {
      title: 'S:create_course_confirm_title',
      body: 'S:create_course_confirm_body [Maths &lt;101&gt; &amp; $&amp; more] S:create_course_confirm_replace',
      saveLabel: 'S:create_course_confirm_yes'
    })
    assert.deepEqual(env.calls.ajax.slice(1),
      [{ methodname: 'mod_skilland_create_course_ajax', args: { moodlecourseid: 5 } }])
    assert.equal(field().value, 'skill-new')
    assert.equal(field().options.find(option => option.value === 'skill-new').textContent, 'New $& course')
    const createBtn = env.document.getElementById('skilland-create-course-btn')
    assert.equal(createBtn.disabled, false)
    assert.equal(createBtn.textContent, 'S:create_in_skilland')
  })

  test('an unlinked course gets no replace warning and a cancelled confirmation creates nothing', async () => {
    const { env } = await startMapping({ saveCancel: 'cancel', ajax: coursesAjax() }, '')

    env.document.getElementById('skilland-create-course-btn').click()
    await env.flush()

    assert.equal(env.calls.saveCancels[0].body,
      'S:create_course_confirm_body [Maths &lt;101&gt; &amp; $&amp; more]')
    assert.equal(env.calls.ajax.filter(call => call.methodname === 'mod_skilland_create_course_ajax').length, 0)
  })

  test('a create error reply is reported escaped and the button comes back', async () => {
    const { env } = await startMapping({
      ajax: coursesAjax({ mod_skilland_create_course_ajax: () => ({ error: 'quota $& <full>' }) })
    }, '')

    env.document.getElementById('skilland-create-course-btn').click()
    await env.flush()

    assert.deepEqual(env.calls.notifications,
      [{ message: 'S:error_create_course [quota $&amp; &lt;full&gt;]', type: 'error' }])
    assert.equal(env.document.getElementById('skilland-create-course-btn').disabled, false)
  })

  test('no custom field on the page: nothing is fetched or built', async () => {
    const env = loadModule('course_mapping', { ajax: coursesAjax() })
    env.module.init(MAPPING_CONFIG)
    await env.flush()
    assert.deepEqual(env.calls.strings, [])
    assert.deepEqual(env.calls.ajax, [])
  })
})
