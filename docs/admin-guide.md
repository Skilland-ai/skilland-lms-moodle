# Administrator guide

This guide is for Moodle site administrators who install and run the Skilland content activity module (`mod_skilland`). Teachers should read the [teacher guide](instructor-guide.md) instead.

Menu paths are given in English, with the Spanish label in parentheses where the plugin itself sets it.

## Contents

- [How the plugin works](#how-the-plugin-works)
- [Requirements](#requirements)
- [Install](#install)
- [Upgrade](#upgrade)
- [Connect the plugin to Skilland](#connect-the-plugin-to-skilland)
- [Settings reference](#settings-reference)
- [The Skilland Course ID custom field](#the-skilland-course-id-custom-field)
- [Scheduled task](#scheduled-task)
- [Notifications](#notifications)
- [Capabilities and roles](#capabilities-and-roles)
- [Disabling the plugin](#disabling-the-plugin)
- [Backup and restore](#backup-and-restore)
- [Security](#security)
- [Privacy and GDPR](#privacy-and-gdpr)
- [Troubleshooting](#troubleshooting)
- [Uninstall](#uninstall)

## How the plugin works

- A Moodle course is linked to one Skilland course through a course custom field, *Skilland Course ID*.
- A teacher adds a **Skilland content** activity, picks one topic of that Skilland course and ticks the lessons learners should see.
- When the teacher prepares the content, the plugin downloads the topic's SCORM package from Skilland, verifies its signature and creates a SCORM activity in the same section. That SCORM activity is available to learners but not shown on the course page; learners reach its lessons through the Skilland activity.
- The Skilland activity owns completion and the grade. Learner tracking stays in Moodle's SCORM tables, plus a per-lesson summary in the plugin's own table.
- Every call to Skilland is server-side, from the Moodle server to the Skilland REST API (`{Skilland URL}/api/moodle/...`), authenticated with the organization's API key. Browsers only talk to Skilland when a user opens Skilland Studio through single sign-on.

## Requirements

| Requirement | Detail |
|---|---|
| Moodle | 4.5. `version.php` sets `$plugin->requires = 2024100700` and `$plugin->supported = [405, 405]`, so 4.5 is the only branch the plugin declares as supported. |
| PHP | 8.1 or later (`composer.json`; CI runs PHP 8.1 on PostgreSQL and 8.3 on MariaDB). |
| SCORM package module | `mod_scorm` must be installed; it is a declared dependency. |
| Cron | Moodle cron must run for the [scheduled task](#scheduled-task). |
| Outbound HTTPS | From the Moodle server to the Skilland site (default `app.skilland.ai`) and to the hosts SCORM packages are downloaded from (default `*.skilland.ai`, `*.amazonaws.com`). If your site uses a proxy or Moodle's cURL blocked hosts list, allow these. |
| Skilland | An organization on Skilland and someone with the Owner or Admin role there, who can copy the values below. |

## Install

### From a release ZIP (recommended)

Every release on the [Releases page](https://github.com/Skilland-ai/skilland-lms-moodle/releases) has a `mod_skilland-<version>.zip` asset, where `<version>` is the numeric `$plugin->version` (for example `mod_skilland-2026100100.zip`). The ZIP contains a single `skilland/` folder with the built plugin and its runtime dependency (`firebase/php-jwt`), without tests or development tools.

Either:

1. Go to **Site administration › Plugins › Install plugins**, upload the ZIP and confirm the validation screen.
2. Follow the upgrade screens.

Or, if installing plugins from the web interface is disabled on your site:

1. Unzip it into `<moodle>/mod/`, so that you have `<moodle>/mod/skilland/version.php`.
2. Go to **Site administration › Notifications**, or run `php admin/cli/upgrade.php`.

The plugin is still marked beta (`MATURITY_BETA`), and releases are published as GitHub pre-releases.

### From source

The repository is not the plugin folder: the plugin code is in `src/`, and the build adds the minified JavaScript and the Composer dependency. Cloning the repository straight into `mod/skilland` does **not** give a working plugin. Build it first (Node.js 20, as in the release workflow):

```bash
git clone https://github.com/Skilland-ai/skilland-lms-moodle.git
cd skilland-lms-moodle
npm ci
npm run build                      # writes dist/
cp -r dist <moodle>/mod/skilland   # then visit Site administration › Notifications
```

`npm run build` leaves out the tests and `cli/configure_api.php`, a development-only helper.

### After installing

The install step creates the *Skilland Course ID* course custom field (see [below](#the-skilland-course-id-custom-field)). Then [connect the plugin to Skilland](#connect-the-plugin-to-skilland).

## Upgrade

1. Download the newer ZIP.
2. Replace the `mod/skilland` folder with the new one (or upload the ZIP through **Install plugins**, which offers to replace the existing version).
3. Go to **Site administration › Notifications** and run the upgrade.

Settings, activities, course links and learner progress are kept. Each upgrade also recreates the *Skilland Course ID* field if it was deleted. Read the [changelog](../CHANGELOG.md) for each version you skip: some upgrades change behaviour, for example 0.9.41-beta renamed *GraphQL Endpoint* to *Skilland URL*.

> [!NOTE]
> A release is tagged `v<$plugin->version>` (for example `v2026100100`), and its title shows the human-readable release (`0.9.41-beta`). **Site administration › Plugins › Plugins overview** shows both.

## Connect the plugin to Skilland

You need four values from Skilland. An Owner or Admin of your Skilland organization finds them in Skilland under **Settings › Integrations › Moodle** (*Ajustes › Integraciones › Moodle*):

| Value in Skilland | Plugin setting |
|---|---|
| Organization ID | **Organization ID** |
| Skilland URL | **Skilland URL** |
| SSO secret (press *Show*) | **SSO Shared Secret** |
| API key: under *API keys for the Moodle plugin*, press *Generate New Key*. The key is shown only once. | **API Key** |

> [!WARNING]
> Generating a new API key in Skilland replaces the active one immediately. Any Moodle site still using the old key stops working until you paste the new one.

Then:

1. Go to **Site administration › Plugins › Activity modules › Skilland content** (*Contenido Skilland*).
2. Paste the four values.
3. Leave the other settings at their defaults unless Skilland asks you to change them. If your Skilland URL is not `https://app.skilland.ai`, read the note on [Frontend URL](#frontend-url).
4. Press **Save changes**.
5. Check that the heading **Course Custom Field Status** at the top of the page says *Custom field exists*.

The plugin has no "test connection" button. To check the connection, edit a course as a manager and open the *Skilland Course ID* field: it should list your organization's Skilland courses.

## Settings reference

All settings are on **Site administration › Plugins › Activity modules › Skilland content**. They are stored as plugin config `mod_skilland/<name>`, so you can also set them in `config.php` with `$CFG->forced_plugin_settings['mod_skilland']['<name>']`.

| Setting (Spanish label) | Config name | Default | What it does |
|---|---|---|---|
| Verbose debug logging (*Registro de depuración detallado*) | `devmode` | Off | Adds request details to Moodle's debugging output, at `DEBUG_DEVELOPER` level, and shows the underlying error message next to the generic "could not be reached" error. **Never enable on a production site.** |
| API Key (*Clave API*) | `apikey` | Empty | The organization's API key from Skilland. Sent as `Authorization: Bearer <key>` on every REST call. Required. |
| Organization ID (*ID de Organización*) | `orgid` | Empty | Your Skilland organization's ID. Stored with each course link and sent in SSO tokens. Required. |
| Skilland URL (*URL de Skilland*) | `graphql_endpoint` | `https://app.skilland.ai` | The address of your Skilland site. The plugin calls the REST API under `{Skilland URL}/api/moodle`. Must be `https://`. A value saved by older versions with a trailing `/graphql` or `/api/moodle` still works; the suffix is ignored. The config name is kept from older versions. |
| SCORM package hosts (*Hosts de paquetes SCORM*) | `package_hosts` | `*.skilland.ai, *.amazonaws.com` | Comma-separated hosts SCORM packages may be downloaded from, in addition to the hosts of the Skilland URL and Frontend URL. `*.example.com` matches subdomains of `example.com` only, not `example.com` itself. `*.amazonaws.com` covers the presigned storage links Skilland hands out. |
| Maximum SCORM package size (MB) (*Tamaño máximo del paquete SCORM (MB)*) | `package_max_mb` | `200` | Downloads larger than this are aborted. |
| SCORM package signing keys (*Claves de firma de paquetes SCORM*) | `signingkeys` | Empty | Extra public keys SCORM packages may be signed with, one `keyid:base64publickey` per line, on top of the keys built into the plugin. Only add a key Skilland has published, for example during a key rotation. See [Signed SCORM packages](#signed-scorm-packages). |
| Frontend URL (optional) (*URL del Frontend (opcional)*) | `frontend_url` | `https://app.skilland.ai` | Overrides the Skilland URL for the REST API, the SSO handoff and the Studio links. See the note below. |
| SSO Shared Secret (*Secreto Compartido SSO*) | `sso_secret` | Empty | The organization's Moodle SSO secret from Skilland, used to sign single sign-on tokens. Skilland derives one secret per organization, so it only works with this site's Organization ID. Must be at least 32 bytes; values that were ever published as development defaults are refused. |

### Frontend URL

> [!IMPORTANT]
> **Frontend URL** is pre-filled with `https://app.skilland.ai`, and when it is set it takes precedence over **Skilland URL** for every request. If your organization uses a Skilland site other than `app.skilland.ai`, either clear **Frontend URL** or set it to the same address as **Skilland URL**. Otherwise the plugin keeps calling `app.skilland.ai`.

The setting description says "Leave it empty to use the Skilland URL"; that is what the code does when the field is empty.

### Values that must be HTTPS

**Skilland URL** and **Frontend URL** must start with `https://`. The only exception is a development site that sets `$CFG->mod_skilland_allow_http = true` in `config.php`; never set it on a production site, because it also lets the plugin reach local and private network addresses.

## The Skilland Course ID custom field

The link between a Moodle course and a Skilland course is a course custom field:

| Property | Value |
|---|---|
| Category | *Skilland content* (*Contenido Skilland*), created by the plugin |
| Name | *Skilland Course ID* (*ID de Curso de Skilland*) |
| Short name | `skilland_course_id` |
| Type | Short text |
| Locked | Yes |
| Visible to | Everyone |

The field is created when the plugin is installed, recreated on each upgrade and the first time a course is linked. You find it under **Site administration › Courses › Course custom fields**. Do not rename its short name: the plugin finds it by `skilland_course_id`.

On the course settings page, the plugin turns this field into a list of Skilland courses with a **Create in SkilLand** button and a **Go to Skilland** button.

> [!IMPORTANT]
> Because the field is locked, only users with `moodle/course:changelockedcustomfields` can change a course's link. In a standard Moodle install that is the Manager role, not Editing teacher. If teachers should link their own courses, allow that capability for the Editing teacher role (site-wide, or as a role override in a course category). Otherwise a manager links each course.

Which Skilland courses appear in the list:

- **Site administrators** (`moodle/site:config`) see every course of the organization.
- **Everyone else** sees the Skilland courses already linked to Moodle courses they can edit, plus the Skilland courses they own or collaborate on in Skilland. The plugin matches the Moodle user's email address with the Skilland account, so the two must use the same email.

## Scheduled task

| Task | Class | Default schedule |
|---|---|---|
| Sync Skilland content for auto-update activities (*Sincronizar contenido de Skilland para actividades con actualización automática*) | `\mod_skilland\task\sync_content` | Every 15 minutes (`*/15 * * * *`) |

You can change the schedule in **Site administration › Server › Tasks › Scheduled tasks**. Each run:

1. Does nothing if the module is disabled.
2. Copies learners' SCORM status and scores into the plugin's progress table (no network calls), so completion and grades stay current.
3. Stops there if the API key, Organization ID or URL is missing.
4. For each activity with **Notify me of content updates** on, asks Skilland for the topic's current content hash. It skips an activity that was checked less than 5 minutes ago, one locked by **Lock after first access** once a learner has opened it, and a topic whose package Skilland has not built yet or is still rebuilding.
5. When the hash changed, records that an update is available and notifies the course's teachers, once per content version.

The task **never downloads or imports** content. A teacher applies an update by pressing **Update From Skilland**, which replaces the package and deletes learners' attempts on that activity.

## Notifications

The plugin defines one message provider, *Skilland content updates available for your activities* (*Actualizaciones de contenido de Skilland disponibles para sus actividades*). It goes to users with `mod/skilland:provision` in the activity, with web (popup) and email notifications on by default. Change the defaults in **Site administration › General › Messaging › Notification settings**.

## Capabilities and roles

| Capability | Context | Risk | Default roles | What it allows |
|---|---|---|---|---|
| `mod/skilland:addinstance` | Course | XSS | Editing teacher, Manager | Adding a Skilland activity to a course. Users with it are signed in to Skilland Studio with the **Expert** role; everyone else gets **Learner**. |
| `mod/skilland:view` | Activity | | Guest, Student, Non-editing teacher, Editing teacher, Manager | Opening a Skilland activity and its lessons. |
| `mod/skilland:provision` | Activity | XSS | Editing teacher, Manager | Preparing an activity's SCORM content, applying updates (**Update From Skilland**), seeing update notices and receiving the update notification. |
| `mod/skilland:accessstudio` | Course | | Editing teacher, Manager | Opening Skilland Studio with single sign-on, the course navigation link, listing or creating linked Skilland courses, and browsing the linked course's topics and lessons. |

On install and upgrade, `mod/skilland:addinstance` and `mod/skilland:provision` copy their role permissions from `moodle/course:manageactivities`, and `mod/skilland:accessstudio` from `moodle/course:update`, so roles you have customised keep the access they had.

Linking a course also needs `moodle/course:changelockedcustomfields`; see [the custom field](#the-skilland-course-id-custom-field).

## Disabling the plugin

To switch the plugin off without uninstalling it, hide it in **Site administration › Plugins › Activity modules › Manage activities** (eye icon). While it is hidden:

- teachers can no longer add Skilland activities;
- the plugin's web services answer *The Skilland plugin is currently disabled*, so the activity form cannot load topics or lessons;
- the course navigation link and the course settings controls are not added;
- the scheduled task does nothing.

No settings or data are deleted. Show the module again to restore everything.

## Backup and restore

Skilland activities are included in course backups, restores, imports, course copies and activity duplication. In short:

- The activity's settings, topic, selected lessons and the course's Skilland link are backed up. Restoring into a course that has no Skilland link yet creates the link from the backup; a course that already has one keeps it.
- When the hidden SCORM activity is part of the same restore (full course backup, course copy, import of both activities), the Skilland activity is linked to the restored SCORM, so restored learner attempts stay attached.
- When it is not (importing or duplicating the Skilland activity alone), a new SCORM is prepared from Skilland during the restore. If that fails, the restore still completes and a teacher prepares the content from the activity page.
- Learner attempts and grades live in the SCORM activity, so they are restored when you include user data. The plugin's progress summary is rebuilt from them.

The full contract is in [Backup and restore](backup-restore.md).

## Security

### Outbound connections

- All calls go from the Moodle server to `{Skilland URL}/api/moodle` (or the Frontend URL when set), over HTTPS, with the API key as a Bearer token.
- Redirects are never followed, so the API key cannot be replayed to another host. A redirect answer is reported as an error.
- Moodle's cURL security settings (blocked hosts and ports) stay in force.
- SCORM packages are downloaded only from the Skilland URL host, the Frontend URL host or a host matching **SCORM package hosts** (`mod_skilland/package_hosts`); anything larger than **Maximum SCORM package size (MB)** (`mod_skilland/package_max_mb`) or not a ZIP file is refused.
- The plugin does not log the API key, URL query strings or email addresses.

### Signed SCORM packages

A SCORM package is not inert content. Moodle's SCORM player serves it as content of your Moodle site: its JavaScript runs with the learner's session, calls the SCORM API and writes the learner's SCORM track, which feeds completion and grades. The plugin therefore imports only packages it can prove Skilland made for the topic it asked for:

- Skilland signs each package with Ed25519. The signature covers a fixed prefix (`skilland-scorm-package-v1`), the topic id the plugin requested, the content hash, the SHA-256 of the downloaded file (computed by the plugin, and required to match the hash Skilland announced) and the generation time.
- The check runs after the download and before the file reaches Moodle's file API or SCORM parser, on every import: first preparation, **Update From Skilland**, a topic change and a restore.
- An unsigned package, an unknown key, a malformed signature, a hash mismatch or a failed verification each abort the import with their own error. The activity's existing SCORM is left as it was.
- Trusted keys are the ones built into the plugin (`mod_skilland\local\package_signature::PINNED_KEYS`, currently key id `2026-09`) plus the lines of **SCORM package signing keys**. A built-in key id always wins over a setting line with the same id. Each line must be a key id of 1 to 64 letters, digits, `.`, `_` or `-`, a colon, and the base64 of exactly 32 bytes; the setting is not saved if any line is invalid.
- When Skilland rotates its key, the new public key ships in a plugin release; until you upgrade, you can add it to **SCORM package signing keys**.

### Single sign-on tokens

Opening Skilland Studio from Moodle goes through `mod/skilland/sso_redirect.php`, which:

1. Requires a logged-in, non-guest user, a valid `sesskey`, a `courseid`, and `mod/skilland:accessstudio` in that course.
2. Re-reads the user record and refuses guest, suspended, deleted, unconfirmed and `nologin` accounts.
3. Signs a JWT with HS256 and the **SSO Shared Secret**.
4. Returns a page that POSTs the token and the Studio path to `{Skilland URL}/sso-login` (or the Frontend URL). The token never appears in a URL; the page is sent with `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.

Token claims:

| Claim | Value |
|---|---|
| `sub` | The Moodle user id, as a string. Skilland binds its account to the organization, `iss` and `sub`, not to the email address. |
| `iss` | This site's `$CFG->wwwroot` |
| `aud` | The origin (`scheme://host[:port]`) of the Frontend URL, or the Skilland URL when Frontend URL is empty |
| `iat`, `exp` | Issue time, and issue time + 60 seconds |
| `nonce` | 16 random bytes, hex |
| `email`, `name` | The user's email and full name |
| `orgId` | The **Organization ID** setting |
| `role` | `Expert` with `mod/skilland:addinstance` in the course, otherwise `Learner` |
| `source` | `moodle` |
| `courseAccess` | The Moodle courses the user is enrolled in that are linked to a Skilland course, as `moodleCourseId` / `skillandSkillId` pairs |

### Web services

The plugin's external functions (`mod_skilland_fetch_courses_ajax`, `mod_skilland_create_course_ajax`, `mod_skilland_fetch_topics_ajax`, `mod_skilland_fetch_lessons_ajax`, `mod_skilland_provision_topic_scorm_ajax`, `mod_skilland_update_topic_scorm_ajax`, `mod_skilland_check_topic_snapshot`) exist for its own pages' AJAX calls and require a login. Each validates its course or activity context and checks a plugin capability in it. Topics, lessons and packages are only served for the Skilland course linked to that Moodle course.

### Stored secrets

The API key and SSO secret are stored in Moodle's plugin configuration, like other plugin settings, and are shown as password fields on the settings page. Anyone with `moodle/site:config` can read them.

## Privacy and GDPR

The plugin implements Moodle's Privacy API (`classes/privacy/provider.php`). Its declarations show in **Site administration › Users › Privacy and policies › Plugin privacy registry**, and data requests include its data.

**Sent to Skilland** (your organization's Skilland site):

| When | Data |
|---|---|
| Signing in to Skilland Studio | Moodle user id, email, full name, the Skilland role given, and the linked Moodle courses the user is enrolled in |
| Listing a teacher's Skilland courses in the course settings | The user's email |
| Creating a Skilland course from Moodle | The user's email, Moodle user id and this site's URL. Skilland creates an account for that email if none exists. |

**Stored in Moodle**: the table `skilland_progress` holds, per learner and lesson, the best status, highest score and last update time. Data export and deletion requests cover this table. The SCORM attempts themselves belong to the SCORM activity and are handled by Moodle's SCORM privacy provider.

Data already sent to Skilland is handled by your Skilland organization's administrator, under your agreement with Skilland.

## Troubleshooting

### Where to look

- **Debugging output.** The plugin reports through Moodle's `debugging()`, with messages starting `[Skilland] [<area>]`. Errors show at the *MINIMAL* level, warnings and progress at *NORMAL*. Set **Site administration › Development › Debugging › Debug messages** accordingly. With *Display debug messages* off, Moodle writes them to the PHP error log.
- **Scheduled task logs.** **Site administration › Server › Tasks › Task logs**, filtered on the sync task, shows each run's summary (`Sync complete: N notified, N skipped, N errors`).
- **Verbose debug logging** adds request-level detail (Debug messages must be *DEVELOPER*) and shows the underlying error next to generic messages. Turn it off again afterwards.

### Common problems

| Message or symptom | Cause and fix |
|---|---|
| *Skilland API Key is not configured* / *Skilland Organization ID is not configured* / *Skilland URL is not configured* | A required setting is empty. Fill it in. |
| *Invalid API credentials. Please check your Organization ID and API Key* | The key was revoked or replaced in Skilland, or belongs to another organization. Generate a new key in Skilland and paste it. |
| *The SkilLand service could not be reached* | Generic network or API error. Check outbound HTTPS from the Moodle server, proxy settings and Moodle's cURL blocked hosts. Turn on Verbose debug logging briefly to see the underlying error. |
| *The Skilland server answered with a redirect (HTTP …)* | The URL is not the final address (for example `http` instead of `https`, or a missing `www`). Enter the exact address Skilland shows. |
| Requests go to `app.skilland.ai` although Skilland URL points elsewhere | **Frontend URL** still has its default. Clear it or set it to the same address. |
| *This URL must use https://* | Skilland URL and Frontend URL only accept `https://`. |
| *SCORM packages may not be downloaded from …* | The package link points to a host outside the allowlist. Add the host to **SCORM package hosts** only if Skilland confirms it. |
| *The SCORM package is larger than the … MB limit* | Raise **Maximum SCORM package size (MB)**, or ask the teacher to select fewer lessons in Skilland. |
| *… is not signed* / *… signed with a key this site does not trust* | The plugin is older than Skilland's current signing key. Upgrade the plugin, or add the key Skilland publishes to **SCORM package signing keys**. |
| *The SSO shared secret must be at least 32 bytes long* / *…was published as a development default* | Copy the secret again from Skilland › Settings › Integrations › Moodle. |
| Skilland rejects the sign-in token | The SSO secret does not belong to the configured Organization ID, or the token's audience does not match the Skilland address (check Frontend URL). Tokens are valid for 60 seconds, so also check the Moodle server's clock. |
| *This Moodle account cannot sign in to SkilLand* | The account is a guest, suspended, deleted, unconfirmed or uses the `nologin` authentication method. |
| *JWT library not installed* | The plugin folder was copied from the repository instead of a release ZIP or `dist/` build. Reinstall from the ZIP. |
| **Course Custom Field Status** says *Custom field not found* | The field was deleted. Run the upgrade (**Site administration › Notifications**) or link a course; both recreate it. |
| Teachers cannot change the *Skilland Course ID* field | The field is locked; see [the custom field](#the-skilland-course-id-custom-field). |
| A teacher's own Skilland course is missing from the list | Their Moodle email differs from their Skilland email, or they are not owner or collaborator of that course in Skilland. |
| Learners see *This lesson or topic content is being prepared* | Nobody has prepared the content yet. A teacher opens the activity and presses **Prepare Topic Content**. |
| Teachers get no update notices | Check that cron runs, that the sync task is enabled, that the activity has **Notify me of content updates** on and is not locked by **Lock after first access**, and the users' notification preferences. |
| An activity points to a SCORM activity that was deleted | Run `php mod/skilland/cli/cleanup_orphaned_scorm.php --dry-run` (add `--course=<id>` to limit it), then without `--dry-run`. It clears the dangling references so the content can be prepared again. |

## Uninstall

1. Go to **Site administration › Plugins › Plugins overview**, find *Skilland content* and choose **Uninstall**.
2. Confirm. Moodle deletes all Skilland activities, the plugin's tables (`skilland`, `skilland_course`, `skilland_lesson`, `skilland_progress`) and its settings.

> [!WARNING]
> The plugin has no uninstall hook, so some things it created stay behind and must be removed by hand if you no longer want them:
> - the hidden SCORM activities, one per prepared Skilland activity, with their learner attempts. In edit mode they appear in their section as available but not shown on the course page;
> - the *Skilland Course ID* course custom field and its *Skilland content* category, under **Site administration › Courses › Course custom fields**.

Uninstalling Moodle's side does not delete anything in Skilland. Revoke the site's API key in Skilland under **Settings › Integrations** when you stop using it.
