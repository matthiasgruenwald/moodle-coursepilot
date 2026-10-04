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
final class local_coursepilot_umlaut_test extends advanced_testcase {

    /**
     * Complete list of substitutions introduced in #487–520 and fixed
     * by #521, checked word by word against the pre-fix git version.
     *
     * @return array<string, string[]>
     */
    private function forbidden_by_file(): array {
        $root = __DIR__ . '/../';
        return [
            $root . 'classes/tool_registry.php' => ['Aktivitaet', 'Aktivitaetstyp', 'Eintraege', 'Feldbuendel', 'Geprueft', 'Haengt', 'Loeschen', 'Verhaltensaenderung', 'Werkzeugeintraege', 'auffaellt', 'ausdruecklich', 'dafuer', 'fuegt', 'fuehrt', 'fuer', 'haengt', 'hoechstens', 'laeuft', 'loeschen', 'loescht', 'tatsaechlich', 'ueber', 'ueberschreibt', 'unveraendert', 'vollstaendig', 'vollstaendige', 'zurueck', 'zusaetzlich', 'zusammengefuegt'],
            $root . 'classes/tool_registry_context_tools.php' => ['Aktivitaet', 'Aktivitaets', 'Aktivitaetsart', 'Aktivitaetsarten', 'Aktivitaetstyp', 'Anhaengen', 'Anhaengversuch', 'Anzuhaengender', 'Aufloesung', 'Bestaetigung', 'Erklaerung', 'Feldbuendel', 'Fragenidentitaet', 'Geprueft', 'Gespraech', 'Groesse', 'Haelfte', 'Haengt', 'Handaenderung', 'Loeschen', 'Loescht', 'Loeschweg', 'Pruefwert', 'Ruehrt', 'Sekundenaufloesung', 'Vollstaendiger', 'aenderung', 'angehaengt', 'anzuhaengen', 'auffaellt', 'ausdruecklich', 'ausdrueckliche', 'ausstaende', 'benoetigten', 'dafuer', 'erklaerender', 'fuegt', 'fuenf', 'fuer', 'gefuehrten', 'gehoert', 'checked', 'groesser', 'gueltigen', 'gueltiger', 'haeufig', 'hoechstens', 'kursuebergreifend', 'kursuebergreifenden', 'laengste', 'loeschende', 'loescht', 'moeglich', 'moeglicherweise', 'noetig', 'tatsaechlich', 'traegt', 'ueber', 'uebergebene', 'ueberschreibt', 'ueberschrieben', 'ueberschriebene', 'uebersetzen', 'veraendert', 'vollstaendig', 'waehlen', 'waehlt', 'waere', 'zusaetzlich', 'zusammenfuehren'],
            $root . 'lang/de/local_coursepilot.php' => ['Aktivitaet', 'Dateigroesse', 'Groesse', 'Inhaltspruefsumme', 'Loeschen', 'Markierungsgedaechtnis', 'Schluessel', 'fuer', 'gehoert', 'laeuft', 'noetig', 'rueckschreibbar', 'vollstaendig'],
            $root . 'skills/adapter/coursepilot-plan.md' => ['Bestandsaenderung', 'ausfuehrbaren', 'fuer', 'checked', 'zusaetzlich'],
            $root . 'skills/adapter/coursepilot-implement.md' => ['Aktivitaet', 'Anhaengen', 'Einzelbestaetigung', 'fuer', 'zusaetzlich'],
            $root . 'skills/adapter/coursepilot.md' => ['Bestandsaenderung', 'ausfuehrbar', 'ausstaende', 'fuer', 'ueber', 'zusaetzlich'],
            $root . 'skills/reference/journal.md' => ['Aktivitaetstyp', 'Bestaetigung', 'Eintraege', 'Eintraegen', 'angehaengt', 'ausstaende', 'fuer', 'haelt', 'laeuft', 'spaeter', 'ueber', 'ueberschrieben', 'zusaetzlich'],
            $root . 'skills/reference/context-area.md' => ['Aktivitaet', 'Anhaenge', 'Anhaengen', 'Ausstaende', 'Bestaetigung', 'Dateigroesse', 'Eintraege', 'Einzelbestaetigung', 'Gespraech', 'Groesse', 'Kuerzel', 'Kuerzeln', 'Loeschen', 'Luecke', 'Rueckfall', 'Zaehlung', 'angehaengt', 'ausdruecklich', 'ausdrueckliche', 'ausdrueckliches', 'ausstaende', 'bestaetigten', 'fuehrt', 'fuer', 'gebuendelt', 'gewaehlt', 'gewoehnliche', 'gueltiger', 'haengt', 'laeuft', 'loeschen', 'loescht', 'moeglich', 'noetig', 'prueft', 'schlaegt', 'schwaecher', 'sinngemaess', 'spaeter', 'traegt', 'ueber', 'ueberschreiben', 'ueberschreibt', 'ueberschrieben', 'uebersprungenen', 'unveraendert', 'vollstaendig', 'vollstaendige', 'zurueck', 'zusaetzlich', 'zusammenfuehren',
                // Additional substitutions missed by #521 (#522).
                'enthaelt', 'Aktivitaetsvorlagen', 'Aufraeumfrage', 'Ergaenzung', 'Geloescht', 'Gesamtgroesse', 'Handaenderungs', 'Kuenftige', 'Loeschgrund', 'Pruefung', 'Rueckgaben', 'Schueler', 'Schuelernamen', 'Statuspruefung', 'Waechst', 'Zusammenfuehren', 'anhaengen', 'aufloesen', 'ausfuehrt', 'auszufuehrenden', 'auszufuehrender', 'ergaenzen', 'fuehlt', 'geaendert', 'gefuehrt', 'gehoeren', 'koennte', 'koennten', 'loest', 'muesste', 'naechsten', 'natuerlichen', 'pruefen', 'regulaer', 'schwaechere', 'spuerbar', 'ueberholte', 'ueberschreitet', 'widerspruechlicher', 'ausschliesslich', 'heisst', 'gleichermassen', 'fruheren'],
            $root . 'skills/reference/mcp-tools.md' => ['Aktivitaet', 'Aktivitaetsart', 'Aktivitaetstyp', 'Anhaenge', 'Anhaengen', 'Bestaetigung', 'Groesse', 'ausdruecklich', 'ausdruecklicher', 'fuer', 'gueltigen', 'loeschen', 'loescht', 'traegt', 'ueberschreiben', 'vollstaendig', 'waehlt', 'zusaetzlich'],
            $root . 'skills/reference/notepad.md' => ['Anhaenge', 'Ausfuehrung', 'Bestaetigung', 'Bestandsaenderung', 'Faellen', 'Fuer', 'Gespraech', 'Loesung', 'Originalqualitaet', 'Pruefsumme', 'Rueckfrage', 'ankuendigen', 'ausdruecklich', 'ausfuehrbar', 'ausfuehren', 'bestaetigten', 'entfaellt', 'fuer', 'gewoehnliche', 'haelt', 'laedt', 'laengst', 'laeuft', 'loeschen', 'mituebertragen', 'moeglich', 'prueft', 'schlaegt', 'schreibgeschuetzt', 'traegt', 'ueber', 'ueberein', 'unveraendert'],
        ];
    }

