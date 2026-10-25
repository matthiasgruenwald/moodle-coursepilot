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

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact translation, validation and IServ rules of the context pointer document (Spec #486 §2).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(context_pointer::class)]
final class context_pointer_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * An external target.
     *
     * @param mixed[] $overrides
     * @return mixed[]
     */
    private function external(array $overrides = []): array {
        return array_replace_recursive([
            'location' => 'external',
            'instanceid' => 7,
            'path' => 'Ctx',
            'fingerprint' => ['server' => 'cloud.example.test', 'basepath' => 'Base', 'account' => 'alice'],
        ], $overrides);
    }

    /**
     * A Moodle target.
     *
     * @param string $path
     * @return mixed[]
     */
    private function moodle(string $path): array {
        return ['location' => 'moodle', 'path' => $path];
    }

    public function test_legacy_keys_are_translated_everywhere_but_values_only_under_location_and_target(): void {
        $decoded = [
            'kontextbereich' => [
                'ort' => 'extern',
                'pfad' => 'extern',
                'instanzid' => 3,
                'pruefmerkmal' => ['basispfad' => 'b', 'konto' => 'a'],
            ],
            'ortsverlauf' => [['datum' => 'd', 'ziel' => 'materialbestand', 'von' => 'kontextbereich', 'nach' => 'extern']],
        ];

        $this->assertSame([
            'context_area' => [
                'location' => 'external',
                'path' => 'extern',
                'instanceid' => 3,
                'fingerprint' => ['basepath' => 'b', 'account' => 'a'],
            ],
            'location_history' => [
                ['date' => 'd', 'target' => 'material_store', 'from_text' => 'kontextbereich', 'to_text' => 'extern'],
            ],
        ], context_pointer::normalise($decoded));
        $this->assertSame(['location' => 'external', 5 => 'x'], context_pointer::normalise(['location' => 'external', 5 => 'x']));
    }

    /**
     * Provides paths and their validated form.
     *
     * @return array<string, array{string, string}>
     */
    public static function good_paths(): array {
        return [
            'plain' => ['a/b', 'a/b'],
            'surrounding slashes' => ['/a/b/', 'a/b'],
            'backslashes' => ['a\\b', 'a/b'],
            'mixed' => ['\\a\\b/', 'a/b'],
        ];
    }

    #[DataProvider('good_paths')]
    public function test_a_path_is_trimmed_and_normalised(string $path, string $expected): void {
        $this->assertSame($expected, context_pointer::validate_path($path));
    }

    /**
     * Provides paths that are refused and the error they get.
     *
     * @return array<string, array{string, string}>
     */
    public static function bad_paths(): array {
        return [
            'empty' => ['', 'pointerincomplete'],
            'only slashes' => ['///', 'pointerincomplete'],
            'only backslashes' => ['\\\\', 'pointerincomplete'],
            'empty segment' => ['a//b', 'pointerunreachable'],
            'dot segment' => ['a/./b', 'pointerunreachable'],
            'parent segment' => ['a/../b', 'pointerunreachable'],
        ];
    }

    #[DataProvider('bad_paths')]
    public function test_unusable_paths_are_refused_with_their_pointer_error(string $path, string $errorcode): void {
        $e = $this->caught(fn() => context_pointer::validate_path($path));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame($errorcode, $e->errorcode);
        $this->assertSame(storage_anchor::POINTER_FILENAME, $e->a);
    }

    public function test_an_external_target_becomes_a_location_with_a_boolean_iserv_flag(): void {
        $document = ['context_area' => $this->external(), 'material_store' => $this->moodle('Material')];

        $location = context_pointer::resolve_target($document, 'context_area');

        $this->assertSame(pointer_location::EXTERNAL, $location->kind);
        $this->assertSame(7, $location->instanceid);
        $this->assertSame('Ctx', $location->relativepath);
        $this->assertSame(
            ['server' => 'cloud.example.test', 'basepath' => 'Base', 'account' => 'alice', 'iserv' => false],
            $location->fingerprint
        );
        $store = context_pointer::resolve_target($document, 'material_store');
        $this->assertSame('/Material/', $store->path);
        $iserv = context_pointer::resolve_target(
            [
                'context_area' => $this->external(['path' => 'Files/x', 'fingerprint' => ['iserv' => 1]]),
                'material_store' => $this->moodle('M'),
            ],
            'context_area'
        );
        $this->assertTrue($iserv->fingerprint['iserv']);
    }

    /**
     * Provides external targets that are incomplete.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function incomplete_externals(): array {
        return [
            'no instance' => [['instanceid' => null]],
            'instance not numeric' => [['instanceid' => 'abc']],
            'instance zero' => [['instanceid' => 0]],
            'instance negative' => [['instanceid' => -2]],
            'path missing' => [['path' => null]],
            'path not a string' => [['path' => 5]],
            'fingerprint not an array' => [['fingerprint' => 'x']],
            'server not a string' => [['fingerprint' => ['server' => 5]]],
            'basepath not a string' => [['fingerprint' => ['basepath' => 5]]],
            'account not a string' => [['fingerprint' => ['account' => 5]]],
        ];
    }

    /**
     * Incomplete external targets are refused.
     *
     * @param array<string, mixed> $override Part of the target to break.
     */
    #[DataProvider('incomplete_externals')]
    public function test_an_incomplete_external_target_is_refused(array $override): void {
        $target = array_replace($this->external(), $override);
        if (isset($override['fingerprint']) && is_array($override['fingerprint'])) {
            $target['fingerprint'] = array_replace($this->external()['fingerprint'], $override['fingerprint']);
        }

        $e = $this->caught(fn() => context_pointer::resolve_target(
            ['context_area' => $target, 'material_store' => $this->moodle('M')],
            'context_area'
        ));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('pointerincomplete', $e->errorcode);
    }

    public function test_an_iserv_instance_is_reachable_only_below_files_and_the_error_names_page_host_and_instance(): void {
        $document = [
            'context_area' => $this->external(['path' => 'Other/x', 'fingerprint' => ['iserv' => true]]),
            'material_store' => $this->moodle('M'),
        ];

        $e = $this->caught(fn() => context_pointer::resolve_target($document, 'material_store'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('webdaviservfilesonly', $e->errorcode);
        $this->assertEquals((object) [
            'page' => \local_coursepilot\webdav\webdav_setup_steps::LOCATION_SELECTION_PAGE,
            'server' => 'cloud.example.test',
            'instanceid' => 7,
        ], $e->a);
        $this->assertSame(7, $e->a->instanceid);
        $ok = context_pointer::resolve_target([
            'context_area' => $this->external(['path' => 'Files/x', 'fingerprint' => ['iserv' => true]]),
            'material_store' => $this->moodle('M'),
        ], 'material_store');
        $this->assertSame('/M/', $ok->path);
        $plain = context_pointer::resolve_target([
            'context_area' => $this->external(['path' => 'Other/x']),
            'material_store' => $this->moodle('M'),
        ], 'material_store');
        $this->assertSame('/M/', $plain->path);
    }

    public function test_the_previous_location_follows_the_iserv_rule_but_not_the_nesting_rule(): void {
        $bad = $this->caught(fn() => context_pointer::resolve_previous(
            $this->external(['path' => 'Other', 'fingerprint' => ['iserv' => true]])
        ));
        $this->assertInstanceOf(\moodle_exception::class, $bad);
        $this->assertSame('webdaviservfilesonly', $bad->errorcode);

        $plain = context_pointer::resolve_previous($this->external(['path' => 'Other']));
        $this->assertSame('Other', $plain->relativepath);
        $this->assertFalse($plain->fingerprint['iserv']);
        $files = context_pointer::resolve_previous($this->external(['path' => 'Files/Ctx', 'fingerprint' => ['iserv' => true]]));
        $this->assertSame('Files/Ctx', $files->relativepath);
    }

    public function test_a_material_store_inside_the_context_area_is_refused(): void {
        $e = $this->caught(fn() => context_pointer::resolve_target(
            ['context_area' => $this->moodle('A'), 'material_store' => $this->moodle('A/B')],
            'context_area'
        ));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialstoreincontext', $e->errorcode);
    }

    public function test_a_legacy_pointer_resolves_both_moodle_folders_and_needs_both(): void {
        $legacy = ['context_area' => '/Ctx/', 'material_store' => 'Mat'];

        $this->assertSame('/Ctx/', context_pointer::resolve_target($legacy, 'context_area')->path);
        $this->assertSame('/Mat/', context_pointer::resolve_target($legacy, 'material_store')->path);
        $e = $this->caught(
            fn() => context_pointer::resolve_target(['context_area' => 'Ctx', 'material_store' => 5], 'context_area')
        );
        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('pointerincomplete', $e->errorcode);
    }

    public function test_both_targets_of_a_v2_pointer_must_be_objects_with_a_known_location(): void {
        $documents = [
            ['context_area' => $this->moodle('A')],
            ['context_area' => $this->moodle('A'), 'material_store' => 'text'],
            ['context_area' => $this->moodle('A'), 'material_store' => ['location' => 'nowhere']],
            ['context_area' => $this->moodle('A'), 'material_store' => ['location' => 'moodle', 'path' => 5]],
        ];
        foreach ($documents as $document) {
            $e = $this->caught(fn() => context_pointer::resolve_target($document, 'context_area'));
            $this->assertInstanceOf(\moodle_exception::class, $e, json_encode($document));
            $this->assertSame('pointerincomplete', $e->errorcode);
        }
    }
}
