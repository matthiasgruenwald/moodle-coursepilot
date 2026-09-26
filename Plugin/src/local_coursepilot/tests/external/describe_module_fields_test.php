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

namespace local_coursepilot\external;

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Feldkatalog-Abruf (#379): Katalog-Gerüst, gemeinsamer Block, label als
 * erste vollständig katalogisierte Aktivitätsart.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(describe_module_fields::class)]
final class describe_module_fields_test extends \advanced_testcase {

    /**
     * Ohne modname: die von Coursepilot geführten Aktivitätsarten (User Story 13).
     */
    public function test_without_modname_lists_known_activity_types(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = describe_module_fields::execute();
        $result = external_api::clean_returnvalue(describe_module_fields::execute_returns(), $result);

        $this->assertContains('label', $result['known_modnames']);
        $this->assertNull($result['module']);
        $this->assertNotSame('', trim($result['notice']));
    }

    /**
     * Kurzform: Felder und Feldbündel ja, die restlichen vier Kategorien
     * nein - dafür ein ausdrücklicher Hinweis, dass es mehr gibt.
     */
    public function test_short_form_omits_extra_categories_and_says_so(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = describe_module_fields::execute('label');
        $result = external_api::clean_returnvalue(describe_module_fields::execute_returns(), $result);

        $this->assertNotEmpty($result['module']['fields']);
        $this->assertSame([], $result['module']['pseudo_fields']);
        $this->assertSame([], $result['module']['blocked_fields']);
        $this->assertSame([], $result['module']['combination_rules']);
        $this->assertSame([], $result['module']['side_effects']);
        $this->assertStringContainsString('full', $result['notice']);
    }

