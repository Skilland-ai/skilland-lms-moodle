# Development

How to work on `mod_skilland`: repository layout, local setup, coding rules, tests, CI and releases. Site administrators want the [administrator guide](admin-guide.md); contribution rules (branches, commits, pull requests) are in [CONTRIBUTING.md](../CONTRIBUTING.md).

## Contents

- [Repository layout](#repository-layout)
- [Local setup](#local-setup)
- [Development workflow](#development-workflow)
- [Architecture](#architecture)
- [Coding conventions](#coding-conventions)
- [Tests](#tests)
- [E2E tests](#e2e-tests)
- [CI and releases](#ci-and-releases)
- [Git hooks](#git-hooks)

## Repository layout

| Path | What |
|---|---|
| `src/` | The plugin itself, installed as `<moodle>/mod/skilland`. `src/amd/build/` is committed and must match a fresh build |
| `src/tests/` | Real Moodle PHPUnit and Behat tests (run by moodle-plugin-ci) |
| `vendor/` | Runtime Composer dependency (`firebase/php-jwt`), committed; `src/vendor/autoload.php` loads it |
| `cli/` | `cleanup_orphaned_scorm.php` (shipped) and `configure_api.php` (development only, not shipped) |
| `tests/phpunit/` | Stub PHPUnit suite, no Moodle needed |
| `tests/e2e/` | Playwright suite against a local Moodle |
| `00_development/` | Docker Compose Moodle (MariaDB + Moodle on port 8081) for local work |
| `scripts/` | CI helpers: version check, translation parity, vendor drift |
| `dist/` | Build output (`npm run build`), git-ignored; this is what the release ZIP contains |

`npm run build` (Grunt) copies `src/` to `dist/` without `tests/` and `classes/local/testing/`, copies `vendor/` and `cli/` (without `configure_api.php`), and minifies `src/amd/src/*.js` into `dist/amd/build/*.min.js`.

## Local setup

```bash
git clone git@github.com:Skilland-ai/skilland-lms-moodle.git
cd skilland-lms-moodle
npm ci
```

You also need Docker (for `npm run test:unit` and the local Moodle) and, for the real Moodle suites, PHP 8.1+ and Composer.

### A local Moodle

`00_development/docker-compose.yml` runs Moodle 4.5 (`MOODLE_405_STABLE`, PHP 8.1) on http://localhost:8081. It mounts `dist/` and copies it into `mod/skilland` each time the container starts:

```bash
npm run build
(cd 00_development && docker compose up -d --build)
```

`00_development/create_test_users.php` creates the `admin`, `teacher1` and `student1` accounts the E2E suite expects.

### Connecting it to a Skilland stack

The Skilland application lives in Skilland's private monorepo; outside contributors can work without it (the E2E suite mocks Skilland, and the PHPUnit suites use fixture clients). With access, `./start.sh --plugin` at the monorepo root starts the Skilland web app on port 3100, which serves the REST API (`/api/moodle/...`), the Studio and the SSO handoff (`/sso-login`), plus this Moodle container.

Configure the plugin at **Site administration › Plugins › Activity modules › Skilland content**:

| Setting | Local value |
|---|---|
| API Key | Generated in Skilland under **Settings › Integrations** (*API keys for the Moodle plugin*) |
| Organization ID | Shown in Skilland under **Settings › Integrations › Moodle** |
| Skilland URL | `http://host.docker.internal:3100` |
| Frontend URL | `http://host.docker.internal:3100`, or empty |
| SSO Shared Secret | Shown in Skilland under **Settings › Integrations › Moodle** |

On the monorepo stack you do not type most of these. The Moodle container passes `MOODLE_SSO_SECRET` (as `SKILLAND_SSO_SECRET`), `SKILLAND_ORG_ID` and the optional `SKILLAND_URL` / `SKILLAND_FRONTEND_URL` (the old `SKILLAND_GRAPHQL_ENDPOINT` still works) from the monorepo `.env` to `00_development/config.php`. When the SSO master secret is set, `config.php`:

- forces Skilland URL and Frontend URL (default `http://host.docker.internal:3100`);
- sets `$CFG->mod_skilland_allow_http = true`, which lets the plugin use `http://` and reach local hosts (`localhost`, `host.docker.internal`, private IPs). Everywhere else both URLs must be `https://`;
- when `SKILLAND_ORG_ID` is set too, forces that Organization ID and the secret Skilland derives for it, `hex(HMAC-SHA256(MOODLE_SSO_SECRET, "skilland:moodle-sso:v1:" + orgId))`. Skilland rejects tokens signed with the master secret itself, so without `SKILLAND_ORG_ID` you paste the organization's secret by hand.

The SSO secret must be at least 32 bytes, and the plugin refuses any value that was ever published as a development default. Generate a master with `openssl rand -base64 32`; never reuse a development value in production.

#### Setting the API from the command line

`cli/configure_api.php` is a development-only helper (not in the release ZIP). It writes only the settings you pass and never takes the key as an option (`--apikey` is rejected): the key comes from `SKILLAND_API_KEY` or, on a terminal, from a prompt with echo off, and is never printed.

```bash
SKILLAND_API_KEY=... php mod/skilland/cli/configure_api.php \
    --endpoint=http://host.docker.internal:3100 --allow-insecure --orgid=<org id>
```

`--endpoint` is the Skilland URL (a trailing `/graphql` or `/api/moodle` is dropped); the script then probes `GET /api/moodle/skills`. An `http://` endpoint needs `--allow-insecure` or `$CFG->mod_skilland_allow_http`.

## Development workflow

1. `npm run watch` copies changes in `src/` to `dist/` and rebuilds the AMD modules.
2. Get `dist/` into Moodle: with the Docker setup, restart the container (`docker compose restart` in `00_development/`) so it copies `dist/` again; with your own Moodle, symlink it once (`ln -s /path/to/repo/dist <moodle>/mod/skilland`).
3. Purge caches (**Site administration › Development › Purge all caches**) after adding files, changing strings or bumping the version.
4. New UI strings go in both `src/lang/en/skilland.php` and `src/lang/es/skilland.php`; `scripts/check_translations.php` and `tests/phpunit/language_parity_test.php` check parity.

### Debugging REST calls

Turn on **Verbose debug logging** in the plugin settings and set Moodle's debug messages to *DEVELOPER*. Then check:

- the `[Skilland] [REST]` lines in Moodle's debugging output (URLs without query strings, never the API key);
- the Skilland web app logs;
- the browser console (AMD modules log through a devmode-gated helper).

## Architecture

```
 Moodle (mod_skilland)                         Skilland (web app)
 ─────────────────────                         ──────────────────
 server-side REST  ── Bearer API key ───────▶  {Skilland URL}/api/moodle/...
 package download  ── allowlisted host ─────▶  presigned package URL
 browser SSO form  ── POST token, redirect ─▶  {Skilland URL}/sso-login ─▶ /skills/<id>
```

### REST client

Every call to Skilland is a REST route under `{Skilland URL}/api/moodle`. The base is `skilland_get_frontend_url()`: the `frontend_url` override, else the `graphql_endpoint` setting, both normalised by `mod_skilland\local\skilland_url::normalise()` (which drops a trailing `/`, `/graphql` or `/api/moodle`). Requests carry `Authorization: Bearer <apikey>` and never follow redirects. Routes:

| Route | Used for |
|---|---|
| `GET skills?status=all` | The organization's course list |
| `GET users/courses?email=` (lower-cased) | The courses a teacher can edit in Skilland |
| `POST skills` | Create a course; sends the SSO token's `sub` / `iss` as `moodleUserId` / `issuer` |
| `GET skills/{id}/topics` | Topics of a course |
| `GET topics/{id}/contents` | Lessons of a topic (lessons with a body only) |
| `GET topics/{id}/scorm-hash` | Content hash, for the update check |
| `GET topics/{id}/scorm` | Package metadata, download URL and signature |

`mod_skilland_rest_get()` retries transient failures (HTTP 429/500/502/503/504, curl connect or timeout errors); `mod_skilland_rest_post()` is sent exactly once, so every new write must be a POST. A failure throws `mod_skilland\rest_exception` with `httpcode` (0 for a transport failure) and `apierror`, the answer's `{error}` code; `tests/phpunit/locallib_rest_test.php` guards this. A 409 from the scorm route (topic without lessons) is `error_scorm_not_available`. There is no GraphQL client any more.

Studio links are native paths, checked by `mod_skilland_is_studio_path()` before one is stored for after the course form saves: `/skills`, `/skills/<id>`, `/skills/<id>?topic=<id>`, `/skills/new?draft=<id>`, nothing else.

### SSO

1. `sso_redirect.php` requires a login, a `sesskey`, a `courseid` and `mod/skilland:accessstudio` in that course, and refuses guests.
2. `skilland_generate_sso_token()` refuses guest, suspended, deleted, unconfirmed and `nologin` accounts (read from the user table at click time) and signs an HS256 JWT with the organization's SSO secret: `sub` = Moodle user id, `iss` = `wwwroot`, `aud` = origin of the frontend URL (Skilland may override the expected value with `MOODLE_SSO_AUDIENCE`), `exp` = `iat` + 60 s, `role` = `Expert` with `mod/skilland:addinstance` in the course, otherwise `Learner`.
3. The page is a self-submitting form that POSTs `token` and `redirect` to `/sso-login` (no query string; `Cache-Control: no-store`, `Referrer-Policy: no-referrer`).
4. Skilland verifies the token, binds the login to (organization, `iss`, `sub`), creates a session and redirects to the Studio path in `redirect`.

### SCORM provisioning

- Provisioning is serialised per activity with a Moodle lock (`mod_skilland/provision_<id>`), is idempotent (a repeat call returns the existing SCORM cmid), creates the module through core `create_module()` (visible, not shown on the course page, no grade) and deletes it again if the package does not parse.
- Every topic package is verified by `mod_skilland\local\package_signature` inside `skilland_download_topic_scorm_package()`, after the download and before `create_file_from_pathname()` or the SCORM parser: an Ed25519 signature (`keyId`, `signature`) over `skilland-scorm-package-v1`, the requested topic id (lower case), `contentHash`, the locally computed sha256 of the zip (which must equal `packageHash`) and `generatedAt`, joined by `\n`. Trusted keys are `package_signature::PINNED_KEYS` plus the `mod_skilland/signingkeys` setting. An unsigned answer is always refused.
- The `sync_content` task never imports: it stores the new content hash in `skilland.updateavailable`, notifies the teachers once per hash and leaves the import to **Update From Skilland** (`skilland_link_topic_scorm()` clears the field).
- Changing a provisioned activity's topic rebuilds its SCORM on save (student progress is reset; the form confirms first, and a failed rebuild drops the activity back to the Prepare state with a warning). Lessons ticked later in the same topic get their SCO from the installed package through the stored lesson-to-SCO map (`skilland.scomappings`); a lesson missing from the package is flagged to teachers until Update From Skilland rebuilds it.
- `skilland_lesson.updatedat` is the lesson version inside the installed SCORM: only `skilland_update_topic_scorm()`, after a successful build, advances it.

Backup and restore are described in [backup-restore.md](backup-restore.md).

## Coding conventions

- **Escaping.** Never pass Skilland API data (course and topic names, descriptions, error messages) or language strings to `innerHTML`, and never build a JS string literal with `addslashes()`. In JS, set text with `textContent` and build markup with `document.createElement`; when a Moodle API only takes HTML (e.g. `notification.addNotification`), wrap the value in the form's `escapeHtml()` helper. From PHP, emit values into JS with `json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)`. HTML meant to be rendered (topic descriptions) is purified with `clean_text()` and declared `PARAM_CLEANHTML`. `tests/phpunit/xss_sinks_test.php` guards this.
- **Lesson selection.** The activity form keeps the selection per topic (`selectionsByTopic`), derives `selected_lessons` only from the ticked checkboxes of the rendered topic, drops lesson responses for a superseded topic (`lessonsRequestSeq`), and `validation()` rejects lessons outside the submitted topic (`skilland_lessons_outside_topic()`); `tests/phpunit/form_lesson_selection_test.php` guards this.
- **Web services** live in `src/classes/external/<function>.php` (one class per function, extending `base`, on the `core_external` API). Each `execute()` calls `self::validate_context()` before `require_capability()`; `tests/phpunit/external_validate_context_test.php` guards this.
- **Errors.** External functions return `self::client_error($e, '<function>')` in `error` from a last `catch (\Throwable $e)`, so a `TypeError` from a malformed API answer becomes the declared error payload (access checks stay outside the try). The raw message goes to the log only; `mod_skilland_client_error_message()` shows allowlisted codes and the generic `error_api_unavailable` otherwise, plus the raw message when devmode is on.
- **Logging.** Log through `mod_skilland\logger` in PHP and the devmode-gated `log` helper in JS. Never log emails or the API key. `tests/phpunit/no_pii_logging_test.php` and `client_errors_test.php` guard this.
- **Privacy.** Any new field sent to Skilland, or any new table with a `userid` field, must be declared in `src/classes/privacy/provider.php`; `tests/phpunit/privacy_provider_test.php` guards this.
- **Style.** Every PHP file under `src/` carries the Moodle GPL header and a `@package mod_skilland` docblock; short array syntax; no hardcoded UI strings. `moodle-plugin-ci phpcs` and `phpdoc` enforce the rest.

## Tests

The plugin has two PHPUnit layers. Use the stub suite for fast feedback and the real Moodle suite for anything that touches the database, capabilities, events or Moodle APIs.

### Stub suite (`tests/phpunit`)

```bash
npm run test:unit
```

Runs in Docker (Composer, then `php:8.2-cli`), config in the root `phpunit.xml`, bootstrap `tests/phpunit/bootstrap.php`. Moodle is replaced by hand-written stubs in `tests/phpunit/stubs`, so a passing run proves the plugin's own logic, not its integration with Moodle. In particular `FakeDatabase` answers raw SQL (`get_record_sql`, `get_records_sql`) with nothing unless a test installs a canned handler, and `get_records_select` with the whole unfiltered table, so SQL paths are only exercised on their empty path. It runs in the pre-commit hook and in the `test` job of `ci.yml`.

### Real Moodle suite (`src/tests`)

`advanced_testcase` tests against a real `$DB`, the data generator (`src/tests/generator`) and Behat features (`src/tests/behat`). CI runs them with [moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/) in `.github/workflows/moodle-plugin-ci.yml` (PHP 8.1 + PostgreSQL and PHP 8.3 + MariaDB, on `MOODLE_405_STABLE`). Locally:

```bash
composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4
export PATH="$(cd ci/bin; pwd):$(cd ci/vendor/bin; pwd):$PATH"
moodle-plugin-ci install --plugin ./src --db-host=127.0.0.1
moodle-plugin-ci phpunit --fail-on-warning
moodle-plugin-ci behat --profile chrome
moodle-plugin-ci phpcs --max-warnings 0
moodle-plugin-ci phpdoc --max-warnings 0
moodle-plugin-ci validate
```

Or put `src/` into a Moodle 4.5 checkout as `mod/skilland` and use Moodle's own runners:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite mod_skilland_testsuite
php admin/tool/behat/cli/init.php
vendor/bin/behat --config <behat_dataroot>/behatrun/behat/behat.yml --tags=@mod_skilland
```

In the Moodle suite, `fixture_api_client` signs its package with a test-only key derived from a fixed seed (`fixture_key_line()`), trusted by `skilland_testcase`, the generator and the Behat background; `sign_with()`, `tamper_download()` and `sign_scorm()` build the refusal cases.

### Seams instead of test hooks

Production code never carries `$GLOBALS` test hooks. Everything a test needs to replace is a service resolved from Moodle's DI container (`\core\di`):

| Seam | Default | Used by |
|---|---|---|
| `\mod_skilland\local\api_client` | `http_api_client`, bound in `mod_skilland\hooks::di_configuration()` (registered in `db/hooks.php`); on a Behat site `local\testing\fixture_api_client` | `mod_skilland_rest_get()`, `mod_skilland_rest_post()`, `mod_skilland_download_package()`: every call to Skilland |
| `\mod_skilland\local\topic_scorm_updater` | itself (wraps `skilland_update_topic_scorm()`) | `skilland_update_instance()`, the `update_topic_scorm` web service |
| `\mod_skilland\local\update_notifier` | itself (sends the `contentupdate` message to users with `mod/skilland:provision`) | the `sync_content` task |
| `\mod_skilland\local\retry_sleeper` | itself (`usleep`) | `mod_skilland_retry_sleep()` between REST GET retries |

A test replaces one with `\core\di::set(api_client::class, $fake)`; `advanced_testcase` resets the container between tests. The stub suite ships a `\core\di` stub with the same `get` / `set` / `reset_container` API plus doubles in `tests/phpunit/stubs/test_doubles.php`: `fake_api_client` (canned responses keyed by the route's last path segment, `respond_rest()` for GETs and `respond_post()` for POSTs; `fake_api_client::topic_snapshot($hash)` for the `scorm-hash` route), `fake_topic_scorm_updater`, `fake_update_notifier`, `test_package_signer` (signs a scorm answer with a test-only key and trusts that key) and `recording_retry_sleeper` (the default, so no stub test sleeps). A stub test that binds a double calls `\core\di::reset_container()` in `setUp()` and `tearDown()`.

### JavaScript

```bash
npm run lint             # ESLint over tests/e2e and src/amd/src
npm run test:e2e:unit    # Node harness for the AMD modules and the E2E mock
```

## E2E tests

The Playwright suite in `tests/e2e` needs **only a running Moodle** with the plugin installed: no Skilland backend. It is local only (no CI job).

```bash
npm run build                                        # dist/, mounted into the Moodle container
(cd 00_development && docker compose up -d --build)  # Moodle on http://localhost:8081
npm run test:e2e                                     # MOODLE_URL overrides the Moodle address
```

`setup/global-setup.js` fails the run when Moodle is not reachable, and `loginToMoodle` throws `Moodle login failed for <user>` instead of carrying on logged out. The suite expects the accounts from `00_development/create_test_users.php`. The SSO specs set an organization id on the settings page when it is empty and sign tokens with the configured (or `config.php`-forced) SSO secret.

**Skilland is always mocked.** The `skillandMock` fixture (`fixtures/skilland-mock.js`, auto-used through `fixtures/auth.js`) intercepts Moodle's AJAX endpoint (`/lib/ajax/service.php`) and answers every `mod_skilland_*` call in the batch from per-test handlers; core Moodle calls in the same batch still reach Moodle. Browser navigations to a Skilland origin (`SKILLAND_URL`, default `http://localhost:3000`, plus `https://app.skilland.ai`) land on a stub page; the SSO form POST to `/sso-login` is recorded with its method, URL and form fields:

```js
test('lists topics', async ({ authenticatedPage, skillandMock, moodleCourse }) => {
  const courseId = await moodleCourse.create({ skillId: SKILL_ID })  // created and deleted for this test
  skillandMock.on('mod_skilland_fetch_topics_ajax', skillandData.topics())
  skillandMock.fail('mod_skilland_fetch_lessons_ajax', 'boom')        // Moodle web service exception
  // skillandMock.abort(method): network failure; skillandMock.on(method, args => data) for dynamic answers
  // skillandMock.calls(method): args the page sent; skillandMock.navigations(): Skilland URLs opened
  // skillandMock.ssoRequests(): [{ method, url, form: { token, redirect } }] posted to /sso-login
})
```

Only `mod_skilland_fetch_courses_ajax` has a default answer, because every course form calls it. The default payloads live in `fixtures/skilland-data.js` and must keep the shape of the matching `execute_returns()` in `src/classes/external/<function>.php`: change both in the same commit.

**Tests fail loudly.** A test fails when the page calls a `mod_skilland_*` method it did not mock, logs a `console.error`, or throws an uncaught error. Allow an expected one with `expectConsoleError(/pattern/)`; `skillandMock.abort()` allows the `net::ERR_FAILED` it causes. Use `test.skip` only for a real environment toggle, and wait on `expect(...)`, `waitForURL` or `expect.poll` rather than `waitForTimeout`.

Server-side behaviour against Skilland's REST API (saving an activity, provisioning and updates, the `sync_content` task) cannot be reached from the browser mock and is covered by PHPUnit.

Every spec creates its own Moodle course through the `moodleCourse` fixture, so a spec passes alone (`npx playwright test --config=tests/e2e/playwright.config.js --grep "<title>"`) and in parallel (`--workers=4`); the default stays `workers: 1`.

## CI and releases

### On every pull request to `main`

`.github/workflows/ci.yml`:

- **`test`**: AMD build freshness (`src/amd/build/` must match a fresh build), `npm run lint`, `npm run test:e2e:unit`, `php -l` on every PHP file under `src/`, `cli/` and `scripts/` (vendor excluded) in a `php:8.2-cli` container, `npm run test:unit`, and `npm run check:vendor-drift`.
- **`version`** (pull requests only): `node scripts/check_version.js` against the PR base, then a gitleaks scan of the source tree.

`.github/workflows/moodle-plugin-ci.yml`: the real Moodle PHPUnit and Behat suites, plus phpcs, phpdoc and validate.

### Version rules

Every merge to `main` publishes a release, so the version check requires, against the base branch's `src/version.php`:

1. `$plugin->version` higher than the base (format `YYYYMMDDXX`);
2. a different `$plugin->release` string;
3. `$plugin->maturity` matching the release string: `-alpha` → `MATURITY_ALPHA`, `-beta` → `MATURITY_BETA`, `-rc` → `MATURITY_RC`, anything else → `MATURITY_STABLE`.

Run it locally with `BASE_REF=origin/main node scripts/check_version.js`. `SKIP_VERSION_CHECK=1` skips it.

### Cutting a release

1. In the pull request, bump `$plugin->version` and `$plugin->release` in `src/version.php` (and `$plugin->maturity` if the suffix changes).
2. Add a `## [<release>]` section to `CHANGELOG.md`, for example `## [0.9.42-beta]`. The release workflow copies that section into the GitHub release notes.
3. Merge to `main`.

`.github/workflows/release.yml` then runs on the push to `main`:

1. Runs `ci.yml` as a reusable workflow; the release job waits for it.
2. Builds `dist/` with Node 20, re-checks that `dist/version.php` matches `src/version.php` and that maturity matches the release suffix.
3. Scans `dist/` for secrets with gitleaks.
4. Copies `dist/` to `skilland/` and zips it as `mod_skilland-<$plugin->version>.zip`.
5. Smoke-tests the ZIP: `version.php`, `lib.php`, the Composer autoloader and `firebase/php-jwt` present; one minified AMD module per source; no `tests/`, `node_modules/`, PHPUnit vendor packages or `.env`.
6. Tags `v<$plugin->version>` and publishes a GitHub release named `Release <release> (<version>)`, marked as a pre-release when the release string ends in `-alpha`, `-beta` or `-rc`.

A tag that already points at the pushed commit without a release is reused; a tag or release that belongs to a different commit fails the run instead of skipping it. Actions are pinned by commit SHA and the gitleaks image by version tag.

## Git hooks

Husky runs `.husky/pre-commit` on every commit:

1. Refuses a commit that adds or modifies files under `dist/` (it is built by CI).
2. `npm run lint`.
3. `npm run test:unit` (PHPUnit in Docker).
4. `npm run check:vendor-drift`.

The version bump is enforced by CI on the pull request, not by the hook.
