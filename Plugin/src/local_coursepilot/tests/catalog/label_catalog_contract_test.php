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

namespace local_coursepilot\catalog;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Catalog/Moodle contract (Spec 0015, validation seam 2 from #377). Check
 * label and course_modules columns and callable sources on the running
 * instance, following privacy_surface_test. Fail when an upgrade adds,
 * removes or renames a column or removes a referenced function/constant.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(label::class)]
#[CoversClass(\local_coursepilot\catalog\shared_block::class)]
final class label_catalog_contract_test extends \advanced_testcase {
    /**
     * Catalog fields, module and shared blocklist columns, and id exactly
     * match the label table, without missing or extra columns.
     */
    public function test_label_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('label'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, label::fields()),
            label::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "The columns of table 'label' and the field catalog (label::fields()/blocklist()) have "
                . 'diverged - Moodle probably added, removed or renamed a column.'
        );
    }

    /**
     * The shared block references actual course_modules columns.
     */
    public function test_shared_block_columns_exist_on_course_modules(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('course_modules'));
        $blockfields = array_map(static fn (field $f): string => $f->name, shared_block::fields());

        // sectionnum maps to the section column (course/modlib.php:799).
        // Check it separately rather than as a matching column name.
        $expecteddbcolumns = array_diff($blockfields, ['sectionnum']);

        foreach ($expecteddbcolumns as $name) {
            $this->assertContains(
                $name,
                $realcolumns,
                "Shared-block column course_modules.$name no longer exists."
            );
        }
        $this->assertContains('section', $realcolumns, 'Spalte course_modules.section (Abschnittszuordnung) fehlt.');
    }

    /**
     * Every callable field source exists, checking callability rather than
     * just field names (acceptance #379).
     */
    public function test_referenced_callable_sources_exist(): void {
        $fields = array_merge(shared_block::fields(), shared_block::pseudofields(), label::fields(), label::pseudofields());

        $callables = array_filter(array_map(
            static fn (field $f): ?string => $f->sourcecallable,
            $fields
        ));

        $this->assertNotEmpty($callables, 'No field references a callable source; test assumption violated.');

        foreach ($callables as $callable) {
            $functionname = rtrim($callable, '()');
            $this->assertTrue(
                function_exists($functionname),
                "Referenced callable source $callable no longer exists on this instance."
            );
        }
    }

    /**
     * Group-mode constants from shared_block::checked_constants() still
     * exist, using the same source as runtime drift validation (Ticket #399).
     */
    public function test_shared_block_group_mode_constants_exist(): void {
        $this->assertSame(['NOGROUPS', 'SEPARATEGROUPS', 'VISIBLEGROUPS'], shared_block::checked_constants());
        foreach (shared_block::checked_constants() as $constname) {
            $this->assertTrue(defined($constname), "Constant $constname no longer exists on this instance.");
        }
        $this->assertSame([0, 1, 2], [NOGROUPS, SEPARATEGROUPS, VISIBLEGROUPS]);
    }
}
