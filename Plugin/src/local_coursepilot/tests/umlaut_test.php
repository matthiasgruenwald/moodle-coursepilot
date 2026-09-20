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
 * Echte Umlaute statt ae/oe/ue-Ersatzschreibweisen in Texten, die Lehrkraft
 * oder KI sehen (Issue #521, Review-Nacharbeit zu #486): Sprachpaket sowie
 * Werkzeug-/Parameterbeschreibungen aus der Kette #487-#520. Und: die
 * Plugin-Beschreibung beschreibt die externe Isolierung korrekt
 * (Schreibsperre, keine Lesesperre - Spec #486 §12) und verweist auf #481.
 *
 * Issue #522 (weitere Review-Nacharbeit zu #486): die Referenzteile
 * `kontextbereich` und `merkzettel` bleiben ortsneutral - echte Umlaute,
 * kein Verbindungsaufbau-Satz fuer die Ortswahl, keine Festlegung auf
 * "Meine Dateien", Altbestand (nur Kontextbereich) und Wechsel des
 * Materialbestands getrennt beschrieben, "keinen anderen Ort nehmen" beim
 * Ausstand benannt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class local_coursepilot_umlaut_test extends advanced_testcase {

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
            $root . 'lang/de/local_coursepilot.php' => ['Aktivitaet', 'Dateigroesse', 'Groesse', 'Inhaltspruefsumme', 'Loeschen', 'Markierungsgedaechtnis', 'Schluessel', 'fuer', 'gehoert', 'laeuft', 'noetig', 'rueckschreibbar', 'vollstaendig'],
            $root . 'skills/adapter/coursepilot-planen.md' => ['Bestandsaenderung', 'ausfuehrbaren', 'fuer', 'geprueft', 'zusaetzlich'],
            $root . 'skills/adapter/coursepilot-umsetzen.md' => ['Aktivitaet', 'Anhaengen', 'Einzelbestaetigung', 'fuer', 'zusaetzlich'],
            $root . 'skills/adapter/coursepilot.md' => ['Bestandsaenderung', 'ausfuehrbar', 'ausstaende', 'fuer', 'ueber', 'zusaetzlich'],
            $root . 'skills/referenz/journal.md' => ['Aktivitaetstyp', 'Bestaetigung', 'Eintraege', 'Eintraegen', 'angehaengt', 'ausstaende', 'fuer', 'haelt', 'laeuft', 'spaeter', 'ueber', 'ueberschrieben', 'zusaetzlich'],
            $root . 'skills/referenz/kontextbereich.md' => ['Aktivitaet', 'Anhaenge', 'Anhaengen', 'Ausstaende', 'Bestaetigung', 'Dateigroesse', 'Eintraege', 'Einzelbestaetigung', 'Gespraech', 'Groesse', 'Kuerzel', 'Kuerzeln', 'Loeschen', 'Luecke', 'Rueckfall', 'Zaehlung', 'angehaengt', 'ausdruecklich', 'ausdrueckliche', 'ausdrueckliches', 'ausstaende', 'bestaetigten', 'fuehrt', 'fuer', 'gebuendelt', 'gewaehlt', 'gewoehnliche', 'gueltiger', 'haengt', 'laeuft', 'loeschen', 'loescht', 'moeglich', 'noetig', 'prueft', 'schlaegt', 'schwaecher', 'sinngemaess', 'spaeter', 'traegt', 'ueber', 'ueberschreiben', 'ueberschreibt', 'ueberschrieben', 'uebersprungenen', 'unveraendert', 'vollstaendig', 'vollstaendige', 'zurueck', 'zusaetzlich', 'zusammenfuehren',
                // Issue #522: weitere Ersatzschreibweisen, von #521 nicht erfasst.
                'enthaelt', 'Aktivitaetsvorlagen', 'Aufraeumfrage', 'Ergaenzung', 'Geloescht', 'Gesamtgroesse', 'Handaenderungs', 'Kuenftige', 'Loeschgrund', 'Pruefung', 'Rueckgaben', 'Schueler', 'Schuelernamen', 'Statuspruefung', 'Waechst', 'Zusammenfuehren', 'anhaengen', 'aufloesen', 'ausfuehrt', 'auszufuehrenden', 'auszufuehrender', 'ergaenzen', 'fuehlt', 'geaendert', 'gefuehrt', 'gehoeren', 'koennte', 'koennten', 'loest', 'muesste', 'naechsten', 'natuerlichen', 'pruefen', 'regulaer', 'schwaechere', 'spuerbar', 'ueberholte', 'ueberschreitet', 'widerspruechlicher', 'ausschliesslich', 'heisst', 'gleichermassen', 'fruheren'],
            $root . 'skills/referenz/mcp-tools.md' => ['Aktivitaet', 'Aktivitaetsart', 'Aktivitaetstyp', 'Anhaenge', 'Anhaengen', 'Bestaetigung', 'Groesse', 'ausdruecklich', 'ausdruecklicher', 'fuer', 'gueltigen', 'loeschen', 'loescht', 'traegt', 'ueberschreiben', 'vollstaendig', 'waehlt', 'zusaetzlich'],
            $root . 'skills/referenz/merkzettel.md' => ['Anhaenge', 'Ausfuehrung', 'Bestaetigung', 'Bestandsaenderung', 'Faellen', 'Fuer', 'Gespraech', 'Loesung', 'Originalqualitaet', 'Pruefsumme', 'Rueckfrage', 'ankuendigen', 'ausdruecklich', 'ausfuehrbar', 'ausfuehren', 'bestaetigten', 'entfaellt', 'fuer', 'gewoehnliche', 'haelt', 'laedt', 'laengst', 'laeuft', 'loeschen', 'mituebertragen', 'moeglich', 'prueft', 'schlaegt', 'schreibgeschuetzt', 'traegt', 'ueber', 'ueberein', 'unveraendert'],
        ];
    }

    /**
     * Issue #522: der Ablageort wird nicht mehr "beim Verbindungsaufbau"
     * gewaehlt (das war die MCP-Anbindung), sondern auf der Ortswahlseite -
     * unabhaengig davon, wie die KI-Anbindung eingerichtet ist.
     */
    public function test_kontextbereich_replaces_verbindungsaufbau_sentence(): void {
        $content = file_get_contents(__DIR__ . '/../skills/referenz/kontextbereich.md');
        $this->assertStringNotContainsString('beim Verbindungsaufbau gewählt', $content);
        $this->assertStringContainsString('Ortswahlseite', $content);
    }

    /**
     * Issue #522: die Handaenderungs-Routine legt den Ablageort nicht auf
     * Moodles "Meine Dateien" fest - der Kontextbereich bleibt ortsneutral
     * (Spec #486 §14).
     */
    public function test_kontextbereich_does_not_fix_handaenderung_to_meine_dateien(): void {
        $content = file_get_contents(__DIR__ . '/../skills/referenz/kontextbereich.md');
        $this->assertStringNotContainsString(
            'kann jede Datei jederzeit in "Meine Dateien" selbst bearbeiten',
            $content
        );
    }

    /**
     * Issue #522: der Ausstand-Ablauf nennt ausdruecklich, keinen anderen
     * Ort fuer den nicht gespeicherten Inhalt zu nehmen (Spec #486 §14/§8).
     */
    public function test_kontextbereich_ausstand_forbids_taking_another_location(): void {
        $content = file_get_contents(__DIR__ . '/../skills/referenz/kontextbereich.md');
        $this->assertStringContainsString('keinen anderen Ort', $content);
    }

    /**
     * Issue #522: der Materialbestand kennt keinen Altbestand (siehe
     * `\local_coursepilot\altbestand`) - der Merkzettel-Text darf den Wechsel
     * des Materialbestands nicht als Altbestand-Vorgang beschreiben.
     */
    public function test_merkzettel_separates_altbestand_from_materialbestand_wechsel(): void {
        $content = file_get_contents(__DIR__ . '/../skills/referenz/merkzettel.md');
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
     * Die Plugin-Beschreibung (Einstellungsseite) nennt die richtige
     * Isolierung - Schreibsperre, keine Lesesperre - und verweist auf #481.
     */
    public function test_plugin_description_states_write_lock_not_read_lock(): void {
        // Direkt aus der Quelle gelesen statt ueber get_string(): die
        // Testumgebung hat kein deutsches Sprachpaket installiert.
        $string = [];
        require(__DIR__ . '/../lang/de/local_coursepilot.php');
        $desc = $string['settingintroheading_desc'];

        $this->assertStringContainsString('Issue #481', $desc);
        $this->assertStringContainsString('Schreibsperre', $desc);
        $this->assertStringContainsString('keine Lesesperre', $desc);
        $this->assertStringNotContainsString('ohne Lesesperre schreiben', $desc);
    }
}
