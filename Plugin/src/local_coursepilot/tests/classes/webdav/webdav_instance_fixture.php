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

namespace local_coursepilot\tests\webdav;

/**
 * Shared valid WebDAV user-instance fixture (#490, Spec #486 §2/§3/§12).
 * Populate repository, repository_instances and repository_instance_config,
 * enable all three setup steps and write a matching v2 pointer. Reuse
 * across webdav_instance_test and external endpoint tests.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
trait webdav_instance_fixture {
    /** @var string Default test-instance server for the verification marker. */
    protected string $fixtureserver = 'cloud.example.test';

    /** @var string Default test-instance base path. */
    protected string $fixturebasispfad = 'Coursepilot';

    /** @var string Default test-instance account. */
    protected string $fixturekonto = 'lehrerin';

    /**
     * Enable the WebDAV repository and user instances (Spec §12, steps 1+2).
     * Store enableuserinstances in the webdav config plugin as resolution expects.
     */
    protected function enable_webdav_repository_type(): void {
        global $DB;

        if (!$DB->record_exists('repository', ['type' => 'webdav'])) {
            $DB->insert_record('repository', (object) ['type' => 'webdav', 'visible' => 1, 'sortorder' => 1]);
        } else {
            $DB->set_field('repository', 'visible', 1, ['type' => 'webdav']);
        }
        set_config('enableuserinstances', 1, 'webdav');
    }

    /**
     * Grant repository/webdav:view in the system context, affecting every
     * user context (Spec §12, recommended dedicated system role).
     *
     * @param \stdClass $user
     */
    protected function grant_webdav_capability(\stdClass $user): void {
        global $DB;

        $shortname = 'coursepilotwebdavtest';
        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
        if (!$roleid) {
            $roleid = create_role('Coursepilot WebDAV Test', $shortname, '');
        }
        assign_capability('repository/webdav:view', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Create a repository_webdav instance owned by the supplied user in
     * their own user context.
     *
     * @param \stdClass $user
     * @param array $overrides Override instance options, e.g. webdav_auth=digest for the auth edge case.
     * @phpstan-param array<string,string|int> $overrides
     * @return int Instance ID.
     */
    protected function create_webdav_instance(\stdClass $user, array $overrides = []): int {
        global $DB;

        $this->enable_webdav_repository_type();
        $typeid = $DB->get_field('repository', 'id', ['type' => 'webdav'], MUST_EXIST);
        $contextid = \context_user::instance($user->id)->id;

        $instanceid = $DB->insert_record('repository_instances', (object) [
            'name' => 'Meine Cloud',
            'typeid' => $typeid,
            'userid' => 0,
            'contextid' => $contextid,
            'timecreated' => time(),
            'timemodified' => time(),
            'readonly' => 0,
        ]);

        $options = array_merge([
            'webdav_type' => 1,
            'webdav_server' => $this->fixtureserver,
            'webdav_port' => '',
            'webdav_path' => $this->fixturebasispfad,
            'webdav_user' => $this->fixturekonto,
            'webdav_password' => 'g3h31m-nie-sichtbar',
            'webdav_auth' => 'basic',
        ], $overrides);

        foreach ($options as $name => $value) {
            $DB->insert_record('repository_instance_config', (object) [
                'instanceid' => $instanceid,
                'name' => $name,
                'value' => (string) $value,
            ]);
        }

        return (int) $instanceid;
    }

    /**
     * Default test-instance verification marker, as carried in a v2 pointer.
     *
     * @return array{server: string, basispfad: string, konto: string}
     */
    protected function fixture_fingerprint(): array {
        return ['server' => $this->fixtureserver, 'basepath' => $this->fixturebasispfad, 'account' => $this->fixturekonto];
    }

    /**
     * Write a v2 context pointer into the user’s anchor (Spec §2).
     * One target is external; the other defaults to Moodle.
     *
     * @param \stdClass $user
     * @param string $externtarget "context_area" or "material_store".
     * @param int $instanceid
     * @param string $relativepath
     * @param array|null $fingerprint Default: {@see fixture_fingerprint()}.
     */
    protected function write_v2_pointer(
        \stdClass $user,
        string $externtarget,
        int $instanceid,
        string $relativepath,
        ?array $fingerprint = null
    ): void {
        $external = [
            'location' => 'external',
            'instanceid' => $instanceid,
            'path' => $relativepath,
            'fingerprint' => $fingerprint ?? $this->fixture_fingerprint(),
        ];
        $inmoodle = ['location' => 'moodle', 'path' => 'coursepilot'];

        $pointer = [
            'context_area' => $externtarget === 'context_area' ? $external : $inmoodle,
            'material_store' => $externtarget === 'material_store' ? $external : ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ];

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-location.json',
        ], json_encode($pointer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Shared external-context endpoint fixture: create an authorized
     * WebDAV user instance, point v2 context at its Kontext folder and inject
     * fake transport. list_context_files_test and read_context_file_test
     * use the same valid external-context setup.
     *
     * @return array{0: \stdClass, 1: fake_webdav_transport}
     */
    protected function set_up_external_context(): array {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'context_area', $instanceid, 'Kontext');

        $fake = new fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        return [$user, $fake];
    }

    /**
     * External inventory counterpart to {@see set_up_external_context()}
     * (#495). Point v2 inventory at Material while context stays in Moodle.
     *
     * @return array{0: \stdClass, 1: fake_webdav_transport}
     */
    protected function set_up_external_material(): array {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'material_store', $instanceid, 'Material');

        $fake = new fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        return [$user, $fake];
    }

    /**
     * Shared v2 pointer fixture with pending old content (#498, Spec #486 §9).
     * Both regular targets remain at default Moodle roots; the previous
     * location is also in Moodle.
     *
     * @param \stdClass $user
     * @param string $path Previous root relative to Private Files.
     */
    protected function write_pointer_with_previous_location(\stdClass $user, string $path = 'alter-kontext'): void {
        $document = [
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [],
            'previous_location' => ['location' => 'moodle', 'path' => $path],
        ];
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-location.json',
        ], json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * External previous-location counterpart to
     * {@see write_pointer_with_previous_location()} (#517, Spec §6).
     * Both current targets remain in Moodle; only old content is in WebDAV.
     * moodle/user:manageownfiles does not apply externally.
     *
     * @param \stdClass $user
     * @param int $instanceid
     * @param string $relativepath Previous root within the instance.
     * @param array|null $fingerprint Default: {@see fixture_fingerprint()}.
     */
    protected function write_pointer_with_external_previous_location(
        \stdClass $user,
        int $instanceid,
        string $relativepath = 'Alt',
        ?array $fingerprint = null
    ): void {
        $document = [
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [],
            'previous_location' => [
                'location' => 'external',
                'instanceid' => $instanceid,
                'path' => $relativepath,
                'fingerprint' => $fingerprint ?? $this->fixture_fingerprint(),
            ],
        ];
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-location.json',
        ], json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
