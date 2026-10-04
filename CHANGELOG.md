# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project's version follows Moodle's `$plugin->release` in `src/version.php`
(each build is also tagged with the numeric `$plugin->version`).

## [0.9.58-beta]

### Changed
- The development Docker stack (`00_development/`) bind-mounts the built plugin
  live, runs Moodle's upgrade and cache purge on every start, pins Moodle core
  with the `MOODLE_TAG` build argument, starts only under the `plugin` profile
  and publishes Moodle on `MOODLE_PORT` (default 8081) with no fixed container
  names or database port, so two stacks can run side by side (SKL-698).
- `grunt clean` empties `dist/` instead of deleting it, and `grunt watch`
  removes deleted build outputs, so the live mount survives a rebuild (SKL-698).

## [0.9.57-beta]

### Changed
- Version check and release skip tooling-only changes that ship no file from `src/` or `cli/`.
- CI actions bumped (checkout 7, setup-node 7, action-gh-release 3) on Node 22; ESLint 10 flat config; grunt and Playwright bumped.
- Dependabot groups minor and patch updates per ecosystem and ignores firebase/php-jwt majors (SKL-988).

## [0.9.56-beta]

### Fixed
- Uninstall removes Skilland-owned topic SCORM activities, including orphaned
  ones, and the course mapping custom field and its data. Adopted Edukami
  SCORMs and unrelated activities remain (SKL-993).

## [0.9.55-beta]

### Changed
- Pre-commit checks now inspect only staged PHP and JavaScript files; PHPUnit remains available manually and in CI (SKL-693).

### Removed
- The obsolete `.githooks` hook and broken translation-check script (SKL-693).

## [0.9.54-beta]

### Added
- A terminal Skilland REST failure records one Moodle site log event after retries end.
  The event stores a masked route path, HTTP status and fixed failure category;
  it excludes response content, request secrets and query parameters (SKL-994).

## [0.9.53-beta]

### Changed
- SSO tokens are signed with the php-jwt library bundled in Moodle core
  (4.5.14 ships php-jwt 6.10.0 and autoloads `\Firebase\JWT\JWT`); the plugin
  no longer bundles `vendor/` and ships no third-party PHP code (SKL-682).
- `firebase/php-jwt` is now a dev dependency of the repository, used only by
  the PHPUnit stub harness (SKL-682).

### Removed
- `composer.phar`, the committed `vendor/` directory, `src/vendor/autoload.php`,
  `src/thirdpartylibs.xml` and the vendor drift guard (script, npm script,
  pre-commit step and CI step) (SKL-682).

### CI
- `composer audit` runs in the Moodle plugin CI job; the release smoke check
  now fails when the zip contains a `vendor` directory or `thirdpartylibs.xml`
  (SKL-682).
## [0.9.52-beta]

### Changed
- The `skilland_course_id` course custom field is now the only course mapping.
  The upgrade copies each row of the legacy `skilland_course` table into an
  empty field (a field that already holds a value is kept), then drops the
  table; backup and restore carry the mapping through the custom field
  (SKL-689).
- Hide Skilland labels now also strips the topic code from the hidden SCORM's
  name and its gradebook item; renaming the activity or toggling the setting
  renames the SCORM (SKL-689).
- The course mapping is trimmed, and a `skilland_course_id` field holding only
  whitespace counts as unmapped, so requests naming a skill fail closed; an
  activity name that is only a topic code (`T1 - `) is kept whole instead of
  becoming blank under Hide Skilland labels (SKL-689).

### Added
- Skilland activities can show their description on the course page
  (SKL-689).

### Removed
- The unused plain player navigation (`skilland_render_player_navigation()`,
  its template and styles) and the unused SCORM attempt lookup in the player
  (SKL-689).

## [0.9.51-beta]

### Fixed
- Students now see the same lesson codes as the activity form: the lesson
  list, the player header and the fullscreen navigation number each lesson by
  its position in the topic in Skilland, so a lesson left out of the activity
  leaves a gap (lessons 1, 3 and 4 show as L1.1, L1.3 and L1.4 everywhere).
  The position is stored with each lesson and refreshed by Update From
  Skilland; existing activities are backfilled by an ad-hoc task after the
  upgrade and keep their current numbering until it runs (SKL-694).

## [0.9.48-beta]

### Added
- `cli/adopt_edukami.php` turns `mod_edukami` activities into Skilland
  activities on a site moving from the Edukami plugin. Each adopted activity
  keeps the Edukami topic SCORM, its learners' attempts, its snapshot hash and
  its lesson→SCO mapping, sits right before the Edukami activity, which is
  hidden (never deleted). Topics that are not in the migration are skipped and
  left alone; a dry run is the default and `--apply` writes (SKL-997).

## [0.9.46-beta]

### Fixed
- Signing in to Skilland from Moodle now lists only the courses where the user
  can add Skilland activities (teaching courses). A teacher who is enrolled as
  a student in a colleague's course no longer becomes a collaborator on that
  course's skill; the next sign-in removes any such synced collaborator
  (SKL-999).

## [0.9.45-beta]

### Added
- Site administration has a **Test connection** page, linked from the top of
  the plugin settings. **Run check** asks Skilland, with the saved settings,
  whether the Skilland URL / Frontend URL is reachable, the API key is
  accepted, the Organization ID matches the key's organization and the SSO
  shared secret matches, and names the setting to fix. The SSO secret never
  leaves Moodle (SKL-992).

### Fixed
- The Frontend URL is now empty by default, which means "use the Skilland URL".
  It used to default to `https://app.skilland.ai`, so a site that pointed the
  Skilland URL at its own Skilland server still sent requests and sign-ins to
  that address. Upgrading removes a stored copy of that old default and keeps
  any other value. The API key, Organization ID and SSO shared secret settings
  now say where in Skilland to find each value, and the release notes name
  Moodle 4.5 and the Install plugins page (SKL-991).

### Changed
- The product name is spelled "Skilland" throughout the plugin's texts and
  documentation (SKL-990).

## [0.9.44-beta]

### Fixed
- Edit settings no longer overwrites a custom activity name; topic options read
  `T<n> - <name>` without the internal id, the General section is visible
  (expanded on new activities, collapsed on existing ones) with a note that its
  values are filled in from Skilland, and the name field is labelled "Name"
  (SKL-678).
- The lesson list shows the score as a percentage using the package's scaled,
  min and max values, SCORM 2004 lessons show Passed or Failed from
  `cmi.success_status`, and lessons that cannot be played yet say "Not yet
  available" with an explanation and `aria-disabled` (SKL-680).

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
