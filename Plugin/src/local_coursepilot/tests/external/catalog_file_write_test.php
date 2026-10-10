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

namespace local_coursepilot\external;

use local_coursepilot\catalog\write_target;
use local_coursepilot\material_files;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * File and editor write sequences of the public create/update tools run only
 * after a fully checked target (Spec 0028 F11, issue #647): a rejected or
 * failing write leaves neither files nor activity changes behind.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(write_target::class)]
#[CoversClass(create_module::class)]
#[CoversClass(update_module_settings::class)]
final class catalog_file_write_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass */
    private \stdClass $teacher;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Exercise the endpoint transaction outside PHPUnit's enclosing PostgreSQL transaction.
        $this->preventResetByRollback();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($this->teacher);
        $this->store_material('blatt.pdf', 'First version');
        $this->store_material('bild.png', $this->png());
    }

    protected function tearDown(): void {
        // Clears the replaced observers' keep-flag so the next reset reloads the real ones.
        \core\event\manager::phpunit_reset();
        parent::tearDown();
    }

    /**
     * Native failure injected at the very end of add_moduleinfo()/update_moduleinfo().
     * An \Error (not \Exception) is not swallowed by the event manager.
     *
     * @param \core\event\base $event
     * @return never
     */
    public static function fail_native_write(\core\event\base $event): never {
        throw new \Error('Injected native failure');
    }

    /**
     * Assign with an attached "blatt.pdf" and an embedded "bild.png"; the
     * material files are then replaced so a repeated reference would trash
     * the attached versions.
     *
     * @return int cmid
     */
    private function assign_with_files(): int {
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $this->course->id,
            'name' => 'Original',
            'duedate' => 2000000000,
            'cutoffdate' => 0,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        update_module_settings::execute($cmid, json_encode([
            'intro' => '<img src="@@PLUGINFILE@@/bild.png" alt="Diagram">',
            'introimages' => ['bild.png'],
            'introattachments' => ['blatt.pdf'],
        ]));
        $this->store_material('blatt.pdf', 'Second version');
        $this->store_material('bild.png', $this->png(30));
        return $cmid;
    }

    /**
     * Every stored file, every activity row and every history version.
     *
     * @return array
     */
    private function snapshot(): array {
        global $DB;
        $files = array_map(
            static fn(\stdClass $f): string => implode('|', [$f->contextid, $f->component, $f->filearea,
                $f->itemid, $f->filepath, $f->filename, $f->contenthash]),
            $DB->get_records_select(
                'files',
                "filename <> '.'",
                null,
                'id',
                'id, contextid, component, filearea, itemid, filepath, filename, contenthash'
            )
        );
        return [
            'files' => array_values($files),
            'assign' => array_values($DB->get_records('assign', ['course' => $this->course->id], 'id')),
            'cms' => array_values($DB->get_records('course_modules', ['course' => $this->course->id], 'id')),
            'sections' => $DB->count_records('course_sections', ['course' => $this->course->id]),
            'versions' => $DB->count_records('local_coursepilot_cm_version'),
        ];
    }

    /**
     * Valid file input combined with one rejected rule.
     *
     * @return array<string, array{0: array, 1: string}>
     */
    public static function rejected_updates(): array {
        return [
            'invalid date order' => [['cutoffdate' => 1000000000], 'combinationruleviolation'],
            'unknown field' => [['nosuchfield' => 1], 'unknownfield'],
            'unconfirmed learner lock' => [['attemptreopenmethod' => 'manual'], 'learnerlocksunconfirmed'],
            'disallowed intro image type' => [['introimages' => ['blatt.pdf']], 'materialfiledisallowedtype'],
        ];
    }

    #[DataProvider('rejected_updates')]
    public function test_rejected_update_mutates_neither_files_nor_activity(array $invalid, string $errorcode): void {
        $cmid = $this->assign_with_files();
        $before = $this->snapshot();

        try {
            update_module_settings::execute($cmid, json_encode($invalid + [
                'name' => 'Changed',
                'intro' => '<img src="@@PLUGINFILE@@/bild.png" alt="New">',
                'introimages' => ['bild.png'],
                'introattachments' => ['blatt.pdf'],
            ]));
            $this->fail('Expected rejection.');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_update_without_permission_mutates_neither_files_nor_activity(): void {
        $cmid = $this->assign_with_files();
        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $this->course->id, 'teacher');
        $this->setUser($nonedit);
        $this->store_material('blatt.pdf', 'Foreign version');
        $before = $this->snapshot();

        try {
            update_module_settings::execute($cmid, json_encode([
                'name' => 'Changed',
                'introattachments' => ['blatt.pdf'],
            ]));
            $this->fail('Expected capability rejection.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/course:manageactivities'), $e->a);
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_failure_mid_file_sequence_rolls_back_trash_and_drafts(): void {
        $cmid = $this->assign_with_files();
        $before = $this->snapshot();

        try {
            // Note: "blatt.pdf" is trashed and copied into a draft before "missing.pdf" fails.
            update_module_settings::execute($cmid, json_encode([
                'name' => 'Changed',
                'introattachments' => ['blatt.pdf', 'missing.pdf'],
            ]));
            $this->fail('Expected materialfilenotfound.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialfilenotfound', $e->errorcode);
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_update_without_file_permission_mutates_neither_files_nor_activity(): void {
        global $DB;
        $cmid = $this->assign_with_files();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($this->teacher->id)->id,
            true
        );
        $before = $this->snapshot();

        try {
            update_module_settings::execute($cmid, json_encode([
                'name' => 'Changed',
                'introattachments' => ['blatt.pdf'],
            ]));
            $this->fail('Expected capability rejection.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/user:manageownfiles'), $e->a);
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_native_failure_during_update_rolls_back_files_and_activity(): void {
        $cmid = $this->assign_with_files();
        $before = $this->snapshot();
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\core\event\course_module_updated',
            'callback' => self::class . '::fail_native_write',
        ]]);

        try {
            update_module_settings::execute($cmid, json_encode([
                'name' => 'Changed',
                'intro' => '<img src="@@PLUGINFILE@@/bild.png" alt="New">',
                'introimages' => ['bild.png'],
                'introattachments' => ['blatt.pdf'],
            ]));
            $this->fail('A native failure must not report success.');
        } catch (\Error $e) {
            $this->assertSame('Injected native failure', $e->getMessage());
        }

        $this->assertEquals($before, $this->snapshot());
    }

    /**
     * Provides rejected creates.
     *
     * @return array<string, array{0: array, 1: string}>
     */
    public static function rejected_creates(): array {
        return [
            'invalid date order' => [['duedate' => 2000000000, 'cutoffdate' => 1000000000], 'combinationruleviolation'],
            'unknown field' => [['nosuchfield' => 1], 'unknownfield'],
            'unconfirmed learner lock' => [['attemptreopenmethod' => 'manual'], 'learnerlocksunconfirmed'],
            'disallowed intro image type' => [['introimages' => ['blatt.pdf']], 'materialfiledisallowedtype'],
        ];
    }

    #[DataProvider('rejected_creates')]
    public function test_rejected_create_mutates_neither_files_nor_course(array $invalid, string $errorcode): void {
        $before = $this->snapshot();

        try {
            create_module::execute($this->course->id, 3, 'assign', json_encode($invalid + [
                'name' => 'New',
                'intro' => '<img src="@@PLUGINFILE@@/bild.png" alt="New">',
                'introimages' => ['bild.png'],
                'introattachments' => ['blatt.pdf'],
            ]));
            $this->fail('Expected rejection.');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_create_without_permission_mutates_neither_files_nor_course(): void {
        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $this->course->id, 'teacher');
        $this->setUser($nonedit);
        $this->store_material('blatt.pdf', 'Foreign version');
        $before = $this->snapshot();

        try {
            create_module::execute($this->course->id, 3, 'resource', json_encode([
                'name' => 'New',
                'files' => ['blatt.pdf'],
            ]));
            $this->fail('Expected capability rejection.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/course:manageactivities'), $e->a);
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_native_failure_during_create_rolls_back_files_section_and_activity(): void {
        $before = $this->snapshot();
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\core\event\course_module_created',
            'callback' => self::class . '::fail_native_write',
        ]]);

        try {
            create_module::execute($this->course->id, 3, 'assign', json_encode([
                'name' => 'New',
                'intro' => '<img src="@@PLUGINFILE@@/bild.png" alt="New">',
                'introimages' => ['bild.png'],
                'introattachments' => ['blatt.pdf'],
            ]));
            $this->fail('A native failure must not report success.');
        } catch (\Error $e) {
            $this->assertSame('Injected native failure', $e->getMessage());
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_create_and_partial_update_with_editor_and_material_files_still_work(): void {
        global $DB;
        $created = create_module::execute($this->course->id, 3, 'assign', json_encode([
            'name' => 'New',
            'intro' => '<img src="@@PLUGINFILE@@/bild.png" alt="New">',
            'introimages' => ['bild.png'],
            'introattachments' => ['blatt.pdf'],
            'activityeditor' => '<p>Do it</p>',
        ]));
        $cmid = (int) $created['cmid'];
        $context = \context_module::instance($cmid);
        $fs = get_file_storage();
        $this->assertNotFalse($fs->get_file($context->id, 'mod_assign', 'intro', 0, '/', 'bild.png'));
        $this->assertSame(
            'First version',
            $fs->get_file($context->id, 'mod_assign', 'introattachment', 0, '/', 'blatt.pdf')->get_content()
        );
        $instance = $DB->get_record('assign', ['id' => get_coursemodule_from_id('assign', $cmid)->instance]);
        $this->assertSame('<p>Do it</p>', $instance->activity);
        $defaultgrade = $instance->grade;

        $this->store_material('blatt.pdf', 'Second version');
        update_module_settings::execute($cmid, json_encode(['introattachments' => ['blatt.pdf']]));

        $this->assertSame(
            'Second version',
            $fs->get_file($context->id, 'mod_assign', 'introattachment', 0, '/', 'blatt.pdf')->get_content()
        );
        $instance = $DB->get_record('assign', ['id' => $instance->id]);
        $this->assertSame('New', $instance->name);
        $this->assertSame('<p>Do it</p>', $instance->activity);
        $this->assertSame($defaultgrade, $instance->grade);
        $this->assertNotFalse($fs->get_file($context->id, 'mod_assign', 'intro', 0, '/', 'bild.png'));
    }

    /**
     * Stores material.
     *
     * @param string $path
     * @param string $content
     */
    private function store_material(string $path, string $content): void {
        $record = material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', $path);
        $existing = get_file_storage()->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );
        material_files::replace($existing ?: null, $record, $content);
    }

    /**
     * Returns PNG bytes.
     *
     * @param int $size
     * @return string PNG bytes.
     */
    private function png(int $size = 20): string {
        $image = imagecreatetruecolor($size, $size);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        return $png;
    }
}
