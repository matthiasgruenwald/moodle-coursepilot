<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot;

/**
 * Deutet den bereits als JSON-Objekt gelesenen Kontextpointer (Issue #490,
 * Spec #486 §2) - reine Werteumformung, ohne Datei- oder Netzzugriff. Kennt
 * beide Fassungen:
 *
 * - **Erste Fassung** (Issue #445): zwei flache Pfade unter den Schluesseln
 *   "kontextbereich"/"materialordner". Gilt vollstaendig als *in Moodle* an
 *   diesen Pfaden - kein Upgrade-Schritt schreibt sie um (Spec §2).
 * - **Zweite Fassung** (Issue #490): je Ziel ein Objekt mit `ort` =
 *   "moodle" (Feld `pfad`) oder "extern" (Felder `instanzid`, `pfad`,
 *   `pruefmerkmal`). Der Materialbestand traegt intern denselben Feldnamen
 *   wie sein Ziel - "materialbestand" loest den frueheren Begriff
 *   "materialordner" ab (Spec §2), auch wenn {@see \local_kurspilot\material_files}
 *   ihren Pointer-Schluessel (aus historischen Gruenden "materialordner")
 *   unveraendert weiterreicht: die Zuordnung passiert hier in
 *   {@see TARGET_FIELD}.
 *
 * "Vorheriger Ort" und "Ortsverlauf" (Spec §2) sind im Pointer vorgesehen,
 * aber diese Klasse liest sie noch nicht - beide Felder gehoeren keinem
 * Auflosungspfad dieses Issues (#490), erst der Altbestand (spaeteres Issue)
 * braucht sie.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class context_pointer {

    /** @var string[] Pflichtfelder der ersten Fassung. */
    private const LEGACY_KEYS = ['kontextbereich', 'materialordner'];

    /**
     * @var array<string, string> Bereichs-Pointerschluessel => Feldname im
     *      Pointer der zweiten Fassung.
     */
    private const TARGET_FIELD = [
        'kontextbereich' => 'kontextbereich',
        'materialordner' => 'materialbestand',
    ];

    /**
     * @param array $decoded Bereits als JSON-Objekt dekodierter Pointerinhalt.
     * @param string $pointerkey {@see storage_area::$pointerkey} des aufloesenden Bereichs.
     * @return pointer_location
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    public static function resolve_target(array $decoded, string $pointerkey): pointer_location {
        if (self::is_legacy($decoded)) {
            return self::resolve_legacy($decoded, $pointerkey);
        }
        return self::resolve_v2($decoded, $pointerkey);
    }

    /**
     * Erste Fassung erkennen: "kontextbereich" ist ein flacher String, keine
     * Struktur (Spec §2: "Ein Pointer der ersten Fassung mit zwei Pfaden
     * gilt als in Moodle an diesen Pfaden").
     *
     * @param array $decoded
     * @return bool
     */
    private static function is_legacy(array $decoded): bool {
        return is_string($decoded['kontextbereich'] ?? null);
    }

    /**
     * @param array $decoded
     * @param string $pointerkey
     * @return pointer_location
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function resolve_legacy(array $decoded, string $pointerkey): pointer_location {
        foreach (self::LEGACY_KEYS as $key) {
            if (!is_string($decoded[$key] ?? null)) {
                self::incomplete();
            }
        }
        return pointer_location::moodle('/' . self::validate_path($decoded[$pointerkey]) . '/');
    }

    /**
     * @param array $decoded
     * @param string $pointerkey
     * @return pointer_location
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function resolve_v2(array $decoded, string $pointerkey): pointer_location {
        $field = self::TARGET_FIELD[$pointerkey] ?? $pointerkey;

        // Vollstaendigkeit (Pruefung 1, Spec §2): beide Ziele muessen als
        // gueltige Struktur vorliegen, unabhaengig davon, welches gerade
        // aufgeloest wird - dieselbe Regel wie bei der ersten Fassung.
        foreach (self::TARGET_FIELD as $targetfield) {
            if (!is_array($decoded[$targetfield] ?? null)) {
                self::incomplete();
            }
        }

        $target = $decoded[$field];
        $ort = $target['ort'] ?? null;

        if ($ort === 'moodle') {
            if (!is_string($target['pfad'] ?? null)) {
                self::incomplete();
            }
            return pointer_location::moodle('/' . self::validate_path($target['pfad']) . '/');
        }

        if ($ort === 'extern') {
            return self::resolve_extern($target);
        }

        self::incomplete();
    }

    /**
     * @param array $target
     * @return pointer_location
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function resolve_extern(array $target): pointer_location {
        $instanceid = $target['instanzid'] ?? null;
        $relativepath = $target['pfad'] ?? null;
        $fingerprint = $target['pruefmerkmal'] ?? null;

        if (!is_numeric($instanceid) || (int) $instanceid <= 0
            || !is_string($relativepath)
            || !is_array($fingerprint)
            || !is_string($fingerprint['server'] ?? null)
            || !is_string($fingerprint['basispfad'] ?? null)
            || !is_string($fingerprint['konto'] ?? null)
        ) {
            self::incomplete();
        }

        return pointer_location::extern((int) $instanceid, self::validate_path($relativepath), [
            'server' => $fingerprint['server'],
            'basispfad' => $fingerprint['basispfad'],
            'konto' => $fingerprint['konto'],
        ]);
    }

    /**
     * Dieselbe Segmentpruefung wie die erste Fassung (Issue #445): nicht
     * leer, keine `.`/`..`-Segmente, Backslash zaehlt als Pfadtrenner. Auch
     * von {@see storage_anchor::write_pointer()} genutzt - derselbe
     * Maßstab gilt beim Schreiben wie beim Lesen.
     *
     * @param string $value
     * @return string Getrimmter Pfad, ohne fuehrenden/abschliessenden Schraegstrich.
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    public static function validate_path(string $value): string {
        $normalised = str_replace('\\', '/', $value);
        if (trim($normalised, '/') === '') {
            self::incomplete();
        }
        $trimmed = trim($normalised, '/');
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \moodle_exception('pointerunreachable', 'local_kurspilot', '', storage_anchor::POINTER_FILENAME);
            }
        }
        return $trimmed;
    }

    /**
     * @throws \moodle_exception pointerincomplete
     */
    private static function incomplete(): never {
        throw new \moodle_exception('pointerincomplete', 'local_kurspilot', '', storage_anchor::POINTER_FILENAME);
    }
}
