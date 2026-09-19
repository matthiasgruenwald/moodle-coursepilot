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
 * Ein Bereich ist ein Wertesatz, kein Typ (Issue #444): das, was
 * {@see context_files} von {@see material_files} unterscheidet, und sonst
 * nichts. Component/Filearea/Itembezug/Nutzerkontext sind fuer beide
 * Bereiche gleich und bleiben deshalb in {@see storage_anchor} - nicht hier.
 *
 * Die Namensregel beim Schreiben ({@see $checkwritablename}) ist die eine
 * echte, bereichsspezifische Policy-Methode (`.md`-Whitelist bei
 * context_files, Endungs-Whitelist bei material_files): der Bereich liefert
 * sie als Closure, die bei Verstoss selbst die passende moodle_exception mit
 * ihrem eigenen Fehlerschluessel wirft.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class storage_area {

    /**
     * @param string $rootsetting Name der Plugin-Einstellung fuer den Wurzelordner.
     * @param string $defaultroot Standardwurzel, falls die Einstellung leer ist.
     * @param string $invalidpathkey Sprachstring-Schluessel fuer einen abgewiesenen Pfad
     *        (`.`/`..`-Segmente, ungueltige Ordnersegmente).
     * @param string $quotaerrorkey Sprachstring-Schluessel, wenn ein Schreibvorgang die
     *        Nutzerquote sprengen wuerde.
     * @param \Closure(string): void $checkwritablename Wirft bei einem nicht zulaessigen
     *        Dateinamen eine eigene moodle_exception; gibt sonst einfach zurueck.
     * @param string|null $pointerkey Feldname dieses Bereichs im Kontextpointer
     *        (Issue #445), z.B. "kontextbereich"/"materialordner". `null`, wenn
     *        der Bereich den Pointer nicht kennt (z.B. ein reiner Testbereich) -
     *        dann gilt immer die per Einstellung konfigurierte Standardwurzel.
     * @param bool $externalfallback Bei einem Pointer-Ziel *extern* auf die
     *        konfigurierte Standardwurzel zurueckfallen, statt zu werfen
     *        (Issue #520, Spec #486 §1: die Werkbank kennt noch keine externen
     *        Ziele und bleibt am Anker, waehrend der Materialbestand selbst
     *        weiterhin benannt scheitert, siehe {@see storage_anchor::root()}).
     */
    public function __construct(
        public readonly string $rootsetting,
        public readonly string $defaultroot,
        public readonly string $invalidpathkey,
        public readonly string $quotaerrorkey,
        public readonly \Closure $checkwritablename,
        public readonly ?string $pointerkey = null,
        public readonly bool $externalfallback = false,
    ) {
    }
}
