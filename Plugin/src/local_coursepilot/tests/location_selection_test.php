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
use local_coursepilot\webdav\webdav_instance;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Location-selection logic (#494, Spec #486 §5/§10), tested through
 * the class with fake WebDAV rather than through the page.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(location_selection::class)]
#[CoversClass(\local_coursepilot\previous_location::class)]
final class location_selection_test extends \advanced_testcase {
    use webdav_instance_fixture;

    /**
     * Page states and file-picker timeout are named constants used by
     * {@see location_selection::setup_state()} and templates (#507).
     */
    public function test_state_and_timeout_constants_have_the_expected_values(): void {
        $this->assertSame('not_enabled', location_selection::STATE_NOT_ENABLED);
        $this->assertSame('no_instance', location_selection::STATE_NO_INSTANCE);
        $this->assertSame('ready', location_selection::STATE_READY);
        $this->assertSame(8000, location_selection::BROWSE_TIMEOUT_MS);
    }

    public function test_setup_state_is_not_enabled_without_freischaltung(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $state = location_selection::setup_state((int) $user->id);

        $this->assertSame('not_enabled', $state['state']);
    }

    public function test_setup_state_is_no_instance_when_enabled_but_none_owned(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->enable_webdav_repository_type();

        $state = location_selection::setup_state((int) $user->id);

        $this->assertSame('no_instance', $state['state']);
    }

    public function test_setup_state_is_ready_with_instance_and_freischaltung(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->create_webdav_instance($user);

        $state = location_selection::setup_state((int) $user->id);

        $this->assertSame('ready', $state['state']);
    }

    public function test_setup_state_prefers_not_enabled_over_no_instance(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        // Neither authorization nor an instance exists: report unauthorized, not missing instance.
        $this->enable_webdav_repository_type();

        $state = location_selection::setup_state((int) $user->id);

        $this->assertSame('not_enabled', $state['state']);
    }

    public function test_page_state_uses_named_states_and_keys_instead_of_display_text(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $state = location_selection::page_state((int) $user->id);

        $this->assertSame(location_selection::STATE_NOT_ENABLED, $state['webdav']['state']);
        $this->assertSame('not_selected', $state['locations']['context_area']['state']);
        $this->assertSame('moodle', $state['locations']['context_area']['kind']);
        $this->assertSame('closed', $state['previous_location']['state']);
        $this->assertSame([], $state['history']);
        $this->assertSame('idle', $state['browse']['state']);
        $this->assertSame([], $state['notices']);
        $this->assertSame([], $state['errors']);
        $this->assertArrayNotHasKey('display', $state['locations']['context_area']);
        $this->assertArrayNotHasKey('instruction', $state['webdav']['steps']['repository_active']);
    }

    public function test_missing_steps_text_names_only_failing_steps(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_webdav_repository_type();
        // Steps 1+2 fulfilled, step 3 (capability) missing.

        $text = location_selection::missing_steps_text((int) $user->id);

        $this->assertStringContainsString('repository/webdav:view', $text);
        $this->assertStringNotContainsString('Nutzerinstanzen erlauben', $text);
    }

    /**
     * Disabling the repository (step 1) must not report already configured
     * steps 2 and 3 as missing, misleading administrators (#528, Spec #486
     * §5, live acceptance #505 finding 11).
     */
    public function test_missing_steps_text_omits_already_satisfied_steps_when_step_one_is_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_webdav_instance($user);
        $this->grant_webdav_capability($user);

        global $DB;
        $DB->set_field('repository', 'visible', 0, ['type' => 'webdav']);

        $text = location_selection::missing_steps_text((int) $user->id);

