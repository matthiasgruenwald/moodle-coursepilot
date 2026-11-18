# ADR 0037 — Grundaufbau und Sitzungsbeginn des Kontextbereichs

Status: angenommen (Spec 0030). Übernommen aus Glossar, E2, E6, E7 und Skill `context` des
moocp-contextmanager. Löst die Dateiordnung aus Spec 0010/0016 (`status.md`, Statuswerte,
`journal-*.md`) für neue Schreibungen ab; diese Dateien bleiben als altes Layout lesbar.

## Entscheidung

1. **Grundaufbau.** Jede Ebene (Wurzel, Fachbereich, Schuljahr, Klasse, Einheit) hat ein
   `index.md`, das nur verweist. Gewohnheiten in `habits.md` auf der allgemeinsten passenden
   Ebene, Fachsprache in `glossary.md`, überraschende Entscheidungen je Datei in `decisions/`,
   der Zielzustand einer Einheit in `plan.md` (über 100 Zeilen: `plan/`) mit Abschnittszuständen
   `draft`/`approved`/`implemented`, die Geschichte in `journal/JJJJ-MM-TT.md`. Coursepilots
   Lerndateien (`question-types/`, `activity-types/`, `templates.md`, `notepad.md`) bleiben an
   der Wurzel. Den Aufbau beschreibt der Skill-Korpus.
2. **Sitzungsbeginn.** `coursepilot_start_context` (lesend, ohne Parameter) antwortet auch
   ohne Ort: Lage oder Kontextlücke mit Ursache und Ortswahl-Link, offene Ausstände
   (ADR 0023), Altbestand-Hinweis, Konfliktkopien, Wurzel-`index.md` (fehlt sie: `CONTEXT.md`
   als altes Layout) und Wurzel-`habits.md`. Dateiinhalte sind als Daten gekennzeichnet (K3).
3. **Inhaltsverzeichnis.** Dateien über 100 Zeilen liefert `read_context_file` als Gliederung
   aus `##`/`###` mit Zeilenbereichen; `section` liefert einen Abschnitt, `whole: true` die
   ganze Datei. `contenthash` gilt immer für die ganze Datei; ersetzen setzt ein ganzes Lesen
   voraus (Skill).
4. **Konfliktkopien** werden am Namen erkannt (Syncthing, Nextcloud, Dropbox, Seafile) und mit
   dem Original gemeldet, nie verändert: je Ebene in `list_context_files`, im ganzen Bereich
   beim Sitzungsbeginn, begrenzt auf 500 Ordner, 10 Ebenen und 8 Sekunden mit Angabe, was
   ungeprüft blieb.

## Considered Options

- Sitzungsbeginn weiter über `list_skills`-Hinweise: Der Handshake bleibt, liefert aber keinen
  Kontextinhalt; ein eigener lesender Aufruf hält beides getrennt.
- Lange Dateien kürzen statt gliedern: verliert Inhalt ohne Weg zurück.
- Statusdatei behalten: Zustand je Abschnitt im Plan macht `status.md` überflüssig und
  vermeidet zwei Stellen für denselben Stand.

## Consequences

- Glossarbegriffe Statusbericht, Statuswerte, Ein-Plan-Regel und Status-gesteuerte
  Planfreigabe gelten nur noch für das alte Layout.
- Der Wegweiser `CONTEXT-LINK.md` des contextmanager entfällt (kein lokaler Ordner); die
  Zuordnung Kurs → Projektordner steht im `index.md` des Projektordners.
