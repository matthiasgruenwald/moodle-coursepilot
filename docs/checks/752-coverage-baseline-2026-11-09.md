# #752 – Voller Gate-Lauf und Coverage-Baseline (9.11.2026)

## Messstand

| | |
|---|---|
| HEAD | `408f748d06e35cd50f0b7219704aa3010b7d6eb0` plus ungestagte Änderungen: Test `test_grant_lock_is_bound_to_its_grant_id`, Ignore `9ddd9e50` entfernt (siehe `674-abschluss-2026-11-09.md`, Phase 2) |
| Plugin-Quelle ohne `tests/` | sha256-Baumsumme `cdf38510…9caa`, vor und nach dem Lauf identisch (`source-tree.sha256`) |
| Testbaum | sha256-Baumsumme `ab992fb4…739c`, vor und nach dem Lauf identisch (`tests-tree.sha256`) |
| Befehl | `npm run gate -- full` als systemd-Einheit `kurspilot-752-full` auf der Haupt-Instanz `/opt/kurspilot-gate`; vorab keine fremden PHPUnit-/Infection-Container, keine Sperren, 6,4 GB verfügbar. `GATE_MUTATION` nicht gesetzt (Mutation läuft in CI) |
| Lauf | 20:01:21 – 20:23:56 (22:35 min), Exit **0** (`full.exit`) |

## Messwerte

| | |
|---|---|
| Node-Vertragstests | 211 Tests, 211 bestanden, 0 Fehler, 0 Skips, 12,6 s |
| PHPUnit | 3303 Tests (3302 + 1 neuer), **164291 Assertions** (+2), Dauer 21:04,7 min, 244 MB |
| Skips | 20 (`activity_file_supplement_test` 9, `update_module_settings_test` 2, `update_quiz_settings_test` 2, `xml_activity_creator_674_final_contract_test` 2, je 1 in `activity_type_templates_test`, `create_activity_from_xml_test`, `crop_material_file_test`, `webdav_storage_port_test`, `xml_activity_creator_contract_test`; Gründe stehen nicht in der Junit-Datei) |
| PHPUnit Deprecations | 12 (nicht zugeordnet, Lauf ohne `--do-not-fail-on-deprecation`) |
| **Coverage** | **99,11 %** (13912/14037 Zeilen, 185 Dateien, 1212 Methoden) |
| Statik | `moodle_cs_errors=0 moodle_cs_warnings=0 phpdoc=0 savepoints=0 mustache=0 eslint=0 phpstan=0 phpstan_reasons=0 covers=0 english=0 external=0 deptrac=0` |
| Ratsche | Basis `f621600b5450`, coverage 99,11 % ≥ Baseline 86,26 % (alt), **0 Verstöße** |
| Mutation | übersprungen (läuft in CI), s. u. `verify` |

Ziel 95 %: **erreicht**, tatsächlich 99,11 %.

## Dateiverteilung (aus `gate-report.json`)

| Bereich | Dateien |
|---|---:|
| 100 % | 153 |
| 95 – <100 % | 24 |
| 90 – <95 % | 4 |
| 50 – <90 % | 2 |
| <50 % | 2 |
| gesamt | 185 |

Dateien unter 95 %: `activity_file_supplement.php` 13,3 % (6/45), `activity_files/lightboxgallery.php` 0 % (0/6), `admin/personaldatahosts_setting.php` 66,7 % (6/9), `instance_check.php` 87,5 % (21/24), `course_module_placement.php` 93,6 % (44/47), `external/get_question_categories.php` 93,9 % (31/33), `admin/remoteaccesscohorts_setting.php` 93,8 % (30/32), `question_xml.php` 94,2 % (65/69). Vier Dateien unter 90 % erscheinen als Gate-Hinweis `coverage-file` (Ratsche blockiert nicht, `violations=0`).

## CRAP (Methoden über 8)

7 Methoden über 8, 0 über 30:

