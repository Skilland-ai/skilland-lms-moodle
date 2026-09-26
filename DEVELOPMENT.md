# Skilland Moodle Plugin - Local Development Setup

This guide helps you set up the Skilland Moodle plugin for local development.

## Prerequisites

1. **Docker & Docker Compose** - for running Skilland Universe
2. **Moodle** - local Moodle installation (4.2+)
3. **SCORM module** - enabled in Moodle

## Quick Setup

### 1. Start Skilland Universe

```bash
cd skillandUniverse
docker-compose up -d
```

This starts:
- **MongoDB** on port 27017
- **Backend** on port 8000 (GraphQL: http://localhost:8000/graphql)
- **Frontend** on port 3000 (http://localhost:3000)
- **Langfuse** on port 3001

### 2. Configure Moodle Plugin

1. Navigate to **Site administration → Plugins → Activity modules → Skilland content**

2. Configure the following settings:

   | Setting | Value | Description |
   |---------|-------|-------------|
   | **API Key** | (from backend) | Your Skilland API key |
   | **Organization ID** | (from backend) | Your organization ID |
   | **GraphQL Endpoint** | `http://localhost:8000/graphql` | Backend API endpoint |
   | **Frontend URL** | `http://localhost:3000` | Frontend URL for SSO redirects |
   | **SSO Shared Secret** | `<generate with openssl rand -base64 32>` | Must equal `MOODLE_SSO_SECRET` in the monorepo `.env` |

3. Click **Save changes**

   On the monorepo dev stack (`./start.sh --plugin`) you do not type these by hand: the
   Moodle container reads `MOODLE_SSO_SECRET` (plus the optional `SKILLAND_GRAPHQL_ENDPOINT`
   and `SKILLAND_FRONTEND_URL`) from the monorepo `.env` and `00_development/config.php`
   forces them as plugin settings. `http://` URLs are accepted only there, because that
   config sets `$CFG->mod_skilland_allow_http = true`; everywhere else the endpoint and
   frontend URL must use `https://`.

   Outbound requests keep Moodle's curl security (blocked hosts and ports) on and never
   follow redirects. A local `http://` or Docker endpoint (`localhost`, `host.docker.internal`,
   `skilland-back`, private IPs) is reachable only with `$CFG->mod_skilland_allow_http`, which
   `00_development/config.php` sets. SCORM packages must come from the GraphQL endpoint host or
   a host listed in the **SCORM package hosts** setting (`mod_skilland/package_hosts`), and are
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

## Testing SSO Login

### 1. Create a Course in Moodle

1. Create a new course or use an existing one
2. Go to **Course settings**
3. Scroll to **Custom fields**
4. Enter your Skilland Course ID in the `skilland_course_id` field
5. Save

### 2. Create a Skilland Activity

1. Turn editing on in your course
2. Add an activity → **Skilland content**
3. Select a topic from the dropdown
4. Click **Edit Lessons in Skilland** button
5. You should be redirected to http://localhost:3000/sso-login with a token
6. The SSO should authenticate you and redirect to the topic editor

## Troubleshooting

### SSO Authentication Errors

If you see errors like "undefined orgId" or "undefined apikey" in the console:

**Cause**: The frontend URL is not configured correctly, and it's trying to use the backend URL (port 8000) instead of the frontend URL (port 3000).

**Solution**: Ensure the **Frontend URL** setting in Moodle is set to `http://localhost:3000`

### GraphQL Connection Issues

If you see "HTTP 0" or connection failed errors:

1. Check that docker containers are running: `docker ps`
2. Check backend logs: `docker logs skilland-back`
3. Verify the GraphQL endpoint is accessible: `curl http://localhost:8000/graphql`

### API Key Authentication Failed

If you see "Invalid API key or organization":

1. Verify the Organization ID matches exactly (MongoDB ObjectId)
2. Ensure the API key was generated for the correct organization
3. Check backend logs for detailed error messages

### Token Verification Failed

If SSO login fails with "Invalid SSO token":

1. Verify `MOODLE_SSO_SECRET` in the monorepo `.env` matches the plugin setting
2. Check that the secret is the same in both places (case-sensitive)
3. After changing `.env`, recreate the Moodle and web containers so both read the new value

## Environment Configuration

### SSO shared secret

There is no default secret. It lives only in the monorepo `.env` as `MOODLE_SSO_SECRET`,
which both compose files read (the web app to verify tokens, the Moodle container to
sign them). `./start.sh --plugin` generates one when it is missing. To set it yourself:

```bash
MOODLE_SSO_SECRET=<generate with openssl rand -base64 32>
```

Production sites set their own secret in the plugin settings and in the SkilLand
deployment; never reuse a development value.

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

Never pass SkilLand API data (course and topic names, descriptions, error messages) or language strings to `innerHTML`, and never build a JS string literal with `addslashes()`. In inline JS, set text with `textContent` and build markup with `document.createElement`; when a Moodle API only takes HTML (e.g. `notification.addNotification`), wrap the value in the form's `escapeHtml()` helper. From PHP, emit strings and URLs into JS as whole literals with `json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)`. HTML that is meant to be rendered (topic descriptions) is purified server-side with `clean_text()` and declared as `PARAM_CLEANHTML` in the external function's return structure. `tests/phpunit/xss_sinks_test.php` guards these rules (SKL-674).

The activity form keeps the lesson selection per topic (`selectionsByTopic`), derives `selected_lessons` only from the ticked checkboxes of the rendered topic (`updateSelectedState()`), drops lesson responses for a superseded topic (`lessonsRequestSeq`), and `validation()` rejects lessons outside the submitted topic (`skilland_lessons_outside_topic()`); `tests/phpunit/form_lesson_selection_test.php` guards this (SKL-657).

Web services live in `src/classes/external/<function>.php` (one `mod_skilland\external\<function>` class per function, extending `base`, on Moodle 4.2's `core_external` API), and each `execute()` calls `self::validate_context()` before `require_capability()`; `tests/phpunit/external_validate_context_test.php` guards this (SKL-666).

External functions return `self::client_error($e, '<function>')` in `error` from a last `catch (\Throwable $e)`, so a `TypeError` from a malformed API answer becomes the declared error payload (access checks stay outside the try; `tests/phpunit/external_throwable_test.php` guards this, SKL-659) (the raw message goes to the log only; `mod_skilland_client_error_message()` shows allowlisted codes and the generic `error_api_unavailable` otherwise, plus the raw message when devmode is on); inline and AMD JS logs only through a devmode-gated `log` helper; never log emails. `tests/phpunit/no_pii_logging_test.php` and `client_errors_test.php` guard this (SKL-670).

Any new field sent to SkilLand, or any new table with a `userid` field, must be declared in `src/classes/privacy/provider.php`; `tests/phpunit/privacy_provider_test.php` guards this (SKL-660).

## Common Development Tasks

### Debugging GraphQL Queries

Enable debug mode in Moodle plugin settings and check:
- `error_log()` calls in PHP files
- Backend logs: `docker logs -f skilland-back`
- Browser console for frontend errors

### Testing SCORM Provisioning

1. Create a Skilland activity with lessons selected
2. Save the activity
3. Check the SCORM activity was created (should be hidden)
4. Check logs for provisioning details

Provisioning is serialized per activity with a Moodle lock (`mod_skilland/provision_<id>`), is idempotent (a repeat call returns the existing SCORM cmid), creates the module through core `create_module()` and deletes it again if the package does not parse; `mod_scorm` is a declared dependency in `version.php`.

Changing a provisioned activity's topic rebuilds its SCORM on save (student progress is reset; the form confirms first, and a failed rebuild drops the activity back to the Provision state with a warning). Lessons ticked later in the same topic get their SCO from the installed package through the stored lesson-to-SCO map (`skilland.scomappings`); a lesson missing from the package is flagged to teachers until Update From Skilland rebuilds it (SKL-655).

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
       │ API Key Auth
       │ (GraphQL)
       ▼
┌─────────────┐      ┌─────────────┐
│   Backend   │◄────►│   MongoDB   │
│ (Port 8000) │      │ (Port 27017)│
└──────┬──────┘      └─────────────┘
       │
       │ JWT Token
       │
       ▼
┌─────────────┐
│   Frontend  │
│ (Port 3000) │
│  (Vue.js)   │
└─────────────┘

SSO Flow:
1. Moodle generates SSO token with shared secret
2. Redirects to frontend /sso-login?token=...
3. Frontend calls backend ssoLogin mutation
4. Backend verifies token, creates user session
5. Returns JWT token for subsequent requests
6. Frontend stores token and redirects to destination
```

## Support

For issues or questions:
1. Check the logs (backend and Moodle)
2. Review this guide and the main README
3. Contact the development team

## E2E tests

The Playwright suite in `tests/e2e` needs **only a running Moodle** with the plugin installed: no SkilLand backend, frontend or database. It is local only (no CI job).

```bash
npx grunt build                                      # dist/, mounted into the Moodle container
(cd 00_development && docker compose up -d --build)  # Moodle on http://localhost:8081
npm run test:e2e                                     # MOODLE_URL overrides the Moodle address
node --test tests/e2e/unit/*.test.js                 # unit tests of the mock itself
```

`setup/global-setup.js` fails the run when Moodle is not reachable, and `loginToMoodle` throws `Moodle login failed for <user>` instead of carrying on logged out. The suite expects the `admin`, `teacher1` and `student1` accounts from `00_development/create_test_users.php`. The SSO specs set an organization id on the plugin settings page when it is empty and sign tokens with the configured (or `config.php`-forced) SSO secret.

**SkilLand is always mocked.** The `skillandMock` fixture (`fixtures/skilland-mock.js`, auto-used through `fixtures/auth.js`) intercepts Moodle's AJAX endpoint (`/lib/ajax/service.php`) and answers every `mod_skilland_*` call in the batch from per-test handlers; core Moodle calls in the same batch still reach Moodle. Browser navigations to a SkilLand origin (`SKILLAND_URL`, default `http://localhost:3000`, plus `https://app.skilland.ai`) land on a stub page, and the `sso_redirect.php` redirect is recorded instead of followed:

```js
test('lists topics', async ({ authenticatedPage, skillandMock, moodleCourse }) => {
  const courseId = await moodleCourse.create({ skillId: SKILL_ID })  // created and deleted for this test
  skillandMock.on('mod_skilland_fetch_topics_ajax', skillandData.topics())
  skillandMock.fail('mod_skilland_fetch_lessons_ajax', 'boom')        // Moodle web service exception
  // skillandMock.abort(method): network failure; skillandMock.on(method, args => data) for dynamic answers
  // skillandMock.calls(method): args the page sent; skillandMock.navigations(): SkilLand URLs opened
})
```

Only `mod_skilland_fetch_courses_ajax` has a default answer, because every course form calls it. The default payloads live in `fixtures/skilland-data.js` and must keep the shape of the matching `execute_returns()` in `src/classes/external/<function>.php`: change both in the same commit.

**Tests fail loudly.** A test fails when the page calls a `mod_skilland_*` method it did not mock, logs a `console.error`, or throws an uncaught error. Allow an expected one with `expectConsoleError(/pattern/)` (or `skillandMock.expectConsoleError`); `skillandMock.abort()` allows the `net::ERR_FAILED` it causes. Use `test.skip` only for a real environment toggle, never to hide a missing precondition, and wait on `expect(...)`, `waitForURL` or `expect.poll` rather than `waitForTimeout`.

Behaviour that runs server-side against SkilLand's GraphQL API (saving an activity, SCORM provisioning and updates, the `sync_content` task) cannot be reached from the browser mock and is covered by PHPUnit in `tests/phpunit`.

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

