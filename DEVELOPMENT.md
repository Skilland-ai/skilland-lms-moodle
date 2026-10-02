# Skilland Moodle Plugin - Local Development Setup

This guide helps you set up the Skilland Moodle plugin for local development.

## Prerequisites

1. **Docker & Docker Compose** - for running the Skilland monorepo dev stack
2. **Moodle** - local Moodle installation (4.2+)
3. **SCORM module** - enabled in Moodle

## Quick Setup

### 1. Start Skilland

From the monorepo root:

```bash
./start.sh --plugin
```

This starts the Skilland web app (Next.js) on port 3100, which serves the REST API the plugin
calls (`/api/moodle/...`), the Studio and the SSO handoff (`/sso-login`), plus a Moodle
container with this plugin.

### 2. Configure Moodle Plugin

1. Navigate to **Site administration → Plugins → Activity modules → Skilland content**

2. Configure the following settings:

   | Setting | Value | Description |
   |---------|-------|-------------|
   | **API Key** | (from backend) | Your Skilland API key |
   | **Organization ID** | (from backend) | Your organization ID |
   | **Skilland URL** | `http://host.docker.internal:3100` | Address of the Skilland site; the REST API lives under `/api/moodle` (config key `graphql_endpoint`; a stored `/graphql` or `/api/moodle` suffix is ignored) |
   | **Frontend URL** | (empty, the default) | Empty uses the Skilland URL; set it only when Moodle must reach Skilland through a different address than browsers |
   | **SSO Shared Secret** | (from Skilland › Settings › Integrations › Moodle) | Your organization's SSO secret, which Skilland derives from `MOODLE_SSO_SECRET` |

3. Click **Save changes**

   On the monorepo dev stack (`./start.sh --plugin`) you do not type these by hand: the
   Moodle container reads `MOODLE_SSO_SECRET` and `SKILLAND_ORG_ID` (plus the optional
   `SKILLAND_URL` and `SKILLAND_FRONTEND_URL`; the old `SKILLAND_GRAPHQL_ENDPOINT` still works) from the monorepo `.env` and
   `00_development/config.php` forces them as plugin settings. The SSO secret is forced only
   when `SKILLAND_ORG_ID` is set: config.php then forces that Organization ID and the secret
   Skilland derives for it, `hex(HMAC-SHA256(MOODLE_SSO_SECRET, "skilland:moodle-sso:v1:" + orgId))`.
   With `MOODLE_SSO_SECRET` alone, copy the secret from Skilland › Settings › Integrations ›
   Moodle; Skilland rejects tokens signed with the master secret itself. `http://` URLs are
   accepted only there, because that config sets `$CFG->mod_skilland_allow_http = true`;
   everywhere else the Skilland URL and frontend URL must use `https://`.

   Outbound requests keep Moodle's curl security (blocked hosts and ports) on and never
   follow redirects. A local `http://` or Docker endpoint (`localhost`, `host.docker.internal`,
   `skilland-back`, private IPs) is reachable only with `$CFG->mod_skilland_allow_http`, which
   `00_development/config.php` sets. SCORM packages must come from the Skilland URL host, the
   frontend URL host or a host listed in the **SCORM package hosts** setting (`mod_skilland/package_hosts`), and are
   rejected above `mod_skilland/package_max_mb` or when they are not a zip.

   The secret must be at least 32 bytes. The plugin rejects shorter values and any secret
   that was ever published as a development default.

### 3. Get Your Organization ID and API Key

You need to get these from your Skilland backend:

#### Option A: Using existing organization

```bash
# Connect to MongoDB
docker exec -it skilland-mongo mongosh -u skilland -p skilland skilland

# Find your organization
db.organizations.findOne({}, { _id: 1, name: 1 })

# Copy the _id value - this is your Organization ID
```

#### Option B: Generate API Key

```bash
# Execute inside the backend container
docker exec -it skilland-back node /app/db/generate-api-key.js <YOUR_ORG_ID>
```

This will output an API key that you can use in the Moodle plugin settings.

#### Setting them from the command line

`cli/configure_api.php` is a development-only helper: `npm run build` leaves it out of
`dist/`, so it is never in the release zip. It writes only the settings you pass and never
takes the key as an option (`--apikey` is rejected): the key comes from the
`SKILLAND_API_KEY` environment variable or, on a terminal, from a prompt with echo off, and
is never printed.

