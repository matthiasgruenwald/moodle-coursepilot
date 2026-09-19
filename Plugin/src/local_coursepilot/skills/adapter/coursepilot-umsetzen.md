---
name: coursepilot-umsetzen
description: Coursepilot-Umsetzung. Nutze diesen Skill bei der Freigabeformulierung "ja, so umsetzen", um einen freigegebenen Coursepilot-Plan in einem bestehenden Moodle-Kurs zu schreiben.
---

# coursepilot-umsetzen

Lies zuerst `coursepilot_get_skill("coursepilot-core")` und
`coursepilot_get_skill("kontextbereich")` für Werkzeuge, Schreibangebot,
Journal-Append unter der Sitzungs-Kontextfreigabe, Handaenderungs-Routine und
Rotation. Halte die Statuspruefung vor Schreibzugriff aus dem Kern ein —
`status.md` wird per `coursepilot_read_context_file` gelesen (mit
Handaenderungs-Pruefung). Vor jedem Schreibzugriff gilt zusätzlich
`coursepilot_get_skill("implementierungsplan-workflow")`; nutze je nach
Aktivität den passenden Korpusteil aus der Uebersicht in `coursepilot-core`.

Nach Moodle-Schreibzugriffen: `status.md` per `coursepilot_write_context_file`
aktualisieren (Schreibangebot), Umsetzungsbericht per
`coursepilot_append_context_file` ins Journal anhaengen (automatisch unter der
Sitzungs-Kontextfreigabe, keine Einzelbestätigung). Scheitert dieses
Anhängen (Ausstand oder Kontext-Lücke, siehe
`coursepilot_get_skill("kontextbereich")`), gilt die Moodle-Umsetzung trotzdem
als abgeschlossen — nur der Bericht wird nachgetragen, nie erneut umgesetzt.

Beim Anlegen oder Aendern einer Frage, deren Fragetyp Coursepilot nicht kennt,
gilt `coursepilot_get_skill("fragetypen")` (Fragetyp-Ablage, Lernschleife,
Widerspruchspruefung).

Am Ende eines abgeschlossenen Aufbaus gilt die Aufraeumfrage aus
`coursepilot_get_skill("kontextbereich")` (Abschnitt "Aufraeumfrage nach
Aufbau").

Halte die Planstrenge aus dem Kern ein.
