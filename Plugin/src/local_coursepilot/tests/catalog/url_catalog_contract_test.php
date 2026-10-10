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
 * Catalog/Moodle contract for mod_url (Ticket #380), following
 * label_catalog_contract_test from #379.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(url::class)]
#[CoversClass(\local_coursepilot\catalog\shared_block::class)]
final class url_catalog_contract_test extends \advanced_testcase {
    /**
     * Catalog fields, real blocklisted columns and id exactly match the
     * url table columns. Pseudofields do not count as database columns.
     */
    public function test_url_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('url'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, url::fields()),
            url::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "The columns of table 'url' and the field catalog (url::fields()/blocklist()) have "
                . 'diverged - Moodle probably added, removed or renamed a column.'
        );
    }

    /**
     * url has no revision column; guard against accidental copying from
     * page, resource or folder.
     */
    public function test_url_has_no_revision_column(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('url'));
        $this->assertNotContains('revision', $realcolumns);
        $this->assertNotContains('revision', url::blocklist());
    }

    /**
     * externalurl uses url_appears_valid_url(), rather than PARAM_URL
     * (Ticket #380, Spec 0015 §4.4).
     */
    public function test_externalurl_is_not_param_url(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/url/locallib.php');

        $externalurl = current(array_filter(url::fields(), static fn (field $f): bool => $f->name === 'externalurl'));

        $this->assertNotFalse($externalurl, 'Field externalurl is missing from the catalog.');
        $this->assertNotSame('PARAM_URL', $externalurl->type);
        $this->assertSame('url_appears_valid_url()', $externalurl->sourcecallable);
        $this->assertTrue(
            function_exists('url_appears_valid_url'),
            'url_appears_valid_url() no longer exists on this instance.'
        );
    }

    /**
     * displayoptions and parameters are blocklisted (Ticket #380).
     */
    public function test_displayoptions_and_parameters_are_blocked(): void {
        $this->assertContains('displayoptions', url::blocklist());
        $this->assertContains('parameters', url::blocklist());
    }

    /**
     * Every referenced callable source exists.
     */
    public function test_referenced_callable_sources_exist(): void {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');
        require_once($CFG->dirroot . '/mod/url/locallib.php');

        $fields = array_merge(shared_block::fields(), shared_block::pseudofields(), url::fields(), url::pseudofields());

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
