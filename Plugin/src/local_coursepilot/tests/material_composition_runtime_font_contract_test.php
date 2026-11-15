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
namespace local_coursepilot;

use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\isolated_php_ini;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A runtime without the FreeType text function refuses compositions with their own error (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(material_composition::class)]
#[RunTestsInSeparateProcesses]
final class material_composition_runtime_font_contract_test extends \advanced_testcase {
    use caught_throwable;
    use isolated_php_ini;

    /**
     * Disables only the text function of the child interpreters; the GD extension stays loaded.
     *
     * @return string
     */
    protected static function ini_settings(): string {
        return "disable_functions=imagettftext\n";
    }

    public function test_without_the_freetype_text_function_a_composition_is_refused_with_the_font_missing_error(): void {
        $this->assertFalse(function_exists('imagettftext'), 'The isolated interpreter must lack the FreeType text function.');
        $input = ['sourcepath' => 'a.png', 'content' => 'x', 'source_header_text' => ''];

        $e = $this->caught(fn() => material_composition::render([$input], 'horizontal'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialcompositionfontmissing', $e->errorcode);
    }
}
