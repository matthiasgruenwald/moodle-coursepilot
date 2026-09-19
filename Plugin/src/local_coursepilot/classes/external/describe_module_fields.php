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

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\field;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\shared_block;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Feldkatalog als Daten (Spec 0015 §3.1, Ticket #379). Rein lesend: liefert,
 * was eine Aktivitaetsart einstellen kann, in Lehrkraft-verstaendlichem
 * Deutsch statt englischer Feldnamen ohne Erklaerung.
 *
 * Ohne $modname: welche Aktivitaetsarten Coursepilot ueberhaupt fuehrt (User
 * Story 13). Mit $modname, ohne $vollstaendig: die haeufig gesetzten Felder
 * plus Feldbuendel plus Vermerk, dass es mehr gibt (User Story 15). Mit
 * $vollstaendig=true: alle fuenf Kategorien aus Spec 0015 §2.2.
 *
 * Nicht course-gebunden: der Katalog ist statische Serverkonfiguration, kein
 * Kursinhalt - deshalb keine 'local/coursepilot:use'-Pruefung im Kurskontext
 * (die gibt es hier nicht), sondern nur das Standard-Login/die globale
 * Fernzugriffs-Notbremse aus dispatcher::handle_authorized().
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class describe_module_fields extends external_api {

    /**
     * Vehikel-Hinweis fuer Aktivitaetsarten ohne eigenes schreibweg() (Spec
     * 0015 §1/§3.1: der Formularweg update_moduleinfo()). Bewusst OHNE
     * konkrete MCP-Werkzeugnamen ("update_module_settings"/"create_module") -
     * dieses Ticket (#379) liefert nur den Lesekatalog, der Schreibkern selbst
     * kommt erst in Phase 3.
     */
    private const VEHICLE_SCHREIBWEG = 'Formularweg (update_moduleinfo() bzw. add_moduleinfo()); eigener '
        . 'Schreib-Endpunkt folgt in einer spaeteren Ausbaustufe.';

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'modname' => new external_value(
                PARAM_ALPHANUMEXT,
                'Aktivitaetstyp, z.B. label. Leer liefert die Liste der von Coursepilot gefuehrten Arten.',
                VALUE_DEFAULT,
                ''
            ),
            'vollstaendig' => new external_value(
                PARAM_BOOL,
                'true fuer alle fuenf Kategorien (Felder, Pseudofelder, Sperrliste, Kombinationsregeln, '
                    . 'Nebenwirkungen); sonst nur die haeufig gesetzten Felder plus Feldbuendel.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param string $modname
     * @param bool $vollstaendig
     * @return array
     * @throws moodle_exception unknownmodname, wenn $modname nicht gefuehrt wird.
     */
    public static function execute(string $modname = '', bool $vollstaendig = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'modname' => $modname,
            'vollstaendig' => $vollstaendig,
        ]);

        self::validate_context(context_system::instance());

        $aktivitaetsarten = registry::known_modnames();
        $modname = trim($params['modname']);

        if ($modname === '') {
            return [
                'aktivitaetsarten' => $aktivitaetsarten,
                'hinweis' => 'Coursepilot kann die Aktivitaetsarten, die er kennt: '
                    . implode(', ', $aktivitaetsarten) . '. describe_module_fields(modname) fragt eine davon ab.',
                'modul' => null,
            ];
        }

        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            throw new moodle_exception(
                'unknownmodname',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'aktivitaetsarten' => implode(', ', $aktivitaetsarten)]
            );
        }

        $vollstaendig = (bool) $params['vollstaendig'];
        $modulefields = $catalogclass::fields();
        if (!$vollstaendig) {
            // Ausduennung fuer die Kurzform (Spec 0015 §3.1, Ticket #382): nur Aktivitaetsarten mit sehr
            // vielen Feldern (assign: ~30) grenzen common_field_names() echt ein - bei wenigen Feldern
            // (label, choice, forum, ...) liefert die Methode ohnehin alle Namen (siehe module_catalog).
            $commonnames = $catalogclass::common_field_names();
            $modulefields = array_values(array_filter(
                $modulefields,
                static fn (field $f): bool => in_array($f->name, $commonnames, true)
            ));
        }
        $felder = array_merge(shared_block::fields(), $modulefields);

        $modul = [
            'modname' => $modname,
            'schreibweg' => $catalogclass::schreibweg() ?? self::VEHICLE_SCHREIBWEG,
            'felder' => array_map(static fn (field $f): array => $f->to_array(), $felder),
            'feldbuendel' => self::bundles($catalogclass::bundles()),
            'pseudofelder' => [],
            'sperrliste' => [],
            'kombinationsregeln' => [],
            'nebenwirkungen' => [],
        ];

        if ($vollstaendig) {
            $pseudofelder = array_merge(shared_block::pseudofields(), $catalogclass::pseudofields());
            $modul['pseudofelder'] = array_map(static fn (field $f): array => $f->to_array(), $pseudofelder);
            $modul['sperrliste'] = array_values(array_unique(
                array_merge(shared_block::BLOCKLIST, $catalogclass::blocklist())
            ));
            $modul['kombinationsregeln'] = $catalogclass::combination_rules();
            $modul['nebenwirkungen'] = array_merge(shared_block::side_effects(), $catalogclass::side_effects());
        }

        return [
            'aktivitaetsarten' => $aktivitaetsarten,
            'hinweis' => $vollstaendig
                ? 'Vollstaendige Form: alle fuenf Katalogkategorien.'
                : 'Kurzform: nur die haeufig gesetzten Felder und Feldbuendel. Pseudofelder, Sperrliste, '
                    . 'Kombinationsregeln und Nebenwirkungen fehlen - mit vollstaendig:true abrufen.',
            'modul' => $modul,
        ];
    }

    /**
     * Feldbuendel fuer die Rueckgabestruktur: Werte sind je Feld gemischten
     * Typs, deshalb als JSON-Zeile statt als dynamische Struktur (gleiches
     * Vorgehen wie get_course_catalog::plugin_config_field()-Zusatzdateien).
     *
     * @param array<string, array<string, mixed>> $bundles
     * @return array<int, array{name: string, felder_json: string}>
     */
    private static function bundles(array $bundles): array {
        $result = [];
        foreach ($bundles as $name => $felder) {
            $result[] = ['name' => $name, 'felder_json' => json_encode($felder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }
        return $result;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $fieldstructure = new external_single_structure([
            'name' => new external_value(PARAM_TEXT, 'Moodle-Feldname (Formularweg-Vertrag)'),
            'typ' => new external_value(PARAM_TEXT, 'PARAM_*-Konstante oder Kurzbeschreibung des Typs'),
            'bedeutung' => new external_value(PARAM_TEXT, 'Deutsche Bedeutung des Felds'),
            'pflicht' => new external_value(PARAM_BOOL, 'Pflichtfeld ohne Default?'),
            'default_json' => new external_value(PARAM_RAW, 'JSON-kodierter Formular-Default, "null" wenn keiner'),
            'wertebereich' => new external_single_structure([
                'werte_json' => new external_value(
                    PARAM_RAW,
                    'JSON-kodierte Liste erlaubter Werte, "null" wenn nur ueber quelle_callable bestimmbar'
                ),
                'quelle_callable' => new external_value(
                    PARAM_TEXT,
                    'Aufrufbare Moodle-Quelle des Wertebereichs, z.B. "format_text_menu()", sonst null'
                ),
                'quelle' => new external_value(PARAM_TEXT, 'Datei:Zeile-Beleg'),
            ]),
        ]);

        return new external_single_structure([
            'aktivitaetsarten' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Modname'),
                'Von Coursepilot gefuehrte Aktivitaetsarten'
            ),
            'hinweis' => new external_value(PARAM_TEXT, 'Deutscher Hinweistext'),
            'modul' => new external_single_structure([
                'modname' => new external_value(PARAM_TEXT, 'Aktivitaetstyp'),
                'schreibweg' => new external_value(
                    PARAM_TEXT,
                    'Vehikel-Hinweis oder Name des Einzelwerkzeugs, das stattdessen schreibt'
                ),
                'felder' => new external_multiple_structure($fieldstructure, 'Kategorie 1: Felder (inkl. gemeinsamer Block)'),
                'feldbuendel' => new external_multiple_structure(
                    new external_single_structure([
                        'name' => new external_value(PARAM_TEXT, 'Buendelname'),
                        'felder_json' => new external_value(PARAM_RAW, 'JSON-kodierte Feld=>Wert-Vorbelegung'),
                    ]),
                    'Feldbuendel (Presets)'
                ),
                'pseudofelder' => new external_multiple_structure($fieldstructure, 'Kategorie 2: nur bei vollstaendig:true'),
                'sperrliste' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Feldname'),
                    'Kategorie 3: nur bei vollstaendig:true'
                ),
                'kombinationsregeln' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Regel in Lehrkraft-Deutsch'),
                    'Kategorie 4: nur bei vollstaendig:true'
                ),
                'nebenwirkungen' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Nebenwirkungsvermerk in Lehrkraft-Deutsch'),
                    'Kategorie 5: nur bei vollstaendig:true'
                ),
            ], 'Der abgefragte Modulkatalog, null wenn modname leer war', VALUE_DEFAULT, null, NULL_ALLOWED),
        ]);
    }
}
