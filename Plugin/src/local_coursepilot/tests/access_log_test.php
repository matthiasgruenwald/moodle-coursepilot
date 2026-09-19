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
 * Protokollstufen (#339): vier Stufen, Voreinstellung "Lesezugriffe und
 * Fehler", keine Geheimnisse im Protokolltext.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(access_log::class)]
final class access_log_test extends \advanced_testcase {

    /**
     * Frische Installation, Konfigwert nie gesetzt: Voreinstellung ist
     * "Lesezugriffe und Fehler".
     */
    public function test_default_level_is_reads_and_errors(): void {
        $this->resetAfterTest();

        $this->assertSame(access_log::LEVEL_READS, access_log::current_level());
    }

    /**
     * Stufe "kein Protokoll": weder Erfolg noch Fehler erzeugen einen Eintrag.
     */
    public function test_level_none_logs_nothing(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_NONE, 'local_coursepilot');
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');
        access_log::log_failure('AUTHENTICATION_FAILED');

        $this->assertCount(0, $sink->get_events());
        $sink->close();
    }

    /**
     * Stufe "nur Fehler": Erfolg erzeugt keinen Eintrag, Fehler schon.
     */
    public function test_level_errors_only_skips_success_but_logs_failure(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_ERRORS, 'local_coursepilot');
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');
        access_log::log_failure('AUTHENTICATION_FAILED');

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(event\tool_access_failed::class, $events[0]);
        $sink->close();
    }

    /**
     * Stufe "Lesezugriffe und Fehler": beides erzeugt einen Eintrag.
     */
    public function test_level_reads_logs_success_and_failure(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_READS, 'local_coursepilot');
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');
        access_log::log_failure('AUTHENTICATION_FAILED');

        $events = $sink->get_events();
        $this->assertCount(2, $events);
        $sink->close();
    }

    /**
     * Stufe "Alles": beides erzeugt ebenfalls einen Eintrag.
     */
    public function test_level_all_logs_success_and_failure(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_ALL, 'local_coursepilot');
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');
        access_log::log_failure('AUTHENTICATION_FAILED');

        $events = $sink->get_events();
        $this->assertCount(2, $events);
        $sink->close();
    }

    /**
     * Stufe "Schreibzugriffe und Fehler" (#388): ein Schreibzugriff erzeugt
     * einen Eintrag, ein Lesezugriff (noch) nicht - erst Stufe 2 (Lesen)
     * schaltet das dazu.
     */
    public function test_level_errors_logs_write_success_but_not_read_success(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_ERRORS, 'local_coursepilot');
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_update_module_settings', true);
        access_log::log_success('coursepilot_list_courses', false);

        $events = array_values($sink->get_events());
        $this->assertCount(1, $events);
        $this->assertSame('coursepilot_update_module_settings', $events[0]->other['toolname']);
        $sink->close();
    }

    /**
     * Die uebliche Moodle-Merkmale (crud, edulevel, Kontext, Komponente)
     * sind gesetzt, damit Filterung/Berichte funktionieren.
     */
    public function test_success_event_carries_usual_moodle_characteristics(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');

        $event = $sink->get_events()[0];
        $this->assertSame('r', $event->crud);
        $this->assertSame('local_coursepilot', $event->component);
        $this->assertSame(\context_system::instance()->id, $event->contextid);
        $this->assertSame('coursepilot_list_courses', $event->other['toolname']);
        $sink->close();
    }

    /**
     * Materialvorgaenge sind nachvollziehbar (Spec 0018 §9.2, #428): das
     * Ereignis fuehrt den Dateipfad mit, wenn der Aufrufer einen mitgibt.
     */
    public function test_success_event_carries_path_when_given(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_upload_material_file', true, 'screenshot.png');

        $event = $sink->get_events()[0];
        $this->assertSame('screenshot.png', $event->other['path']);
        $sink->close();
    }

    /**
     * Werkzeuge ohne Dateipfad (die meisten) protokollieren weiterhin ohne
     * Fehler - der Pfad ist optional, kein Pflichtfeld.
     */
    public function test_success_event_path_is_null_when_not_given(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');

        $event = $sink->get_events()[0];
        $this->assertNull($event->other['path']);
        $sink->close();
    }

    /**
     * Kein Zugangsgeheimnis landet im Protokolltext - der Grundtext ist
     * ein fester Code/Text, kein Token.
     */
    public function test_failure_event_never_contains_a_secret_looking_token(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        $token = 'sk-'.str_repeat('a', 40);
        access_log::log_failure('AUTHENTICATION_FAILED');

        $event = $sink->get_events()[0];
        $encoded = json_encode($event->get_data());
        $this->assertStringNotContainsString($token, $encoded);
        $this->assertStringNotContainsString('sk-', $encoded);
        $sink->close();
    }
}
