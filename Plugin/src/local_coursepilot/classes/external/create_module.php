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
use local_coursepilot\catalog\learner_locks;
use local_coursepilot\catalog\pseudofield_carry_forward;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\write_target;
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
 * Pflichtfeld-Mechanismus ({@see \local_coursepilot\catalog\write_target::create()}).
 * "folder" bleibt anlegbar - ein leerer Ordner ist gueltig, "files" ist dort
 * optional und akzeptiert mehrere Pfade samt Zielunterordner (Spec 0018 §4.2,
 * {@see self::resolve_material_reference_pseudofields()}).
 *
 * Feldbuendel (Spec 0015 §2.4) sind bewusst KEIN eigener Endpunkt-Parameter:
 * "Sie überleben als benannte Feldbündel im Katalog, nicht als
 * Endpunkt-Parameter" - describe_module_fields liefert das Buendel, die KI
 * mischt es selbst in fields_json (ein Buendelwert gilt nur fuer Felder, die
 * fields_json nicht schon selbst nennt). Dieser Endpunkt sieht deshalb nur
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
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based)'),
            'modname' => new external_value(PARAM_PLUGIN, 'Activity type, e.g. page, label, url, choice, forum, assign'),
            'fields_json' => new external_value(
                PARAM_RAW,
                'JSON object field name => value - missing fields are filled with the catalog form default. '
                    . 'A field bundle (describe_module_fields) is mixed in here BEFORE the call (Spec 0015 §2.4: '
                    . 'bundles are not an endpoint parameter) - a bundle value only applies to fields this object '
                    . 'does not already name itself.'
            ),
            'location' => material_files::location_parameter(),
            learner_locks::PARAMETER => learner_locks::confirm_parameter(),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @param string $modname
     * @param string $fieldsjson
     * @param string $location
     * @param string[] $confirmlearnerlocks
     * @return array
     */
    public static function execute(
        int $courseid,
        int $sectionnum,
        string $modname,
        string $fieldsjson,
        string $location = material_files::LOCATION_STORE,
        array $confirmlearnerlocks = []
    ): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'modname' => $modname,
            'fields_json' => $fieldsjson,
            'location' => $location,
            learner_locks::PARAMETER => $confirmlearnerlocks,
        ]);

        $coursecontext = self::authorise($params['courseid']);

        $modname = $params['modname'];
        $catalogclass = self::catalog_for($modname);
        // Billigteil der Selbstfreigabe (Spec 0015 §11, ADR 0017, Ticket #399):
        // sperrt nur DIESE Aktivitaetsart, wenn ein erkannter Moodle-Versionswechsel
        // eine Katalogabweichung ergeben hat. Lesen bleibt unberuehrt.
        write_gate::assert_writable($modname);

        [$merged, $defaults] = self::prepare_merged_fields($catalogclass, $coursecontext, $params);
        $modulefields = $merged;
        self::resolve_intro_image_pseudofield($modname, $catalogclass, $coursecontext, $modulefields, $params['location']);

        $course = get_course($params['courseid']);
        require_once($CFG->dirroot . '/course/modlib.php');
        $cmid = self::create_activity($course, $modname, $catalogclass, $params['sectionnum'], $modulefields, $defaults);

        $after = self::read_settings($cmid);
        [$createdfields, $sideeffects] = self::report_and_side_effects($modname, $merged, $after);

        return [
            'cmid' => $cmid,
            'modname' => $modname,
            'message' => self::build_message($modname, $createdfields, $sideeffects),
            'created_fields' => $createdfields,
            'side_effects' => $sideeffects,
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
     * Decodes fields_json, normalises pseudofields and lets the catalog write
     * target decide every rule before any file is resolved (#646).
     *
     * @param class-string<module_catalog> $catalogclass
     * @param \context_course $coursecontext
     * @param array $params Validated parameters of execute().
     * @return array{0: array, 1: array} Named fields ready for add_moduleinfo(), filled form defaults.
     */
    private static function prepare_merged_fields(string $catalogclass, \context_course $coursecontext, array $params): array {
        $merged = json_decode($params['fields_json'], true);
        if (!is_array($merged) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        self::expand_scalar_to_repeated_fields($catalogclass, $merged);
        // Vor derive_content_from_editor_pseudofield(): die steigt bei einem
        // Nicht-Array still aus, und die Seite entstuende leer (#405).
        pseudofield_carry_forward::normalise_editor_pseudofields($catalogclass, $merged);
        self::derive_content_from_editor_pseudofield($catalogclass, $merged);
        // Before the required-field check: an empty path list ("files": [])
        // counts as not named, otherwise a resource without main file would
        // slip through with an empty draft (review finding on #434).
        self::drop_empty_material_reference_pseudofields($catalogclass, $merged);

        $target = write_target::create($catalogclass, $merged, $params[learner_locks::PARAMETER]);
        self::resolve_material_reference_pseudofields($catalogclass, $coursecontext, $merged, $params['location']);

        return [$merged, $target->defaults()];
    }

    /**
     * Legt die Aktivitaet nativ an (Issue #523: aus execute() ausgelagert).
     *
     * @param \stdClass $course
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param int $sectionnum
     * @param array $merged
     * @param array $defaults Filled form defaults from the write target.
     * @return int Die neue Kursmodul-ID.
     */
    private static function create_activity(
        \stdClass $course,
        string $modname,
        string $catalogclass,
        int $sectionnum,
        array $merged,
        array $defaults
    ): int {
        // can_add_moduleinfo() prueft die native Capability (s.o.), ermittelt
        // die Modul-ID und legt den Zielabschnitt bei Bedarf an
        // (course/modlib.php).
        [$module] = \can_add_moduleinfo($course, $modname, $sectionnum);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = $modname;
        $moduleinfo->module = (int) $module->id;
        $moduleinfo->section = $sectionnum;
        foreach ($defaults as $fieldname => $value) {
            $moduleinfo->{self::moduleinfo_property($fieldname)} = $value;
        }
        // mod_folder reads "files" (draft itemid) unguarded in
        // folder_add_instance(); an empty folder needs a "no draft" placeholder.
        foreach ($catalogclass::write_options()['missing_form_values'] ?? [] as $field => $value) {
            if (!property_exists($moduleinfo, $field)) {
                $moduleinfo->{$field} = $value;
            }
        }
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
        $catalogclass = registry::require_catalogued($modname);
        $writeroute = $catalogclass::write_route();
        if ($writeroute !== null) {
            throw new moodle_exception(
                'writevehicleblocked',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'write_route' => $writeroute]
            );
        }
        return $catalogclass;
    }

    /**
     * Eine leere Pfadliste ("files": []) zaehlt wie ein nicht genanntes Feld
     * (Issue #434, Review-Fund: sonst rutscht sie an
     * {@see \local_coursepilot\catalog\write_target::create()} vorbei und
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
     * @param string $location {@see \local_coursepilot\material_files::LOCATION_STORE}/{@see \local_coursepilot\material_files::LOCATION_WORKBENCH}
     *        - Quelle der Pfade (Issue #496).
     * @return void
     * @throws moodle_exception materialfilenotfound / invalidmaterialpath / invalidmateriallocation /
     *         materialpathiscontext / invalidmaterialreferencelist / materialembedtoolarge
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    private static function resolve_material_reference_pseudofields(
        string $catalogclass,
        context_course $coursecontext,
        array &$merged,
        string $location
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
                $location
            );
        }
    }

    /**
     * Resolves catalog intro images before creation. The course context is
     * only used to prepare the draft; add_moduleinfo() saves it to the new
     * module context. Keep the original fields separately for the report.
     *
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param context_course $coursecontext
     * @param array $fields Replaces the image pseudofield with introeditor.
     * @param string $location
     */
    private static function resolve_intro_image_pseudofield(
        string $modname,
        string $catalogclass,
        context_course $coursecontext,
        array &$fields,
        string $location
    ): void {
        $fieldname = $catalogclass::write_options()['intro_image_field'] ?? null;
        if ($fieldname === null || !array_key_exists($fieldname, $fields)) {
            return;
        }
        $paths = $fields[$fieldname];
        if (!is_array($paths) || !array_is_list($paths)) {
            throw new moodle_exception('invalidmaterialreferencelist', 'local_coursepilot', '', $fieldname);
        }
        material_files::require_manage_own_files();
        foreach ($paths as $path) {
            if (!is_string($path) || !material_files::is_allowed_embed_image_extension($path)) {
                throw new moodle_exception('materialfiledisallowedtype', 'local_coursepilot', '', (object) [
                    'filename' => is_string($path) ? $path : '',
                    'allowed' => implode(', ', material_files::allowed_embed_image_extensions()),
                ]);
            }
        }
        $draftitemid = material_files::resolve_into_draft(
            $coursecontext->id, 'mod_' . $modname, 'intro', 0, $paths, $location);
        $fields['introeditor'] = [
            'text' => $fields['intro'] ?? '',
            'format' => $fields['introformat'] ?? FORMAT_HTML,
            'itemid' => $draftitemid,
        ];
        unset($fields[$fieldname]);
    }

    /**
     * Das Buendel "allocation" (choice) fuehrt "limit" als EINEN Wert
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
     * Ist-Stand nach dem Anlegen als assoziatives Array - dieselbe
     * Zusammenstellung wie {@see get_module_settings}, wiederverwendet statt
     * dupliziert (wie {@see update_module_settings::read_settings()}).
     *
     * @param int $cmid
     * @return array
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        $settings = json_decode($result['settings_json'], true);
        $fieldname = registry::for($result['modname'])::write_options()['intro_image_field'] ?? null;
        if ($fieldname !== null) {
            $files = get_file_storage()->get_area_files(
                \context_module::instance($cmid)->id, 'mod_' . $result['modname'], 'intro', 0, 'filename', false);
            $settings[$fieldname] = array_values(array_map(
                static fn(\stored_file $file): string => $file->get_filename(), $files));
        }
        return $settings;
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
                'field' => $fieldname,
                'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            $parts[] = '"' . $field['field'] . '" = ' . $field['value_json'];
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
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the newly created activity'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing German creation message'),
            'created_fields' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'value_json' => new external_value(PARAM_RAW, 'JSON-encoded, actually persisted value'),
                ]),
                'One entry per field set by the patch/bundle - silent catalog defaults are deliberately absent here'
            ),
            'side_effects' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Teacher-facing German side-effect note'),
                'Triggered side effects from catalog category 5, empty when none were triggered'
            ),
        ]);
    }
}
