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
 * Erster Adapter des Ablage-Vertrags (Issue #536, Spec 0021): Moodles
 * Private Files. Erfuellt {@see storage_port} vollstaendig, indem er fuer
 * Pfadaufloesung, Segmentpruefung, Quotenpruefung und die
 * Schreibchoreografie mit Zwischendatei auf {@see storage_anchor} aufsetzt -
 * dieselben Bausteine, die {@see context_files} und {@see material_files}
 * heute schon nutzen, hier hinter der neuen, ortsneutralen Schnittstelle.
 *
 * Kennt bewusst keinen Kontextpointer: die Bereiche, die dieser Adapter
 * entgegennimmt, tragen keinen {@see storage_area::$pointerkey}, damit
 * {@see storage_anchor::root()} nie in die Pointer-Aufloesung des
 * bisherigen Wegs verzweigt (siehe dortige Kurzschlussregel). Welcher
 * Adapter greift, entscheidet erst ein spaeteres Ticket, nicht dieser
 * Adapter selbst.
 *
 * Der Pruefwert dieses Adapters ist Moodles `contenthash`.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class private_files_storage_port implements storage_port {

    /**
     * @param pointer_location|null $location Der bereits serverseitig
     *        aufgeloeste Moodle-Ort. `null` bleibt fuer den Vertragstest ohne
     *        Pointer moeglich.
     */
    public function __construct(private readonly ?pointer_location $location = null) {
    }

    /**
     * @inheritDoc
     */
    public function read(storage_area $area, string $path): ?array {
        [$directory, $filename] = $this->resolve_file($area, $path);
        $found = storage_anchor::read_content($directory, $filename);
        if ($found === null) {
            return null;
        }
        return [
            'content' => $found['content'],
            'checksum' => $found['contenthash'],
            'size' => $found['size'],
            'mimetype' => $found['mimetype'],
            'timemodified' => $found['timemodified'],
        ];
    }

    /**
     * @inheritDoc
     */
    public function list(storage_area $area, string $path): array {
        $directory = $this->resolve_directory($area, $path);
        $entries = [];
        foreach (storage_anchor::list_entries($directory) as $entry) {
            $entries[] = [
                'name' => $entry['name'],
                'type' => $entry['type'],
                'size' => $entry['size'],
                'mimetype' => $entry['mimetype'],
                'checksum' => $entry['contenthash'],
                'timemodified' => $entry['timemodified'],
            ];
        }
        return $entries;
    }

    /**
     * @inheritDoc
     */
    public function write(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
        [$directory, $filename] = $this->resolve_writable_file($area, $path);
        $clientpath = storage_anchor::normalise_client_path($area, $path);
        $existing = storage_anchor::read_content($directory, $filename);
        $this->require_checksum_match($existing, $expectedchecksum, $clientpath);

        $oldsize = $existing['size'] ?? 0;
        storage_anchor::require_quota($area, strlen($content) - $oldsize);
        storage_anchor::write($directory, $filename, $content);

        $written = storage_anchor::read_content($directory, $filename);
        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => $written['size'],
            'checksum' => $written['contenthash'],
        ];
    }

    /**
     * @inheritDoc
     */
    public function append(storage_area $area, string $path, string $content): array {
        [$directory, $filename] = $this->resolve_writable_file($area, $path);
        $clientpath = storage_anchor::normalise_client_path($area, $path);
        $existing = storage_anchor::read_content($directory, $filename);

        storage_anchor::require_quota($area, strlen($content));
        $size = storage_anchor::append($directory, $filename, $content);

        $written = storage_anchor::read_content($directory, $filename);
        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => $size,
            'checksum' => $written['contenthash'],
        ];
    }

    /**
     * @inheritDoc
     */
    public function delete(storage_area $area, string $path): bool {
        [$directory, $filename] = $this->resolve_file($area, $path);
        return storage_anchor::delete($directory, $filename);
    }

    /** @return array{0: string, 1: string} */
    private function resolve_file(storage_area $area, string $path): array {
        $normalised = storage_anchor::normalise_client_path($area, $path);
        if ($normalised === '') {
            throw new \moodle_exception($area->invalidpathkey, 'local_coursepilot');
        }
        $segments = explode('/', $normalised);
        $filename = array_pop($segments);
        return [$this->resolve_directory($area, implode('/', $segments)), $filename];
    }

    /** @return array{0: string, 1: string} */
    private function resolve_writable_file(storage_area $area, string $path): array {
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        return [$this->resolve_directory($area, implode('/', $folders)), $filename];
    }

    private function resolve_directory(storage_area $area, string $path): string {
        $relative = storage_anchor::normalise_client_path($area, $path);
        $root = $this->location === null
            ? storage_anchor::resolve_directory($area, '')
            : rtrim((string) $this->location->path, '/') . '/';
        return $relative === '' ? $root : $root . $relative . '/';
    }

    /**
     * Weist ein bedingtes Schreiben ab, dessen Pruefwert nicht (mehr) zum
     * aktuellen Stand passt - auch wenn die Datei inzwischen ganz fehlt.
     * Kein Vergleich, wenn kein Pruefwert mitgegeben wurde (`null`): dann
     * gilt weiterhin ungeprueftes Ueberschreiben/Anlegen.
     *
     * @param array{content: string, mimetype: string, size: int, contenthash: string,
     *        timemodified: int}|null $existing
     * @param string|null $expectedchecksum
     * @param string $clientpath Fuer die Fehlermeldung.
     * @throws storage_conflict_exception
     */
    private function require_checksum_match(?array $existing, ?string $expectedchecksum, string $clientpath): void {
        if ($expectedchecksum === null) {
            return;
        }
        if ($expectedchecksum === storage_port::MISSING_CHECKSUM) {
            if ($existing !== null) {
                throw new storage_conflict_exception($clientpath);
            }
            return;
        }
        $currentchecksum = $existing['contenthash'] ?? null;
        if ($currentchecksum !== $expectedchecksum) {
            throw new storage_conflict_exception($clientpath);
        }
    }
}
