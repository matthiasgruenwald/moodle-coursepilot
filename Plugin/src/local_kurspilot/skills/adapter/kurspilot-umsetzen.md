---
name: kurspilot-umsetzen
description: Kurspilot-Umsetzung. Nutze diesen Skill bei der Freigabeformulierung "ja, so umsetzen", um einen freigegebenen Kurspilot-Plan in einem bestehenden Moodle-Kurs zu schreiben.
---

# kurspilot-umsetzen

Lies zuerst `kurspilot_get_skill("kurspilot-core")` und
`kurspilot_get_skill("kontextbereich")` fuer Werkzeuge, Schreibangebot,
Journal-Append unter der Sitzungs-Kontextfreigabe, Handaenderungs-Routine und
Rotation. Halte die Statuspruefung vor Schreibzugriff aus dem Kern ein —
`status.md` wird per `kurspilot_read_context_file` gelesen (mit
Handaenderungs-Pruefung). Vor jedem Schreibzugriff gilt zusaetzlich
`kurspilot_get_skill("implementierungsplan-workflow")`; nutze je nach
Aktivitaet den passenden Korpusteil aus der Uebersicht in `kurspilot-core`.

Nach Moodle-Schreibzugriffen: `status.md` per `kurspilot_write_context_file`
aktualisieren (Schreibangebot), Umsetzungsbericht per
`kurspilot_append_context_file` ins Journal anhaengen (automatisch unter der
Sitzungs-Kontextfreigabe, keine Einzelbestaetigung). Scheitert dieses
Anhaengen (Ausstand oder Kontext-Lücke, siehe
`kurspilot_get_skill("kontextbereich")`), gilt die Moodle-Umsetzung trotzdem
als abgeschlossen — nur der Bericht wird nachgetragen, nie erneut umgesetzt.

Beim Anlegen oder Aendern einer Frage, deren Fragetyp Kurspilot nicht kennt,
gilt `kurspilot_get_skill("fragetypen")` (Fragetyp-Ablage, Lernschleife,
Widerspruchspruefung).

Am Ende eines abgeschlossenen Aufbaus gilt die Aufraeumfrage aus
`kurspilot_get_skill("kontextbereich")` (Abschnitt "Aufraeumfrage nach
Aufbau").

Halte die Planstrenge aus dem Kern ein.
