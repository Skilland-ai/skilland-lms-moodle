// @ts-check

/**
 * Default payloads for the Skilland AJAX mock.
 *
 * Each builder returns a fresh object shaped exactly like the matching
 * `execute_returns()` definition in src/classes/external/<function>.php. When a return structure
 * changes there, change the builder here in the same commit: the mock answers
 * at the Moodle AJAX boundary, so Moodle's own return validation never runs on it.
 *
 * Ids only use [A-Za-z0-9_-], matching PARAM_ALPHANUMEXT on the server side.
 */

const testData = require('./test-data.json')

const SKILLAND_URL = process.env.SKILLAND_URL || testData.skilland.baseUrl

const SKILL_ID = 'skl-e2e-skill-1'
const SECOND_SKILL_ID = 'skl-e2e-skill-2'
const CREATED_SKILL_ID = 'skl-e2e-created-1'
const TOPIC_ID = 'skl-e2e-topic-1'
const SECOND_TOPIC_ID = 'skl-e2e-topic-2'

/**
 * mod_skilland_fetch_courses_ajax: {courses: [{id, name, code?, status?}], error?}
 * Skills have no code, so the plugin sends the id as code; status is the skill status.
 * @param {Array<{id: string, name: string, code?: string, status?: string}>} [extra] Courses appended to the defaults
 */
function courses(extra = []) {
  return {
    courses: [
      { id: SKILL_ID, name: 'E2E Skill One', code: SKILL_ID, status: 'Published' },
      { id: SECOND_SKILL_ID, name: 'E2E Skill Two', code: SECOND_SKILL_ID, status: 'Draft' },
      ...extra
    ]
  }
}

/**
 * mod_skilland_create_course_ajax: {skillid, name, redirect_url, error?}
 * @param {Partial<{skillid: string, name: string, redirect_url: string}>} [overrides]
 */
function createdCourse(overrides = {}) {
  const skillid = overrides.skillid || CREATED_SKILL_ID
  return {
    skillid,
    name: 'E2E Created Skill',
    redirect_url: `${SKILLAND_URL}/skills/new?draft=${skillid}`,
    ...overrides
  }
}

/**
 * mod_skilland_fetch_topics_ajax: {course: {id, name?, code?}, topics: [{id, name, code?, description?}], error?}
 * Skills and topics have no code, so the plugin sends each id as code.
 * @param {string} [courseId]
 */
function topics(courseId = SKILL_ID) {
  return {
    course: { id: courseId, name: 'E2E Skill One', code: courseId },
    topics: [
      { id: TOPIC_ID, name: 'Getting started', code: TOPIC_ID, description: '<p>First topic</p>' },
      { id: SECOND_TOPIC_ID, name: 'Going further', code: SECOND_TOPIC_ID, description: '<p>Second topic</p>' }
    ]
  }
}

/**
 * mod_skilland_fetch_lessons_ajax: {lessons: [{id, name, updatedAt}], error?}
 */
function lessons() {
  return {
    lessons: [
      { id: 'skl-e2e-lesson-1', name: 'What is testing?', updatedAt: '2026-09-01T10:00:00.000Z' },
      { id: 'skl-e2e-lesson-2', name: 'Writing a first test', updatedAt: '2026-09-02T10:00:00.000Z' }
    ]
  }
}

/**
 * mod_skilland_provision_topic_scorm_ajax / mod_skilland_update_topic_scorm_ajax:
 * {success, scormcmid, error?}
 * @param {number} [scormcmid]
 */
function scormResult(scormcmid = 4242) {
  return { success: true, scormcmid }
}

/**
 * mod_skilland_check_topic_snapshot: {isstale, contenthash, error?}
 * @param {boolean} [isstale]
 */
function snapshot(isstale = true) {
  return { isstale, contenthash: isstale ? 'e2e-hash-new' : 'e2e-hash-current' }
}

module.exports = {
  SKILLAND_URL,
  SKILL_ID,
  SECOND_SKILL_ID,
  CREATED_SKILL_ID,
  TOPIC_ID,
  SECOND_TOPIC_ID,
  courses,
  createdCourse,
  topics,
  lessons,
  scormResult,
  snapshot
}
