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
 * Katalog-gegen-Moodle-Vertragstest fuer mod_url (Ticket #380, Vorbild
 * label_catalog_contract_test.php aus #379).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(url::class)]
final class url_catalog_contract_test extends \advanced_testcase {

    /**
     * Jede von url gefuehrte Datenbankspalte muss die reale Spaltenmenge von
     * {url} exakt ergeben.
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
            "Die Spalten der Tabelle 'url' und der Feldkatalog (url::fields()/blocklist()) sind "
                . 'auseinandergelaufen - Moodle hat vermutlich eine Spalte hinzugefuegt, entfernt oder umbenannt.'
        );
    }

    /**
     * url hat KEINE "revision"-Spalte - ein Regressionswaechter gegen ein
     * versehentliches Uebertragen aus page/resource/folder.
     */
    public function test_url_has_no_revision_column(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('url'));
        $this->assertNotContains('revision', $realcolumns);
        $this->assertNotContains('revision', url::blocklist());
    }

    /**
     * "externalurl" ist bewusst KEIN PARAM_URL (Ticket #380/Spec 0015 §4.4) -
     * geprueft wird gegen url_appears_valid_url().
     */
    public function test_externalurl_is_not_param_url(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/url/locallib.php');

        $externalurl = current(array_filter(url::fields(), static fn (field $f): bool => $f->name === 'externalurl'));

        $this->assertNotFalse($externalurl, 'Feld externalurl fehlt im Katalog.');
        $this->assertNotSame('PARAM_URL', $externalurl->type);
        $this->assertSame('url_appears_valid_url()', $externalurl->sourcecallable);
        $this->assertTrue(
            function_exists('url_appears_valid_url'),
            'url_appears_valid_url() existiert auf dieser Instanz nicht mehr.'
        );
    }

    /**
     * "displayoptions" und "parameters" stehen auf der Sperrliste (Ticket #380).
     */
    public function test_displayoptions_and_parameters_are_blocked(): void {
        $this->assertContains('displayoptions', url::blocklist());
        $this->assertContains('parameters', url::blocklist());
    }

    /**
     * Jede referenzierte aufrufbare Quelle existiert wirklich.
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

        $this->assertNotEmpty($callables, 'Kein Feld referenziert eine aufrufbare Quelle - Testannahme verletzt.');

        foreach ($callables as $callable) {
            $functionname = rtrim($callable, '()');
            $this->assertTrue(
                function_exists($functionname),
                "Referenzierte aufrufbare Quelle $callable existiert auf dieser Instanz nicht mehr."
            );
        }
    }
}
