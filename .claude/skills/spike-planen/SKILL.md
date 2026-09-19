---
name: spike-planen
description: Kurspilot-Planung gegen das native Server-MCP (Spike, local_coursepilot). Nutze diesen Skill statt kurspilot-planen, wenn die Lehrkraft ausdruecklich den nativen Kontextbereich (serverseitig, ueber die coursepilot_*_context_file-Tools) statt der lokalen Arbeitsdateien nutzt.
---

# spike-planen

Vorlaeufiger Name (Issue #410) fuer die Planung gegen `local_coursepilot`
(Branch `moodle-native-mcp`) — kein Ersatz fuer das produktive
`kurspilot-planen`, das unveraendert lokal bleibt.

Lies zuerst `../../../skills/spike-kontextbereich.md` fuer Werkzeuge,
Schreibangebot, Handaenderungs-Routine und Rotation. Fuer Planstrenge,
Ein-Plan-Regel und die uebrigen fachlichen Ankerbegriffe gilt
`../../../skills/kurspilot-core.md` unveraendert — nur die
Arbeitsbereich-Regel entfaellt (siehe "Was hier nicht gilt" in
`spike-kontextbereich.md`). Je nach Planungsschritt zusaetzlich die
themenbezogenen Referenzdateien aus der Uebersicht in `kurspilot-core.md`.

`plan.md`, `status.md` und Vorlagen werden nur nach dem Schreibangebot
geschrieben (`coursepilot_write_context_file`), nie still.

Geht es in der Planung um einen Fragetyp fuer die Fragenbank, gilt
zusaetzlich `../../../skills/spike-fragetypen.md` (Fragetyp-Ablage,
Lernschleife).
