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
 * Unknown activity columns fail closed without hiding intentional catalog fields.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(activity_credentials::class)]
final class activity_credentials_test extends \advanced_testcase {
    /**
     * Third-party credentials are omitted, including compound and uppercase column names.
     */
    public function test_unknown_module_credentials_fail_closed(): void {
        $state = [
            'name' => 'Synthetic activity', 'password' => 'a', 'clientsecret' => 'b',
            'service_salt' => 'c', 'api_key' => 'd', 'accessToken' => 'e', 'viewerpass' => 'f',
            'secret_value' => 'g',
        ];
        $this->assertSame(['name' => 'Synthetic activity'], activity_credentials::settings('thirdparty', $state));
    }

    /**
     * Catalogued passing grades are ordinary settings, while quiz access stays intentional.
     */
    public function test_reviewed_catalog_fields_remain_visible(): void {
        $this->assertFalse(activity_credentials::hidden('quiz', 'password'));
        $this->assertFalse(activity_credentials::hidden('quiz', 'gradepass'));
        $this->assertFalse(activity_credentials::hidden('page', 'name'));
    }
}
