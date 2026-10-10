<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

/**
 * Location-neutral failure translation (Issue #540, ADR 0023).
 * webdav_storage_port, context_area's Moodle branch and pointer_writer
 * share record_and_translate(). Test this translator independently
 * of WebDAV and Private Files.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(pending_write_translation::class)]
final class pending_write_translation_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * Record a pending entry with identifier, timestamp, path, operation
     * and error kind, never content (#540, criterion 1), then build the
     * five-part outage response.
     */
    public function test_records_an_entry_and_builds_the_five_part_message(): void {
        $exception = pending_write_translation::record_and_translate(
            'meinefehlerklasse',
            'interne Rohmeldung, nur fuers Zugriffsprotokoll',
            'plan.md',
            pending_write_translation::OP_OVERWRITE,
            'die Ursache in Lehrkraftsprache',
            'das Ziel',
            42
        );

        $this->assertSame('pendingwritefailed', $exception->errorcode);
        $message = $exception->getMessage();
        $this->assertStringContainsString('plan.md', $message);
        $this->assertStringContainsString('overwrite', $message);
        $this->assertStringContainsString('die Ursache in Lehrkraftsprache', $message);
        $this->assertStringContainsString('das Ziel', $message);
        $this->assertStringNotContainsString('interne Rohmeldung', $message);

        $ausstaende = pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('plan.md', $ausstaende[0]['path']);
        $entry = $ausstaende[0]['entries'][0];
        $this->assertSame(pending_write_translation::OP_OVERWRITE, $entry['operation']);
        $this->assertSame('meinefehlerklasse', $entry['error_class']);
        $this->assertSame(42, $entry['course_id']);
        $this->assertGreaterThan(0, $entry['timestamp']);
        $this->assertStringContainsString($entry['identifier'], $message);
    }

    /**
     * If Private Files is full and the note cannot be saved, explicitly
     * report that rather than hiding the original failure (ADR 0023 consequences).
     */
    public function test_reports_when_the_note_itself_cannot_be_written(): void {
        global $CFG;
        $CFG->userquota = 1;

        $exception = pending_write_translation::record_and_translate(
            'meinefehlerklasse',
            'interne Rohmeldung',
            'plan.md',
            pending_write_translation::OP_CREATE,
            'Ursache',
            'Ziel',
            0
        );

        $this->assertSame('pendingnotewritefailed', $exception->errorcode);
        $this->assertSame([], pending_write_notice::list_grouped());
    }
}
