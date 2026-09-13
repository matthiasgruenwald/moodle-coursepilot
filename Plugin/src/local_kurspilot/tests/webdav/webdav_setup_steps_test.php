<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\webdav;

use local_kurspilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Der Schrittkatalog ohne angemeldete Person (Issue #505 Befund #1): die
 * CLI ruft `admin/cli/checks.php` mit `$USER->id = 0` auf. `context_user::
 * instance(0)` wirft dort `dml_missing_record`, was den ganzen CLI-Lauf
 * abbricht. Der Katalog darf fuer die Person ohne ID nur "nein" melden,
 * nicht werfen - die Systemschritte 1 und 2 sind ohnehin personenunabhaengig.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(webdav_setup_steps::class)]
final class webdav_setup_steps_test extends \advanced_testcase {
    use webdav_instance_fixture;

    public function test_catalog_does_not_throw_for_userid_zero(): void {
        $this->resetAfterTest();
        $this->enable_webdav_repository_type();

        $steps = webdav_setup_steps::catalog(0);

        $this->assertFalse($steps[webdav_setup_steps::STEP_CAPABILITY]['ok']);
    }

    public function test_catalog_still_reports_capability_for_real_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_webdav_instance($user);

        $steps = webdav_setup_steps::catalog((int) $user->id);

        $this->assertIsBool($steps[webdav_setup_steps::STEP_CAPABILITY]['ok']);
    }

    /**
     * Issue #528: Schritt 2 (Nutzerinstanzen erlaubt) und Schritt 3 (Recht)
     * werten unabhaengig von Schritt 1 (Repository aktiv) aus - ein
     * ausgeschaltetes Repository darf die bereits gesetzte Konfiguration von
     * Schritt 2 nicht als "fehlend" melden.
     */
    public function test_step_two_and_three_stay_ok_when_step_one_is_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_webdav_instance($user);
        $this->grant_webdav_capability($user);
        // has_capability() im Katalog prueft ohne dritten Parameter $USER,
        // nicht $userid (dieselbe Konvention wie die drei WebDAV-Checks) -
        // die angemeldete Testperson muss deshalb die geprüfte sein.
        $this->setUser($user);

        global $DB;
        $DB->set_field('repository', 'visible', 0, ['type' => 'webdav']);

        $steps = webdav_setup_steps::catalog((int) $user->id);

        $this->assertFalse($steps[webdav_setup_steps::STEP_REPOSITORY_ACTIVE]['ok']);
        $this->assertTrue($steps[webdav_setup_steps::STEP_USER_INSTANCES]['ok']);
        $this->assertTrue($steps[webdav_setup_steps::STEP_CAPABILITY]['ok']);
    }

    /**
     * enabled_for_user() bleibt trotz der Entkopplung in {@see catalog()}
     * die UND-Verknuepfung aller drei Schritte (Issue #528).
     */
    public function test_enabled_for_user_still_requires_all_three_steps(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_webdav_instance($user);
        $this->grant_webdav_capability($user);
        $this->setUser($user);

        global $DB;
        $DB->set_field('repository', 'visible', 0, ['type' => 'webdav']);

        $this->assertFalse(webdav_setup_steps::enabled_for_user((int) $user->id));
    }
}
