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
namespace local_coursepilot\output;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The failure bodies of the download endpoint are plain JSON with escaped slashes and raw angle brackets (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(http_endpoint::class)]
final class http_endpoint_json_674_contract_test extends \advanced_testcase {
    /**
     * Customises the invalid-ticket string for the English language.
     */
    private function customise_invalid_ticket_string(): void {
        global $CFG;
        $dir = $CFG->dataroot . '/lang/en_local';
        make_writable_directory($dir);
        file_put_contents($dir . '/local_coursepilot.php', "<?php\n\$string['workbenchticketinvalid'] = 'Bad <b> / ticket';\n");
        get_string_manager()->reset_caches(true);
    }

    public function test_the_empty_ticket_failure_keeps_angle_brackets_and_escapes_slashes(): void {
        $this->resetAfterTest();
        $this->customise_invalid_ticket_string();

        $response = http_endpoint::download('');

        $this->assertSame(404, $response->status);
        $this->assertSame('{"error":"Bad <b> \\/ ticket"}', $response->body);
    }

    public function test_the_redemption_failure_keeps_angle_brackets_and_escapes_slashes(): void {
        $this->resetAfterTest();
        set_config('remoteaccessenabled', 1, 'local_coursepilot');
        $this->customise_invalid_ticket_string();

        $response = http_endpoint::download('never-issued-ticket');

        $this->assertSame(403, $response->status);
        $this->assertSame('{"error":"Bad <b> \\/ ticket"}', $response->body);
    }
}
