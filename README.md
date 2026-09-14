<div align="center">

# 🎓 Skilland Content - User Guide

**Seamlessly integrate AI-generated educational content from Skilland into your Moodle courses**

[![Moodle](https://img.shields.io/badge/Moodle-4.0%2B-orange?style=flat-square&logo=moodle)](https://moodle.org)
[![Status](https://img.shields.io/badge/Status-Beta-blue?style=flat-square)]()

</div>

### 📥 Quick Install

[**Download the latest Release ZIP**](https://github.com/f3r/skilland-lms-moodle/releases/latest) and upload it to your Moodle via **Site administration > Plugins > Install plugins**.

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
- Moodle **4.0 or higher**
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

### 2. Course Setup (Teacher/Admin)
Before adding activities, you must link the course:

1. Go to your Moodle course.
2. Click **Settings** (or "Edit settings").
3. Scroll down to **Custom fields**.
4. Enter your **Skilland Course ID** in the `skilland_course_id` field.
5. Save changes.

> **Note**: If you don't see this field, ask your administrator to create the "Skilland Course ID" custom course field.

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

---

## 🛠️ Development

For instructions on how to set up the development environment, build the plugin, and contribute, please refer to [DEVELOPMENT.md](DEVELOPMENT.md).

---

## 💬 Support

For issues, questions, or contributions, please contact your **Skilland platform administrator**.

<div align="center">

**Made with ❤️ for the Skilland Team**

</div>
