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
 * Der Ablage-Vertrag (Issue #536, Spec 0021): lesen, schreiben, anhaengen,
 * auflisten, loeschen - je Bereich ({@see storage_area}, unveraendert ein
 * reiner Wertesatz, ADR 0020) und relativem Pfad, mit einem Pruefwert fuer
 * bedingtes Schreiben.
 *
 * Genau eine Schnittstelle fuer beide kuenftigen Adapter (Moodles Private
 * Files, WebDAV) - welcher greift, entscheidet spaeter der Kontextpointer,
 * nicht dieses Interface (Spec 0021, Implementation Decisions). Noch ruft
 * kein Werkzeug einen Adapter dieses Vertrags auf - reines Danebenstellen
 * neben dem bisherigen Weg ({@see storage_anchor}, {@see context_files},
 * {@see material_files}), der unveraendert bleibt.
 *
 * Ein Pruefwert ist ein ortsneutraler Bezeichner fuer den Inhaltsstand einer
 * Datei (bei Private Files der Moodle-`contenthash`, spaeter beim
 * WebDAV-Adapter ein ETag/Aenderungszeit-Ersatz) - fuer den Aufrufer eine
 * blanke Zeichenkette zum Vergleichen, kein Erkennungsmerkmal des Ortes
 * (Spec 0021 Implementation Decisions).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
interface storage_port {

    /**
     * Liest den Inhalt einer Datei, oder null, wenn sie fehlt oder ein
     * Ordner ist.
     *
     * @param storage_area $area
     * @param string $path Client-Pfad, z.B. "plan.md" oder "faecher/mathe/profil.md".
     * @return array{content: string, checksum: string, size: int, mimetype: string,
     *         timemodified: int}|null
     * @throws \moodle_exception invalidpathkey des Bereichs bei einem unzulaessigen Pfad.
     */
    public function read(storage_area $area, string $path): ?array;

    /**
     * Listet eine Ebene eines Bereichs - fuer beide kuenftigen Orte derselbe
     * Feldsatz (Spec 0021 Implementation Decisions: "Ein ortsspezifischer
     * Pruefwert wird entweder fuer beide Orte geliefert oder fuer keinen").
     *
     * @param storage_area $area
     * @param string $path Client-Unterordner, z.B. "" oder "faecher/mathe".
     * @return array<int, array{name: string, type: string, size: int, mimetype: string,
     *         checksum: string, timemodified: int}>
     * @throws \moodle_exception invalidpathkey des Bereichs bei einem unzulaessigen Pfad.
     */
    public function list(storage_area $area, string $path): array;

    /**
     * Legt eine Datei an oder ersetzt ihren Inhalt vollstaendig. Pfadpruefung,
     * Quotenpruefung und die Schreibchoreografie mit Zwischendatei liegen im
     * Adapter, nicht beim Aufrufer (Spec 0021 Abnahmekriterium).
     *
     * @param storage_area $area
     * @param string $path
     * @param string $content Vollstaendiger neuer Inhalt.
     * @param string|null $expectedchecksum Pruefwert aus einem frueheren Lesen fuer
     *        bedingtes Schreiben. `null` (Standard) heisst: ungeprueft ueberschreiben/
     *        anlegen, wie bisher. Ein angegebener Pruefwert, der nicht (mehr) zum
     *        aktuellen Stand passt - auch wenn die Datei inzwischen fehlt - loest
     *        {@see storage_conflict_exception} aus.
     * @return array{path: string, created: bool, size: int, checksum: string}
     * @throws \moodle_exception invalidpathkey/eigener Namensfehler des Bereichs,
     *         quotaerrorkey des Bereichs
     * @throws storage_conflict_exception bei nicht passendem Pruefwert.
     */
    public function write(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array;

    /**
     * Haengt Inhalt an eine Datei an, legt sie an, falls sie noch nicht
     * existiert.
     *
     * @param storage_area $area
     * @param string $path
     * @param string $content Anzuhaengender Inhalt.
     * @return array{path: string, created: bool, size: int, checksum: string}
     * @throws \moodle_exception invalidpathkey/eigener Namensfehler des Bereichs,
     *         quotaerrorkey des Bereichs
     */
    public function append(storage_area $area, string $path, string $content): array;

    /**
     * Loescht eine Datei, falls sie existiert.
     *
     * @param storage_area $area
     * @param string $path
     * @return bool true, wenn eine Datei geloescht wurde; false, wenn keine existierte.
     * @throws \moodle_exception invalidpathkey des Bereichs bei einem unzulaessigen Pfad.
     */
    public function delete(storage_area $area, string $path): bool;
}
