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

namespace local_coursepilot\history;

defined('MOODLE_INTERNAL') || die();

/**
 * Source of a history state as one concept (#596, Spec 0026 module 5, ADR 0028):
 * key, reference cmid and label live here instead of being known piecemeal by
 * version_writer, version_history::describe_meta and summary_line.
 *
 * The reference cmid is stored in the existing column `sourcecmid`: for
 * `cloned` it is the clone origin, for `superseded` the replacing activity
 * (no schema change, the column only ever held "the other cmid").
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class version_source {

    /** @var string Native Moodle write path. */
    public const MOODLE = 'moodle';

    /** @var string Retroactively recorded starting state (#386). */
    public const DISCOVERED = 'vorgefunden';

    /** @var string Clone, always version 1 (#421); reference = origin cmid. */
    public const CLONED = 'geklont';

    /** @var string Created from an activity XML (ADR 0028). */
    public const FROM_XML = 'from_xml';

    /** @var string Marker state on the old cmid after replacement; reference = new cmid. */
    public const SUPERSEDED = 'superseded';

    /**
     * Label for the summary line, "%s" is replaced by the reference cmid.
     * Unknown keys (future API clients) get no entry and fall back to the key.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        self::MOODLE => 'erster erfasster Stand',
        self::DISCOVERED => 'vorgefundener Ausgangsstand vor Coursepilot',
        self::CLONED => 'Klon der Aktivität %s',
        self::FROM_XML => 'aus Aktivitäts-XML angelegt',
        self::SUPERSEDED => 'abgelöst durch Aktivität %s',
    ];

    /** @var string[] Sources whose summary line is the label even when a predecessor exists. */
    private const MARKERS = [self::SUPERSEDED];

    /**
     * @param string $key
     * @param int|null $refcmid
     */
    public function __construct(
        public readonly string $key,
        public readonly ?int $refcmid = null,
    ) {
    }

    /**
     * @param \stdClass $record local_coursepilot_cm_version row
     * @return self
     */
    public static function from_record(\stdClass $record): self {
        return new self((string) $record->source, $record->sourcecmid !== null ? (int) $record->sourcecmid : null);
    }

    /**
     * @return bool true for the retroactive starting state
     */
    public function is_discovered(): bool {
        return $this->key === self::DISCOVERED;
    }

    /**
     * @return bool true if the state is a marker, not a content snapshot to diff
     */
    public function is_marker(): bool {
        return in_array($this->key, self::MARKERS, true);
    }

    /**
     * @return string teacher-facing label
     */
    public function label(): string {
        return sprintf(self::LABELS[$this->key] ?? $this->key, (string) $this->refcmid);
    }
}
