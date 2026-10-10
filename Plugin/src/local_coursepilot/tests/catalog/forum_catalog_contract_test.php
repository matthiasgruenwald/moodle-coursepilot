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
 * Catalog/Moodle contract for mod_forum (Ticket #381), following
 * resource_catalog_contract_test from #380.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(forum::class)]
#[CoversClass(\local_coursepilot\catalog\shared_block::class)]
final class forum_catalog_contract_test extends \advanced_testcase {
    /**
     * Cataloged columns exactly match the forum table. assesstimestart and
     * assesstimefinish are actual columns counted through forum::blocklist()
     * (see test_ratingtime_pseudofield_and_assesstime_blocklist).
     */
    public function test_forum_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('forum'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, forum::fields()),
            forum::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "The columns of table 'forum' and the field catalog (forum::fields()/blocklist()) have "
                . 'diverged - Moodle probably added, removed or renamed a column.'
        );
    }

    /**
     * All referenced callable sources exist, including
     * rating_manager::get_aggregate_types() (acceptance #381).
     */
    public function test_referenced_callable_sources_exist(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        require_once($CFG->dirroot . '/rating/lib.php');

        $fields = array_merge(
            shared_block::fields(),
            shared_block::pseudofields(),
            forum::fields(),
            forum::pseudofields()
        );

        $callables = array_filter(array_map(
            static fn (field $f): ?string => $f->sourcecallable,
            $fields
        ));

        $this->assertNotEmpty($callables, 'No field references a callable source; test assumption violated.');
        $this->assertContains(
            'rating_manager::get_aggregate_types()',
            $callables,
            'assessed must reference rating_manager::get_aggregate_types() instead of copying the values.'
        );
        $this->assertContains('forum_get_forum_types()', $callables);
        $this->assertContains('forum_get_subscriptionmode_options()', $callables);

        foreach ($callables as $callable) {
            if (str_contains($callable, '::')) {
                [$classname, $methodname] = explode('::', rtrim($callable, '()'), 2);
                $this->assertTrue(
                    method_exists($classname, $methodname),
                    "Referenced callable source $callable no longer exists on this instance."
                );
                continue;
            }
            $functionname = rtrim($callable, '()');
            $this->assertTrue(
                function_exists($functionname),
                "Referenced callable source $callable no longer exists on this instance."
            );
        }
    }

    /**
     * Acceptance #381: ratingtime is a pseudofield; assesstimestart and
     * assesstimefinish are blocklisted.
     */
    public function test_ratingtime_pseudofield_and_assesstime_blocklist(): void {
        $pseudonames = array_map(static fn (field $f): string => $f->name, forum::pseudofields());
        $this->assertContains('ratingtime', $pseudonames);

        $this->assertContains('assesstimestart', forum::blocklist());
        $this->assertContains('assesstimefinish', forum::blocklist());
    }

    /**
     * Acceptance #381: forcesubscribe=2 carries a side-effect warning about
     * email to all course participants.
     */
    public function test_forcesubscribe_side_effect_notes_mass_mail(): void {
        $notes = implode(' ', forum::side_effects());
        $this->assertStringContainsString('forcesubscribe', $notes);
        $this->assertStringContainsString('email', $notes);
        $this->assertStringContainsString('all', $notes);
        $this->assertStringContainsString('course participants', $notes);
    }

    /**
     * Calendar entry as a second side-effect note (Ticket #381).
     */
    public function test_side_effects_note_calendar_entries(): void {
        $notes = implode(' ', forum::side_effects());
        $this->assertStringContainsString('calendar entry', $notes);
    }

    /**
     * FORUM_INITIALSUBSCRIBE (2) exists on this instance, using
     * forum::checked_constants(), shared with runtime drift validation
     * (Ticket #399).
     */
    public function test_forum_initialsubscribe_constant_exists(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $this->assertSame(['FORUM_INITIALSUBSCRIBE'], forum::checked_constants());
        foreach (forum::checked_constants() as $constname) {
            $this->assertTrue(defined($constname), "Constant $constname no longer exists on this instance.");
        }
        $this->assertSame(2, FORUM_INITIALSUBSCRIBE);
    }
}
