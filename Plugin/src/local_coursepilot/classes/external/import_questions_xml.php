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

namespace local_coursepilot\external;

use context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_question\local\bank\question_version_status;
use local_coursepilot\material_files;
use local_coursepilot\question_suspect_gate;
use qformat_xml;
use question_bank;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Der XML-Kern (Spec 0017 §7.1, Ticket #415): importiert Moodle-XML-Fragen
 * beliebigen Typs versionstreu - "die Lehrkraft erfaehrt vom Server, ob es
 * funktioniert, nicht erst vor der Klasse".
 *
 * Parst ueber die oeffentliche, reine Parse-API qformat_xml::readquestions()
 * (kein DB-Zugriff) - ein Parse-Fehler bricht den GESAMTEN Aufruf ab, kein
 * Teilergebnis. Schreibt gezielt ueber question_type::save_question() mit
 * gesetzter $question->id fuer einen wiedererkannten Bank-Eintrag ⇒ neue
 * Version unter bestehender questionbankentryid (ADR 0001).
 * importprocess() wird NICHT verwendet - legt bei jedem Aufruf einen neuen
 * question_bank_entries-Datensatz an und kennt kein Matching gegen
 * bestehende Eintraege.
 *
 * Round-Trip-Pruefung in derselben Transaktion: die frisch geschriebene
 * Frage wird ueber denselben Formatter wieder als XML exportiert
 * (qformat_xml::writequestion()) und erneut ueber readquestions()
 * zurueckgeparst - dieselbe Importer-Funktion produziert damit auf beiden
 * Seiten dieselbe Objektform, ein generischer Feldvergleich wird moeglich,
 * ohne fuer jeden Fragetyp eigenen Abgleichscode zu schreiben. Verglichen
 * werden die Kernfelder (name, idnumber, Fragetext, Antwortoptionen mit
 * Bruchteilen, Feedback je Option, allgemeines Feedback) - keine
 * Byte-Gleichheit, Moodle normalisiert IDs, Reihenfolgen und Dateipfade.
 * Jede Abweichung oder Ausnahme wirft und rollt damit die gesamte
 * Transaktion zurueck (Moodle rollt eine delegierte Transaktion automatisch
 * zurueck, wenn sie ohne allow_commit() verlassen wird, Muster aus
 * update_question_category.php) - nach dem Aufruf existiert weder
 * Bank-Eintrag noch Version.
 *
 * Wiedererkennung ausschliesslich innerhalb der Zielkategorie ueber
 * idnumber (ADR 0015). Bringt das XML eine idnumber mit, die in der
 * Zielkategorie keinen Treffer hat, ist das ein Verdachtsfall - das
 * gemeinsame Gate-Antwortformat aus move_question.php/T3
 * ({@see \local_coursepilot\question_suspect_gate}) wird uebernommen, auch
 * wenn die Kollisionsrichtung hier umgekehrt ist (dort: idnumber bereits
 * vergeben; hier: idnumber ohne Treffer). Nichts wird geschrieben, bis ein
 * erneuter Aufruf mit "confirmed": true das bestaetigt. Fehlt die
 * idnumber ganz, ist das ein echter Erstimport - eine neue wird generiert,
 * kein Gate.
 *
 * Zwei Tueren fuer eingebettete Dateien (Spec 0018 §7.1, Ticket #436) - die
 * Abweisung eingebetteter <file>-Bloecke aus Spec 0017 §6 ist damit
 * entfallen:
 * - Textuer (Parameter xmlcontent): die KI schreibt die XML selbst; ein
 *   <file>-Block traegt statt echtem Base64 ein material="<materialordner-pfad>"-
 *   Attribut, {@see self::resolve_material_file_references()} loest es
 *   serverseitig zu echtem Base64 auf, BEVOR geparst wird.
 * - Verweistuer (Parameter xmlpath): Verweis auf eine XML-Datei im
 *   Materialordner (Massenimport eines fremden Exports mit echtem Base64) -
 *   {@see self::read_material_binary()} liest sie serverseitig, kein Byte
 *   passiert den KI-Kontext. Genau eine der beiden Tueren je Aufruf.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class import_questions_xml extends external_api {

    /**
     * @var int Groessengrenze je Import (#424 Nachlauf 2) - reines Text-XML,
     *      eingebettete Dateien sind gesperrt. Siehe
     *      {@see self::guard_server_size_limit()} fuer die Begruendung.
     */
    public const MAX_XML_BYTES = 5 * 1024 * 1024;

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'ID of the target question bank category'),
            'xmlcontent' => new external_value(
                PARAM_RAW,
                'Moodle question XML export as text (text door) - <file> blocks carry a '
                    . 'material="<material-folder-path>" attribute instead of real base64, the server resolves it '
                    . 'server-side. Give exactly one of xmlcontent/xmlpath.',
                VALUE_DEFAULT,
                ''
            ),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'true explicitly confirms a previously reported suspect case (an idnumber that came with no match '
                    . 'in the target category) and creates the question anyway as a new entry. Omit or false on '
                    . 'the first call.',
                VALUE_DEFAULT,
                false
            ),
            'xmlpath' => new external_value(
                PARAM_PATH,
                'Reference to an XML file in the material folder (reference door, bulk import of a foreign export '
                    . 'with real base64 in <file> blocks) - e.g. "export.xml". Give exactly one of xmlcontent/xmlpath.',
                VALUE_DEFAULT,
                ''
            ),
            'location' => material_files::ort_parameter(),
        ]);
    }

    /**
     * @param int $categoryid
     * @param string $xmlcontent
     * @param bool $confirmed
     * @param string $xmlpath
     * @param string $location
     * @return array
     */
    public static function execute(
        int $categoryid,
        string $xmlcontent = '',
        bool $confirmed = false,
        string $xmlpath = '',
        string $location = material_files::ORT_BESTAND
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'xmlcontent' => $xmlcontent,
            'confirmed' => $confirmed,
            'xmlpath' => $xmlpath,
            'location' => $location,
        ]);

        [$category, $context, $questions] = self::resolve_and_parse($params);

        return ['questions' => self::import_all($category, $context, $questions, $params['confirmed'])];
    }

    /**
     * Prueft Kontext/Capabilities, loest das XML auf und parst die Fragen
     * (Issue #523: aus execute() ausgelagert, um die Funktion unter der
     * 50-Zeilen-Grenze zu halten).
     *
     * @param array $params Validierte Parameter von execute().
     * @return array{0: \stdClass, 1: \context, 2: array}
     */
    private static function resolve_and_parse(array $params): array {
        global $DB;

        $category = $DB->get_record('question_categories', ['id' => $params['categoryid']], '*', MUST_EXIST);
        $context = context::instance_by_id((int) $category->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/question:add', $context);

        $xml = self::resolve_door($params['xmlcontent'], $params['xmlpath'], $params['location']);

        // Groessenschranke (Spec 0017 "Bilder und Groessen", Ticket #416) -
        // VOR dem Parsen/Schreiben. Eingebettete Dateien sind seit Spec 0018
        // §7.1 nicht mehr gesperrt (beide Tueren oben aufgeloest).
        self::guard_server_size_limit($xml);
        self::guard_quiz_root($xml);

        // Reines Parsen, kein DB-Zugriff: ein ungueltiges XML wirft hier,
        // BEVOR irgendetwas geschrieben wird - kein Teilergebnis moeglich.
        $questions = self::parse($category, $context, $xml);

        return [$category, $context, $questions];
    }

    /**
     * Importiert alle geparsten Fragen in einer Transaktion (Issue #523:
     * aus execute() ausgelagert).
     *
     * @param \stdClass $category
     * @param \context $context
     * @param array $questions
     * @param bool $confirmed
     * @return array
     */
    private static function import_all(\stdClass $category, \context $context, array $questions, bool $confirmed): array {
        global $DB;

        // moodle_transaction hat keinen Destruktor - anders als in
        // manchen anderen Endpunkten muss hier explizit zurueckgerollt
        // werden, weil Fehler (Round-Trip-Abweichung) bewusst ERST NACH dem
        // Schreiben auftreten, nicht schon in der Validierung davor.
        $transaction = $DB->start_delegated_transaction();

        try {
            $results = [];
            foreach ($questions as $question) {
                $results[] = self::import_one($category, $context, $question, $confirmed);
            }
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        $transaction->allow_commit();

        return $results;
    }

    /**
     * Weist eine XML ab, die die Groessengrenze dieses Endpunkts
     * ueberschreitet.
     *
     * Die urspruengliche Begruendung (Ticket #416) war die
     * Serverkonfiguration: PHP scheitert beim Ueberschreiten von
     * post_max_size nicht sauber, sondern liefert eine Anfrage mit leeren
     * Feldern. Diese Begruendung traegt hier nicht - waere post_max_size
     * ueberschritten, laege der Inhalt gar nicht erst vor, und die Pruefung
     * wuerde nie erreicht. Gegen get_max_upload_file_size() (200 MB neben
     * post_max_size 206 MB) feuerte sie deshalb praktisch nie (#424
     * Nachlauf 2).
     *
     * Die Grenze ist eine bewusst gesetzte Fachgrenze auf das AUFGELOESTE
     * XML (nach Materialordner-Aufloesung beider Tueren, Spec 0018 §7.1) -
     * jede Frage darin durchlaeuft einen eigenen Round-Trip, jenseits
     * weniger MB laeuft der Aufruf in die Ausfuehrungszeit, nicht in die
     * Uploadgrenze. Die Serverkonfiguration bleibt als zusaetzliche
     * Obergrenze stehen, falls sie ausnahmsweise kleiner ist.
     *
     * @param string $xmlcontent
     * @return void
     */
    private static function guard_server_size_limit(string $xmlcontent): void {
        // get_max_upload_file_size() (Moodle-Bordmittel, lib/moodlelib.php)
        // liest post_max_size und upload_max_filesize aus der PHP-Konfiguration
        // und liefert das kleinere der beiden in Bytes.
        $serverlimit = get_max_upload_file_size();
        $limit = $serverlimit > 0 ? min(self::MAX_XML_BYTES, $serverlimit) : self::MAX_XML_BYTES;

        self::guard_size_against_limit(strlen($xmlcontent), $limit);
    }

    /**
     * Testbarer Kern von {@see self::guard_server_size_limit()}: $maxbytes
     * kommt vom Aufrufer, damit Tests die Schwelle setzen koennen, ohne eine
     * 5-MB-Zeichenkette aufbauen zu muessen.
     *
     * @param int $bytes
     * @param int $maxbytes
     * @return void
     */
    private static function guard_size_against_limit(int $bytes, int $maxbytes): void {
        if ($maxbytes <= 0 || $bytes <= $maxbytes) {
            return;
        }

        throw new \invalid_parameter_exception(
            'Die XML ist zu gross (' . display_size($bytes) . ', Grenze ' . display_size($maxbytes)
                . '). Bitte den Import auf mehrere kleinere Dateien aufteilen - z.B. eine Datei je '
                . 'Fragenkategorie.'
        );
    }

    /**
     * Waehlt die eine von zwei Tueren (Spec 0018 §7.1) und liefert das
     * fertig aufgeloeste XML - genau eine der beiden Angaben ist erlaubt,
     * keine stille Bevorzugung.
     *
     * @param string $xmlcontent Textuer-Angabe (leer, wenn nicht genutzt)
     * @param string $xmlpath Verweistuer-Angabe (leer, wenn nicht genutzt)
     * @param string $location {@see material_files::ORT_BESTAND}/{@see material_files::ORT_WERKBANK} -
     *        Quelle der Materialordner-Pfade in beiden Tueren (Issue #496).
     * @return string
     * @throws \invalid_parameter_exception weder oder beide Angaben gesetzt
     */
    private static function resolve_door(string $xmlcontent, string $xmlpath, string $location): string {
        $xmlcontent = trim($xmlcontent);
        $xmlpath = trim($xmlpath);

        if ($xmlcontent !== '' && $xmlpath !== '') {
            throw new \invalid_parameter_exception(
                'xmlcontent und xmlpath duerfen nicht gleichzeitig angegeben werden - genau eine Tuer waehlen: '
                    . 'XML als Text (xmlcontent) oder Verweis auf eine XML-Datei im Materialordner (xmlpath).'
            );
        }
        if ($xmlcontent === '' && $xmlpath === '') {
            throw new \invalid_parameter_exception(
                'Weder xmlcontent noch xmlpath angegeben - genau eine Tuer waehlen: XML als Text (xmlcontent) oder '
                    . 'Verweis auf eine XML-Datei im Materialordner (xmlpath).'
            );
        }

        if ($xmlpath !== '') {
            // Verweistuer: die XML-Datei liegt bereits im Materialordner
            // (z.B. ein fremder Moodle-Export) und traegt echtes Base64 in
            // ihren <file>-Bloecken - rein serverseitig gelesen, kein Byte
            // passiert den KI-Kontext.
            return self::read_material_binary($xmlpath, $location);
        }

        // Textuer: die KI hat die XML selbst geschrieben. <file>-Bloecke
        // tragen statt echtem Base64 einen Materialordner-Verweis.
        return self::resolve_material_file_references($xmlcontent, $location);
    }

    /**
     * Loest jeden <file>-Block mit einem material="<materialordner-pfad>"-
     * Attribut serverseitig zu echtem Base64 auf (Textuer, Spec 0018 §7.1) -
     * die KI nennt nur den Namen, der Import-Endpunkt baut den Base64-Block.
     * <file>-Bloecke ohne dieses Attribut bleiben unangetastet.
     *
     * @param string $xmlcontent
     * @param string $location {@see material_files::ORT_BESTAND}/{@see material_files::ORT_WERKBANK} -
     *        Quelle der referenzierten Pfade (Issue #496).
     * @return string
     * @throws \moodle_exception materialfilenotfound, wenn ein Verweis ins Leere zeigt
     */
    private static function resolve_material_file_references(string $xmlcontent, string $location): string {
        $resolved = preg_replace_callback(
            '/<file\b([^>]*)>(.*?)<\/file>/s',
            static function (array $matches) use ($location): string {
                $attributes = $matches[1];
                if (!preg_match('/\bmaterial=(["\'])(.*?)\1/', $attributes, $materialmatch)) {
                    // Kein Materialordner-Verweis - unveraendert lassen
                    // (z.B. bereits echtes Base64 im Text).
                    return $matches[0];
                }

                $materialpath = html_entity_decode($materialmatch[2], ENT_QUOTES | ENT_XML1);
                $base64 = base64_encode(self::read_material_binary($materialpath, $location));

                $cleanattributes = trim(preg_replace(
                    ['/\bmaterial=(["\']).*?\1/', '/\bencoding=(["\']).*?\1/'],
                    '',
                    $attributes
                ));

                return '<file ' . $cleanattributes . ' encoding="base64">' . $base64 . '</file>';
            },
            $xmlcontent
        );

        return $resolved ?? $xmlcontent;
    }

    /**
     * Liest den vollstaendigen Binaerinhalt einer Materialordner-Datei der
     * angemeldeten Person - fuer beide Tueren genutzt (Verweistuer: die
     * XML-Datei selbst; Textuer: je referenzierte Einzeldatei).
     *
     * @param string $path Materialordner-Pfad, z.B. "export.xml" oder "diagramme/skizze.png".
     * @param string $location {@see material_files::ORT_BESTAND}/{@see material_files::ORT_WERKBANK} (Issue #496).
     * @return string
     * @throws \moodle_exception materialfilenotfound / invalidmaterialort / materialpathiskontext
     */
    private static function read_material_binary(string $path, string $location = material_files::ORT_BESTAND): string {
        $stored = material_files::read_content_for_ort($location, $path);
        if ($stored === null) {
            throw new \moodle_exception(
                'materialfilenotfound',
                'local_coursepilot',
                '',
                material_files::normalise_path($path)
            );
        }

        return $stored['content'];
    }

    /**
     * Weist eine XML ohne <quiz>-Wurzelelement mit genau dieser Ursache ab.
     *
     * qformat_xml::readquestions() greift ungeprueft auf $xml['quiz'] zu und
     * scheitert dann mit PHP-Innenleben ("Undefined array key \"quiz\"",
     * "Cannot access offset of type string on string") - fuer eine Lehrkraft
     * wertlos, und der Fall ist nicht exotisch: ein von Hand gekuerztes
     * Beispiel besteht typischerweise nur aus dem <question>-Block (#425 F2,
     * #424 Nachlauf 1).
     *
     * @param string $xmlcontent
     * @return void
     */
    private static function guard_quiz_root(string $xmlcontent): void {
        if (preg_match('/<quiz[\s>]/i', $xmlcontent)) {
            return;
        }

        throw new \invalid_parameter_exception(
            'Dem XML fehlt das umschliessende <quiz>-Element. Moodle-Fragen-XML besteht immer aus <quiz> mit einem '
                . 'oder mehreren <question>-Bloecken darin - ein einzelner <question>-Block laesst sich nicht '
                . 'importieren. Bitte den vollstaendigen Moodle-Export senden oder die Fragen in <quiz>...</quiz> '
                . 'einfassen.'
        );
    }

    /**
     * Uebersetzt eine beim Parsen gefangene Ausnahme in einen Text, der der
     * Lehrkraft etwas sagt (#424 Nachlauf 1).
     *
     * Eine moodle_exception ist bereits eine Aussage ueber die Datei (z.B.
     * der Formatfehler von xmlize) und wird durchgereicht. Alles andere ist
     * PHP-Innenleben aus dem XML-Kern - kein Leck, aber ohne jeden
     * Handlungswert; an seiner Stelle steht die haeufigste tatsaechliche
     * Ursache.
     *
     * @param \Throwable $e
     * @return string
     */
    private static function parse_failure_message(\Throwable $e): string {
        if ($e instanceof \moodle_exception) {
            return $e->getMessage();
        }

        return 'Ungueltiges Moodle-XML: Die Datei liess sich nicht als Moodle-Fragen-XML lesen. Haeufigste '
            . 'Ursachen: die Datei ist unvollstaendig oder abgeschnitten, ein Element ist nicht geschlossen, oder '
            . 'die Struktur weicht vom Moodle-Export ab. Bitte einen vollstaendigen, unveraenderten Export senden.';
    }

    /**
     * Parst das XML ausschliesslich lesend ueber qformat_xml::readquestions().
     * Wirft bei jedem Parse-Problem eine Exception - der gesamte Aufruf
     * bricht damit ab.
     *
     * @param \stdClass $category
     * @param \context $context
     * @param string $xmlcontent
     * @return \stdClass[]
     */
    private static function parse(\stdClass $category, \context $context, string $xmlcontent): array {
        $qformat = new qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);
        $qformat->setStoponerror(true);
        $qformat->setMatchgrades('grade');
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $xmlcontent));

        // qformat_xml::readquestions() wirft bei einem Parse-Fehler NICHT -
        // es echot eine Fehlermeldung (qformat_default::error()) und liefert
        // false zurueck. Die Ausgabe wird abgefangen (kein HTML-Leck in die
        // Webservice-Antwort) und stattdessen als Exception geworfen.
        ob_start();
        try {
            $questions = $qformat->readquestions($lines);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new \invalid_parameter_exception(self::parse_failure_message($e));
        }
        $errortext = trim(strip_tags((string) ob_get_clean()));

        if ($questions === false || !is_array($questions) || $qformat->importerrors > 0) {
            throw new \invalid_parameter_exception('Ungueltiges Moodle-XML' . ($errortext !== '' ? ': ' . $errortext : '.'));
        }

        // Kategorie-Direktiven ($CATEGORY:) sind keine Fragen; dieser Endpunkt
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
     * Wiedererkennung + Schreiben + Round-Trip-Pruefung einer einzelnen
     * geparsten Frage.
     *
     * @param \stdClass $category
     * @param \context $context
     * @param \stdClass $question
     * @param bool $confirmed
     * @return array
     */
    private static function import_one(
        \stdClass $category,
        \context $context,
        \stdClass $question,
        bool $confirmed
    ): array {
        global $DB;

        $name = (string) ($question->name ?? '');
        $xmlidnumber = trim((string) ($question->idnumber ?? ''));

        if ($xmlidnumber === '') {
            // Echter Erstimport: keine idnumber im XML, kein Gate.
            $idnumber = self::generate_idnumber();
            $saved = self::save($category, $context, $question, null, $idnumber);
            self::verify_roundtrip($category, $context, $question, $saved, $idnumber);
            return self::result($saved, 'erstimport', $name);
        }

        $entry = $DB->get_record('question_bank_entries', [
            'questioncategoryid' => $category->id,
            'idnumber' => $xmlidnumber,
        ]);

        if ($entry) {
            // Eindeutiger idnumber-Treffer: neue Version desselben Eintrags.
            $latest = question_suspect_gate::latest_version_question((int) $entry->id);
            $saved = self::save($category, $context, $question, (int) $latest->id, $xmlidnumber);
            self::verify_roundtrip($category, $context, $question, $saved, $xmlidnumber);
            return self::result($saved, 'reimport', $name);
        }

        if (!$confirmed) {
            return self::unmatched_idnumber_response($category, $question, $name, $xmlidnumber);
        }

        // Bestaetigter Verdachtsfall: neuer Eintrag mit der mitgebrachten idnumber.
        $saved = self::save($category, $context, $question, null, $xmlidnumber);
        self::verify_roundtrip($category, $context, $question, $saved, $xmlidnumber);
        return self::result($saved, 'erstimport', $name);
    }

    /**
     * Verdachtsfall-Antwort: mitgebrachte idnumber ohne Treffer in der
     * Zielkategorie - nichts wird geschrieben (ADR 0015, Spec 0017 §7.1).
     * Issue #523: aus import_one() ausgelagert, um die Funktion unter der
     * 50-Zeilen-Grenze zu halten.
     *
     * @param \stdClass $category
     * @param \stdClass $question
     * @param string $name
     * @param string $xmlidnumber
     * @return array
     */
    private static function unmatched_idnumber_response(
        \stdClass $category,
        \stdClass $question,
        string $name,
        string $xmlidnumber
    ): array {
        $candidates = question_suspect_gate::find_name_candidates((int) $category->id, $name);
        $newquestiontext = self::text_of($question->questiontext ?? '');

        return array_merge(
            [
                'name' => $name,
                'questionbankentryid' => 0,
                'version' => 0,
                'status' => 'verdachtsfall',
                'message' => 'Verdachtsfall: Die mitgebrachte idnumber "' . $xmlidnumber . '" hat keinen '
                    . 'Treffer in der Zielkategorie. Nichts wurde importiert. Zum Anlegen als neuer Eintrag '
                    . 'trotzdem erneut mit confirmed=true aufrufen.',
            ],
            [
                'idnumber' => $xmlidnumber,
                'categoryid' => (int) $category->id,
                'candidates' => $candidates,
                'questiontext_old' => '',
                'questiontext_new' => $newquestiontext,
            ]
        );
    }

    /**
     * Ruft question_type::save_question() auf - mit $question->id fuer eine
     * neue Version eines bestehenden Eintrags, ohne fuer einen neuen Eintrag.
     *
     * @param \stdClass $category
     * @param \context $context
     * @param \stdClass $question
     * @param int|null $oldquestionid
     * @param string $idnumber
     * @return \stdClass
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
        // questiontextitemid/generalfeedbackitemid: qformat_xml::readquestions()
        // legt eingebettete <file>-Bloecke bereits als Draft-Dateien an (siehe
        // question/format/xml/format.php import_files_as_draft()) und haengt
        // deren Itemid separat an, statt sie in questiontext/generalfeedback
        // selbst einzubetten - ohne die Itemid hier durchzureichen wuerden
        // save_question()->file_save_draft_area_files() nie aufgerufen und
        // Bilder aus BEIDEN Tueren (Spec 0018 §7.1) stumm verworfen (Ticket #437).
        $form->questiontext = self::as_text_array(
            $question->questiontext ?? '', $question->questiontextformat ?? FORMAT_HTML,
            $question->questiontextitemid ?? 0);
        $form->generalfeedback = self::as_text_array(
            $question->generalfeedback ?? '', $question->generalfeedbackformat ?? FORMAT_HTML,
            $question->generalfeedbackitemid ?? 0);
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
        try {
            return $qtype->save_question($towrite, $form);
        } catch (\Throwable $e) {
            throw new \invalid_parameter_exception(self::save_failure_message($question->qtype, $e));
        }
    }

    /**
     * Uebersetzt eine beim Speichern gefangene Ausnahme in einen Text, der
     * der Lehrkraft etwas sagt (#440).
     *
     * question_type::save_question() erwartet bei manchen Fragetypen nicht
     * die von qformat_xml::readquestions() gelieferte Rohform, sondern eine
     * typspezifisch aufbereitete Struktur (z.B. "calculated": $form->dataset
     * muss ein Array zusammengesetzter String-Schluessel sein, nicht die
     * geparsten dataset_definitions-Objekte). Dieser generische save()-Pfad
     * bereitet nicht fuer jeden Fragetyp eigens auf - schlaegt das fehl,
     * liefert PHP intern nur einen TypeError ohne fachlichen Hinweis.
     *
     * @param string $qtype
     * @param \Throwable $e
     * @return string
     */
    private static function save_failure_message(string $qtype, \Throwable $e): string {
        if ($e instanceof \moodle_exception) {
            return $e->getMessage();
        }

        return 'Fragetyp "' . $qtype . '" liess sich mit dieser XML-Struktur nicht speichern. Haeufigste Ursache: '
            . 'eine fragetyp-spezifische Struktur (z.B. Dataset-Definitionen bei "calculated") weicht von der '
            . 'internen Form ab, die dieser Fragetyp beim Speichern erwartet. Bitte die Fragetyp-Ablage pruefen '
            . 'oder die Struktur vereinfachen.';
    }

    /**
     * Round-Trip-Pruefung (Spec 0017 §7.1): exportiert die frisch
     * geschriebene Frage wieder ueber qformat_xml und parst sie erneut -
     * dieselbe Importer-Funktion liefert damit auf beiden Seiten dieselbe
     * Objektform. Wirft bei jeder Abweichung eine Exception, die die
     * umgebende Transaktion zurueckrollt.
     *
     * @param \stdClass $category
     * @param \context $context
     * @param \stdClass $original Vom Aufruf geparste Eingabe-Frage
     * @param \stdClass $saved Rueckgabe von question_type::save_question()
     * @param string $expectedidnumber Die diesem Schreibvorgang zugewiesene idnumber
     * @return void
     */
    private static function verify_roundtrip(
        \stdClass $category,
        \context $context,
        \stdClass $original,
        \stdClass $saved,
        string $expectedidnumber
    ): void {
        $wrapped = self::rewrite_saved_question($category, $context, $saved);
        $reparsedquestion = self::reparse($category, $context, $wrapped);

        $mismatch = self::find_mismatch($original, $reparsedquestion, $expectedidnumber);
        if ($mismatch !== null) {
            throw self::roundtrip_exception($mismatch);
        }
    }

    /**
     * Laedt die gespeicherte Frage neu und schreibt sie ueber qformat_xml
     * zurueck (Issue #523: aus verify_roundtrip() ausgelagert, um die
     * Funktion unter der 50-Zeilen-Grenze zu halten).
     *
     * @param \stdClass $category
     * @param \context $context
     * @param \stdClass $saved
     * @return string Das in ein <quiz>-Wurzelelement gewickelte XML.
     */
    private static function rewrite_saved_question(\stdClass $category, \context $context, \stdClass $saved): string {
        global $DB;

        $reloaded = $DB->get_record('question', ['id' => $saved->id], '*', MUST_EXIST);
        $reloaded->export_process = true;
        $reloaded->categoryobject = $category;

        $qtype = question_bank::get_qtype($reloaded->qtype);
        $qtype->get_question_options($reloaded);

        $reloaded->contextid = (int) $context->id;
        $entry = get_question_bank_entry((int) $reloaded->id);
        $reloaded->idnumber = $entry->idnumber;

        $qformat = new qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);

        $xml = $qformat->writequestion($reloaded);

        // writequestion() liefert nur den <question>-Block, readquestions()
        // erwartet aber ein <quiz>-Wurzelelement (xmlize-Struktur $xml['quiz']).
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n" . $xml . "\n</quiz>";
    }

    /**
     * Parst das zurueckgeschriebene XML erneut und liefert die eine
     * enthaltene Frage (Issue #523: aus verify_roundtrip() ausgelagert).
     *
     * @param \stdClass $category
     * @param \context $context
     * @param string $wrapped
     * @return \stdClass
     */
    private static function reparse(\stdClass $category, \context $context, string $wrapped): \stdClass {
        $reparser = new qformat_xml();
        $reparser->setCategory($category);
        $reparser->setContexts([$context]);
        $reparser->setStoponerror(true);
        $reparser->setMatchgrades('grade');
        $reparser->setCatfromfile(false);
        $reparser->setContextfromfile(false);

        ob_start();
        try {
            $reparsed = $reparser->readquestions(explode("\n", $wrapped));
        } catch (\Throwable $e) {
            ob_end_clean();
            throw self::roundtrip_exception('parse', $e->getMessage());
        }
        $errortext = trim(strip_tags((string) ob_get_clean()));

        if ($reparsed === false || !is_array($reparsed) || $reparser->importerrors > 0) {
            throw self::roundtrip_exception('parse', $errortext !== '' ? $errortext : 'Parse-Fehler');
        }
        $reparsedquestion = reset($reparsed);
        if (!$reparsedquestion) {
            throw self::roundtrip_exception('parse', 'keine Frage im zurueckgelesenen XML');
        }

        return $reparsedquestion;
    }

    /**
     * Baut die moodle_exception fuer eine fehlgeschlagene Round-Trip-Pruefung.
     *
     * @param string $field Name des abweichenden Feldes ("parse" bei einem Reparse-Fehler)
     * @param string $detail Zusatzinfo, leer wenn keine vorhanden
     * @return \moodle_exception
     */
    private static function roundtrip_exception(string $field, string $detail = ''): \moodle_exception {
        return new \moodle_exception(
            'roundtripmismatch',
            'local_coursepilot',
            '',
            (object) ['field' => $field, 'detail' => $detail !== '' ? ' (' . $detail . ')' : '']
        );
    }

    /**
     * Kernfeld-Vergleich zwischen der Eingabe-Frage und der zurueckgelesenen
     * Frage (Spec 0017 §7.1): name, idnumber, Fragetext, allgemeines
     * Feedback, Antwortoptionen mit Bruchteilen und Feedbacktexte je Option.
     * Keine Byte-Gleichheit - IDs, Reihenfolgen und Dateipfade werden von
     * Moodle normalisiert und sind hier bewusst aussen vor.
     *
     * @param \stdClass $expected
     * @param \stdClass $actual
     * @param string $expectedidnumber
     * @return string|null Name des abweichenden Feldes, oder null bei Uebereinstimmung
     */
    private static function find_mismatch(\stdClass $expected, \stdClass $actual, string $expectedidnumber): ?string {
        if (trim((string) ($expected->name ?? '')) !== trim((string) ($actual->name ?? ''))) {
            return 'name';
        }
        if ($expectedidnumber !== trim((string) ($actual->idnumber ?? ''))) {
            return 'idnumber';
        }
        if (self::text_of($expected->questiontext ?? '') !== self::text_of($actual->questiontext ?? '')) {
            return 'questiontext';
        }
        if (self::text_of($expected->generalfeedback ?? '') !== self::text_of($actual->generalfeedback ?? '')) {
            return 'generalfeedback';
        }

        $expectedanswers = self::extract_answer_list($expected);
        $actualanswers = self::extract_answer_list($actual);
        if (count($expectedanswers) !== count($actualanswers)) {
            return 'answers';
        }
        foreach ($expectedanswers as $i => $answer) {
            $other = $actualanswers[$i];
            if ($answer['text'] !== $other['text']
                || abs($answer['fraction'] - $other['fraction']) > 0.00001
                || $answer['feedback'] !== $other['feedback']
            ) {
                return 'answers';
            }
        }

        return null;
    }

    /**
     * Normalisiert die Antwortoptionen eines qformat_xml-Frageobjekts
     * (Text, Bruchteil, Feedback) unabhaengig vom konkreten Fragetyp -
     * deckt sowohl das parallele Array-Format (multichoice, shortanswer,
     * numerical, ...) als auch das truefalse-Sonderformat ab. Beide Seiten
     * der Round-Trip-Pruefung durchlaufen dieselbe qformat_xml-Importer-
     * Funktion, liefern also dieselbe Form.
     *
     * @param \stdClass $qo
     * @return array<int, array{text: string, fraction: float, feedback: string}>
     */
    private static function extract_answer_list(\stdClass $qo): array {
        if (isset($qo->answer) && is_array($qo->answer)) {
            $list = [];
            foreach ($qo->answer as $i => $answer) {
                $list[] = [
                    'text' => self::text_of($answer),
                    'fraction' => round((float) ($qo->fraction[$i] ?? 0), 5),
                    'feedback' => self::text_of($qo->feedback[$i] ?? ''),
                ];
            }
            return $list;
        }

        if (isset($qo->answer) && is_bool($qo->answer)) {
            // truefalse: ein einzelnes Bool ("true" ist die richtige
            // Antwort"), Feedback getrennt je Option.
            return [
                [
                    'text' => 'true',
                    'fraction' => $qo->answer ? 1.0 : 0.0,
                    'feedback' => self::text_of($qo->feedbacktrue ?? ''),
                ],
                [
                    'text' => 'false',
                    'fraction' => $qo->answer ? 0.0 : 1.0,
                    'feedback' => self::text_of($qo->feedbackfalse ?? ''),
                ],
            ];
        }

        return [];
    }

    /**
     * @param \stdClass $saved
     * @param string $status
     * @param string $name
     * @return array
     */
    private static function result(\stdClass $saved, string $status, string $name): array {
        global $DB;

        $version = $DB->get_record('question_versions', ['questionid' => $saved->id], '*', MUST_EXIST);
        $message = $status === 'erstimport'
            ? 'Frage "' . $name . '" neu angelegt (Version ' . $version->version . ').'
            : 'Frage "' . $name . '" als neue Version (Version ' . $version->version . ') desselben Bank-Eintrags importiert.';

        return array_merge(
            [
                'name' => $name,
                'questionbankentryid' => (int) $version->questionbankentryid,
                'version' => (int) $version->version,
                'status' => $status,
                'message' => $message,
            ],
            question_suspect_gate::empty_result()
        );
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
    private static function as_text_array($value, $format, int $itemid = 0): array {
        if (is_array($value) && array_key_exists('text', $value)) {
            return [
                'text' => (string) $value['text'],
                'format' => $value['format'] ?? $format,
                'itemid' => $value['itemid'] ?? $itemid,
            ];
        }
        return ['text' => self::text_of($value), 'format' => $format ?? FORMAT_HTML, 'itemid' => $itemid];
    }

    /** Generiert eine neue, eindeutige idnumber (gleiches Schema wie mc_question_version). */
    private static function generate_idnumber(): string {
        return 'kp-' . bin2hex(random_bytes(8));
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => new external_multiple_structure(
                new external_single_structure(array_merge(
                    [
                        'name' => new external_value(PARAM_TEXT, 'Name der importierten Frage'),
                        'questionbankentryid' => new external_value(
                            PARAM_INT,
                            'ID des question_bank_entries (0 bei "verdachtsfall")'
                        ),
                        'version' => new external_value(
                            PARAM_INT,
                            'Neue Versionsnummer (0 bei "verdachtsfall")'
                        ),
                        'status' => new external_value(PARAM_ALPHA, '"erstimport" (first import) | "reimport" (new version of the same entry) | "verdachtsfall" (suspect case)'),
                        'message' => new external_value(PARAM_RAW, 'Teacher-facing German message'),
                    ],
                    question_suspect_gate::response_fields()
                )),
                'Ein Ergebnis-Eintrag je importierter Frage im XML'
            ),
        ]);
    }
}
