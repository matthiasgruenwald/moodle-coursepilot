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
use local_coursepilot\material_files;
use local_coursepilot\storage_anchor;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Auflisten des Materialordners (Spec 0018 §2, Issue #428): Pfad, Groesse,
 * `contenthash`, Aenderungszeit je Datei, plus verbleibender Speicherplatz.
 * Seit Issue #495 zusaetzlich der Parameter "ort" (Bestand/Werkbank), der
 * externe Zweig ueber den WebDAV-Transport-Fake, und die
 * Kontextbereich-Ausnahme (Spec #486 §2/§7).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(list_material_files::class)]
final class list_material_files_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    public function test_lists_empty_root(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = list_material_files::execute();

        $this->assertSame('', $result['path']);
        $this->assertSame([], $result['entries']);
    }

    public function test_lists_uploaded_file_with_metadata(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;
        $stored = get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $result = list_material_files::execute();

        $this->assertCount(1, $result['entries']);
        $entry = $result['entries'][0];
        $this->assertSame('blatt.pdf', $entry['name']);
        $this->assertSame('file', $entry['type']);
        $this->assertSame(strlen('Inhalt'), $entry['size']);
        $this->assertSame($stored->get_contenthash(), $entry['contenthash']);
        $this->assertGreaterThan(0, $entry['timemodified']);
    }

    /**
     * Verbleibender Speicherplatz ist Teil der Antwort (Issue #428 - "mit
     * ... verbleibendem Speicherplatz").
     */
    public function test_reports_remaining_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        $result = list_material_files::execute();

        $this->assertNotNull($result['remaining_quota_mb']);
    }

    public function test_remaining_quota_is_null_without_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 0;

        $result = list_material_files::execute();

        $this->assertNull($result['remaining_quota_mb']);
    }

    public function test_lists_subfolder(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/faecher/mathe/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $result = list_material_files::execute('faecher/mathe');

        $this->assertSame('faecher/mathe', $result['path']);
        $this->assertSame('blatt.pdf', $result['entries'][0]['name']);
    }

    public function test_rejects_traversal_path(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        list_material_files::execute('../../../etc');
    }

    /**
     * Der Kontextpointer (Issue #445) liegt physisch im Kontextbereich-
     * Anker, nicht im Materialordner - dieselbe Ausschluss-Regel gilt hier
     * trotzdem defensiv, falls Anker und Materialordner je zusammenfallen.
     */
    public function test_pointer_file_is_excluded_from_listing(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => \local_coursepilot\storage_anchor::POINTER_FILENAME,
        ], '{"kontextbereich":"coursepilot","materialordner":"coursepilot-material"}');

        $result = list_material_files::execute();

        $this->assertSame([], $result['entries']);
    }

    /**
     * Ohne Kontextpointer zeigen "bestand" und "werkbank" auf denselben Ort
     * (Issue #495, Abnahmekriterium 1).
     */
    public function test_ort_bestand_and_ort_werkbank_show_the_same_place_in_moodle(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $bestand = list_material_files::execute('', material_files::ORT_BESTAND);
        $werkbank = list_material_files::execute('', material_files::ORT_WERKBANK);

        $this->assertSame(['blatt.pdf'], array_column($bestand['entries'], 'name'));
        $this->assertSame(array_column($bestand['entries'], 'name'), array_column($werkbank['entries'], 'name'));
    }

    /**
     * Bei einem Pointer der ersten Fassung mit eigenem Materialpfad zeigen
     * "bestand" und "werkbank" weiterhin auf denselben Ordner (Issue #520,
     * Spec #486 §1) - der Kontextpointer verschiebt beide Orte gemeinsam,
     * statt die Werkbank an der Standardwurzel zurueckzulassen.
     */
    public function test_ort_bestand_and_ort_werkbank_follow_legacy_pointer_together(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => storage_anchor::own_context()->id,
            'component' => storage_anchor::COMPONENT,
            'filearea' => storage_anchor::FILEAREA,
            'itemid' => storage_anchor::ITEMID,
            'filepath' => '/' . storage_anchor::ANCHOR_DEFAULT_ROOT . '/',
            'filename' => storage_anchor::POINTER_FILENAME,
        ], json_encode(['kontextbereich' => 'coursepilot', 'materialordner' => 'eigener-materialpfad']));
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/eigener-materialpfad/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $bestand = list_material_files::execute('', material_files::ORT_BESTAND);
        $werkbank = list_material_files::execute('', material_files::ORT_WERKBANK);

        $this->assertSame(['blatt.pdf'], array_column($bestand['entries'], 'name'));
        $this->assertSame(array_column($bestand['entries'], 'name'), array_column($werkbank['entries'], 'name'));
    }

    public function test_unknown_ort_value_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            list_material_files::execute('', 'woanders');
            $this->fail('Ein unbekannter Ort-Wert haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidmaterialort', $e->errorcode);
        }
    }

    /**
     * Der externe Materialbestand (Issue #495, Spec #486 §2/§7) listet ueber
     * den WebDAV-Client, statt ueber Moodles Dateispeicher - dieselbe
     * Werkzeugantwort wie im Moodle-Zweig, `contenthash` bleibt leer.
     */
    public function test_lists_external_material_via_webdav(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_material();
        $fake->seed_folder('/Coursepilot/Material');
        $fake->seed_file('/Coursepilot/Material/blatt.pdf', 'Inhalt');

        $result = list_material_files::execute();
        $result = external_api::clean_returnvalue(list_material_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'blatt.pdf');
        $this->assertNotNull($entry);
        $this->assertSame('file', $entry['type']);
        $this->assertSame('', $entry['contenthash']);
    }

    /**
     * Eine noch nicht angelegte externe Ebene ist leer, nie ein Fehler.
     */
    public function test_listing_missing_external_material_directory_is_empty(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_material();

        $result = list_material_files::execute();

        $this->assertSame([], $result['entries']);
    }

    /**
     * "werkbank" bleibt beim externen Materialbestand unveraendert in Moodle
     * erreichbar (Abnahmekriterium 2: schreibende Werkzeuge zielen immer auf
     * die Werkbank, die den Materialbestand-Pointer nicht kennt).
     */
    public function test_ort_werkbank_stays_in_moodle_when_material_is_external(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_material();
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'werkbankdatei.pdf',
        ], 'Inhalt');

        $result = list_material_files::execute('', material_files::ORT_WERKBANK);

        $this->assertSame(['werkbankdatei.pdf'], array_column($result['entries'], 'name'));
    }

    /**
     * Liegt der Kontextbereich im Bestand, erscheint er als eigener
     * Eintragstyp "kontextbereich" (Issue #495, Abnahmekriterium 4) - nicht
     * als gewoehnlicher Ordner.
     */
    public function test_kontextbereich_inside_bestand_appears_as_own_entry_type(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        // Kontextbereich liegt bewusst als Unterordner des Materialbestands -
        // erlaubte Richtung (CONTEXT.md "Materialbestand").
        storage_anchor::write_pointer_document([
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'coursepilot-material/kontext'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'coursepilot-material'],
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/kontext/',
            'filename' => 'plan.md',
        ], '# Plan');
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $result = list_material_files::execute();

        $kontexteintrag = $this->find_entry($result['entries'], 'kontext');
        $this->assertNotNull($kontexteintrag);
        $this->assertSame('kontextbereich', $kontexteintrag['type']);
        $materialeintrag = $this->find_entry($result['entries'], 'blatt.pdf');
        $this->assertSame('file', $materialeintrag['type']);
    }

    /**
     * Ein Materialweg lehnt jeden Pfad am oder unter dem Kontextbereich ab
     * (Issue #495, Abnahmekriterium 4) - benannter Fehler statt stiller
     * Auflistung des Kontextbereichs ueber den Materialweg.
     */
    public function test_listing_path_under_kontextbereich_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        storage_anchor::write_pointer_document([
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'coursepilot-material/kontext'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'coursepilot-material'],
        ]);

        try {
            list_material_files::execute('kontext');
            $this->fail('Ein Pfad unter dem Kontextbereich haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialpathiskontext', $e->errorcode);
        }
    }

    /**
     * @param array $entries
     * @param string $name
     * @return array|null
     */
    private function find_entry(array $entries, string $name): ?array {
        foreach ($entries as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }
        return null;
    }
}
