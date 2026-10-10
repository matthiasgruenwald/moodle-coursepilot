<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\catalog;

use moodle_exception;

/**
 * A catalog field (Spec 0015 §2.2, category 1 "fields" and category 2
 * "pseudofields" - same shape, different list).
 *
 * Always carries a meaning (acceptance criterion #379: "no field is
 * delivered with only an English name") and a source reference: where
 * Moodle has a callable source, its name is in $sourcecallable -
 * otherwise $source is the literal file:line reference (Spec 0015 §2.2).
 *
 * The PHP identifiers of this class are English (CLAUDE.md); since #569 the
 * delivered JSON keys in {@see to_array()} are English as well - the actual
 * teacher/AI contract remains the meaning ("meaning"), not the key name
 * itself (#379).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class field {
    /**
     * Field specifications from JSON objects must have string keys.
     *
     * @param mixed $fieldname
     * @return void
     * @throws moodle_exception invalidfieldname
     */
    public static function assert_name($fieldname): void {
        if (!is_string($fieldname)) {
            throw new moodle_exception('invalidfieldname', 'local_coursepilot');
        }
    }

    /**
     * @param string $name Moodle field name (form path contract).
     * @param string $type PARAM_* constant or short description of the type.
     * @param string $meaning Meaning for the teacher/AI.
     * @param bool $required Required field without a default?
     * @param mixed $default Form default, null if none exists.
     * @param array|null $values Allowed values, literal - null if only determinable via
     *        $sourcecallable.
     * @param string|null $sourcecallable Name of a callable Moodle source
     *        for the value range, e.g. "format_text_menu()".
     * @param string $source File:line reference - always given, even if
     *        $sourcecallable is set (where the function itself lives).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $meaning,
        public readonly bool $required,
        public readonly mixed $default,
        public readonly ?array $values,
        public readonly ?string $sourcecallable,
        public readonly string $source,
    ) {
    }

    /**
     * JSON-encodes default and value list, because Moodle's external API declares
     * exactly one PARAM_* type per field - "default" can be int, string, bool
     * or null here depending on the catalog field (#379).
     *
     * @return array{name: string, type: string, meaning: string, required: bool,
     *     default_json: string, value_range: array{values_json: string, source_callable: ?string, source: string}}
     */
    public function to_array(): array {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'meaning' => $this->meaning,
            'required' => $this->required,
            'default_json' => json_encode($this->default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'value_range' => [
                'values_json' => json_encode($this->values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_callable' => $this->sourcecallable,
                'source' => $this->source,
            ],
        ];
    }
}
