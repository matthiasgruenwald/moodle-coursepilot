---
name: coursepilot-plan
description: Coursepilot-Planung. Nutze diesen Skill bei Formulierungen wie "Plane den Abschnitt für ..." (geplant), "Zeig mir den ganzen Text der Infoseite" (geprüft) oder "Plan ist gut, leg los" (freigegeben), wenn eine Unterrichtseinheit vor Moodle-Schreibzugriffen geplant, geprüft oder freigegeben werden soll.
---

# coursepilot-plan

Lies zuerst `coursepilot_get_skill("coursepilot-core")` und
`coursepilot_get_skill("context-area")` für Werkzeuge, Schreibangebot und
Handaenderungs-Routine der Arbeitsdateien. Je nach Planungsschritt
zusätzlich: beim Aufbau oder der Vorschau des Implementierungsplans
`coursepilot_get_skill("implementation-plan-workflow")`, beim Planen eines
Quiz oder einer Fragensammlung `coursepilot_get_skill("quiz-and-question-bank")`,
geht es dabei um einen unbekannten Fragetyp zusätzlich
`coursepilot_get_skill("question-types")`, bei einer Aktivität ohne Feldkatalog (Buch,
Checkliste, Glossar) `coursepilot_get_skill("activity-types")`, beim Dokumentieren einer
Planungsentscheidung `coursepilot_get_skill("journal")`, bei einer gerade nicht
ausführbaren Bestandsänderung `coursepilot_get_skill("notepad")`.

`plan.md`, `status.md` und Vorlagen werden nur nach dem Schreibangebot
geschrieben (`coursepilot_write_context_file`), nie still.

Halte die Ein-Plan-Regel und die Planstrenge aus dem Kern ein.

Bei Fachabbildungen aus Lehrwerken oder dem Materialbestand sowie gemeinsam
zu betrachtenden Ausschnitten schon vor der Planvorschau
`coursepilot_get_skill("graphics")` lesen (Quellenkopf und Zusammensetzen).
