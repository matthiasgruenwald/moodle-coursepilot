---
name: abschlussverfolgung
description: Lies diese Datei, wenn die Lehrkraft Abschlussverfolgung (Completion/Restriction-Ketten zwischen Aktivitaeten) wuenscht.
---

# Referenz: Abschlussverfolgung (optionales Feature)

Lies diese Datei, wenn die Lehrkraft Abschlussverfolgung (Completion/
Restriction-Ketten zwischen Aktivitaeten) wuenscht.

Wenn der Benutzer Abschlussverfolgung wuenscht, den Benutzer zuerst fragen:

> "Soll ich die Abschlussverfolgung aktivieren? Dann muessen SuS jede Aufgabe
> einreichen bevor die naechste freigeschaltet wird."

Falls ja: Den folgenden Workflow NACH dem Erstellen aller Aktivitaeten ausfuehren.

## Welche Aktivitaeten bekommen Abschlussverfolgung?

| Aktivitaetstyp (`modname`) | Completion-Typ | Erlaeuterung |
|---|---|---|
| `assign` | completion=2, completionsubmit=1 | Automatisch bei Einreichung |
| `choice` | completion=2, completionsubmit=1 | Automatisch bei abgegebener Abstimmung |
| `page` | completion=1 | Manuell (SuS klickt "Abgeschlossen") |
| `url` | – | Keine Verfolgung (Links ueberspringen) |
| `label` | – | Keine Verfolgung (Header ueberspringen) |

`completionsubmit` gibt es nur bei `assign` und `choice` – bei jeder anderen
Aktivitaetsart weist `coursepilot_set_completion` das Feld ab.

`completion`/`completionsubmit` (und die anderen `completion*`-Felder) laufen
ausschliesslich ueber `coursepilot_set_completion` – `coursepilot_create_module` und
`coursepilot_update_module_settings` sperren diese Felder bewusst, weil Moodle sie ohne
`completionunlocked` still verwirft und mit `completionunlocked` die
Abschlussdaten der Lernenden loeschen wuerde.

## Pflicht-Reihenfolge beim Einrichten

IMMER in dieser Reihenfolge vorgehen – niemals umgekehrt:

```
1. Alle Aktivitaeten per coursepilot_create_module erstellen
   → cmids aus den Antworten notieren

2. Fuer jede zu verfolgende Aktivitaet coursepilot_set_completion aufrufen
   → Erst wenn ALLE set_completion-Calls erfolgreich sind:

3. Fuer jede abhaengige Aktivitaet coursepilot_set_restriction aufrufen
   → Bedingung "abschluss" auf die VORHERIGE Aktivitaet zeigen lassen
```

## Beispiel-Workflow fuer 3 aufeinanderfolgende Aufgaben

```
// Schritt 1: Aktivitaeten anlegen, cmids merken
cmid_A = coursepilot_create_module(courseid, sectionnum, modname="assign",
  felder_json='{"name": "Phase 1 Arbeitsblatt", ...}')    → z.B. 1001
cmid_B = coursepilot_create_module(courseid, sectionnum, modname="assign",
  felder_json='{"name": "Phase 2 Aufgabe", ...}')          → z.B. 1002
cmid_C = coursepilot_create_module(courseid, sectionnum, modname="assign",
  felder_json='{"name": "Phase 3 Implementierung", ...}')  → z.B. 1003

// Schritt 2: Abschluss aktivieren (alle drei)
coursepilot_set_completion(cmid=1001, felder_json='{"completion": 2, "completionsubmit": 1}')
coursepilot_set_completion(cmid=1002, felder_json='{"completion": 2, "completionsubmit": 1}')
coursepilot_set_completion(cmid=1003, felder_json='{"completion": 2, "completionsubmit": 1}')

// Schritt 3: Voraussetzungen setzen (Kette)
// B erst sichtbar wenn A abgeschlossen
coursepilot_set_restriction(cmid=1002,
  bedingungen_json='[{"typ": "abschluss", "aktivitaet_cmid": 1001, "status": "abgeschlossen"}]')
// C erst sichtbar wenn B abgeschlossen
coursepilot_set_restriction(cmid=1003,
  bedingungen_json='[{"typ": "abschluss", "aktivitaet_cmid": 1002, "status": "abgeschlossen"}]')
```

Meldet `coursepilot_set_completion` beim ersten Aufruf ein Datenverlustrisiko
(vorhandene Abschlussdaten von Lernenden), NICHT einfach erneut ohne Ruecksprache
mit `bestaetigt: true` wiederholen – siehe `coursepilot_get_skill("mcp-tools")`.

## Textseiten in die Kette einbeziehen

Wenn auch Textseiten (Informationsblaetter) abgeschlossen sein muessen:

```
cmid_info = coursepilot_create_module(courseid, sectionnum, modname="page",
  felder_json='{"name": "Informationsblatt", ...}')   → z.B. 1000
cmid_task = coursepilot_create_module(courseid, sectionnum, modname="assign",
  felder_json='{"name": "Aufgabe", ...}')              → z.B. 1001

// Informationsblatt: manueller Abschluss
coursepilot_set_completion(cmid=1000, felder_json='{"completion": 1}')

// Aufgabe: automatisch bei Einreichung
coursepilot_set_completion(cmid=1001, felder_json='{"completion": 2, "completionsubmit": 1}')

// Aufgabe erst freischalten wenn Informationsblatt gelesen (manuell abgeschlossen)
coursepilot_set_restriction(cmid=1001,
  bedingungen_json='[{"typ": "abschluss", "aktivitaet_cmid": 1000, "status": "abgeschlossen"}]')
```

## Labels und URLs NICHT in die Kette einbeziehen

Phasen-Header (Labels) und externe Links (URLs) bekommen KEINE Abschlussverfolgung
und KEINE Voraussetzungen. Sie bleiben immer sichtbar.

Die Kette bezieht sich nur auf Aufgaben (assign) und ggf. Textseiten (page).

## Fehlervermeidung

- NIEMALS `coursepilot_set_restriction` aufrufen bevor `coursepilot_set_completion` auf
  der Voraussetzungs-Aktivitaet gesetzt wurde – sonst funktioniert die
  Freischaltung nicht korrekt
- NIEMALS eine Aktivitaet als Voraussetzung eintragen die selbst
  keine Abschlussverfolgung hat (completion=0)
- Bei mehreren Voraussetzungen (mehrere Eintraege in `bedingungen_json`) muessen
  ALLE genannten `aktivitaet_cmid`-Werte zuvor mit `coursepilot_set_completion`
  konfiguriert worden sein
- `name` ist fuer `label` gesperrt – siehe `coursepilot_get_skill("technische-hinweise")`
