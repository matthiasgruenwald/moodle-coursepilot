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
 * Katalog-gegen-Moodle-Vertragstest (Spec 0015, Pruefschnitt 2 aus #377):
 * prueft den label-Katalog gegen die auf der laufenden Instanz tatsaechlich
 * vorhandene Wirklichkeit - Spalten von {label}/{course_modules} und die
 * referenzierten aufrufbaren Quellen -, nicht gegen die Repo-Quelle.
 *
 * Vorbild: tests/privacy_surface_test.php (derselbe Gedanke: registrierte
 * Wirklichkeit statt Repo-Annahme). Muss fehlschlagen, sobald ein
 * Moodle-Update eine gefuehrte Spalte hinzufuegt, entfernt oder umbenennt,
 * oder eine referenzierte Funktion/Konstante verschwindet.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(label::class)]
final class label_catalog_contract_test extends \advanced_testcase {

    /**
     * Jede von label gefuehrte Datenbankspalte (Felder + modulspezifische
     * Sperrliste + die durchgaengig gesperrten, sofern in dieser Tabelle
     * vorhanden) plus "id" muss die reale Spaltenmenge von {label} exakt
     * ergeben - keine ungefuehrte, keine fehlende.
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
            "Die Spalten der Tabelle 'label' und der Feldkatalog (label::fields()/blocklist()) sind "
                . 'auseinandergelaufen - Moodle hat vermutlich eine Spalte hinzugefuegt, entfernt oder umbenannt.'
        );
    }

    /**
     * Der modulübergreifende Block referenziert reale course_modules-Spalten.
     */
    public function test_shared_block_columns_exist_on_course_modules(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('course_modules'));
        $blockfields = array_map(static fn (field $f): string => $f->name, shared_block::fields());

        // "sectionnum" ist Coursepilot-Vokabular fuer die Spalte "section"
        // (course/modlib.php:799) - kein 1:1-Spaltenname, deshalb gesondert
        // erwartet statt in der Spaltenmenge gesucht.
        $expecteddbcolumns = array_diff($blockfields, ['sectionnum']);

        foreach ($expecteddbcolumns as $name) {
            $this->assertContains(
                $name,
                $realcolumns,
                "Spalte course_modules.$name aus dem modulübergreifenden Block existiert nicht mehr."
            );
        }
        $this->assertContains('section', $realcolumns, 'Spalte course_modules.section (Abschnittszuordnung) fehlt.');
    }

    /**
     * Jede referenzierte aufrufbare Quelle (Kategorie 1 "Felder") existiert
     * wirklich - nicht nur der Feldname wird geprueft, sondern die
     * Aufrufbarkeit selbst (Abnahmekriterium #379).
     */
    public function test_referenced_callable_sources_exist(): void {
        $fields = array_merge(shared_block::fields(), shared_block::pseudofields(), label::fields(), label::pseudofields());

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
     * Die Gruppenmodus-Konstanten des gemeinsamen Blocks existieren noch -
     * aus shared_block::checked_constants() statt einer zweiten, separat
     * gepflegten Liste (Ticket #399, wiederverwendet von der
     * Laufzeit-Tiefenpruefung).
     */
    public function test_shared_block_group_mode_constants_exist(): void {
        $this->assertSame(['NOGROUPS', 'SEPARATEGROUPS', 'VISIBLEGROUPS'], shared_block::checked_constants());
        foreach (shared_block::checked_constants() as $constname) {
            $this->assertTrue(defined($constname), "Konstante $constname existiert auf dieser Instanz nicht mehr.");
        }
        $this->assertSame([0, 1, 2], [NOGROUPS, SEPARATEGROUPS, VISIBLEGROUPS]);
    }
}
