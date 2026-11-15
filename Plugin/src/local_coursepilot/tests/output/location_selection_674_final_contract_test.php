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

namespace local_coursepilot\output;

use local_coursepilot\location_selection as selection;
use local_coursepilot\previous_location;
use local_coursepilot\storage_anchor;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exact structure of the location-selection template data (#674).
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(location_selection::class)]
final class location_selection_674_final_contract_test extends \advanced_testcase {
    use \local_coursepilot\tests\webdav\webdav_instance_fixture;

    /**
     * Builds the expected targets block for the two location targets.
     *
     * @return list<array<string, mixed>>
     */
    private function expected_targets(): array {
        $fields = static fn(string $target): array => [
            ['name' => $target . '_type', 'id' => 'coursepilot-location-selection-' . $target . '_type'],
            ['name' => $target . '_instanceid', 'id' => 'coursepilot-location-selection-' . $target . '_instanceid'],
            ['name' => $target . '_path', 'id' => 'coursepilot-location-selection-' . $target . '_path'],
            ['name' => $target . '_confirmed', 'id' => 'coursepilot-location-selection-' . $target . '_confirmed'],
        ];
        return [
            [
                'name' => 'context_area',
                'first' => true,
                'context_area' => true,
                'fields' => $fields('context_area'),
            ],
            [
                'name' => 'material_store',
                'first' => false,
                'context_area' => false,
                'fields' => $fields('material_store'),
            ],
        ];
    }

    /**
     * Builds the Moodle-root URL prefix used by the expected editor URLs.
     *
     * @return string
     */
    private function root(): string {
        global $CFG;
        return $CFG->wwwroot;
    }

    /**
     * Provides the editor data without OAuth passthrough: exact structure and values.
     *
     * @return void
     */
    public function test_editor_without_oauth_has_exact_structure_and_no_flow(): void {
        $this->resetAfterTest();
        $data = location_selection::editor_data([]);
        $this->assertSame([
            'actionurl' => $this->root() . '/local/coursepilot/location_selection.php',
            'sesskey' => sesskey(),
            'oauthflow' => false,
            'oauthpassthrough' => [],
            'targets' => $this->expected_targets(),
        ], $data);
    }

    /**
     * Provides the editor data with OAuth passthrough: flow flag and ordered fields.
     *
     * @return void
     */
    public function test_editor_with_oauth_passthrough_sets_flow_and_keeps_name_value_pairs(): void {
        $this->resetAfterTest();
        $data = location_selection::editor_data(['client_id' => 'abc', 'state' => 'xyz']);
        $this->assertTrue($data['oauthflow']);
        $this->assertSame([
            ['name' => 'client_id', 'value' => 'abc'],
            ['name' => 'state', 'value' => 'xyz'],
        ], $data['oauthpassthrough']);
        $this->assertSame($this->expected_targets(), $data['targets']);
    }

    /**
     * Provides the AMD configuration: exact keys, URLs, sesskey and timeout.
     *
     * @return void
     */
    public function test_amd_configuration_has_exact_keys_urls_and_timeout(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $contextid = storage_anchor::own_context()->id;
        $config = location_selection::amd_configuration($user);
        $this->assertSame(
            ['instances', 'targets', 'browseurl', 'manageinstancesurl', 'sesskey', 'timeoutms'],
            array_keys($config)
        );
        $this->assertSame([], $config['instances']);
        $this->assertSame(
            ['context_area' => selection::current('context_area'), 'material_store' => selection::current('material_store')],
            $config['targets']
        );
        $this->assertSame($this->root() . '/local/coursepilot/location_selection_browse.php', $config['browseurl']);
        $expectedurl = $this->root() . '/repository/manage_instances.php?contextid=' . $contextid;
        $this->assertSame($expectedurl, $config['manageinstancesurl']);
        $this->assertSame(sesskey(), $config['sesskey']);
        $this->assertSame(8000, $config['timeoutms']);
    }

    /**
     * Provides the AMD configuration: owned instances are passed through.
     *
     * @return void
     */
    public function test_amd_configuration_passes_owned_instances_through(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $config = location_selection::amd_configuration($user);
        $this->assertCount(1, $config['instances']);
        $this->assertSame($instanceid, $config['instances'][0]['id']);
    }

