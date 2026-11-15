# #672: begrenzter Äquivalenznachweis für den Kategorie-Kontextsuffix

Der ursprüngliche universelle Ignore für `question_import.php:442` war falsch
begründet: ein zusätzlich installierter Fragetyp darf die vollständige
Kategorieangabe vor dem Aufruf seiner Basisklasse auswerten. Spec 0017 §3.1
erlaubt solche Fragetypen. Der neue Eintrag gilt deshalb ausschließlich bei
Übereinstimmung der tatsächlich gemessenen Fragetypquellen mit
`qtypeSourceHash=a4202fdd0d146ab327992a53365530ab728c64f74cc069bb73bbc4287f72eac8`.

## Tatsächliche Quellen und fachlicher Nachweis

Im isolierten Moodle 5.1.7+ (Build 20260928) wurden 19 installierte Fragetypen
und 252 Quelldateien gemessen. Der vollständige sortierte Pfad-/SHA256-Bestand
ist im Original `infection-environment.json` festgehalten. Er umfasst alle
PHP-Quellen dieser Fragetypen außer Tests sowie explizit `question/engine/bank.php`,
`question/format.php`, `question/format/xml/format.php` und
`question/type/questiontypebase.php` sowie den tatsächlich verwendeten Parser
`lib/classes/xml_parser.php`, das Fragenschema `lib/db/install.xml`, die
numerische PHP-Initialisierung `lib/setup.php` sowie `lib/filelib.php` und die
DML-Treiber `lib/dml/{moodle,mysqli_native_moodle,pgsql_native_moodle}_database.php`.
Das sind 251 PHP-Dateien und eine XML-Datei. Originalquellen der Prüfung liegen unter
`/tmp/coursepilot-672-qtype-reviewed-sources-v5/`; Originalmessung unter
`/tmp/coursepilot-672-environment-v5.json` (Upgradeprüfung `...-v5-upgrade-audit.json`).
Die früheren Quellen blieben unverändert; die zusätzlichen Abhängigkeiten werden ebenfalls gemessen.

Die Zuordnung über `question_bank::get_qtype()` lädt die jeweilige konkrete
Klasse. Die Quellen enthalten genau fünf Deklarationen von `save_question()`:

| Implementierung | Verwendung der Kategorie vor der Basisklasse |
| --- | --- |
| `question_type`, `questiontypebase.php:366` | `list($form->category) = explode(',', $form->category)` entfernt den zweiten Teil unmittelbar vor dem Kontext-Lookup. Erst danach folgen Speicherung und typabhängige Optionen. |
| `qtype_description`, `description/questiontype.php:51` | Setzt ausschließlich die Höchstpunktzahl auf null und delegiert. |
| `qtype_random`, `random/questiontype.php:195` | Liest ebenfalls ausschließlich den ersten Teil mit `explode`; delegiert anschließend. |
| `qtype_multianswer`, `multianswer/questiontype.php:234` | Kopiert die Kategorie in das extrahierte Frageobjekt. Die unmittelbar aufgerufene Basisklasse überschreibt diese Kopie vor jeder Verwendung mit dem ersten Teil. |
| `qtype_calculated`, `calculated/questiontype.php:628` | Einseitige Typen delegieren unmittelbar. Bei der ersten Wizard-Seite läuft die Basisklasse vor `preparedatasets()` und den kategorieabhängigen Dataset-Helfern. Die anderen Wizard-Zweige speichern bestehende Dataset-Definitionen/-Werte über Frage-/Dataset-IDs; sie lesen den Kontextsuffix der Formkategorie nicht. |

Alle übrigen installierten Typen erben einen dieser Pfade. Der Mutant entfernt
nur den Kontextwert hinter dem erhaltenen Komma; der erste Teil bleibt gleich.
Für diesen endlichen Quellenbestand verändern sich dadurch weder Berechtigung,
Zielkategorie, persistierte Frage noch Diagnose. Dies ist keine Aussage über
beliebige Drittanbieter-Fragetypen oder zukünftige Moodle-Versionen.

## Derselbe begrenzte Nachweis für Formatter-Einstellungen

