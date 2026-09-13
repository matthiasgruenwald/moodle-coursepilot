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

    /**
     * Issue #507 (Spec #486, Review von #486): die Seitenzustaende und die
     * Zeitgrenze des Dateifenster-Abrufs sind benannte Konstanten statt
     * roher Werte - {@see ortswahl_lib::setup_state()} und
     * {@see \ortswahl_render.php} nutzen sie.
     */
    public function test_state_and_timeout_constants_have_the_expected_values(): void {
        $this->assertSame('not_enabled', ortswahl_lib::STATE_NOT_ENABLED);
        $this->assertSame('no_instance', ortswahl_lib::STATE_NO_INSTANCE);
        $this->assertSame('ready', ortswahl_lib::STATE_READY);
        $this->assertSame(8000, ortswahl_lib::BROWSE_TIMEOUT_MS);
    }

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

    /**
     * Issue #528 (Spec #486 §5, Befund #11 aus der Live-Abnahme #505): ein
     * ausgeschaltetes Repository (Schritt 1) darf nicht auch Schritt 2 und 3
     * als fehlend melden, wenn deren Konfiguration/Recht bereits gesetzt
     * sind - der Text an die Administration nennt sonst erledigte Schritte.
     */
    public function test_missing_steps_text_omits_already_satisfied_steps_when_step_one_is_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_webdav_instance($user);
        $this->grant_webdav_capability($user);

        global $DB;
        $DB->set_field('repository', 'visible', 0, ['type' => 'webdav']);

        $text = ortswahl_lib::missing_steps_text((int) $user->id);

        $this->assertStringContainsString('Repositories', $text);
        $this->assertStringNotContainsString('Nutzerinstanzen erlauben', $text);
        $this->assertStringNotContainsString('repository/webdav:view', $text);
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

    /**
     * "chosen" (Issue #525, Spec §5): ohne Pointer ("erste Einrichtung")
     * gilt kein Ziel als ausdruecklich gewaehlt, obwohl current() bereits
     * die Standardwurzel als Anzeigewert liefert - das Dateifenster-JS soll
     * hier weiterhin eine ausdrueckliche Wahl verlangen.
     */
    public function test_current_marks_target_as_not_chosen_without_pointer(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $kontext = ortswahl_lib::current('kontextbereich');
        $material = ortswahl_lib::current('materialbestand');

        $this->assertFalse($kontext['chosen']);
        $this->assertFalse($material['chosen']);
    }

    /**
     * Gegenstueck: ein Ziel, das der Pointer bereits ausdruecklich aufloest
     * (in Moodle oder extern), gilt als gewaehlt (Issue #525).
     */
    public function test_current_marks_target_as_chosen_with_pointer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Kontext');

        $kontext = ortswahl_lib::current('kontextbereich');
        $material = ortswahl_lib::current('materialbestand');

        $this->assertTrue($kontext['chosen']);
        $this->assertTrue($material['chosen']);
    }

    /**
     * "Zugelassen" fuer die Anzeige (Issue #500, ADR 0021 §3): Private
     * Files sind immer zugelassen, ohne dass `personaldatahosts` etwas
     * dazu sagen muss.
     */
    public function test_current_marks_moodle_location_as_allowed(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $kontext = ortswahl_lib::current('kontextbereich');

        $this->assertTrue($kontext['zugelassen']);
        $this->assertSame(
            get_string('ortswahlzugelassenja', 'local_kurspilot'),
            ortswahl_lib::zugelassen_label($kontext)
        );
    }

    /**
     * Ein externer Ort ist nur zugelassen, wenn sein Server in
     * `personaldatahosts` steht (Issue #500, ADR 0021 §3) - dieselbe
     * Pruefung wie {@see \local_kurspilot\admin\connection_ablageort}, hier
     * je Ziel fuer Zustimmungsdialog/"Meine Verbindungen"/Ortswahlseite.
     */
    public function test_current_marks_extern_location_as_not_allowed_without_configured_host(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Kontext');
        // personaldatahosts bleibt leer - der Instanzserver ist damit nicht zugelassen.

        $kontext = ortswahl_lib::current('kontextbereich');

        $this->assertFalse($kontext['zugelassen']);
        $this->assertSame(
            get_string('ortswahlzugelassennein', 'local_kurspilot'),
            ortswahl_lib::zugelassen_label($kontext)
        );
    }

    /**
     * Gegenstueck: ein externer Ort mit zugelassenem Server gilt als
     * zugelassen (Issue #500).
     */
    public function test_current_marks_extern_location_as_allowed_with_configured_host(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Kontext');
        set_config('personaldatahosts', $this->fixtureserver, 'local_kurspilot');

        $kontext = ortswahl_lib::current('kontextbereich');

        $this->assertTrue($kontext['zugelassen']);
    }

    /**
     * Der geteilte Datenschutz-Informationstext (Issue #500, Spec #486 §11),
     * der im Zustimmungsdialog, auf "Meine Verbindungen" und in der
     * Beschreibung von `personaldatahosts` erscheint, nennt alle vier
     * geforderten Fakten - ein Aenderungsrisiko an einer zentralen Stelle
     * statt eines stillen Auseinanderdriftens der drei Anzeigen.
     */
    public function test_external_location_privacy_info_names_all_four_facts(): void {
        // Die Testinstanz laeuft mit der Standardsprache "en" (kein
        // installiertes deutsches Sprachpaket) - get_string() liefert daher
        // den englischen Text von lang/en/local_kurspilot.php, dessen vier
        // Fakten wortgleich zu lang/de/ formuliert sind.
        $text = get_string('externallocationprivacyinfo', 'local_kurspilot');

        $this->assertStringContainsString('AI', $text);
        $this->assertStringContainsString('write lock', $text);
        $this->assertStringContainsString('no read lock', $text);
        $this->assertStringContainsString('Mounted shares', $text);
        $this->assertStringContainsString('cannot be told apart', $text);
        $this->assertStringContainsString('app password', $text);
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

    /**
     * Issue #518, Spec §5: die ausdrueckliche Uebergabe eines gefuellten
     * Kontextbereich-Ordners wird serverseitig geprueft, nicht nur im
     * Seitenskript - ein Aufruf ohne die Bestaetigung scheitert benannt.
     */
    public function test_apply_rejects_filled_context_folder_without_explicit_confirmation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Unterricht/notiz.md', 'Inhalt');

        try {
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Unterricht'],
                'materialbestand' => ['type' => 'moodle'],
            ]);
            $this->fail('ortswahlfolderconfirmrequired haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ortswahlfolderconfirmrequired', $e->errorcode);
        } finally {
            $this->assertNull(storage_anchor::read_raw_pointer());
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Gegenstueck: mit der ausdruecklichen Bestaetigung schliesst dieselbe
     * Auswahl erfolgreich ab.
     */
    public function test_apply_accepts_filled_context_folder_with_explicit_confirmation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Unterricht/notiz.md', 'Inhalt');

        try {
            $changed = ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Unterricht', 'confirmed' => true],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $this->assertSame(['kontextbereich'], $changed);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Ein leerer Ordner braucht keine Bestaetigung (Spec §5: "ein leerer ...
     * Ordner braucht keine Rueckfrage").
     */
    public function test_apply_accepts_empty_context_folder_without_confirmation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Leer');

        try {
            $changed = ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Leer'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $this->assertSame(['kontextbereich'], $changed);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Ein erneutes Abschliessen ohne Ortswechsel braucht keine erneute
     * Bestaetigung (Spec §5 gilt nur fuer "der neu gewaehlte Ordner").
     */
    public function test_apply_repeat_of_unchanged_filled_context_folder_needs_no_confirmation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Unterricht/notiz.md', 'Inhalt');
        $selection = [
            'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Unterricht', 'confirmed' => true],
            'materialbestand' => ['type' => 'moodle'],
        ];

        try {
            $this->assertSame(['kontextbereich'], ortswahl_lib::apply($selection));
            $selection['kontextbereich']['confirmed'] = false;
            $this->assertSame([], ortswahl_lib::apply($selection));
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

    public function test_apply_rejects_root_of_instance_as_selection(): void {
        // Issue #497, Spec #486 §5: "Die Wurzel jeder Instanz ist nicht
        // waehlbar" - revidiert das fruehere Verhalten (Issue #494 liess die
        // Wurzel noch zu, die Sperre kam erst in diesem Ticket).
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;

        try {
            $this->expectException(\moodle_exception::class);
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => ''],
                'materialbestand' => ['type' => 'moodle'],
            ]);
        } finally {
            $this->assertNull(storage_anchor::read_raw_pointer());
            webdav_instance::use_test_transport(null);
        }
    }

    // --- Issue #497: Sperren, IServ-Erkennung, Uebergabe eines gefuellten Ordners ---

    public function test_browse_root_is_not_selectable(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();

        try {
            $result = ortswahl_lib::browse($this->lastinstanceid, '');
            $this->assertFalse($result['selectable']);
            $this->assertNotSame('', $result['reason']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_browse_reports_iserv_no_in_nextcloud_mode(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');

        try {
            $root = ortswahl_lib::browse($this->lastinstanceid, '');
            $this->assertFalse($root['iserv']);

            $level = ortswahl_lib::browse($this->lastinstanceid, 'Unterricht');
            $this->assertFalse($level['iserv']);
            $this->assertTrue($level['selectable']);
            $this->assertSame('', $level['reason']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_browse_logs_when_iserv_detection_fails_and_reports_no(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        // Nur die zusaetzliche IServ-Erkennung auf der Wurzel scheitert - die
        // Hauptauflistung von "Unterricht" gelingt normal (Issue #506: ein
        // Netzfehler hier gilt als "nein", muss aber protokolliert werden).
        $fake->fail_once('/' . $this->fixturebasispfad, 401);
        $sink = $this->redirectEvents();

        try {
            $level = ortswahl_lib::browse($this->lastinstanceid, 'Unterricht');
            $this->assertFalse($level['iserv']);
        } finally {
            webdav_instance::use_test_transport(null);
        }

        $failures = array_filter($sink->get_events(), fn ($e) => $e instanceof \local_kurspilot\event\tool_access_failed);
        $sink->close();
        $this->assertNotEmpty(
            $failures,
            'Ein gescheiterter IServ-Check muss protokolliert werden, nicht schweigend als "nein" gelten.'
        );
    }

    public function test_browse_reports_iserv_yes_and_locks_everything_outside_files(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->as_iserv_root('/' . $this->fixturebasispfad);
        $fake->without_etags();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Files');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Files/Unterricht');

        try {
            $root = ortswahl_lib::browse($this->lastinstanceid, '');
            $this->assertTrue($root['iserv']);
            $this->assertFalse($root['selectable'], 'Die Wurzel bleibt zusaetzlich immer gesperrt.');
            $this->assertSame(
                ['Files', 'Groups', 'Print', 'Temp', 'Windows'],
                array_map(static fn (array $f): string => $f['name'], $root['folders'])
            );

            $groups = ortswahl_lib::browse($this->lastinstanceid, 'Groups');
            $this->assertTrue($groups['iserv']);
            $this->assertFalse($groups['selectable'], 'Ausserhalb von Files/ ist bei IServ nichts waehlbar.');
            $this->assertNotSame('', $groups['reason']);

            $files = ortswahl_lib::browse($this->lastinstanceid, 'Files');
            $this->assertTrue($files['iserv']);
            $this->assertTrue($files['selectable'], 'Unterhalb von Files/ bleibt bei IServ waehlbar.');

            $nested = ortswahl_lib::browse($this->lastinstanceid, 'Files/Unterricht');
            $this->assertTrue($nested['selectable']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_browse_reports_entrycount_and_first_names_for_confirmation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht/b-ordner');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Unterricht/a-datei.md', 'Inhalt');

        try {
            $result = ortswahl_lib::browse($this->lastinstanceid, 'Unterricht');
            $this->assertSame(2, $result['entrycount']);
            $this->assertSame(['a-datei.md', 'b-ordner'], $result['entrynames']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_browse_of_empty_folder_has_no_entries_to_confirm(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Leer');

        try {
            $result = ortswahl_lib::browse($this->lastinstanceid, 'Leer');
            $this->assertSame(0, $result['entrycount']);
            $this->assertSame([], $result['entrynames']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_own_instances_marks_instance_without_https_basic_as_not_selectable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->create_webdav_instance($user, ['webdav_auth' => 'digest']);

        $instances = ortswahl_lib::own_instances();

        $this->assertCount(1, $instances);
        $this->assertFalse($instances[0]['selectable']);
        $this->assertNotSame('', $instances[0]['reason']);
    }

    public function test_own_instances_marks_valid_instance_as_selectable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->create_webdav_instance($user);

        $instances = ortswahl_lib::own_instances();

        $this->assertTrue($instances[0]['selectable']);
        $this->assertSame('', $instances[0]['reason']);
    }

    public function test_apply_rejects_iserv_path_outside_files(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->as_iserv_root('/' . $this->fixturebasispfad);
        $instanceid = $this->lastinstanceid;

        try {
            $this->expectException(\moodle_exception::class);
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Groups'],
                'materialbestand' => ['type' => 'moodle'],
            ]);
        } finally {
            $this->assertNull(storage_anchor::read_raw_pointer());
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_apply_accepts_iserv_path_under_files_and_stores_iserv_flag(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $fake->as_iserv_root('/' . $this->fixturebasispfad);
        $instanceid = $this->lastinstanceid;

        try {
            $changed = ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Files/Unterricht'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $this->assertSame(['kontextbereich'], $changed);
            $document = storage_anchor::read_raw_pointer();
            $this->assertTrue($document['kontextbereich']['pruefmerkmal']['iserv']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    public function test_apply_rejects_materialbestand_inside_kontextbereich(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        $instanceid = $this->lastinstanceid;

        try {
            $this->expectException(\moodle_exception::class);
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Unterricht'],
                'materialbestand' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Unterricht/Material'],
            ]);
        } finally {
            $this->assertNull(storage_anchor::read_raw_pointer());
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

        storage_anchor::write_pointer_document([
            'kontextbereich' => 'mein-kontext',
            'materialordner' => 'mein-material',
        ]);
        $this->assertFalse(ortswahl_lib::open_with_access((int) $user->id), 'Ortswahl nicht mehr offen, sobald ein Pointer existiert.');
    }

    // --- Issue #498: Altbestand (vorheriger Ort) ---

    /**
     * Liegen am alten Moodle-Ort des Kontextbereichs Dateien, merkt sich der
     * Pointer den vorherigen Ort (Spec §5: "prueft die Seite, ob am alten
     * Ort Kontextdateien liegen. Nur dann merkt sich der Pointer den
     * vorherigen Ort.").
     */
    public function test_apply_records_previous_location_when_old_moodle_location_has_files(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot/',
            'filename' => 'vorlagen.md',
        ], '# Alt');

        try {
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $document = storage_anchor::read_raw_pointer();
            $this->assertSame('moodle', $document['vorheriger_ort']['ort']);
            $this->assertSame('kurspilot', $document['vorheriger_ort']['pfad']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Ein leerer alter Ort erzeugt keinen Altbestand.
     */
    public function test_apply_records_no_previous_location_when_old_location_is_empty(): void {
        $this->resetAfterTest();
        [, $fake] = $this->prepare_instance();

        try {
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $document = storage_anchor::read_raw_pointer();
            $this->assertArrayNotHasKey('vorheriger_ort', $document);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Ein Wechsel nur des Materialbestands erzeugt keinen Altbestand (Spec
     * §9: "Ein Wechsel nur des Materialbestands erzeugt nichts.") - selbst
     * wenn am alten Moodle-Materialordner Dateien liegen.
     */
    public function test_apply_records_no_previous_location_for_materialbestand_only_change(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot-material/',
            'filename' => 'bild.png',
        ], 'x');

        try {
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'moodle'],
                'materialbestand' => ['type' => 'extern', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
            ]);

            $document = storage_anchor::read_raw_pointer();
            $this->assertArrayNotHasKey('vorheriger_ort', $document);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Ein neuer Wechsel verdraengt einen bereits offenen Altbestand - es
     * gibt immer nur einen (Spec §9).
     */
    public function test_apply_replaces_an_already_open_previous_location(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Erst');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Erst/datei.md', 'Erst');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Zweit');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Zweit/datei.md', 'Zweit');
        webdav_instance::use_test_transport($fake);

        try {
            // Moodle -> Erst: kein alter Moodle-Ort mit Dateien -> kein Altbestand.
            // "Erst" enthaelt bereits eine Datei -> Uebergabe-Bestaetigung noetig (Issue #518).
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Erst', 'confirmed' => true],
                'materialbestand' => ['type' => 'moodle'],
            ]);
            $this->assertArrayNotHasKey('vorheriger_ort', storage_anchor::read_raw_pointer());

            // Erst -> Zweit: "Erst" enthaelt eine Datei -> wird zum Altbestand.
            // "Zweit" enthaelt ebenfalls bereits eine Datei -> Bestaetigung noetig.
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Zweit', 'confirmed' => true],
                'materialbestand' => ['type' => 'moodle'],
            ]);
            $document = storage_anchor::read_raw_pointer();
            $this->assertSame('Erst', $document['vorheriger_ort']['pfad']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Ein nicht mehr erreichbarer alter Ort (z.B. geloeschte Instanz) gilt
     * als "kein nachweisbarer Altbestand" - der Abschluss scheitert daran
     * nicht.
     */
    public function test_apply_treats_unreachable_old_location_as_no_previous_location(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $oldinstanceid = $this->create_webdav_instance($user);
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Alt');
        webdav_instance::use_test_transport($fake);

        try {
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $oldinstanceid, 'path' => 'Alt'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $DB->delete_records('repository_instances', ['id' => $oldinstanceid]);
            $newinstanceid = $this->create_webdav_instance($user);
            $fake->seed_folder('/' . $this->fixturebasispfad . '/Neu');

            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $newinstanceid, 'path' => 'Neu'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $document = storage_anchor::read_raw_pointer();
            $this->assertArrayNotHasKey('vorheriger_ort', $document);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * A -> B -> A: nach der Rueckkehr darf kein Altbestand mehr auf den
     * jetzt aktuellen Ort zeigen, auch wenn B leer war (Issue #517, Spec §9:
     * "Wer erneut wechselt, verdraengt ihn - auch wenn am verlassenen Ort
     * nichts lag").
     */
    public function test_apply_clears_previous_location_that_would_point_at_the_current_place(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot/',
            'filename' => 'vorlagen.md',
        ], '# A');
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        webdav_instance::use_test_transport($fake);

        try {
            // A (Moodle, mit Datei) -> B (extern, leer): A wird zum Altbestand.
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'B'],
                'materialbestand' => ['type' => 'moodle'],
            ]);
            $document = storage_anchor::read_raw_pointer();
            $this->assertSame('moodle', $document['vorheriger_ort']['ort']);
            $this->assertSame('kurspilot', $document['vorheriger_ort']['pfad']);

            // B (extern, leer) -> A (Moodle): B ist leer, verdraengt den
            // Altbestand trotzdem - sonst zeigte er wieder auf A, den jetzt
            // aktuellen Ort.
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'moodle'],
                'materialbestand' => ['type' => 'moodle'],
            ]);
            $document = storage_anchor::read_raw_pointer();
            $this->assertArrayNotHasKey('vorheriger_ort', $document);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Eine andere Datei (kein `.md`, kein Unterordner) am alten Ort erzeugt
     * fuer sich allein keinen Altbestand (Issue #517, Spec §9) - das bleibt
     * unveraendert (Issue #505 Befund #7 aendert nur den Unterordner-Fall,
     * siehe {@see test_apply_records_previous_location_when_old_location_has_only_a_subfolder()}).
     */
    public function test_apply_records_no_previous_location_when_old_location_has_only_non_context_entries(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot/',
            'filename' => 'notizen.txt',
        ], 'x');

        try {
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $document = storage_anchor::read_raw_pointer();
            $this->assertArrayNotHasKey('vorheriger_ort', $document);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Dasselbe wie oben, nur am alten *externen* Ort: eine andere Datei ohne
     * Unterordner erzeugt ebenfalls keinen Altbestand (Issue #517).
     */
    public function test_apply_records_no_previous_location_when_old_external_location_has_only_non_context_entries(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Alt');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Alt/notizen.txt', 'x');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Neu');
        webdav_instance::use_test_transport($fake);

        try {
            // "Alt" enthaelt nur eine Nicht-Kontextdatei, keinen Unterordner
            // -> Uebergabe-Bestaetigung noetig (Issue #518); "Neu" ist leer.
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Alt', 'confirmed' => true],
                'materialbestand' => ['type' => 'moodle'],
            ]);
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Neu'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $document = storage_anchor::read_raw_pointer();
            $this->assertArrayNotHasKey('vorheriger_ort', $document);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Ein Unterordner am alten *Moodle*-Ort begruendet allein schon
     * Altbestand (Issue #505 Befund #7): vorher wurde nur die oberste Ebene
     * geprueft und ein Ordner dort zaehlte nicht - Kontextdateien, die
     * ausschliesslich in Unterordnern lagen (z.B.
     * `2026-27/9a/biologie/immunsystem/journal.md`), blieben beim
     * Ortswechsel unbemerkt.
     */
    public function test_apply_records_previous_location_when_old_location_has_only_a_subfolder(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->prepare_instance();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot/9a/biologie/',
            'filename' => 'journal.md',
        ], '# Journal');

        try {
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $this->lastinstanceid, 'path' => 'Kontext'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $document = storage_anchor::read_raw_pointer();
            $this->assertSame('moodle', $document['vorheriger_ort']['ort']);
            $this->assertSame('kurspilot', $document['vorheriger_ort']['pfad']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }

    /**
     * Dasselbe am alten *externen* Ort (Issue #505 Befund #7): ein blosser
     * Unterordner in der obersten Ebene begruendet Altbestand, ohne dass die
     * oberste Ebene selbst hineingeschaut wird (Lehrkraft-Entscheidung: kein
     * rekursives PROPFIND).
     */
    public function test_apply_records_previous_location_when_old_external_location_has_only_a_subfolder(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Alt');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Alt/9a');
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Neu');
        webdav_instance::use_test_transport($fake);

        try {
            // "Alt" enthaelt nur einen Unterordner -> Uebergabe-Bestaetigung
            // noetig (Issue #518); "Neu" ist leer.
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Alt', 'confirmed' => true],
                'materialbestand' => ['type' => 'moodle'],
            ]);
            ortswahl_lib::apply([
                'kontextbereich' => ['type' => 'extern', 'instanceid' => $instanceid, 'path' => 'Neu'],
                'materialbestand' => ['type' => 'moodle'],
            ]);

            $document = storage_anchor::read_raw_pointer();
            $this->assertSame('extern', $document['vorheriger_ort']['ort']);
            $this->assertSame('Alt', $document['vorheriger_ort']['pfad']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
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
