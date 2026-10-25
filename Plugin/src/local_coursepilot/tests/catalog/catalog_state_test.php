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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * What `state()` of each catalog reports about a real activity, in catalog vocabulary: names,
 * the preview of the intro/content, and the settings as name/value text pairs.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(assign::class)]
#[CoversClass(quiz::class)]
#[CoversClass(label::class)]
#[CoversClass(page::class)]
#[CoversClass(url::class)]
#[CoversClass(resource::class)]
#[CoversClass(folder::class)]
#[CoversClass(choice::class)]
#[CoversClass(forum::class)]
final class catalog_state_test extends \advanced_testcase {
    /**
     * Name/value pairs from an associative array.
     *
     * @param string[] $settings Field name to text value.
     * @return array<int, array{name: string, value: string}>
     */
    private static function pairs(array $settings): array {
        return module_state::settings($settings);
    }

    /**
     * An assignment is reported with its dates, switches, grading and plugin settings as text.
     */
    public function test_assign_state_reports_every_setting_as_text(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'Abgabe 1', 'intro' => '<p>Bitte <b>lesen</b></p>',
            'allowsubmissionsfromdate' => 1700000000, 'duedate' => 1700100000, 'cutoffdate' => 1700200000,
            'gradingduedate' => 1700300000, 'grade' => 80, 'submissiondrafts' => 1, 'requiresubmissionstatement' => 1,
            'maxattempts' => 3, 'attemptreopenmethod' => 'manual', 'teamsubmission' => 1, 'requireallteammemberssubmit' => 1,
            'sendnotifications' => 1, 'sendlatenotifications' => 1, 'sendstudentnotifications' => 0, 'blindmarking' => 1,
            'markingworkflow' => 1, 'markingallocation' => 1,
            'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_onlinetext_wordlimit_enabled' => 1,
            'assignsubmission_onlinetext_wordlimit' => 50, 'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 3, 'assignsubmission_file_maxsizebytes' => 1048576,
            'assignsubmission_file_filetypes' => '.pdf', 'assignfeedback_comments_enabled' => 1,
            'assignfeedback_file_enabled' => 1, 'assignfeedback_editpdf_enabled' => 0, 'assignfeedback_offline_enabled' => 1,
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($module->cmid)->id, 'component' => 'mod_assign',
            'filearea' => 'introattachment', 'itemid' => 0, 'filepath' => '/', 'filename' => 'Blatt.txt',
        ], 'Hallo');
        $gradeitem = $DB->get_record('grade_items', [
            'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $module->id,
        ], '*', MUST_EXIST);
        $DB->set_field('grade_items', 'gradepass', 40.5, ['id' => $gradeitem->id]);

        $state = assign::state((int) $module->id, (int) $module->cmid, false);

        $this->assertSame('Abgabe 1', $state['name']);
        $this->assertSame(['html' => '', 'preview' => 'Bitte lesen', 'truncated' => true], $state['content']);
        $this->assertSame([], $state['quizslots']);
        $this->assertSame(self::pairs([
            'duedate' => '1700100000', 'allowsubmissionsfromdate' => '1700000000', 'cutoffdate' => '1700200000',
            'gradingduedate' => '1700300000', 'completionsubmit' => '0', 'grade' => '80', 'gradepass' => '40.5',
            'submissiondrafts' => '1', 'maxattempts' => '3', 'attemptreopenmethod' => 'manual',
            'requiresubmissionstatement' => '1', 'teamsubmission' => '1', 'requireallteammemberssubmit' => '1',
            'teamsubmissiongroupingid' => '0', 'sendnotifications' => '1', 'sendlatenotifications' => '1',
            'sendstudentnotifications' => '0', 'blindmarking' => '1', 'markingworkflow' => '1', 'markingallocation' => '1',
            'gradecat' => (string) $gradeitem->categoryid, 'gradingmethod' => 'none',
            'additionalfiles' => json_encode([[
                'filename' => 'Blatt.txt', 'filepath' => '/', 'filesize' => '5', 'mimetype' => 'text/plain',
            ]]),
            'onlinetext_enabled' => '1', 'onlinetext_wordlimit_enabled' => '1', 'onlinetext_wordlimit' => '50',
            'submission_file_enabled' => '1', 'submission_file_maxfiles' => '3', 'submission_file_maxsizebytes' => '1048576',
            'submission_file_filetypes' => '.pdf', 'feedback_comments_enabled' => '1', 'feedback_editpdf_enabled' => '0',
            'feedback_file_enabled' => '1', 'feedback_file_maxfiles' => '0', 'feedback_file_maxsizebytes' => '0',
            'feedback_file_filetypes' => '', 'feedback_offline_enabled' => '1',
        ]), $state['settings']);
    }

    /**
     * Full content reveals the intro HTML; an unknown instance yields the empty state.
     */
    public function test_assign_state_with_full_content_and_for_a_missing_instance(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'intro' => '<p>Text</p>']);

        $full = assign::state((int) $module->id, (int) $module->cmid, true);
        $missing = assign::state(0, (int) $module->cmid, false);

        $this->assertSame(['html' => '<p>Text</p>', 'preview' => 'Text', 'truncated' => false], $full['content']);
        $empty = ['html' => '', 'preview' => '', 'truncated' => false];
        $this->assertSame(['name' => '', 'content' => $empty, 'settings' => [], 'quizslots' => []], $missing);
    }

    /**
     * A missing gradebook item has no passing grade or category in the catalog state.
     */
    public function test_assign_state_without_a_grade_item_uses_zero_defaults(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $DB->delete_records('grade_items', ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $module->id]);

        $state = assign::state((int) $module->id, (int) $module->cmid, false);
        $settings = array_column($state['settings'], 'value', 'name');

        $this->assertSame('0', $settings['gradepass']);
        $this->assertSame('0', $settings['gradecat']);
    }

    /**
     * Feedback configuration belongs to its assignment, including non-default values.
     */
    public function test_assign_state_keeps_feedback_settings_separate_between_assignments(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $second = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        foreach ([[$first, '3', '2048', '.pdf', '1'], [$second, '7', '4096', '.txt', '0']] as $fixture) {
            [$module, $maxfiles, $maxbytes, $types, $enabled] = $fixture;
            $context = \context_module::instance($module->cmid);
            $cm = get_coursemodule_from_id('assign', $module->cmid, 0, false, MUST_EXIST);
            $assignment = new \assign($context, $cm, $course);
            $fileplugin = $assignment->get_feedback_plugin_by_type('file');
            $fileplugin->set_config('maxfiles', $maxfiles);
            $fileplugin->set_config('maxsizebytes', $maxbytes);
            $fileplugin->set_config('filetypes', $types);
            $assignment->get_feedback_plugin_by_type('editpdf')->set_config('enabled', $enabled);
        }

        $state = assign::state((int) $first->id, (int) $first->cmid, false);
        $settings = array_column($state['settings'], 'value', 'name');

        $this->assertSame('3', $settings['feedback_file_maxfiles']);
        $this->assertSame('2048', $settings['feedback_file_maxsizebytes']);
        $this->assertSame('.pdf', $settings['feedback_file_filetypes']);
        $this->assertSame('1', $settings['feedback_editpdf_enabled']);
    }

    /**
     * A present plugin setting with a null value is empty text, rather than the missing-setting default.
     */
    public function test_assign_state_distinguishes_a_null_plugin_value_from_a_missing_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $context = \context_module::instance($module->cmid);
        $cm = get_coursemodule_from_id('assign', $module->cmid, 0, false, MUST_EXIST);
        $assignment = new \assign($context, $cm, $course);
        $assignment->get_feedback_plugin_by_type('file')->set_config('maxfiles', null);

        $state = assign::state((int) $module->id, (int) $module->cmid, false);
        $settings = array_column($state['settings'], 'value', 'name');

        $this->assertSame('', $settings['feedback_file_maxfiles']);
    }

    /**
     * The grade origin of an assignment is the teacher, or none when it is not graded.
     */
    public function test_assign_grade_origin_follows_the_grade_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $graded = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $ungraded = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 0]);

        $this->assertSame(learner_locks::GRADE_TEACHER, assign::grade_origin((int) $graded->id));
        $this->assertSame(learner_locks::GRADE_NONE, assign::grade_origin((int) $ungraded->id));
        $this->assertSame(learner_locks::GRADE_TEACHER, assign::grade_origin());
    }

    /**
     * A label reports name and intro; page adds its content and an intro preview; url its address.
     */
    public function test_label_page_and_url_state(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module(
            'label',
            ['course' => $course->id, 'name' => 'Etikett', 'intro' => '<p>Hinweis</p>']
        );
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Seite', 'intro' => '<p>Kurz</p>', 'content' => '<p>Lang <i>text</i></p>',
        ]);
        $url = $this->getDataGenerator()->create_module('url', [
            'course' => $course->id, 'name' => 'Link', 'intro' => '<p>Zur Seite</p>', 'externalurl' => 'https://example.org/x',
        ]);

        $labelstate = label::state((int) $label->id, (int) $label->cmid, true);
        $pagestate = page::state((int) $page->id, (int) $page->cmid, false);
        $urlstate = url::state((int) $url->id, (int) $url->cmid, true);

        $this->assertSame('Etikett', $labelstate['name']);
        $this->assertSame(['html' => '<p>Hinweis</p>', 'preview' => 'Hinweis', 'truncated' => false], $labelstate['content']);
        $this->assertSame([], $labelstate['settings']);
        $this->assertSame('Seite', $pagestate['name']);
        $this->assertSame(['html' => '', 'preview' => 'Lang text', 'truncated' => true], $pagestate['content']);
        $this->assertSame(self::pairs(['intro' => 'Kurz']), $pagestate['settings']);
        $this->assertSame('Link', $urlstate['name']);
        $this->assertSame(['html' => '<p>Zur Seite</p>', 'preview' => 'Zur Seite', 'truncated' => false], $urlstate['content']);
        $this->assertSame(self::pairs(['externalurl' => 'https://example.org/x']), $urlstate['settings']);
        foreach ([[label::class, 'label'], [page::class, 'page'], [url::class, 'url']] as [$class, $modname]) {
            $this->assertSame('', $class::state(0, 0, false)['name'], $modname);
            $this->assertSame([], $class::state(0, 0, false)['settings'], $modname);
        }
    }

    /**
     * Resource, folder, choice and forum report only their name.
     */
    public function test_name_only_catalogs(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $catalogs = [
            resource::class => 'resource', folder::class => 'folder', choice::class => 'choice', forum::class => 'forum',
        ];
        foreach ($catalogs as $class => $modname) {
            $module = $this->getDataGenerator()->create_module($modname, ['course' => $course->id, 'name' => "Name $modname"]);

            $state = $class::state((int) $module->id, (int) $module->cmid, true);

            $this->assertSame("Name $modname", $state['name'], $modname);
            $this->assertSame(['html' => '', 'preview' => '', 'truncated' => false], $state['content'], $modname);
            $this->assertSame([], $state['settings'], $modname);
            $this->assertSame('', $class::state(0, 0, false)['name'], $modname);
        }
    }

    /**
     * A quiz reports behaviour, attempts, grading and its passing grade as text.
     */
    public function test_quiz_state_reports_settings_as_text(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'name' => 'Test A', 'intro' => '<p>Los</p>', 'attempts' => 2, 'grademethod' => 2,
            'timelimit' => 600, 'grade' => 12.5, 'preferredbehaviour' => 'interactive', 'sumgrades' => 0,
        ]);
        $DB->set_field('grade_items', 'gradepass', 6.5, [
            'itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $module->id, 'itemnumber' => 0,
        ]);

        $state = quiz::state((int) $module->id, (int) $module->cmid, true);

        $this->assertSame('Test A', $state['name']);
        $this->assertSame(['html' => '<p>Los</p>', 'preview' => 'Los', 'truncated' => false], $state['content']);
        $this->assertSame(self::pairs([
            'preferredbehaviour' => 'interactive', 'attempts' => '2', 'grademethod' => '2', 'timelimit' => '600',
            'grade' => '12.5', 'gradepass' => '6.5', 'grademax' => '12.5',
        ]), $state['settings']);
        $this->assertSame([], $state['quizslots']);
        $empty = ['html' => '', 'preview' => '', 'truncated' => false];
        $this->assertSame(
            ['name' => '', 'content' => $empty, 'settings' => [], 'quizslots' => []],
            quiz::state(0, 0, true)
        );
    }
}
