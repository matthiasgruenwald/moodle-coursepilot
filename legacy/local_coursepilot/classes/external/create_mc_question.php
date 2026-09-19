<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/questionlib.php');

use local_coursepilot\mc_question_version;
use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;

/**
 * Legt eine Multiple-Choice-Frage (qtype_multichoice) in einer Fragenbank-
 * Kategorie an. Bestehende options[] + correctindex-Aufrufe bleiben
 * Einfachauswahl; answers[] kann Gewicht und Antwortfeedback ausdruecken.
 *
 * Die eigentliche Versionserzeugung (neuer question_bank_entries-Eintrag,
 * initiale question_versions-Zeile mit version=1, generierte idnumber)
 * uebernimmt local_coursepilot\mc_question_version::create() ueber Moodles
 * question_type::save_question(). Spaetere update_mc_question-Aufrufe
 * haengen neue Versionen an dieselbe questionbankentryid (ADR-0001).
 */
class create_mc_question extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid'      => new external_value(PARAM_INT,   'ID der Fragenbank-Kategorie'),
            'name'            => new external_value(PARAM_TEXT,  'Eindeutiger Name der Frage innerhalb der Kategorie'),
            'questiontext'    => new external_value(PARAM_RAW,   'Fragetext (HTML)'),
            'options'         => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Antwort-Option (HTML)'),
                'Alte Antwort-Optionen fuer correctindex-Kompatibilitaet', VALUE_DEFAULT, []
            ),
            'correctindex'    => new external_value(PARAM_INT,   '0-basierter Index der richtigen Antwort in options[]', VALUE_DEFAULT, -1),
            'selectionmode'   => new external_value(PARAM_ALPHA, 'single oder multiple', VALUE_DEFAULT, 'single'),
            'answers'         => new external_multiple_structure(new external_single_structure([
                'answer'   => new external_value(PARAM_RAW, 'Antworttext (HTML)'),
                'correct'  => new external_value(PARAM_BOOL, 'Als richtig markiert'),
                'feedback' => new external_value(PARAM_RAW, 'Antwortspezifisches Feedback (HTML)'),
                'fraction' => new external_value(PARAM_FLOAT, 'Gewicht zwischen -1 und 1'),
            ]), 'Strukturierte Antworten', VALUE_DEFAULT, []),
            'defaultmark'     => new external_value(PARAM_FLOAT, 'Standard-Punktzahl der Frage', VALUE_DEFAULT, 1.0),
            'generalfeedback' => new external_value(PARAM_RAW,   'Allgemeines Feedback (HTML, optional)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int    $categoryid,
        string $name,
        string $questiontext,
        array  $options = [],
        int    $correctindex = -1,
        string $selectionmode = 'single',
        array  $answers = [],
        float  $defaultmark = 1.0,
        string $generalfeedback = ''
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid'      => $categoryid,
            'name'            => $name,
            'questiontext'    => $questiontext,
            'options'         => $options,
            'correctindex'    => $correctindex,
            'selectionmode'   => $selectionmode,
            'answers'         => $answers,
            'defaultmark'     => $defaultmark,
            'generalfeedback' => $generalfeedback,
        ]);

        // Kategorie + Kontext aufloesen, Capability pruefen.
        $category = $DB->get_record('question_categories',
            ['id' => $params['categoryid']], '*', MUST_EXIST);
        $context = \context::instance_by_id($category->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/question:add', $context);

        $answers = self::normalise_answers($params);

        $result = mc_question_version::create(
            $params['categoryid'],
            $params['name'],
            $params['questiontext'],
            $params['generalfeedback'],
            $params['defaultmark'],
            $params['selectionmode'],
            $answers
        );

        return [
            'questionid'          => $result->questionid,
            'questionbankentryid' => $result->questionbankentryid,
            'version'             => $result->version,
            'answerids'           => $result->answerids,
            'warnings'            => self::warnings($answers, $params['selectionmode']),
            'message'             => 'MC-Frage "' . $params['name'] . '" erfolgreich angelegt.',
        ];
    }

    private static function normalise_answers(array $params): array {
        $answers = $params['answers'];
        if (empty($answers)) {
            foreach ($params['options'] as $index => $option) {
                $answers[] = ['answer' => $option, 'correct' => $index === $params['correctindex'],
                    'feedback' => '', 'fraction' => $index === $params['correctindex'] ? 1.0 : 0.0];
            }
        }
        if (count($answers) < 2) {
            throw new \invalid_parameter_exception('Eine Multiple-Choice-Frage braucht mindestens 2 Antworten.');
        }
        if (!in_array($params['selectionmode'], ['single', 'multiple'], true)) {
            throw new \invalid_parameter_exception('selectionmode muss single oder multiple sein.');
        }
        foreach ($answers as $answer) {
            if ($answer['fraction'] < -1 || $answer['fraction'] > 1) {
                throw new \invalid_parameter_exception('fraction muss zwischen -1 und 1 liegen.');
            }
            if ((bool) $answer['correct'] !== ((float) $answer['fraction'] > 0)) {
                throw new \invalid_parameter_exception('Korrektheit und fraction muessen uebereinstimmen.');
            }
        }
        if ($params['selectionmode'] === 'single' && count(array_filter($answers,
                static fn($answer) => !empty($answer['correct']))) !== 1) {
            throw new \invalid_parameter_exception('Eine Einfachauswahl braucht genau eine richtige Antwort.');
        }
        // qtype_multichoice::save_question_options() verlangt zwingend, dass die
        // positiven fractions in Summe genau 1 ergeben (sonst interner Moodle-
        // Fehler statt sauberer Rueckmeldung) - vorab pruefen.
        $positivesum = round(array_sum(array_map(
            static fn($answer) => max(0.0, (float) $answer['fraction']), $answers)), 2);
        if (abs($positivesum - 1.0) > 0.001) {
            throw new \invalid_parameter_exception(
                'Die positiven fraction-Werte muessen in Summe genau 1 ergeben (aktuell ' . $positivesum . ').');
        }
        return $answers;
    }

    private static function warnings(array $answers, string $selectionmode): array {
        $sum = array_sum(array_map(static fn($answer) => (float) $answer['fraction'], $answers));
        $warnings = [];
        if ($selectionmode === 'multiple' && $sum >= 1.0) {
            $warnings[] = 'Wer alle Antworten auswählt, erhält volle Punktzahl; prüfe die Gewichte der Distraktoren.';
        }
        $correct = count(array_filter($answers, static fn($answer) => (float) $answer['fraction'] > 0));
        if ($selectionmode === 'multiple' && $correct !== count($answers) - $correct) {
            $warnings[] = 'Die Zahl richtiger und nicht richtiger Antworten ist unausgewogen; prüfe die Gewichte für eine faire Bewertung.';
        }
        return $warnings;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionid'          => new external_value(PARAM_INT,  'ID der neu erzeugten question-Zeile'),
            'questionbankentryid' => new external_value(PARAM_INT,  'ID des question_bank_entries (Frage-Identitaet ueber alle Versionen)'),
            'version'             => new external_value(PARAM_INT,  'Versionsnummer (initial = 1)'),
            'answerids'           => new external_multiple_structure(
                new external_value(PARAM_INT, 'question_answers.id'),
                'IDs der angelegten Antworten in Reihenfolge der options[]'
            ),
            'warnings'            => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Hinweis zur Gewichtung'), 'Gewichtungswarnungen'
            ),
            'message'             => new external_value(PARAM_TEXT, 'Status-Nachricht'),
        ]);
    }
}
