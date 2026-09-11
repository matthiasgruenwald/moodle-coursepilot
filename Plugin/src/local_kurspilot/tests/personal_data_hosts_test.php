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

namespace local_kurspilot;

use local_kurspilot\admin\personaldatahosts_setting;

/**
 * Zugelassene Speicher fuer personenbezogene Kontextdaten (Issue #493, ADR
 * 0021 §3): Domain samt Unterdomains, Ablehnung einstelliger Eintraege und
 * von "*" beim Speichern.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(personal_data_hosts::class)]
final class personal_data_hosts_test extends \advanced_testcase {

    /**
     * Eine leere Liste heisst: kein externer Speicher zugelassen.
     */
    public function test_empty_list_allows_nothing(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', '', 'local_kurspilot');

        $this->assertFalse(personal_data_hosts::allowed('cloud.example.test'));
    }

    /**
     * Ein exakt genannter Host ist zugelassen.
     */
    public function test_exact_match_is_allowed(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "cloud.example.test", 'local_kurspilot');

        $this->assertTrue(personal_data_hosts::allowed('cloud.example.test'));
    }

    /**
     * Ein Eintrag gilt fuer eine Domain samt Unterdomains.
     */
    public function test_entry_covers_subdomains(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test", 'local_kurspilot');

        $this->assertTrue(personal_data_hosts::allowed('cloud.example.test'));
        $this->assertTrue(personal_data_hosts::allowed('nextcloud.schule.example.test'));
    }

    /**
     * Getrennt wird nur an Punkten - "anders-example.test" ist keine
     * Unterdomain von "example.test".
     */
    public function test_only_dot_separated_suffix_matches(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test", 'local_kurspilot');

        $this->assertFalse(personal_data_hosts::allowed('anders-example.test'));
    }

    /**
     * Ein nicht genannter Host bleibt abgelehnt.
     */
    public function test_unrelated_host_is_not_allowed(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test", 'local_kurspilot');

        $this->assertFalse(personal_data_hosts::allowed('anderer-speicher.test'));
    }

    /**
     * Mehrere Zeilen sind alle wirksam.
     */
    public function test_multiple_lines_all_apply(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test\nschulcloud.test", 'local_kurspilot');

        $this->assertTrue(personal_data_hosts::allowed('cloud.example.test'));
        $this->assertTrue(personal_data_hosts::allowed('schulcloud.test'));
    }

    /**
     * Ein Eintrag mit nur einem Namensteil wird abgelehnt.
     */
    public function test_rejects_single_label_entry(): void {
        $this->assertSame('localhost', personal_data_hosts::first_invalid_entry('localhost'));
    }

    /**
     * "*" ist nicht Teil der Syntax und wird abgelehnt.
     */
    public function test_rejects_wildcard_entry(): void {
        $this->assertSame('*.example.test', personal_data_hosts::first_invalid_entry('*.example.test'));
    }

    /**
     * Ein gueltiger Eintrag mit mindestens zwei Namensteilen wird akzeptiert.
     */
    public function test_accepts_valid_entry(): void {
        $this->assertNull(personal_data_hosts::first_invalid_entry('cloud.example.test'));
    }

    /**
     * Mehrere Zeilen: der erste ungueltige Eintrag wird genannt.
     */
    public function test_reports_first_invalid_entry_among_several_lines(): void {
        $this->assertSame(
            'localhost',
            personal_data_hosts::first_invalid_entry("cloud.example.test\nlocalhost\nschulcloud.test")
        );
    }

    /**
     * Eine leere Eingabe ist gueltig (leere Liste).
     */
    public function test_empty_input_is_valid(): void {
        $this->assertNull(personal_data_hosts::first_invalid_entry(''));
        $this->assertNull(personal_data_hosts::first_invalid_entry("\n\n"));
    }

    /**
     * Die Einstellungsseite (Issue #493) lehnt einen einstelligen Eintrag
     * beim Speichern ab - dieselbe Regel, ueber den Admin-Setting-Vertrag.
     */
    public function test_admin_setting_rejects_single_label_entry_on_save(): void {
        $this->resetAfterTest();
        $setting = new personaldatahosts_setting('local_kurspilot/personaldatahosts', 'x', 'y', '', PARAM_RAW);

        $this->assertIsString($setting->validate('localhost'));
    }

    /**
     * Ein gueltiger Eintrag wird von der Einstellungsseite akzeptiert.
     */
    public function test_admin_setting_accepts_valid_entry(): void {
        $this->resetAfterTest();
        $setting = new personaldatahosts_setting('local_kurspilot/personaldatahosts', 'x', 'y', '', PARAM_RAW);

        $this->assertTrue($setting->validate('cloud.example.test'));
    }
}
