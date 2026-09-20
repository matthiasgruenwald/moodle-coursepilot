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

defined('MOODLE_INTERNAL') || die();

/**
 * Die Ausstandsnotiz selbst (Issue #492, ADR 0023) - unabhaengig vom
 * WebDAV-Ausfallpfad ({@see pointer_writer}), der sie nur aufruft.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(pending_write_notice::class)]
final class ausstand_notice_test extends \advanced_testcase {

    /**
     * Ein Eintrag je gescheitertem Vorgang, nie den Inhalt - nur Kennung,
     * Zeitpunkt, Pfad, Vorgang, Fehlerklasse (ADR 0023).
     */
    public function test_record_returns_kennung_and_is_listed(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $kennung = pending_write_notice::record('plan.md', 'anlegen', 'Speicher voll', 7);

        $this->assertNotSame('', $kennung);
        $groups = pending_write_notice::list_grouped();
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

        $erste = pending_write_notice::record('plan.md', 'anlegen', 'Speicher voll', 7);
        $zweite = pending_write_notice::record('plan.md', 'überschreiben', 'nicht erreichbar', 7);

        $this->assertNotSame($erste, $zweite);
        $groups = pending_write_notice::list_grouped();
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
        $kennung = pending_write_notice::record('plan.md', 'anlegen', 'Speicher voll', 7);

        $this->assertTrue(pending_write_notice::dismiss($kennung));
        $this->assertSame([], pending_write_notice::list_grouped());
        $this->assertFalse(pending_write_notice::dismiss($kennung));
        $this->assertFalse(pending_write_notice::dismiss('NIEEXISTIERT'));
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
        pending_write_notice::record('plan.md', 'anlegen', 'Speicher voll', 7);

        $this->setUser($teacherb);
        $this->assertSame([], pending_write_notice::list_grouped());
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
            pending_write_notice::record('plan.md', 'anlegen', 'Speicher voll', 7);
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
        $this->assertSame([], pending_write_notice::list_grouped());
    }
}
