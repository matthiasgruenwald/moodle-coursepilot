<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_course;
use invalid_parameter_exception;

/**
 * Creates a mod_quiz activity. Drei Kurspilot-Modi geben komplette
 * Settings-Kombinationen vor:
 *
 *  - mini-check:       Kurzer Kompetenzcheck mit direkter Auswertung ohne
 *                      Selbsteinschätzung.
 *  - lernstandscheck:  Lernstandscheck mit späterer Auswertung,
 *                      Selbsteinschätzung und Lernplanung.
 *  - abschlusstest:    Abschlusstest mit Verbesserungsmöglichkeit,
 *                      keine Klassenarbeit.
 *
 * Explizit gesetzte Parameter (gradepass, timelimit) überschreiben den Modus-Default
 * (Layered Defaults).
 */
class create_quiz extends external_api {

    /** @var string[] Native Kurspilot-Modi. */
    const NATIVE_MODES = ['mini-check', 'lernstandscheck', 'abschlusstest'];

    /** @var array Deprecated aliases kept for existing clients. */
    const DEPRECATED_MODE_ALIASES = [
        'intensiv'  => 'mini-check',
        'lerncheck' => 'lernstandscheck',
        'bewertung' => 'abschlusstest',
    ];

    // Bitmasks fuer review*-Felder, identisch zu \mod_quiz\question\display_options.
    // quiz_process_options() (mod/quiz/lib.php) berechnet diese Felder nicht aus
    // den hier gesetzten Kombi-Werten, sondern aus einzelnen "<feld><zeitpunkt>"-
    // Formularfeldern (z.B. "attemptduring"). apply_review_options() uebersetzt
    // unsere Kombi-Bitmasken in diese Einzelfelder, damit sie erhalten bleiben.
    const REVIEW_DURING          = 0x10000;
    const REVIEW_IMMEDIATELY     = 0x01000;
    const REVIEW_LATER_WHILE_OPEN = 0x00100;
    const REVIEW_AFTER_CLOSE     = 0x00010;

