---
name: mcp-tools
description: Lies diese Datei, wenn du nachschlagen willst, welches Moodle-MCP-Tool fuer einen Schreib- oder Lesezugriff zustaendig ist.
---

# Referenz: Verfuegbare MCP-Tools

Lies diese Datei, wenn du nachschlagen willst, welches Moodle-MCP-Tool fuer
einen Schreib- oder Lesezugriff zustaendig ist.

## Kurs, Abschnitte und Aktivitaeten

| Tool | Verwendung |
|---|---|
| `kurspilot_get_sections` | Abschnitte eines Kurses lesen |
| `kurspilot_get_modules` | Aktivitaeten + cmids eines Abschnitts lesen |
| `kurspilot_get_course_catalog` | Kompakte, filterbare read-only Moodle-Katalogansicht fuer Planung lesen |
| `kurspilot_update_section` | Abschnittsname und bei Planbezug Abschnittseinstieg setzen |
| `kurspilot_move_section` | Bestehenden Abschnitt ohne Inhaltsaenderung an eine neue Position verschieben |
| `kurspilot_move_module` | Bestehende Aktivitaet per cmid in einen (anderen) Abschnitt oder an eine Position darin verschieben |
| `kurspilot_create_module` | Aktivitaet jeden Typs anlegen (`modname` waehlt den Typ, z.B. `label`, `page`, `assign`, `url`, `resource`, `folder`, `choice`, `forum`) |
| `kurspilot_update_module_settings` | Einzelne Einstellungen einer bestehenden Aktivitaet jeden Typs patchen (Feldname/-wert je nach `modname`) |
| `kurspilot_set_completion` | Abschlussverfolgung einer Aktivitaet setzen — einziger Weg fuer `completion*`-Felder, `create_module`/`update_module_settings` sperren sie |
| `kurspilot_set_restriction` | Voraussetzungen einer Aktivitaet setzen (Abschluss einer anderen Aktivitaet, Datum, Gruppe) |
| `kurspilot_ensure_question_bank` | Benannte Kurs-/Projekt-Fragensammlung anlegen oder wiederverwenden (idempotent) |
| `kurspilot_ensure_question_category` | Fragenbank-Kategorie je Unterthema/Inhaltsabschnitt in ausgewählter Fragensammlung finden oder anlegen (idempotent) |
| `kurspilot_update_question_category` | Fragenbank-Kategorie nicht-destruktiv umbenennen und/oder in die richtige Fragensammlung/Zielkategorie verschieben |
| `kurspilot_get_question_categories` | Vorhandene Fragenbank-Kategorien einer ausgewählten Fragensammlung lesen |
| `kurspilot_move_question` | Frage mit allen Versionen nicht-destruktiv in eine Zielkategorie verschieben |
| `kurspilot_create_quiz` | Quiz (mod_quiz) anlegen – Modus waehlt komplette Settings-Kombination (siehe `kurspilot_get_skill("quiz-und-fragenbank")`) |
| `kurspilot_update_quiz_settings` | Bestehendes Quiz nachträglich auf eine Kurspilot-Settings-Kombination umstellen |
| `kurspilot_list_courses` | Kurse lesen, in denen die angemeldete Lehrkraft Kurspilot nutzen darf – Einstieg, wenn keine Kurs-ID bekannt ist |
| `kurspilot_ensure_section` | Abschnitt anlegen, falls die `sectionnum` noch nicht existiert (idempotent; bestehender Abschnitt: nur Namensabgleich) |
| `kurspilot_get_module_settings` | Vollstaendigen Ist-Stand einer Aktivitaet lesen (`cmid`) – vor jedem Patch, statt eine bestehende Einstellung anzunehmen |
| `kurspilot_describe_module_fields` | Feldkatalog einer Aktivitaetsart lesen (`modname`, optional `vollstaendig`) – welche Felder es gibt, was sie bedeuten, was gesperrt ist |
| `kurspilot_clone_activity` | Aktivitaet duplizieren (`cmid`, `title`, optional `targetcourseid`) – im selben Kurs oder in einen anderen |

