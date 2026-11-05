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


namespace local_coursepilot\catalog;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Template names of numbered parameter and variable fields match the whole field name (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(catalog_fields::class)]
final class catalog_fields_template_name_674_contract_test extends \advanced_testcase {
    /**
     * Calls the private template_name().
     *
     * @param string $fieldname Field name.
     * @return string
     */
    private function template_name(string $fieldname): string {
        return (new \ReflectionMethod(catalog_fields::class, 'template_name'))->invoke(null, $fieldname);
    }

    public function test_numbered_parameter_and_variable_fields_become_templates(): void {
        $this->assertSame('parameter_N', $this->template_name('parameter_12'));
        $this->assertSame('variable_N', $this->template_name('variable_3'));
    }

    public function test_a_name_with_a_prefix_before_the_keyword_is_not_a_template(): void {
        $this->assertSame('myparameter_1', $this->template_name('myparameter_1'));
    }

    public function test_a_name_with_a_suffix_after_the_number_is_not_a_template(): void {
        $this->assertSame('parameter_1x_2', $this->template_name('parameter_1x_2'));
    }
}