    /**
     * Liefert die Settings-Kombination fuer einen Modus.
     *
     * @param string $mode Einer von self::NATIVE_MODES.
     * @return array Settings-Map.
     */
    public static function mode_defaults(string $mode): array {
        $afterattempt = self::REVIEW_IMMEDIATELY | self::REVIEW_LATER_WHILE_OPEN | self::REVIEW_AFTER_CLOSE;

        switch ($mode) {
            case 'mini-check':
                return [
                    'preferredbehaviour' => 'immediatefeedback',
                    'attempts'           => 0,
                    'grademethod'        => QUIZ_GRADEHIGHEST,
                    'timelimit'          => 0,
                    'gradepass'          => 80.0,
                    'questionsperpage'    => 1,
                    'navmethod'           => 'free',
                    'shuffleanswers'      => 1,
                    'attemptonlast'       => 0,
                    'delay1'              => 0,
                    'delay2'              => 0,
                    'decimalpoints'       => 2,
                    'completion'          => 2,
                    'completionusegrade'  => 1,
                    'completionpassgrade' => 1,
                    'overallfeedback'     => [
                        'pass' => 'Bestanden: Du hast die Bestehensgrenze erreicht. Nutze die Rückmeldungen für deine nächsten Übungsschritte.',
                        'fail' => 'Noch nicht bestanden: Schau dir die Rückmeldungen an und starte einen neuen Versuch.',
                    ],
                    'reviewattempt'          => self::REVIEW_DURING | $afterattempt,
                    'reviewcorrectness'      => self::REVIEW_DURING | $afterattempt,
                    'reviewmaxmarks'         => self::REVIEW_DURING | $afterattempt,
                    'reviewmarks'            => self::REVIEW_DURING | $afterattempt,
                    'reviewspecificfeedback' => self::REVIEW_DURING | $afterattempt,
                    'reviewgeneralfeedback'  => self::REVIEW_DURING | $afterattempt,
                    'reviewrightanswer'      => 0,
                    'reviewoverallfeedback'  => $afterattempt,
                ];

            case 'abschlusstest':
                return [
                    'preferredbehaviour' => 'deferredfeedback',
                    'attempts'           => 2,
                    'grademethod'        => QUIZ_GRADEAVERAGE,
                    'timelimit'          => 0,
                    'gradepass'          => 80.0,
                    'questionsperpage'    => 0,
                    'navmethod'           => 'free',
                    'shuffleanswers'      => 1,
                    'attemptonlast'       => 0,
                    'delay1'              => 900,
                    'delay2'              => 900,
                    'decimalpoints'       => 2,
                    'completion'          => 2,
                    'completionusegrade'  => 1,
                    'completionpassgrade' => 1,
                    'overallfeedback'     => [
                        'pass' => 'Bestanden: Du hast die Bestehensgrenze im Abschlusstest erreicht.',
                        'fail' => 'Noch nicht bestanden: Nutze die Rückmeldungen und den zweiten Versuch zur Verbesserung.',
                    ],
                    'reviewattempt'          => $afterattempt,
                    'reviewcorrectness'      => $afterattempt,
                    'reviewmaxmarks'         => $afterattempt,
                    'reviewmarks'            => $afterattempt,
                    'reviewspecificfeedback' => $afterattempt,
                    'reviewgeneralfeedback'  => $afterattempt,
                    'reviewrightanswer'      => 0,
                    'reviewoverallfeedback'  => $afterattempt,
                ];

            case 'lernstandscheck':
            default:
                return [
                    'preferredbehaviour' => 'deferredcbm',
                    'attempts'           => 0,
                    'grademethod'        => QUIZ_GRADEHIGHEST,
                    'timelimit'          => 0,
                    'gradepass'          => 80.0,
                    'questionsperpage'    => 0,
                    'navmethod'           => 'free',
                    'shuffleanswers'      => 1,
                    'attemptonlast'       => 0,
                    'delay1'              => 300,
                    'delay2'              => 300,
                    'decimalpoints'       => 2,
                    'completion'          => 2,
                    'completionusegrade'  => 1,
                    'completionpassgrade' => 1,
                    'overallfeedback'     => [
                        'high'   => 'Ab 80 %: Du hast die Bestehensgrenze erreicht. Plane jetzt passende Vertiefungen oder den nächsten Lernschritt.',
                        'middle' => '50 bis unter 80 %: Du bist auf dem Weg. Nutze die Rückmeldungen für gezielte Wiederholung.',
                        'low'    => 'Unter 50 %: Bearbeite die offenen Kompetenzen noch einmal grundlegend und starte danach einen neuen Versuch.',
                    ],
                    'reviewattempt'          => $afterattempt,
                    'reviewcorrectness'      => $afterattempt,
                    'reviewmaxmarks'         => self::REVIEW_DURING | $afterattempt,
                    'reviewmarks'            => self::REVIEW_DURING | $afterattempt,
                    'reviewspecificfeedback' => $afterattempt,
                    'reviewgeneralfeedback'  => $afterattempt,
                    'reviewrightanswer'      => 0,
                    'reviewoverallfeedback'  => $afterattempt,
                ];
        }
    }

    /** @var string[] Felder, die als String per Sentinel '' ueberschreibbar sind. */
    const OVERRIDABLE_STRING_FIELDS = ['preferredbehaviour', 'navmethod'];

    /** @var string[] Felder, die als Int per Sentinel -1 ueberschreibbar sind. */
    const OVERRIDABLE_INT_FIELDS = [
        'questionsperpage',
        'attempts',
        'attemptonlast',
        'grademethod',
        'delay1',
        'delay2',
        'shuffleanswers',
        'decimalpoints',
        'completion',
        'completionusegrade',
        'completionpassgrade',
        'reviewattempt',
        'reviewcorrectness',
        'reviewmaxmarks',
        'reviewmarks',
        'reviewspecificfeedback',
        'reviewgeneralfeedback',
        'reviewrightanswer',
        'reviewoverallfeedback',
    ];