Coursepilot verwendet hier direkt `qformat_xml::readquestions()` beziehungsweise
`writequestion()`, nicht Moodles vollständige `importprocess()`-/`exportprocess()`-
Abläufe. In den geprüften XML-Methoden werden die Formatter-Einstellungen
`category`, `contexts`, `matchgrades`, `catfromfile`, `contextfromfile` und
`stoponerror` nicht gelesen. Diese Einstellungen dürfen bei beliebigen
Drittanbieter-Callbacks dennoch nicht pauschal als unbeobachtbar gelten.

Der erfasste Quellenbestand enthält genau 14 Deklarationen von
`import_from_xml()`/`export_to_xml()`: je zwei in `questiontypebase.php`,
`randomsamatch`, `ordering`, `ddwtos`, `gapselect`, `ddimageortext` und `ddmarker`.
Die tatsächlichen Signaturen und vollständigen Methoden stehen in
`/tmp/coursepilot-672-qtype-xml-callbacks.json` und
`/tmp/coursepilot-672-qtype-xml-callback-bodies.json`; die Originaldateien gehören
zum oben genannten Quellenmanifest.

Diese Callbacks reichen den Formatter nicht an weitere Funktionen weiter und
lesen keine seiner Eigenschaften. Sie rufen ausschließlich diese Methoden auf:
`format`, `get_format`, `getpath`, `import_answer`, `import_combined_feedback`,
`import_files_as_draft`, `import_headers`, `import_hints`, `trans_single`,
`write_answer`, `write_combined_feedback`, `write_files`, `writetext`, `xml_escape`.
Auch diese XML-Helfer lesen die genannten sechs Einstellungen nicht.
Der native Dispatch in `qformat_default::try_importing_using_qtypes()` und
`try_exporting_using_qtypes()` liest sie ebenfalls nicht. Insbesondere erhöht
`qformat_default::error()` den beobachteten Fehlerzähler unabhängig von
`stoponerror`; nur der hier nicht verwendete vollständige Importprozess wertet
dieses Flag aus. Entfernte Setter bzw. geänderte Werte dieser Einstellungen
verändern deshalb in diesem endlichen Bestand weder geparste Frage, Export,
Fehlerzähler noch Diagnose. Die konkreten Einträge nennen jeweils Einstellung
und Aufrufphase und tragen zwingend denselben gemessenen Quellenhash.

Der frühere CRLF-Eintrag entfällt. Ein nativer Import-/Rückexporttest zeigte,
dass das Aufteilen und anschließende Zusammenfügen der XML-Zeilen echte
Feedback-Zeilenumbrüche verlor. Import und Rückprüfung übergeben das XML jetzt
jeweils als vollständigen Chunk; die bisherige Ersetzung existiert nicht mehr.

## Herkunft der aktualisierten Mutantenidentitäten

Die Fehlerkorrekturen für Transaktionsabbruch, XML-Zeilenumbrüche, numerische
Persistenzpräzision und den case-sensitiven XML-Wrapper verändern die gesamten
Infection-Quellpaare dieser Datei. 48 bestehende Begründungen wurden ausschließlich
auf eindeutig gleiche Methoden, Mutatoren und tatsächlich geänderte AST-Zeilen
der neuen nativen Quellpaare übertragen. Zwei alte Einträge entfielen: die
entfernte Zeilenendenersetzung und die inzwischen entfernte `round()`-Umgebung
eines Float-Casts. Dessen neuer Mutant erhält damit keine automatische Ausnahme.

Originale: `/tmp/coursepilot-672-import-identities-3-original/`, Zuordnung und
Quellpaare: `/tmp/coursepilot-672-ignore-migration-plan-1.json`, Audit:
`/tmp/coursepilot-672-ignore-migration-audit-1.json`. Diese native Erfassung mit
`--dry-run` enthält ausdrücklich ungedeckte Mutanten und keinen Mutantentest.
Sie belegt nur deren Identität; vollständiges Gate und CI bleiben erforderlich.

## Rückgabevertrag des erfassten XML-Formatters

