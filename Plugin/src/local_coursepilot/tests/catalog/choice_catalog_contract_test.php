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
 * Katalog-gegen-Moodle-Vertragstest fuer mod_choice (Ticket #381, Vorbild
 * resource_catalog_contract_test.php aus #380).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(choice::class)]
final class choice_catalog_contract_test extends \advanced_testcase {

    /**
     * Jede von choice gefuehrte Datenbankspalte muss die reale Spaltenmenge
     * von {choice} exakt ergeben.
     */
    public function test_choice_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('choice'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, choice::fields()),
            choice::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "Die Spalten der Tabelle 'choice' und der Feldkatalog (choice::fields()/blocklist()) sind "
                . 'auseinandergelaufen - Moodle hat vermutlich eine Spalte hinzugefuegt, entfernt oder umbenannt.'
        );
    }

    /**
     * Jede referenzierte aufrufbare Quelle existiert wirklich.
     */
    public function test_referenced_callable_sources_exist(): void {
        $fields = array_merge(
            shared_block::fields(),
            shared_block::pseudofields(),
            choice::fields(),
            choice::pseudofields()
        );

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
     * Abnahmekriterium #381: der Katalog fuehrt keine Coursepilot-eigene
     * Obergrenze fuer Optionen - die 2-6-Grenze gehoert zum aelteren lokalen
     * Weg (local_coursepilot\external\create_choice), nicht zu diesem
     * Katalog. Weder ein Feld noch eine Kombinationsregel darf eine solche
     * Obergrenze nennen.
     */
    public function test_no_coursepilot_option_upper_bound(): void {
        $allfieldnames = array_merge(
            array_map(static fn (field $f): string => $f->name, choice::fields()),
            array_map(static fn (field $f): string => $f->name, choice::pseudofields())
        );
        $this->assertNotContains('maxoptions', $allfieldnames);
        $this->assertNotContains('anzahloptionen', $allfieldnames);

        $rules = implode(' ', choice::combination_rules());
        $this->assertStringNotContainsString('2-6', $rules);
        $this->assertStringNotContainsString('hoechstens', $rules);
    }

    /**
     * Abnahmekriterium #381: "limit[] so lang wie option[]" ist eine eigene
     * Kombinationsregel (Kategorie 4), keine Eigenschaft des Felds "limit"
     * selbst.
     */
    public function test_limit_length_rule_is_a_combination_rule(): void {
        $rules = implode(' ', choice::combination_rules());
        $this->assertStringContainsString('limit', $rules);
        $this->assertStringContainsString('option', $rules);

        $limitfields = array_filter(
            choice::pseudofields(),
            static fn (field $f): bool => $f->name === 'limit'
        );
        $this->assertCount(1, $limitfields);
    }

    /**
     * Abnahmekriterium #381: "publish" traegt einen Nebenwirkungsvermerk zum
     * Wechsel anonym -> namentlich.
     */
    public function test_publish_side_effect_notes_the_anonymous_to_named_switch(): void {
        $notes = implode(' ', choice::side_effects());
        $this->assertStringContainsString('anonym', $notes);
        $this->assertStringContainsString('namentlich', $notes);
    }

    /**
     * Abnahmekriterium #381: das Feldbuendel "zuteilung" wird ausgeliefert
     * und enthaelt genau die sechs genannten Felder.
     */
    public function test_zuteilung_bundle_has_the_six_named_fields(): void {
        $bundles = choice::bundles();
        $this->assertArrayHasKey('zuteilung', $bundles);

        $zuteilung = $bundles['zuteilung'];
        $this->assertEqualsCanonicalizing(
            ['limitanswers', 'limit', 'publish', 'showresults', 'display', 'allowupdate'],
            array_keys($zuteilung)
        );
        $this->assertSame(1, $zuteilung['limitanswers']);
        $this->assertSame(1, $zuteilung['publish']); // CHOICE_PUBLISH_NAMES.
        $this->assertSame(3, $zuteilung['showresults']); // CHOICE_SHOWRESULTS_ALWAYS.
        $this->assertSame(1, $zuteilung['display']); // CHOICE_DISPLAY_VERTICAL.
        $this->assertSame(1, $zuteilung['allowupdate']);
    }

    /**
     * Abnahmekriterium #381: ein Feldbuendel belegt nur Felder vor, die nicht
     * ausdruecklich genannt wurden - ein ausdruecklich genanntes Feld schlaegt
     * das Buendel. Spec 0015 §2.4: "die KI setzt ein Buendel ein und
     * ueberschreibt einzelne Felder daraus" - das ist array_merge(bundle,
     * explizit), Bundle zuerst.
     *
     * Es gibt in dieser Phase (nur der Lesekatalog, #381) noch keinen
     * Schreib-Endpunkt, der ein Buendel tatsaechlich anwendet - der
     * Merge-Vertrag ist deshalb genau die array_merge()-Semantik, die hier
     * geprueft wird; sie legt fest, wie ein spaeterer Schreibendpunkt
     * (Phase 3) das Buendel konsumieren muss. Kein apply_bundle() vorab
     * bauen, ohne einen Aufrufer dafuer (YAGNI) - diese Regel entsteht mit
     * dem Schreibkern selbst.
     */
    public function test_explicit_field_overrides_the_bundle(): void {
        $bundle = choice::bundles()['zuteilung'];

        // Partnerarbeit statt Geraetezuteilung: die Lehrkraft nennt "limit"
        // und "publish" ausdruecklich, das Buendel darf sie nicht zuruecksetzen.
        $explizit = ['limit' => 2, 'publish' => 0];
        $merged = array_merge($bundle, $explizit);

        $this->assertSame(2, $merged['limit'], 'Ausdruecklich genanntes Feld "limit" wurde vom Buendel ueberschrieben.');
        $this->assertSame(0, $merged['publish'], 'Ausdruecklich genanntes Feld "publish" wurde vom Buendel ueberschrieben.');
        // Nicht genannte Buendelfelder bleiben unveraendert.
        $this->assertSame(1, $merged['limitanswers']);
        $this->assertSame(3, $merged['showresults']);
        $this->assertSame(1, $merged['display']);
        $this->assertSame(1, $merged['allowupdate']);
    }
}
