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
 * Feldkatalog fuer mod_page (Spec 0015 §4.1, gelb: benannte Sonderbehandlung).
 *
 * Fallstricke aus dem Bestand (Ticket #380):
 * - Das Formularfeld "page" (Editor-Array text/format/itemid) ist ein
 *   Pseudofeld, das page_update_instance() ungeschuetzt liest
 *   (mod/page/lib.php: `$data->content = $data->page['text'];`). Fehlt es
 *   beim Update, wird `content` (und `contentformat`) auf null gesetzt - PHP
 *   liest den Array-Offset eines nicht gesetzten Attributs klaglos als null.
 * - "printintro"/"printlastmodified" werden nach `displayoptions` serialisiert
 *   und existieren nicht als eigene Spalte.
 * - "display" selbst ist trotz gegenteiliger Notiz in Spec 0015 §2.2 eine
 *   echte Spalte (mod/page/db/install.xml) - der Katalog-gegen-Moodle-Test
 *   wuerde sonst auf einer ungefuehrten Spalte scheitern. "popupwidth"/
 *   "popupheight" sind zwar in Spec 0015 nur bei url (display=6) genannt,
 *   page liest sie aber genauso (mod/page/lib.php: `if ($data->display ==
 *   RESOURCELIB_DISPLAY_POPUP) { ... $data->popupwidth ... }`) - hier
 *   deshalb ebenfalls als Pseudofeld gefuehrt.
 * - "printheading" existiert in Moodle 5.0 nicht mehr und taucht hier bewusst
 *   nicht auf.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class page implements module_catalog {

    public static function modname(): string {
        return 'page';
    }

    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Anzeigename der Textseite.',
                true,
                null,
                null,
                null,
                'mod/page/mod_form.php:39-45 (PARAM_TEXT bzw. PARAM_CLEANHTML je nach $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Beschreibungstext (Intro), optional oberhalb des Seiteninhalts eingeblendet (Pseudofeld '
                    . '"printintro" steuert ob).',
                false,
                null,
                null,
                null,
                'mod/page/db/install.xml (page.intro, NOTNULL=false)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Textformat des Intros.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/page/db/install.xml (page.introformat)'
            ),
            new field(
                'content',
                'PARAM_RAW',
                'Der eigentliche Seiteninhalt (HTML). Wird ueber das Pseudofeld "page" gesetzt, nicht direkt - '
                    . 'deshalb hier NICHT als Pflichtfeld gefuehrt, obwohl die Spalte einen Wert braucht: die '
                    . 'Pflicht erfuellt das Pseudofeld "page" (#404). Beides als Pflicht zu fuehren, ergab eine '
                    . 'Sackgasse - "content" nennen forderte "page", "page" nennen forderte "content".',
                false,
                null,
                null,
                null,
                'mod/page/db/install.xml (page.content); gesetzt aus dem Pseudofeld "page" in '
                    . 'mod/page/lib.php (page_update_instance())'
            ),
            new field(
                'contentformat',
                'PARAM_INT',
                'Textformat des Seiteninhalts. Wird wie "content" ueber das Pseudofeld "page" gesetzt.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/page/db/install.xml (page.contentformat)'
            ),
            new field(
                'display',
                'PARAM_INT',
                'Darstellung der Seite (z.B. neues Fenster, Popup). Die tatsaechlich waehlbaren Optionen sind '
                    . 'eine von der Moodle-Administration konfigurierte Teilmenge, keine feste Liste.',
                false,
                0,
                null,
                'resourcelib_get_displayoptions()',
                'lib/resourcelib.php:30-42 (RESOURCELIB_DISPLAY_*-Konstanten), :111 (resourcelib_get_displayoptions()); '
                    . 'Teilmenge aus admin_setting_configmultiselect(\'page/displayoptions\', ...) in '
                    . 'mod/page/settings.php:31-35; Spalte mod/page/db/install.xml (page.display)'
            ),
        ];
    }

    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    public static function pseudofields(): array {
        return [
            new field(
                'page',
                'array{text: string, format: int, itemid: int}',
                'Editor-Array fuer den Seiteninhalt. OHNE dieses Feld setzt Moodle beim Update "content" (und '
                    . '"contentformat") stillschweigend auf null - page_update_instance() liest '
                    . '$data->page[\'text\'] ungeschuetzt.',
                true,
                null,
                null,
                null,
                'mod/page/lib.php (page_update_instance(): `$data->content = $data->page[\'text\'];`)'
            ),
            new field(
                'printintro',
                'PARAM_BOOL',
                'Intro zusaetzlich zum Seiteninhalt anzeigen. Kein DB-Feld - fliesst in die serialisierte '
                    . '"displayoptions"-Spalte ein.',
                false,
                0,
                [0, 1],
                null,
                'mod/page/lib.php (page_update_instance(): `$displayoptions[\'printintro\']`); Default '
                    . 'mod/page/settings.php:41'
            ),
            new field(
                'printlastmodified',
                'PARAM_BOOL',
                'Aenderungsdatum unter dem Seiteninhalt anzeigen. Kein DB-Feld - fliesst in "displayoptions" ein.',
                false,
                1,
                [0, 1],
                null,
                'mod/page/lib.php (page_update_instance(): `$displayoptions[\'printlastmodified\']`); Default '
                    . 'mod/page/settings.php:43'
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
                'mod/page/lib.php (page_update_instance(): nur gesetzt, wenn display==RESOURCELIB_DISPLAY_POPUP); '
                    . 'Default mod/page/settings.php:45'
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
                'mod/page/lib.php (page_update_instance(): nur gesetzt, wenn display==RESOURCELIB_DISPLAY_POPUP)'
            ),
        ];
    }

    public static function blocklist(): array {
        return [
            'revision',
            'displayoptions',
            'legacyfiles',
            'legacyfileslast',
        ];
    }

    public static function combination_rules(): array {
        return [];
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
        return ['RESOURCELIB_DISPLAY_POPUP'];
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
