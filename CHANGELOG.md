# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project's version follows Moodle's `$plugin->release` in `src/version.php`
(each build is also tagged with the numeric `$plugin->version`).

## [0.9.43-beta]

### Added
- `scripts/moodle-core-tests.sh` runs the real-Moodle PHPUnit and Behat suites
  (`src/tests`) locally in an opt-in Docker stack (`core-tests/`), so they no
  longer run first in CI (SKL-984).

## [0.9.42-beta]

### Fixed
- With **Lock after first access** on, only a learner's SCORM attempt locks
  auto-update. A teacher or admin previewing the activity (anyone holding
  `moodle/course:manageactivities` in the SCORM) no longer blocks content
  updates, and the destructive-update confirmation counts learners only
  (SKL-677).

## [0.9.41-beta]

### Changed
- Every call to SkilLand now goes to its REST API (`/api/moodle`, Bearer API
  key): the course list (`GET skills?status=all`, every status), the courses a
  teacher can edit (`GET users/courses`), topics (`GET skills/{id}/topics`) and
  lessons (`GET topics/{id}/contents`, lessons with a body only) join the
  topic SCORM routes. Reads are retried on transient failures; writes are sent
  once (SKL-963).
- **Create in SkilLand** uses `POST skills` and sends the Moodle user id and
  site URL, the same `sub`/`iss` as the SSO token, so a SkilLand account
  created for the teacher is linked for SSO. A missing Expert role, a
  deactivated account, an email from another organization, a name already
  taken and rate limiting each show their own message (SKL-963).
- Studio links open the native Studio: `/skills/<id>`,
  `/skills/<id>?topic=<id>` and `/skills/new?draft=<id>` after a create; the
  stored post-save link only accepts those paths (SKL-963).
- The **GraphQL Endpoint** setting is now **Skilland URL**, the address of the
  SkilLand site (default `https://app.skilland.ai`); a stored value ending in
  `/graphql` or `/api/moodle` keeps working. **Frontend URL** is an optional
  override (SKL-963).

### Removed
- The GraphQL client and the legacy GraphQL fallback of the topic SCORM
  lookups (SKL-963).

## [0.9.40-beta]

### Security
- `sso_redirect.php` now requires a `courseid`, refuses guests and checks
  `mod/skilland:accessstudio` in that course before minting an SSO token, so a
  student or guest can no longer reach the handoff by leaving the course out
  (SKL-645).
- The token's `role` claim is no longer hard-coded to `Expert`: it is `Expert`
  only for a user holding `mod/skilland:addinstance` in the course, otherwise
  `Learner`, and `skilland_generate_sso_token()` refuses any other role
  (SKL-645).

### Changed
- The "Go to Skilland" button on the activity form of an unlinked course
  passes the course id to the SSO handoff (SKL-645).

## [0.9.38-beta]

### Added
- Ed25519 signature verification of every topic SCORM package before import:
  the signature covers the requested topic id, `contentHash`, the locally
  computed sha256 of the zip and `generatedAt`; unsigned, tampered,
  unknown-key and cross-topic packages are refused, including anything reached
  through the legacy GraphQL fallback (SKL-650).
- The production signing key `2026-09` pinned in the plugin, plus a
  **SCORM package signing keys** admin setting for extra keys during a
  rotation (SKL-650).
- A `contentupdate` message provider that tells a course's teachers when new
  SkilLand content is available for an activity (SKL-650).
- A `skilland.updateavailable` field recording the content hash of an update
  the teachers were notified about (upgrade step 2026092800, SKL-650).

### Changed
- The `sync_content` task no longer imports packages: content updates are
  applied only when a teacher presses **Update From Skilland**, and the
  "Auto-update content" option is now "Notify me of content updates"
  (SKL-650).
- `packageHash` is compared as plain sha256 hex; algorithm-prefixed hashes
  are no longer read from the API (SKL-650).

## [0.9.37-beta]

### Added
- GPL headers, `@package`/`@copyright`/`@license` phpdoc and a `moodle-plugin-ci`
  phpcs/phpdoc/validate lint job, plus this changelog, a license file and
  contributor/issue scaffolding (SKL-691).
- Bound SSO tokens to the Moodle user id and refused sign-in for inactive
  accounts (SKL-647).
- A shared `string_loader` AMD helper that guards against the `Str.get_strings`
  race across every module that fetches language strings (SKL-773).

### Changed
- Rendered the activity view through Mustache templates and a plugin renderer
  instead of hand-built HTML strings (SKL-681).
- Consolidated the activity form's inline JavaScript and CSS into
  `mod_form.js` and `styles.css`, and dropped the dead `course_mapping_field`
  module (SKL-681).
- Moved the view page's render helper functions out of `view.php` and into
  `locallib.php` so the page itself declares no functions of its own
  (SKL-691).

### Fixed
- Passed the `{$a}` language placeholders to the activity form's JavaScript
  literally instead of pre-interpolating them (SKL-696).
- Persisted the course-mapping custom field value according to its field
  type (SKL-696).

## Earlier releases

Earlier history predates this changelog; see `git log` for the full record.
