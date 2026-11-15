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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exact reconciliation output of the privacy surface check and the registered
 * function listing (#674): violation details, duplicate handling and ordering.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(privacy_surface::class)]
final class privacy_surface_674_final_contract_test extends \advanced_testcase {
    /**
     * Keep only the forbidden-component violations of a check result.
     *
     * @param string[] $registered Registered function names to check.
     * @return array<int, array{type: string, name: string, detail: string}>
     */
    private static function forbidden_violations(array $registered): array {
        return array_values(array_filter(
            privacy_surface::check($registered),
            static fn(array $violation): bool => $violation['type'] === 'forbidden_token'
        ));
    }

    /**
     * A forbidden component is reported with the exact name and the exact detail text.
     */
    public function test_forbidden_component_violation_has_exact_detail(): void {
        $this->assertSame(
            [[
                'type' => 'forbidden_token',
                'name' => 'mod_assign_get_submissions',
                'detail' => 'Contains the forbidden component "submission".',
            ]],
            self::forbidden_violations(['mod_assign_get_submissions'])
        );
    }

    /**
     * The forbidden component is quoted verbatim in the detail, even for a mixed-case name.
     */
    public function test_mixed_case_name_reports_the_lowercase_component(): void {
        $this->assertSame(
            [[
                'type' => 'forbidden_token',
                'name' => 'Local_Coursepilot_Get_GRADES',
                'detail' => 'Contains the forbidden component "grade".',
            ]],
            self::forbidden_violations(['Local_Coursepilot_Get_GRADES'])
        );
    }

    /**
     * A name carrying a forbidden component is reported once, however often it is registered.
     */
    public function test_duplicate_forbidden_name_is_reported_once(): void {
        $this->assertCount(
            1,
            self::forbidden_violations(['mod_assign_get_submissions', 'mod_assign_get_submissions'])
        );
    }

    /**
     * A name matching several components is reported once per component.
     */
    public function test_name_with_two_components_is_reported_per_component(): void {
        $details = array_column(
            self::forbidden_violations(['core_enrol_get_participants']),
            'detail'
        );

        $this->assertSame([
            'Contains the forbidden component "participant".',
            'Contains the forbidden component "enrol".',
        ], $details);
    }

    /**
     * The registered function listing comes back sorted, whatever order the database returns.
     */
    public function test_registered_functions_are_listed_sorted(): void {
        global $DB;

        $this->resetAfterTest();

        $service = $DB->get_record('external_services', ['shortname' => privacy_surface::SERVICE_SHORTNAME]);
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $service->id,
            'functionname' => 'local_coursepilot_zz_sort_probe',
        ]);
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $service->id,
            'functionname' => 'local_coursepilot_aa_sort_probe',
        ]);

        $registered = privacy_surface::registered_functions();
        $probes = array_values(array_filter(
            $registered,
            static fn(string $name): bool => str_contains($name, 'sort_probe')
        ));

        $this->assertSame(
            ['local_coursepilot_aa_sort_probe', 'local_coursepilot_zz_sort_probe'],
            $probes
        );
        $sorted = $registered;
        sort($sorted);
        $this->assertSame($sorted, $registered);
    }
}
