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
use local_coursepilot\remote_access;
use local_coursepilot\skill_corpus;

defined('MOODLE_INTERNAL') || die();

/**
 * Delivery of a single skill corpus entry (Spec 0020 §4, issue
 * #450): content, names of the referenced parts, corpus version.
 *
 * $name is an identifier, not a path: {@see skill_corpus::get()} checks
 * exclusively against the names scanned from the directory - an
 * unknown or path-like name (`../`, leading `/`, backslash,
 * encoded variant) is rejected alike, the message lists the
 * valid names. Deliberately PARAM_TEXT instead of an alphanumeric
 * filter: the name arrives at the check unchanged, the rejection
 * is a matter of the directory list, not of character cleaning.
 *
 * Response keys are English end to end (#571, #602). The corpus content
 * is also English (#604); its adapters instruct the AI to respond in
 * the teacher's language. Delivery returns the stored Markdown unchanged.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class get_skill extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(PARAM_TEXT, 'Skill identifier from coursepilot_list_skills, not a path'),
        ]);
    }

    /**
     * @param string $name
     * @return array
     * @throws \moodle_exception unknownskillname, if $name is not in the corpus directory.
     */
    public static function execute(string $name): array {
        $params = self::validate_parameters(self::execute_parameters(), ['name' => $name]);
        self::validate_context(context_system::instance());
        // Course-independent product content: the remote access grant is the
        // gate, not the course capability 'use' (Issue #630).
        remote_access::require_granted();

        return skill_corpus::get($params['name']);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'content' => new external_value(PARAM_RAW, 'Markdown content'),
            'referenced_parts' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Name of a corpus part referenced in the content')
            ),
            'corpus_version' => new external_value(PARAM_TEXT, 'Plugin release and version of the delivered corpus'),
        ]);
    }
}
