# Activity type `glossary`

| Verification | Value |
|---|---|
| Type | glossary, learned without a field catalog |
| Moodle | 5.1.7+ (Build: 20260928), branch 501 |
| Original Coursepilot | 2.0.0-beta, version 2026100107 |
| Original verification | 2026-10-01, Spike, creation and round trip (#592) |
| Verification Coursepilot | 2.0.0-beta, version 2026100402 (integration source) |
| English example | 2026-10-04, isolated Moodle 5.1.7+, creation and round trip |

## Minimal example

The following exact XML is checked by the bundled-template integration test.
Teacher-facing titles are translated from the verified Spike example.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<activity id="1" moduleid="1" modulename="glossary" contextid="1">
  <glossary id="1">
    <name>My glossary</name>
    <intro></intro>
    <introformat>1</introformat>
    <allowduplicatedentries>0</allowduplicatedentries>
    <displayformat>dictionary</displayformat>
    <mainglossary>0</mainglossary>
    <showspecial>1</showspecial>
    <showalphabet>1</showalphabet>
    <showall>1</showall>
    <allowcomments>0</allowcomments>
    <allowprintview>1</allowprintview>
    <usedynalink>1</usedynalink>
    <defaultapproval>1</defaultapproval>
    <globalglossary>0</globalglossary>
    <entbypage>10</entbypage>
    <editalways>0</editalways>
    <rsstype>0</rsstype>
    <rssarticles>0</rssarticles>
    <assessed>0</assessed>
    <assesstimestart>0</assesstimestart>
    <assesstimefinish>0</assesstimefinish>
    <scale>100</scale>
    <timecreated>0</timecreated>
    <timemodified>0</timemodified>
    <completionentries>0</completionentries>
    <entries>
    </entries>
    <entriestags>
    </entriestags>
    <categories>
    </categories>
  </glossary>
</activity>
```

## Required structure

This example contains all settings from the Moodle default export. Keep entries,
entriestags and categories empty. Set timecreated and timemodified to 0;
Moodle supplies the actual times. Omitting individual fields has not been verified.

## Pitfalls

The XML creates an empty glossary. Entries are user-data content and are not
restored through activity XML; an entry in XML fails the round trip and the new
activity is removed. After approved creation, use
coursepilot_add_glossary_entries(cmid, entries) to add teacher-authored content.
It also extends existing glossaries; it never returns existing learner entries.

Each entry requires concept and definition. Optional fields include
 definitionformat (0 Moodle, 1 HTML, 2 plain text, 4 Markdown), aliases,
categories (names), usedynalink, casesensitive, fullmatch, approved, tags,
attachment_files, definition_files and location (store or workbench).
For definition_files use @@PLUGINFILE@@/filename in definition. Name material
paths; file bytes remain on the server. Existing material sources stay intact.

Missing category creation requires mod/glossary:managecategories; explicit
approved requires mod/glossary:approve. Omit approved to use Moodle's default.
Autolinking also needs the glossary linking setting and Moodle's glossary filter.
Tags require enabled glossary-entry tagging; standard-only tagging accepts only
existing standard tags in the glossary collection. Report each zero-based result
index and retry only failed entries: earlier successes remain saved and Moodle's
duplicate-entry setting applies. Editing/deleting entries, comments and ratings
are outside this API. History covers the glossary instance only, not entries or
their files; report gap_notice rather than promising undo.
