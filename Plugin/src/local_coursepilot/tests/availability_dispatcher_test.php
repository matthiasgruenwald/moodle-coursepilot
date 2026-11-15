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

use local_coursepilot\history\version_history;
use local_coursepilot\history\version_writer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unknown availability plugin data never reaches any public MCP projection.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(availability_privacy::class)]
#[CoversClass(dispatcher::class)]
final class availability_dispatcher_test extends \advanced_testcase {
    public function test_catalog_settings_and_both_history_states_mask_unknown_conditions(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enableavailability = true;
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = connection::issue_code('privacy-test', (int) $USER->id, 'https://client.example/cb', $challenge);
        $token = connection::exchange_code($code, 'privacy-test', 'https://client.example/cb', $verifier)['access_token'];
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $safe = [
            ['type' => 'completion', 'cm' => 123, 'e' => 1],
            ['type' => 'date', 'd' => '>=', 't' => 1700000000],
            ['type' => 'grade', 'id' => 456, 'min' => 50],
            ['type' => 'group', 'id' => 789],
            ['type' => 'grouping', 'id' => 321],
        ];
        $versions = [];
        foreach (['first-private-sentinel', 'second-private-sentinel'] as $index => $sentinel) {
            $conditions = $safe;
            $conditions[1]['t'] += $index;
            $conditions[] = ['op' => '|', 'c' => [['type' => 'stubtype', 'v' => $sentinel,
                'members' => ['private-plugin-member']]], 'showc' => [true]];
            $availability = json_encode(['op' => '&', 'c' => $conditions, 'showc' => array_fill(0, 6, true)]);
            // Deliberately bypass core's plugin evaluator: the unknown stub has no installed condition class.
            $DB->set_field('course_modules', 'availability', $availability, ['id' => $page->cmid]);
            $DB->set_field('course_sections', 'availability', $availability, ['course' => $course->id, 'section' => 0]);
            $versionid = version_writer::capture((int) $page->cmid, (int) $USER->id);
            $versions[] = (int) $DB->get_field('local_coursepilot_cm_version', 'version', ['id' => $versionid], MUST_EXIST);
        }
        $requests = [
            'get_course_catalog' => ['courseid' => (int) $course->id],
            'get_module_settings' => ['cmid' => (int) $page->cmid],
            'compare_activity_versions' => ['cmid' => (int) $page->cmid,
                'from_version' => $versions[0], 'to_version' => $versions[1]],
        ];
        foreach ($requests as $name => $arguments) {
            $response = dispatcher::handle(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                    'params' => ['name' => 'coursepilot_' . $name, 'arguments' => $arguments]],
                $token,
                ['origin' => null, 'pathinfo' => '', 'method' => 'POST']
            );
            $this->assertSame(200, $response['status']);
            $result = $response['body']['result'];
            $this->assertFalse($result['isError'] ?? false, json_encode($result));
            $encoded = json_encode($result);
            foreach (['first-private-sentinel', 'second-private-sentinel', 'private-plugin-member'] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }
            if ($name === 'get_course_catalog') {
                $states = [$result['structuredContent']['sections'][0]['availability'],
                    $result['structuredContent']['sections'][0]['modules'][0]['availability']];
            } else if ($name === 'get_module_settings') {
                $settings = json_decode($result['structuredContent']['settings_json'], true);
                $states = [$settings['availabilityconditionsjson']];
            } else {
                $changes = array_column($result['structuredContent']['changes'], null, 'field');
                $states = [json_decode($changes['availabilityconditionsjson']['before_json'], true),
                    json_decode($changes['availabilityconditionsjson']['after_json'], true)];
            }
            foreach ($states as $state) {
                $conditions = json_decode($state, true)['c'];
                $this->assertSame(['type' => 'stubtype', 'v' => '***'], $conditions[5]['c'][0]);
                foreach ([0, 2, 3, 4] as $index) {
                    $this->assertSame($safe[$index], $conditions[$index]);
                }
                $this->assertSame('date', $conditions[1]['type']);
                $this->assertSame('>=', $conditions[1]['d']);
                $this->assertContains($conditions[1]['t'], [1700000000, 1700000001]);
            }
        }
        // Public projection must preserve the raw snapshot for native restoration.
        $raw = version_history::state_at((int) $page->cmid, $versions[1]);
        $this->assertSame($availability, $raw['availabilityconditionsjson']);
    }
}
