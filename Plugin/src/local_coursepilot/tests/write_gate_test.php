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

namespace local_coursepilot;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Selbstfreigabe des Feldkatalogs in zwei Stufen (Ticket #399, ADR 0017):
 * Drift sperrt nur die betroffene Aktivitätsart, alle anderen bleiben
 * schreibbar; Lesen ist von diesem Gate nie betroffen.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(write_gate::class)]
final class write_gate_test extends \advanced_testcase {

    /**
     * Auf der aktuellen Testinstanz ist jede katalogisierte Aktivitätsart
     * gruen und "geprueft" (reviewed_up_to_major() deckt die laufende
     * Moodle-Hauptversion ab).
     */
    public function test_all_catalogs_are_geprueft_on_the_current_instance(): void {
        $this->resetAfterTest();

        foreach (write_gate::all_statuses() as $status) {
            $this->assertSame('geprueft', $status['zustand'], $status['modname'] . ': ' . implode(' ', $status['verstoesse']));
            $this->assertSame([], $status['verstoesse']);
        }
    }

    /**
     * assert_writable() wirft nicht, solange kein Drift vorliegt.
     */
    public function test_assert_writable_does_not_throw_when_green(): void {
        $this->resetAfterTest();

        write_gate::assert_writable('label');
        $this->addToAssertionCount(1);
    }

    /**
     * Abnahmekriterium #399: Drift in einer Aktivitätsart sperrt genau diese
     * fuers Schreiben und laesst die uebrigen acht schreibbar.
     */
    public function test_drift_locks_only_the_affected_activity_type(): void {
        $this->resetAfterTest();

        // Erster Aufruf erzwingt die Tiefenpruefung und cached das Ergebnis
        // (alle gruen auf dieser Instanz).
        write_gate::all_statuses();

        // Simuliert eine erkannte Katalogabweichung fuer "label" - genau so,
        // wie ensure_fresh() sie nach einem echten Versionswechsel selbst
        // cachen wuerde.
        set_config('driftviolations_label', json_encode(['Spalte "intro" fehlt.']), 'local_coursepilot');

        $labelstatus = write_gate::status_for('label');
        $this->assertSame('braucht_arbeit', $labelstatus['zustand']);
        $this->assertNotEmpty($labelstatus['verstoesse']);

        foreach (\local_coursepilot\catalog\registry::known_modnames() as $modname) {
            if ($modname === 'label') {
                continue;
            }
            $status = write_gate::status_for($modname);
            $this->assertNotSame('braucht_arbeit', $status['zustand'], "$modname sollte durch den Drift von label nicht gesperrt sein.");
        }

        $this->expectException(\moodle_exception::class);
        write_gate::assert_writable('label');
    }

    /**
     * Abnahmekriterium #399: die Meldung an die Lehrkraft nennt die
     * Handlung "bitte der Administration melden".
     */
    public function test_drift_message_tells_the_teacher_to_report_it(): void {
        $this->resetAfterTest();

        write_gate::all_statuses();
        set_config('driftviolations_forum', json_encode(['Spalte "forcesubscribe" fehlt.']), 'local_coursepilot');

        try {
            write_gate::assert_writable('forum');
            $this->fail('assert_writable() haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('modnamedriftlocked', $e->errorcode);
            $this->assertStringContainsString('forum', $e->getMessage());
        }

        // Die PHPUnit-Testumgebung hat kein vollstaendiges de-Sprachpaket im
        // eigenen Dataroot (nur "en") - deshalb direkt gegen die
        // ausgelieferte deutsche Zeichenkette geprueft statt gegen Moodles
        // Spracherkennung zur Laufzeit.
        $string = [];
        require(__DIR__ . '/../lang/de/local_coursepilot.php');
        $this->assertArrayHasKey('modnamedriftlocked', $string);
        $this->assertStringContainsStringIgnoringCase('bitte der Administration melden', $string['modnamedriftlocked']);
    }

    /**
     * Andere Aktivitätsarten bleiben trotz Drift woanders unbeeintraechtigt
     * schreibbar - {@see test_drift_locks_only_the_affected_activity_type()}
     * prueft den Status, hier zusaetzlich, dass assert_writable() fuer sie
     * tatsaechlich folgenlos zurueckkehrt.
     */
    public function test_other_activity_types_stay_writable_during_drift(): void {
        $this->resetAfterTest();

        write_gate::all_statuses();
        set_config('driftviolations_label', json_encode(['Kaputt.']), 'local_coursepilot');

        write_gate::assert_writable('page');
        write_gate::assert_writable('quiz');
        $this->addToAssertionCount(2);
    }
}
