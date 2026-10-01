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

use moodle_exception;

/**
 * Ein Katalogfeld (Spec 0015 §2.2, Kategorie 1 "Felder" und Kategorie 2
 * "Pseudofelder" - gleiche Form, unterschiedliche Liste).
 *
 * Traegt immer eine deutsche Bedeutung (Abnahmekriterium #379: "kein Feld
 * wird nur mit englischem Namen ausgeliefert") und eine Quellenangabe: wo
 * Moodle eine aufrufbare Quelle hat, steht ihr Name in $sourcecallable -
 * sonst ist $source die literale Datei:Zeile-Angabe (Spec 0015 §2.2).
 *
 * Die PHP-Bezeichner dieser Klasse sind Englisch (CLAUDE.md); seit #569 sind
 * auch die ausgelieferten JSON-Schluessel in {@see to_array()} unmittelbar
 * Englisch - der eigentliche Lehrkraft-/KI-Vertrag bleibt die deutsche
 * Bedeutung ("meaning"), nicht der Schluesselname selbst (#379).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class field {

    /**
     * Feldangaben aus JSON-Objekten muessen String-Schluessel sein.
     *
     * @param mixed $fieldname
     * @return void
     * @throws moodle_exception invalidfieldname
     */
    public static function assert_name($fieldname): void {
        if (!is_string($fieldname)) {
            throw new moodle_exception('invalidfieldname', 'local_coursepilot');
        }
    }

    /**
     * @param string $name Moodle-Feldname (Formularweg-Vertrag).
     * @param string $type PARAM_*-Konstante oder Kurzbeschreibung des Typs.
     * @param string $meaning Deutsche Bedeutung fuer die Lehrkraft/KI.
     * @param bool $required Pflichtfeld ohne Default?
     * @param mixed $default Formular-Default, null wenn keiner existiert.
     * @param array|null $values Erlaubte Werte, literal - null, wenn nur ueber
     *        $sourcecallable bestimmbar.
     * @param string|null $sourcecallable Name einer aufrufbaren Moodle-Quelle
     *        fuer den Wertebereich, z.B. "format_text_menu()".
     * @param string $source Datei:Zeile-Beleg - immer angegeben, auch wenn
     *        $sourcecallable gesetzt ist (wo die Funktion selbst lebt).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $meaning,
        public readonly bool $required,
        public readonly mixed $default,
        public readonly ?array $values,
        public readonly ?string $sourcecallable,
        public readonly string $source,
    ) {
    }

    /**
     * JSON-kodiert Default und Wertliste, weil Moodles externe API pro Feld
     * genau einen PARAM_*-Typ deklariert - "default" kann hier je nach
     * Katalogfeld int, string, bool oder null sein (#379).
     *
     * @return array{name: string, type: string, meaning: string, required: bool,
     *     default_json: string, value_range: array{values_json: string, source_callable: ?string, source: string}}
     */
    public function to_array(): array {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'meaning' => $this->meaning,
            'required' => $this->required,
            'default_json' => json_encode($this->default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'value_range' => [
                'values_json' => json_encode($this->values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_callable' => $this->sourcecallable,
                'source' => $this->source,
            ],
        ];
    }
}
