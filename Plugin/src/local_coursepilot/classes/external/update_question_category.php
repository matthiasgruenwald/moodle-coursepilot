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
 * Umbenennen und/oder Verschieben einer Fragenbank-Kategorie (Spec 0017 §1,
 * Ticket #413) - bewusst auf echte Aenderungen verengt: Anlegen-oder-Finden
 * ist {@see ensure_question_category}, dieser Endpunkt legt nie an.
 *
 * Kontextauflösung wie ensure_question_category direkt ueber die
 * (Ziel-)Kategorie, kein courseid/questionbankid-Parameter noetig - anders
 * als das aeltere lokale Pendant
 * local_coursepilot\external\update_question_category, dessen Muster als
 * Vorbild fuer Zyklus-/Top-Kategorie-/Namenskollisionsschutz diente.
 *
 * Fragen und ihre Versionen werden nie angefasst - nur die
 * question_categories-Zeile(n) selbst (Name, Parent, ggf. contextid des
 * gesamten Unterbaums bei Umzug in eine andere Fragensammlung).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class update_question_category extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'ID of the category to change'),
            'name' => new external_value(PARAM_TEXT, 'New category name (empty = keep the current name)', VALUE_DEFAULT, ''),
            'parent' => new external_value(PARAM_INT, 'ID of the new parent category (0 = keep the current parent)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @param int $categoryid
     * @param string $name
     * @param int $parent
     * @return array
     */
    public static function execute(int $categoryid, string $name = '', int $parent = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'name' => $name,
            'parent' => $parent,
        ]);

        $category = $DB->get_record('question_categories', ['id' => $params['categoryid']], '*', MUST_EXIST);
        $sourcecontext = context::instance_by_id((int) $category->contextid, MUST_EXIST);
        self::validate_context($sourcecontext);
        require_capability('local/coursepilot:use', $sourcecontext);
        require_capability('moodle/question:managecategory', $sourcecontext);

        $sourcetopcategory = question_get_top_category($sourcecontext->id, true);
        if ((int) $category->id === (int) $sourcetopcategory->id) {
            throw new \invalid_parameter_exception('Die oberste Kategorie einer Fragensammlung kann nicht umbenannt oder verschoben werden.');
        }

        $targetparentid = $params['parent'] > 0 ? $params['parent'] : (int) $category->parent;
        if ($targetparentid === (int) $category->id) {
            throw new \invalid_parameter_exception('Eine Kategorie kann nicht ihre eigene Elternkategorie sein.');
        }

        $targetparent = $params['parent'] > 0
            ? $DB->get_record('question_categories', ['id' => $targetparentid], '*', MUST_EXIST)
            : $DB->get_record('question_categories', ['id' => $category->parent], '*', MUST_EXIST);
        $targetcontext = context::instance_by_id((int) $targetparent->contextid, MUST_EXIST);

        if ((int) $targetcontext->id !== (int) $sourcecontext->id) {
            self::validate_context($targetcontext);
            require_capability('local/coursepilot:use', $targetcontext);
            require_capability('moodle/question:managecategory', $targetcontext);
        }

        $subtreeids = self::collect_subtree_ids((int) $category->id);
        if (in_array($targetparentid, $subtreeids, true)) {
            throw new \invalid_parameter_exception('Eine Kategorie kann nicht in eine ihrer eigenen Unterkategorien verschoben werden.');
        }

        $targetname = trim($params['name']) !== '' ? $params['name'] : $category->name;

        $conflict = $DB->get_record('question_categories', [
            'contextid' => $targetcontext->id,
            'parent' => $targetparentid,
            'name' => $targetname,
        ]);
        if ($conflict && (int) $conflict->id !== (int) $category->id) {
            throw new \invalid_parameter_exception(
                'Unter der Zielkategorie gibt es bereits eine Kategorie mit diesem Namen.'
            );
        }

        $moved = (int) $category->contextid !== (int) $targetcontext->id
            || (int) $category->parent !== $targetparentid;
        $renamed = $targetname !== $category->name;

        $transaction = $DB->start_delegated_transaction();

        $update = new \stdClass();
        $update->id = (int) $category->id;
        $update->name = $targetname;
        $update->parent = $targetparentid;

        if ($moved && (int) $category->contextid !== (int) $targetcontext->id) {
            // Ein Kontextwechsel ist mehr als die contextid-Spalte: an den
            // Fragen haengen Dateien (Fragebilder liegen im Kontext der
            // Fragensammlung), Schlagwoerter und Slot-Referenzen aus Tests.
            // question_move_category_to_context() zieht all das nach und
            // schreibt die contextid des Unterbaums um - eine eigene
            // Schleife ueber die Kategoriezeilen laesst die Bilder im alten
            // Kontext zurueck, sichtbar erst, wenn jemand die Frage
            // aufschlaegt.
            question_move_category_to_context(
                (int) $category->id,
                (int) $category->contextid,
                (int) $targetcontext->id
            );
            // Die Kernfunktion setzt die contextid nur fuer die
            // Unterkategorien, nicht fuer die uebergebene Kategorie selbst -
            // und deren Slot-Referenzen fasst sie ebenfalls nicht an.
            move_question_set_references(
                (int) $category->id,
                (int) $category->id,
                (int) $category->contextid,
                (int) $targetcontext->id
            );
            $update->contextid = (int) $targetcontext->id;
        }

        $DB->update_record('question_categories', $update);

        $transaction->allow_commit();

        $message = self::build_message($renamed, $moved, $targetname);

        return [
            'id' => (int) $category->id,
            'name' => $targetname,
            'parent' => $targetparentid,
            'contextid' => (int) $targetcontext->id,
            'moved' => $moved,
            'renamed' => $renamed,
            'message' => $message,
        ];
    }

    /**
     * @param bool $renamed
     * @param bool $moved
     * @param string $name
     * @return string
     */
    private static function build_message(bool $renamed, bool $moved, string $name): string {
        if ($renamed && $moved) {
            return 'Kategorie in "' . $name . '" umbenannt und verschoben.';
        }
        if ($renamed) {
            return 'Kategorie in "' . $name . '" umbenannt.';
        }
        if ($moved) {
            return 'Kategorie "' . $name . '" verschoben.';
        }
        return 'Keine Änderung: Name und Elternkategorie sind unverändert.';
    }

    /**
     * @param int $categoryid
     * @return int[]
     */
    private static function collect_subtree_ids(int $categoryid): array {
        global $DB;

        $ids = [];
        $queue = [$categoryid];

        while (!empty($queue)) {
            $currentid = array_shift($queue);
            $ids[] = $currentid;

            $children = $DB->get_records('question_categories', ['parent' => $currentid], 'id ASC', 'id');
            foreach ($children as $child) {
                $queue[] = (int) $child->id;
            }
        }

        return $ids;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'ID of the changed category'),
            'name' => new external_value(PARAM_TEXT, 'Category name after the change'),
            'parent' => new external_value(PARAM_INT, 'ID of the parent category after the change'),
            'contextid' => new external_value(PARAM_INT, 'Context ID of the category after the change'),
            'moved' => new external_value(PARAM_BOOL, 'true if the parent category and/or context changed'),
            'renamed' => new external_value(PARAM_BOOL, 'true if the name changed'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing German message'),
        ]);
    }
}
