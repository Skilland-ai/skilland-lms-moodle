<p align="center">
  <img src="docs/assets/skilland-mark.svg" alt="Skilland" width="64">
</p>

<h1 align="center">Skilland content for Moodle</h1>

<p align="center">
  <code>mod_skilland</code> · an activity module for Moodle™ 4.5 that brings <a href="https://app.skilland.ai">Skilland</a> courses into Moodle courses
</p>

---

Skilland content (*Contenido Skilland*) links a Moodle course to a course in Skilland and lets teachers add Skilland topics to it as activities. Each activity shows the lessons the teacher picked; behind it, the plugin imports the topic as a signed SCORM package into a hidden SCORM activity, so learners' progress, completion and grades stay in Moodle. Teachers open Skilland Studio from Moodle with single sign-on to edit the content, and the plugin tells them when a newer version of a topic is published.

## Requirements

| | |
|---|---|
| Moodle | 4.5 (`$plugin->requires = 2024100700`, declared supported range 4.5 only) |
| PHP | 8.1 or later (CI runs 8.1 and 8.3) |
| Moodle plugins | SCORM package (`mod_scorm`), a declared dependency |
| Moodle cron | Running, for the content update check |
| Network | Outbound HTTPS from the Moodle server to your Skilland site (default `https://app.skilland.ai`) and to `*.amazonaws.com` for package downloads |
| Skilland | An organization on Skilland, with an Owner or Admin who can copy its Moodle settings |

## Features

- **Course linking**: each Moodle course is linked to one Skilland course through the *Skilland Course ID* custom course field, picked from a list or created in Skilland from Moodle.
- **Topic activities**: one activity per Skilland topic; the teacher chooses which lessons learners see.
- **SCORM delivery**: every package is checked against an Ed25519 signature before Moodle stores it.
- **Single sign-on** to Skilland Studio from the course and the activity form.
- **Update notices**: a scheduled task tells teachers when a topic changes; nothing is replaced until a teacher applies the update.
- **Completion and grades**: an activity completion rule (*Complete all lessons*) and an optional point grade, both kept when the package is rebuilt.
- **Backup, restore, import, course copy and duplicate**.
- **Privacy API** provider; English and Spanish language packs.

## Documentation

| Guide | For |
|---|---|
| [Administrator guide](docs/admin-guide.md) | Installing, configuring, securing and troubleshooting the plugin |
| [Teacher guide](docs/instructor-guide.md) | Linking a course, adding activities, updates, completion and grades |
| [Backup and restore](docs/backup-restore.md) | How activities, links and SCORM packages are backed up and restored |
| [Development](docs/development.md) | Local setup, tests, CI and the release process |
| [Changelog](CHANGELOG.md) | Changes per release |
| [Contributing](CONTRIBUTING.md) | Branches, commits and pull requests |

## Quick install

1. Download `mod_skilland-<version>.zip` from the [latest release](https://github.com/Skilland-ai/skilland-lms-moodle/releases/latest).
2. In Moodle, go to **Site administration › Plugins › Install plugins**, upload the ZIP and follow the upgrade screens.
3. Open **Site administration › Plugins › Activity modules › Skilland content**.
4. In Skilland, open **Settings › Integrations › Moodle** and copy the Organization ID, Skilland URL, SSO secret and a new API key into those settings.
5. Save. Teachers now find **Skilland content** in the activity chooser.

The [administrator guide](docs/admin-guide.md#install) covers each step, upgrades and installing from source.

## Support

Report bugs and ask questions in [GitHub Issues](https://github.com/Skilland-ai/skilland-lms-moodle/issues). For anything about your Skilland organization (accounts, API keys, SSO secret), contact your Skilland organization administrator.

## License

GNU General Public License v3 or later; see [LICENSE](LICENSE). Copyright Skilland.

Moodle™ is a registered trademark of Moodle Pty Ltd. This plugin is not made or endorsed by Moodle Pty Ltd.
