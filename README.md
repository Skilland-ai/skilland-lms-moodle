<div align="center">

# 🎓 Skilland Content - User Guide

**Seamlessly integrate AI-generated educational content from Skilland into your Moodle courses**

[![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat-square&logo=moodle)](https://moodle.org)
[![Status](https://img.shields.io/badge/Status-Beta-blue?style=flat-square)]()

</div>

### 📥 Quick Install

[**Download the latest Release ZIP**](https://github.com/Skilland-ai/skilland-lms-moodle/releases/latest) and upload it to your Moodle via **Site administration > Plugins > Install plugins**.

---

## 📋 Table of Contents

- [Overview](#-overview)
- [Features](#-features)
- [Installation](#-installation)
- [Configuration](#-configuration)
- [Usage](#-usage)
- [Support](#-support)

---

## 🎯 Overview

The **Skilland Content** module bridges the gap between the Skilland platform and Moodle. It allows teachers to easily import topics and lessons from their Skilland courses directly into Moodle as interactive activities.

### Key Benefits
- 🔗 **Connect** Moodle courses to Skilland courses
- 📚 **Import** specific topics and select which lessons to include
- 🔄 **Stay Updated** with optional update notices from Skilland, applied when you choose
- 🔒 **Control Access** with flexible locking and visibility settings
- 💾 **Backup & Restore** fully supported

---

## ✨ Features

### 🔄 Course Connection
Link a Moodle course to a specific Skilland course using the unique **Skilland Course ID**. This ensures all activities within the course pull from the correct source.

### 💾 Backup and Restore
Full support for Moodle's backup, restore, and course copy features.
- Keeps your Skilland-Moodle links intact when moving courses.
- [Read more about Backup & Restore](BACKUP.md)

### 📖 Lesson Selection
When creating an activity (Topic), you can now **select exactly which lessons** you want to display to your students. This gives you granular control over the content curriculum.

### ⚙️ Smart Behavior
- **Update notices**: Be told when content improves in Skilland, and apply it when it suits your course.
- **Lock after access**: Ensure content stability once students start learning.
- **Clean Interface**: Options to hide internal system codes/labels for a cleaner student experience.

---

## 🚀 Installation

### Prerequisites
- Moodle **4.5 or higher**
- PHP **8.1 or higher**
- A Skilland platform account with API access
- SCORM module enabled in Moodle

### Steps

1.  **Download**: Get the latest Release ZIP file from the Releases page.
    *   *Alternatively, if cloning the repository*: Run `npm install && npm run build` to generate the plugin in the `dist/` folder. Use the contents of `dist/` as the plugin.
2.  **Install**: Upload the ZIP (or the `dist` folder contents) to your Moodle site's `mod/skilland` directory.
3.  **Upgrade**: Follow the standard Moodle plugin installation prompts.
4.  **Verify**: Ensure "Skilland content" appears in the Activity Chooser.

---

## ⚙️ Configuration

### 1. Global Settings (Administrator)
Go to **Site administration → Plugins → Activity modules → Skilland content**.

- **API Key**: In Skilland, go to **Settings › Integrations › Moodle** and click **Generate New Key**. The key is shown only once.
- **Organization ID**: The Organization ID shown on the same Skilland page; it must be the organization the API key belongs to.
- **Skilland URL**: The address of the Skilland site (default: `https://app.skilland.ai`). Every call the plugin makes goes to the SkilLand REST API under it (`{Skilland URL}/api/moodle/...`, authenticated with the API key as a Bearer token): the course list, the courses a teacher can edit, course creation, topics, lessons, topic SCORM packages and their content hashes. A value saved with a trailing `/graphql` or `/api/moodle` (from older versions) still works; the suffix is ignored.
  - For local development: `http://localhost:3100`
- **Frontend URL** (optional, empty by default): Leave it empty to use the Skilland URL for everything (the REST API, the SSO handoff and the Studio links). Set it only when Moodle must reach Skilland through a different address than browsers use. Up to 0.9.44-beta it defaulted to `https://app.skilland.ai`, which overrode a Skilland URL pointing at another server; the 0.9.45-beta upgrade removes that stored default and keeps any other value.
- **SSO Shared Secret**: Your organization's Moodle SSO secret, copied from SkilLand › Settings › Integrations › Moodle. SkilLand derives a secret for each organization, so it only works together with this site's **Organization ID**. It must be at least 32 bytes long.

After saving, open **Test connection** (linked at the top of the settings page, or **Site administration → Plugins → Activity modules → Skilland content: test connection**) and click **Run check**. It asks Skilland, with the saved settings, whether the Skilland URL / Frontend URL is reachable, the API key is accepted, the Organization ID matches the key's organization and the SSO shared secret matches, and prints one line per setting to fix. The SSO secret itself never leaves Moodle: the plugin sends an HMAC proof over the Organization ID, a timestamp and a nonce.

#### SSO handoff

Opening SkilLand Studio signs an HS256 JWT with the SSO shared secret and hands it over with a self-submitting form that **POSTs** `token` and `redirect` to `{Skilland URL}/sso-login` (or the Frontend URL when set). `redirect` is a native Studio path: `/skills/<skillId>`, `/skills/<skillId>?topic=<topicId>` for one topic, or `/skills/new?draft=<skillId>` right after a course was created from Moodle. The token never appears in a URL, so it stays out of browser history, `Referer` headers and access logs; the handoff page is sent with `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. Besides the user claims, every token carries:

- `exp`: `iat` + 60 seconds, just long enough for the form to submit.
- `aud`: the origin (`scheme://host[:port]`) of the Skilland URL (or the Frontend URL when set). The SkilLand side may override the audience it expects with `MOODLE_SSO_AUDIENCE`.
- `iss`: this Moodle site's `wwwroot`.
- `sub`: the Moodle user id, as a string. SkilLand binds the SkilLand account to the organization, `iss` and `sub`, not to the email address.

Tokens are only issued to accounts that may sign in, read from the user table at click time: guest, suspended, deleted, unconfirmed and `nologin` accounts get an error page instead.
- **SCORM package hosts**: Comma-separated hosts SCORM packages may be downloaded from, besides the Skilland URL and Frontend URL hosts (default: `*.skilland.ai, *.amazonaws.com`). `*.example.com` matches subdomains of `example.com` only; the default `*.amazonaws.com` covers the presigned S3 URLs the REST API hands out.
- **Maximum SCORM package size (MB)**: Downloads larger than this are aborted (default: `200`).
- **SCORM package signing keys**: Extra public keys SCORM packages may be signed with, one `keyid:base64publickey` per line, besides the keys built into the plugin (the setting is empty by default; key `2026-09` is pinned). See [Trust boundary](#trust-boundary).

#### Trust boundary

A SCORM package SkilLand sends is not inert content. Once imported, it is served by Moodle's SCORM player **as same-origin content of your Moodle site**: its HTML and JavaScript run with the learner's Moodle session, call the SCORM API, and read and write the learner's SCORM track (status, score, suspend data), which feeds the activity's completion and grade. Whoever can put a package into that player can act on the site as the learner viewing it.

So the plugin never imports a package it cannot prove SkilLand made for the topic it asked for:

- **Signed packages only.** For every package SkilLand returns `contentHash`, `keyId` and an Ed25519 `signature` over five lines: `skilland-scorm-package-v1`, the topic id the plugin requested (lower case, never the one in the answer), `contentHash`, the sha256 of the package bytes the plugin downloaded (computed locally, and required to equal the announced `packageHash`), and `generatedAt`. The plugin checks it after the download and **before** the package reaches Moodle's file API or the SCORM parser, on every import path: first provisioning, **Update From Skilland**, and a topic change.
- **Unsigned packages are refused.** A missing signature or key id, a key id the site does not trust, a malformed signature, a hash mismatch or a signature that does not verify each abort the import with its own error, and the activity's existing SCORM is left as it was. The legacy GraphQL fallback never carries a signature, so a package reached through it is always refused (the metadata-only hash check still falls back to it).
- **Pinned keys.** The production public keys ship inside the plugin (`mod_skilland\local\package_signature::PINNED_KEYS`; currently key id `2026-09`, rotated per SkilLand's `infra/runbooks/scorm-signing-key.md`). An administrator can trust additional keys with **SCORM package signing keys**; the trusted set is the pinned keys plus those lines, and a pinned key id always wins over an admin line with the same id. Each line must be a key id of 1–64 letters, digits, `.`, `_` or `-`, a colon, and base64 of exactly 32 bytes; a value with any invalid line is not saved.
- **Rotation.** Several keys can be trusted at once: SkilLand publishes the new public key, it is pinned in a plugin release (or added to the setting in the meantime), SkilLand starts signing with the new key id, and the old key is dropped from a later release or from the setting.
- **No silent imports.** The scheduled `sync_content` task never downloads or imports anything. When an activity with update notices on has new content, it records the update and notifies the course's teachers (message provider *Skilland content updates available*, once per content version); a teacher applies it from the activity page, through the same verified path.

### 2. Course Setup (Teacher/Admin)
Before adding activities, you must link the course:

1. Go to your Moodle course.
2. Click **Settings** (or "Edit settings").
3. Scroll down to **Custom fields**.
4. Pick the SkilLand course from the dropdown, or click **Create in SkilLand** and confirm to create a new one. A course created this way is linked to the Moodle course immediately, even if you then cancel the form. SkilLand creates it for your email address (and, if you have no SkilLand account yet, creates one linked to your Moodle account for SSO); an account without the Expert role, a deactivated one, an email that belongs to another organization or a course name already taken each show their own message.
5. Save changes. After a create, the saved course page shows an **Open the new course in SkilLand** link.

> **Note**: If you don't see this field, ask your administrator to create the "Skilland Course ID" custom course field.

### 3. Capabilities

| Capability | Context | Gates |
|------------|---------|-------|
| `mod/skilland:addinstance` | Course | Adding a Skilland activity to a course |
| `mod/skilland:view` | Activity | Opening a Skilland activity (`view.php`) |
| `mod/skilland:provision` | Activity | Provisioning and updating an activity's SCORM content, and the update checker |
| `mod/skilland:accessstudio` | Course | Opening SkilLand Studio (SSO), the course navigation link, listing or creating linked SkilLand courses, and browsing the linked course's topics and lessons |

When the plugin is first installed or upgraded, `mod/skilland:provision` copies its role permissions from `moodle/course:manageactivities` and `mod/skilland:accessstudio` copies them from `moodle/course:update`, so existing teacher and manager roles keep the access they had.

---

## 📖 Usage

### Creating a New Activity

1. **Turn editing on** in your course.
2. Click **Add an activity or resource** and select **Skilland content**.
3. **Topic Selection**:
   - The form will automatically load available topics from the linked Skilland course.
   - Select the Topic you want to import.
4. **Lesson Selection** (New Feature!):
   - Once a topic is selected, a list of lessons will appear.
   - **Check the boxes** next to the lessons you want to include in this activity.
   - You can come back later and check/uncheck lessons to show or hide them.
5. **Behavior Settings**:
   - **Notify me of content updates**: Check this to be told (by a Moodle notification and a banner on the activity) when the content changes in Skilland. Nothing is replaced until a teacher presses **Update From Skilland**, which deletes the students' attempts on the activity.
   - **Lock after first access**: Check this to freeze content versions once students start working.
6. Click **Save and return to course**.

### Editing an Activity

1. Turn editing on and click **Edit settings** for the Skilland activity.
2. You can change the selected **Topic** or modify which **Lessons** are selected.
3. Changes to lesson selection will update the visibility of those lessons for students.

### Completion and grades

The Skilland activity owns completion and the grade; the hidden topic SCORM has no grade item.

- **Completion rule**: under *Activity completion*, choose automatic completion and tick **Complete all lessons**. The activity completes once the learner has completed or passed every visible lesson. Hidden lessons are ignored; an activity with no visible lesson never completes.
- **Grade** (opt-in, *None* by default): with a maximum grade set, the raw grade is `grade × mean / 100`, the mean taken over the visible lessons of the SCO raw score (clamped to 0–100) when one was reported, else 100 for a completed or passed lesson, else 0. A learner with no recorded progress gets no grade. Scales are not supported.
- **Progress survives re-provisioning**: each learner's best status and highest score per lesson are kept in the plugin's own table, so rebuilding the SCORM (an applied update, topic change) never loses completion or grades. The `sync_content` task also backfills that table from the SCORM tracks.

## Privacy

The plugin implements Moodle's Privacy API (`classes/privacy/provider.php`), so its data shows up in *Site administration → Users → Privacy and policies → Plugin privacy registry* and in data requests.

**Sent to SkilLand** (the organisation's SkilLand platform):

- **Signing in to SkilLand Studio (SSO)**: the user's Moodle user id, email, full name, the SkilLand role they get, and the Moodle courses they are enrolled in that are linked to a SkilLand course.
- **Listing a teacher's SkilLand courses** in the activity form: the teacher's email.
- **Creating a SkilLand course from Moodle**: the teacher's email; SkilLand creates an account for that email if none exists.

**Stored in Moodle**: `skilland_progress` holds each learner's best status, highest score and last update time per lesson of a Skilland activity.

Data export and deletion requests cover that local table. Data already sent to SkilLand is handled by the organisation's SkilLand administrator.

---

## 🛠️ Development

For instructions on how to set up the development environment, build the plugin, and contribute, please refer to [DEVELOPMENT.md](DEVELOPMENT.md).

Tests: a fast stub PHPUnit suite (`npm run test:unit`) plus real Moodle PHPUnit and Behat tests in `src/tests`, run by moodle-plugin-ci; see [DEVELOPMENT.md › Tests](DEVELOPMENT.md#tests).

---

## 💬 Support

For issues, questions, or contributions, please contact your **Skilland platform administrator**.

<div align="center">

**Made with ❤️ for the Skilland Team**

</div>
