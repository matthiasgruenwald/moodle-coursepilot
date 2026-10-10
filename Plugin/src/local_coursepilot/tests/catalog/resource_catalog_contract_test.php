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
 * Catalog/Moodle contract for mod_resource (Ticket #380), following
 * label_catalog_contract_test from #379.
 * Check only resource, excluding the resource_old Moodle 1.9 migration archive.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(resource::class)]
#[CoversClass(\local_coursepilot\catalog\shared_block::class)]
final class resource_catalog_contract_test extends \advanced_testcase {
    /**
     * Catalog fields, real blocklisted columns and id exactly match the
     * resource table columns. Pseudofields do not count as database columns.
     */
    public function test_resource_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('resource'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, resource::fields()),
            resource::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "The columns of table 'resource' and the field catalog (resource::fields()/blocklist()) have "
                . 'diverged - Moodle probably added, removed or renamed a column.'
        );
    }

    /**
     * files is a required cataloged pseudofield without a default, no longer
     * blocked (Issue #434).
     */
    public function test_files_is_catalogued_required_and_unlocked(): void {
        $pseudofields = resource::pseudofields();
        $pseudonames = array_map(static fn (field $f): string => $f->name, $pseudofields);
        $this->assertContains('files', $pseudonames, '"files" must be fully cataloged.');

        $filesfield = current(array_filter($pseudofields, static fn (field $f): bool => $f->name === 'files'));
        $this->assertTrue($filesfield->required, '"files" must be required on creation.');
        $this->assertNull($filesfield->default, '"files" must not have a form default.');

        $this->assertNotContains('files', resource::blocklist(), '"files" must no longer be blocked.');
    }

    /**
     * The catalog explicitly requires files when creating resource
     * (Spec 0018 §4.2/§7).
     */
    public function test_side_effects_note_files_is_required(): void {
        $notes = implode(' ', resource::side_effects());
        $this->assertStringContainsString('"files"', $notes);
    }

    /**
     * revision and displayoptions are blocklisted (Ticket #380).
     */
    public function test_revision_and_displayoptions_are_blocked(): void {
        $this->assertContains('revision', resource::blocklist());
        $this->assertContains('displayoptions', resource::blocklist());
    }

    /**
     * Every referenced callable source exists.
     */
    public function test_referenced_callable_sources_exist(): void {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');

        $fields = array_merge(
            shared_block::fields(),
            shared_block::pseudofields(),
            resource::fields(),
            resource::pseudofields()
        );

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
}
