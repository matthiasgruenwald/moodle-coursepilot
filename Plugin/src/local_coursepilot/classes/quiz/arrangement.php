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

namespace local_coursepilot\quiz;

defined('MOODLE_INTERNAL') || die();

/**
 * Anordnungs-Stand eines Tests (#396, Spec 0015 §10, ADR 0016): Slots +
 * Fragereferenzen, Abschnitte und Feedback - der Teil eines Test-Standes, der
 * NICHT ueber den Feldkatalog (Ticket #383) laeuft, weil er nicht in der
 * quiz-Tabelle steht (siehe {@see \local_coursepilot\catalog\quiz} Klassendoku
 * "Anordnung ist nicht Teil dieses Katalogs").
 *
 * Zurueckgeschrieben wird ausschliesslich ueber die Kern-Struktur-API
 * (mod_quiz\structure::move_slot/update_slot_version/update_question_dependency/
 * update_slot_maxmark/update_slot_display_number, set_section_heading/
 * set_section_shuffle) - keine rohen quiz_slots-UPDATEs. quiz_feedback hat
 * keine solche API: Moodle selbst (mod/quiz/lib.php: quiz_after_add_or_update())
 * schreibt diese Tabelle per delete+insert, {@see self::restore_feedback()}
 * repliziert genau das.
 *
 * ponytail: eine reine Umsortierung bestehender Slots ist der getragene
 * Regelfall - ein Slot, der im Zielstand vorkommt, im aktuellen Stand aber
 * nicht mehr existiert (Frage seither entfernt) oder umgekehrt, ist ein
 * Inhaltswechsel, keine Anordnungsfrage (siehe
 * {@see \local_coursepilot\history\version_history} GAPS_HINT: "Quiz-Inhalt
 * jenseits der Anordnung ... nicht erfasst"). Solche Slots werden beim
 * Rueckschreiben stillschweigend uebersprungen statt per remove_slot/
 * add_question nachgebildet zu werden - Erweiterung erst, wenn Spec 0017
 * (Fragenanordnung als eigenes Werkzeug) das Zusammenspiel mit Inhaltsaenderungen
 * tatsaechlich braucht.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class arrangement {

    /**
     * Schnappt Slots (mit Fragereferenz), Abschnitte und Feedback eines Tests.
     * Das ist die Form, die {@see \local_coursepilot\history\version_writer}
     * unveraendert als "arrangement_json" ablegt und {@see self::restore()}
     * unveraendert zurueckbekommt.
     *
     * @param int $quizid
     * @return array{slots: array, sections: array, feedback: array}
     */
    public static function capture(int $quizid): array {
        global $DB;

        $slots = $DB->get_records_sql(
            'SELECT s.id, s.page, s.displaynumber, s.requireprevious, s.maxmark,
                    qr.questionbankentryid, qr.version
               FROM {quiz_slots} s
          LEFT JOIN {question_references} qr
                 ON qr.component = ? AND qr.questionarea = ? AND qr.itemid = s.id
              WHERE s.quizid = ?
           ORDER BY s.slot',
            ['mod_quiz', 'slot', $quizid]
        );
        $sections = $DB->get_records(
            'quiz_sections',
            ['quizid' => $quizid],
            'firstslot',
            'id, firstslot, heading, shufflequestions'
        );
        $feedback = $DB->get_records(
            'quiz_feedback',
            ['quizid' => $quizid],
            'mingrade',
            'id, feedbacktext, feedbacktextformat, mingrade, maxgrade'
        );

        return [
            'slots' => array_values(array_map(static fn (\stdClass $slot): array => [
                'id' => (int) $slot->id,
                'page' => (int) $slot->page,
                'displaynumber' => (string) ($slot->displaynumber ?? ''),
                'requireprevious' => (int) $slot->requireprevious,
                'maxmark' => (float) $slot->maxmark,
                'questionbankentryid' => $slot->questionbankentryid === null ? null : (int) $slot->questionbankentryid,
                // NULL heisst "immer aktuellste Fassung" - bewusst nicht auf einen
                // Wert gepinnt (Spec 0015 §Schutzschiene Fragereferenzen).
                'version' => $slot->version === null ? null : (int) $slot->version,
            ], $slots)),
            'sections' => array_values(array_map(static fn (\stdClass $section): array => [
                'firstslot' => (int) $section->firstslot,
                'heading' => $section->heading,
                'shufflequestions' => (int) $section->shufflequestions,
            ], $sections)),
            'feedback' => array_values(array_map(static fn (\stdClass $row): array => [
                'feedbacktext' => $row->feedbacktext,
                'feedbacktextformat' => (int) $row->feedbacktextformat,
                'mingrade' => (float) $row->mingrade,
                'maxgrade' => (float) $row->maxgrade,
            ], $feedback)),
        ];
    }

    /**
     * @param array $current
     * @param array $target
     * @return bool
     */
    public static function differs(array $current, array $target): bool {
        return json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== json_encode($target, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Schreibt einen gespeicherten Anordnungs-Stand zurueck. Schutzschiene
     * Versuche (Spec 0015): quiz_has_attempts() wird VOR jedem Schreibversuch
     * geprueft und bricht mit einer eigenen, klaren Meldung ab - nicht als
     * abgefangene coding_exception aus structure::check_can_be_edited().
     *
     * @param int $quizid
     * @param array $target Anordnungs-Stand wie von {@see self::capture()} geliefert.
     * @throws \moodle_exception arrangementrestoreblocked, wenn der Test bereits Versuche hat.
     */
    public static function restore(int $quizid, array $target): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        if (quiz_has_attempts($quizid)) {
            throw new \moodle_exception('arrangementrestoreblocked', 'local_coursepilot', '', ['quizid' => $quizid]);
        }

        $quizobj = \mod_quiz\quiz_settings::create($quizid);
        $currentids = array_flip($DB->get_fieldset_select('quiz_slots', 'id', 'quizid = ?', [$quizid]));
        $targetslots = array_values(array_filter(
            $target['slots'],
            static fn (array $slot): bool => isset($currentids[$slot['id']])
        ));

        self::restore_slot_order($quizobj, $targetslots);
        self::restore_page_breaks($quizobj, $targetslots);
        self::restore_slot_fields($quizobj, $targetslots);
        self::restore_sections($quizobj, $target['sections']);
        self::restore_feedback($quizid, $target['feedback']);
    }

    /**
     * Bringt die Slots in Zielreihenfolge, ueber structure::move_slot() fuer
     * jeden Slot einmal, in Zielreihenfolge nach dem jeweils zuvor
     * platzierten Slot einsortiert. move_slot() erklaert die
     * Struktur-Instanz nach jedem Aufruf fuer ungueltig (siehe deren
     * Klassendoku), deshalb wird sie danach frisch geholt.
     *
     * Der Seitenwert dieses Aufrufs uebernimmt bewusst nur die Seite des
     * bereits platzierten Vorgaengers (nicht die Zielseite): move_slot()
     * prueft die Zielseite gegen die aktuellen nicht immer schon fertigen
     * Nachbarn und lehnt einen "Sprung" auf eine hoehere Seite fuer den
     * letzten Slot ab, selbst wenn genau das der Zielstand ist. Die
     * tatsaechliche Seitenaufteilung setzt danach {@see self::restore_page_breaks()}
     * ueber update_page_break() - das ist ohnehin der von Moodle selbst
     * verwendete Mechanismus fuer Seitenumbrueche, keine Abkuerzung.
     *
     * @param \mod_quiz\quiz_settings $quizobj
     * @param array $targetslots Nur Slots, die auch aktuell existieren.
     * @return void
     */
    private static function restore_slot_order(\mod_quiz\quiz_settings $quizobj, array $targetslots): void {
        $previousid = 0;
        foreach ($targetslots as $targetslot) {
            $structure = \mod_quiz\structure::create_for_quiz($quizobj);
            $page = $previousid === 0 ? 1 : $structure->get_slot_by_id($previousid)->page;
            $structure->move_slot($targetslot['id'], $previousid, $page);
            $previousid = $targetslot['id'];
        }
    }

    /**
     * Seitenumbrueche zwischen benachbarten Zielslots - ueber
     * update_page_break() je Slotgrenze (LINK entfernt den Umbruch VOR dem
     * uebergebenen Slot, UNLINK fuegt ihn ein - siehe dessen Klassendoku "id
     * of slot which we will add/remove the page break before"), nicht ueber
     * eine absolute Seitenzahl.
     *
     * @param \mod_quiz\quiz_settings $quizobj
     * @param array $targetslots In Zielreihenfolge, bereits umsortiert (siehe {@see self::restore_slot_order()}).
     * @return void
     */
    private static function restore_page_breaks(\mod_quiz\quiz_settings $quizobj, array $targetslots): void {
        for ($i = 1; $i < count($targetslots); $i++) {
            $previousslot = $targetslots[$i - 1];
            $currentslot = $targetslots[$i];
            $type = $currentslot['page'] > $previousslot['page'] ? \mod_quiz\repaginate::UNLINK : \mod_quiz\repaginate::LINK;
            \mod_quiz\structure::create_for_quiz($quizobj)->update_page_break($currentslot['id'], $type);
        }
    }

    /**
     * Requireprevious/Notenmaximum/Anzeigenummer/Fragereferenz-Version je
     * Slot - exakt wie gespeichert, inklusive version=null ("immer
     * aktuellste"), das bleibt unveraendert null statt auf die zum
     * Erfassungszeitpunkt aktuelle Fassung gepinnt zu werden.
     *
     * @param \mod_quiz\quiz_settings $quizobj
     * @param array $targetslots
     * @return void
     */
    private static function restore_slot_fields(\mod_quiz\quiz_settings $quizobj, array $targetslots): void {
        $structure = \mod_quiz\structure::create_for_quiz($quizobj);
        foreach ($targetslots as $targetslot) {
            $slot = $structure->get_slot_by_id($targetslot['id']);

            if ((int) $slot->requireprevious !== $targetslot['requireprevious']) {
                $structure->update_question_dependency($slot->id, (bool) $targetslot['requireprevious']);
            }
            if (abs((float) $slot->maxmark - $targetslot['maxmark']) > 1e-7) {
                $structure->update_slot_maxmark($slot, $targetslot['maxmark']);
            }
            if ((string) ($slot->displaynumber ?? '') !== $targetslot['displaynumber']) {
                $structure->update_slot_display_number($slot->id, $targetslot['displaynumber']);
            }
            // Immer aufrufen (nicht nur bei Abweichung): update_slot_version()
            // ist selbst ein No-Op ohne Aenderung und ist die einzige Stelle,
            // die version=null exakt erhaelt statt sie ueber einen eigenen
            // Vergleich zu interpretieren.
            $structure->update_slot_version($slot->id, $targetslot['version']);
        }
    }

    /**
     * Ueberschrift/Mischen je Abschnitt, positionsweise zugeordnet (nach dem
     * Slot-Rueckschreiben liegen die firstslot-Werte der Abschnitte bereits
     * richtig - move_slot() verschiebt Abschnittsgrenzen automatisch mit,
     * siehe quiz_update_section_firstslots() in structure::move_slot()).
     * Eine abweichende Abschnittsanzahl ist wie ein fehlender Slot ein
     * Inhaltswechsel und wird uebersprungen.
     *
     * @param \mod_quiz\quiz_settings $quizobj
     * @param array $targetsections
     * @return void
     */
    private static function restore_sections(\mod_quiz\quiz_settings $quizobj, array $targetsections): void {
        $structure = \mod_quiz\structure::create_for_quiz($quizobj);
        $currentsections = array_values($structure->get_sections());

        if (count($currentsections) !== count($targetsections)) {
            return;
        }

        foreach ($currentsections as $index => $currentsection) {
            $targetsection = $targetsections[$index];
            if ((string) ($currentsection->heading ?? '') !== (string) ($targetsection['heading'] ?? '')) {
                $structure->set_section_heading($currentsection->id, $targetsection['heading']);
            }
            if ((int) $currentsection->shufflequestions !== $targetsection['shufflequestions']) {
                $structure->set_section_shuffle($currentsection->id, $targetsection['shufflequestions']);
            }
        }
    }

    /**
     * quiz_feedback hat keine Struktur-API (auch Moodle-Kern schreibt sie in
     * mod/quiz/lib.php: quiz_after_add_or_update() per delete+insert) - dieser
     * Restore repliziert exakt dasselbe Muster statt eine eigene
     * Schreibweise zu erfinden.
     *
     * @param int $quizid
     * @param array $targetfeedback
     * @return void
     */
    private static function restore_feedback(int $quizid, array $targetfeedback): void {
        global $DB;

        if (self::feedback_matches($quizid, $targetfeedback)) {
            return;
        }

        $DB->delete_records('quiz_feedback', ['quizid' => $quizid]);
        foreach ($targetfeedback as $row) {
            $DB->insert_record('quiz_feedback', (object) array_merge($row, ['quizid' => $quizid]));
        }
    }

    /**
     * @param int $quizid
     * @param array $targetfeedback
     * @return bool
     */
    private static function feedback_matches(int $quizid, array $targetfeedback): bool {
        $current = self::capture($quizid)['feedback'];
        return json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === json_encode($targetfeedback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