`qformat_xml::readquestions()` (`question/format/xml/format.php:997–1010`)
liefert entweder das Array von `import_questions()` oder `false`. Der einzige
`false`-Pfad fängt `xml_format_exception`, ruft zuerst `error()` auf und erhöht
damit `importerrors`. `true` wird nie zurückgegeben. Die Konjunktion dieser
Eigenschaften begründet zwei konkrete `FalseValue`-Mutanten: Der unveränderte
zweite Operand `importerrors > 0` im jeweiligen ODER erkennt weiterhin jeden
`false`-Rückgabefall. Ein bloßes UND statt ODER ist davon ausdrücklich nicht
gedeckt: Callbacks können bei einem Array-Ergebnis Importfehler melden.

Nach dem ursprünglichen Fehlercheck in `parse()` ist das Ergebnis bereits
ein Array. Nur dort ist der zusätzliche Array-Cast redundant. Der separate
`array_values`-Mutant braucht keine Behauptung über dichte Callback-Ergebnisse:
Allein `import_all()` erhält das private Ergebnis, prüft bzw. durchläuft Werte
und hängt seine öffentlichen Ergebnisse mit `$results[]` neu an. Durch
Kategorieanweisungen entstandene numerische Lücken bleiben daher unsichtbar.

Die drei Aussagen über Formatter-Rückgaben sind an denselben vollständigen
Quellenhash gebunden; eine geänderte Formatterimplementierung deaktiviert sie.
Sie begründen keine Ausnahme für einen möglichen nicht erkannten Importfehler.

## Endliche Feldproduzenten und 17 weitere Einzelbeweise

Die zusätzlich aufgenommenen Einträge verwenden ausschließlich Originalidentitäten
aus der nativen Erfassung. Zuordnung, unveränderte AST-Diffs und einzelne Gründe:
`/tmp/coursepilot-672-new-finite-equivalents-1.json`. Jeder Eintrag ist an den oben
genannten vollständigen Quellenhash gebunden. Die Eingabeformen aller 19 erfassten
Fragetypen sind:

| Fragetypen | Für die private Vergleichsliste relevante XML-Produzenten |
| --- | --- |
| `multichoice`, `shortanswer`, `numerical`, `calculated`, `calculatedsimple`, `calculatedmulti` | Native XML-Schleifen hängen Antwort und Bruchteil jeweils unter demselben Index an. Antworttext ist ein String oder ein Array mit String-`text`; Feedback kommt aus `import_text_with_files()`. |
| `ordering` | Der Callback hängt je Antwort ein Text-Array und den Bruchteil `1` an. |
| `ddwtos`, `gapselect` | Verwenden `choices`; das Array `answer` ist ein leerer Platzhalter. Beim alten Gapselect-Format wird es nicht gesetzt. |
| `truefalse` | Ausschließlich boolesches `answer` und zwei separate Feedback-Arrays. Speichern und erneuter XML-Dispatch erhalten diesen Fragetyp. |
| `description`, `essay`, `match`, `multianswer`, `randomsamatch`, `ddimageortext`, `ddmarker` | Keine nichtleere allgemeine Antwortliste; eigene Unterfragen-/Zuordnungs-/Drag-Strukturen. `multianswer` entpackt den gemeinsamen Fragetext ausdrücklich wieder zum String. |
| `random`, `missingtype` | Kein erfolgreicher nativer XML-Import mit einer nichtleeren allgemeinen Antwortliste. |

Auch der erfasste generische `question_type::import_from_xml()` hängt Antwort
und Bruchteil im selben Durchlauf unter `$a_count` an. Die beiden geänderten
Fallback-Zahlen sind daher für keinen tatsächlich iterierten Index erreichbar.
Dies begründet keine Ausnahme für das vollständige Ersetzen vorhandener Bruchteile.

Gemeinsame Fragetexte und allgemeines Feedback entstehen durch `import_headers()`
als Strings; der eigene Multianswer-Pfad liefert ebenfalls Strings. Antwort- und
Feedback-Arrays verwenden String-`text`. `getpath(..., istext=true)` meldet andere
XML-Formen als Importfehler; die Fehlerprüfung bricht vor dem Vergleich ab.
Objekte gelangen in diesem Bestand nicht zu `text_of()`. Daraus folgen die beiden
String-Casts, die beiden Varianten der unerreichbaren Objektverzweigung und die
unerreichbare Array-Schlüsselprüfung für die gemeinsamen Textfelder. Das tatsächliche
Entfernen von Text oder Feedback bleibt durch native Inhalts-/Anhangstests belegt.

