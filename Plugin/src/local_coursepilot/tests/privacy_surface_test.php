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

namespace local_coursepilot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Contract between registered service surface, allowlist and forbidden
 * name fragments (acceptance #309 criterion 2, contract #300).
 *
 * Following data-protection-contract.test.js, but checking the running
 * instance's registrations rather than repository declarations. This
 * catches functions added to the service by an administrator.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(privacy_surface::class)]
final class privacy_surface_test extends \advanced_testcase {
    /**
     * The registered service surface matches the contract.
     */
    public function test_registered_surface_matches_contract(): void {
        $this->resetAfterTest();

        $registered = privacy_surface::registered_functions();
        $this->assertNotEmpty($registered, 'The Coursepilot service has no registered functions.');

        $violations = privacy_surface::check($registered);
        $this->assertSame([], $violations, self::describe($violations));
    }

    /**
     * Detect a function added after installation, which repository tests
     * cannot catch (#300 point 1).
     */
    public function test_function_added_to_service_by_admin_is_detected(): void {
        global $DB;

        $this->resetAfterTest();

        $service = $DB->get_record('external_services', ['shortname' => privacy_surface::SERVICE_SHORTNAME]);
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $service->id,
            'functionname' => 'core_enrol_get_enrolled_users',
        ]);

        $violations = privacy_surface::check(privacy_surface::registered_functions());

        $types = array_column($violations, 'type');
        $this->assertContains('unexpected', $types);
        $this->assertContains('forbidden_token', $types);
    }

    /**
     * Detect allowlisted functions missing from actual registration.
     */
    public function test_missing_registration_is_detected(): void {
        $violations = privacy_surface::check([]);

        $this->assertSame(['missing'], array_unique(array_column($violations, 'type')));
    }

    /**
     * Forbidden name fragments apply case-insensitively to registered names.
     *
     * @param string $name
     */
    #[DataProvider('forbidden_name_provider')]
    public function test_forbidden_tokens_are_rejected(string $name): void {
        $violations = privacy_surface::check([$name]);

        $this->assertContains('forbidden_token', array_column($violations, 'type'), $name . ' should have been noticed.');
    }

    /**
     * @return array<string, string[]>
     */
    public static function forbidden_name_provider(): array {
        return [
            'submission' => ['mod_assign_get_submissions'],
            'discussion' => ['mod_forum_get_forum_discussions'],
            'attempt' => ['mod_quiz_get_user_attempts'],
            'participant' => ['core_course_get_participants'],
            'enrol' => ['core_enrol_get_enrolled_users'],
            'grade' => ['gradereport_user_get_grade_items'],
            'user' => ['core_user_get_users'],
            'gemischte schreibweise' => ['Local_Coursepilot_Get_GRADES'],
        ];
    }

    /**
     * No registered Coursepilot name contains a forbidden fragment.
     */
    public function test_own_surface_carries_no_forbidden_token(): void {
        $names = array_merge(
            array_keys(privacy_surface::allowed_tools()),
            array_values(privacy_surface::allowed_tools())
        );

        foreach ($names as $name) {
            foreach (privacy_surface::FORBIDDEN_TOKENS as $token) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $token,
                    $name,
                    $name . ' contains the forbidden component "' . $token . '".'
                );
            }
        }
    }

    /**
     * @param array $violations
     * @return string
     */
    private static function describe(array $violations): string {
        return implode("\n", array_map(static function (array $violation): string {
            return $violation['type'] . ': ' . $violation['name'] . ' - ' . $violation['detail'];
        }, $violations));
    }
}
