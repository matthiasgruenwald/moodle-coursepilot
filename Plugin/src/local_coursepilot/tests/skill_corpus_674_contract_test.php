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

/**
 * Front matter reading of the skill corpus stops at the closing delimiter (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(skill_corpus::class)]
final class skill_corpus_674_contract_test extends \advanced_testcase {
    /**
     * Calls the private front matter reader.
     *
     * @param string $content Skill file content.
     * @return string|null
     */
    private function description(string $content): ?string {
        return (new \ReflectionMethod(skill_corpus::class, 'frontmatter_description'))->invoke(null, $content);
    }

    public function test_the_description_is_read_from_the_front_matter(): void {
        $this->assertSame('Real text', $this->description("---\nname: x\ndescription:   Real text  \n---\nBody\n"));
    }

    public function test_a_description_line_in_the_body_is_not_front_matter(): void {
        $this->assertNull($this->description("---\nname: x\n---\ndescription: From the body\n"));
    }

    public function test_without_a_closing_delimiter_there_is_no_front_matter(): void {
        $this->assertNull($this->description("---\nname: x\ndescription: Open\n"));
    }
}
