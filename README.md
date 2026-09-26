<div align="center">

# 🎓 Skilland Content - User Guide

**Seamlessly integrate AI-generated educational content from Skilland into your Moodle courses**

[![Moodle](https://img.shields.io/badge/Moodle-4.2%2B-orange?style=flat-square&logo=moodle)](https://moodle.org)
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
- 🔄 **Stay Updated** with optional auto-updates from Skilland
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
- **Auto-update**: Automatically receive content improvements.
- **Lock after access**: Ensure content stability once students start learning.
- **Clean Interface**: Options to hide internal system codes/labels for a cleaner student experience.

---

## 🚀 Installation

### Prerequisites
- Moodle **4.2 or higher**
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

- **API Key**: Your private key from the Skilland platform.
- **Organization ID**: Your institution's ID.
- **GraphQL Endpoint**: The URL of the Skilland GraphQL API (default: `https://api.skilland.ai/graphql`).
  - For local development: `http://localhost:8000/graphql`
- **Frontend URL**: The URL of the Skilland frontend application (default: `https://app.skilland.ai`).
  - For local development: `http://localhost:3000`
  - This is used for SSO redirects when teachers click "Edit Lessons in Skilland"
- **SSO Shared Secret**: The shared secret for SSO authentication (must match the backend `MOODLE_SSO_SECRET`).
- **SCORM package hosts**: Comma-separated hosts SCORM packages may be downloaded from, besides the GraphQL endpoint host (default: `*.skilland.ai, *.amazonaws.com`). `*.example.com` matches subdomains of `example.com` only.
- **Maximum SCORM package size (MB)**: Downloads larger than this are aborted (default: `200`).

### 2. Course Setup (Teacher/Admin)
Before adding activities, you must link the course:

1. Go to your Moodle course.
2. Click **Settings** (or "Edit settings").
3. Scroll down to **Custom fields**.
4. Enter your **Skilland Course ID** in the `skilland_course_id` field.
5. Save changes.

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
   - **Auto-update**: Check this if you want the content to update automatically when changed on Skilland.
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
- **Progress survives re-provisioning**: each learner's best status and highest score per lesson are kept in the plugin's own table, so rebuilding the SCORM (auto-update, topic change) never loses completion or grades. The `sync_content` task also backfills that table from the SCORM tracks.
- **Moodle 4.2**: SCORM tracks live in `scorm_attempt` / `scorm_scoes_value` only from Moodle 4.3, so on 4.2 no progress is read and the completion rule never ticks.

## Privacy

The plugin implements Moodle's Privacy API (`classes/privacy/provider.php`), so its data shows up in *Site administration → Users → Privacy and policies → Plugin privacy registry* and in data requests.

**Sent to SkilLand** (the organisation's SkilLand platform):

- **Signing in to SkilLand Studio (SSO)**: the user's email, full name, the SkilLand role they get, and the Moodle courses they are enrolled in that are linked to a SkilLand course.
- **Listing a teacher's SkilLand courses** in the activity form: the teacher's email.
- **Creating a SkilLand course from Moodle**: the teacher's email; SkilLand creates an account for that email if none exists.

**Stored in Moodle**: `skilland_progress` holds each learner's best status, highest score and last update time per lesson of a Skilland activity.

Data export and deletion requests cover that local table. Data already sent to SkilLand is handled by the organisation's SkilLand administrator.

---

## 🛠️ Development

For instructions on how to set up the development environment, build the plugin, and contribute, please refer to [DEVELOPMENT.md](DEVELOPMENT.md).

---

## 💬 Support

For issues, questions, or contributions, please contact your **Skilland platform administrator**.

<div align="center">

**Made with ❤️ for the Skilland Team**

</div>
