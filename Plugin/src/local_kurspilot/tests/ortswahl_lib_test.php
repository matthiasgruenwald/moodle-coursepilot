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

use local_kurspilot\tests\webdav\fake_webdav_transport;
use local_kurspilot\tests\webdav\webdav_instance_fixture;
use local_kurspilot\webdav\webdav_instance;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Die Logik der Ortswahlseite (Issue #494, Spec #486 §5/§10) - getestet ueber
 * ihre Klasse mit dem WebDAV-Transport-Fake, nie ueber die Seite selbst
 * (Issue #494 Akzeptanzkriterium).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(ortswahl_lib::class)]
final class ortswahl_lib_test extends \advanced_testcase {
    use webdav_instance_fixture;

    public function test_setup_state_is_not_enabled_without_freischaltung(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $state = ortswahl_lib::setup_state((int) $user->id);

        $this->assertSame('not_enabled', $state['state']);
    }

    public function test_setup_state_is_no_instance_when_enabled_but_none_owned(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->enable_webdav_repository_type();

        $state = ortswahl_lib::setup_state((int) $user->id);

        $this->assertSame('no_instance', $state['state']);
    }

    public function test_setup_state_is_ready_with_instance_and_freischaltung(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->create_webdav_instance($user);

        $state = ortswahl_lib::setup_state((int) $user->id);

        $this->assertSame('ready', $state['state']);
    }

    public function test_setup_state_prefers_not_enabled_over_no_instance(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        // Weder Freischaltung noch Instanz - "nicht freigeschaltet" gilt,
        // nicht "keine Instanz".
        $this->enable_webdav_repository_type();

        $state = ortswahl_lib::setup_state((int) $user->id);

        $this->assertSame('not_enabled', $state['state']);
    }

    public function test_missing_steps_text_names_only_failing_steps(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_webdav_repository_type();
        // Schritt 1+2 erfuellt, Schritt 3 (Capability) fehlt.

        $text = ortswahl_lib::missing_steps_text((int) $user->id);

        $this->assertStringContainsString('repository/webdav:view', $text);
        $this->assertStringNotContainsString('Nutzerinstanzen erlauben', $text);
    }

    public function test_own_instances_excludes_foreign_instances(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $this->grant_webdav_capability($owner);
        $ownid = $this->create_webdav_instance($owner);
        $this->setUser($other);
        $this->grant_webdav_capability($other);
        $this->create_webdav_instance($other);
        $this->setUser($owner);

        $instances = ortswahl_lib::own_instances();

        $this->assertCount(1, $instances);
        $this->assertSame($ownid, $instances[0]['id']);
    }

    public function test_current_defaults_to_configured_moodle_root_without_pointer(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $kontext = ortswahl_lib::current('kontextbereich');
        $material = ortswahl_lib::current('materialbestand');

        $this->assertSame('moodle', $kontext['ort']);
        $this->assertSame('kurspilot', $kontext['pfad']);
        $this->assertSame('moodle', $material['ort']);
        $this->assertSame('kurspilot-material', $material['pfad']);
    }

    public function test_history_is_empty_without_pointer(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame([], ortswahl_lib::history());
    }

    public function test_browse_lists_folders_only_and_ignores_files(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        $fake->seed_file('/' . $this->fixturebasispfad . '/notiz.md', 'Inhalt');

        try {
            $result = ortswahl_lib::browse($this->lastinstanceid, '');
            $this->assertSame('', $result['path']);
            $this->assertSame([['name' => 'Unterricht']], $result['folders']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_browse_of_missing_folder_returns_empty_list(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();

        try {
            $result = ortswahl_lib::browse($this->lastinstanceid, 'nicht-vorhanden');
            $this->assertSame([], $result['folders']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_browse_rejects_foreign_instance(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $this->grant_webdav_capability($owner);
        $instanceid = $this->create_webdav_instance($owner);

        $attacker = $this->getDataGenerator()->create_user();
        $this->setUser($attacker);
        $this->grant_webdav_capability($attacker);

        $this->expectException(\moodle_exception::class);
        ortswahl_lib::browse($instanceid, '');
    }

    public function test_apply_writes_nothing_when_both_targets_stay_in_moodle(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $changed = ortswahl_lib::apply([
            'kontextbereich' => ['type' => 'moodle'],
            'materialbestand' => ['type' => 'moodle'],
        ]);

        $this->assertSame([], $changed);
        $this->assertNull(storage_anchor::read_raw_pointer());
    }

    public function test_apply_creates_folder_chain_writes_pointer_and_history_line(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;

        try {
            $changed = ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Unterricht/Kontext'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $this->assertSame(['kontextbereich'], $changed);

            $mkcols = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'MKCOL'));
            $this->assertCount(2, $mkcols, 'Ebene fuer Ebene: "Unterricht", dann "Unterricht/Kontext".');

            $document = storage_anchor::read_raw_pointer();
            $this->assertSame('extern', $document['kontextbereich']['ort']);
            $this->assertSame($instanceid, $document['kontextbereich']['instanzid']);
            $this->assertSame('Unterricht/Kontext', $document['kontextbereich']['pfad']);
            $this->assertSame('moodle', $document['materialbestand']['ort']);
            $this->assertCount(1, $document['ortsverlauf']);
            $this->assertSame('kontextbereich', $document['ortsverlauf'][0]['ziel']);
            $this->assertArrayHasKey('datum', $document['ortsverlauf'][0]);
            $this->assertArrayHasKey('von', $document['ortsverlauf'][0]);
            $this->assertArrayHasKey('nach', $document['ortsverlauf'][0]);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_apply_is_a_noop_and_does_not_duplicate_history_on_repeat(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $selection = [
            'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Kontext'],
            'materialbestand' => ['type' => 'moodle'],
        ];

        try {
            $this->assertSame(['kontextbereich'], ortswahl_lib::apply($selection));
            $this->assertSame([], ortswahl_lib::apply($selection));

            $document = storage_anchor::read_raw_pointer();
            $this->assertCount(1, $document['ortsverlauf']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_apply_aborts_without_saving_when_folder_creation_fails(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $fake->deny_auth();

        try {
            $this->expectException(\moodle_exception::class);
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Kontext'],
                'materialbestand' => ['type' => 'moodle'],
            ]);
        } finally {
            $this->assertNull(storage_anchor::read_raw_pointer());
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_apply_rejects_a_foreign_instance_in_the_selection(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $this->grant_webdav_capability($owner);
        $instanceid = $this->create_webdav_instance($owner);

        $attacker = $this->getDataGenerator()->create_user();
        $this->setUser($attacker);
        $this->grant_webdav_capability($attacker);

        $this->expectException(\moodle_exception::class);
        ortswahl_lib::apply([
            'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Kontext'],
            'materialbestand' => ['type' => 'moodle'],
        ]);
    }

    public function test_apply_root_of_instance_is_a_valid_selection(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;

        try {
            $changed = ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => ''],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $this->assertSame(['kontextbereich'], $changed);
            $mkcols = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'MKCOL'));
            $this->assertCount(0, $mkcols, 'Die Instanzwurzel selbst braucht kein MKCOL.');
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_open_with_access_requires_both_freischaltung_and_no_pointer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertFalse(ortswahl_lib::open_with_access((int) $user->id), 'Ohne Freischaltung kein Fakt.');

        $this->enable_webdav_repository_type();
        $this->grant_webdav_capability($user);
        $this->assertTrue(ortswahl_lib::open_with_access((int) $user->id), 'Freigeschaltet, Ortswahl offen.');

        storage_anchor::write_pointer('mein-kontext', 'mein-material');
        $this->assertFalse(ortswahl_lib::open_with_access((int) $user->id), 'Ortswahl nicht mehr offen, sobald ein Pointer existiert.');
    }

    /** @var int Instanz-ID der zuletzt von {@see prepare_instance()} angelegten Instanz. */
    private int $lastinstanceid = 0;

    /**
     * @return array{0: \stdClass, 1: fake_webdav_transport}
     */
    private function prepare_instance(): array {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->lastinstanceid = $this->create_webdav_instance($user);

        $fake = new fake_webdav_transport();
        // Der Basispfad der Instanz existiert auf dem echten Speicher
        // bereits (von der Lehrkraft/Administration angelegt) - der Fake
        // startet leer und braucht ihn deshalb als Testvorbereitung.
        $fake->seed_folder('/' . $this->fixturebasispfad);
        webdav_instance::use_test_transport($fake);

        return [$user, $fake];
    }
}
