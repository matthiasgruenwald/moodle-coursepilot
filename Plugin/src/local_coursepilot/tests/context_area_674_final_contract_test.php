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

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * A pointer error outranks the personal-data lock when the context area is written (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(context_area::class)]
final class context_area_674_final_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * A marked text is refused for a broken pointer with the pointer's own error, not as locked personal data.
     */
    public function test_marked_content_with_a_broken_pointer_reports_the_pointer_error(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        set_config('allowpersonaldata', 0, 'local_coursepilot');
        private_files_storage_port::write_content(
            '/' . storage_anchor::ANCHOR_DEFAULT_ROOT . '/',
            storage_anchor::POINTER_FILENAME,
            '{"context_area":"x"}'
        );

        $e = $this->caught(fn() => context_area::write('a.md', "---\ncoursepilot:\n  personenbezug: true\n---\nText\n"));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('pointerincomplete', $e->errorcode);
    }
}