| Methode | CRAP | Komplexität |
|---|---:|---:|
| `activity_file_supplement::apply` (:80) | 20 | 4 |
| `activity_file_supplement::validate` (:50) | 17,7 | 6 |
| `activity_backup::run_restore` (:275) | 15 | 15 |
| `xml_activity_creator::create` (:72) | 13 | 13 |
| `external/update_question_category::execute` (:75) | 13 | 13 |
| `external/get_question::execute` (:73) | 11 | 11 |
| `history/version_writer::add_grade_fields` (:242) | 10 | 10 |

## Baseline

`npm run gate -- baseline /tmp/spec0029-752-final/clover.xml` (Clover des obigen Laufs, 20:22:46, sha256 `bdc3091d…38b852b0`), Exit 0, `summary: baseline coverage=99.11% lines=13912/14037`.

| | alt | neu |
|---|---|---|
| `coverage.covered` | 11990 | 13912 |
| `coverage.statements` | 13900 | 14037 |
| Anteil | 86,26 % | 99,11 % |
| `armedAt` | `67c2026a…c906` | unverändert |
| `excluded` | – | unverändert |

Nur `scripts/gate/baseline.json` (2 Zeilen) geändert.

## `verify classes/connection.php` (s1, ohne Filter)

Coverage des `gate full` (`reports/infection-coverage/` per `cp -a` nach s1, Zeitstempel und Inhalt unverändert; Junit 20:22:46,705, neuer als jede Quell- und Testdatei, ohne `ALLOW_STALE`, ohne `touch`); s1-Plugin per `sync-plugin.sh` auf den Hauptstand gebracht (`diff -r` leer), nur Lesezugriff auf die Haupt-Instanz. 1 Thread. Einheit `kurspilot-752-verify`, 20:25:09 – 21:01:31.

`total=351 killed=327 escaped=23 uncovered=0 open=0`, Exit **0**; `timeouted 1` (zählt als erkannt), `errored 0`, `syntaxErrors 0`, `skipped 0`, `ignored 0` (Infection-seitig), Mutation-MSI laut Infection 93,45 %. Mutant `9ddd9e50` (`connection:615 ArrayItemRemoval`): **killed** mit `Failed asserting …` aus `test_grant_lock_is_bound_to_its_grant_id`. Die 23 Überlebenden sind durch bestehende Ignores gedeckt (davon `connection:241` zweimal, siehe 674-Bericht).

## Belege

- `/tmp/spec0029-752-final/` (Kopie, 0700, ohne Dependencies: `tmp/spec0029-final-evidence/752-final/`, Phase-1-Rohdaten unter `…/674-sonnet/`): `full.out/.exit/.start/.end`, `gate-fast.out`, `clover.xml`, `gate-report.json`, `gate-static.json`, `junit.xml`, `baseline-before.json`, `baseline-after.json`, `verify.out/.err/.exit/.start/.end`, `infection-connection.json`, `source-tree.sha256`, `tests-tree.sha256`, `SHA256SUMS`, `s1-old-infection-coverage/` (alte s1-Coverage).
- Dateihashes nach der Änderung: `connection.php` `71b39b17…14f0` (unverändert), neue Testdatei `a78ce2a9…0c79`, `mutation-ignore.json` `6993b5ce…d76d4`, `baseline.json` `5ef6996f…0677`.

## Grenzen

- `version_history.php` und `workbench_ticket.php` nicht neu verifiziert (Quellen unverändert, Phase-1-Werte aus dem 674-Bericht).
- Kein neuer Gesamt-MSI; Mutationsprüfung des Diffs läuft in CI.
- Die 12 Deprecations und 20 Skips sind unverändert gegenüber der Messung vor der Testaddition nicht verglichen; nicht auf Ursache untersucht.

## Dauerhaft versionierte Identitäten

Der [JSON-Nachweis](evidence/spec-0029-2026-11-09.json) enthält die vollständigen
SHA-256-Werte der drei Produktionsdateien, des endgültigen Teststands, von
Baseline und Ignore-Datei sowie des Clovers und der Rohberichte. Die Kürzungen
in diesem Lesetext dienen nur der Darstellung. Die Rohdaten bleiben zusätzlich
unter `tmp/spec0029-final-evidence/` im Hauptcheckout erhalten.
