# local_coursepilot

**Coursepilot** – Moodle plugin for AI-supported course authoring via webservice / MCP.

This is the Moodle plugin (component `local_coursepilot`, Moodle **5.0 or newer**).
It is only useful together with the local Coursepilot MCP. The primary
development, support and issue repository (MCP, installer, skills, tests) is
[matthiasgruenwald/moodle-coursepilot](https://github.com/matthiasgruenwald/moodle-coursepilot);
this plugin source tree is published as a read-only mirror
([matthiasgruenwald/moodle-local_coursepilot](https://github.com/matthiasgruenwald/moodle-local_coursepilot))
for the Moodle Plugin Directory. Development, issues and support happen
exclusively in the primary repository.

## License

The Moodle plugin `local_coursepilot` is licensed under **GPL-3.0-or-later** (see
[LICENSE](LICENSE)). The accompanying Coursepilot MCP server and installer in the
primary repository are licensed under AGPL-3.0-or-later; the plugin itself is GPL.

## Provided webservice functions

| Function | Description |
|---|---|
| `local_coursepilot_create_page` | Creates a page (mod_page) in a course section |
| `local_coursepilot_create_assign` | Creates an assignment (mod_assign) in a course section |
| `local_coursepilot_update_section` | Sets the name and summary of a section |
| `local_coursepilot_get_sections` | Returns all sections of a course |

---

## Installation

Requirement: Moodle **5.0 or newer**.

1. **Unzip** into `[moodle-root]/local/coursepilot/`
2. Open the Moodle admin area → **Run upgrade**
3. Done – the plugin is installed

> **Important – fresh install when the old component is present:** Coursepilot now
> uses the component `local_coursepilot`. If the old component `local_aicoursecreator`
> is still installed, **uninstall** it first (Site administration → Plugins →
> Manage plugins) and then install `local_coursepilot`. There is deliberately no
> migration of data, settings or web services from `local_aicoursecreator`.

---

## Privacy and AI client

The plugin itself **does not call any AI provider**. Coursepilot uses an AI client
configured **locally** on the teacher's machine; only when the teacher hands course
content to that client can it be transmitted to the client's provider.

Coursepilot is intended exclusively for course authoring by the teacher and exposes
**no learner data** – in particular no assignment submissions, forum posts, quiz
attempts, grades or participant lists. The Moodle privacy API describes this
behaviour via a `null_provider` (no stored personal data).

---

## Languages

English (`lang/en/local_coursepilot.php`) is the base language. German is bundled
**temporarily** during the transition (`lang/de/local_coursepilot.php`) until the
translation is maintained via **AMOS**; afterwards the bundled German file will be
removed in an early release.

---

## Configuration (webservice + token)

### 1. Enable web services
`Site administration → Advanced features → Enable web services` ✅

### 2. Enable the REST protocol
`Site administration → Plugins → Web services → Manage protocols → REST` ✅

### 3. Create a token
`User menu → Preferences → Security keys`
- **Service:** `Coursepilot`
- **Who can use it:** any user who can create a security key for the `Coursepilot` service. The service has no authorised-users list (`restrictedusers=0`); there is no dedicated Coursepilot role.
- **Permissions:** what a user can do via the API is limited by their own Moodle capabilities in each course (e.g. editing teacher rights), in addition to the `local/coursepilot:use` capability (allowed by default for teachers and editing teachers). The API never grants anything a user could not already do on the website.
- Copy the token and store it securely.

### 4. Connect via MCP (webservice_mcp plugin)
MCP endpoint:
```
https://YOUR-MOODLE-URL/webservice/mcp/server.php?wstoken=YOUR_TOKEN
```

---

## Example API call (REST)

### Create a page
```
POST https://moodle.example.com/webservice/rest/server.php
wstoken=abc123
wsfunction=local_coursepilot_create_page
moodlewsrestformat=json
courseid=5
sectionnum=1
name=Introduction to the topic
content=<p>Welcome to this section...</p>
```

### Create an assignment
```
POST https://moodle.example.com/webservice/rest/server.php
wstoken=abc123
wsfunction=local_coursepilot_create_assign
moodlewsrestformat=json
courseid=5
sectionnum=1
name=Assignment 1: Research
description=<p>Research the following topics...</p>
duedate=1735689600
maxfiles=3
```

### Name a section
```
POST .../server.php
wsfunction=local_coursepilot_update_section
courseid=5
sectionnum=1
name=Unit 1: Basics
summary=<p>In this section you will learn...</p>
```

---

## Parameter reference

### create_page
| Parameter | Type | Required | Description |
|---|---|---|---|
| courseid | int | ✅ | Course ID |
| sectionnum | int | ✅ | Section number (0-based) |
| name | string | ✅ | Page title |
| content | string | ✅ | HTML content |
| visible | int | – | 1=visible (default), 0=hidden |

### create_assign
| Parameter | Type | Required | Description |
|---|---|---|---|
| courseid | int | ✅ | Course ID |
| sectionnum | int | ✅ | Section number (0-based) |
| name | string | ✅ | Assignment title |
| description | string | – | HTML description |
| duedate | int | – | Due date as Unix timestamp (0 = no date) |
| allowsubmissionsfromdate | int | – | Allow submissions from (0 = immediately) |
| maxfiles | int | – | Max file uploads (default: 1, 0 = no upload) |
| submissiondrafts | int | – | 1 = students must click submit |
| visible | int | – | 1=visible (default) |

### update_section
| Parameter | Type | Required | Description |
|---|---|---|---|
| courseid | int | ✅ | Course ID |
| sectionnum | int | ✅ | Section number |
| name | string | – | Section name |
| summary | string | – | HTML summary |
| visible | int | – | 1=visible (default) |

---

## Compatibility
- Moodle 5.0 or newer
- PHP 8.1 or newer (Moodle 5.0 support)
