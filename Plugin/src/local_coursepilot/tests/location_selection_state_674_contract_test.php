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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The page state reports location permission and each history entry exactly (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(location_selection::class)]
final class location_selection_state_674_contract_test extends \advanced_testcase {
    /**
     * History entries as stored, with the state a client sees.
     *
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function history_entries(): array {
        return [
            'complete entry with a stored date string' => [
                ['date' => '1700000001', 'target' => 'context_area', 'from' => 'old', 'to' => 'new'],
                ['at' => 1700000001, 'target' => 'context_area', 'from' => 'old', 'to' => 'new'],
            ],
            'complete entry without date and target' => [
                ['from' => 'old', 'to' => 'new'],
                ['at' => 0, 'target' => '', 'from' => 'old', 'to' => 'new'],
            ],
            'legacy entry with a stored date string and numeric target' => [
                ['date' => '1700000002', 'target' => 7],
                ['at' => 1700000002, 'target' => '7', 'state' => 'legacy'],
            ],
            'legacy entry without date and target' => [
                ['from' => 'only the source'],
                ['at' => 0, 'target' => '', 'state' => 'legacy'],
            ],
        ];
    }

    /**
     * Stores one history entry and reads the page state as a teacher would see it.
     *
     * @param mixed[] $entry
     * @return array<string, mixed>
     */
    private function history_state_of(array $entry): array {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [$entry],
        ]);

        return location_selection::page_state((int) $user->id)['history'][0];
    }

    /**
     * Reports date as an integer, target as a string and the legacy marker only for old entries.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $expected
     */
    #[DataProvider('history_entries')]
    public function test_a_history_entry_is_reported_with_typed_values(array $entry, array $expected): void {
        $this->resetAfterTest();

        $this->assertSame($expected, $this->history_state_of($entry));
    }

    /**
     * Private Files are always allowed for personal data.
     */
    public function test_moodle_locations_are_reported_as_allowed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $locations = location_selection::page_state((int) $user->id)['locations'];

        $this->assertSame('allowed', $locations['context_area']['allowed']);
        $this->assertSame('allowed', $locations['material_store']['allowed']);
    }
}
