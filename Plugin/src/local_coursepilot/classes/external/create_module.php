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

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\module_catalog;
use local_coursepilot\catalog\catalog_fields;
use local_coursepilot\catalog\pseudofield_carry_forward;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\shared_block;
use local_coursepilot\material_files;
use local_coursepilot\write_gate;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Der zweite Schreibvorgang (Spec 0015 §3.4, Ticket #389, Phase 3): legt eine
 * neue Aktivitaet ueber den nativen Formularweg an (can_add_moduleinfo() fuer
 * die native Berechtigungspruefung plus Modul-/Abschnittsermittlung,
 * add_moduleinfo() zum Schreiben) - keine Handaenderung, die ueberleben
 * muesste, deshalb kein Vorher/Nachher-Diff wie bei {@see update_module_settings}.
 *
 * Anders als beim Patch (Ticket #388, "Vollersatz verworfen") gilt hier die
 * entgegengesetzte Regel: fehlende Felder werden mit dem katalogisierten
 * FORMULAR-Default aufgefuellt (nicht dem DB-Spalten-Default, die weichen bei
 * mehreren Feldern ab, siehe {@see \local_coursepilot\catalog\choice} Feld
 * "includeinactive") - beim Anlegen gibt es keine Handaenderung, die ein
 * stiller Reset zerstoeren koennte. Ein Pflichtfeld ganz ohne Formular-Default
 * (Kategorie "required" ohne "default" im Katalog) scheitert stattdessen mit
 * einer Meldung, die das Feld nennt (Spec 0015 §3.4).
 *
 * "resource" verlangt seit Spec 0018 (§4/§7, Issue #434) das Pflichtfeld
 * "files" (Liste von Materialordner-Pfaden) im selben Aufruf - ohne
 * Hauptdatei entsteht eine kaputte Aktivitaetsseite
 * (mod/resource/view.php: resource_print_filenotfound()), die Pruefung
 * laeuft deshalb VOR add_moduleinfo() ueber den normalen
 * Pflichtfeld-Mechanismus ({@see self::assert_no_required_field_missing()}).
 * "folder" bleibt anlegbar - ein leerer Ordner ist gueltig, "files" ist dort
 * optional und akzeptiert mehrere Pfade samt Zielunterordner (Spec 0018 §4.2,
 * {@see self::resolve_material_reference_pseudofields()}).
 *
 * Feldbuendel (Spec 0015 §2.4) sind bewusst KEIN eigener Endpunkt-Parameter:
 * "Sie überleben als benannte Feldbündel im Katalog, nicht als
 * Endpunkt-Parameter" - describe_module_fields liefert das Buendel, die KI
 * mischt es selbst in felder_json (ein Buendelwert gilt nur fuer Felder, die
 * felder_json nicht schon selbst nennt). Dieser Endpunkt sieht deshalb nur
 * das bereits gemischte Ergebnis.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class create_module extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Kurs-ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Abschnittsnummer (0-basiert)'),
            'modname' => new external_value(PARAM_PLUGIN, 'Aktivitaetstyp, z.B. page, label, url, choice, forum, assign'),
            'felder_json' => new external_value(
                PARAM_RAW,
                'JSON-Objekt Feldname => Wert - fehlende Felder werden mit dem Formular-Default aus dem Katalog '
                    . 'aufgefuellt. Ein Feldbuendel (describe_module_fields) wird VOR dem Aufruf hier hinein '
                    . 'gemischt (Spec 0015 §2.4: Buendel sind kein Endpunkt-Parameter) - ein Buendelwert gilt nur '
                    . 'fuer Felder, die dieses Objekt nicht schon selbst nennt.'
            ),
            'ort' => material_files::ort_parameter(),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @param string $modname
     * @param string $felderjson
     * @param string $ort
     * @return array
     */
    public static function execute(
        int $courseid,
        int $sectionnum,
        string $modname,
        string $felderjson,
        string $ort = material_files::ORT_BESTAND
    ): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'modname' => $modname,
            'felder_json' => $felderjson,
            'ort' => $ort,
        ]);

        $coursecontext = self::authorise($params['courseid']);

        $modname = $params['modname'];
        $catalogclass = self::catalog_for($modname);
        // Billigteil der Selbstfreigabe (Spec 0015 §11, ADR 0017, Ticket #399):
        // sperrt nur DIESE Aktivitaetsart, wenn ein erkannter Moodle-Versionswechsel
        // eine Katalogabweichung ergeben hat. Lesen bleibt unberuehrt.
        write_gate::assert_writable($modname);

        $merged = self::prepare_merged_fields($modname, $catalogclass, $coursecontext, $params);

        $course = get_course($params['courseid']);
        require_once($CFG->dirroot . '/course/modlib.php');
        $cmid = self::create_activity($course, $modname, $catalogclass, $params['sectionnum'], $merged);

        $after = self::read_settings($cmid);
        [$createdfields, $sideeffects] = self::report_and_side_effects($modname, $merged, $after);

        return [
            'cmid' => $cmid,
            'modname' => $modname,
            'meldung' => self::build_message($modname, $createdfields, $sideeffects),
            'angelegte_felder' => $createdfields,
            'nebenwirkungen' => $sideeffects,
        ];
    }

    /**
     * Prueft Kontext und Capabilities fuer den Kurs (Issue #523: aus
     * execute() ausgelagert, um die Funktion unter der 50-Zeilen-Grenze zu
     * halten).
     *
     * @param int $courseid
     * @return \context_course
     */
    private static function authorise(int $courseid): \context_course {
        $coursecontext = context_course::instance($courseid);
        self::validate_context($coursecontext);
        require_capability('local/coursepilot:use', $coursecontext);
        // Native Berechtigungspruefung vorgezogen (Spec 0015 §3.4, wie
        // {@see update_module_settings}): can_add_moduleinfo() prueft dieselbe
        // Capability spaeter ohnehin erneut - der Aufruf hier ist billig und
        // stellt sicher, dass eine fehlende Bearbeiten-Berechtigung nicht
        // hinter einer Feldvalidierungsmeldung versteckt bleibt.
        require_capability('moodle/course:manageactivities', $coursecontext);

        return $coursecontext;
    }

    /**
     * Dekodiert und validiert die Felder-JSON, loest Pseudofelder auf (Issue
     * #523: aus execute() ausgelagert, um die Funktion unter der
     * 50-Zeilen-Grenze zu halten).
     *
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param \context_course $coursecontext
     * @param array $params Validierte Parameter von execute().
     * @return array Die gepatchten Felder, bereit fuer add_moduleinfo().
     */
    private static function prepare_merged_fields(
        string $modname,
        string $catalogclass,
        \context_course $coursecontext,
        array $params
    ): array {
        $merged = json_decode($params['felder_json'], true);
        if (!is_array($merged) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        self::expand_scalar_to_repeated_fields($catalogclass, $merged);
        // Vor derive_content_from_editor_pseudofield(): die steigt bei einem
        // Nicht-Array still aus, und die Seite entstuende leer (#405).
        pseudofield_carry_forward::normalise_editor_pseudofields($catalogclass, $merged);
        self::derive_content_from_editor_pseudofield($catalogclass, $merged);

        catalog_fields::validate($catalogclass, $merged);
        self::validate_parallel_array_lengths($catalogclass, $modname, $merged);
        self::validate_combination_rules($catalogclass, $modname, $merged);
        // VOR assert_no_required_field_missing(): eine leere Pfadliste
        // ("files": []) zaehlt als nicht genannt, sonst rutscht sie am
        // Pflichtfeld-Check vorbei und resolve_into_draft() liefert einen
        // gueltigen, aber LEEREN Entwurf - eine resource ohne Hauptdatei
        // waere die Folge (Review-Fund zu Issue #434).
        self::drop_empty_material_reference_pseudofields($catalogclass, $merged);
        self::assert_no_required_field_missing($modname, $catalogclass, $merged);
        self::assert_stealth_allowed($merged);
        self::resolve_material_reference_pseudofields($catalogclass, $coursecontext, $merged, $params['ort']);

        return $merged;
    }

    /**
     * Legt die Aktivitaet nativ an (Issue #523: aus execute() ausgelagert).
     *
     * @param \stdClass $course
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param int $sectionnum
     * @param array $merged
     * @return int Die neue Kursmodul-ID.
     */
    private static function create_activity(
        \stdClass $course,
        string $modname,
        string $catalogclass,
        int $sectionnum,
        array $merged
    ): int {
        // can_add_moduleinfo() prueft die native Capability (s.o.), ermittelt
        // die Modul-ID und legt den Zielabschnitt bei Bedarf an
        // (course/modlib.php).
        [$module] = \can_add_moduleinfo($course, $modname, $sectionnum);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = $modname;
        $moduleinfo->module = (int) $module->id;
        $moduleinfo->section = $sectionnum;
        self::fill_form_defaults($modname, $catalogclass, $moduleinfo, $merged);
        foreach ($merged as $fieldname => $value) {
            $moduleinfo->{self::moduleinfo_property($fieldname)} = $value;
        }

        $created = \add_moduleinfo($moduleinfo, $course);
        return (int) $created->coursemodule;
    }

    /**
     * Die Katalogklasse fuer $modname, sofern der Schreibweg dieser Endpunkt
     * ist (Spec 0015 §3.1: manche Aktivitaetsarten haben ein eigenes
     * Einzelwerkzeug, z.B. quiz -> update_quiz_settings) - identische Pruefung
     * wie {@see update_module_settings::catalog_for()}.
     *
     * @param string $modname
     * @return class-string<module_catalog>
     * @throws moodle_exception unknownmodname|writevehicleblocked
     */
    private static function catalog_for(string $modname): string {
        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            throw new moodle_exception(
                'unknownmodname',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'aktivitaetsarten' => implode(', ', registry::known_modnames())]
            );
        }
        $schreibweg = $catalogclass::schreibweg();
        if ($schreibweg !== null) {
            throw new moodle_exception(
                'writevehicleblocked',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'schreibweg' => $schreibweg]
            );
        }
        return $catalogclass;
    }

    /**
     * Eine leere Pfadliste ("files": []) zaehlt wie ein nicht genanntes Feld
     * (Issue #434, Review-Fund: sonst rutscht sie an
     * {@see self::assert_no_required_field_missing()} vorbei und
     * resolve_into_draft() liefert einen gueltigen, aber LEEREN Entwurf -
     * eine resource ohne Hauptdatei waere die Folge). Nur Listen werden
     * hier entfernt - ein Nicht-Array bleibt stehen und scheitert weiter
     * unten in {@see self::resolve_material_reference_pseudofields()} mit
     * der generischen invalidmaterialreferencelist-Meldung.
     *
     * @param string $modname
     * @param array $merged Wird in-place bereinigt.
     * @return void
     */
    private static function drop_empty_material_reference_pseudofields(string $catalogclass, array &$merged): void {
        foreach (array_keys($catalogclass::write_options()['material_reference_fields'] ?? []) as $fieldname) {
            if (array_key_exists($fieldname, $merged) && $merged[$fieldname] === []) {
                unset($merged[$fieldname]);
            }
        }
    }

    /**
     * Loest Materialordner-Verweis-Pseudofelder ({@see self::MATERIAL_REFERENCE_PSEUDOFIELDS})
     * im zusammengefuehrten Feldsatz zu Dateimanager-Entwurfs-Itemids auf,
     * bevor add_moduleinfo() laeuft (Spec 0018 §4.2, Issue #434) - identisches
     * Muster wie {@see update_module_settings::resolve_material_reference_pseudofields()},
     * hier ohne Papierkorb-Verdraengung (es gibt noch keine bestehende
     * Aktivitaet, die etwas zu verdraengen haette). Ohne Treffer keine
     * Wirkung, kein zusaetzlicher Dateizugriff.
     *
     * @param string $modname
     * @param context_course $coursecontext targetcontextid fuer
     *        file_prepare_draft_area() - der Modulkontext existiert beim
     *        Anlegen noch nicht.
     * @param array $merged Wird in-place ersetzt: Pfadliste -> Entwurfs-Itemid.
     * @param string $ort {@see \local_coursepilot\material_files::ORT_BESTAND}/{@see \local_coursepilot\material_files::ORT_WERKBANK}
     *        - Quelle der Pfade (Issue #496).
     * @return void
     * @throws moodle_exception materialfilenotfound / invalidmaterialpath / invalidmaterialort /
     *         materialpathiskontext / invalidmaterialreferencelist / materialembedtoolarge
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    private static function resolve_material_reference_pseudofields(
        string $catalogclass,
        context_course $coursecontext,
        array &$merged,
        string $ort
    ): void {
        $specs = $catalogclass::write_options()['material_reference_fields'] ?? [];
        $relevant = array_intersect_key($specs, $merged);
        if (!$relevant) {
            return;
        }

        material_files::require_manage_own_files();
        foreach ($relevant as $fieldname => $spec) {
            if (!is_array($merged[$fieldname])) {
                throw new moodle_exception('invalidmaterialreferencelist', 'local_coursepilot', '', $fieldname);
            }
            $merged[$fieldname] = material_files::resolve_into_draft(
                $coursecontext->id,
                $spec['component'],
                $spec['filearea'],
                0,
                $merged[$fieldname],
                $ort
            );
        }
    }

    /**
     * Das Buendel "zuteilung" (choice) fuehrt "limit" als EINEN Wert
     * (dieselbe Begrenzung fuer jede Option, siehe
     * {@see \local_coursepilot\catalog\choice::bundles()}), waehrend das echte
     * Formularfeld ein Array je Option ist. Ohne diese Aufloesung wuerde
     * choice_add_instance() den skalaren Wert als $choice->limit[$key]
     * fehlinterpretieren. Nur choice hat dieses Buendelmuster - ponytail: bei
     * Bedarf fuer weitere Buendel mit demselben Muster verallgemeinern.
     *
     * @param string $modname
     * @param array $merged Wird in-place ergaenzt.
     * @return void
     */
    private static function expand_scalar_to_repeated_fields(string $catalogclass, array &$merged): void {
        foreach ($catalogclass::write_options()['scalar_to_repeated'] ?? [] as $field => $reference) {
            if (array_key_exists($field, $merged) && !is_array($merged[$field]) && isset($merged[$reference]) && is_array($merged[$reference])) {
                $merged[$field] = array_fill(0, count($merged[$reference]), (int) $merged[$field]);
            }
        }
    }

    /**
     * mod_page: das Pseudofeld "page" (Editor-Array text/format/itemid) ist
     * der einzige Formularweg zu den echten Spalten "content"/"contentformat"
     * (mod/page/lib.php: page_add_instance() liest sie nur aus $data->page,
     * UND NUR wenn ein $mform-Objekt vorhanden ist - add_moduleinfo() ruft
     * *_add_instance() hier ohne $mform (Spec 0015 §3.4 nennt keinen
     * Formularobjekt-Aufbau), die Umrechnung muss deshalb hier selbst
     * passieren, nicht erst in Moodle. Ohne diesen Schritt bliebe "content"
     * das per Katalog als Pflichtfeld ohne Default gefuehrte Feld dauerhaft
     * unbelegt, obwohl die Lehrkraft "page" genannt hat.
     *
     * ponytail: nur page hat dieses Editor-nach-Spalte-Muster beim Anlegen
     * (siehe {@see update_module_settings::REQUIRED_EDITOR_PSEUDOFIELDS} fuer
     * dieselbe Beobachtung auf dem Patch-Weg) - bei einer weiteren
     * Aktivitaetsart mit demselben Muster hier ergaenzen.
     *
     * @param string $modname
     * @param array $merged Wird in-place ergaenzt.
     * @return void
     */
    private static function derive_content_from_editor_pseudofield(string $catalogclass, array &$merged): void {
        foreach ($catalogclass::write_options()['editor_content'] ?? [] as $editor => $fields) {
            if (!isset($merged[$editor]) || !is_array($merged[$editor])) {
                continue;
            }
            if (!array_key_exists($fields[0], $merged)) {
                $merged[$fields[0]] = (string) ($merged[$editor]['text'] ?? '');
            }
            if (!array_key_exists($fields[1], $merged)) {
                $merged[$fields[1]] = (int) ($merged[$editor]['format'] ?? FORMAT_HTML);
            }
        }
    }

    /**
     * Katalogfeldname => tatsaechlicher $moduleinfo-Eigenschaftsname. Fuer
     * fast jedes Feld identisch - Ausnahme "idnumber"
     * ({@see \local_coursepilot\catalog\shared_block}): der echte Formularweg-
     * Name ist "cmidnumber" (course/modlib.php: get_moduleinfo_data() setzt
     * `$data->cmidnumber = $cm->idnumber`, add_moduleinfo() liest
     * `$moduleinfo->cmidnumber`) - "idnumber" bleibt der lehrkraftverstaendliche
     * Katalogname (Spec 0015 §2.3), wird hier aber auf die reale Eigenschaft
     * abgebildet, damit edit_module_post_actions() (course/modlib.php) nicht
     * mit einer undefinierten Eigenschaft auf "cmidnumber" laeuft.
     *
     * @param string $fieldname
     * @return string
     */
    private static function moduleinfo_property(string $fieldname): string {
        return $fieldname === 'idnumber' ? 'cmidnumber' : $fieldname;
    }

    /**
     * mod_url fuehrt "parameter_N"/"variable_N" (N=0..99) als EIN
     * Katalogeintrag je Vorlage, nicht 200 Einzelfelder ({@see
     * \local_coursepilot\catalog\url}) - eine Lehrkraft/KI schreibt aber
     * konkrete Indizes wie "parameter_0". Bildet einen konkreten Index auf
     * seine Vorlage ab, damit die Feldpruefung ihn erkennt; alles andere
     * bleibt unveraendert (fuer die "unknownfield"-Fehlermeldung soll der
     * echte, konkrete Feldname stehen bleiben, nicht die Vorlage).
     *
     * @param string $fieldname
     * @return string
     */
    private static function templated_field_name(string $fieldname): string {
        return preg_match('/^(parameter|variable)_\d+$/', $fieldname) === 1
            ? preg_replace('/_\d+$/', '_N', $fieldname)
            : $fieldname;
    }

    /**
     * Alles-oder-nichts-Pruefung VOR dem Anlegen: unbekanntes Feld, gesperrtes
     * Feld, unerlaubter Wert - dieselbe Pruefung wie
     * {@see update_module_settings::validate_patch()}. Die Datumspaar-
     * Kombinationsregeln laufen separat, siehe {@see self::validate_combination_rules()}.
     *
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param array $merged
     * @return void
     * @throws moodle_exception blockedfield|unknownfield|invalidfieldvalue
     */

    /**
     * choice: "limit[]" muss genauso viele Eintraege haben wie "option[]"
     * (Katalogkommentar {@see \local_coursepilot\catalog\choice}), sonst
     * begrenzt Moodle manche Optionen gar nicht, ohne einen Fehler zu melden -
     * hier ausdruecklich erzwungen statt Moodles stiller Toleranz zu folgen
     * (Abnahmekriterium #389: "eine Begrenzungsliste falscher Laenge
     * scheitert").
     *
     * @param string $modname
     * @param array $merged
     * @return void
     * @throws moodle_exception combinationruleviolation
     */
    private static function validate_parallel_array_lengths(string $catalogclass, string $modname, array $merged): void {
        foreach ($catalogclass::write_options()['parallel_array_lengths'] ?? [] as $rule) {
            if (!isset($merged[$rule['reference']], $merged[$rule['field']])
                || !is_array($merged[$rule['reference']]) || !is_array($merged[$rule['field']])
                || count($merged[$rule['reference']]) === count($merged[$rule['field']])) {
                continue;
            }
            throw new moodle_exception('combinationruleviolation', 'local_coursepilot', '', [
                'modname' => $modname,
                'message' => '"' . $rule['field'] . '" muss genauso viele Eintraege haben wie "' . $rule['reference'] . '".',
            ]);
        }
    }

    /**
     * Datumspaar-Kombinationsregeln (s.o. {@see self::DATE_ORDER_RULES}) -
     * geprueft nur, wenn der Patch tatsaechlich eines der beiden Felder
     * nennt (ein unbenanntes Feld bleibt beim Anlegen ohnehin auf seinem
     * Katalog-Default 0 und kann keine Regel verletzen).
     *
     * @param string $modname
     * @param array $merged
     * @return void
     * @throws moodle_exception combinationruleviolation
     */
    private static function validate_combination_rules(string $catalogclass, string $modname, array $merged): void {
        $rules = $catalogclass::write_options()['date_order_rules'] ?? [];
        foreach ($rules as $rule) {
            if (!array_key_exists($rule['reference'], $merged) && !array_key_exists($rule['field'], $merged)) {
                continue;
            }
            $reference = (int) ($merged[$rule['reference']] ?? 0);
            $value = (int) ($merged[$rule['field']] ?? 0);
            if ($reference === 0 || $value === 0) {
                continue;
            }

            $violated = $rule['mode'] === 'must_be_after' ? ($value <= $reference) : ($value < $reference);
            if (!$violated) {
                continue;
            }
            $message = $rule['mode'] === 'must_be_after'
                ? '"' . $rule['field'] . '" muss nach "' . $rule['reference'] . '" liegen.'
                : '"' . $rule['field'] . '" darf nicht vor "' . $rule['reference'] . '" liegen.';
            throw new moodle_exception(
                'combinationruleviolation',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'message' => $message]
            );
        }
    }

    /**
     * Ein Pflichtfeld ganz ohne Formular-Default (Katalog: required=true,
     * default=null) muss die Lehrkraft nennen - anders als bei jedem anderen
     * Feld gibt es hier keinen Formular-Default zum Auffuellen (Spec 0015
     * §3.4).
     *
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param array $merged
     * @return void
     * @throws moodle_exception requiredfieldwithoutdefault
     */
    private static function assert_no_required_field_missing(string $modname, string $catalogclass, array $merged): void {
        $allfields = array_merge(shared_block::fields(), $catalogclass::fields(), $catalogclass::pseudofields());
        // Alle fehlenden auf einmal, nicht das erste (#404): sonst muss sich
        // die Lehrkraft (bzw. das Modell) Aufruf fuer Aufruf durch die
        // Pflichtfelder raten, jedes Mal mit einer Fehlermeldung dazwischen.
        $missing = [];
        foreach ($allfields as $field) {
            if (!$field->required || $field->default !== null) {
                continue;
            }
            if (array_key_exists($field->name, $merged)) {
                continue;
            }
            $missing[] = '"' . $field->name . '"';
        }
        if ($missing) {
            throw new moodle_exception(
                'requiredfieldwithoutdefault',
                'local_coursepilot',
                '',
                ['field' => implode(', ', $missing), 'modname' => $modname]
            );
        }
    }

    /**
     * Stealth (Spec 0015 §7, Ticket #390) setzt voraus, dass die Instanz
     * "allowstealth" erlaubt - identische Regel wie
     * {@see update_module_settings::assert_stealth_allowed()}. Beim Anlegen
     * bleibt visibleoncoursepage ohne ausdrueckliche Angabe auf seinem
     * Katalog-Default 1 (sichtbar), betroffen ist also nur ein
     * ausdruecklicher Wunsch nach Stealth gleich beim Anlegen.
     *
     * @param array $merged
     * @return void
     * @throws moodle_exception stealthnotallowed
     */
    private static function assert_stealth_allowed(array $merged): void {
        if (($merged['visibleoncoursepage'] ?? null) !== 0) {
            return;
        }
        if (get_config(null, 'allowstealth')) {
            return;
        }
        throw new moodle_exception('stealthnotallowed', 'local_coursepilot');
    }

    /**
     * Fuellt jedes vom Patch/Buendel nicht genannte Feld mit seinem
     * katalogisierten FORMULAR-Default (nicht dem DB-Default, siehe
     * Klassendoku) - fuer mod_assign zusaetzlich die sechs dynamisch
     * aufzuloesenden Abgabe-/Feedback-Enable-Felder (s.o.).
     *
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param array $merged
     * @return void
     */
    private static function fill_form_defaults(string $modname, string $catalogclass, \stdClass $moduleinfo, array $merged): void {
        $allfields = array_merge(shared_block::fields(), $catalogclass::fields(), $catalogclass::pseudofields());
        foreach ($allfields as $field) {
            if (array_key_exists($field->name, $merged)) {
                continue;
            }
            if ($field->default !== null) {
                $moduleinfo->{self::moduleinfo_property($field->name)} = $field->default;
                continue;
            }
            $adminfields = $catalogclass::write_options()['admin_default_fields'] ?? [];
            if (isset($adminfields[$field->name])) {
                $configcomponent = $adminfields[$field->name];
                $moduleinfo->{$field->name} = (int) (bool) get_config($configcomponent, 'default');
            }
        }
        // mod_folder liest "files" (Draft-Itemid) ungeschuetzt, ohne isset()-
        // Wache (mod/folder/lib.php: folder_add_instance()) - das Feld ist
        // bis Spec 0018 gesperrt (siehe Klassendoku), braucht aber trotzdem
        // einen Platzhalter "kein Draftbereich", sonst ein PHP-Warning bei
        // JEDEM Anlegen. Ein leerer Ordner ist gueltig (siehe
        // \local_coursepilot\catalog\folder).
        foreach ($catalogclass::write_options()['missing_form_values'] ?? [] as $field => $value) {
            if (!property_exists($moduleinfo, $field)) {
                $moduleinfo->{$field} = $value;
            }
        }
    }

    /**
     * Ist-Stand nach dem Anlegen als assoziatives Array - dieselbe
     * Zusammenstellung wie {@see get_module_settings}, wiederverwendet statt
     * dupliziert (wie {@see update_module_settings::read_settings()}).
     *
     * @param int $cmid
     * @return array
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * Die tatsaechlich vom Patch/Buendel gesetzten Felder mit ihrem
     * persistierten Wert (nicht dem rohen Eingabewert - Moodle normalisiert
     * manche Felder beim Schreiben, z.B. url_fix_submitted_url()), plus
     * ausgeloeste Nebenwirkungen. Katalog-Defaults, die die Lehrkraft nicht
     * genannt hat, tauchen hier bewusst nicht auf - sie sind stille
     * Voreinstellung, keine "Aenderung".
     *
     * @param string $modname
     * @param array $merged
     * @param array $after
     * @return array{0: array, 1: string[]}
     */
    private static function report_and_side_effects(string $modname, array $merged, array $after): array {
        $createdfields = [];
        $sideeffects = [];
        $triggers = registry::for($modname)::write_options()['side_effect_triggers'] ?? [];

        foreach (array_keys($merged) as $fieldname) {
            $value = $after[$fieldname] ?? null;
            $createdfields[] = [
                'feld' => $fieldname,
                'wert_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            if (isset($triggers[$fieldname][$value])) {
                $sideeffects[] = $triggers[$fieldname][$value];
            }
        }

        return [$createdfields, $sideeffects];
    }

    /**
     * Die Lehrkraft-deutsche Anlegemeldung (Spec 0015 §3.4: "die Antwort ist
     * die Aenderungsmeldung").
     *
     * @param string $modname
     * @param array $createdfields
     * @param string[] $sideeffects
     * @return string
     */
    private static function build_message(string $modname, array $createdfields, array $sideeffects): string {
        $parts = [];
        foreach ($createdfields as $field) {
            $parts[] = '"' . $field['feld'] . '" = ' . $field['wert_json'];
        }
        $message = 'Aktivität "' . $modname . '" angelegt';
        $message .= $parts ? (': ' . implode(', ', $parts) . '.') : '.';

        if ($sideeffects) {
            $message .= ' ' . implode(' ', $sideeffects);
        }

        return $message;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID der neu angelegten Aktivitaet'),
            'modname' => new external_value(PARAM_TEXT, 'Aktivitaetstyp'),
            'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Anlegemeldung'),
            'angelegte_felder' => new external_multiple_structure(
                new external_single_structure([
                    'feld' => new external_value(PARAM_TEXT, 'Feldname'),
                    'wert_json' => new external_value(PARAM_RAW, 'JSON-kodierter, tatsaechlich persistierter Wert'),
                ]),
                'Je vom Patch/Buendel gesetztem Feld ein Eintrag - stille Katalog-Defaults fehlen hier bewusst'
            ),
            'nebenwirkungen' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Nebenwirkungsvermerk in Lehrkraft-Deutsch'),
                'Ausgeloeste Nebenwirkungen aus Katalogkategorie 5, leer wenn keine ausgeloest wurden'
            ),
        ]);
    }
}
