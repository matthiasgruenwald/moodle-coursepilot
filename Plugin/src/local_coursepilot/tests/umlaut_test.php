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

/**
 * Use real German umlauts instead of ae/oe/ue substitutions in
 * teacher-facing German language text and descriptions (#521).
 * The plugin description accurately explains external isolation: a
 * write lock rather than a read lock (Spec #486 §12).
 *
 * Context-area and notepad references stay location-neutral (#522):
 * selection is separate from AI connection setup, not fixed to My files;
 * old context content and inventory changes have distinct semantics,
 * and pending writes must never choose another storage location.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class umlaut_test extends advanced_testcase {
    /**
     * German language-pack substitutions fixed by #521. The English
     * corpus and tool descriptions are covered by their language contracts.
     *
     * @return array<string, string[]>
     */
    private function forbidden_by_file(): array {
        $root = __DIR__ . '/../';
        return [
            $root . 'lang/de/local_coursepilot.php' => ['Aktivitaet', 'Dateigroesse', 'Groesse', 'Inhaltspruefsumme', 'Loeschen', 'Markierungsgedaechtnis', 'Schluessel', 'fuer', 'gehoert', 'laeuft', 'noetig', 'rueckschreibbar', 'vollstaendig'],
        ];
    }

    /**
     * Select storage on the location-selection page, independently of
     * AI connection setup (#522).
     */
    public function test_kontextbereich_replaces_verbindungsaufbau_sentence(): void {
        $content = preg_replace('/\s+/u', ' ', file_get_contents(__DIR__ . '/../skills/reference/context-area.md'));
        $this->assertStringNotContainsString('selected during connection setup', $content);
        $this->assertStringContainsString('location-selection page', $content);
    }

    /**
     * Manual-edit guidance must not fix context storage to Moodle
     * My files; it stays location-neutral (#522, Spec #486 §14).
     */
    public function test_kontextbereich_does_not_fix_handaenderung_to_meine_dateien(): void {
        $content = preg_replace('/\s+/u', ' ', file_get_contents(__DIR__ . '/../skills/reference/context-area.md'));
        $this->assertStringNotContainsString(
            'can edit every file in "My files" at any time',
            $content
        );
    }

    /**
     * Pending-write guidance explicitly prohibits choosing another
     * storage location for unsaved content (#522, Spec #486 §14/§8).
     */
    public function test_kontextbereich_ausstand_forbids_taking_another_location(): void {
        $content = preg_replace('/\s+/u', ' ', file_get_contents(__DIR__ . '/../skills/reference/context-area.md'));
        $this->assertStringContainsString('Do not choose another storage location', $content);
    }

    /**
     * Inventory has no pending old-content mechanism; notepad guidance
     * must distinguish inventory changes from context-location changes (#522).
     */
    public function test_merkzettel_separates_previouslocation_from_materialbestand_wechsel(): void {
        $content = preg_replace('/\s+/u', ' ', file_get_contents(__DIR__ . '/../skills/reference/notepad.md'));
        $this->assertStringNotContainsString('Inventory change (old content', $content);
        $this->assertStringContainsString('has no old-content state', $content);
    }

    public function test_no_ascii_umlaut_substitutes_in_chain_texts(): void {
        foreach ($this->forbidden_by_file() as $path => $needles) {
            $this->assertFileExists($path);
            $content = file_get_contents($path);
            foreach ($needles as $needle) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b' . preg_quote($needle, '/') . '\b/',
                    $content,
                    basename($path) . ' still contains the ASCII substitute "' . $needle . '" instead of a real umlaut.'
                );
            }
        }
    }

    /**
     * The settings description explains a write lock, not a read lock,
     * and links to admin setup documentation. After #481 was implemented,
     * #568 replaced the obsolete issue reference with
     * docs/admin-erstanleitung.md; retain behavior-based privacy assertions.
     */
    public function test_plugin_description_states_write_lock_not_read_lock(): void {
        // Read the source directly because the test environment has no German language pack.
        $string = [];
        require(__DIR__ . '/../lang/de/local_coursepilot.php');
        $desc = $string['settingintroheading_desc'];

        $this->assertStringContainsString('docs/admin-erstanleitung.md', $desc);
        $this->assertStringContainsString('Schreibsperre', $desc);
        $this->assertStringContainsString('keine Lesesperre', $desc);
        $this->assertStringNotContainsString('ohne Lesesperre schreiben', $desc);
    }
}
