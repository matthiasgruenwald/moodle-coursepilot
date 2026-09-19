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
 * Ein Katalogfeld (Spec 0015 §2.2, Kategorie 1 "Felder" und Kategorie 2
 * "Pseudofelder" - gleiche Form, unterschiedliche Liste).
 *
 * Traegt immer eine deutsche Bedeutung (Abnahmekriterium #379: "kein Feld
 * wird nur mit englischem Namen ausgeliefert") und eine Quellenangabe: wo
 * Moodle eine aufrufbare Quelle hat, steht ihr Name in $sourcecallable -
 * sonst ist $source die literale Datei:Zeile-Angabe (Spec 0015 §2.2).
 *
 * Die PHP-Bezeichner dieser Klasse sind Englisch (CLAUDE.md); die
 * ausgelieferten JSON-Schluessel in {@see to_array()} sind bewusst Deutsch -
 * das ist der eigentliche Lehrkraft-/KI-Vertrag dieses Tickets (#379: "Antwort
 * auf Deutsch statt englischer Feldnamen").
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class field {

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
     * @return array{name: string, typ: string, bedeutung: string, pflicht: bool,
     *     default_json: string, wertebereich: array{werte_json: string, quelle_callable: ?string, quelle: string}}
     */
    public function to_array(): array {
        return [
            'name' => $this->name,
            'typ' => $this->type,
            'bedeutung' => $this->meaning,
            'pflicht' => $this->required,
            'default_json' => json_encode($this->default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'wertebereich' => [
                'werte_json' => json_encode($this->values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'quelle_callable' => $this->sourcecallable,
                'quelle' => $this->source,
            ],
        ];
    }
}
