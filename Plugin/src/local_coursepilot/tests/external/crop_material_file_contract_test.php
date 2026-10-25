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
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact pixels, messages, checks and their order of crop_material_file (Spec 0018 §5, #431).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(crop_material_file::class)]
final class crop_material_file_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Plain user who owns the material store.
     */
    private function setup_user(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * Stores raw bytes as material file.
     *
     * @param string $filename
     * @param string $content
     */
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

    /**
     * Stores a PNG whose pixel (x, y) has the unique colour (x * 20, y * 20, 100).
     *
     * @param string $filename
     * @param int $width
     * @param int $height
     */
    private function store_pattern(string $filename, int $width, int $height): void {
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $x * 20, $y * 20, 100));
            }
        }
        $this->store($filename, $this->encode($image, 'png'));
    }

    /**
     * Stores a fully transparent PNG.
     *
     * @param string $filename
     * @param int $width
     * @param int $height
     */
    private function store_transparent(string $filename, int $width, int $height): void {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $this->store($filename, $this->encode($image, 'png'));
    }

    /**
     * Encodes a GD image.
     *
     * @param \GdImage $image
     * @param string $format
     * @return string
     */
    private function encode(\GdImage $image, string $format): string {
        ob_start();
        $format === 'png' ? imagepng($image) : imagegif($image);
        return ob_get_clean();
    }

    /**
     * Reads a stored material file back as GD image.
     *
     * @param string $filename
     * @return \GdImage
     */
    private function load(string $filename): \GdImage {
        $file = get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/coursepilot-material/',
            $filename
        );
        $this->assertNotFalse($file);
        $image = imagecreatefromstring($file->get_content());
        $this->assertNotFalse($image);
        return $image;
    }

    /**
     * Returns the colour of a pixel as [r, g, b, alpha].
     *
     * @param \GdImage $image
     * @param int $x
     * @param int $y
     * @return int[]
     */
    private function pixel(\GdImage $image, int $x, int $y): array {
        $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        return [$rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']];
    }

    public function test_the_context_restriction_is_checked_before_anything_else(): void {
        $this->setup_user();
        $this->store_pattern('a.png', 5, 5);
        external_api::set_context_restriction(\context_course::instance(SITEID));
        try {
            $e = $this->caught(fn() => crop_material_file::execute('missing.png', 'b.png', 0.0, 0.0, 0.5, 0.5));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }

    public function test_the_file_right_is_checked_before_the_source_is_looked_up(): void {
        global $DB, $USER;
        $this->setup_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('moodle/user:manageownfiles', CAP_PROHIBIT, $roleid, \context_user::instance($USER->id)->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $e = $this->caught(fn() => crop_material_file::execute('missing.png', 'b.png', 0.0, 0.0, 0.5, 0.5));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_extensions_are_compared_in_lower_case_for_source_and_target(): void {
        $this->setup_user();
        $this->store_pattern('PHOTO.PNG', 5, 5);

        $result = crop_material_file::execute('PHOTO.PNG', 'OUT.PNG', 0.0, 0.0, 1.0, 1.0);

        $this->assertSame('OUT.PNG', $result['path']);
        $this->assertSame([5, 5], [$result['width'], $result['height']]);
    }

    public function test_a_source_that_is_no_raster_image_is_refused_by_name(): void {
        $this->setup_user();
        // Valid PNG bytes under a document name: only the extension may decide.
        $image = imagecreatetruecolor(2, 2);
        $this->store('doc.pdf', $this->encode($image, 'png'));

        $e = $this->caught(fn() => crop_material_file::execute('doc.pdf', 'b.png', 0.0, 0.0, 0.5, 0.5));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialcropsourceunsupported', $e->errorcode);
        $this->assertSame('doc.pdf', $e->a);
    }

    public function test_corrupt_image_bytes_are_refused_like_an_unsupported_source(): void {
        $this->setup_user();
        $this->store('bad.png', 'definitely not a png');

        $e = $this->caught(fn() => crop_material_file::execute('bad.png', 'b.png', 0.0, 0.0, 0.5, 0.5));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialcropsourceunsupported', $e->errorcode);
        $this->assertSame('bad.png', $e->a);
    }

    public function test_the_expected_contenthash_guards_only_an_existing_target(): void {
        $this->setup_user();
        $this->store_pattern('a.png', 5, 5);

        $missing = $this->caught(fn() => crop_material_file::execute('a.png', 'b.png', 0.0, 0.0, 0.5, 0.5, 'abc'));
        $this->assertInstanceOf(\moodle_exception::class, $missing);
        $this->assertSame('materialfilechanged', $missing->errorcode);
        $this->assertSame('b.png', $missing->a);

        $first = crop_material_file::execute('a.png', 'b.png', 0.0, 0.0, 0.5, 0.5);
        $this->assertTrue($first['created']);
        $hash = sha1($this->load_content('b.png'));

        $wrong = $this->caught(fn() => crop_material_file::execute('a.png', 'b.png', 0.0, 0.0, 0.5, 0.5, 'abc'));
        $this->assertInstanceOf(\moodle_exception::class, $wrong);
        $this->assertSame('materialfilechanged', $wrong->errorcode);

        $right = crop_material_file::execute('a.png', 'b.png', 0.0, 0.0, 0.5, 0.5, $hash);
        $this->assertFalse($right['created']);

        $blank = crop_material_file::execute('a.png', 'b.png', 0.0, 0.0, 0.5, 0.5, '');
        $this->assertFalse($blank['created']);
    }

    /**
     * Returns the stored bytes of a material file.
     *
     * @param string $filename
     * @return string
     */
    private function load_content(string $filename): string {
        return get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/coursepilot-material/',
            $filename
        )->get_content();
    }

    public function test_messages_name_path_source_and_size_for_created_and_overwritten(): void {
        $this->setup_user();
        $this->store_pattern('a.png', 10, 8);

        $created = crop_material_file::execute('a.png', 'b.png', 0.0, 0.0, 0.5, 0.5);
        $overwritten = crop_material_file::execute('a.png', 'b.png', 0.0, 0.0, 0.5, 0.5);

        $this->assertSame('b.png created, cropped from a.png (5×4px).', $created['message']);
        $this->assertSame('b.png overwritten, cropped from a.png (5×4px).', $overwritten['message']);
        $this->assertSame(strlen($this->load_content('b.png')), $overwritten['size']);
        $this->assertMatchesRegularExpression(
            '/^store:a\.png \(\d+ bytes, modified \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\)$/',
            $created['source']
        );
        $this->assertSame(
            sprintf(
                'store:a.png (%d bytes, modified %s)',
                strlen($this->load_content('a.png')),
                substr($created['source'], -21, 20)
            ),
            $created['source']
        );
        $this->assertSame(['path', 'source', 'created', 'width', 'height', 'size', 'message'], array_keys($created));
    }

    public function test_a_nearly_full_quota_adds_its_warning_after_one_space(): void {
        global $CFG;
        $this->setup_user();
        $noise = imagecreatetruecolor(200, 200);
        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 200; $y++) {
                $colour = imagecolorallocate($noise, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                imagesetpixel($noise, $x, $y, $colour);
            }
        }
        $this->store('noise.png', $this->encode($noise, 'png'));
        $probe = crop_material_file::execute('noise.png', 'probe.png', 0.0, 0.0, 0.05, 0.05);
        $CFG->userquota = strlen($this->load_content('noise.png')) + strlen($this->load_content('probe.png'))
            + $probe['size'] + 50;

        $result = crop_material_file::execute('noise.png', 'probe.png', 0.0, 0.0, 0.05, 0.05);

        $this->assertSame(
            'probe.png overwritten, cropped from noise.png (10×10px). Note: only 0.0 MB of storage left.',
            $result['message']
        );
    }

    public function test_the_edges_of_the_relative_range_are_allowed_and_everything_else_is_refused(): void {
        $this->setup_user();
        $this->store_pattern('a.png', 5, 5);

        $full = crop_material_file::execute('a.png', 'full.png', 0.0, 0.0, 1.0, 1.0);
        $this->assertSame([5, 5], [$full['width'], $full['height']]);

        foreach ([[-0.1, 0.0, 1.0, 1.0], [0.0, -0.1, 1.0, 1.0], [0.0, 0.0, 1.1, 1.0], [0.0, 0.0, 1.0, 1.1]] as $case) {
            $e = $this->caught(fn() => crop_material_file::execute('a.png', 'x.png', ...$case));
            $this->assertInstanceOf(\moodle_exception::class, $e, json_encode($case));
            $this->assertSame('materialcropinvalidcoordinates', $e->errorcode);
        }
        foreach ([[0.5, 0.0, 0.5, 1.0], [0.0, 0.5, 1.0, 0.5], [0.6, 0.0, 0.4, 1.0], [0.0, 0.6, 1.0, 0.4]] as $case) {
            $e = $this->caught(fn() => crop_material_file::execute('a.png', 'x.png', ...$case));
            $this->assertInstanceOf(\moodle_exception::class, $e, json_encode($case));
            $this->assertSame('materialcropinvalidcoordinates', $e->errorcode);
        }
    }

    public function test_the_invalid_coordinates_message_names_all_four_values(): void {
        $this->setup_user();
        $this->store_pattern('a.png', 5, 5);

        $e = $this->caught(fn() => crop_material_file::execute('a.png', 'x.png', 0.2, 0.3, 0.1, 0.9));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertEquals((object) ['x0' => 0.2, 'y0' => 0.3, 'x1' => 0.1, 'y1' => 0.9], $e->a);
        $this->assertSame(
            get_string('materialcropinvalidcoordinates', 'local_coursepilot', $e->a),
            $e->getMessage()
        );
    }

    /**
     * Provides crops on a 10x10 pattern with the width and first column expected for round-half-up pixel edges.
     *
     * @return array<string, array{float, float, int, int}>
     */
    public static function edge_roundings(): array {
        return [
            'both edges round down' => [0.24, 0.74, 2, 5],
            'both edges round up' => [0.26, 0.76, 3, 5],
            'left rounds up, right down' => [0.26, 0.74, 3, 4],
            'left down, right up' => [0.24, 0.76, 2, 6],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('edge_roundings')]
    public function test_pixel_edges_are_rounded_to_the_nearest_pixel_on_both_axes(
        float $low,
        float $high,
        int $firstpixel,
        int $size
    ): void {
        $this->setup_user();
        $this->store_pattern('a.png', 10, 10);

        $result = crop_material_file::execute('a.png', 'b.png', $low, $low, $high, $high);

        $this->assertSame([$size, $size], [$result['width'], $result['height']]);
        $image = $this->load('b.png');
        $this->assertSame([$firstpixel * 20, $firstpixel * 20, 100, 0], $this->pixel($image, 0, 0));
    }

    public function test_a_rectangle_that_rounds_to_nothing_keeps_one_pixel_not_zero_and_not_two(): void {
        $this->setup_user();
        $this->store_pattern('a.png', 10, 10);

        $result = crop_material_file::execute('a.png', 'b.png', 0.499, 0.499, 0.501, 0.501);

        $this->assertSame([1, 1], [$result['width'], $result['height']]);
        $this->assertSame([100, 100, 100, 0], $this->pixel($this->load('b.png'), 0, 0));
    }

    public function test_every_pixel_lands_at_the_same_position_in_the_crop(): void {
        $this->setup_user();
        $this->store_pattern('a.png', 10, 10);

        crop_material_file::execute('a.png', 'b.png', 0.2, 0.3, 0.6, 0.8);

        $image = $this->load('b.png');
        $this->assertSame([imagesx($image), imagesy($image)], [4, 5]);
        for ($x = 0; $x < 4; $x++) {
            for ($y = 0; $y < 5; $y++) {
                $this->assertSame([(2 + $x) * 20, (3 + $y) * 20, 100, 0], $this->pixel($image, $x, $y));
            }
        }
    }

    public function test_transparency_is_kept_for_png_and_whitened_for_jpeg(): void {
        $this->setup_user();
        $this->store_transparent('t.png', 4, 4);

        crop_material_file::execute('t.png', 'png-out.png', 0.0, 0.0, 1.0, 1.0);
        crop_material_file::execute('t.png', 'jpg-out.jpg', 0.0, 0.0, 1.0, 1.0);
        crop_material_file::execute('t.png', 'jpeg-out.jpeg', 0.0, 0.0, 1.0, 1.0);

        $this->assertSame([0, 0, 0, 127], $this->pixel($this->load('png-out.png'), 1, 1));
        $this->assertSame([255, 255, 255, 0], $this->pixel($this->load('jpg-out.jpg'), 1, 1));
        $this->assertSame([255, 255, 255, 0], $this->pixel($this->load('jpeg-out.jpeg'), 1, 1));
    }

    public function test_a_one_pixel_wide_or_high_jpeg_still_gets_its_white_background(): void {
        $this->setup_user();
        $this->store_transparent('t.png', 6, 6);

        crop_material_file::execute('t.png', 'tall.jpg', 0.0, 0.0, 0.17, 1.0);
        crop_material_file::execute('t.png', 'flat.jpg', 0.0, 0.0, 1.0, 0.17);

        $tall = $this->load('tall.jpg');
        $flat = $this->load('flat.jpg');
        $this->assertSame([1, 6], [imagesx($tall), imagesy($tall)]);
        $this->assertSame([6, 1], [imagesx($flat), imagesy($flat)]);
        foreach ([[0, 0], [0, 5]] as [$x, $y]) {
            $this->assertSame([255, 255, 255, 0], $this->pixel($tall, $x, $y));
        }
        foreach ([[0, 0], [5, 0]] as [$x, $y]) {
            $this->assertSame([255, 255, 255, 0], $this->pixel($flat, $x, $y));
        }
    }

    public function test_the_colour_of_a_fully_transparent_pixel_survives_the_crop(): void {
        $this->setup_user();
        $image = imagecreatetruecolor(2, 2);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 200, 40, 10, 127));
        $this->store('ghost.png', $this->encode($image, 'png'));

        crop_material_file::execute('ghost.png', 'out.png', 0.0, 0.0, 1.0, 1.0);

        $this->assertSame([200, 40, 10, 127], $this->pixel($this->load('out.png'), 1, 1));
    }

    public function test_a_translucent_pixel_is_copied_unblended_into_a_png(): void {
        $this->setup_user();
        $image = imagecreatetruecolor(2, 2);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 200, 40, 10, 64));
        $this->store('half.png', $this->encode($image, 'png'));

        crop_material_file::execute('half.png', 'out.png', 0.0, 0.0, 1.0, 1.0);

        $this->assertSame([200, 40, 10, 64], $this->pixel($this->load('out.png'), 1, 1));
    }

    public function test_gif_targets_are_written_as_gif(): void {
        $this->setup_user();
        $this->store_pattern('a.png', 4, 4);

        crop_material_file::execute('a.png', 'out.gif', 0.0, 0.0, 0.5, 0.5);

        $this->assertSame(IMAGETYPE_GIF, getimagesizefromstring($this->load_content('out.gif'))[2]);
    }

    public function test_the_response_matches_its_declared_structure(): void {
        $this->setup_user();
        $this->store_pattern('a.png', 4, 4);

        $result = crop_material_file::execute('a.png', 'out.png', 0.0, 0.0, 0.5, 0.5);

        $this->assertSame($result, external_api::clean_returnvalue(crop_material_file::execute_returns(), $result));
    }
}