        $this->assertStringContainsString('Repositories', $text);
        $this->assertStringNotContainsString('Nutzerinstanzen erlauben', $text);
        $this->assertStringNotContainsString('repository/webdav:view', $text);
    }

    public function test_own_instances_excludes_foreign_instances(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $this->grant_webdav_capability($owner);
        $ownid = $this->create_webdav_instance($owner);
        $this->setUser($other);
        $this->grant_webdav_capability($other);
        $this->create_webdav_instance($other);
        $this->setUser($owner);

        $instances = location_selection::own_instances();

        $this->assertCount(1, $instances);
        $this->assertSame($ownid, $instances[0]['id']);
    }

    public function test_current_defaults_to_configured_moodle_root_without_pointer(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $kontext = location_selection::current('context_area');
        $material = location_selection::current('material_store');

        $this->assertSame('moodle', $kontext['location']);
        $this->assertSame('coursepilot', $kontext['path']);
        $this->assertSame('moodle', $material['location']);
        $this->assertSame('coursepilot-material', $material['path']);
    }

    /**
     * Without a pointer, no target is explicitly chosen even though current()
     * displays the default root. The file-picker JS must still require a
     * choice during first setup (#525, Spec §5).
     */
    public function test_current_marks_target_as_not_chosen_without_pointer(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $kontext = location_selection::current('context_area');
        $material = location_selection::current('material_store');

        $this->assertFalse($kontext['chosen']);
        $this->assertFalse($material['chosen']);
    }

    /**
     * A target explicitly resolved by a pointer is chosen, whether in
     * Moodle or externally (#525).
     */
    public function test_current_marks_target_as_chosen_with_pointer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'context_area', $instanceid, 'Kontext');

        $kontext = location_selection::current('context_area');
        $material = location_selection::current('material_store');

        $this->assertTrue($kontext['chosen']);
        $this->assertTrue($material['chosen']);
    }

    /**
     * Private Files are always approved in the display, independently of
     * personaldatahosts (#500, ADR 0021 §3).
     */
    public function test_current_marks_moodle_location_as_allowed(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $kontext = location_selection::current('context_area');

        $this->assertTrue($kontext['allowed']);
        $this->assertSame(
            get_string('locationselectionallowedyes', 'local_coursepilot'),
            location_selection::allowed_label($kontext)
        );
    }

    /**
     * Approve external locations only when their server is in
     * personaldatahosts (#500, ADR 0021 §3). Use the same check as
     * {@see \local_coursepilot\admin\connection_storage_location} for consent,
     * My connections and location selection.
     */
    public function test_current_marks_extern_location_as_not_allowed_without_configured_host(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'context_area', $instanceid, 'Kontext');
        // Empty personaldatahosts leaves the instance server unapproved.

        $kontext = location_selection::current('context_area');

        $this->assertFalse($kontext['allowed']);
        $this->assertSame(
            get_string('locationselectionallowedno', 'local_coursepilot'),
            location_selection::allowed_label($kontext)
        );
    }

    /**
     * An external location on an approved server is approved (#500).
     */
    public function test_current_marks_extern_location_as_allowed_with_configured_host(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'context_area', $instanceid, 'Kontext');
        set_config('personaldatahosts', $this->fixtureserver, 'local_coursepilot');

        $kontext = location_selection::current('context_area');

        $this->assertTrue($kontext['allowed']);
    }

    /**
     * The shared privacy information contains all four required facts
     * (#500, Spec #486 §11). Consent, My connections and personaldatahosts
     * use one source to prevent wording from drifting.
     */
    public function test_external_location_privacy_info_names_all_four_facts(): void {
        // The test instance resolves English strings only. The four facts in
        // lang/en/local_coursepilot.php match the German development language pack.
        $text = get_string('externallocationprivacyinfo', 'local_coursepilot');

        $this->assertStringContainsString('AI', $text);
        $this->assertStringContainsString('write lock', $text);
        $this->assertStringContainsString('no read lock', $text);
        $this->assertStringContainsString('Mounted shares', $text);
        $this->assertStringContainsString('cannot be told apart', $text);
        $this->assertStringContainsString('app password', $text);
    }

    public function test_history_is_empty_without_pointer(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame([], location_selection::history());
    }

    public function test_browse_lists_folders_only_and_ignores_files(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        $fake->seed_file('/' . $this->fixturebasispfad . '/notiz.md', 'Inhalt');

        try {
            $result = location_selection::browse($this->lastinstanceid, '');
            $this->assertSame('', $result['path']);
            $this->assertSame([['name' => 'Unterricht']], $result['folders']);
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_browse_of_missing_folder_returns_empty_list(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();

        try {
            $result = location_selection::browse($this->lastinstanceid, 'nicht-vorhanden');
            $this->assertSame([], $result['folders']);
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_browse_rejects_foreign_instance(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $this->grant_webdav_capability($owner);
        $instanceid = $this->create_webdav_instance($owner);

        $attacker = $this->getDataGenerator()->create_user();
        $this->setUser($attacker);
        $this->grant_webdav_capability($attacker);

        $this->expectException(\moodle_exception::class);
        location_selection::browse($instanceid, '');
    }

    public function test_apply_keeps_pointer_unchanged_when_both_targets_stay_in_moodle(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $changed = location_selection::apply([
            'context_area' => ['type' => 'moodle'],
            'material_store' => ['type' => 'moodle'],
        ]);

        $this->assertSame([], $changed);
        $state = location_selection::page_state((int) $user->id);
        $this->assertSame('not_selected', $state['locations']['context_area']['state']);
        $this->assertSame('not_selected', $state['locations']['material_store']['state']);
    }

    public function test_apply_creates_folder_chain_writes_pointer_and_history_line(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;

        try {
            $changed = location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Unterricht/Kontext'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $this->assertSame(['context_area'], $changed);

            $mkcols = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'MKCOL'));
            $this->assertCount(5, $mkcols, 'Two location levels, then best-effort activity-types creation for each template.');
            $this->assertStringEndsWith('/Unterricht/', $mkcols[0]['url']);
            $this->assertStringEndsWith('/Unterricht/Kontext/', $mkcols[1]['url']);

            $state = location_selection::page_state((int) $user->id);
            $this->assertSame('selected', $state['locations']['context_area']['state']);
            $this->assertSame('external', $state['locations']['context_area']['kind']);
            $this->assertSame($instanceid, $state['locations']['context_area']['instanceid']);
            $this->assertSame('Unterricht/Kontext', $state['locations']['context_area']['path']);
            $this->assertSame('moodle', $state['locations']['material_store']['kind']);
            $this->assertCount(1, $state['history']);
            $this->assertSame('context_area', $state['history'][0]['target']);
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * Require explicit confirmation of a populated context folder on the
     * server, not just in page JS (#518, Spec §5).
     */
    public function test_apply_rejects_filled_context_folder_without_explicit_confirmation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Unterricht/notiz.md', 'Inhalt');

        try {
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Unterricht'],
                'material_store' => ['type' => 'moodle'],
            ]);
            $this->fail('locationselectionfolderconfirmrequired should have been thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('locationselectionfolderconfirmrequired', $e->errorcode);
        } finally {
            $state = location_selection::page_state((int) $user->id);
            $this->assertSame('not_selected', $state['locations']['context_area']['state']);
            \core\di::reset_container();
        }
    }

    /**
     * Explicit confirmation permits the same selection.
     */
    public function test_apply_accepts_filled_context_folder_with_explicit_confirmation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Unterricht/notiz.md', 'Inhalt');

        try {
            $changed = location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Unterricht', 'confirmed' => true],
                'material_store' => ['type' => 'moodle'],
            ]);

            $this->assertSame(['context_area'], $changed);
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * Empty folders require no confirmation (Spec §5).
     */
    public function test_apply_accepts_empty_context_folder_without_confirmation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Leer');

        try {
            $changed = location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Leer'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $this->assertSame(['context_area'], $changed);
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * Reapplying without a location change requires no new confirmation;
     * Spec §5 applies only to newly selected folders.
     */
    public function test_apply_repeat_of_unchanged_filled_context_folder_needs_no_confirmation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Unterricht/notiz.md', 'Inhalt');
        $selection = [
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Unterricht', 'confirmed' => true],
            'material_store' => ['type' => 'moodle'],
        ];

        try {
            $this->assertSame(['context_area'], location_selection::apply($selection));
            $selection['context_area']['confirmed'] = false;
            $this->assertSame([], location_selection::apply($selection));
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_apply_is_a_noop_and_does_not_duplicate_history_on_repeat(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $selection = [
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Kontext'],
            'material_store' => ['type' => 'moodle'],
        ];

        try {
            $this->assertSame(['context_area'], location_selection::apply($selection));
            $this->assertSame([], location_selection::apply($selection));

            $this->assertCount(1, location_selection::page_state((int) $user->id)['history']);
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_apply_aborts_without_saving_when_folder_creation_fails(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $fake->deny_auth();

        try {
            $this->expectException(\moodle_exception::class);
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Kontext'],
                'material_store' => ['type' => 'moodle'],
            ]);
        } finally {
            $this->assertSame('not_selected', location_selection::page_state((int) $user->id)['locations']['context_area']['state']);
            \core\di::reset_container();
        }
    }

    public function test_apply_rejects_a_foreign_instance_in_the_selection(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $this->grant_webdav_capability($owner);
        $instanceid = $this->create_webdav_instance($owner);

        $attacker = $this->getDataGenerator()->create_user();
        $this->setUser($attacker);
        $this->grant_webdav_capability($attacker);

        $this->expectException(\moodle_exception::class);
        location_selection::apply([
            'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Kontext'],
            'material_store' => ['type' => 'moodle'],
        ]);
    }

    public function test_apply_rejects_root_of_instance_as_selection(): void {
        // Instance roots are not selectable (#497, Spec #486 §5), superseding
        // the earlier root selection allowed by #494.
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;

        try {
            $this->expectException(\moodle_exception::class);
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => ''],
                'material_store' => ['type' => 'moodle'],
            ]);
        } finally {
            $this->assertSame('not_selected', location_selection::page_state((int) $user->id)['locations']['context_area']['state']);
            \core\di::reset_container();
        }
    }

    // --- Issue #497: locks, IServ detection, handover of a filled folder ---

    public function test_browse_root_is_not_selectable(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();

        try {
            $result = location_selection::browse($this->lastinstanceid, '');
            $this->assertFalse($result['selectable']);
            $this->assertSame('locationselectionrootnotselectable', $result['reasonkey']);
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_browse_reports_iserv_no_in_nextcloud_mode(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');

        try {
            $root = location_selection::browse($this->lastinstanceid, '');
            $this->assertFalse($root['iserv']);

            $level = location_selection::browse($this->lastinstanceid, 'Unterricht');
            $this->assertFalse($level['iserv']);
            $this->assertTrue($level['selectable']);
            $this->assertNull($level['reasonkey']);
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_browse_logs_when_iserv_detection_fails_and_reports_no(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        // Only additional IServ root detection fails; listing Unterricht succeeds.
        // Treat this network error as false and log it (#506).
        $fake->fail_once('/' . $this->fixturebasispfad, 401);
        $sink = $this->redirectEvents();

        try {
            $level = location_selection::browse($this->lastinstanceid, 'Unterricht');
            $this->assertFalse($level['iserv']);
        } finally {
            \core\di::reset_container();
        }

        $failures = array_filter($sink->get_events(), fn ($e) => $e instanceof \local_coursepilot\event\tool_access_failed);
        $sink->close();
        $this->assertNotEmpty(
            $failures,
            'A failed IServ check must be logged, not silently treated as "no".'
        );
    }

    public function test_browse_reports_iserv_yes_and_locks_everything_outside_files(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->as_iserv_root('/' . $this->fixturebasispfad);
        $fake->without_etags();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Files');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Files/Unterricht');

        try {
            $root = location_selection::browse($this->lastinstanceid, '');
            $this->assertTrue($root['iserv']);
            $this->assertFalse($root['selectable'], 'The root additionally always stays locked.');
            $this->assertSame(
                ['Files', 'Groups', 'Print', 'Temp', 'Windows'],
                array_map(static fn (array $f): string => $f['name'], $root['folders'])
            );

            $groups = location_selection::browse($this->lastinstanceid, 'Groups');
            $this->assertTrue($groups['iserv']);
            $this->assertFalse($groups['selectable'], 'Outside Files/ nothing is selectable on IServ.');
            $this->assertSame('locationselectioniservfilesonly', $groups['reasonkey']);

            $files = location_selection::browse($this->lastinstanceid, 'Files');
            $this->assertTrue($files['iserv']);
            $this->assertTrue($files['selectable'], 'Below Files/ stays selectable on IServ.');

            $nested = location_selection::browse($this->lastinstanceid, 'Files/Unterricht');
            $this->assertTrue($nested['selectable']);
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_browse_reports_entrycount_and_first_names_for_confirmation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht/b-ordner');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Unterricht/a-datei.md', 'Inhalt');

        try {
            $result = location_selection::browse($this->lastinstanceid, 'Unterricht');
            $this->assertSame(2, $result['entrycount']);
            $this->assertSame(['a-datei.md', 'b-ordner'], $result['entrynames']);
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_browse_of_empty_folder_has_no_entries_to_confirm(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Leer');

        try {
            $result = location_selection::browse($this->lastinstanceid, 'Leer');
            $this->assertSame(0, $result['entrycount']);
            $this->assertSame([], $result['entrynames']);
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_own_instances_marks_instance_without_https_basic_as_not_selectable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->create_webdav_instance($user, ['webdav_auth' => 'digest']);

        $instances = location_selection::own_instances();

        $this->assertCount(1, $instances);
        $this->assertFalse($instances[0]['selectable']);
        $this->assertSame('locationselectioninstanceauthunsupported', $instances[0]['reasonkey']);
    }

    public function test_own_instances_marks_valid_instance_as_selectable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->create_webdav_instance($user);

        $instances = location_selection::own_instances();

        $this->assertTrue($instances[0]['selectable']);
        $this->assertNull($instances[0]['reasonkey']);
    }

    public function test_apply_rejects_iserv_path_outside_files(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->as_iserv_root('/' . $this->fixturebasispfad);
        $instanceid = $this->lastinstanceid;

        try {
            $this->expectException(\moodle_exception::class);
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Groups'],
                'material_store' => ['type' => 'moodle'],
            ]);
        } finally {
            $this->assertSame('not_selected', location_selection::page_state((int) $user->id)['locations']['context_area']['state']);
            \core\di::reset_container();
        }
    }

    public function test_apply_accepts_iserv_path_under_files_and_stores_iserv_flag(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->as_iserv_root('/' . $this->fixturebasispfad);
        $instanceid = $this->lastinstanceid;

        try {
            $changed = location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Files/Unterricht'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $this->assertSame(['context_area'], $changed);
            $this->assertSame('selected', location_selection::page_state((int) $user->id)['locations']['context_area']['state']);
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_apply_rejects_materialbestand_inside_kontextbereich(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;

        try {
            $this->expectException(\moodle_exception::class);
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Unterricht'],
                'material_store' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Unterricht/Material'],
            ]);
        } finally {
            $this->assertSame('not_selected', location_selection::page_state((int) $user->id)['locations']['context_area']['state']);
            \core\di::reset_container();
        }
    }

    public function test_open_with_access_requires_both_freischaltung_and_no_pointer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertFalse(location_selection::open_with_access((int) $user->id), 'No fact without enablement.');

        $this->enable_webdav_repository_type();
        $this->grant_webdav_capability($user);
        $this->assertTrue(location_selection::open_with_access((int) $user->id), 'Freigeschaltet, Ortswahl offen.');

        [$user, $fake] = $this->prepare_instance();
        try {
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
                'material_store' => ['type' => 'moodle'],
            ]);
            $this->assertFalse(location_selection::open_with_access((int) $user->id), 'Location selection is no longer open once a location is chosen.');
        } finally {
            \core\di::reset_container();
        }
    }

    // --- Issue #498: legacy items (previous location) ---

    /**
     * Record the previous Moodle location only when it contains context
     * files (Spec §5).
     */
    public function test_apply_records_previous_location_when_old_moodle_location_has_files(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => 'vorlagen.md',
        ], '# Alt');

        try {
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $vorheriger = previous_location::current();
            $this->assertSame('moodle', $vorheriger['location']);
            $this->assertSame('coursepilot', $vorheriger['path']);
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * An empty old location creates no pending old content.
     */
    public function test_apply_records_no_previous_location_when_old_location_is_empty(): void {
        $this->resetAfterTest();
        [, $fake] = $this->prepare_instance();

        try {
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $this->assertNull(previous_location::current());
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * Changing only material inventory creates no pending old content,
     * even when the old Moodle material folder contains files (Spec §9).
     */
    public function test_apply_records_no_previous_location_for_materialbestand_only_change(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot-material/',
            'filename' => 'bild.png',
        ], 'x');

        try {
            location_selection::apply([
                'context_area' => ['type' => 'moodle'],
                'material_store' => ['type' => 'external', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
            ]);

            $this->assertNull(previous_location::current());
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * A new location change replaces previous pending old content;
     * only one previous location exists (Spec §9).
     */
    public function test_apply_replaces_an_already_open_previous_location(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Erst');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Erst/datei.md', 'Erst');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Zweit');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Zweit/datei.md', 'Zweit');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        try {
            // Moodle -> Erst: the old Moodle location has no files, so no old content.
            // Erst already contains a file and needs handover confirmation (#518).
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Erst', 'confirmed' => true],
                'material_store' => ['type' => 'moodle'],
            ]);
            $this->assertNull(previous_location::current());

            // Erst -> Zweit: Erst contains a file and becomes the previous location.
            // Zweit also contains a file and needs confirmation.
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Zweit', 'confirmed' => true],
                'material_store' => ['type' => 'moodle'],
            ]);
            $vorheriger = previous_location::current();
            $this->assertSame('Erst', $vorheriger['path']);
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * An unreachable old location, such as a deleted instance, has no
     * provable old content and does not prevent applying the selection.
     */
    public function test_apply_treats_unreachable_old_location_as_no_previous_location(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $oldinstanceid = $this->create_webdav_instance($user);
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Alt');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        try {
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $oldinstanceid, 'path' => 'Alt'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $DB->delete_records('repository_instances', ['id' => $oldinstanceid]);
            $newinstanceid = $this->create_webdav_instance($user);
            $fake->seed_folder('/' . $this->fixturebasispfad . '/Neu');

            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $newinstanceid, 'path' => 'Neu'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $this->assertNull(previous_location::current());
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * Returning A -> B -> A must never leave pending old content pointing
     * to the current location, even if B was empty (#517, Spec §9).
     */
    public function test_apply_clears_previous_location_that_would_point_at_the_current_place(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => 'vorlagen.md',
        ], '# A');
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        try {
            // A (Moodle with a file) -> B (external, empty): A becomes the previous location.
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'B'],
                'material_store' => ['type' => 'moodle'],
            ]);
            $vorheriger = previous_location::current();
            $this->assertSame('moodle', $vorheriger['location']);
            $this->assertSame('coursepilot', $vorheriger['path']);

            // The teacher removes the supplied templates and their empty folder.
            foreach (['book', 'checklist', 'glossary'] as $modname) {
                storage_anchor::port(context_files::area())->delete(context_files::area(), 'activity-types/' . $modname . '.md');
            }
            $fake->request('DELETE', 'https://' . $this->fixtureserver . '/' . $this->fixturebasispfad . '/B/activity-types');
            // B (external, empty) -> A (Moodle): empty B still replaces previous
            // content, otherwise the previous location would point to current A.
            location_selection::apply([
                'context_area' => ['type' => 'moodle'],
                'material_store' => ['type' => 'moodle'],
            ]);
            $this->assertNull(previous_location::current());
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * A non-Markdown file without subfolders alone creates no pending old
     * content (#517, Spec §9). #505 finding 7 changes only subfolder handling;
     * see {@see test_apply_records_previous_location_when_old_location_has_only_a_subfolder()}.
     */
    public function test_apply_records_no_previous_location_when_old_location_has_only_non_context_entries(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => 'notizen.txt',
        ], 'x');

        try {
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $this->assertNull(previous_location::current());
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * A non-Markdown file without subfolders at the old external location
     * also creates no pending old content (#517).
     */
    public function test_apply_records_no_previous_location_when_old_external_location_has_only_non_context_entries(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Alt');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Alt/notizen.txt', 'x');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Neu');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        try {
            // Establish the old location without selecting it here: selection now
            // supplies Markdown templates, which would no longer be this fixture.
            $this->write_v2_pointer($user, 'context_area', $instanceid, 'Alt',
                $this->fixture_fingerprint() + ['iserv' => false]);
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Neu'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $this->assertNull(previous_location::current());
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * A subfolder at the old Moodle location alone counts as old content
     * (#505 finding 7). Previously checking only top-level files missed
     * context files stored exclusively in subfolders during location changes.
     */
    public function test_apply_records_previous_location_when_old_location_has_only_a_subfolder(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/9a/biologie/',
            'filename' => 'journal.md',
        ], '# Journal');

        try {
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $vorheriger = previous_location::current();
            $this->assertSame('moodle', $vorheriger['location']);
            $this->assertSame('coursepilot', $vorheriger['path']);
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * A top-level subfolder at the old external location counts as old
     * content without recursively inspecting it (#505 finding 7). The teacher
     * chose to avoid recursive PROPFIND.
     */
    public function test_apply_records_previous_location_when_old_external_location_has_only_a_subfolder(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Alt');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Alt/9a');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Neu');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        try {
            // Alt has only a subfolder, requiring handover confirmation (#518); Neu is empty.
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Alt', 'confirmed' => true],
                'material_store' => ['type' => 'moodle'],
            ]);
            location_selection::apply([
                'context_area' => ['type' => 'external', 'instanceid' => $instanceid, 'path' => 'Neu'],
                'material_store' => ['type' => 'moodle'],
            ]);

            $vorheriger = previous_location::current();
            $this->assertSame('external', $vorheriger['location']);
            $this->assertSame('Alt', $vorheriger['path']);
        } finally {
            \core\di::reset_container();
        }
    }

    /** @var int ID of the last instance created by {@see prepare_instance()}. */
    private int $lastinstanceid = 0;

    /**
     * @return array{0: \stdClass, 1: fake_webdav_transport}
     */
    private function prepare_instance(): array {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->lastinstanceid = $this->create_webdav_instance($user);

        $fake = new fake_webdav_transport();
        // The teacher or administrator creates the instance base path on real
        // storage. Seed it explicitly because fake storage starts empty.
        $fake->seed_folder('/' . $this->fixturebasispfad);
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        return [$user, $fake];
    }
}
