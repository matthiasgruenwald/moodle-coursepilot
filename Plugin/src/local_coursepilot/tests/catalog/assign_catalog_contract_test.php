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
 * Katalog-gegen-Moodle-Vertragstest fuer mod_assign (Ticket #382, Vorbild
 * forum_catalog_contract_test.php aus #381).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(assign::class)]
final class assign_catalog_contract_test extends \advanced_testcase {

    /**
     * Jede von assign gefuehrte Datenbankspalte muss die reale Spaltenmenge
     * von {assign} exakt ergeben - inklusive der drei modulweit gesperrten
     * Spalten (nosubmissions, revealidentities, completionsubmit), die ganz
     * normal ueber assign::blocklist() mitgezaehlt werden.
     */
    public function test_assign_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('assign'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, assign::fields()),
            assign::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "Die Spalten der Tabelle 'assign' und der Feldkatalog (assign::fields()/blocklist()) sind "
                . 'auseinandergelaufen - Moodle hat vermutlich eine Spalte hinzugefuegt, entfernt oder umbenannt.'
        );
    }

    /**
     * Die 34 Konstanten ohne aufrufbare Wertemenge (Spec 0015 §11) existieren
     * noch auf dieser Instanz - der Billigteil der Katalogpflege (ADR 0017)
     * fuer den Teil, der maschinell prüfbar ist. Liste jetzt in
     * assign::checked_constants() statt hier dupliziert (Ticket #399,
     * wiederverwendet von der Laufzeit-Tiefenpruefung).
     */
    public function test_the_34_constants_without_callable_source_still_exist(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $this->assertCount(
            34,
            assign::checked_constants(),
            'Testannahme verletzt: die Liste selbst muss 34 Eintraege haben.'
        );

        foreach (assign::checked_constants() as $constname) {
            $this->assertTrue(defined($constname), "Konstante $constname existiert auf dieser Instanz nicht mehr.");
        }

        $this->assertFalse(
            in_array('ASSIGN_MARKER_FILTER_NO_MARKER', assign::checked_constants(), true),
            'ASSIGN_MARKER_FILTER_NO_MARKER ist eine Filter-UI-Kennung, kein Feldwert - bewusst ausgeschlossen.'
        );
    }

    /**
     * Jede referenzierte aufrufbare Quelle existiert wirklich.
     */
    public function test_referenced_callable_sources_exist(): void {
        global $CFG;
        require_once($CFG->dirroot . '/lib/moodlelib.php');

        $fields = array_merge(
            shared_block::fields(),
            shared_block::pseudofields(),
            assign::fields(),
            assign::pseudofields()
        );

        $callables = array_filter(array_map(
            static fn (field $f): ?string => $f->sourcecallable,
            $fields
        ));

        $this->assertNotEmpty($callables, 'Kein Feld referenziert eine aufrufbare Quelle - Testannahme verletzt.');
        $this->assertContains('format_text_menu()', $callables);
        $this->assertContains('get_max_upload_sizes()', $callables);

        foreach ($callables as $callable) {
            $functionname = rtrim($callable, '()');
            $this->assertTrue(
                function_exists($functionname),
                "Referenzierte aufrufbare Quelle $callable existiert auf dieser Instanz nicht mehr."
            );
        }
    }

    /**
     * Abnahmekriterium #382: die ~20 (real: 13) assignsubmission_* und
     * assignfeedback_*-Pseudofelder sind gefuehrt, mit dem Vermerk, dass ihr
     * Fehlen alle Abgabe-Plugins abschaltet.
     */
    public function test_submission_and_feedback_pseudofields_carry_the_shutdown_warning(): void {
        $pseudofields = assign::pseudofields();
        $names = array_map(static fn (field $f): string => $f->name, $pseudofields);

        $submissionnames = array_filter($names, static fn (string $n): bool => str_starts_with($n, 'assignsubmission_'));
        $feedbacknames = array_filter($names, static fn (string $n): bool => str_starts_with($n, 'assignfeedback_'));

        $this->assertGreaterThanOrEqual(4, count($submissionnames), 'Zu wenige assignsubmission_*-Pseudofelder.');
        $this->assertGreaterThanOrEqual(4, count($feedbacknames), 'Zu wenige assignfeedback_*-Pseudofelder.');

        $enablednames = array_filter($names, static fn (string $n): bool => str_ends_with($n, '_enabled'));
        $this->assertNotEmpty($enablednames);

        $warningcarrier = null;
        foreach ($pseudofields as $f) {
            if ($f->name === 'assignsubmission_file_enabled') {
                $warningcarrier = $f;
                break;
            }
        }
        $this->assertNotNull($warningcarrier, 'assignsubmission_file_enabled fehlt im Pseudofeldkatalog.');
        $this->assertStringContainsString('nosubmissions', $warningcarrier->meaning);
        $this->assertStringContainsString('nimmt', $warningcarrier->meaning);
    }

    /**
     * Abnahmekriterium #382: "nosubmissions" und die Vervollstaendigungsfelder
     * stehen auf der Sperrliste - "nosubmissions"/"completionsubmit" modulweit
     * bei assign, die generischen "completion*"-Spalten durchgängig im
     * gemeinsamen Block.
     */
    public function test_nosubmissions_and_completion_fields_are_blocked(): void {
        $this->assertContains('nosubmissions', assign::blocklist());
        $this->assertContains('completionsubmit', assign::blocklist());

        $this->assertContains('completion', shared_block::BLOCKLIST);
        $this->assertContains('completionview', shared_block::BLOCKLIST);
        $this->assertContains('completionexpected', shared_block::BLOCKLIST);
        $this->assertContains('completiongradeitemnumber', shared_block::BLOCKLIST);
        $this->assertContains('completionpassgrade', shared_block::BLOCKLIST);
    }

    /**
     * Abnahmekriterium #382: "teamsubmissiongroupingid" traegt die
     * Einschraenkung auf denselben Kurs.
     */
    public function test_teamsubmissiongroupingid_notes_same_course_restriction(): void {
        $field = null;
        foreach (assign::fields() as $f) {
            if ($f->name === 'teamsubmissiongroupingid') {
                $field = $f;
                break;
            }
        }
        $this->assertNotNull($field, 'teamsubmissiongroupingid fehlt im Feldkatalog.');
        $this->assertStringContainsString('desselben Kurses', $field->meaning);
    }

    /**
     * Abnahmekriterium #429 (Spec 0018 §4.2/§7): "introattachments"
     * ("Zusaetzliche Dateien") ist vollstaendig katalogisiert und - anders
     * als resource::files/folder::files (siehe
     * resource_catalog_contract_test::test_files_is_catalogued_and_locked())
     * - NICHT gesperrt. Anders als bei resource/folder war "introattachments"
     * bei assign vorher gar nicht katalogisiert (kein Blocklist-Eintrag zum
     * Entfernen) - dieser Test belegt den Zielzustand aus Spec 0018 §4.2/§7
     * (katalogisiert, ungesperrt), nicht eine Sperre-zu-Freigabe-Transition
     * im Code.
     */
    public function test_introattachments_is_catalogued_and_unlocked(): void {
        $pseudonames = array_map(static fn (field $f): string => $f->name, assign::pseudofields());

        $this->assertContains('introattachments', $pseudonames, '"introattachments" muss vollstaendig katalogisiert sein.');
        $this->assertNotContains(
            'introattachments',
            assign::blocklist(),
            '"introattachments" darf ab Spec 0018 nicht mehr gesperrt sein.'
        );
    }

    /**
     * Abnahmekriterium #382: Feldbündel "standard" und "übung" werden
     * mitgeliefert.
     */
    public function test_standard_and_uebung_bundles_are_shipped(): void {
        $bundles = assign::bundles();
        $this->assertArrayHasKey('standard', $bundles);
        $this->assertArrayHasKey('übung', $bundles);
        $this->assertNotEmpty($bundles['standard']);
        $this->assertNotEmpty($bundles['übung']);
    }

    /**
     * Abnahmekriterium #382: jede literal gefuehrte Wertemenge traegt eine
     * Quellenangabe "Datei:Zeile" - jedes Feld hat ueberhaupt eine nicht
     * leere source-Angabe.
     */
    public function test_every_field_carries_a_file_line_source(): void {
        $fields = array_merge(assign::fields(), assign::pseudofields());
        $this->assertNotEmpty($fields);

        foreach ($fields as $f) {
            $this->assertNotEmpty($f->source, "Feld {$f->name} hat keine Quellenangabe.");
            if ($f->values !== null && $f->sourcecallable === null) {
                $this->assertMatchesRegularExpression(
                    '/[A-Za-z0-9_\/.]+\.php:\d+/',
                    $f->source,
                    "Literal gefuehrtes Feld {$f->name} braucht eine Datei:Zeile-Quellenangabe."
                );
            }
        }
    }

    /**
     * Abnahmekriterium #382: die Kombinationsregeln aus validation() sind
     * gefuehrt.
     */
    public function test_combination_rules_are_present(): void {
        $rules = assign::combination_rules();
        $this->assertGreaterThanOrEqual(6, count($rules));
        $joined = implode(' ', $rules);
        $this->assertStringContainsString('allowsubmissionsfromdate', $joined);
        $this->assertStringContainsString('cutoffdate', $joined);
        $this->assertStringContainsString('gradingduedate', $joined);
        $this->assertStringContainsString('attemptreopenmethod', $joined);
    }
}
