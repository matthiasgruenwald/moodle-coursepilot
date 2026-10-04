# Activity type `checklist`

| Verification | Value |
|---|---|
| Type | checklist, learned without a field catalog |
| Activity module | Requires installed mod_checklist; verified with 4.1.0.8 (2026042400) |
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
<activity id="1" moduleid="1" modulename="checklist" contextid="1">
  <checklist id="1">
    <name>My checklist</name>
    <intro></intro>
    <introformat>1</introformat>
    <timecreated>0</timecreated>
    <timemodified>0</timemodified>
    <useritemsallowed>0</useritemsallowed>
    <teacheredit>0</teacheredit>
    <theme>default</theme>
    <maxgrade>100</maxgrade>
    <items>
      <item id="1">
        <userid>0</userid>
        <displaytext>Read task 1</displaytext>
        <position>1</position>
        <indent>0</indent>
        <itemoptional>0</itemoptional>
        <hidden>0</hidden>
        <duetime>0</duetime>
      </item>
      <item id="2">
        <userid>0</userid>
        <displaytext>Optional task</displaytext>
        <position>2</position>
        <indent>0</indent>
        <itemoptional>1</itemoptional>
        <hidden>0</hidden>
        <duetime>0</duetime>
      </item>
      <item id="3">
        <userid>0</userid>
        <displaytext>Finish</displaytext>
        <position>3</position>
        <indent>0</indent>
        <itemoptional>2</itemoptional>
        <hidden>0</hidden>
        <duetime>0</duetime>
      </item>
    </items>
  </checklist>
</activity>
```

## Required structure

Include timecreated and timemodified with value 0, as well as the other settings
in this example. Moodle adds omitted settings such as autoupdate and teachercomments
as presets. Each item includes userid 0, displaytext, position, indent,
itemoptional, hidden and duetime. itemoptional 0 is required, 1 optional and 2 a
heading. Further omissions have not been verified.

## Pitfalls

- "Column 'timecreated' cannot be null": include timecreated and timemodified
  with value 0. A failed creation is removed.
- "Undefined property: duetime": include duetime 0 for every item.
- Activity history covers the checklist instance, not its items or learner checks.