    /**
     * Provides current locations data: exact labels and allowance labels per target.
     *
     * @return void
     */
    public function test_current_locations_data_has_labels_per_target(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = selection::current('context_area');
        $material = selection::current('material_store');
        $this->assertSame([
            'context_area' => [
                'label' => get_string('locationselectioncurrentcontextarea', 'local_coursepilot', $context['display']),
                'allowancelabel' => get_string('locationselectionallowedyes', 'local_coursepilot'),
            ],
            'material_store' => [
                'label' => get_string('locationselectioncurrentmaterialstore', 'local_coursepilot', $material['display']),
                'allowancelabel' => get_string('locationselectionallowedyes', 'local_coursepilot'),
            ],
        ], location_selection::current_locations_data());
    }

    /**
     * Provides page data without any result, OAuth return or history: exact keys and values.
     *
     * @return void
     */
    public function test_page_data_without_result_or_oauth_has_exact_values(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $data = location_selection::page_data($user, null, null);
        $this->assertSame(
            [
                'finishresult', 'oauthreturn', 'previouslocationopen', 'notenabled', 'noinstance', 'ready',
                'missingstepstext', 'supporturl', 'schoolhint', 'editor', 'locations', 'history',
            ],
            array_keys($data)
        );
        $this->assertNull($data['finishresult']);
        $this->assertNull($data['oauthreturn']);
        $this->assertSame(previous_location::open(), $data['previouslocationopen']);
        $this->assertTrue($data['notenabled']);
        $this->assertFalse($data['noinstance']);
        $this->assertFalse($data['ready']);
        $this->assertSame(selection::missing_steps_text((int) $user->id), $data['missingstepstext']);
        $this->assertNotSame('', $data['missingstepstext']);
        $this->assertSame($this->root() . '/admin/settings.php?section=supportcontact', $data['supporturl']);
        $this->assertSame('', $data['schoolhint']);
        $this->assertSame(location_selection::editor_data([]), $data['editor']);
        $this->assertSame(location_selection::current_locations_data(), $data['locations']);
        $this->assertSame(['empty' => true, 'entries' => []], $data['history']);
    }

    /**
     * Provides page data state flags: enabled without instance is the no-instance state.
     *
     * @return void
     */
    public function test_page_data_flags_no_instance_state_only(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->enable_webdav_repository_type();
        $data = location_selection::page_data($user, null, null);
        $this->assertFalse($data['notenabled']);
        $this->assertTrue($data['noinstance']);
        $this->assertFalse($data['ready']);
    }

    /**
     * Provides page data state flags: an instance with authorization is the ready state.
     *
     * @return void
     */
    public function test_page_data_flags_ready_state_only(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->create_webdav_instance($user);
        $data = location_selection::page_data($user, null, null);
        $this->assertFalse($data['notenabled']);
        $this->assertFalse($data['noinstance']);
        $this->assertTrue($data['ready']);
    }

    /**
     * Provides the notification class for each notification type, including the default.
     *
     * @return void
     */
    public function test_finish_result_maps_each_notification_type_to_its_alert_class(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $cases = [
            [\core\output\notification::NOTIFY_SUCCESS, 'alert-success'],
            [\core\output\notification::NOTIFY_ERROR, 'alert-danger'],
            [\core\output\notification::NOTIFY_WARNING, 'alert-warning'],
            [\core\output\notification::NOTIFY_INFO, 'alert-info'],
            ['unknown', 'alert-info'],
        ];
        foreach ($cases as [$type, $class]) {
            $data = location_selection::page_data($user, ['type' => $type, 'text' => 'Meldung'], null);
            $this->assertSame(['class' => $class, 'text' => 'Meldung'], $data['finishresult'], $type);
        }
    }

