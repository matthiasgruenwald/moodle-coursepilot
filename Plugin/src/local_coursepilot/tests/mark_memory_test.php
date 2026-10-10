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

/**
 * Marking memory (Issue #493, Spec #486 §6), independently of
 * list_context_files: path/size/modification-time/ETag keys, hits,
 * misses after changes and user isolation.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mark_memory::class)]
final class mark_memory_test extends \advanced_testcase {
    /**
     * lookup() returns null before remember().
     */
    public function test_lookup_without_prior_remember_returns_null(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertNull(mark_memory::lookup('lerngruppe.md', 42, 100, 'etag-1'));
    }

    /**
     * Return the remembered bit when the key is unchanged.
     */
    public function test_remembered_bit_is_returned_when_key_matches(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        mark_memory::remember('lerngruppe.md', 42, 100, 'etag-1', true);

        $this->assertTrue(mark_memory::lookup('lerngruppe.md', 42, 100, 'etag-1'));
    }

    /**
     * Remember unmarked files as well as marked files.
     */
    public function test_remembered_unmarked_bit_is_returned(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        mark_memory::remember('sachtext.md', 10, 50, null, false);

        $this->assertFalse(mark_memory::lookup('sachtext.md', 10, 50, null));
    }

    /**
     * Changed size invalidates the key; lookup() returns null so callers reread.
     */
    public function test_changed_size_invalidates_the_entry(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        mark_memory::remember('lerngruppe.md', 42, 100, 'etag-1', true);

        $this->assertNull(mark_memory::lookup('lerngruppe.md', 99, 100, 'etag-1'));
    }

    /**
     * Changed modification time invalidates the key.
     */
    public function test_changed_timemodified_invalidates_the_entry(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        mark_memory::remember('lerngruppe.md', 42, 100, 'etag-1', true);

        $this->assertNull(mark_memory::lookup('lerngruppe.md', 42, 200, 'etag-1'));
    }

    /**
     * Changed ETag invalidates the key even if size and time remain equal.
     */
    public function test_changed_etag_invalidates_the_entry(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        mark_memory::remember('lerngruppe.md', 42, 100, 'etag-1', true);

        $this->assertNull(mark_memory::lookup('lerngruppe.md', 42, 100, 'etag-2'));
    }

    /**
     * remember() replaces an existing entry for the same path instead
     * of creating a second one.
     */
    public function test_remember_overwrites_existing_entry(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        mark_memory::remember('lerngruppe.md', 42, 100, 'etag-1', true);
        mark_memory::remember('lerngruppe.md', 50, 200, 'etag-2', false);

        $this->assertSame(1, $DB->count_records('local_coursepilot_context_mark'));
        $this->assertFalse(mark_memory::lookup('lerngruppe.md', 50, 200, 'etag-2'));
    }

    /**
     * Users have separate entries for the same client path.
     */
    public function test_entries_are_isolated_per_user(): void {
        $this->resetAfterTest();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $this->setUser($usera);
        mark_memory::remember('lerngruppe.md', 42, 100, 'etag-1', true);

        $this->setUser($userb);
        $this->assertNull(mark_memory::lookup('lerngruppe.md', 42, 100, 'etag-1'));
    }
}
