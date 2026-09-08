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
 * Die einzige Werkzeug-Registrierung (#378, Prefactor vor Spec 0015 Phase 1).
 *
 * Vorher: ein Werkzeug wurde an vier auseinanderlaufenden Stellen eingetragen
 * (db/services.php, dispatcher::TOOL_DESCRIPTIONS, dispatcher::TOOL_SCHEMAS,
 * privacy_surface::ALLOWED_TOOLS). Jetzt: ein Eintrag hier je Werkzeug, die
 * vier Listen werden daraus abgeleitet. Aus Lehrkraft-/Client-Sicht aendert
 * sich nichts - dieselben neun Werkzeuge, dieselben Beschreibungen, dieselben
 * Schemata.
 *
 * Reine Datenstruktur plus reine Ableitungsfunktionen - kein Moodle-Zugriff.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_registry {

    /**
     * Ein Eintrag je Werkzeug: MCP-Toolname => Webservice-Funktionsname,
     * externe Klasse, Moodle-Dienstbeschreibung, MCP-Beschreibung,
     * inputSchema (properties/required) und Capability fuer
     * db/services.php.
     *
     * wsdescription vs. description: unterschiedliche Zielgruppen, nicht
     * dieselbe Angabe zweimal. wsdescription ist Moodles Webservice-
     * Beschreibung (Site administration > Server > Web services, englisch
     * wie die uebrigen Moodle-Kernfunktionen). description ist der
     * MCP-Werkzeugtext, den die Lehrkraft im Client sieht (deutsch, siehe
     * CLAUDE.md: UI-/CLI-sichtbare Strings deutsch).
     *
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
    private const TOOLS = [
        'kurspilot_list_courses' => [
            'function' => 'local_kurspilot_list_courses',
            'classname' => 'local_kurspilot\external\list_courses',
            'wsdescription' => 'Lists the courses the calling teacher may use Kurspilot in.',
            'description' => 'Listet die Moodle-Kurse, in denen die angemeldete Lehrkraft Kurspilot nutzen darf.',
            'schema' => null,
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_get_course_catalog' => [
            'function' => 'local_kurspilot_get_course_catalog',
            'classname' => 'local_kurspilot\external\get_course_catalog',
            'wsdescription' => 'Reads a compact, filterable Moodle course catalog (sections, content, completion, '
                . 'restrictions) for course planning.',
            'description' => 'Liest eine kompakte, filterbare Moodle-Katalogansicht: Abschnitte, sichtbare '
                . 'Inhalte, Teststruktur, Sichtbarkeit, Abschluss und Voraussetzungen. Quelle ist klar als "aus Moodle '
                . 'gelesen" markiert; detail="full" liefert gezielt Vollinhalte, "compact" (Standard) nur eine Vorschau. '
                . 'Eine Beschraenkung auf ein Profilmerkmal (z.B. Fachgruppe) erscheint maskiert: Typ, Feld und Operator '
                . 'bleiben sichtbar, der Wert ist ersetzt. Gruppennamen werden nie geliefert, nur Gruppenmodus und '
                . 'Kennungen (cmid/sectionnum) - eine Gruppierung ist nur dann anzunehmen, wenn die Lehrkraft sie '
                . 'ausdruecklich nennt, niemals erraten.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'sectionnum' => ['type' => 'number', 'description' => 'Abschnittsnummer (0-basiert, -1 = alle Abschnitte)'],
                    'modname' => ['type' => 'string', 'description' => 'Optionaler Aktivitaetstyp-Filter, z.B. page, label, assign, quiz, url'],
                    'detail' => ['type' => 'string', 'enum' => ['compact', 'full'], 'description' => 'compact = Vorschau, full = Vollinhalte'],
                ],
                'required' => ['courseid'],
            ],
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_get_modules' => [
            'function' => 'local_kurspilot_get_modules',
            'classname' => 'local_kurspilot\external\get_modules',
            'wsdescription' => 'Lists the activities of a course or section (cmid, type, name) for targeted access.',
            'description' => 'Gibt alle Aktivitaeten eines Kurses oder Abschnitts zurueck - mit cmid, Typ und '
                . 'Name. Verwenden um cmids fuer gezielte Zugriffe zu ermitteln.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'sectionnum' => ['type' => 'number', 'description' => 'Abschnittsnummer (0-basiert, -1 = alle Abschnitte)'],
                ],
                'required' => ['courseid'],
            ],
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_get_module_settings' => [
            'function' => 'local_kurspilot_get_module_settings',
            'classname' => 'local_kurspilot\external\get_module_settings',
            'wsdescription' => 'Reads the full current state of one activity as get_moduleinfo_data() would '
                . 'return it, for update_module_settings to build a patch on top of.',
            'description' => 'Liefert den vollstaendigen Ist-Stand einer einzelnen Aktivitaet als JSON - '
                . 'dieselbe Form, die eine spaetere Aenderung zuruecknimmt. Kein eigenes Kurspilot-Schema, keine '
                . 'Markdown-Zusammenfassung: die KI liest die rohen Moodle-Feldnamen. coursepagevisibility, '
                . 'visibleoncoursepage und availability_status heissen wie in get_course_catalog/get_modules. '
                . 'Eine Beschraenkung auf ein Profilmerkmal erscheint maskiert: Typ, Feld und Operator bleiben '
                . 'sichtbar, der Wert ist ersetzt. Vor einer Aenderung aufrufen statt eine bestehende Einstellung '
                . 'anzunehmen.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivitaet'],
                ],
                'required' => ['cmid'],
            ],
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_list_activity_versions' => [
            'function' => 'local_kurspilot_list_activity_versions',
            'classname' => 'local_kurspilot\external\list_activity_versions',
            'wsdescription' => 'Lists all recorded versions of an activity with a server-computed, teacher-'
                . 'readable one-line change description against the direct predecessor.',
            'description' => 'Listet alle erfassten Versionen einer Aktivitaet - je Version eine serverseitig '
                . 'aus den Vollstaenden berechnete Lehrkraft-deutsche Zeile gegenueber dem direkten Vorgaenger '
                . '(wer, wann, wodurch). Version 1 ist als "vorgefunden" erkennbar, wenn sie rueckwirkend vor '
                . 'Kurspilot angelegt wurde. Enthaelt einen festen Hinweis auf die strukturellen Luecken des '
                . 'Verlaufs (Quiz-Inhalt jenseits der Anordnung, Notenbuch, Restore, direkte '
                . 'Datenbankschreibungen). Rein lesend.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivitaet'],
                ],
                'required' => ['cmid'],
            ],
            'capability' => 'local/kurspilot:viewhistory',
        ],
        'kurspilot_compare_activity_versions' => [
            'function' => 'local_kurspilot_compare_activity_versions',
            'classname' => 'local_kurspilot\external\compare_activity_versions',
            'wsdescription' => 'Compares two freely chosen recorded versions of an activity - full field and '
                . 'file diff, computed on read, not stored.',
            'description' => 'Vergleicht zwei frei gewaehlte Staende einer Aktivitaet - nicht nur benachbarte. '
                . 'Liefert je unterschiedlichem Feld den Wert im Von- und im Nach-Stand sowie hinzugekommene/'
                . 'weggefallene Dateien. Das Diff wird beim Ansehen berechnet, nicht gespeichert. Enthaelt '
                . 'denselben festen Luecken-Hinweis wie list_activity_versions. Rein lesend.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivitaet'],
                    'von_version' => ['type' => 'number', 'description' => 'Erste zu vergleichende Versionsnummer'],
                    'nach_version' => ['type' => 'number', 'description' => 'Zweite zu vergleichende Versionsnummer'],
                ],
                'required' => ['cmid', 'von_version', 'nach_version'],
            ],
            'capability' => 'local/kurspilot:viewhistory',
        ],
        'kurspilot_restore_activity_version' => [
            'function' => 'local_kurspilot_restore_activity_version',
            'classname' => 'local_kurspilot\external\restore_activity_version',
            'wsdescription' => 'Restores an earlier recorded version of an activity by writing it forward as a new '
                . 'latest version, via update_module_settings/set_completion - no rollback, no duplicate activity.',
            'description' => '„Vor drei Versionen war das besser" - schreibt einen frueheren erfassten Stand als '
                . 'neue juengste Version fort. Kein Rueckspulen, keine Sicherungskopie: die cmid bleibt stabil, es '
                . 'entsteht keine zusaetzliche Aktivitaet, Links und Voraussetzungen auf die Aktivitaet bleiben '
                . 'gueltig. Zurueckgeschrieben wird ausschliesslich ueber update_module_settings/set_completion - '
                . 'kein eigener Schreibmechanismus. Abschlussfelder (completion*) laufen ueber denselben Zweitakt '
                . 'wie set_completion: wuerde das Zurueckschreiben bestehende Abschlussdaten von Lernenden loeschen, '
                . 'meldet der erste Aufruf das (Anzahl betroffener Lernender) und laesst die Abschlussfelder aussen '
                . 'vor - erst ein zweiter Aufruf mit "bestaetigt": true schreibt sie ebenfalls zurueck. Ohne '
                . 'Datenverlustrisiko laufen sie sofort mit durch. Die Antwort nennt Vorher- und Nachher-Wert je '
                . 'tatsaechlich geaendertem Feld. Geprueft wird eine eigene Faehigkeit fuer diese Rueckkehr '
                . 'zusaetzlich zur nativen Moodle-Bearbeiten-Berechtigung im Kurs.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivitaet'],
                    'zielversion' => ['type' => 'number', 'description' => 'Versionsnummer, auf die zurueckgeschrieben werden soll'],
                    'bestaetigt' => [
                        'type' => 'boolean',
                        'description' => 'true bestaetigt ausdruecklich das Loeschen bestehender Abschlussdaten, '
                            . 'falls das Zurueckschreiben der Abschlussfelder das ausloesen wuerde. Beim ersten Aufruf weglassen',
                    ],
                ],
                'required' => ['cmid', 'zielversion'],
            ],
            'capability' => 'local/kurspilot:restoreversion',
            'write' => true,
        ],
        'kurspilot_update_module_settings' => [
            'function' => 'local_kurspilot_update_module_settings',
            'classname' => 'local_kurspilot\external\update_module_settings',
            'wsdescription' => 'Patches individual settings of an existing activity via update_moduleinfo() - '
                . 'only the transmitted fields change, everything else survives untouched.',
            'description' => 'Aendert einzelne Einstellungen einer bestehenden Aktivitaet - ein Patch: nur die '
                . 'uebergebenen Felder aendern sich, alle uebrigen bleiben unangetastet. Vorher get_module_settings '
                . 'aufrufen statt einen Wert zu erraten. Unbekannter Feldname, unerlaubter Wert, gesperrtes Feld '
                . 'oder verletzte Kombinationsregel: nichts wird geschrieben, die Meldung nennt das betroffene Feld '
                . 'und verweist auf describe_module_fields. Die Antwort nennt Vorher- und Nachher-Wert je '
                . 'geaendertem Feld und spricht ausgeloeste Nebenwirkungen ausdruecklich aus (z.B. "Alle '
                . 'Kursteilnehmenden wurden fuer dieses Forum abonniert"). Geprueft wird die native '
                . 'Moodle-Bearbeiten-Berechtigung im Kurs, keine eigene Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivitaet'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt Feldname => neuer Wert, nur die zu aendernden Felder',
                    ],
                ],
                'required' => ['cmid', 'felder_json'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_create_module' => [
            'function' => 'local_kurspilot_create_module',
            'classname' => 'local_kurspilot\external\create_module',
            'wsdescription' => 'Creates a new activity via add_moduleinfo() - missing fields are filled with the '
                . 'catalog\'s FORM default (not the DB default), so a submitted assignment keeps active submission '
                . 'types and an external link keeps its parameters even if the teacher did not name them.',
            'description' => 'Legt eine neue Aktivitaet in einem Abschnitt an. Nicht genannte Felder kommen aus '
                . 'dem Feldkatalog-Formular-Default - eine Aufgabe ohne genannte Abgabe-Einstellungen bekommt '
                . 'trotzdem aktive Abgabemoeglichkeiten, ein externer Link ohne genannte Parameter behaelt sie. '
                . 'Ein Feldbuendel aus describe_module_fields (z.B. "zuteilung") vorher selbst in felder_json '
                . 'mischen - ein Buendelwert gilt nur fuer Felder, die felder_json nicht schon selbst nennt. '
                . '"resource" ist bis Spec 0018 gesperrt (kaputte Seite ohne Hauptdatei) - "folder" bleibt '
                . 'anlegbar. Ein Pflichtfeld ganz ohne Formular-Default muss die Lehrkraft nennen, sonst scheitert '
                . 'das Anlegen mit einer Meldung, die das Feld nennt. Die Antwort nennt jedes tatsaechlich gesetzte '
                . 'Feld mit seinem persistierten Wert und spricht ausgeloeste Nebenwirkungen ausdruecklich aus. '
                . 'Geprueft wird die native Moodle-Bearbeiten-Berechtigung im Kurskontext, keine eigene '
                . 'Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'sectionnum' => ['type' => 'number', 'description' => 'Abschnittsnummer (0-basiert), in die die Aktivitaet kommt'],
                    'modname' => ['type' => 'string', 'description' => 'Aktivitaetstyp, z.B. page, label, url, choice, forum, assign'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt Feldname => Wert - fehlende Felder kommen aus dem Formular-Default; '
                            . 'ein gewaehltes Feldbuendel vorher selbst hineinmischen',
                    ],
                ],
                'required' => ['courseid', 'sectionnum', 'modname', 'felder_json'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_create_quiz' => [
            'function' => 'local_kurspilot_create_quiz',
            'classname' => 'local_kurspilot\external\create_quiz',
            'wsdescription' => 'Creates a mod_quiz activity via add_moduleinfo() where the form path carries, and '
                . 'via Moodle\'s own quiz grade path for "grade" - quiz has its own field catalog and write vehicle '
                . '(Spec 0015 §5) instead of the generic create_module.',
            'description' => 'Legt einen Test (Quiz) an. Ein Modus ("mini-check", "lernstandscheck" oder '
                . '"abschlusstest") nennt die didaktische Absicht statt zwanzig Einzeleinstellungen - die '
                . 'Bedeutung und Einstellungen jedes Modus liefert describe_module_fields(modname: "quiz"). Ein '
                . 'Buendelwert gilt nur fuer Felder, die felder_json nicht bereits selbst nennt. Pflichtfelder ohne '
                . 'Formular-Default muessen genannt werden: "name", "intro", "subnet" (leer = keine '
                . 'Einschraenkung), "browsersecurity" ("-" = keine Einschraenkung). Die maximale Bewertung kommt '
                . 'ueber den eigenen Parameter "grade" (Moodles eigener Bewertungsweg), nicht ueber felder_json - '
                . '"grade"/"sumgrades" sind dort gesperrt. Die Antwort nennt jedes tatsaechlich gesetzte Feld mit '
                . 'seinem persistierten Wert. Geprueft wird die native Moodle-Bearbeiten-Berechtigung im '
                . 'Kurskontext, keine eigene Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'sectionnum' => ['type' => 'number', 'description' => 'Abschnittsnummer (0-basiert), in die der Test kommt'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt Feldname => Wert - fehlende Felder kommen aus dem Formular-Default',
                    ],
                    'mode' => ['type' => 'string', 'enum' => ['mini-check', 'lernstandscheck', 'abschlusstest'], 'description' => 'Optionales Modus-Buendel'],
                    'grade' => ['type' => 'number', 'description' => 'Maximale Bewertung. Weglassen = Moodle-Formular-Default'],
                ],
                'required' => ['courseid', 'sectionnum', 'felder_json'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_update_quiz_settings' => [
            'function' => 'local_kurspilot_update_quiz_settings',
            'classname' => 'local_kurspilot\external\update_quiz_settings',
            'wsdescription' => 'Patches settings of an existing mod_quiz activity via update_moduleinfo() where '
                . 'the form path carries, and via Moodle\'s own quiz grade path for "grade" - the dedicated '
                . 'counterpart to update_module_settings for quiz (Spec 0015 §5).',
            'description' => 'Aendert einzelne Einstellungen eines bestehenden Tests (Quiz) - ein Patch: nur die '
                . 'uebergebenen Felder aendern sich. Ohne "feedbacktext" im Patch bleibt bestehendes Gesamtfeedback '
                . 'erhalten (Moodle wuerde es sonst still loeschen) - genauso fuer Passwort und Review-Einstellungen. '
                . 'Ein Modus-Buendel ("mini-check", "lernstandscheck", "abschlusstest") gilt nur fuer Felder, die '
                . 'felder_json nicht bereits selbst nennt. Die maximale Bewertung kommt ueber den eigenen Parameter '
                . '"grade" (Moodles eigener Bewertungsweg, skaliert Versuchsnoten und Gesamtfeedback-Grenzen '
                . 'automatisch um) - "grade"/"sumgrades" sind in felder_json gesperrt. Unbekannter Feldname, '
                . 'unerlaubter Wert oder verletzte Kombinationsregel: nichts wird geschrieben. Die Antwort nennt '
                . 'Vorher- und Nachher-Wert je geaendertem Feld sowie ausgeloeste Nebenwirkungen (z.B. '
                . 'Kalendereintraege). Die Anordnung (Fragen/Seiten/Abschnitte) ist nicht Teil dieses Werkzeugs. '
                . 'Geprueft wird die native Moodle-Bearbeiten-Berechtigung im Kurskontext, keine eigene '
                . 'Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID des Tests'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt Feldname => neuer Wert, nur die zu aendernden Felder',
                    ],
                    'mode' => ['type' => 'string', 'enum' => ['mini-check', 'lernstandscheck', 'abschlusstest'], 'description' => 'Optionales Modus-Buendel, leer = kein Moduswechsel'],
                    'grade' => ['type' => 'number', 'description' => 'Neue maximale Bewertung. Weglassen = unveraendert'],
                ],
                'required' => ['cmid', 'felder_json'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_set_completion' => [
            'function' => 'local_kurspilot_set_completion',
            'classname' => 'local_kurspilot\external\set_completion',
            'wsdescription' => 'Writes the completion tracking fields of an activity via update_moduleinfo() - '
                . 'the only path for these fields, in a named two-step confirmation when it would delete learner '
                . 'completion data.',
            'description' => 'Setzt die Abschlussverfolgung einer Aktivitaet - "completion" (0=aus, 1=manuell, '
                . '2=automatisch), "completionview", "completionusegrade", "completionpassgrade", '
                . '"completionexpected" und - nur bei "assign" und "choice" - "completionsubmit" (1 = "Abgabe '
                . 'erforderlich" bzw. "Abstimmung abgegeben"; die uebliche Abschlussbedingung einer Aufgabe). '
                . 'Der einzige Schreibweg fuer diese Felder: update_module_settings und '
                . 'create_module sperren sie, weil Moodle sie ohne "completionunlocked" still verwirft und mit '
                . '"completionunlocked" die Abschlussdaten der Lernenden loescht. Wuerde die Aenderung bestehende '
                . 'Abschlussdaten loeschen, meldet der erste Aufruf das (Anzahl betroffener Lernender) und schreibt '
                . 'nichts - erst ein zweiter Aufruf mit "bestaetigt": true fuehrt aus. Ohne Datenverlustrisiko '
                . '(keine vorhandenen Daten, oder nur "completionexpected" geaendert) laeuft der Aufruf sofort '
                . 'durch. Geprueft wird die native Moodle-Bearbeiten-Berechtigung im Kurskontext, keine eigene '
                . 'Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivitaet'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt mit "completion", "completionview", "completionusegrade", '
                            . '"completionpassgrade", "completionexpected" und/oder (nur assign/choice) '
                            . '"completionsubmit" - nur die zu aendernden Felder',
                    ],
                    'bestaetigt' => [
                        'type' => 'boolean',
                        'description' => 'true bestaetigt ausdruecklich das Loeschen bestehender Abschlussdaten '
                            . '(zweiter Aufruf des Zweitakts). Beim ersten Aufruf weglassen',
                    ],
                ],
                'required' => ['cmid', 'felder_json'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_set_restriction' => [
            'function' => 'local_kurspilot_set_restriction',
            'classname' => 'local_kurspilot\external\set_restriction',
            'wsdescription' => 'Writes an activity\'s availability restriction via update_moduleinfo(), built from '
                . 'teacher-understandable arguments instead of raw JSON.',
            'description' => 'Setzt Voraussetzungen einer Aktivitaet ("erst nach bestandenem Lerncheck", "ab '
                . 'Datum X", "nur Gruppe Y") aus lehrkraftverstaendlichen Argumenten - kein rohes '
                . 'Verfuegbarkeits-JSON. "bedingungen_json" ist ein JSON-Array; leer entfernt alle Voraussetzungen, '
                . 'mehrere Eintraege muessen alle gleichzeitig erfuellt sein. Je Eintrag "typ": "abschluss" '
                . '(Felder "aktivitaet_cmid", "status": abgeschlossen|nicht_abgeschlossen|bestanden|'
                . 'nicht_bestanden), "datum" (Felder "richtung": ab|bis, "zeitstempel": Unix-Zeit) oder "gruppe" '
                . '(Feld "gruppen_id", weglassen = beliebige Gruppe). Eine ungueltige Bedingung scheitert mit einer '
                . 'Meldung, die das betroffene Feld nennt - nichts wird geschrieben, die Kursseite bleibt '
                . 'aufrufbar. Geprueft wird die native Moodle-Bearbeiten-Berechtigung im Kurskontext, keine eigene '
                . 'Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivitaet'],
                    'bedingungen_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Array von Voraussetzungen (leer = alle entfernen), siehe Werkzeugbeschreibung',
                    ],
                ],
                'required' => ['cmid', 'bedingungen_json'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_ensure_section' => [
            'function' => 'local_kurspilot_ensure_section',
            'classname' => 'local_kurspilot\external\ensure_section',
            'wsdescription' => 'Idempotently creates a section if it is missing (course_create_sections_if_missing()) '
                . 'or, if it already exists, only reconciles its name.',
            'description' => 'Legt einen Kursabschnitt an, falls die Abschnittsnummer noch nicht existiert - ein '
                . 'erneuter Aufruf mit derselben Nummer erzeugt keinen zweiten Abschnitt. Existiert der Abschnitt '
                . 'bereits, wird ausschließlich der Name abgeglichen, sonst nichts. Geprüft wird die native '
                . 'Moodle-Bearbeiten-Berechtigung im Kurskontext.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'sectionnum' => ['type' => 'number', 'description' => 'Abschnittsnummer (0-basiert)'],
                    'name' => ['type' => 'string', 'description' => 'Optionaler Abschnittsname'],
                ],
                'required' => ['courseid', 'sectionnum'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_update_section' => [
            'function' => 'local_kurspilot_update_section',
            'classname' => 'local_kurspilot\external\update_section',
            'wsdescription' => 'Patches name, summary and/or visibility of an existing section via '
                . 'course_update_section() - only the transmitted fields change.',
            'description' => 'Ändert Name, Zusammenfassung und/oder Sichtbarkeit eines bestehenden Abschnitts - ein '
                . 'Patch: nur die übergebenen Felder ändern sich. Ein auf unsichtbar geschalteter Abschnitt macht '
                . 'alle enthaltenen Aktivitäten unsichtbar, unabhängig von deren eigener Sichtbarkeitseinstellung - '
                . 'die Antwort spricht das ausdrücklich aus. Geprüft wird die native Moodle-Bearbeiten-Berechtigung '
                . 'im Kurskontext.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'sectionnum' => ['type' => 'number', 'description' => 'Abschnittsnummer (0-basiert)'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt mit "name", "summary" und/oder "visible" (0|1) - nur die genannten Felder ändern sich',
                    ],
                ],
                'required' => ['courseid', 'sectionnum', 'felder_json'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_move_section' => [
            'function' => 'local_kurspilot_move_section',
            'classname' => 'local_kurspilot\external\move_section',
            'wsdescription' => 'Moves a section to another position in the course via the core_courseformat '
                . 'command bus (stateactions::section_move_after()).',
            'description' => 'Verschiebt einen Kursabschnitt an eine andere Position, damit die Reihenfolge dem '
                . 'Lernpfad folgt. Der allgemeine Abschnitt (0) kann nicht verschoben werden. Geprüft wird die '
                . 'native Moodle-Berechtigung zum Verschieben von Abschnitten im Kurskontext.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'sourcesectionnum' => ['type' => 'number', 'description' => 'Aktuelle Abschnittsnummer'],
                    'targetsectionnum' => ['type' => 'number', 'description' => 'Gewünschte Abschnittsnummer nach der Verschiebung'],
                ],
                'required' => ['courseid', 'sourcesectionnum', 'targetsectionnum'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_move_module' => [
            'function' => 'local_kurspilot_move_module',
            'classname' => 'local_kurspilot\external\move_module',
            'wsdescription' => 'Moves an activity to another section and/or position via the core_courseformat '
                . 'command bus (stateactions::cm_move()).',
            'description' => 'Verschiebt eine Aktivität in einen (anderen) Abschnitt, optional an eine bestimmte '
                . 'Position darin - ohne Positionsangabe ans Ende des Zielabschnitts. Geprüft wird die native '
                . 'Moodle-Bearbeiten-Berechtigung im Kurskontext.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der zu verschiebenden Aktivität'],
                    'sectionnum' => ['type' => 'number', 'description' => 'Zielabschnittsnummer (0-basiert)'],
                    'position' => ['type' => 'number', 'description' => 'Optionaler 0-basierter Zielindex im Zielabschnitt; ohne Angabe ans Ende'],
                ],
                'required' => ['cmid', 'sectionnum'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_get_sections' => [
            'function' => 'local_kurspilot_get_sections',
            'classname' => 'local_kurspilot\external\get_sections',
            'wsdescription' => 'Lists the sections of a course (id, number, name) for targeted access.',
            'description' => 'Gibt alle Abschnitte eines Moodle-Kurses zurueck (Name, Nummer, ID).',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Die Kurs-ID (steht in der URL: ?id=XX)'],
                ],
                'required' => ['courseid'],
            ],
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_ensure_question_bank' => [
            'function' => 'local_kurspilot_ensure_question_bank',
            'classname' => 'local_kurspilot\external\ensure_question_bank',
            'wsdescription' => 'Creates a named question bank activity in a course or reuses an existing one '
                . 'with the same name - idempotent, a repeated call never creates a second bank.',
            'description' => 'Legt eine benannte Fragensammlung (Fragenbank-Aktivitaet) im Kurs an oder '
                . 'verwendet eine gleichnamige bestehende wieder - idempotent, ein zweiter Aufruf mit demselben '
                . 'Namen erzeugt keine zweite Bank. Die Antwort nennt Bank-ID (questionbankid), Kontext-ID, die '
                . 'oberste Kategorie (topcategoryid, Startpunkt fuer ensure_question_category) sowie "angelegt": '
                . 'true/false, damit ein Tippfehler im Namen auffaellt statt still eine zweite Bank zu erzeugen. '
                . 'Geprueft wird die native Moodle-Bearbeiten-Berechtigung im Kurskontext, keine eigene '
                . 'Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'name' => ['type' => 'string', 'description' => 'Name der Fragensammlung, z.B. "Biologie 9a - Immunsystem"'],
                ],
                'required' => ['courseid', 'name'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_ensure_question_category' => [
            'function' => 'local_kurspilot_ensure_question_category',
            'classname' => 'local_kurspilot\external\ensure_question_category',
            'wsdescription' => 'Finds an existing question category by name under a given parent category, or '
                . 'creates it - idempotent, combines the local search-then-create pair into one call.',
            'description' => 'Findet eine gleichnamige Fragenbank-Kategorie unter derselben Elternkategorie oder '
                . 'legt sie an - idempotent, ein zweiter Aufruf mit demselben Namen/Elternteil erzeugt keine '
                . 'zweite Kategorie. "parent" ist die ID einer bestehenden Kategorie, z.B. die topcategoryid aus '
                . 'ensure_question_bank fuer eine Kategorie direkt unter der Fragensammlung, oder eine zuvor '
                . 'angelegte Unterkategorie fuer verschachtelte Kategorien. Eine gleichnamige Kategorie unter '
                . 'einer anderen Elternkategorie zaehlt nicht als Treffer. Die Antwort nennt "angelegt": true/false, '
                . 'damit ein Tippfehler im Namen auffaellt statt still eine zweite Kategorie zu erzeugen. Geprueft '
                . 'wird die native Moodle-Berechtigung zum Verwalten von Fragenbank-Kategorien im Kontext der '
                . 'Elternkategorie, keine eigene Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Kategoriename, Konvention: "<Abschnittsnummer> <Titel>", z.B. "7.2 Stoffe und ihre Eigenschaften"'],
                    'parent' => ['type' => 'number', 'description' => 'ID der Elternkategorie (z.B. topcategoryid aus ensure_question_bank)'],
                ],
                'required' => ['name', 'parent'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_update_question_category' => [
            'function' => 'local_kurspilot_update_question_category',
            'classname' => 'local_kurspilot\external\update_question_category',
            'wsdescription' => 'Renames and/or moves a question category subtree without touching questions or '
                . 'versions - creation is done exclusively via ensure_question_category.',
            'description' => 'Benennt eine Fragenbank-Kategorie um und/oder haengt sie unter eine andere '
                . 'Elternkategorie - Fragen und ihre Versionen bleiben unangetastet. Legt niemals neu an (dafuer '
                . 'ist ensure_question_category da). "name" leer laesst den Namen unveraendert, "parent" 0 laesst '
                . 'die Elternkategorie unveraendert. Verschiebt der Aufruf in eine andere Fragensammlung, wandert '
                . 'der gesamte Unterbaum mit. Die oberste Kategorie einer Fragensammlung kann nicht umbenannt oder '
                . 'verschoben werden, ebenso wenig in eine ihrer eigenen Unterkategorien. Geprueft wird die native '
                . 'Moodle-Berechtigung zum Verwalten von Fragenbank-Kategorien im Kontext der Quell- (und ggf. '
                . 'Ziel-)Kategorie, keine eigene Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'categoryid' => ['type' => 'number', 'description' => 'ID der zu aendernden Kategorie'],
                    'name' => ['type' => 'string', 'description' => 'Neuer Kategoriename (leer = Name behalten)'],
                    'parent' => ['type' => 'number', 'description' => 'ID der neuen Elternkategorie (0 = Elternteil behalten)'],
                ],
                'required' => ['categoryid'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_move_question' => [
            'function' => 'local_kurspilot_move_question',
            'classname' => 'local_kurspilot\external\move_question',
            'wsdescription' => 'Moves a question bank entry with all its versions into another category, gating '
                . 'an idnumber collision in the target category before the move instead of letting the core '
                . 'silently suffix it.',
            'description' => 'Verschiebt eine Frage samt aller Versionen in eine andere Fragenbank-Kategorie - '
                . 'die questionbankentryid bleibt dabei unveraendert. Gibt es in der Zielkategorie bereits einen '
                . 'Eintrag mit derselben idnumber, wird NICHTS verschoben ("status": "verdachtsfall"); die '
                . 'Antwort nennt die idnumber, die Zielkategorie, den nahen Kandidaten sowie dessen und den '
                . 'eigenen Fragetext zum Vergleich. Erst ein erneuter Aufruf mit "bestaetigt": true fuehrt den '
                . 'Umzug trotzdem aus - Moodle haengt der idnumber dann einen Zahlen-Suffix an, statt sie still zu '
                . 'verlieren. Geprueft wird die native Moodle-Berechtigung zum Anlegen von Fragen im '
                . 'Zielkategorie-Kontext, keine eigene Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'questionid' => ['type' => 'number', 'description' => 'questionid einer beliebigen Version der zu verschiebenden Frage'],
                    'targetcategoryid' => ['type' => 'number', 'description' => 'ID der Ziel-Fragenbank-Kategorie'],
                    'bestaetigt' => ['type' => 'boolean', 'description' => 'true bestaetigt einen zuvor gemeldeten Verdachtsfall (idnumber-Kollision) und verschiebt trotzdem'],
                ],
                'required' => ['questionid', 'targetcategoryid'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_create_mc_question' => [
            'function' => 'local_kurspilot_create_mc_question',
            'classname' => 'local_kurspilot\external\create_mc_question',
            'wsdescription' => 'Creates a multiple-choice question (qtype_multichoice) from plain typed fields - '
                . 'builds the XML server-side from a fixed template and writes it via import_questions_xml, '
                . 'including the round-trip check and rollback. The AI never writes XML for multiple-choice.',
            'description' => 'Legt eine Multiple-Choice-Frage aus schlichten Feldern an - die Lehrkraft sieht nie '
                . 'XML. Der Server baut die XML serverseitig aus einer festen Vorlage und schreibt sie ueber '
                . 'denselben Kern wie import_questions_xml (inkl. Round-Trip-Pruefung und Rollback). Die neue '
                . 'Frage bekommt eine generierte, stabile idnumber. Gibt es in der Zielkategorie bereits einen '
                . 'gleichnamigen Eintrag, wird NICHTS angelegt ("status": "verdachtsfall") - eine Neuanlage bringt '
                . 'nie eine idnumber mit, gegen die gematcht werden koennte, deshalb zaehlt hier bereits der Name '
                . 'als Verdachtsfall. Erst ein erneuter Aufruf mit "bestaetigt": true legt die Frage trotzdem als '
                . 'neuen Eintrag an. Die Antwort nennt den Bank-Eintrag (questionbankentryid) und die '
                . 'Versionsnummer (initial 1). Geprueft wird die native Moodle-Berechtigung zum Anlegen von Fragen '
                . 'im Kategorie-Kontext, keine eigene Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'categoryid' => ['type' => 'number', 'description' => 'ID der Ziel-Fragenbank-Kategorie'],
                    'name' => ['type' => 'string', 'description' => 'Eindeutiger Name der Frage innerhalb der Kategorie'],
                    'questiontext' => ['type' => 'string', 'description' => 'Fragetext (HTML)'],
                    'selectionmode' => ['type' => 'string', 'description' => '"single" oder "multiple"'],
                    'answers' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'answer' => ['type' => 'string', 'description' => 'Antworttext (HTML)'],
                                'fraction' => ['type' => 'number', 'description' => 'Gewicht zwischen -1 und 1'],
                                'feedback' => ['type' => 'string', 'description' => 'Antwortspezifisches Feedback (HTML)'],
                            ],
                        ],
                        'description' => 'Antwortoptionen, mindestens 2, positive fractions summieren zu genau 1',
                    ],
                    'defaultmark' => ['type' => 'number', 'description' => 'Standard-Punktzahl der Frage (Default 1.0)'],
                    'generalfeedback' => ['type' => 'string', 'description' => 'Allgemeines Feedback (HTML, optional)'],
                    'bestaetigt' => [
                        'type' => 'boolean',
                        'description' => 'true bestaetigt einen zuvor gemeldeten Verdachtsfall (gleichnamiger '
                            . 'Eintrag) und legt die Frage trotzdem als neuen Eintrag an',
                    ],
                ],
                'required' => ['categoryid', 'name', 'questiontext', 'selectionmode', 'answers'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_update_mc_question' => [
            'function' => 'local_kurspilot_update_mc_question',
            'classname' => 'local_kurspilot\external\update_mc_question',
            'wsdescription' => 'Patches a multiple-choice question by read-modify-write: reads the current '
                . 'question, overwrites only the given fields, and writes the FULL state back via '
                . 'import_questions_xml - fields not mentioned in the patch are preserved unchanged. Backfills a '
                . 'missing idnumber on exactly this one question, on first write.',
            'description' => 'Aendert einzelne Felder einer bestehenden Multiple-Choice-Frage, ohne die uebrigen '
                . 'zu verlieren: liest die Frage zuerst aus, ueberschreibt nur die in felder_json genannten Felder '
                . '(Patch, kein Vollstand) und schreibt den Vollstand ueber denselben Kern wie '
                . 'import_questions_xml zurueck (inkl. Round-Trip-Pruefung und Rollback). Das Ergebnis ist eine '
                . 'neue Version DESSELBEN Bank-Eintrags, kein neuer Eintrag. Hat die vorgefundene Frage noch keine '
                . 'idnumber (z.B. aus einem Fremdbestand), wird beim ersten Schreibzugriff genau fuer DIESE eine '
                . 'Frage eine generiert - kein Massenlauf ueber Kategorie oder Fragenbank. Geprueft wird die '
                . 'native Moodle-Berechtigung zum Anlegen von Fragen im Kategorie-Kontext, keine eigene '
                . 'Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'questionid' => ['type' => 'number', 'description' => 'questionid einer beliebigen Version der zu aendernden Frage'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt Feldname => neuer Wert - nur die zu aendernden Felder '
                            . '(Patch, kein Vollstand). Erlaubt: name, questiontext, selectionmode, answers '
                            . '(Liste mit answer/fraction/feedback), defaultmark, generalfeedback.',
                    ],
                    'bestaetigt' => ['type' => 'boolean', 'description' => 'true bestaetigt einen zuvor gemeldeten Verdachtsfall des XML-Kerns und schreibt trotzdem'],
                ],
                'required' => ['questionid', 'felder_json'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_import_questions_xml' => [
            'function' => 'local_kurspilot_import_questions_xml',
            'classname' => 'local_kurspilot\external\import_questions_xml',
            'wsdescription' => 'Imports Moodle-XML questions of any type version-safely via '
                . 'question_type::save_question() with a round-trip check in the same transaction - '
                . 'importprocess() is not used.',
            'description' => 'Importiert Moodle-XML-Fragen beliebigen Typs (auch STACK, oder Exporte aus anderen '
                . 'Moodle-Instanzen) - der Kern, ueber den auch die MC-Fassaden schreiben. Nach dem Schreiben '
                . 'liest der Server die frisch angelegte Frage in derselben Transaktion wieder aus und vergleicht '
                . 'ihre Kernfelder (Name, idnumber, Fragetext, Antwortoptionen mit Bruchteilen, Feedbacktexte, '
                . 'allgemeines Feedback) mit der Eingabe - weicht etwas ab oder fliegt eine Ausnahme, wird '
                . 'zurueckgerollt: nichts landet in der Fragenbank. Ein ungueltiges XML bricht den gesamten Aufruf '
                . 'ab, kein Teilergebnis. Traegt die mitgebrachte idnumber bereits ein Eintrag der Zielkategorie, '
                . 'wird eine neue Version desselben Bank-Eintrags geschrieben - Quiz-Slots auf "immer aktuellste '
                . 'Version" folgen automatisch. Fehlt die idnumber ganz, ist es ein echter Erstimport mit '
                . 'generierter idnumber. Bringt das XML eine idnumber mit, die in der Zielkategorie keinen '
                . 'Treffer hat, ist das ein Verdachtsfall ("status": "verdachtsfall") - nichts wird geschrieben, '
                . 'die Antwort nennt die idnumber, die Zielkategorie und nahe (gleichnamige) Kandidaten. Erst ein '
                . 'erneuter Aufruf mit "bestaetigt": true legt die Frage trotzdem als neuen Eintrag an. Geprueft '
                . 'wird die native Moodle-Berechtigung zum Anlegen von Fragen im Kategorie-Kontext, keine eigene '
                . 'Kurspilot-Schreibrechte. Zwei Tueren fuer eingebettete Dateien (genau eine je Aufruf): '
                . 'xmlcontent - <file>-Bloecke tragen ein material="<materialordner-pfad>"-Attribut statt echtem '
                . 'Base64, der Server loest es auf; xmlpath - Verweis auf eine XML-Datei im Materialordner mit '
                . 'echtem Base64 in ihren <file>-Bloecken (Massenimport eines fremden Exports), rein serverseitig '
                . 'verarbeitet, kein Bildbyte passiert den Kontext.',
            'schema' => [
                'properties' => [
                    'categoryid' => ['type' => 'number', 'description' => 'ID der Ziel-Fragenbank-Kategorie'],
                    'xmlcontent' => [
                        'type' => 'string',
                        'description' => 'Textuer: Moodle-XML-Fragenexport als Text - vollstaendig, mit '
                            . 'umschliessendem <quiz>-Element (ein nackter <question>-Block ist nicht '
                            . 'importierbar), hoechstens 5 MB. <file>-Bloecke tragen statt echtem Base64 ein '
                            . 'material="<materialordner-pfad>"-Attribut. Genau eins von xmlcontent/xmlpath '
                            . 'angeben.',
                    ],
                    'xmlpath' => [
                        'type' => 'string',
                        'description' => 'Verweistuer: Pfad einer XML-Datei im Materialordner, z.B. "export.xml" - '
                            . 'fuer Massenimporte mit echtem Base64 in <file>-Bloecken. Genau eins von '
                            . 'xmlcontent/xmlpath angeben.',
                    ],
                    'bestaetigt' => [
                        'type' => 'boolean',
                        'description' => 'true bestaetigt einen zuvor gemeldeten Verdachtsfall (idnumber ohne '
                            . 'Treffer in der Zielkategorie) und legt die Frage trotzdem als neuen Eintrag an',
                    ],
                ],
                'required' => ['categoryid'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_export_questions_xml' => [
            'function' => 'local_kurspilot_export_questions_xml',
            'classname' => 'local_kurspilot\external\export_questions_xml',
            'wsdescription' => 'Exports one or more questions as a complete, standards-compliant Moodle XML file '
                . '(real base64 in <file> blocks) written to the material folder - the response names only the '
                . 'path, no image byte returned. Optional placeholder switch (platzhalter=true) returns the old '
                . 'inline XML with named comment placeholders instead of files, for template purposes only.',
            'description' => 'Liest eine oder mehrere bestehende Fragen als Moodle-XML - derselbe Formatter, den '
                . 'auch der XML-Kern fuer die Round-Trip-Pruefung nutzt. Standard-Modus (Default): die '
                . 'vollstaendige, standardkonforme XML - mit echtem Base64 in <file>-Bloecken - wird unter '
                . '"targetpath" in den Materialordner geschrieben, die Antwort nennt nur den Pfad, kein Bildbyte '
                . 'passiert den Kontext. Diese Datei ist in jedes andere Moodle importierbar (Weitergabe an eine '
                . 'Kollegin) und ueber die Verweistuer von import_questions_xml wieder einlesbar (Rundlauf). '
                . 'Platzhalter-Modus (platzhalter=true): liefert wie bisher die XML direkt in der Antwort, '
                . 'eingebettete Dateien durch einen benannten Kommentar-Platzhalter ersetzt - NICHT zur '
                . 'Weitergabe geeignet, nur um sich selbst eine Vorlage aus dem eigenen Bestand zu holen (Struktur '
                . 'lernen, nicht 400 KB Bild). Geprueft wird die native Moodle-Leseberechtigung im Kategoriekontext '
                . 'jeder Frage (moodle/question:viewall); im Standard-Modus zusaetzlich das Schreibrecht auf den '
                . 'eigenen Materialordner (moodle/user:manageownfiles).',
            'schema' => [
                'properties' => [
                    'questionids' => [
                        'type' => 'array',
                        'items' => ['type' => 'number'],
                        'description' => 'questionid je Frage (beliebige Version, mindestens eine)',
                    ],
                    'targetpath' => [
                        'type' => 'string',
                        'description' => 'Materialordner-Pfad der zu schreibenden XML-Datei, z.B. "export.xml" - '
                            . 'Pflicht im Standard-Modus, ignoriert im Platzhalter-Modus',
                    ],
                    'platzhalter' => [
                        'type' => 'boolean',
                        'description' => 'true: wie bisher XML mit Platzhaltern direkt in der Antwort statt '
                            . 'Dateischreibvorgang - NICHT zur Weitergabe geeignet, nur Vorlagenzweck. Default false.',
                    ],
                ],
                'required' => ['questionids'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_get_question_categories' => [
            'function' => 'local_kurspilot_get_question_categories',
            'classname' => 'local_kurspilot\external\get_question_categories',
            'wsdescription' => 'Lists the question bank categories of a named question bank, for reuse instead of '
                . 'duplication.',
            'description' => 'Listet alle Fragenbank-Kategorien der ausgewaehlten benannten '
                . 'Kurs-/Projekt-Fragensammlung (inkl. der Top-Kategorie) mit id, Name und uebergeordneter '
                . 'Kategorie-ID - fuer Wiederverwendung statt Doppelanlage.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'questionbankid' => ['type' => 'number', 'description' => 'ID der benannten Fragensammlung (CMID)'],
                ],
                'required' => ['courseid', 'questionbankid'],
            ],
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_get_question' => [
            'function' => 'local_kurspilot_get_question',
            'classname' => 'local_kurspilot\external\get_question',
            'wsdescription' => 'Reads the latest version of a single question, identified by name or questionid.',
            'description' => 'Liefert die latest version einer Frage in einer Kategorie - eindeutig '
                . 'identifiziert per Name ODER per questionid. Vor einer Bearbeitung aufrufen, um die aktuelle '
                . 'questionid und questionbankentryid zu kennen.',
            'schema' => [
                'properties' => [
                    'categoryid' => ['type' => 'number', 'description' => 'ID der Fragenbank-Kategorie'],
                    'name' => ['type' => 'string', 'description' => 'Name der Frage (alternativ zu questionid)'],
                    'questionid' => ['type' => 'number', 'description' => 'questionid einer beliebigen Version der Frage (alternativ zu name)'],
                ],
                'required' => ['categoryid'],
            ],
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_plan_quiz_cleanup' => [
            'function' => 'local_kurspilot_get_quiz_cleanup_plan',
            'classname' => 'local_kurspilot\external\get_quiz_cleanup_plan',
            'wsdescription' => 'Builds a manual, non-destructive cleanup plan for obsolete quiz slots - names '
                . 'findings and links, deletes nothing.',
            'description' => 'Plant eine manuelle Bereinigung, wenn eine neue Quizversion weniger '
                . 'Fragen enthaelt. Kurspilot loescht weder Quiz-Slots noch Fragen: Die Antwort nennt jeden '
                . 'betroffenen Slot, Frage und Kategorie sowie den direkten Moodle-Link. Dort nur aus dem Quiz '
                . 'entfernen, nicht aus der Fragensammlung loeschen; die Fragen bleiben wiederverwendbar.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID des Quiz'],
                    'keep_questionbankentryids' => [
                        'type' => 'array',
                        'items' => ['type' => 'number'],
                        'description' => 'questionbankentryid-Werte, die in der neuen Quizversion verbleiben; alle anderen Slots werden ausschliesslich als manuelle Schritte ausgegeben.',
                    ],
                ],
                'required' => ['cmid', 'keep_questionbankentryids'],
            ],
            'capability' => 'local/kurspilot:use',
        ],
        'kurspilot_add_questions_to_quiz' => [
            'function' => 'local_kurspilot_add_questions_to_quiz',
            'classname' => 'local_kurspilot\external\add_questions_to_quiz',
            'wsdescription' => 'Appends questions to a quiz in the given order via quiz_add_quiz_question() - '
                . 'a question already in the quiz (matched by questionbankentryid) is skipped, not duplicated. '
                . 'Refuses entirely if the quiz already has attempts.',
            'description' => 'Haengt Fragen in der genannten Reihenfolge an einen Test an. Eine Frage, die schon '
                . 'im Test steckt (gleicher Bank-Eintrag), wird uebersprungen statt doppelt eingefuegt - die '
                . 'Antwort weist das je Frage aus ("added": false). Die Antwort nennt zusaetzlich den entstandenen '
                . 'Slot-Stand mit Bank-Eintrag, aktuellster Fragen-Version und Versionsnummer je Slot, damit sich '
                . 'der Test pruefen laesst, ohne ihn zu oeffnen. Gibt es im Test bereits Versuche, wird GAR NICHTS '
                . 'geaendert - kein Teilerfolg, keine halb gefuellte Slot-Liste. Entfernen, Umsortieren und '
                . 'Seitenumbrueche sind nicht Teil dieses Werkzeugs (Moodle-Oberflaeche). Geprueft wird die native '
                . 'Moodle-Bearbeiten-Berechtigung des Tests sowie die Nutzungsberechtigung je Frage, keine eigene '
                . 'Kurspilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID des Tests'],
                    'questionids' => [
                        'type' => 'array',
                        'items' => ['type' => 'number'],
                        'description' => 'questionid je Frage (beliebige Version), in der Reihenfolge des Anhaengens, mindestens eine',
                    ],
                ],
                'required' => ['cmid', 'questionids'],
            ],
            'capability' => 'local/kurspilot:use',
            'write' => true,
        ],
        'kurspilot_get_version_info' => [
            'function' => 'local_kurspilot_get_version_info',
            'classname' => 'local_kurspilot\external\get_version_info',
            'wsdescription' => 'Reports the Moodle release/version/branch and the Kurspilot plugin version and '
                . 'release, plus the server date.',
            'description' => 'Liefert den Versionsstand der Instanz: Moodle-Release, -Versionsstempel und -Zweig '
                . 'sowie Version und Release des Kurspilot-Plugins, dazu das Serverdatum. Damit wird der Kopf der '
                . 'Fragetyp-Ablage ("Moodle-Version", "Plugin-Version", "zuletzt verifiziert am") gefuellt, und '
                . 'Support-Rueckfragen lassen sich beantworten, ohne die Lehrkraft nach Versionsnummern zu fragen. '
                . 'Weicht die in der Datenbank eingetragene Plugin-Version von der der laufenden Dateien ab, wird '
                . 'das in der Meldung genannt (fehlender upgrade.php-Lauf). Rein lesend.',
            'schema' => null,
            'capability' => null,
        ],
        'kurspilot_list_context_files' => [
            'function' => 'local_kurspilot_list_context_files',
            'classname' => 'local_kurspilot\external\list_context_files',
            'wsdescription' => 'Lists the calling teacher\'s Kurspilot context area (own working area only).',
            'description' => 'Listet den eigenen Kontextbereich der angemeldeten Lehrkraft auf '
                . '(Lerngruppenprofile, Fachprofile, gemerkte Vorlagen). "path" waehlt optional einen Unterordner, leer '
                . 'liefert die Wurzel. Nur der eigene Bereich der aufrufenden Person ist erreichbar.',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Optionaler Unterordner, leer fuer die Wurzel'],
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
                . 'Schreiben ist ueber dieses Werkzeug nicht moeglich.',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Dateipfad relativ zur Wurzel, z.B. "vorlagen.md"'],
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
                . 'ob die Datei neu angelegt oder ueberschrieben wurde.',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Dateipfad relativ zur Wurzel, nur .md, z.B. "plan.md"'],
                    'content' => ['type' => 'string', 'description' => 'Vollstaendiger neuer Dateiinhalt, hoechstens 1 MB'],
                    'expected_contenthash' => [
                        'type' => 'string',
                        'description' => 'Optional: contenthash aus dem letzten Lesen - passt er nicht, bricht der Vorgang ab',
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
                . 'Rotation (neues Journalarchiv anlegen).',
            'schema' => [
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Dateipfad relativ zur Wurzel, nur .md, z.B. "journal.md"'],
                    'content' => ['type' => 'string', 'description' => 'Anzuhaengender Inhalt, hoechstens 1 MB'],
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
    ];

    /**
     * MCP-Toolname => Webservice-Funktionsname (privacy_surface::ALLOWED_TOOLS).
     *
     * @return array<string, string>
     */
    public static function allowed_tools(): array {
        return array_map(static fn (array $tool): string => $tool['function'], self::TOOLS);
    }

    /**
     * MCP-Toolname => Beschreibung (dispatcher::TOOL_DESCRIPTIONS).
     *
     * @return array<string, string>
     */
    public static function descriptions(): array {
        return array_map(static fn (array $tool): string => $tool['description'], self::TOOLS);
    }

    /**
     * MCP-Toolname => inputSchema-Properties, nur wo vorhanden
     * (dispatcher::TOOL_SCHEMAS).
     *
     * @return array<string, array{properties: array, required?: array}>
     */
    public static function schemas(): array {
        $out = [];
        foreach (self::TOOLS as $name => $tool) {
            if ($tool['schema'] !== null) {
                $out[$name] = $tool['schema'];
            }
        }
        return $out;
    }

    /**
     * $functions-Array fuer db/services.php.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function service_functions(): array {
        $functions = [];
        foreach (self::TOOLS as $tool) {
            $entry = [
                'classname' => $tool['classname'],
                'description' => $tool['wsdescription'],
                'type' => ($tool['write'] ?? false) ? 'write' : 'read',
                'ajax' => false,
            ];
            if ($tool['capability'] !== null) {
                $entry['capabilities'] = $tool['capability'];
            }
            $functions[$tool['function']] = $entry;
        }
        return $functions;
    }

    /**
     * Ist dieses Werkzeug ein Schreibzugriff (#388, Protokollstufen-Schwelle
     * in {@see \local_kurspilot\access_log::log_success()})?
     *
     * @param string $toolname
     * @return bool
     */
    public static function is_write(string $toolname): bool {
        return self::TOOLS[$toolname]['write'] ?? false;
    }

    /**
     * Webservice-Funktionsnamen in Registrierungsreihenfolge, fuer
     * $services['Kurspilot']['functions'] in db/services.php.
     *
     * @return string[]
     */
    public static function service_function_names(): array {
        return array_column(self::TOOLS, 'function');
    }
}
