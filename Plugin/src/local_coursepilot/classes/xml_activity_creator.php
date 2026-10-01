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
     * @return array{cmid: int, presets: string[]}
     * @throws moodle_exception kind gate, xmlroundtripmismatch
     * @throws invalid_parameter_exception invalid XML or modname mismatch
     */
    public static function create(int $courseid, string $modname, int $sectionnum, string $activityxml, bool $hidden = false): array {
        global $USER;
        registry::require_developed($modname, 'createfromxmlcatalogued');
        self::assert_valid($activityxml, $modname);

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
            // ponytail: #593 (content follow-up, e.g. glossary entries) goes HERE, after the passed
            // round trip and before showing; no seam until a second adapter exists.
            if (!$hidden) {
                course_module_placement::set_visible($cmid, true);
            }
        } catch (\Throwable $e) {
            try {
                course_module_placement::discard_failed($cmid);
            } catch (\Throwable $cleanup) {
                debugging('create_activity_from_xml cleanup failed: ' . $cleanup->getMessage(), DEBUG_DEVELOPER);
            }
            throw $e;
        }
        // Last: drop what the observers wrote on the way, keep exactly one state.
        retention::purge_cm($cmid);
        version_writer::capture($cmid, (int) $USER->id, version_writer::SOURCE_FROM_XML);
        return ['cmid' => $cmid, 'presets' => $result['presets']];
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
        if (str_contains($expected, self::FILE_MARKER) || $expected === $actual
                || (is_numeric($expected) && is_numeric($actual) && (float) $expected === (float) $actual)) {
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
