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

/**
 * Template data for the location-selection pages.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\output;

use local_coursepilot\location_selection as selection;
use local_coursepilot\previous_location;
use local_coursepilot\storage_anchor;

/**
 * Prepares display-neutral state for the location-selection templates.
 */
final class location_selection {
    /**
     * Provides editor data.
     *
     * @param string[] $oauthpassthrough
     * @return array<string, mixed>
     */
    public static function editor_data(array $oauthpassthrough = []): array {
        return [
            'actionurl' => (new \moodle_url('/local/coursepilot/location_selection.php'))->out(false),
            'sesskey' => sesskey(),
            'oauthflow' => $oauthpassthrough !== [],
            'oauthpassthrough' => self::oauth_fields($oauthpassthrough),
            'targets' => self::targets(),
        ];
    }

    /**
     * Configuration for amd/src/location_selection.js (Issue #551, Spec 0023): reaches
     * the module via $PAGE->requires->js_call_amd(), not via embedded data in
     * the page source.
     *
     * @param \stdClass $user
     * @return array<string, mixed>
     */
    public static function amd_configuration(\stdClass $user): array {
        // Page_state() includes the full unbounded location history.
        // AMD does not use it; history is already rendered by the server.
        // Send only the instances actually needed here.
        $state = selection::page_state((int) $user->id);
        return [
            'instances' => $state['instances'],
            'targets' => [
                'context_area' => selection::current('context_area'),
                'material_store' => selection::current('material_store'),
            ],
            'browseurl' => (new \moodle_url('/local/coursepilot/location_selection_browse.php'))->out(false),
            'manageinstancesurl' => (new \moodle_url('/repository/manage_instances.php', [
                'contextid' => storage_anchor::own_context()->id,
            ]))->out(false),
            'sesskey' => sesskey(),
            'timeoutms' => selection::BROWSE_TIMEOUT_MS,
        ];
    }

    /**
     * Provides targets.
     *
     * @return list<array<string, mixed>>
     */
    private static function targets(): array {
        $targets = [];
        foreach (selection::TARGETS as $index => $target) {
            $targets[] = [
                'name' => $target,
                'first' => $index === 0,
                'context_area' => $target === 'context_area',
                'fields' => array_map(static fn(string $suffix): array => [
                    'name' => $target . '_' . $suffix,
                    'id' => 'coursepilot-location-selection-' . $target . '_' . $suffix,
                ], ['type', 'instanceid', 'path', 'confirmed']),
            ];
        }
        return $targets;
    }

    /**
     * Provides oauth fields.
     *
     * @param string[] $oauthpassthrough
     * @return list<array{name: string, value: string}>
     */
    private static function oauth_fields(array $oauthpassthrough): array {
        $fields = [];
        foreach ($oauthpassthrough as $name => $value) {
            $fields[] = ['name' => $name, 'value' => $value];
        }
        return $fields;
    }

    /**
     * Provides current locations data.
     *
     * @return array<string, array<string, string>>
     */
    public static function current_locations_data(): array {
        $locations = [];
        foreach (selection::TARGETS as $target) {
            $location = selection::current($target);
            $locations[$target] = [
                'label' => get_string(
                    'locationselectioncurrent' . str_replace('_', '', $target),
                    'local_coursepilot',
                    $location['display']
                ),
                'allowancelabel' => selection::allowed_label($location),
            ];
        }
        return $locations;
    }

    /**
     * Provides page data.
     *
     * @param \stdClass $user The user.
     * @param ?array $finishresult Type: array{type:string,text:string}|null.
     * @param ?array $oauthreturn Type: array{client:\stdClass,params:array<string,string>}|null.
     * @return array<string, mixed>
     */
    public static function page_data(\stdClass $user, ?array $finishresult, ?array $oauthreturn): array {
        $setup = selection::setup_state((int) $user->id);
        $data = [
            'finishresult' => self::notification_data($finishresult),
            'oauthreturn' => self::oauth_return_data($oauthreturn),
            'previouslocationopen' => previous_location::open(),
            'notenabled' => $setup['state'] === selection::STATE_NOT_ENABLED,
            'noinstance' => $setup['state'] === selection::STATE_NO_INSTANCE,
            'ready' => $setup['state'] === selection::STATE_READY,
            'missingstepstext' => selection::missing_steps_text((int) $user->id),
            'supporturl' => (new \moodle_url('/admin/settings.php', ['section' => 'supportcontact']))->out(false),
            'schoolhint' => selection::school_hint(),
            'editor' => self::editor_data($oauthreturn['params'] ?? []),
            'locations' => self::current_locations_data(),
            'history' => self::history_data(),
        ];
        return $data;
    }

    /**
     * Provides notification data.
     *
     * @param ?array $finishresult Type: array{type:string,text:string}|null.
     * @return array{class: string, text: string}|null
     */
    private static function notification_data(?array $finishresult): ?array {
        if ($finishresult === null) {
            return null;
        }
        $class = match ($finishresult['type']) {
            \core\output\notification::NOTIFY_SUCCESS => 'alert-success',
            \core\output\notification::NOTIFY_ERROR => 'alert-danger',
            \core\output\notification::NOTIFY_WARNING => 'alert-warning',
            default => 'alert-info',
        };
        return ['class' => $class, 'text' => $finishresult['text']];
    }

    /**
     * Provides oauth return data.
     *
     * @param ?array $oauthreturn Type: array{client:\stdClass,params:array<string,string>}|null.
     * @return array{clientname: string, backurl: string}|null
     */
    private static function oauth_return_data(?array $oauthreturn): ?array {
        if ($oauthreturn === null) {
            return null;
        }
        $client = $oauthreturn['client'];
        return [
            'clientname' => $client->clientname ?: $client->clientid,
            'backurl' => (new \moodle_url('/local/coursepilot/oauth/authorize.php', $oauthreturn['params']))->out(false),
        ];
    }

    /**
     * Provides history data.
     *
     * @return array{empty: bool, entries: list<array<string, string>>}
     */
    private static function history_data(): array {
        $entries = [];
        foreach (array_reverse(selection::history()) as $entry) {
            $entries[] = [
                'date' => userdate((int) ($entry['date'] ?? 0)),
                'target' => get_string(
                    $entry['target'] === 'context_area' ? 'locationselectiontabcontextarea' : 'locationselectiontabmaterialstore',
                    'local_coursepilot'
                ),
                'from' => isset($entry['from'])
                    ? selection::describe_location($entry['from'])
                    : (string) ($entry['from_text'] ?? ''),
                'to' => isset($entry['to']) ? selection::describe_location($entry['to']) : (string) ($entry['to_text'] ?? ''),
            ];
        }
        return ['empty' => $entries === [], 'entries' => $entries];
    }
}
