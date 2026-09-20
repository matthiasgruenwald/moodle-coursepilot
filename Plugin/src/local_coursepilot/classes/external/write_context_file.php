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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\pending_write_notice;
use local_coursepilot\context_area;
use local_coursepilot\context_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Legt eine Datei im Kontextbereich der aufrufenden Lehrkraft an oder
 * ueberschreibt sie vollstaendig (Issue #408, Spec 0016 §4.1).
 *
 * Ortsneutral seit Issue #538 (Spec 0021): dieses Werkzeug kennt weder
 * Ortsart noch Ortsfehlerschluessel noch ein "etag"-Sonderfeld - die
 * Entscheidung, ob Private Files oder der externe Ort greift, trifft
 * {@see context_area::write()}. Nichts wird angefasst, bevor nicht alles
 * geprueft ist - diese Reihenfolge (Pfad, Endung, Groesse, Personenbezug,
 * Gleichzeitigkeit, Quote) bleibt weiterhin Absicht, liegt aber jetzt dort.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class write_context_file extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Dateipfad relativ zum Kontextbereich, z.B. "plan.md"'),
            'content' => new external_value(PARAM_RAW, 'Vollstaendiger neuer Dateiinhalt'),
            'expected_contenthash' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: contenthash aus dem letzten Lesen - passt er nicht, bricht der Vorgang ab',
                VALUE_DEFAULT,
                ''
            ),
            'ausstand' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: Kennung eines offenen Ausstands (aus coursepilot_list_skills) - gelingt das Schreiben, '
                    . 'verschwindet der Eintrag im selben Aufruf',
                VALUE_DEFAULT,
                ''
            ),
            'nur_anlegen' => new external_value(
                PARAM_BOOL,
                'Optional: true legt nur an und ueberschreibt nie - fuer das Kopieren aus dem Altbestand '
                    . '(vorheriger Ort, aus coursepilot_list_context_files/read_context_file) an den neuen Ort',
                VALUE_DEFAULT,
                false
            ),
            'courseid' => new external_value(
                PARAM_INT,
                'Optional: Kurs-ID, wenn der Inhalt zu einem bestimmten Kurs gehoert - dient nur einem etwaigen '
                    . 'Eintrag der Notiz "noch nicht gespeichert", falls der Speicher/die Verbindung/der Ort scheitert',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash
     * @param string $ausstand
     * @param bool $nuranlegen
     * @param int $courseid
     * @return array
     * @throws \moodle_exception invalidcontextpath, contextfilenotmarkdown,
     *         contextfiletoolarge, contextfilelocked, contextfilechanged,
     *         contextfilealreadyexists, contextquotaexceeded
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $ausstand = '',
        bool $nuranlegen = false,
        int $courseid = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'path' => $path,
            'content' => $content,
            'expected_contenthash' => $expectedcontenthash,
            'ausstand' => $ausstand,
            'nur_anlegen' => $nuranlegen,
            'courseid' => $courseid,
        ]);

        self::validate_context(context_files::own_context());

        $content = $params['content'];
        context_files::require_size_within_limit($content);

        $result = context_area::write(
            $params['path'],
            $content,
            $params['expected_contenthash'],
            $params['ausstand'],
            $params['nur_anlegen'],
            $params['courseid']
        );
        pending_write_notice::dismiss($params['ausstand']);

        return self::build_response($result);
    }

    /**
     * Baut die Lehrkraft-Deutsch-Aenderungsmeldung aus dem ortsneutralen
     * Ergebnis von {@see context_area::write()}.
     *
     * @param array{path: string, created: bool, size: int, oldsize: int} $result
     * @return array
     */
    private static function build_response(array $result): array {
        $message = $result['created']
            ? get_string('contextfilecreated', 'local_coursepilot', $result['path'])
            : get_string('contextfileoverwritten', 'local_coursepilot', (object) [
                'path' => $result['path'],
                'before' => $result['oldsize'],
                'after' => $result['size'],
            ]);

        return [
            'path' => $result['path'],
            'created' => $result['created'],
            'size' => $result['size'],
            'message' => $message,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Aufgeloester Dateipfad, relativ zum Kontextbereich'),
            'created' => new external_value(PARAM_BOOL, 'true, wenn die Datei neu angelegt wurde'),
            'size' => new external_value(PARAM_INT, 'Neue Dateigroesse in Byte'),
            'message' => new external_value(PARAM_RAW, 'Aenderungsmeldung in Lehrkraft-Deutsch'),
        ]);
    }
}
