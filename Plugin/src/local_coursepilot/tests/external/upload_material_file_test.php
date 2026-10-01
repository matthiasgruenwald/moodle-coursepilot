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

use local_coursepilot\material_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Ablegen einer Datei im Materialordner (Spec 0018 §2/§4.2/§8.1, Issue #428).
 * Happy-Path plus die Absagen, die das Werkzeug eng halten: Endung,
 * Servergroesse, Gleichzeitigkeit, Quote.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(upload_material_file::class)]
final class upload_material_file_test extends \advanced_testcase {

    public function test_creates_new_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->upload('screenshot.png', 'Bildinhalt');

        $this->assertTrue($result['created']);
        $this->assertSame('screenshot.png', $result['path']);
        $this->assertSame(strlen('Bildinhalt'), $result['size']);
        $this->assertSame(
            'Bildinhalt',
            $this->read_stored($user, '/coursepilot-material/', 'screenshot.png')
        );
    }

    public function test_overwrites_existing_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->upload('blatt.pdf', 'alt');

        $result = $this->upload('blatt.pdf', 'neuer Inhalt');

        $this->assertFalse($result['created']);
        $this->assertSame('neuer Inhalt', $this->read_stored($user, '/coursepilot-material/', 'blatt.pdf'));
    }

    public function test_rejects_disallowed_extension(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->upload('programm.exe', 'Inhalt');
    }

    public function test_svg_stays_allowed(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = $this->upload('diagramm.svg', '<svg></svg>');

        $this->assertTrue($result['created']);
    }

    public function test_rejects_invalid_base64(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\invalid_parameter_exception::class);
        upload_material_file::execute('blatt.pdf', '***nicht base64***');
    }

    /**
     * Gleichzeitigkeitsschutz (Spec 0016 §5.3, hier uebernommen): ein
     * falscher expected_contenthash bricht ab.
     */
    public function test_rejects_when_contenthash_does_not_match(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->upload('blatt.pdf', 'alt');

        $this->expectException(\moodle_exception::class);
        upload_material_file::execute('blatt.pdf', base64_encode('neu'), 'falscherhash');
    }

    public function test_accepts_when_contenthash_matches(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $first = $this->upload('blatt.pdf', 'alt');
        $stored = $this->read_file_object(material_files::own_context()->id, '/coursepilot-material/', 'blatt.pdf');

        $result = upload_material_file::execute('blatt.pdf', base64_encode('neu'), $stored->get_contenthash());

        $this->assertFalse($result['created']);
    }

    /**
     * Keine eigene Groessengrenze - gegen die Serverkonfiguration melden
     * (Spec 0018 §8.1, Praezedenz Spec 0017 §9). Getestet ueber den
     * testbaren Kern (Reflection), da sich die PHP-Ini-Werte des
     * Testcontainers nicht aus dem Test heraus setzen lassen - derselbe
     * Kniff wie import_questions_xml_test.php.
     */
    public function test_guard_size_against_limit_rejects_when_over_server_limit(): void {
        $this->resetAfterTest();
        $method = new \ReflectionMethod(upload_material_file::class, 'guard_size_against_limit');
        $method->setAccessible(true);

        $this->expectException(\moodle_exception::class);
        $method->invoke(null, 100, 10);
    }

    public function test_guard_size_against_limit_passes_within_limit(): void {
        $method = new \ReflectionMethod(upload_material_file::class, 'guard_size_against_limit');
        $method->setAccessible(true);

        $method->invoke(null, 10, 100);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Volle Quote ist ein harter Fehler (Spec 0018 §8.1).
     */
    public function test_rejects_when_quota_is_full(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 5;

        $this->expectException(\moodle_exception::class);
        $this->upload('blatt.pdf', str_repeat('x', 100));
    }

    /**
     * Warnung unter 10% Restplatz landet in der Antwortmeldung (Spec 0018
     * §8.1, Form wie Spec 0016 §5.4).
     */
    public function test_warns_below_ten_percent_remaining_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        $result = $this->upload('blatt.pdf', str_repeat('x', 950));

        $this->assertStringContainsString('MB', $result['message']);
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash
     * @return array
     */
    private function upload(string $path, string $content, string $expectedcontenthash = ''): array {
        return upload_material_file::execute($path, base64_encode($content), $expectedcontenthash);
    }

    /**
     * @param \stdClass $user
     * @param string $filepath
     * @param string $filename
     * @return string|false
     */
    private function read_stored(\stdClass $user, string $filepath, string $filename) {
        $file = get_file_storage()->get_file(
            \context_user::instance($user->id)->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            $filepath,
            $filename
        );
        return $file ? $file->get_content() : false;
    }

    /**
     * @param int $contextid
     * @param string $filepath
     * @param string $filename
     * @return \stored_file
     */
    private function read_file_object(int $contextid, string $filepath, string $filename): \stored_file {
        return get_file_storage()->get_file(
            $contextid,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            $filepath,
            $filename
        );
    }
}
