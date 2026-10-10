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
 * Catalog/Moodle contract for mod_assign (Ticket #382), following
 * forum_catalog_contract_test from #381.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(assign::class)]
#[CoversClass(\local_coursepilot\catalog\shared_block::class)]
final class assign_catalog_contract_test extends \advanced_testcase {
    /**
     * Cataloged columns, including nosubmissions, revealidentities and
     * completionsubmit from assign::blocklist(), exactly match the assign table.
     */
    public function test_assign_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('assign'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, assign::fields()),
            assign::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "The columns of table 'assign' and the field catalog (assign::fields()/blocklist()) have "
                . 'diverged - Moodle probably added, removed or renamed a column.'
        );
    }

    /**
     * Every constant required by the catalog exists on the running Moodle version.
     */
    public function test_required_constants_still_exist(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $this->assertNotEmpty(assign::checked_constants());

        foreach (assign::checked_constants() as $constname) {
            $this->assertTrue(defined($constname), "Constant $constname no longer exists on this instance.");
        }

        $this->assertFalse(
            in_array('ASSIGN_MARKER_FILTER_NO_MARKER', assign::checked_constants(), true),
            'ASSIGN_MARKER_FILTER_NO_MARKER is a filter UI identifier, not a field value - deliberately excluded.'
        );
    }

    /**
     * Every referenced callable source actually exists.
     */
    public function test_referenced_callable_sources_exist(): void {
        global $CFG;
        require_once($CFG->dirroot . '/lib/moodlelib.php');

        $fields = array_merge(
            shared_block::fields(),
            shared_block::pseudofields(),
            assign::fields(),
            assign::pseudofields()
        );

        $callables = array_filter(array_map(
            static fn (field $f): ?string => $f->sourcecallable,
            $fields
        ));

        $this->assertNotEmpty($callables, 'No field references a callable source; test assumption violated.');
        $this->assertContains('format_text_menu()', $callables);
        $this->assertContains('get_max_upload_sizes()', $callables);

        foreach ($callables as $callable) {
            $functionname = rtrim($callable, '()');
            $this->assertTrue(
                function_exists($functionname),
                "Referenced callable source $callable no longer exists on this instance."
            );
        }
    }

    /**
     * Acceptance #382: the 13 assignsubmission/assignfeedback pseudofields
     * are cataloged with a warning that omitting them disables submission plugins.
     */
    public function test_submission_and_feedback_pseudofields_carry_the_shutdown_warning(): void {
        $pseudofields = assign::pseudofields();
        $names = array_map(static fn (field $f): string => $f->name, $pseudofields);

        $submissionnames = array_filter($names, static fn (string $n): bool => str_starts_with($n, 'assignsubmission_'));
        $feedbacknames = array_filter($names, static fn (string $n): bool => str_starts_with($n, 'assignfeedback_'));

        $this->assertGreaterThanOrEqual(4, count($submissionnames), 'Zu wenige assignsubmission_*-Pseudofelder.');
        $this->assertGreaterThanOrEqual(4, count($feedbacknames), 'Zu wenige assignfeedback_*-Pseudofelder.');

        $enablednames = array_filter($names, static fn (string $n): bool => str_ends_with($n, '_enabled'));
        $this->assertNotEmpty($enablednames);

        $warningcarrier = null;
        foreach ($pseudofields as $f) {
            if ($f->name === 'assignsubmission_file_enabled') {
                $warningcarrier = $f;
                break;
            }
        }
        $this->assertNotNull($warningcarrier, 'assignsubmission_file_enabled fehlt im Pseudofeldkatalog.');
        $this->assertStringContainsString('nosubmissions', $warningcarrier->meaning);
        $this->assertStringContainsString('accepts no submissions', $warningcarrier->meaning);
    }

    /**
     * Acceptance #382: nosubmissions and completionsubmit are blocklisted in
     * assign; generic completion columns are blocklisted in the shared block.
     */
    public function test_nosubmissions_and_completion_fields_are_blocked(): void {
        $this->assertContains('nosubmissions', assign::blocklist());
        $this->assertContains('completionsubmit', assign::blocklist());

        $this->assertContains('completion', shared_block::BLOCKLIST);
        $this->assertContains('completionview', shared_block::BLOCKLIST);
        $this->assertContains('completionexpected', shared_block::BLOCKLIST);
        $this->assertContains('completiongradeitemnumber', shared_block::BLOCKLIST);
        $this->assertContains('completionpassgrade', shared_block::BLOCKLIST);
    }

    /**
     * Acceptance #382: teamsubmissiongroupingid is restricted to the same course.
     */
    public function test_teamsubmissiongroupingid_notes_same_course_restriction(): void {
        $field = null;
        foreach (assign::fields() as $f) {
            if ($f->name === 'teamsubmissiongroupingid') {
                $field = $f;
                break;
            }
        }
        $this->assertNotNull($field, 'teamsubmissiongroupingid fehlt im Feldkatalog.');
        $this->assertStringContainsString('same course', $field->meaning);
    }

    /**
     * Acceptance #429 (Spec 0018 §4.2/§7): introattachments is cataloged and
     * unblocked. It was previously absent from assign rather than blocklisted,
     * so test the resulting contract, not a blocklist-to-allowlist transition.
     */
    public function test_introattachments_is_catalogued_and_unlocked(): void {
        $pseudonames = array_map(static fn (field $f): string => $f->name, assign::pseudofields());

        $this->assertContains('introattachments', $pseudonames, '"introattachments" must be fully cataloged.');
        $this->assertNotContains(
            'introattachments',
            assign::blocklist(),
            'introattachments must remain unblocked since Spec 0018.'
        );
    }

    /**
     * Acceptance #382: standard and exercise bundles are provided.
     */
    public function test_standard_and_uebung_bundles_are_shipped(): void {
        $bundles = assign::bundles();
        $this->assertArrayHasKey('standard', $bundles);
        $this->assertArrayHasKey('exercise', $bundles);
        $this->assertNotEmpty($bundles['standard']);
        $this->assertNotEmpty($bundles['exercise']);
    }

    /**
     * Acceptance #382: literal value sets have file/line citations, and every
     * field has a nonempty source.
     */
    public function test_every_field_carries_a_file_line_source(): void {
        $fields = array_merge(assign::fields(), assign::pseudofields());
        $this->assertNotEmpty($fields);

        foreach ($fields as $f) {
            $this->assertNotEmpty($f->source, "Field {$f->name} has no source reference.");
            if ($f->values !== null && $f->sourcecallable === null) {
                $this->assertMatchesRegularExpression(
                    '/[A-Za-z0-9_\/.]+\.php:\d+/',
                    $f->source,
                    "Literal-tracked field {$f->name} needs a file:line source reference."
                );
            }
        }
    }

    /**
     * Acceptance #382: combination rules from validation() are cataloged.
     */
    public function test_combination_rules_are_present(): void {
        $rules = assign::combination_rules();
        $this->assertGreaterThanOrEqual(6, count($rules));
        $joined = implode(' ', $rules);
        $this->assertStringContainsString('allowsubmissionsfromdate', $joined);
        $this->assertStringContainsString('cutoffdate', $joined);
        $this->assertStringContainsString('gradingduedate', $joined);
        $this->assertStringContainsString('attemptreopenmethod', $joined);
    }
}
