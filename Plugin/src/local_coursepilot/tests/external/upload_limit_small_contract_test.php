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

use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\isolated_php_ini;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A material upload above the interpreter's upload limit is refused by the tool itself (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(upload_material_file::class)]
#[RunTestsInSeparateProcesses]
final class upload_limit_small_contract_test extends \advanced_testcase {
    use caught_throwable;
    use isolated_php_ini;

    /**
     * Upload limits of the child interpreters.
     *
     * @return string
     */
    protected static function ini_settings(): string {
        return "upload_max_filesize=1K\npost_max_size=1K\n";
    }

    public function test_content_above_the_server_limit_is_refused_and_content_below_it_is_stored(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertSame(1024, get_max_upload_file_size(), 'The isolated interpreter must limit uploads to 1 KiB.');

        $stored = $this->succeeded(fn() => upload_material_file::execute('small.txt', base64_encode(str_repeat('x', 1000))));
        $refused = $this->caught(fn() => upload_material_file::execute('large.txt', base64_encode(str_repeat('x', 1025))));

        $this->assertSame('small.txt', $stored['path']);
        $this->assertInstanceOf(\moodle_exception::class, $refused);
        $this->assertSame('materialfiletoolarge', $refused->errorcode);
        $this->assertEquals((object) ['size' => 1025, 'max' => 1024], $refused->a);
    }
}
