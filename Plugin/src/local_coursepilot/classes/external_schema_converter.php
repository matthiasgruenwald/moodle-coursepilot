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

namespace local_coursepilot;

use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Converts Moodle's external-function declarations into MCP input schemas.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class external_schema_converter {

    /**
     * @return array{properties: array<string, array>, required?: string[]}
     */
    public static function from_parameters(external_function_parameters $parameters): array {
        $schema = self::from_structure($parameters);
        unset($schema['type']);
        return contract_keys::externalize($schema);
    }

    /**
     * @return array<string, mixed>
     */
    private static function from_description(external_description $description): array {
        if ($description instanceof external_value) {
            $schema = [
                'type' => match ($description->type) {
                    PARAM_INT => 'integer',
                    PARAM_FLOAT => 'number',
                    PARAM_BOOL => 'boolean',
                    default => 'string',
                },
                'description' => $description->desc,
            ];
        } else if ($description instanceof external_multiple_structure) {
            $schema = [
                'type' => 'array',
                'items' => self::from_description($description->content),
                'description' => $description->desc,
            ];
        } else {
            $schema = self::from_structure($description);
            $schema['description'] = $description->desc;
        }

        if ($description->required === VALUE_DEFAULT) {
            $schema['default'] = $description->default;
        }
        return $schema;
    }

    /**
     * @return array{type: string, properties: array<string, array>, required?: string[]}
     */
    private static function from_structure(external_single_structure $structure): array {
        $schema = ['type' => 'object', 'properties' => []];
        $required = [];
        foreach ($structure->keys as $name => $description) {
            $schema['properties'][$name] = self::from_description($description);
            if ($description->required === VALUE_REQUIRED) {
                // #568: 'required' ist eine Werteliste, keine Schluesselmenge
                // - die abschliessende contract_keys::externalize() in
                // from_parameters() uebersetzt nur Schluessel, nie
                // Array-Werte. Ohne diese Zeile blieb der Eintrag hier am
                // deutschen Rohnamen haengen, waehrend 'properties' densel-
                // ben Namen laengst englisch fuehrte - elf so widerspruech-
                // liche Pflichtfeldlisten (Review vom 25.09.2026).
                $required[] = contract_keys::externalize_key($name);
            }
        }
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }
}
