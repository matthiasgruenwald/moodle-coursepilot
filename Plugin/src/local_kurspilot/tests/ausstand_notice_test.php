<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot;

defined('MOODLE_INTERNAL') || die();

/**
 * Die Ausstandsnotiz selbst (Issue #492, ADR 0023) - unabhaengig vom
 * WebDAV-Ausfallpfad ({@see pointer_writer}), der sie nur aufruft.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ausstand_notice::class)]
final class ausstand_notice_test extends \advanced_testcase {

    /**
     * Ein Eintrag je gescheitertem Vorgang, nie den Inhalt - nur Kennung,
     * Zeitpunkt, Pfad, Vorgang, Fehlerklasse (ADR 0023).
     */
    public function test_record_returns_kennung_and_is_listed(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $kennung = ausstand_notice::record('plan.md', 'anlegen', 'Speicher voll', 7);

        $this->assertNotSame('', $kennung);
        $groups = ausstand_notice::list_grouped();
        $this->assertCount(1, $groups);
        $this->assertSame('plan.md', $groups[0]['pfad']);
        $this->assertSame([
            'kennung' => $kennung,
            'zeitpunkt' => $groups[0]['eintraege'][0]['zeitpunkt'],
            'vorgang' => 'anlegen',
            'fehlerklasse' => 'Speicher voll',
            'kursid' => 7,
        ], $groups[0]['eintraege'][0]);
    }

    /**
     * Zwei gescheiterte Vorgaenge auf dieselbe Datei ergeben zwei Eintraege
     * mit verschiedenen Kennungen (ADR 0023: "ein Eintrag je gescheitertem
     * Vorgang").
     */
    public function test_two_failures_on_the_same_file_get_separate_entries(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $erste = ausstand_notice::record('plan.md', 'anlegen', 'Speicher voll', 7);
        $zweite = ausstand_notice::record('plan.md', 'überschreiben', 'nicht erreichbar', 7);

        $this->assertNotSame($erste, $zweite);
        $groups = ausstand_notice::list_grouped();
        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]['eintraege']);
    }

    /**
     * Verwerfen entfernt den Eintrag und meldet Erfolg; eine unbekannte
     * Kennung meldet false statt eines stillen Erfolgs.
     */
    public function test_dismiss_removes_entry_and_reports_unknown_kennung(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $kennung = ausstand_notice::record('plan.md', 'anlegen', 'Speicher voll', 7);

        $this->assertTrue(ausstand_notice::dismiss($kennung));
        $this->assertSame([], ausstand_notice::list_grouped());
        $this->assertFalse(ausstand_notice::dismiss($kennung));
        $this->assertFalse(ausstand_notice::dismiss('NIEEXISTIERT'));
    }

    /**
     * Person A sieht nie den Ausstand von Person B - der Eintrag liegt im
     * eigenen Nutzerkontext.
     */
    public function test_entries_are_isolated_per_person(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teachera);
        ausstand_notice::record('plan.md', 'anlegen', 'Speicher voll', 7);

        $this->setUser($teacherb);
        $this->assertSame([], ausstand_notice::list_grouped());
    }

    /**
     * Reicht die Private-Files-Quote nicht mehr, sagt der Fehler das
     * ausdruecklich (ADR 0023 Consequences: "Kann das Plugin die Notiz
     * nicht schreiben, weil die Quote von Private Files voll ist, sagt die
     * Fehlermeldung das ausdrücklich.").
     */
    public function test_record_fails_explicitly_when_quota_is_exhausted(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->userquota = 1;
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            ausstand_notice::record('plan.md', 'anlegen', 'Speicher voll', 7);
            $this->fail('Quotenueberschreitung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandnotequotaexceeded', $e->errorcode);
        }
    }

    /**
     * Ohne angemeldete Person ist die Notiz leer statt einen DB-Zugriff zu
     * erzwingen (dieselbe Grenze wie {@see storage_anchor::raw_pointer()}).
     */
    public function test_list_grouped_is_empty_without_logged_in_user(): void {
        $this->resetAfterTest();
        $this->assertSame([], ausstand_notice::list_grouped());
    }
}