## Fragen und Tests

| Tool | Verwendung |
|---|---|
| `kurspilot_create_mc_question` | Multiple-Choice-Frage anlegen (`categoryid`, `name`, `questiontext`, `selectionmode`, `answers`) |
| `kurspilot_update_mc_question` | Bestehende MC-Frage aendern (`questionid`, `felder_json`) – erzeugt eine neue Fragenversion |
| `kurspilot_get_question` | Aktuelle Version einer Frage lesen (`categoryid` plus `name` **oder** `questionid`) – vor jeder Bearbeitung |
| `kurspilot_import_questions_xml` | Fragen aus Moodle-XML in eine Kategorie einspielen (`categoryid`) – Weg fuer Fragetypen jenseits MC |
| `kurspilot_export_questions_xml` | Bestehende Fragen als Moodle-XML ausgeben (`questionids`) – Vorlage fuer einen Import |
| `kurspilot_plan_quiz_cleanup` | Manuellen Bereinigungsplan fuer ueberzaehlige Quiz-Slots erstellen (`cmid`, `keep_questionbankentryids`) – loescht nichts, nennt Links |
| `kurspilot_report_clone_lineage` | Je Frage eines geklonten Tests melden, ob eigene Kopie oder geteilte Referenz auf den Quellkurs (`cmid`) – rein lesend |

## Versionsverlauf einer Aktivitaet

| Tool | Verwendung |
|---|---|
| `kurspilot_list_activity_versions` | Alle erfassten Staende einer Aktivitaet auflisten (`cmid`) – je Version eine Zeile, was sich gegenueber dem Vorgaenger geaendert hat |
| `kurspilot_compare_activity_versions` | Zwei frei gewaehlte Staende vergleichen (`cmid`, `von_version`, `nach_version`) – Feld- und Dateiunterschiede |
| `kurspilot_restore_activity_version` | Einen frueheren Stand als neue juengste Version fortschreiben (`cmid`, `zielversion`) – kein Rueckspulen, cmid bleibt stabil. Wuerden dabei Abschlussdaten von Lernenden geloescht, meldet der erste Aufruf das; erst ein zweiter mit `bestaetigt: true` schreibt die Abschlussfelder mit |

## Materialordner

Der eigene Materialordner der Lehrkraft – Bilder und Dokumente, die in
Aktivitaeten eingebettet werden. Alle Pfade sind relativ zur Wurzel des
Materialordners; das Werkzeug heisst den Pfad ueberall `path`, nie `course_id`.

| Tool | Verwendung |
|---|---|
| `kurspilot_list_material_files` | Materialordner auflisten (optional `path` fuer einen Unterordner, leer = Wurzel) – Groesse, `contenthash`, Restspeicher |
| `kurspilot_upload_material_file` | Datei anlegen oder ersetzen (`path`, `content_base64`) |
| `kurspilot_preview_material_file` | Verkleinerte Vorschau eines Bildes ansehen (`path`) – damit ein Ausschnitt oder ein Alt-Text nicht geraten wird |
| `kurspilot_crop_material_file` | Bild auf einen Ausschnitt zuschneiden (`sourcepath`, `targetpath`, `x0`/`y0`/`x1`/`y1` relativ 0–1 auf die Vorschau) |
| `kurspilot_report_loose_material_files` | Dateien melden, die in keiner Aktivitaet verwendet werden – liest nur |
| `kurspilot_delete_material_files` | Genau die genannten Pfade loeschen (`paths`) – nur nach ausdruecklicher Bestaetigung der Lehrkraft |

Aktivitaetstyp-Auswahl (welcher `modname` fuer welche Situation) steht in
`kurspilot_get_skill("implementierungsplan-workflow")`.
