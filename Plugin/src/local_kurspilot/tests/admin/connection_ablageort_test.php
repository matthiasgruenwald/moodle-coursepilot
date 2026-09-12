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

namespace local_kurspilot\admin;

use local_kurspilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Die Spalte Ablageort der Verbindungsübersicht (Issue #499, Spec #486 §12):
 * Zustand je Ziel und Marker fuer nicht zugelassenen Speicher, offene
 * Ausstaende, offenen Altbestand und einen defekten Pointer - gelesen ohne
 * Netz und ohne Pfad.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(connection_ablageort::class)]
final class connection_ablageort_test extends \advanced_testcase {
    use webdav_instance_fixture;

    public function test_both_targets_are_offen_without_any_pointer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $description = connection_ablageort::describe((int) $user->id);

        $offen = get_string('ablageortoffen', 'local_kurspilot');
        $this->assertStringContainsString($offen, $description['targets']['kontextbereich']);
        $this->assertStringContainsString($offen, $description['targets']['materialbestand']);
        $this->assertSame([], $description['markers']);
    }

    public function test_marks_a_host_that_is_not_on_the_approved_list(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Kontext');
        // personaldatahosts bleibt leer - der Instanzserver ist damit nicht zugelassen.

        $description = connection_ablageort::describe((int) $user->id);

        $this->assertContains(get_string('ablageortmarkernichtzugelassen', 'local_kurspilot'), $description['markers']);
    }

    public function test_does_not_mark_an_approved_host(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Kontext');
        set_config('personaldatahosts', $this->fixtureserver, 'local_kurspilot');

        $description = connection_ablageort::describe((int) $user->id);

        $this->assertNotContains(get_string('ablageortmarkernichtzugelassen', 'local_kurspilot'), $description['markers']);
    }

    public function test_marks_an_open_altbestand(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->write_pointer_with_vorheriger_ort($user);

        $description = connection_ablageort::describe((int) $user->id);

        $this->assertContains(get_string('ablageortmarkeraltbestand', 'local_kurspilot'), $description['markers']);
    }

    public function test_marks_an_open_ausstand(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot/',
            'filename' => '.kurspilot-ausstand.json',
        ], json_encode(['ABCDEFGH' => ['zeitpunkt' => time(), 'pfad' => 'a.md', 'vorgang' => 'anlegen', 'fehlerklasse' => 'x']]));

        $description = connection_ablageort::describe((int) $user->id);

        $this->assertContains(get_string('ablageortmarkerausstand', 'local_kurspilot'), $description['markers']);
    }

    public function test_marks_a_broken_pointer_with_a_missing_instance(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->write_v2_pointer($user, 'kontextbereich', 999999, 'Kontext');

        $description = connection_ablageort::describe((int) $user->id);

        $expected = get_string('ablageortmarkerdefekt', 'local_kurspilot', get_string('ablageortdefektinstanzfehlt', 'local_kurspilot'));
        $this->assertContains($expected, $description['markers']);
    }

    public function test_does_not_reveal_the_chosen_path(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Unterricht/Geheim');

        $description = connection_ablageort::describe((int) $user->id);

        $this->assertStringNotContainsString('Unterricht', $description['targets']['kontextbereich']);
        $this->assertStringNotContainsString('Geheim', $description['targets']['kontextbereich']);
        $this->assertStringContainsString($this->fixtureserver, $description['targets']['kontextbereich']);
    }
}
