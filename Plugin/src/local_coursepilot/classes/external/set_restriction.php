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

use coding_exception;
use context_module;
use core_availability\tree;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\pseudofield_carry_forward;
use local_coursepilot\catalog\registry;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Der einzige Schreibweg fuer Voraussetzungen (Spec 0015, Ticket #393): baut
 * das native "availability"-JSON aus lehrkraftverstaendlichen Argumenten
 * statt es roh entgegenzunehmen - rohes JSON ist kein Vertrag, den eine KI
 * zuverlaessig trifft, und ein kaputter Wert macht ueber den direkten
 * DB-Weg die Kursseite unaufrufbar (availability/classes/info.php baut aus
 * dem gespeicherten JSON einen Baum auf und wirft bei ungueltiger Struktur
 * eine coding_exception).
 *
 * Ueber diesen Endpunkt entsteht das JSON ausschliesslich aus den nativen
 * "get_json()"-Fabriken der drei praktisch relevanten Bedingungstypen
 * (availability_completion, availability_date, availability_group - Spec
 * 0015, bewusste Ponytail-Beschraenkung statt vollstaendigem Nachbau der
 * Availability-API) und wird VOR dem Schreiben mit core_availability\tree
 * geprueft - eine ungueltige Bedingung scheitert dadurch schon hier, nie
 * erst beim naechsten Seitenaufruf.
 *
 * "availability"/"availabilityconditionsjson" bleibt auf der Sperrliste von
 * update_module_settings (shared_block::BLOCKLIST fuehrt es gar nicht erst
 * als Feld) - dieser Endpunkt ist der einzige Schreibweg.
 *
 * Geschrieben wird ueber get_moduleinfo_data()/update_moduleinfo() (ADR
 * 0016), nie direkt in course_modules.availability - der Aenderungsverlauf
 * (#385-387) beobachtet course_module_updated automatisch.
 *
 * "profile"-Bedingungen werden von diesem Endpunkt nicht angeboten (sie
 * bleiben ohnehin nur ueber den nativen Formularweg oder
 * update_module_settings/direkte Bearbeitung setzbar, nicht ueber
 * Coursepilot) - der Lese-Weg (get_module_settings) maskiert sie unveraendert
 * weiter (ADR 0011).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class set_restriction extends external_api {

    /**
     * Lehrkraft-deutsche Statuswoerter fuer "abschluss" -> Moodles
     * COMPLETION_xx-Werte (lib/completionlib.php: INCOMPLETE=0, COMPLETE=1,
     * COMPLETE_PASS=2, COMPLETE_FAIL=3). Als Literale statt Konstanten
     * referenziert, damit diese Klassenkonstante beim Laden der Datei nicht
     * von der Ladereihenfolge von completionlib.php abhaengt.
     *
     * @var array<string, int>
     */
    private const COMPLETION_STATUS = [
        'abgeschlossen' => 1,
        'nicht_abgeschlossen' => 0,
        'bestanden' => 2,
        'nicht_bestanden' => 3,
    ];

    /**
     * Lehrkraft-deutsche Richtungswoerter fuer "datum" -> Moodles
     * DIRECTION_xx-Werte (availability_date\condition::DIRECTION_FROM/
     * DIRECTION_UNTIL). Als Literale referenziert, aus demselben Grund wie
     * {@see self::COMPLETION_STATUS}.
     *
     * @var array<string, string>
     */
    private const DATE_DIRECTION = [
        'ab' => '>=',
        'bis' => '<',
    ];

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID der Aktivitaet'),
            'bedingungen_json' => new external_value(
                PARAM_RAW,
                'JSON-Array von Voraussetzungen (leeres Array entfernt alle Voraussetzungen). Je Eintrag ein Objekt '
                    . 'mit "typ": "abschluss" (Felder "aktivitaet_cmid", "status": abgeschlossen|nicht_abgeschlossen|'
                    . 'bestanden|nicht_bestanden), "datum" (Felder "richtung": ab|bis, "zeitstempel": Unix-Zeit) oder '
                    . '"gruppe" (Feld "gruppen_id", 0 oder weggelassen = beliebige Gruppe). Alle Eintraege muessen '
                    . 'gleichzeitig erfuellt sein (UND-Verknuepfung).'
            ),
        ]);
    }

    /**
     * @param int $cmid
     * @param string $bedingungenjson
     * @return array
     */
    public static function execute(int $cmid, string $bedingungenjson): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'bedingungen_json' => $bedingungenjson,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native Berechtigungspruefung im Kurskontext (Spec 0015 §3.3/§9.2),
        // identisch zu update_module_settings/set_completion - keine eigene
        // Coursepilot-Schreib-Capability.
        require_capability('moodle/course:manageactivities', $context);

        if (empty($CFG->enableavailability)) {
            // Ohne instanzweit aktivierte bedingte Verfuegbarkeit wuerde
            // Moodle das Feld ohnehin verwerfen (course/modlib.php) -
            // klare Meldung statt stillem No-op.
            throw new moodle_exception('restrictionsnotenabled', 'local_coursepilot');
        }

        $modname = (string) $cm->modname;
        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            throw new moodle_exception(
                'unknownmodname',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'aktivitaetsarten' => implode(', ', registry::known_modnames())]
            );
        }

        $bedingungen = json_decode($params['bedingungen_json'], true);
        if (!is_array($bedingungen) || json_last_error() !== JSON_ERROR_NONE || self::is_json_object($bedingungen)) {
            throw new moodle_exception('invalidrestrictionjson', 'local_coursepilot');
        }

        $conditions = [];
        foreach ($bedingungen as $bedingung) {
            if (!is_array($bedingung) || self::is_json_object($bedingung) === false) {
                throw new coding_exception('bedingungen_json muss ein Array von JSON-Objekten sein.');
            }
            $conditions[] = self::build_condition((int) $cm->course, $bedingung);
        }

        $availabilityjson = self::build_availability_json($conditions);

        $course = get_course((int) $cm->course);
        require_once($CFG->dirroot . '/course/modlib.php');
        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        // Dieselbe Vorbereitung wie update_module_settings/set_completion
        // (#388/#392): ohne sie liest z.B. page_update_instance() ein
        // fehlendes Pseudofeld ungeschuetzt und schreibt Inhalt auf null,
        // obwohl dieser Endpunkt gar kein anderes Feld patcht.
        $before = self::read_settings((int) $cmid);
        pseudofield_carry_forward::apply($modname, $catalogclass, $moduleinfo, $before, $cm, []);
        $moduleinfo->availabilityconditionsjson = $availabilityjson;

        \update_moduleinfo($cm, $moduleinfo, $course);

        return [
            'cmid' => (int) $cmid,
            'modname' => (string) $cm->modname,
            'meldung' => self::build_message(count($conditions)),
        ];
    }

    /**
     * @param int $cmid
     * @return array Ist-Stand, dieselbe Form wie get_module_settings.
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * Manche Testdatengeneratoren/DB-Treiber liefern numerische IDs als
     * Zeichenkette statt als Ganzzahl - lehrkraftverstaendliche Argumente
     * sollen trotzdem als gueltig gelten, solange sie eindeutig eine
     * positive Ganzzahl meinen (kein "3.5", kein "3abc").
     *
     * @param mixed $value
     * @return int|null Positive Ganzzahl, oder null wenn ungueltig.
     */
    private static function positive_int($value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    /**
     * PHP kennt beim Dekodieren keinen Unterschied zwischen JSON-Array und
     * JSON-Objekt - beide werden zu assoziativen Arrays. "bedingungen_json"
     * muss aber eine Liste (JSON-Array) sein, kein Objekt.
     *
     * @param array $value
     * @return bool
     */
    private static function is_json_object(array $value): bool {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * Baut ein natives Bedingungs-Objekt (core_availability\condition::save()
     * -Form) aus einer lehrkraftverstaendlichen Bedingung - jeweils ueber die
     * offizielle get_json()-Fabrik der drei unterstuetzten Bedingungstypen.
     *
     * @param int $courseid
     * @param array $bedingung
     * @return stdClass
     * @throws moodle_exception restrictionunknowntype|restrictionactivitynotfound|restrictioninvalidstatus|
     *         restrictioninvaliddate|restrictiongroupnotfound
     */
    private static function build_condition(int $courseid, array $bedingung): stdClass {
        $typ = $bedingung['typ'] ?? null;
        switch ($typ) {
            case 'abschluss':
                return self::build_completion_condition($courseid, $bedingung);
            case 'datum':
                return self::build_date_condition($bedingung);
            case 'gruppe':
                return self::build_group_condition($courseid, $bedingung);
            default:
                throw new moodle_exception(
                    'restrictionunknowntype',
                    'local_coursepilot',
                    '',
                    ['field' => 'typ', 'value' => json_encode($typ)]
                );
        }
    }

    /**
     * @param int $courseid
     * @param array $bedingung
     * @return stdClass
     */
    private static function build_completion_condition(int $courseid, array $bedingung): stdClass {
        $aktivitaetcmid = self::positive_int($bedingung['aktivitaet_cmid'] ?? null);
        if ($aktivitaetcmid === null
                || !get_coursemodule_from_id('', $aktivitaetcmid, $courseid, false, IGNORE_MISSING)) {
            throw new moodle_exception(
                'restrictionactivitynotfound',
                'local_coursepilot',
                '',
                ['field' => 'aktivitaet_cmid', 'value' => json_encode($aktivitaetcmid)]
            );
        }

        $status = $bedingung['status'] ?? null;
        if (!is_string($status) || !array_key_exists($status, self::COMPLETION_STATUS)) {
            throw new moodle_exception(
                'restrictioninvalidstatus',
                'local_coursepilot',
                '',
                ['field' => 'status', 'value' => json_encode($status)]
            );
        }

        return \availability_completion\condition::get_json($aktivitaetcmid, self::COMPLETION_STATUS[$status]);
    }

    /**
     * @param array $bedingung
     * @return stdClass
     */
    private static function build_date_condition(array $bedingung): stdClass {
        $richtung = $bedingung['richtung'] ?? null;
        $zeitstempel = $bedingung['zeitstempel'] ?? null;
        if (!is_string($richtung) || !array_key_exists($richtung, self::DATE_DIRECTION) || !is_int($zeitstempel)) {
            throw new moodle_exception(
                'restrictioninvaliddate',
                'local_coursepilot',
                '',
                ['field' => 'richtung/zeitstempel', 'value' => json_encode($bedingung)]
            );
        }

        return \availability_date\condition::get_json(self::DATE_DIRECTION[$richtung], $zeitstempel);
    }

    /**
     * @param int $courseid
     * @param array $bedingung
     * @return stdClass
     */
    private static function build_group_condition(int $courseid, array $bedingung): stdClass {
        global $DB;

        $rawgruppenid = $bedingung['gruppen_id'] ?? 0;
        $isanygroup = $rawgruppenid === 0 || $rawgruppenid === null || $rawgruppenid === '0';
        $gruppenid = $isanygroup ? 0 : self::positive_int($rawgruppenid);
        if ($gruppenid === null) {
            throw new moodle_exception(
                'restrictiongroupnotfound',
                'local_coursepilot',
                '',
                ['field' => 'gruppen_id', 'value' => json_encode($rawgruppenid)]
            );
        }
        if ($gruppenid > 0 && !$DB->record_exists('groups', ['id' => $gruppenid, 'courseid' => $courseid])) {
            throw new moodle_exception(
                'restrictiongroupnotfound',
                'local_coursepilot',
                '',
                ['field' => 'gruppen_id', 'value' => json_encode($gruppenid)]
            );
        }

        return \availability_group\condition::get_json($gruppenid);
    }

    /**
     * Verpackt die einzelnen Bedingungen in den nativen Baum
     * (core_availability\tree-Wurzelformat, UND-Verknuepfung, alle sichtbar
     * fuer Lernende) und prueft das Ergebnis mit core_availability\tree VOR
     * dem Schreiben - eine Struktur, die core_availability\tree ablehnt,
     * wuerde auch availability/classes/info.php beim naechsten Seitenaufruf
     * ablehnen (dort ungefangen: das macht die Kursseite unaufrufbar). Ein
     * leeres Bedingungs-Array liefert einen leeren String (Voraussetzung
     * entfernt, siehe course/modlib.php).
     *
     * @param stdClass[] $conditions
     * @return string
     * @throws moodle_exception invalidrestrictionjson
     */
    private static function build_availability_json(array $conditions): string {
        if (!$conditions) {
            return '';
        }

        $structure = (object) [
            'op' => tree::OP_AND,
            'c' => $conditions,
            'showc' => array_fill(0, count($conditions), true),
        ];

        try {
            new tree($structure);
        } catch (coding_exception $e) {
            // Sollte durch die Validierung oben nie erreicht werden - letzte
            // Absicherung, damit niemals eine Struktur geschrieben wird, die
            // availability/classes/info.php spaeter ablehnen wuerde.
            throw new moodle_exception('invalidrestrictionjson', 'local_coursepilot', '', ['field' => 'bedingungen_json']);
        }

        return json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param int $count
     * @return string
     */
    private static function build_message(int $count): string {
        if ($count === 0) {
            return 'Alle Voraussetzungen wurden entfernt.';
        }
        return $count === 1
            ? 'Voraussetzung gesetzt: 1 Bedingung muss erfuellt sein.'
            : 'Voraussetzungen gesetzt: ' . $count . ' Bedingungen muessen alle erfuellt sein.';
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Aktivitaetstyp'),
            'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Aenderungsmeldung'),
        ]);
    }
}
