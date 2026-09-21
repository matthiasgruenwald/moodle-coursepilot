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

namespace local_coursepilot\catalog;

/**
 * Feldkatalog fuer mod_choice (Spec 0015 §2.4/§4.5, Ticket #381).
 *
 * Fallstricke aus dem Bestand:
 * - Die Optionen sind EIN Feld ("option[]"), keine Feldreihe - eine
 *   wiederholte Formulargruppe (mod/choice/mod_form.php: repeat_elements()),
 *   kein fester Satz Einzelfelder. Die 2-6-Grenze des lokalen Wegs
 *   (local_coursepilot\external\create_choice) ist eine Coursepilot-eigene
 *   Erfindung dieses aelteren Wegs, keine Moodle-Grenze - sie geht deshalb
 *   NICHT in diesen Katalog ein.
 * - "limit[]" ist parallel zu "option[]" ueber denselben Schluessel indiziert
 *   (choice_add_instance()/choice_update_instance(): `$choice->limit[$key]`)
 *   - deshalb eine Kombinationsregel statt einer Feldeigenschaft: die Laenge
 *   von limit[] muss der Laenge von option[] entsprechen, sonst begrenzt
 *   Moodle manche Optionen gar nicht.
 * - "optionid[]" traegt bestehende choice_options-IDs fuer den Update-Pfad.
 *   choice_update_instance() prueft NICHT, ob eine ID zur eigenen Instanz
 *   gehoert, bevor sie per $DB->update_record() ueberschrieben wird - eine
 *   ID aus einer fremden choice-Instanz wuerde deren Option kaputt
 *   ueberschreiben. Dokumentiert im Feld selbst, siehe pseudofields().
 * - Feldbuendel "zuteilung" (Spec 0015 §2.4, neu): Geraete-/Partnerzuteilung
 *   braucht sechs Felder, die einzeln zu setzen niemand im Kopf hat -
 *   limitanswers=1, limit[] je Option (1 fuer Geraete, 2 fuer Partnerarbeit -
 *   das Buendel setzt den haeufigeren Fall 1 vor, die KI ueberschreibt ihn
 *   bei Bedarf), publish=CHOICE_PUBLISH_NAMES (1), showresults=
 *   CHOICE_SHOWRESULTS_ALWAYS (3), display=CHOICE_DISPLAY_VERTICAL (1),
 *   allowupdate=1. Literale Werte statt Konstanten, weil mod/choice/lib.php
 *   beim Laden dieser Klasse nicht zwingend eingebunden ist - die Konstanten
 *   stehen als Kommentar dabei.
 * - **"completionsubmit"** ist Vervollstaendigungsfeld UND echte Spalte
 *   zugleich, genau wie bei assign: fachlich gehoert es zur
 *   Abschlussverfolgung, nicht in einen beilaeufigen Feld-Patch. Deshalb wie
 *   dort auf der Sperrliste - geschrieben wird es ueber `set_completion` im
 *   Zweitakt (Ticket #461), wo es fuer "assign" und "choice" als
 *   modulspezifisches Vervollstaendigungsfeld freigeschaltet ist.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class choice implements module_catalog {

    public static function modname(): string {
        return 'choice';
    }

    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Anzeigename der Abstimmung.',
                true,
                null,
                null,
                null,
                'mod/choice/mod_form.php:18-22 (PARAM_TEXT bzw. PARAM_CLEANHTML je nach $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Beschreibungstext (Intro) der Abstimmung.',
                true,
                null,
                null,
                null,
                'mod/choice/db/install.xml (choice.intro, NOTNULL ohne DB-Default)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Textformat des Intros.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/choice/db/install.xml (choice.introformat)'
            ),
            new field(
                'display',
                'PARAM_INT',
                'Darstellung der Optionen: horizontal (0) oder vertikal (1).',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/lib.php:42-43 (CHOICE_DISPLAY_HORIZONTAL/CHOICE_DISPLAY_VERTICAL); '
                    . 'mod/choice/mod_form.php:29-34'
            ),
            new field(
                'allowupdate',
                'PARAM_BOOL',
                'Lernende duerfen ihre Auswahl nachtraeglich aendern.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:37 (selectyesno); Spalte mod/choice/db/install.xml (choice.allowupdate)'
            ),
            new field(
                'allowmultiple',
                'PARAM_BOOL',
                'Mehrfachauswahl erlaubt. Nach der ersten Antwort eingefroren, wenn bereits Antworten '
                    . 'vorliegen.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:39-43 (selectyesno, freeze() bei bestehenden choice_answers); Spalte '
                    . 'mod/choice/db/install.xml (choice.allowmultiple)'
            ),
            new field(
                'limitanswers',
                'PARAM_BOOL',
                'Teilnehmerzahl je Option begrenzen. Erst dann wirkt "limit[]" (siehe Kombinationsregeln).',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:47 (selectyesno); Spalte mod/choice/db/install.xml (choice.limitanswers)'
            ),
            new field(
                'showavailable',
                'PARAM_BOOL',
                'Freie Plaetze je Option anzeigen. Nur sichtbar/wirksam bei limitanswers=1.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:50-52 (selectyesno, hideIf limitanswers eq 0); Spalte '
                    . 'mod/choice/db/install.xml (choice.showavailable)'
            ),
            new field(
                'showunanswered',
                'PARAM_BOOL',
                'Eine zusaetzliche Option "Nicht beantwortet" bei den Ergebnissen mitzaehlen.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:114 (selectyesno); Spalte mod/choice/db/install.xml (choice.showunanswered)'
            ),
            new field(
                'includeinactive',
                'PARAM_BOOL',
                'Antworten inaktiver (z.B. abgemeldeter) Nutzer:innen bei den Ergebnissen mitzaehlen. '
                    . 'Formular-Default (0) weicht vom DB-Spalten-Default (1) ab.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:116-117 (selectyesno, setDefault(0) ueberschreibt DB-Default); Spalte '
                    . 'mod/choice/db/install.xml (choice.includeinactive, DEFAULT=1)'
            ),
            new field(
                'timeopen',
                'PARAM_INT',
                'Unix-Zeitstempel: Abstimmung oeffnet. 0 = kein Startzeitpunkt. Erzeugt einen Kalendereintrag '
                    . '(siehe Nebenwirkungen).',
                false,
                0,
                null,
                null,
                'mod/choice/mod_form.php:87-89 (date_time_selector, optional); Spalte '
                    . 'mod/choice/db/install.xml (choice.timeopen)'
            ),
            new field(
                'timeclose',
                'PARAM_INT',
                'Unix-Zeitstempel: Abstimmung schliesst. 0 = kein Endzeitpunkt. Erzeugt einen Kalendereintrag '
                    . '(siehe Nebenwirkungen).',
                false,
                0,
                null,
                null,
                'mod/choice/mod_form.php:90-92 (date_time_selector, optional); Spalte '
                    . 'mod/choice/db/install.xml (choice.timeclose)'
            ),
            new field(
                'showpreview',
                'PARAM_BOOL',
                'Optionen vor timeopen bereits anzeigen (ohne Abstimmen zu koennen).',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:93 (advcheckbox); Spalte mod/choice/db/install.xml (choice.showpreview)'
            ),
            new field(
                'showresults',
                'PARAM_INT',
                'Wann Ergebnisse sichtbar sind: nie (0), nach eigener Antwort (1), nach Schliessung (2), immer '
                    . '(3).',
                false,
                0,
                [0, 1, 2, 3],
                null,
                'mod/choice/lib.php:37-40 (CHOICE_SHOWRESULTS_NOT/AFTER_ANSWER/AFTER_CLOSE/ALWAYS); '
                    . 'mod/choice/mod_form.php:100-105'
            ),
            new field(
                'publish',
                'PARAM_INT',
                'Ergebnisdarstellung anonym (0) oder mit Namen (1). Nebenwirkung: der Wechsel von anonym auf '
                    . 'namentlich macht bereits abgegebene Antworten rueckwirkend namentlich sichtbar - siehe '
                    . 'Nebenwirkungen.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/lib.php:34-35 (CHOICE_PUBLISH_ANONYMOUS/CHOICE_PUBLISH_NAMES); '
                    . 'mod/choice/mod_form.php:107-112'
            ),
        ];
    }

    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::for_modname(self::modname(), $instanceid, $cmid, $fullcontent);
    }

    public static function write_options(): array {
        return [
            'scalar_to_repeated' => ['limit' => 'option'],
            'parallel_array_lengths' => [['reference' => 'option', 'field' => 'limit']],
            'date_order_rules' => [['reference' => 'timeopen', 'field' => 'timeclose', 'mode' => 'not_before']],
            // Rueckweg zu carry_forward_choice_options()
            // (pseudofield_carry_forward.php): "option"/"limit"/"optionid" leben in
            // choice_options, nicht in der choice-Instanzzeile - der Lesepfad
            // (module_state::read_repeated_groups(), aufgerufen aus
            // get_module_settings) liest dieselbe Tabelle ueber diese Deklaration
            // zurueck, statt einen "choice"-Sonderfall im Werkzeug zu brauchen
            // (Issue #564).
            'repeated_group' => [
                'option' => [
                    'table' => 'choice_options',
                    'foreignkey' => 'choiceid',
                    'orderby' => 'id',
                    'fields' => ['option' => 'text', 'limit' => 'maxanswers', 'optionid' => 'id'],
                ],
            ],
        ];
    }

    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    public static function pseudofields(): array {
        return [
            new field(
                'option',
                'string[]',
                'Die Abstimmungsoptionen - EIN Feld, keine Feldreihe: eine Liste von Texten, ueber denselben '
                    . 'Schluessel mit "limit" und "optionid" verknuepft. Mindestens ein nicht-leerer Eintrag '
                    . '(option[0]) ist Pflicht.',
                true,
                null,
                null,
                null,
                'mod/choice/mod_form.php:53-78 (repeat_elements() der Gruppe option/limit/optionid); '
                    . 'mod/choice/lib.php:110/151 (choice_add_instance()/choice_update_instance(): '
                    . '`foreach ($choice->option as $key => $value)`)'
            ),
            new field(
                'limit',
                'int[]',
                'Teilnehmerlimit je Option, ueber denselben Schluessel wie "option" verknuepft. Nur wirksam bei '
                    . 'limitanswers=1. Muss genauso viele Eintraege haben wie "option" (siehe Kombinationsregeln).',
                false,
                null,
                null,
                null,
                'mod/choice/mod_form.php:53,61-64 (repeat_elements(), Default 0); mod/choice/lib.php:116-117/156-157 '
                    . '(`$choice->limit[$key]`)'
            ),
            new field(
                'optionid',
                'int[]',
                'Bestehende choice_options-IDs fuer den Update-Pfad, ueber denselben Schluessel wie "option" '
                    . 'verknuepft; 0 oder fehlend legt eine neue Option an. ACHTUNG: choice_update_instance() '
                    . 'prueft nicht, ob eine ID zur eigenen Instanz gehoert - eine ID aus einer fremden '
                    . 'choice-Instanz darf hier deshalb nicht landen, sonst wird deren Option ueberschrieben.',
                false,
                0,
                null,
                null,
                'mod/choice/mod_form.php:55,120-133 (data_preprocessing(), hidden-Feld); '
                    . 'mod/choice/lib.php:160-168 (choice_update_instance(): kein Instanzabgleich vor '
                    . '$DB->update_record())'
            ),
        ];
    }

    public static function blocklist(): array {
        return [
            'completionsubmit',
        ];
    }

    public static function combination_rules(): array {
        return [
            '"limit[]" muss genauso viele Eintraege haben wie "option[]" (gleicher Schluessel in '
                . 'choice_add_instance()/choice_update_instance()) - fehlt ein Eintrag, bleibt die zugehoerige '
                . 'Option unbegrenzt, auch wenn limitanswers=1 gesetzt ist.',
            '"timeclose" darf nicht vor "timeopen" liegen (mod/choice/mod_form.php: validation()).',
        ];
    }

    public static function side_effects(): array {
        return [
            '"publish" von anonym (0) auf namentlich (1) wechseln macht bereits abgegebene Antworten '
                . 'rueckwirkend mit Namen sichtbar, nicht nur kuenftige.',
            '"timeopen"/"timeclose" erzeugen bzw. aktualisieren Kalendereintraege (mod/choice/locallib.php: '
                . 'choice_set_events()).',
        ];
    }

    public static function bundles(): array {
        return [
            'zuteilung' => [
                'limitanswers' => 1,
                'limit' => 1,
                'publish' => 1, // CHOICE_PUBLISH_NAMES.
                'showresults' => 3, // CHOICE_SHOWRESULTS_ALWAYS.
                'display' => 1, // CHOICE_DISPLAY_VERTICAL.
                'allowupdate' => 1,
            ],
        ];
    }

    public static function schreibweg(): ?string {
        return null;
    }

    public static function checked_constants(): array {
        return [];
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
