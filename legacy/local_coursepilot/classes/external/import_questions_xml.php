<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use qformat_xml;
use question_bank;
use core_question\local\bank\question_version_status;

/**
 * Importiert Moodle-XML-Fragen (Moodle-Fragenexportformat) versionstreu.
 *
 * Parst ueber die oeffentliche, reine Parse-API qformat_xml::readquestions()
 * (kein DB-Zugriff) und schreibt gezielt ueber
 * question_type::save_question() - NICHT ueber
 * qformat_default::importprocess(). importprocess() legt bei jedem Aufruf
 * einen neuen question_bank_entries-Datensatz an und kennt kein Matching
 * gegen bestehende Eintraege (siehe Recherche, Branch
 * research/fragen-xml-import, docs/research/2026-08-14-fragen-xml-import.md);
 * fuer einen redaktionellen Reimport-Workflow (Frage korrigieren, neu
 * importieren, Quiz-Slots mit "immer aktuellste Version" folgen automatisch)
 * ist das unbrauchbar. Analog zu mc_question_version.php (Issue #321,
 * ADR-0001) wird stattdessen $question->id VOR dem save_question()-Aufruf
 * gesetzt, wenn ein bestehender Eintrag eindeutig wiedererkannt wurde.
 *
 * Wiedererkennung ausschliesslich innerhalb der Zielkategorie: idnumber
 * massgeblich, Namens-Fallback nur ohne idnumber im XML. Jeder unsichere
 * Fall (idnumber ohne Treffer, oder Namenstreffer ohne idnumber) ist ein
 * "Verdachtsfall": es wird nichts geschrieben, bis ein erneuter Aufruf mit
 * allownew=true das bestaetigt (docs/specs/0014).
 *
 * Bekannte Grenze dieser ersten Ausbaustufe: eingebettete <file>-Bloecke
 * (base64-Bilder im XML) werden nicht in echte Fragenbank-Dateien
 * uebernommen - der reine Textimport (inkl. STACK-Variablen/PRTs, die als
 * gewoehnliche qtype-Felder mitkommen) funktioniert unabhaengig davon. Kein
 * Blocker fuer die MC-Testfaelle von Issue #327; nachzuziehen, sobald ein
 * echter Datei-Roundtrip gebraucht wird.
 */