```bash
SKILLAND_API_KEY=... php mod/skilland/cli/configure_api.php \
    --endpoint=http://host.docker.internal:3100 --allow-insecure --orgid=<YOUR_ORG_ID>
```

`--endpoint` is the Skilland URL (a trailing `/graphql` or `/api/moodle` is dropped); the
script then probes `GET /api/moodle/skills`. An `http://` endpoint needs `--allow-insecure` (or `$CFG->mod_skilland_allow_http` in
`config.php`); anything else must be `https://`.

The course custom field (`skilland_course_id`) is created on install, recreated on every
plugin upgrade and the first time a course is mapped; the settings page only reports
whether it exists.

## Testing SSO Login

### 1. Create a Course in Moodle

1. Create a new course or use an existing one
2. Go to **Course settings**
3. Scroll to **Custom fields**
4. Pick a Skilland course in the dropdown, or click **Create in Skilland** and confirm (the course is created and linked at once; the Studio link appears after the save). If the course list cannot load, the plain `skilland_course_id` text field comes back
5. Save

### 2. Create a Skilland Activity

1. Turn editing on in your course
2. Add an activity → **Skilland content**
3. Select a topic from the dropdown
4. Click **Edit Lessons in Skilland** button
5. Moodle shows a short "Signing you in to Skilland…" page that POSTs the token to http://localhost:3100/sso-login (it is never in the URL)
6. The SSO should authenticate you and redirect to the native Studio path, `/skills/<skillId>?topic=<topicId>`

## Troubleshooting

### REST API Connection Issues

If you see "HTTP 0" or connection failed errors:

1. Check that the dev stack is running: `docker ps`
2. Check that the **Skilland URL** points at the web app (`http://host.docker.internal:3100` from the Moodle container)
3. Verify the API answers: `curl -H "Authorization: Bearer <api key>" http://localhost:3100/api/moodle/skills`

### API Key Authentication Failed

If you see "Invalid API key or organization":

1. Verify the Organization ID matches exactly (a UUID)
2. Ensure the API key was generated for the correct organization
3. Check backend logs for detailed error messages

### Token Verification Failed

If SSO login fails with "Invalid SSO token":

1. Verify the plugin's SSO secret is the one Skilland › Settings › Integrations › Moodle shows for
   the organization in the plugin's Organization ID (or that `SKILLAND_ORG_ID` in the monorepo `.env`
   names that organization)
2. A token signed with `MOODLE_SSO_SECRET` itself, or with another organization's secret, is rejected
3. After changing `.env`, recreate the Moodle and web containers so both read the new value

Guest, suspended, deleted, unconfirmed and `nologin` accounts never get a token: Moodle shows
"This Moodle account cannot sign in to Skilland" instead of posting to Skilland.

## Environment Configuration

### SSO shared secret

There is no default secret. Skilland's master secret lives only in the monorepo `.env` as
`MOODLE_SSO_SECRET`; Skilland verifies each token with the organization's own secret derived from it,
`hex(HMAC-SHA256(MOODLE_SSO_SECRET, "skilland:moodle-sso:v1:" + orgId))` (64 lower-case hex
characters), and that derived value is what the plugin's SSO Shared Secret holds. `./start.sh --plugin`
generates the master when it is missing. To have `config.php` force the organization and its secret:

```bash
MOODLE_SSO_SECRET=<generate with openssl rand -base64 32>
SKILLAND_ORG_ID=<your Skilland organization id>
```

Production sites paste their organization's secret from Skilland › Settings › Integrations › Moodle
into the plugin settings; never reuse a development value.

### Moodle Plugin

Settings location: **Site administration → Plugins → Activity modules → Skilland content**

## Development Workflow

1.  **Install Dependencies**: Run `npm install` in the root directory.
2.  **Start Watcher**: Run `npm run watch`. This will watch for changes in `src/` and automatically build/copy them to `dist/`.
3.  **Link/Copy to Moodle**:
    *   **Option A (Symlink)**: Symlink the `dist` folder to your Moodle modules directory.
        `ln -s /path/to/repo/dist /path/to/moodle/mod/skilland`
    *   **Option B (Copy)**: Copy the contents of `dist/` to your Moodle modules directory.
        `cp -r dist/* /path/to/moodle/mod/skilland/`
4.  **Clear cache**: In Moodle, go to **Site administration → Development → Purge all caches** (often needed when adding new files or changing version).
5.  **Test**: Create/edit a Skilland activity to test your changes.

