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
 *   "materialordner" ab (Spec §2), auch wenn {@see \local_coursepilot\material_files}
 *   ihren Pointer-Schluessel (aus historischen Gruenden "materialordner")
 *   unveraendert weiterreicht: die Zuordnung passiert hier in
 *   {@see TARGET_FIELD}.
 *
 * "Ortsverlauf" (Spec §2) wird von dieser Klasse weiterhin nicht gedeutet -
 * die Ortswahlseite ({@see \local_coursepilot\location_selection}) haengt Zeilen an
 * und liest sie roh zurueck, keine Aufloesung noetig. "Vorheriger Ort" (Feld
 * `vorheriger_ort`, Issue #498, Spec #486 §9) wird dagegen hier gedeutet -
 * {@see resolve_previous()} - denn der Altbestand-Nur-Lese-Zweig
 * ({@see \local_coursepilot\previous_location}) braucht dieselbe Struktur- und
 * IServ-Pruefung wie die beiden regulaeren Ziele.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
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
        $pair = self::is_legacy($decoded) ? self::resolve_pair_legacy($decoded) : self::resolve_pair_v2($decoded);

        // Aufloesungspruefung 7 (Issue #495, Spec #486 §2): der Materialbestand
        // darf nie im Kontextbereich oder im selben Ordner liegen - egal,
        // welches der beiden Ziele hier gerade angefragt wird, denn beide
        // muessen ohnehin zusammen aufgeloest werden (Pruefung 1). Ein
        // Vergleichsschluessel aus Server+Konto+Pfad (ortsunabhaengig, siehe
        // {@see pointer_location::comparison_key()}) haelt das fest: liegt der
        // Materialbestand-Schluessel unter (oder gleich) dem Kontextbereich-
        // Schluessel, ist der Bestand nicht erreichbar, ohne versehentlich in
        // den Kontextbereich hineinzulesen.
        if (str_starts_with($pair['materialbestand']->comparison_key(), $pair['kontextbereich']->comparison_key())) {
            throw new \moodle_exception('materialbestandimkontext', 'local_coursepilot');
        }

        // Aufloesungspruefung 8 (Issue #497, Spec #486 §2/§5): bei einer als
        // IServ erkannten Instanz (Pruefmerkmal, ohne Netz) ist nur unterhalb
        // von "Files/" erreichbar - fuer beide Ziele, unabhaengig davon,
        // welches hier gerade angefragt wird (derselbe Grund wie bei Pruefung 7).
        foreach ($pair as $location) {
            if ($location->kind === pointer_location::EXTERN && ($location->fingerprint['iserv'] ?? false) === true) {
                $first = strtok((string) $location->relativepath, '/');
                if ($first !== \local_coursepilot\webdav\webdav_instance::ISERV_FILES_AREA) {
                    throw self::iserv_files_only_exception($location);
                }
            }
        }

        $field = self::TARGET_FIELD[$pointerkey] ?? $pointerkey;
        return $pair[$field];
    }

    /**
     * @param array $decoded
     * @return array{kontextbereich: pointer_location, materialbestand: pointer_location}
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function resolve_pair_legacy(array $decoded): array {
        foreach (self::LEGACY_KEYS as $key) {
            if (!is_string($decoded[$key] ?? null)) {
                self::incomplete();
            }
        }
        return [
            'kontextbereich' => pointer_location::moodle('/' . self::validate_path($decoded['kontextbereich']) . '/'),
            'materialbestand' => pointer_location::moodle('/' . self::validate_path($decoded['materialordner']) . '/'),
        ];
    }

    /**
     * @param array $decoded
     * @return array{kontextbereich: pointer_location, materialbestand: pointer_location}
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function resolve_pair_v2(array $decoded): array {
        // Vollstaendigkeit (Pruefung 1, Spec §2): beide Ziele muessen als
        // gueltige Struktur vorliegen, unabhaengig davon, welches gerade
        // aufgeloest wird - dieselbe Regel wie bei der ersten Fassung.
        foreach (self::TARGET_FIELD as $targetfield) {
            if (!is_array($decoded[$targetfield] ?? null)) {
                self::incomplete();
            }
        }

        $pair = [];
        foreach (self::TARGET_FIELD as $targetfield) {
            $pair[$targetfield] = self::resolve_single_v2($decoded[$targetfield]);
        }
        return $pair;
    }

    /**
     * @param array $target
     * @return pointer_location
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function resolve_single_v2(array $target): pointer_location {
        $location = $target['ort'] ?? null;

        if ($location === pointer_location::MOODLE) {
            if (!is_string($target['pfad'] ?? null)) {
                self::incomplete();
            }
            return pointer_location::moodle('/' . self::validate_path($target['pfad']) . '/');
        }

        if ($location === pointer_location::EXTERN) {
            return self::resolve_extern($target);
        }

        self::incomplete();
    }

    /**
     * Deutet den vorherigen Ort des Altbestands (Issue #498, Spec #486 §9) -
     * dieselbe Struktur wie ein regulaeres Ziel der zweiten Fassung, deshalb
     * ueber {@see resolve_single_v2()} statt einer eigenen Deutung. Traegt
     * die IServ-Pruefung (Pruefung 8) mit, die auch fuer den vorherigen Ort
     * gilt ("alle Aufloesungspruefungen gelten auch fuer den vorherigen
     * Ort") - die Verschachtelungspruefung (Pruefung 7) dagegen nicht: der
     * vorherige Ort wird nie gegen den aktuellen Materialbestand verglichen.
     *
     * @param array $value Der Wert des Feldes "vorheriger_ort" im Pointer-Dokument.
     * @return pointer_location
     * @throws \moodle_exception pointerincomplete/pointerunreachable/webdaviservfilesonly
     */
    public static function resolve_previous(array $value): pointer_location {
        $location = self::resolve_single_v2($value);
        if ($location->kind === pointer_location::EXTERN && ($location->fingerprint['iserv'] ?? false) === true) {
            $first = strtok((string) $location->relativepath, '/');
            if ($first !== \local_coursepilot\webdav\webdav_instance::ISERV_FILES_AREA) {
                throw self::iserv_files_only_exception($location);
            }
        }
        return $location;
    }

    /**
     * Baut die `webdaviservfilesonly`-Ausnahme (Pruefung 8) mit Host und
     * Instanz-ID im $a-Objekt (Issue #516, Spec #486 §8) - so kann
     * {@see \local_coursepilot\pointer_writer} "Instanzname und Host" (Teil 5
     * der Ausfallantwort) auch dann noch nennen, wenn der Pointer selbst nie
     * bis zu einem fertigen {@see pointer_location} kam (Pruefung 8 scheitert
     * *waehrend* der Aufloesung).
     *
     * @param pointer_location $location Bereits als EXTERN/iserv erkannt.
     * @return \moodle_exception
     */
    private static function iserv_files_only_exception(pointer_location $location): \moodle_exception {
        return new \moodle_exception('webdaviservfilesonly', 'local_coursepilot', '', (object) [
            'page' => \local_coursepilot\webdav\webdav_setup_steps::ORTSWAHL_PAGE,
            'server' => $location->fingerprint['server'] ?? '',
            'instanceid' => $location->instanceid,
        ]);
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
            // IServ-Erkennung (Issue #497, Spec #486 §2 Pruefung 8) - optional,
            // ein Pointer vor #497 kennt das Feld noch nicht und gilt dann als "nein".
            'iserv' => (bool) ($fingerprint['iserv'] ?? false),
        ]);
    }

    /**
     * Dieselbe Segmentpruefung wie die erste Fassung (Issue #445): nicht
     * leer, keine `.`/`..`-Segmente, Backslash zaehlt als Pfadtrenner.
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
                throw new \moodle_exception('pointerunreachable', 'local_coursepilot', '', storage_anchor::POINTER_FILENAME);
            }
        }
        return $trimmed;
    }

    /**
     * @throws \moodle_exception pointerincomplete
     */
    private static function incomplete(): never {
        throw new \moodle_exception('pointerincomplete', 'local_coursepilot', '', storage_anchor::POINTER_FILENAME);
    }
}
