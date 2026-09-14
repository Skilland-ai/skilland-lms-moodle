// @ts-check

/**
 * Skilland GraphQL API helpers for cross-service E2E tests.
 *
 * Provides functions to authenticate with Skilland and manage
 * skills/topics/lessons via the GraphQL API, enabling tests
 * that span both Moodle and Skilland.
 */

const SKILLAND_API_URL = process.env.SKILLAND_API_URL || 'http://localhost:8000'
const SKILLAND_GRAPHQL_URL = `${SKILLAND_API_URL}/graphql`

/**
 * Login to Skilland and return auth token
 * @param {string} email
 * @param {string} password
 * @returns {Promise<{token: string, user: object}>}
 */
async function skillandLogin(email, password) {
  const response = await fetch(SKILLAND_GRAPHQL_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      query: `mutation Login($email: String!, $password: String!) {
        login(email: $email, password: $password) {
          token
          user { id email name currentOrganization { id name } }
        }
      }`,
      variables: { email, password }
    })
  })

  const data = await response.json()
  if (data.errors) {
    throw new Error(`Skilland login failed: ${data.errors[0].message}`)
  }

  return data.data.login
}

/**
 * Execute a GraphQL query/mutation against Skilland
 * @param {string} token - JWT auth token
 * @param {string} query - GraphQL query/mutation string
 * @param {object} variables - Query variables
 * @returns {Promise<object>}
 */
async function skillandGraphQL(token, query, variables = {}) {
  const response = await fetch(SKILLAND_GRAPHQL_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({ query, variables })
  })

  const data = await response.json()
  if (data.errors) {
    throw new Error(`Skilland GraphQL error: ${data.errors[0].message}`)
  }

  return data.data
}

/**
 * Create a skill in Skilland
 * @param {string} token
 * @param {string} name
 * @returns {Promise<{id: string, name: string, topics: Array}>}
 */
async function createSkill(token, name) {
  const data = await skillandGraphQL(token, `
    mutation CreateSkill($name: String!) {
      createSkill(name: $name) {
        id name status topics { id name }
      }
    }
  `, { name })

  return data.createSkill
}

/**
 * Generate a topic for a skill using AI
 * @param {string} token
 * @param {string} skillId
 * @param {string} topicPrompt
 * @returns {Promise<Array<{id: string, name: string}>>}
 */
async function generateTopic(token, skillId, topicPrompt) {
  const data = await skillandGraphQL(token, `
    mutation GenerateTopic($skillId: ID!, $newTopic: String!) {
      ai_generateTopic(skillId: $skillId, newTopic: $newTopic) {
        id name content { id name __typename }
      }
    }
  `, { skillId, newTopic: topicPrompt })

  return data.ai_generateTopic
}

/**
 * Generate a lesson for a topic using AI
 * @param {string} token
 * @param {string} skillId
 * @param {string} topicId
 * @param {string} lessonPrompt
 * @returns {Promise<{id: string, name: string, content: Array}>}
 */
async function generateLesson(token, skillId, topicId, lessonPrompt) {
  const data = await skillandGraphQL(token, `
    mutation GenerateLesson($skillId: ID!, $topicId: ID!, $newLesson: String!) {
      ai_generateLesson(skillId: $skillId, topicId: $topicId, newLesson: $newLesson) {
        id name content { id name __typename }
      }
    }
  `, { skillId, topicId, newLesson: lessonPrompt })

  return data.ai_generateLesson
}

/**
 * Update a lesson's content directly (no AI)
 * @param {string} token
 * @param {string} lessonId
 * @param {object} input - { name?, content?, goals? }
 * @returns {Promise<{id: string, name: string}>}
 */
async function updateLesson(token, lessonId, input) {
  const data = await skillandGraphQL(token, `
    mutation UpdateLesson($lessonId: ID!, $input: UpdateLessonInput!) {
      updateLesson(lessonId: $lessonId, input: $input) {
        id name
      }
    }
  `, { lessonId, input })

  return data.updateLesson
}

/**
 * Get SCORM info for a topic
 * @param {string} token
 * @param {string} topicId
 * @returns {Promise<{packageUrl: string, packageHash: string, generatedAt: string, mappings: Array}>}
 */
async function getTopicScorm(token, topicId) {
  const data = await skillandGraphQL(token, `
    query TopicScorm($topicId: ID!) {
      topicScorm(topicId: $topicId) {
        packageUrl packageHash packageSize generatedAt expiresAt mappings
      }
    }
  `, { topicId })

  return data.topicScorm
}

/**
 * Get skill details including topics and content
 * @param {string} token
 * @param {string} skillId
 * @returns {Promise<object>}
 */
async function getSkill(token, skillId) {
  const data = await skillandGraphQL(token, `
    query GetSkill($skillId: ID!) {
      skill(skillId: $skillId) {
        id name status
        topics {
          id name
          content {
            id name __typename
            ... on LessonContent { content }
          }
        }
      }
    }
  `, { skillId })

  return data.skill
}

/**
 * Delete a skill by ID
 * @param {string} token
 * @param {string} skillId
 * @returns {Promise<void>}
 */
async function deleteSkill(token, skillId) {
  await skillandGraphQL(token, `
    mutation DeleteSkill($skillId: ID!) {
      deleteSkill(skillId: $skillId) { id }
    }
  `, { skillId })
}

module.exports = {
  SKILLAND_API_URL,
  SKILLAND_GRAPHQL_URL,
  skillandLogin,
  skillandGraphQL,
  createSkill,
  generateTopic,
  generateLesson,
  updateLesson,
  getTopicScorm,
  getSkill,
  deleteSkill
}