### Coding conventions: escaping output

Never pass Skilland API data (course and topic names, descriptions, error messages) or language strings to `innerHTML`, and never build a JS string literal with `addslashes()`. In inline JS, set text with `textContent` and build markup with `document.createElement`; when a Moodle API only takes HTML (e.g. `notification.addNotification`), wrap the value in the form's `escapeHtml()` helper. From PHP, emit strings and URLs into JS as whole literals with `json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)`. HTML that is meant to be rendered (topic descriptions) is purified server-side with `clean_text()` and declared as `PARAM_CLEANHTML` in the external function's return structure. `tests/phpunit/xss_sinks_test.php` guards these rules (SKL-674).

The activity form keeps the lesson selection per topic (`selectionsByTopic`), derives `selected_lessons` only from the ticked checkboxes of the rendered topic (`updateSelectedState()`), drops lesson responses for a superseded topic (`lessonsRequestSeq`), and `validation()` rejects lessons outside the submitted topic (`skilland_lessons_outside_topic()`); `tests/phpunit/form_lesson_selection_test.php` guards this (SKL-657).

Web services live in `src/classes/external/<function>.php` (one `mod_skilland\external\<function>` class per function, extending `base`, on Moodle 4.2's `core_external` API), and each `execute()` calls `self::validate_context()` before `require_capability()`; `tests/phpunit/external_validate_context_test.php` guards this (SKL-666).

External functions return `self::client_error($e, '<function>')` in `error` from a last `catch (\Throwable $e)`, so a `TypeError` from a malformed API answer becomes the declared error payload (access checks stay outside the try; `tests/phpunit/external_throwable_test.php` guards this, SKL-659) (the raw message goes to the log only; `mod_skilland_client_error_message()` shows allowlisted codes and the generic `error_api_unavailable` otherwise, plus the raw message when devmode is on); inline and AMD JS logs only through a devmode-gated `log` helper; never log emails. `tests/phpunit/no_pii_logging_test.php` and `client_errors_test.php` guard this (SKL-670).

Every call to Skilland is a REST route under `{Skilland URL}/api/moodle` (`skilland_get_frontend_url()`: the `frontend_url` override, else the `graphql_endpoint` setting, both normalised by `mod_skilland\local\skilland_url::normalise()`, which drops a trailing `/`, `/graphql` or `/api/moodle`), authenticated with `Authorization: Bearer <apikey>`, redirects refused (SKL-963): `GET skills?status=all` (course list), `GET users/courses?email=` (lower-cased), `POST skills` (create, sending the SSO token's `sub`/`iss` as `moodleUserId`/`issuer`), `GET skills/{id}/topics`, `GET topics/{id}/contents` (lessons with a body only), `GET topics/{id}/scorm-hash`, `GET topics/{id}/scorm` and `POST connection-check` (the admin **Test connection** page, `mod_skilland\local\connection_check`: body `{organizationId, ssoProof: {timestamp, nonce, signature} | null}`, the signature being `hex(HMAC-SHA256(sso_secret, "skilland:moodle-connection-check:v1\n" + lower(trim(orgid)) + "\n" + timestamp + "\n" + nonce))`; `tests/phpunit/connection_check_test.php` holds the shared test vector). `mod_skilland_rest_get()` retries on transient failures (HTTP 429/500/502/503/504, curl connect/timeout errors); `mod_skilland_rest_post()` is sent exactly once, so every new write must be a POST. A failure throws `mod_skilland\rest_exception` carrying `httpcode` (0 for a transport failure) and `apierror`, the answer's `{error}` code; `tests/phpunit/locallib_rest_test.php` guards this. A 409 from the scorm route (topic without lessons) is `error_scorm_not_available`; there is no GraphQL fallback any more.

Studio links are native paths, checked by `mod_skilland_is_studio_path()` before one is stored for after the course form saves: `/skills`, `/skills/<id>`, `/skills/<id>?topic=<id>`, `/skills/new?draft=<id>`, nothing else.

Every topic SCORM package is verified by `mod_skilland\local\package_signature` inside `skilland_download_topic_scorm_package()`, after the download and before `create_file_from_pathname()` or the SCORM parser: an Ed25519 signature (`keyId`, `signature`) over `skilland-scorm-package-v1`, the requested topic id (lower case), `contentHash`, the locally computed sha256 of the zip (which must equal `packageHash`) and `generatedAt`, joined by `\n`. Trusted keys are `package_signature::PINNED_KEYS` plus the `mod_skilland/signingkeys` setting (`keyid:base64publickey` lines). An unsigned answer is always refused. The `sync_content` task never imports: it stores the new content hash in `skilland.updateavailable`, notifies the teachers once per hash and leaves the import to **Update From Skilland** (`skilland_link_topic_scorm()` clears the field). In the Moodle suite, `fixture_api_client` signs its package with a test-only key derived from a fixed seed (`fixture_key_line()`), trusted by `skilland_testcase`, the generator and the Behat background; `sign_with()`, `tamper_download()` and `sign_scorm()` build the refusal cases (SKL-650).

Any new field sent to Skilland, or any new table with a `userid` field, must be declared in `src/classes/privacy/provider.php`; `tests/phpunit/privacy_provider_test.php` guards this (SKL-660).

## Common Development Tasks

### Debugging REST Calls

Enable debug mode in Moodle plugin settings and check:
- the `[REST]` lines in the Moodle debug log (URLs without query strings, never the API key)
- the Skilland web app logs
- Browser console for frontend errors

### Testing SCORM Provisioning

1. Create a Skilland activity with lessons selected
2. Save the activity
3. Check the SCORM activity was created (should be hidden)
4. Check logs for provisioning details

Provisioning is serialized per activity with a Moodle lock (`mod_skilland/provision_<id>`), is idempotent (a repeat call returns the existing SCORM cmid), creates the module through core `create_module()` and deletes it again if the package does not parse; `mod_scorm` is a declared dependency in `version.php`.

Changing a provisioned activity's topic rebuilds its SCORM on save (student progress is reset; the form confirms first, and a failed rebuild drops the activity back to the Provision state with a warning). Lessons ticked later in the same topic get their SCO from the installed package through the stored lesson-to-SCO map (`skilland.scomappings`); a lesson missing from the package is flagged to teachers until Update From Skilland rebuilds it (SKL-655).

Lesson codes (`L<topic>.<n>`) come from one helper, `skilland_lesson_label()` in `locallib.php`, used by the lesson list, the player header and the navigation: `n` is the lesson's 1-based position in the topic in Skilland (`skilland_lesson.skillandposition`, the index in `mod_skilland_fetch_lessons()`'s list, so a left-out lesson leaves a gap), and 0 (unknown, e.g. before the `backfill_lesson_positions` ad-hoc task ran) falls back to `orderindex`. The activity form labels lessons with the same position and posts it with each selected lesson; `skilland_update_topic_scorm()` refreshes it after a reorder. `tests/phpunit/lesson_label_test.php` and `src/tests/lesson_codes_test.php` guard this (SKL-694).

`skilland_lesson.updatedat` is the lesson version inside the installed SCORM: only `skilland_update_topic_scorm()`, after a successful build, advances it; the settings form never does (new lessons are inserted with the submitted value) (SKL-683).

### Updating Language Strings

1. Edit `lang/en/skilland.php` and `lang/es/skilland.php`
2. Copy updated files to Moodle installation
3. Purge caches in Moodle

## Architecture

```
┌─────────────┐
│   Moodle    │
│  (Plugin)   │
└──────┬──────┘
       │
       │ API Key (Bearer)
       │ REST /api/moodle
       ▼
┌─────────────┐      ┌─────────────┐
│  Skilland   │◄────►│ PostgreSQL  │
│  (Next.js)  │      │             │
│ (Port 3100) │      └─────────────┘
└─────────────┘

SSO Flow:
1. Moodle generates SSO token with the organization's SSO secret: sub = Moodle user id,
   exp = iat + 60 s, aud = origin of frontend_url (Skilland may override the expected value
   with MOODLE_SSO_AUDIENCE), iss = Moodle wwwroot. Guest, suspended, deleted, unconfirmed
   and nologin accounts are refused. sso_redirect.php requires `courseid` and
   `mod/skilland:accessstudio` in that course; role = Expert with `mod/skilland:addinstance`
   there, otherwise Learner
2. sso_redirect.php answers with a self-submitting form that POSTs token and redirect to
   Skilland /sso-login (no query string; Cache-Control: no-store, Referrer-Policy: no-referrer)
3. Skilland verifies the token, binds the login to (organization, iss, sub) and creates a session
4. Skilland redirects to the native Studio path in `redirect` (/skills/<id>[?topic=<id>])
```

## Support

For issues or questions:
1. Check the logs (backend and Moodle)
2. Review this guide and the main README
3. Contact the development team

## Tests

The plugin has two PHPUnit layers. Use the stub suite for fast feedback and the real Moodle suite for anything that touches the database, capabilities, events or the Moodle APIs themselves.

### Stub suite (`tests/phpunit`)

A fast smoke layer that needs no Moodle install: `npm run test:unit` (Composer and `php:8.2-cli` in Docker, config in the root `phpunit.xml`, bootstrap `tests/phpunit/bootstrap.php`). Moodle is replaced by hand-written stubs in `tests/phpunit/stubs`, so a passing run proves the plugin's own logic, not its integration with Moodle. In particular `FakeDatabase` answers raw SQL (`get_record_sql`, `get_records_sql`) with nothing unless a test installs a canned handler, and `get_records_select` with the whole unfiltered table, so SQL paths are only exercised on their empty path. It runs in the pre-commit hook and in the `test` job of `ci.yml`.

### Real Moodle suite (`src/tests`)

`advanced_testcase` tests against a real `$DB`, the data generator (`src/tests/generator`) and Behat features (`src/tests/behat`). CI runs them with moodle-plugin-ci in `.github/workflows/moodle-plugin-ci.yml`. The stub suite passing says nothing about them (SKL-963's `topic_scorm_provisioning_test` and `add_activity.feature` only failed in CI), so run them before pushing a change that touches `src/`:

```bash
scripts/moodle-core-tests.sh phpunit [filter]   # mod_skilland_testsuite, --filter is optional
scripts/moodle-core-tests.sh behat [name]       # @mod_skilland, a scenario name is optional
scripts/moodle-core-tests.sh down               # stop the stack and drop its volumes
```

The script is opt-in and self-contained: `core-tests/docker-compose.yml` brings up its own MariaDB 11, a Moodle 4.5 image (PHP 8.3, `core-tests/Dockerfile`) and, for Behat, `selenium/standalone-chrome`, in a separate Compose project (`skilland-core-tests`) that never touches the `00_development` site. Each run syncs `src/` into `mod/skilland` and stages the root `vendor/` into it, as CI does, then calls Moodle's own `admin/tool/phpunit/cli/init.php` / `admin/tool/behat/cli/init.php` and runs `vendor/bin/phpunit --testsuite mod_skilland_testsuite --fail-on-warning` or `vendor/bin/behat --profile chrome --tags=@mod_skilland`. The first run builds the image and initialises the test sites (several minutes, a few GB of Docker disk); later runs are much faster. Behat failure dumps land in `/var/behatdata/faildump` inside the `moodle` container. It does not cover phpcs, phpdoc or `validate` (the `lint` job of the same workflow).

### Seams instead of test hooks

Production code never carries `$GLOBALS` test hooks. Everything a test needs to replace is a service resolved from Moodle's DI container (`\core\di`, Moodle 4.4+):

| Seam | Default | Used by |
|------|---------|---------|
| `\mod_skilland\local\api_client` | `http_api_client`, bound in `mod_skilland\hooks::di_configuration()` (registered in `db/hooks.php`); on a Behat site `local\testing\fixture_api_client` | `mod_skilland_rest_get()`, `mod_skilland_rest_post()`, `mod_skilland_download_package()` - every call to Skilland |
| `\mod_skilland\local\topic_scorm_updater` | itself (wraps `skilland_update_topic_scorm()`) | `skilland_update_instance()`, the `update_topic_scorm` web service |
| `\mod_skilland\local\update_notifier` | itself (sends the `contentupdate` message to users with `mod/skilland:provision`) | the `sync_content` task |
| `\mod_skilland\local\retry_sleeper` | itself (`usleep`) | `mod_skilland_retry_sleep()` between REST GET retries |

A test replaces one with `\core\di::set(api_client::class, $fake)`; Moodle's `advanced_testcase` resets the container between tests. The stub suite ships a `\core\di` stub with the same `get` / `set` / `reset_container` API plus doubles in `tests/phpunit/stubs/test_doubles.php`: `fake_api_client` (canned responses keyed by the REST route's last path segment, `respond_rest()` for GETs and `respond_post()` for POSTs, falling back to the curl stub; `fake_api_client::topic_snapshot($hash)` for the `scorm-hash` route), `fake_topic_scorm_updater` (a fixed cmid or a closure), `fake_update_notifier` (records each call; a fixed count or a closure), `test_package_signer` (signs a scorm answer for given zip bytes with a test-only key and trusts that key in the test's config) and `recording_retry_sleeper` (the suite default, so no stub test ever sleeps). A stub test that binds a double calls `\core\di::reset_container()` in `setUp()` and `tearDown()`.

## E2E tests

The Playwright suite in `tests/e2e` needs **only a running Moodle** with the plugin installed: no Skilland backend, frontend or database. It is local only (no CI job).

```bash
npx grunt build                                      # dist/, mounted into the Moodle container
(cd 00_development && docker compose up -d --build)  # Moodle on http://localhost:8081
npm run test:e2e                                     # MOODLE_URL overrides the Moodle address
node --test tests/e2e/unit/*.test.js                 # unit tests of the mock itself
```

`setup/global-setup.js` fails the run when Moodle is not reachable, and `loginToMoodle` throws `Moodle login failed for <user>` instead of carrying on logged out. The suite expects the `admin`, `teacher1` and `student1` accounts from `00_development/create_test_users.php`. The SSO specs set an organization id on the plugin settings page when it is empty and sign tokens with the configured (or `config.php`-forced) SSO secret.

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

**Tests fail loudly.** A test fails when the page calls a `mod_skilland_*` method it did not mock, logs a `console.error`, or throws an uncaught error. Allow an expected one with `expectConsoleError(/pattern/)` (or `skillandMock.expectConsoleError`); `skillandMock.abort()` allows the `net::ERR_FAILED` it causes. Use `test.skip` only for a real environment toggle, never to hide a missing precondition, and wait on `expect(...)`, `waitForURL` or `expect.poll` rather than `waitForTimeout`.

Behaviour that runs server-side against Skilland's REST API (saving an activity, SCORM provisioning and updates, the `sync_content` task) cannot be reached from the browser mock and is covered by PHPUnit (see [Tests](#tests)).

Every spec creates its own Moodle course through the `moodleCourse` fixture, so a spec passes alone (`npx playwright test --config=tests/e2e/playwright.config.js --grep "<title>"`) and in parallel (`--workers=4`); the default stays `workers: 1`.

## CI and releases

Every pull request to `main` runs `.github/workflows/ci.yml`:

- **`test`**: `npm run lint` (ESLint over `tests/e2e` and the AMD sources in `src/amd/src`), `php -l` on every PHP file under `src/`, `cli/` and `scripts/` (vendor excluded) in a `php:8.2-cli` container, then PHPUnit via `npm run test:unit`.
- **`version`** (pull requests only): `node scripts/check_version.js` against the PR base, then a gitleaks scan of the source tree.

Every merge to `main` publishes a release, so the version check requires, against the base branch's `src/version.php`:

1. `$plugin->version` higher than the base (format `YYYYMMDDXX`);
2. a different `$plugin->release` string;
3. `$plugin->maturity` matching the release string: `alpha` → `MATURITY_ALPHA`, `beta` → `MATURITY_BETA`, `rc` → `MATURITY_RC`, anything else → `MATURITY_STABLE`.

Run it locally with `BASE_REF=origin/main node scripts/check_version.js` (without `BASE_REF` it compares against `HEAD`). `SKIP_VERSION_CHECK=1` skips it.

`.github/workflows/release.yml` runs on every push to `main`. Its `release` job needs the same `ci.yml` (called as a reusable workflow) to pass first, then builds `dist/`, re-checks version and maturity, scans `dist/` for secrets, zips it as `skilland/`, smoke-tests the zip (required files, one minified AMD module per source, no `tests/`, `node_modules/`, dev vendor packages or `.env`), and tags `v<$plugin->version>` with a GitHub release. A tag that already points at the pushed commit without a release is reused; a tag or release that belongs to a different commit fails the run instead of skipping it. Actions are pinned by commit SHA and the gitleaks image by version tag.

## Git Hooks

This project uses **Husky** to manage git hooks.

### Pre-commit Hook
On every commit, `.husky/pre-commit`:

1.  **Blocks `dist/`**: refuses a commit that adds or modifies files under `dist/` (it is built by CI).
2.  **Lint**: runs `npm run lint`.
3.  **Unit tests**: runs `npm run test:unit` (PHPUnit in Docker).

The version bump is enforced by CI on the pull request, not by the hook.

