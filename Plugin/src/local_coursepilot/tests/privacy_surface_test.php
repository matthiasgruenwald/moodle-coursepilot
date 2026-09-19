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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Vertragstest: real registrierte Oberflaeche <-> Allowlist <-> verbotene
 * Namensbestandteile (Abnahmekriterium 2 aus #309, Vertrag aus #300).
 *
 * Vorbild: test/data-protection-contract.test.js von local_coursepilot. Der
 * Unterschied und der Grund fuer PHPUnit: dieser Test prueft nicht die
 * Repo-Quelle, sondern die auf der laufenden Instanz **registrierte**
 * Oberflaeche - er faengt damit den Fall, dass ein Admin dem Dienst
 * nachtraeglich eine Funktion anhaengt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(privacy_surface::class)]
final class privacy_surface_test extends \advanced_testcase {

    /**
     * Die real registrierte Oberflaeche entspricht dem Vertrag.
     */
    public function test_registered_surface_matches_contract(): void {
        $this->resetAfterTest();

        $registered = privacy_surface::registered_functions();
        $this->assertNotEmpty($registered, 'Der Coursepilot-Dienst hat keine registrierten Funktionen.');

        $violations = privacy_surface::check($registered);
        $this->assertSame([], $violations, self::describe($violations));
    }

    /**
     * Eine nachtraeglich angehaengte Funktion faellt auf - der Fall, den kein
     * Repo-Test fangen kann (#300, Punkt 1).
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
     * Eine in der Allowlist stehende, aber nicht registrierte Funktion faellt
     * ebenfalls auf - sonst behauptet die Allowlist eine Oberflaeche, die es
     * nicht gibt.
     */
    public function test_missing_registration_is_detected(): void {
        $violations = privacy_surface::check([]);

        $this->assertSame(['missing'], array_unique(array_column($violations, 'type')));
    }

    /**
     * Die verbotenen Bestandteile greifen auf registrierten Namen, unabhaengig
     * von Gross-/Kleinschreibung.
     *
     * @param string $name
     */
    #[DataProvider('forbidden_name_provider')]
    public function test_forbidden_tokens_are_rejected(string $name): void {
        $violations = privacy_surface::check([$name]);

        $this->assertContains('forbidden_token', array_column($violations, 'type'), $name . ' haette auffallen muessen.');
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
     * Kein registrierter Coursepilot-Name traegt einen verbotenen Bestandteil.
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
                    $name . ' enthaelt den verbotenen Bestandteil "' . $token . '".'
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
