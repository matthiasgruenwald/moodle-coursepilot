# Main-Gate-Bootstrap: Abnahmenachweis für #715

Stand 15.11.2026. Nachweis zu
[#715](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/715) für den Release-PR
[#765](https://github.com/matthiasgruenwald/moodle-coursepilot/pull/765) (`release/2.1.4-beta` → `main`).
Merge, Tag und Deploy bleiben beim Maintainer.

## Kandidat

- Release-Branch `release/2.1.4-beta` = `dev@48eb598c4ad726039ff82842f203dab432f118e3` plus Release-Commit
  (Version `2026111500` / `2.1.4-beta`, Release Notes, Versionsstellen, dieser Nachweis).
- Zielstand: `main@2b534e52bd04f928cb1371870f6a70962d6b297e` (2.1.3-beta), ohne `scripts/gate/`.
- Der Head-SHA des Kandidaten steht nicht in dieser Datei (sie ist Teil des Commits). Verbindlich ist
  der CI-Lauf am Head von #765; Head-SHA und Messwerte stehen dort im PR-Kommentar zur Abnahme.

## Messung

Quelle: CI-Push-Lauf [43696664210](https://github.com/matthiasgruenwald/moodle-coursepilot/actions/runs/43696664210)
auf `dev@3855ac3fbdb8dc5324243bea0f545c179f3b42b2`, Artefakt `gate-trend-MOODLE_501_STABLE-teil0-<SHA>`,
`full.exit` = 0. Der Plugin-Quellbaum des Kandidaten unterscheidet sich davon nur in der Zeile
`$plugin->release` (`version.php` ist ausgeschlossen); `48eb598c` ändert nur Gate-Skript, Tests,
Workflow und Doku. Der Lauf am Head von #765 misst den Kandidaten selbst erneut.

| Wert | Messung |
|---|---|
| Werkzeuge | Moodle 5.1.9 (Build 20261112), PHP 8.4.27, PCOV 1.0.12, MariaDB 11.4, PHPUnit 11.5.55, PHPStan 2.3.1 (Level 6), deptrac 3.0.0, Infection 0.29.14 |
| Suite | 3487 Tests, 165288 Assertions, 20 Skips, 12 PHPUnit-Deprecations, Exit 0 |
| Coverage | **99,11 % (14074/14200)**, 187 Dateien, 2 unter 50 %, 4 unter 90 % |
| CRAP | 1228 Methoden, 7 über 8, 0 über 30 |
| Statik | moodle-cs, phpdoc, savepoints, Mustache, ESLint, PHPStan, PHPStan-Gründe, Covers, Kommentarsprache, externe Funktionen, deptrac: je 0 Befunde |
| Mutation (Trend) | 13 Mutanten, 0 entkommen |
| Übrige Matrix | 5.1/PostgreSQL, 5.2 und 5.3 je MariaDB und PostgreSQL, drei Artefakt-Installationen: grün |

Ausschlussumfang: unverändert die 24 begründeten Einträge in `scripts/gate/baseline.json`
(Sprachdateien, vier `db/`-Datentabellen, `version.php`, 18 delegierende Einstiegsskripte nach #668).
Upgrade, Upgrade-Helfer und `lib.php` bleiben im Nenner.

## Baselines

- **Coverage:** `baseline.json` bleibt bei 13912/14037 = 99,11 % (auf `dev` gemessen und fortgeschrieben).
  Der Kandidat liegt mit 14074/14200 darüber. Keine Anhebung, keine Absenkung.
  - `ratchet-coverage` blockiert nur, wenn der Messwert unter der Baseline **und** unter 95 % liegt
    (`scripts/gate/ratchet.js`, `COVERAGE_FLOOR`; ADR 0033). Mit jeder Baseline ≥ 95 % ist die wirksame
    Grenze also 95 %. Der Wert 99,11 % nagelt nichts fest.
  - `ratchet-baseline-lowered` verbietet nur, die Zahl in der Datei gegenüber dem Zielbranch zu senken.
    Ein niedrigerer Wert auf `main` brächte keine Freiheit, aber abweichende Dateien auf `main` und `dev`.
- **PHPStan:** 31 Einträge (22 `variable.undefined`, 5 `class.notFound`, 4 `method.alreadyNarrowedType`),
  jeder mit `# reason:`, `# evidence:` und `# resolution: permanent`; die Prüfung `phpstan-reasons`
  kontrolliert Zuordnung und Quellbelege bei jedem Lauf (0 Befunde). Die drei `return.missing`-Blocker
  aus der [Vorbereitung](667-main-bootstrap-vorbereitung.md) sind im Kandidaten behoben (PHPStan 0 Befunde).
- **deptrac:** Baseline leer (`skip_violations: {}`), 0 Verstöße, keine unzugeordnete Klasse.

## Vorgehen

1. `GATE_BOOTSTRAP_BASE` ([#778](https://github.com/matthiasgruenwald/moodle-coursepilot/pull/778)):
   Der Workflow nennt fest den Main-Commit `2b534e52…`. Nur wenn der Vergleichsstand genau dieser Commit
   ist und dort alle drei Baselines fehlen, gelten die Baselines des Kandidaten als Erstbestand
   (`gate-bootstrap:` im Log). Sonst bleibt „Zielbranch-Baseline fehlt“ ein Fehler. Siehe
   [Gate-Doku](../gate.md#ratsche).
2. Geänderte Zeilen prüft der Bootstrap ab `armedAt` (`67c2026a`, 10.10.2026) statt ab `main`.
   Grund: `main` enthält das einmalige Style-Aufräumen vor dem Scharfschalten nicht. Alles danach hat
   die Ratsche auf `dev` PR für PR geprüft.
3. Simulation vor dem Rollout: `gate ratchet` mit dem Clover des Messlaufs, `GATE_BASE_REF=origin/main`
   und der Variable: 0 Verstöße. Ohne Schritt 2: sechs Altbefunde, siehe unten.
4. Nach Freigabe: Merge von #765 (Merge-Commit), Tag `v2.1.4-beta`, manueller CI-Lauf mit
   `target_branch=main` als Main-Abnahme (vergleicht normal gegen die dann vorhandenen Baselines).
5. Danach `GATE_BOOTSTRAP_BASE` aus dem Workflow entfernen (über `dev`) und #715 schließen.

## Akzeptierter Altbestand

Nur durch die Style-Commits vor `armedAt` berührt, fachlich unverändert gegenüber 2.1.3-beta:

| Stelle | Wert |
|---|---|
| `classes/activity_file_supplement.php` | 13,3 % (6/45); `apply` CRAP 20, `validate` CRAP 17,7 |
| `classes/activity_files/lightboxgallery.php` | 0 % (0/6) |
| `classes/admin/personaldatahosts_setting.php` | 66,7 % (6/9) |
| `classes/instance_check.php` | 87,5 % (21/24) |
| `classes/activity_backup.php::run_restore` | CRAP 15 |
| `classes/external/get_question.php::execute` | CRAP 11 |

Weitere Methoden über CRAP 8 im Bestand: `xml_activity_creator::create` (13),
`external/update_question_category::execute` (13), `history/version_writer::add_grade_fields` (10).
Sie blockieren wie auf `dev`, sobald jemand sie ändert.

## Version und Upgrade

`version.php` = `2026111500`, höchster Schritt in `db/upgrade.php` = `2026111500` (leert
`local_coursepilot_context_mark`), einziger neuer Schritt seit `main` (`2026101801`). Kein Tag
`v2.1.4-beta`, Spike und Devstack stehen auf `2026101801`: Die Nummer wurde nicht mit anderem Inhalt
ausgeliefert.

## Restrisiken

- Der Push-Lauf auf `main` direkt nach dem Merge mutiert als Trend den gesamten Unterschied
  2.1.3 → 2.1.4 in vier Teilen (Zeitgrenze 360 Minuten je Teil). Befunde blockieren nicht; ein
  Zeitüberlauf oder vorab übersprungene Mutanten färben den Lauf rot. Maßgeblich für die Abnahme ist
  der manuelle Lauf aus Schritt 4.
- Kommt vor dem Merge ein Hotfix auf `main`, passt der feste SHA nicht mehr und #765 wird wieder rot;
  dann SHA anpassen und neu messen.
- Der Bootstrap vergleicht PHPStan- und deptrac-Baseline nicht gegen einen Vorstand (es gibt keinen).
  Abgenommen ist ihr Inhalt oben, nicht ein Wachstum.
- Die Messquelle ist `dev@3855ac3f`; ändert sich der Kandidat nach dem CI-Lauf an #765, gilt nur ein
  neuer Lauf.
