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
 * Catalog/Moodle contract for mod_choice (Ticket #381), following
 * resource_catalog_contract_test from #380.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(choice::class)]
#[CoversClass(\local_coursepilot\catalog\shared_block::class)]
final class choice_catalog_contract_test extends \advanced_testcase {

    /**
     * Cataloged columns exactly match the choice table.
     */
    public function test_choice_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('choice'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, choice::fields()),
            choice::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "Die Spalten der Tabelle 'choice' und der Feldkatalog (choice::fields()/blocklist()) sind "
                . 'auseinandergelaufen - Moodle hat vermutlich eine Spalte hinzugefuegt, entfernt oder umbenannt.'
        );
    }

    /**
     * Every referenced callable source actually exists.
     */
    public function test_referenced_callable_sources_exist(): void {
        $fields = array_merge(
            shared_block::fields(),
            shared_block::pseudofields(),
            choice::fields(),
            choice::pseudofields()
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

    /**
     * Acceptance #381: neither fields nor combination rules impose a
     * Coursepilot option-count limit. The legacy local create_choice limit
     * of 2-6 does not belong to this catalog.
     */
    public function test_no_coursepilot_option_upper_bound(): void {
        $allfieldnames = array_merge(
            array_map(static fn (field $f): string => $f->name, choice::fields()),
            array_map(static fn (field $f): string => $f->name, choice::pseudofields())
        );
        $this->assertNotContains('maxoptions', $allfieldnames);
        $this->assertNotContains('anzahloptionen', $allfieldnames);

        $rules = implode(' ', choice::combination_rules());
        $this->assertStringNotContainsString('2-6', $rules);
        $this->assertStringNotContainsString('at most', $rules);
    }

    /**
     * Matching limit[] and option[] lengths is a separate combination
     * rule (category 4), not a property of limit itself (#381).
     */
    public function test_limit_length_rule_is_a_combination_rule(): void {
        $rules = implode(' ', choice::combination_rules());
        $this->assertStringContainsString('limit', $rules);
        $this->assertStringContainsString('option', $rules);

        $limitfields = array_filter(
            choice::pseudofields(),
            static fn (field $f): bool => $f->name === 'limit'
        );
        $this->assertCount(1, $limitfields);
    }

    /**
     * Acceptance #381: publish documents the side effect of switching
     * from anonymous to named responses.
     */
    public function test_publish_side_effect_notes_the_anonymous_to_named_switch(): void {
        $notes = implode(' ', choice::side_effects());
        $this->assertStringContainsString('anonymous', $notes);
        $this->assertStringContainsString('named', $notes);
    }

    /**
     * Acceptance #381: allocation bundle contains exactly the six specified fields.
     */
    public function test_zuteilung_bundle_has_the_six_named_fields(): void {
        $bundles = choice::bundles();
        $this->assertArrayHasKey('allocation', $bundles);

        $zuteilung = $bundles['allocation'];
        $this->assertEqualsCanonicalizing(
            ['limitanswers', 'limit', 'publish', 'showresults', 'display', 'allowupdate'],
            array_keys($zuteilung)
        );
        $this->assertSame(1, $zuteilung['limitanswers']);
        $this->assertSame(1, $zuteilung['publish']); // CHOICE_PUBLISH_NAMES.
        $this->assertSame(3, $zuteilung['showresults']); // CHOICE_SHOWRESULTS_ALWAYS.
        $this->assertSame(1, $zuteilung['display']); // CHOICE_DISPLAY_VERTICAL.
        $this->assertSame(1, $zuteilung['allowupdate']);
    }

    /**
     * Acceptance #381: explicitly named fields override bundle defaults
     * (Spec 0015 §2.4), using array_merge(bundle, explicit).
     *
     * At the read-catalog stage (#381), this tests merge semantics for the later
     * phase-3 writer. Avoid creating apply_bundle() without a caller (YAGNI).
     */
    public function test_explicit_field_overrides_the_bundle(): void {
        $bundle = choice::bundles()['allocation'];

        // Pair work: explicit limit and publish values override allocation defaults.
        $explizit = ['limit' => 2, 'publish' => 0];
        $merged = array_merge($bundle, $explizit);

        $this->assertSame(2, $merged['limit'], 'Bundle overwrote explicit limit.');
        $this->assertSame(0, $merged['publish'], 'Bundle overwrote explicit publish.');
        // Unspecified bundle fields remain unchanged.
        $this->assertSame(1, $merged['limitanswers']);
        $this->assertSame(3, $merged['showresults']);
        $this->assertSame(1, $merged['display']);
        $this->assertSame(1, $merged['allowupdate']);
    }
}
