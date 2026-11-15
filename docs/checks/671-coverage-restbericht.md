# #671: Coverage-Zielbild Werkzeuge, OAuth und Rest – Begründungsbericht

Spec 0029 · 16 (Eltern #655). Der Bericht listet **jede** nach der Arbeit verbleibende ungedeckte
Anweisung im ganzen Plugin und jede Methode mit CRAP über 8, je mit Grund.

## Messquelle

- Echte Gate-Messung: PHPUnit 11.5 mit pcov im Gate-Container (Moodle 5.1.7+, PHP 8.4, MariaDB 11.4),
  strikt nach `#[CoversClass]`/`#[CoversFunction]`, Auswertung mit `node scripts/gate/gate.js report <clover.xml>`
  (gleiche Ausschlussliste wie die Ratsche). Die Vollsuite-Läufe verwenden dieselbe Konfiguration wie `gate full`.
- Ausgangsstand: Commit `f0069f9` (Clover aus `gate full`): **93,28 %** (13057/13998), 30 Methoden mit CRAP > 8, 3 mit CRAP > 30.
- Endmessung: Vollsuite-Lauf `r4` auf `f0069f9` plus den Änderungen dieses Tickets (1998 Tests, 132 482 Assertions,
  15 reguläre Skips): **98,19 %** (13756/14009), 7 Methoden mit CRAP > 8, keine mit CRAP > 30.
  Der Commit dieses Tickets ist der, der diesen Bericht enthält; die Endwerte des Push-Gates (`gate full`) ersetzen die
  Zahlen dieses Berichts nur nach oben, nicht nach unten (Ratsche).
- Originalnachweise: `/var/lib/afk-pipeline/671/` (`clover-base.xml`, `clover-r4.xml`, `focus-r4.{log,exit}`,
  `uncovered-r4.txt`).

## Was geändert wurde

- **Covers-Ergänzungen**, wo ein bestehender Test die Klasse real ausführt (Ereignisse, `personal_data`, `image_preview`,
  `gd_support`, `version_source`, `retention`, `tool_registry`, `question_suspect_gate`, Upgrade-Bibliothek per
  `CoversFunction` statt `CoversNothing`). Das hob allein 93,28 % → 93,63 %; die Upgrade-Bibliothek (164 Anweisungen) war
  vorher unter `CoversNothing` komplett ungezählt.
- **Neue Tests** mit fachlicher Assertion an der öffentlichen Schnittstelle (externe Funktionen, Statikmethoden der
  Fachklassen, Moodle-Privacy-API): u. a. Instanzprüfung (Moodles `curl::mock_response`), Privatdatenschalter, Maskierung
  von Verfügbarkeits-JSON, Papierkorb/Wiederherstellung, Dispatcher (Protected-Resource, 405, 202, gesperrter Nutzer),
  Datenschutz im Systemkontext, OAuth-Secret-Clients, Quiz-Arrangement vollständig, Abschnitts-/Aktivitätsfehler, Glossar
  (Formate, reservierte Aliase, Typen), Rückgabeschema (`clean_returnvalue(execute_returns(), …)`) der Material-Werkzeuge.
- **Zerlegung** (CRAP): `glossary_entry_writer::add` (23 → 5 Methoden), `clone_activity::strip_dangling_completion`
  (13 → 8), `read_context_file::execute`, `set_restriction::grade_condition_locks`, `connection_storage_location::describe`,
  `version_history::summarize_change`, `oauth_lib::validate_authorize_request`. Tote Verzweigung entfernt:
  `add_questions_to_quiz::build_message` (leere Liste ist durch die Eingabeprüfung ausgeschlossen).
- **Zerlegung zurückgenommen**, wo sie nur Mutations-Überlebende in verschobenen Zeilen erzeugte, ohne Fachgewinn
  (`activity_backup::run_restore`, `get_question::execute`, `update_question_category::execute`,
  `xml_activity_creator::create`, `version_writer::add_grade_fields`): siehe CRAP-Ausnahmen unten. Die neuen Tests dieser
  Klassen bleiben.
- Keine Baseline- oder Schwellenabsenkung (`baseline.json` unverändert). PHPStan-Baseline: nur zwei Anonymklassen-Zeilennummern (+1) und ein
  Quellbeleg nachgeführt, kein neuer Eintrag.

## CRAP über 8 (7 Methoden, jede mit Grund)

| Methode | CRAP / Komplexität | Grund |
|---|---|---|
| `activity_file_supplement::validate` | 17,7 / 6 | Optionales Plugin `mod_lightboxgallery` fehlt im Gate-Image; die Fachtests laufen in der isolierten Optional-Plugin-Suite (Skip). Dort gedeckt, hier nicht messbar. |
| `activity_file_supplement::apply` | 20 / 4 | wie oben |
| `activity_backup::run_restore` | 17,83 / 15 | Der Rest sind Moodle-Backup-Fehlerpfade (Vorabprüfung, Aufräum-Fehler, keine eigene Aktivität), nur mit Fehlerinjektion in Moodles `restore_controller` erreichbar; es gibt dafür keinen Port. Eine Zerlegung wurde erprobt und verworfen: sie verschiebt nur Zeilen und erzeugt 12 Mutations-Überlebende in unveränderter Logik ohne testbaren Gewinn. |
| `xml_activity_creator::create` | 13 / 13 | Vollständig gedeckt; Komplexität stammt aus der linearen Abfolge Prüfen → Wiederherstellen → Rundlauf → Aufräumen. Der Aufräumzweig mit fehlschlagender Aufräumaktion ist nur mit Fehlerinjektion erreichbar (20 Mutations-Überlebende bei erprobter Zerlegung). |
| `update_question_category::execute` | 13 / 13 | Vollständig gedeckt durch die Werkzeugtests (Umbenennen, Verschieben, Kontextwechsel, Kollision, Selbst-/Teilbaumziel, Kein-Änderung); lineare Guard-Folge. Erprobte Zerlegung: 18 Überlebende in Cast-/Aufrufzeilen. |
| `get_question::execute` | 11,06 / 11 | Lineare Auflösung (Parameter → Kategorie → Eintrag → Version → Antworten); die Zweige „Version fehlt" sind durch die Datenstruktur nicht erreichbar (Eintrag wird aus Versionen abgeleitet). Erprobte Zerlegung: 26 Überlebende. |
| `version_writer::add_grade_fields` | 10,07 / 10 | Unerreichte Zeilen: Ergebnis-Outcome-Zuordnung und gemischte Bewertungskategorien (verlangt aktivierte Outcomes bzw. manipulierte Notenstruktur). Erprobte Zerlegung: 4 Überlebende. |

## Ungedeckte Stellen (Datei:Zeile) und Grund

Gesamt 253 von 14 009 Anweisungen. Gruppen:

**A. Dateikopf bei Autoload (`defined('MOODLE_INTERNAL') || die()`, `require_once`).** Die Zeilen laufen beim ersten
Laden der Klasse, also im ersten Test, der sie berührt – das ist häufig ein Test, der die Klasse nicht per Covers
benennt, und strikte Covers zählt sie dann nicht. Nicht lösbar ohne Tests künstlich zu verschieben.
`activity_backup.php:22,25-27`, `course_module_placement.php:19,22`, `question_import.php:25,28-30`,
`question_xml.php:23,26-28`, `update_mc_question.php:29,31`, `admin/personaldatahosts_setting.php:21,28`,
`admin/remoteaccesscohorts_setting.php:21,26`, `catalog/assign.php:21,23`, `external/add_questions_to_quiz.php:28,31-32`,
`clone_activity.php:31,34`, `ensure_question_bank.php:27,29-30`, `ensure_question_category.php:25,27`,
`ensure_quiz_question_categories.php:27,31`, `get_question.php:25,27`, `get_question_categories.php:26,28`,
`get_quiz_cleanup_plan.php:26,28`, `move_question.php:27,29`, `update_question_category.php:25,27`.

**B. Optionales Plugin `mod_lightboxgallery` fehlt im Gate-Image** (Tests laufen in der isolierten Optional-Plugin-Suite,
hier Skip): `activity_file_supplement.php:58-70,82-97,110-128`, `activity_files/lightboxgallery.php:41-48`,
`xml_activity_creator.php:268-269`.

**C. Umgebung des Gate-Containers:** `material_composition.php:46,50` (GD/Schrift fehlen nur in anderen Umgebungen; hier
vorhanden, Zweig nur mit Fehlinstallation), `external/crop_material_file.php:510-511` (WebP-Ausgabe, libwebp-abhängig),
`webdav/webdav_client.php:439,443` (libmagic-abhängige MIME-Erkennung).

**D. Moodle-Versionszweige** (Gate läuft auf 5.1; die CI-Matrix 5.2/5.3 deckt sie): `catalog/forum.php:331-340`,
`catalog/quiz.php:419-428,565-566`, `catalog/assign.php:862,865`.

**E. Nebenläufigkeit und Transaktions-Rollback** (nur mit echter Parallelität bzw. Fehlerinjektion in Moodle-Kern):
`connection.php:109-110` (verlorener Claim-Wettlauf), `connection.php:492-493,530-531`, `oauth_budget.php:66-67,209-210`,
`private_files_storage_port.php:125-127,129`, `question_import.php:104`, `glossary_entry_writer.php:178` (nach
`rollback()` unerreichbar, `rollback()` wirft selbst; die Zeile erfüllt nur die statische Analyse),
`external/add_glossary_entries.php:107` (Sperre belegt), `history/version_restore.php:94`,
`history/version_writer.php:110,168`, `pending_write_translation.php:86`.
Die fünf Race-Szenarien laufen als Kindprozess-Fixtures; deren Abdeckung zählt für den Eltern-Lauf nicht.

**F. Verteidigende Zweige ohne Port zur Fehlerinjektion:** `activity_backup.php:90-92,220-222,289,300,322-329`
(Backup-/Restore-Controller-Fehler), `instance_check.php:132-134` (curl wirft), `dispatcher.php:390` (Fehler ohne
Ausnahmeobjekt, kann `call_external_function` nicht liefern), `external/update_mc_question.php:116,139-152` (Verdachtsfall nach Wettlauf: die gerade vergebene idnumber trifft nur bei
gleichzeitiger fremder Änderung), `update_mc_question.php:603-606` (Index außerhalb der Antworten ist durch die
Parameterableitung ausgeschlossen), `update_mc_question.php:308-309` (Warnung bei entfernten eingebetteten Dateien),
`question_import.php:318-320,469,472,589-591,600,638,641,644,768,783-787` (Parserfehler als Ausnahme statt Rückgabewert,
`defaultgrade`-Rückfall, abgeleitete Abweichungsfelder `idnumber`/`questiontext`/`generalfeedback`, Objekt-/Array-Textformen
des Formatters), `get_question.php:123-129` (Version fehlt: durch Datenstruktur ausgeschlossen),
`external/set_restriction.php:429,433` (Strukturprüfung des Kerns, nach vorheriger Validierung nicht erreichbar),
`external/get_modules.php:107-108` (Tabelle eines deinstallierten Moduls),
`catalog/completion_settings.php:461` (Meldung bei unverändertem Patch, vorgelagert abgefangen),
`external/export_questions_xml.php:133` (Warnungstext), `external/export_activity_backup.php:64` und
`external/clone_activity.php:185` (alle installierten Kernmodule unterstützen Backup), `external/clone_activity.php:210,222,246,311`
(Verfügbarkeitsbaum nicht wohlgeformt bzw. Bedingung ohne Quellverweis), `external/crop_material_file.php:257,310`,
`external/move_question.php:104,128`, `external/update_question_category.php:97,200` (Selbst-Elternkategorie wird vom
Parameterwert 0 nicht ausgelöst; `allow_commit` läuft über die Rückgabe), `history/retention.php:107`,
`history/version_history.php:156`, `history/version_restore.php:191,196,239`, `history/version_writer.php:260,275-276`
(Outcome-Felder), `quiz/arrangement.php:228` (abweichende Abschnittszahl), `cm_references.php:154`, `cimd_http_fetcher.php:61`,
`oauth_lib.php:194,317`, `admin/pointer_scan.php:155`, `admin/personaldatahosts_setting.php:49`,
`access_log.php:58`, `event/tool_access_succeeded.php:84`, `history/file_policy.php:43`,
`course_module_placement.php:67,144`, `glossary_entry_writer.php:264` (Dateizahl über 99; ein Test bräuchte 100 Materialdateien, der Größenzweig derselben Prüfung ist gedeckt), `db/upgradelib.php:63,124,274,283,287` (Upgradeschritte für Zwischenstände,
die auf dem frischen Test-Schema nicht vorkommen).

Gruppe F enthält Defensivzweige und Kern-Interna sowie einige triviale Normalpfade, die nach dem Messlauf `r4` nicht mehr
nachgemessen wurden: `history/file_policy.php:43`, `event/tool_access_succeeded.php:84`, `access_log.php:58` und
`question_suspect_gate.php:116` sind inzwischen durch ergänzte Tests gedeckt (Stand nach `r4`, im Push-Gate sichtbar).
Offen bleiben dort bewusst `retention.php:107`, `pointer_scan.php:155`, `oauth_lib.php:194,317`, `move_question.php:104`,
`version_restore.php:191,196`, `version_history.php:156`: öffentlich erreichbar, aber ohne eigenen Fachwert gegenüber dem
Aufwand; Folge-Ticket für ihre Tests ist die Sammelcheckliste #675. Neue Ports nur für Tests wären gegen CODING_STANDARDS T1.

## Mutation

- Vollständiger Diff-Lauf `gate mutation` auf dem Stand vor der Bereinigung: 1650 Mutanten, 107 Überlebende in geänderten
  Zeilen, 0 Fehler. Davon entfielen 95 auf die fünf zurückgenommenen Zerlegungen (siehe oben); die übrigen wurden mit
  Tests getötet:
  Verfügbarkeitssperre des Quiz-Tools (`completion_by_grade_cases`), Glossarformate/Aliase/Typen, Altnamen im Unterordner,
  Ablageort-Markierung des Material-Ziels, Arrangement-Rückschreibung.
- Zwei Cast-Überlebende wurden durch Entfernen der überflüssigen Casts beseitigt (`set_restriction.php:393-394`,
  `glossary_entry_writer.php:77`), einer exakt ignoriert: `connection_storage_location.php:68` CastString (der Host eines
  externen Zustands ist stets ein String; der Cast bedient nur den deklarierten Nullable-Typ); das ist der einzige neue
  Eintrag in `scripts/gate/mutation-ignore.json`. Die Quell-Hashes der Altignores blieben unverändert gültig.
- Teilprüfung der fünf geänderten Dateien: 261 Mutanten, 232 getötet, 3 ungedeckt, 26 entweder durch Altignores abgedeckt
  oder in der Liste oben behoben.

## Grenzen

- Der Endlauf der Mutationsprüfung und das Push-Gate laufen auf dem committeten Stand; ihr Ergebnis steht im
  Worker-Bericht, nicht in diesem Dokument.
- Die Zahl 98,19 % stammt aus einem Vollsuite-Lauf mit derselben pcov-Konfiguration wie das Gate; die Zeilenbasis
  (14 009 statt 13 998 Anweisungen) wächst um die refaktorierten Klassen.
