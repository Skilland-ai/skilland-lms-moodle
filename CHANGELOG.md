# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project's version follows Moodle's `$plugin->release` in `src/version.php`
(each build is also tagged with the numeric `$plugin->version`).

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
