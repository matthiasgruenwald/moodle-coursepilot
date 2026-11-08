# Mutations-Volllauf #673 (Wiederholung, 7.–8.11.2026)

Zweiter Lauf über den gesamten Produktionscode, maßgeblich für den Abschluss von Spec 0029 (Issue #673). Muster und Methode: [Bericht vom 19.10.2026](673-mutationsvolllauf-2026-10-19.md).

## Ergebnis

| Messwert | Wert |
|---|---:|
| Mutanten gesamt | 12.991 |
| Getötet | 11.987 |
| Überlebt | 898: 874 mit exaktem begründetem Ignore (`scripts/gate/mutation-ignore.json`), **24 offen** |
| Nicht gedeckt | 76 |
| Fehler (Infection `errored`) | 30 |
| Getötet ohne zugeordneten Töter | 150 (siehe unten, sind in den 11.987 enthalten) |
| **MSI (Trendwert, keine Schwelle)** | **92,27 %** (nur gedeckte Mutanten: 92,81 %) |

Gegenüber dem 19.10. (59,26 %, 4.591 offene Überlebende) sind die Überlebenden zu 97 % getötet oder mit Grund ignoriert. MSI hier = getötet / gesamt; Infection selbst zählt Fehler zusätzlich als erkannt (z. B. `classes-external-06`: 91,61 % statt 91,30 %). Die Zahl ist die konservative.

Die 24 offenen wurden nach dem Lauf in PR #744 getötet bzw. ignoriert (Stand `origin/dev` d8b68c9); das ist **nicht neu gemessen**, der MSI oben beschreibt den Lauf selbst. Berichte:

- [Überlebende Mutanten je Datei](673-mutationsvolllauf-2026-11-08-ueberlebende.md)
- [Prune-Bericht](673-mutationsvolllauf-2026-11-08-prune.md): Liste für eine menschliche Entscheidung, **kein Test wurde gelöscht**.
- Vorgänger (teilweise ungültig, siehe unten): [Überlebende 11-07](673-mutationsvolllauf-2026-11-07-ueberlebende.md), [Prune 11-07](673-mutationsvolllauf-2026-11-07-prune.md).

## Methode und Messquelle

- Wie am 19.10.: Infection 0.29.14, `--threads=1 --only-covering-test-cases --skip-initial-tests`, Gate-Container (Moodle 5.1.7+, PHP 8.4, MariaDB 11.4, PHPUnit 11.5), Abschnitte je Verzeichnis, mehrere isolierte Instanzen. Zahlen aus den kompakten Abschnitts-JSONs (`sections/*.compact.json`) und den Rohberichten.
- PR #741 (Tests, die Überlebende des ersten Volllaufs töten, plus geprüfte Ignores) ging dem Lauf voraus; PR #743 brachte danach die Werkzeuge: Der Läufer verweigert einen Volllauf auf veralteter Coverage (`mutation-full.js`), `verify` mutiert eine Einzeldatei, ein Watchdog beendet hängende Mutanten-Prozesse (`systemd-run`). PR #744 tötete bzw. ignorierte die 24 offenen Überlebenden.
- Stand der Quellen: Plugin-Baumdigest `e8596f23ba53cb869a9df93b5bba76a9c73fb0b29c1f579117b41210ecda6eeb` (`plugin-digest.txt`), Quell-SHA-256 je Datei in `sources.json`. Rohdaten liegen unter `/opt/mutation-674-out-2/` (nicht im Repo).

## Ungültiger Lauf vom 7.11. und Neumessung

Der erste Volllauf (2026-11-07: 1.061 Überlebende, 301 offen) lief auf Instanz `s1` mit **veralteter Coverage**. Mutanten wurden als „ungedeckt“ oder mit falschem `coveredBy` geführt. Zehn Abschnitte wurden deshalb am 8.11. neu gemessen und ersetzen die 7.11.-Werte: `classes-01`, `classes-06`, `classes-external-01`, `-02`, `-03`, `-05`, `-06`, `classes-history`, `classes-output`, `classes-webdav`. Die übrigen 17 der 27 Abschnitte blieben gültig. Die 11-07-Berichte bleiben als Verlauf im Repo, gelten aber nicht als Messergebnis.

## Prüfung der historisch unreviewten Ignores

Die neun „historischen“ Ignores in `write_target.php` und `move_module.php` (laut Bericht 2026-11-01 nicht Teil der Review-Bundles) wurden gegen die Quelle gelesen. Der Bericht nennt sie nicht einzeln; geprüft wurden alle 17 Einträge der beiden Dateien. **Ergebnis: alle beweisbar äquivalent, keine Begründung geändert, keine offen.**

| Datei:Zeile | Mutator | Prüfung |
|---|---|---|
| `move_module.php:85` | FalseValue | 4. Parameter von `get_coursemodule_from_id()` fügt nur `sectionnum` hinzu; `$cm` wird nur für id, course, name gelesen. |
| `move_module.php:116` (2) | CastInt | `move_to(int, int, ?int)`, Datei ohne `strict_types`: Zahlenstring wird zum gleichen int. |
| `move_module.php:126` | CastInt | `validate_parameters` mit `PARAM_INT` liefert schon int. |
| `write_target.php:146` | CastInt | In Core `modlib.php` wird `$moduleinfo->module` nur in Zeile 65 nach `$newcm->module` kopiert und per `insert_record` gespeichert (verifiziert im Gate-Moodle). |
| `write_target.php:163, 164, 213` | CastInt | `int`-Parameter und `: int`-Rückgabe, kein `strict_types`: Coercion identisch. |
| `write_target.php:230` | FalseValue | Wie `move_module.php:85`; gelesen werden course, instance, idnumber. |
| `write_target.php:231` | ArrayItemRemoval | `iteminstance` ist die eindeutige Assign-Instanz-ID; die Kursbedingung schließt nie eine Zeile aus. |
| `write_target.php:237` | GreaterThan | Fall `=== 1` wurde zwei Zeilen davor beendet; `> 1` und `>= 1` verhalten sich dort gleich. |
| `write_target.php:245, 246` (3) | ArrayItemRemoval/ArrayItem | Der Seed-Aufruf legt das Grade-Item nur an; `assign_grade_item_update()` setzt itemname, idnumber und finalen Typ danach vollständig. Der Grund bleibt stehen. |
| `write_target.php:299` | Continue_ | Nur `choice` deklariert `parallel_array_lengths`, mit genau einer Regel (grep über `classes/catalog`). |
| `write_target.php:484` | FalseValue | Verzeichniseinträge heißen `.`; Material-Dateinamen müssen `^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$` erfüllen. |
| `write_target.php:600` | Continue_ | `editor_content` hat in `page` und `assign` je genau einen Eintrag. |

Schwächster Punkt ist `write_target.php:245/246`: Die Äquivalenz stützt sich auf das Verhalten von `assign_grade_item_update()` in Core, nicht auf eine einzelne Zeile. Sie bleibt gebucht, weil der Seed-Aufruf selbst durch den Test „Grade-Item gelöscht“ gedeckt ist.

## Zeitüberschreitung in `update_question_category.php`

Der Mutant `ArrayItemRemoval` in `collect_subtree_ids()` (Zeile 219, `['parent' => $currentid]` fällt weg: die Schleife liest alle Kategorien nach, die Queue läuft nie leer) wurde von Infection als **Fehler (`errored`)** geführt, weder als Überlebender noch als Timeout (`timeOutCount` 0 in `classes-external-06`). Er ist damit **erkannt, nicht überlebt**. Ein Ignore ist weder nötig noch zulässig (kein Überlebender), ein zusätzlicher Test auch nicht. Er gehört zu den 30 Fehlern unten und ist nur als Prozessabbruch ohne zuordenbaren Töter sichtbar.

## Einordnung der Nebenzahlen

### 76 ungedeckt

| Datei | Zahl | Einordnung |
|---|---:|---|
| `activity_file_supplement.php` | 29 | Lightbox-Block; `mod_lightboxgallery` fehlt im Gate-Container. Umgebungsgrenze, bekannt (Bericht 2026-11-01). |
| `activity_files/lightboxgallery.php` | 9 | dasselbe |
| `xml_activity_creator.php` | 4 | dasselbe (Lightbox-Zweig) |
| `external/update_mc_question.php` | 10 | Fehlerpfad (Zeilen 603/604: `Throw_`, `Concat`): Arbeitsliste, kleiner Test |
| `external/get_question.php` | 6 | Fehlerpfad Zeilen 123/128; laut 11-01 nur Concurrency/Entropie: schwer testbar, Entscheidung offen |
| `activity_backup.php` 4, `question_import.php` 3, `external/export_activity_backup.php` 3, `history/version_writer.php` 2 | 12 | defensive `Throw_`-Pfade: Arbeitsliste |
| je 1: `course_module_placement`, `glossary_entry_writer`, `oauth_budget`, `private_files_storage_port`, `add_glossary_entries`, `crop_material_file` | 6 | Einzelfälle, Arbeitsliste |

Davon 42 Umgebung (Lightbox), 34 Arbeitsliste (überwiegend `Throw_`/Meldungstext).

### 150 getötet ohne zugeordneten Töter („nicht zugeordnet“)

Alle 150 sind PHPUnit-Läufe mit **grünen Tests und genau einer Warnung** (`OK, but there were issues`, 1 Test, 1 Warning). Infection wertet das als getötet; es ist kein Beweis, dass ein Test den Mutanten erkennt (wie die 174 im Lauf vom 19.10.). Verteilung: `question_import` 15, `location_selection` 12, `catalog/write_target` 10, `webdav_storage_port` 9, `activity_backup` 8, `private_files_storage_port` 8, `glossary_entry_writer` 6, danach 40 Dateien mit 1–4. Ursache der Warnung ungeklärt (ob sie auch im unmutierten Test auftritt, wurde nicht geprüft). **Arbeitsliste**: Warnungsquelle finden; ohne sie wäre der MSI um bis zu 1,15 Punkte niedriger (11.837 / 12.991 = 91,12 %).

### 30 Fehler

| Datei | Zahl | Einordnung |
|---|---:|---|
| `connection.php` | 25 | Prozess endet ohne PHPUnit-Ausgabe (23 leer, 1 nur Fortschrittspunkt, 1 mit Hinweis auf `phpunit/cli/init.php`). Mutanten in Token-/Lock-/Transaktionspfaden (z. B. `LogicalOr`, `PublicVisibility`, `NotIdentical`), vermutlich Hänger oder Fatal im Test. Abschnitt `classes-01` dauerte 11.525 s. **Arbeitsliste**: einzeln mit `verify` reproduzieren. |
| `xml_activity_creator.php` | 2 | `LogicalAnd`/`LogicalAndNegation` Zeile 205, Ausgabe bricht nach der Konfiguration ab: Prozessabbruch, Arbeitsliste. |
| `history/retention.php` | 2 | `LogicalAndNegation` Zeilen 147/151, dasselbe Bild. |
| `external/update_question_category.php` | 1 | Endlosschleifen-Mutant, siehe oben: erkannt, harmlos. |

Infection zählt Fehler als erkannt; ein Mutant, der den Prozess zerstört, wird von jeder Suite „erkannt“, ohne dass ein Test ihn benennt. Kein Handlungsbedarf für das Gate, nur die 25 `connection.php`-Fälle sind eine Reproduktion wert.

## Grenzen

1. MSI ist ein Trendwert ohne Schwelle; keine Gate-Konfiguration, keine Baseline gelockert.
2. Die Neumessung nach PR #744 (24 offene getötet/ignoriert) fehlt; kein neuer Volllauf.
3. Die Prune-Liste ist eine Obermenge (PHPUnit bricht beim ersten Fehlschlag ab).
4. Instanz-/Checkout-Grenzen wie im Bericht vom 19.10. (gemeinsamer Mount).

## Abschlussreview gegen Spec 0029 (Stichproben)

Geprüft an `docs/gate.md`, `scripts/gate/`, `scripts/githooks/`, `.github/workflows/native-ci.yml`, `package.json`, `CODING_STANDARDS.md`.

- **Erfüllt (Stichprobe):** Gate-Kommando mit `fast`/`full` (US 1, 4, 5: `npm run gate`, `scripts/gate/gate.js`); Hooks ohne Husky (US 2, 6: `scripts/githooks/pre-commit|pre-push`, `npm run hooks:install` setzt `core.hooksPath`); Gate-Container (US 7); Ratsche mit versionierter `baseline.json` (US 8–12, 14, 16); Coverage-Nenner ohne `lang/`, deklarative `db/*` mit Grund (US 17); Schichtregeln mit deptrac, `deptrac-unassigned` blockiert, `deptrac-baseline.yaml` ist leer, die Rückkanten sind also aufgelöst (US 23–25); Volllauf mit Berichten, kein Test gelöscht (US 27–33); diff-basierte Mutation in CI mit Ignore-Liste (928 Einträge, US 34–35); Fehlschlagslog (`failure-log.js`, US 36); CI führt `gate -- full` aus, `Gate (required)` verlangt `quality`, `phpunit`, `js-native`, `artifact`, Trend-Artefakt `gate-trend-…` (US 41–44); `CODING_STANDARDS.md` ist als angenommen markiert (#657/#659, US 38–40).
- **Bewusst offen / Abweichung:**
  - US 13 (Zielbild 95 % Coverage oder begründeter Rest je Stelle): Baseline 11.990 / 13.900 Statements = 86,3 %; ein Bericht über den ungedeckten Rest je Stelle wurde in dieser Stichprobe nicht gefunden. Offen.
  - US 45 (Vorher/Nachher-Tabelle): `docs/gate.md` enthält nur die erste Messung (82,33 %); die Abschlusstabelle steht jetzt in der Spec (Abschnitt „Abschluss“).
  - `docs/gate.md` Abschnitt „Verbindliche CI und Artefakte“ sagt noch „MSI kommt erst mit der Mutationsphase“; das Trend-Artefakt enthält den Volllauf-MSI nicht. Veraltet, kleine Doku-Korrektur.
  - US 38/39: Die Abstimmung der gemeinsamen Coding Standards ist nicht abgeschlossen (security-checks Issue #2).
  - Nicht geprüft: Mustache-/ESLint-/savepoint-Prüfungen im Einzelnen (US 21), PHPStan-Level und Baseline-Schrumpfung (US 22), Upgrade-Pfad-Test (US 19), Hotspot-Architekturprüfung (US 26, 37).
