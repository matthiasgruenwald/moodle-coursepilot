---
name: mcp-tools
description: Lies diese Datei, wenn du nachschlagen willst, welches Moodle-MCP-Tool für einen Schreib- oder Lesezugriff zustaendig ist.
---

# Referenz: Verfuegbare MCP-Tools

Lies diese Datei, wenn du nachschlagen willst, welches Moodle-MCP-Tool für
einen Schreib- oder Lesezugriff zustaendig ist.

## Kurs, Abschnitte und Aktivitaeten

| Tool | Verwendung |
|---|---|
| `coursepilot_get_sections` | Abschnitte eines Kurses lesen |
| `coursepilot_get_modules` | Aktivitaeten + cmids eines Abschnitts lesen |
| `coursepilot_get_course_catalog` | Kompakte, filterbare read-only Moodle-Katalogansicht für Planung lesen |
| `coursepilot_update_section` | Abschnittsname und bei Planbezug Abschnittseinstieg setzen |
| `coursepilot_move_section` | Bestehenden Abschnitt ohne Inhaltsaenderung an eine neue Position verschieben |
| `coursepilot_move_module` | Bestehende Aktivität per cmid in einen (anderen) Abschnitt oder an eine Position darin verschieben |
| `coursepilot_create_module` | Aktivität jeden Typs anlegen (`modname` wählt den Typ, z.B. `label`, `page`, `assign`, `url`, `resource`, `folder`, `choice`, `forum`) |
| `coursepilot_update_module_settings` | Einzelne Einstellungen einer bestehenden Aktivität jeden Typs patchen (Feldname/-wert je nach `modname`) |
| `coursepilot_set_completion` | Abschlussverfolgung einer Aktivität setzen — einziger Weg für `completion*`-Felder, `create_module`/`update_module_settings` sperren sie |
| `coursepilot_set_restriction` | Voraussetzungen einer Aktivität setzen (Abschluss einer anderen Aktivität, Datum, Gruppe) |
| `coursepilot_ensure_question_bank` | Benannte Kurs-/Projekt-Fragensammlung anlegen oder wiederverwenden (idempotent) |
| `coursepilot_ensure_question_category` | Fragenbank-Kategorie je Unterthema/Inhaltsabschnitt in ausgewählter Fragensammlung finden oder anlegen (idempotent) |
| `coursepilot_update_question_category` | Fragenbank-Kategorie nicht-destruktiv umbenennen und/oder in die richtige Fragensammlung/Zielkategorie verschieben |
| `coursepilot_get_question_categories` | Vorhandene Fragenbank-Kategorien einer ausgewählten Fragensammlung lesen |
| `coursepilot_plan_question_category_cleanup` | Leere, blattlose Kategorien einer Fragensammlung zur manuellen Prüfung auflisten (`courseid`, `questionbankid` als CMID) – rein lesend; liefert Bankname, Kategorie-ID/Name/Eltern-ID, Moodle-Link und Handlungsanweisung; Kategorien mit Fragen oder Unterkategorien und die oberste Kategorie bleiben ausgenommen |
| `coursepilot_move_question` | Frage mit allen Versionen nicht-destruktiv in eine Zielkategorie verschieben |
| `coursepilot_create_quiz` | Quiz (mod_quiz) anlegen – Modus wählt komplette Settings-Kombination (siehe `coursepilot_get_skill("quiz-und-fragenbank")`) |
| `coursepilot_update_quiz_settings` | Bestehendes Quiz nachträglich auf eine Coursepilot-Settings-Kombination umstellen |
| `coursepilot_list_courses` | Kurse lesen, in denen die angemeldete Lehrkraft Coursepilot nutzen darf – Einstieg, wenn keine Kurs-ID bekannt ist |
| `coursepilot_ensure_section` | Abschnitt anlegen, falls die `sectionnum` noch nicht existiert (idempotent; bestehender Abschnitt: nur Namensabgleich) |
| `coursepilot_get_module_settings` | Vollstaendigen Ist-Stand einer Aktivität lesen (`cmid`) – vor jedem Patch, statt eine bestehende Einstellung anzunehmen; meldet bestehende Riegel (`learner_locks`) |
| `coursepilot_describe_module_fields` | Feldkatalog einer Aktivitätsart lesen (`modname`, optional `full`) – welche Felder es gibt, was sie bedeuten, was gesperrt ist, welche Werte ein Riegel sind (`learner_lock`) |
| `coursepilot_clone_activity` | Aktivität duplizieren (`cmid`, `title`, optional `targetcourseid`) – im selben Kurs oder in einen anderen |

