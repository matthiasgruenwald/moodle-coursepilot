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

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

/**
 * Maskiert den Personenbezug in Moodle-Verfuegbarkeitsbedingungen vor der
 * Ausgabe an eine KI (#341).
 *
 * Eigenstaendige Portierung von local_coursepilot\availability_privacy:
 * local_coursepilot hat laut Spec 0012 ("keine Abhaengigkeit zu
 * local_coursepilot") keine Laufzeitabhaengigkeit auf das andere Plugin -
 * die Spike-Testinstanz traegt ausschliesslich local_coursepilot, ein
 * Klassenverweis auf local_coursepilot waere dort ein Fatal Error (Fund aus
 * dem PHPUnit-Lauf zu #341). Geteilte reine Funktion INNERHALB von
 * local_coursepilot, keine Sonderbehandlung im Katalogcode.
 *
 * Regeln:
 * - Leerer String -> leerer String.
 * - Nicht parsebares JSON -> leerer String (nicht sicher beurteilbar).
 * - Verschachtelte "c"-Bedingungslisten werden rekursiv verarbeitet.
 * - Fuer type==="profile"-Bedingungen: "v" wird durch "***" ersetzt.
 *   "sf"/"cf" und "op" bleiben erhalten, damit die KI weiss, dass eine
 *   personenbezogene Beschraenkung existiert (weglassen waere schlimmer als
 *   maskieren).
 * - Alle anderen Typen/Felder bleiben unveraendert.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class availability_privacy {

    /**
     * @param string $availability Rohes Moodle-Verfuegbarkeits-JSON.
     * @return string Maskiertes JSON, oder leerer String.
     */
    public static function sanitize(string $availability): string {
        if ($availability === '') {
            return '';
        }
        $data = json_decode($availability, true);
        if ($data === null) {
            return '';
        }
        $data = self::sanitize_node($data);
        return json_encode($data);
    }

    /**
     * @param array $node
     * @return array
     */
    private static function sanitize_node(array $node): array {
        if (!isset($node['c']) || !is_array($node['c'])) {
            return $node;
        }
        $node['c'] = array_map([self::class, 'sanitize_condition'], $node['c']);
        return $node;
    }

    /**
     * @param array $condition
     * @return array
     */
    private static function sanitize_condition(array $condition): array {
        if (isset($condition['c']) && is_array($condition['c'])) {
            return self::sanitize_node($condition);
        }
        if (($condition['type'] ?? '') === 'profile') {
            $condition['v'] = '***';
        }
        return $condition;
    }
}
