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

use coding_exception;
use mod_quiz\question\display_options;
use mod_quiz\quiz_settings as native_quiz_settings;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Fachliche Bruecke fuer {@see \local_coursepilot\external\create_quiz} und
 * {@see \local_coursepilot\external\update_quiz_settings} (Spec 0015 §5,
 * Ticket #398) - kein eigener module_catalog, sondern die Uebersetzung
 * zwischen dem Katalog-Vokabular von {@see quiz} und den Formularweg-
 * Eigenschaften, die quiz_process_options() (mod/quiz/lib.php) unbedingt
 * liest. Portiert aus der fachlichen Logik von
 * local_coursepilot\external\create_quiz/quiz_settings (Grade-Handling,
 * Feedback-Handling, Modus-Presets), aber auf die neue Bauform umgestellt:
 * Katalog-Validierung statt Sentinel-Parameter, update_moduleinfo() statt
 * direkter DB-Schreibung.
 *
 * Drei Uebersetzungen, die der Formularweg braucht, aber der Katalog bewusst
 * einfacher fuehrt (siehe quiz-Klassendoku):
 * - "password" <-> "quizpassword": identisch zum Formularfeld, quiz_process_options()
 *   spiegelt es selbst - hier nur das Carry-forward, falls der Patch es nicht nennt.
 * - Die acht review*-Bitmasken <-> 32 Einzel-Checkboxen "<art><zeitpunkt>":
 *   quiz_process_options() berechnet die Bitmasken IMMER aus den 32 Checkboxen neu -
 *   ohne Carry-forward wuerde ein Patch, der keine einzige review*-Checkbox nennt,
 *   alle Review-Einstellungen auf 0 zuruecksetzen.
 * - Gesamtfeedback: Katalog fuehrt "feedbacktext" als einfaches String-Array
 *   (kein Feld je Grenzstufe), der Formularweg braucht
 *   [['text'=>..,'format'=>..,'itemid'=>..], ...] plus "feedbackboundaries".
 *   Ohne "feedbacktext" im Patch loescht Moodle bestehendes Feedback still
 *   (quiz_after_add_or_update() loescht immer erst, siehe Klassendoku quiz.php) -
 *   deshalb immer Carry-forward des Ist-Stands, wenn der Patch es nicht nennt.
 *
 * "grade" und "sumgrades" laufen bewusst NICHT durch diese Bruecke: beide
 * sind auf der Katalog-Sperrliste (quiz::blocklist()) und werden von den
 * Endpunkten direkt bzw. ueber {@see self::apply_grade_change()} (Moodles
 * eigener Grade-Calculator) gesetzt - nie ueber ein Katalogfeld.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class quiz_write_bridge {

    /** @var string[] Die acht Review-Arten, siehe {@see quiz::REVIEW_TYPES}. */
    private const REVIEW_TYPES = [
        'attempt', 'correctness', 'maxmarks', 'marks',
        'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback',
    ];

    /**
     * Zeitpunkt-Suffix => Bitmaske, direkt aus Moodles eigener Konstantenklasse
     * (kein eigenes Bitmasken-Vokabular, anders als der abgeloeste
     * local_coursepilot-Weg).
     *
     * @var array<string, int>
     */
    private static function review_timings(): array {
        return [
            'during' => display_options::DURING,
            'immediately' => display_options::IMMEDIATELY_AFTER,
            'open' => display_options::LATER_WHILE_OPEN,
            'closed' => display_options::AFTER_CLOSE,
        ];
    }

    /**
     * Alle 32 Pseudofeld-Namen "<art><zeitpunkt>" (Katalog-Vokabular).
     *
     * @return string[]
     */
    public static function review_field_names(): array {
        $names = [];
        foreach (self::REVIEW_TYPES as $type) {
            foreach (array_keys(self::review_timings()) as $timing) {
                $names[] = $type . $timing;
            }
        }
        return $names;
    }

    /**
     * Zerlegt die acht review*-Bitmasken-Spalten einer Quiz-Zeile in die 32
     * Katalog-Pseudofelder - fuer das Carry-forward, wenn ein Patch keine
     * dieser Checkboxen nennt.
     *
     * @param \stdClass $quiz Rohe quiz-Tabellenzeile.
     * @return array<string, int>
     */
    public static function decompose_review_bitmasks(\stdClass $quiz): array {
        $result = [];
        foreach (self::REVIEW_TYPES as $type) {
            $mask = (int) ($quiz->{'review' . $type} ?? 0);
            foreach (self::review_timings() as $timing => $bit) {
                $result[$type . $timing] = ($mask & $bit) ? 1 : 0;
            }
        }
        return $result;
    }

    /**
     * Liest das aktuelle Gesamtfeedback als Katalog-Form (einfaches
     * String-Array plus Grenzen) - Grundlage sowohl fuer das Carry-forward
     * als auch fuer Vorher-/Nachher-Vergleiche in der Aenderungsmeldung.
     *
     * @param int $quizid
     * @return array{feedbacktext: string[], feedbackboundaries: float[]}
     */
    public static function read_feedback(int $quizid): array {
        global $DB;

        $records = array_values($DB->get_records('quiz_feedback', ['quizid' => $quizid], 'mingrade DESC, id ASC'));
        $texts = [];
        $boundaries = [];
        foreach ($records as $index => $record) {
            $texts[] = (string) $record->feedbacktext;
            if ($index < count($records) - 1) {
                $boundaries[] = (float) $record->mingrade;
            }
        }
        return ['feedbacktext' => $texts, 'feedbackboundaries' => $boundaries];
    }

    /**
     * Uebersetzt das Katalog-Gesamtfeedback (einfaches String-Array) auf die
     * Formularweg-Eigenschaften, die quiz_process_options() erwartet.
     * Mindestens ein Text ist Pflicht (siehe {@see self::validate_combination_rules()}) -
     * ein leeres Array wuerde quiz_after_add_or_update() mit einem
     * undefinierten Index zum Absturz bringen.
     *
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param string[] $texts
     * @param array<int, int|float|string> $boundaries
     * @return void
     */
    public static function apply_feedback_pseudofields(\stdClass $moduleinfo, array $texts, array $boundaries): void {
        $moduleinfo->feedbacktext = [];
        $moduleinfo->feedbackboundaries = [];
        foreach (array_values($texts) as $index => $text) {
            $moduleinfo->feedbacktext[$index] = ['text' => (string) $text, 'format' => FORMAT_HTML, 'itemid' => 0];
        }
        foreach (array_values($boundaries) as $index => $boundary) {
            $moduleinfo->feedbackboundaries[$index] = $boundary;
        }
    }

    /**
     * Formular-Default fuer "grade" beim Anlegen (mod/quiz/mod_form.php:388,
     * $mform->setDefault('grade', $quizconfig->maximumgrade)) - "grade"
     * selbst ist auf der Katalog-Sperrliste und hat deshalb keinen
     * Katalog-Default.
     *
     * @return float
     */
    public static function default_grade(): float {
        $configured = (float) get_config('quiz', 'maximumgrade');
        return $configured > 0 ? $configured : 10.0;
    }

    /**
     * Aendert die maximale Bewertung ueber Moodles eigenen Grade-Calculator
     * (mod/quiz/classes/grade_calculator.php: update_quiz_maximum_grade(),
     * Ersatz fuer das deprecated quiz_set_grade()) - skaliert bestehende
     * Versuchsnoten und Gesamtfeedback-Grenzen automatisch um, aktualisiert
     * den Grade-Item und das Gradebook. Keine direkte DB-Schreibung auf der
     * quiz-Tabelle (ADR 0016, Spec 0015 §5: "grade laeuft ueber die
     * Moodle-eigenen Quiz-Wege").
     *
     * @param int $quizid
     * @param float $newgrade
     * @return void
     */
    public static function apply_grade_change(int $quizid, float $newgrade): void {
        native_quiz_settings::create($quizid)->get_grade_calculator()->update_quiz_maximum_grade($newgrade);
    }

    /**
     * Katalogfeldname => tatsaechlicher $moduleinfo-Eigenschaftsname -
     * identische Ausnahme wie {@see \local_coursepilot\external\update_module_settings::moduleinfo_property()}.
     *
     * @param string $fieldname
     * @return string
     */
    public static function moduleinfo_property(string $fieldname): string {
        return $fieldname === 'idnumber' ? 'cmidnumber' : $fieldname;
    }

    /**
     * Alles-oder-nichts-Feldpruefung (Spec 0015 §3.6): unbekanntes Feld,
     * gesperrtes Feld ("grade"/"sumgrades" ueber quiz::blocklist()),
     * unerlaubter Wert.
     *
     * @param array $merged
     * @return void
     * @throws moodle_exception blockedfield|unknownfield|invalidfieldvalue
     */
    public static function validate_fields(array $merged): void {
        $blocklist = array_unique(array_merge(shared_block::BLOCKLIST, quiz::blocklist()));
        // shared_block::pseudofields() (coursepagevisibility/availability_status) bleiben aussen vor -
        // reine Lese-Vokabel, kein echtes $moduleinfo-Feld (identisch zu update_module_settings::validate_patch()).
        $settable = array_merge(shared_block::fields(), quiz::fields(), quiz::pseudofields());
        $byname = [];
        foreach ($settable as $field) {
            $byname[$field->name] = $field;
        }

        foreach ($merged as $fieldname => $value) {
            if (!is_string($fieldname)) {
                throw new coding_exception('felder_json muss ein JSON-Objekt sein, kein Array.');
            }
            if (in_array($fieldname, $blocklist, true)) {
                throw new moodle_exception('blockedfield', 'local_coursepilot', '', ['field' => $fieldname, 'modname' => 'quiz']);
            }
            shared_block::assert_not_read_only_vocabulary($fieldname, 'quiz');
            if (!array_key_exists($fieldname, $byname)) {
                throw new moodle_exception('unknownfield', 'local_coursepilot', '', ['field' => $fieldname, 'modname' => 'quiz']);
            }
            $field = $byname[$fieldname];
            if ($field->values !== null && !in_array($value, $field->values, false)) {
                throw new moodle_exception(
                    'invalidfieldvalue',
                    'local_coursepilot',
                    '',
                    ['field' => $fieldname, 'modname' => 'quiz', 'value' => json_encode($value)]
                );
            }
        }
    }

    /**
     * Stealth-Regel, identisch zu
     * {@see \local_coursepilot\external\update_module_settings::assert_stealth_allowed()}.
     *
     * @param array $merged
     * @return void
     * @throws moodle_exception stealthnotallowed
     */
    public static function assert_stealth_allowed(array $merged): void {
        if (($merged['visibleoncoursepage'] ?? null) !== 0) {
            return;
        }
        if (get_config(null, 'allowstealth')) {
            return;
        }
        throw new moodle_exception('stealthnotallowed', 'local_coursepilot');
    }

    /**
     * Kombinationsregeln (Spec 0015 §2.2 Kategorie 4, quiz::combination_rules()):
     * nur die pruefbaren, alle VOR dem Schreiben (Spec 0015 §3.6).
     *
     * @param array $effective Alle wirksamen Werte fuer die Datumsregeln (Vorher-Stand ueberlagert mit
     *        Patch/Buendel, oder Katalog-Defaults ueberlagert mit Patch/Buendel beim Anlegen) - ein
     *        unveraendert bleibender Altwert darf eine Regel nicht neu ausloesen (siehe Aufrufer).
     * @param array $patch Nur die vom Patch/Buendel selbst gesetzten Felder - massgeblich fuer die
     *        Gesamtfeedback-Regel: ein Patch ohne "feedbacktext" hat nichts zu pruefen, das
     *        Carry-forward des Ist-Stands ist per Definition bereits gueltig.
     * @param float $grade Die fuer Gesamtfeedback-Grenzen wirksame maximale Bewertung -
     *        der NEUE Wert, falls "grade" im selben Aufruf mitgeaendert wird, sonst der aktuelle.
     * @return void
     * @throws moodle_exception combinationruleviolation
     */
    public static function validate_combination_rules(array $effective, array $patch, float $grade): void {
        $timeopen = (int) ($effective['timeopen'] ?? 0);
        $timeclose = (int) ($effective['timeclose'] ?? 0);
        if ($timeopen > 0 && $timeclose > 0 && $timeclose < $timeopen) {
            self::throw_combination_violation('"timeclose" darf nicht vor "timeopen" liegen.');
        }

        if (($effective['overduehandling'] ?? '') === 'graceperiod') {
            $min = (int) get_config('quiz', 'graceperiodmin');
            $grace = (int) ($effective['graceperiod'] ?? 0);
            if ($grace <= $min) {
                self::throw_combination_violation(
                    '"graceperiod" muss groesser sein als die serverweite Mindestdauer ('
                        . $min . ' Sekunden), wenn "overduehandling"="graceperiod" ist.'
                );
            }
        }

        if (!array_key_exists('feedbacktext', $patch)) {
            return;
        }
        $texts = $patch['feedbacktext'];
        if (!is_array($texts) || !$texts) {
            self::throw_combination_violation('"feedbacktext" muss mindestens einen Eintrag haben.');
        }
        $boundaries = $patch['feedbackboundaries'] ?? [];
        if (!is_array($boundaries) || count($boundaries) !== count($texts) - 1) {
            self::throw_combination_violation(
                '"feedbackboundaries" muss genau einen Eintrag weniger haben als "feedbacktext" ('
                    . count($texts) . ' Text(e), ' . count($boundaries) . ' Grenze(n)).'
            );
        }

        $previous = null;
        foreach ($boundaries as $boundary) {
            $numeric = self::resolve_boundary($boundary, $grade);
            if ($numeric <= 0 || $numeric >= $grade) {
                self::throw_combination_violation(
                    '"feedbackboundaries" muessen zwischen 0 und der maximalen Bewertung (' . $grade . ') liegen.'
                );
            }
            if ($previous !== null && $numeric >= $previous) {
                self::throw_combination_violation('"feedbackboundaries" muessen absteigend sortiert sein.');
            }
            $previous = $numeric;
        }
    }

    /**
     * @param string $message
     * @return never
     * @throws moodle_exception combinationruleviolation
     */
    private static function throw_combination_violation(string $message): void {
        throw new moodle_exception('combinationruleviolation', 'local_coursepilot', '', [
            'modname' => 'quiz',
            'message' => $message,
        ]);
    }

    /**
     * Loest eine Grenze (absolut oder Prozentsatz, z.B. "50%") gegen die
     * wirksame maximale Bewertung auf - identische Rechnung wie
     * quiz_process_options() (mod/quiz/lib.php:1005).
     *
     * @param int|float|string $boundary
     * @param float $grade
     * @return float
     */
    private static function resolve_boundary($boundary, float $grade): float {
        $value = trim((string) $boundary);
        if ($value !== '' && $value[strlen($value) - 1] === '%') {
            return ((float) trim(substr($value, 0, -1))) * $grade / 100.0;
        }
        return (float) $value;
    }
}
