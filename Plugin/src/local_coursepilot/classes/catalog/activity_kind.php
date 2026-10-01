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
 * Antwort des Art-Tors (registry::kind): katalogisiert, erschlossen oder
 * ausgeschlossen (mit Grund als Sprachschluessel).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class activity_kind {
    /** Katalogisiert: gepruefter Feldkatalog. */
    public const CATALOGUED = 'catalogued';
    /** Erschlossen: installiert, ohne Feldkatalog. */
    public const DEVELOPED = 'developed';
    /** Ausgeschlossen: weder katalogisiert noch per XML anlegbar. */
    public const EXCLUDED = 'excluded';

    /**
     * @param string $kind Eine der Konstanten.
     * @param string|null $catalog Katalogklasse, nur bei CATALOGUED.
     * @param string|null $reasonkey Sprachschluessel (local_coursepilot), nur bei EXCLUDED.
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $catalog = null,
        public readonly ?string $reasonkey = null
    ) {
    }
}
