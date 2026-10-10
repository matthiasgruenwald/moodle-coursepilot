<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\external;

use core_external\external_api;
use local_coursepilot\catalog\choice;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Second write operation (Spec 0015 §3.4, issue #389).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(create_module::class)]
final class create_module_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Provides course with editing teacher.
     *
     * @return array{0: \stdClass, 1: \stdClass} Course, teacher (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * Set up fake external material storage for a logged-in teacher (#496).
     * See
     * {@see \local_coursepilot\external\update_module_settings_test::set_up_external_material_for()}.
     *
     * @param \stdClass $teacher
     * @return \local_coursepilot\tests\webdav\fake_webdav_transport
     */
    private function set_up_external_material_for(\stdClass $teacher): \local_coursepilot\tests\webdav\fake_webdav_transport {
        $this->grant_webdav_capability($teacher);
        $instanceid = $this->create_webdav_instance($teacher);
        $this->write_v2_pointer($teacher, 'material_store', $instanceid, 'Material');

        $fake = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        return $fake;
    }

    /**
     * Creates the create module test.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param string $modname
     * @param array $felder
     * @param string $ort {@see \local_coursepilot\material_files::LOCATION_STORE}/{@see \local_coursepilot\material_files::LOCATION_WORKBENCH}
     *        (Issue #496).
     * @param string[] $confirmlearnerlocks Explicitly confirmed learner restrictions (#583).
     * @return array
     */
    private function create(
        int $courseid,
        int $sectionnum,
        string $modname,
        array $felder,
        string $ort = \local_coursepilot\material_files::LOCATION_STORE,
        array $confirmlearnerlocks = []
    ): array {
        return external_api::clean_returnvalue(
            create_module::execute_returns(),
            create_module::execute($courseid, $sectionnum, $modname, json_encode($felder), $ort, $confirmlearnerlocks)
        );
    }

    /**
     * Simulate AI preparation: apply bundle values first, then explicit
     * fields. Bundles are not endpoint parameters (Spec 0015 §2.4).
     *
     * @param array $bundle
     * @param array $felder
     * @return array
     */
    private function merge_bundle(array $bundle, array $felder): array {
        return array_merge($bundle, $felder);
    }

    /**
     * Returns current state, with the same shape as get_module_settings.
     *
     * @param int $cmid
     * @return array Current state, with the same shape as get_module_settings.
     */
    private function read(int $cmid): array {
        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cmid)
        );
        return json_decode($result['settings_json'], true);
    }

    /**
     * Creation messages quote supplied values, including HTML. PARAM_TEXT
     * previously rejected the response even after successful creation (#400).
     */
    public function test_message_may_quote_html_content(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'label', [
            'intro' => '<p>Einstieg <strong>fett</strong></p>',
        ]);

        $this->assertStringContainsString('<p>Einstieg <strong>fett', $result['message']);
    }

    /**
     * Assignments without explicit submission settings still use
     * administrator-configurable form defaults, normally file submission,
     * instead of silently disabling all submission methods.
     */
    public function test_assign_without_submission_settings_gets_active_submissions(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'assign', [
            'name' => 'Hausaufgabe',
            'intro' => 'Beschreibung',
        ]);

        $after = $this->read($result['cmid']);
        $this->assertEquals(0, $after['nosubmissions'], 'nosubmissions must be 0 - at least one submission type active.');
        $this->assertSame('Beschreibung', $after['intro']);
        $this->assertSame([], get_file_storage()->get_area_files(
            \context_module::instance($result['cmid'])->id,
            'mod_assign',
            'intro',
            0,
            'filename',
            false
        ));
        $this->assertNotContains('introimages', array_column($result['created_fields'], 'field'));
    }

    /**
     * Preserve supplied parameter_N/variable_N fields when filling missing
     * URL defaults.
     */
    public function test_url_keeps_given_parameters(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'url', [
            'name' => 'Externer Link',
            'externalurl' => 'https://example.org/',
            'parameter_0' => 'id',
            'variable_0' => 'userid',
        ]);

        $after = $this->read($result['cmid']);
        $this->assertSame(['id' => 'userid'], unserialize($after['parameters']));
    }

    /**
     * Create material for the current user in upload_material_file’s
     * storage location (#428). See
     * {@see \local_coursepilot\external\update_module_settings_test::create_material_file()}.
     *
     * @param string $path
     * @param string $content
     * @return void
     */
    private function create_material_file(string $path, string $content): void {
        $filerecord = \local_coursepilot\material_files::filerecord(
            \local_coursepilot\material_files::own_context()->id,
            '/coursepilot-material/',
            $path
        );
        $existing = get_file_storage()->get_file(
            $filerecord['contextid'],
            $filerecord['component'],
            $filerecord['filearea'],
            $filerecord['itemid'],
            $filerecord['filepath'],
            $filerecord['filename']
        );
        \local_coursepilot\material_files::replace($existing ?: null, $filerecord, $content);
    }

    public function test_assign_create_persists_intro_image_from_werkbank(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $this->create_material_file('diagramm.png', 'Bildinhalt 581');

        $result = $this->create($course->id, 0, 'assign', [
            'name' => 'Bildaufgabe',
            'intro' => '<img src="@@PLUGINFILE@@/diagramm.png" alt="Diagramm">',
            'introimages' => ['diagramm.png'],
        ], \local_coursepilot\material_files::LOCATION_WORKBENCH);

        $context = \context_module::instance($result['cmid']);
        $file = get_file_storage()->get_file($context->id, 'mod_assign', 'intro', 0, '/', 'diagramm.png');
        $this->assertNotFalse($file);
        $this->assertSame('Bildinhalt 581', $file->get_content());
        $this->assertSame(sha1('Bildinhalt 581'), $file->get_contenthash());
        $this->assertStringContainsString('@@PLUGINFILE@@/diagramm.png', $this->read($result['cmid'])['intro']);
        $rendered = file_rewrite_pluginfile_urls(
            $this->read($result['cmid'])['intro'],
            'pluginfile.php',
            $context->id,
            'mod_assign',
            'intro',
            0
        );
        $this->assertStringContainsString('/pluginfile.php/' . $context->id . '/mod_assign/intro/0/diagramm.png', $rendered);
        $this->assertStringNotContainsString('draftfile.php', $rendered);
        $fields = array_column($result['created_fields'], 'value_json', 'field');
        $this->assertSame('["diagramm.png"]', $fields['introimages']);
        $this->assertSame([], report_loose_material_files::execute()['files']);
    }

    public function test_assign_create_persists_intro_image_from_bestand(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/bilder/diagramm.png', 'Bestandsbild 581');

        $result = $this->create($course->id, 0, 'assign', [
            'name' => 'Bildaufgabe aus dem Bestand',
            'intro' => '<img src="@@PLUGINFILE@@/diagramm.png" alt="Diagramm">',
            'introformat' => FORMAT_HTML,
            'introimages' => ['bilder/diagramm.png'],
        ]);

        $context = \context_module::instance($result['cmid']);
        $file = get_file_storage()->get_file($context->id, 'mod_assign', 'intro', 0, '/', 'diagramm.png');
        $this->assertNotFalse($file);
        $this->assertSame('Bestandsbild 581', $file->get_content());
        $this->assertSame(sha1('Bestandsbild 581'), $file->get_contenthash());
        $after = $this->read($result['cmid']);
        $this->assertStringContainsString('@@PLUGINFILE@@/diagramm.png', $after['intro']);
        $this->assertEquals(FORMAT_HTML, $after['introformat']);
        $fields = array_column($result['created_fields'], 'value_json', 'field');
        $this->assertSame('["diagramm.png"]', $fields['introimages']);
    }

    /**
     * Provides invalid intro images.
     *
     * @return array
     */
    public static function invalid_intro_images(): array {
        return [
            'missing file' => [['missing.png'], 'materialfilenotfound'],
            'disallowed extension' => [['worksheet.pdf'], 'materialfiledisallowedtype'],
            'scalar' => ['diagramm.png', 'invalidmaterialreferencelist'],
            'null' => [null, 'invalidmaterialreferencelist'],
            'object instead of list' => [['image' => 'diagramm.png'], 'invalidmaterialreferencelist'],
            'non-string entry' => [[['path' => 'diagramm.png']], 'materialfiledisallowedtype'],
            'path traversal' => [['../diagramm.png'], 'invalidmaterialpath'],
        ];
    }

    #[DataProvider('invalid_intro_images')]
    public function test_assign_create_rejects_invalid_intro_images_without_activity($paths, string $errorcode): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $this->create_material_file('worksheet.pdf', 'PDF content');

        try {
            $this->create($course->id, 0, 'assign', [
                'name' => 'Invalid image',
                'intro' => '<img src="@@PLUGINFILE@@/missing.png" alt="Missing">',
                'introimages' => $paths,
            ], \local_coursepilot\material_files::LOCATION_WORKBENCH);
            $this->fail('Expected rejection before activity creation.');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('assign', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
    }

    public function test_assign_create_checks_manageownfiles_before_image_type(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($teacher->id)->id,
            true
        );

        try {
            $this->create($course->id, 0, 'assign', [
                'name' => 'No file permission', 'intro' => 'Description', 'introimages' => ['worksheet.pdf'],
            ], \local_coursepilot\material_files::LOCATION_WORKBENCH);
            $this->fail('Expected capability rejection before the image whitelist.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/user:manageownfiles'), $e->a);
        }
        $this->assertSame(0, $DB->count_records('assign', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
    }

    /**
     * Create a resource with its main file in one call (Spec 0018 §4/§7,
     * #434). Resolve files references into the new activity’s content file
     * area before add_moduleinfo().
     */
    public function test_resource_creates_activity_with_main_file_in_one_call(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $this->create_material_file('arbeitsblatt.pdf', 'Arbeitsblattinhalt');

        $result = $this->create($course->id, 0, 'resource', [
            'name' => 'Datei',
            'files' => ['arbeitsblatt.pdf'],
        ]);

        $this->assertSame('resource', $result['modname']);
        $modulecontext = \context_module::instance($result['cmid']);
        $stored = get_file_storage()->get_file($modulecontext->id, 'mod_resource', 'content', 0, '/', 'arbeitsblatt.pdf');
        $this->assertNotFalse($stored, 'The main file must be under mod_resource/content.');
        $this->assertSame('Arbeitsblattinhalt', $stored->get_content());
    }

    /**
     * Embed directly from external inventory without routing through
     * the workbench (#496, Spec #486 §7).
     */
    public function test_resource_creates_activity_with_main_file_from_external_bestand(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/arbeitsblatt.pdf', 'Arbeitsblattinhalt');

        $result = $this->create($course->id, 0, 'resource', [
            'name' => 'Datei',
            'files' => ['arbeitsblatt.pdf'],
        ]);

        $this->assertSame('resource', $result['modname']);
        $modulecontext = \context_module::instance($result['cmid']);
        $stored = get_file_storage()->get_file($modulecontext->id, 'mod_resource', 'content', 0, '/', 'arbeitsblatt.pdf');
        $this->assertNotFalse($stored, 'The main file must be under mod_resource/content.');
        $this->assertSame('Arbeitsblattinhalt', $stored->get_content());
    }

    /**
     * Explicit location = workbench still uses the workbench when inventory
     * is external, matching material readers (#495/#496).
     */
    public function test_resource_with_ort_werkbank_ignores_external_bestand(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/nur-extern.pdf', 'external');
        $this->create_material_file('werkbankdatei.pdf', 'aus der Werkbank');

        $result = $this->create(
            $course->id,
            0,
            'resource',
            ['name' => 'Datei', 'files' => ['werkbankdatei.pdf']],
            \local_coursepilot\material_files::LOCATION_WORKBENCH
        );

        $modulecontext = \context_module::instance($result['cmid']);
        $stored = get_file_storage()->get_file($modulecontext->id, 'mod_resource', 'content', 0, '/', 'werkbankdatei.pdf');
        $this->assertNotFalse($stored);
        $this->assertSame('aus der Werkbank', $stored->get_content());
    }

    /**
     * Missing resource files trigger the mandatory-field mechanism with
     * a clear message and no new activity (#434).
     */
    public function test_resource_without_files_fails_and_creates_no_activity(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        try {
            $this->create($course->id, 0, 'resource', ['name' => 'Datei']);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('requiredfieldwithoutdefault', $e->errorcode);
            $this->assertStringContainsString('files', $e->getMessage());
        }

        $this->assertSame(
            0,
            $this->count_resource_coursemodules($course->id),
            'Without "files" no resource activity may be created in the course.'
        );
    }

    /**
     * An empty files list must fail like a missing field. Otherwise
     * resolve_into_draft() supplies a valid empty draft and creates a resource
     * without a main file (#434 review).
     */
    public function test_resource_with_empty_files_list_fails_and_creates_no_activity(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        try {
            $this->create($course->id, 0, 'resource', ['name' => 'Datei', 'files' => []]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('requiredfieldwithoutdefault', $e->errorcode);
        }

        $this->assertSame(0, $this->count_resource_coursemodules($course->id));
    }

    /**
     * Reject missing material references before add_moduleinfo(); leave no
     * empty activity behind.
     */
    public function test_resource_with_missing_material_file_fails_and_creates_no_activity(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        try {
            $this->create($course->id, 0, 'resource', ['name' => 'Datei', 'files' => ['gibtsnicht.pdf']]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('gibtsnicht.pdf', $e->getMessage());
        }

        $this->assertSame(0, $this->count_resource_coursemodules($course->id));
    }

    /**
     * Count resource course_modules rows directly in the database. Modinfo
     * cache may reflect failed add_moduleinfo() only after a rebuild.
     *
     * @param int $courseid
     * @return int
     */
    private function count_resource_coursemodules(int $courseid): int {
        global $DB;
        return (int) $DB->count_records_sql(
            'SELECT COUNT(cm.id) FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module '
                . 'WHERE cm.course = :courseid AND m.name = :modname',
            ['courseid' => $courseid, 'modname' => 'resource']
        );
    }

    /**
     * Create a folder without files; empty folders are valid.
     */
    public function test_folder_without_files_is_still_creatable(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'folder', ['name' => 'Materialordner']);

        $this->assertSame('folder', $result['modname']);
        $this->assertGreaterThan(0, $result['cmid']);
    }

    /**
     * Add several material files to a folder in one call, including an
     * entry with a selected target subfolder (#434).
     */
    public function test_folder_accepts_multiple_files_with_target_subfolder(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $this->create_material_file('wurzel.pdf', 'an der Wurzel');
        $this->create_material_file('blatt.pdf', 'im Unterordner');

        $result = $this->create($course->id, 0, 'folder', [
            'name' => 'Materialordner',
            'files' => [
                'wurzel.pdf',
                ['path' => 'blatt.pdf', 'target_folder' => 'unterordner'],
            ],
        ]);

        $modulecontext = \context_module::instance($result['cmid']);
        $fs = get_file_storage();
        $atroot = $fs->get_file($modulecontext->id, 'mod_folder', 'content', 0, '/', 'wurzel.pdf');
        $insubfolder = $fs->get_file($modulecontext->id, 'mod_folder', 'content', 0, '/unterordner/', 'blatt.pdf');
        $this->assertNotFalse($atroot, 'The file without a target folder must be at the root.');
        $this->assertNotFalse($insubfolder, 'The file with "zielordner" must be in the subfolder.');
        $this->assertSame('im Unterordner', $insubfolder->get_content());
    }

    /**
     * Create choices with 30 options for device allocation, without an
     * invented upper limit.
     */
    public function test_choice_with_thirty_options_can_be_created(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $options = array_map(static fn (int $i): string => "Geraet $i", range(1, 30));

        $result = $this->create($course->id, 0, 'choice', [
            'name' => 'Geraete-Zuteilung',
            'intro' => 'Bitte waehlen',
            'option' => $options,
            'allowupdate' => 1,
        ]);

        $cm = get_coursemodule_from_id('choice', $result['cmid'], 0, false, MUST_EXIST);
        $this->assertEquals(30, $DB->count_records('choice_options', ['choiceid' => $cm->instance]));
    }

    /**
     * Read repeated pseudofields back from choice_options instead of
     * reporting null from nonexistent columns (#564).
     */
    public function test_choice_create_report_uses_persisted_options_and_limits(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'choice', [
            'name' => 'Abstimmung',
            'intro' => 'Bitte waehlen',
            'option' => ['Ja', 'Nein'],
            'limit' => [2, 3],
            'allowupdate' => 1,
        ]);

        $fields = array_column($result['created_fields'], 'value_json', 'field');
        $this->assertSame('["Ja","Nein"]', $fields['option']);
        $this->assertSame('["2","3"]', $fields['limit']);
    }

    /**
     * Reject limit lists of the wrong length.
     */
    public function test_choice_with_mismatched_limit_length_fails(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        try {
            $this->create($course->id, 0, 'choice', [
                'name' => 'Abstimmung',
                'intro' => 'Bitte waehlen',
                'option' => ['A', 'B', 'C'],
                'limit' => [1, 2],
            ]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
            $this->assertStringContainsString('option', $e->getMessage());
        }
    }

    /**
     * The allocation bundle produces all six documented settings.
     */
    public function test_choice_zuteilung_bundle_sets_documented_fields(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'choice', $this->merge_bundle(choice::bundles()['allocation'], [
            'name' => 'Geraete-Zuteilung',
            'intro' => 'Bitte waehlen',
            'option' => ['Tablet 1', 'Tablet 2'],
        ]));

        $after = $this->read($result['cmid']);
        $this->assertEquals(1, $after['limitanswers']);
        $this->assertEquals(1, $after['publish']);
        $this->assertEquals(3, $after['showresults']);
        $this->assertEquals(1, $after['display']);
        $this->assertEquals(1, $after['allowupdate']);

        $cm = get_coursemodule_from_id('choice', $result['cmid'], 0, false, MUST_EXIST);
        $maxanswers = $DB->get_fieldset_select('choice_options', 'maxanswers', 'choiceid = ?', [$cm->instance]);
        $this->assertSame([1, 1], array_map('intval', $maxanswers));
    }

    /**
     * Explicit fields override bundle defaults; bundles never override
     * explicit input.
     */
    public function test_explicit_field_beats_bundle(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'choice', $this->merge_bundle(choice::bundles()['allocation'], [
            'name' => 'Geraete-Zuteilung',
            'intro' => 'Bitte waehlen',
            'option' => ['Tablet 1', 'Tablet 2'],
            'allowupdate' => 0,
        ]), \local_coursepilot\material_files::LOCATION_STORE, ['allowupdate']);

        $after = $this->read($result['cmid']);
        $this->assertEquals(0, $after['allowupdate']);
        // Die uebrigen Buendelfelder bleiben gesetzt.
        $this->assertEquals(1, $after['limitanswers']);
    }

    /**
     * Missing mandatory fields without form defaults (mod_page name here)
     * produce errors naming the field.
     */
    public function test_required_field_without_default_fails(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        try {
            $this->create($course->id, 0, 'page', [
                'page' => ['text' => 'Inhalt', 'format' => FORMAT_HTML, 'itemid' => 0],
            ]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('name', $e->getMessage());
        }
    }

    /**
     * Create pages from name and the page pseudofield alone. Requiring
     * content as well caused circular mandatory-field errors (#404), though
     * page already supplies content.
     */
    public function test_page_needs_only_the_editor_pseudofield(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'page', [
            'name' => 'Textseite 1',
            'page' => ['text' => '<p>Alpha</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
        ]);

        $after = $this->read($result['cmid']);
        $this->assertSame('<p>Alpha</p>', $after['content']);
    }

    /**
     * Accept page content as text as well as an editor array.
     * page_update_instance() reads page[text]; passing strings previously
     * created empty pages with success messages (#405, Claude cross-check).
     */
    public function test_page_accepts_plain_string_as_content(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'page', [
            'name' => 'Textseite',
            'page' => '<p>Inhalt als Text</p>',
        ]);

        $after = $this->read($result['cmid']);
        $this->assertSame('<p>Inhalt als Text</p>', $after['content']);
    }

    /**
     * Reject input that is neither text nor an object with text instead
     * of silently creating an empty page (#405).
     */
    public function test_editor_pseudofield_without_text_fails(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $before = $DB->count_records('page');

        try {
            $this->create($course->id, 0, 'page', [
                'name' => 'Textseite',
                'page' => ['format' => FORMAT_HTML],
            ]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('page', $e->getMessage());
        }
        $this->assertSame($before, $DB->count_records('page'), 'Nothing may have been created.');
    }

    /**
     * Report all missing mandatory fields without form defaults together
     * to prevent repeated guessing (#404).
     */
    public function test_all_missing_required_fields_are_named_at_once(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        try {
            $this->create($course->id, 0, 'page', []);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('name', $e->getMessage());
            $this->assertStringContainsString('page', $e->getMessage());
        }
    }

    /**
     * coursepagevisibility is read vocabulary, not a write field. Explain
     * the supported write path instead of reporting an unknown field (#404).
     */
    public function test_read_only_vocabulary_points_to_the_writable_field(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        try {
            $this->create($course->id, 0, 'label', [
                'intro' => 'Text',
                'coursepagevisibility' => 'stealth',
            ]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('coursepagevisibility', $e->getMessage());
            $this->assertStringContainsString('visibleoncoursepage', $e->getMessage());
            $this->assertStringNotContainsString('Unbekanntes Feld', $e->getMessage());
        }
    }

    /**
     * Creation also rejects date-pair combination-rule violations without
     * creating anything (Spec 0015 §3.6).
     */
    public function test_combination_rule_violation_fails_and_creates_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        try {
            $this->create($course->id, 0, 'forum', [
                'name' => 'Ankuendigungen',
                'intro' => 'Wichtige Hinweise',
                // cutoffdate before duedate violates the combination rule.
                'duedate' => 2000000000,
                'cutoffdate' => 1000000000,
            ]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('cutoffdate', $e->getMessage());
            $this->assertStringContainsString('duedate', $e->getMessage());
        }

        $this->assertEquals(0, $DB->count_records('forum', ['course' => $course->id]));
    }

    /**
     * Create page, label, URL, assignment, choice and forum activities
     * sequentially in an existing course.
     */
    public function test_all_catalogued_types_can_be_created_in_sequence(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->create($course->id, 0, 'page', [
            'name' => 'Textseite',
            'page' => ['text' => 'Seiteninhalt', 'format' => FORMAT_HTML, 'itemid' => 0],
        ]);
        $this->assertGreaterThan(0, $page['cmid']);

        $label = $this->create($course->id, 0, 'label', [
            'intro' => 'Textfeld-Inhalt',
        ]);
        $this->assertGreaterThan(0, $label['cmid']);

        $url = $this->create($course->id, 0, 'url', [
            'name' => 'Externer Link',
            'externalurl' => 'https://example.org/',
        ]);
        $this->assertGreaterThan(0, $url['cmid']);

        $assign = $this->create($course->id, 0, 'assign', [
            'name' => 'Aufgabe',
            'intro' => 'Aufgabenstellung',
        ]);
        $this->assertGreaterThan(0, $assign['cmid']);

        $choice = $this->create($course->id, 0, 'choice', [
            'name' => 'Abstimmung',
            'intro' => 'Bitte waehlen',
            'option' => ['Ja', 'Nein'],
            'allowupdate' => 1,
        ]);
        $this->assertGreaterThan(0, $choice['cmid']);

        $forum = $this->create($course->id, 0, 'forum', [
            'name' => 'Forum',
            'intro' => 'Diskussion',
        ]);
        $this->assertGreaterThan(0, $forum['cmid']);
    }

    /**
     * The localized creation response reports side effects, including
     * forcesubscribe=2 immediately subscribing all participants.
     */
    public function test_response_is_the_creation_message_including_side_effects(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'forum', [
            'name' => 'Ankuendigungen',
            'intro' => 'Wichtige Hinweise',
            'forcesubscribe' => 2,
        ]);

        $this->assertStringContainsString('Ankuendigungen', $result['message']);
        $this->assertNotEmpty($result['side_effects']);
        $this->assertStringContainsString('course participants', $result['side_effects'][0]);
        $this->assertStringContainsString('course participants', $result['message']);
    }

    /**
     * Clearly reject writes without native editing capability.
     */
    public function test_create_without_native_capability_fails(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'teacher');
        $this->setUser($nonedit);

        $this->expectException(\required_capability_exception::class);
        create_module::execute($course->id, 0, 'page', json_encode([
            'name' => 'Textseite',
            'page' => ['text' => 'x', 'format' => FORMAT_HTML, 'itemid' => 0],
        ]));
    }

    /**
     * Every creation records history version 1 through add_moduleinfo()’s
     * native course_module_created event (#385).
     */
    public function test_create_produces_a_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'label', ['intro' => 'Textfeld']);

        $version = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $result['cmid'], 'version' => 1]);
        $this->assertNotFalse($version);
    }

    /**
     * Use only add_moduleinfo(), never direct instance-table writes (ADR 0016).
     */
    public function test_source_never_writes_the_instance_table_directly(): void {
        $source = file_get_contents(__DIR__ . '/../../classes/catalog/write_target.php');
        $this->assertStringNotContainsString('$DB->update_record', $source);
        $this->assertStringNotContainsString('$DB->insert_record', $source);
        $this->assertStringContainsString('add_moduleinfo(', $source);
    }

    /**
     * Create stealth activities (visibleoncoursepage=0) with allowstealth
     * enabled and preserve idnumber (#390).
     */
    public function test_stealth_and_idnumber_can_be_set_on_create(): void {
        $this->resetAfterTest();
        set_config('allowstealth', 1);
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, 'page', [
            'name' => 'Versteckte Seite',
            'page' => ['text' => 'Inhalt', 'format' => FORMAT_HTML, 'itemid' => 0],
            'visibleoncoursepage' => 0,
            'idnumber' => 'kp-390-create',
        ]);

        $after = $this->read($result['cmid']);
        $this->assertSame(0, $after['visibleoncoursepage']);
        $this->assertSame('stealth', $after['coursepagevisibility']);
        $this->assertSame('kp-390-create', $after['idnumber']);
    }

    /**
     * With allowstealth off, reject visibleoncoursepage=0 without creation (#390).
     */
    public function test_stealth_on_create_fails_with_clear_message_when_allowstealth_is_off(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('allowstealth', 0);
        [$course] = $this->course_with_editing_teacher();

        $before = $DB->count_records('page', ['course' => $course->id]);

        try {
            $this->create($course->id, 0, 'page', [
                'name' => 'x',
                'page' => ['text' => 'Inhalt', 'format' => FORMAT_HTML, 'itemid' => 0],
                'visibleoncoursepage' => 0,
            ]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('allowstealth', $e->getMessage());
        }

        $this->assertSame($before, $DB->count_records('page', ['course' => $course->id]));
    }

    /**
     * Drift blocks only affected types: folder remains creatable while
     * page is blocked by simulated catalog drift (#399).
     */
    public function test_drift_blocks_only_the_affected_activity_type(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        \local_coursepilot\write_gate::all_statuses();
        set_config('driftviolations_page', json_encode(['Spalte "intro" fehlt.']), 'local_coursepilot');

        try {
            $this->create($course->id, 0, 'page', [
                'name' => 'x',
                'page' => ['text' => 'Inhalt', 'format' => FORMAT_HTML, 'itemid' => 0],
            ]);
            $this->fail('execute() should have thrown because of drift.');
        } catch (\moodle_exception $e) {
            // write_gate_test.php checks exact wording against the language pack.
            $this->assertSame('modnamedriftlocked', $e->errorcode);
        }

        // folder remains creatable; only page is blocked.
        $this->create($course->id, 0, 'folder', ['name' => 'x']);
        $this->addToAssertionCount(1);
    }

    /**
     * Reject attemptreopenmethod=manual without confirmation; create
     * with confirmation (#583).
     */
    public function test_assign_manual_reopen_needs_confirmation(): void {
        global $DB;

        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $felder = ['name' => 'Aufgabe', 'intro' => 'x', 'attemptreopenmethod' => 'manual'];

        try {
            $this->create($course->id, 0, 'assign', $felder);
            $this->fail('attemptreopenmethod=manual should have required confirmation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('learnerlocksunconfirmed', $e->errorcode);
            $this->assertStringContainsString('attemptreopenmethod', $e->getMessage());
            $this->assertStringContainsString('confirm_learner_locks', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('assign', ['course' => $course->id]));

        $result = external_api::clean_returnvalue(
            create_module::execute_returns(),
            create_module::execute(
                $course->id,
                0,
                'assign',
                json_encode($felder),
                \local_coursepilot\material_files::LOCATION_STORE,
                ['attemptreopenmethod']
            )
        );
        $this->assertSame('manual', $this->read($result['cmid'])['attemptreopenmethod']);
    }

    /**
     * Form defaults that impose learner restrictions also require
     * confirmation; choice.allowupdate defaults to 0 (#583).
     */
    public function test_choice_default_lock_counts_unless_named_open(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $felder = ['name' => 'Wahl', 'intro' => 'x', 'option' => ['A', 'B']];

        try {
            $this->create($course->id, 0, 'choice', $felder);
            $this->fail('Default guard allowupdate=0 should have been reported.');
        } catch (\moodle_exception $e) {
            $this->assertSame('learnerlocksunconfirmed', $e->errorcode);
            $this->assertStringContainsString('form default', $e->getMessage());
        }

        $result = $this->create($course->id, 0, 'choice', $felder + ['allowupdate' => 1]);
        $this->assertSame(1, (int) $this->read($result['cmid'])['allowupdate']);
    }
}
