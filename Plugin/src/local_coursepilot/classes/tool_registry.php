<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

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
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class tool_registry {

    /**
     * Ein Eintrag je Werkzeug: MCP-Toolname => Webservice-Funktionsname,
     * externe Klasse, Moodle-Dienstbeschreibung, MCP-Beschreibung,
     * inputSchema (properties/required) und Capability für
     * db/services.php.
     *
     * wsdescription vs. description: unterschiedliche Zielgruppen, nicht
     * dieselbe Angabe zweimal. wsdescription ist Moodles Webservice-
     * Beschreibung (Site administration > Server > Web services, englisch
     * wie die uebrigen Moodle-Kernfunktionen). description ist der
     * MCP-Werkzeugtext, den die Lehrkraft im Client sieht (deutsch, siehe
     * CLAUDE.md: UI-/CLI-sichtbare Strings deutsch).
     *
     * Erster (Kurs/Quiz/Fragen) von zwei Teilen (Issue #509, Vorab-Umbau vor
     * Spec #486-Review): diese Datei lag über 1000 Zeilen, ein reiner
     * Zeilengrenzen-Schnitt, keine Verhaltensänderung. Der zweite Teil
     * (Kontextbereich, Materialbestand, Klonen, Skills, Ausstand,
     * Altbestand) liegt in {@see tool_registry_context_tools::TOOLS};
     * {@see all()} fügt beide zusammen.
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
    private const CORE_TOOLS = [
        'coursepilot_list_courses' => [
            'function' => 'local_coursepilot_list_courses',
            'classname' => 'local_coursepilot\external\list_courses',
            'wsdescription' => 'Lists the courses the calling teacher may use Coursepilot in.',
            'description' => 'Listet die Moodle-Kurse, in denen die angemeldete Lehrkraft Coursepilot nutzen darf.',
            'schema' => null,
            'capability' => 'local/coursepilot:use',
        ],
        'coursepilot_get_course_catalog' => [
            'function' => 'local_coursepilot_get_course_catalog',
            'classname' => 'local_coursepilot\external\get_course_catalog',
            'wsdescription' => 'Reads a compact, filterable Moodle course catalog (sections, content, completion, '
                . 'restrictions) for course planning.',
            'description' => 'Liest eine kompakte, filterbare Moodle-Katalogansicht: Abschnitte, sichtbare '
                . 'Inhalte, Teststruktur, Sichtbarkeit, Abschluss und Voraussetzungen. Quelle ist klar als "aus Moodle '
                . 'gelesen" markiert; detail="full" liefert gezielt Vollinhalte, "compact" (Standard) nur eine Vorschau. '
                . 'Eine Beschraenkung auf ein Profilmerkmal (z.B. Fachgruppe) erscheint maskiert: Typ, Feld und Operator '
                . 'bleiben sichtbar, der Wert ist ersetzt. Gruppennamen werden nie geliefert, nur Gruppenmodus und '
                . 'Kennungen (cmid/sectionnum) - eine Gruppierung ist nur dann anzunehmen, wenn die Lehrkraft sie '
                . 'ausdrücklich nennt, niemals erraten.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'sectionnum' => ['type' => 'number', 'description' => 'Abschnittsnummer (0-basiert, -1 = alle Abschnitte)'],
                    'modname' => ['type' => 'string', 'description' => 'Optionaler Aktivitätstyp-Filter, z.B. page, label, assign, quiz, url'],
                    'detail' => ['type' => 'string', 'enum' => ['compact', 'full'], 'description' => 'compact = Vorschau, full = Vollinhalte'],
                ],
                'required' => ['courseid'],
            ],
            'capability' => 'local/coursepilot:use',
        ],
        'coursepilot_get_modules' => [
            'function' => 'local_coursepilot_get_modules',
            'classname' => 'local_coursepilot\external\get_modules',
            'wsdescription' => 'Lists the activities of a course or section (cmid, type, name) for targeted access.',
            'description' => 'Gibt alle Aktivitaeten eines Kurses oder Abschnitts zurück - mit cmid, Typ und '
                . 'Name. Verwenden um cmids für gezielte Zugriffe zu ermitteln.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'sectionnum' => ['type' => 'number', 'description' => 'Abschnittsnummer (0-basiert, -1 = alle Abschnitte)'],
                ],
                'required' => ['courseid'],
            ],
            'capability' => 'local/coursepilot:use',
        ],
        'coursepilot_get_module_settings' => [
            'function' => 'local_coursepilot_get_module_settings',
            'classname' => 'local_coursepilot\external\get_module_settings',
            'wsdescription' => 'Reads the full current state of one activity as get_moduleinfo_data() would '
                . 'return it, for update_module_settings to build a patch on top of.',
            'description' => 'Liefert den vollstaendigen Ist-Stand einer einzelnen Aktivität als JSON - '
                . 'dieselbe Form, die eine spaetere Aenderung zuruecknimmt. Kein eigenes Coursepilot-Schema, keine '
                . 'Markdown-Zusammenfassung: die KI liest die rohen Moodle-Feldnamen. coursepagevisibility, '
                . 'visibleoncoursepage und availability_status heissen wie in get_course_catalog/get_modules. '
                . 'Eine Beschraenkung auf ein Profilmerkmal erscheint maskiert: Typ, Feld und Operator bleiben '
                . 'sichtbar, der Wert ist ersetzt. Vor einer Aenderung aufrufen statt eine bestehende Einstellung '
                . 'anzunehmen.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivität'],
                ],
                'required' => ['cmid'],
            ],
            'capability' => 'local/coursepilot:use',
        ],
        'coursepilot_list_activity_versions' => [
            'function' => 'local_coursepilot_list_activity_versions',
            'classname' => 'local_coursepilot\external\list_activity_versions',
            'wsdescription' => 'Lists all recorded versions of an activity with a server-computed, teacher-'
                . 'readable one-line change description against the direct predecessor.',
            'description' => 'Listet alle erfassten Versionen einer Aktivität - je Version eine serverseitig '
                . 'aus den Vollstaenden berechnete Lehrkraft-deutsche Zeile gegenueber dem direkten Vorgaenger '
                . '(wer, wann, wodurch). Version 1 ist als "vorgefunden" erkennbar, wenn sie rueckwirkend vor '
                . 'Coursepilot angelegt wurde. Enthaelt einen festen Hinweis auf die strukturellen Luecken des '
                . 'Verlaufs (Quiz-Inhalt jenseits der Anordnung, Notenbuch, Restore, direkte '
                . 'Datenbankschreibungen). Rein lesend.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivität'],
                ],
                'required' => ['cmid'],
            ],
            'capability' => 'local/coursepilot:viewhistory',
        ],
        'coursepilot_compare_activity_versions' => [
            'function' => 'local_coursepilot_compare_activity_versions',
            'classname' => 'local_coursepilot\external\compare_activity_versions',
            'wsdescription' => 'Compares two freely chosen recorded versions of an activity - full field and '
                . 'file diff, computed on read, not stored.',
            'description' => 'Vergleicht zwei frei gewaehlte Staende einer Aktivität - nicht nur benachbarte. '
                . 'Liefert je unterschiedlichem Feld den Wert im Von- und im Nach-Stand sowie hinzugekommene/'
                . 'weggefallene Dateien. Das Diff wird beim Ansehen berechnet, nicht gespeichert. Enthaelt '
                . 'denselben festen Luecken-Hinweis wie list_activity_versions. Rein lesend.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivität'],
                    'von_version' => ['type' => 'number', 'description' => 'Erste zu vergleichende Versionsnummer'],
                    'nach_version' => ['type' => 'number', 'description' => 'Zweite zu vergleichende Versionsnummer'],
                ],
                'required' => ['cmid', 'von_version', 'nach_version'],
            ],
            'capability' => 'local/coursepilot:viewhistory',
        ],
        'coursepilot_restore_activity_version' => [
            'function' => 'local_coursepilot_restore_activity_version',
            'classname' => 'local_coursepilot\external\restore_activity_version',
            'wsdescription' => 'Restores an earlier recorded version of an activity by writing it forward as a new '
                . 'latest version, via update_module_settings/set_completion - no rollback, no duplicate activity.',
            'description' => '„Vor drei Versionen war das besser" - schreibt einen frueheren erfassten Stand als '
                . 'neue juengste Version fort. Kein Rueckspulen, keine Sicherungskopie: die cmid bleibt stabil, es '
                . 'entsteht keine zusaetzliche Aktivität, Links und Voraussetzungen auf die Aktivität bleiben '
                . 'gueltig. Zurueckgeschrieben wird ausschliesslich über update_module_settings/set_completion - '
                . 'kein eigener Schreibmechanismus. Abschlussfelder (completion*) laufen über denselben Zweitakt '
                . 'wie set_completion: wuerde das Zurueckschreiben bestehende Abschlussdaten von Lernenden löschen, '
                . 'meldet der erste Aufruf das (Anzahl betroffener Lernender) und laesst die Abschlussfelder aussen '
                . 'vor - erst ein zweiter Aufruf mit "bestaetigt": true schreibt sie ebenfalls zurück. Ohne '
                . 'Datenverlustrisiko laufen sie sofort mit durch. Die Antwort nennt Vorher- und Nachher-Wert je '
                . 'tatsächlich geaendertem Feld. Geprüft wird eine eigene Faehigkeit für diese Rueckkehr '
                . 'zusätzlich zur nativen Moodle-Bearbeiten-Berechtigung im Kurs.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivität'],
                    'zielversion' => ['type' => 'number', 'description' => 'Versionsnummer, auf die zurueckgeschrieben werden soll'],
                    'bestaetigt' => [
                        'type' => 'boolean',
                        'description' => 'true bestaetigt ausdrücklich das Löschen bestehender Abschlussdaten, '
                            . 'falls das Zurueckschreiben der Abschlussfelder das ausloesen wuerde. Beim ersten Aufruf weglassen',
                    ],
                ],
                'required' => ['cmid', 'zielversion'],
            ],
            'capability' => 'local/coursepilot:restoreversion',
            'write' => true,
        ],
        'coursepilot_update_module_settings' => [
            'function' => 'local_coursepilot_update_module_settings',
            'classname' => 'local_coursepilot\external\update_module_settings',
            'wsdescription' => 'Patches individual settings of an existing activity via update_moduleinfo() - '
                . 'only the transmitted fields change, everything else survives untouched.',
            'description' => 'Aendert einzelne Einstellungen einer bestehenden Aktivität - ein Patch: nur die '
                . 'uebergebenen Felder aendern sich, alle uebrigen bleiben unangetastet. Vorher get_module_settings '
                . 'aufrufen statt einen Wert zu erraten. Unbekannter Feldname, unerlaubter Wert, gesperrtes Feld '
                . 'oder verletzte Kombinationsregel: nichts wird geschrieben, die Meldung nennt das betroffene Feld '
                . 'und verweist auf describe_module_fields. Die Antwort nennt Vorher- und Nachher-Wert je '
                . 'geaendertem Feld und spricht ausgeloeste Nebenwirkungen ausdrücklich aus (z.B. "Alle '
                . 'Kursteilnehmenden wurden für dieses Forum abonniert"). Geprüft wird die native '
                . 'Moodle-Bearbeiten-Berechtigung im Kurs, keine eigene Coursepilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivität'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt Feldname => neuer Wert, nur die zu aendernden Felder',
                    ],
                ],
                'required' => ['cmid', 'felder_json'],
            ],
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_create_module' => [
            'function' => 'local_coursepilot_create_module',
            'classname' => 'local_coursepilot\external\create_module',
            'wsdescription' => 'Creates a new activity via add_moduleinfo() - missing fields are filled with the '
                . 'catalog\'s FORM default (not the DB default), so a submitted assignment keeps active submission '
                . 'types and an external link keeps its parameters even if the teacher did not name them.',
            'description' => 'Legt eine neue Aktivität in einem Abschnitt an. Nicht genannte Felder kommen aus '
                . 'dem Feldkatalog-Formular-Default - eine Aufgabe ohne genannte Abgabe-Einstellungen bekommt '
                . 'trotzdem aktive Abgabemoeglichkeiten, ein externer Link ohne genannte Parameter behaelt sie. '
                . 'Ein Feldbündel aus describe_module_fields (z.B. "zuteilung") vorher selbst in felder_json '
                . 'mischen - ein Buendelwert gilt nur für Felder, die felder_json nicht schon selbst nennt. '
                . '"resource" ist bis Spec 0018 gesperrt (kaputte Seite ohne Hauptdatei) - "folder" bleibt '
                . 'anlegbar. Ein Pflichtfeld ganz ohne Formular-Default muss die Lehrkraft nennen, sonst scheitert '
                . 'das Anlegen mit einer Meldung, die das Feld nennt. Die Antwort nennt jedes tatsächlich gesetzte '
                . 'Feld mit seinem persistierten Wert und spricht ausgeloeste Nebenwirkungen ausdrücklich aus. '
                . 'Geprüft wird die native Moodle-Bearbeiten-Berechtigung im Kurskontext, keine eigene '
                . 'Coursepilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'sectionnum' => ['type' => 'number', 'description' => 'Abschnittsnummer (0-basiert), in die die Aktivität kommt'],
                    'modname' => ['type' => 'string', 'description' => 'Aktivitätstyp, z.B. page, label, url, choice, forum, assign'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt Feldname => Wert - fehlende Felder kommen aus dem Formular-Default; '
                            . 'ein gewaehltes Feldbündel vorher selbst hineinmischen',
                    ],
                ],
                'required' => ['courseid', 'sectionnum', 'modname', 'felder_json'],
            ],
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_create_quiz' => [
            'function' => 'local_coursepilot_create_quiz',
            'classname' => 'local_coursepilot\external\create_quiz',
            'wsdescription' => 'Creates a mod_quiz activity via add_moduleinfo() where the form path carries, and '
                . 'via Moodle\'s own quiz grade path for "grade" - quiz has its own field catalog and write vehicle '
                . '(Spec 0015 §5) instead of the generic create_module.',
            'description' => 'Legt einen Test (Quiz) an. Ein Modus ("mini-check", "lernstandscheck" oder '
                . '"abschlusstest") nennt die didaktische Absicht statt zwanzig Einzeleinstellungen - die '
                . 'Bedeutung und Einstellungen jedes Modus liefert describe_module_fields(modname: "quiz"). Ein '
                . 'Buendelwert gilt nur für Felder, die felder_json nicht bereits selbst nennt. Pflichtfelder ohne '
                . 'Formular-Default muessen genannt werden: "name", "intro", "subnet" (leer = keine '
                . 'Einschraenkung), "browsersecurity" ("-" = keine Einschraenkung). Die maximale Bewertung kommt '
                . 'über den eigenen Parameter "grade" (Moodles eigener Bewertungsweg), nicht über felder_json - '
                . '"grade"/"sumgrades" sind dort gesperrt. Die Antwort nennt jedes tatsächlich gesetzte Feld mit '
                . 'seinem persistierten Wert. Geprüft wird die native Moodle-Bearbeiten-Berechtigung im '
                . 'Kurskontext, keine eigene Coursepilot-Schreibrechte.',
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
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_update_quiz_settings' => [
            'function' => 'local_coursepilot_update_quiz_settings',
            'classname' => 'local_coursepilot\external\update_quiz_settings',
            'wsdescription' => 'Patches settings of an existing mod_quiz activity via update_moduleinfo() where '
                . 'the form path carries, and via Moodle\'s own quiz grade path for "grade" - the dedicated '
                . 'counterpart to update_module_settings for quiz (Spec 0015 §5).',
            'description' => 'Aendert einzelne Einstellungen eines bestehenden Tests (Quiz) - ein Patch: nur die '
                . 'uebergebenen Felder aendern sich. Ohne "feedbacktext" im Patch bleibt bestehendes Gesamtfeedback '
                . 'erhalten (Moodle wuerde es sonst still löschen) - genauso für Passwort und Review-Einstellungen. '
                . 'Ein Modus-Buendel ("mini-check", "lernstandscheck", "abschlusstest") gilt nur für Felder, die '
                . 'felder_json nicht bereits selbst nennt. Die maximale Bewertung kommt über den eigenen Parameter '
                . '"grade" (Moodles eigener Bewertungsweg, skaliert Versuchsnoten und Gesamtfeedback-Grenzen '
                . 'automatisch um) - "grade"/"sumgrades" sind in felder_json gesperrt. Unbekannter Feldname, '
                . 'unerlaubter Wert oder verletzte Kombinationsregel: nichts wird geschrieben. Die Antwort nennt '
                . 'Vorher- und Nachher-Wert je geaendertem Feld sowie ausgeloeste Nebenwirkungen (z.B. '
                . 'Kalendereintraege). Die Anordnung (Fragen/Seiten/Abschnitte) ist nicht Teil dieses Werkzeugs. '
                . 'Geprüft wird die native Moodle-Bearbeiten-Berechtigung im Kurskontext, keine eigene '
                . 'Coursepilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID des Tests'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt Feldname => neuer Wert, nur die zu aendernden Felder',
                    ],
                    'mode' => ['type' => 'string', 'enum' => ['mini-check', 'lernstandscheck', 'abschlusstest'], 'description' => 'Optionales Modus-Buendel, leer = kein Moduswechsel'],
                    'grade' => ['type' => 'number', 'description' => 'Neue maximale Bewertung. Weglassen = unverändert'],
                ],
                'required' => ['cmid', 'felder_json'],
            ],
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_set_completion' => [
            'function' => 'local_coursepilot_set_completion',
            'classname' => 'local_coursepilot\external\set_completion',
            'wsdescription' => 'Writes the completion tracking fields of an activity via update_moduleinfo() - '
                . 'the only path for these fields, in a named two-step confirmation when it would delete learner '
                . 'completion data.',
            'description' => 'Setzt die Abschlussverfolgung einer Aktivität - "completion" (0=aus, 1=manuell, '
                . '2=automatisch), "completionview", "completionusegrade", "completionpassgrade", '
                . '"completionexpected" und - nur bei "assign" und "choice" - "completionsubmit" (1 = "Abgabe '
                . 'erforderlich" bzw. "Abstimmung abgegeben"; die uebliche Abschlussbedingung einer Aufgabe). '
                . 'Der einzige Schreibweg für diese Felder: update_module_settings und '
                . 'create_module sperren sie, weil Moodle sie ohne "completionunlocked" still verwirft und mit '
                . '"completionunlocked" die Abschlussdaten der Lernenden löscht. Wuerde die Aenderung bestehende '
                . 'Abschlussdaten löschen, meldet der erste Aufruf das (Anzahl betroffener Lernender) und schreibt '
                . 'nichts - erst ein zweiter Aufruf mit "bestaetigt": true führt aus. Ohne Datenverlustrisiko '
                . '(keine vorhandenen Daten, oder nur "completionexpected" geaendert) läuft der Aufruf sofort '
                . 'durch. Geprüft wird die native Moodle-Bearbeiten-Berechtigung im Kurskontext, keine eigene '
                . 'Coursepilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivität'],
                    'felder_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Objekt mit "completion", "completionview", "completionusegrade", '
                            . '"completionpassgrade", "completionexpected" und/oder (nur assign/choice) '
                            . '"completionsubmit" - nur die zu aendernden Felder',
                    ],
                    'bestaetigt' => [
                        'type' => 'boolean',
                        'description' => 'true bestaetigt ausdrücklich das Löschen bestehender Abschlussdaten '
                            . '(zweiter Aufruf des Zweitakts). Beim ersten Aufruf weglassen',
                    ],
                ],
                'required' => ['cmid', 'felder_json'],
            ],
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_set_restriction' => [
            'function' => 'local_coursepilot_set_restriction',
            'classname' => 'local_coursepilot\external\set_restriction',
            'wsdescription' => 'Writes an activity\'s availability restriction via update_moduleinfo(), built from '
                . 'teacher-understandable arguments instead of raw JSON.',
            'description' => 'Setzt Voraussetzungen einer Aktivität ("erst nach bestandenem Lerncheck", "ab '
                . 'Datum X", "nur Gruppe Y") aus lehrkraftverstaendlichen Argumenten - kein rohes '
                . 'Verfuegbarkeits-JSON. "bedingungen_json" ist ein JSON-Array; leer entfernt alle Voraussetzungen, '
                . 'mehrere Einträge muessen alle gleichzeitig erfuellt sein. Je Eintrag "typ": "abschluss" '
                . '(Felder "aktivitaet_cmid", "status": abgeschlossen|nicht_abgeschlossen|bestanden|'
                . 'nicht_bestanden), "datum" (Felder "richtung": ab|bis, "zeitstempel": Unix-Zeit) oder "gruppe" '
                . '(Feld "gruppen_id", weglassen = beliebige Gruppe). Eine ungueltige Bedingung scheitert mit einer '
                . 'Meldung, die das betroffene Feld nennt - nichts wird geschrieben, die Kursseite bleibt '
                . 'aufrufbar. Geprüft wird die native Moodle-Bearbeiten-Berechtigung im Kurskontext, keine eigene '
                . 'Coursepilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'cmid' => ['type' => 'number', 'description' => 'Course module ID der Aktivität'],
                    'bedingungen_json' => [
                        'type' => 'string',
                        'description' => 'JSON-Array von Voraussetzungen (leer = alle entfernen), siehe Werkzeugbeschreibung',
                    ],
                ],
                'required' => ['cmid', 'bedingungen_json'],
            ],
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_ensure_section' => [
            'function' => 'local_coursepilot_ensure_section',
            'classname' => 'local_coursepilot\external\ensure_section',
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
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_update_section' => [
            'function' => 'local_coursepilot_update_section',
            'classname' => 'local_coursepilot\external\update_section',
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
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_move_section' => [
            'function' => 'local_coursepilot_move_section',
            'classname' => 'local_coursepilot\external\move_section',
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
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_move_module' => [
            'function' => 'local_coursepilot_move_module',
            'classname' => 'local_coursepilot\external\move_module',
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
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_get_sections' => [
            'function' => 'local_coursepilot_get_sections',
            'classname' => 'local_coursepilot\external\get_sections',
            'wsdescription' => 'Lists the sections of a course (id, number, name) for targeted access.',
            'description' => 'Gibt alle Abschnitte eines Moodle-Kurses zurück (Name, Nummer, ID).',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Die Kurs-ID (steht in der URL: ?id=XX)'],
                ],
                'required' => ['courseid'],
            ],
            'capability' => 'local/coursepilot:use',
        ],
        'coursepilot_ensure_question_bank' => [
            'function' => 'local_coursepilot_ensure_question_bank',
            'classname' => 'local_coursepilot\external\ensure_question_bank',
            'wsdescription' => 'Creates a named question bank activity in a course or reuses an existing one '
                . 'with the same name - idempotent, a repeated call never creates a second bank.',
            'description' => 'Legt eine benannte Fragensammlung (Fragenbank-Aktivität) im Kurs an oder '
                . 'verwendet eine gleichnamige bestehende wieder - idempotent, ein zweiter Aufruf mit demselben '
                . 'Namen erzeugt keine zweite Bank. Die Antwort nennt Bank-ID (questionbankid), Kontext-ID, die '
                . 'oberste Kategorie (topcategoryid, Startpunkt für ensure_question_category) sowie "angelegt": '
                . 'true/false, damit ein Tippfehler im Namen auffällt statt still eine zweite Bank zu erzeugen. '
                . 'Geprüft wird die native Moodle-Bearbeiten-Berechtigung im Kurskontext, keine eigene '
                . 'Coursepilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'name' => ['type' => 'string', 'description' => 'Name der Fragensammlung, z.B. "Biologie 9a - Immunsystem"'],
                ],
                'required' => ['courseid', 'name'],
            ],
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_ensure_question_category' => [
            'function' => 'local_coursepilot_ensure_question_category',
            'classname' => 'local_coursepilot\external\ensure_question_category',
            'wsdescription' => 'Finds an existing question category by name under a given parent category, or '
                . 'creates it - idempotent, combines the local search-then-create pair into one call.',
            'description' => 'Findet eine gleichnamige Fragenbank-Kategorie unter derselben Elternkategorie oder '
                . 'legt sie an - idempotent, ein zweiter Aufruf mit demselben Namen/Elternteil erzeugt keine '
                . 'zweite Kategorie. "parent" ist die ID einer bestehenden Kategorie, z.B. die topcategoryid aus '
                . 'ensure_question_bank für eine Kategorie direkt unter der Fragensammlung, oder eine zuvor '
                . 'angelegte Unterkategorie für verschachtelte Kategorien. Eine gleichnamige Kategorie unter '
                . 'einer anderen Elternkategorie zaehlt nicht als Treffer. Die Antwort nennt "angelegt": true/false, '
                . 'damit ein Tippfehler im Namen auffällt statt still eine zweite Kategorie zu erzeugen. Geprüft '
                . 'wird die native Moodle-Berechtigung zum Verwalten von Fragenbank-Kategorien im Kontext der '
                . 'Elternkategorie, keine eigene Coursepilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Kategoriename, Konvention: "<Abschnittsnummer> <Titel>", z.B. "7.2 Stoffe und ihre Eigenschaften"'],
                    'parent' => ['type' => 'number', 'description' => 'ID der Elternkategorie (z.B. topcategoryid aus ensure_question_bank)'],
                ],
                'required' => ['name', 'parent'],
            ],
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_update_question_category' => [
            'function' => 'local_coursepilot_update_question_category',
            'classname' => 'local_coursepilot\external\update_question_category',
            'wsdescription' => 'Renames and/or moves a question category subtree without touching questions or '
                . 'versions - creation is done exclusively via ensure_question_category.',
            'description' => 'Benennt eine Fragenbank-Kategorie um und/oder hängt sie unter eine andere '
                . 'Elternkategorie - Fragen und ihre Versionen bleiben unangetastet. Legt niemals neu an (dafür '
                . 'ist ensure_question_category da). "name" leer laesst den Namen unverändert, "parent" 0 laesst '
                . 'die Elternkategorie unverändert. Verschiebt der Aufruf in eine andere Fragensammlung, wandert '
                . 'der gesamte Unterbaum mit. Die oberste Kategorie einer Fragensammlung kann nicht umbenannt oder '
                . 'verschoben werden, ebenso wenig in eine ihrer eigenen Unterkategorien. Geprüft wird die native '
                . 'Moodle-Berechtigung zum Verwalten von Fragenbank-Kategorien im Kontext der Quell- (und ggf. '
                . 'Ziel-)Kategorie, keine eigene Coursepilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'categoryid' => ['type' => 'number', 'description' => 'ID der zu aendernden Kategorie'],
                    'name' => ['type' => 'string', 'description' => 'Neuer Kategoriename (leer = Name behalten)'],
                    'parent' => ['type' => 'number', 'description' => 'ID der neuen Elternkategorie (0 = Elternteil behalten)'],
                ],
                'required' => ['categoryid'],
            ],
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_move_question' => [
            'function' => 'local_coursepilot_move_question',
            'classname' => 'local_coursepilot\external\move_question',
            'wsdescription' => 'Moves a question bank entry with all its versions into another category, gating '
                . 'an idnumber collision in the target category before the move instead of letting the core '
                . 'silently suffix it.',
            'description' => 'Verschiebt eine Frage samt aller Versionen in eine andere Fragenbank-Kategorie - '
                . 'die questionbankentryid bleibt dabei unverändert. Gibt es in der Zielkategorie bereits einen '
                . 'Eintrag mit derselben idnumber, wird NICHTS verschoben ("status": "verdachtsfall"); die '
                . 'Antwort nennt die idnumber, die Zielkategorie, den nahen Kandidaten sowie dessen und den '
                . 'eigenen Fragetext zum Vergleich. Erst ein erneuter Aufruf mit "bestaetigt": true führt den '
                . 'Umzug trotzdem aus - Moodle hängt der idnumber dann einen Zahlen-Suffix an, statt sie still zu '
                . 'verlieren. Geprüft wird die native Moodle-Berechtigung zum Anlegen von Fragen im '
                . 'Zielkategorie-Kontext, keine eigene Coursepilot-Schreibrechte.',
            'schema' => [
                'properties' => [
                    'questionid' => ['type' => 'number', 'description' => 'questionid einer beliebigen Version der zu verschiebenden Frage'],
                    'targetcategoryid' => ['type' => 'number', 'description' => 'ID der Ziel-Fragenbank-Kategorie'],
                    'bestaetigt' => ['type' => 'boolean', 'description' => 'true bestaetigt einen zuvor gemeldeten Verdachtsfall (idnumber-Kollision) und verschiebt trotzdem'],
                ],
                'required' => ['questionid', 'targetcategoryid'],
            ],
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_create_mc_question' => [
            'function' => 'local_coursepilot_create_mc_question',
            'classname' => 'local_coursepilot\external\create_mc_question',
            'wsdescription' => 'Creates a multiple-choice question (qtype_multichoice) from plain typed fields - '
                . 'builds the XML server-side from a fixed template and writes it via import_questions_xml, '
                . 'including the round-trip check and rollback. The AI never writes XML for multiple-choice.',
            'description' => 'Legt eine Multiple-Choice-Frage aus schlichten Feldern an - die Lehrkraft sieht nie '
                . 'XML. Der Server baut die XML serverseitig aus einer festen Vorlage und schreibt sie über '
                . 'denselben Kern wie import_questions_xml (inkl. Round-Trip-Pruefung und Rollback). Die neue '
                . 'Frage bekommt eine generierte, stabile idnumber. Gibt es in der Zielkategorie bereits einen '
                . 'gleichnamigen Eintrag, wird NICHTS angelegt ("status": "verdachtsfall") - eine Neuanlage bringt '
                . 'nie eine idnumber mit, gegen die gematcht werden koennte, deshalb zaehlt hier bereits der Name '
                . 'als Verdachtsfall. Erst ein erneuter Aufruf mit "bestaetigt": true legt die Frage trotzdem als '
                . 'neuen Eintrag an. Die Antwort nennt den Bank-Eintrag (questionbankentryid) und die '
                . 'Versionsnummer (initial 1). Geprüft wird die native Moodle-Berechtigung zum Anlegen von Fragen '
                . 'im Kategorie-Kontext, keine eigene Coursepilot-Schreibrechte.',
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
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_update_mc_question' => [
            'function' => 'local_coursepilot_update_mc_question',
            'classname' => 'local_coursepilot\external\update_mc_question',
            'wsdescription' => 'Patches a multiple-choice question by read-modify-write: reads the current '
                . 'question, overwrites only the given fields, and writes the FULL state back via '
                . 'import_questions_xml - fields not mentioned in the patch are preserved unchanged. Backfills a '
                . 'missing idnumber on exactly this one question, on first write.',
            'description' => 'Aendert einzelne Felder einer bestehenden Multiple-Choice-Frage, ohne die uebrigen '
                . 'zu verlieren: liest die Frage zuerst aus, überschreibt nur die in felder_json genannten Felder '
                . '(Patch, kein Vollstand) und schreibt den Vollstand über denselben Kern wie '
                . 'import_questions_xml zurück (inkl. Round-Trip-Pruefung und Rollback). Das Ergebnis ist eine '
                . 'neue Version DESSELBEN Bank-Eintrags, kein neuer Eintrag. Hat die vorgefundene Frage noch keine '
                . 'idnumber (z.B. aus einem Fremdbestand), wird beim ersten Schreibzugriff genau für DIESE eine '
                . 'Frage eine generiert - kein Massenlauf über Kategorie oder Fragenbank. Geprüft wird die '
                . 'native Moodle-Berechtigung zum Anlegen von Fragen im Kategorie-Kontext, keine eigene '
                . 'Coursepilot-Schreibrechte.',
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
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_import_questions_xml' => [
            'function' => 'local_coursepilot_import_questions_xml',
            'classname' => 'local_coursepilot\external\import_questions_xml',
            'wsdescription' => 'Imports Moodle-XML questions of any type version-safely via '
                . 'question_type::save_question() with a round-trip check in the same transaction - '
                . 'importprocess() is not used.',
            'description' => 'Importiert Moodle-XML-Fragen beliebigen Typs (auch STACK, oder Exporte aus anderen '
                . 'Moodle-Instanzen) - der Kern, über den auch die MC-Fassaden schreiben. Nach dem Schreiben '
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
                . 'erneuter Aufruf mit "bestaetigt": true legt die Frage trotzdem als neuen Eintrag an. Geprüft '
                . 'wird die native Moodle-Berechtigung zum Anlegen von Fragen im Kategorie-Kontext, keine eigene '
                . 'Coursepilot-Schreibrechte. Zwei Tueren für eingebettete Dateien (genau eine je Aufruf): '
                . 'xmlcontent - <file>-Bloecke tragen ein material="<materialordner-pfad>"-Attribut statt echtem '
                . 'Base64, der Server loest es auf; xmlpath - Verweis auf eine XML-Datei im Materialordner mit '
                . 'echtem Base64 in ihren <file>-Bloecken (Massenimport eines fremden Exports), rein serverseitig '
                . 'verarbeitet, kein Bildbyte passiert den Kontext.',
            'schema' => [
                'properties' => [
                    'categoryid' => ['type' => 'number', 'description' => 'ID der Ziel-Fragenbank-Kategorie'],
                    'xmlcontent' => [
                        'type' => 'string',
                        'description' => 'Textuer: Moodle-XML-Fragenexport als Text - vollständig, mit '
                            . 'umschliessendem <quiz>-Element (ein nackter <question>-Block ist nicht '
                            . 'importierbar), höchstens 5 MB. <file>-Bloecke tragen statt echtem Base64 ein '
                            . 'material="<materialordner-pfad>"-Attribut. Genau eins von xmlcontent/xmlpath '
                            . 'angeben.',
                    ],
                    'xmlpath' => [
                        'type' => 'string',
                        'description' => 'Verweistuer: Pfad einer XML-Datei im Materialordner, z.B. "export.xml" - '
                            . 'für Massenimporte mit echtem Base64 in <file>-Bloecken. Genau eins von '
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
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_export_questions_xml' => [
            'function' => 'local_coursepilot_export_questions_xml',
            'classname' => 'local_coursepilot\external\export_questions_xml',
            'wsdescription' => 'Exports one or more questions as a complete, standards-compliant Moodle XML file '
                . '(real base64 in <file> blocks) written to the material folder - the response names only the '
                . 'path, no image byte returned. Optional placeholder switch (platzhalter=true) returns the old '
                . 'inline XML with named comment placeholders instead of files, for template purposes only.',
            'description' => 'Liest eine oder mehrere bestehende Fragen als Moodle-XML - derselbe Formatter, den '
                . 'auch der XML-Kern für die Round-Trip-Pruefung nutzt. Standard-Modus (Default): die '
                . 'vollständige, standardkonforme XML - mit echtem Base64 in <file>-Bloecken - wird unter '
                . '"targetpath" in den Materialordner geschrieben, die Antwort nennt nur den Pfad, kein Bildbyte '
                . 'passiert den Kontext. Diese Datei ist in jedes andere Moodle importierbar (Weitergabe an eine '
                . 'Kollegin) und über die Verweistuer von import_questions_xml wieder einlesbar (Rundlauf). '
                . 'Platzhalter-Modus (platzhalter=true): liefert wie bisher die XML direkt in der Antwort, '
                . 'eingebettete Dateien durch einen benannten Kommentar-Platzhalter ersetzt - NICHT zur '
                . 'Weitergabe geeignet, nur um sich selbst eine Vorlage aus dem eigenen Bestand zu holen (Struktur '
                . 'lernen, nicht 400 KB Bild). Geprüft wird die native Moodle-Leseberechtigung im Kategoriekontext '
                . 'jeder Frage (moodle/question:viewall); im Standard-Modus zusätzlich das Schreibrecht auf den '
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
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_get_question_categories' => [
            'function' => 'local_coursepilot_get_question_categories',
            'classname' => 'local_coursepilot\external\get_question_categories',
            'wsdescription' => 'Lists the question bank categories of a named question bank, for reuse instead of '
                . 'duplication.',
            'description' => 'Listet alle Fragenbank-Kategorien der ausgewaehlten benannten '
                . 'Kurs-/Projekt-Fragensammlung (inkl. der Top-Kategorie) mit id, Name und uebergeordneter '
                . 'Kategorie-ID - für Wiederverwendung statt Doppelanlage.',
            'schema' => [
                'properties' => [
                    'courseid' => ['type' => 'number', 'description' => 'Kurs-ID'],
                    'questionbankid' => ['type' => 'number', 'description' => 'ID der benannten Fragensammlung (CMID)'],
                ],
                'required' => ['courseid', 'questionbankid'],
            ],
            'capability' => 'local/coursepilot:use',
        ],
        'coursepilot_get_question' => [
            'function' => 'local_coursepilot_get_question',
            'classname' => 'local_coursepilot\external\get_question',
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
            'capability' => 'local/coursepilot:use',
        ],
        'coursepilot_plan_quiz_cleanup' => [
            'function' => 'local_coursepilot_get_quiz_cleanup_plan',
            'classname' => 'local_coursepilot\external\get_quiz_cleanup_plan',
            'wsdescription' => 'Builds a manual, non-destructive cleanup plan for obsolete quiz slots - names '
                . 'findings and links, deletes nothing.',
            'description' => 'Plant eine manuelle Bereinigung, wenn eine neue Quizversion weniger '
                . 'Fragen enthaelt. Coursepilot löscht weder Quiz-Slots noch Fragen: Die Antwort nennt jeden '
                . 'betroffenen Slot, Frage und Kategorie sowie den direkten Moodle-Link. Dort nur aus dem Quiz '
                . 'entfernen, nicht aus der Fragensammlung löschen; die Fragen bleiben wiederverwendbar.',
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
            'capability' => 'local/coursepilot:use',
        ],
        'coursepilot_add_questions_to_quiz' => [
            'function' => 'local_coursepilot_add_questions_to_quiz',
            'classname' => 'local_coursepilot\external\add_questions_to_quiz',
            'wsdescription' => 'Appends questions to a quiz in the given order via quiz_add_quiz_question() - '
                . 'a question already in the quiz (matched by questionbankentryid) is skipped, not duplicated. '
                . 'Refuses entirely if the quiz already has attempts.',
            'description' => 'Hängt Fragen in der genannten Reihenfolge an einen Test an. Eine Frage, die schon '
                . 'im Test steckt (gleicher Bank-Eintrag), wird uebersprungen statt doppelt eingefuegt - die '
                . 'Antwort weist das je Frage aus ("added": false). Die Antwort nennt zusätzlich den entstandenen '
                . 'Slot-Stand mit Bank-Eintrag, aktuellster Fragen-Version und Versionsnummer je Slot, damit sich '
                . 'der Test pruefen laesst, ohne ihn zu oeffnen. Gibt es im Test bereits Versuche, wird GAR NICHTS '
                . 'geaendert - kein Teilerfolg, keine halb gefuellte Slot-Liste. Entfernen, Umsortieren und '
                . 'Seitenumbrueche sind nicht Teil dieses Werkzeugs (Moodle-Oberflaeche). Geprüft wird die native '
                . 'Moodle-Bearbeiten-Berechtigung des Tests sowie die Nutzungsberechtigung je Frage, keine eigene '
                . 'Coursepilot-Schreibrechte.',
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
            'capability' => 'local/coursepilot:use',
            'write' => true,
        ],
        'coursepilot_get_version_info' => [
            'function' => 'local_coursepilot_get_version_info',
            'classname' => 'local_coursepilot\external\get_version_info',
            'wsdescription' => 'Reports the Moodle release/version/branch and the Coursepilot plugin version and '
                . 'release, plus the server date.',
            'description' => 'Liefert den Versionsstand der Instanz: Moodle-Release, -Versionsstempel und -Zweig '
                . 'sowie Version und Release des Coursepilot-Plugins, dazu das Serverdatum. Damit wird der Kopf der '
                . 'Fragetyp-Ablage ("Moodle-Version", "Plugin-Version", "zuletzt verifiziert am") gefuellt, und '
                . 'Support-Rueckfragen lassen sich beantworten, ohne die Lehrkraft nach Versionsnummern zu fragen. '
                . 'Weicht die in der Datenbank eingetragene Plugin-Version von der der laufenden Dateien ab, wird '
                . 'das in der Meldung genannt (fehlender upgrade.php-Lauf). Rein lesend.',
            'schema' => null,
            'capability' => null,
        ],
    ];

    /**
     * Alle Werkzeugeinträge, beide Teile zusammengefügt (Issue #509): erst
     * {@see CORE_TOOLS} (Kurs/Quiz/Fragen), dann
     * {@see tool_registry_context_tools::TOOLS} (Kontextbereich,
     * Materialbestand, Klonen, Skills, Ausstand, Altbestand) - dieselbe
     * Reihenfolge, in der beide Teile vorher in einer einzigen TOOLS-
     * Konstante standen.
     *
     * @return array<string, array{
     *     function: string,
     *     classname: string,
     *     wsdescription: string,
     *     description: string,
     *     schema: ?array{properties: array, required?: array},
     *     capability: ?string,
     *     write?: bool,
     * }>
     */
    private static function all(): array {
        return self::CORE_TOOLS + tool_registry_context_tools::TOOLS;
    }

    /**
     * MCP-Toolname => Webservice-Funktionsname (privacy_surface::ALLOWED_TOOLS).
     *
     * @return array<string, string>
     */
    public static function allowed_tools(): array {
        return array_map(static fn (array $tool): string => $tool['function'], self::all());
    }

    /**
     * MCP-Toolname => Beschreibung (dispatcher::TOOL_DESCRIPTIONS).
     *
     * @return array<string, string>
     */
    public static function descriptions(): array {
        return array_map(static fn (array $tool): string => $tool['description'], self::all());
    }

    /**
     * MCP-Toolname => inputSchema-Properties, nur wo vorhanden
     * (dispatcher::TOOL_SCHEMAS).
     *
     * @return array<string, array{properties: array, required?: array}>
     */
    public static function schemas(): array {
        $out = [];
        foreach (self::all() as $name => $tool) {
            if ($tool['schema'] !== null) {
                $out[$name] = $tool['schema'];
            }
        }
        return $out;
    }

    /**
     * $functions-Array für db/services.php.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function service_functions(): array {
        $functions = [];
        foreach (self::all() as $tool) {
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
     * in {@see \local_coursepilot\access_log::log_success()})?
     *
     * @param string $toolname
     * @return bool
     */
    public static function is_write(string $toolname): bool {
        return self::all()[$toolname]['write'] ?? false;
    }

    /**
     * Webservice-Funktionsnamen in Registrierungsreihenfolge, für
     * $services['Coursepilot']['functions'] in db/services.php.
     *
     * @return string[]
     */
    public static function service_function_names(): array {
        return array_column(self::all(), 'function');
    }
}
