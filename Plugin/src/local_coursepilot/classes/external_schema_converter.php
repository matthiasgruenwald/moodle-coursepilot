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
     * Builds MCP input properties and required names from Moodle's parameter declaration.
     *
     * Nested declarations retain their schema types; the root object type is omitted
     * because the dispatcher supplies it when publishing the tool's input schema.
     *
     * @param external_function_parameters $parameters Named inputs declared by an external function.
     * @return array{properties: array<string, array>, required?: string[]} Input schemas and any required input names.
     */
    public static function from_parameters(external_function_parameters $parameters): array {
        $schema = self::from_structure($parameters);
        unset($schema['type']);
        return $schema;
    }

    /**
     * Converts a Moodle value, repeated structure or single structure into a JSON Schema fragment.
     *
     * Integer, float and boolean values map to their JSON types; other values map to strings.
     * Repeated structures recursively become array items, single structures become object properties.
     * The declaration's description is preserved, with a default only for VALUE_DEFAULT.
     *
     * @param external_description $description Moodle declaration for one value or nested structure.
     * @return array<string, mixed> Schema fragment including its type, description and any declared default.
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
     * Builds an object schema by recursively converting each named member declaration.
     *
     * Only VALUE_REQUIRED members enter the required list; the list is omitted when empty.
     *
     * @param external_single_structure $structure Named member declarations, including external-function parameters.
     * @return array{type: string, properties: array<string, array>, required?: string[]} Object schema and required names.
     */
    private static function from_structure(external_single_structure $structure): array {
        $schema = ['type' => 'object', 'properties' => []];
        $required = [];
        foreach ($structure->keys as $name => $description) {
            $schema['properties'][$name] = self::from_description($description);
            if ($description->required === VALUE_REQUIRED) {
                $required[] = $name;
            }
        }
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }
}
