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
 * Katalog-gegen-Moodle-Vertragstest fuer mod_folder (Ticket #380, Vorbild
 * label_catalog_contract_test.php aus #379).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(folder::class)]
final class folder_catalog_contract_test extends \advanced_testcase {

    /**
     * Jede von folder gefuehrte Datenbankspalte muss die reale Spaltenmenge
     * von {folder} exakt ergeben. "files" ist kein DB-Feld (Pseudofeld, Spec
     * 0018 §4.2) und steht auch nicht mehr auf der Sperrliste.
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
            "Die Spalten der Tabelle 'folder' und der Feldkatalog (folder::fields()/blocklist()) sind "
                . 'auseinandergelaufen - Moodle hat vermutlich eine Spalte hinzugefuegt, entfernt oder umbenannt.'
        );
    }

    /**
     * "files" ist vollstaendig katalogisiert (Pseudofeld), optional und
     * nicht mehr gesperrt (Issue #434).
     */
    public function test_files_is_catalogued_optional_and_unlocked(): void {
        $pseudofields = folder::pseudofields();
        $pseudonames = array_map(static fn (field $f): string => $f->name, $pseudofields);
        $this->assertContains('files', $pseudonames, '"files" muss vollstaendig katalogisiert sein.');

        $filesfield = current(array_filter($pseudofields, static fn (field $f): bool => $f->name === 'files'));
        $this->assertFalse($filesfield->required, '"files" muss beim Anlegen optional bleiben (leerer Ordner gueltig).');

        $this->assertNotContains('files', folder::blocklist(), '"files" darf nicht mehr gesperrt sein.');
    }

    /**
     * folder bleibt anders als resource anlegbar (Spec 0015 §4.3) - der
     * Katalog vermerkt das ausdruecklich.
     */
    public function test_side_effects_note_folder_stays_creatable(): void {
        $notes = implode(' ', folder::side_effects());
        $this->assertStringContainsString('anlegbar', $notes);
    }

    /**
     * Die FOLDER_DISPLAY_*-Konstanten existieren noch - aus
     * folder::checked_constants() statt einer zweiten, separat gepflegten
     * Liste (Ticket #399, wiederverwendet von der Laufzeit-Tiefenpruefung).
     */
    public function test_folder_display_constants_exist(): void {
        $this->assertSame(['FOLDER_DISPLAY_PAGE', 'FOLDER_DISPLAY_INLINE'], folder::checked_constants());
        foreach (folder::checked_constants() as $constname) {
            $this->assertTrue(defined($constname), "Konstante $constname existiert auf dieser Instanz nicht mehr.");
        }
        $this->assertSame([0, 1], [FOLDER_DISPLAY_PAGE, FOLDER_DISPLAY_INLINE]);
    }
}
