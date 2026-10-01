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

use context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * Idempotentes Finden-oder-Anlegen einer Fragenbank-Kategorie (Spec 0017 §1,
 * Ticket #412): zieht zusammen, was im lokalen Weg zwei Werkzeuge waren
 * (Suchen ueber get_question_categories, Anlegen ueber
 * create_question_category) - der Skill soll nicht bei jedem Lauf erst
 * "gibt es die schon?" fragen muessen.
 *
 * Kontextauflösung ueber die Elternkategorie (Spec 0017, Muster aus
 * local_coursepilot\external\update_question_category::resolve_question_bank_context()):
 * "parent" ist die ID einer bestehenden Kategorie (typischerweise die
 * topcategoryid aus ensure_question_bank, oder eine zuvor angelegte
 * Unterkategorie) - daraus ergibt sich der Fragenbank-Kontext, kein
 * zusaetzlicher courseid/questionbankid-Parameter noetig.
 *
 * Ein gleichnamiger Treffer zaehlt nur unter demselben Elternteil - eine
 * gleichnamige Kategorie unter einer anderen Elternkategorie (z.B. in einer
 * anderen Fragensammlung) wird nicht als Treffer gewertet.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class ensure_question_category extends external_api {

    /** @var int Sortierposition neu angelegter Kategorien - identisch zu local_coursepilot\question_category_defaults::SORTORDER. */
    private const SORTORDER = 999;

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(PARAM_TEXT, 'Category name, convention: "<section number> <title>", e.g. "7.2 Stoffe und ihre Eigenschaften"'),
            'parent' => new external_value(PARAM_INT, 'ID of the parent category (e.g. topcategoryid from ensure_question_bank)'),
        ]);
    }

    /**
     * @param string $name
     * @param int $parent
     * @return array
     */
    public static function execute(string $name, int $parent): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'name' => $name,
            'parent' => $parent,
        ]);

        $parentcategory = $DB->get_record('question_categories', ['id' => $params['parent']], '*', MUST_EXIST);
        $context = context::instance_by_id((int) $parentcategory->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/question:managecategory', $context);

        $existing = $DB->get_record('question_categories', [
            'contextid' => $context->id,
            'parent' => $parentcategory->id,
            'name' => $params['name'],
        ]);

        if ($existing) {
            return [
                'id' => (int) $existing->id,
                'name' => $existing->name,
                'parent' => (int) $existing->parent,
                'contextid' => (int) $context->id,
                'created' => false,
                'message' => 'Kategorie "' . $params['name'] . '" existierte bereits, wird wiederverwendet.',
            ];
        }

        $record = new \stdClass();
        $record->name = $params['name'];
        $record->contextid = $context->id;
        $record->info = '';
        $record->infoformat = FORMAT_HTML;
        $record->stamp = make_unique_id_code();
        $record->parent = $parentcategory->id;
        $record->sortorder = self::SORTORDER;
        $record->idnumber = null;

        $newid = $DB->insert_record('question_categories', $record);

        return [
            'id' => (int) $newid,
            'name' => $params['name'],
            'parent' => (int) $parentcategory->id,
            'contextid' => (int) $context->id,
            'created' => true,
            'message' => 'Kategorie "' . $params['name'] . '" angelegt.',
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'ID of the (created or reused) category'),
            'name' => new external_value(PARAM_TEXT, 'Category name'),
            'parent' => new external_value(PARAM_INT, 'ID of the parent category'),
            'contextid' => new external_value(PARAM_INT, 'Context ID of the question bank'),
            'created' => new external_value(PARAM_BOOL, 'true if newly created; false if a same-named one under the same parent was reused'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing German message'),
        ]);
    }
}
