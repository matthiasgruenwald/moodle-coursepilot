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

/**
 * Der ortsneutrale Ausfall-Uebersetzer (Issue #540, ADR 0023): beide Orte -
 * {@see webdav_storage_port} und {@see context_area}'s Moodle-Zweig - sowie
 * unveraendert {@see pointer_writer} bauen ihre Ausstandsantwort ueber
 * {@see ausstand_translation::record_and_translate()}. Dieser Test deckt den
 * Uebersetzer isoliert ab, ohne WebDAV oder Private Files anzufassen.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ausstand_translation::class)]
final class ausstand_translation_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * Vermerkt einen Ausstand mit Kennung, Zeitpunkt, Pfad, Vorgang und
     * Fehlerart, nie mit Inhalt (Issue #540 Abnahmekriterium 1), und baut die
     * fuenfteilige Ausfallantwort.
     */
    public function test_records_an_entry_and_builds_the_five_part_message(): void {
        $exception = ausstand_translation::record_and_translate(
            'meinefehlerklasse',
            'interne Rohmeldung, nur fuers Zugriffsprotokoll',
            'plan.md',
            ausstand_translation::OP_OVERWRITE,
            'die Ursache in Lehrkraftsprache',
            'das Ziel',
            42
        );

        $this->assertSame('ausstandwritefailed', $exception->errorcode);
        $message = $exception->getMessage();
        $this->assertStringContainsString('plan.md', $message);
        $this->assertStringContainsString('überschreiben', $message);
        $this->assertStringContainsString('die Ursache in Lehrkraftsprache', $message);
        $this->assertStringContainsString('das Ziel', $message);
        $this->assertStringNotContainsString('interne Rohmeldung', $message);

        $ausstaende = ausstand_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('plan.md', $ausstaende[0]['pfad']);
        $entry = $ausstaende[0]['eintraege'][0];
        $this->assertSame(ausstand_translation::OP_OVERWRITE, $entry['vorgang']);
        $this->assertSame('meinefehlerklasse', $entry['fehlerklasse']);
        $this->assertSame(42, $entry['kursid']);
        $this->assertGreaterThan(0, $entry['zeitpunkt']);
        $this->assertStringContainsString($entry['kennung'], $message);
    }

    /**
     * Kann die Notiz selbst nicht mehr geschrieben werden (Private-Files-
     * Quote voll), sagt die Antwort das ausdruecklich, statt die
     * urspruengliche Ursache zu verschweigen (ADR 0023 Consequences).
     */
    public function test_reports_when_the_note_itself_cannot_be_written(): void {
        global $CFG;
        $CFG->userquota = 1;

        $exception = ausstand_translation::record_and_translate(
            'meinefehlerklasse',
            'interne Rohmeldung',
            'plan.md',
            ausstand_translation::OP_CREATE,
            'Ursache',
            'Ziel',
            0
        );

        $this->assertSame('ausstandnotewritefailed', $exception->errorcode);
        $this->assertSame([], ausstand_notice::list_grouped());
    }
}
