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

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Reserved fields retain their specific recovery route at the client boundary.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_module_settings::class)]
#[CoversClass(\local_coursepilot\catalog\catalog_fields::class)]
#[CoversClass(\local_coursepilot\catalog\module_settings::class)]
#[CoversClass(\local_coursepilot\catalog\write_target::class)]
#[CoversClass(\local_coursepilot\catalog\page::class)]
final class update_module_write_contract_test extends \advanced_testcase {
    /**
     * Rejected writes distinguish reserved metadata from the dedicated completion route.
     *
     * @param string $field
     * @param int $value
     * @param string $errorcode
     */
    #[DataProvider('reserved_fields')]
    public function test_reserved_field_keeps_its_specific_failure(string $field, int $value, string $errorcode): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Unchanged']);
        try {
            update_module_settings::execute($page->cmid, json_encode([$field => $value]));
            $this->fail('Reserved fields must not be written.');
        } catch (\moodle_exception $error) {
            $this->assertSame($errorcode, $error->errorcode);
        }
        $result = get_module_settings::execute($page->cmid);
        $result = external_api::clean_returnvalue(get_module_settings::execute_returns(), $result);
        $this->assertSame('Unchanged', json_decode($result['settings_json'], true)['name']);
    }

    /**
     * Shared metadata and completion have different documented recovery actions.
     *
     * @return array<string, array{string, int, string}>
     */
    public static function reserved_fields(): array {
        return [
            'timestamp' => ['timemodified', 123, 'blockedfield'],
            'completion' => ['completionview', 1, 'completionfieldviasetcompletion'],
            'course ownership' => ['course', 2, 'blockedfield'],
            'native page revision' => ['revision', 2, 'blockedfield'],
        ];
    }
}
