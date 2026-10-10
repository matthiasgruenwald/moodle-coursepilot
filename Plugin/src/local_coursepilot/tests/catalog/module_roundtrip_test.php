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

namespace local_coursepilot\catalog;

use core_external\external_api;
use local_coursepilot\external\create_module;
use local_coursepilot\external\create_quiz;
use local_coursepilot\external\get_module_settings;
use local_coursepilot\external\update_module_settings;
use local_coursepilot\external\update_quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Round-trip contract for all nine cataloged module types (#556,
 * criterion 4): create, read, patch one field and read again, through
 * generic writers or dedicated quiz tools ({@see registry::for()}).
 * A tenth type needs only another {@see self::scenario()} branch.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(registry::class)]
final class module_roundtrip_test extends \advanced_testcase {
    /**
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
     * Create a material file like upload_material_file. See
     * {@see \local_coursepilot\external\create_module_test::create_material_file()}.
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
        \local_coursepilot\material_files::replace(null, $filerecord, $content);
    }

    private function create_via_module_tool(int $courseid, string $modname, array $felder): array {
        return external_api::clean_returnvalue(
            create_module::execute_returns(),
            create_module::execute($courseid, 0, $modname, json_encode($felder), \local_coursepilot\material_files::LOCATION_STORE)
        );
    }

    private function create_quiz_instance(int $courseid): array {
        $felder = [
            'name' => 'Rundlauf-Test',
            'intro' => 'Beschreibung',
            'subnet' => '',
            'browsersecurity' => '-',
            'preferredbehaviour' => 'deferredfeedback',
        ];
        return external_api::clean_returnvalue(
            create_quiz::execute_returns(),
            create_quiz::execute($courseid, 0, json_encode($felder), '', -1.0)
        );
    }

    /**
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

    private function patch(string $modname, int $cmid, array $felder): void {
        if ($modname === 'quiz') {
            external_api::clean_returnvalue(
                update_quiz_settings::execute_returns(),
                update_quiz_settings::execute($cmid, json_encode($felder))
            );
            return;
        }
        external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($cmid, json_encode($felder))
        );
    }

    /**
     * Create the requested activity type and identify a safe patch field,
     * using minimum fields from the corresponding create_* tests.
     *
     * @param string $modname
     * @param int $courseid
     * @return array{cmid: int, field: string, value: mixed}
     */
    private function scenario(string $modname, int $courseid): array {
        return match ($modname) {
            // label derives name from intro during creation (see label::fields()),
            // so intro is the only patchable field here.
            'label' => [
                'cmid' => $this->create_via_module_tool($courseid, 'label', [
                    'intro' => 'Ausgangstext',
                ])['cmid'],
                'field' => 'intro',
                'value' => 'Geaenderter Text',
            ],
            'page' => [
                'cmid' => $this->create_via_module_tool($courseid, 'page', [
                    'name' => 'Seite',
                    'page' => ['text' => 'Seiteninhalt', 'format' => FORMAT_HTML, 'itemid' => 0],
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neuer Seitentitel',
            ],
            'url' => [
                'cmid' => $this->create_via_module_tool($courseid, 'url', [
                    'name' => 'Externer Link',
                    'externalurl' => 'https://example.org/',
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neuer Linktitel',
            ],
            'folder' => [
                'cmid' => $this->create_via_module_tool($courseid, 'folder', [
                    'name' => 'Materialordner',
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neuer Ordnername',
            ],
            'resource' => (function () use ($courseid): array {
                $this->create_material_file('arbeitsblatt.pdf', 'Arbeitsblattinhalt');
                return [
                    'cmid' => $this->create_via_module_tool($courseid, 'resource', [
                        'name' => 'Datei',
                        'files' => ['arbeitsblatt.pdf'],
                    ])['cmid'],
                    'field' => 'name',
                    'value' => 'Neuer Dateiname',
                ];
            })(),
            'choice' => [
                'cmid' => $this->create_via_module_tool($courseid, 'choice', [
                    'name' => 'Abstimmung',
                    'intro' => 'Bitte waehlen',
                    'option' => ['Ja', 'Nein'],
                    'allowupdate' => 1,
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neue Abstimmung',
            ],
            'forum' => [
                'cmid' => $this->create_via_module_tool($courseid, 'forum', [
                    'name' => 'Forum',
                    'intro' => 'Diskussion',
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neuer Forumtitel',
            ],
            'assign' => [
                'cmid' => $this->create_via_module_tool($courseid, 'assign', [
                    'name' => 'Aufgabe',
                    'intro' => 'Aufgabenstellung',
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neue Aufgabe',
            ],
            'quiz' => [
                'cmid' => $this->create_quiz_instance($courseid)['cmid'],
                'field' => 'name',
                'value' => 'Neuer Testtitel',
            ],
            default => throw new \coding_exception("No scenario for module type {$modname}."),
        };
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modname_provider(): array {
        return array_combine(
            registry::known_modnames(),
            array_map(static fn (string $modname): array => [$modname], registry::known_modnames())
        );
    }

    /**
     * Every cataloged type completes create/read/patch/read and returns
     * the changed field’s new value (#556, criterion 4).
     */
    #[DataProvider('modname_provider')]
    public function test_round_trip_create_read_patch_read(string $modname): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $case = $this->scenario($modname, $course->id);
        $this->assertGreaterThan(0, $case['cmid'], "{$modname}: creating must return a cmid.");

        $before = $this->read($case['cmid']);
        $this->assertArrayHasKey($case['field'], $before, "{$modname}: patched field must be readable.");
        $this->assertNotSame($case['value'], $before[$case['field']], "{$modname}: initial value must not already be the target value.");

        $this->patch($modname, $case['cmid'], [$case['field'] => $case['value']]);

        $after = $this->read($case['cmid']);
        $this->assertSame($case['value'], $after[$case['field']], "{$modname}: patch must be visible when read again.");
    }

    /**
     * choice option resides in choice_options rather than the instance
     * row. Exercise this repeated group (Spec 0015 §2.2 category 2) through
     * create/read/patch/read; the name-only round trip does not cover it (#564).
     */
    public function test_choice_option_round_trip(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $cmid = $this->create_via_module_tool($course->id, 'choice', [
            'name' => 'Abstimmung',
            'intro' => 'Bitte waehlen',
            'option' => ['Ja', 'Nein'],
            'allowupdate' => 1,
            'limit' => [2, 3],
        ])['cmid'];

        $before = $this->read($cmid);
        $this->assertSame(['Ja', 'Nein'], $before['option'], 'Created options must be visible when read.');
        $this->assertSame([2, 3], array_map('intval', $before['limit']), 'Configured limits must be visible when read.');
        $this->assertCount(2, $before['optionid'], 'Existing choice_options IDs must be visible when read.');

        // Supply optionid; otherwise choice_update_instance() adds options
        // instead of replacing them. See choice::pseudofields(), matching
        // mod_choice_mod_form::data_preprocessing() in the native form lifecycle.
        $this->patch('choice', $cmid, [
            'option' => ['Vielleicht', 'Auf jeden Fall'],
            'limit' => [4, 5],
            'optionid' => $before['optionid'],
        ]);

        $after = $this->read($cmid);
        $this->assertSame(
            ['Vielleicht', 'Auf jeden Fall'],
            $after['option'],
            'Changed options must be visible when read again.'
        );
        $this->assertSame([4, 5], array_map('intval', $after['limit']), 'Changed limits must be visible when read again.');
    }

    /**
     * The registry is the complete type list. Fail when a new catalog lacks
     * a scenario, preserving coverage of all types.
     */
    public function test_every_registered_modname_has_a_scenario(): void {
        foreach (registry::known_modnames() as $modname) {
            $this->assertIsArray(
                self::modname_provider()[$modname] ?? null,
                "Modultyp {$modname} fehlt im Rundlauf-Vertragstest."
            );
        }
    }
}
