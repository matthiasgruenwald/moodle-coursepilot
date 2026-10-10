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

/**
 * Material-storage paths, constants, whitelists and quotas (Spec 0018
 * §2/§6/§8.1, #428), complementing tests/context_files_test.php.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(material_files::class)]
final class material_files_test extends \advanced_testcase {
    public function test_resolve_directory_defaults_to_root(): void {
        $this->resetAfterTest();
        $this->assertSame('/coursepilot-material/', material_files::resolve_directory(''));
    }

    public function test_resolve_directory_builds_subpath(): void {
        $this->resetAfterTest();
        $this->assertSame('/coursepilot-material/faecher/mathe/', material_files::resolve_directory('faecher/mathe'));
    }

    public function test_resolve_directory_rejects_dotdot_segment(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_directory('faecher/../../../etc');
    }

    public function test_resolve_directory_rejects_single_dot_segment(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_directory('./faecher');
    }

    public function test_resolve_file_splits_directory_and_filename(): void {
        $this->resetAfterTest();
        [$directory, $filename] = material_files::resolve_file('screenshot.png');
        $this->assertSame('/coursepilot-material/', $directory);
        $this->assertSame('screenshot.png', $filename);

        [$directory, $filename] = material_files::resolve_file('faecher/mathe/blatt.pdf');
        $this->assertSame('/coursepilot-material/faecher/mathe/', $directory);
        $this->assertSame('blatt.pdf', $filename);
    }

    public function test_resolve_file_rejects_traversal(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_file('../secret.txt');
    }

    public function test_resolve_file_rejects_empty_path(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_file('');
    }

    /**
     * Use Moodle Private Files like context storage (Spec 0018 §2.1), but
     * with dedicated constants. Endpoints never address COMPONENT/FILEAREA/ITEMID directly.
     */
    public function test_storage_anchor_is_private_files(): void {
        $this->assertSame('user', material_files::COMPONENT);
        $this->assertSame('private', material_files::FILEAREA);
        $this->assertSame(0, material_files::ITEMID);
    }

    /**
     * The alternate-location proof (#444) now lives in storage_anchor_test
     * after unifying both areas through storage_anchor. It exercises a third
     * test-only area rather than merely comparing constants.
     */
    public function test_root_defaults_to_coursepilot_material(): void {
        $this->resetAfterTest();
        $this->assertSame('coursepilot-material', get_config('local_coursepilot', 'materialroot') ?: 'coursepilot-material');
    }

    /**
     * Both whitelists from Spec 0018 §6 include SVG.
     */
    public function test_allowed_extensions_cover_both_whitelists(): void {
        $extensions = material_files::allowed_extensions();
        foreach (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'html', 'png', 'jpg'] as $general) {
            $this->assertContains($general, $extensions, $general . ' is missing from the general whitelist.');
        }
        foreach (['png', 'jpg', 'gif', 'svg', 'webp'] as $embeddable) {
            $this->assertContains($embeddable, $extensions, $embeddable . ' is missing from the image whitelist.');
        }
    }

    public function test_is_allowed_extension_accepts_svg(): void {
        $this->assertTrue(material_files::is_allowed_extension('diagramm.svg'));
    }

    public function test_is_allowed_extension_rejects_unknown_type(): void {
        $this->assertFalse(material_files::is_allowed_extension('programm.exe'));
    }

    public function test_resolve_writable_file_accepts_allowed_extension(): void {
        $this->resetAfterTest();
        [$directory, $filename] = material_files::resolve_writable_file('screenshot.png');
        $this->assertSame('/coursepilot-material/', $directory);
        $this->assertSame('screenshot.png', $filename);
    }

    public function test_resolve_writable_file_rejects_disallowed_extension(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_writable_file('programm.exe');
    }

    public function test_resolve_writable_file_rejects_bad_folder_segment(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_writable_file('mit leerzeichen/screenshot.png');
    }

    /**
     * Calculate remaining user quota independently of the root, as in
     * context_files::remaining_quota().
     */
    public function test_remaining_quota_reports_free_space(): void {
        global $CFG;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $CFG->userquota = 1000;

        $this->assertSame(1000, material_files::remaining_quota());

        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot-material/',
            'filename' => 'gross.pdf',
        ], str_repeat('x', 400));

        $this->assertSame(600, material_files::remaining_quota());
    }

    public function test_remaining_quota_is_null_without_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 0;

        $this->assertNull(material_files::remaining_quota());
    }

    /**
     * A full or exceeded quota is a hard error (Spec 0018 §8.1).
     */
    public function test_require_quota_throws_when_exceeded(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        $this->expectException(\moodle_exception::class);
        material_files::require_quota(1001);
    }

    public function test_require_quota_passes_when_within_limit(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        material_files::require_quota(1000);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Zero remaining quota rejects any positive growth (Spec 0018 §8.1).
     */
    public function test_require_quota_throws_when_quota_is_full(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 500;
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot-material/',
            'filename' => 'voll.pdf',
        ], str_repeat('x', 500));

        $this->expectException(\moodle_exception::class);
        material_files::require_quota(1);
    }

    /**
     * Warn below 10% remaining quota and report MB (Spec 0018 §8.1,
     * matching Spec 0016 §5.4).
     */
    public function test_quota_warning_below_ten_percent(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        // After writing, 950 of 1000 bytes are used (5% remaining).
        $warning = material_files::quota_warning(950);
        $this->assertNotNull($warning);
        $this->assertStringContainsString('0.0', $warning);
    }

    public function test_quota_warning_is_null_with_enough_room(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        $this->assertNull(material_files::quota_warning(100));
    }

    public function test_quota_warning_is_null_without_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 0;

        $this->assertNull(material_files::quota_warning(100));
    }

    public function test_require_manage_own_files_passes_for_standard_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        material_files::require_manage_own_files();
        $this->expectNotToPerformAssertions();
    }

    public function test_require_manage_own_files_rejects_user_without_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, $user->id, \context_system::instance()->id);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_system::instance()->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        material_files::require_manage_own_files();
    }

    public function test_own_context_follows_current_user(): void {
        global $USER;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertEquals(\context_user::instance($user->id)->id, material_files::own_context()->id);
        $this->assertSame((int) $user->id, (int) $USER->id);
    }

    /**
     * context_files already tests replacement ordering and temporary files;
     * verify normal delegation here.
     */
    public function test_replace_creates_new_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $contextid = material_files::own_context()->id;

        material_files::replace(
            null,
            material_files::filerecord($contextid, '/coursepilot-material/', 'neu.pdf'),
            'Inhalt'
        );

        $stored = get_file_storage()->get_file(
            $contextid,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/coursepilot-material/',
            'neu.pdf'
        );
        $this->assertNotFalse($stored);
        $this->assertSame('Inhalt', $stored->get_content());
    }

    /**
     * Referencing material (Spec 0018 §4.2, #429) copies it into a file-manager
     * draft used directly as an *_update_instance() field value.
     */
    public function test_resolve_into_draft_copies_material_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );
        $targetcontextid = \context_system::instance()->id;

        $draftitemid = material_files::resolve_into_draft(
            $targetcontextid,
            'mod_assign',
            'introattachment',
            0,
            ['blatt.pdf']
        );

        $draftfile = get_file_storage()->get_file(
            material_files::own_context()->id,
            'user',
            'draft',
            $draftitemid,
            '/',
            'blatt.pdf'
        );
        $this->assertNotFalse($draftfile);
        $this->assertSame('Arbeitsblattinhalt', $draftfile->get_content());
    }

    /**
     * Preserve existing target attachments; references append rather than
     * replace (Spec 0018 §4.2).
     */
    public function test_resolve_into_draft_preserves_existing_target_files(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'neu.pdf'),
            'neuer Inhalt'
        );
        $targetcontextid = \context_system::instance()->id;
        get_file_storage()->create_file_from_string([
            'contextid' => $targetcontextid,
            'component' => 'mod_assign',
            'filearea' => 'introattachment',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'schon-da.pdf',
        ], 'alter Inhalt');

        $draftitemid = material_files::resolve_into_draft(
            $targetcontextid,
            'mod_assign',
            'introattachment',
            0,
            ['neu.pdf']
        );

        $fs = get_file_storage();
        $usercontextid = material_files::own_context()->id;
        $this->assertNotFalse($fs->get_file($usercontextid, 'user', 'draft', $draftitemid, '/', 'schon-da.pdf'));
        $this->assertNotFalse($fs->get_file($usercontextid, 'user', 'draft', $draftitemid, '/', 'neu.pdf'));
    }

    /**
     * Reject missing material files with a message naming the expected path
     * (#429).
     */
    public function test_resolve_into_draft_throws_when_material_file_missing(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            material_files::resolve_into_draft(
                \context_system::instance()->id,
                'mod_assign',
                'introattachment',
                0,
                ['fehlt.pdf']
            );
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('fehlt.pdf', $e->getMessage());
        }
    }

    /**
     * References are read-only; preserve the original material file
     * (Spec 0018 §4.2).
     */
    public function test_resolve_into_draft_leaves_material_file_untouched(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );

        material_files::resolve_into_draft(\context_system::instance()->id, 'mod_assign', 'introattachment', 0, ['blatt.pdf']);

        $stillthere = get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/coursepilot-material/',
            'blatt.pdf'
        );
        $this->assertNotFalse($stillthere);
        $this->assertSame('Arbeitsblattinhalt', $stillthere->get_content());
    }

    /**
     * An object entry with a target folder places the draft file in that
     * subfolder rather than the root (#434).
     */
    public function test_resolve_into_draft_places_file_in_target_subfolder(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );

        $draftitemid = material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_folder',
            'content',
            0,
            [['path' => 'blatt.pdf', 'target_folder' => 'unterordner']]
        );

        $draftfile = get_file_storage()->get_file(
            material_files::own_context()->id,
            'user',
            'draft',
            $draftitemid,
            '/unterordner/',
            'blatt.pdf'
        );
        $this->assertNotFalse($draftfile);
        $this->assertSame('Arbeitsblattinhalt', $draftfile->get_content());
    }

    /**
     * String entries still use the root, preserving existing path-list callers.
     */
    public function test_resolve_into_draft_defaults_string_entries_to_root(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );

        $draftitemid = material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_folder',
            'content',
            0,
            ['blatt.pdf']
        );

        $draftfile = get_file_storage()->get_file(
            material_files::own_context()->id,
            'user',
            'draft',
            $draftitemid,
            '/',
            'blatt.pdf'
        );
        $this->assertNotFalse($draftfile);
    }

    /**
     * Reject target-folder traversal under the same path rules as material
     * storage (#434).
     */
    public function test_resolve_into_draft_rejects_target_folder_traversal(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );

        $this->expectException(\moodle_exception::class);
        material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_folder',
            'content',
            0,
            [['path' => 'blatt.pdf', 'target_folder' => '../ausserhalb']]
        );
    }

    /**
     * Reject object entries missing a path with a clear message, not a PHP error.
     */
    public function test_resolve_into_draft_rejects_entry_without_pfad(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_folder',
            'content',
            0,
            [['target_folder' => 'unterordner']]
        );
    }

    /**
     * Drafts do not consume user quota. Enforce $CFG->maxbytes per file
     * before copying inventory sources (Spec #486 §7, #496).
     */
    public function test_resolve_into_draft_rejects_file_larger_than_cfg_maxbytes(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'gross.pdf'),
            'zwoelf Byte!'
        );
        $CFG->maxbytes = 5;

        try {
            material_files::resolve_into_draft(
                \context_system::instance()->id,
                'mod_assign',
                'introattachment',
                0,
                ['gross.pdf']
            );
            $this->fail('Erwartete moodle_exception wegen $CFG->maxbytes blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialembedtoolarge', $e->errorcode);
        }
    }

    /**
     * Apply $CFG->maxbytes only to inventory embedding drafts (Spec #486 §7).
     * Workbench files already passed the upload server limit; imposing another
     * limit would prevent embedding existing files, beyond #496’s scope.
     */
    public function test_resolve_into_draft_ignores_cfg_maxbytes_for_werkbank_source(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'gross.pdf'),
            'zwoelf Byte!'
        );
        $CFG->maxbytes = 5;

        $draftitemid = material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_assign',
            'introattachment',
            0,
            ['gross.pdf'],
            material_files::LOCATION_WORKBENCH
        );

        $this->assertNotFalse(get_file_storage()->get_file(
            material_files::own_context()->id,
            'user',
            'draft',
            $draftitemid,
            '/',
            'gross.pdf'
        ));
    }

    /**
     * $CFG->maxbytes <= 0 means no additional limit, following Moodle convention.
     */
    public function test_resolve_into_draft_allows_any_size_when_cfg_maxbytes_is_zero(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );
        $CFG->maxbytes = 0;

        $draftitemid = material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_assign',
            'introattachment',
            0,
            ['blatt.pdf']
        );

        $this->assertNotFalse(get_file_storage()->get_file(
            material_files::own_context()->id,
            'user',
            'draft',
            $draftitemid,
            '/',
            'blatt.pdf'
        ));
    }

    /**
     * One source defines the webservice location parameter’s type, default
     * and description, matching tools/list (#508).
     */
    public function test_location_parameter_uses_shared_default_and_description(): void {
        $param = material_files::location_parameter();

        $this->assertSame(PARAM_ALPHA, $param->type);
        $this->assertSame(material_files::LOCATION_STORE, $param->default);
        $this->assertSame(material_files::LOCATION_DESCRIPTION, $param->desc);
        $this->assertSame(VALUE_DEFAULT, $param->required);
    }

    /**
     * Tool-registry schemas and the webservice share the same location
     * description and allowed values (#508).
     */
    public function test_location_schema_matches_shared_description_and_values(): void {
        $schema = material_files::location_schema();

        $this->assertSame('string', $schema['type']);
        $this->assertSame([material_files::LOCATION_STORE, material_files::LOCATION_WORKBENCH], $schema['enum']);
        $this->assertSame(material_files::LOCATION_DESCRIPTION, $schema['description']);
    }
}
