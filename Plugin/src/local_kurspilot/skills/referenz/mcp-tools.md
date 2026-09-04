---
name: mcp-tools
description: Lies diese Datei, wenn du nachschlagen willst, welches Moodle-MCP-Tool fuer einen Schreib- oder Lesezugriff zustaendig ist.
---

# Referenz: Verfuegbare MCP-Tools

Lies diese Datei, wenn du nachschlagen willst, welches Moodle-MCP-Tool fuer
einen Schreib- oder Lesezugriff zustaendig ist.

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

Aktivitaetstyp-Auswahl (welcher `modname` fuer welche Situation) steht in
`kurspilot_get_skill("implementierungsplan-workflow")`.
