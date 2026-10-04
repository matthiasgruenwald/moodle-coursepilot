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
 * Catalog/Moodle contract for mod_page (Ticket #380), following
 * label_catalog_contract_test from #379.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(page::class)]
#[CoversClass(\local_coursepilot\catalog\shared_block::class)]
final class page_catalog_contract_test extends \advanced_testcase {

    /**
     * Catalog fields, real blocklisted columns and id exactly match the
     * page table columns. Pseudofields do not count as database columns.
     */
    public function test_page_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('page'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, page::fields()),
            page::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "Die Spalten der Tabelle 'page' und der Feldkatalog (page::fields()/blocklist()) sind "
                . 'auseinandergelaufen - Moodle hat vermutlich eine Spalte hinzugefuegt, entfernt oder umbenannt.'
        );
    }

    /**
     * printheading was removed in Moodle 5.0 and must remain absent from
     * the catalog (Ticket #380).
     */
    public function test_printheading_is_absent(): void {
        $names = array_column(
            array_map(static fn (field $f): array => $f->to_array(), array_merge(page::fields(), page::pseudofields())),
            'name'
        );
        $this->assertNotContains('printheading', $names);
    }

    /**
     * display is an actual column rather than a pseudofield (Spec 0015 §2.2).
     * Guard against incorrectly reclassifying it.
     */
    public function test_display_is_a_real_field_not_a_pseudofield(): void {
        $fieldnames = array_map(static fn (field $f): string => $f->name, page::fields());
        $pseudonames = array_map(static fn (field $f): string => $f->name, page::pseudofields());

        $this->assertContains('display', $fieldnames);
        $this->assertNotContains('display', $pseudonames);
    }

    /**
     * Jede referenzierte aufrufbare Quelle existiert wirklich.
     */
    public function test_referenced_callable_sources_exist(): void {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');

        $fields = array_merge(shared_block::fields(), shared_block::pseudofields(), page::fields(), page::pseudofields());

        $callables = array_filter(array_map(
            static fn (field $f): ?string => $f->sourcecallable,
            $fields
        ));

        $this->assertNotEmpty($callables, 'Kein Feld referenziert eine aufrufbare Quelle - Testannahme verletzt.');

        foreach ($callables as $callable) {
            $functionname = rtrim($callable, '()');
            $this->assertTrue(
                function_exists($functionname),
                "Referenzierte aufrufbare Quelle $callable existiert auf dieser Instanz nicht mehr."
            );
        }
    }

    /**
     * Constants from page::checked_constants() still exist, using the
     * same source as runtime drift validation (Ticket #399).
     */
    public function test_resourcelib_display_constants_exist(): void {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');

        $this->assertSame(['RESOURCELIB_DISPLAY_POPUP'], page::checked_constants());
        foreach (page::checked_constants() as $constname) {
            $this->assertTrue(defined($constname), "Konstante $constname existiert auf dieser Instanz nicht mehr.");
        }
        $this->assertSame(6, RESOURCELIB_DISPLAY_POPUP);
    }
}
