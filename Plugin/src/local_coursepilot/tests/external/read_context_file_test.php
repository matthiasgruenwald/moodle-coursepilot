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
use local_coursepilot\context_files;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Lesen aus dem Kontextbereich (Issue #343). Sicherheitsrelevant: neben dem
 * Happy-Path echte Angriffstests fuer Pfadausbruch, fremde Bereiche und
 * Personen-Isolation; ausserdem der Beleg, dass kein Schreibpfad existiert.
 * Seit Issue #490 zusaetzlich der externe Zweig ueber einen Kontextpointer
 * der zweiten Fassung und den WebDAV-Transport-Fake.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(read_context_file::class)]
final class read_context_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        webdav_instance::set_transport(null);
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

        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Gemerkte Vorlagen');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame('# Gemerkte Vorlagen', $result['content']);
        $this->assertSame('vorlagen.md', $result['filename']);
        // Der zurueckgegebene Pfad ist derselbe, den der Aufruf entgegennahm -
        // ohne Wurzelordner davor, sonst baut ein Client daraus
        // "coursepilot/..." und landet in /coursepilot/coursepilot/... (#425 F1).
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

        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Gemerkte Vorlagen');

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

        $this->create_context_file($user, '/coursepilot/faecher/mathe/', 'profil.md', '# Mathe-Fachprofil');

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
        $this->create_context_file($teachera, '/coursepilot/', 'lerngruppe-a.md', 'Vertraulich A');

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
        $this->assertSame(['path', 'vorheriger_ort'], array_keys($definition));
    }

    /**
     * "vorheriger_ort" ohne offenen Altbestand ist ein benannter Fehler
     * (Issue #498, Spec #486 §6).
     */
    public function test_vorheriger_ort_switch_without_open_altbestand_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            read_context_file::execute('plan.md', true);
            $this->fail('Ohne offenen Altbestand haette der Schalter abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('altbestandclosed', $e->errorcode);
        }
    }

    /**
     * Mit offenem Altbestand liest der Schalter vom vorherigen Ort, nicht
     * vom aktuellen - beide Dateien heissen hier gleich, der Inhalt
     * unterscheidet sie.
     */
    public function test_vorheriger_ort_switch_reads_from_the_previous_location(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'plan.md', '# Aktuell');
        $this->create_context_file($user, '/altbestand/', 'plan.md', '# Alt');
        $this->write_pointer_with_vorheriger_ort($user, 'altbestand');

        $current = read_context_file::execute('plan.md');
        $current = external_api::clean_returnvalue(read_context_file::execute_returns(), $current);
        $this->assertSame('# Aktuell', $current['content']);

        $previous = read_context_file::execute('plan.md', true);
        $previous = external_api::clean_returnvalue(read_context_file::execute_returns(), $previous);
        $this->assertSame('# Alt', $previous['content']);
    }

    /**
     * Personenbezug-Sperre und alle Auflösungsprüfungen gelten auch für den
     * vorherigen Ort (Issue #498 Akzeptanzkriterium).
     */
    public function test_vorheriger_ort_switch_still_locks_marked_content(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/altbestand/', 'lerngruppe.md', $this->marked_content());
        $this->write_pointer_with_vorheriger_ort($user, 'altbestand');

        $this->expectException(\moodle_exception::class);
        read_context_file::execute('lerngruppe.md', true);
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
        foreach (\local_coursepilot\privacy_surface::allowed_tools() as $toolname => $functionname) {
            if (!str_contains($toolname, 'context')) {
                continue;
            }
            $this->assertStringNotContainsStringIgnoringCase('save', $toolname);
            $this->assertStringNotContainsStringIgnoringCase('upload', $toolname);
            if (\local_coursepilot\tool_registry::is_write($toolname)) {
                $writetools[] = $toolname;
            }
        }
        sort($writetools);
        $this->assertSame(
            ['coursepilot_append_context_file', 'coursepilot_write_context_file'],
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
        $this->assertFalse(\local_coursepilot\personal_data::allowed());
    }

    /**
     * Bei ausgeschaltetem Schalter ist eine personenbezogen markierte Datei
     * nicht lesbar - das Lese-Werkzeug scheitert mit einer klaren Meldung.
     */
    public function test_personal_data_marked_file_unreadable_when_switch_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

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
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $content = $this->marked_content();
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $content);

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
        $content = "---\ntype: vorhaben\ncoursepilot:\n  personenbezug: false\n---\n# Sachtext";
        $this->create_context_file($user, '/coursepilot/', 'sachtext.md', $content);

        $resultoff = read_context_file::execute('sachtext.md');
        $resultoff = external_api::clean_returnvalue(read_context_file::execute_returns(), $resultoff);
        $this->assertSame($content, $resultoff['content']);

        set_config('allowpersonaldata', 1, 'local_coursepilot');
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
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame('# Extern gemerkt', $result['content']);
        $this->assertSame('vorlagen.md', $result['path']);
        $this->assertSame('vorlagen.md', $result['filename']);
    }

    /**
     * Konfliktschutz (Issue #513, Spec #486 §4/§6): Lesen liefert extern
     * einen nicht leeren Pruefwert - dasselbe Muster wie bei
     * list_context_files, hier fuer den Einzeldatei-Lesezweig.
     */
    public function test_external_read_returns_a_nonempty_checkvalue(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertNotSame('', $result['contenthash']);
    }

    /**
     * Ohne ETag (IServ) traegt der Pruefwert die Aenderungszeit - schwaecher,
     * aber ebenfalls nicht leer (Issue #513, Spec §4).
     */
    public function test_external_read_returns_a_nonempty_checkvalue_without_etag(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->without_etags();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertNotSame('', $result['contenthash']);
    }

    /**
     * Eine fehlende externe Datei ist "nicht gefunden" - dieselbe Meldung
     * wie im Moodle-Zweig, kein anderer Fehlertyp.
     */
    public function test_missing_external_file_throws_contextfilenotfound(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

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
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        $this->expectException(\moodle_exception::class);
        read_context_file::execute('lerngruppe.md');
    }

    public function test_personal_data_marked_file_readable_when_external_and_switch_on(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $content = $this->marked_content();
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $content);
        set_config('allowpersonaldata', 1, 'local_coursepilot');

        $result = read_context_file::execute('lerngruppe.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame($content, $result['content']);
    }

    /**
     * Ein Leseausfall liefert eine Meldung, legt aber keinen Ausstand an
     * (Issue #492, CONTEXT.md "Ausstand": "kein Ausstand ist ... ein
     * Leseausfall ... denn es ist nichts verloren gegangen").
     */
    public function test_read_failure_creates_no_ausstand_entry(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->deny_auth();

        try {
            read_context_file::execute('vorlagen.md');
            $this->fail('Anmeldung abgelehnt haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavexternalerror', $e->errorcode);
            // Issue #516 Akzeptanzkriterium: ein Leseausfall nennt der KI
            // ausdruecklich die Kontext-Luecke - sprachneutral geprueft
            // (die PHPUnit-Instanz loest nur Englisch auf, siehe
            // write_context_file_test::test_german_messages_carry_the_required_wording()
            // fuer die deutsche Formulierung).
            $this->assertStringContainsString('context gap', $e->getMessage());
        }

        $this->assertSame([], \local_coursepilot\ausstand_notice::list_grouped());
    }

    /**
     * Die deutsche Formulierung nennt ausdruecklich "Kontext-Lücke" und nie
     * das Wort "Ausstand" (Issue #516 Akzeptanzkriterium, CONTEXT.md).
     */
    public function test_german_read_failure_message_names_the_context_gap(): void {
        $string = [];
        require(__DIR__ . '/../../lang/de/local_coursepilot.php');

        $this->assertStringContainsString('Kontext-Lücke', $string['webdavexternalerror']);
        $this->assertStringNotContainsString('Ausstand', $string['webdavexternalerror']);
    }

    /**
     * Weder Werkzeugname noch -antwort verraten den Speicherort (Spec §6/§15).
     */
    public function test_external_read_response_reveals_no_storage_location(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);
        $encoded = json_encode($result);

        $this->assertStringNotContainsString($this->fixtureserver, $encoded);
        $this->assertStringNotContainsString($this->fixturekonto, $encoded);
    }

    /**
     * @return string Kontextdatei-Inhalt mit Frontmatter-Markierung
     *         "coursepilot.personenbezug: true".
     */
    private function marked_content(): string {
        return "---\ntype: lerngruppe\ncoursepilot:\n  personenbezug: true\n  weitergabe: nicht_weitergeben\n---\n# S. M., 7a";
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
