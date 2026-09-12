<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kurspilot\material_files;
use local_kurspilot\question_suspect_gate;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * Read-modify-write fuer eine Multiple-Choice-Frage (Spec 0017 §7.1, Ticket
 * #419): "felder_json" ist ein PATCH, kein Vollstand (gleiches Vokabular wie
 * {@see update_module_settings}/{@see update_quiz_settings}) - nicht
 * mitgeschickte Felder duerfen NICHT verloren gehen.
 *
 * Anders als eine simple Text-basierte XML-Vorlage (wie
 * {@see create_mc_question::build_xml()}, die fuer eine NEUE Frage bewusst
 * feste Werte fuer penalty/shuffleanswers/answernumbering/Kombi-Feedback
 * setzt) liest dieser Endpunkt die Frage ueber
 * {@see export_questions_xml::resolve_native_question()} als NATIVES
 * Objekt ein - exakt die Form, die {@see \qformat_xml::writequestion()}
 * auch fuer den echten Export nutzt. Nur die im Patch genannten Properties
 * werden ueberschrieben (name, questiontext, generalfeedback, defaultmark,
 * options->single, options->answers); alles andere (penalty, hidden,
 * shuffleanswers, answernumbering, Kombi-Feedback, Tags, Hints, ...) bleibt
 * unangetastet, weil es nie angefasst wird - kein Rekonstruktions- oder
 * Rate-Risiko. Der VOLLSTAND wird anschliessend ueber denselben XML-Kern wie
 * {@see import_questions_xml} zurueckgeschrieben (inkl. Round-Trip-Pruefung
 * und Rollback).
 *
 * idnumber-Backfill (genau EINE Frage, kein Massenlauf): traegt die
 * vorgefundene Frage noch keine idnumber - z.B. aus einem Fremdbestand -,
 * wird beim ersten Schreibzugriff genau fuer DIESEN Bank-Eintrag eine
 * generiert und direkt in question_bank_entries geschrieben, BEVOR die XML
 * gebaut wird. Nur so erkennt import_questions_xml die geschriebene XML als
 * neue Version DESSELBEN Eintrags (Match ueber idnumber in der Kategorie,
 * ADR 0015) statt als neuen Eintrag. Backfill + Schreibvorgang laufen in
 * einer gemeinsamen Transaktion (gleiches Muster wie import_questions_xml
 * selbst) - schlaegt der Rundlauf fehl, wird auch die frisch vergebene
 * idnumber zurueckgerollt (siehe Kommentar bei $transaction unten).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class update_mc_question extends external_api {

    /** @var string[] Erlaubte Patch-Felder - alles andere ist ein Fehler (Trust-Boundary). */
    private const PATCHABLE_FIELDS = [
        'name', 'questiontext', 'selectionmode', 'answers', 'defaultmark', 'generalfeedback',
        // Spec 0018 §4/§7, Issue #435: Materialordner-Pfade fuer Bilder, die im
        // "questiontext"-Patch per @@PLUGINFILE@@ referenziert werden - siehe
        // {@see self::embed_material_images()}. Kein moduleinfo-Aequivalent,
        // dieses Feld landet nie auf dem nativen Fragenobjekt.
        'questiontext_bilder',
    ];

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'questionid einer beliebigen Version der zu aendernden Frage'),
            'felder_json' => new external_value(
                PARAM_RAW,
                'JSON-Objekt Feldname => neuer Wert - nur die zu aendernden Felder (Patch, kein Vollstand). '
                    . 'Erlaubt: name, questiontext, selectionmode, answers, defaultmark, generalfeedback, '
                    . 'questiontext_bilder. Nicht genannte Felder bleiben unveraendert. Ein Bild aus dem '
                    . 'Materialordner wird eingebettet, indem questiontext (bzw. das feedback einer Antwort in '
                    . 'answers) ein "<img src=\"@@PLUGINFILE@@/<dateiname>\" alt=\"...\">" enthaelt UND der '
                    . 'Dateiname zusaetzlich in questiontext_bilder (bzw. im answers-Eintrag unter '
                    . '"feedback_bilder") als Liste von Materialordner-Pfaden genannt wird.'
            ),
            'bestaetigt' => new external_value(
                PARAM_BOOL,
                'true bestaetigt ausdruecklich einen zuvor gemeldeten Verdachtsfall des XML-Kerns. Beim ersten '
                    . 'Aufruf weglassen oder false.',
                VALUE_DEFAULT,
                false
            ),
            'ort' => material_files::ort_parameter(),
        ]);
    }

    /**
     * @param int $questionid
     * @param string $felderjson
     * @param bool $bestaetigt
     * @param string $ort
     * @return array
     */
    public static function execute(
        int $questionid,
        string $felderjson,
        bool $bestaetigt = false,
        string $ort = material_files::ORT_BESTAND
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'questionid' => $questionid,
            'felder_json' => $felderjson,
            'bestaetigt' => $bestaetigt,
            'ort' => $ort,
        ]);

        [$question, $category, $context] = export_questions_xml::resolve_native_question($params['questionid']);
        self::validate_context($context);
        require_capability('local/kurspilot:use', $context);
        // Dieselbe Capability wie fuer eine neue Version in import_questions_xml/
        // create_mc_question - eine neue Version zu schreiben ist derselbe
        // Schreibvorgang, keine eigene "edit"-Kurspilot-Berechtigung.
        require_capability('moodle/question:add', $context);

        if ($question->qtype !== 'multichoice') {
            throw new \invalid_parameter_exception(
                'update_mc_question funktioniert nur fuer Multiple-Choice-Fragen (qtype "multichoice"); '
                    . 'diese Frage ist "' . $question->qtype . '".');
        }

        $patch = self::decode_patch($params['felder_json']);
        // Vor apply_patch() abgezweigt (Issue #435): questiontext_bilder ist
        // kein Feld des nativen Fragenobjekts, und feedback_bilder je
        // Antwort wuerde build_answer_objects() ohnehin verwerfen (dort
        // werden nur answer/fraction/feedback gelesen). Beide Listen werden
        // erst NACH dem Schreiben angewandt (siehe unten), weil sie die
        // question-/answerid der NEUEN Version brauchen - die entsteht erst
        // im import_questions_xml-Aufruf weiter unten.
        $questiontextimages = is_array($patch['questiontext_bilder'] ?? null) ? $patch['questiontext_bilder'] : [];
        $answerfeedbackimages = self::extract_answer_feedback_images($patch);
        // Alles-oder-nichts (gleiche Regel wie update_module_settings::validate_patch()):
        // Berechtigung, Endungs-Whitelist UND Materialdatei-Existenz werden
        // VOR der Schreib-Transaktion geprueft/aufgeloest - eine neue Version
        // wird nicht angelegt, nur um dann an einer trivialen
        // Einbett-Validierung zu scheitern. resolve_into_draft() wirft
        // materialfilenotfound bereits hier, wenn eine referenzierte Datei
        // fehlt.
        [$questiontextdraftitemid, $answerfeedbackdraftitemids] =
            self::prepare_image_drafts($context, $questiontextimages, $answerfeedbackimages, $params['ort']);
        self::apply_patch($question, $patch);

        $categoryid = (int) $category->id;
        $entry = get_question_bank_entry((int) $question->id);

        // Kein eigenes try/catch+rollback hier: import_questions_xml::execute()
        // rollt seine EIGENE (verschachtelte) Transaktion bei einem
        // Rundlauf-Fehler bereits selbst zurueck und wirft die Exception
        // weiter - ein zweiter rollback()-Aufruf auf dieser (dann bereits
        // beendeten) Transaktion wuerde selbst eine dml_transaction_exception
        // werfen. Bleibt DIESE Transaktion ohne allow_commit() verlassen
        // (weil die Exception unbehandelt durchgereicht wird), rollt Moodle
        // sie automatisch zurueck (Muster wie import_questions_xml
        // dokumentiert) - inklusive des eben vergebenen Backfills.
        $transaction = $DB->start_delegated_transaction();
        $backfilled = false;

        $idnumber = trim((string) ($entry->idnumber ?? ''));
        if ($idnumber === '') {
            // Backfill NUR fuer diesen einen Bank-Eintrag (Ticket #419) -
            // kein Massenlauf ueber Kategorie/Fragenbank.
            $idnumber = self::generate_idnumber();
            $DB->set_field('question_bank_entries', 'idnumber', $idnumber, ['id' => $entry->id]);
            $backfilled = true;
        }
        $question->idnumber = $idnumber;

        [$xml, $missingfiles] = export_questions_xml::question_to_xml($question, $category, $context);
        $wrapped = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n" . $xml . "\n</quiz>\n";

        $imported = import_questions_xml::execute($categoryid, $wrapped, $params['bestaetigt']);
        $imported = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $imported);

        $transaction->allow_commit();

        $result = $imported['questions'][0];

        if ($result['status'] === 'verdachtsfall') {
            // Kann nur bei einem gleichzeitigen fremden Eingriff auf denselben
            // Bank-Eintrag auftreten (die soeben zugewiesene idnumber passt
            // per Konstruktion) - dasselbe Antwortformat wie create_mc_question.
            return [
                'name' => $result['name'],
                'questionid' => 0,
                'questionbankentryid' => 0,
                'version' => 0,
                'status' => 'verdachtsfall',
                'idnumber_nachgetragen' => false,
                'meldung' => $result['meldung'],
                'idnumber' => $result['idnumber'],
                'categoryid' => $result['categoryid'],
                'candidates' => $result['candidates'],
                'questiontext_old' => $result['questiontext_old'],
                'questiontext_new' => $result['questiontext_new'],
            ];
        }

        $latest = question_suspect_gate::latest_version_question((int) $entry->id);

        if ($questiontextdraftitemid !== null || !empty($answerfeedbackdraftitemids)) {
            self::embed_images($context, (int) $latest->id, $questiontextdraftitemid, $answerfeedbackdraftitemids);
        }

        $meldung = 'MC-Frage "' . $question->name . '" aktualisiert (Bank-Eintrag ' . $result['questionbankentryid']
            . ', neue Version ' . $result['version'] . ').';
        if ($backfilled) {
            $meldung .= ' idnumber "' . $idnumber . '" wurde nachtraeglich vergeben (Frage hatte zuvor keine).';
        }
        if (!empty($missingfiles)) {
            // Gleiche Transparenzpflicht wie export_questions_xml: eingebettete
            // Dateien werden NICHT stillschweigend verloren, sondern die
            // Meldung nennt sie ausdruecklich.
            $meldung .= ' ACHTUNG: Die Frage enthielt eingebettete Dateien, die dabei entfernt wurden: '
                . implode(', ', $missingfiles) . '.';
        }

        return array_merge(
            [
                'name' => $result['name'],
                'questionid' => (int) $latest->id,
                'questionbankentryid' => (int) $result['questionbankentryid'],
                'version' => (int) $result['version'],
                'status' => 'aktualisiert',
                'idnumber_nachgetragen' => $backfilled,
                'meldung' => $meldung,
            ],
            question_suspect_gate::empty_result()
        );
    }

    /**
     * Dekodiert und validiert felder_json: muss ein JSON-Objekt sein, dessen
     * Schluessel eine Teilmenge von {@see self::PATCHABLE_FIELDS} sind -
     * unbekannte Felder brechen den Aufruf ab (Trust-Boundary), statt still
     * ignoriert zu werden.
     *
     * @param string $felderjson
     * @return array<string, mixed>
     */
    private static function decode_patch(string $felderjson): array {
        $patch = json_decode($felderjson, true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE || ($patch !== [] && array_is_list($patch))) {
            throw new moodle_exception('invalidpatchjson', 'local_kurspilot');
        }

        foreach (array_keys($patch) as $fieldname) {
            if (!in_array($fieldname, self::PATCHABLE_FIELDS, true)) {
                throw new \invalid_parameter_exception(
                    'Unbekanntes Feld "' . $fieldname . '" in felder_json. Erlaubt: '
                        . implode(', ', self::PATCHABLE_FIELDS) . '.');
            }
        }

        return $patch;
    }

    /**
     * Ueberschreibt auf dem NATIVEN Fragenobjekt (siehe
     * {@see export_questions_xml::resolve_native_question()}) nur die im
     * Patch genannten Properties - alles andere bleibt exakt erhalten
     * (Kerntest dieses Tickets), weil es nie angefasst wird. Validiert
     * anschliessend den (gepatchten oder unveraenderten) Antworten-/
     * Auswahlmodus-Stand mit denselben Regeln wie eine Neuanlage
     * ({@see create_mc_question::validate_answers()}).
     *
     * @param \stdClass $question Wird in-place veraendert.
     * @param array<string, mixed> $patch
     * @return void
     */
    private static function apply_patch(\stdClass $question, array $patch): void {
        if (array_key_exists('name', $patch)) {
            $question->name = (string) $patch['name'];
        }
        if (array_key_exists('questiontext', $patch)) {
            $question->questiontext = (string) $patch['questiontext'];
        }
        if (array_key_exists('generalfeedback', $patch)) {
            $question->generalfeedback = (string) $patch['generalfeedback'];
        }
        if (array_key_exists('defaultmark', $patch)) {
            $question->defaultmark = (float) $patch['defaultmark'];
        }
        if (array_key_exists('selectionmode', $patch)) {
            $mode = (string) $patch['selectionmode'];
            if (!in_array($mode, ['single', 'multiple'], true)) {
                throw new \invalid_parameter_exception('selectionmode muss single oder multiple sein.');
            }
            $question->options->single = $mode === 'single' ? 1 : 0;
        }
        if (array_key_exists('answers', $patch)) {
            $question->options->answers = self::build_answer_objects($patch['answers']);
        }

        // Nur validieren, wenn answers/selectionmode TATSAECHLICH im Patch
        // stehen: eine vorgefundene Fremdbestand-Frage, deren Antworten
        // Kurspilots striktere Anlage-Regeln (validate_answers) nicht
        // erfuellen, darf trotzdem in anderen Feldern gepatcht werden - sonst
        // wuerde ein reiner questiontext-Patch an genau der unangetasteten
        // Antwortstruktur scheitern, die dieses Ticket erhalten soll.
        if (array_key_exists('answers', $patch) || array_key_exists('selectionmode', $patch)) {
            $answersforvalidation = array_map(static fn(\stdClass $a): array => [
                'answer' => $a->answer,
                'fraction' => $a->fraction,
                'feedback' => $a->feedback,
            ], array_values((array) $question->options->answers));
            $selectionmode = empty($question->options->single) ? 'multiple' : 'single';
            create_mc_question::validate_answers($answersforvalidation, $selectionmode);
        }
    }

    /**
     * Baut die von {@see \qformat_xml::write_answer()} erwartete Objektform
     * je Antwort (answer/answerformat/fraction/feedback/feedbackformat/id -
     * id nur fuer Datei-Lookups verwendet, negative Platzhalter-IDs kommen
     * nie mit echten DB-IDs in Konflikt) aus den rohen Patch-Daten.
     *
     * @param mixed $answers
     * @return \stdClass[]
     */
    private static function build_answer_objects($answers): array {
        if (!is_array($answers) || $answers === []) {
            throw new \invalid_parameter_exception('"answers" muss eine nicht-leere Liste von Antwortoptionen sein.');
        }
        $objects = [];
        foreach (array_values($answers) as $i => $answer) {
            if (!is_array($answer) || !array_key_exists('answer', $answer) || !array_key_exists('fraction', $answer)) {
                throw new \invalid_parameter_exception(
                    'Jede Antwortoption in "answers" braucht "answer" und "fraction".');
            }
            $object = new \stdClass();
            $object->id = -($i + 1);
            $object->answer = (string) $answer['answer'];
            $object->answerformat = FORMAT_HTML;
            $object->fraction = (float) $answer['fraction'];
            $object->feedback = isset($answer['feedback']) ? (string) $answer['feedback'] : '';
            $object->feedbackformat = FORMAT_HTML;
            $objects[] = $object;
        }
        return $objects;
    }

    /**
     * Liest "feedback_bilder" je Antwortoption aus dem rohen (noch nicht in
     * native Objekte gewandelten) "answers"-Patch (Issue #435) - Index im
     * Rueckgabe-Array entspricht der Position in der answers-Liste, die
     * {@see self::build_answer_objects()} in derselben Reihenfolge auf das
     * native Fragenobjekt schreibt und die deshalb auch die neu geschriebenen
     * question_answers-Zeilen in dieser Reihenfolge ergibt (siehe
     * {@see self::embed_images()}).
     *
     * @param array<string, mixed> $patch
     * @return array<int, string[]> Index => Liste von Materialordner-Pfaden
     */
    private static function extract_answer_feedback_images(array $patch): array {
        $result = [];
        foreach (array_values($patch['answers'] ?? []) as $i => $answer) {
            if (is_array($answer) && !empty($answer['feedback_bilder'])) {
                if (!is_array($answer['feedback_bilder'])) {
                    throw new \invalid_parameter_exception('"feedback_bilder" muss eine Liste von Materialordner-Pfaden sein.');
                }
                $result[$i] = $answer['feedback_bilder'];
            }
        }
        return $result;
    }

    /**
     * Prueft Berechtigung + Einbett-Whitelist und loest jede angeforderte
     * Bildliste bereits VOR der Schreib-Transaktion in einen Dateimanager-
     * Entwurf auf (Issue #435) - Alles-oder-nichts wie bei jedem anderen
     * Patch dieses Plugins (vgl. update_module_settings::validate_patch()):
     * eine falsche Endung oder ein fehlendes Materialbild darf keine neue
     * Fragen-Version anlegen, die dann nur teilweise eingebettet ist.
     * material_files::resolve_into_draft() wirft materialfilenotfound schon
     * hier, wenn eine referenzierte Datei nicht existiert - der 4. Parameter
     * (Ziel-itemid) ist zu diesem Zeitpunkt irrelevant, weil die Zielzeile
     * (question/answer) noch gar nicht existiert; er dient nur dazu,
     * BEREITS an dieser itemid haengende Dateien vorzubelegen, was fuer eine
     * kuenftige question-/answerid ohnehin leer ist.
     *
     * @param \context $context Kategoriekontext (Ziel der Dateiablage).
     * @param string[] $questiontextimages Materialordner-Pfade fuer questiontext.
     * @param array<int, string[]> $answerfeedbackimages Antwortindex => Materialordner-Pfade.
     * @param string $ort {@see material_files::ORT_BESTAND}/{@see material_files::ORT_WERKBANK} -
     *        Quelle der Pfade (Issue #496).
     * @return array{0: int|null, 1: array<int, int>} [Entwurfs-Itemid fuer questiontext (null ohne Anfrage),
     *         Antwortindex => Entwurfs-Itemid fuer answerfeedback]
     * @throws moodle_exception materialfiledisallowedtype / materialfilenotfound / invalidmaterialpath /
     *         invalidmaterialort / materialpathiskontext / materialembedtoolarge
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    private static function prepare_image_drafts(
        \context $context,
        array $questiontextimages,
        array $answerfeedbackimages,
        string $ort
    ): array {
        if (empty($questiontextimages) && empty($answerfeedbackimages)) {
            return [null, []];
        }

        material_files::require_manage_own_files();
        self::assert_allowed_embed_extensions($questiontextimages);
        foreach ($answerfeedbackimages as $images) {
            self::assert_allowed_embed_extensions($images);
        }

        $questiontextdraftitemid = empty($questiontextimages)
            ? null
            : material_files::resolve_into_draft($context->id, 'question', 'questiontext', 0, $questiontextimages, $ort);

        $answerfeedbackdraftitemids = [];
        foreach ($answerfeedbackimages as $index => $images) {
            $answerfeedbackdraftitemids[$index] =
                material_files::resolve_into_draft($context->id, 'question', 'answerfeedback', 0, $images, $ort);
        }

        return [$questiontextdraftitemid, $answerfeedbackdraftitemids];
    }

    /**
     * @param string[] $paths
     * @return void
     * @throws moodle_exception materialfiledisallowedtype
     */
    private static function assert_allowed_embed_extensions(array $paths): void {
        foreach ($paths as $path) {
            if (!is_string($path) || !material_files::is_allowed_embed_image_extension($path)) {
                throw new moodle_exception('materialfiledisallowedtype', 'local_kurspilot', '', (object) [
                    'filename' => (string) $path,
                    'allowed' => implode(', ', material_files::allowed_embed_image_extensions()),
                ]);
            }
        }
    }

    /**
     * Schreibt die in {@see self::prepare_image_drafts()} bereits validierten
     * und aufgeloesten Dateientwuerfe in die Ziel-Fileareas der SOEBEN
     * geschriebenen neuen Version (Issue #435, Spec 0018 §4/§7) - erst NACH
     * dem Schreiben moeglich, weil question/questiontext bzw.
     * question/answerfeedback per Moodle-Konvention ueber die question-/
     * answerid adressiert werden, die es vor import_questions_xml::execute()
     * noch nicht gibt. Der Text (mit "@@PLUGINFILE@@/<dateiname>" plus
     * Alt-Text) hat der Aufrufer bereits im questiontext-/feedback-Patch
     * mitgeschickt - file_save_draft_area_files() loest darin nur den
     * Platzhalter gegen die echte pluginfile-URL auf, exakt der Mechanismus,
     * den question_type::save_question() fuer $form->questiontext['itemid']
     * nutzt (question/type/questiontypebase.php).
     *
     * @param \context $context
     * @param int $questionid Neue question.id der geschriebenen Version.
     * @param int|null $questiontextdraftitemid
     * @param array<int, int> $answerfeedbackdraftitemids Antwortindex => Entwurfs-Itemid.
     * @return void
     */
    private static function embed_images(
        \context $context,
        int $questionid,
        ?int $questiontextdraftitemid,
        array $answerfeedbackdraftitemids
    ): void {
        global $DB;

        $fileoptions = ['subdirs' => true, 'maxfiles' => -1, 'maxbytes' => 0];

        if ($questiontextdraftitemid !== null) {
            $current = $DB->get_field('question', 'questiontext', ['id' => $questionid], MUST_EXIST);
            $new = file_save_draft_area_files(
                $questiontextdraftitemid, $context->id, 'question', 'questiontext', $questionid, $fileoptions, $current);
            file_clear_draft_area($questiontextdraftitemid);
            $DB->set_field('question', 'questiontext', $new, ['id' => $questionid]);
        }

        if (!empty($answerfeedbackdraftitemids)) {
            $answers = array_values($DB->get_records('question_answers', ['question' => $questionid], 'id ASC'));
            foreach ($answerfeedbackdraftitemids as $index => $draftitemid) {
                if (!isset($answers[$index])) {
                    throw new \invalid_parameter_exception(
                        'feedback_bilder verweist auf Antwortoption ' . $index . ', aber "answers" hat nur '
                            . count($answers) . ' Eintraege.');
                }
                $answer = $answers[$index];
                $new = file_save_draft_area_files(
                    $draftitemid, $context->id, 'question', 'answerfeedback', (int) $answer->id,
                    $fileoptions, (string) $answer->feedback);
                file_clear_draft_area($draftitemid);
                $DB->set_field('question_answers', 'feedback', $new, ['id' => $answer->id]);
            }
        }
    }

    /** Generiert eine neue, eindeutige idnumber (gleiches Schema wie import_questions_xml). */
    private static function generate_idnumber(): string {
        return 'kp-' . bin2hex(random_bytes(8));
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(array_merge(
            [
                'name' => new external_value(PARAM_TEXT, 'Name der Frage'),
                'questionid' => new external_value(PARAM_INT, 'ID der neuen question-Zeile (0 bei "verdachtsfall")'),
                'questionbankentryid' => new external_value(
                    PARAM_INT,
                    'ID des question_bank_entries (Frage-Identitaet, unveraendert; 0 bei "verdachtsfall")'
                ),
                'version' => new external_value(PARAM_INT, 'Neue Versionsnummer (0 bei "verdachtsfall")'),
                'status' => new external_value(PARAM_ALPHA, '"aktualisiert" | "verdachtsfall"'),
                'idnumber_nachgetragen' => new external_value(
                    PARAM_BOOL,
                    'true, wenn diese Frage zuvor keine idnumber hatte und beim Schreiben genau eine bekam'
                ),
                'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Meldung mit Bank-Eintrag und Version'),
            ],
            question_suspect_gate::response_fields()
        ));
    }
}