Die Schreibwerkzeuge für Einstellungen (`create_module`,
`update_module_settings`, `set_completion`, `set_restriction`, `create_quiz`,
`update_quiz_settings`) lehnen einen Aufruf mit Riegel ab, solange
`confirm_learner_locks` ihn nicht nennt. Wann bestätigt wird, regelt
`coursepilot_get_skill("implementierungsplan-workflow")`, Planungsgrundsätze.

## Fragen und Tests

| Tool | Verwendung |
|---|---|
| `coursepilot_create_mc_question` | Multiple-Choice-Frage anlegen (`categoryid`, `name`, `questiontext`, `selectionmode`, `answers`) |
| `coursepilot_update_mc_question` | Bestehende MC-Frage aendern (`questionid`, `fields_json`) – erzeugt eine neue Fragenversion |
| `coursepilot_get_question` | Aktuelle Version einer Frage lesen (`categoryid` plus `name` **oder** `questionid`) – vor jeder Bearbeitung |
| `coursepilot_import_questions_xml` | Fragen aus Moodle-XML in eine Kategorie einspielen (`categoryid`) – Weg für Fragetypen jenseits MC |
| `coursepilot_export_questions_xml` | Bestehende Fragen als Moodle-XML ausgeben (`questionids`) – Vorlage für einen Import |
| `coursepilot_plan_quiz_cleanup` | Manuellen Bereinigungsplan für ueberzaehlige Quiz-Slots erstellen (`cmid`, `keep_questionbankentryids`) – löscht nichts, nennt Links |
| `coursepilot_report_clone_lineage` | Je Frage eines geklonten Tests melden, ob eigene Kopie oder geteilte Referenz auf den Quellkurs (`cmid`) – rein lesend |

## Versionsverlauf einer Aktivität

| Tool | Verwendung |
|---|---|
| `coursepilot_list_activity_versions` | Alle erfassten Staende einer Aktivität auflisten (`cmid`) – je Version eine Zeile, was sich gegenueber dem Vorgaenger geaendert hat |
| `coursepilot_compare_activity_versions` | Zwei frei gewaehlte Staende vergleichen (`cmid`, `from_version`, `to_version`) – Feld- und Dateiunterschiede |
| `coursepilot_restore_activity_version` | Einen frueheren Stand als neue juengste Version fortschreiben (`cmid`, `target_version`) – kein Rueckspulen, cmid bleibt stabil. Wuerden dabei Abschlussdaten von Lernenden geloescht, meldet der erste Aufruf das; erst ein zweiter mit `confirmed: true` schreibt die Abschlussfelder mit |

## Materialbestand und Werkbank

Der eigene Materialordner der Lehrkraft – Bilder und Dokumente, die in
Aktivitaeten eingebettet werden. Alle Pfade sind relativ zur Wurzel des
Materialordners; das Werkzeug heisst den Pfad ueberall `path`, nie `course_id`.

Die lesenden Werkzeuge (`coursepilot_list_material_files`,
`coursepilot_preview_material_file`, Quelle von `coursepilot_crop_material_file`)
nehmen zusätzlich `location`: `bestand` (Standard, gewachsener Materialordner der
Lehrkraft, nur lesend) oder `werkbank` (Chat-Anhänge, Zuschnitte). Details
zum Eintragstyp `kontextbereich` und zur Sperre am Kontextbereich stehen in
`coursepilot_get_skill("kontextbereich")`.

