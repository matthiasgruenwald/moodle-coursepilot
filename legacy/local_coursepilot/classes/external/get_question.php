<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->libdir . '/filelib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;

/**
 * Liefert die latest version einer Frage in einer Kategorie, eindeutig
 * identifiziert per Name ODER per questionid (ID einer beliebigen Version
 * derselben Frage).
 *
 * Liefert auch die Antwort-Optionen und kennzeichnet die richtige Antwort,
 * damit Aufrufer (z.B. der MCP-Server) vor einem Edit sehen, mit welcher
 * Frage gearbeitet wird.
 */
class get_question extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT,  'ID der Fragenbank-Kategorie'),
            'name'       => new external_value(PARAM_TEXT, 'Name der Frage (alternativ zu questionid)', VALUE_DEFAULT, ''),
            'questionid' => new external_value(PARAM_INT,  'questionid einer beliebigen Version der Frage (alternativ zu name)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $categoryid, string $name = '', int $questionid = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'name'       => $name,
            'questionid' => $questionid,
        ]);

        if ($params['name'] === '' && $params['questionid'] === 0) {
            throw new \invalid_parameter_exception(
                'Es muss entweder name oder questionid angegeben werden.');
        }

        $category = $DB->get_record('question_categories',
            ['id' => $params['categoryid']], '*', MUST_EXIST);
        $context = \context::instance_by_id($category->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // moodle/question:view existiert nicht (mehr); Moodle kennt nur
        // viewmine/viewall. viewall passt zur Lese-Capability hier.
        require_capability('moodle/question:viewall', $context);

        // questionbankentryid bestimmen.
        $entryid = $params['questionid'] > 0
            ? self::entry_id_from_questionid((int) $params['questionid'])
            : self::entry_id_from_name((int) $params['categoryid'], (string) $params['name']);

        if ($entryid === 0) {
            throw new \moodle_exception('notfound', 'error', '',
                null, 'Keine Frage gefunden fuer die uebergebenen Kriterien.');
        }

        // Latest Version dieser Entry laden.
        $latest = $DB->get_record_sql(
            'SELECT * FROM {question_versions}
              WHERE questionbankentryid = ?
           ORDER BY version DESC',
            [$entryid],
            IGNORE_MULTIPLE
        );
        if (!$latest) {
            throw new \moodle_exception('notfound', 'error', '',
                null, 'Keine Version fuer questionbankentryid ' . $entryid . ' gefunden.');
        }

        $question = $DB->get_record('question',
            ['id' => $latest->questionid], '*', MUST_EXIST);

        // Antworten laden (in der Reihenfolge ihrer IDs = Anlege-Reihenfolge).
        $answers = $DB->get_records('question_answers',
            ['question' => $question->id], 'id ASC');

        $answerlist = [];
        $correctindex = -1;
        $i = 0;
        foreach ($answers as $a) {
            $answerlist[] = [
                'id'       => (int) $a->id,
                'answer'   => (string) $a->answer,
                'fraction' => (float) $a->fraction,
                'feedback' => (string) $a->feedback,
                'correct'  => (float) $a->fraction > 0,
            ];
            if ((float) $a->fraction >= 1.0 && $correctindex === -1) {
                $correctindex = $i;
            }
            $i++;
        }
        $multichoice = $DB->get_record('qtype_multichoice_options', ['questionid' => $question->id]);
        $selectionmode = $multichoice && empty($multichoice->single) ? 'multiple' : 'single';
        // 'none' ist der von mc_question_version.php fest gesetzte Wert (Issue #324).
        // Fallback nur fuer den (regulaer nicht erwarteten) Fall, dass zu einer
        // multichoice-Frage kein qtype_multichoice_options-Datensatz existiert.
        $answernumbering = $multichoice ? (string) $multichoice->answernumbering : 'none';

        $bankentry = $DB->get_record('question_bank_entries', ['id' => $entryid], '*', MUST_EXIST);

        $fs = get_file_storage();
        $files = array_merge(
            self::collect_files($fs, $context->id, 'questiontext', $question->id),
            self::collect_files($fs, $context->id, 'generalfeedback', $question->id)
        );
        foreach ($answerlist as $a) {
            $files = array_merge(
                $files,
                self::collect_files($fs, $context->id, 'answer', $a['id']),
                self::collect_files($fs, $context->id, 'answerfeedback', $a['id'])
            );
        }

        return [
            'questionid'          => (int) $question->id,
            'questionbankentryid' => (int) $entryid,
            'categoryid'          => (int) $bankentry->questioncategoryid,
            'version'             => (int) $latest->version,
            'name'                => (string) $question->name,
            'questiontext'        => (string) $question->questiontext,
            'generalfeedback'     => (string) $question->generalfeedback,
            'qtype'               => (string) $question->qtype,
            'defaultmark'         => (float)  $question->defaultmark,
            'idnumber'            => (string) ($bankentry->idnumber ?? ''),
            'answers'             => $answerlist,
            'correctindex'        => $correctindex,
            'selectionmode'       => $selectionmode,
            'answernumbering'     => $answernumbering,
            'files'               => $files,
        ];
    }

    /**
     * Liest die Dateien eines Bereichs (component 'question') und liefert
     * sie als strukturierte Metadaten (kein pluginfile.php-URL-Aufbau hier,
     * Issue #326, KP-006 Read-back).
     */
    private static function collect_files(\file_storage $fs, int $contextid, string $filearea, int $itemid): array {
        $result = [];
        foreach ($fs->get_area_files($contextid, 'question', $filearea, $itemid, 'filepath, filename', false) as $file) {
            $result[] = [
                'filearea' => $filearea,
                'itemid'   => $itemid,
                'filename' => $file->get_filename(),
                'filesize' => (int) $file->get_filesize(),
                'mimetype' => (string) $file->get_mimetype(),
            ];
        }
        return $result;
    }

    /**
     * Entry-ID ueber eine bekannte questionid (irgendeine Version) ermitteln.
     */
    private static function entry_id_from_questionid(int $questionid): int {
        global $DB;
        $v = $DB->get_record('question_versions', ['questionid' => $questionid]);
        return $v ? (int) $v->questionbankentryid : 0;
    }

    /**
     * Entry-ID ueber Kategorie + Frage-Name ermitteln (eindeutig anhand der
     * latest version, da der Name historisch in question.name liegt).
     * Bei mehreren Treffern wird der mit der hoechsten Version genommen.
     */
    private static function entry_id_from_name(int $categoryid, string $name): int {
        global $DB;
        // Join: Entry in Kategorie -> latest version -> question mit gesuchtem Namen.
        $sql = 'SELECT qbe.id AS entryid, qv.version AS version
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q           ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid = :catid
                   AND q.name = :name
              ORDER BY qbe.id ASC, qv.version DESC';
        $rows = $DB->get_records_sql($sql, ['catid' => $categoryid, 'name' => $name]);
        if (!$rows) {
            return 0;
        }
        // Erste Entry mit Match nehmen.
        $first = reset($rows);
        return (int) $first->entryid;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionid'          => new external_value(PARAM_INT,   'ID der latest-version question-Zeile'),
            'questionbankentryid' => new external_value(PARAM_INT,   'ID des question_bank_entries (Frage-Identitaet)'),
            'categoryid'          => new external_value(PARAM_INT,   'Aktuelle Fragenbank-Kategorie der Frage'),
            'version'             => new external_value(PARAM_INT,   'Aktuelle Versionsnummer'),
            'name'                => new external_value(PARAM_TEXT,  'Name der Frage'),
            'questiontext'        => new external_value(PARAM_RAW,   'Fragetext (HTML)'),
            'generalfeedback'     => new external_value(PARAM_RAW,   'Allgemeines Feedback (HTML)'),
            'qtype'               => new external_value(PARAM_TEXT,  'Fragetyp (i.d.R. multichoice)'),
            'defaultmark'         => new external_value(PARAM_FLOAT, 'Standard-Punktzahl der Frage'),
            'idnumber'            => new external_value(PARAM_TEXT,  'idnumber des question_bank_entries (leer, falls keine vergeben)'),
            'answers'             => new external_multiple_structure(
                new external_single_structure([
                    'id'       => new external_value(PARAM_INT,   'question_answers.id'),
                    'answer'   => new external_value(PARAM_RAW,   'Antwort-Text (HTML)'),
                    'fraction' => new external_value(PARAM_FLOAT, 'Gewicht der Antwort'),
                    'feedback' => new external_value(PARAM_RAW,   'Antwortspezifisches Feedback (HTML)'),
                    'correct'  => new external_value(PARAM_BOOL,  'Antwort hat positives Gewicht'),
                ]),
                'Antwort-Optionen in Anlege-Reihenfolge'
            ),
            'correctindex'        => new external_value(PARAM_INT,   '0-basierter Index der richtigen Antwort in answers[] (-1 wenn keine erkannt)'),
            'selectionmode'       => new external_value(PARAM_ALPHA, 'single oder multiple'),
            'answernumbering'     => new external_value(PARAM_ALPHANUM, 'Nummerierungsstil der Antworten (Kurspilot setzt stets none, KP-007; "123" bei Altfragen vor #324 moeglich)'),
            'files'               => new external_multiple_structure(
                new external_single_structure([
                    'filearea' => new external_value(PARAM_ALPHA, 'Dateibereich (questiontext, generalfeedback, answer, answerfeedback)'),
                    'itemid'   => new external_value(PARAM_INT,   'questionid (Fragenebene) oder answerid (Antwortebene), je nach Dateibereich'),
                    'filename' => new external_value(PARAM_FILE,  'Dateiname'),
                    'filesize' => new external_value(PARAM_INT,   'Dateigroesse in Byte'),
                    'mimetype' => new external_value(PARAM_RAW,   'MIME-Type'),
                ]),
                'Angehaengte Dateien je Bereich (questiontext, generalfeedback, answer, answerfeedback); keine pluginfile.php-URLs'
            ),
        ]);
    }
}
