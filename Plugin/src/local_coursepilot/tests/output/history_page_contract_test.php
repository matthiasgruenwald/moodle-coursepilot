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


namespace local_coursepilot\output;

use local_coursepilot\history\version_history;
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact template data, URLs, page setup and permission rules of the history page (#552, Spec 0023 part 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(history_page::class)]
final class history_page_contract_test extends \advanced_testcase {
    use caught_throwable;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Course module of the page with two versions. */
    private \stdClass $cm;

    /**
     * Course with an editing teacher and a page that has two history versions.
     */
    private function setup_page(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'History course']);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        /** @var \mod_page_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_page');
        $page = $generator->create_instance([
            'course' => $this->course->id,
            'name' => 'First <b>edition</b>',
        ]);
        $this->cm = get_coursemodule_from_instance('page', $page->id, $this->course->id, false, MUST_EXIST);
        \local_coursepilot\external\update_module_settings::execute((int) $this->cm->id, '{"name":"Second edition"}');
        // The writes above touch the global page; the page under test starts clean.
        $PAGE = new \moodle_page();
    }

    public function test_the_version_rows_are_exact_and_only_older_versions_offer_restore(): void {
        $this->setup_page();
        $listurl = new \moodle_url('/local/coursepilot/history.php', ['id' => $this->course->id, 'x' => 'a&b']);
        $source = version_history::list_versions((int) $this->cm->id, current_language());

        $data = history_page::versions_data((int) $this->cm->id, 'Name <i>x</i>', true, $listurl);

        $this->assertSame(['activityname', 'isquiz', 'rows', 'gap_notice', 'listurl'], array_keys($data));
        $this->assertSame(format_string('Name <i>x</i>'), $data['activityname']);
        $this->assertFalse($data['isquiz']);
        $this->assertSame($source['gap_notice'], $data['gap_notice']);
        $this->assertSame($listurl->out(false), $data['listurl']);
        $this->assertStringContainsString('&x=', $data['listurl']);
        $this->assertCount(2, $data['rows']);
        foreach ($data['rows'] as $i => $row) {
            $this->assertSame(
                ['version', 'user', 'time', 'summary_line', 'canrestore', 'restoreurl'],
                array_keys($row)
            );
            $this->assertSame($source['versions'][$i]['version'], $row['version']);
            $this->assertSame($source['versions'][$i]['user'], $row['user']);
            $this->assertSame(userdate($source['versions'][$i]['timestamp']), $row['time']);
            $this->assertSame($source['versions'][$i]['summary_line'], $row['summary_line']);
        }
        $older = $data['rows'][0];
        $this->assertTrue($older['canrestore']);
        $this->assertSame(
            (new \moodle_url('/local/coursepilot/history.php', ['cmid' => $this->cm->id, 'restore' => $older['version']]))->out(
                false
            ),
            $older['restoreurl']
        );
        $this->assertStringContainsString('&restore=', $older['restoreurl']);
        $this->assertFalse($data['rows'][1]['canrestore']);
        $this->assertNull($data['rows'][1]['restoreurl']);
    }

    public function test_a_quiz_is_flagged_for_its_gap_notice(): void {
        $this->setup_page();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);

        $data = history_page::versions_data(
            (int) $quiz->cmid,
            'Quiz',
            false,
            new \moodle_url('/local/coursepilot/history.php')
        );

        $this->assertTrue($data['isquiz']);
    }

    public function test_the_activity_list_links_to_each_history_with_an_unescaped_url(): void {
        $this->setup_page();

        $data = history_page::activities_data((int) $this->course->id);

        $this->assertSame(['empty', 'rows'], array_keys($data));
        $this->assertFalse($data['empty']);
        $this->assertSame(['name', 'modname', 'viewurl'], array_keys($data['rows'][0]));
        $this->assertSame(
            (new \moodle_url('/local/coursepilot/history.php', ['cmid' => $this->cm->id]))->out(false),
            $data['rows'][0]['viewurl']
        );
    }

    public function test_the_module_view_sets_up_the_page_and_offers_restore_only_with_both_rights(): void {
        global $PAGE, $DB;
        $this->setup_page();

        $view = history_page::response((int) $this->cm->id, 0, 0, false);

        $this->assertSame('local_coursepilot/history_versions', $view->template);
        $this->assertSame(\context_module::instance($this->cm->id)->id, $PAGE->context->id);
        $this->assertStringEndsWith('/local/coursepilot/history.php', $PAGE->url->get_path());
        $this->assertSame((string) $this->cm->id, $PAGE->url->get_param('cmid'));
        $this->assertNull($PAGE->url->get_param('id'));
        $this->assertSame('incourse', $PAGE->pagelayout);
        $this->assertStringContainsString(get_string('historytitle', 'local_coursepilot'), $PAGE->title);
        $this->assertSame('History course', $PAGE->heading);
        $this->assertSame(
            (new \moodle_url('/local/coursepilot/history.php', ['id' => $this->course->id]))->out(false),
            $view->data['listurl']
        );
        $this->assertTrue($view->data['rows'][0]['canrestore']);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['local/coursepilot:restoreversion', 'moodle/course:manageactivities'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $limited = history_page::response((int) $this->cm->id, 0, 0, false);
            $this->assertFalse($limited->data['rows'][0]['canrestore'], $capability);
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }

    public function test_the_course_view_sets_up_the_page_for_the_course(): void {
        global $PAGE;
        $this->setup_page();

        $view = history_page::response(0, (int) $this->course->id, 0, false);

        $this->assertSame('local_coursepilot/history_activities', $view->template);
        $this->assertSame(\context_course::instance($this->course->id)->id, $PAGE->context->id);
        $this->assertSame((string) $this->course->id, $PAGE->url->get_param('id'));
        $this->assertNull($PAGE->url->get_param('cmid'));
        $this->assertSame('incourse', $PAGE->pagelayout);
        $this->assertSame('History course', $PAGE->heading);
    }

    public function test_the_page_needs_a_course_login(): void {
        $this->setup_page();
        $this->setUser($this->getDataGenerator()->create_user());

        $e = $this->caught(fn() => history_page::response((int) $this->cm->id, 0, 0, false));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('redirecterrordetected', $e->errorcode);
    }

    public function test_the_restore_question_carries_the_whole_confirmation_and_the_way_back(): void {
        $this->setup_page();

        $response = history_page::response((int) $this->cm->id, 0, 1, false);

        $this->assertSame(get_string('historyrestoreconfirm', 'local_coursepilot', 1), $response->message);
        $this->assertSame(
            (new \moodle_url('/local/coursepilot/history.php', [
                'cmid' => $this->cm->id,
                'restore' => 1,
                'confirmed' => 1,
                'sesskey' => sesskey(),
            ]))->out(false),
            $response->confirmurl->out(false)
        );
        $this->assertSame(
            (new \moodle_url('/local/coursepilot/history.php', ['cmid' => $this->cm->id]))->out(false),
            $response->cancelurl->out(false)
        );
        $this->assertNull($response->redirecturl);
    }

    public function test_a_restore_redirects_to_the_activity_history_with_the_result_message(): void {
        $this->setup_page();
        $_POST['sesskey'] = sesskey();
        try {
            $response = history_page::response((int) $this->cm->id, 0, 1, true);
        } finally {
            unset($_POST['sesskey']);
        }

        $this->assertSame(
            (new \moodle_url('/local/coursepilot/history.php', ['cmid' => $this->cm->id]))->out(false),
            $response->redirecturl->out(false)
        );
        $this->assertNotSame('', $response->message);
        $this->assertNull($response->confirmurl);
    }
}
