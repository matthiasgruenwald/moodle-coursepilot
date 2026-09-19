<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Kapselt die Versionserzeugung fuer MC-Fragen ueber Moodles eigene
 * question_type::save_question()-API (ADR-0001), statt einer manuellen
 * $DB->insert_record()-Kette fuer question/question_bank_entries/
 * question_versions/question_answers/qtype_multichoice_options.
 *
 * Analog zum Snapshot-und-Patch-Vorbild assign_settings.php: dieses Modul
 * ist der gemeinsame Kern fuer create_mc_question und update_mc_question
 * (Issue #321). Zwei Folge-Slices (Bild-Copy-on-Version KP-006, XML-Import
 * KP-011, siehe docs/specs/0012 und 0014) brauchen dieselbe Operation.
 *
 * save_question() erzeugt bei jedem Aufruf eine NEUE question-Zeile und eine
 * NEUE question_versions-Zeile; ob eine neue questionbankentryid entsteht
 * oder eine bestehende weiterverwendet wird, haengt allein davon ab, ob
 * $question->id vor dem Aufruf gesetzt ist (Moodle-Kernverhalten, siehe
 * question/type/questiontypebase.php). save_question() legt selbst schon
 * eine delegierte Transaktion an (questiontypebase.php, Zeile ~376) und
 * deckt damit question/question_bank_entries/question_versions/Antworten/
 * qtype_multichoice_options gemeinsam ab; eine zusaetzliche aeussere
 * Transaktion in diesem Modul waere redundant.
 */
class mc_question_version {

    /**
     * Legt eine neue MC-Frage (Version 1, neue questionbankentryid) an und
     * vergibt eine generierte idnumber.
     *
     * @param array $answers Liste aus ['answer'=>, 'correct'=>, 'feedback'=>, 'fraction'=>]
     * @return \stdClass {questionid, questionbankentryid, version, answerids}
     */
    public static function create(
        int $categoryid,
        string $name,
        string $questiontext,
        string $generalfeedback,
        float $defaultmark,
        string $selectionmode,
        array $answers
    ): \stdClass {
        $question = new \stdClass();
        $question->qtype = 'multichoice';

        $form = self::build_form($categoryid, $name, $questiontext, $generalfeedback,
            $defaultmark, $selectionmode, $answers);
        $form->idnumber = self::generate_idnumber();

        $saved = self::save($question, $form);

        return self::result($saved->id, null);
    }

    /**
     * Legt eine neue Version einer bestehenden MC-Frage an (dieselbe
     * questionbankentryid bleibt erhalten, die bisherige question-Zeile
     * bleibt fuer bestehende Quiz-Attempts unangetastet, ADR-0001). Die
     * bestehende idnumber der Frage bleibt erhalten.
     *
     * @param int $contextid Fragenbank-Kontext der Frage (Aufrufer hat ihn
     *      bereits fuer die Capability-Pruefung aufgeloest); wird fuer den
     *      Datei-Copy-on-Version (Issue #326, KP-006) gebraucht.
     * @param \stdClass $entry question_bank_entries-Zeile der bestehenden
     *      Frage (z. B. via get_question_bank_entry()), damit der Aufrufer
     *      sie nicht ein zweites Mal laden muss.
     * @return \stdClass {questionid, questionbankentryid, version, previousquestionid, answerids}
     */
    public static function update(
        int $contextid,
        \stdClass $entry,
        int $oldquestionid,
        string $name,
        string $questiontext,
        string $generalfeedback,
        float $defaultmark,
        string $selectionmode,
        array $answers
    ): \stdClass {
        $question = new \stdClass();
        $question->id = $oldquestionid;
        $question->qtype = 'multichoice';

        $form = self::build_form((int) $entry->questioncategoryid, $name, $questiontext,
            $generalfeedback, $defaultmark, $selectionmode, $answers);
        $form->idnumber = $entry->idnumber; // Bestehende idnumber erhalten (sonst loescht save_question() sie).

        $saved = self::save($question, $form);

        $result = self::result($saved->id, $oldquestionid);
        self::copy_files_to_new_version($contextid, $oldquestionid, $result->questionid, $result->answerids);

        return $result;
    }