    /**
     * Layered Defaults: $params-Werte ueberschreiben gezielt $defaults,
     * sofern sie nicht den "nicht gesetzt"-Sentinel tragen (String: '',
     * Int: -1). Modus-Presets bleiben dadurch unveraendert; nur einzeln
     * uebergebene Felder weichen vom Preset ab.
     *
     * @param array $defaults Modus-Default-Settings aus mode_defaults().
     * @param array $params Validierte Webservice-Parameter (execute_parameters()).
     * @return array $defaults mit angewendeten Overrides.
     */
    public static function apply_field_overrides(array $defaults, array $params): array {
        $result = $defaults;

        foreach (self::OVERRIDABLE_STRING_FIELDS as $field) {
            if (array_key_exists($field, $params) && $params[$field] !== '') {
                $result[$field] = $params[$field];
            }
        }

        foreach (self::OVERRIDABLE_INT_FIELDS as $field) {
            if (array_key_exists($field, $params) && (int) $params[$field] !== -1) {
                $result[$field] = (int) $params[$field];
            }
        }

        if (!empty($params['overallfeedbacktextpass']) || !empty($params['overallfeedbacktextfail'])) {
            $result['overallfeedback'] = [
                'pass' => $params['overallfeedbacktextpass'] !== '' ? $params['overallfeedbacktextpass'] : $defaults['overallfeedback']['pass'] ?? ($defaults['overallfeedback']['high'] ?? ''),
                'fail' => $params['overallfeedbacktextfail'] !== '' ? $params['overallfeedbacktextfail'] : $defaults['overallfeedback']['fail'] ?? ($defaults['overallfeedback']['low'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Definiert die Webservice-Parameter fuer alle ueberschreibbaren
     * Quiz-Formularfelder (#86). Geteilt von create_quiz und
     * update_quiz_settings, damit beide Endpunkte dieselben Felder anbieten.
     *
     * @return external_value[] Map Feldname => Parameterdefinition.
     */
    public static function overridable_field_params(): array {
        $params = [];

        $stringdescriptions = [
            'preferredbehaviour' => "Frageverhalten, z.B. 'immediatefeedback', 'deferredfeedback', 'deferredcbm'. '' = Modus-Default verwenden.",
            'navmethod'          => "Navigationsmethode: 'free' oder 'sequential'. '' = Modus-Default verwenden.",
        ];
        foreach (self::OVERRIDABLE_STRING_FIELDS as $field) {
            $params[$field] = new external_value(PARAM_ALPHANUMEXT, $stringdescriptions[$field] ?? "Override fuer {$field}. '' = Modus-Default verwenden.", VALUE_DEFAULT, '');
        }

        $intdescriptions = [
            'questionsperpage'      => 'Fragen pro Seite (0 = alle auf einer Seite). -1 = Modus-Default verwenden.',
            'attempts'               => 'Maximale Versuchsanzahl (0 = unbegrenzt). -1 = Modus-Default verwenden.',
            'attemptonlast'          => 'Neuer Versuch baut auf letztem auf (1) oder nicht (0). -1 = Modus-Default verwenden.',
            'grademethod'            => 'Bewertungsmethode (1=Hoechste, 2=Durchschnitt, 3=Erster Versuch, 4=Letzter Versuch). -1 = Modus-Default verwenden.',
            'delay1'                 => 'Wartezeit in Sekunden zwischen 1. und 2. Versuch. -1 = Modus-Default verwenden.',
            'delay2'                 => 'Wartezeit in Sekunden zwischen weiteren Versuchen. -1 = Modus-Default verwenden.',
            'shuffleanswers'         => 'Antworten gemischt anzeigen (1) oder nicht (0). -1 = Modus-Default verwenden.',
            'decimalpoints'          => 'Anzahl Nachkommastellen bei der Bewertung. -1 = Modus-Default verwenden.',
            'completion'             => 'Abschlussverfolgung: 0=keine, 1=manuell, 2=automatisch. -1 = Modus-Default verwenden.',
            'completionusegrade'     => 'Abschluss bei erreichter Bewertung (1) oder nicht (0). -1 = Modus-Default verwenden.',
            'completionpassgrade'    => 'Abschluss erst bei Bestehensgrenze (1) oder nicht (0). -1 = Modus-Default verwenden.',
            'reviewattempt'          => 'Review-Bitmaske: Versuch einsehen. -1 = Modus-Default verwenden.',
            'reviewcorrectness'      => 'Review-Bitmaske: Richtig/Falsch einsehen. -1 = Modus-Default verwenden.',
            'reviewmaxmarks'         => 'Review-Bitmaske: Maximalpunktzahl einsehen. -1 = Modus-Default verwenden.',
            'reviewmarks'            => 'Review-Bitmaske: Punkte einsehen. -1 = Modus-Default verwenden.',
            'reviewspecificfeedback' => 'Review-Bitmaske: Spezifisches Feedback einsehen. -1 = Modus-Default verwenden.',
            'reviewgeneralfeedback'  => 'Review-Bitmaske: Allgemeines Feedback einsehen. -1 = Modus-Default verwenden.',
            'reviewrightanswer'      => 'Review-Bitmaske: Richtige Antwort einsehen. -1 = Modus-Default verwenden.',
            'reviewoverallfeedback'  => 'Review-Bitmaske: Gesamtfeedback einsehen. -1 = Modus-Default verwenden.',
        ];
        foreach (self::OVERRIDABLE_INT_FIELDS as $field) {
            $params[$field] = new external_value(PARAM_INT, $intdescriptions[$field] ?? "Override fuer {$field}. -1 = Modus-Default verwenden.", VALUE_DEFAULT, -1);
        }

        $params['overallfeedbacktextpass'] = new external_value(PARAM_RAW, "Gesamtfeedback-Text bei Bestehen (ueberschreibt Modus-Default-Text). '' = Modus-Default verwenden.", VALUE_DEFAULT, '');
        $params['overallfeedbacktextfail'] = new external_value(PARAM_RAW, "Gesamtfeedback-Text bei Nichtbestehen (ueberschreibt Modus-Default-Text). '' = Modus-Default verwenden.", VALUE_DEFAULT, '');

        return $params;
    }

    public static function normalize_mode(string $mode): array {
        $modekey = strtolower(trim($mode));
        if (array_key_exists($modekey, self::DEPRECATED_MODE_ALIASES)) {
            return [
                'mode' => self::DEPRECATED_MODE_ALIASES[$modekey],
                'deprecated' => true,
                'original' => $modekey,
            ];
        }

        if (in_array($modekey, self::NATIVE_MODES, true)) {
            return [
                'mode' => $modekey,
                'deprecated' => false,
                'original' => $modekey,
            ];
        }

        throw new invalid_parameter_exception(
            "Unbekannter mode '{$mode}'. Erlaubt: " . implode(', ', self::NATIVE_MODES)
        );
    }

    /**
     * Setzt die "<feld><zeitpunkt>"-Formularfelder, aus denen
     * quiz_process_options() die review*-Spalten neu berechnet
     * (z.B. reviewattempt aus attemptduring/attemptimmediately/attemptopen/
     * attemptclosed). Ohne diese Felder wuerden unsere Kombi-Bitmasken aus
     * mode_defaults() von quiz_process_options() verworfen (auf 0 gesetzt).
     *
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param array $reviewbitmasks Map von 'review<aspekt>' (z.B. 'reviewattempt')
     *        auf die Kombi-Bitmaske aus mode_defaults().
     */
    public static function apply_review_options(\stdClass $moduleinfo, array $reviewbitmasks): void {
        $timings = [
            'during'      => self::REVIEW_DURING,
            'immediately' => self::REVIEW_IMMEDIATELY,
            'open'        => self::REVIEW_LATER_WHILE_OPEN,
            'closed'      => self::REVIEW_AFTER_CLOSE,
        ];

        foreach ($reviewbitmasks as $aspect => $bitmask) {
            $field = substr($aspect, strlen('review')); // 'reviewattempt' -> 'attempt'
            foreach ($timings as $when => $bit) {
                $value = ($bitmask & $bit) ? 1 : 0;
                $moduleinfo->{$field . $when} = $value;
            }
        }
    }

    public static function review_fields(): array {
        return [
            'reviewattempt',
            'reviewcorrectness',
            'reviewmaxmarks',
            'reviewmarks',
            'reviewspecificfeedback',
            'reviewgeneralfeedback',
            'reviewrightanswer',
            'reviewoverallfeedback',
        ];
    }

    public static function save_overall_feedback(int $quizid, array $feedback, float $gradepass = 80.0): void {
        global $DB;

        $DB->delete_records('quiz_feedback', ['quizid' => $quizid]);

        if (array_key_exists('high', $feedback)) {
            $records = [
                ['text' => $feedback['high'], 'mingrade' => $gradepass, 'maxgrade' => 100.0],
                ['text' => $feedback['middle'], 'mingrade' => 50.0, 'maxgrade' => max(50.0, $gradepass - 0.00001)],
                ['text' => $feedback['low'], 'mingrade' => 0.0, 'maxgrade' => 49.99999],
            ];
        } else {
            $records = [
                ['text' => $feedback['pass'], 'mingrade' => $gradepass, 'maxgrade' => 100.0],
                ['text' => $feedback['fail'], 'mingrade' => 0.0, 'maxgrade' => max(0.0, $gradepass - 0.00001)],
            ];
        }

        foreach ($records as $feedbackrecord) {
            $record = new \stdClass();
            $record->quizid = $quizid;
            $record->feedbacktext = $feedbackrecord['text'];
            $record->feedbacktextformat = FORMAT_HTML;
            $record->mingrade = $feedbackrecord['mingrade'];
            $record->maxgrade = $feedbackrecord['maxgrade'];
            $DB->insert_record('quiz_feedback', $record);
        }
    }

    public static function review_form_flags(\stdClass $quiz): array {
        $flags = [];
        $timings = [
            'during'      => self::REVIEW_DURING,
            'immediately' => self::REVIEW_IMMEDIATELY,
            'open'        => self::REVIEW_LATER_WHILE_OPEN,
            'closed'      => self::REVIEW_AFTER_CLOSE,
        ];
        $fields = [
            'reviewrightanswer'     => 'rightanswer',
            'reviewmaxmarks'        => 'maxmarks',
            'reviewoverallfeedback' => 'overallfeedback',
        ];

        foreach ($fields as $reviewfield => $prefix) {
            $bitmask = isset($quiz->{$reviewfield}) ? (int) $quiz->{$reviewfield} : 0;
            foreach ($timings as $when => $bit) {
                $flags[$prefix . $when] = ($bitmask & $bit) ? 1 : 0;
            }
        }

        return $flags;
    }

    public static function read_feedback_records(int $quizid): array {
        global $DB;

        $records = $DB->get_records('quiz_feedback', ['quizid' => $quizid], 'mingrade DESC, id ASC');
        $feedbackrecords = [];
        foreach ($records as $record) {
            $feedbackrecords[] = [
                'mingrade' => (float) $record->mingrade,
                'maxgrade' => (float) $record->maxgrade,
                'text'     => (string) $record->feedbacktext,
            ];
        }

        return $feedbackrecords;
    }

    public static function feedback_boundaries(array $feedbackrecords): array {
        $boundaries = [];
        foreach ($feedbackrecords as $record) {
            if ((float) $record['mingrade'] > 0.0) {
                $boundaries[] = (float) $record['mingrade'];
            }
        }
        return $boundaries;
    }

    public static function saved_settings_return_structure(): array {
        $fields = [
            'name'               => new external_value(PARAM_TEXT, 'Saved quiz title'),
            'visible'            => new external_value(PARAM_INT, 'Saved visibility'),
            'preferredbehaviour' => new external_value(PARAM_TEXT, 'Saved question behaviour'),
            'questionsperpage'   => new external_value(PARAM_INT, 'Saved questions per page'),
            'attempts'           => new external_value(PARAM_INT, 'Saved attempt limit'),
            'grademethod'        => new external_value(PARAM_INT, 'Saved grading method'),
            'gradepass'          => new external_value(PARAM_FLOAT, 'Saved grade pass threshold'),
            'timeopen'           => new external_value(PARAM_INT, 'Saved open timestamp (0 = no limit)'),
            'timeclose'          => new external_value(PARAM_INT, 'Saved close timestamp (0 = no limit)'),
            'decimalpoints'      => new external_value(PARAM_INT, 'Saved decimal points'),
            'completion'         => new external_value(PARAM_INT, 'Saved completion tracking mode'),
            'completionusegrade' => new external_value(PARAM_INT, 'Saved grade completion flag'),
            'completionpassgrade'=> new external_value(PARAM_INT, 'Saved pass-grade completion flag'),
            'reviewrightanswer'  => new external_value(PARAM_INT, 'Saved right-answer review bitmask'),
            'reviewmaxmarks'     => new external_value(PARAM_INT, 'Saved max marks review bitmask'),
            'reviewmarks'        => new external_value(PARAM_INT, 'Saved marks review bitmask'),
            'reviewoverallfeedback' => new external_value(PARAM_INT, 'Saved overall-feedback review bitmask'),
        ];

        foreach ([
            'rightanswerduring', 'rightanswerimmediately', 'rightansweropen', 'rightanswerclosed',
            'maxmarksduring', 'maxmarksimmediately', 'maxmarksopen', 'maxmarksclosed',
            'overallfeedbackduring', 'overallfeedbackimmediately', 'overallfeedbackopen', 'overallfeedbackclosed',
        ] as $flag) {
            $fields[$flag] = new external_value(PARAM_INT, 'Saved review form flag');
        }

        $fields['feedbackboundaries'] = new external_multiple_structure(
            new external_value(PARAM_FLOAT, 'Editable feedback boundary')
        );
        $fields['feedbackrecords'] = new external_multiple_structure(
            new external_single_structure([
                'mingrade' => new external_value(PARAM_FLOAT, 'Feedback minimum grade'),
                'maxgrade' => new external_value(PARAM_FLOAT, 'Feedback maximum grade'),
                'text'     => new external_value(PARAM_RAW, 'Feedback text'),
            ])
        );

        return $fields;
    }

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array_merge([
            'courseid'   => new external_value(PARAM_INT,   'Course ID'),
            'sectionnum' => new external_value(PARAM_INT,   'Section number (0-based)'),
            'name'       => new external_value(PARAM_TEXT,  'Quiz title'),
            'intro'      => new external_value(PARAM_RAW,   'Quiz description (HTML, optional)', VALUE_DEFAULT, ''),
            'mode'       => new external_value(PARAM_ALPHANUMEXT, "Quizmodus: 'mini-check', 'lernstandscheck' (Default) oder 'abschlusstest'. Deprecated aliases: 'intensiv', 'lerncheck', 'bewertung'.", VALUE_DEFAULT, 'lernstandscheck'),
            'gradepass'  => new external_value(PARAM_FLOAT, 'Bestehensgrenze in Prozent (0-100). 0 = Modus-Default verwenden.', VALUE_DEFAULT, 0),
            'timelimit'  => new external_value(PARAM_INT,   'Zeitlimit in Sekunden (0 = unbegrenzt / Modus-Default). Nur fuer Bewertungsmodus relevant.', VALUE_DEFAULT, 0),
            'visible'    => new external_value(PARAM_INT,   'Visible (1) or hidden (0)', VALUE_DEFAULT, 1),
        ], self::overridable_field_params()));
    }

    public static function execute(
        int    $courseid,
        int    $sectionnum,
        string $name,
        string $intro = '',
        string $mode = 'lernstandscheck',
        float  $gradepass = 0,
        int    $timelimit = 0,
        int    $visible = 1,
        string $preferredbehaviour = '',
        string $navmethod = '',
        int    $questionsperpage = -1,
        int    $attempts = -1,
        int    $attemptonlast = -1,
        int    $grademethod = -1,
        int    $delay1 = -1,
        int    $delay2 = -1,
        int    $shuffleanswers = -1,
        int    $decimalpoints = -1,
        int    $completion = -1,
        int    $completionusegrade = -1,
        int    $completionpassgrade = -1,
        int    $reviewattempt = -1,
        int    $reviewcorrectness = -1,
        int    $reviewmaxmarks = -1,
        int    $reviewmarks = -1,
        int    $reviewspecificfeedback = -1,
        int    $reviewgeneralfeedback = -1,
        int    $reviewrightanswer = -1,
        int    $reviewoverallfeedback = -1,
        string $overallfeedbacktextpass = '',
        string $overallfeedbacktextfail = ''
    ): array {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'   => $courseid,
            'sectionnum' => $sectionnum,
            'name'       => $name,
            'intro'      => $intro,
            'mode'       => $mode,
            'gradepass'  => $gradepass,
            'timelimit'  => $timelimit,
            'visible'    => $visible,
            'preferredbehaviour' => $preferredbehaviour,
            'navmethod' => $navmethod,
            'questionsperpage' => $questionsperpage,
            'attempts' => $attempts,
            'attemptonlast' => $attemptonlast,
            'grademethod' => $grademethod,
            'delay1' => $delay1,
            'delay2' => $delay2,
            'shuffleanswers' => $shuffleanswers,
            'decimalpoints' => $decimalpoints,
            'completion' => $completion,
            'completionusegrade' => $completionusegrade,
            'completionpassgrade' => $completionpassgrade,
            'reviewattempt' => $reviewattempt,
            'reviewcorrectness' => $reviewcorrectness,
            'reviewmaxmarks' => $reviewmaxmarks,
            'reviewmarks' => $reviewmarks,
            'reviewspecificfeedback' => $reviewspecificfeedback,
            'reviewgeneralfeedback' => $reviewgeneralfeedback,
            'reviewrightanswer' => $reviewrightanswer,
            'reviewoverallfeedback' => $reviewoverallfeedback,
            'overallfeedbacktextpass' => $overallfeedbacktextpass,
            'overallfeedbacktextfail' => $overallfeedbacktextfail,
        ]);

        $moderesolution = self::normalize_mode($params['mode']);
        $modekey = $moderesolution['mode'];

        $defaults = self::apply_field_overrides(self::mode_defaults($modekey), $params);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename  = 'quiz';
        $moduleinfo->module      = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
        $moduleinfo->course      = $params['courseid'];
        $moduleinfo->section     = $params['sectionnum'];
        $moduleinfo->name        = $params['name'];
        $moduleinfo->visible     = $params['visible'];

        // Intro / description.
        $moduleinfo->intro       = $params['intro'];
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->showdescription = 0;

        // Frageverhalten (Modus-spezifisch).
        $moduleinfo->preferredbehaviour = $defaults['preferredbehaviour'];

        // Timing. Layered Defaults: expliziter timelimit-Parameter (>0) ueberschreibt Modus-Default.
        $moduleinfo->timeopen    = 0;
        $moduleinfo->timeclose   = 0;
        $moduleinfo->timelimit   = $params['timelimit'] > 0 ? $params['timelimit'] : $defaults['timelimit'];
        $moduleinfo->overduehandling = 'autosubmit';
        $moduleinfo->graceperiod = 0;

        // Versuche und Bewertungsmethode (Modus-spezifisch).
        $moduleinfo->attempts      = $defaults['attempts'];
        $moduleinfo->attemptonlast = $defaults['attemptonlast'];
        $moduleinfo->grademethod   = $defaults['grademethod'];

        // Layout.
        $moduleinfo->questionsperpage = $defaults['questionsperpage'];
        $moduleinfo->navmethod        = $defaults['navmethod'];

        // Antwortoptionen gemischt (alle Modi).
        $moduleinfo->shuffleanswers = $defaults['shuffleanswers'];

        // Review-Optionen (Modus-spezifisch). quiz_process_options() berechnet
        // die review*-Spalten aus Einzel-Formularfeldern neu, siehe
        // apply_review_options().
        self::apply_review_options($moduleinfo, [
            'reviewattempt'          => $defaults['reviewattempt'],
            'reviewcorrectness'      => $defaults['reviewcorrectness'],
            'reviewmaxmarks'         => $defaults['reviewmaxmarks'],
            'reviewmarks'            => $defaults['reviewmarks'],
            'reviewspecificfeedback' => $defaults['reviewspecificfeedback'],
            'reviewgeneralfeedback'  => $defaults['reviewgeneralfeedback'],
            'reviewrightanswer'      => $defaults['reviewrightanswer'],
            'reviewoverallfeedback'  => $defaults['reviewoverallfeedback'],
        ]);

        // Darstellung.
        $moduleinfo->questiondecimalpoints = -1;
        $moduleinfo->decimalpoints         = $defaults['decimalpoints'];
        $moduleinfo->showuserpicture       = 0;
        $moduleinfo->showblocks            = 0;
        $moduleinfo->completionattemptsexhausted = 0;
        $moduleinfo->completionminattempts       = 0;
        $moduleinfo->completion                  = $defaults['completion'];
        $moduleinfo->completionview              = 0;
        $moduleinfo->completionusegrade          = $defaults['completionusegrade'];
        $moduleinfo->completiongradeitemnumber   = 0;
        $moduleinfo->completionpassgrade         = $defaults['completionpassgrade'];
        // quiz_process_options() liest $quiz->quizpassword (Formularfeld-Name)
        // und schreibt es nach $quiz->password; ohne quizpassword wirft Moodle
        // 5.0 "Undefined property: stdClass::$quizpassword".
        $moduleinfo->password   = '';
        $moduleinfo->quizpassword = '';
        $moduleinfo->subnet     = '';
        $moduleinfo->browsersecurity = '-';
        $moduleinfo->delay1     = $defaults['delay1'];
        $moduleinfo->delay2     = $defaults['delay2'];

        // edit_module_post_actions() (course/modlib.php, von add_moduleinfo()
        // aufgerufen) liest $moduleinfo->cmidnumber unconditional beim Sync
        // mit grade_item->idnumber; ohne dieses Feld wirft Moodle 5.0
        // "Undefined property: stdClass::$cmidnumber".
        $moduleinfo->cmidnumber = '';

        // Bewertung: gradepass als Prozentsatz von grade=100 (Moodle-Standard).
        // Layered Defaults: expliziter gradepass-Parameter (>0) ueberschreibt Modus-Default.
        $moduleinfo->grade     = 100;
        $moduleinfo->gradepass = $params['gradepass'] > 0 ? $params['gradepass'] : $defaults['gradepass'];
        $moduleinfo->gradecat  = 0;

        $moduleinfo = add_moduleinfo($moduleinfo, $course);
        self::save_overall_feedback((int) $moduleinfo->instance, $defaults['overallfeedback'], (float) $moduleinfo->gradepass);

        return [
            'cmid'           => (int) $moduleinfo->coursemodule,
            'mode'           => $modekey,
            'deprecatedmode' => $moderesolution['deprecated'],
            'message'        => 'Quiz "' . $params['name'] . '" successfully created (mode=' . $modekey . ').',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'           => new external_value(PARAM_INT,   'Course module ID of the created quiz'),
            'mode'           => new external_value(PARAM_TEXT, 'Tatsächlich angewendeter Modus'),
            'deprecatedmode' => new external_value(PARAM_BOOL, 'True when a deprecated alias was accepted and mapped'),
            'message'        => new external_value(PARAM_TEXT,  'Success message'),
        ]);
    }
}
