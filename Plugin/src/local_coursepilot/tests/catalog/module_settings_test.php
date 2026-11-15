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
 * The public catalog write operation preserves native activity state.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(module_settings::class)]
final class module_settings_test extends \advanced_testcase {
    /**
     * A patch changes only the requested setting and reports the real difference.
     */
    public function test_patch_preserves_other_settings_and_reports_the_change(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Original',
            'content' => '<p>Keep this content</p>',
        ]);

        $result = module_settings::write($page->cmid, '{"name":"Renamed"}');
        $state = json_decode(module_state::read_settings($page->cmid)['settings_json'], true);

        $this->assertSame('Renamed', $state['name']);
        $this->assertSame('<p>Keep this content</p>', $state['content']);
        $this->assertSame([[
            'field' => 'name',
            'before_json' => '"Original"',
            'after_json' => '"Renamed"',
        ]], $result['changes']);
        $this->assertSame('Changed: "name" from "Original" to "Renamed".', $result['message']);
        $this->assertSame([], $result['side_effects']);
    }
}
