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
namespace local_coursepilot\history;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Domain contract of the history read surface (#674): identifiers, ordering,
 * merged state and field-level diffs as the tool callers see them.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(version_history::class)]
final class version_history_674_final_contract_test extends \advanced_testcase {
    /**
     * Create a course with one page and an editing teacher as current user.
     *
     * @param string $name Activity name.
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function create_page(string $name = 'Erste Fassung'): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => $name,
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        return [$course, $cm];
    }

    /**
     * Add a further page to an existing course.
     *
     * @param \stdClass $course
     * @param string $name
     * @return \stdClass Course module.
     */
    private function add_page(\stdClass $course, string $name): \stdClass {
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => $name,
        ]);
        return get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
    }

    /**
     * Rename the page through the form path, which writes a new version.
     *
     * @param \stdClass $course
     * @param \stdClass $cm
     * @param string $name
     * @return void
     */
    private function rename_page(\stdClass $course, \stdClass $cm, string $name): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        $moduleinfo->name = $name;
        $moduleinfo->page = ['text' => $moduleinfo->content, 'format' => $moduleinfo->contentformat, 'itemid' => 0];
        $moduleinfo->printintro = 0;
        $moduleinfo->printlastmodified = 1;
        \update_moduleinfo($cm, $moduleinfo, $course, null);
    }

    /**
     * Id of the stored version row of an activity.
     *
     * @param int $cmid
     * @param int $version
     * @return int
     */
    private function version_row_id(int $cmid, int $version): int {
        global $DB;
        return (int) $DB->get_field('local_coursepilot_cm_version', 'id', ['cmid' => $cmid, 'version' => $version], MUST_EXIST);
    }

    /**
     * Replace the stored module info of one version.
     *
     * @param int $cmid
     * @param int $version
     * @param mixed[] $info
     * @return void
     */
    private function set_moduleinfo(int $cmid, int $version, array $info): void {
        global $DB;
        $DB->set_field(
            'local_coursepilot_cm_version',
            'moduleinfo_json',
            json_encode($info, JSON_UNESCAPED_UNICODE),
            ['id' => $this->version_row_id($cmid, $version)]
        );
    }

    /**
     * Insert deduplicated file metadata and return its id.
     *
     * @param string $component
     * @param string $filearea
     * @param string $filename
     * @return int
     */
    private function insert_file(string $component, string $filearea, string $filename): int {
        global $DB;
        return (int) $DB->insert_record('local_coursepilot_cm_file', (object) [
            'pathnamehash' => sha1($component . $filearea . $filename),
            'contenthash' => sha1('content-' . $filename),
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'filesize' => 1,
            'mimetype' => null,
            'timemodified' => time(),
        ]);
    }

    /**
     * Link a file to a version row.
     *
     * @param int $versionid
     * @param int $fileid
     * @return void
     */
    private function link_file(int $versionid, int $fileid): void {
        global $DB;
        $DB->insert_record('local_coursepilot_cm_version_file', (object) [
            'versionid' => $versionid,
            'fileid' => $fileid,
            'gap' => 0,
        ]);
    }

    /**
     * The listing names the activity with an integer cmid and returns its versions ascending.
     */
    public function test_list_versions_returns_integer_ids_and_ascending_versions(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $this->rename_page($course, $cm, 'Later title');

        $result = version_history::list_versions((int) $cm->id);

        $this->assertSame((int) $cm->id, $result['cmid']);
        $this->assertSame('page', $result['modname']);
        $this->assertNotSame('', $result['gap_notice']);
        $this->assertCount(2, $result['versions']);
        $this->assertSame(1, $result['versions'][0]['version']);
        $this->assertSame(2, $result['versions'][1]['version']);
        $this->assertGreaterThan(0, $result['versions'][1]['userid']);
        $this->assertGreaterThan(0, $result['versions'][1]['timestamp']);
    }

    /**
     * The versions form a plain list, not a map keyed by row id.
     */
    public function test_list_versions_is_a_plain_list(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $this->rename_page($course, $cm, 'Later title');

        $result = version_history::list_versions((int) $cm->id);

        $this->assertSame([0, 1], array_keys($result['versions']));
    }

    /**
     * The summary of a later version carries its number and a numeric date that matches its timestamp.
     */
    public function test_summary_line_shows_version_number_and_numeric_date(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $this->rename_page($course, $cm, 'Later title');

        $result = version_history::list_versions((int) $cm->id);
        $second = $result['versions'][1];
        $expecteddate = userdate($second['timestamp'], '%Y-%m-%d %H:%M', 99, false, false);

        $this->assertStringStartsWith('Version 2 - ', $second['summary_line']);
        $this->assertStringContainsString($expecteddate, $second['summary_line']);
    }

    /**
     * The date in the summary is rendered in the server timezone.
     */
    public function test_summary_date_uses_server_timezone(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->timezone = 'Europe/Berlin';
        [$course, $cm] = $this->create_page();
        $this->rename_page($course, $cm, 'Later title');
        // 2023-11-05 22:13 UTC is 2023-11-05 23:13 in Berlin.
        $DB->set_field(
            'local_coursepilot_cm_version',
            'timecreated',
            1699222380,
            ['cmid' => (int) $cm->id, 'version' => 2]
        );

        $result = version_history::list_versions((int) $cm->id);

        $this->assertStringContainsString('2023-11-05 23:13', $result['versions'][1]['summary_line']);
    }

    /**
     * A user's own timezone takes precedence over the server timezone in the summary date.
     */
    public function test_summary_date_uses_user_timezone_when_set(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $CFG->timezone = 'Europe/Berlin';
        [$course, $cm] = $this->create_page();
        $this->rename_page($course, $cm, 'Later title');
        // Set after the setup: helpers may replace the global $USER.
        $USER->timezone = 'America/New_York';
        // 2023-11-05 22:13 UTC is 17:13 in New York.
        $DB->set_field(
            'local_coursepilot_cm_version',
            'timecreated',
            1699222380,
            ['cmid' => (int) $cm->id, 'version' => 2]
        );

        $result = version_history::list_versions((int) $cm->id);

        $this->assertStringContainsString('2023-11-05 17:13', $result['versions'][1]['summary_line']);
    }

    /**
     * An unknown activity is an error, not an empty history.
     */
    public function test_list_versions_for_missing_activity_throws(): void {
        $this->resetAfterTest();
        $this->expectException(\dml_missing_record_exception::class);

        version_history::list_versions(999999999);
    }

    /**
     * A listing contains only the versions of the requested activity.
     */
    public function test_list_versions_only_lists_the_requested_activity(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $other = $this->add_page($course, 'Andere Seite');
        $this->rename_page($course, $cm, 'Later title');

        $result = version_history::list_versions((int) $other->id);

        $this->assertSame((int) $other->id, $result['cmid']);
        $this->assertCount(1, $result['versions']);
        $this->assertSame(1, $result['versions'][0]['version']);
    }

    /**
     * A version is looked up under its own activity only.
     */
    public function test_state_at_is_scoped_to_the_activity(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $other = $this->add_page($course, 'Andere Seite');
        $this->rename_page($course, $cm, 'Later title');

        $this->expectException(\moodle_exception::class);
        version_history::state_at((int) $other->id, 2);
    }

    /**
     * The merged state takes moduleinfo values over coursemodule values and keeps integer identity.
     */
    public function test_state_at_merges_module_info_over_course_module(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $this->rename_page($course, $cm, 'Later title');

        $first = version_history::state_at((int) $cm->id, 1);
        $second = version_history::state_at((int) $cm->id, 2);

        $this->assertSame('Erste Fassung', $first['name']);
        $this->assertSame('Later title', $second['name']);
        $this->assertSame((int) $cm->instance, $second['instance']);
        $this->assertIsInt($second['instance']);
    }

    /**
     * Stored course module state is overlaid by stored module info, and invalid stored JSON is ignored.
     */
    public function test_state_at_merges_stored_course_module_state(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cm] = $this->create_page();
        $where = ['cmid' => (int) $cm->id, 'version' => 1];
        $DB->set_field(
            'local_coursepilot_cm_version',
            'coursemodule_json',
            json_encode(['id' => (int) $cm->id, 'name' => 'FromCm', 'cmonly' => 'yes']),
            $where
        );
        $this->set_moduleinfo((int) $cm->id, 1, ['name' => 'FromInfo']);

        $state = version_history::state_at((int) $cm->id, 1);
        $this->assertSame('FromInfo', $state['name']);
        $this->assertSame('yes', $state['cmonly']);

        $DB->set_field('local_coursepilot_cm_version', 'coursemodule_json', 'not json', $where);
        $this->set_moduleinfo((int) $cm->id, 1, ['name' => 'OnlyInfo']);
        $state = version_history::state_at((int) $cm->id, 1);
        $this->assertSame('OnlyInfo', $state['name']);
        $this->assertArrayNotHasKey('cmonly', $state);
    }

    /**
     * The arrangement is decoded when stored and null when absent.
     */
    public function test_arrangement_at_returns_decoded_json_or_null(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cm] = $this->create_page();
        $where = ['cmid' => (int) $cm->id, 'version' => 1];
        $DB->set_field('local_coursepilot_cm_version', 'arrangement_json', json_encode(['sections' => [1]]), $where);

        $this->assertSame(['sections' => [1]], version_history::arrangement_at((int) $cm->id, 1));

        $DB->set_field('local_coursepilot_cm_version', 'arrangement_json', null, $where);
        $this->assertNull(version_history::arrangement_at((int) $cm->id, 1));
    }

    /**
     * Each changed field is reported once, sorted, with unescaped JSON values.
     */
    public function test_compare_reports_each_changed_field_once_sorted_and_unescaped(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $this->rename_page($course, $cm, 'Later title');
        $this->set_moduleinfo((int) $cm->id, 1, ['name' => 'Größe/Ä', 'zeta' => 'a', 'alpha' => 'x', 'gone' => 'g']);
        $this->set_moduleinfo((int) $cm->id, 2, ['name' => 'Neu/Ü', 'zeta' => 'a', 'alpha' => 'y', 'fresh' => 'f']);

        $diff = version_history::compare((int) $cm->id, 1, 2);

        $fields = array_column($diff['changes'], 'field');
        $sorted = $fields;
        sort($sorted);
        $this->assertSame($sorted, $fields);
        $this->assertSame(array_values(array_unique($fields)), $fields);
        foreach (['alpha', 'fresh', 'gone', 'name'] as $field) {
            $this->assertContains($field, $fields);
        }
        $byfield = array_column($diff['changes'], null, 'field');
        $this->assertSame('"Größe/Ä"', $byfield['name']['before_json']);
        $this->assertSame('"Neu/Ü"', $byfield['name']['after_json']);
        $this->assertSame('null', $byfield['fresh']['before_json']);
        $this->assertSame('"g"', $byfield['gone']['before_json']);
        $this->assertSame('null', $byfield['gone']['after_json']);
        $this->assertArrayNotHasKey('zeta', $byfield);
    }

    /**
     * Availability conditions leave the comparison only in sanitized form.
     */
    public function test_compare_sanitizes_availability_fields(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $this->rename_page($course, $cm, 'Later title');
        $profile = json_encode([
            'op' => '&',
            'c' => [['type' => 'profile', 'cf' => 'x', 'op' => 'isequalto', 'v' => 'geheim']],
            'showc' => [true],
        ]);
        $this->set_moduleinfo((int) $cm->id, 2, ['name' => 'Later title',
            'availability' => $profile, 'availabilityconditionsjson' => $profile]);

        $byfield = array_column(version_history::compare((int) $cm->id, 1, 2)['changes'], null, 'field');

        foreach (['availability', 'availabilityconditionsjson'] as $field) {
            $this->assertStringContainsString('***', $byfield[$field]['after_json']);
            $this->assertStringNotContainsString('geheim', $byfield[$field]['after_json']);
        }
    }

    /**
     * Only files allowed for the activity are listed, and allowed_files is callable directly.
     */
    public function test_allowed_files_drops_files_outside_the_policy(): void {
        $this->resetAfterTest();
        [, $cm] = $this->create_page();
        $versionid = $this->version_row_id((int) $cm->id, 1);
        $this->link_file($versionid, $this->insert_file('user', 'private', 'geheim.pdf'));
        $this->link_file($versionid, $this->insert_file('mod_page', 'intro', 'intro.pdf'));

        $files = version_history::files_at((int) $cm->id, 1);
        $this->assertSame(['intro.pdf'], array_column($files, 'filename'));
        $this->assertSame([0], array_keys($files));

        $direct = version_history::allowed_files($versionid, 'page');
        $this->assertSame(['intro.pdf'], array_column($direct, 'filename'));
    }

    /**
     * File changes are evaluated against the activity the version belongs to.
     */
    public function test_compare_reports_file_added_to_a_label(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->create_page();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $labelcm = get_coursemodule_from_instance('label', $label->id, $course->id, false, MUST_EXIST);

        $first = $DB->get_record('local_coursepilot_cm_version', ['cmid' => (int) $labelcm->id, 'version' => 1], '*', MUST_EXIST);
        $row = (array) $first;
        unset($row['id']);
        $row['version'] = 2;
        $secondid = (int) $DB->insert_record('local_coursepilot_cm_version', (object) $row);
        $this->link_file($secondid, $this->insert_file('mod_label', 'intro', 'label.pdf'));

        $diff = version_history::compare((int) $labelcm->id, 1, 2);

        $this->assertSame([['change_type' => 'added', 'filename' => 'label.pdf']], $diff['files']);
    }

    /**
     * Activities are listed by name and the activity deleted in the meantime is left out.
     */
    public function test_course_activities_are_sorted_by_name_and_skip_deleted_ones(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/course/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        // Orphan history row first: the delete observer removes real rows, so insert directly.
        // cmid 1 is the lowest id and the first inserted row, so a `break` instead of `continue`
        // would drop the activities after it.
        $this->assertFalse($DB->record_exists('course_modules', ['id' => 1]));
        $DB->insert_record('local_coursepilot_cm_version', (object) [
            'cmid' => 1, 'courseid' => (int) $course->id, 'version' => 1,
            'source' => 'moodle', 'userid' => 0, 'moduleinfo_json' => '{}',
            'coursemodule_json' => '{}', 'timecreated' => time(),
        ]);
        $zeta = $this->add_page($course, 'Zeta');
        $alphacm = $this->add_page($course, 'Alpha');

        $activities = version_history::course_activities((int) $course->id);

        $this->assertSame(['Alpha', 'Zeta'], array_column($activities, 'name'));
        $this->assertSame([(int) $alphacm->id, (int) $zeta->id], array_column($activities, 'cmid'));
        $this->assertSame(['page', 'page'], array_column($activities, 'modname'));
    }

    /**
     * The compare result has exactly the documented keys, including cmid.
     */
    public function test_compare_returns_the_documented_keys(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $this->rename_page($course, $cm, 'Later title');

        $result = version_history::compare((int) $cm->id, 1, 2);

        $this->assertSame(['cmid', 'modname', 'before', 'after', 'changes', 'files', 'gap_notice'], array_keys($result));
        $this->assertSame((int) $cm->id, $result['cmid']);
    }

    /**
     * An unknown version names both the activity and the version in the exception arguments.
     */
    public function test_unknown_version_exception_carries_cmid_and_version(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();

        try {
            version_history::compare((int) $cm->id, 1, 9);
            $this->fail('Expected versionnotfound.');
        } catch (\moodle_exception $e) {
            $this->assertSame('versionnotfound', $e->errorcode);
            $this->assertSame(['cmid' => (int) $cm->id, 'version' => 9], (array) $e->a);
        }
    }

    /**
     * Hours below ten keep their leading zero in the summary date.
     */
    public function test_summary_time_keeps_leading_zero_hour(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->timezone = 'Europe/Berlin';
        [$course, $cm] = $this->create_page();
        $this->rename_page($course, $cm, 'Later title');
        // 2023-11-05 07:05 UTC is 2023-11-05 08:05 in Berlin.
        $DB->set_field(
            'local_coursepilot_cm_version',
            'timecreated',
            1699167900,
            ['cmid' => (int) $cm->id, 'version' => 2]
        );

        $result = version_history::list_versions((int) $cm->id);

        $this->assertStringContainsString('2023-11-05 08:05', $result['versions'][1]['summary_line']);
    }

    /**
     * A page without attachments has no file rows at any version.
     */
    public function test_files_at_is_empty_for_a_page_without_attachments(): void {
        $this->resetAfterTest();
        [, $cm] = $this->create_page();

        $this->assertSame([], version_history::files_at((int) $cm->id, 1));
    }
}