| Tool | Verwendung |
|---|---|
| `coursepilot_list_material_files` | Materialordner auflisten (optional `path` für einen Unterordner, leer = Wurzel, optional `location`) – Größe, `contenthash`, Restspeicher |
| `coursepilot_upload_material_file` | Datei anlegen oder ersetzen (`path`, `content_base64`) – immer auf der Werkbank, kein `location` |
| `coursepilot_preview_material_file` | Verkleinerte Vorschau eines Bildes ansehen (`path`, optional `location`) – damit ein Ausschnitt oder ein Alt-Text nicht geraten wird |
| `coursepilot_crop_material_file` | Bild auf einen Ausschnitt zuschneiden (`sourcepath`, `targetpath`, `x0`/`y0`/`x1`/`y1` relativ 0–1 auf die Vorschau) – Ziel immer Werkbank |
| `coursepilot_compose_material_file` | Geordnete `parts` mit optionalem Ausschnitt und Quellenkopf als eine PNG zusammensetzen (`arrangement`: `vertical` oder `horizontal`, `targetpath`) – Ziel immer Werkbank, Originalauflösung, fester Hausstil |
| `coursepilot_report_loose_material_files` | Dateien melden, die in keiner Aktivität verwendet werden – liest nur, nur Werkbank |
| `coursepilot_delete_material_files` | Genau die genannten Pfade löschen (`paths`) – nur nach ausdrücklicher Bestätigung der Lehrkraft, nur Werkbank |
| `coursepilot_create_werkbank_download_links` | Je Werkbankdatei einen 15 Minuten gültigen Einmal-Downloadlink ausstellen (`paths`) – für einen Client mit Shell (curl), ohne OAuth-Bearer-Header; liefert URL, Name, Größe, SHA-1, keine fertige Abrufzeile |

Aktivitätstyp-Auswahl (welcher `modname` für welche Situation) steht in
`coursepilot_get_skill("implementierungsplan-workflow")`.

### Quellenkopf und Zusammensetzen

`coursepilot_compose_material_file` nimmt je Teil `sourcepath`, eigenes
`location` (`bestand` als Standard oder `werkbank`, gemischt erlaubt), optional
`crop` mit `x0`/`y0`/`x1`/`y1` relativ 0–1 wie beim Gezielten Bildausschnitt,
`source_header_text` und `expected_contenthash`. Der Prüfwert ist hier der
SHA-1 der **jeweiligen Quelle**; beim Zuschnitt-Werkzeug schützt er dagegen
das Ziel. Bei externem Bestand ohne bekannten SHA-1 den optionalen Wert
weglassen. Fehlender Ausschnitt bedeutet das ganze Original; fehlender oder
leerer Kopftext bedeutet keinen Kopfstreifen. Zielpfade werden wie beim
Zuschnitt aufgelöst und müssen `.png` enden; vorhandene Ziele werden ersetzt.

Ein Teil mit Kopf und Ausschnitt braucht keine Zwischendatei:

```json
{
  "parts": [{
    "sourcepath": "ml-s36.png",
    "location": "bestand",
    "crop": {"x0": 0.1, "y0": 0.2, "x1": 0.9, "y1": 0.5},
    "source_header_text": "ML S. 36"
  }],
  "arrangement": "vertical",
  "targetpath": "ml-s36-aufgabe-kopf.png"
}
```

Die Rückgabe nennt `path`, `width`, `height`, `size`, `contenthash`, `created`,
`message` und geordnete `sources` (Ort, aufgelöster Pfad, Größe, Änderungszeit),
keine Bildbytes. Quellenkopf-Standard, Kürzelklärung, Planfreigabe und Alt-Text
stehen in `coursepilot_get_skill("grafiken")`.

## Kontextbereich

Arbeitsdateien (`plan.md`, `status.md`, Journal, Materialnotizen,
Kontextprofile). Zwei Parameter: `pending_entry=<Kennung>` an
`coursepilot_write_context_file`/`coursepilot_append_context_file` trägt einen
offenen Eintrag der Ausstandsnotiz nach; `previous_location: true` an
`coursepilot_list_context_files`/`coursepilot_read_context_file` ist der
**Nur-Lese-Schalter** für den Altbestand (wirkt nur, solange einer offen
ist). Alle Ausfall-/Konflikt-/Ortswahl-Regeln stehen vollständig in
`coursepilot_get_skill("kontextbereich")`, hier nur die Namen zum Nachschlagen:

| Tool | Verwendung |
|---|---|
| `coursepilot_list_context_files` | Ordnerinhalt auflisten, optional `previous_location` (Nur-Lese-Schalter für den Altbestand) |
| `coursepilot_read_context_file` | Datei lesen, optional `previous_location` |
| `coursepilot_write_context_file` | Anlegen/vollständig überschreiben, optional `pending_entry` (Nachtragen einer Kennung), optional `create_only` (Kopieren aus dem Altbestand) |
| `coursepilot_append_context_file` | Anhängen, optional `pending_entry` |
| `coursepilot_dismiss_ausstand` | Einen Eintrag der Ausstandsnotiz ausdrücklich verwerfen |
| `coursepilot_dismiss_altbestand` | Den Altbestand (vorheriger Ort) ausdrücklich beenden |
