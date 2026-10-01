# Teacher guide

This guide shows teachers how to use Skilland content in a Moodle course: link the course to Skilland, add activities, keep them up to date, and follow learners' completion and grades.

Button and field names are given as they appear in Moodle in English, with the Spanish label in parentheses.

## Contents

- [Before you start](#before-you-start)
- [Open Skilland from Moodle](#open-skilland-from-moodle)
- [Link a Moodle course to a Skilland course](#link-a-moodle-course-to-a-skilland-course)
- [Add a Skilland activity](#add-a-skilland-activity)
- [Prepare the content](#prepare-the-content)
- [What learners see](#what-learners-see)
- [Change the topic or the lessons](#change-the-topic-or-the-lessons)
- [Content updates](#content-updates)
- [Completion and grades](#completion-and-grades)
- [FAQ](#faq)

## Before you start

- Your site administrator has installed and configured the plugin.
- You are an **Editing teacher** in the course (or have the same permissions).
- To create or edit content in Skilland, you need a Skilland account in your organization with the **same email address** as your Moodle account. If you have none, Skilland creates one the first time you create a course from Moodle.
- Linking a course to Skilland may be restricted to managers on your site. If you cannot change the *Skilland Course ID* field, ask your Moodle administrator to link the course for you.

## Open Skilland from Moodle

You never need a separate Skilland password: Moodle signs you in. You can open Skilland from three places:

| Where | Button or link |
|---|---|
| Course navigation (often under **More**) | **Edit in Skilland** (*Editar en Skilland*) when the course is linked, otherwise **Configure Skilland** (*Configurar Skilland*), which takes you to the course settings |
| Course settings, above the *Skilland Course ID* field | **Go to Skilland** (*Ir a Skilland*) |
| Activity settings | **Edit Lessons in Skilland** (*Editar lecciones en Skilland*), which opens the selected topic |

Skilland opens in a new tab. Teachers who can add activities are signed in as Skilland **Experts**, who can edit content.

<!-- screenshot: course navigation showing "Edit in Skilland" -->

## Link a Moodle course to a Skilland course

Each Moodle course is linked to one Skilland course. All Skilland activities in the Moodle course use it.

1. Open your Moodle course and go to **Settings**.
2. Expand the **Skilland content** (*Contenido Skilland*) section.
3. In **Skilland Course ID** (*ID de Curso de Skilland*), either:
   - pick an existing Skilland course from the list, or
   - press **Create in SkilLand** (*Crear en SkilLand*) and confirm, to create a new Skilland course named after your Moodle course.
4. Press **Save and display**.

The list shows the Skilland courses you own or collaborate on, and those already linked to Moodle courses you teach.

> [!NOTE]
> **Create in SkilLand** creates the Skilland course immediately and links it, even if you then cancel the form. After you save, Moodle shows an **Open the new course in SkilLand** (*Abrir el nuevo curso en SkilLand*) link.

If creating the course fails, Moodle tells you why:

| Message | What to do |
|---|---|
| *Your SkilLand account cannot create courses* | Ask a Skilland organization admin for the Expert role. |
| *Your SkilLand account is deactivated in this organization* | Ask a Skilland organization admin to reactivate it. |
| *Your email address belongs to a SkilLand account outside this organization* | Use a Moodle account with your organization's email, or ask your administrator. |
| *A SkilLand course with this name already exists* | Rename the Moodle course, or pick the existing Skilland course from the list. |
| *Too many SkilLand courses were created recently* | Wait a few minutes and try again. |

> [!WARNING]
> Changing the link of a course that already has Skilland activities points them at a different Skilland course. Their topics will no longer belong to it. Link the right course before you add activities.

## Add a Skilland activity

Each activity delivers one topic of the linked Skilland course.

1. Turn **Edit mode** on.
2. In the section you want, choose **Add an activity or resource** and pick **Skilland content** (*Contenido Skilland*).
3. In **Topic** (*Tema*), choose the topic. The lessons of that topic appear below, all ticked.
4. Under **Lessons**, untick the lessons learners should not see, or use **Select All** / **Deselect All**.
5. Check the **Activity name**. Moodle fills it in from the topic (for example `T2 - Data analysis`); you can change it.
6. Under **Behaviour Settings** (*Configuración de Comportamiento*), choose:

   | Option | What it does |
   |---|---|
   | **Notify me of content updates** (*Avisarme de actualizaciones de contenido*) | You are told when the topic changes in Skilland. Nothing changes until you apply the update. Off by default. |
   | **Lock after first access** (*Bloquear después del primer acceso*) | Once a learner has opened the content, update notices stop, so the content stays as it is for the rest of the course. Off by default. |
   | **Hide Skilland codes** (*Ocultar códigos de Skilland*) | Removes the `T2 - ` style prefix from the activity name learners see. Off by default. |

7. Optionally set a **Grade** and **Completion conditions**; see [Completion and grades](#completion-and-grades).
8. Press **Save and display**.

If the course is not linked yet, the form shows *Set Skilland Course ID in course settings* instead of the topic list. Link the course first.

## Prepare the content

After saving, the activity shows **Content Not Yet Available** (*Contenido aún no disponible*). The content must be imported from Skilland once:

1. Press **Prepare Topic Content** (*Preparar contenido del tema*).
2. Keep the page open. Large topics can take several minutes.
3. When it finishes, the activity lists its lessons.

Behind the scenes Moodle creates a SCORM activity in the same section that holds the lessons. It is available to learners but not shown on the course page; you see it in edit mode. Do not delete it: the Skilland activity needs it. If it was deleted, the activity offers to prepare the content again.

## What learners see

- The activity on the course page, with your description.
- A list of the lessons you ticked, each with its status (*Not started*, *In progress*, *Completed*, …) and score, and a **Start Lesson** (*Iniciar lección*) button.
- The lesson player, with previous/next lesson buttons and full-screen mode.
- A notice with the date the content was last updated, and a reminder that updates reset progress on the activity.

Before the content is prepared, learners see *This lesson or topic content is being prepared. Please check back later.*

Learners do not see unticked lessons, the hidden SCORM activity, or any Skilland editing link.

<!-- screenshot: activity page with lesson list as seen by a learner -->

## Change the topic or the lessons

Open the activity and go to **Settings**.

- **Show or hide lessons**: tick or untick them under **Lessons** and save. Learners' progress on the other lessons is kept.
- **Change the topic**: choose another topic and save. Moodle asks you to confirm.

> [!WARNING]
> Changing the topic replaces the activity's content and **permanently deletes every learner's attempts and grades** on it. Moodle tells you how many learners have progress before you confirm.

If a lesson was added to the topic in Skilland after you prepared the content, ticking it may show *This lesson is not in the current content package*. Apply an update (below) to include it.

## Content updates

When a topic changes in Skilland, Moodle does not replace anything on its own.

With **Notify me of content updates** on:

1. Moodle checks Skilland every 15 minutes or so.
2. When the topic has changed, you receive a notification (*Content update available: …*), by web notification and email unless you changed your preferences. The activity page shows **Update Available** (*Actualización disponible*).
3. When it suits your course, open the activity and press **Update From Skilland** (*Actualizar desde Skilland*). It is also available in the activity settings, under **Lessons**.
4. Confirm. Moodle downloads the new version and rebuilds the content.

> [!WARNING]
> Applying an update **deletes every learner's attempts and grades on that activity**, and they cannot be recovered. The confirmation tells you how many learners are affected. The safest moment is before learners start, or between course editions.

To keep the content fixed once learners have started, turn on **Lock after first access**: notices stop as soon as a learner opens the activity.

## Completion and grades

The Skilland activity owns completion and the grade. The hidden SCORM activity has no grade item.

**Completion**

1. In the activity settings, open **Completion conditions**.
2. Choose **Add requirements** and tick **Complete all lessons** (*Completar todas las lecciones*).

A learner completes the activity once they have completed or passed every visible lesson. Hidden lessons do not count. An activity with no visible lesson is never complete.

**Grade**

Grading is off by default (*None*). Set a **Maximum grade** in points to turn it on; scales are not supported. The grade is the maximum grade times the average over the visible lessons, where each lesson counts:

- its score (0–100) when the lesson reports one;
- otherwise 100 when it is completed or passed;
- otherwise 0, which includes lessons the learner has not opened.

A learner with no progress at all gets no grade.

Each learner's best status and highest score per lesson are also kept by the plugin, so hiding and showing lessons does not lose them. Applying an update or changing the topic does delete the attempts, as described above.

## FAQ

**I don't see Skilland content in the activity chooser.**
Your administrator may not have installed or enabled it, or your role cannot add it. Ask your Moodle administrator.

**The Topic list stays empty or shows an error.**
Check that the course is linked (course settings, *Skilland Course ID*), and that the Skilland course has topics. If the message says the service could not be reached, try again later or tell your administrator.

**My Skilland course is not in the list when I link the course.**
The list only shows courses you own or collaborate on in Skilland, matched by email. Check that your Moodle and Skilland accounts use the same email, or ask the course owner in Skilland to add you as a collaborator.

**I edited lessons in Skilland. Why don't learners see the change?**
Moodle keeps the version it imported until you press **Update From Skilland**. Turn on **Notify me of content updates** to be told when there is a new version.

**Can I use the same Skilland topic in two activities?**
Yes. Each activity has its own copy of the content and its own learner progress.

**Does a course backup or course copy keep the Skilland activities?**
Yes. The activities and the course's link to Skilland are kept. In a full course backup with user data, learners' attempts are kept too.

**Can learners open Skilland?**
Learners work inside Moodle. They do not get the Skilland links or buttons.
