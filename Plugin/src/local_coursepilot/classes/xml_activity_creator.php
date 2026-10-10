<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

use invalid_parameter_exception;
use local_coursepilot\catalog\registry;
use local_coursepilot\history\retention;
use local_coursepilot\history\version_writer;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Create a developed activity type from an activity XML (Spec 0026 module 3, ADR 0028).
 *
 * Order matters: kind gate, parse (invalid XML writes nothing), restore hidden, export and
 * round-trip, on deviation discard in the same call, otherwise show (unless $hidden) and
 * only THEN capture the history (earlier it would create a version 2 through
 * course_module_updated). Creating only, never editing.
 *
 * Superseding (#591, ADR 0028): with $replacescmid the new activity is placed directly
 * behind the old one, the old one is only hidden (name untouched, nothing deleted) and
 * gets a marker state "superseded by new cmid". References to the old cmid are reported,
 * never resolved. Repeated superseding forms a chain A -> B -> C (#600): the result names the
 * newest successor of an already superseded $replacescmid and the number of hidden
 * predecessors, both as hints, nothing is blocked. The marker is no restore risk: only developed kinds are superseded
 * (same modname as the old cm), and those have no restore path (catalog_for requires a catalog).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class xml_activity_creator {
    /** Text marker of a file reference; files are out of scope of the comparison. */
    private const FILE_MARKER = '@@PLUGINFILE@@';

    /** Actual value reported for an element absent from the output. */
    private const MISSING = '(missing)';

    /** Moodle backup NULL marker, equal to an empty value. */
    private const NULL_MARKER = '$@NULL@$';

    /**
     * @param int $courseid
     * @param string $modname developed (not catalogued, not excluded) activity type
     * @param int $sectionnum target section number
     * @param string $activityxml activity XML (`<mod>.xml`)
     * @param bool $hidden leave the activity hidden after the check
     * @param int|null $replacescmid supersede this activity of the same type in the same course;
     *        $sectionnum is then ignored (the new one lands behind the old one)
     * @param array $files Declared file-area supplements from material paths.
     * @return array{cmid: int, presets: string[], references: array, successor_cmid: int, hidden_predecessors: int}
     * references: {@see cm_references::references_to()} of the old cmid, empty without $replacescmid;
     * successor_cmid/hidden_predecessors: {@see self::chain()}, 0 without $replacescmid
     * @throws moodle_exception kind gate, xmlroundtripmismatch
     * @throws invalid_parameter_exception invalid XML, modname mismatch or unusable $replacescmid
     */
    public static function create(
        int $courseid,
        string $modname,
        int $sectionnum,
        string $activityxml,
        bool $hidden = false,
        ?int $replacescmid = null,
        array $files = []
    ): array {
        global $USER;
        registry::require_developed($modname, 'createfromxmlcatalogued');
        self::assert_valid($activityxml, $modname);
        activity_file_supplement::validate($modname, \context_course::instance($courseid), $files);
        if ($replacescmid !== null) {
            self::assert_replaceable($replacescmid, $courseid, $modname);
        }
        $references = $replacescmid === null ? [] : cm_references::references_to($replacescmid);
        $chain = $replacescmid === null
            ? ['successor_cmid' => 0, 'hidden_predecessors' => 0]
            : self::chain($courseid, $replacescmid);
        $oldvisible = $replacescmid === null || (bool) get_fast_modinfo($courseid)->get_cm($replacescmid)->visible;

        $cmid = activity_backup::restore($courseid, $sectionnum, $activityxml, true);
        try {
            $cm = get_coursemodule_from_id($modname, $cmid, $courseid, false, MUST_EXIST);
            $result = self::compare($activityxml, activity_backup::export($cm));
            if ($result['mismatches']) {
                $first = $result['mismatches'][0];
                throw new moodle_exception('xmlroundtripmismatch', 'local_coursepilot', '', [
                    'path' => $first['path'],
                    'expected' => shorten_text($first['expected'], 80),
                    'actual' => shorten_text($first['actual'], 80),
                    'count' => count($result['mismatches']),
                ]);
            }
            activity_file_supplement::apply($cm, $files);
            if (!$hidden) {
                course_module_placement::set_visible($cmid, true);
            }
            if ($replacescmid !== null) {
                self::supersede($cmid, $replacescmid, (int) $USER->id);
            }
        } catch (\Throwable $e) {
            $cleanupfailures = [];
            try {
                if ($replacescmid !== null) {
                    course_module_placement::set_visible($replacescmid, $oldvisible);
                }
            } catch (\Throwable $cleanup) {
                $cleanupfailures[] = 'Predecessor visibility: ' . $cleanup->getMessage();
            }
            try {
                course_module_placement::discard_failed($cmid);
            } catch (\Throwable $cleanup) {
                $cleanupfailures[] = 'New activity deletion: ' . $cleanup->getMessage();
            }
            if ($cleanupfailures) {
                throw new moodle_exception('xmlactivitycleanupincomplete', 'local_coursepilot', '', (object) [
                    'cmid' => $cmid, 'predecessor' => $replacescmid ?? 0,
                ], 'Creation failed: ' . $e->getMessage() . '; ' . implode('; ', $cleanupfailures));
            }
            throw $e;
        }
        // Last: drop what the observers wrote on the way, keep exactly one state.
        retention::purge_cm($cmid);
        version_writer::capture($cmid, (int) $USER->id, version_writer::SOURCE_FROM_XML);
        return ['cmid' => $cmid, 'presets' => $result['presets'], 'references' => $references] + $chain;
    }

    /**
     * Dry run of superseding: the same checks as create(), writes nothing, names what still
     * points at the old activity (Spec 0026 module 6, plan preview).
     *
     * @return array{references: array, successor_cmid: int, hidden_predecessors: int}
     * references: {@see cm_references::references_to()}; the rest: {@see self::chain()}
     * @throws moodle_exception kind gate
     * @throws invalid_parameter_exception invalid XML or unusable $replacescmid
     */
    public static function preview_supersede(int $courseid, string $modname, string $activityxml, int $replacescmid): array {
        registry::require_developed($modname, 'createfromxmlcatalogued');
        self::assert_valid($activityxml, $modname);
        self::assert_replaceable($replacescmid, $courseid, $modname);
        return ['references' => cm_references::references_to($replacescmid)] + self::chain($courseid, $replacescmid);
    }

    /**
     * Chain hints for superseding $oldcmid (#600), read from the superseded marker states.
     * successor_cmid: newest existing successor if $oldcmid is already superseded, else 0.
     * hidden_predecessors: hidden activities behind the new one once $oldcmid is hidden,
     * i.e. $oldcmid itself plus its hidden predecessors.
     * ponytail: links live in the marker states, which expire with the history retention and
     * vanish with a deleted middle version (purge_cm); such a gap ends the walk, so the hints
     * are a lower bound then. A persistent chain field (upgrade.php) if that ever matters.
     *
     * @return array{successor_cmid: int, hidden_predecessors: int}
     */
    private static function chain(int $courseid, int $oldcmid): array {
        global $DB;
        $cms = get_fast_modinfo($courseid)->cms;
        $markers = $DB->get_records(
            'local_coursepilot_cm_version',
            ['courseid' => $courseid, 'source' => version_writer::SOURCE_SUPERSEDED],
            'id ASC',
            'id, cmid, sourcecmid'
        );
        $next = [];
        $previous = [];
        foreach ($markers as $m) {
            if (isset($cms[$m->cmid], $cms[$m->sourcecmid])) {
                $next[(int) $m->cmid] = (int) $m->sourcecmid; // Ascending id: the latest marker wins.
                $previous[(int) $m->sourcecmid] = (int) $m->cmid;
            }
        }
        $successors = self::walk($next, $oldcmid);
        $hiddenpredecessors = array_filter(self::walk($previous, $oldcmid), static fn($cmid) => !$cms[$cmid]->visible);
        return ['successor_cmid' => end($successors) ?: 0, 'hidden_predecessors' => 1 + count($hiddenpredecessors)];
    }

    /**
     * Follows $links from $start, cycle-safe.
     *
     * @param array<int, int> $links cmid => linked cmid
     * @return int[] the linked cmids in walking order, $start excluded
     */
    private static function walk(array $links, int $start): array {
        $path = [$start => $start];
        for ($cmid = $start; isset($links[$cmid]) && !isset($path[$links[$cmid]]); $cmid = $links[$cmid]) {
            $path[$links[$cmid]] = $links[$cmid];
        }
        return array_values(array_slice($path, 1, null, true));
    }

    /**
     * Places the new activity behind the old one, hides the old one, notes the marker state.
     * Inside create()'s try block: a failure here discards the new activity.
     */
    private static function supersede(int $newcmid, int $oldcmid, int $userid): void {
        course_module_placement::place_after($newcmid, $oldcmid);
        course_module_placement::set_visible($oldcmid, false);
        version_writer::capture_superseded($oldcmid, $newcmid, $userid);
    }

    /**
     * @throws invalid_parameter_exception no activity of this type in this course
     */
    private static function assert_replaceable(int $cmid, int $courseid, string $modname): void {
        $cm = get_coursemodule_from_id($modname, $cmid, $courseid);
        if (!$cm || $cm->deletioninprogress) {
            throw new invalid_parameter_exception("replaces_cmid $cmid is not an activity of type \"$modname\" in this course.");
        }
    }

    /**
     * @throws invalid_parameter_exception not well-formed, root is not the activity of $modname
     */
    private static function assert_valid(string $xml, string $modname): void {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$ok || $dom->documentElement->nodeName !== 'activity' || $dom->documentElement->getAttribute('modulename') !== $modname) {
            throw new invalid_parameter_exception("activity_xml is not a valid activity XML of type \"$modname\".");
        }
        // The Lightboxgallery restore step reads this field before inserting the activity.
        if (
            $modname === 'lightboxgallery' &&
                (new \DOMXPath($dom))->query('/activity/lightboxgallery/timemodified')->length === 0
        ) {
            throw new invalid_parameter_exception('activity_xml requires <timemodified> in <lightboxgallery>. '
                . 'Use the complete XML from coursepilot_export_default_activity.');
        }
    }

    /**
     * Round-trip comparison (pure): input must be a subset of output.
     * Ignored: id-like values (attributes id/moduleid/contextid, numeric elements ending in "id"),
     * time* elements and file references. Elements of one name are matched by position.
     *
     * @param string $inputxml
     * @param string $outputxml
     * @return array{mismatches: array<int, array{path: string, expected: string, actual: string}>, presets: string[]}
     */
    public static function compare(string $inputxml, string $outputxml): array {
        $in = new \DOMDocument();
        $out = new \DOMDocument();
        $in->loadXML($inputxml, LIBXML_NONET);
        $out->loadXML($outputxml, LIBXML_NONET);
        $mismatches = [];
        $presets = [];
        self::compare_node($in->documentElement, $out->documentElement, $in->documentElement->nodeName, $mismatches, $presets);
        return ['mismatches' => $mismatches, 'presets' => array_values(array_unique($presets))];
    }

    private static function compare_node(\DOMElement $in, \DOMElement $out, string $path, array &$mismatches, array &$presets): void {
        $inchildren = self::element_children($in);
        if (!$inchildren) {
            self::compare_leaf($in, $out, $path, $mismatches);
            return;
        }
        $seen = [];
        foreach ($inchildren as $child) {
            $name = $child->nodeName;
            $index = $seen[$name] = ($seen[$name] ?? -1) + 1;
            if (self::is_ignored($child)) {
                continue;
            }
            $match = self::element_children($out, $name)[$index] ?? null;
            if ($match === null) {
                $mismatches[] = ['path' => "$path/$name", 'expected' => self::text($child), 'actual' => self::MISSING];
                continue;
            }
            self::compare_node($child, $match, "$path/$name", $mismatches, $presets);
        }
        foreach (self::element_children($out) as $child) {
            $name = $child->nodeName;
            $ininput = array_filter($inchildren, static fn($c) => $c->nodeName === $name);
            if (!$ininput && !self::is_ignored($child)) {
                $presets[] = "$path/$name";
            }
        }
    }

    private static function compare_leaf(\DOMElement $in, \DOMElement $out, string $path, array &$mismatches): void {
        $expected = self::normalise(self::text($in));
        $actual = self::normalise(self::text($out));
        if (
            str_contains($expected, self::FILE_MARKER) || $expected === $actual
                || (is_numeric($expected) && is_numeric($actual) && (float) $expected === (float) $actual)
        ) {
            return;
        }
        $mismatches[] = ['path' => $path, 'expected' => $expected, 'actual' => $actual];
    }

    /** @return \DOMElement[] direct element children, optionally of one name */
    private static function element_children(\DOMElement $node, ?string $name = null): array {
        $result = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && ($name === null || $child->nodeName === $name)) {
                $result[] = $child;
            }
        }
        return $result;
    }

    private static function is_ignored(\DOMElement $node): bool {
        $name = $node->nodeName;
        if (in_array($name, ['id', 'contextid', 'file', 'files', 'fileref', 'inforef'], true) || str_starts_with($name, 'time')) {
            return true;
        }
        return str_ends_with($name, 'id') && is_numeric(trim($node->textContent));
    }

    private static function text(\DOMElement $node): string {
        return $node->textContent;
    }

    private static function normalise(string $value): string {
        $value = trim(str_replace("\r\n", "\n", $value));
        return $value === self::NULL_MARKER ? '' : $value;
    }
}