    /**
     * Kopiert alle an der Vorgaengerversion haengenden Dateien
     * (questiontext, generalfeedback auf Fragenebene; answer, answerfeedback
     * je Antwort) auf die neue Version (Issue #326, KP-006). Kein erneuter
     * Upload durch die Lehrkraft noetig. Antworten werden per Position
     * gematcht (gleiche Reihenfolge wie in $answers uebergeben); Antworten
     * ohne Gegenstueck in der neuen Version (Anzahl geaendert) werden
     * uebersprungen.
     */
    private static function copy_files_to_new_version(
        int $contextid,
        int $oldquestionid,
        int $newquestionid,
        array $newanswerids
    ): void {
        global $DB;

        $fs = get_file_storage();

        foreach (['questiontext', 'generalfeedback'] as $filearea) {
            self::copy_area_files($fs, $contextid, $filearea, $oldquestionid, $newquestionid);
        }

        $oldanswerids = array_map('intval', array_values($DB->get_records_menu(
            'question_answers', ['question' => $oldquestionid], 'id ASC', 'id, id')));

        $pairs = min(count($oldanswerids), count($newanswerids));
        for ($i = 0; $i < $pairs; $i++) {
            foreach (['answer', 'answerfeedback'] as $filearea) {
                self::copy_area_files($fs, $contextid, $filearea, $oldanswerids[$i], $newanswerids[$i]);
            }
        }
    }

    /** Kopiert alle Dateien eines Bereichs (component 'question') von einem alten auf ein neues itemid. */
    private static function copy_area_files(
        \file_storage $fs,
        int $contextid,
        string $filearea,
        int $olditemid,
        int $newitemid
    ): void {
        $files = $fs->get_area_files($contextid, 'question', $filearea, $olditemid, 'filepath, filename', false);
        foreach ($files as $file) {
            $fs->create_file_from_storedfile(['itemid' => $newitemid], $file);
        }
    }

    /** Ruft die echte Moodle-Core-API auf: qtype_multichoice::save_question(). */
    private static function save(\stdClass $question, \stdClass $form): \stdClass {
        $qtype = \question_bank::get_qtype('multichoice');
        return $qtype->save_question($question, $form);
    }

    /**
     * Baut das einheitliche Rueckgabeobjekt. Die Antwort-IDs werden hier an
     * genau einer Stelle nachgeladen (statt in jedem Aufrufer erneut): sie
     * sind nicht Teil von save_question()s Rueckgabe, aber qtype_multichoice
     * legt sie beim Speichern strikt in der Reihenfolge von $form->answer[]
     * an (siehe qtype_multichoice::save_question_options()), sodass
     * 'id ASC' verlaesslich die urspruengliche answers[]-Reihenfolge liefert
     * - genau die Invariante, auf die sich auch get_question.php stuetzt.
     */
    private static function result(int $questionid, ?int $previousquestionid): \stdClass {
        global $DB;

        $version = $DB->get_record('question_versions', ['questionid' => $questionid], '*', MUST_EXIST);
        $answerids = array_map('intval', array_values($DB->get_records_menu(
            'question_answers', ['question' => $questionid], 'id ASC', 'id, id')));

        $result = [
            'questionid'          => $questionid,
            'questionbankentryid' => (int) $version->questionbankentryid,
            'version'             => (int) $version->version,
            'answerids'           => $answerids,
        ];
        if ($previousquestionid !== null) {
            $result['previousquestionid'] = $previousquestionid;
        }
        return (object) $result;
    }

    /**
     * Baut das $form-Objekt, das question_type::save_question() und
     * qtype_multichoice::save_question_options() erwarten (Feldnamen und
     * -formen entsprechen dem Fragen-Editierformular).
     */
    private static function build_form(
        int $categoryid,
        string $name,
        string $questiontext,
        string $generalfeedback,
        float $defaultmark,
        string $selectionmode,
        array $answers
    ): \stdClass {
        $form = new \stdClass();
        $form->category = (string) $categoryid;
        $form->name = $name;
        $form->questiontext = ['text' => $questiontext, 'format' => FORMAT_HTML, 'itemid' => 0];
        $form->generalfeedback = ['text' => $generalfeedback, 'format' => FORMAT_HTML, 'itemid' => 0];
        $form->defaultmark = $defaultmark;
        $form->penalty = 0;
        $form->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

        // qtype_multichoice::save_question_options()-spezifische Felder.
        $form->single = $selectionmode === 'single' ? 1 : 0;
        $form->shuffleanswers = 1;
        // Fest auf 'none': Antworten werden gemischt (shuffleanswers), eine
        // aufgedruckte Nummerierung (abc/123) waere didaktisch irrefuehrend
        // und veraendert sich bei jedem Versuch (Issue #324, KP-007).
        $form->answernumbering = 'none';
        $form->showstandardinstruction = 0;
        $form->shownumcorrect = 0;
        $form->correctfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $form->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $form->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];

        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach (array_values($answers) as $index => $answer) {
            $form->answer[$index] = ['text' => (string) $answer['answer'], 'format' => FORMAT_HTML, 'itemid' => 0];
            $form->fraction[$index] = (float) $answer['fraction'];
            $form->feedback[$index] = ['text' => (string) $answer['feedback'], 'format' => FORMAT_HTML, 'itemid' => 0];
        }

        return $form;
    }

    /** Generiert eine neue, eindeutige idnumber fuer eine frisch angelegte Frage. */
    private static function generate_idnumber(): string {
        return 'kp-' . bin2hex(random_bytes(8));
    }
}
