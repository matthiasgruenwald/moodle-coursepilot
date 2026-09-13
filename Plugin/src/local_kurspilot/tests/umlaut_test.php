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

/**
 * Echte Umlaute statt ae/oe/ue-Ersatzschreibweisen in Texten, die Lehrkraft
 * oder KI sehen (Issue #521, Review-Nacharbeit zu #486): Sprachpaket sowie
 * Werkzeug-/Parameterbeschreibungen aus der Kette #487-#520. Und: die
 * Plugin-Beschreibung beschreibt die externe Isolierung korrekt
 * (Schreibsperre, keine Lesesperre - Spec #486 §12) und verweist auf #481.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class local_kurspilot_umlaut_test extends advanced_testcase {

    /**
     * Vollstaendige Liste der ae/oe/ue-Ersatzschreibweisen, die in der Kette
     * #487-#520 neu eingefuehrt und mit #521 korrigiert wurden - je Datei
     * jedes einzelne betroffene Wort (per `git show HEAD:<datei>` gegen den
     * Stand vor #521 ermittelt), nicht nur eine Stichprobe.
     *
     * @return array<string, string[]>
     */
    private function forbidden_by_file(): array {
        $root = __DIR__ . '/../';
        return [
            $root . 'classes/tool_registry.php' => ['Aktivitaet', 'Aktivitaetstyp', 'Eintraege', 'Feldbuendel', 'Geprueft', 'Haengt', 'Loeschen', 'Verhaltensaenderung', 'Werkzeugeintraege', 'auffaellt', 'ausdruecklich', 'dafuer', 'fuegt', 'fuehrt', 'fuer', 'haengt', 'hoechstens', 'laeuft', 'loeschen', 'loescht', 'tatsaechlich', 'ueber', 'ueberschreibt', 'unveraendert', 'vollstaendig', 'vollstaendige', 'zurueck', 'zusaetzlich', 'zusammengefuegt'],
            $root . 'classes/tool_registry_context_tools.php' => ['Aktivitaet', 'Aktivitaets', 'Aktivitaetsart', 'Aktivitaetsarten', 'Aktivitaetstyp', 'Anhaengen', 'Anhaengversuch', 'Anzuhaengender', 'Aufloesung', 'Bestaetigung', 'Erklaerung', 'Feldbuendel', 'Fragenidentitaet', 'Geprueft', 'Gespraech', 'Groesse', 'Haelfte', 'Haengt', 'Handaenderung', 'Loeschen', 'Loescht', 'Loeschweg', 'Pruefwert', 'Ruehrt', 'Sekundenaufloesung', 'Vollstaendiger', 'aenderung', 'angehaengt', 'anzuhaengen', 'auffaellt', 'ausdruecklich', 'ausdrueckliche', 'ausstaende', 'benoetigten', 'dafuer', 'erklaerender', 'fuegt', 'fuenf', 'fuer', 'gefuehrten', 'gehoert', 'geprueft', 'groesser', 'gueltigen', 'gueltiger', 'haeufig', 'hoechstens', 'kursuebergreifend', 'kursuebergreifenden', 'laengste', 'loeschende', 'loescht', 'moeglich', 'moeglicherweise', 'noetig', 'tatsaechlich', 'traegt', 'ueber', 'uebergebene', 'ueberschreibt', 'ueberschrieben', 'ueberschriebene', 'uebersetzen', 'veraendert', 'vollstaendig', 'waehlen', 'waehlt', 'waere', 'zusaetzlich', 'zusammenfuehren'],
            $root . 'lang/de/local_kurspilot.php' => ['Aktivitaet', 'Dateigroesse', 'Groesse', 'Inhaltspruefsumme', 'Loeschen', 'Markierungsgedaechtnis', 'Schluessel', 'fuer', 'gehoert', 'laeuft', 'noetig', 'rueckschreibbar', 'vollstaendig'],
            $root . 'skills/adapter/kurspilot-planen.md' => ['Bestandsaenderung', 'ausfuehrbaren', 'fuer', 'geprueft', 'zusaetzlich'],
            $root . 'skills/adapter/kurspilot-umsetzen.md' => ['Aktivitaet', 'Anhaengen', 'Einzelbestaetigung', 'fuer', 'zusaetzlich'],
            $root . 'skills/adapter/kurspilot.md' => ['Bestandsaenderung', 'ausfuehrbar', 'ausstaende', 'fuer', 'ueber', 'zusaetzlich'],
            $root . 'skills/referenz/journal.md' => ['Aktivitaetstyp', 'Bestaetigung', 'Eintraege', 'Eintraegen', 'angehaengt', 'ausstaende', 'fuer', 'haelt', 'laeuft', 'spaeter', 'ueber', 'ueberschrieben', 'zusaetzlich'],
            $root . 'skills/referenz/kontextbereich.md' => ['Aktivitaet', 'Anhaenge', 'Anhaengen', 'Ausstaende', 'Bestaetigung', 'Dateigroesse', 'Eintraege', 'Einzelbestaetigung', 'Gespraech', 'Groesse', 'Kuerzel', 'Kuerzeln', 'Loeschen', 'Luecke', 'Rueckfall', 'Zaehlung', 'angehaengt', 'ausdruecklich', 'ausdrueckliche', 'ausdrueckliches', 'ausstaende', 'bestaetigten', 'fuehrt', 'fuer', 'gebuendelt', 'gewaehlt', 'gewoehnliche', 'gueltiger', 'haengt', 'laeuft', 'loeschen', 'loescht', 'moeglich', 'noetig', 'prueft', 'schlaegt', 'schwaecher', 'sinngemaess', 'spaeter', 'traegt', 'ueber', 'ueberschreiben', 'ueberschreibt', 'ueberschrieben', 'uebersprungenen', 'unveraendert', 'vollstaendig', 'vollstaendige', 'zurueck', 'zusaetzlich', 'zusammenfuehren'],
            $root . 'skills/referenz/mcp-tools.md' => ['Aktivitaet', 'Aktivitaetsart', 'Aktivitaetstyp', 'Anhaenge', 'Anhaengen', 'Bestaetigung', 'Groesse', 'ausdruecklich', 'ausdruecklicher', 'fuer', 'gueltigen', 'loeschen', 'loescht', 'traegt', 'ueberschreiben', 'vollstaendig', 'waehlt', 'zusaetzlich'],
            $root . 'skills/referenz/merkzettel.md' => ['Anhaenge', 'Ausfuehrung', 'Bestaetigung', 'Bestandsaenderung', 'Faellen', 'Fuer', 'Gespraech', 'Loesung', 'Originalqualitaet', 'Pruefsumme', 'Rueckfrage', 'ankuendigen', 'ausdruecklich', 'ausfuehrbar', 'ausfuehren', 'bestaetigten', 'entfaellt', 'fuer', 'gewoehnliche', 'haelt', 'laedt', 'laengst', 'laeuft', 'loeschen', 'mituebertragen', 'moeglich', 'prueft', 'schlaegt', 'schreibgeschuetzt', 'traegt', 'ueber', 'ueberein', 'unveraendert'],
        ];
    }

    public function test_no_ascii_umlaut_substitutes_in_chain_texts(): void {
        foreach ($this->forbidden_by_file() as $path => $needles) {
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
     * Die Plugin-Beschreibung (Einstellungsseite) nennt die richtige
     * Isolierung - Schreibsperre, keine Lesesperre - und verweist auf #481.
     */
    public function test_plugin_description_states_write_lock_not_read_lock(): void {
        // Direkt aus der Quelle gelesen statt ueber get_string(): die
        // Testumgebung hat kein deutsches Sprachpaket installiert.
        $string = [];
        require(__DIR__ . '/../lang/de/local_kurspilot.php');
        $desc = $string['settingintroheading_desc'];

        $this->assertStringContainsString('Issue #481', $desc);
        $this->assertStringContainsString('Schreibsperre', $desc);
        $this->assertStringContainsString('keine Lesesperre', $desc);
        $this->assertStringNotContainsString('ohne Lesesperre schreiben', $desc);
    }
}
