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
use local_coursepilot\catalog\field;
use local_coursepilot\catalog\learner_locks;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\shared_block;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Field catalog as data (Spec 0015 §3.1, #379). Read-only: describes an
 * activity type's settings in language teachers understand, instead of
 * unexplained field names.
 *
 * Without modname, list the activity types Coursepilot knows (user story 13).
 * With modname and without full, return common fields, field bundles and a
 * notice that more are available (user story 15). full=true returns all five
 * categories from Spec 0015 §2.2.
 *
 * The catalog is static server configuration, not course content. There is
 * no course context for local/coursepilot:use; dispatcher::handle_authorized()
 * applies the standard login and global remote-access emergency stop.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class describe_module_fields extends external_api {

    /**
     * Write vehicle hint for types without their own write_route() (Spec 0015
     * §1/§3.1: update_moduleinfo()). Deliberately avoids specific MCP tool names
     * (update_module_settings/create_module): #379 provides the read catalog,
     * while the write core follows in phase 3.
     */
    private const VEHICLE_WRITE_ROUTE = 'Formularweg (update_moduleinfo() bzw. add_moduleinfo()); eigener '
        . 'Schreib-Endpunkt folgt in einer spaeteren Ausbaustufe.';

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'modname' => new external_value(
                PARAM_ALPHANUMEXT,
                'Activity type, e.g. label. Empty returns the list of activity types Coursepilot knows.',
                VALUE_DEFAULT,
                ''
            ),
            'full' => new external_value(
                PARAM_BOOL,
                'true for all five categories (fields, pseudo fields, blocked fields, combination rules, '
                    . 'side effects); otherwise only the commonly set fields plus field bundles.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param string $modname
     * @param bool $full
     * @return array
     * @throws moodle_exception unknownmodname if $modname is not supported.
     */
    public static function execute(string $modname = '', bool $full = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'modname' => $modname,
            'full' => $full,
        ]);

        self::validate_context(context_system::instance());

        $knownmodnames = registry::known_modnames();
        $modname = trim($params['modname']);

        if ($modname === '') {
            return [
                'known_modnames' => $knownmodnames,
                'notice' => 'Coursepilot kann die Aktivitaetsarten, die er kennt: '
                    . implode(', ', $knownmodnames) . '. describe_module_fields(modname) fragt eine davon ab.',
                'module' => null,
            ];
        }

        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            throw new moodle_exception(
                'unknownmodname',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'modnames' => implode(', ', $knownmodnames)]
            );
        }

        $full = (bool) $params['full'];
        $modulefields = $catalogclass::fields();
        if (!$full) {
            // Trim the short form (Spec 0015 §3.1, #382). Only types with many fields
            // (assign: about 30) restrict common_field_names(); types with few fields
            // (label, choice, forum, ...) already return all names (see module_catalog).
            $commonnames = $catalogclass::common_field_names();
            $modulefields = array_values(array_filter(
                $modulefields,
                static fn (field $f): bool => in_array($f->name, $commonnames, true)
            ));
        }
        $fields = array_merge(shared_block::fields(), $modulefields);

        $withlock = static fn (field $f): array => $f->to_array()
            + ['learner_lock' => learner_locks::condition_json($catalogclass, $f->name)];
        $module = [
            'modname' => $modname,
            'write_route' => $catalogclass::write_route() ?? self::VEHICLE_WRITE_ROUTE,
            'grade_origin' => $catalogclass::grade_origin(),
            'fields' => array_map($withlock, $fields),
            'field_bundles' => self::bundles($catalogclass::bundles()),
            'pseudo_fields' => [],
            'blocked_fields' => [],
            'combination_rules' => [],
            'side_effects' => [],
        ];

        if ($full) {
            $pseudofields = array_merge(shared_block::pseudofields(), $catalogclass::pseudofields());
            $module['pseudo_fields'] = array_map($withlock, $pseudofields);
            $module['blocked_fields'] = array_values(array_unique(
                array_merge(shared_block::BLOCKLIST, $catalogclass::blocklist())
            ));
            $module['combination_rules'] = $catalogclass::combination_rules();
            $module['side_effects'] = array_merge(shared_block::side_effects(), $catalogclass::side_effects());
        }

        return [
            'known_modnames' => $knownmodnames,
            'notice' => $full
                ? 'Vollstaendige Form: alle fuenf Katalogkategorien.'
                : 'Kurzform: nur die haeufig gesetzten Felder und Feldbuendel. Pseudofelder, Sperrliste, '
                    . 'Kombinationsregeln und Nebenwirkungen fehlen - mit full:true abrufen.',
            'module' => $module,
        ];
    }

    /**
     * Serialize field bundles for the return structure. Per-field values have
     * mixed types, so use a JSON row rather than a dynamic structure, as with
     * get_course_catalog::plugin_config_field() supplemental files.
     *
     * @param array<string, array<string, mixed>> $bundles
     * @return array<int, array{name: string, fields_json: string}>
     */
    private static function bundles(array $bundles): array {
        $result = [];
        foreach ($bundles as $name => $fields) {
            $result[] = ['name' => $name, 'fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }
        return $result;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $fieldstructure = new external_single_structure([
            'name' => new external_value(PARAM_TEXT, 'Moodle field name (form-path contract)'),
            'type' => new external_value(PARAM_TEXT, 'PARAM_* constant or short type description'),
            'meaning' => new external_value(PARAM_TEXT, 'Teacher-facing German meaning of the field'),
            'required' => new external_value(PARAM_BOOL, 'Required field without a default?'),
            'default_json' => new external_value(PARAM_RAW, 'JSON-encoded form default, "null" if none'),
            'value_range' => new external_single_structure([
                'values_json' => new external_value(
                    PARAM_RAW,
                    'JSON-encoded list of allowed values, "null" if only determinable via source_callable'
                ),
                'source_callable' => new external_value(
                    PARAM_TEXT,
                    'Callable Moodle source of the value range, e.g. "format_text_menu()", otherwise null'
                ),
                'source' => new external_value(PARAM_TEXT, 'File:line reference'),
            ]),
            'learner_lock' => new external_value(
                PARAM_RAW,
                'JSON-encoded learner lock condition {op, value, reason}: when the written value meets it, learners '
                    . 'need an action by the teacher to continue or resubmit, and write tools reject the call unless '
                    . 'confirm_learner_locks names the field. "null" if the field cannot be a lock.'
            ),
        ]);

        return new external_single_structure([
            'known_modnames' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Modname'),
                'Activity types Coursepilot knows'
            ),
            'notice' => new external_value(PARAM_TEXT, 'Teacher-facing German notice text'),
            'module' => new external_single_structure([
                'modname' => new external_value(PARAM_TEXT, 'Activity type'),
                'write_route' => new external_value(
                    PARAM_TEXT,
                    'Vehicle notice, or the name of the single tool that writes instead'
                ),
                'grade_origin' => new external_value(
                    PARAM_ALPHA,
                    'Who produces the grade: "teacher", "automatic" or "none". A quiz containing a manually graded '
                        . 'question counts as "teacher" in the write tools.'
                ),
                'fields' => new external_multiple_structure($fieldstructure, 'Category 1: fields (incl. shared block)'),
                'field_bundles' => new external_multiple_structure(
                    new external_single_structure([
                        'name' => new external_value(PARAM_TEXT, 'Bundle name'),
                        'fields_json' => new external_value(PARAM_RAW, 'JSON-encoded field=>value preset'),
                    ]),
                    'Field bundles (presets)'
                ),
                'pseudo_fields' => new external_multiple_structure($fieldstructure, 'Category 2: only with full:true'),
                'blocked_fields' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Field name'),
                    'Category 3: only with full:true'
                ),
                'combination_rules' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Teacher-facing combination rule'),
                    'Category 4: only with full:true'
                ),
                'side_effects' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Teacher-facing German side-effect note'),
                    'Category 5: only with full:true'
                ),
            ], 'The requested module catalog, null when modname was empty', VALUE_DEFAULT, null, NULL_ALLOWED),
        ]);
    }
}
