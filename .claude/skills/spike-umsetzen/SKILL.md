---
name: spike-umsetzen
description: Kurspilot-Umsetzung gegen das native Server-MCP (Spike, local_coursepilot). Nutze diesen Skill statt kurspilot-umsetzen, wenn die Lehrkraft ausdruecklich den nativen Kontextbereich (serverseitig, ueber die coursepilot_*_context_file-Tools) statt der lokalen Arbeitsdateien nutzt.
---

# spike-umsetzen

Vorlaeufiger Name (Issue #410) fuer die Umsetzung gegen `local_coursepilot`
(Branch `moodle-native-mcp`) — kein Ersatz fuer das produktive
`kurspilot-umsetzen`, das unveraendert lokal bleibt.

Lies zuerst `../../../skills/spike-kontextbereich.md` fuer Werkzeuge,
Schreibangebot, Journal-Append unter der Sitzungs-Kontextfreigabe,
Handaenderungs-Routine und Rotation. Halte die Statuspruefung vor
Schreibzugriff aus `../../../skills/kurspilot-core.md` ein — `status.md` wird
per `coursepilot_read_context_file` gelesen (mit Handaenderungs-Pruefung), nicht
von der Festplatte. Vor jedem Moodle-Schreibzugriff gilt zusaetzlich
`../../../skills/implementierungsplan-workflow.md`.

Nach Moodle-Schreibzugriffen: `status.md` per `coursepilot_write_context_file`
aktualisieren (Schreibangebot), Umsetzungsbericht per
`coursepilot_append_context_file` ins Journal anhaengen (automatisch unter der
Sitzungs-Kontextfreigabe, keine Einzelbestaetigung). Halte die Planstrenge aus
dem Kern ein.

Beim Anlegen oder Aendern einer Frage, deren Fragetyp Kurspilot nicht kennt,
gilt `../../../skills/spike-fragetypen.md` (Fragetyp-Ablage `fragetypen/<typ>.md`,
Lernschleife mit hoechstens drei Versuchen, Transparenzpflicht,
Widerspruchspruefung).

Am Ende eines abgeschlossenen Aufbaus gilt die Aufraeumfrage aus
`../../../skills/spike-kontextbereich.md` (Abschnitt "Aufraeumfrage nach
Aufbau"): `coursepilot_report_loose_material_files` aufrufen, nur bei
tatsaechlich losen Dateien aktiv fragen (Anzahl, Groesse, Dateiliste),
bei knapper Quote zusaetzlich den Restplatz nennen, und ausschliesslich
auf ausdrueckliche Antwort per `coursepilot_delete_material_files` loeschen.