class import_questions_xml extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'ID der Ziel-Fragenbank-Kategorie'),
            'xmlcontent' => new external_value(PARAM_RAW, 'Moodle-XML-Fragenexport als Text'),
            'allownew'   => new external_value(PARAM_BOOL,
                'Bestaetigt einen zuvor gemeldeten Verdachtsfall (idnumber ohne Treffer bzw. ' .
                'Namens-Fallback mit Treffer) und legt ihn als neuen Eintrag an', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(int $categoryid, string $xmlcontent, bool $allownew = false): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'xmlcontent' => $xmlcontent,
            'allownew'   => $allownew,
        ]);

        $category = $DB->get_record('question_categories', ['id' => $params['categoryid']], '*', MUST_EXIST);
        $context = \context::instance_by_id($category->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/question:add', $context);

        // Reines Parsen, kein DB-Zugriff: ein ungueltiges XML wirft hier,
        // BEVOR irgendetwas geschrieben wird (kein Teilergebnis moeglich).
        $questions = self::parse($category, $context, $params['xmlcontent']);

        $results = [];
        foreach ($questions as $question) {
            $results[] = self::import_one($category, $context, $question, $params['allownew']);
        }

        return ['questions' => $results];
    }

    /**
     * Parst das XML ausschliesslich lesend ueber qformat_xml::readquestions().
     * Wirft bei jedem Parse-Problem (ungueltige Struktur, unbekannter
     * Fragetyp ohne import_from_xml()) eine Exception - der gesamte Aufruf
     * bricht damit ab, kein Teilergebnis wird geschrieben.
     */
    private static function parse(\stdClass $category, \context $context, string $xmlcontent): array {
        $qformat = new qformat_xml();
        $qformat->setCategory($category);
        // Nur der Zielkontext ist bekannt; $CATEGORY:-Direktiven auf andere
        // Kontexte werden dadurch nicht unterstuetzt (Out of Scope,
        // docs/specs/0014: "Vollstaendige Formularsteuerung ... ist bewusster
        // Ausfallweg statt Ersatz").
        $qformat->setContexts([$context]);
        $qformat->setStoponerror(true);
        $qformat->setMatchgrades('grade');
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $xmlcontent));

        try {
            $questions = $qformat->readquestions($lines);
        } catch (\Throwable $e) {
            throw new \invalid_parameter_exception('Ungueltiges Moodle-XML: ' . $e->getMessage());
        }

        // Kategorie-Direktiven ($CATEGORY:) sind keine Fragen; dieser Adapter
        // schreibt ausschliesslich in die uebergebene categoryid.
        $questions = array_values(array_filter((array) $questions, static function ($question) {
            return !isset($question->qtype) || $question->qtype !== 'category';
        }));

        if (empty($questions)) {
            throw new \invalid_parameter_exception('Das XML enthaelt keine importierbaren Fragen.');
        }

        return $questions;
    }

    /**
     * Wiedererkennung + Schreiben einer einzelnen geparsten Frage.
     */
    private static function import_one(
        \stdClass $category,
        \context $context,
        \stdClass $question,
        bool $allownew
    ): array {
        $name = (string) ($question->name ?? '');
        $xmlidnumber = trim((string) ($question->idnumber ?? ''));
        $newquestiontext = self::text_of($question->questiontext ?? '');

        $match = self::find_match((int) $category->id, $xmlidnumber, $name);

        if ($match === null) {
            // Echter Erstimport: keine idnumber im XML, kein Namenstreffer.
            $idnumber = self::resolve_new_idnumber($xmlidnumber);
            $saved = self::save($category, $context, $question, null, $idnumber);
            return self::result($saved, 'created', $name, false, null, $newquestiontext);
        }

        if ($match->ambiguous) {
            if (!$allownew) {
                $result = [
                    'name'                => $name,
                    'questionbankentryid' => 0,
                    'version'             => 0,
                    'status'              => 'skipped_ambiguous',
                    'questiontext_old'    => $match->questiontext ?? '',
                    'questiontext_new'    => $newquestiontext,
                ];
                if ($match->reason === 'idnumber_notfound') {
                    // docs/specs/0014: "Ein Verdachtsfall ohne idnumber-
                    // Treffer nennt zusaetzlich die mitgebrachte idnumber,
                    // Zielkategorie und nahe Kandidaten."
                    $result['xml_idnumber'] = $match->idnumber;
                    $result['categoryid']   = (int) $category->id;
                    $result['candidates']   = self::find_name_candidates((int) $category->id, $name);
                }
                return $result;
            }
            // Bestaetigter Verdachtsfall: neuer Eintrag. Die fremde idnumber
            // (falls vorhanden) wird uebernommen; ohne idnumber wird - wie
            // beim echten Erstimport - eine generierte vergeben.
            $idnumber = self::resolve_new_idnumber($xmlidnumber);
            $saved = self::save($category, $context, $question, null, $idnumber);
            return self::result($saved, 'created', $name, true, $match->questiontext, $newquestiontext);
        }

        // Eindeutiger idnumber-Treffer: neue Version desselben Eintrags.
        $saved = self::save($category, $context, $question, $match->questionid, $match->idnumber);
        return self::result($saved, 'versioned', $name, true, $match->questiontext, $newquestiontext);
    }

    /**
     * Sucht einen bestehenden Fragenbank-Eintrag in der Zielkategorie.
     *
     * @return \stdClass|null null = kein Treffer, keine idnumber im XML =>
     *      echter Erstimport. Sonst {ambiguous, reason, questionid,
     *      idnumber, questiontext}: ambiguous=false ist der eindeutige
     *      idnumber-Treffer (neue Version), ambiguous=true ist jeder
     *      unsichere Fall (reason 'idnumber_notfound' ODER 'name_match').
     */
    private static function find_match(int $categoryid, string $xmlidnumber, string $name): ?\stdClass {
        global $DB;

        if ($xmlidnumber !== '') {
            $entry = $DB->get_record('question_bank_entries', [
                'questioncategoryid' => $categoryid,
                'idnumber'           => $xmlidnumber,
            ]);
            if (!$entry) {
                return (object) [
                    'ambiguous'    => true,
                    'reason'       => 'idnumber_notfound',
                    'questionid'   => 0,
                    'idnumber'     => $xmlidnumber,
                    'questiontext' => null,
                ];
            }
            $latest = self::latest_version_question((int) $entry->id);
            return (object) [
                'ambiguous'    => false,
                'reason'       => 'idnumber_match',
                'questionid'   => (int) $latest->id,
                'idnumber'     => $xmlidnumber,
                'questiontext' => self::text_of($latest->questiontext),
            ];
        }

        // Namens-Fallback ausschliesslich ohne idnumber im XML.
        $sql = 'SELECT qbe.id AS entryid, qbe.idnumber AS idnumber
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q           ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid = :catid
                   AND q.name = :name
              ORDER BY qbe.id ASC, qv.version DESC';
        $rows = $DB->get_records_sql($sql, ['catid' => $categoryid, 'name' => $name]);
        if (!$rows) {
            return null;
        }
        $first = reset($rows);
        $entryid = (int) $first->entryid;
        $latest = self::latest_version_question($entryid);
        return (object) [
            'ambiguous'    => true,
            'reason'       => 'name_match',
            'questionid'   => (int) $latest->id,
            'idnumber'     => (string) ($first->idnumber ?? ''),
            'questiontext' => self::text_of($latest->questiontext),
        ];
    }

    /**
     * Nahe Kandidaten fuer den Verdachtsfall "idnumber ohne Treffer":
     * bestehende Eintraege in der Zielkategorie mit demselben Fragenamen
     * (docs/specs/0014: "nahe Kandidaten (gleichnamige Eintraege)").
     */
    private static function find_name_candidates(int $categoryid, string $name): array {
        global $DB;

        $sql = 'SELECT DISTINCT qbe.id AS entryid, qbe.idnumber AS idnumber, q.name AS name
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q           ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid = :catid
                   AND q.name = :name';
        $rows = $DB->get_records_sql($sql, ['catid' => $categoryid, 'name' => $name]);

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = [
                'questionbankentryid' => (int) $row->entryid,
                'idnumber'            => (string) ($row->idnumber ?? ''),
                'name'                => (string) $row->name,
            ];
        }
        return $candidates;
    }

    /** Laedt die question-Zeile der neuesten Version eines Fragenbank-Eintrags. */
    private static function latest_version_question(int $entryid): \stdClass {
        global $DB;
        $version = $DB->get_record_sql(
            'SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC',
            [$entryid],
            IGNORE_MULTIPLE
        );
        return $DB->get_record('question', ['id' => $version->questionid], '*', MUST_EXIST);
    }

    /**
     * Ruft question_type::save_question() auf - mit $question->id fuer eine
     * neue Version eines bestehenden Eintrags, ohne fuer einen neuen
     * Eintrag (dasselbe Kernverhalten wie mc_question_version.php).
     */
    private static function save(
        \stdClass $category,
        \context $context,
        \stdClass $question,
        ?int $oldquestionid,
        string $idnumber
    ): \stdClass {
        $form = clone $question;
        $form->category = $category->id . ',' . $context->id;
        $form->status = question_version_status::QUESTION_STATUS_READY;
        $form->idnumber = $idnumber;
        $form->questiontext = self::as_text_array(
            $question->questiontext ?? '', $question->questiontextformat ?? FORMAT_HTML);
        $form->generalfeedback = self::as_text_array(
            $question->generalfeedback ?? '', $question->generalfeedbackformat ?? FORMAT_HTML);
        if (!isset($form->defaultmark)) {
            // Moodle-XML-Export nutzt historisch das Feld <defaultgrade>.
            $form->defaultmark = $question->defaultgrade ?? 1.0;
        }
        if (!isset($form->penalty)) {
            $form->penalty = 0.0;
        }

        $towrite = new \stdClass();
        $towrite->qtype = $question->qtype;
        if ($oldquestionid !== null) {
            $towrite->id = $oldquestionid;
        }

        $qtype = question_bank::get_qtype($question->qtype);
        return $qtype->save_question($towrite, $form);
    }

    private static function result(
        \stdClass $saved,
        string $status,
        string $name,
        bool $includetextdiff,
        ?string $oldtext,
        string $newtext
    ): array {
        global $DB;

        $version = $DB->get_record('question_versions', ['questionid' => $saved->id], '*', MUST_EXIST);
        $result = [
            'name'                => $name,
            'questionbankentryid' => (int) $version->questionbankentryid,
            'version'             => (int) $version->version,
            'status'              => $status,
        ];
        if ($includetextdiff) {
            $result['questiontext_old'] = $oldtext ?? '';
            $result['questiontext_new'] = $newtext;
        }
        return $result;
    }

    /** Extrahiert reinen Text aus einem qformat-Feld (String oder ['text'=>...]-Array). */
    private static function text_of($value): string {
        if (is_array($value)) {
            return (string) ($value['text'] ?? '');
        }
        if (is_object($value) && isset($value->text)) {
            return (string) $value->text;
        }
        return (string) $value;
    }

    /** Baut die von save_question() erwartete ['text','format','itemid']-Struktur. */
    private static function as_text_array($value, $format): array {
        if (is_array($value) && array_key_exists('text', $value)) {
            return [
                'text'   => (string) $value['text'],
                'format' => $value['format'] ?? $format,
                'itemid' => $value['itemid'] ?? 0,
            ];
        }
        return ['text' => self::text_of($value), 'format' => $format ?? FORMAT_HTML, 'itemid' => 0];
    }

    /** idnumber fuer einen neuen Eintrag: die aus dem XML, sonst eine generierte. */
    private static function resolve_new_idnumber(string $xmlidnumber): string {
        return $xmlidnumber !== '' ? $xmlidnumber : self::generate_idnumber();
    }

    /** Generiert eine neue, eindeutige idnumber (gleiches Schema wie mc_question_version). */
    private static function generate_idnumber(): string {
        return 'kp-' . bin2hex(random_bytes(8));
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'name'                => new external_value(PARAM_TEXT, 'Name der importierten Frage'),
                    'questionbankentryid' => new external_value(PARAM_INT,
                        'ID des question_bank_entries (0 bei skipped_ambiguous)'),
                    'version'             => new external_value(PARAM_INT,
                        'Neue Versionsnummer (0 bei skipped_ambiguous)'),
                    'status'              => new external_value(PARAM_ALPHANUMEXT,
                        'created | versioned | skipped_ambiguous'),
                    'questiontext_old'    => new external_value(PARAM_RAW,
                        'Fragetext der Vorgaenger- bzw. Kandidatenversion', VALUE_OPTIONAL),
                    'questiontext_new'    => new external_value(PARAM_RAW,
                        'Fragetext aus dem importierten XML', VALUE_OPTIONAL),
                    'xml_idnumber'        => new external_value(PARAM_TEXT,
                        'Mitgebrachte idnumber aus dem XML (nur bei skipped_ambiguous ohne idnumber-Treffer)',
                        VALUE_OPTIONAL),
                    'categoryid'          => new external_value(PARAM_INT,
                        'Zielkategorie (nur bei skipped_ambiguous ohne idnumber-Treffer)', VALUE_OPTIONAL),
                    'candidates'          => new external_multiple_structure(
                        new external_single_structure([
                            'questionbankentryid' => new external_value(PARAM_INT, 'ID des bestehenden Eintrags'),
                            'idnumber'            => new external_value(PARAM_TEXT, 'idnumber des bestehenden Eintrags'),
                            'name'                => new external_value(PARAM_TEXT, 'Name des bestehenden Eintrags'),
                        ]),
                        'Nahe Kandidaten (gleichnamige Eintraege in der Zielkategorie), nur bei skipped_ambiguous ohne idnumber-Treffer',
                        VALUE_OPTIONAL
                    ),
                ]),
                'Ein Ergebnis-Eintrag je importierter Frage im XML'
            ),
        ]);
    }
}
