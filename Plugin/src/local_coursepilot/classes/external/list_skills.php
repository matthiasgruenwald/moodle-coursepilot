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

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\pending_write_notice;
use local_coursepilot\location_selection;
use local_coursepilot\remote_access;
use local_coursepilot\skill_corpus;

/**
 * Skill catalog (Spec 0020 §4, Issue #450): name, trigger, kind and length
 * per entry. get_skill supplies content. Course-independent; requires
 * remote access, rather than a course capability (Issue #630).
 *
 * Also reports open pending entries grouped by target file, oldest first,
 * without network access (Issue #492, ADR 0023 point 4). The server reports
 * them rather than relying on the AI. Contract keys are English (#571,
 * Spec 0025 §A), matching internal formats migrated under #602.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class list_skills extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Runs the list skills tool.
     *
     * @return mixed[]
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        self::validate_context(context_system::instance());
        // Course-independent product content: the remote access grant is the
        // gate, not the course capability 'use' (Issue #630).
        remote_access::require_granted();

        $skills = array_map(static fn (array $entry): array => [
            'name' => $entry['name'],
            'trigger' => $entry['trigger'],
            'kind' => $entry['kind'],
            'length' => $entry['length'],
        ], skill_corpus::list());

        global $USER;
        $notices = location_selection::notices((int) $USER->id);

        return ['skills' => $skills, 'pending_entries' => pending_write_notice::list_grouped(), 'notices' => $notices];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'skills' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Skill identifier, for get_skill(name)'),
                    'trigger' => new external_value(PARAM_TEXT, 'Trigger/description, English'),
                    'kind' => new external_value(PARAM_TEXT, '"adapter" or "reference"'),
                    'length' => new external_value(PARAM_INT, 'Length of the content in characters'),
                ])
            ),
            'pending_entries' => new external_multiple_structure(
                new external_single_structure([
                    'path' => new external_value(PARAM_TEXT, 'Relative target file path in the context area'),
                    'entries' => new external_multiple_structure(
                        new external_single_structure([
                            'identifier' => new external_value(
                                PARAM_ALPHANUMEXT,
                                'Identifier, for pending_entry=<identifier> or coursepilot_dismiss_pending_entry'
                            ),
                            'timestamp' => new external_value(PARAM_INT, 'Unix timestamp of the failed operation'),
                            'operation' => new external_value(
                                PARAM_TEXT,
                                '"create", "overwrite", "append" or "unknown" (pre-read failed)'
                            ),
                            'error_class' => new external_value(PARAM_TEXT, 'Named error class, never free text'),
                            'course_id' => new external_value(PARAM_INT, 'Course ID, 0 if the call was not tied to a course'),
                        ])
                    ),
                ]),
                'Open pending entries, bundled per target file, oldest first (ADR 0023)',
                VALUE_DEFAULT,
                []
            ),
            'notices' => new external_multiple_structure(
                new external_single_structure([
                    'text' => new external_value(PARAM_TEXT, 'Localized notice text'),
                    'link' => new external_value(PARAM_URL, 'Target page of the notice'),
                ]),
                'Notices determined without network access, e.g. open location selection with existing enablement (Issue #494)',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
