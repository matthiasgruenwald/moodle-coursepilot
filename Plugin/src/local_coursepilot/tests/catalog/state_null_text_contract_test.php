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
namespace local_coursepilot\catalog;

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Activities whose nullable text columns are NULL are read as empty text (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(page::class)]
#[CoversClass(url::class)]
final class state_null_text_contract_test extends \advanced_testcase {
    use caught_throwable;

    public function test_a_page_with_null_content_and_intro_reads_as_empty_text(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Seite']);
        $DB->set_field('page', 'content', null, ['id' => $page->id]);
        $DB->set_field('page', 'intro', null, ['id' => $page->id]);

        $details = $this->succeeded(fn() => page::state((int) $page->id, (int) $page->cmid, true));

        $this->assertSame('Seite', $details['name']);
        $this->assertSame('', $details['content']['html']);
        $settings = array_column($details['settings'], 'value', 'name');
        $this->assertSame('', $settings['intro']);
    }

    public function test_a_url_with_a_null_intro_reads_as_empty_text(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $url = $this->getDataGenerator()->create_module('url', ['course' => $course->id, 'externalurl' => 'https://example.org/x']);
        $DB->set_field('url', 'intro', null, ['id' => $url->id]);

        $details = $this->succeeded(fn() => url::state((int) $url->id, (int) $url->cmid, true));

        $settings = array_column($details['settings'], 'value', 'name');
        $this->assertSame('https://example.org/x', $settings['externalurl']);
        $this->assertSame('', $details['content']['html']);
    }
}
