# Skilland Moodle Plugin - Local Development Setup

This guide helps you set up the Skilland Moodle plugin for local development.

## Prerequisites

1. **Docker & Docker Compose** - for running Skilland Universe
2. **Moodle** - local Moodle installation (4.0+)
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

## Git Hooks

This project uses **Husky** to manage git hooks.

### Pre-commit Hook
On every commit, the following checks run automatically:

1.  **Version Check**: Verifies that `$plugin->version` in `src/version.php` has been increased.
2.  **Auto-Build**: Runs `npm run build` to update the `dist/` directory.
3.  **Add to Commit**: Automatically adds the updated `dist/` files to your commit.

### Bypassing Checks
If you need to make a commit without bumping the version (e.g., updating documentation) but **still want the build to run**, use the `SKIP_VERSION_CHECK` environment variable:

```bash
SKIP_VERSION_CHECK=1 git commit -m "Update docs"
```

If you want to skip **everything** (including the build), use `--no-verify`:
```bash
git commit -m "WIP" --no-verify
```

