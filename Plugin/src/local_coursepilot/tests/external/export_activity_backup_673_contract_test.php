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

use local_coursepilot\tests\plugin_support_override;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/plugin_supports_override_functions.php');

/**
 * An activity type without backup support is refused with its name (#673).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(export_activity_backup::class)]
final class export_activity_backup_673_contract_test extends \advanced_testcase {
    /**
     * Restores the real plugin_supports() answers.
     */
    protected function tearDown(): void {
        plugin_support_override::reset();
        parent::tearDown();
    }

    /**
     * The refusal names the activity type and nothing is exported.
     */
    public function test_an_activity_type_without_backup_support_is_refused_naming_the_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        plugin_support_override::$unsupported['page'] = true;

        try {
            export_activity_backup::execute((int) $page->cmid);
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('clonenobackupsupport', $e->errorcode);
            $this->assertSame('local_coursepilot', $e->module);
            $this->assertSame(['modname' => 'page'], $e->a);
            $this->assertSame(
                get_string('clonenobackupsupport', 'local_coursepilot', ['modname' => 'page']),
                $e->getMessage()
            );
        }
    }
}
