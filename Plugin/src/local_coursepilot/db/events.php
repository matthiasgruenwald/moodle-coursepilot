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

/**
 * Aenderungsverlauf (#385/#386/#387, Spec 0015 §10): jedes course_module_updated
 * schnappt einen Vollstand, egal ob Formularweg, Kursseite,
 * Massenbearbeitung, Handaenderung oder spaeter ein Coursepilot-Schreibvorgang
 * ueber denselben Weg. course_module_created legt Version 1 direkt beim
 * Anlegen an (#386) - fuer Bestandsaktivitaeten ohne dieses Ereignis holt
 * course_module_updated die fehlende Version rueckwirkend als
 * Vorgefunden-Stand nach. course_module_deleted und course_deleted loeschen
 * den Verlauf mit (#387, Kaskade).
 *
 * Die 16 mod_quiz-Struktur-Ereignisse (#396, Spec 0015 §10): jedes davon kann
 * den Anordnungs-Stand eines Tests (quiz_slots+question_references,
 * quiz_sections, quiz_feedback) aendern - Reihenfolge, Seiten, Abschnitte,
 * Fragereferenz-Version, Sub-Notenzuordnung. Genau die Ereignisse, die
 * mod/quiz/classes/structure.php selbst ausloest (grep nach "::create([" in
 * dieser Datei) - slot_created (neue Frage) und quiz_repaginated/
 * quiz_grade_items_reordered (nicht aus structure.php ausgeloest bzw. in
 * Moodle 5.0.8 nirgends getriggert) zaehlen bewusst NICHT dazu: eine neue
 * Frage ist ein Inhaltswechsel, kein Anordnungswechsel (siehe
 * catalog\quiz-Klassendoku "Anordnung ist nicht Teil dieses Katalogs" und
 * version_history::GAPS_HINT "Quiz-Inhalt jenseits der Anordnung ... nicht
 * erfasst").
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$quizstructureevents = [
    '\mod_quiz\event\slot_moved',
    '\mod_quiz\event\slot_deleted',
    '\mod_quiz\event\slot_mark_updated',
    '\mod_quiz\event\slot_version_updated',
    '\mod_quiz\event\slot_grade_item_updated',
    '\mod_quiz\event\slot_requireprevious_updated',
    '\mod_quiz\event\slot_displaynumber_updated',
    '\mod_quiz\event\page_break_created',
    '\mod_quiz\event\page_break_deleted',
    '\mod_quiz\event\section_break_created',
    '\mod_quiz\event\section_break_deleted',
    '\mod_quiz\event\section_title_updated',
    '\mod_quiz\event\section_shuffle_updated',
    '\mod_quiz\event\quiz_grade_item_created',
    '\mod_quiz\event\quiz_grade_item_updated',
    '\mod_quiz\event\quiz_grade_item_deleted',
];

$observers = [
    [
        'eventname' => '\core\event\course_module_created',
        'callback' => '\local_coursepilot\observer::course_module_created',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => '\local_coursepilot\observer::course_module_updated',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\local_coursepilot\observer::course_module_deleted',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\local_coursepilot\observer::course_deleted',
    ],
];

foreach ($quizstructureevents as $quizstructureevent) {
    $observers[] = [
        'eventname' => $quizstructureevent,
        'callback' => '\local_coursepilot\observer::quiz_structure_changed',
    ];
}
