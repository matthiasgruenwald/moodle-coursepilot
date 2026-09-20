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

use core_external\external_function_parameters;
use core_external\external_value;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Vertrag: MCP-Schema und die von Moodle validierte Parameterbeschreibung
 * stammen aus genau derselben Deklaration.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(external_schema_converter::class)]
final class tool_schema_contract_test extends \advanced_testcase {

    public function test_converter_exposes_required_defaults_and_integer_types(): void {
        $parameters = new external_function_parameters([
            'requiredinteger' => new external_value(PARAM_INT, 'Required integer'),
            'optionalinteger' => new external_value(PARAM_INT, 'Optional integer', VALUE_DEFAULT, 7),
            'optionalstring' => new external_value(PARAM_TEXT, 'Optional string', VALUE_DEFAULT, 'default'),
        ]);

        $schema = external_schema_converter::from_parameters($parameters);

        $this->assertSame(['requiredinteger'], $schema['required']);
        $this->assertSame('integer', $schema['properties']['requiredinteger']['type']);
        $this->assertSame(7, $schema['properties']['optionalinteger']['default']);
        $this->assertSame('default', $schema['properties']['optionalstring']['default']);
    }

    public function test_every_registered_tool_schema_matches_moodle_parameters(): void {
        $schemas = tool_registry::schemas();
        $tools = tool_registry::allowed_tools();
        $functions = tool_registry::service_functions();

        $this->assertCount(49, $tools);
        $this->assertSame(array_keys($tools), array_keys($schemas));

        foreach ($tools as $name => $tool) {
            $classname = $functions[$tool]['classname'];
            $parameters = $classname::execute_parameters();

            $this->assertSame(
                external_schema_converter::from_parameters($parameters),
                $schemas[$name],
                "{$name}: MCP-Schema weicht von execute_parameters() ab."
            );
        }
    }
}
