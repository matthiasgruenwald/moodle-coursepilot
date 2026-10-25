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

use local_coursepilot\external\export_default_activity;
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact results, messages, side effects and comparison rules of xml_activity_creator (Spec 0026, #590).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(xml_activity_creator::class)]
final class xml_activity_creator_contract_test extends \advanced_testcase {
    use caught_throwable;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var string Book activity XML named "Created". */
    private string $xml;

    /**
     * Course with an editing teacher and a book activity XML.
     */
    private function setup_course(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        $xml = export_default_activity::execute($this->course->id, 'book')['xml'];
        $this->xml = preg_replace('#<name>.*?</name>#s', '<name>Created</name>', $xml, 1);
    }

    /**
     * Separate connection for triggers that inject failures.
     *
     * @return \mysqli|null Null when the database is not MariaDB/MySQL.
     */
    private function ddl(): ?\mysqli {
        global $DB;
        if ($DB->get_dbfamily() !== 'mysql') {
            return null;
        }
        $cfg = $DB->export_dbconfig();
        $options = (array) ($cfg->dboptions ?? []);
        return new \mysqli(
            $cfg->dbhost,
            $cfg->dbuser,
            $cfg->dbpass,
            $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null
        );
    }

    public function test_a_plain_creation_returns_exactly_its_documented_shape(): void {
        $this->setup_course();

        $result = xml_activity_creator::create((int) $this->course->id, 'book', 2, $this->xml);

        $this->assertSame(['cmid', 'presets', 'references', 'successor_cmid', 'hidden_predecessors'], array_keys($result));
        $this->assertSame([], $result['references']);
        $this->assertSame(0, $result['successor_cmid']);
        $this->assertSame(0, $result['hidden_predecessors']);
        $this->assertSame(array_values($result['presets']), $result['presets']);
        $this->assertSame(array_values(array_unique($result['presets'])), $result['presets']);
    }

    public function test_the_mismatch_exception_names_path_shortened_texts_and_count(): void {
        global $DB;
        $this->setup_course();
        $long = str_repeat('0123456789', 10);
        $broken = str_replace('</book>', "<bogusfield>$long</bogusfield><other>y</other></book>", $this->xml);

        $e = $this->caught(fn() => xml_activity_creator::create((int) $this->course->id, 'book', 1, $broken));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('xmlroundtripmismatch', $e->errorcode);
        $this->assertSame(
            ['path', 'expected', 'actual', 'count'],
            array_keys($e->a)
        );
        $this->assertSame('activity/book/bogusfield', $e->a['path']);
        $this->assertSame(shorten_text($long, 80), $e->a['expected']);
        $this->assertSame(80, mb_strlen($e->a['expected']));
        $this->assertSame('(missing)', $e->a['actual']);
        $this->assertSame(2, $e->a['count']);
        $this->assertSame(0, $DB->count_records('local_coursepilot_cm_version'));
    }

    public function test_a_long_actual_value_is_shortened_too(): void {
        global $DB;
        $this->setup_course();
        $ddl = $this->ddl();
        if ($ddl === null) {
            $this->markTestSkipped('MariaDB/MySQL trigger fixture required.');
        }
        $key = 'cplong' . bin2hex(random_bytes(8));
        $ddl->query('CREATE TRIGGER ' . $key . ' BEFORE INSERT ON ' . $DB->get_prefix() . 'book FOR EACH ROW BEGIN
            IF NEW.course = ' . (int) $this->course->id . " THEN SET NEW.name = REPEAT('b', 120); END IF;
        END");
        try {
            $xml = preg_replace('#<name>.*?</name>#s', '<name>' . str_repeat('a', 120) . '</name>', $this->xml, 1);
            $e = $this->caught(fn() => xml_activity_creator::create((int) $this->course->id, 'book', 1, $xml));
        } finally {
            $ddl->query("DROP TRIGGER IF EXISTS $key");
            $ddl->close();
        }

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('xmlroundtripmismatch', $e->errorcode);
        $this->assertSame(shorten_text(str_repeat('a', 120), 80), $e->a['expected']);
        $this->assertSame(shorten_text(str_repeat('b', 120), 80), $e->a['actual']);
        $this->assertSame(1, $e->a['count']);
    }

    public function test_files_for_an_activity_type_without_file_support_are_refused_before_anything_is_restored(): void {
        global $DB;
        $this->setup_course();
        $before = $DB->count_records('course_modules');

        $e = $this->caught(fn() => xml_activity_creator::create(
            (int) $this->course->id,
            'book',
            1,
            $this->xml,
            false,
            null,
            [['path' => 'a.png', 'filearea' => 'content']]
        ));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('activityfilesunsupported', $e->errorcode);
        $this->assertSame($before, $DB->count_records('course_modules'));
    }

    public function test_the_libxml_error_state_is_restored_after_every_validation(): void {
        $this->setup_course();

        foreach ([false, true] as $initial) {
            libxml_use_internal_errors($initial);
            libxml_clear_errors();
            foreach (['<activity', '<foo/>', $this->xml] as $xml) {
                $this->caught(fn() => xml_activity_creator::preview_supersede((int) $this->course->id, 'book', $xml, 1));
                $this->assertSame([], libxml_get_errors());
                $this->assertSame($initial, libxml_use_internal_errors($initial), 'the error handling mode must be restored');
            }
        }
        libxml_use_internal_errors(false);
    }

    public function test_a_validation_that_throws_still_restores_the_libxml_state(): void {
        $this->setup_course();
        libxml_use_internal_errors(false);

        $e = $this->caught(fn() => xml_activity_creator::preview_supersede((int) $this->course->id, 'book', '', 1));

        $this->assertInstanceOf(\ValueError::class, $e);
        $this->assertFalse(libxml_use_internal_errors(false));
        $this->assertSame([], libxml_get_errors());
    }

    public function test_the_preview_checks_kind_xml_and_target_in_this_order_and_writes_nothing(): void {
        global $DB;
        $this->setup_course();
        $old = xml_activity_creator::create((int) $this->course->id, 'book', 1, $this->xml)['cmid'];
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id])->cmid;
        $before = [$DB->count_records('course_modules'), $DB->count_records('local_coursepilot_cm_version')];
        $pagexml = '<activity id="1" moduleid="1" modulename="page" contextid="1"><page id="1"><name>x</name></page></activity>';

        $kind = $this->caught(fn() => xml_activity_creator::preview_supersede((int) $this->course->id, 'page', $pagexml, $page));
        $xml = $this->caught(fn() => xml_activity_creator::preview_supersede((int) $this->course->id, 'book', '<foo/>', $old));
        $target = $this->caught(
            fn() => xml_activity_creator::preview_supersede((int) $this->course->id, 'book', $this->xml, 987654)
        );

        $this->assertInstanceOf(\moodle_exception::class, $kind);
        $this->assertSame('createfromxmlcatalogued', $kind->errorcode);
        $this->assertInstanceOf(\invalid_parameter_exception::class, $xml);
        $this->assertSame(
            'activity_xml is not a valid activity XML of type "book".',
            $xml->getMessage() === '' ? '' : $xml->debuginfo
        );
        $this->assertInstanceOf(\invalid_parameter_exception::class, $target);
        $this->assertStringContainsString(
            'replaces_cmid 987654 is not an activity of type "book" in this course.',
            $target->debuginfo
        );
        $this->assertSame($before, [$DB->count_records('course_modules'), $DB->count_records('local_coursepilot_cm_version')]);
    }

    public function test_the_preview_lists_what_points_at_the_old_activity(): void {
        global $DB;
        $this->setup_course();
        $old = xml_activity_creator::create((int) $this->course->id, 'book', 1, $this->xml)['cmid'];
        $next = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id])->cmid;
        $availability = json_encode(['op' => '&', 'c' => [['type' => 'completion', 'cm' => $old, 'e' => 1]], 'showc' => [true]]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $next]);
        rebuild_course_cache($this->course->id, true);

        $preview = xml_activity_creator::preview_supersede((int) $this->course->id, 'book', $this->xml, $old);

        $this->assertSame(['references', 'successor_cmid', 'hidden_predecessors'], array_keys($preview));
        $this->assertSame([[
            'kind' => cm_references::KIND_ACTIVITY_AVAILABILITY,
            'location_id' => $next,
            'location' => 'cmid ' . $next,
        ]], $preview['references']);
        $this->assertSame(0, $preview['successor_cmid']);
        $this->assertSame(1, $preview['hidden_predecessors']);
    }

    public function test_the_lightboxgallery_rule_applies_only_to_that_type(): void {
        $this->setup_course();
        $bookwith = '<activity id="1" moduleid="1" modulename="book" contextid="1"><book id="1"/>'
            . '<lightboxgallery><timemodified>1</timemodified></lightboxgallery></activity>';

        $e = $this->caught(fn() => xml_activity_creator::preview_supersede((int) $this->course->id, 'book', $bookwith, 987654));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertStringContainsString('replaces_cmid 987654 is not an activity', $e->debuginfo);
    }

    public function test_a_visible_predecessor_is_not_counted_as_hidden(): void {
        $this->setup_course();
        $a = xml_activity_creator::create((int) $this->course->id, 'book', 2, $this->xml)['cmid'];
        $b = xml_activity_creator::create((int) $this->course->id, 'book', 2, $this->xml, false, $a)['cmid'];
        course_module_placement::set_visible($a, true);

        $preview = xml_activity_creator::preview_supersede((int) $this->course->id, 'book', $this->xml, $b);

        $this->assertSame(1, $preview['hidden_predecessors']);
        $this->assertSame(
            $b,
            xml_activity_creator::preview_supersede((int) $this->course->id, 'book', $this->xml, $a)['successor_cmid']
        );
    }

    public function test_the_cleanup_failure_names_cause_and_each_failed_step_in_order(): void {
        global $DB;
        $this->setup_course();
        $ddl = $this->ddl();
        if ($ddl === null) {
            $this->markTestSkipped('MariaDB/MySQL trigger fixture required.');
        }
        $old = xml_activity_creator::create((int) $this->course->id, 'book', 2, $this->xml)['cmid'];
        $key = 'cpboth' . bin2hex(random_bytes(8));
        try {
            $ddl->query('CREATE TRIGGER ' . $key . 'del BEFORE DELETE ON ' . $DB->get_prefix() . 'book FOR EACH ROW BEGIN
                IF OLD.course = ' . (int) $this->course->id . " THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic deletion failure';
                END IF;
            END");
            $ddl->query('CREATE TRIGGER ' . $key . 'vis BEFORE UPDATE ON ' . $DB->get_prefix() . 'course_modules FOR EACH ROW BEGIN
                IF OLD.id = ' . (int) $old . " AND OLD.visible = 0 AND NEW.visible = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic visibility failure';
                END IF;
            END");
            $versiontable = $DB->get_prefix() . 'local_coursepilot_cm_version';
            $ddl->query('CREATE TRIGGER ' . $key . 'his BEFORE INSERT ON ' . $versiontable . ' FOR EACH ROW BEGIN
                IF NEW.cmid = ' . (int) $old . " AND NEW.source = 'superseded' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic supersede failure';
                END IF;
            END");
            $e = $this->caught(fn() => xml_activity_creator::create((int) $this->course->id, 'book', 2, $this->xml, false, $old));
        } finally {
            $ddl->query("DROP TRIGGER IF EXISTS {$key}his");
            $ddl->query("DROP TRIGGER IF EXISTS {$key}del");
            $ddl->query("DROP TRIGGER IF EXISTS {$key}vis");
            $ddl->close();
        }

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('xmlactivitycleanupincomplete', $e->errorcode);
        $this->assertMatchesRegularExpression(
            '/^Creation failed: .*Synthetic supersede failure.*; Predecessor visibility: .*Synthetic visibility failure.*'
                . '; New activity deletion: .*Synthetic deletion failure/s',
            $e->debuginfo
        );
        $this->assertSame($old, (int) $e->a->predecessor);
        $this->assertSame(['cmid', 'predecessor'], array_keys((array) $e->a));
    }

    public function test_the_cleanup_failure_without_a_predecessor_reports_zero(): void {
        global $DB;
        $this->setup_course();
        $ddl = $this->ddl();
        if ($ddl === null) {
            $this->markTestSkipped('MariaDB/MySQL trigger fixture required.');
        }
        $key = 'cpdel' . bin2hex(random_bytes(8));
        try {
            $ddl->query('CREATE TRIGGER ' . $key . ' BEFORE DELETE ON ' . $DB->get_prefix() . 'book FOR EACH ROW BEGIN
                IF OLD.course = ' . (int) $this->course->id . " THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic deletion failure';
                END IF;
            END");
            $broken = str_replace('</book>', '<bogusfield>x</bogusfield></book>', $this->xml);
            $e = $this->caught(fn() => xml_activity_creator::create((int) $this->course->id, 'book', 1, $broken));
        } finally {
            $ddl->query("DROP TRIGGER IF EXISTS $key");
            $ddl->close();
        }

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame(0, $e->a->predecessor);
        $this->assertMatchesRegularExpression(
            '/^Creation failed: [^;]*bogusfield[^;]*; New activity deletion: .*Synthetic deletion failure/s',
            $e->debuginfo
        );
        $this->assertStringNotContainsString('Predecessor visibility', $e->debuginfo);
    }

    public function test_the_comparison_skips_ignored_elements_and_continues_with_the_following_ones(): void {
        $in = '<activity modulename="book"><book><timecreated>1</timecreated><name>A</name></book></activity>';
        $out = '<activity modulename="book"><book><timecreated>9</timecreated><name>B</name></book></activity>';

        $result = xml_activity_creator::compare($in, $out);

        $this->assertSame([['path' => 'activity/book/name', 'expected' => 'A', 'actual' => 'B']], $result['mismatches']);
    }

    public function test_numeric_equality_needs_two_numbers(): void {
        $in = '<activity modulename="book"><book><a>0</a><b>x</b><c>1</c><d>1.0</d></book></activity>';
        $out = '<activity modulename="book"><book><a>x</a><b>0</b><c>1.00</c><d>1</d></book></activity>';

        $result = xml_activity_creator::compare($in, $out);

        $this->assertSame(
            [
                ['path' => 'activity/book/a', 'expected' => '0', 'actual' => 'x'],
                ['path' => 'activity/book/b', 'expected' => 'x', 'actual' => '0'],
            ],
            $result['mismatches']
        );
    }

    public function test_an_id_element_is_ignored_even_when_its_text_is_no_number(): void {
        $in = '<activity modulename="book"><book><id>abc</id><userid>zz</userid><cmid> 5 </cmid></book></activity>';
        $out = '<activity modulename="book"><book><id>def</id><userid>yy</userid><cmid>7</cmid></book></activity>';

        $result = xml_activity_creator::compare($in, $out);

        $this->assertSame([['path' => 'activity/book/userid', 'expected' => 'zz', 'actual' => 'yy']], $result['mismatches']);
    }

    public function test_surrounding_whitespace_and_line_ending_style_do_not_matter(): void {
        $in = "<activity modulename=\"book\"><book><a>  x\n</a><b>p&#13;&#10;q</b></book></activity>";
        $out = "<activity modulename=\"book\"><book><a>x</a><b>p\nq</b></book></activity>";

        $this->assertSame([], xml_activity_creator::compare($in, $out)['mismatches']);
    }

    public function test_repeated_new_elements_are_reported_once_as_a_plain_list(): void {
        $in = '<activity modulename="book"><book><name>A</name></book></activity>';
        $out = '<activity modulename="book"><book><name>A</name><extra>1</extra><extra>2</extra><more>3</more></book></activity>';

        $result = xml_activity_creator::compare($in, $out);

        $this->assertSame(['activity/book/extra', 'activity/book/more'], $result['presets']);
    }
}
