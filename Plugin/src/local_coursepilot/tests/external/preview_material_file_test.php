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

use local_coursepilot\gd_support;
use local_coursepilot\material_files;
use local_coursepilot\storage_anchor;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Bildvorschau einer Materialdatei (Spec 0018 §3, Issue #430): laengste
 * Kante 768px, JPEG. Nicht-Bilddatei ist kein Fehler ("available": false
 * plus Meldung), eine fehlende Datei bleibt ein Fehler (wie bei den
 * uebrigen Materialordner-Werkzeugen).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(preview_material_file::class)]
final class preview_material_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        webdav_instance::set_transport(null);
        parent::tearDown();
    }

    /**
     * @param string $filename
     * @param int $width
     * @param int $height
     * @return void
     */
    private function store_png(string $filename, int $width = 1600, int $height = 100): void {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => $filename,
        ], $png);
    }

    public function test_shrinks_wide_image_to_768px_longest_edge_jpeg(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('bild.png', 1600, 100);

        $result = preview_material_file::execute('bild.png');

        $this->assertTrue($result['available']);
        $this->assertSame('image/jpeg', $result['mimetype']);
        $this->assertSame(768, $result['width']);
        $this->assertSame(48, $result['height']);

        $decoded = base64_decode($result['image_base64'], true);
        $info = getimagesizefromstring($decoded);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(768, $info[0]);
    }

    public function test_small_image_is_not_upscaled(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('klein.png', 100, 50);

        $result = preview_material_file::execute('klein.png');

        $this->assertSame(100, $result['width']);
        $this->assertSame(50, $result['height']);
    }

    public function test_non_image_file_returns_message_instead_of_error(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], 'kein echtes PDF, reicht fuer den Test');

        $result = preview_material_file::execute('blatt.pdf');

        $this->assertFalse($result['available']);
        $this->assertNotEmpty($result['message']);
        $this->assertNull($result['image_base64']);
    }

    public function test_missing_file_throws(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        preview_material_file::execute('nichtvorhanden.png');
    }

    /**
     * Spec 0018 §3.3: fehlt GD, ist die Vorschau gesperrt, mit klarer
     * Meldung (kein stiller Fallback).
     */
    public function test_missing_gd_blocks_preview_with_clear_message(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('bild.png');
        gd_support::override_for_testing(false);

        try {
            preview_material_file::execute('bild.png');
            $this->fail('materialgdmissing haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialgdmissing', $e->errorcode);
        } finally {
            gd_support::override_for_testing(null);
        }
    }

    /**
     * Spec 0018 §3, Abnahmekriterium "Vorschau einer Nicht-Bilddatei ⇒
     * klare Meldung statt Fehler": gilt auch fuer eine Datei mit Bildendung,
     * die GD nicht als Rasterbild lesen kann (z.B. defekte Bytes) - kein
     * Fehler, sondern "available": false plus Meldung.
     */
    public function test_unreadable_image_bytes_return_message_instead_of_error(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'kaputt.png',
        ], 'das sind keine echten PNG-Bytes');

        $result = preview_material_file::execute('kaputt.png');

        $this->assertFalse($result['available']);
        $this->assertNotEmpty($result['message']);
        $this->assertNull($result['image_base64']);
    }

    /**
     * Der Parameter "ort" (Issue #495) liest aus dem externen Materialbestand,
     * statt aus Moodle - dieselbe Vorschau wie im Moodle-Zweig.
     */
    public function test_ort_bestand_reads_from_external_material(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_material();
        $fake->seed_folder('/Coursepilot/Material');
        $image = imagecreatetruecolor(1600, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $fake->seed_file('/Coursepilot/Material/bild.png', $png);

        $result = preview_material_file::execute('bild.png');

        $this->assertTrue($result['available']);
        $this->assertSame(768, $result['width']);
    }

    public function test_unknown_ort_value_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('bild.png');

        try {
            preview_material_file::execute('bild.png', 'woanders');
            $this->fail('Ein unbekannter Ort-Wert haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidmaterialort', $e->errorcode);
        }
    }

    /**
     * Liegt der Kontextbereich im Bestand, weist die Vorschau einen Pfad
     * darunter ab (Issue #495, Abnahmekriterium 4).
     */
    public function test_rejects_path_under_kontextbereich(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        storage_anchor::write_pointer_document([
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'coursepilot-material/kontext'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'coursepilot-material'],
        ]);

        try {
            preview_material_file::execute('kontext/plan.png');
            $this->fail('Ein Pfad unter dem Kontextbereich haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialpathiskontext', $e->errorcode);
        }
    }
}
