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

/**
 * Der Schalter fuer personenbezogene Kontextdaten (Issue #344, ADR 0011).
 * Wirkt auf der Markierung im YAML-Frontmatter einer Kontextdatei
 * (`coursepilot.personenbezug: true`), nicht auf ihrem Inhalt - siehe
 * Spezifikation 0010, Abschnitt "Frontmatter"/"Personenbezug und Varianten".
 *
 * ponytail: kein YAML-Parser im Projekt (kein symfony/yaml, kein
 * ext-yaml) und fuer eine einzelne, eindeutig benannte Flag-Pruefung auch
 * nicht noetig - eine Regex auf den Frontmatter-Block reicht.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class personal_data {

    /**
     * Ob die Instanz personenbezogen markierte Kontextdateien an die KI
     * ausliefert. Standard: aus (siehe `settings.php`).
     *
     * @return bool
     */
    public static function allowed(): bool {
        return (bool) get_config('local_coursepilot', 'allowpersonaldata');
    }

    /**
     * Ob der Dateiinhalt im YAML-Frontmatter als personenbezogen markiert
     * ist (`coursepilot.personenbezug: true`).
     *
     * @param string $content
     * @return bool
     */
    public static function is_marked(string $content): bool {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            return false;
        }
        return (bool) preg_match('/personenbezug:\s*true\b/', $matches[1]);
    }
}
