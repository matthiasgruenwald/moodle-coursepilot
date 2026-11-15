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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\material_files;
use local_coursepilot\question_suspect_gate;
use local_coursepilot\question_import;

/**
 * External input and response contract for the shared XML question importer.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class import_questions_xml extends external_api {
    /** @var int Resolved XML size limit retained for existing callers. */
    public const MAX_XML_BYTES = question_import::MAX_XML_BYTES;

    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'ID of the target question bank category'),
            'xmlcontent' => new external_value(
                PARAM_RAW,
                'Moodle question XML export as text (text door) - <file> blocks carry a '
                    . 'material="<material-folder-path>" attribute instead of real base64, the server resolves it '
                    . 'server-side. Give exactly one of xmlcontent/xmlpath.',
                VALUE_DEFAULT,
                ''
            ),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'true explicitly confirms a previously reported suspect case (an idnumber that came with no match '
                    . 'in the target category) and creates the question anyway as a new entry. Omit or false on '
                    . 'the first call.',
                VALUE_DEFAULT,
                false
            ),
            'xmlpath' => new external_value(
                PARAM_PATH,
                'Reference to an XML file in the material folder (reference door, bulk import of a foreign export '
                    . 'with real base64 in <file> blocks) - e.g. "export.xml". Give exactly one of xmlcontent/xmlpath.',
                VALUE_DEFAULT,
                ''
            ),
            'location' => material_files::location_parameter(),
        ]);
    }

    /**
     * Runs the import questions xml tool.
     *
     * @param int $categoryid
     * @param string $xmlcontent
     * @param bool $confirmed
     * @param string $xmlpath
     * @param string $location
     * @return mixed[]
     */
    public static function execute(
        int $categoryid,
        string $xmlcontent = '',
        bool $confirmed = false,
        string $xmlpath = '',
        string $location = material_files::LOCATION_STORE
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'xmlcontent' => $xmlcontent,
            'confirmed' => $confirmed,
            'xmlpath' => $xmlpath,
            'location' => $location,
        ]);

        return question_import::import(
            (int) $params['categoryid'],
            $params['xmlcontent'],
            $params['confirmed'],
            $params['xmlpath'],
            $params['location']
        );
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => new external_multiple_structure(
                new external_single_structure(array_merge(
                    [
                        'name' => new external_value(PARAM_TEXT, 'Imported question name'),
                        'questionbankentryid' => new external_value(
                            PARAM_INT,
                            'question_bank_entries ID (0 for "suspect")'
                        ),
                        'version' => new external_value(
                            PARAM_INT,
                            'New version number (0 for "suspect")'
                        ),
                        'status' => new external_value(
                            PARAM_ALPHAEXT,
                            '"first_import" (first import) | "reimport" (new version of the same entry) | "suspect" (suspect case)'
                        ),
                        'message' => new external_value(PARAM_RAW, 'Teacher-facing message'),
                    ],
                    question_suspect_gate::response_fields()
                )),
                'One result entry per imported XML question'
            ),
        ]);
    }
}
