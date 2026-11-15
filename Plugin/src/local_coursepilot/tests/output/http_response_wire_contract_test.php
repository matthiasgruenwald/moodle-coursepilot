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

use local_coursepilot\tests\header_spy;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * What http_response sends: status, header lines and body bytes (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(http_response::class)]
final class http_response_wire_contract_test extends \basic_testcase {
    public function test_send_delivers_status_each_header_and_the_exact_bytes(): void {
        require_once(__DIR__ . '/../fixtures/output_header_spy.php');
        header_spy::reset();
        $response = new http_response(201, ['X-First' => 'a b', 'Cache-Control' => 'no-store'], "BYTES\0X");

        ob_start();
        try {
            $response->send();
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame(201, header_spy::$status);
        $this->assertSame(['X-First: a b', 'Cache-Control: no-store'], header_spy::$headers);
        $this->assertSame("BYTES\0X", $output);
    }
}
