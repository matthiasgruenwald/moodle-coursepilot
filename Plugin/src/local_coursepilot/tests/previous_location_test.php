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

use local_coursepilot\tests\webdav\webdav_instance_fixture;

/**
 * The previous context location: visible while open, ended only by an explicit dismiss.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(previous_location::class)]
final class previous_location_test extends \advanced_testcase {
    use webdav_instance_fixture;

    /** @var \stdClass Signed-in test user. */
    private \stdClass $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
    }

    /**
     * Without a pointer document nothing is open and the switch is refused with a named error.
     */
    public function test_nothing_is_open_without_pointer(): void {
        $this->assertNull(previous_location::current());
        $this->assertFalse(previous_location::open());
        $this->assertFalse(previous_location::dismiss());
        try {
            previous_location::require_open_location();
            $this->fail('A closed previous location must not resolve.');
        } catch (\moodle_exception $e) {
            $this->assertSame('previouslocationclosed', $e->errorcode);
        }
    }

    /**
     * An open previous location resolves to its stored place, dismiss removes only that field.
     */
    public function test_open_location_resolves_and_dismiss_keeps_the_rest(): void {
        $this->write_pointer_with_previous_location($this->user, 'alter-kontext');

        $this->assertTrue(previous_location::open());
        $this->assertSame('alter-kontext', previous_location::current()['path']);
        $this->assertSame('/alter-kontext/', previous_location::require_open_location()->path);

        $this->assertTrue(previous_location::dismiss());

        $this->assertFalse(previous_location::open());
        $document = storage_anchor::read_raw_pointer();
        $this->assertArrayNotHasKey('previous_location', $document);
        $this->assertSame('coursepilot', $document['context_area']['path']);
        $this->assertFalse(previous_location::dismiss(), 'A second dismiss finds nothing to close.');
    }

    /**
     * A malformed field counts as closed rather than as an open location.
     */
    public function test_non_array_field_counts_as_closed(): void {
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [],
            'previous_location' => 'broken',
        ]);

        $this->assertNull(previous_location::current());
        $this->assertFalse(previous_location::open());
    }
}
