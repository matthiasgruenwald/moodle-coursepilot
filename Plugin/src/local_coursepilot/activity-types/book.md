# Activity type `book`

| Verification | Value |
|---|---|
| Type | book, learned without a field catalog |
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
<activity id="1" moduleid="1" modulename="book" contextid="1">
  <book id="1">
    <name>Short book</name>
    <intro></intro>
    <introformat>1</introformat>
    <numbering>1</numbering>
    <navstyle>1</navstyle>
    <customtitles>0</customtitles>
    <chapters>
      <chapter id="1">
        <pagenum>1</pagenum>
        <subchapter>0</subchapter>
        <title>Chapter 1</title>
        <content>&lt;p&gt;Text.&lt;/p&gt;</content>
        <contentformat>1</contentformat>
        <hidden>0</hidden>
      </chapter>
    </chapters>
  </book>
</activity>
```

## Required structure

The example omits book timecreated/timemodified, chapter timemcreated/timemodified,
importsrc and chaptertags. Moodle supplies these as presets. Each chapter includes
pagenum, subchapter, title, content, contentformat and hidden. Content is escaped
HTML with contentformat 1. A subchapter uses subchapter 1 and follows its parent;
pagenum defines the order. Further omissions have not been verified.

## Pitfalls

No additional pitfall was observed for this example. Activity history covers the
book instance, not its chapters; do not promise chapter restoration.
