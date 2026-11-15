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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The teacher-facing error text and the diagnostic detail are trimmed after the debug suffix is removed (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(dispatcher::class)]
final class dispatcher_error_text_674_contract_test extends \advanced_testcase {
    /**
     * Calls a private static dispatcher method.
     *
     * @param string $name Method name.
     * @param \stdClass $exception Exception information.
     * @return mixed The result.
     */
    private function call(string $name, \stdClass $exception): mixed {
        return (new \ReflectionMethod(dispatcher::class, $name))->invoke(null, $exception);
    }

    public function test_the_invalid_parameter_text_loses_the_error_code_suffix_and_surrounding_whitespace(): void {
        $exception = (object) [
            'errorcode' => 'invalidparameter',
            'message' => 'Invalid parameter value detected',
            'debuginfo' => "  Field x is wrong  \n\nError code: invalidparameter\n",
        ];

        $this->assertSame('Field x is wrong', $this->call('error_message', $exception));
    }

    public function test_the_diagnostic_detail_loses_the_error_code_suffix_and_surrounding_whitespace(): void {
        $exception = (object) [
            'errorcode' => 'invalidresponse',
            'message' => 'Invalid response value detected',
            'debuginfo' => "\n  Return value broken  \nError code: invalidresponse",
        ];

        $this->assertSame('Return value broken', $this->call('diagnostic_detail', $exception));
    }
}
