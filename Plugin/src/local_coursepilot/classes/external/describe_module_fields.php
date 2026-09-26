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
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\shared_block;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Feldkatalog als Daten (Spec 0015 §3.1, Ticket #379). Rein lesend: liefert,
 * was eine Aktivitaetsart einstellen kann, in Lehrkraft-verstaendlichem
 * Deutsch statt englischer Feldnamen ohne Erklaerung.
 *
 * Ohne $modname: welche Aktivitaetsarten Coursepilot ueberhaupt fuehrt (User
 * Story 13). Mit $modname, ohne $full: die haeufig gesetzten Felder
 * plus Feldbuendel plus Vermerk, dass es mehr gibt (User Story 15). Mit
 * $full=true: alle fuenf Kategorien aus Spec 0015 §2.2.
 *
 * Nicht course-gebunden: der Katalog ist statische Serverkonfiguration, kein
 * Kursinhalt - deshalb keine 'local/coursepilot:use'-Pruefung im Kurskontext
 * (die gibt es hier nicht), sondern nur das Standard-Login/die globale
 * Fernzugriffs-Notbremse aus dispatcher::handle_authorized().
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class describe_module_fields extends external_api {

    /**
     * Vehikel-Hinweis fuer Aktivitaetsarten ohne eigenes schreibweg() (Spec
     * 0015 §1/§3.1: der Formularweg update_moduleinfo()). Bewusst OHNE
     * konkrete MCP-Werkzeugnamen ("update_module_settings"/"create_module") -
     * dieses Ticket (#379) liefert nur den Lesekatalog, der Schreibkern selbst
     * kommt erst in Phase 3.
     */
    private const VEHICLE_SCHREIBWEG = 'Formularweg (update_moduleinfo() bzw. add_moduleinfo()); eigener '
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
     * @throws moodle_exception unknownmodname, wenn $modname nicht gefuehrt wird.
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
                ['modname' => $modname, 'aktivitaetsarten' => implode(', ', $knownmodnames)]
            );
        }

        $full = (bool) $params['full'];
        $modulefields = $catalogclass::fields();
        if (!$full) {
            // Ausduennung fuer die Kurzform (Spec 0015 §3.1, Ticket #382): nur Aktivitaetsarten mit sehr
            // vielen Feldern (assign: ~30) grenzen common_field_names() echt ein - bei wenigen Feldern
            // (label, choice, forum, ...) liefert die Methode ohnehin alle Namen (siehe module_catalog).
            $commonnames = $catalogclass::common_field_names();
            $modulefields = array_values(array_filter(
                $modulefields,
                static fn (field $f): bool => in_array($f->name, $commonnames, true)
            ));
        }
        $fields = array_merge(shared_block::fields(), $modulefields);

        $module = [
            'modname' => $modname,
            'write_route' => $catalogclass::schreibweg() ?? self::VEHICLE_SCHREIBWEG,
            'fields' => array_map(static fn (field $f): array => $f->to_array(), $fields),
            'field_bundles' => self::bundles($catalogclass::bundles()),
            'pseudo_fields' => [],
            'blocked_fields' => [],
            'combination_rules' => [],
            'side_effects' => [],
        ];

        if ($full) {
            $pseudofields = array_merge(shared_block::pseudofields(), $catalogclass::pseudofields());
            $module['pseudo_fields'] = array_map(static fn (field $f): array => $f->to_array(), $pseudofields);
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
     * Feldbuendel fuer die Rueckgabestruktur: Werte sind je Feld gemischten
     * Typs, deshalb als JSON-Zeile statt als dynamische Struktur (gleiches
     * Vorgehen wie get_course_catalog::plugin_config_field()-Zusatzdateien).
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
                    new external_value(PARAM_TEXT, 'Regel in Lehrkraft-Deutsch'),
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
