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

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Table of contents of long context files (#782, Spec 0030).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(context_toc::class)]
final class context_toc_test extends \basic_testcase {
    /**
     * Builds the lines "Line n", with some replaced.
     *
     * @param int $count
     * @param string[] $replace Replacement text by 1-based line number.
     * @return string[]
     */
    private static function lines_of(int $count, array $replace): array {
        $lines = [];
        for ($n = 1; $n <= $count; $n++) {
            $lines[] = $replace[$n] ?? "Line $n";
        }
        return $lines;
    }

    public function test_sections_nest_level_three_in_level_two(): void {
        $lines = self::lines_of(101, [1 => '# Plan', 3 => '## Week 1', 11 => '### Monday', 21 => '## Week 2']);
        $this->assertSame([
            ['level' => 2, 'heading' => 'Week 1', 'start_line' => 3, 'end_line' => 20],
            ['level' => 3, 'heading' => 'Monday', 'start_line' => 11, 'end_line' => 20],
            ['level' => 2, 'heading' => 'Week 2', 'start_line' => 21, 'end_line' => 101],
        ], context_toc::sections($lines));
    }

    public function test_headings_in_code_fences_do_not_count(): void {
        $fence = "\x60\x60\x60";
        $lines = self::lines_of(40, [
            3 => '## Real', 10 => $fence, 11 => '## Fake', 12 => $fence,
            20 => '~~~', 21 => '## Fake2', 22 => $fence, 23 => '## Still fake', 24 => '~~~',
            30 => $fence . "\x60", 31 => $fence, 32 => '## Fake3', 33 => $fence . "\x60",
            35 => '## Last',
        ]);
        $this->assertSame(['Real', 'Last'], array_column(context_toc::sections($lines), 'heading'));
    }

    public function test_only_levels_two_and_three_with_a_blank_count(): void {
        $lines = ['# One', '#### Four', '##NoBlank', '## ', '## Yes  '];
        $this->assertSame([['level' => 2, 'heading' => 'Yes', 'start_line' => 5, 'end_line' => 5]], context_toc::sections($lines));
    }

    public function test_lines_split_like_a_line_reader(): void {
        $this->assertSame(['a', 'b', 'c'], context_toc::lines("a\r\nb\rc\n"));
        $this->assertSame([], context_toc::lines(''));
    }

    public function test_find_ignores_case_blanks_and_marker(): void {
        $sections = context_toc::sections(['## Week  2: Depth', 'x', '### Week 2: depth']);
        $this->assertCount(2, context_toc::find($sections, '  week 2: DEPTH '));
        $this->assertCount(2, context_toc::find($sections, '## Week 2: Depth'));
        $this->assertSame([], context_toc::find($sections, 'Week 3'));
    }

    public function test_slice_returns_the_inclusive_range(): void {
        $this->assertSame("b\nc", context_toc::slice(['a', 'b', 'c', 'd'], 2, 3));
    }
}
