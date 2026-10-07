# ADR 0029 — Qualitäts-Gate als Ratsche statt fester Schwellen

Status: angenommen (05.10.2026)

## Context

Ab 2.1 arbeiten mehrere Menschen und Agenten am Server-MCP. Regeln in `CLAUDE.md` und
`AGENTS.md` behandeln Agenten als Richtlinie, nicht als Pflicht. Qualität muss deshalb ein
deterministisches Gate erzwingen, an dem der Agent so lange arbeitet, bis es grün ist.

Die Baseline (dev @ 05fb815, CI-Clover) liegt bei 82,3 % Line-Coverage (strikt nach
`#[CoversClass]`), 88 von 1088 Methoden haben einen CRAP-Score über 8. Feste Schwellen
(„gesamt ≥ 95 %“, „jede Methode CRAP ≤ 8“) würden ab dem ersten Tag jede Änderung blockieren
und müssten mit wachsendem Code ohnehin nachgezogen werden.

## Decision

Das Gate ist eine **Ratsche mit Prüfregel für Geändertes**:

- Gesamt-Coverage darf nie sinken.
- Jede geänderte Datei hat mindestens 90 % Line-Coverage.
- Jede geänderte Methode hat einen CRAP-Score von höchstens 8.
- Geänderte Zeilen haben keine überlebenden Mutanten, außer sie stehen begründet in der
  versionierten Ignore-Liste (äquivalente Mutanten).
- Baselines von Werkzeugen (PHPStan) dürfen nur schrumpfen.

Gesamtwerte (Coverage, Methoden über CRAP 8, MSI) sind Trend, kein Gate. Das Zielbild ist
95 % Coverage; ungedeckter Rest wird begründet berichtet. Coverage zählt strikt nach
`#[CoversClass]`, gemessen auf der Moodle-Mindestversion von `dev` (ADR 0027).

Das Gate läuft zweistufig lokal (pre-commit schnell, pre-push voll) und verbindlich in CI.
Die Git-Hooks liegen versioniert im Repo und wirken für Claude, Codex und Menschen gleich.

## Considered Options

- **Feste Schwellen ab sofort** (Bob-Martin-Stil): verworfen, blockiert den Bestand statt
  ihn beim Anfassen zu verbessern.
- **Nur Report, kein Gate**: verworfen, Agenten ignorieren nicht erzwungene Regeln.
- **Mutation nur als Report**: verworfen, schwache Tests fallen sonst nicht auf.

## Consequences

- Bestand über der Regel wird fällig, sobald jemand ihn ändert (Boy-Scout-Rule). Wer eine
  Methode mit hohem CRAP anfasst, zerlegt oder testet sie im selben Ticket.
- Größeres Aufräumen geschieht nur auf Ereignis: Hotspot, Rule of Three oder eine Datei
  oben im Gate-Fehlschlagsreport.
- Tests werden nie automatisch gelöscht; Prune-Kandidaten aus der Mutationsanalyse sind nur
  ein Report zur Freigabe.