    /**
     * Vollständige Form: alle fünf Kategorien, unterscheidbar von der Kurzform.
     */
    public function test_full_form_includes_all_five_categories(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $short = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('label', false)
        );
        $full = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('label', true)
        );

        $this->assertNotEquals($short, $full, 'Kurzform und vollständige Form müssen sich unterscheiden.');

        $sperrliste = $full['module']['blocked_fields'];
        $this->assertContains('name', $sperrliste, 'label.name muss gesperrt sein - es wird aus dem Intro abgeleitet.');
        $this->assertContains('course', $sperrliste);
        $this->assertContains('timemodified', $sperrliste);

        $pseudonames = array_column($full['module']['pseudo_fields'], 'name');
        $this->assertContains('coursepagevisibility', $pseudonames);
    }

    /**
     * Der gemeinsame Block erscheint bei label und ist nicht in der
     * label-Klasse dupliziert - geprüft über die Ausgabe, nicht die
     * Implementierung: die Feldliste enthält sowohl label-eigene als auch
     * course_modules-Felder.
     */
    public function test_shared_block_appears_alongside_label_fields(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('label', true)
        );

        $names = array_column($result['module']['fields'], 'name');
        $this->assertContains('intro', $names, 'label-eigenes Feld fehlt.');
        $this->assertContains('visible', $names, 'Gemeinsamer Block fehlt.');
        $this->assertContains('groupmode', $names, 'Gemeinsamer Block fehlt.');
        $this->assertContains('idnumber', $names, 'Gemeinsamer Block fehlt.');

        // Keine Dopplung: jeder Feldname erscheint genau einmal.
        $this->assertSame(count($names), count(array_unique($names)), 'Ein Feld ist dupliziert.');
    }

    /**
     * Jedes Katalogfeld trägt eine deutsche Bedeutung - kein Feld wird nur
     * mit englischem Namen ausgeliefert.
     */
    public function test_every_field_carries_a_german_meaning(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('label', true)
        );

        $allfields = array_merge($result['module']['fields'], $result['module']['pseudo_fields']);
        $this->assertNotEmpty($allfields);
        foreach ($allfields as $field) {
            $this->assertNotSame('', trim($field['meaning']), $field['name'] . ' hat keine deutsche Bedeutung.');
        }
    }

    /**
     * describe_module_fields antwortet für alle vier in Ticket #380
     * hinzugefügten Aktivitätsarten - Kurzform und vollständige Form.
     */
    public function test_answers_for_page_url_folder_resource_short_and_full(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        foreach (['page', 'url', 'folder', 'resource', 'choice', 'forum', 'assign', 'quiz'] as $modname) {
            $short = external_api::clean_returnvalue(
                describe_module_fields::execute_returns(),
                describe_module_fields::execute($modname, false)
            );
            $this->assertNotEmpty($short['module']['fields'], "$modname: Kurzform liefert keine Felder.");

            $full = external_api::clean_returnvalue(
                describe_module_fields::execute_returns(),
                describe_module_fields::execute($modname, true)
            );
            $this->assertNotEmpty($full['module']['pseudo_fields'], "$modname: vollstaendige Form ohne Pseudofelder.");
            $this->assertNotEmpty($full['module']['blocked_fields'], "$modname: vollstaendige Form ohne Sperrliste.");
        }
    }

    /**
     * printheading existiert in Moodle 5.0 nicht mehr und darf im
     * page-Katalog nicht auftauchen (Ticket #380).
     */
    public function test_page_catalog_omits_printheading(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $full = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('page', true)
        );

        $allnames = array_merge(
            array_column($full['module']['fields'], 'name'),
            array_column($full['module']['pseudo_fields'], 'name')
        );
        $this->assertNotContains('printheading', $allnames);
    }

    /**
     * Datei-Pseudofelder (resource, folder) sind vollständig katalogisiert
     * und stehen zugleich auf der Sperrliste (bis Spec 0018).
     */
    public function test_file_fields_are_catalogued_and_unlocked(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        foreach (['resource', 'folder'] as $modname) {
            $full = external_api::clean_returnvalue(
                describe_module_fields::execute_returns(),
                describe_module_fields::execute($modname, true)
            );

            $pseudonames = array_column($full['module']['pseudo_fields'], 'name');
            $this->assertContains('files', $pseudonames, "$modname: 'files' fehlt in den Pseudofeldern.");
            $this->assertNotContains(
                'files',
                $full['module']['blocked_fields'],
                "$modname: 'files' darf seit Issue #434 nicht mehr gesperrt sein."
            );
        }
    }

    /**
     * Abnahmekriterium #382: die Kurzform von describe_module_fields('assign')
     * nennt die üblichen Felder plus Feldbündel plus den Vermerk auf mehr,
     * aber nicht die vollständige Feldliste - der Stresstest der
     * Zweistufigkeit (Spec 0015 §3.1: ~30 Instanzspalten, die Lehrkraft
     * braucht im Regelfall zwölf davon).
     */
    public function test_assign_short_form_uses_common_fields_subset(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $short = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('assign', false)
        );
        $full = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('assign', true)
        );

        $shortnames = array_column($short['module']['fields'], 'name');
        $fullnames = array_column($full['module']['fields'], 'name');

        $this->assertContains('name', $shortnames);
        $this->assertContains('duedate', $shortnames);
        $this->assertNotContains('markinganonymous', $shortnames, 'Kurzform darf nicht alle Felder auflisten.');
        $this->assertLessThan(count($fullnames), count($shortnames));

        $this->assertNotEmpty($short['module']['field_bundles']);
        $bundlenames = array_column($short['module']['field_bundles'], 'name');
        $this->assertContains('standard', $bundlenames);
        $this->assertContains('übung', $bundlenames);

        $this->assertContains('markinganonymous', $fullnames, 'Vollstaendige Form muss alle Felder enthalten.');
    }

    /**
     * Unbekannte Aktivitätsart scheitert mit einer Meldung, die die
     * geführten Arten nennt.
     */
    public function test_unknown_modname_throws(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        describe_module_fields::execute('unbekanntermodultyp');
    }

    /**
     * Rein lesend: der Aufruf führt zu keiner neuen Coursepilot-Capability.
     */
    public function test_introduces_no_write_capability(): void {
        global $DB;

        $this->resetAfterTest();

        $names = $DB->get_fieldset_select('capabilities', 'name', 'component = :component', ['component' => 'local_coursepilot']);
        sort($names);

        $this->assertSame(
            ['local/coursepilot:restoreversion', 'local/coursepilot:use', 'local/coursepilot:useremote', 'local/coursepilot:viewhistory'],
            $names
        );
    }
}
