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
 * Personal-data switch and front matter marker of context files (ADR 0021).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(personal_data::class)]
final class personal_data_test extends \advanced_testcase {
    public function test_delivery_follows_the_admin_setting(): void {
        $this->resetAfterTest();

        set_config('allowpersonaldata', 0, 'local_coursepilot');
        $this->assertFalse(personal_data::allowed());
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $this->assertTrue(personal_data::allowed());
    }

    /**
     * Recognise imported editor variants without extending the accepted flag values.
     *
     * @param string $content File content.
     * @param bool $marked Expected personal-data marker.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('marker_provider')]
    public function test_front_matter_marker(string $content, bool $marked): void {
        $this->assertSame($marked, personal_data::is_marked($content));
    }

    /**
     * Accepted marker syntax and values from specification 0010.
     *
     * @return array<string, array{string, bool}>
     */
    public static function marker_provider(): array {
        return [
            'canonical' => ["---\npersonenbezug: true\n---\nText", true],
            'BOM' => ["\xEF\xBB\xBF---\npersonenbezug: true\n---\nText", true],
            'blank lines' => ["\n \r\n---\npersonenbezug: true\n---\nText", true],
            'end of file' => ["---\npersonenbezug: true\n---", true],
            'space before colon' => ["---\npersonenbezug : true\n---\nText", true],
            'nested CRLF' => ["---\r\ncoursepilot:\r\n  personenbezug: true\r\n---\r\n", true],
            'multiline value' => ["---\npersonenbezug:\n  true\n---\n", true],
            'dotted' => ["---\ncoursepilot.personenbezug:true\n---\n", true],
            'no front matter' => ["personenbezug: true\nText", false],
            'false' => ["---\npersonenbezug: false\n---\nText", false],
            'capitalised' => ["---\npersonenbezug: True\n---\n", false],
            'quoted' => ["---\npersonenbezug: \"true\"\n---\n", false],
            'yes' => ["---\npersonenbezug: yes\n---\n", false],
            'body only' => ["---\ntitle: Plan\n---\npersonenbezug: true", false],
            'intro' => ["Intro\n---\npersonenbezug: true\n---\n", false],
        ];
    }
}
