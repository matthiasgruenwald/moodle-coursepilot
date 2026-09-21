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

/**
 * Feldkatalog fuer mod_quiz (Spec 0015 §4.6, Ticket #383) - ein Vokabular,
 * zwei Schreibwege: {@see schreibweg()} liefert "update_quiz_settings", weil
 * quiz laut ADR 0016 eine begruendete Ausnahme vom Formularweg
 * (update_moduleinfo()) bleibt: Feldnamen decken sich nicht durchgaengig mit
 * Spaltennamen, "grade" ist ueber den Formularweg gar nicht aenderbar, und
 * die Substanz (Fragen/Seiten/Abschnitte) liegt in quiz_slots, nicht in
 * dieser Tabelle. Der Katalog selbst benennt trotzdem dieselben Felder wie
 * eine Aufgabe (assign), wo es dasselbe Feld ist - z.B. "timelimit" -, damit
 * eine Lehrkraft fuer den Test kein zweites Nachschlagewerk braucht.
 *
 * Fallstricke aus dem Bestand:
 * - **Sechs aufrufbare Quiz-Quellen** liefern hier Wertebereiche, keine davon
 *   ist abgeschrieben: quiz_get_overdue_handling_options(),
 *   quiz_get_grading_options(), quiz_questions_per_page_options(),
 *   quiz_get_navigation_options() (alle mod/quiz/locallib.php bzw. lib.php)
 *   sowie \mod_quiz\access_manager::get_browser_security_choices() und
 *   \question_engine::get_behaviour_options() (question/engine/lib.php).
 * - **"password" ist ueber den Formularweg NICHT unter seinem eigenen Namen
 *   erreichbar**: das Formularfeld heisst "quizpassword"
 *   (mod/quiz/mod_form.php:289-291, passwordunmask), erst
 *   data_preprocessing()/data_postprocessing() spiegeln es auf die Spalte
 *   "password". "password" steht deshalb auf der Sperrliste, "quizpassword"
 *   ist das Pseudofeld dafuer.
 * - **Die acht "review*"-Spalten sind reine Bitmasken**, ueber den
 *   Formularweg nicht direkt setzbar: quiz_process_options()
 *   (mod/quiz/lib.php) berechnet sie aus 32 Einzel-Checkboxen
 *   "<art><zeitpunkt>" (z.B. "attemptduring"), acht Arten (attempt,
 *   correctness, maxmarks, marks, specificfeedback, generalfeedback,
 *   rightanswer, overallfeedback) mal vier Zeitpunkte (during, immediately,
 *   open, closed) - siehe \mod_quiz\question\display_options::DURING &Co.
 *   Sperrliste fuer die acht Bitmasken, die 32 Einzel-Checkboxen sind die
 *   Pseudofelder.
 * - **"completionattemptsexhausted"/"completionminattempts"** sind wie
 *   assign::completionsubmit (#382) modulspezifische Vervollstaendigungsfelder
 *   mit Datenverlustrisiko ohne "completionunlocked"
 *   (mod/quiz/mod_form.php:531-541, data_postprocessing() setzt
 *   "completionminattempts" still auf 0 zurueck). Sperrliste statt stillem
 *   No-Op, wie bei assign.
 * - **"allowofflineattempts"** ist eine echte Spalte, aber kein Kernfeld des
 *   Formulars - sie kommt ausschliesslich vom Zugriffsregel-Plugin
 *   quizaccess_offlineattempts ueber access_manager::add_settings_form_fields()
 *   (mod/quiz/accessrule/offlineattempts/rule.php:100-107). Trotzdem ein
 *   ganz normaler Formularweg, deshalb Feld statt Sperrliste.
 * - **Die drei Modus-Buendel** (mini-check, lernstandscheck, abschlusstest)
 *   uebernehmen die Settings-Kombinationen aus dem aelteren
 *   local_coursepilot\external\create_quiz::mode_defaults(), aber nur fuer
 *   tatsaechlich schreibbare Felder/Pseudofelder dieses Katalogs - "grade"
 *   und die generischen "completion*"-Felder sind dort blockiert und fehlen
 *   deshalb im Buendel; die acht Bitmask-Bevorzugungen sind auf die 32
 *   Pseudofeld-Checkboxen uebersetzt.
 * - **Anordnung ist nicht Teil dieses Katalogs**: Fragen, Seiten und
 *   Abschnitte (quiz_slots/quiz_sections) laufen ueber die Kern-Struktur-API
 *   (\mod_quiz\structure), nicht ueber update_quiz_settings - siehe ADR 0016.
 *   Dieser Katalog beschreibt ausschliesslich die Instanz-Settings.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class quiz implements module_catalog {

    /**
     * Die acht Review-Arten (Spaltenname ohne "review"-Praefix => deutsche
     * Kurzbeschreibung), je mal vier Zeitpunkt-Suffixe "during"/"immediately"/
     * "open"/"closed" = die 32 Pseudofeld-Checkboxen (mod/quiz/mod_form.php:
     * self::$reviewfields, add_review_options_group()).
     *
     * @var array<string, string>
     */
    private const REVIEW_TYPES = [
        'attempt' => 'Ob der Testversuch selbst überhaupt einsehbar ist.',
        'correctness' => 'Ob die Richtig/Falsch-Kennzeichnung je Frage sichtbar ist.',
        'maxmarks' => 'Ob die maximal erreichbare Punktzahl je Frage sichtbar ist.',
        'marks' => 'Ob die erreichte Punktzahl je Frage sichtbar ist. Ohne "maxmarks" desselben '
            . 'Zeitpunkts wirkungslos.',
        'specificfeedback' => 'Ob das spezifische Feedback je Antwort sichtbar ist.',
        'generalfeedback' => 'Ob das allgemeine Feedback der Frage sichtbar ist.',
        'rightanswer' => 'Ob die richtige Antwort sichtbar ist.',
        'overallfeedback' => 'Ob das Gesamtfeedback des Tests sichtbar ist.',
    ];

    /**
     * Die vier Zeitpunkt-Suffixe in Formularreihenfolge, je mit deutscher
     * Bedeutung.
     *
     * @var array<string, string>
     */
    private const REVIEW_TIMINGS = [
        'during' => 'während des Versuchs',
        'immediately' => 'unmittelbar nach Abgabe',
        'open' => 'später, solange der Test noch offen ist',
        'closed' => 'nachdem der Test geschlossen wurde',
    ];

    public static function modname(): string {
        return 'quiz';
    }

    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Anzeigename des Tests.',
                true,
                null,
                null,
                null,
                'mod/quiz/mod_form.php:76-83 (PARAM_TEXT bzw. PARAM_CLEANHTML je nach $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Beschreibungstext (Intro) des Tests.',
                true,
                null,
                null,
                null,
                'mod/quiz/db/install.xml (quiz.intro, NOTNULL ohne DB-Default)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Textformat des Intros.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/quiz/db/install.xml (quiz.introformat)'
            ),
            new field(
                'timeopen',
                'PARAM_INT',
                'Unix-Zeitstempel: Test öffnet. 0 = kein Startzeitpunkt. Erzeugt einen Kalendereintrag '
                    . '(siehe Nebenwirkungen).',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:92-94 (date_time_selector, optional); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.timeopen)'
            ),
            new field(
                'timeclose',
                'PARAM_INT',
                'Unix-Zeitstempel: Test schließt. 0 = kein Endzeitpunkt. Erzeugt einen Kalendereintrag '
                    . '(siehe Nebenwirkungen).',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:96-97 (date_time_selector, optional); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.timeclose)'
            ),
            new field(
                'timelimit',
                'PARAM_INT',
                'Bearbeitungszeit in Sekunden ab Versuchsbeginn. 0 = kein Zeitlimit. Dasselbe Feld wie bei '
                    . 'einer Aufgabe (assign::timelimit).',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:100-102 (duration, optional); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.timelimit)'
            ),
            new field(
                'overduehandling',
                'PARAM_ALPHA',
                'Umgang mit überzogenen Versuchen: automatisch abschicken, Kulanzzeit gewähren oder den '
                    . 'Versuch verwerfen.',
                false,
                'autoabandon',
                ['autosubmit', 'graceperiod', 'autoabandon'],
                'quiz_get_overdue_handling_options()',
                'mod/quiz/locallib.php:939 (quiz_get_overdue_handling_options()); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.overduehandling, DEFAULT=autoabandon)'
            ),
            new field(
                'graceperiod',
                'PARAM_INT',
                'Kulanzzeit in Sekunden nach Ablauf des Zeitlimits, in der eine Abgabe noch angenommen wird. '
                    . 'Nur wirksam bei "overduehandling"="graceperiod" und muss länger sein als eine '
                    . 'serverweite Mindestdauer (siehe Kombinationsregeln).',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:113-116 (duration, optional, hideIf overduehandling neq graceperiod); '
                    . 'Spalte mod/quiz/db/install.xml (quiz.graceperiod)'
            ),
            new field(
                'preferredbehaviour',
                'PARAM_ALPHA',
                'Fragebearbeitungsverhalten: wie und wann eine Antwort bewertet/rückgemeldet wird (z.B. '
                    . 'direktes Feedback, verzögertes Feedback, verzögert mit Sicherheitsgrad).',
                true,
                null,
                null,
                '\\question_engine::get_behaviour_options()',
                'question/engine/lib.php (question_engine::get_behaviour_options()); '
                    . 'mod/quiz/mod_form.php:202-205; Spalte mod/quiz/db/install.xml '
                    . '(quiz.preferredbehaviour, NOTNULL ohne DB-Default)'
            ),
            new field(
                'canredoquestions',
                'PARAM_BOOL',
                'Lernende dürfen eine innerhalb des Versuchs bereits abgeschlossene Frage erneut bearbeiten. '
                    . 'Nur bei Verhalten wirksam, die ein Fragenende während des Versuchs kennen.',
                false,
                0,
                [0, 1],
                null,
                'mod/quiz/mod_form.php:208-215 (select); Spalte mod/quiz/db/install.xml (quiz.canredoquestions)'
            ),
            new field(
                'attempts',
                'PARAM_INT',
                'Maximale Anzahl erlaubter Versuche. 0 = unbegrenzt.',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:151-156 (select, 0-QUIZ_MAX_ATTEMPT_OPTION); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.attempts)'
            ),
            new field(
                'attemptonlast',
                'PARAM_BOOL',
                'Ein neuer Versuch übernimmt die Antworten des letzten Versuchs (1) statt leer zu beginnen '
                    . '(0). Nur sichtbar, wenn mehr als ein Versuch erlaubt ist.',
                false,
                0,
                [0, 1],
                null,
                'mod/quiz/mod_form.php:218-223 (selectyesno, hideIf attempts eq 1); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.attemptonlast)'
            ),
            new field(
                'grademethod',
                'PARAM_INT',
                'Wie die Testnote aus mehreren Versuchen berechnet wird: höchste Note, Durchschnitt, erster '
                    . 'oder letzter Versuch.',
                false,
                1,
                [1, 2, 3, 4],
                'quiz_get_grading_options()',
                'mod/quiz/lib.php:61-64 (QUIZ_GRADEHIGHEST/AVERAGE/ATTEMPTFIRST/ATTEMPTLAST); '
                    . 'mod/quiz/locallib.php:916 (quiz_get_grading_options()); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.grademethod, DEFAULT=1)'
            ),
            new field(
                'decimalpoints',
                'PARAM_INT',
                'Nachkommastellen bei der Anzeige der Gesamtnote.',
                false,
                2,
                null,
                null,
                'mod/quiz/mod_form.php:264-269 (select, 0-QUIZ_MAX_DECIMAL_OPTION); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.decimalpoints, DEFAULT=2)'
            ),
            new field(
                'questiondecimalpoints',
                'PARAM_INT',
                'Nachkommastellen bei der Anzeige einzelner Fragenoten. -1 = wie "decimalpoints".',
                false,
                -1,
                null,
                null,
                'mod/quiz/mod_form.php:272-278 (select, -1 bis QUIZ_MAX_Q_DECIMAL_OPTION); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.questiondecimalpoints, DEFAULT=-1)'
            ),
            new field(
                'questionsperpage',
                'PARAM_INT',
                'Nach wie vielen Fragen beim Bearbeiten/Mischen eine neue Seite beginnt. 0 = alle Fragen auf '
                    . 'einer Seite.',
                false,
                0,
                null,
                'quiz_questions_per_page_options()',
                'mod/quiz/locallib.php:1001 (quiz_questions_per_page_options()); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.questionsperpage)'
            ),
            new field(
                'navmethod',
                'PARAM_ALPHA',
                'Navigation im Test: frei zwischen Fragen springen ("free") oder nur der Reihe nach '
                    . '("sequential").',
                false,
                'free',
                ['free', 'sequential'],
                'quiz_get_navigation_options()',
                'mod/quiz/lib.php:76-77,1849 (QUIZ_NAVMETHOD_FREE/SEQ, quiz_get_navigation_options()); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.navmethod, DEFAULT=free)'
            ),
            new field(
                'shuffleanswers',
                'PARAM_BOOL',
                'Innerhalb jeder Frage die Antwortteile mischen, sofern der Fragetyp das unterstützt.',
                false,
                0,
                [0, 1],
                null,
                'mod/quiz/mod_form.php:192-194 (selectyesno); Spalte mod/quiz/db/install.xml (quiz.shuffleanswers)'
            ),
            new field(
                'subnet',
                'PARAM_RAW',
                'Erlaubte IP-Adressen/-bereiche, aus denen ein Testversuch gestartet werden darf (Format wie '
                    . 'address_in_subnet()). Leer = keine Einschränkung.',
                true,
                null,
                null,
                null,
                'mod/quiz/mod_form.php:294-296 (text); Spalte mod/quiz/db/install.xml '
                    . '(quiz.subnet, NOTNULL ohne DB-Default)'
            ),
            new field(
                'browsersecurity',
                'PARAM_ALPHANUMEXT',
                'Einschränkung des verwendeten Browsers während des Versuchs, z.B. sicherer Vollbildmodus. '
                    . '"-" = keine Einschränkung.',
                true,
                null,
                null,
                '\\mod_quiz\\access_manager::get_browser_security_choices()',
                'mod/quiz/classes/access_manager.php:128 (get_browser_security_choices()); '
                    . 'mod/quiz/mod_form.php:315-317; Spalte mod/quiz/db/install.xml '
                    . '(quiz.browsersecurity, NOTNULL ohne DB-Default)'
            ),
            new field(
                'delay1',
                'PARAM_INT',
                'Erzwungene Wartezeit in Sekunden zwischen dem ersten und dem zweiten Versuch.',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:299-304 (duration, optional, hideIf attempts eq 1); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.delay1)'
            ),
            new field(
                'delay2',
                'PARAM_INT',
                'Erzwungene Wartezeit in Sekunden zwischen dem zweiten und weiteren Versuchen.',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:306-312 (duration, optional, hideIf attempts eq 1 oder eq 2); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.delay2)'
            ),
            new field(
                'showuserpicture',
                'PARAM_INT',
                'Nutzerbild während des Versuchs und auf der Review-Seite anzeigen: keins, klein oder groß.',
                false,
                0,
                [0, 1, 2],
                'quiz_get_user_image_options()',
                'mod/quiz/locallib.php:69-79 (QUIZ_SHOWIMAGE_NONE/SMALL/LARGE), :951 '
                    . '(quiz_get_user_image_options()); Spalte mod/quiz/db/install.xml (quiz.showuserpicture)'
            ),
            new field(
                'showblocks',
                'PARAM_BOOL',
                'Blöcke während des Testversuchs anzeigen.',
                false,
                0,
                [0, 1],
                null,
                'mod/quiz/mod_form.php:282-283 (selectyesno); Spalte mod/quiz/db/install.xml (quiz.showblocks)'
            ),
            new field(
                'allowofflineattempts',
                'PARAM_BOOL',
                'Test darf offline in der Moodle-App bearbeitet werden. Kommt vom Zugriffsregel-Plugin '
                    . 'quizaccess_offlineattempts, nicht vom Formularkern selbst.',
                false,
                0,
                [0, 1],
                null,
                'mod/quiz/accessrule/offlineattempts/rule.php:100-107 (add_settings_form_fields()); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.allowofflineattempts)'
            ),
            new field(
                'precreateattempts',
                'PARAM_BOOL',
                'Versuche für Lernende vorab anlegen. Nur sichtbar, wenn der Admin ein '
                    . 'Vorab-Anlage-Zeitfenster konfiguriert hat UND "timeopen" gesetzt ist.',
                false,
                null,
                [0, 1],
                null,
                'mod/quiz/mod_form.php:118-135 (select, bedingt sichtbar); Spalte '
                    . 'mod/quiz/db/install.xml (quiz.precreateattempts, NULLable)'
            ),
        ];
    }

    /**
     * Quiz bleibt die ADR-0016-Ausnahme: Bewertung und Fragenanordnung liegen
     * ausserhalb des generischen Formularwegs und werden deshalb hier gelesen.
     */
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::quiz($instanceid, $cmid, $fullcontent);
    }

    public static function common_field_names(): array {
        return [
            'name',
            'intro',
            'timeopen',
            'timeclose',
            'timelimit',
            'attempts',
            'grademethod',
            'preferredbehaviour',
            'navmethod',
            'shuffleanswers',
        ];
    }

    public static function pseudofields(): array {
        $fields = [
            new field(
                'quizpassword',
                'PARAM_TEXT',
                'Passwort, das vor Beginn/Fortsetzung eines Testversuchs eingegeben werden muss. Leer = kein '
                    . 'Passwort. Formularname der Spalte "password" (siehe Sperrliste) - beide meinen dasselbe '
                    . 'Feld, aber nur "quizpassword" ist über den Formularweg schreibbar.',
                false,
                '',
                null,
                null,
                'mod/quiz/mod_form.php:289-291 (passwordunmask "quizpassword"); data_preprocessing()/'
                    . 'data_postprocessing() spiegeln auf die Spalte "password"'
            ),
            new field(
                'feedbacktext',
                'array',
                'Gesamtfeedback-Texte je Notenband, absteigend sortiert - EIN Feld, keine Feldreihe: '
                    . 'feedbacktext[0] ist das Feedback für 100% bis zur ersten Grenze. Nur wirksam, wenn '
                    . '"grade" (Sperrliste) größer 0 ist.',
                false,
                null,
                null,
                null,
                'mod/quiz/mod_form.php:339-368 (repeat_elements() der Gruppe feedbacktext/feedbackboundaries)'
            ),
            new field(
                'feedbackboundaries',
                'float[]',
                'Notengrenzen der Feedback-Bänder, über denselben Schlüssel wie "feedbacktext" verknüpft - '
                    . 'eine weniger als feedbacktext-Einträge. Absolut oder als Prozentsatz (z.B. "50%"), muss '
                    . 'absteigend sortiert und zwischen 0 und "grade" liegen (siehe Kombinationsregeln).',
                false,
                null,
                null,
                null,
                'mod/quiz/mod_form.php:344-347,570-611 (repeat_elements(); validation())'
            ),
        ];

        foreach (self::REVIEW_TYPES as $type => $typemeaning) {
            foreach (self::REVIEW_TIMINGS as $timing => $timingmeaning) {
                $fields[] = new field(
                    $type . $timing,
                    'PARAM_BOOL',
                    $typemeaning . ' Zeitpunkt: ' . $timingmeaning . '. Eine von 32 Einzel-Checkboxen, aus '
                        . 'denen Moodle die Bitmaske "review' . $type . '" berechnet (Sperrliste) - dasselbe '
                        . 'Vokabular wie die acht gesperrten Spalten, nur pro Zeitpunkt aufgeschlüsselt.',
                    false,
                    0,
                    [0, 1],
                    null,
                    'mod/quiz/mod_form.php (self::$reviewfields, add_review_options_group()); '
                        . 'mod/quiz/lib.php: quiz_process_options() (Zusammenbau zur Bitmaske)'
                );
            }
        }

        return $fields;
    }

    public static function blocklist(): array {
        return [
            'grade',
            'sumgrades',
            'password',
            'reviewattempt',
            'reviewcorrectness',
            'reviewmaxmarks',
            'reviewmarks',
            'reviewspecificfeedback',
            'reviewgeneralfeedback',
            'reviewrightanswer',
            'reviewoverallfeedback',
            'completionattemptsexhausted',
            'completionminattempts',
        ];
    }

    public static function combination_rules(): array {
        return [
            '"timeclose" darf nicht vor "timeopen" liegen, wenn beide gesetzt sind (mod/quiz/mod_form.php: '
                . 'validation()).',
            '"graceperiod" muss größer sein als eine serverweite Mindestdauer (Admin-Einstellung '
                . '"graceperiodmin"), wenn "overduehandling"="graceperiod" (validation()).',
            '"feedbackboundaries[]" muss absteigend sortiert sein und jeder Wert zwischen 0 und "grade" '
                . 'liegen; die Anzahl muss genau eine weniger sein als "feedbacktext[]" (validation()).',
            '"preferredbehaviour"="deferredcbm" oder "immediatecbm" (Certainty-Based Marking) unterdrückt die '
                . 'sonstige Warnung, wenn die Bestehensgrenze über der maximalen Note liegt (validation()).',
        ];
    }

    public static function side_effects(): array {
        return [
            '"timeopen"/"timeclose" erzeugen bzw. aktualisieren je einen Kalendereintrag '
                . '(mod/quiz/lib.php: quiz_update_events()).',
        ];
    }

    public static function bundles(): array {
        return [
            'mini-check' => array_merge([
                'preferredbehaviour' => 'immediatefeedback',
                'attempts' => 0,
                'grademethod' => 1, // QUIZ_GRADEHIGHEST.
                'timelimit' => 0,
                'questionsperpage' => 1,
                'navmethod' => 'free',
                'shuffleanswers' => 1,
                'attemptonlast' => 0,
                'delay1' => 0,
                'delay2' => 0,
                'decimalpoints' => 2,
            ], self::review_bundle_fields(
                // attempt, correctness, maxmarks, marks, specificfeedback, generalfeedback: immer sichtbar.
                ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback'],
                // overallfeedback: nach dem Versuch, nicht während. rightanswer bleibt in allen drei Modi 0.
                ['overallfeedback']
            )),
            'lernstandscheck' => array_merge([
                'preferredbehaviour' => 'deferredcbm',
                'attempts' => 0,
                'grademethod' => 1, // QUIZ_GRADEHIGHEST.
                'timelimit' => 0,
                'questionsperpage' => 0,
                'navmethod' => 'free',
                'shuffleanswers' => 1,
                'attemptonlast' => 0,
                'delay1' => 300,
                'delay2' => 300,
                'decimalpoints' => 2,
            ], self::review_bundle_fields(
                ['maxmarks', 'marks'],
                ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'overallfeedback']
            )),
            'abschlusstest' => array_merge([
                'preferredbehaviour' => 'deferredfeedback',
                'attempts' => 2,
                'grademethod' => 2, // QUIZ_GRADEAVERAGE.
                'timelimit' => 0,
                'questionsperpage' => 0,
                'navmethod' => 'free',
                'shuffleanswers' => 1,
                'attemptonlast' => 0,
                'delay1' => 900,
                'delay2' => 900,
                'decimalpoints' => 2,
            ], self::review_bundle_fields(
                [],
                ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'overallfeedback']
            )),
        ];
    }

    /**
     * Baut die 32 review*-Pseudofeld-Checkboxen fuer ein Modus-Buendel:
     * eine Uebersetzung der alten Bitmasken-Kombinationen
     * (local_coursepilot\external\create_quiz::mode_defaults()) auf die
     * einzeln schreibbaren Pseudofelder dieses Katalogs (Sperrliste kennt
     * nur die acht Bitmask-Spalten selbst, siehe Klassendoku).
     *
     * @param string[] $duringtypes Review-Arten, die zusaetzlich "during"=1 haben.
     * @param string[] $afterattempttypes Review-Arten mit "immediately"/"open"/"closed"=1. Nicht gelistete
     *        Arten (in allen drei Modi: "rightanswer") bleiben zu jedem Zeitpunkt 0.
     * @return array<string, int>
     */
    private static function review_bundle_fields(array $duringtypes, array $afterattempttypes): array {
        $result = [];
        foreach (array_keys(self::REVIEW_TYPES) as $type) {
            $result[$type . 'during'] = in_array($type, $duringtypes, true) ? 1 : 0;
            $afterattempt = in_array($type, $afterattempttypes, true) ? 1 : 0;
            $result[$type . 'immediately'] = $afterattempt;
            $result[$type . 'open'] = $afterattempt;
            $result[$type . 'closed'] = $afterattempt;
        }
        return $result;
    }

    public static function schreibweg(): ?string {
        return 'update_quiz_settings';
    }

    public static function checked_constants(): array {
        return [];
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
