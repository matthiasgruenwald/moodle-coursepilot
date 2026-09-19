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

namespace local_coursepilot\external;

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Schreibkern 13 (Spec 0015 Phase 3, Ticket #391): verschiebt einen Abschnitt
 * an eine andere Position im Kurs.
 *
 * Ticket #391 nennt cmactions/sectionactions::move_after()/move_at() als
 * Zielapi der 5.2-Nachfolge (MDL-86854/MDL-86862). Auf dieser Instanz (echte
 * Moodle-5.0.8-Quelle, siehe /opt/moodle/course/format/classes/local/) ist
 * dieser Ersatz noch nicht gelandet - {@see \core_courseformat\local\sectionactions}
 * fuehrt in 5.0.8 kein "move_after". Der tatsaechlich existierende,
 * NICHT-deprecated Kommando-Bus fuer diese Aktion ist
 * {@see \core_courseformat\stateactions::section_move_after()} - dieselbe
 * Methode, die core_courseformat\external\update_course (die von der
 * JS-Kursbearbeitung genutzte Webservice-Funktion "core_courseformat_update_course")
 * fuer die Aktion "section_move_after" aufruft. Kein direkter Aufruf von
 * move_section_to() - das bleibt Moodles eigene interne Implementierung
 * hinter dieser Abstraktion und wird beim spaeteren Umstieg auf
 * sectionactions::move_after() unsichtbar fuer diesen Aufrufer ausgetauscht.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class move_section extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Kurs-ID'),
            'sourcesectionnum' => new external_value(PARAM_INT, 'Aktuelle Abschnittsnummer'),
            'targetsectionnum' => new external_value(PARAM_INT, 'Gewuenschte Abschnittsnummer nach der Verschiebung'),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sourcesectionnum
     * @param int $targetsectionnum
     * @return array
     * @throws moodle_exception sectionnotmovable|sectiontargetoutofrange
     */
    public static function execute(int $courseid, int $sourcesectionnum, int $targetsectionnum): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sourcesectionnum' => $sourcesectionnum,
            'targetsectionnum' => $targetsectionnum,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native Berechtigungspruefung: stateactions::section_move_after()
        // prueft 'moodle/course:movesections' ohnehin selbst erneut - der
        // Aufruf hier ist billig und stellt sicher, dass eine fehlende
        // Berechtigung nicht hinter einer Positionsvalidierung versteckt
        // bleibt.
        require_capability('moodle/course:movesections', $context);

        $course = get_course($params['courseid']);
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();
        $maxsectionnum = max(array_keys($sections));

        $von = $params['sourcesectionnum'];
        $nach = $params['targetsectionnum'];

        if ($von <= 0 || !array_key_exists($von, $sections)) {
            throw new moodle_exception('sectionnotmovable', 'local_coursepilot', '', ['sectionnum' => $von]);
        }
        if ($nach < 1 || $nach > $maxsectionnum) {
            throw new moodle_exception(
                'sectiontargetoutofrange',
                'local_coursepilot',
                '',
                ['nach' => $nach, 'max' => $maxsectionnum]
            );
        }

        $format = course_get_format($course);
        $sectionname = $format->get_section_name($sections[$von]);

        if ($von === $nach) {
            return [
                'id' => (int) $sections[$von]->id,
                'sectionnum' => (int) $nach,
                'meldung' => "Abschnitt \"{$sectionname}\" liegt bereits an Position {$nach}.",
            ];
        }

        // Destinationsnummer: sectionactions::move_after()/move_at() haetten
        // die Zielposition direkt entgegengenommen; der hier verfuegbare
        // Kommando-Bus (section_move_after) verlangt stattdessen "nach
        // welchem Abschnitt einfuegen" - die Umrechnung ist reine
        // Indexarithmetik (siehe Klassendoku).
        $destinationnum = $nach > $von ? $nach : $nach - 1;
        $destination = $sections[$destinationnum];

        $updates = $format->get_stateupdates_instance();
        $actions = $format->get_stateactions_instance();
        $actions->section_move_after($updates, $course, [$sections[$von]->id], $destination->id);

        return [
            'id' => (int) $sections[$von]->id,
            'sectionnum' => (int) $nach,
            'meldung' => "Abschnitt \"{$sectionname}\" von Position {$von} nach Position {$nach} verschoben.",
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Abschnitts-DB-ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Neue Abschnittsnummer nach der Verschiebung'),
            'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Aenderungsmeldung'),
        ]);
    }
}
