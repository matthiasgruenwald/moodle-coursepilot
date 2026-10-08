# ADR 0027 — Versionslinien, Branches und Moodle-Mindestversion

Status: angenommen (01.10.2026), aktualisiert für 2.1.1-beta (08.10.2026)

## Context

Der Server-MCP hat den Praxistest bestanden: Mit ihm wurden echte Kurse gebaut und
umgebaut. Es blieben nur kleinere Werkzeuglücken in Randfällen. Der lokale stdio-Weg
(Coursepilot 1.x) wird nicht mehr gebraucht; der Schnitt aus ADR 0024 fällt damit.

Ausgangslage am 01.10.2026: Die bisherige CI prüfte Moodle 5.0 und 5.1.
Für 2.1.1-beta ist die vollständige Pflichtmatrix für Moodle 5.1, 5.2 und 5.3
grün (Issue #679). Die Devstack-Kerne wurden auf 5.1.8, 5.2.4 und 5.3.0
aktualisiert; historische Instanznamen bleiben bestehen.

## Decision

**Release und Maturity.** `2.0.0` erscheint als `2.0.0-beta` mit `MATURITY_BETA`.
Stable ist eine eigene, spätere Entscheidung.

**Moodle-Versionen je Linie.**

| Linie | `requires` | Zusage |
|---|---|---|
| 2.0.x | Moodle 5.0 | 5.0 und 5.1 (CI-Nachweis). 5.0 bleibt zugesagt, weil sie geprüft ist. |
| 2.1.1-beta | Moodle 5.1 | Moodle 5.1, 5.2 und 5.3; geprüfte PHP-/Datenbankkombinationen gemäß [Pflichtmatrix](../ci-native-server-mcp.md). Weitere Versionen brauchen CI- und Testinstanz-Nachweis. |

Neue größere Funktionen kommen erst ab 2.1. Sie dürfen Moodle-5.1-APIs voraussetzen.
2.0.x erhält nur Hotfixes.

**Branches.**

- `main` trägt den veröffentlichten Stand (2.1.x). Release-PRs aus `dev` und
  Hotfixes werden dort nach grüner Pflicht-CI mit Tag veröffentlicht.
- `dev` ist der Entwicklungszweig für 2.1. Entwickelt und geprüft wird gegen die
  Spike-Instanz.
- Forschungs- und Prototypzweige zweigen von `dev` ab und werden dorthin zurückgeführt.
  Abgeschlossene Zweige werden gelöscht; ihr Inhalt bleibt über die Commits erreichbar.

**Instanzen.** Die Spike-Instanz läuft auf Moodle 5.1, also auf der Mindestversion von 2.1.
Die vier Devstack-Instanzen laufen auf Moodle 5.1 (öffentlich, MariaDB),
5.2 (MariaDB), 5.3 (MariaDB) und 5.2 (PostgreSQL).
Die historischen Namen `5.0-mariadb`, `5.1-mariadb`, `5.2-mariadb` und
`5.2-pgsql` sind keine Aussage über die aktuelle Moodle-Version.
Alle vier tragen den Server-MCP, das Altplugin ist entfernt.

## Consequences

- Hotfixes auf `main` müssen nach `dev` zurückgeführt werden.
- Die CI läuft auf `main` und `dev`: fünf PHPUnit-Kombinationen für Moodle
  5.1/5.2/5.3 und frische Release-ZIP-Installationen auf allen drei Linien.
  `requires` ist 5.1 (`2025100600`). Eine reine Metadatenänderung ist keine
  Kompatibilitätsabnahme.
- Der Mirror-Sync wird nicht mehr automatisch angestoßen, solange er noch den Altstand baut.
  Mit der Marketplace-Einreichung (#192) wird er auf die native Linie umgestellt.
- Der Altstand (`legacy/`, `moodle-mcp*.js`, lokale Installer) wird auf `dev` entfernt.
- Ändert ADR 0024 an zwei Stellen: Die Maturity ist jetzt Beta statt Alpha, und der Schnitt
  ist vollzogen.
