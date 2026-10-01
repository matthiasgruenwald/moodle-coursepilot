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

use context_module;

/**
 * Gemeinsame Vorbereitung des $moduleinfo-Feldobjekts fuer JEDEN Aufrufer von
 * update_moduleinfo() ausserhalb des echten Formularwegs (Ticket #388: erst
 * update_module_settings, Ticket #392: auch set_completion) - ohne diese
 * Ergaenzungen lesen etliche *_update_instance()-Funktionen eine undefinierte
 * Eigenschaft (PHP-Warning bis -Error, siehe {@see \local_coursepilot\catalog\page}-
 * Kommentar "unbedingtes Lesemuster"), weil get_moduleinfo_data() sie NICHT
 * liefert - das tut sonst ausschliesslich moodleform_mod::data_preprocessing(),
 * das hier nie laeuft.
 *
 * Extrahiert aus update_module_settings (#388), damit set_completion (#392)
 * dieselbe Vorbereitung nutzt, statt sie fuer jeden Modultyp zu wiederholen -
 * set_completion setzt selbst nur Vervollstaendigungsfelder, ruft aber
 * denselben update_moduleinfo()-Weg auf und braucht deshalb exakt dieselbe
 * Vorbereitung wie ein Patch, der gar kein Pseudofeld nennt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pseudofield_carry_forward {

    /**
     * Fuehrt alle sechs Ergaenzungen fuer $modname aus.
     *
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param array $before Ist-Stand vor dem Schreiben (fuer Editor-Pseudofelder).
     * @param \stdClass $cm
     * @param array $patch Vom Aufrufer selbst gesetzte Felder - hier nicht ueberschrieben.
     * @return void
     */
    public static function apply(
        string $modname,
        string $catalogclass,
        \stdClass $moduleinfo,
        array $before,
        \stdClass $cm,
        array $patch
    ): void {
        self::fill_pseudofield_defaults($catalogclass, $moduleinfo, $patch);
        self::prepare_editor_content_pseudofields($modname, $catalogclass, $moduleinfo, $before, $cm, $patch);
        self::carry_forward_draft_file_pseudofield($modname, $moduleinfo, $patch);
        self::carry_forward_choice_options($modname, $moduleinfo, $cm, $patch);
        self::carry_forward_assign_plugin_config($modname, $moduleinfo, $cm, $patch);
        self::unformat_localised_gradepass($moduleinfo);
    }

    /**
     * Bringt Editor-Array-Pseudofelder im Patch in die Form, die Moodle
     * erwartet - oder weist sie ab (#405).
     *
     * Der Katalogtyp dieser Felder ist "array{text: string, format: int,
     * itemid: int}", weil page_update_instance() genau das liest:
     * `$data->content = $data->page['text'];`. Ein Aufrufer schreibt aber
     * naheliegend den Inhalt direkt, also "page": "<p>Text</p>". Auf einem
     * String liefert $data->page['text'] null - und die Seite entsteht leer,
     * ohne Fehlermeldung. Genau so passiert in der Claude-Gegenprobe zu #400:
     * Aktivitaet angelegt, Erfolgsmeldung, Inhalt weg.
     *
     * Deshalb: ein String ist eine gueltige Kurzform und wird zum Editor-Array
     * ergaenzt. Alles andere, was kein "text" traegt, scheitert mit einer
     * Meldung, die das Feld nennt - lieber ein klarer Fehler als eine leere
     * Seite.
     *
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @param array $patch Wird in-place normalisiert.
     * @return void
     * @throws \moodle_exception invalideditorpseudofield
     */
    public static function normalise_editor_pseudofields(string $catalogclass, array &$patch): void {
        foreach ($catalogclass::pseudofields() as $pseudofield) {
            if (!str_starts_with($pseudofield->type, 'array{text:')) {
                continue;
            }
            if (!array_key_exists($pseudofield->name, $patch)) {
                continue;
            }
            $value = $patch[$pseudofield->name];
            if (is_string($value)) {
                $patch[$pseudofield->name] = ['text' => $value, 'format' => FORMAT_HTML, 'itemid' => 0];
                continue;
            }
            if (is_array($value) && array_key_exists('text', $value)) {
                $patch[$pseudofield->name] = $value + ['format' => FORMAT_HTML, 'itemid' => 0];
                continue;
            }
            throw new \moodle_exception('invalideditorpseudofield', 'local_coursepilot', '', [
                'field' => $pseudofield->name,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    /**
     * Traegt die aktuell gespeicherten Abgabe-/Feedback-Einstellungen einer
     * Aufgabe weiter (#400).
     *
     * mod_assign leitet "nosubmissions" bei JEDEM Schreibvorgang neu aus den
     * aktivierten Abgabearten ab (mod/assign/locallib.php:1629), und aktiviert
     * ist eine Abgabeart nur, wenn $formdata das Feld
     * "{subtype}_{plugin}_enabled" traegt (ebd. :1359-1373). Diese Felder
     * kommen sonst ausschliesslich aus dem Formular - get_moduleinfo_data()
     * liefert sie nicht, sie stehen in assign_plugin_config. Ein Schreibvorgang,
     * der sie nicht mitbringt (z.B. set_completion, das nur
     * Vervollstaendigungsfelder setzt), schaltet deshalb still saemtliche
     * Abgabearten ab und setzt "nosubmissions"=1 - die Aufgabe nimmt danach
     * gar keine Abgaben mehr an.
     *
     * Der Formular-Default taugt hier nicht als Ersatz (die katalogisierten
     * Pseudofelder haben bewusst keinen, er ist admin-konfigurierbar): weiter
     * gilt der Ist-Stand, wie bei den choice-Optionen.
     *
     * @param string $modname
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param \stdClass $cm
     * @param array $patch
     * @return void
     */
    private static function carry_forward_assign_plugin_config(
        string $modname,
        \stdClass $moduleinfo,
        \stdClass $cm,
        array $patch
    ): void {
        global $DB;

        if ($modname !== 'assign') {
            return;
        }
        $rows = $DB->get_records('assign_plugin_config', ['assignment' => $cm->instance]);
        foreach ($rows as $row) {
            $fieldname = $row->subtype . '_' . $row->plugin . '_' . $row->name;
            if (array_key_exists($fieldname, $patch) || property_exists($moduleinfo, $fieldname)) {
                continue;
            }
            $moduleinfo->{$fieldname} = $row->value;
        }
    }

    /**
     * Macht die Anzeigeformatierung der Bestehensgrenze rueckgaengig (#400).
     *
     * course/modlib.php::get_moduleinfo_data() gibt "gradepass" durch
     * format_float() (ebd. :863) - in einer deutschsprachigen Instanz also als
     * "0,00". Zurueckgeschrieben wird der Wert unveraendert in die
     * Bewertungsspalte (ebd. :294), wo er als Dezimalzahl ankommen muss:
     * MariaDB bricht mit "Data truncated for column 'gradepass'" ab, der
     * Aufruf endet in "Fehler beim Schreiben der Datenbank" - nachdem die
     * eigentliche Aenderung bereits geschrieben ist. Auf dem echten
     * Formularweg nimmt das float-Element die Formatierung beim Absenden
     * zurueck; ausserhalb muss das hier passieren.
     *
     * Feldname je nach Aktivitaetsart verschieden (workshop:
     * "submissiongradepass"/"assessmentgradepass", siehe
     * component_gradeitems::get_field_name_for_itemnumber()), deshalb ueber
     * die Endung statt ueber eine feste Liste.
     *
     * Oeffentlich, weil update_quiz_settings einen eigenen Carry-forward-Weg
     * geht (quiz hat eigenes Schreibvehikel, Spec 0015 §5), aber dieselbe
     * get_moduleinfo_data()-Grundlage und damit dasselbe Problem hat.
     *
     * @param \stdClass $moduleinfo Wird in-place korrigiert.
     * @return void
     */
    public static function unformat_localised_gradepass(\stdClass $moduleinfo): void {
        foreach (get_object_vars($moduleinfo) as $name => $value) {
            if (is_string($value) && $value !== '' && str_ends_with($name, 'gradepass')) {
                $moduleinfo->{$name} = unformat_float($value);
            }
        }
    }

    /**
     * update_moduleinfo() (course/modlib.php:675-680) ueberschreibt
     * $moduleinfo->intro IMMER aus $moduleinfo->introeditor['text'], egal was
     * ein Aufrufer direkt auf ->intro gesetzt hat - ein reiner "intro"-Patch
     * wuerde sonst stillschweigend verpuffen. get_moduleinfo_data() hat
     * ->introeditor bereits mit dem Ist-Stand vorbelegt (Draftitemid
     * eingeschlossen); hier wird nur der Patch-Wert nachgezogen, ohne das
     * Itemid anzufassen - das bleibt Sache des Aufrufers (z.B.
     * {@see \local_coursepilot\external\update_module_settings::resolve_intro_image_pseudofield()}
     * fuer eingebettete Fachabbildungen, Issue #433).
     *
     * Gemeinsam fuer update_module_settings (alle Aktivitaetsarten mit
     * FEATURE_MOD_INTRO) und update_quiz_settings (eigenes Schreibvehikel,
     * derselbe Fallstrick) - vorher an beiden Stellen einzeln nachgebaut.
     *
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param array $patch
     * @return void
     */
    public static function sync_intro_editor_from_patch(\stdClass $moduleinfo, array $patch): void {
        if (!isset($moduleinfo->introeditor) || !is_array($moduleinfo->introeditor)) {
            return;
        }
        if (array_key_exists('intro', $patch)) {
            $moduleinfo->introeditor['text'] = (string) $patch['intro'];
        }
        if (array_key_exists('introformat', $patch)) {
            $moduleinfo->introeditor['format'] = (int) $patch['introformat'];
        }
    }

    /**
     * Fuellt Pseudofelder, die $moduleinfo (aus get_moduleinfo_data(), ohne
     * den Formularweg) unbekannt sind, mit ihrem katalogisierten
     * Formular-Default auf - genau das, was moodleform_mod beim Laden des
     * Formulars ohnehin taete. Ohne das entstehen fuer jedes optionale
     * Pseudofeld, das der Patch nicht erwaehnt (z.B. mod_page:
     * "printintro"), "Undefined property"-Warnungen und ein stiller
     * Reset auf null statt auf den dokumentierten Default.
     *
     * Pseudofelder mit Default null (durchweg die Editor-Arrays) bleiben hier aussen vor -
     * ein Nullwert waere kein sinnvoller Ersatz.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param array $patch
     * @return void
     */
    private static function fill_pseudofield_defaults(string $catalogclass, \stdClass $moduleinfo, array $patch): void {
        foreach ($catalogclass::pseudofields() as $pseudofield) {
            if (array_key_exists($pseudofield->name, $patch) || property_exists($moduleinfo, $pseudofield->name)) {
                continue;
            }
            if ($pseudofield->default !== null) {
                $moduleinfo->{$pseudofield->name} = $pseudofield->default;
            }
        }
    }

    /**
     * Rekonstruiert die Editor-Arrays aus dem flachen Katalogvertrag. Moodle
     * schreibt deren Inhalt aus dem Editor-Array, nicht aus den Instanzspalten.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param array $before
     * @param array $patch
     * @return void
     */
    private static function prepare_editor_content_pseudofields(
        string $modname,
        string $catalogclass,
        \stdClass $moduleinfo,
        array $before,
        \stdClass $cm,
        array $patch
    ): void {
        global $CFG;

        $editors = $catalogclass::write_options()['editor_content'] ?? [];
        if (!$editors) {
            return;
        }
        require_once($CFG->dirroot . '/mod/' . $modname . '/lib.php');
        $locallib = $CFG->dirroot . '/mod/' . $modname . '/locallib.php';
        if (is_file($locallib)) {
            require_once($locallib);
        }
        foreach ($editors as $pseudofield => $columns) {
            if (array_key_exists($pseudofield, $patch)) {
                continue;
            }
            [$content, $format] = $columns;
            $draftitemid = file_get_unused_draft_itemid();
            $text = file_prepare_draft_area(
                $draftitemid,
                context_module::instance($cm->id)->id,
                'mod_' . $modname,
                $content,
                0,
                [],
                (string) ($patch[$content] ?? $before[$content] ?? '')
            );
            $moduleinfo->{$pseudofield} = [
                'text' => $text,
                'format' => (int) ($patch[$format] ?? $before[$format] ?? FORMAT_HTML),
                'itemid' => $draftitemid,
            ];
        }
    }

    /**
     * "files" (folder, resource) hat keinen Katalog-Default (null), bleibt
     * also nach {@see self::fill_pseudofield_defaults()} auf dem Feldobjekt
     * unbelegt, wenn ein Patch das Feld nicht selbst nennt (Issue #434: nicht
     * mehr Blocklist-bedingt, sondern schlicht "nicht im Patch genannt").
     * Ohne diese Ergaenzung liest folder_update_instance()/resource_set_mainfile()
     * eine undefinierte Eigenschaft (PHP-Warning) - der abgelesene Wert wird
     * danach ohnehin ignoriert oder durch file_get_submitted_draft_itemid()
     * ueberschrieben (kein Formularkontext hier), ein neutraler Platzhalter
     * aendert also nichts an bestehenden Dateien, silenced nur die Warnung.
     *
     * @param string $modname
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param array $patch
     * @return void
     */
    private static function carry_forward_draft_file_pseudofield(string $modname, \stdClass $moduleinfo, array $patch): void {
        if (!in_array($modname, ['folder', 'resource'], true) || array_key_exists('files', $patch)) {
            return;
        }
        if (!property_exists($moduleinfo, 'files')) {
            $moduleinfo->files = 0;
        }
    }

    /**
     * "option"/"limit"/"optionid" (choice) leben in choice_options, nicht in
     * der choice-Instanzzeile - get_moduleinfo_data() liefert sie deshalb
     * nicht (anders als bei "page", das ueber $before rekonstruierbar waere).
     * Ohne diese Ergaenzung liest choice_update_instance() eine undefinierte
     * Eigenschaft "option" (PHP-Warning) und die foreach-Schleife laeuft ins
     * Leere - bestehende Optionen bleiben zwar unangetastet (die Schleife tut
     * nichts), aber ein Schreibvorgang, der "option" gar nicht nennt, soll
     * sauber durchlaufen, nicht mit Warnings. Rekonstruktion identisch zu
     * mod_choice_mod_form::data_preprocessing() (mod/choice/mod_form.php).
     *
     * @param string $modname
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param \stdClass $cm
     * @param array $patch
     * @return void
     */
    private static function carry_forward_choice_options(string $modname, \stdClass $moduleinfo, \stdClass $cm, array $patch): void {
        global $DB;

        if ($modname !== 'choice' || array_key_exists('option', $patch)) {
            return;
        }
        $texts = $DB->get_records_menu('choice_options', ['choiceid' => $cm->instance], 'id', 'id,text');
        $limits = $DB->get_records_menu('choice_options', ['choiceid' => $cm->instance], 'id', 'id,maxanswers');
        if (!$texts) {
            return;
        }
        $ids = array_keys($texts);
        $moduleinfo->option = array_values($texts);
        $moduleinfo->limit = array_values($limits);
        $moduleinfo->optionid = $ids;
    }
}
