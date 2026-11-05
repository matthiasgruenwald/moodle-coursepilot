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
namespace local_coursepilot\tests;

use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Asserts that a raw (not cleaned) external function result already has the types its declaration promises.
 *
 * clean_returnvalue() silently converts numeric strings to integers, so a test that cleans first cannot
 * tell whether the function itself delivered a number. This walks the declaration instead.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
trait declared_return_types {
    /**
     * Asserts the value tree against its declaration.
     *
     * @param mixed $value Raw value returned by execute().
     * @param external_description $description Declaration from execute_returns().
     * @param string $path Location for the failure message.
     */
    protected function assert_declared_types(mixed $value, external_description $description, string $path = 'result'): void {
        if ($description instanceof external_value) {
            $this->assert_declared_scalar($value, $description, $path);
            return;
        }
        $this->assertIsArray($value, "{$path} must be an array");
        if ($description instanceof external_multiple_structure) {
            $this->assertTrue(array_is_list($value), "{$path} must be a list");
            foreach ($value as $index => $item) {
                $this->assert_declared_types($item, $description->content, "{$path}[{$index}]");
            }
            return;
        }
        $this->assertInstanceOf(external_single_structure::class, $description);
        $this->assertSame([], array_diff(array_keys($value), array_keys($description->keys)), "{$path} has undeclared keys");
        foreach ($description->keys as $key => $child) {
            if (!array_key_exists($key, $value)) {
                $this->assertNotSame(VALUE_REQUIRED, $child->required, "{$path}.{$key} is required but missing");
                continue;
            }
            $this->assert_declared_types($value[$key], $child, "{$path}.{$key}");
        }
    }

    /**
     * Asserts one scalar against its declared parameter type.
     *
     * @param mixed $value
     * @param external_value $description
     * @param string $path
     */
    private function assert_declared_scalar(mixed $value, external_value $description, string $path): void {
        if ($value === null) {
            $this->assertSame(NULL_ALLOWED, $description->allownull, "{$path} is null but null is not allowed");
            return;
        }
        match ($description->type) {
            PARAM_INT => $this->assertIsInt($value, "{$path} must be an int"),
            PARAM_FLOAT => $this->assertTrue(is_int($value) || is_float($value), "{$path} must be a number"),
            PARAM_BOOL => $this->assertIsBool($value, "{$path} must be a bool"),
            default => $this->assertIsString($value, "{$path} must be a string"),
        };
    }
}
