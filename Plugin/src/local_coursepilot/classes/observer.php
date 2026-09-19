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

use local_coursepilot\history\retention;
use local_coursepilot\history\version_writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Beobachter fuer den Aenderungsverlauf (#385, Spec 0015 §10.8): serialisiert
 * nur den Ist-Stand in die Schnappschuss-Tabellen, ruft weder MCP-Werkzeuge
 * noch Webservices auf. Loescht ausserdem den Verlauf mit, wenn eine
 * Aktivitaet oder ein Kurs geloescht wird (#387, Spec 0015 §10.7).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class observer {

    /**
     * Version 1 entsteht beim Anlegen (#386, Spec 0015 §10.3): fuer Aktivitaeten,
     * die es erst seit Einfuehrung des Verlaufs gibt, greift beim ersten
     * course_module_updated deshalb nicht mehr die Vorgefunden-Logik.
     *
     * @param \core\event\course_module_created $event
     * @return void
     */
    public static function course_module_created(\core\event\course_module_created $event): void {
        version_writer::capture((int) $event->objectid, (int) $event->userid);
    }

    /**
     * @param \core\event\course_module_updated $event
     * @return void
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        version_writer::capture_on_update((int) $event->objectid, (int) $event->userid);
    }

    /**
     * Aktivitaets-Kaskade (#387): eine geloeschte Aktivitaet nimmt ihren
     * Verlauf mit. Der Papierkorb haelt den Inhalt ohnehin sieben Tage als
     * .mbz - der Aenderungsverlauf ist kein zweiter Papierkorb.
     *
     * @param \core\event\course_module_deleted $event
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        retention::purge_cm((int) $event->objectid);
    }

    /**
     * Kurs-Kaskade (#387): ein geloeschter Kurs nimmt seinen Verlauf mit.
     * course_modules ist zu diesem Zeitpunkt bereits geloescht - die
     * Zuordnung laeuft ueber die mitgeschriebene courseid, siehe
     * {@see \local_coursepilot\history\version_writer::capture()}.
     *
     * @param \core\event\course_deleted $event
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        retention::purge_course((int) $event->objectid);
    }

    /**
     * Der Anordnungs-Stand eines Tests (#396, Spec 0015 §10): ein Beobachter
     * fuer alle 16 mod_quiz-Struktur-Ereignisse (siehe db/events.php), die
     * quiz_slots/question_references/quiz_sections/quiz_feedback aendern
     * koennen. Alle 16 werden mit derselben Ereignis-Kontextklasse
     * (Modulkontext, siehe structure.php: durchgaengig
     * $this->quizobj->get_context()) ausgeloest - die cmid steckt deshalb
     * immer im Kontext, nicht in einem modulspezifisch unterschiedlichen
     * Event-Feld. Nutzt denselben capture_on_update()-Weg wie
     * course_module_updated (#385): eine Bestandsaktivitaet ohne bisherigen
     * Verlauf bekommt dabei zuerst rueckwirkend eine Vorgefunden-Version 1.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function quiz_structure_changed(\core\event\base $event): void {
        $cmid = (int) $event->get_context()->instanceid;
        version_writer::capture_on_update($cmid, (int) $event->userid);
    }
}
