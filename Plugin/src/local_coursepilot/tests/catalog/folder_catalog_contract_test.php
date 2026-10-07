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
 * Catalog/Moodle contract for mod_folder (Ticket #380), following
 * label_catalog_contract_test from #379.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(folder::class)]
#[CoversClass(\local_coursepilot\catalog\shared_block::class)]
final class folder_catalog_contract_test extends \advanced_testcase {

    /**
     * Catalog fields, real blocklisted columns and id exactly match the
     * folder table columns. Pseudofields do not count as database columns.
     */
    public function test_folder_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('folder'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, folder::fields()),
            folder::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "The columns of table 'folder' and the field catalog (folder::fields()/blocklist()) have "
                . 'diverged - Moodle probably added, removed or renamed a column.'
        );
    }

    /**
     * files is a fully cataloged optional pseudofield, no longer blocked
     * (Issue #434).
     */
    public function test_files_is_catalogued_optional_and_unlocked(): void {
        $pseudofields = folder::pseudofields();
        $pseudonames = array_map(static fn (field $f): string => $f->name, $pseudofields);
        $this->assertContains('files', $pseudonames, '"files" must be fully cataloged.');

        $filesfield = current(array_filter($pseudofields, static fn (field $f): bool => $f->name === 'files'));
        $this->assertFalse($filesfield->required, '"files" must stay optional on creation (empty folder valid).');

        $this->assertNotContains('files', folder::blocklist(), '"files" must no longer be blocked.');
    }

    /**
     * The catalog explicitly notes that an empty folder can be created,
     * unlike resource without a file (Spec 0015 §4.3).
     */
    public function test_side_effects_note_folder_stays_creatable(): void {
        $notes = implode(' ', folder::side_effects());
        $this->assertStringContainsString('created', $notes);
    }

    /**
     * Constants from folder::checked_constants() still exist, using the
     * same source as runtime drift validation (Ticket #399).
     */
    public function test_folder_display_constants_exist(): void {
        $this->assertSame(['FOLDER_DISPLAY_PAGE', 'FOLDER_DISPLAY_INLINE'], folder::checked_constants());
        foreach (folder::checked_constants() as $constname) {
            $this->assertTrue(defined($constname), "Constant $constname no longer exists on this instance.");
        }
        $this->assertSame([0, 1], [FOLDER_DISPLAY_PAGE, FOLDER_DISPLAY_INLINE]);
    }
}
