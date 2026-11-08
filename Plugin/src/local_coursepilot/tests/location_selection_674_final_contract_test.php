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

use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exact error keys, browse ordering and history records of location selection (#674, Welle 2).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(location_selection::class)]
final class location_selection_674_final_contract_test extends \advanced_testcase {
    use webdav_instance_fixture;

    /**
     * Creates an owned instance with fake transport and the instance base folder.
     *
     * @return array{0: \stdClass, 1: int, 2: fake_webdav_transport}
     */
    private function prepare(): array {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);

        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        return [$user, $instanceid, $fake];
    }

    /**
     * Runs apply() and returns the exception errorcode.
     *
     * @param mixed[] $selection
     * @return string
     */
    private function apply_errorcode(array $selection): string {
        try {
            location_selection::apply($selection);
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
        $this->fail('Expected a moodle_exception.');
    }

    /**
     * Browse sorts folders naturally, lists all entries and previews only five names.
     */
    public function test_browse_sorts_folders_and_limits_name_preview(): void {
        $this->resetAfterTest();
        [, $instanceid, $fake] = $this->prepare();
        $base = '/' . $this->fixturebasispfad;
        $fake->seed_folder($base . '/b10');
        $fake->seed_folder($base . '/B2');
        $fake->seed_folder($base . '/a1');
        foreach (['f1', 'f2', 'f3', 'f4', 'f5', 'f6'] as $name) {
            $fake->seed_file($base . '/' . $name . '.txt', 'x');
        }

        $result = location_selection::browse($instanceid, '');

        $this->assertSame(['a1', 'B2', 'b10'], array_column($result['folders'], 'name'));
        $this->assertSame(9, $result['entrycount']);
        $this->assertSame(['a1', 'B2', 'b10', 'f1.txt', 'f2.txt'], $result['entrynames']);
    }

    /**
     * Backslashes, leading and trailing slashes are normalised; empty segments are skipped.
     */
    public function test_browse_normalises_separators_and_empty_segments(): void {
        $this->resetAfterTest();
        [, $instanceid, $fake] = $this->prepare();
        $base = '/' . $this->fixturebasispfad;
        $fake->seed_folder($base . '/A');
        $fake->seed_folder($base . '/A/B');
        $fake->seed_folder($base . '/A/B/C');

        $this->assertSame('A/B', location_selection::browse($instanceid, '\\A\\B')['path']);
        $this->assertSame('A/B', location_selection::browse($instanceid, '/A/B/')['path']);
        $this->assertSame('A/B/C', location_selection::browse($instanceid, 'A//B/C')['path']);
        $this->assertSame('A/B', location_selection::browse($instanceid, 'A//B')['path']);
    }

    /**
     * Dot segments are rejected with the exact path error.
     */
    public function test_browse_rejects_dot_segments_with_exact_key(): void {
        $this->resetAfterTest();
        [, $instanceid] = $this->prepare();

        try {
            location_selection::browse($instanceid, 'A/../B');
            $this->fail('invalidcontextpath expected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidcontextpath', $e->errorcode);
        }
    }

    /**
     * The instance root is reported as not selectable with its reason key.
     */
    public function test_browse_root_reports_not_selectable_reason(): void {
        $this->resetAfterTest();
        [, $instanceid] = $this->prepare();

        $result = location_selection::browse($instanceid, '');

        $this->assertFalse($result['selectable']);
        $this->assertSame('locationselectionrootnotselectable', $result['reasonkey']);
        $this->assertSame('', $result['path']);
    }

    /**
     * Instance id zero or non-numeric is an invalid selection, not a missing instance.
     */
    public function test_apply_rejects_non_positive_or_non_numeric_instance_id(): void {
        $this->resetAfterTest();
        $this->prepare();

        $this->assertSame('locationselectionselectioninvalid', $this->apply_errorcode([
            'context_area' => ['type' => 'external', 'instanceid' => 0, 'path' => 'Kontext'],
            'material_store' => ['type' => 'moodle'],
        ]));
        $this->assertSame('locationselectionselectioninvalid', $this->apply_errorcode([
            'context_area' => ['type' => 'external', 'instanceid' => 'abc', 'path' => 'Kontext'],
            'material_store' => ['type' => 'moodle'],
        ]));
    }

    /**
     * Foreign and missing instance ids are rejected with their exact error keys.
     */
    public function test_apply_rejects_foreign_and_missing_instance_with_exact_keys(): void {
        $this->resetAfterTest();
        $this->prepare();
        $other = $this->getDataGenerator()->create_user();
        $foreignid = $this->create_webdav_instance($other);

        $this->assertSame('webdavinstanceforeign', $this->apply_errorcode([
            'context_area' => ['type' => 'external', 'instanceid' => $foreignid, 'path' => 'Kontext'],
            'material_store' => ['type' => 'moodle'],
        ]));
        $this->assertSame('webdavinstancemissing', $this->apply_errorcode([
            'context_area' => ['type' => 'external', 'instanceid' => $foreignid + 999, 'path' => 'Kontext'],
            'material_store' => ['type' => 'moodle'],
        ]));
    }

    /**
     * External locations are described with instance name and relative path.
     */
    public function test_describe_location_names_instance_and_path(): void {
        $this->resetAfterTest();
        [, $instanceid] = $this->prepare();

        $text = location_selection::describe_location([
            'location' => 'external',
            'instanceid' => $instanceid,
            'path' => 'Kontext/Unter',
        ]);
        $this->assertStringContainsString('Meine Cloud', $text);
        $this->assertStringContainsString('Kontext/Unter', $text);
    }

    /**
     * An unknown instance id reports the webdav instance error key.
     */
    public function test_apply_reports_missing_instance_key(): void {
        $this->resetAfterTest();
        $this->prepare();

        $this->assertSame('webdavinstancemissing', $this->apply_errorcode([
            'context_area' => ['type' => 'external', 'instanceid' => 999999, 'path' => 'Kontext'],
            'material_store' => ['type' => 'moodle'],
        ]));
    }

    /**
     * The instance root is never selectable on apply, with and without a slash.
     */
    public function test_apply_rejects_instance_root_with_exact_key(): void {
        $this->resetAfterTest();
        [, $instanceid] = $this->prepare();

        foreach (['', '/'] as $path) {
            $this->assertSame('locationselectionrootnotselectable', $this->apply_errorcode([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => $path],
                'material_store' => ['type' => 'moodle'],
            ]));
        }
    }

    /**
     * IServ locks every folder outside Files/ with the exact key; Files/ stays selectable.
     */
    public function test_apply_iserv_outside_files_uses_exact_key_and_files_is_allowed(): void {
        $this->resetAfterTest();
        [, $instanceid, $fake] = $this->prepare();
        $fake->as_iserv_root('/' . $this->fixturebasispfad);

        $this->assertSame('locationselectioniservfilesonly', $this->apply_errorcode([
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Groups'],
            'material_store' => ['type' => 'moodle'],
        ]));

        $fake->seed_folder('/' . $this->fixturebasispfad . '/Files');
        $changed = location_selection::apply([
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Files/Kontext'],
            'material_store' => ['type' => 'moodle'],
        ]);
        $this->assertSame(['context_area'], $changed);
        $pointer = storage_anchor::read_raw_pointer();
        $this->assertTrue($pointer['context_area']['fingerprint']['iserv']);
    }

    /**
     * The saved fingerprint always carries the iserv flag, false on a plain WebDAV root.
     */
    public function test_apply_saves_fingerprint_with_iserv_flag(): void {
        $this->resetAfterTest();
        [, $instanceid] = $this->prepare();

        location_selection::apply([
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Kontext'],
            'material_store' => ['type' => 'moodle'],
        ]);

        $fingerprint = storage_anchor::read_raw_pointer()['context_area']['fingerprint'];
        $this->assertArrayHasKey('iserv', $fingerprint);
        $this->assertFalse($fingerprint['iserv']);
        $this->assertSame($instanceid, storage_anchor::read_raw_pointer()['context_area']['instanceid']);
    }

    /**
     * Material storage inside the context area is rejected before any write,
     * while a sibling folder with a shared name prefix is accepted.
     */
    public function test_material_inside_context_is_rejected_but_prefix_sibling_is_allowed(): void {
        $this->resetAfterTest();
        [, $instanceid] = $this->prepare();

        $this->assertSame('materialstoreincontext', $this->apply_errorcode([
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Kontext'],
            'material_store' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Kontext/Material'],
        ]));
        $this->assertNull(storage_anchor::read_raw_pointer());

        $changed = location_selection::apply([
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Kontext'],
            'material_store' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Kontext2'],
        ]);
        $this->assertSame(['context_area', 'material_store'], $changed);
    }

    /**
     * A populated context folder needs explicit confirmation; an empty one does not.
     */
    public function test_populated_context_folder_needs_confirmation_and_empty_does_not(): void {
        $this->resetAfterTest();
        [, $instanceid, $fake] = $this->prepare();
        $base = '/' . $this->fixturebasispfad;
        $fake->seed_folder($base . '/Leer');
        $fake->seed_folder($base . '/Voll');
        $fake->seed_file($base . '/Voll/notiz.md', 'x');

        $this->assertSame('locationselectionfolderconfirmrequired', $this->apply_errorcode([
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Voll'],
            'material_store' => ['type' => 'moodle'],
        ]));
        $this->assertNull(storage_anchor::read_raw_pointer());

        $changed = location_selection::apply([
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Leer'],
            'material_store' => ['type' => 'moodle'],
        ]);
        $this->assertSame(['context_area'], $changed);
    }

    /**
     * An explicit confirmation allows a populated handover and records it.
     */
    public function test_confirmed_handover_is_saved_with_history(): void {
        $this->resetAfterTest();
        [, $instanceid, $fake] = $this->prepare();
        $base = '/' . $this->fixturebasispfad;
        $fake->seed_folder($base . '/Voll');
        $fake->seed_file($base . '/Voll/notiz.md', 'x');

        $changed = location_selection::apply([
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Voll', 'confirmed' => true],
            'material_store' => ['type' => 'moodle'],
        ]);

        $this->assertSame(['context_area'], $changed);
        $this->assertSame('Voll', storage_anchor::read_raw_pointer()['context_area']['path']);
    }

    /**
     * Only the changed target gets a history entry, with exact from/to and the
     * current time; an unchanged target before it must not stop the recording.
     */
    public function test_history_records_second_target_after_unchanged_first(): void {
        $this->resetAfterTest();
        [$user, $instanceid] = $this->prepare();

        location_selection::apply([
            'context_area' => ['type' => 'moodle'],
            'material_store' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Material'],
        ]);

        $history = location_selection::history();
        $this->assertCount(1, $history);
        $entry = $history[0];
        $this->assertSame('material_store', $entry['target']);
        $this->assertEqualsWithDelta(time(), $entry['date'], 10);
        $this->assertSame('moodle', $entry['from']['location']);
        $this->assertSame('coursepilot-material', $entry['from']['path']);
        $this->assertSame('external', $entry['to']['location']);
        $this->assertSame($instanceid, $entry['to']['instanceid']);
        $this->assertSame('Material', $entry['to']['path']);
    }

    /**
     * Without a real change the pointer stays untouched (no file written).
     */
    public function test_unchanged_selection_writes_no_pointer(): void {
        $this->resetAfterTest();
        $this->prepare();

        $this->assertSame([], location_selection::apply([
            'context_area' => ['type' => 'moodle'],
            'material_store' => ['type' => 'moodle'],
        ]));
        $this->assertNull(storage_anchor::read_raw_pointer());
    }

    /**
     * History entries without a stored target report an empty target string.
     */
    public function test_history_entry_without_target_reports_empty_string(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [['date' => 1700000005, 'from' => 'a', 'to' => 'b']],
        ]);

        $history = location_selection::page_state((int) $user->id)['history'];

        $this->assertSame(['at' => 1700000005, 'target' => '', 'from' => 'a', 'to' => 'b'], $history[0]);
    }

    /**
     * Page steps map completion to exact states and the browse block keeps its keys.
     */
    public function test_page_state_step_and_browse_states_are_exact(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $state = location_selection::page_state((int) $user->id);
        $this->assertSame(['state' => 'idle'], $state['browse']);
        foreach (\local_coursepilot\webdav\webdav_setup_steps::catalog((int) $user->id) as $key => $step) {
            $this->assertSame(
                ['state' => $step['ok'] ? 'complete' : 'missing'],
                $state['webdav']['steps'][$key]
            );
        }

        $browse = ['path' => 'A', 'folders' => [], 'iserv' => false, 'selectable' => true,
            'reasonkey' => null, 'entrycount' => 0, 'entrynames' => []];
        $state = location_selection::page_state((int) $user->id, $browse);
        $this->assertSame(['state' => 'ready'] + $browse, $state['browse']);
    }

    /**
     * Own instances carry an integer id and a string name.
     */
    public function test_own_instances_have_int_id_and_string_name(): void {
        $this->resetAfterTest();
        [$user, $instanceid] = $this->prepare();

        $instances = location_selection::own_instances();

        $this->assertSame($instanceid, $instances[0]['id']);
        $this->assertIsInt($instances[0]['id']);
        $this->assertNotSame('', $instances[0]['name']);
    }

    /**
     * Context detection: a folder always counts, a file only with a case-insensitive .md suffix.
     *
     * @dataProvider context_file_provider
     * @param string $name
     * @param string $type
     * @param bool $hascontext
     */
    public function test_has_context_file_detects_by_type_and_markdown_suffix(
        string $name,
        string $type,
        bool $hascontext
    ): void {
        $this->resetAfterTest();
        $method = new \ReflectionMethod(location_selection::class, 'has_context_file');
        $method->setAccessible(true);

        $this->assertSame($hascontext, $method->invoke(null, [['name' => $name, 'type' => $type]]));
    }

    /**
     * Context detection cases for has_context_file().
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function context_file_provider(): array {
        return [
            'markdown lower case' => ['notiz.md', 'file', true],
            'markdown upper case' => ['NOTIZ.MD', 'file', true],
            'markdown suffix in middle' => ['notiz.md.txt', 'file', false],
            'text file' => ['notiz.txt', 'file', false],
            'folder with any name' => ['Unterricht', 'folder', true],
        ];
    }

    /**
     * A history entry with a numeric target is reported as a string.
     */
    public function test_history_entry_numeric_target_is_reported_as_string(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [['date' => 1700000005, 'target' => 42, 'from' => 'a', 'to' => 'b']],
        ]);

        $history = location_selection::page_state((int) $user->id)['history'];

        $this->assertSame('42', $history[0]['target']);
    }

    /**
     * The current target carries its human readable display text and allowed flag.
     */
    public function test_current_target_contains_display_and_allowed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $current = location_selection::current('context_area');

        $this->assertArrayHasKey('display', $current);
        $this->assertSame(
            get_string('locationselectionlocationmoodle', 'local_coursepilot', $current['path']),
            $current['display']
        );
        $this->assertTrue($current['allowed']);
    }

    /**
     * Ownership of the instance is checked before the path and the instance root.
     */
    public function test_apply_checks_instance_ownership_before_path_validation(): void {
        $this->resetAfterTest();
        $this->prepare();
        $other = $this->getDataGenerator()->create_user();
        $foreignid = $this->create_webdav_instance($other);

        foreach (['', '../Kontext'] as $path) {
            $this->assertSame('webdavinstanceforeign', $this->apply_errorcode([
                'context_area' => ['type' => 'external', 'instanceid' => $foreignid, 'path' => $path],
                'material_store' => ['type' => 'moodle'],
            ]));
            $this->assertSame('webdavinstancemissing', $this->apply_errorcode([
                'context_area' => ['type' => 'external', 'instanceid' => $foreignid + 999, 'path' => $path],
                'material_store' => ['type' => 'moodle'],
            ]));
        }
    }

    /**
     * Folders with the same relative path on different servers do not overlap.
     */
    public function test_same_relative_path_on_different_servers_is_no_overlap(): void {
        $this->resetAfterTest();
        [$user, $instanceid, $fake] = $this->prepare();
        $secondid = $this->create_webdav_instance($user, ['webdav_server' => 'other.example.test', 'webdav_path' => 'Zwei']);
        $fake->seed_folder('/Zwei');

        $changed = location_selection::apply([
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Kontext'],
            'material_store' => ['type' => 'external', 'instanceid' => $secondid, 'path' => 'Kontext/Material'],
        ]);

        $this->assertSame(['context_area', 'material_store'], $changed);
    }
}
