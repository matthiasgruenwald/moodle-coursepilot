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

/**
 * Feldkatalog fuer mod_resource (Spec 0015 §4.1/§4.3, Spec 0018 §4/§7:
 * Hauptdatei ist Pflicht, kommt als Materialordner-Verweis im selben
 * `create_module`-Aufruf mit).
 *
 * Fallstricke aus dem Bestand (Ticket #380, Issue #434):
 * - "files" (Dateimanager-Draft-Itemid) ist ein Pseudofeld, vollstaendig
 *   katalogisiert und PFLICHT: der Wert ist eine Liste von
 *   Materialordner-Pfaden (Spec 0018 §4.2, z.B. `["arbeitsblatt.pdf"]`),
 *   die {@see \local_coursepilot\external\create_module} bzw.
 *   {@see \local_coursepilot\external\update_module_settings} vor dem
 *   eigentlichen Schreiben zu einem Dateimanager-Entwurf aufloesen
 *   ({@see \local_coursepilot\material_files::resolve_into_draft()}).
 * - Anders als bei folder erzeugt resource OHNE Hauptdatei eine kaputte
 *   Aktivitaetsseite (mod/resource/view.php:69-71: `resource_print_filenotfound()`
 *   wenn keine Datei vorhanden ist) - deshalb `required=true` ohne Default:
 *   `create_module` scheitert ohne "files" mit einer Pflichtfeld-Meldung,
 *   BEVOR die Aktivitaet angelegt wird (kein leerer Zwischenstand).
 * - "displayoptions" wird von resource_set_display_options() unmittelbar aus
 *   display/popupwidth/popupheight/printintro/showsize/showtype/showdate
 *   nachgerechnet und steht auf der Sperrliste.
 * - "revision" wird beim Update selbst hochgezaehlt (resource_update_instance():
 *   `$data->revision++;`) und steht auf der Sperrliste.
 * - "tobemigrated"/"legacyfiles"/"legacyfileslast" sind Migrations-Buchhaltung
 *   aus Moodle 1.9-Restores, kein Lehrkraft-Feld - gesperrt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class resource implements module_catalog {

    public static function modname(): string {
        return 'resource';
    }

    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Anzeigename der Datei-Aktivitaet.',
                true,
                null,
                null,
                null,
                'mod/resource/mod_form.php:51-54 (PARAM_TEXT bzw. PARAM_CLEANHTML je nach $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Beschreibungstext (Intro), optional oberhalb der Datei eingeblendet (Pseudofeld "printintro" '
                    . 'steuert ob, nur bei bestimmten display-Werten wirksam).',
                false,
                null,
                null,
                null,
                'mod/resource/db/install.xml (resource.intro, NOTNULL=false)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Textformat des Intros.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/resource/db/install.xml (resource.introformat)'
            ),
            new field(
                'display',
                'PARAM_INT',
                'Darstellung der Datei (z.B. automatisch, eingebettet, neues Fenster, Popup, direkt '
                    . 'oeffnen/herunterladen). Die tatsaechlich waehlbaren Optionen sind eine von der '
                    . 'Moodle-Administration konfigurierte Teilmenge, keine feste Liste.',
                false,
                0,
                null,
                'resourcelib_get_displayoptions()',
                'lib/resourcelib.php:30-42 (RESOURCELIB_DISPLAY_*-Konstanten), :111 (resourcelib_get_displayoptions()); '
                    . 'Teilmenge aus admin_setting_configmultiselect(\'resource/displayoptions\', ...) in '
                    . 'mod/resource/settings.php:28-42; Spalte mod/resource/db/install.xml (resource.display)'
            ),
            new field(
                'filterfiles',
                'PARAM_INT',
                'Textfilter auf den Dateiinhalt anwenden: kein Filter (0), alle Dateien (1) oder nur HTML-Dateien '
                    . '(2).',
                false,
                0,
                [0, 1, 2],
                null,
                'mod/resource/mod_form.php:132-134 (Optionsliste none/allfiles/htmlfilesonly); Spalte '
                    . 'mod/resource/db/install.xml (resource.filterfiles)'
            ),
        ];
    }

    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::for_modname(self::modname(), $instanceid, $cmid, $fullcontent);
    }

    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    public static function pseudofields(): array {
        return [
            new field(
                'files',
                'Liste von Materialordner-Pfaden (JSON-Array)',
                'Die anzuhaengende(n) Datei(en), darunter die Hauptdatei - je Eintrag ein Pfad in den Materialordner '
                    . '(Spec 0018 §4.2, z.B. ["arbeitsblatt.pdf"]), die zuerst per upload_material_file dorthin '
                    . 'gelegt werden muss. PFLICHTFELD: OHNE Hauptdatei erzeugt resource eine kaputte '
                    . 'Aktivitaetsseite - anders als bei folder ist ein leerer Zustand hier ungueltig.',
                true,
                null,
                null,
                null,
                'mod/resource/locallib.php: resource_set_mainfile(); mod/resource/view.php:69-71 '
                    . '(resource_print_filenotfound() ohne Datei); local_coursepilot\material_files::resolve_into_draft()'
            ),
            new field(
                'printintro',
                'PARAM_BOOL',
                'Intro zusaetzlich anzeigen - nur wirksam bei display=0 (Auto), 1 (Embed) oder 2 (Frame). Kein '
                    . 'DB-Feld - fliesst in die serialisierte "displayoptions"-Spalte ein.',
                false,
                0,
                [0, 1],
                null,
                'mod/resource/lib.php (resource_set_display_options(): nur gesetzt, wenn display in '
                    . '[AUTO, EMBED, FRAME])'
            ),
            new field(
                'popupwidth',
                'PARAM_INT',
                'Fensterbreite in Pixeln, nur wirksam bei display=6 (Popup). Kein DB-Feld - fliesst in '
                    . '"displayoptions" ein.',
                false,
                620,
                null,
                null,
                'mod/resource/lib.php (resource_set_display_options(): nur gesetzt, wenn '
                    . 'display==RESOURCELIB_DISPLAY_POPUP)'
            ),
            new field(
                'popupheight',
                'PARAM_INT',
                'Fensterhoehe in Pixeln, nur wirksam bei display=6 (Popup). Kein DB-Feld - fliesst in '
                    . '"displayoptions" ein.',
                false,
                450,
                null,
                null,
                'mod/resource/lib.php (resource_set_display_options(): nur gesetzt, wenn '
                    . 'display==RESOURCELIB_DISPLAY_POPUP)'
            ),
            new field(
                'showsize',
                'PARAM_BOOL',
                'Dateigroesse anzeigen. Kein DB-Feld - fliesst in "displayoptions" ein.',
                false,
                0,
                [0, 1],
                null,
                'mod/resource/lib.php (resource_set_display_options(): `$displayoptions[\'showsize\']`)'
            ),
            new field(
                'showtype',
                'PARAM_BOOL',
                'Dateityp anzeigen. Kein DB-Feld - fliesst in "displayoptions" ein.',
                false,
                0,
                [0, 1],
                null,
                'mod/resource/lib.php (resource_set_display_options(): `$displayoptions[\'showtype\']`)'
            ),
            new field(
                'showdate',
                'PARAM_BOOL',
                'Erstellungs-/Aenderungsdatum der Datei anzeigen. Kein DB-Feld - fliesst in "displayoptions" ein.',
                false,
                0,
                [0, 1],
                null,
                'mod/resource/lib.php (resource_set_display_options(): `$displayoptions[\'showdate\']`)'
            ),
        ];
    }

    public static function blocklist(): array {
        return [
            'revision',
            'displayoptions',
            'tobemigrated',
            'legacyfiles',
            'legacyfileslast',
        ];
    }

    public static function combination_rules(): array {
        return [];
    }

    public static function side_effects(): array {
        return [
            'resource verlangt beim Anlegen das Pflichtfeld "files" (Liste von Materialordner-Pfaden, Spec 0018 '
                . '§4.2): ohne Hauptdatei entsteht eine kaputte Aktivitaetsseite (Spec 0015 §4.3), create_module '
                . 'scheitert deshalb ohne "files" bevor irgendetwas angelegt wird.',
        ];
    }

    public static function bundles(): array {
        return [];
    }

    public static function schreibweg(): ?string {
        return null;
    }

    public static function checked_constants(): array {
        return [];
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
