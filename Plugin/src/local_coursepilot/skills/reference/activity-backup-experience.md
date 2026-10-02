---
name: activity-backup-experience
description: Read this before building or reworking an activity backup (.mbz or activity XML) by hand, or before preparing a new activity type for create_activity_from_xml. Field experience, not a verified rule set.
---

# Activity backups: field experience

**Status: experience notes.** Everything below was observed in real imports on real
Moodle instances (lightboxgallery and checklist, September 2026). It is not a verified
rule set and not a promise that it holds for every Moodle or plugin version. Treat each
point as a strong hint, check it against a real backup of the same instance, and record
what you verified in the activity-type file (`activity-types/<modname>.md`, see
`coursepilot_get_skill("activity-types")`).

`mod_lightboxgallery` served as the first worked example; the points apply to every
activity type that is built or reworked outside Moodle's own backup tool.

## Which part applies where

- **Server path** (`create_activity_from_xml`): you only supply the activity XML
  (`<mod>.xml`). The plugin builds the backup scaffold, restores and runs the round trip.
  Sections 3 and 4 apply; sections 1 and 2 are handled by the plugin.
- **Local path** (a complete `.mbz` built or reworked by script, then imported through
  Moodle's restore): all sections apply.

## 1. No PAX extended headers in the tar archive (local path)

An `.mbz` is a gzip-compressed tar archive. Moodle's PHP tar parser rejects PAX extended
headers during restore; the import then fails with an "invalid encoding" error. Python's
`tarfile` writes such headers automatically when `mtime` is a float (sub-second precision):
each affected file gets a `././@PaxHeader` entry. A real Moodle backup contains none.

- **Diagnosis:** scan the tar bytes for typeflag `x`/`X`/`L`/`K` at offset 156 of every
  512-byte block.
- **Fix:** write with `format=tarfile.USTAR_FORMAT`, force an integer `mtime` per entry, set
  `uid=0`, `gid=0`, `uname=''`, `gname=''` like the original, and gzip separately
  afterwards (not `tarfile` mode `w:gz`), so you keep control of the headers.

```python
def clean_filter(tarinfo):
    tarinfo.mtime = int(tarinfo.mtime)
    tarinfo.uid = tarinfo.gid = 0
    tarinfo.uname = tarinfo.gname = ''
    return tarinfo
# tarfile.open(out, mode="w", format=tarfile.USTAR_FORMAT), then tf.add(..., filter=clean_filter)
```

Checklist before every import:

1. `gzip -t file.mbz` passes.
2. The byte scan finds 0 PAX headers.
3. Every XML file parses (`xml.etree.ElementTree`).
4. Every `<contenthash>` in `files.xml` equals the SHA1 of the file under
   `files/<hash[:2]>/<hash>`.

## 2. Start from a real backup, patch values only (local path)

Confirmed for `mod_checklist`: a real, restorable activity backup of the same plugin
version works as a template. Data may change, the backup structure may not.

1. Always start from a real, working backup of the same activity/plugin version.
2. Do not re-serialise critical XML files when only single values change. Keep the original
   bytes and replace only the field contents you need.
3. Change the activity name consistently in every place: `<mod>.xml <name>`,
   the backup manifest (moodle\_backup.xml, `<title>`), `grades.xml <itemname>`.
4. After changing a file, update its size in `.ARCHIVE_INDEX`; keep the remaining structure
   and timestamps.
5. Then build USTAR without PAX/GNU headers and run the checks from section 1.

Relevant files for `mod_checklist`: `activities/checklist_<cmid>/checklist.xml` (name,
intro, settings, items), `module.xml` (visibility, completion, availability), `grades.xml`
(gradebook name), the backup manifest moodle\_backup.xml (title), `.ARCHIVE_INDEX` (sizes).

## 3. Activity-specific observations (both paths)

**Gallery thumbnails need a fixed crop** (`mod_lightboxgallery`, and in principle every
activity with generated previews). A grid with fixed tile size distorts thumbnails that
were only scaled proportionally. Symptom: the tile looks squashed, the enlarged view (which
loads the original) looks fine. In a real backup **all** thumbnails have exactly the same
pixel size regardless of the original aspect ratio, i.e. a centred crop ("cover"). The
target size is instance/activity specific (observed once: 162×132 px); read it from a real
backup of the same instance first.

- **Fix:** scale proportionally until the image covers the target size, then crop centred
  to the target size. After replacing a thumbnail, update `<contenthash>` (SHA1) and
  `<filesize>` in `files.xml` and store the file under its new hash path.

**Checklist items** (`mod_checklist`):

- `displaytext` text, `position` order, `indent` indentation,
  `itemoptional` 0 = normal, 1 = optional, 2 = heading (not tickable),
  `moduleid` direct link to a course module.
- In an isolated activity backup, `moduleid` links to other activities do not survive:
  the plugin cannot map them to the target module and drops those items. Use the exact
  activity name as text instead; Moodle's activity-name filter can turn it into a link.
- An inherited prerequisite sits in `module.xml`, field `availability`. For an independent
  new checklist, empty that field without touching the other module settings.
  (Observed: renaming worked in a real import; emptying `availability` was not yet
  confirmed by an import.)

## 4. Files and the AI context

Images, packages and other binary content belong in Moodle's file areas, not in the AI
context. The regular way is to name paths in the material store and let the server copy
the files. Loading file contents into the context is an exception that needs the
teacher's explicit consent after the costs were made transparent (ADR 0028, addendum
2026-10-02). Until the file supplement (#598) exists, activity types with files in their
content cannot be created from XML.
