<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot;

/**
 * Zweiter Teil der Werkzeug-Registrierung (Issue #509, Vorab-Umbau vor
 * Spec #486-Review): {@see tool_registry} lag ueber 1000 Zeilen, ein reiner
 * Zeilengrenzen-Schnitt derselben TOOLS-Datenstruktur, keine Verhaltens-
 * aenderung. Diese Haelfte traegt Kontextbereich, Materialbestand,
 * Aktivitaets-Klonen, Skills, Ausstand und Altbestand -
 * {@see tool_registry::all()} fuegt sie mit dem ersten Teil
 * (Kurs/Quiz/Fragen, {@see tool_registry::CORE_TOOLS}) zusammen.
 *
 * Gleiche Eintragsform wie {@see tool_registry::CORE_TOOLS} - siehe dort
 * fuer die Feldbeschreibung.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_registry_context_tools {

    /**
     * @var array<string, array{
     *     function: string,
     *     classname: string,
     *     wsdescription: string,
     *     description: string,
     *     schema: ?array{properties: array, required?: array},
     *     capability: ?string,
     *     write?: bool,
     * }>
     */
    public const TOOLS = [
        'kurspilot_list_context_files' => [
            'function' => 'local_kurspilot_list_context_files',
            'classname' => 'local_kurspilot\external\list_context_files',
            'wsdescription' => 'Lists the calling teacher\'s Kurspilot context area (own working area only).',
            'description' => 'Listet den eigenen Kontextbereich der angemeldeten Lehrkraft auf '
                . '(Lerngruppenprofile, Fachprofile, gemerkte Vorlagen). "path" waehlt optional einen Unterordner, leer '
                . 'liefert die Wurzel. Nur der eigene Bereich der aufrufenden Person ist erreichbar. '
                . '"vorheriger_ort": true listet stattdessen den Altbestand (vorheriger Ort nach einem Ortswechsel, '
                . 'aus kurspilot_list_skills als "Altbestand offen" erkennbar) - nur lesbar, wirkt nur solange offen.',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Optionaler Unterordner, leer fuer die Wurzel'],
                    'vorheriger_ort' => [
                        'type' => 'boolean',
                        'description' => 'true listet den vorherigen Ort (Altbestand) statt des aktuellen - '
                            . 'wirkt nur, solange Altbestand offen ist',
                    ],
                ],
            ],
            'capability' => null,
        ],
        'kurspilot_describe_module_fields' => [
            'function' => 'local_kurspilot_describe_module_fields',
            'classname' => 'local_kurspilot\external\describe_module_fields',
            'wsdescription' => 'Reads the field catalog for a module type (fields, presets) or, without a '
                . 'modname, the list of module types Kurspilot catalogs at all.',
            'description' => 'Liefert den Feldkatalog: was eine Aktivitaetsart einstellen kann, mit deutscher '
                . 'Bedeutung je Feld statt englischer Namen ohne Erklaerung. Ohne "modname" die Liste der von '
                . 'Kurspilot gefuehrten Aktivitaetsarten (z.B. label). Mit "modname" die haeufig gesetzten Felder '
                . 'plus Feldbuendel und einen Hinweis, dass es mehr gibt; mit "vollstaendig": true zusaetzlich '
                . 'Pseudofelder, Sperrliste, Kombinationsregeln und Nebenwirkungsvermerke. Rein lesend.',
            'schema' => [
                'properties' => [
                    'modname' => ['type' => 'string', 'description' => 'Aktivitaetstyp, z.B. label. Leer fuer die Liste der gefuehrten Arten'],
                    'vollstaendig' => ['type' => 'boolean', 'description' => 'true fuer alle fuenf Katalogkategorien'],
                ],
            ],
            'capability' => null,
        ],
        'kurspilot_read_context_file' => [
            'function' => 'local_kurspilot_read_context_file',
            'classname' => 'local_kurspilot\external\read_context_file',
            'wsdescription' => 'Reads one file from the calling teacher\'s Kurspilot context area (own working '
                . 'area only).',
            'description' => 'Liest eine einzelne Datei aus dem eigenen Kontextbereich der angemeldeten '
                . 'Lehrkraft, z.B. "vorlagen.md" an der Wurzel fuer gemerkte Vorlagenentscheidungen. Rein lesend - '
                . 'Schreiben ist ueber dieses Werkzeug nicht moeglich. "vorheriger_ort": true liest stattdessen vom '
                . 'Altbestand (vorheriger Ort nach einem Ortswechsel) - nur lesbar, wirkt nur solange offen. Zum '
                . 'Kopieren die gelesenen Inhalte anschliessend ueber kurspilot_write_context_file mit '
                . '"nur_anlegen": true an den neuen Ort schreiben.',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Dateipfad relativ zur Wurzel, z.B. "vorlagen.md"'],
                    'vorheriger_ort' => [
                        'type' => 'boolean',
                        'description' => 'true liest vom vorherigen Ort (Altbestand) statt vom aktuellen - '
                            . 'wirkt nur, solange Altbestand offen ist',
                    ],
                ],
                'required' => ['path'],
            ],
            'capability' => null,
        ],
        'kurspilot_write_context_file' => [
            'function' => 'local_kurspilot_write_context_file',
            'classname' => 'local_kurspilot\external\write_context_file',
            'wsdescription' => 'Creates or fully overwrites one .md file in the calling teacher\'s Kurspilot '
                . 'context area (own working area only).',
            'description' => 'Legt eine .md-Datei im eigenen Kontextbereich der angemeldeten Lehrkraft an oder '
                . 'ueberschreibt sie vollstaendig, z.B. "plan.md". Der uebergebene Inhalt ersetzt die Datei ganz - '
                . 'zum Fortschreiben eines Journals nicht geeignet. "expected_contenthash" aus dem letzten Lesen '
                . 'mitgeben, damit eine zwischenzeitliche Handaenderung nicht ueberschrieben wird. Die Antwort sagt, '
                . 'ob die Datei neu angelegt oder ueberschrieben wurde. Scheitert ein gueltiger Schreibversuch am '
                . 'externen Speicher, der Verbindung oder dem Ort, wird nichts abgelegt - die Antwort nennt eine '
                . 'Kennung und die Anweisung, den Inhalt im Gespraech zu behalten und mit "ausstand" erneut zu '
                . 'schreiben, sobald die Verbindung wieder steht. "ausstand" mit genau dieser Kennung mitgeben, um '
                . 'einen offenen Ausstand (aus kurspilot_list_skills) im selben Aufruf abzuhaken. "nur_anlegen": '
                . 'true legt nur an und ueberschreibt nie - fuer das Kopieren aus dem Altbestand (vorheriger Ort, '
                . 'aus kurspilot_list_context_files/kurspilot_read_context_file mit "vorheriger_ort": true '
                . 'gelesen) an den neuen Ort.',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Dateipfad relativ zur Wurzel, nur .md, z.B. "plan.md"'],
                    'content' => ['type' => 'string', 'description' => 'Vollstaendiger neuer Dateiinhalt, hoechstens 1 MB'],
                    'expected_contenthash' => [
                        'type' => 'string',
                        'description' => 'Optional: contenthash aus dem letzten Lesen oder Auflisten - passt er '
                            . 'nicht mehr zum aktuellen Stand, bricht der Vorgang mit "Konflikt" ab (neu lesen, '
                            . 'zusammenfuehren, erneut schreiben). Ohne ETag am externen Ort (IServ) beruht der '
                            . 'Vergleich auf der Aenderungszeit (Sekundenaufloesung) - ein sehr knapp zeitgleicher '
                            . 'zweiter Schreibvorgang kann dort unerkannt bleiben.',
                    ],
                    'ausstand' => [
                        'type' => 'string',
                        'description' => 'Optional: Kennung eines offenen Ausstands (aus kurspilot_list_skills) - '
                            . 'gelingt das Schreiben, verschwindet der Eintrag im selben Aufruf. Am externen Ort '
                            . 'zusaetzlich zu einer bereits vorhandenen Zieldatei "expected_contenthash" mitgeben - '
                            . 'ohne Pruefwert wird beim Nachtragen nie ueberschrieben.',
                    ],
                    'nur_anlegen' => [
                        'type' => 'boolean',
                        'description' => 'Optional: true legt nur an und ueberschreibt nie - fuer das Kopieren aus '
                            . 'dem Altbestand an den neuen Ort',
                    ],
                    'courseid' => [
                        'type' => 'number',
                        'description' => 'Optional: Kurs-ID, wenn der Inhalt zu einem bestimmten Kurs gehoert - '
                            . 'dient nur einem etwaigen Eintrag der Notiz "noch nicht gespeichert", falls der '
                            . 'Speicher, die Verbindung oder der Ort scheitert.',
                    ],
                ],
                'required' => ['path', 'content'],
            ],
            'capability' => null,
            'write' => true,
        ],
        'kurspilot_append_context_file' => [
            'function' => 'local_kurspilot_append_context_file',
            'classname' => 'local_kurspilot\external\append_context_file',
            'wsdescription' => 'Appends content to one .md file in the calling teacher\'s Kurspilot '
                . 'context area in a single server call (own working area only).',
            'description' => 'Haengt Inhalt an eine .md-Datei im eigenen Kontextbereich der angemeldeten '
                . 'Lehrkraft an, z.B. einen Journaleintrag an "journal.md". Vorhandener Inhalt bleibt stehen - '
                . 'dafuer die Datei nicht vorher lesen, das Anhaengen passiert in einem Vorgang auf dem Server. '
                . 'Fehlt die Zieldatei, wird sie angelegt, und die Antwort sagt das ausdruecklich, damit ein '
                . 'Tippfehler im Pfad auffaellt. Wird die Datei groesser als 1 MB, empfiehlt die Antwort eine '
                . 'Rotation (neues Journalarchiv anlegen). Scheitert ein gueltiger Anhaengversuch am externen '
                . 'Speicher, der Verbindung oder dem Ort, wird nichts abgelegt - die Antwort nennt eine Kennung und '
                . 'die Anweisung, den Inhalt im Gespraech zu behalten und mit "ausstand" erneut anzuhaengen, sobald '
                . 'die Verbindung wieder steht. "ausstand" mit genau dieser Kennung mitgeben, um einen offenen '
                . 'Ausstand (aus kurspilot_list_skills) im selben Aufruf abzuhaken. "expected_contenthash" wirkt '
                . 'nur am externen Ort: gegen eine bereits vorhandene Zieldatei mitgeben, um eine zwischenzeitliche '
                . 'Handaenderung zu erkennen (nicht noetig, wenn die Datei noch fehlt).',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Dateipfad relativ zur Wurzel, nur .md, z.B. "journal.md"'],
                    'content' => ['type' => 'string', 'description' => 'Anzuhaengender Inhalt, hoechstens 1 MB'],
                    'ausstand' => [
                        'type' => 'string',
                        'description' => 'Optional: Kennung eines offenen Ausstands (aus kurspilot_list_skills) - '
                            . 'gelingt das Schreiben, verschwindet der Eintrag im selben Aufruf. Am externen Ort '
                            . 'zusaetzlich zu einer bereits vorhandenen Zieldatei "expected_contenthash" mitgeben - '
                            . 'ohne Pruefwert wird beim Nachtragen nie angehaengt.',
                    ],
                    'expected_contenthash' => [
                        'type' => 'string',
                        'description' => 'Optional, wirkt nur am externen Ort: contenthash der Zieldatei aus dem '
                            . 'letzten Lesen oder Auflisten - passt er nicht mehr, bricht der Vorgang mit '
                            . '"Konflikt" ab. Ohne ETag (IServ) beruht der Vergleich auf der Aenderungszeit '
                            . '(Sekundenaufloesung).',
                    ],
                    'courseid' => [
                        'type' => 'number',
                        'description' => 'Optional: Kurs-ID, wenn der Inhalt zu einem bestimmten Kurs gehoert - '
                            . 'dient nur einem etwaigen Eintrag der Notiz "noch nicht gespeichert", falls der '
                            . 'Speicher, die Verbindung oder der Ort scheitert.',
                    ],
                ],
                'required' => ['path', 'content'],
            ],
            'capability' => null,
            'write' => true,
        ],
        'kurspilot_list_material_files' => [
            'function' => 'local_kurspilot_list_material_files',
            'classname' => 'local_kurspilot\external\list_material_files',
            'wsdescription' => 'Lists the calling teacher\'s Kurspilot material folder (own working area '
                . 'only): path, size, contenthash, last modified, and remaining storage quota.',
            'description' => 'Listet den eigenen Materialordner der angemeldeten Lehrkraft auf - hochgeladene '
                . 'Bilder und Dokumente mit Groesse, contenthash, Aenderungszeit sowie den verbleibenden '
                . 'Speicherplatz. "path" waehlt optional einen Unterordner, leer liefert die Wurzel.',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Optionaler Unterordner, leer fuer die Wurzel'],
                    'ort' => [
                        'type' => 'string',
                        'enum' => [material_files::ORT_BESTAND, material_files::ORT_WERKBANK],
                        'description' => material_files::ORT_DESCRIPTION,
                    ],
                ],
            ],
            'capability' => null,
        ],
        'kurspilot_upload_material_file' => [
            'function' => 'local_kurspilot_upload_material_file',
            'classname' => 'local_kurspilot\external\upload_material_file',
            'wsdescription' => 'Creates or fully overwrites one file in the calling teacher\'s Kurspilot '
                . 'material folder (own working area only). No own size limit - checked against the server\'s '
                . 'upload configuration.',
            'description' => 'Legt eine Datei im eigenen Materialordner der angemeldeten Lehrkraft an oder '
                . 'ueberschreibt sie vollstaendig, z.B. "screenshot.png". "content_base64" ist der Dateiinhalt '
                . 'base64-kodiert. Erlaubte Endungen: PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, HTML, TXT, CSV, ZIP sowie '
                . 'PNG/JPG/GIF/SVG/WEBP. "expected_contenthash" aus dem letzten Auflisten mitgeben, damit eine '
                . 'zwischenzeitliche Handaenderung nicht ueberschrieben wird. Die Antwort warnt, wenn nach dem '
                . 'Schreiben weniger als 10% des Speicherplatzes frei bleiben.',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Dateipfad relativ zur Wurzel, z.B. "screenshot.png"'],
                    'content_base64' => ['type' => 'string', 'description' => 'Dateiinhalt, base64-kodiert'],
                    'expected_contenthash' => [
                        'type' => 'string',
                        'description' => 'Optional: contenthash aus dem letzten Auflisten - passt er nicht, bricht der Vorgang ab',
                    ],
                ],
                'required' => ['path', 'content_base64'],
            ],
            'capability' => null,
            'write' => true,
        ],
        'kurspilot_preview_material_file' => [
            'function' => 'local_kurspilot_preview_material_file',
            'classname' => 'local_kurspilot\external\preview_material_file',
            'wsdescription' => 'Returns a shrunk preview (longest edge 768px, JPEG) of an image in the calling '
                . 'teacher\'s Kurspilot material folder, so the model can actually see it - choose a crop, '
                . 'suggest alt text. Non-image files return a clear message instead of an error.',
            'description' => 'Zeigt eine verkleinerte Vorschau (laengste Kante 768px, JPEG) einer Bilddatei aus '
                . 'dem eigenen Materialordner - damit das Modell den Inhalt tatsaechlich sieht, einen Ausschnitt '
                . 'waehlen und einen Alt-Text vorschlagen kann. Uebertragen wird nur die Vorschau, nie das '
                . 'Original. Bei einer Nicht-Bilddatei liefert "available": false mit erklaerender Meldung statt '
                . 'eines Fehlers.',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Dateipfad relativ zum Materialordner, z.B. "screenshot.png"'],
                    'ort' => [
                        'type' => 'string',
                        'enum' => [material_files::ORT_BESTAND, material_files::ORT_WERKBANK],
                        'description' => material_files::ORT_DESCRIPTION,
                    ],
                ],
                'required' => ['path'],
            ],
            'capability' => null,
        ],
        'kurspilot_crop_material_file' => [
            'function' => 'local_kurspilot_crop_material_file',
            'classname' => 'local_kurspilot\external\crop_material_file',
            'wsdescription' => 'Crops one image in the calling teacher\'s Kurspilot material folder to a '
                . 'targeted sub-region and stores the result as a new (or overwritten) file in the same folder. '
                . 'Coordinates are relative (0-1) against the preview image, but the crop is taken from the '
                . 'full-resolution original. The origin is recorded in the result file\'s standard Moodle "source" '
                . 'field.',
            'description' => 'Schneidet ein Bild aus dem eigenen Materialordner auf den fachlich benoetigten '
                . 'Ausschnitt zu (Gezielter Bildausschnitt) und legt das Ergebnis als neue oder ueberschriebene '
                . 'Datei im selben Ordner ab - eigener Endpunkt statt Upload-Parameter, damit ein zweiter Versuch '
                . 'nur einen Aufruf kostet statt eines zweiten Uploads. "x0"/"y0"/"x1"/"y1" sind relative '
                . 'Koordinaten (0-1) auf die von preview_material_file gezeigte Vorschau; geschnitten wird aus dem '
                . 'Original in voller Aufloesung. SVG-Quellen werden abgewiesen (GD ist raster-only). Die Herkunft '
                . 'steht danach im source-Feld der Zieldatei.',
            'schema' => [
                'properties' => [
                    'sourcepath' => ['type' => 'string', 'description' => 'Pfad der zuzuschneidenden Materialdatei, relativ zum Materialordner'],
                    'targetpath' => ['type' => 'string', 'description' => 'Zielpfad des Ausschnitts, relativ zum Materialordner, z.B. "ausschnitt.png"'],
                    'x0' => ['type' => 'number', 'description' => 'Linke Kante des Ausschnitts, relativ 0-1'],
                    'y0' => ['type' => 'number', 'description' => 'Obere Kante des Ausschnitts, relativ 0-1'],
                    'x1' => ['type' => 'number', 'description' => 'Rechte Kante des Ausschnitts, relativ 0-1'],
                    'y1' => ['type' => 'number', 'description' => 'Untere Kante des Ausschnitts, relativ 0-1'],
                    'expected_contenthash' => [
                        'type' => 'string',
                        'description' => 'Optional: contenthash der Zieldatei aus dem letzten Auflisten - passt er nicht, bricht der Vorgang ab',
                    ],
                    'ort' => [
                        'type' => 'string',
                        'enum' => [material_files::ORT_BESTAND, material_files::ORT_WERKBANK],
                        'description' => material_files::ORT_DESCRIPTION,
                    ],
                ],
                'required' => ['sourcepath', 'targetpath', 'x0', 'y0', 'x1', 'y1'],
            ],
            'capability' => null,
            'write' => true,
        ],
        'kurspilot_report_loose_material_files' => [
            'function' => 'local_kurspilot_report_loose_material_files',
            'classname' => 'local_kurspilot\external\report_loose_material_files',
            'wsdescription' => 'Reports material folder files whose contenthash does not appear in any activity '
                . 'filearea of the calling teacher\'s own courses ("loose"): path, size, age in days, total '
                . 'reclaimable space and remaining quota. Writes nothing.',
            'description' => 'Listet Materialdateien, die in keiner Aktivitaet der eigenen Kurse verwendet werden '
                . '("lose") - Pfad, Groesse, Alter in Tagen, Summe des freiwerdenden Platzes, Restquote. '
                . '"Verwendet" wird per contenthash-Abgleich geprueft, nicht geraten: ein Original bleibt nach '
                . 'einem Zuschnitt zurecht lose, sobald nur der Ausschnitt eingebettet ist. Liest nur, loescht '
                . 'nichts - der Loeschweg ist delete_material_files.',
            'schema' => null,
            'capability' => null,
        ],
        'kurspilot_delete_material_files' => [
            'function' => 'local_kurspilot_delete_material_files',
            'classname' => 'local_kurspilot\external\delete_material_files',
            'wsdescription' => 'Deletes exactly the given material folder paths - nothing is deleted without an '
                . 'explicit list, no automatic deletion, no age-based rule. Intended to follow an explicit '
                . 'confirmation after report_loose_material_files.',
            'description' => 'Loescht genau die angegebenen Pfade im Materialordner - nichts ohne ausdrueckliche '
                . 'Liste, kein automatisches Loeschen, keine Altersregel. Erst nach Bestaetigung durch die '
                . 'Lehrkraft aufrufen, i.d.R. im Anschluss an report_loose_material_files. Bricht komplett ab, '
                . 'wenn ein Pfad nicht existiert - kein Teilerfolg bei einem Tippfehler.',
            'schema' => [
                'properties' => [
                    'paths' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Zu loeschende Dateipfade, relativ zum Materialordner, z.B. ["altes-blatt.pdf"]',
                    ],
                ],
                'required' => ['paths'],
            ],
            'capability' => null,
            'write' => true,
        ],
        'kurspilot_clone_activity' => [
            'function' => 'local_kurspilot_clone_activity',
            'classname' => 'local_kurspilot\external\clone_activity',
            'wsdescription' => 'Clones an activity, either within the same course or across courses, via a '
                . 'single-activity backup/restore (backup_controller/restore_controller, MODE_IMPORT) for both '
                . 'paths - whether the restore lands in the source or a different course is chosen internally '
                . 'based on targetcourseid. Title is always set explicitly (no "(copy)" suffix), visibility is '
                . 'always set explicitly. Cross-course clones: a completion condition Moodle could not translate '
                . 'into the target course (cmid set to 0) is detected and removed, named in the response.',
            'description' => 'Dupliziert eine Aktivitaet - im selben Kurs oder in einen anderen, je nachdem, ob '
                . '"targetcourseid" gesetzt und vom Quellkurs verschieden ist. Der Titel wird immer explizit '
                . 'gesetzt (kein "(Kopie)"-Suffix), die Sichtbarkeit ebenso. Beim kursuebergreifenden Klon kann '
                . 'Moodle Verweise in Abschlussbedingungen nicht in den Zielkurs uebersetzen - eine solche kaputte '
                . 'Bedingung wird erkannt, entfernt und in der Meldung im Klartext genannt (sonst waere die '
                . 'Aktivitaet moeglicherweise fuer niemanden sichtbar). Geprueft wird die native '
                . 'Bearbeiten-Berechtigung in Quell- und Zielkurs, kursuebergreifend zusaetzlich die Backup-/'
                . 'Restore-Rechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der zu klonenden Aktivitaet'],
                    'title' => ['type' => 'string', 'description' => 'Titel der geklonten Aktivitaet, immer explizit gesetzt'],
                    'targetcourseid' => [
                        'type' => 'number',
                        'description' => 'Ziel-Kurs-ID; weggelassen oder gleich dem Quellkurs = Klon im selben Kurs',
                    ],
                    'visible' => ['type' => 'boolean', 'description' => 'Sichtbarkeit der geklonten Aktivitaet (Default: true)'],
                ],
                'required' => ['cmid', 'title'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_report_clone_lineage' => [
            'function' => 'local_kurspilot_report_clone_lineage',
            'classname' => 'local_kurspilot\external\report_clone_lineage',
            'wsdescription' => 'Reports per question of a (typically just cloned) quiz whether it became its own '
                . 'copy or the reference still points at the source course, by reading question_references - '
                . 'writes nothing, no idnumber backfill.',
            'description' => 'Meldet je Frage eines Tests (i.d.R. das Ergebnis eines vorherigen clone_activity), '
                . 'ob eine eigene Kopie entstanden ist oder die Fragereferenz weiterhin auf den Bank-Eintrag im '
                . 'Quellkurs zeigt ("eigene_kopie" vs. "geteilte_referenz") - wichtig, weil eine Korrektur an einer '
                . 'geteilten Referenz auch den Quellkurs veraendert. Reines Lesen ueber die Fragereferenzen: es '
                . 'wird nichts geschrieben, keine idnumber nachgetragen, keine Frage oder Referenz veraendert. Die '
                . 'Anbindung an eine Fragenidentitaet geschieht weiterhin erst beim ersten echten Schreibzugriff '
                . 'auf die einzelne Frage. Geprueft wird die native Moodle-Leseberechtigung im Testkontext '
                . '(moodle/question:viewall), keine eigene Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID des Tests (mod_quiz)'],
                ],
                'required' => ['cmid'],
            ],
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_list_skills' => [
            'function' => 'local_kurspilot_list_skills',
            'classname' => 'local_kurspilot\external\list_skills',
            'wsdescription' => 'Lists the Kurspilot skill corpus shipped with the plugin: name, trigger, kind '
                . '(adapter/reference) and size in characters per entry - catalog only, no content.',
            'description' => 'Listet den mit dem Plugin ausgelieferten Skill-Korpus: je Eintrag Name, Auslöser, '
                . 'Art ("adapter" oder "referenz") und Umfang in Zeichen - kein Inhalt. '
                . dispatcher::HANDSHAKE_INSTRUCTIONS . ' Danach kurspilot_get_skill(name) fuer den eigentlichen Text.',
            'schema' => null,
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_get_skill' => [
            'function' => 'local_kurspilot_get_skill',
            'classname' => 'local_kurspilot\external\get_skill',
            'wsdescription' => 'Delivers one skill corpus entry by name (from kurspilot_list_skills): content, '
                . 'names of referenced parts, and the corpus version. Unknown or path-like names are rejected, the '
                . 'error names the valid names.',
            'description' => 'Liefert einen Eintrag aus dem Skill-Korpus per Name (aus kurspilot_list_skills): '
                . 'Inhalt (Markdown), die Namen darin referenzierter Teile und den Korpus-Stand (Plugin-Version). '
                . 'Ein unbekannter oder pfadartiger Name wird abgewiesen, die Meldung nennt die gueltigen Namen.',
            'schema' => [
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Skill-Name aus kurspilot_list_skills, kein Pfad'],
                ],
                'required' => ['name'],
            ],
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_dismiss_ausstand' => [
            'function' => 'local_kurspilot_dismiss_ausstand',
            'classname' => 'local_kurspilot\external\dismiss_ausstand',
            'wsdescription' => 'Explicitly discards one entry of the calling teacher\'s Ausstandsnotiz (pending-'
                . 'write journal at the context anchor, own working area only) by Kennung.',
            'description' => 'Verwirft einen Eintrag der Ausstandsnotiz ausdruecklich (Kennung aus '
                . 'kurspilot_list_skills, Feld "ausstaende") - fuer Inhalt, der nicht mehr nachgetragen werden soll. '
                . 'Vor dem Verwerfen anbieten, den Inhalt zu rekonstruieren, wo das moeglich ist (z.B. aus dem '
                . 'Aenderungsverlauf einer Aktivitaet). Ein Nachtragen mit "ausstand=<Kennung>" an '
                . 'write_context_file/append_context_file hakt einen Eintrag stattdessen automatisch ab.',
            'schema' => [
                'properties' => [
                    'kennung' => ['type' => 'string', 'description' => 'Kennung des Ausstands, aus kurspilot_list_skills'],
                ],
                'required' => ['kennung'],
            ],
            'capability' => null,
            'write' => true,
        ],
        'kurspilot_create_werkbank_download_links' => [
            'function' => 'local_kurspilot_create_werkbank_download_links',
            'classname' => 'local_kurspilot\external\create_werkbank_download_links',
            'wsdescription' => 'Issues one 15-minute, single-use download ticket per given Werkbank file - a '
                . 'shell client (curl) can then fetch the original bytes without an OAuth bearer header. '
                . 'Read-only, no ready-made retrieval line.',
            'description' => 'Stellt je angegebener Werkbankdatei einen 15 Minuten gueltigen Einmal-Downloadlink '
                . 'aus - ein Client mit Shell (curl) kann die Originalbytes damit abrufen, ohne einen '
                . 'OAuth-Bearer-Header zu setzen, z.B. fuer den Merkzettelpunkt "Werkbank -> Bestand" am Laptop. '
                . 'Liefert je Datei URL, Name, Groesse und SHA-1 - keine fertige Abrufzeile. Rein lesend.',
            'schema' => [
                'properties' => [
                    'paths' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Dateipfade relativ zur Werkbankwurzel, z.B. ["blatt.pdf"]',
                    ],
                ],
                'required' => ['paths'],
            ],
            'capability' => null,
        ],
        'kurspilot_dismiss_altbestand' => [
            'function' => 'local_kurspilot_dismiss_altbestand',
            'classname' => 'local_kurspilot\external\dismiss_altbestand',
            'wsdescription' => 'Explicitly ends the calling teacher\'s Altbestand (legacy holdings at the '
                . 'previous context area location, own working area only).',
            'description' => 'Beendet den Altbestand ausdruecklich (vorheriger Ort des Kontextbereichs nach einem '
                . 'Ortswechsel, aus kurspilot_list_skills als "Altbestand offen" erkennbar) - nach dem Kopieren, '
                . 'oder wenn die Lehrkraft auf den Rest verzichtet. Ruehrt nie an den Dateien des vorherigen Ortes '
                . 'selbst, nur am Merkmal "offen". Kein Parameter - es gibt immer nur einen vorherigen Ort.',
            'schema' => null,
            'capability' => null,
            'write' => true,
        ],
    ];
}
