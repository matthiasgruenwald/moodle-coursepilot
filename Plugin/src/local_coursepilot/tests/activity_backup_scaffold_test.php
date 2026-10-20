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
 * The synthesized backup scaffold around an activity XML: which files Moodle gets, with which
 * settings and visibility, and which activity XML is accepted for it (Spec 0026, #588).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(activity_backup::class)]
final class activity_backup_scaffold_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
    }

    /**
     * Course with an editing teacher acting.
     *
     * @return \stdClass
     */
    private function course_as_editing_teacher(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return $course;
    }

    /**
     * Minimal activity XML of a page.
     *
     * @param string $name
     * @return string
     */
    private function page_xml(string $name): string {
        return '<activity id="1" moduleid="900001" modulename="page" contextid="1"><page id="1">'
            . '<name>' . $name . '</name><intro></intro><introformat>1</introformat>'
            . '<content>&lt;p&gt;Hallo&lt;/p&gt;</content><contentformat>1</contentformat>'
            . '<legacyfiles>0</legacyfiles><legacyfileslast>$@NULL@$</legacyfileslast><display>5</display>'
            . '<displayoptions>a:1:{s:12:"printheading";s:1:"1";}</displayoptions><revision>1</revision>'
            . '<timemodified>0</timemodified></page></activity>';
    }

    /**
     * Restores an XML while keeping the scaffold directory, and returns that directory.
     *
     * @param int $courseid
     * @param int|null $section
     * @param string $xml
     * @param bool|null $hidden null leaves the default
     * @return string path of the kept scaffold
     */
    private function restore_keeping_scaffold(int $courseid, ?int $section, string $xml, ?bool $hidden = null): string {
        global $CFG;

        $CFG->keeptempdirectoriesonbackup = true;
        $before = glob($CFG->tempdir . '/backup/cp*', GLOB_ONLYDIR) ?: [];
        if ($hidden === null) {
            activity_backup::restore($courseid, $section, $xml);
        } else {
            activity_backup::restore($courseid, $section, $xml, $hidden);
        }
        $created = array_values(array_diff(glob($CFG->tempdir . '/backup/cp*', GLOB_ONLYDIR) ?: [], $before));
        $this->assertCount(1, $created);
        return $created[0];
    }

    /**
     * Relative file paths below a directory, sorted.
     *
     * @param string $dir
     * @return string[]
     */
    private function files_below(string $dir): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[] = substr($file->getPathname(), strlen($dir) + 1);
        }
        sort($files);
        return $files;
    }

    /**
     * The scaffold has a random 32-character id, exactly the sixteen files, each with an XML declaration.
     */
    public function test_scaffold_directory_has_a_random_id_and_exactly_the_expected_files(): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();

        $dir = $this->restore_keeping_scaffold((int) $course->id, 1, $this->page_xml('Gerüst'));

        $this->assertMatchesRegularExpression('/^cp[A-Za-z0-9]{30}$/', basename($dir));
        $this->assertSame([
            'activities/page_900001/calendar.xml',
            'activities/page_900001/competencies.xml',
            'activities/page_900001/filters.xml',
            'activities/page_900001/grade_history.xml',
            'activities/page_900001/grades.xml',
            'activities/page_900001/inforef.xml',
            'activities/page_900001/module.xml',
            'activities/page_900001/page.xml',
            'activities/page_900001/roles.xml',
            'completion.xml',
            'files.xml',
            'groups.xml',
            'moodle_backup.xml',
            'outcomes.xml',
            'questions.xml',
            'roles.xml',
            'scales.xml',
        ], array_values(array_filter($this->files_below($dir), static fn (string $f): bool => !str_ends_with($f, '.log'))));
        $declaration = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $static = [
            'files.xml' => '<files></files>',
            'roles.xml' => '<roles_definition></roles_definition>',
            'completion.xml' => '<course_completion></course_completion>',
            'scales.xml' => '<scales_definition></scales_definition>',
            'outcomes.xml' => '<outcomes_definition></outcomes_definition>',
            'questions.xml' => '<question_categories></question_categories>',
            'groups.xml' => '<groups><groupcustomfields></groupcustomfields><groupings><groupingcustomfields>'
                . '</groupingcustomfields></groupings></groups>',
            'activities/page_900001/calendar.xml' => '<events></events>',
            'activities/page_900001/inforef.xml' => '<inforef></inforef>',
            'activities/page_900001/filters.xml' => '<filters><filter_actives></filter_actives><filter_configs></filter_configs>'
                . '</filters>',
            'activities/page_900001/roles.xml' => '<roles><role_overrides></role_overrides><role_assignments></role_assignments>'
                . '</roles>',
            'activities/page_900001/grades.xml' => '<activity_gradebook><grade_items></grade_items><grade_letters></grade_letters>'
                . '</activity_gradebook>',
            'activities/page_900001/grade_history.xml' => '<grade_history><grade_grades></grade_grades></grade_history>',
            'activities/page_900001/competencies.xml' => '<course_module_competencies><competencies></competencies>'
                . '</course_module_competencies>',
        ];
        foreach ($static as $path => $content) {
            $this->assertSame($declaration . $content, file_get_contents("$dir/$path"), $path);
        }
        $activity = file_get_contents("$dir/activities/page_900001/page.xml");
        $this->assertSame($declaration . '<activity id="1"', substr($activity, 0, strlen($declaration) + 16));
    }

    /**
     * The module descriptor carries section, visibility, version and the fixed defaults, in order.
     *
     * @return array<string, array{?bool, int}>
     */
    public static function visibility(): array {
        return ['default is visible' => [null, 1], 'explicitly visible' => [false, 1], 'hidden' => [true, 0]];
    }

    /**
     * The descriptor of the synthesized module for each visibility choice.
     *
     * @param bool|null $hidden
     * @param int $visible
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('visibility')]
    public function test_module_descriptor_matches_section_visibility_and_version(?bool $hidden, int $visible): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();
        $before = time();

        $dir = $this->restore_keeping_scaffold((int) $course->id, 2, $this->page_xml('Modul'), $hidden);

        $text = file_get_contents("$dir/activities/page_900001/module.xml");
        $this->assertMatchesRegularExpression('#<added>(\d+)</added>#', $text);
        preg_match('#<added>(\d+)</added>#', $text, $m);
        $this->assertGreaterThanOrEqual($before, (int) $m[1]);
        $version = get_config('mod_page', 'version');
        $expected = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<module id=\"900001\" version=\"$version\"><modulename>page</modulename><sectionid>1</sectionid>"
            . "<sectionnumber>2</sectionnumber><idnumber>\$@NULL@\$</idnumber><added>{$m[1]}</added><score>0</score>"
            . "<indent>0</indent><visible>$visible</visible><visibleoncoursepage>1</visibleoncoursepage>"
            . "<visibleold>$visible</visibleold><groupmode>0</groupmode><groupingid>0</groupingid><completion>0</completion>"
            . '<completiongradeitemnumber>$@NULL@$</completiongradeitemnumber><completionpassgrade>0</completionpassgrade>'
            . '<completionview>0</completionview><completionexpected>0</completionexpected>'
            . '<availability>$@NULL@$</availability><showdescription>0</showdescription><downloadcontent>1</downloadcontent>'
            . '<lang>$@NULL@$</lang><tags></tags></module>';
        $this->assertSame($expected, $text);
    }

    /**
     * Without a section the activity lands in section 1; the visibility reaches the created activity.
     */
    public function test_restore_without_section_uses_section_one_and_visibility_reaches_the_activity(): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();

        $visible = activity_backup::restore((int) $course->id, null, $this->page_xml('Sichtbar'));
        $hidden = activity_backup::restore((int) $course->id, null, $this->page_xml('Versteckt'), true);

        $modinfo = get_fast_modinfo($course);
        $this->assertSame(1, (int) $modinfo->get_cm($visible)->sectionnum);
        $this->assertSame(1, (int) $modinfo->get_cm($hidden)->sectionnum);
        $this->assertSame(1, (int) $modinfo->get_cm($visible)->visible);
        $this->assertSame(0, (int) $modinfo->get_cm($hidden)->visible);
    }

    /**
     * Without an installed version the descriptor falls back to the whole-number site version.
     */
    public function test_descriptor_falls_back_to_the_integer_site_version(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();
        // The fallback applies when the module has no stored version; keep the real value to restore it.
        $DB->delete_records('config_plugins', ['plugin' => 'mod_page', 'name' => 'version']);
        purge_caches(['muc' => true]);

        $dir = $this->restore_keeping_scaffold((int) $course->id, 1, $this->page_xml('Ohne Version'));

        $this->assertStringContainsString(
            '<module id="900001" version="' . (int) $CFG->version . '">',
            file_get_contents("$dir/activities/page_900001/module.xml")
        );
    }

    /**
     * The backup descriptor lists the site versions, the root settings and the single activity.
     */
    public function test_backup_descriptor_settings_and_contents(): void {
        global $CFG;

        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();

        $dir = $this->restore_keeping_scaffold((int) $course->id, 1, $this->page_xml('Beschreibung'));

        $text = file_get_contents("$dir/moodle_backup.xml");
        preg_match('#<backup_date>(\d+)</backup_date>#', $text, $m);
        $this->assertNotEmpty($m);
        $now = $m[1];
        $settings = '';
        $rootsettings = ['users' => 0, 'activities' => 1, 'files' => 1, 'filters' => 1, 'calendarevents' => 1, 'groups' => 1,
            'competencies' => 1, 'customfield' => 1, 'contentbankcontent' => 1];
        foreach ($rootsettings as $name => $value) {
            $settings .= "<setting><level>root</level><name>$name</name><value>$value</value></setting>";
        }
        $settings .= '<setting><level>activity</level><activity>page_900001</activity><name>page_900001_included</name>'
            . '<value>1</value></setting><setting><level>activity</level><activity>page_900001</activity>'
            . '<name>page_900001_userinfo</name><value>0</value></setting>';
        $expected = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<moodle_backup><information><name>synth.mbz</name>'
            . "<moodle_version>{$CFG->version}</moodle_version><moodle_release>" . s($CFG->release) . '</moodle_release>'
            . '<backup_version>' . \backup::VERSION . '</backup_version><backup_release>' . s(\backup::RELEASE)
            . "</backup_release><backup_date>$now</backup_date><mnet_remoteusers>0</mnet_remoteusers>"
            . '<include_files>0</include_files>'
            . '<include_file_references_to_external_content>0</include_file_references_to_external_content>'
            . '<original_wwwroot>https://synthetic.invalid</original_wwwroot>'
            . '<original_site_identifier_hash>synthetic</original_site_identifier_hash>'
            . '<original_course_id>1</original_course_id><original_course_format>topics</original_course_format>'
            . '<original_course_fullname>s</original_course_fullname><original_course_shortname>s</original_course_shortname>'
            . '<original_course_startdate>0</original_course_startdate><original_course_enddate>0</original_course_enddate>'
            . '<original_course_contextid>1</original_course_contextid><original_system_contextid>1</original_system_contextid>'
            . '<details><detail backup_id="synthetic"><type>activity</type><format>moodle2</format><interactive></interactive>'
            . '<mode>' . \backup::MODE_IMPORT . '</mode><execution>1</execution><executiontime>0</executiontime></detail></details>'
            . '<contents><activities><activity><moduleid>900001</moduleid><sectionid>1</sectionid>'
            . '<modulename>page</modulename><title>synth</title><directory>activities/page_900001</directory>'
            . '<insubsection></insubsection></activity></activities></contents>'
            . "<settings>$settings</settings></information></moodle_backup>";
        $this->assertSame($expected, $text);
    }

    /**
     * Leading whitespace is tolerated and dropped; an own XML declaration is replaced, not doubled.
     */
    public function test_restore_accepts_leading_whitespace_and_replaces_a_declaration(): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();
        $declaration = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

        $padded = $this->restore_keeping_scaffold((int) $course->id, 1, "\n  " . $this->page_xml('Mit Leerraum'));
        $declared = $this->restore_keeping_scaffold(
            (int) $course->id,
            1,
            '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n  " . $this->page_xml('Mit Deklaration')
        );

        $this->assertSame(
            $declaration . $this->page_xml('Mit Leerraum'),
            file_get_contents("$padded/activities/page_900001/page.xml")
        );
        $this->assertSame(
            $declaration . $this->page_xml('Mit Deklaration'),
            file_get_contents("$declared/activities/page_900001/page.xml")
        );
    }

    /**
     * Only an activity element with a plain lowercase module name of a backup-capable type is accepted.
     *
     * @return array<string, array{string}>
     */
    public static function rejected_xml(): array {
        return [
            'other root element' => ['<foo modulename="page"/>'],
            'uppercase module name' => ['<activity modulename="Page"/>'],
            'module name with suffix' => ['<activity modulename="page-x"/>'],
            'module name with prefix' => ['<activity modulename="9page"/>'],
            'empty module name' => ['<activity modulename=""/>'],
            'unknown module type' => ['<activity modulename="nosuchmod"/>'],
        ];
    }

    /**
     * Unsuitable activity XML is refused before anything is restored.
     *
     * @param string $xml
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejected_xml')]
    public function test_restore_rejects_unsuitable_activity_xml(string $xml): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();

        $this->expectException(\invalid_parameter_exception::class);
        activity_backup::restore((int) $course->id, 1, $xml);
    }

    /**
     * Unparseable XML is rejected as not an activity, not as an internal error.
     */
    public function test_restore_rejects_unparseable_xml(): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();

        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(\invalid_parameter_exception::class);
            activity_backup::restore((int) $course->id, 1, '<activity modulename="page"');
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A finished backup releases the controller and its log handles even when collection of cycles is off.
     */
    public function test_backup_releases_its_controller_resources(): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        // Warm up lazily opened handles (caches, session) once, then measure.
        $this->discard(activity_backup::backup($cm));
        $gc = gc_enabled();
        gc_collect_cycles();
        gc_disable();
        try {
            $before = count(scandir('/proc/self/fd'));
            $backupid = activity_backup::backup($cm);
            $after = count(scandir('/proc/self/fd'));
        } finally {
            if ($gc) {
                gc_enable();
            }
        }
        $this->discard($backupid);

        $this->assertSame($before, $after);
    }

    /**
     * Removes a backup directory a test created.
     *
     * @param string $backupid
     */
    private function discard(string $backupid): void {
        global $CFG;
        fulldelete($CFG->tempdir . '/backup/' . $backupid);
    }
}
