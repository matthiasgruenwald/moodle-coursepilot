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
 * Loeschweg fuer den Aufraeumbericht (Spec 0018 §8.3, Issue #438): entfernt
 * genau die uebergebenen Pfade, nichts ohne ausdrueckliche Liste, kein
 * Teilerfolg bei einem fehlenden Pfad.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(delete_material_files::class)]
final class delete_material_files_test extends \advanced_testcase {

    public function test_deletes_exactly_named_files(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->store('loeschen.pdf', 'weg damit');
        $this->store('behalten.pdf', 'bleibt');

        $result = delete_material_files::execute(['loeschen.pdf']);

        $this->assertSame(['loeschen.pdf'], $result['deleted']);
        $this->assertSame(strlen('weg damit'), $result['freed_bytes']);
        $this->assertSame('loeschen.pdf', $result['path']);
        $this->assertFalse($this->exists('loeschen.pdf'));
        $this->assertTrue($this->exists('behalten.pdf'));
    }

    public function test_empty_list_deletes_nothing(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('behalten.pdf', 'bleibt');

        $result = delete_material_files::execute([]);

        $this->assertSame([], $result['deleted']);
        $this->assertTrue($this->exists('behalten.pdf'));
    }

    /**
     * Ein nicht existierender Pfad in der Liste bricht den gesamten Vorgang
     * ab - kein Teilerfolg bei einem Tippfehler.
     */
    public function test_missing_path_aborts_without_partial_delete(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('vorhanden.pdf', 'da');

        $this->expectException(\moodle_exception::class);
        try {
            delete_material_files::execute(['vorhanden.pdf', 'nichtda.pdf']);
        } finally {
            $this->assertTrue($this->exists('vorhanden.pdf'), 'Kein Teilerfolg: die gueltige Datei bleibt erhalten.');
        }
    }

    public function test_rejects_traversal_path(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        delete_material_files::execute(['../../../etc/passwd']);
    }

    private function store(string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => $filename,
        ], $content);
    }

    private function exists(string $filename): bool {
        return (bool) get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/coursepilot-material/',
            $filename
        );
    }
}
