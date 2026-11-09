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

use local_coursepilot\material_area;
use local_coursepilot\tests\webp_encoder_stub;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/webp_encoder_stub_functions.php');

/**
 * A WebP crop target is handed to the WebP encoder (#673).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(crop_material_file::class)]
final class crop_material_file_673_contract_test extends \advanced_testcase {
    /**
     * Switches the encoder stub off again.
     */
    protected function tearDown(): void {
        webp_encoder_stub::$active = false;
        parent::tearDown();
    }

    /**
     * The stored file is what the encoder wrote, cropped to the requested size.
     */
    public function test_a_webp_target_stores_the_output_of_the_webp_encoder(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($image);
        get_file_storage()->create_file_from_string([
            'contextid' => \local_coursepilot\material_files::own_context()->id,
            'component' => \local_coursepilot\material_files::COMPONENT,
            'filearea' => \local_coursepilot\material_files::FILEAREA,
            'itemid' => \local_coursepilot\material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'source.png',
        ], (string) ob_get_clean());
        webp_encoder_stub::$active = true;

        crop_material_file::execute('source.png', 'crop.webp', 0.0, 0.0, 0.5, 0.5);

        $this->assertSame('webp:10x5', material_area::read('crop.webp')['content']);
    }
}
