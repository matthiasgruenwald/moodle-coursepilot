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

namespace local_coursepilot\external;

use core_external\external_api;
use local_coursepilot\pending_write_notice;

defined('MOODLE_INTERNAL') || die();

/**
 * Ausdruecklich verwerfen (Issue #492, ADR 0023 Punkt 3) - der zweite Weg,
 * auf dem ein Eintrag der Ausstandsnotiz verschwindet, neben dem
 * Nachtragen ueber `write_context_file`/`append_context_file`.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(dismiss_ausstand::class)]
final class dismiss_ausstand_test extends \advanced_testcase {

    /**
     * Ein vorhandener Eintrag verschwindet und die Antwort bestaetigt das.
     */
    public function test_dismisses_existing_entry(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $kennung = pending_write_notice::record('plan.md', 'anlegen', 'Speicher voll', 0);

        $result = dismiss_ausstand::execute($kennung);
        $result = external_api::clean_returnvalue(dismiss_ausstand::execute_returns(), $result);

        $this->assertSame($kennung, $result['kennung']);
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Eine unbekannte Kennung ist ein benannter Fehler, kein stiller Erfolg.
     */
    public function test_rejects_unknown_kennung(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            dismiss_ausstand::execute('UNBEKANNT1');
            $this->fail('Unbekannte Kennung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandunknown', $e->errorcode);
        }
    }

    /**
     * Ohne moodle/user:manageownfiles kein Zugriff - dasselbe Recht wie bei
     * den beiden Schreibendpunkten.
     */
    public function test_rejects_missing_manageownfiles_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $kennung = pending_write_notice::record('plan.md', 'anlegen', 'Speicher voll', 0);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        dismiss_ausstand::execute($kennung);
    }

    /**
     * Person A verwirft nie einen Eintrag von Person B - schon die Kennung
     * ist nur im eigenen Bestand bekannt.
     */
    public function test_person_a_cannot_dismiss_person_bs_entry(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teachera);
        $kennung = pending_write_notice::record('plan.md', 'anlegen', 'Speicher voll', 0);

        $this->setUser($teacherb);
        try {
            dismiss_ausstand::execute($kennung);
            $this->fail('Fremde Kennung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandunknown', $e->errorcode);
        }
    }

    /**
     * Der Endpunkt haengt am Coursepilot-Dienst und steht in der Allowlist.
     */
    public function test_registered_in_service_and_allowlist(): void {
        $this->assertArrayHasKey(
            'coursepilot_dismiss_ausstand',
            \local_coursepilot\privacy_surface::allowed_tools()
        );
        $this->assertContains(
            'local_coursepilot_dismiss_ausstand',
            \local_coursepilot\tool_registry::service_function_names()
        );
        $this->assertTrue(\local_coursepilot\tool_registry::is_write('coursepilot_dismiss_ausstand'));
    }
}
