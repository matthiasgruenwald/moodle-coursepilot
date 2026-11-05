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

namespace local_coursepilot\history;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Which file areas of an activity belong to its restorable design.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(file_policy::class)]
final class file_policy_test extends \advanced_testcase {
    public function test_the_intro_area_of_the_activity_itself_is_allowed(): void {
        $this->assertTrue(file_policy::allows('page', 'mod_page', 'intro'));
    }

    public function test_foreign_components_and_uncatalogued_activity_types_are_refused(): void {
        $this->assertFalse(file_policy::allows('page', 'mod_forum', 'intro'));
        $this->assertFalse(file_policy::allows('data', 'mod_data', 'content'));
        $this->assertFalse(file_policy::allows('page', 'mod_page', 'unbekannter_bereich'));
    }

    public function test_material_reference_areas_of_catalogued_activities_are_allowed(): void {
        $specs = \local_coursepilot\catalog\registry::for('assign')::write_options()['material_reference_fields'] ?? [];

        $this->assertNotEmpty($specs);
        foreach ($specs as $spec) {
            $this->assertTrue(file_policy::allows('assign', $spec['component'], $spec['filearea']));
        }
    }
}
