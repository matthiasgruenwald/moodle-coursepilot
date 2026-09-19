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
use local_coursepilot\material_files;
use qformat_xml;
use question_bank;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Export-Gegenpart zum XML-Kern (Spec 0017 §7.1, Ticket #417; volle Datei
 * Spec 0018 §7.2, Ticket #437): liest eine oder mehrere Fragen als
 * Moodle-XML - derselbe Formatter (qformat_xml), den die Round-Trip-Pruefung
 * in {@see import_questions_xml} ohnehin serverseitig verwendet, hier als
 * eigenstaendiges Werkzeug herausgefuehrt.
 *
 * Standard-Modus (platzhalter=false, Default): die VOLLSTAENDIGE,
 * standardkonforme XML - mit echtem Base64 in den <file>-Bloecken - wird als
 * Datei in den Materialordner geschrieben (Spec 0018 §2); die Antwort nennt
 * nur den Pfad, kein Bildbyte passiert den KI-Kontext. Diese Datei ist in
 * jedes andere Moodle importierbar (Weitergabe) und ueber die Verweistuer von
 * {@see import_questions_xml} wieder einlesbar (Rundlauf).
 *
 * Platzhalter-Modus (platzhalter=true): der urspruengliche Export aus Spec
 * 0017 §4.2 - <file>-Bloecke werden durch einen benannten XML-Kommentar-
 * Platzhalter ersetzt und die XML kommt direkt in der Antwort zurueck. Fuer
 * den Vorlagenzweck (die KI soll die Struktur lernen, nicht 400 KB Bild) ist
 * das weiterhin die richtige Form - NICHT zur Weitergabe geeignet, die
 * Meldung sagt das ausdruecklich. Der Platzhalter beginnt bewusst NICHT mit
 * "<file", damit ein re-importierter Export nicht faelschlich als
 * eingebettete Datei behandelt wird.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class export_questions_xml extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'questionid einer beliebigen Version der zu exportierenden Frage'),
                'Liste von questionids (mindestens eine)'
            ),
            'targetpath' => new external_value(
                PARAM_PATH,
                'Materialordner-Pfad der zu schreibenden XML-Datei, z.B. "export.xml" - Pflicht im Standard-Modus '
                    . '(platzhalter=false), ignoriert im Platzhalter-Modus.',
                VALUE_DEFAULT,
                ''
            ),
            'platzhalter' => new external_value(
                PARAM_BOOL,
                'Schalter (Spec 0018 §7.2), Default false: die vollstaendige, standardkonforme XML mit echtem '
                    . 'Base64 wird in den Materialordner geschrieben, die Antwort nennt nur den Pfad. true liefert '
                    . 'stattdessen wie bisher die XML direkt in der Antwort, mit benannten Platzhaltern statt '
                    . 'eingebetteter Dateien - nur fuer den Vorlagenzweck (Struktur lernen), NICHT zur Weitergabe '
                    . 'geeignet.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param int[] $questionids
     * @param string $targetpath
     * @param bool $platzhalter
     * @return array
     */
    public static function execute(array $questionids, string $targetpath = '', bool $platzhalter = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'questionids' => $questionids,
            'targetpath' => $targetpath,
            'platzhalter' => $platzhalter,
        ]);
        $ids = $params['questionids'];

        if (empty($ids)) {
            throw new \invalid_parameter_exception('Es muss mindestens eine questionid angegeben werden.');
        }
        if (!$params['platzhalter'] && trim($params['targetpath']) === '') {
            throw new \invalid_parameter_exception(
                'targetpath ist im Standard-Modus Pflicht (Materialordner-Pfad der zu schreibenden XML-Datei), '
                    . 'z.B. "export.xml". Fuer den Platzhalter-Modus stattdessen platzhalter=true setzen.'
            );
        }

        $parts = [];
        $missing = [];
        foreach ($ids as $questionid) {
            [$xml, $name, $filenames] = self::export_one((int) $questionid, $params['platzhalter']);
            $parts[] = $xml;
            if ($params['platzhalter'] && !empty($filenames)) {
                $missing[] = ['name' => $name, 'files' => $filenames];
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n" . implode("\n", $parts) . "\n</quiz>\n";

        if ($params['platzhalter']) {
            return [
                'xml' => $xml,
                'pfad' => '',
                'anzahl' => count($ids),
                'meldung' => self::build_message(count($ids), $missing, true),
            ];
        }

        return self::write_and_report($params['targetpath'], $xml, count($ids));
    }

    /**
     * Schreibt das Export-XML in den Materialordner und baut die
     * Standard-Modus-Antwort (Issue #523: aus execute() ausgelagert, um die
     * Funktion unter der Zeilengrenze zu halten).
     *
     * @param string $targetpath
     * @param string $xml
     * @param int $count
     * @return array{xml: string, pfad: string, anzahl: int, meldung: string}
     */
    private static function write_and_report(string $targetpath, string $xml, int $count): array {
        [$path, $warning] = self::write_material_file($targetpath, $xml);
        $message = self::build_message($count, [], false) . ' Datei: ' . $path . '.';
        if ($warning !== null) {
            $message .= ' ' . $warning;
        }

        return [
            'xml' => '',
            'pfad' => $path,
            'anzahl' => $count,
            'meldung' => $message,
        ];
    }

    /**
     * Loest eine questionid (beliebige Version) auf die neueste Version
     * ihres Bank-Eintrags auf, prueft die native Lese-Capability im
     * Kategoriekontext und exportiert die Frage ueber qformat_xml -
     * dasselbe Vorgehen wie import_questions_xml::verify_roundtrip(), hier
     * fuer eine bereits bestehende Frage statt einer frisch geschriebenen.
     *
     * @param int $questionid
     * @param bool $platzhalter true: eingebettete Dateien durch Platzhalter ersetzen (Spec 0017 §4.2)
     * @return array{0: string, 1: string, 2: string[]} [XML-Fragment, Fragename, entfernte Dateinamen (nur Platzhalter-Modus)]
     */
    private static function export_one(int $questionid, bool $platzhalter): array {
        [$question, $category, $context] = self::resolve_native_question($questionid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // moodle/question:view existiert nicht (mehr); Moodle kennt nur
        // viewmine/viewall (siehe get_question.php). viewall passt zur
        // Lese-Capability hier - Export ist ein Lesevorgang.
        require_capability('moodle/question:viewall', $context);

        [$xml, $filenames] = self::question_to_xml($question, $category, $context, $platzhalter);

        return [$xml, (string) $question->name, $filenames];
    }

    /**
     * Schreibt das vollstaendige Export-XML (echtes Base64, Standard-Modus)
     * in den Materialordner der aufrufenden Lehrkraft, ohne dessen
     * Endungs-Whitelist: ".xml" steht bewusst nicht auf der Upload-Whitelist
     * (Spec 0018 §6, siehe {@see material_files::resolve_writable_file()}),
     * die hier ueber {@see material_files::resolve_file()} umgangen wird -
     * derselbe Weg, ueber den auch die Verweistuer von
     * {@see import_questions_xml} liest. Die eigentliche
     * Groessen-/Quote-/Schreib-Choreografie ist gemeinsamer Kern mit
     * {@see \local_coursepilot\external\upload_material_file}, siehe
     * {@see material_files::write()}.
     *
     * @param string $targetpath
     * @param string $content
     * @return array{0: string, 1: string|null} [Materialordner-Pfad, Quotenwarnung oder null]
     */
    private static function write_material_file(string $targetpath, string $content): array {
        $context = material_files::own_context();
        self::validate_context($context);
        material_files::require_manage_own_files();

        [$directory, $filename] = material_files::resolve_file($targetpath);

        $existing = material_files::read_content($directory, $filename);
        $oldsize = $existing !== null ? $existing['size'] : 0;

        $warning = material_files::write($directory, $filename, $content, $oldsize);

        return [material_files::relative_file($directory, $filename), $warning];
    }

    /**
     * Laedt die neueste Version einer Frage in genau der nativen Objektform,
     * die {@see \qformat_xml::writequestion()} erwartet (question-Zeile plus
     * qtype-Optionen ueber {@see \question_type::get_question_options()}) -
     * OHNE Capability-Pruefung, das bleibt Sache des Aufrufers (Export prueft
     * eine Lese-, {@see \local_coursepilot\external\update_mc_question} eine
     * Schreib-Capability).
     *
     * Wiederverwendet von update_mc_question (Ticket #419): dort wird NICHT
     * die XML gepatcht, sondern gezielt einzelne Properties auf diesem
     * nativen Objekt ueberschrieben (name/questiontext/generalfeedback/
     * defaultmark/options->single/options->answers) - alles andere (penalty,
     * shuffleanswers, answernumbering, Kombi-Feedback, Tags, Hints, ...)
     * bleibt dadurch automatisch unangetastet, weil es nie angefasst wird.
     *
     * @param int $questionid
     * @return array{0: \stdClass, 1: \stdClass, 2: \context} [natives Fragenobjekt, Kategorie, Kontext]
     */
    public static function resolve_native_question(int $questionid): array {
        global $DB;

        $version = $DB->get_record('question_versions', ['questionid' => $questionid]);
        if (!$version) {
            throw new \moodle_exception('notfound', 'error', '',
                null, 'Keine Frage mit questionid ' . $questionid . ' gefunden.');
        }

        $latest = $DB->get_record_sql(
            'SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC',
            [$version->questionbankentryid],
            IGNORE_MULTIPLE
        );

        $entry = $DB->get_record('question_bank_entries', ['id' => $latest->questionbankentryid], '*', MUST_EXIST);
        $category = $DB->get_record('question_categories', ['id' => $entry->questioncategoryid], '*', MUST_EXIST);
        $context = context::instance_by_id((int) $category->contextid);

        $question = $DB->get_record('question', ['id' => $latest->questionid], '*', MUST_EXIST);
        $question->export_process = true;
        $question->categoryobject = $category;

        $qtype = question_bank::get_qtype($question->qtype);
        $qtype->get_question_options($question);

        $question->contextid = (int) $context->id;
        $question->idnumber = (string) $entry->idnumber;

        return [$question, $category, $context];
    }

    /**
     * Schreibt ein natives Fragenobjekt (siehe {@see self::resolve_native_question()})
     * ueber qformat_xml als XML, fuer Wiederverwendung ausserhalb dieser
     * Klasse public.
     *
     * @param \stdClass $question
     * @param \stdClass $category
     * @param \context $context
     * @param bool $stripfiles true (Default, back-kompatibel fuer bestehende Aufrufer wie
     *        update_mc_question): eingebettete Dateien durch Platzhalter ersetzen (siehe Klassendoku).
     *        false: echtes Base64 unveraendert belassen (Standard-Modus des Exports, Spec 0018 §7.2).
     * @return array{0: string, 1: string[]} [XML-Fragment, entfernte Dateinamen (leer, wenn nicht gestrippt)]
     */
    public static function question_to_xml(
        \stdClass $question,
        \stdClass $category,
        \context $context,
        bool $stripfiles = true
    ): array {
        $qformat = new qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);

        $xml = $qformat->writequestion($question);
        if (!$stripfiles) {
            return [$xml, []];
        }
        return self::strip_embedded_files($xml);
    }

    /**
     * Ersetzt jeden <file>-Block (Base64-Dateiinhalt) durch einen
     * XML-Kommentar-Platzhalter, der den urspruenglichen Dateinamen nennt.
     * Bewusst KEIN Strippen ohne Spur - die Lehrkraft/KI muss sehen, dass
     * und welche Datei fehlt (Spec 0017 "Bilder und Groessen").
     *
     * @param string $xml
     * @return array{0: string, 1: string[]} [bereinigtes XML, entfernte Dateinamen]
     */
    private static function strip_embedded_files(string $xml): array {
        $filenames = [];
        $cleaned = preg_replace_callback(
            '/<file\b[^>]*\bname="([^"]*)"[^>]*>.*?<\/file>\n?/s',
            static function (array $m) use (&$filenames): string {
                $filename = $m[1];
                $filenames[] = $filename;
                // "--" wuerde den XML-Kommentar vorzeitig beenden.
                $safe = str_replace('--', '- -', $filename);
                return '<!-- Datei entfernt (kein Binaertransport im Export): ' . $safe . " -->\n";
            },
            $xml
        );

        return [$cleaned ?? $xml, $filenames];
    }

    /**
     * Baut die Lehrkraft-deutsche Gesamtmeldung - sagt bei fehlenden
     * Dateien (Platzhalter-Modus) AUSDRUECKLICH, welche Frage(n) betroffen
     * sind und dass die Dateien nicht mitexportiert wurden, und nennt im
     * Platzhalter-Modus ausdruecklich, dass die Ausgabe unvollstaendig und
     * nicht zur Weitergabe geeignet ist (Spec 0018 §7.2, Ticket #437).
     *
     * @param int $count
     * @param array<int, array{name: string, files: string[]}> $missing
     * @param bool $platzhalter
     * @return string
     */
    private static function build_message(int $count, array $missing, bool $platzhalter): string {
        $base = $count === 1 ? '1 Frage exportiert.' : $count . ' Fragen exportiert.';

        if ($platzhalter) {
            $base .= ' PLATZHALTER-MODUS: Diese Ausgabe ist unvollstaendig (eingebettete Dateien sind durch '
                . 'Kommentar-Platzhalter ersetzt) und NICHT zur Weitergabe geeignet - nur fuer den Vorlagenzweck '
                . '(Struktur einer Frage lernen). Fuer eine vollstaendige, weitergebbare XML platzhalter=false '
                . '(Default) verwenden.';
        }

        if (empty($missing)) {
            return $base;
        }

        $details = [];
        foreach ($missing as $entry) {
            $details[] = 'Frage "' . $entry['name'] . '": ' . implode(', ', $entry['files']);
        }

        return $base . ' ACHTUNG: Eingebettete Dateien fehlen im Export und wurden durch Platzhalter ersetzt ('
            . implode('; ', $details) . ').';
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'xml' => new external_value(
                PARAM_RAW,
                'NUR im Platzhalter-Modus gefuellt: Moodle-XML-Fragenexport (ein <quiz>-Wurzelelement mit einer '
                    . '<question> je angeforderter Frage), eingebettete Dateien durch benannte Kommentar-Platzhalter '
                    . 'ersetzt. Im Standard-Modus leer - die vollstaendige XML liegt in "pfad".',
                VALUE_DEFAULT,
                ''
            ),
            'pfad' => new external_value(
                PARAM_TEXT,
                'NUR im Standard-Modus gefuellt: Materialordner-Pfad der geschriebenen, vollstaendigen XML-Datei '
                    . '(echtes Base64 in <file>-Bloecken). Im Platzhalter-Modus leer.',
                VALUE_DEFAULT,
                ''
            ),
            'anzahl' => new external_value(PARAM_INT, 'Anzahl exportierter Fragen'),
            'meldung' => new external_value(
                PARAM_RAW,
                'Lehrkraft-deutsche Meldung; nennt im Platzhalter-Modus ausdruecklich, dass die Ausgabe '
                    . 'unvollstaendig und nicht zur Weitergabe geeignet ist, sowie bei fehlenden Dateien welche Frage '
                    . 'betroffen ist'
            ),
        ]);
    }
}
