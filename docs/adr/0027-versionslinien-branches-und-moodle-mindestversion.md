# ADR 0027 — Versionslinien, Branches und Moodle-Mindestversion

Status: angenommen (01.10.2026)

## Context

Der Server-MCP hat den Praxistest bestanden: Mit ihm wurden echte Kurse gebaut und
umgebaut. Es blieben nur kleinere Werkzeuglücken in Randfällen. Der lokale stdio-Weg
(Coursepilot 1.x) wird nicht mehr gebraucht; der Schnitt aus ADR 0024 fällt damit.

Moodle 5.0 verlässt am 05.10.2026 den Support. Die CI weist 5.0 und 5.1 nach.
Moodle 5.2 ist stabil, 5.3 existiert nur als Entwicklungsstand (`main`, 5.3dev).

## Decision

**Release und Maturity.** `2.0.0` erscheint als `2.0.0-beta` mit `MATURITY_BETA`.
Stable ist eine eigene, spätere Entscheidung.

**Moodle-Versionen je Linie.**

| Linie | `requires` | Zusage |
|---|---|---|
| 2.0.x | Moodle 5.0 | 5.0 und 5.1 (CI-Nachweis). 5.0 bleibt zugesagt, weil sie geprüft ist. |
| 2.1+ | Moodle 5.1 | ab 5.1. Neuere Versionen nur, wenn CI und Testinstanz sie nachweisen. |

Neue größere Funktionen kommen erst ab 2.1. Sie dürfen Moodle-5.1-APIs voraussetzen.
2.0.x erhält nur Hotfixes.

**Branches.**

- `main` trägt den veröffentlichten Stand (2.0.x). Auf `main` landen nur Hotfixes, jeweils
  mit Tag.
- `dev` ist der Entwicklungszweig für 2.1. Entwickelt und geprüft wird gegen die
  Spike-Instanz.
- Forschungs- und Prototypzweige zweigen von `dev` ab und werden dorthin zurückgeführt.
  Abgeschlossene Zweige werden gelöscht; ihr Inhalt bleibt über die Commits erreichbar.

**Instanzen.** Die Spike-Instanz läuft auf Moodle 5.1, also auf der Mindestversion von 2.1.
Die vier Devstack-Instanzen werden um eine Version angehoben: 5.0 → 5.1 (öffentlich, für
Kolleginnen und Kollegen), 5.1 → 5.2, 5.2-mariadb → 5.3dev. 5.2-pgsql bleibt auf 5.2.
Alle vier tragen den Server-MCP, das Altplugin ist entfernt.

## Consequences

- Hotfixes auf `main` müssen nach `dev` zurückgeführt werden.
- Die CI läuft auf `main` und `dev`. Für 2.1 kommt eine 5.2-Zeile hinzu, die 5.0-Zeile
  entfällt. Erst danach steigt `requires` auf 5.1 (`2025100600`). Eine reine
  Metadatenänderung ist keine Kompatibilitätsabnahme.
- Der Mirror-Sync wird nicht mehr automatisch angestoßen, solange er noch den Altstand baut.
  Mit der Marketplace-Einreichung (#192) wird er auf die native Linie umgestellt.
- Der Altstand (`legacy/`, `moodle-mcp*.js`, lokale Installer) wird auf `dev` entfernt.
- Ändert ADR 0024 an zwei Stellen: Die Maturity ist jetzt Beta statt Alpha, und der Schnitt
  ist vollzogen.
