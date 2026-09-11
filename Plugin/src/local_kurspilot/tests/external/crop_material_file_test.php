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

use local_kurspilot\gd_support;
use local_kurspilot\material_files;
use local_kurspilot\storage_anchor;
use local_kurspilot\tests\webdav\webdav_instance_fixture;
use local_kurspilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Gezielter Bildausschnitt (Spec 0018 §5, Issue #431): schneidet eine
 * liegende Materialdatei zu und legt das Ergebnis wieder im Materialordner
 * ab. Relative Koordinaten (0-1) auf die Vorschau, geschnitten wird aus dem
 * Original in voller Aufloesung.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(crop_material_file::class)]
final class crop_material_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        webdav_instance::use_test_transport(null);
        parent::tearDown();
    }

    /**
     * @param string $filename
     * @param int $width
     * @param int $height
     * @return void
     */
    private function store_png(string $filename, int $width, int $height): void {
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
            'filepath' => '/kurspilot-material/',
            'filename' => $filename,
        ], $png);
    }

    /**
     * Beleg fuer "geschnitten wird aus dem Original, nicht aus der
     * Vorschau" (Spec 0018 §3.1): das Original ist deutlich groesser als
     * die 768px-Vorschaukante, der Zuschnitt muss trotzdem in
     * Originalaufloesung herauskommen.
     */
    public function test_crops_from_full_resolution_original_not_preview(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 3000, 2000);

        $result = crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.5, 0.5, 0.75, 0.75);

        // 0.25 * 3000 = 750, 0.25 * 2000 = 500 - weit ueber 768px, waere
        // aus der Vorschau geschnitten worden, kaeme ein Bruchteil davon heraus.
        $this->assertSame(750, $result['width']);
        $this->assertSame(500, $result['height']);
        $this->assertSame('ausschnitt.png', $result['path']);
        $this->assertTrue($result['created']);

        $stored = get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/kurspilot-material/',
            'ausschnitt.png'
        );
        $this->assertNotFalse($stored);
        $info = getimagesizefromstring($stored->get_content());
        $this->assertSame(750, $info[0]);
        $this->assertSame(500, $info[1]);
    }

    /**
     * Herkunft steht in Moodles vorhandenem source-Feld (Spec 0018 §5) -
     * kein neues Feld, keine Tabelle.
     */
    public function test_origin_is_recorded_in_source_field(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);

        $stored = get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/kurspilot-material/',
            'ausschnitt.png'
        );
        $source = unserialize_object($stored->get_source());
        $this->assertSame('buchseite.png', $source->original);
    }

    /**
     * Der Ausschnitt ist erneut zuschneidbar, ohne dass die Quelldatei neu
     * hochgeladen wird - zweiter Aufruf auf denselben sourcepath, anderer
     * Zielausschnitt.
     */
    public function test_source_file_can_be_cropped_again_without_reupload(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        $first = crop_material_file::execute('buchseite.png', 'versuch1.png', 0.0, 0.0, 0.5, 0.5);
        $second = crop_material_file::execute('buchseite.png', 'versuch2.png', 0.25, 0.25, 0.75, 0.75);

        $this->assertTrue($first['created']);
        $this->assertTrue($second['created']);
        $this->assertSame(500, $second['width']);
    }

    /**
     * Erneutes Zuschneiden auf denselben Zielpfad ueberschreibt, statt
     * einen Fehler zu werfen.
     */
    public function test_recropping_into_same_target_overwrites(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
        $result = crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.25, 0.25);

        $this->assertFalse($result['created']);
        $this->assertSame(250, $result['width']);
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    public static function invalid_coordinate_cases(): array {
        return [
            'x0 negativ' => [-0.1, 0.0, 0.5, 0.5],
            'y1 ueber eins' => [0.0, 0.0, 0.5, 1.1],
            'nullflaeche x' => [0.5, 0.0, 0.5, 0.5],
            'nullflaeche y' => [0.0, 0.5, 0.5, 0.5],
            'x1 kleiner x0' => [0.6, 0.0, 0.4, 0.5],
        ];
    }

    /**
     * @dataProvider invalid_coordinate_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_coordinate_cases')]
    public function test_rejects_invalid_coordinates(float $x0, float $y0, float $x1, float $y1): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        try {
            crop_material_file::execute('buchseite.png', 'ausschnitt.png', $x0, $y0, $x1, $y1);
            $this->fail('materialcropinvalidcoordinates haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialcropinvalidcoordinates', $e->errorcode);
        }
    }

    /**
     * SVG ist raster-only nicht zuschneidbar - klare Meldung statt stiller
     * Ersatzhandlung (Spec 0018 §5).
     */
    public function test_rejects_svg_source_with_clear_message(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/kurspilot-material/',
            'filename' => 'diagramm.svg',
        ], '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        try {
            crop_material_file::execute('diagramm.svg', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
            $this->fail('materialcropsourceunsupported haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialcropsourceunsupported', $e->errorcode);
        }
    }

    /**
     * Gleichzeitigkeitsschutz auf der Zieldatei (Spec 0016 §5.3, hier
     * uebernommen wie bei upload_material_file) - ein falscher
     * expected_contenthash bricht ab, bevor der teure Zuschnitt laeuft.
     */
    public function test_rejects_when_target_contenthash_does_not_match(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);
        crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);

        try {
            crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.1, 0.1, 0.6, 0.6, 'falscherhash');
            $this->fail('materialfilechanged haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialfilechanged', $e->errorcode);
        }
    }

    /**
     * Volle Quote ist ein harter Fehler (Spec 0018 §8.1), wie bei
     * upload_material_file.
     */
    public function test_rejects_when_quota_is_full(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);
        $CFG->userquota = 1;

        $this->expectException(\moodle_exception::class);
        crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
    }

    /**
     * Eine Zielendung, die GD nicht als Zuschnitt schreiben kann (z.B. pdf,
     * svg), wird abgewiesen - der Zuschnitt bleibt ausschliesslich raster.
     */
    public function test_rejects_unsupported_output_extension(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        try {
            crop_material_file::execute('buchseite.png', 'ausschnitt.svg', 0.0, 0.0, 0.5, 0.5);
            $this->fail('materialcropoutputunsupported haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialcropoutputunsupported', $e->errorcode);
        }
    }

    public function test_missing_source_file_throws(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        crop_material_file::execute('nichtvorhanden.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
    }

    public function test_missing_gd_blocks_crop_with_clear_message(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);
        gd_support::override_for_testing(false);

        try {
            crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
            $this->fail('materialgdmissing haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialgdmissing', $e->errorcode);
        } finally {
            gd_support::override_for_testing(null);
        }
    }

    /**
     * @return string PNG-Bytes eines 1000x1000-Bilds.
     */
    private function build_png(int $width = 1000, int $height = 1000): string {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        return $png;
    }

    /**
     * Die Quelle (Issue #495) kommt aus dem externen Materialbestand, das
     * Ergebnis landet trotzdem auf der Werkbank (Abnahmekriterium 2/6).
     */
    public function test_reads_source_from_external_material_and_writes_result_to_workbench(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_material();
        $fake->seed_folder('/Kurspilot/Material');
        $fake->seed_file('/Kurspilot/Material/buchseite.png', $this->build_png(1000, 1000));

        $result = crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);

        $this->assertSame(500, $result['width']);
        $this->assertStringStartsWith('bestand:buchseite.png', $result['source']);

        $stored = get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/kurspilot-material/',
            'ausschnitt.png'
        );
        $this->assertNotFalse($stored, 'Das Ergebnis muss auf der Werkbank (Moodle) liegen.');
    }

    /**
     * "source" nennt Ort und Pruefmerkmal (Groesse, Aenderungszeit) - kein
     * contenthash, den der Materialbestand nicht traegt.
     */
    public function test_source_field_names_ort_and_fingerprint(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        $result = crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);

        $this->assertMatchesRegularExpression(
            '/^bestand:buchseite\.png \(\d+ Byte, geändert \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\)$/u',
            $result['source']
        );
    }

    public function test_unknown_ort_value_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        try {
            crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5, '', 'woanders');
            $this->fail('Ein unbekannter Ort-Wert haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidmaterialort', $e->errorcode);
        }
    }

    /**
     * Liegt der Kontextbereich im Bestand, weist der Zuschnitt eine Quelle
     * darunter ab (Issue #495, Abnahmekriterium 4).
     */
    public function test_rejects_source_path_under_kontextbereich(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        storage_anchor::write_pointer_document([
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'kurspilot-material/kontext'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'kurspilot-material'],
        ]);

        try {
            crop_material_file::execute('kontext/buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
            $this->fail('Eine Quelle unter dem Kontextbereich haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialpathiskontext', $e->errorcode);
        }
    }
}
