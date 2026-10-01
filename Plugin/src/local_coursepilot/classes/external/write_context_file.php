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
 * Unmittelbar englisch deklariert (#571, Spec 0025 §A): "pending_entry" statt
 * "ausstand", "create_only" statt "nur_anlegen" - dasselbe Nachtragsverhalten
 * (ein erfolgreiches Schreiben mit "pending_entry" verwirft den Eintrag im
 * selben Aufruf ueber {@see \local_coursepilot\pending_write_notice::dismiss()})
 * bleibt unveraendert.
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
            'path' => new external_value(PARAM_PATH, 'File path relative to the context area, e.g. "plan.md"'),
            'content' => new external_value(PARAM_RAW, 'Complete new file content'),
            'expected_contenthash' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: contenthash from the last read - if it does not match, the operation aborts',
                VALUE_DEFAULT,
                ''
            ),
            'pending_entry' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: identifier of an open pending entry (from coursepilot_list_skills) - if the write '
                    . 'succeeds, the entry disappears within the same call',
                VALUE_DEFAULT,
                ''
            ),
            'create_only' => new external_value(
                PARAM_BOOL,
                'Optional: true only creates and never overwrites - for copying from the legacy stock (previous '
                    . 'location, from coursepilot_list_context_files/read_context_file) to the new location',
                VALUE_DEFAULT,
                false
            ),
            'courseid' => new external_value(
                PARAM_INT,
                'Optional: course ID, if the content belongs to a specific course - only used for a possible entry '
                    . 'in the "not yet saved" notice if the storage/connection/location fails',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash
     * @param string $pendingentry
     * @param bool $createonly
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
        string $pendingentry = '',
        bool $createonly = false,
        int $courseid = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'path' => $path,
            'content' => $content,
            'expected_contenthash' => $expectedcontenthash,
            'pending_entry' => $pendingentry,
            'create_only' => $createonly,
            'courseid' => $courseid,
        ]);

        self::validate_context(context_files::own_context());

        $content = $params['content'];
        context_files::require_size_within_limit($content);

        $result = context_area::write(
            $params['path'],
            $content,
            $params['expected_contenthash'],
            $params['pending_entry'],
            $params['create_only'],
            $params['courseid']
        );
        pending_write_notice::dismiss($params['pending_entry']);

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
            'path' => new external_value(PARAM_TEXT, 'Resolved file path, relative to the context area'),
            'created' => new external_value(PARAM_BOOL, 'true if the file was newly created'),
            'size' => new external_value(PARAM_INT, 'New file size in bytes'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing German change message'),
        ]);
    }
}
