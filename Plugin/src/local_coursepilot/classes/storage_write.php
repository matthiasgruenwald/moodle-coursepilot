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
 * Conditional persistence shared by context and material operations.
 * Keeps the authorised preflight state until the adapter writes, and records
 * storage outages once without changing caller validation or conflict errors.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class storage_write {
    /** @var array{content:string,checksum:string,size:int,mimetype:string,timemodified:int}|null Authorised preflight state. */
    private readonly ?array $existing;

    /**
     * Selects the storage boundary and the pending-note destination.
     *
     * @param storage_port $port Selected adapter.
     * @param storage_area $area Area-specific validation and quota policy.
     * @param pointer_location $location Selected location.
     * @param string $path Relative target path.
     * @param int $courseid Course for pending writes.
     * @param string $operation Pending operation if the preflight fails.
     */
    public function __construct(
        /** @var storage_port Selected adapter. */
        private readonly storage_port $port,
        /** @var storage_area Target area. */
        private readonly storage_area $area,
        /** @var pointer_location Selected location. */
        private readonly pointer_location $location,
        /** @var string Relative target path. */
        private readonly string $path,
        /** @var int Course for pending writes. */
        private readonly int $courseid = 0,
        string $operation = pointer_writer::OP_UNKNOWN,
    ) {
        $this->existing = $this->read_target($operation);
    }

    /**
     * Reads before mutation; an outage aborts and records the pending operation.
     *
     * @param string $operation Pending operation if the target cannot be read.
     * @return array{content: string, checksum: string, size: int, mimetype: string, timemodified: int}|null
     */
    private function read_target(string $operation): ?array {
        try {
            return $this->port->read($this->area, $this->path);
        } catch (storage_failure $e) {
            throw pointer_writer::record_preread_failure($e, $this->location, $this->path, $operation, $this->courseid);
        } catch (\moodle_exception $e) {
            $operation = $operation === pointer_writer::OP_UNKNOWN ? pointer_writer::OP_CREATE : $operation;
            throw pointer_writer::record_location_failure($e, $this->path, $this->location, $operation, $this->courseid);
        } catch (\Throwable $e) {
            throw pointer_writer::record_storage_failure($e, $this->location, $this->path, $operation, $this->courseid);
        }
    }

    /**
     * Returns the immutable preflight content for area-specific authorisation.
     *
     * @return array{content:string,checksum:string,size:int,mimetype:string,timemodified:int}|null
     */
    public function existing(): ?array {
        return $this->existing;
    }

    /**
     * Writes only while the authorised preflight state remains unchanged.
     *
     * @param string $content Replacement content or appended bytes.
     * @param string $expectedchecksum Explicit client condition, otherwise the preflight checksum.
     * @param bool $append Append rather than replace.
     * @param string $source Optional serialised provenance for Private Files.
     * @return array{path: string, created: bool, size: int, checksum: string}
     */
    public function write(
        string $content,
        string $expectedchecksum = '',
        bool $append = false,
        string $source = ''
    ): array {
        $checksum = $expectedchecksum !== '' ? $expectedchecksum
            : ($this->existing['checksum'] ?? storage_port::MISSING_CHECKSUM);
        $operation = $append ? pending_write_translation::OP_APPEND
            : ($this->existing === null ? pending_write_translation::OP_CREATE : pending_write_translation::OP_OVERWRITE);
        return $this->persist($content, $checksum, $operation, $append, $source);
    }

    /**
     * Persists an authorised operation and translates storage failures once.
     *
     * @param string $content Replacement content or appended bytes.
     * @param string $checksum Authorised target state.
     * @param string $operation Pending operation category.
     * @param bool $append Append rather than replace.
     * @param string $source Serialised provenance for Private Files.
     * @return array{path: string, created: bool, size: int, checksum: string}
     */
    private function persist(string $content, string $checksum, string $operation, bool $append, string $source): array {
        try {
            return $append
                ? $this->port->append($this->area, $this->path, $content, $checksum)
                : $this->port->write($this->area, $this->path, $content, $checksum, $source);
        } catch (storage_conflict_exception $e) {
            throw $e;
        } catch (\moodle_exception $e) {
            if (
                in_array($e->errorcode, [
                    $this->area->invalidpathkey, $this->area->quotaerrorkey,
                    'contextfilenotmarkdown', 'materialfiledisallowedtype',
                ], true)
            ) {
                throw $e;
            }
            throw pointer_writer::record_storage_failure($e, $this->location, $this->path, $operation, $this->courseid);
        } catch (\Throwable $e) {
            throw pointer_writer::record_storage_failure($e, $this->location, $this->path, $operation, $this->courseid);
        }
    }
}
