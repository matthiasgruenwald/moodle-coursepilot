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
 * Round-trip comparison of xml_activity_creator (pure, no database; Spec 0026, #590).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(xml_activity_creator::class)]
final class xml_activity_creator_compare_test extends \basic_testcase {

    private function xml(string $inner, string $attrs = 'id="1" moduleid="5" modulename="book" contextid="9"'): string {
        return "<activity $attrs><book id=\"1\">$inner</book></activity>";
    }

    public function test_identical_xml_has_no_findings(): void {
        $xml = $this->xml('<name>A</name><chapters><chapter id="1"><title>T</title></chapter></chapters>');
        $this->assertSame(['mismatches' => [], 'presets' => []], xml_activity_creator::compare($xml, $xml));
    }

    public function test_output_may_be_superset_and_reports_presets(): void {
        $in = $this->xml('<name>A</name>');
        $out = $this->xml('<name>A</name><numbering>1</numbering><navstyle>1</navstyle>');
        $result = xml_activity_creator::compare($in, $out);
        $this->assertSame([], $result['mismatches']);
        $this->assertSame(['activity/book/numbering', 'activity/book/navstyle'], $result['presets']);
    }

    public function test_changed_and_missing_values_are_mismatches(): void {
        $in = $this->xml('<name>A</name><numbering>2</numbering><custom>x</custom>');
        $out = $this->xml('<name>B</name><numbering>2</numbering>');
        $result = xml_activity_creator::compare($in, $out);
        $this->assertSame(
            [
                ['path' => 'activity/book/name', 'expected' => 'A', 'actual' => 'B'],
                ['path' => 'activity/book/custom', 'expected' => 'x', 'actual' => '(missing)'],
            ],
            $result['mismatches']
        );
    }

    public function test_ids_times_contextid_and_file_references_are_ignored(): void {
        $in = $this->xml('<name>A</name><bookid>3</bookid><timecreated>1</timecreated><timemodified>2</timemodified>'
            . '<contextid>4</contextid><intro>&lt;img src="@@PLUGINFILE@@/a.png"&gt;</intro>', 'id="1" moduleid="5" modulename="book" contextid="9"');
        $out = '<activity id="77" moduleid="6" modulename="book" contextid="10"><book id="2"><name>A</name>'
            . '<bookid>8</bookid><timecreated>100</timecreated><timemodified>200</timemodified><contextid>11</contextid>'
            . '<intro>other</intro></book></activity>';
        $this->assertSame([], xml_activity_creator::compare($in, $out)['mismatches']);
    }

    public function test_repeated_elements_match_by_position(): void {
        $in = $this->xml('<chapters><chapter id="1"><title>A</title></chapter><chapter id="2"><title>B</title></chapter></chapters>');
        $out = $this->xml('<chapters><chapter id="5"><title>A</title></chapter><chapter id="6"><title>X</title></chapter></chapters>');
        $result = xml_activity_creator::compare($in, $out);
        $this->assertSame('activity/book/chapters/chapter/title', $result['mismatches'][0]['path']);
        $this->assertSame('X', $result['mismatches'][0]['actual']);
    }

    public function test_numbers_and_null_marker_compare_by_value(): void {
        $in = $this->xml('<grade>0</grade><idnumber>$@NULL@$</idnumber><rawtext>a&#13;&#10;b</rawtext>');
        $out = $this->xml('<grade>0.00000</grade><idnumber></idnumber><rawtext>a' . "\n" . 'b</rawtext>');
        $this->assertSame([], xml_activity_creator::compare($in, $out)['mismatches']);
    }
}
