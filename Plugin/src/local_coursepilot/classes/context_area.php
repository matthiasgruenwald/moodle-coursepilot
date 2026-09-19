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
 * Fuer Private Files laeuft die eigentliche Ablage seit diesem Issue ueber
 * den {@see storage_port}-Adapter {@see private_files_storage_port} - fuer
 * den externen Ort unveraendert ueber {@see pointer_writer}/{@see pointer_reader}
 * (deren Ausfallbehandlung, ADR 0023, ist noch nicht Teil dieses Vertrags -
 * das ist Issue #540).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_area {

    /**
     * Liest eine Kontextdatei zeigerbewusst und ortsneutral - wie
     * {@see context_files::read_content_pointer_aware()}, aber ohne das nur
     * intern gebrauchte "etag"-Feld: der Pruefwert steht bereits als
     * "contenthash" bereit, gleich ob Moodle- oder externer Ort. Der einzige
     * Aufrufer, der diese Unterscheidung noch braucht, ist diese Klasse
     * selbst - kein Werkzeug sieht sie mehr.
     *
     * @param string $path
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     */
    public static function read(string $path): ?array {
        return self::normalise_pointer_result(context_files::read_content_pointer_aware($path));
    }

    /**
     * Wie {@see read()}, aber fuer den vorherigen Ort (Issue #498) - siehe
     * {@see context_files::read_content_previous_location()}.
     *
     * @param string $path
     * @param pointer_location $location
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     */
    public static function read_previous_location(string $path, pointer_location $location): ?array {
        return self::normalise_pointer_result(context_files::read_content_previous_location($path, $location));
    }

    /**
     * Ersetzt ein nur intern gefuehrtes "etag"-Feld (Kontextbereich-Pruefwert,
     * Issue #513) durch den bereits nach aussen gedachten "contenthash" -
     * ortsneutral: ein Moodle-Ergebnis (kein "etag"-Schluessel) bleibt
     * unangetastet.
     *
     * @param array|null $file
     * @return array|null
     */
    private static function normalise_pointer_result(?array $file): ?array {
        if ($file === null || !array_key_exists('etag', $file)) {
            return $file;
        }
        $file['contenthash'] = pointer_reader::external_checkvalue($file['etag'], $file['timemodified']);
        unset($file['etag']);
        return $file;
    }

    /**
     * Listet eine Ebene des Kontextbereichs zeigerbewusst und ortsneutral:
     * derselbe Feldsatz fuer beide Orte, "locked" bereits ausgewertet - kein
     * Werkzeug muss dafuer noch selbst zwischen Moodle und extern
     * unterscheiden.
     *
     * @param string $path
     * @param bool $previouslocation Siehe {@see context_files::list_entries_previous_location()}.
     * @return array{directory: string, entries: array}
     */
    public static function list(string $path, bool $previouslocation = false): array {
        $result = $previouslocation
            ? context_files::list_entries_previous_location($path, altbestand::require_open_location())
            : context_files::list_entries_pointer_aware($path);

        return [
            'directory' => $result['directory'],
            'entries' => array_map(
                static fn (array $entry): array => self::annotate_entry($entry, $result['directory']),
                $result['entries']
            ),
        ];
    }

    /**
     * Ergaenzt einen Auflistungs-Eintrag um "locked" und einen ortsneutralen
     * "contenthash" - relocated aus
     * {@see \local_coursepilot\external\list_context_files} (Issue #538),
     * damit das Werkzeug selbst keine Ortsverzweigung mehr braucht.
     *
     * @param array $entry Ein Eintrag aus {@see context_files::list_entries_pointer_aware()}
     *        (traegt noch das interne "etag"-Feld, nur extern).
     * @param string $directory Ergebnis-Ordner, siehe {@see list()}.
     * @return array Derselbe Eintrag ohne "etag", mit "locked" und (extern,
     *         Dateien) einem gefuellten "contenthash".
     */
    private static function annotate_entry(array $entry, string $directory): array {
        $etag = $entry['etag'] ?? null;
        $isexternal = array_key_exists('etag', $entry);
        unset($entry['etag']);

        if ($entry['type'] === 'folder') {
            return $entry + ['locked' => false];
        }

        if ($isexternal) {
            $entry['contenthash'] = pointer_reader::external_checkvalue($etag, $entry['timemodified']);
        }

        // Nur .md-Dateien tragen ueberhaupt eine Personenbezugs-Markierung
        // (Frontmatter) - siehe die urspruengliche Begruendung in
        // list_context_files::annotate_locked() (Issue #506/#493).
        $ismarkdown = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) === 'md';
        if (!$ismarkdown || personal_data::allowed()) {
            return $entry + ['locked' => false];
        }

        return $entry + ['locked' => self::is_entry_marked($entry, $directory, $etag)];
    }

    /**
     * Prueft (mit Markierungsgedaechtnis) ob eine .md-Datei personenbezogen
     * markiert ist - relocated aus list_context_files::is_marked_cached()
     * (Issue #538).
     *
     * @param array $entry
     * @param string $directory
     * @param string|null $etag
     * @return bool
     */
    private static function is_entry_marked(array $entry, string $directory, ?string $etag): bool {
        $relativepath = $directory === '' ? $entry['name'] : $directory . '/' . $entry['name'];

        $marked = mark_memory::lookup($relativepath, $entry['size'], $entry['timemodified'], $etag);
        if ($marked === null) {
            $content = context_files::read_content_pointer_aware($relativepath);
            $marked = $content !== null && personal_data::is_marked($content['content']);
            mark_memory::remember($relativepath, $entry['size'], $entry['timemodified'], $etag, $marked);
        }

        return $marked;
    }

    /**
     * Schreibt eine Kontextdatei zeigerbewusst und ortsneutral: entscheidet
     * intern, ob Private Files (ueber den {@see storage_port}-Adapter
     * {@see private_files_storage_port}) oder der externe Ort
     * ({@see pointer_writer}) greift - kein Werkzeug aussen sieht diese
     * Entscheidung mehr, und Private Files laufen jetzt ueber denselben
     * Vertrag wie der externe Ort.
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash Siehe {@see context_files::write_pointer_aware()}.
     * @param string $ausstand Siehe {@see context_files::write_pointer_aware()}.
     * @param bool $createonly Siehe {@see context_files::write_pointer_aware()}.
     * @param int $courseid Siehe {@see context_files::write_pointer_aware()}.
     * @return array{path: string, created: bool, size: int, oldsize: int}
     */
    public static function write(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $ausstand = '',
        bool $createonly = false,
        int $courseid = 0
    ): array {
        $external = self::resolve_write_target($content, $path, $createonly, $courseid);
        if ($external) {
            return context_files::write_pointer_aware($path, $content, $createonly, $expectedcontenthash, $ausstand !== '', $courseid);
        }
        return self::write_moodle($path, $content, $expectedcontenthash, $createonly);
    }

    /**
     * Loest den Kontextpointer auf (Sonderfall Pruefung 8/IServ eingerechnet)
     * und wendet den Personenbezugs-Gate an - relocated aus
     * {@see \local_coursepilot\external\write_context_file::dispatch()}
     * (Issue #538). Gibt zurueck, ob der externe Zweig greift.
     *
     * @param string $content
     * @param string $path
     * @param bool $createonly
     * @param int $courseid
     * @return bool true, wenn der externe Zweig greift.
     */
    private static function resolve_write_target(string $content, string $path, bool $createonly, int $courseid): bool {
        try {
            $location = context_files::resolve_pointer_location();
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'webdaviservfilesonly') {
                self::guard_personal_data_for_write($content, null, $path, $createonly, $courseid);
                return true;
            }
            throw $e;
        }
        self::guard_personal_data_for_write($content, $location, $path, $createonly, $courseid);

        return $location !== null && $location->kind === pointer_location::EXTERN;
    }

    /**
     * Personenbezugs-Gate vor dem Schreiben - relocated aus
     * write_context_file::require_personal_data_allowed() (Issue #538),
     * unveraendertes Verhalten.
     *
     * @param string $content
     * @param pointer_location|null $location
     * @param string $path
     * @param bool $createonly
     * @param int $courseid
     * @throws \moodle_exception contextfilelocked, contextfilealreadyexists, ausstandwritefailed
     */
    private static function guard_personal_data_for_write(
        string $content,
        ?pointer_location $location,
        string $path,
        bool $createonly,
        int $courseid = 0
    ): void {
        if ($location !== null && $location->kind === pointer_location::EXTERN && !personal_data::allowed()) {
            try {
                $existing = pointer_reader::peek_external_content(context_files::area(), $path, $location);
            } catch (webdav_error $e) {
                throw pointer_writer::record_preread_failure(
                    $e,
                    $location,
                    $path,
                    $createonly ? pointer_writer::OP_CREATE : pointer_writer::OP_OVERWRITE,
                    $courseid
                );
            }
            if ($existing !== null && $createonly) {
                throw new \moodle_exception('contextfilealreadyexists', 'local_coursepilot', '', $path);
            }
            if ($existing !== null && personal_data::is_marked($existing)) {
                throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
            }
        }

        if (!personal_data::is_marked($content)) {
            return;
        }
        if (!personal_data::allowed()) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }
        personal_data_hosts::require_allowed_location($location, $path);
    }

    /**
     * Der Moodle-Zweig von {@see write()} - laeuft ueber
     * {@see private_files_storage_port} statt ueber eine eigene
     * Schreibchoreografie (Issue #538, Spec 0021 Abnahmekriterium "Auch das
     * Schreiben in Private Files laeuft ueber den Anker").
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash
     * @param bool $createonly
     * @return array{path: string, created: bool, size: int, oldsize: int}
     * @throws \moodle_exception contextfilealreadyexists, contextfilelocked,
     *         contextfilechanged, contextquotaexceeded
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    private static function write_moodle(string $path, string $content, string $expectedcontenthash, bool $createonly): array {
        context_files::require_manage_own_files();

        $port = new private_files_storage_port();
        $existing = $port->read(context_files::area(), $path);

        if ($existing !== null && $createonly) {
            throw new \moodle_exception('contextfilealreadyexists', 'local_coursepilot', '', $path);
        }
        self::guard_existing_locked($existing, $path);
        if ($expectedcontenthash !== '' && (!$existing || $existing['checksum'] !== $expectedcontenthash)) {
            throw new \moodle_exception('contextfilechanged', 'local_coursepilot', '', $path);
        }

        $written = $port->write(context_files::area(), $path, $content);

        return [
            'path' => $written['path'],
            'created' => $written['created'],
            'size' => $written['size'],
            'oldsize' => $existing['size'] ?? 0,
        ];
    }

    /**
     * Sperrt eine bereits vorhandene, personenbezogen markierte Zieldatei bei
     * ausgeschaltetem #344-Schalter - gemeinsame Absage von
     * {@see write_moodle()} und {@see append_moodle()} (Standards-Review zu
     * Issue #538: beide Kopien lagen vorher in getrennten Tool-Klassen,
     * durch die Relocation hierher nebeneinander sichtbar geworden).
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
     * Haengt an eine Kontextdatei zeigerbewusst und ortsneutral an - siehe
     * {@see write()}.
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash Siehe {@see context_files::append_pointer_aware()}.
     * @param string $ausstand Siehe {@see context_files::append_pointer_aware()}.
     * @param int $courseid Siehe {@see context_files::append_pointer_aware()}.
     * @return array{path: string, created: bool, size: int}
     */
    public static function append(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $ausstand = '',
        int $courseid = 0
    ): array {
        if (self::resolve_append_target($path, $content, $courseid)) {
            return context_files::append_pointer_aware($path, $content, $expectedcontenthash, $ausstand !== '', $courseid);
        }
        return self::append_moodle($path, $content);
    }

    /**
     * Loest den Kontextpointer auf (Sonderfall Pruefung 8/IServ eingerechnet)
     * - relocated aus append_context_file::execute() (Issue #538). Gibt
     * zurueck, ob der externe Zweig greift; wendet dort zugleich das
     * Personenbezugs-Gate der Zieldatei an.
     *
     * @param string $path
     * @param string $content
     * @param int $courseid
     * @return bool
     */
    private static function resolve_append_target(string $path, string $content, int $courseid): bool {
        try {
            $location = context_files::resolve_pointer_location();
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'webdaviservfilesonly') {
                self::guard_personal_data_for_append($path, $content, $courseid);
                return true;
            }
            throw $e;
        }
        if ($location !== null && $location->kind === pointer_location::EXTERN) {
            self::guard_personal_data_for_append($path, $content, $courseid);
            return true;
        }
        return false;
    }

    /**
     * Personenbezugs-Gate vor dem Anhaengen am externen Ort - relocated aus
     * append_context_file::guard_personal_data_external() (Issue #538),
     * unveraendertes Verhalten.
     *
     * @param string $path
     * @param string $content
     * @param int $courseid
     * @throws \moodle_exception contextfilelocked
     */
    private static function guard_personal_data_for_append(string $path, string $content, int $courseid = 0): void {
        $existingcontent = self::peek_append_target_content($path, $courseid);
        if ($existingcontent !== null && !personal_data::allowed() && personal_data::is_marked($existingcontent)) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }

        $finalcontent = ($existingcontent ?? '') . $content;
        if (personal_data::is_marked($finalcontent)) {
            try {
                personal_data_hosts::require_allowed_location(context_files::resolve_pointer_location(), $path);
            } catch (\moodle_exception $e) {
                if ($e->errorcode !== 'webdaviservfilesonly') {
                    throw $e;
                }
            }
        }
    }

    /**
     * Liest die bereits vorhandene externe Zieldatei fuer das
     * Anhaengen-Gate, tolerant gegen eine unaufloesbare Instanz/Verbindung -
     * relocated aus append_context_file::peek_existing_content() (Issue
     * #538), unveraendertes Verhalten.
     *
     * @param string $path
     * @param int $courseid
     * @return string|null
     */
    private static function peek_append_target_content(string $path, int $courseid = 0): ?string {
        try {
            $location = context_files::resolve_pointer_location();
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'webdaviservfilesonly') {
                throw $e;
            }
            return null;
        }
        if ($location === null || $location->kind !== pointer_location::EXTERN) {
            return null;
        }
        try {
            return pointer_reader::peek_external_content(context_files::area(), $path, $location);
        } catch (webdav_error $e) {
            throw pointer_writer::record_preread_failure($e, $location, $path, pointer_writer::OP_APPEND, $courseid);
        }
    }

    /**
     * Der Moodle-Zweig von {@see append()} - laeuft ueber
     * {@see private_files_storage_port} (Issue #538).
     *
     * @param string $path
     * @param string $content
     * @return array{path: string, created: bool, size: int}
     * @throws \moodle_exception contextfilelocked, contextquotaexceeded
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    private static function append_moodle(string $path, string $content): array {
        context_files::require_manage_own_files();

        $port = new private_files_storage_port();
        $existing = $port->read(context_files::area(), $path);
        self::guard_existing_locked($existing, $path);

        $result = $port->append(context_files::area(), $path, $content);

        return ['path' => $result['path'], 'created' => $result['created'], 'size' => $result['size']];
    }
}
