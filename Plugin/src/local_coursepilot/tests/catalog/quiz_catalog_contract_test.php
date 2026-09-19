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

namespace local_coursepilot\catalog;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Katalog-gegen-Moodle-Vertragstest fuer mod_quiz (Ticket #383, Vorbild
 * assign_catalog_contract_test.php aus #382).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(quiz::class)]
final class quiz_catalog_contract_test extends \advanced_testcase {

    /**
     * Die sechs aufrufbaren Quiz-Quellen aus der Klassendoku, jede als
     * [callable-string, ist-statische-methode] - Existenznachweis ist Teil
     * dieses Vertragstests (Abnahmekriterium #383).
     *
     * @var array<int, array{0: string, 1: bool}>
     */
    private const EXPECTED_CALLABLE_SOURCES = [
        ['quiz_get_overdue_handling_options()', false],
        ['quiz_get_grading_options()', false],
        ['quiz_questions_per_page_options()', false],
        ['quiz_get_navigation_options()', false],
        ['\\mod_quiz\\access_manager::get_browser_security_choices()', true],
        ['\\question_engine::get_behaviour_options()', true],
    ];

    /**
     * Jede von quiz gefuehrte Datenbankspalte muss die reale Spaltenmenge
     * von {quiz} exakt ergeben - inklusive der modulweiten Sperrliste
     * (grade, sumgrades, password, acht review*-Bitmasken, die beiden
     * Vervollstaendigungsspalten).
     */
    public function test_quiz_table_columns_match_the_catalog(): void {
        global $DB;

        $this->resetAfterTest();

        $realcolumns = array_keys($DB->get_columns('quiz'));
        sort($realcolumns);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, quiz::fields()),
            quiz::blocklist(),
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        sort($known);

        $this->assertSame(
            $realcolumns,
            array_values(array_unique($known)),
            "Die Spalten der Tabelle 'quiz' und der Feldkatalog (quiz::fields()/blocklist()) sind "
                . 'auseinandergelaufen - Moodle hat vermutlich eine Spalte hinzugefuegt, entfernt oder umbenannt.'
        );
    }

    /**
     * Abnahmekriterium #383: der Eintrag traegt "schreibweg" ausdruecklich -
     * quiz bleibt laut ADR 0016 Einzelwerkzeug statt Formularweg.
     */
    public function test_schreibweg_is_update_quiz_settings(): void {
        $this->assertSame('update_quiz_settings', quiz::schreibweg());
    }

    /**
     * Abnahmekriterium #383: die sechs aufrufbaren Quiz-Quellen existieren
     * wirklich auf dieser Instanz - Funktionen wie statische Methoden.
     */
    public function test_the_six_callable_sources_exist(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/classes/access_manager.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $this->assertCount(6, self::EXPECTED_CALLABLE_SOURCES, 'Testannahme verletzt: es muessen sechs Quellen sein.');

        foreach (self::EXPECTED_CALLABLE_SOURCES as [$callable, $isstatic]) {
            $bare = rtrim($callable, '()');
            if ($isstatic) {
                [$class, $method] = explode('::', $bare);
                $this->assertTrue(
                    method_exists($class, $method),
                    "Referenzierte aufrufbare Quelle $callable existiert auf dieser Instanz nicht mehr."
                );
            } else {
                $this->assertTrue(
                    function_exists($bare),
                    "Referenzierte aufrufbare Quelle $callable existiert auf dieser Instanz nicht mehr."
                );
            }
        }

        // Jede der sechs Quellen taucht tatsaechlich in Feldern oder Pseudofeldern auf.
        $sources = array_map(
            static fn (field $f): string => $f->sourcecallable ?? '',
            array_merge(quiz::fields(), quiz::pseudofields())
        );
        foreach (self::EXPECTED_CALLABLE_SOURCES as [$callable, $unused]) {
            $this->assertContains($callable, $sources, "$callable ist in keinem Feld als sourcecallable referenziert.");
        }
    }

    /**
     * Abnahmekriterium #383: grade, sumgrades, password und die acht
     * review*-Bitmasken stehen auf der Sperrliste.
     */
    public function test_grade_sumgrades_password_and_review_bitmasks_are_blocked(): void {
        $blocklist = quiz::blocklist();

        $this->assertContains('grade', $blocklist);
        $this->assertContains('sumgrades', $blocklist);
        $this->assertContains('password', $blocklist);

        $reviewbitmasks = [
            'reviewattempt',
            'reviewcorrectness',
            'reviewmaxmarks',
            'reviewmarks',
            'reviewspecificfeedback',
            'reviewgeneralfeedback',
            'reviewrightanswer',
            'reviewoverallfeedback',
        ];
        $this->assertCount(8, $reviewbitmasks, 'Testannahme verletzt: es muessen acht Bitmasken sein.');
        foreach ($reviewbitmasks as $bitmask) {
            $this->assertContains($bitmask, $blocklist, "$bitmask fehlt auf der Sperrliste.");
        }
    }

    /**
     * Abnahmekriterium #383: die 32 review*-Booleans (acht Arten mal vier
     * Zeitpunkte) und "feedbacktext" sind als Pseudofelder gefuehrt, ebenso
     * "quizpassword".
     */
    public function test_pseudofields_carry_quizpassword_feedbacktext_and_32_review_booleans(): void {
        $pseudofields = quiz::pseudofields();
        $names = array_map(static fn (field $f): string => $f->name, $pseudofields);

        $this->assertContains('quizpassword', $names);
        $this->assertContains('feedbacktext', $names);

        $reviewtypes = ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback',
            'rightanswer', 'overallfeedback'];
        $timings = ['during', 'immediately', 'open', 'closed'];

        $reviewbooleans = [];
        foreach ($reviewtypes as $type) {
            foreach ($timings as $timing) {
                $reviewbooleans[] = $type . $timing;
            }
        }
        $this->assertCount(32, $reviewbooleans, 'Testannahme verletzt: es muessen 32 Kombinationen sein.');

        foreach ($reviewbooleans as $expected) {
            $this->assertContains($expected, $names, "$expected fehlt in den Pseudofeldern.");
        }
    }

    /**
     * Abnahmekriterium #383: wo Test und Aufgabe dasselbe Feld meinen,
     * heisst es gleich - "timelimit" ist in beiden Katalogen identisch
     * benannt (Spec 0015: "ein Vokabular, zwei Schreibwege").
     */
    public function test_shared_field_names_match_assign(): void {
        $quiznames = array_map(static fn (field $f): string => $f->name, quiz::fields());
        $assignnames = array_map(static fn (field $f): string => $f->name, assign::fields());

        $this->assertContains('timelimit', $quiznames);
        $this->assertContains('timelimit', $assignnames);

        // "Ein Vokabular, zwei Schreibwege" (#383): dasselbe Feld "timelimit", aber assign schreibt
        // ueber das Vehikel (schreibweg() === null) und quiz ueber ein Einzelwerkzeug.
        $this->assertNull(assign::schreibweg());
        $this->assertNotNull(quiz::schreibweg());
        $this->assertNotSame(assign::schreibweg(), quiz::schreibweg());
    }

    /**
     * Abnahmekriterium #383: die drei Modus-Buendel werden ausgeliefert.
     */
    public function test_the_three_mode_bundles_are_shipped(): void {
        $bundles = quiz::bundles();

        $this->assertArrayHasKey('mini-check', $bundles);
        $this->assertArrayHasKey('lernstandscheck', $bundles);
        $this->assertArrayHasKey('abschlusstest', $bundles);

        foreach (['mini-check', 'lernstandscheck', 'abschlusstest'] as $mode) {
            $this->assertNotEmpty($bundles[$mode], "Buendel $mode ist leer.");

            // Kein Buendel darf gesperrte Felder setzen - sonst wuerde ein Schreibvorgang, der das
            // Buendel unveraendert uebernimmt, gegen die eigene Sperrliste des Katalogs verstossen.
            $blocked = array_intersect(array_keys($bundles[$mode]), quiz::blocklist());
            $this->assertSame([], $blocked, "Buendel $mode setzt gesperrte Felder: " . implode(', ', $blocked));
        }
    }

    /**
     * Abnahmekriterium #383: der Katalog vermerkt, dass die Anordnung
     * (Fragen, Seiten, Abschnitte) nicht Teil dieses Katalogs ist.
     */
    public function test_catalog_notes_that_ordering_is_out_of_scope(): void {
        $reflection = new \ReflectionClass(quiz::class);
        $doccomment = $reflection->getDocComment();

        $this->assertNotFalse($doccomment);
        $this->assertStringContainsString('Anordnung', $doccomment);
        $this->assertStringContainsString('quiz_slots', $doccomment);
    }

    /**
     * Jedes Katalogfeld traegt eine deutsche Bedeutung und eine
     * Quellenangabe.
     */
    public function test_every_field_carries_a_german_meaning_and_source(): void {
        $fields = array_merge(quiz::fields(), quiz::pseudofields());
        $this->assertNotEmpty($fields);

        foreach ($fields as $f) {
            $this->assertNotSame('', trim($f->meaning), "Feld {$f->name} hat keine deutsche Bedeutung.");
            $this->assertNotEmpty($f->source, "Feld {$f->name} hat keine Quellenangabe.");
        }
    }

    /**
     * Kombinationsregeln aus validation() sind gefuehrt.
     */
    public function test_combination_rules_are_present(): void {
        $rules = quiz::combination_rules();
        $this->assertNotEmpty($rules);
        $joined = implode(' ', $rules);
        $this->assertStringContainsString('timeopen', $joined);
        $this->assertStringContainsString('graceperiod', $joined);
    }

    /**
     * Nebenwirkungsvermerk: Kalendereintraege (Abnahmekriterium #383).
     */
    public function test_side_effects_note_calendar_entries(): void {
        $notes = implode(' ', quiz::side_effects());
        $this->assertStringContainsString('Kalendereintrag', $notes);
    }
}
