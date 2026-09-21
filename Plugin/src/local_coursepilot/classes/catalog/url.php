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
 * Feldkatalog fuer mod_url (Spec 0015 §4.1/§4.4, gelb: benannte
 * Sonderbehandlung).
 *
 * Fallstricke aus dem Bestand (Ticket #380):
 * - "externalurl" ist bewusst KEIN PARAM_URL: `clean_param()` verwirft bei
 *   jeder Syntaxabweichung still zu '' (lib/classes/param.php:1039-1052) -
 *   strenger als Moodles eigenes Formular, das serverrelative Links und
 *   "mailto:" akzeptiert. Der Bestand (#357, ae58d76) nimmt PARAM_RAW_TRIMMED
 *   plus eine explizite Pruefung gegen url_appears_valid_url() - der Katalog
 *   uebernimmt genau das als Wertebereich.
 * - "displayoptions" und "parameters" werden von url_add_instance()/
 *   url_update_instance() unmittelbar aus anderen Feldern nachgerechnet und
 *   stehen deshalb auf der Sperrliste.
 * - "popupwidth"/"popupheight" sind Pseudofelder, nur bei display=6 (Popup)
 *   wirksam.
 * - "parameter_N"/"variable_N" (N=0..99) sind Pseudofelder: ohne sie beim
 *   Update loescht url_update_instance() saemtliche bestehenden
 *   URL-Parameter, weil parameters() jedes Mal aus $data->parameter_N/
 *   $data->variable_N komplett neu aufgebaut wird (Spec 0015 §3.4).
 * - url hat KEINE "revision"-Spalte (anders als page/resource/folder).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class url implements module_catalog {

    public static function modname(): string {
        return 'url';
    }

    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Anzeigename des Links.',
                true,
                null,
                null,
                null,
                'mod/url/mod_form.php:42-45 (PARAM_TEXT bzw. PARAM_CLEANHTML je nach $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Beschreibungstext (Intro), optional oberhalb des Links eingeblendet (Pseudofeld "printintro" '
                    . 'steuert ob, nur bei bestimmten display-Werten wirksam).',
                false,
                null,
                null,
                null,
                'mod/url/db/install.xml (url.intro, NOTNULL=false)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Textformat des Intros.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/url/db/install.xml (url.introformat)'
            ),
            new field(
                'externalurl',
                'PARAM_RAW_TRIMMED',
                'Die Ziel-URL. KEIN PARAM_URL (siehe Klassenkommentar) - geprueft wird gegen '
                    . 'url_appears_valid_url().',
                true,
                null,
                null,
                'url_appears_valid_url()',
                'mod/url/locallib.php:39-46 (url_appears_valid_url()); Typ mod/url/mod_form.php:50 '
                    . '(PARAM_RAW_TRIMMED); Spalte mod/url/db/install.xml (url.externalurl, NOTNULL)'
            ),
            new field(
                'display',
                'PARAM_INT',
                'Darstellung des Links (z.B. eingebettet, neues Fenster, Popup). Die tatsaechlich waehlbaren '
                    . 'Optionen sind eine von der Moodle-Administration konfigurierte Teilmenge, keine feste Liste.',
                false,
                0,
                null,
                'resourcelib_get_displayoptions()',
                'lib/resourcelib.php:30-42 (RESOURCELIB_DISPLAY_*-Konstanten), :111 (resourcelib_get_displayoptions()); '
                    . 'Teilmenge aus admin_setting_configmultiselect(\'url/displayoptions\', ...) in '
                    . 'mod/url/settings.php:31-39; Spalte mod/url/db/install.xml (url.display)'
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
                'printintro',
                'PARAM_BOOL',
                'Intro zusaetzlich anzeigen - nur wirksam bei display=0 (Auto), 1 (Embed) oder 2 (Frame). Kein '
                    . 'DB-Feld - fliesst in die serialisierte "displayoptions"-Spalte ein.',
                false,
                0,
                [0, 1],
                null,
                'mod/url/lib.php (url_update_instance(): nur gesetzt, wenn display in [AUTO, EMBED, FRAME])'
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
                'mod/url/lib.php (url_update_instance(): nur gesetzt, wenn display==RESOURCELIB_DISPLAY_POPUP); '
                    . 'Default mod/url/settings.php'
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
                'mod/url/lib.php (url_update_instance(): nur gesetzt, wenn display==RESOURCELIB_DISPLAY_POPUP)'
            ),
            new field(
                'parameter_N',
                'PARAM_RAW',
                'Name des N-ten URL-Parameters (N=0..99, mit variable_N gepaart). OHNE dieses Feld beim Update '
                    . 'loescht Moodle saemtliche bestehenden Parameter - url_update_instance() baut sie bei jedem '
                    . 'Aufruf komplett neu aus parameter_N/variable_N auf.',
                false,
                null,
                null,
                null,
                'mod/url/lib.php (url_add_instance()/url_update_instance(): Schleife ueber parameter_0..parameter_99)'
            ),
            new field(
                'variable_N',
                'PARAM_RAW',
                'Wert des N-ten URL-Parameters (N=0..99, mit parameter_N gepaart). Siehe parameter_N.',
                false,
                null,
                null,
                null,
                'mod/url/lib.php (url_add_instance()/url_update_instance(): Schleife ueber variable_0..variable_99)'
            ),
        ];
    }

    public static function blocklist(): array {
        return [
            'displayoptions',
            'parameters',
        ];
    }

    public static function combination_rules(): array {
        return [
            'parameter_N und variable_N muessen beide gesetzt sein, sonst wird das Paar ignoriert '
                . '(mod/url/lib.php: url_add_instance()/url_update_instance()).',
        ];
    }

    public static function side_effects(): array {
        return [];
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