Namen laufen durch `clean_question_name()` beziehungsweise dessen Default-Name-Pfad.
Sie sind bereits getrimmt; das Verkürzen bewahrt den linken Textanfang und endet
in Text oder Auslassungszeichen. Die tatsächliche XML-Identität stammt aus der
vorher erzeugten oder getrimmten Bank-ID und wird ohne zusätzliche Leerzeichen
exportiert. Der Multianswer-Parser trimmt diese ID zwar nicht selbst, erhält aber
bereits genau den ungepolsterten Exportwert. Dies begründet die drei einzelnen
Trim-Ausnahmen, keine allgemeine Aussage über eigene Callback-Namen oder IDs.

Für die sechs booleschen Bruchteilmutanten bleibt jeweils die andere Option eine
injektive Abbildung desselben booleschen Werts. Ein konstanter Wert der geänderten
Option verliert daher keine Erkennung; eine umgekehrte Option bewahrt zusätzlich
die absolute Differenz. Beide Feedbackvergleiche und beide Antworttexte bleiben
erhalten. Die vollständigen 96 booleschen/Feedback-Kombinationen sind in
`/tmp/coursepilot-672-boolean-fraction-proof.js` geprüft. Das ist ein algebraischer
Beweis, kein Infection-Lauf. Derselbe Nachweis enthält ein Gegenbeispiel mit
unterschiedlichen Darstellungen auf beiden Seiten: Deshalb ist die tatsächliche
geschlossene Produzentenmenge zwingend, und neue Fragetypquellen deaktivieren
diese Ausnahmen.

Der einzelne entfernte schließende `quiz`-Tag betrifft nur den privaten Wrapper
um einen vollständig geschlossenen nativen Frageblock. Der erfasste
`core\xml_parser` finalisiert `xml_parse()` nicht. Der äußere End-Handler verändert
den bereits aufgebauten Baum mit benannten `question`-Kindern nicht; vorherige
innere Parsefehler bleiben ebenfalls erhalten. Der tatsächliche native Parser
lieferte für drei vollständige Frageblöcke mit und ohne äußeren Endtag gleiche
Bäume: `/tmp/coursepilot-672-native-parser-probe.{php,log,exit}`. Das ist eine
Abhängigkeitsdiagnose, keine Import-/Infection-Abnahme. Gerade diese Aussage hängt
an der zusätzlich gemessenen Parserdatei und verliert bei deren Änderung ihre Gültigkeit.

## Erzwingung und noch offene Abnahme

Der Gate-Prozess erfasst die tatsächlichen Containerquellen vor Infection.
Ein bedingter Ignore braucht zusätzlich zur exakten Mutantenidentität und
Pflichtbegründung den passenden Quellenhash. Eine neue oder geänderte
Fragetypquelle deaktiviert ihn; fehlende Herkunftsdaten deaktivieren ihn ebenfalls.
Ein beschädigtes Manifest oder eine ungültige Bedingung ist rot. Der Offline-
Berichtsmodus wendet dieselbe Regel auf die zugehörige Original-Sidecar-Datei an.

Der öffentliche CLI-Vertrag prüft diese Fälle mit Fixture-Berichten. Diese
Fixtures sind keine Infection- oder CI-Abnahme. Zusätzlich hat der echte native
Lauf `proof-3` die tatsächliche Kategoriezuweisung über einen semantisch neutralen
Whitespace-Diff ausgewählt: sechs Mutanten, fünf getötet und genau der exakte
Suffixmutant überlebt. Mit passender Quellenbindung war der Gate-Exit 0 nach
175,009 s; mit abweichender Bindung blieb derselbe echte Survivor blockierend,
Gate-Exit 1 nach 173,693 s. Beide Infection-Prozesse lieferten Exit 0, keine
Fehler, Timeouts, Skips oder ungedeckten Mutanten. Der QA-Quell-Mount wurde
erfolgreich wiederhergestellt, Exit 0. Originale einschließlich der je Phase
tatsächlich verwendeten Ignore-Liste, Umgebung, Reports, Timer und Exits:
`/tmp/coursepilot-672-proof-3-original/`. Keine simulierten Infection-Berichte.

