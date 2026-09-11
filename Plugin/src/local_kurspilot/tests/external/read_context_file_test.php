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

namespace local_kurspilot\external;

use core_external\external_api;
use local_kurspilot\context_files;
use local_kurspilot\tests\webdav\webdav_instance_fixture;
use local_kurspilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Lesen aus dem Kontextbereich (Issue #343). Sicherheitsrelevant: neben dem
 * Happy-Path echte Angriffstests fuer Pfadausbruch, fremde Bereiche und
 * Personen-Isolation; ausserdem der Beleg, dass kein Schreibpfad existiert.
 * Seit Issue #490 zusaetzlich der externe Zweig ueber einen Kontextpointer
 * der zweiten Fassung und den WebDAV-Transport-Fake.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(read_context_file::class)]
final class read_context_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        webdav_instance::use_test_transport(null);
        parent::tearDown();
    }

    /**
     * Die Vorlagendatei an der Wurzel ist mit demselben Vertrag lesbar wie
     * jede andere Kontextdatei - kein Sonderweg.
     */
    public function test_reads_root_template_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/kurspilot/', 'vorlagen.md', '# Gemerkte Vorlagen');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame('# Gemerkte Vorlagen', $result['content']);
        $this->assertSame('vorlagen.md', $result['filename']);
        // Der zurueckgegebene Pfad ist derselbe, den der Aufruf entgegennahm -
        // ohne Wurzelordner davor, sonst baut ein Client daraus
        // "kurspilot/..." und landet in /kurspilot/kurspilot/... (#425 F1).
        $this->assertSame('vorlagen.md', $result['path']);
    }

    /**
     * contenthash und timemodified kommen additiv mit (Spec 0016 §2) - sie
     * sind die Grundlage fuer Gleichzeitigkeitsschutz und
     * Handaenderungs-Erkennung im Schreibpfad.
     */
    public function test_returns_contenthash_and_timemodified(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/kurspilot/', 'vorlagen.md', '# Gemerkte Vorlagen');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame(sha1('# Gemerkte Vorlagen'), $result['contenthash']);
        $this->assertGreaterThan(0, $result['timemodified']);
    }

    /**
     * Eine Datei in einem Unterordner ist ohne Sonderfall lesbar.
     */
    public function test_reads_subfolder_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/kurspilot/faecher/mathe/', 'profil.md', '# Mathe-Fachprofil');

        $result = read_context_file::execute('faecher/mathe/profil.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame('# Mathe-Fachprofil', $result['content']);
    }

    /**
     * Ein Pfad, der aus dem Bereich herausfuehren wuerde, wird abgewiesen
     * (CRITICAL) - Moodles Parametervalidierung lehnt das "../"-Segment
     * bereits an der API-Grenze ab; eine Datei ausserhalb der Wurzel bleibt
     * so unerreichbar.
     */
    public function test_traversal_attempt_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/', 'secret.txt', 'geheim');

        $this->expectException(\moodle_exception::class);
        read_context_file::execute('../secret.txt');
    }

    /**
     * Person A erreicht unter keinen Umstaenden eine Datei von Person B
     * (CRITICAL), selbst wenn der exakte Dateiname bekannt ist.
     */
    public function test_person_a_cannot_read_person_bs_file(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teachera);
        $this->create_context_file($teachera, '/kurspilot/', 'lerngruppe-a.md', 'Vertraulich A');

        $this->setUser($teacherb);
        $this->expectException(\moodle_exception::class);
        read_context_file::execute('lerngruppe-a.md');
    }

    /**
     * Kein Parameter erlaubt es, contextid/itemid/component zu manipulieren
     * - "path" ist der einzige Parameter.
     */
    public function test_execute_parameters_expose_no_area_selector(): void {
        $definition = read_context_file::execute_parameters()->keys;
        $this->assertSame(['path'], array_keys($definition));
    }

    /**
     * Der Kontextbereich hat genau zwei Schreibpfade (#408/#409, Spec 0016
     * §4): write_context_file und append_context_file. Kein Hochladen, kein
     * Speichern von Material *im Kontextbereich* - die Oberflaeche bleibt
     * eng, auch nachdem sie nicht mehr rein lesend ist. Der Materialordner
     * (Spec 0018 §2/§4.2, #428) ist ein bewusst eigener, so benannter
     * Bereich mit eigenem Werkzeug ("upload_material_file") - die
     * "save"/"upload"-Ausschlussregel gilt deshalb nur fuer *context*-Tools.
     */
    public function test_context_write_surface_is_exactly_two_tools(): void {
        $writetools = [];
        foreach (\local_kurspilot\privacy_surface::allowed_tools() as $toolname => $functionname) {
            if (!str_contains($toolname, 'context')) {
                continue;
            }
            $this->assertStringNotContainsStringIgnoringCase('save', $toolname);
            $this->assertStringNotContainsStringIgnoringCase('upload', $toolname);
            if (\local_kurspilot\tool_registry::is_write($toolname)) {
                $writetools[] = $toolname;
            }
        }
        sort($writetools);
        $this->assertSame(
            ['kurspilot_append_context_file', 'kurspilot_write_context_file'],
            $writetools
        );
    }

    /**
     * Schalter fuer personenbezogene Kontextdaten (#344, ADR 0011):
     * voreingestellt aus - ohne explizites set_config() liefert
     * personal_data::allowed() false.
     */
    public function test_personal_data_switch_defaults_off(): void {
        $this->resetAfterTest();
        $this->assertFalse(\local_kurspilot\personal_data::allowed());
    }

    /**
     * Bei ausgeschaltetem Schalter ist eine personenbezogen markierte Datei
     * nicht lesbar - das Lese-Werkzeug scheitert mit einer klaren Meldung.
     */
    public function test_personal_data_marked_file_unreadable_when_switch_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/kurspilot/', 'lerngruppe.md', $this->marked_content());

        $this->expectException(\moodle_exception::class);
        read_context_file::execute('lerngruppe.md');
    }

    /**
     * Bei eingeschaltetem Schalter ist dieselbe Datei lesbar, und der
     * Inhalt kommt byteidentisch zurueck - kein automatisches Schwaerzen
     * oder Umschreiben.
     */
    public function test_personal_data_marked_file_readable_when_switch_on(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_kurspilot');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $content = $this->marked_content();
        $this->create_context_file($user, '/kurspilot/', 'lerngruppe.md', $content);

        $result = read_context_file::execute('lerngruppe.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame($content, $result['content']);
    }

    /**
     * Unmarkierte Inhalte sind in beiden Stellungen des Schalters
     * unveraendert lesbar.
     */
    public function test_unmarked_file_readable_regardless_of_switch(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $content = "---\ntype: vorhaben\nkurspilot:\n  personenbezug: false\n---\n# Sachtext";
        $this->create_context_file($user, '/kurspilot/', 'sachtext.md', $content);

        $resultoff = read_context_file::execute('sachtext.md');
        $resultoff = external_api::clean_returnvalue(read_context_file::execute_returns(), $resultoff);
        $this->assertSame($content, $resultoff['content']);

        set_config('allowpersonaldata', 1, 'local_kurspilot');
        $resulton = read_context_file::execute('sachtext.md');
        $resulton = external_api::clean_returnvalue(read_context_file::execute_returns(), $resulton);
        $this->assertSame($content, $resulton['content']);
    }

    /**
     * Der externe Kontextbereich (Issue #490, Spec #486 §2/§6) liest ueber
     * den WebDAV-Client - dieselbe Werkzeugantwort wie im Moodle-Zweig.
     */
    public function test_reads_external_context_file_via_webdav(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame('# Extern gemerkt', $result['content']);
        $this->assertSame('vorlagen.md', $result['path']);
        $this->assertSame('vorlagen.md', $result['filename']);
    }

    /**
     * Eine fehlende externe Datei ist "nicht gefunden" - dieselbe Meldung
     * wie im Moodle-Zweig, kein anderer Fehlertyp.
     */
    public function test_missing_external_file_throws_contextfilenotfound(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        try {
            read_context_file::execute('vorlagen.md');
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilenotfound', $e->errorcode);
        }
    }

    /**
     * Die Personenbezug-Prüfung wirkt extern am Inhalt genauso wie in
     * Moodle (Spec §6).
     */
    public function test_personal_data_marked_file_unreadable_when_external_and_switch_off(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/lerngruppe.md', $this->marked_content());

        $this->expectException(\moodle_exception::class);
        read_context_file::execute('lerngruppe.md');
    }

    public function test_personal_data_marked_file_readable_when_external_and_switch_on(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $content = $this->marked_content();
        $fake->seed_file('/Kurspilot/Kontext/lerngruppe.md', $content);
        set_config('allowpersonaldata', 1, 'local_kurspilot');

        $result = read_context_file::execute('lerngruppe.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame($content, $result['content']);
    }

    /**
     * Weder Werkzeugname noch -antwort verraten den Speicherort (Spec §6/§15).
     */
    public function test_external_read_response_reveals_no_storage_location(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/vorlagen.md', '# Extern');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);
        $encoded = json_encode($result);

        $this->assertStringNotContainsString($this->fixtureserver, $encoded);
        $this->assertStringNotContainsString($this->fixturekonto, $encoded);
    }

    /**
     * @return string Kontextdatei-Inhalt mit Frontmatter-Markierung
     *         "kurspilot.personenbezug: true".
     */
    private function marked_content(): string {
        return "---\ntype: lerngruppe\nkurspilot:\n  personenbezug: true\n  weitergabe: nicht_weitergeben\n---\n# S. M., 7a";
    }

    /**
     * @param \stdClass $user
     * @param string $filepath
     * @param string $filename
     * @param string $content
     */
    private function create_context_file(\stdClass $user, string $filepath, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => context_files::COMPONENT,
            'filearea' => context_files::FILEAREA,
            'itemid' => context_files::ITEMID,
            'filepath' => $filepath,
            'filename' => $filename,
        ], $content);
    }
}
