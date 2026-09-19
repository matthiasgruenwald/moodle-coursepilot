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

namespace local_coursepilot\admin;

use local_coursepilot\context_pointer;
use local_coursepilot\pointer_location;
use local_coursepilot\storage_anchor;

/**
 * Liest Kontextpointer und Ausstandsnotiz beliebiger Personen - ohne Netz und
 * ohne den Umweg ueber $USER, den {@see storage_anchor} voraussetzt (Issue
 * #499, Spec #486 §12): Grundlage der vier Statusprüfungen (Anzahl externer
 * Pointer, Schritt 3 je verbundener Person) und der Spalte Ablageort der
 * Verbindungsübersicht.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pointer_scan {

    /** @var string[] Die beiden Pointer-Ziele, wie {@see \local_coursepilot\ortswahl_lib::TARGETS}. */
    public const TARGETS = ['kontextbereich', 'materialbestand'];

    /** @var string Zustand: kein Kontextpointer vorhanden. */
    public const STATE_OPEN = 'offen';

    /** @var string Zustand: Ziel liegt in Moodles Private Files. */
    public const STATE_MOODLE = pointer_location::MOODLE;

    /** @var string Zustand: Ziel liegt in einer WebDAV-Nutzerinstanz. */
    public const STATE_EXTERN = pointer_location::EXTERN;

    /** @var string Zustand: Pointer strukturell defekt (nicht aufloesbar). */
    public const STATE_BROKEN = 'kaputt';

    /** @var string Defekt: die referenzierte Instanz existiert nicht mehr. */
    public const DEFECT_INSTANCE_MISSING = 'instanzfehlt';

    /** @var string Defekt: die Instanz gehoert einer anderen Person. */
    public const DEFECT_FOREIGN_INSTANCE = 'fremdeinstanz';

    /** @var string Defekt: die Instanz nutzt HTTP statt HTTPS+Basic. */
    public const DEFECT_HTTP = 'http';

    /** @var string Defekt: die Pointer-Struktur selbst ist ungueltig. */
    public const DEFECT_INVALID = 'ungueltig';

    /**
     * Alle Personen mit einer nicht-leeren Kontextpointer-Datei - eine reine
     * DB-Abfrage ueber die Dateitabelle, ohne jede Datei zu lesen.
     *
     * @return int[]
     */
    public static function userids_with_pointer(): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT ctx.instanceid AS id
               FROM {files} f
               JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = :level
              WHERE f.component = :component AND f.filearea = :filearea
                AND f.filename = :filename AND f.filesize > 0',
            [
                'level' => CONTEXT_USER,
                'component' => storage_anchor::COMPONENT,
                'filearea' => storage_anchor::FILEAREA,
                'filename' => storage_anchor::POINTER_FILENAME,
            ]
        );
        return array_values(array_map(static fn (\stdClass $row): int => (int) $row->id, $rows));
    }

    /**
     * Der rohe Kontextpointer einer beliebigen Person - `null`, wenn keine
     * Datei existiert oder sie kein gueltiges JSON-Objekt enthaelt (dieselbe
     * Toleranz wie {@see storage_anchor::read_raw_pointer()} fuer die eigene
     * Person, hier ohne $USER-Bezug).
     *
     * @param int $userid
     * @return array|null
     */
    public static function raw_pointer_for(int $userid): ?array {
        $file = get_file_storage()->get_file(
            \context_user::instance($userid)->id,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            storage_anchor::anchor_root(),
            storage_anchor::POINTER_FILENAME
        );
        if (!$file) {
            return null;
        }
        $decoded = json_decode($file->get_content(), true);
        return (is_array($decoded) && !array_is_list($decoded)) ? $decoded : null;
    }

    /**
     * Ob eine beliebige Person eine offene Ausstandsnotiz hat - dieselbe
     * Toleranz wie {@see \local_coursepilot\ausstand_notice}, ohne $USER-Bezug.
     *
     * @param int $userid
     * @return bool
     */
    public static function has_open_ausstand(int $userid): bool {
        $file = get_file_storage()->get_file(
            \context_user::instance($userid)->id,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            storage_anchor::anchor_root(),
            storage_anchor::AUSSTAND_FILENAME
        );
        if (!$file) {
            return false;
        }
        $decoded = json_decode($file->get_content(), true);
        return is_array($decoded) && !array_is_list($decoded) && !empty($decoded);
    }

    /**
     * Ob ein bereits gelesener Kontextpointer offenen Altbestand traegt
     * (Feld "vorheriger_ort", {@see \local_coursepilot\altbestand::current()}).
     *
     * @param array|null $decoded
     * @return bool
     */
    public static function has_open_altbestand(?array $decoded): bool {
        return is_array($decoded) && is_array($decoded['vorheriger_ort'] ?? null);
    }

    /**
     * Ob mindestens eines der beiden Ziele eines bereits gelesenen
     * Kontextpointers extern liegt - roh am Feld "ort" geprueft, ohne die
     * volle Aufloesungspruefung von {@see context_pointer::resolve_target()}:
     * fuer die Zaehlung in Statusprüfung 1 (Spec §12) reicht das rohe Feld,
     * ein strukturell kaputter Pointer zaehlt hier bewusst nicht mit (er
     * erscheint stattdessen als defekter Pointer in der Verbindungsübersicht).
     *
     * @param array|null $decoded
     * @return bool
     */
    public static function has_external_target(?array $decoded): bool {
        if ($decoded === null) {
            return false;
        }
        foreach (self::TARGETS as $target) {
            $value = $decoded[$target] ?? null;
            if (is_array($value) && ($value['ort'] ?? null) === pointer_location::EXTERN) {
                return true;
            }
        }
        return false;
    }

    /**
     * Alle Personen, deren Kontextpointer mindestens ein externes Ziel nennt
     * - die Anzahl aus Statusprüfung 1 (Spec §12, Schritt 1: "sonst WARNING
     * mit Anzahl").
     *
     * @return int[]
     */
    public static function userids_with_external_target(): array {
        $result = [];
        foreach (self::userids_with_pointer() as $userid) {
            if (self::has_external_target(self::raw_pointer_for($userid))) {
                $result[] = $userid;
            }
        }
        return $result;
    }

    /**
     * Der aufgeloeste Zustand eines Ziels fuer die Verbindungsübersicht
     * (Spec #486 §12): nie ein geworfener Fehler, ein struktureller Defekt
     * wird selbst zum Zustand. Liest ausschliesslich Pointer und Datenbank,
     * nie das Netz, nie einen Pfad (Akzeptanzkriterium).
     *
     * @param int $userid
     * @param array|null $decoded Ergebnis von {@see raw_pointer_for()}.
     * @param string $target "kontextbereich" oder "materialbestand".
     * @return array{state: string, host: ?string, defect: ?string}
     *         state: "offen"|"moodle"|"extern"|"kaputt".
     */
    public static function target_state(int $userid, ?array $decoded, string $target): array {
        if ($decoded === null) {
            return ['state' => self::STATE_OPEN, 'host' => null, 'defect' => null];
        }

        $pointerkey = $target === 'materialbestand' ? 'materialordner' : $target;
        try {
            $location = context_pointer::resolve_target($decoded, $pointerkey);
        } catch (\moodle_exception $e) {
            return ['state' => self::STATE_BROKEN, 'host' => null, 'defect' => self::DEFECT_INVALID];
        }

        if ($location->kind === pointer_location::MOODLE) {
            return ['state' => self::STATE_MOODLE, 'host' => null, 'defect' => null];
        }

        $host = (string) ($location->fingerprint['server'] ?? '');
        return ['state' => self::STATE_EXTERN, 'host' => $host, 'defect' => self::extern_defect($userid, $location)];
    }

    /**
     * Die drei benannten Pointer-Defekte einer externen Instanz (Spec §12:
     * "Instanz fehlt, gehört jemand anderem, http") - dieselben Pruefungen
     * 2/3 wie {@see \local_coursepilot\webdav\webdav_instance::resolve_owned()},
     * hier aber fuer eine beliebige Person statt $USER, und ohne dessen
     * Freischaltungs-/Sitzungspruefungen (die gelten nur fuer die eigene,
     * gerade angemeldete Person). Kein Netzzugriff: nur `repository_instances`
     * und `repository_instance_config`.
     *
     * @param int $userid
     * @param pointer_location $location
     * @return string|null "instanzfehlt"|"fremdeinstanz"|"http"|null (kein Defekt).
     */
    private static function extern_defect(int $userid, pointer_location $location): ?string {
        global $DB;

        $record = $DB->get_record_sql(
            'SELECT ri.id, ri.contextid
               FROM {repository_instances} ri
               JOIN {repository} r ON r.id = ri.typeid
              WHERE ri.id = :id AND r.type = :type',
            ['id' => $location->instanceid, 'type' => 'webdav']
        );
        if (!$record) {
            return self::DEFECT_INSTANCE_MISSING;
        }
        if ((int) $record->contextid !== \context_user::instance($userid)->id) {
            return self::DEFECT_FOREIGN_INSTANCE;
        }
        $webdavtype = $DB->get_field(
            'repository_instance_config',
            'value',
            ['instanceid' => $location->instanceid, 'name' => 'webdav_type']
        );
        return ((int) $webdavtype === 1) ? null : self::DEFECT_HTTP;
    }
}
