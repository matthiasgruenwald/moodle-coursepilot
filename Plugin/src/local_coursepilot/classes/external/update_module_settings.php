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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\activity_file_trash;
use local_coursepilot\catalog\module_catalog;
use local_coursepilot\catalog\field;
use local_coursepilot\catalog\pseudofield_carry_forward;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\shared_block;
use local_coursepilot\material_files;
use local_coursepilot\write_gate;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Der erste Schreibvorgang (Spec 0015 §3.3, Ticket #388, Phase 3): patcht
 * einzelne Einstellungen einer bestehenden Aktivitaet ueber den nativen
 * Formularweg (get_moduleinfo_data() lesen, ueberlagern, update_moduleinfo()
 * schreiben) - kein Konfliktschutz, kein expected_version, eine parallele
 * Handaenderung an einem ANDEREN Feld ueberlebt (Spec 0015 §3.3).
 *
 * Alles oder nichts: jede Validierung (unbekanntes Feld, gesperrtes Feld,
 * unerlaubter Wert, Kombinationsregel) laeuft VOR dem einzigen Schreibaufruf
 * - kein Teilergebnis moeglich.
 *
 * Keine eigene Coursepilot-Schreib-Capability: get_moduleinfo_data() ruft
 * intern can_update_moduleinfo(), das 'moodle/course:manageactivities' im
 * Modulkontext verlangt - das ist die native Pruefung, die Spec 0015 §3.3
 * verlangt. 'local/coursepilot:use' bleibt die Basis-Zugriffspruefung wie bei
 * jedem anderen Werkzeug.
 *
 * Direkte DB-Schreibung wird bewusst nicht genutzt (ADR 0016): sie loest
 * kein course_module_updated aus, der Aenderungsverlauf (#385-387) bliebe
 * dafuer blind.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class update_module_settings extends external_api {

    /**
     * Pseudofelder, deren Patch-Wert kein Skalar ist, sondern eine Liste von
     * Materialordner-Pfaden (Spec 0018 §4.2, Ticket #429) - der Verweisweg,
     * der die Dateisperre aus Spec 0015 §4.3 fuer assign aufhebt. Vor dem
     * eigentlichen update_moduleinfo()-Aufruf wird jeder Pfad zu einer
     * bestehenden Materialdatei aufgeloest und in einen Dateimanager-Entwurf
     * kopiert ({@see material_files::resolve_into_draft()}) - derselbe
     * Freigabeweg wie jeder andere Patch (validate_patch laeuft vorher,
     * unveraendert), kein Sonderweg fuer Binaerdaten.
     *
     * @var array<string, array<string, array{component: string, filearea: string}>>
     */
    /**
     * Oeffentlicher Blick auf {@see self::MATERIAL_REFERENCE_PSEUDOFIELDS} fuer
     * eine Aktivitaetsart - wiederverwendet statt dupliziert von
     * {@see \local_coursepilot\external\restore_activity_version}, das denselben
     * component/filearea-Satz braucht, um ersetzte Dateien aus dem Papierkorb
     * ({@see \local_coursepilot\activity_file_trash}) zurueckzuholen (Spec 0018
     * §9.1, Issue #432).
     *
     * @param string $modname
     * @return array<string, array{component: string, filearea: string}>
     */
    public static function material_reference_specs(string $modname): array {
        $catalogclass = registry::for($modname);
        return $catalogclass === null ? [] : ($catalogclass::write_options()['material_reference_fields'] ?? []);
    }

    /**
     * Pseudofeld je Aktivitaetsart, dessen Pfadliste NICHT an eine eigene
     * Datei-Filearea angehaengt wird, sondern in den Draft-Dateibereich der
     * Intro selbst (Spec 0018 §4.2/§5, Issue #433: "Fachabbildung in die
     * Aufgabenbeschreibung einbetten") - anders als
     * {@see self::MATERIAL_REFERENCE_PSEUDOFIELDS} deshalb kein eigener
     * moduleinfo-Eintrag, sondern {@see self::resolve_intro_image_pseudofield()}
     * setzt direkt $moduleinfo->introeditor['itemid'].
     *
     * @var array<string, string>
     */
    /**
     * Pseudofelder, die zwar {@see \local_coursepilot\catalog\module_catalog::blocklist()}
     * nicht mehr sperrt (fuer create_module frei, Issue #434), auf DIESEM
     * Patch-Weg aber scheitern muessen statt still wirkungslos zu bleiben -
     * siehe {@see self::MATERIAL_REFERENCE_PSEUDOFIELDS} fuer die Begruendung
     * (folder_update_instance() liest den Draft-Itemid aus $_REQUEST, nicht
     * aus $data->files).
     *
     * @var array<string, string[]>
     */
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID der Aktivitaet'),
            'felder_json' => new external_value(
                PARAM_RAW,
                'JSON-Objekt Feldname => neuer Wert - nur die zu aendernden Felder (Patch, kein Vollstand)'
            ),
            'ort' => material_files::ort_parameter(),
        ]);
    }

    /**
     * Roher Schreibweg fuer genau EIN Materialreferenz-Pseudofeld
     * ({@see self::MATERIAL_REFERENCE_PSEUDOFIELDS}), mit einem bereits
     * fertigen Dateimanager-Entwurf statt Materialordner-Pfaden - fuer
     * {@see \local_coursepilot\external\restore_activity_version}, das Dateien
     * aus dem Papierkorb ({@see \local_coursepilot\activity_file_trash}) statt
     * aus dem Materialordner zurueckschreibt (Spec 0018 §9.1, Issue #432).
     *
     * Kein eigener Feld-Patch-Validierungsdurchlauf: der Aufrufer hat
     * moodle/course:manageactivities und local/coursepilot:restoreversion
     * bereits geprueft, und der Entwurfsinhalt stammt ausschliesslich aus
     * dem eigenen Aenderungsverlauf/Papierkorb, nicht aus Client-Eingaben.
     *
     * @param int $cmid
     * @param string $fieldname Eines der MATERIAL_REFERENCE_PSEUDOFIELDS-Felder dieser Aktivitaetsart.
     * @param int $draftitemid Fertiger Dateimanager-Entwurf, z.B. aus
     *        {@see \local_coursepilot\activity_file_trash::resolve_restore_into_draft()}.
     * @return void
     */
    public static function write_pseudofield_draft(int $cmid, string $fieldname, int $draftitemid): void {
        global $CFG;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $course = get_course((int) $cm->course);
        require_once($CFG->dirroot . '/course/modlib.php');
        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        $moduleinfo->{self::moduleinfo_property($fieldname)} = $draftitemid;
        \update_moduleinfo($cm, $moduleinfo, $course);
    }

    /**
     * @param int $cmid
     * @param string $felderjson
     * @param string $ort
     * @return array
     */
    public static function execute(int $cmid, string $felderjson, string $ort = material_files::ORT_BESTAND): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'felder_json' => $felderjson,
            'ort' => $ort,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = self::authorise($cm);

        $modname = (string) $cm->modname;
        $catalogclass = self::catalog_for($modname);
        // Billigteil der Selbstfreigabe (Spec 0015 §11, ADR 0017, Ticket #399):
        // sperrt nur DIESE Aktivitaetsart, wenn ein erkannter Moodle-Versionswechsel
        // eine Katalogabweichung ergeben hat. Lesen bleibt unberuehrt (kein
        // Lese-Werkzeug ruft assert_writable() auf).
        write_gate::assert_writable($modname);

        [$patch, $before] = self::decode_and_validate_patch($modname, $catalogclass, $cmid, $params['felder_json']);

        $course = get_course((int) $cm->course);
        require_once($CFG->dirroot . '/course/modlib.php');
        self::apply_patch_to_module($cm, $course, $modname, $catalogclass, $context, $before, $patch, $params['ort']);

        $after = self::read_settings($cmid);
        [$changes, $sideeffects] = self::diff_and_side_effects($modname, $patch, $before, $after);

        return [
            'cmid' => (int) $cmid,
            'modname' => $modname,
            'meldung' => self::build_message($changes, $sideeffects, self::written_pseudofields($catalogclass, $patch)),
            'aenderungen' => $changes,
            'nebenwirkungen' => $sideeffects,
        ];
    }

    /**
     * Prueft Kontext und Capabilities (Issue #523: aus execute() ausgelagert,
     * um die Funktion unter der 50-Zeilen-Grenze zu halten).
     *
     * @param \stdClass $cm
     * @return \context_module
     */
    private static function authorise(\stdClass $cm): \context_module {
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native Berechtigungspruefung vorgezogen (Spec 0015 §3.3: "im Kurs
        // einer Kollegin: lesen ja, schreiben nein - mit klarer Meldung").
        // get_moduleinfo_data() prueft dieselbe Capability spaeter ohnehin
        // erneut ueber can_update_moduleinfo() - der Aufruf hier ist billig
        // (nur require_capability(), kein DB-Zugriff) und stellt sicher, dass
        // eine fehlende Bearbeiten-Berechtigung nicht hinter einer
        // Feldvalidierungsmeldung versteckt bleibt.
        require_capability('moodle/course:manageactivities', $context);

        return $context;
    }

    /**
     * Dekodiert felder_json und validiert den Patch (Issue #523: aus
     * execute() ausgelagert).
     *
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param int $cmid
     * @param string $felderjson
     * @return array{0: array, 1: array} [Patch, aktuelle Einstellungen vor dem Patch]
     */
    private static function decode_and_validate_patch(
        string $modname,
        string $catalogclass,
        int $cmid,
        string $felderjson
    ): array {
        $patch = json_decode($felderjson, true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        pseudofield_carry_forward::normalise_editor_pseudofields($catalogclass, $patch);
        // Issue #523: einmal gelesen und an execute() zurueckgegeben, statt
        // dort ein zweites Mal denselben Stand zu lesen (Review-Fund am
        // Extraktions-Schnitt: reiner Performance-/DRY-Fund, keine
        // Verhaltensaenderung).
        $before = self::read_settings($cmid);
        self::validate_patch($modname, $catalogclass, $before, $patch);

        return [$patch, $before];
    }

    /**
     * Wendet den Patch auf das native Formularweg-Objekt an und schreibt es
     * (Issue #523: aus execute() ausgelagert).
     *
     * @param \stdClass $cm
     * @param \stdClass $course
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param \context_module $context
     * @param array $before
     * @param array $patch
     * @param string $ort
     */
    private static function apply_patch_to_module(
        \stdClass $cm,
        \stdClass $course,
        string $modname,
        string $catalogclass,
        \context_module $context,
        array $before,
        array $patch,
        string $ort
    ): void {
        // get_moduleinfo_data() gibt das Tupel [cm, context, module, data, cw]
        // zurueck (course/modlib.php) - "data" (Positon 3) ist das
        // Formularweg-Feldobjekt, das ueberlagert und zurueckgeschrieben wird.
        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        pseudofield_carry_forward::apply($modname, $catalogclass, $moduleinfo, $before, $cm, $patch);
        self::resolve_material_reference_pseudofields($modname, $context, $patch, $ort);
        self::resolve_intro_image_pseudofield($modname, $context, $moduleinfo, $patch, $ort);
        foreach ($patch as $fieldname => $value) {
            $moduleinfo->{self::moduleinfo_property($fieldname)} = $value;
        }

        // Ein reiner "intro"-Patch wuerde sonst stillschweigend verpuffen -
        // siehe pseudofield_carry_forward::sync_intro_editor_from_patch().
        pseudofield_carry_forward::sync_intro_editor_from_patch($moduleinfo, $patch);

        \update_moduleinfo($cm, $moduleinfo, $course);
    }

    /**
     * Katalogfeldname => tatsaechlicher $moduleinfo-Eigenschaftsname -
     * identische Abbildung wie {@see create_module::moduleinfo_property()}.
     * Einzige Ausnahme "idnumber": get_moduleinfo_data() liefert das
     * Feldobjekt bereits mit der realen Formularweg-Eigenschaft
     * "cmidnumber" (course/modlib.php: `$data->cmidnumber = $cm->idnumber`),
     * update_moduleinfo() liest ebenso nur `$moduleinfo->cmidnumber`
     * (course/modlib.php:70) - ein Patch, der stattdessen "idnumber" auf das
     * Objekt schreibt, würde folgenlos verpuffen (das ungenutzte
     * "cmidnumber" bliebe unveraendert). "idnumber" bleibt trotzdem der
     * lehrkraftverstaendliche Katalogname (Spec 0015 §2.3, Ticket #390).
     *
     * @param string $fieldname
     * @return string
     */
    private static function moduleinfo_property(string $fieldname): string {
        return $fieldname === 'idnumber' ? 'cmidnumber' : $fieldname;
    }

    /**
     * Loest Materialordner-Verweis-Pseudofelder ({@see self::MATERIAL_REFERENCE_PSEUDOFIELDS})
     * im Patch zu Dateimanager-Entwurfs-Itemids auf, bevor sie auf
     * $moduleinfo landen - Spec 0018 §4.2: "Ab hier ist der Weg fuer alle
     * Herkuenfte derselbe: die Datei landet immer erst im Materialordner,
     * die Aktivitaet verweist darauf." Ohne Treffer keine Wirkung, kein
     * zusaetzlicher Dateizugriff.
     *
     * @param string $modname
     * @param \context_module $context Modulkontext - Ziel der Dateiablage.
     * @param array $patch Wird in-place ersetzt: Pfadliste -> Entwurfs-Itemid.
     * @param string $ort {@see material_files::ORT_BESTAND}/{@see material_files::ORT_WERKBANK} -
     *        Quelle der Pfade (Issue #496).
     * @return void
     * @throws moodle_exception materialfilenotfound / invalidmaterialpath / invalidmaterialort /
     *         materialpathiskontext / materialembedtoolarge
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    private static function resolve_material_reference_pseudofields(
        string $modname,
        \context_module $context,
        array &$patch,
        string $ort
    ): void {
        $catalogclass = registry::for($modname);
        $specs = $catalogclass::write_options()['material_reference_fields'] ?? [];
        $relevant = array_intersect_key($specs, $patch);
        if (!$relevant) {
            return;
        }

        material_files::require_manage_own_files();
        foreach ($relevant as $fieldname => $spec) {
            if (!is_array($patch[$fieldname])) {
                throw new moodle_exception('invalidmaterialreferencelist', 'local_coursepilot', '', $fieldname);
            }
            self::trash_files_about_to_be_replaced($context, $spec, $patch[$fieldname]);
            $patch[$fieldname] = material_files::resolve_into_draft(
                $context->id,
                $spec['component'],
                $spec['filearea'],
                0,
                $patch[$fieldname],
                $ort
            );
        }
    }

    /**
     * Loest ein {@see self::INTRO_IMAGE_PSEUDOFIELDS}-Pseudofeld auf (Spec
     * 0018 §4.2/§5, Issue #433): jeder Materialordner-Pfad muss zur engeren
     * Einbett-Whitelist gehoeren (§6) - eine andere Endung (z.B. ein PDF)
     * scheitert mit klarer Meldung statt still zu verpuffen. Ein bereits
     * unter demselben Dateinamen eingebettetes Bild wird wie bei
     * introattachments zuerst in den Papierkorb verdraengt (Spec 0018 §9.1,
     * {@see self::trash_files_about_to_be_replaced()}). Anders als
     * {@see self::resolve_material_reference_pseudofields()} landet das
     * Ergebnis nicht in $patch (introimages ist keine echte moduleinfo-
     * Eigenschaft), sondern direkt in $moduleinfo->introeditor['itemid'] -
     * update_moduleinfo() loest @@PLUGINFILE@@-Verweise im "intro"-Patch
     * (s.o.) gegen genau diesen Draft-Dateibereich auf.
     *
     * @param string $modname
     * @param \context_module $context
     * @param \stdClass $moduleinfo Wird in-place ergaenzt (introeditor-Itemid).
     * @param array $patch Wird in-place bereinigt: introimages entfernt.
     * @param string $ort {@see material_files::ORT_BESTAND}/{@see material_files::ORT_WERKBANK} -
     *        Quelle der Pfade (Issue #496).
     * @return void
     * @throws moodle_exception invalidmaterialreferencelist / materialfiledisallowedtype /
     *         materialfilenotfound / invalidmaterialpath / invalidmaterialort / materialpathiskontext /
     *         materialembedtoolarge
     */
    private static function resolve_intro_image_pseudofield(
        string $modname,
        \context_module $context,
        \stdClass $moduleinfo,
        array &$patch,
        string $ort
    ): void {
        $catalogclass = registry::for($modname);
        $fieldname = $catalogclass::write_options()['intro_image_field'] ?? null;
        if ($fieldname === null || !array_key_exists($fieldname, $patch)) {
            return;
        }

        $paths = $patch[$fieldname];
        if (!is_array($paths)) {
            throw new moodle_exception('invalidmaterialreferencelist', 'local_coursepilot', '', $fieldname);
        }

        // Capability zuerst pruefen, wie resolve_material_reference_pseudofields()
        // es fuer introattachments schon tut - sonst saehe ein Aufrufer ohne
        // moodle/user:manageownfiles die Dateityp-Meldung, bevor die
        // Berechtigung ueberhaupt geprueft wurde.
        material_files::require_manage_own_files();

        foreach ($paths as $path) {
            if (!is_string($path) || !material_files::is_allowed_embed_image_extension($path)) {
                // Dieselbe Meldung wie beim Upload (materialfiledisallowedtype) -
                // nur die Whitelist ist enger (Einbett- statt Upload-Whitelist, §6).
                throw new moodle_exception('materialfiledisallowedtype', 'local_coursepilot', '', (object) [
                    'filename' => (string) $path,
                    'allowed' => implode(', ', material_files::allowed_embed_image_extensions()),
                ]);
            }
        }

        $introspec = ['component' => 'mod_' . $modname, 'filearea' => 'intro'];
        self::trash_files_about_to_be_replaced($context, $introspec, $paths);
        $draftitemid = material_files::resolve_into_draft(
            $context->id, $introspec['component'], $introspec['filearea'], 0, $paths, $ort);
        if (!isset($moduleinfo->introeditor) || !is_array($moduleinfo->introeditor)) {
            $moduleinfo->introeditor = ['text' => $moduleinfo->intro ?? '', 'format' => $moduleinfo->introformat ?? FORMAT_HTML];
        }
        $moduleinfo->introeditor['itemid'] = $draftitemid;

        unset($patch[$fieldname]);
    }

    /**
     * Verdraengt jede derzeit angehaengte Datei, deren Dateiname unter den
     * neu referenzierten Materialordner-Pfaden erneut vorkommt, in den
     * Papierkorb ({@see activity_file_trash}) - BEVOR update_moduleinfo()
     * lauft und Moodle-Core den alten `files`-Datensatz tief in
     * file_save_draft_area_files() loescht (Spec 0018 §9.1, Issue #432).
     * Ohne Namenskollision keine Wirkung: reines Hinzufuegen bleibt
     * kostenlos.
     *
     * @param \context_module $context
     * @param array{component: string, filearea: string} $spec
     * @param array $paths Materialordner-Pfade aus dem Patch - Strings oder
     *        `['pfad' => ..., 'zielordner' => ...]`-Objekte (Issue #434).
     * @return void
     */
    private static function trash_files_about_to_be_replaced(\context_module $context, array $spec, array $paths): void {
        $newfilenames = array_map(
            static fn($entry): string => basename(material_files::entry_path($entry)),
            $paths
        );
        $existing = get_file_storage()->get_area_files(
            $context->id,
            $spec['component'],
            $spec['filearea'],
            0,
            'filename',
            false
        );
        foreach ($existing as $file) {
            if (in_array($file->get_filename(), $newfilenames, true)) {
                activity_file_trash::trash($file, $context->instanceid);
            }
        }
    }

    /**
     * Die Katalogklasse fuer $modname, sofern der Schreibweg dieser Endpunkt
     * ist (Spec 0015 §3.1: manche Aktivitaetsarten haben ein eigenes
     * Einzelwerkzeug, z.B. quiz -> update_quiz_settings).
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
     * Ist-Stand als assoziatives Array - dieselbe Zusammenstellung wie
     * {@see get_module_settings}, ueber deren settings_json wiederverwendet
     * statt dupliziert (Ticket #384: "gleiche Bauform fuer Read-Teil
     * wiederverwendbar").
     *
     * @param int $cmid
     * @return array
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * Alles-oder-nichts-Pruefung VOR dem Schreiben: unbekanntes Feld,
     * gesperrtes Feld, unerlaubter Wert, verletzte Kombinationsregel.
     *
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param array $before Ist-Stand vor dem Patch (fuer Kombinationsregeln).
     * @param array $patch
     * @return void
     * @throws moodle_exception blockedfield|unknownfield|invalidfieldvalue|combinationruleviolation|stealthnotallowed
     */
    private static function validate_patch(string $modname, string $catalogclass, array $before, array $patch): void {
        $blocklist = array_unique(array_merge(shared_block::BLOCKLIST, $catalogclass::blocklist()));

        $settablefields = array_merge(
            shared_block::fields(),
            $catalogclass::fields(),
            $catalogclass::pseudofields()
        );
        $fieldsbyname = [];
        foreach ($settablefields as $settablefield) {
            $fieldsbyname[$settablefield->name] = $settablefield;
        }

        foreach ($patch as $fieldname => $value) {
            self::validate_patch_field($modname, $fieldname, $value, $blocklist, $fieldsbyname);
        }

        self::validate_combination_rules($modname, $before, $patch);
        self::assert_stealth_allowed($patch);
    }

    /**
     * Prueft ein einzelnes Patch-Feld (Issue #523: aus validate_patch()
     * ausgelagert, um die Funktion unter der 50-Zeilen-Grenze zu halten).
     *
     * @param string $modname
     * @param mixed $fieldname
     * @param mixed $value
     * @param string[] $blocklist
     * @param array $fieldsbyname
     */
    private static function validate_patch_field(
        string $modname,
        $fieldname,
        $value,
        array $blocklist,
        array $fieldsbyname
    ): void {
        field::assert_name($fieldname);
        if (in_array($fieldname, $blocklist, true)) {
            // Vervollstaendigungsfelder zuerst: sie sind nicht nur
            // gesperrt, sie haben einen Weg (Ticket #461).
            shared_block::assert_not_completion_field($fieldname);
            throw new moodle_exception('blockedfield', 'local_coursepilot', '', ['field' => $fieldname, 'modname' => $modname]);
        }
        $catalogclass = registry::for($modname);
        if (in_array($fieldname, $catalogclass::write_options()['patch_blocked_fields'] ?? [], true)) {
            throw new moodle_exception('folderfilespatchunsupported', 'local_coursepilot');
        }
        shared_block::assert_not_read_only_vocabulary($fieldname, $modname);
        if (!array_key_exists($fieldname, $fieldsbyname)) {
            throw new moodle_exception('unknownfield', 'local_coursepilot', '', ['field' => $fieldname, 'modname' => $modname]);
        }

        $field = $fieldsbyname[$fieldname];
        if ($field->values !== null && !in_array($value, $field->values, false)) {
            throw new moodle_exception(
                'invalidfieldvalue',
                'local_coursepilot',
                '',
                ['field' => $fieldname, 'modname' => $modname, 'value' => json_encode($value)]
            );
        }
    }

    /**
     * Stealth (Spec 0015 §7, Ticket #390) setzt voraus, dass die Instanz
     * "allowstealth" erlaubt - sonst ignoriert Moodles eigener Formularweg
     * visibleoncoursepage=0 kommentarlos (course/modlib.php:
     * set_moduleinfo_defaults() faellt auf 1 zurueck), der Schreibvorgang
     * wuerde also still wirkungslos bleiben statt zu scheitern. Nur der
     * Zielwert 0 ist betroffen - visibleoncoursepage=1 (zurueck auf normal)
     * bleibt immer erlaubt.
     *
     * @param array $patch
     * @return void
     * @throws moodle_exception stealthnotallowed
     */
    private static function assert_stealth_allowed(array $patch): void {
        if (($patch['visibleoncoursepage'] ?? null) !== 0) {
            return;
        }
        if (get_config(null, 'allowstealth')) {
            return;
        }
        throw new moodle_exception('stealthnotallowed', 'local_coursepilot');
    }

    /**
     * @param string $modname
     * @param array $before
     * @param array $patch
     * @return void
     * @throws moodle_exception combinationruleviolation
     */
    private static function validate_combination_rules(string $modname, array $before, array $patch): void {
        $catalogclass = registry::for($modname);
        $rules = $catalogclass::write_options()['date_order_rules'] ?? [];
        if (!$rules) {
            return;
        }

        $merged = array_merge($before, $patch);
        foreach ($rules as $rule) {
            // Nur pruefen, wenn der Patch tatsaechlich eines der beiden
            // Felder beruehrt - unveraendert bleibende, bereits vorhandene
            // Altdaten werden durch einen unabhaengigen Patch nicht neu
            // bewertet.
            if (!array_key_exists($rule['reference'], $patch) && !array_key_exists($rule['field'], $patch)) {
                continue;
            }
            $reference = (int) ($merged[$rule['reference']] ?? 0);
            $value = (int) ($merged[$rule['field']] ?? 0);
            if ($reference === 0 || $value === 0) {
                continue;
            }

            $violated = $rule['mode'] === 'must_be_after' ? ($value <= $reference) : ($value < $reference);
            if ($violated) {
                throw new moodle_exception(
                    'combinationruleviolation',
                    'local_coursepilot',
                    '',
                    ['modname' => $modname, 'message' => self::rule_violation_message($rule)]
                );
            }
        }
    }

    /**
     * Generischer Verstoss-Text aus reference/field/mode statt eines
     * separat gepflegten Zitats der Katalogtexte (DRY, siehe
     * {@see self::DATE_ORDER_RULES}).
     *
     * @param array{reference: string, field: string, mode: string} $rule
     * @return string
     */
    private static function rule_violation_message(array $rule): string {
        return $rule['mode'] === 'must_be_after'
            ? '"' . $rule['field'] . '" muss nach "' . $rule['reference'] . '" liegen.'
            : '"' . $rule['field'] . '" darf nicht vor "' . $rule['reference'] . '" liegen.';
    }

    /**
     * Vorher-/Nachher-Werte je tatsaechlich geaendertem Feld, plus
     * ausgeloeste Nebenwirkungen - aus einem echten Vorher-/Nachher-Vergleich
     * (nicht aus dem Patch selbst uebernommen), damit eine parallele
     * Handaenderung an einem anderen Feld korrekt unerwaehnt bleibt und ein
     * Patch, der den bestehenden Wert nur wiederholt, nicht als Aenderung
     * gemeldet wird.
     *
     * @param string $modname
     * @param array $patch
     * @param array $before
     * @param array $after
     * @return array{0: array, 1: string[]}
     */
    private static function diff_and_side_effects(string $modname, array $patch, array $before, array $after): array {
        $changes = [];
        $sideeffects = [];
        $catalogclass = registry::for($modname);
        $triggers = $catalogclass::write_options()['side_effect_triggers'] ?? [];

        foreach (array_keys($patch) as $fieldname) {
            $oldvalue = $before[$fieldname] ?? null;
            $newvalue = $after[$fieldname] ?? null;
            if ($oldvalue != $newvalue) {
                $changes[] = [
                    'feld' => $fieldname,
                    'von_json' => json_encode($oldvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'auf_json' => json_encode($newvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }

            if (isset($triggers[$fieldname][$newvalue]) && $oldvalue != $newvalue) {
                $sideeffects[] = $triggers[$fieldname][$newvalue];
            }
        }

        return [$changes, $sideeffects];
    }

    /**
     * Die Pseudofelder aus dem Patch - die, die der Vorher/Nachher-Vergleich
     * grundsaetzlich nicht sehen kann (#403).
     *
     * Pseudofelder haben per Definition keine Spalte in der Instanztabelle
     * ("assignsubmission_file_enabled" steht in assign_plugin_config, die
     * choice-Optionen in choice_options). read_settings() liest den Ist-Stand
     * der Datenbankfelder, dort stehen sie vorher wie nachher als null - der
     * Diff bleibt leer, obwohl geschrieben wurde. Ein echter Vergleich
     * braeuchte eine Leseschicht je Aktivitaetsart; stattdessen sagt die
     * Meldung ausdruecklich, was sie nicht vergleichen kann.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array $patch
     * @return array<string, mixed> Feldname => gesetzter Wert.
     */
    private static function written_pseudofields(string $catalogclass, array $patch): array {
        $names = array_column($catalogclass::pseudofields(), 'name');
        return array_intersect_key($patch, array_flip($names));
    }

    /**
     * Die Lehrkraft-deutsche Aenderungsmeldung (Spec 0015 §3.3: "die Antwort
     * ist die Aenderungsmeldung").
     *
     * @param array $changes
     * @param string[] $sideeffects
     * @param array<string, mixed> $pseudofields Geschriebene Pseudofelder, siehe
     *        {@see self::written_pseudofields()} - nicht vergleichbar, aber gesetzt.
     * @return string
     */
    private static function build_message(array $changes, array $sideeffects, array $pseudofields = []): string {
        if (!$changes && !$pseudofields) {
            return 'Keine Aenderung: der Patch stimmte bereits mit dem aktuellen Stand ueberein.';
        }

        $parts = [];
        foreach ($changes as $change) {
            $parts[] = '"' . $change['feld'] . '" von ' . $change['von_json'] . ' auf ' . $change['auf_json'];
        }
        $message = $parts ? ('Geaendert: ' . implode(', ', $parts) . '.') : '';

        if ($pseudofields) {
            $set = [];
            foreach ($pseudofields as $fieldname => $value) {
                $set[] = '"' . $fieldname . '" = '
                    . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $message .= ($message ? ' ' : '')
                . 'Gesetzt, aber ohne Datenbankfeld und deshalb nicht mit dem Vorher-Stand vergleichbar: '
                . implode(', ', $set) . '.';
        }

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
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Aktivitaetstyp'),
            'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Aenderungsmeldung'),
            'aenderungen' => new external_multiple_structure(
                new external_single_structure([
                    'feld' => new external_value(PARAM_TEXT, 'Feldname'),
                    'von_json' => new external_value(PARAM_RAW, 'JSON-kodierter Wert vor dem Schreiben'),
                    'auf_json' => new external_value(PARAM_RAW, 'JSON-kodierter Wert nach dem Schreiben'),
                ]),
                'Je tatsaechlich geaendertem Feld ein Eintrag'
            ),
            'nebenwirkungen' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Nebenwirkungsvermerk in Lehrkraft-Deutsch'),
                'Ausgeloeste Nebenwirkungen aus Katalogkategorie 5, leer wenn keine ausgeloest wurden'
            ),
        ]);
    }
}