    /**
     * Provides OAuth return data: the client name falls back to the client id, and the back URL keeps all parameters.
     *
     * @return void
     */
    public function test_oauth_return_uses_client_name_then_id_and_keeps_all_parameters_in_the_back_url(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $params = ['client_id' => 'abc', 'state' => 'xyz'];
        $named = (object) ['clientname' => 'Schulclient', 'clientid' => 'abc'];
        $data = location_selection::page_data($user, null, ['client' => $named, 'params' => $params]);
        $this->assertSame([
            'clientname' => 'Schulclient',
            'backurl' => $this->root() . '/local/coursepilot/oauth/authorize.php?client_id=abc&state=xyz',
        ], $data['oauthreturn']);
        $passthrough = array_column($data['editor']['oauthpassthrough'], 'value', 'name');
        $this->assertSame(['client_id' => 'abc', 'state' => 'xyz'], $passthrough);
        $this->assertTrue($data['editor']['oauthflow']);

        $unnamed = (object) ['clientname' => '', 'clientid' => 'abc'];
        $fallback = location_selection::page_data($user, null, ['client' => $unnamed, 'params' => []]);
        $this->assertSame('abc', $fallback['oauthreturn']['clientname']);
        $this->assertFalse($fallback['editor']['oauthflow']);
    }

    /**
     * Provides history data: newest entries first, target translation, and exact date and text.
     *
     * @return void
     */
    public function test_history_is_newest_first_with_translated_target_and_location_text(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [
                [
                    'date' => 1700000040,
                    'target' => 'context_area',
                    'from' => ['location' => 'moodle', 'path' => 'alt'],
                    'to' => ['location' => 'moodle', 'path' => 'neu'],
                ],
                [
                    'date' => 1700000099,
                    'target' => 'material_store',
                    'from_text' => 'alter Text',
                    'to_text' => 'neuer Text',
                ],
            ],
        ]);
        $history = location_selection::page_data($user, null, null)['history'];
        $this->assertFalse($history['empty']);
        $this->assertSame([
            [
                'date' => userdate(1700000099),
                'target' => get_string('locationselectiontabmaterialstore', 'local_coursepilot'),
                'from' => 'alter Text',
                'to' => 'neuer Text',
            ],
            [
                'date' => userdate(1700000040),
                'target' => get_string('locationselectiontabcontextarea', 'local_coursepilot'),
                'from' => get_string('locationselectionlocationmoodle', 'local_coursepilot', 'alt'),
                'to' => get_string('locationselectionlocationmoodle', 'local_coursepilot', 'neu'),
            ],
        ], $history['entries']);
    }

    /**
     * Provides history data: a missing date is reported as the epoch and legacy text stays string-typed.
     *
     * @return void
     */
    public function test_history_entry_without_date_or_text_uses_epoch_and_empty_strings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [
                ['target' => 'context_area', 'from_text' => 42],
            ],
        ]);
        $entries = location_selection::page_data($user, null, null)['history']['entries'];
        $this->assertSame([
            [
                'date' => userdate(0),
                'target' => get_string('locationselectiontabcontextarea', 'local_coursepilot'),
                'from' => '42',
                'to' => '',
            ],
        ], $entries);
    }

    /**
     * Checks that a non-string legacy target text is returned as a string.
     *
     * @return void
     */
    public function test_history_legacy_to_text_is_returned_as_string(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [
                ['target' => 'context_area', 'to_text' => 7],
            ],
        ]);
        $entries = location_selection::page_data($user, null, null)['history']['entries'];
        $this->assertSame('7', $entries[0]['to']);
    }

    /**
     * Checks that a non-numeric history date falls back to the epoch instead of reaching userdate() unchanged.
     *
     * @return void
     */
    public function test_history_non_numeric_date_falls_back_to_epoch(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [
                ['date' => 'abc', 'target' => 'context_area', 'from_text' => 'a', 'to_text' => 'b'],
            ],
        ]);
        $entries = location_selection::page_data($user, null, null)['history']['entries'];
        $this->assertSame(userdate(0), $entries[0]['date']);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Provides history dates at minute boundaries: a one second shift changes the displayed minute.
     *
     * @return void
     */
    public function test_history_dates_are_shown_exactly_at_minute_boundaries(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [
                ['date' => 1700000040, 'target' => 'context_area', 'from_text' => 'a', 'to_text' => 'b'],
                ['date' => 1700000099, 'target' => 'context_area', 'from_text' => 'c', 'to_text' => 'd'],
            ],
        ]);
        $entries = location_selection::page_data($user, null, null)['history']['entries'];
        $this->assertSame(userdate(1700000099), $entries[0]['date']);
        $this->assertSame(userdate(1700000040), $entries[1]['date']);
        $this->assertNotSame(userdate(1700000039), $entries[1]['date']);
        $this->assertNotSame(userdate(1700000100), $entries[0]['date']);
    }
}