Erneutes vollständiges Gate, frisches Zweier-Review und CI-Abnahme sind noch
ausstehend. Der übrige Produktions-Diff hat weiterhin offene Survivor.

## Unabhängige native Typ- und Erreichbarkeitsverträge

Sieben weitere Einträge beziehen sich auf unveränderte Produktionsquellen des echten
vollständigen Entwicklungsreports `full-resumed-1`, nicht auf nachgebildete Mutationen.
Originalidentitäten, Diff und unveränderte Dateihashes:
`/tmp/coursepilot-672-native-contract-equivalents-2.json`.

- Quiz-Modul-ID: `can_add_moduleinfo` liefert ausschließlich die kanonische Identität
  des angeforderten Quiz-Moduls. `add_moduleinfo` übernimmt `moduleinfo->module`
  ausschließlich in `newcm->module` (`course/modlib.php:65`); der native Integer-Fremdschlüssel
  erhält aus Integer und dezimaler Integerzeichenfolge denselben Wert.
- Quiz-Passwort: Das Schema `mod/quiz/db/install.xml` erklärt `password` als nicht
  nullable Zeichenfeld. Native MariaDB-/PostgreSQL-Treiber liefern auch numerisch
  aussehende VARCHAR-Inhalte als Strings. Der zusätzliche String-Cast ist identisch.
- Quiz-Bestehensgrenze: Vor `apply_patch` ersetzt `quiz_write::update` den angezeigten
  Formwert durch den Float von `quiz::grade_settings`. Ein expliziter Patch muss zuvor
  die endliche JSON-Integer-/Float-Prüfung von `quiz_write_bridge` bestehen.
  Ohne primäre Bewertungsposition gibt es keinen entsprechenden Formwert.
  Die String-Normalisierung kann hier deshalb nichts ändern. Für generische Modul-
  Schreibwege gilt dieser Beweis ausdrücklich nicht. Der echte Komma-Dezimaltest
  bestätigt die Erhaltung; der Mutant überlebt diesen Test ebenfalls. Das ist kein
  Rotnachweis, sondern eine zusätzlich geprüfte Konsequenz des Quellenbeweises.
- Quiz-Anordnungs-ID: Beide einzigen Verbraucher von `quizid` sind die öffentlichen
  Operationen `arrangement::capture(int)` und `arrangement::restore(int)`. Beide
  erhalten durch PHPs schwache Parameterkonvertierung denselben kanonischen Integer.
- Zwei Completion-Fallbacks: Alle iterierten aktuellen Werte existieren und sind
  nicht null: gemeinsame Felder aus `course_settings` und nicht nullable Kernspalten,
  abgeleitetes `completionusegrade` als 0/1, bei Assign/Choice zusätzlich deren nicht
  nullable `completionsubmit`. Quiz verlässt den Pfad vorher. Ein fehlender historischer
  Zielwert wird weiterhin ausgeschlossen. Dies rechtfertigt ausschließlich die
  unerreichbaren Ersatzwerte ±1, nicht das Entfernen des Casts des tatsächlichen Werts:
  letzteres scheitert real beim Titel-Restore mit deaktiviertem Site-Abschluss.
- Benutzerquota: `core_admin\local\settings\filesize::write_setting` akzeptiert nur
  `is_int($bytes)` vor `config_write`; Bruchteile/Überlauf werden verworfen.
  Kanonische Integerbytes und ihre Konfigurationszeichenfolge liefern beim numerischen
  Vergleich und der Subtraktion denselben Integer. Anders als die verworfenen
  Timestamp-Begründungen ist diese Integerprüfung tatsächlich implementiert.

Öffentlich relevante Integer-Rückgaben werden nicht als äquivalent ignoriert:
Originalmutanten 16 und 64 sind durch native Fachoperationstests mit unverändertem
MariaDB-Datentyp rot nachgewiesen; externe Return-Schemas hatten den Unterschied
in bisherigen Werkzeugtests nachträglich normalisiert.