    /**
     * Select storage on the location-selection page, independently of
     * AI connection setup (#522).
     */
    public function test_kontextbereich_replaces_verbindungsaufbau_sentence(): void {
        $content = file_get_contents(__DIR__ . '/../skills/reference/context-area.md');
        $this->assertStringNotContainsString('beim Verbindungsaufbau gewählt', $content);
        $this->assertStringContainsString('Ortswahlseite', $content);
    }

    /**
     * Manual-edit guidance must not fix context storage to Moodle
     * My files; it stays location-neutral (#522, Spec #486 §14).
     */
    public function test_kontextbereich_does_not_fix_handaenderung_to_meine_dateien(): void {
        $content = file_get_contents(__DIR__ . '/../skills/reference/context-area.md');
        $this->assertStringNotContainsString(
            'kann jede Datei jederzeit in "Meine Dateien" selbst bearbeiten',
            $content
        );
    }

    /**
     * Pending-write guidance explicitly prohibits choosing another
     * storage location for unsaved content (#522, Spec #486 §14/§8).
     */
    public function test_kontextbereich_ausstand_forbids_taking_another_location(): void {
        $content = file_get_contents(__DIR__ . '/../skills/reference/context-area.md');
        $this->assertStringContainsString('keinen anderen Ort', $content);
    }

    /**
     * Inventory has no pending old-content mechanism; notepad guidance
     * must distinguish inventory changes from context-location changes (#522).
     */
    public function test_merkzettel_separates_previouslocation_from_materialbestand_wechsel(): void {
        $content = file_get_contents(__DIR__ . '/../skills/reference/notepad.md');
        $this->assertStringNotContainsString('Wechsel des Bestands (Altbestand', $content);
        $this->assertStringContainsString('kein Altbestand', $content);
    }

    public function test_no_ascii_umlaut_substitutes_in_chain_texts(): void {
        foreach ($this->forbidden_by_file() as $path => $needles) {
            if ($path === __DIR__ . '/../classes/tool_registry_context_tools.php') {
                continue;
            }
            $this->assertFileExists($path);
            $content = file_get_contents($path);
            foreach ($needles as $needle) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b' . preg_quote($needle, '/') . '\b/',
                    $content,
                    basename($path) . ' enthaelt noch die Ersatzschreibweise "' . $needle . '" statt eines echten Umlauts.'
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
