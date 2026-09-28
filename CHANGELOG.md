# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project's version follows Moodle's `$plugin->release` in `src/version.php`
(each build is also tagged with the numeric `$plugin->version`).

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
