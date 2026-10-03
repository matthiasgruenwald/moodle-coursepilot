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

use local_coursepilot\webdav\webdav_error;

/**
 * Die vier Kontextbereich-Werkzeugoperationen (lesen, schreiben, anhaengen,
 * auflisten), ortsneutral fuer den Aufrufer (Issue #538, Spec 0021): kein
 * Werkzeug in {@see \local_coursepilot\external\write_context_file} und
 * seinen drei Geschwistern verzweigt mehr selbst auf Ortsart, Ortsfehler-
 * schluessel oder ein "etag"-Sonderfeld - diese Entscheidungen wandern hier
 * herein.
 *
 * Von {@see context_files} getrennt gehalten (die Klasse naeherte sich sonst
 * der 800-Zeilen-Grenze aus den Coding-Standards) - dasselbe Muster, mit dem
 * {@see pointer_reader}/{@see pointer_writer} bereits von {@see storage_anchor}
 * getrennt wurden. Anders als jene beiden bleibt diese Klasse bewusst auf den
 * Kontextbereich fixiert (`context_files::area()`), statt einen Bereich als
 * Parameter zu nehmen - noch kein zweiter Aufrufer braucht das (YAGNI, ADR
 * 0020).
 *
 * All four operations run through {@see storage_anchor::port()}, i.e. the
 * {@see storage_port} adapters {@see private_files_storage_port} and
 * {@see webdav_storage_port} (Issue #645). Failure handling (ADR 0023,
 * pending note without fallback) applies to both locations: the WebDAV
 * adapter records the note itself, Private Files persistence failures are
 * recorded here ({@see persist_moodle_write()}/{@see persist_moodle_append()},
 * via {@see pending_write_translation}).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_area {

    /**
     * Reads a context file through the current location's adapter. The
     * checksum is returned as "contenthash" for both locations.
     *
     * @param string $path
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     */
    public static function read(string $path): ?array {
        return self::read_from(storage_anchor::port(context_files::area()), $path);
    }

    /**
     * Like {@see read()}, but for the read-only previous location (Issue #498).
     *
     * @param string $path
     * @param pointer_location $location
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     */
    public static function read_previous_location(string $path, pointer_location $location): ?array {
        return self::read_from(storage_anchor::port_at($location), $path);
    }

    /**
     * @param storage_port $port
     * @param string $path
     * @return array|null
     */
    private static function read_from(storage_port $port, string $path): ?array {
        $area = context_files::area();
        try {
            $file = $port->read($area, $path);
        } catch (webdav_error $e) {
            throw pointer_reader::webdav_exception($e);
        }
        return $file === null ? null : [
            'path' => storage_anchor::normalise_client_path($area, $path),
            'content' => $file['content'],
            'mimetype' => $file['mimetype'],
            'size' => $file['size'],
            'contenthash' => $file['checksum'],
            'timemodified' => $file['timemodified'],
        ];
    }

    /**
     * Lists one level of the context area with the same field set for both
     * locations, "locked" already evaluated.
     *
     * @param string $path
     * @param bool $previouslocation Lists the read-only previous location instead.
     * @return array{directory: string, entries: array}
     */
    public static function list(string $path, bool $previouslocation = false): array {
        $area = context_files::area();
        $port = $previouslocation
            ? storage_anchor::port_at(previous_location::require_open_location())
            : storage_anchor::port($area);
        $directory = storage_anchor::normalise_client_path($area, $path);
        try {
            $entries = $port->list($area, $path);
        } catch (webdav_error $e) {
            throw pointer_reader::webdav_exception($e);
        }

        return [
            'directory' => $directory,
            'entries' => array_map(
                static fn (array $entry): array => self::annotate_entry($port, $entry, $directory),
                $entries
            ),
        ];
    }

    /**
     * Renames the adapter checksum to "contenthash" and adds "locked".
     *
     * @param storage_port $port The adapter that listed the entry.
     * @param array $entry An entry from {@see storage_port::list()}.
     * @param string $directory Result directory, see {@see list()}.
     * @return array
     */
    private static function annotate_entry(storage_port $port, array $entry, string $directory): array {
        $checksum = $entry['checksum'];
        unset($entry['checksum']);

        if ($entry['type'] === 'folder') {
            return $entry + ['contenthash' => '', 'locked' => false];
        }
        $entry['contenthash'] = $checksum;

        // Nur .md-Dateien tragen ueberhaupt eine Personenbezugs-Markierung
        // (Frontmatter, Issue #506/#493).
        $ismarkdown = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) === 'md';
        if (!$ismarkdown || personal_data::allowed()) {
            return $entry + ['locked' => false];
        }

        return $entry + ['locked' => self::is_entry_marked($port, $entry, $directory)];
    }

    /**
     * Whether a .md file is marked as personal data, via the mark memory
     * (Issue #493) keyed by the adapter checksum. The file is read from the
     * same adapter that listed it.
     *
     * @param storage_port $port
     * @param array $entry
     * @param string $directory
     * @return bool
     */
    private static function is_entry_marked(storage_port $port, array $entry, string $directory): bool {
        $relativepath = $directory === '' ? $entry['name'] : $directory . '/' . $entry['name'];

        $marked = mark_memory::lookup($relativepath, $entry['size'], $entry['timemodified'], $entry['contenthash']);
        if ($marked === null) {
            $content = self::read_from($port, $relativepath);
            $marked = $content !== null && personal_data::is_marked($content['content']);
            mark_memory::remember($relativepath, $entry['size'], $entry['timemodified'], $entry['contenthash'], $marked);
        }

        return $marked;
    }

    /**
     * Writes a context file through the adapter of the current location
     * ({@see storage_anchor::port()}); no tool sees the location decision.
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash Siehe {@see context_files::write_pointer_aware()}.
     * @param string $pendingentry Siehe {@see context_files::write_pointer_aware()}.
     * @param bool $createonly Siehe {@see context_files::write_pointer_aware()}.
     * @param int $courseid Siehe {@see context_files::write_pointer_aware()}.
     * @return array{path: string, created: bool, size: int, oldsize: int}
     */
    public static function write(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $pendingentry = '',
        bool $createonly = false,
        int $courseid = 0
    ): array {
        $area = context_files::area();
        storage_anchor::writable_segments($area, $path);
        try {
            $location = storage_anchor::effective_location($area);
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'webdaviservfilesonly') {
                throw $e;
            }
            if (personal_data::is_marked($content) && !personal_data::allowed()) {
                throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
            }
            throw pointer_writer::record_location_failure($e, $path, null, pointer_writer::OP_CREATE, $courseid);
        }
        if ($location->kind === pointer_location::MOODLE) {
            context_files::require_manage_own_files();
        }
        $port = storage_anchor::port($area, $courseid);
        try {
            $existing = $port->read($area, $path);
        } catch (webdav_error $e) {
            throw pointer_writer::record_preread_failure($e, $location, $path, pointer_writer::OP_UNKNOWN, $courseid);
        } catch (\moodle_exception $e) {
            throw pointer_writer::record_location_failure($e, $path, $location, pointer_writer::OP_CREATE, $courseid);
        }
        if ($existing !== null && $createonly) {
            throw new \moodle_exception('contextfilealreadyexists', 'local_coursepilot', '', $path);
        }
        self::guard_existing_locked($existing, $path);
        if (personal_data::is_marked($content)) {
            if (!personal_data::allowed()) {
                throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
            }
            personal_data_hosts::require_allowed_location($location, $path);
        }
        if ($pendingentry !== '' && $existing !== null && $expectedcontenthash === '') {
            throw new storage_conflict_exception($path);
        }
        // Reuse the preflight read as the write condition. This closes the
        // read-write window without exposing location-specific concurrency.
        $checksum = $expectedcontenthash !== '' ? $expectedcontenthash : ($existing['checksum'] ?? storage_port::MISSING_CHECKSUM);
        $operation = $existing === null ? pending_write_translation::OP_CREATE : pending_write_translation::OP_OVERWRITE;
        $written = $location->kind === pointer_location::MOODLE
            ? self::persist_moodle_write($port, $path, $content, $operation, $courseid, $checksum)
            : $port->write($area, $path, $content, $checksum);
        return [
            'path' => $written['path'],
            'created' => $written['created'],
            'size' => $written['size'],
            'oldsize' => $existing['size'] ?? 0,
        ];
    }

    /**
     * Der eigentliche Schreibvorgang, umschlossen von der Ausfallbehandlung
     * (Issue #540, ADR 0023 "an beiden Orten"): Pfad-, Endungs- und
     * Quotenpruefung sowie ein Pruefwert-Konflikt
     * ({@see storage_conflict_exception}) bleiben Aufruffehler; jeder andere Ausfall, der beim Persistieren selbst entsteht
     * (z.B. Private-Files-Speicher/Datenbank), vermerkt einen Ausstand, bevor
     * der Fehler zurueckgeht - nie roh durchgereicht.
     *
     * @param storage_port $port
     * @param string $path
     * @param string $content
     * @param string $operation Eine der {@see pending_write_translation}-OP_*-Konstanten.
     * @param int $courseid
     * @return array{path: string, created: bool, size: int, checksum: string}
     * @throws \moodle_exception contextquotaexceeded, pendingwritefailed, pendingnotewritefailed
     */
    private static function persist_moodle_write(
        storage_port $port,
        string $path,
        string $content,
        string $operation,
        int $courseid,
        ?string $expectedchecksum = null
    ): array {
        try {
            return $port->write(context_files::area(), $path, $content, $expectedchecksum);
        } catch (storage_conflict_exception $e) {
            throw $e;
        } catch (\moodle_exception $e) {
            if (self::is_moodle_call_error($e)) {
                throw $e;
            }
            throw self::record_moodle_storage_failure($e->errorcode, $e->getMessage(), $path, $operation, $courseid);
        } catch (\Throwable $e) {
            throw self::record_moodle_storage_failure(get_class($e), $e->getMessage(), $path, $operation, $courseid);
        }
    }

    /**
     * Aufruffehler, die {@see private_files_storage_port::write()}/{@see private_files_storage_port::append()}
     * selbst noch werfen koennen (Pfad-/Endungs-/Quotenpruefung liegt dort) -
     * zaehlen weiterhin nicht als Ausstand (Issue #540 Abnahmekriterium 2,
     * ADR 0023 Punkt 2). Ohne diese Ausnahme wuerde z.B. eine falsche
     * Dateiendung faelschlich als Speicherausfall vermerkt, nur weil sie erst
     * beim tatsaechlichen Schreibversuch durchschlaegt statt vorher.
     *
     * @param \moodle_exception $e
     * @return bool
     */
    private static function is_moodle_call_error(\moodle_exception $e): bool {
        $area = context_files::area();
        return in_array($e->errorcode, [$area->invalidpathkey, $area->quotaerrorkey, 'contextfilenotmarkdown'], true);
    }

    /**
     * Vermerkt einen Ausstand fuer einen Ausfall beim Persistieren in Private
     * Files (Issue #540, ADR 0023 "an beiden Orten") - dieselbe fuenfteilige
     * Ausfallantwort wie extern ({@see pointer_writer}), nur ohne
     * Instanzname/Host: es gibt keine Verbindung, die ausfallen koennte, nur
     * die eigene Moodle-Ablage selbst.
     *
     * @param string $errorclass
     * @param string $rawmessage
     * @param string $path
     * @param string $operation
     * @param int $courseid
     * @return \moodle_exception
     */
    private static function record_moodle_storage_failure(
        string $errorclass,
        string $rawmessage,
        string $path,
        string $operation,
        int $courseid
    ): \moodle_exception {
        return pending_write_translation::record_and_translate(
            $errorclass,
            'Private Files ' . $errorclass . ': ' . $rawmessage,
            $path,
            $operation,
            'Ihre privaten Dateien in Moodle sind gerade nicht beschreibbar – an Ihrem Speicher ist etwas zu tun',
            'Ihre privaten Dateien in Moodle',
            $courseid
        );
    }

    /**
     * Sperrt eine bereits vorhandene, personenbezogen markierte Zieldatei bei
     * ausgeschaltetem #344-Schalter - gemeinsame Absage von {@see write()}
     * und {@see append()} an beiden Orten.
     *
     * @param array{content: string}|null $existing Ergebnis von {@see private_files_storage_port::read()}.
     * @param string $path
     * @throws \moodle_exception contextfilelocked
     */
    private static function guard_existing_locked(?array $existing, string $path): void {
        if ($existing !== null && !personal_data::allowed() && personal_data::is_marked($existing['content'])) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }
    }

    /**
     * Haengt an eine Kontextdatei ortsneutral an - siehe
     * {@see write()}.
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash Siehe {@see context_files::append_pointer_aware()}.
     * @param string $pendingentry Siehe {@see context_files::append_pointer_aware()}.
     * @param int $courseid Siehe {@see context_files::append_pointer_aware()}.
     * @return array{path: string, created: bool, size: int}
     */
    public static function append(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $pendingentry = '',
        int $courseid = 0
    ): array {
        $area = context_files::area();
        storage_anchor::writable_segments($area, $path);
        try {
            $location = storage_anchor::effective_location($area);
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'webdaviservfilesonly') {
                throw $e;
            }
            throw pointer_writer::record_location_failure($e, $path, null, pointer_writer::OP_APPEND, $courseid);
        }
        if ($location->kind === pointer_location::MOODLE) {
            context_files::require_manage_own_files();
        }
        $port = storage_anchor::port($area, $courseid);
        try {
            $existing = $port->read($area, $path);
        } catch (webdav_error $e) {
            throw pointer_writer::record_preread_failure($e, $location, $path, pointer_writer::OP_APPEND, $courseid);
        } catch (\moodle_exception $e) {
            throw pointer_writer::record_location_failure($e, $path, $location, pointer_writer::OP_APPEND, $courseid);
        }
        self::guard_existing_locked($existing, $path);
        $finalcontent = ($existing['content'] ?? '') . $content;
        if (personal_data::is_marked($finalcontent)) {
            if (!personal_data::allowed()) {
                throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
            }
            personal_data_hosts::require_allowed_location($location, $path);
        }
        if ($expectedcontenthash !== '' && ($existing === null || $existing['checksum'] !== $expectedcontenthash)) {
            throw new storage_conflict_exception($path);
        }
        if ($pendingentry !== '' && $existing !== null && $expectedcontenthash === '') {
            throw new storage_conflict_exception($path);
        }
        $written = $location->kind === pointer_location::MOODLE
            ? self::persist_moodle_append($port, $path, $content, pending_write_translation::OP_APPEND, $courseid)
            : $port->append($area, $path, $content);
        return ['path' => $written['path'], 'created' => $written['created'], 'size' => $written['size']];
    }

    /**
     * Der eigentliche Anhaengevorgang, umschlossen von der Ausfallbehandlung
     * - siehe {@see persist_moodle_write()}.
     *
     * @param storage_port $port
     * @param string $path
     * @param string $content
     * @param string $operation
     * @param int $courseid
     * @return array{path: string, created: bool, size: int, checksum: string}
     * @throws \moodle_exception contextquotaexceeded, pendingwritefailed, pendingnotewritefailed
     */
    private static function persist_moodle_append(
        storage_port $port,
        string $path,
        string $content,
        string $operation,
        int $courseid
    ): array {
        try {
            return $port->append(context_files::area(), $path, $content);
        } catch (\moodle_exception $e) {
            if (self::is_moodle_call_error($e)) {
                throw $e;
            }
            throw self::record_moodle_storage_failure($e->errorcode, $e->getMessage(), $path, $operation, $courseid);
        } catch (\Throwable $e) {
            throw self::record_moodle_storage_failure(get_class($e), $e->getMessage(), $path, $operation, $courseid);
        }
    }
}
