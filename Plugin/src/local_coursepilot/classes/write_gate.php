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

use local_coursepilot\catalog\drift_check;
use local_coursepilot\catalog\registry;
use moodle_exception;

/**
 * Self-approval of the field catalog in two stages (spec 0015 §11, ADR 0017,
 * ticket #399): because the catalog is largely transcribed, it silently
 * goes stale - after a Moodle update the administration should see whether
 * Coursepilot still fits, without trying it out, and if not, only the
 * affected activity type should be locked.
 *
 * 1. Cheap part (every write, {@see assert_writable()}): compares the
 *    cached Moodle version with the current one. Equal? A single
 *    get_config() call, no DB introspection or reflection effort -
 *    "costs no noticeable effort".
 * 2. Deep check (automatically on a detected version change, including
 *    point releases, AND retrievable at any time via {@see all_statuses()} or
 *    the admin status check): {@see drift_check::check()} per
 *    activity type, result is cached until the version changes
 *    again.
 *
 * No cron: the deep check runs exclusively triggered by a detected
 * version change (write or status page) - nothing periodically checks
 * something that only changes on upgrade.
 *
 * Drift locks only the affected activity type for writing - reading and
 * lookup are never affected by this, no read tool calls
 * {@see assert_writable()}.
 *
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class write_gate {
    /** @var string Configuration component for get_config()/set_config(). */
    private const CONFIG_COMPONENT = 'local_coursepilot';

    /** @var string Config key: last checked version tuple. */
    private const CONFIG_CHECKED_VERSION = 'driftcheckversion';

    /** @var string Config key prefix per activity type: JSON violation list. */
    private const CONFIG_VIOLATIONS_PREFIX = 'driftviolations_';

    /**
     * Throws if this activity type is currently write-locked - otherwise
     * returns without consequence. To be called by every write endpoint (ticket #399:
     * update_module_settings, create_module, create_quiz,
     * update_quiz_settings) before the actual write logic.
     * @param string $modname
     * @return void
     * @throws moodle_exception modnamedriftlocked
     */
    public static function assert_writable(string $modname): void {
        $status = self::status_for($modname);
        if ($status['state'] !== 'needs_work') {
            return;
        }

        // ADR 0017: "The teacher never sees the maintenance, only the consequence" - the
        // raw technical violations (columns/constants/sources) belong
        // in the admin status check ({@see \local_coursepilot\check\activity_drift}),
        // not in the teacher message. Here only as $debuginfo (visible only with
        // debugging enabled), not as a placeholder in the lang string.
        throw new moodle_exception(
            'modnamedriftlocked',
            'local_coursepilot',
            '',
            ['modname' => $modname],
            implode(' ', $status['violations'])
        );
    }

    /**
     * Status of a single activity type - one of "checked",
     * "auto_checked", "needs_work" (ticket #399: "one of three states
     * per activity type").
     *
     * @param string $modname
     * @return array{modname: string, state: string, violations: string[]}
     */
    public static function status_for(string $modname): array {
        global $CFG;

        self::ensure_fresh();

        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            return ['modname' => $modname, 'state' => 'needs_work', 'violations' => ['Unknown activity type.']];
        }

        $violations = self::cached_violations($modname);
        if ($violations) {
            $state = 'needs_work';
        } else if ((int) $CFG->branch > $catalogclass::reviewed_up_to_major()) {
            // Newer major version than the last manual review - machine-green,
            // but the residual risk that cannot be checked (value lists,
            // combination rules, side effects) has not yet been reviewed.
            $state = 'auto_checked';
        } else {
            $state = 'checked';
        }

        return ['modname' => $modname, 'state' => $state, 'violations' => $violations];
    }

    /**
     * Status of all cataloged activity types - basis of the
     * admin status check ({@see \local_coursepilot\check\activity_drift}) and
     * usable on demand at any time, independent of a write.
     *
     * @return array<int, array{modname: string, state: string, violations: string[]}>
     */
    public static function all_statuses(): array {
        return array_map([self::class, 'status_for'], registry::known_modnames());
    }

    /**
     * Re-runs the deep check for all activity types if the Moodle
     * version (incl. point release) or the installed Coursepilot version
     * has changed since the last call - the "detected version change"
     * from ticket #399. Otherwise no DB/reflection access (cheap part).
     * @return void
     */
    private static function ensure_fresh(): void {
        global $CFG;

        $currentversiontuple = self::version_tuple();
        $lastchecked = get_config(self::CONFIG_COMPONENT, self::CONFIG_CHECKED_VERSION);
        if ($lastchecked === $currentversiontuple) {
            return;
        }

        foreach (registry::known_modnames() as $modname) {
            $violations = drift_check::check($modname);
            set_config(self::CONFIG_VIOLATIONS_PREFIX . $modname, json_encode($violations), self::CONFIG_COMPONENT);
        }
        set_config(self::CONFIG_CHECKED_VERSION, $currentversiontuple, self::CONFIG_COMPONENT);
    }

    /**
     * Moodle core version plus installed Coursepilot version as one tuple -
     * a Coursepilot deploy (new catalog class without a Moodle upgrade) thus
     * triggers the deep check just like a Moodle upgrade.
     * @return string
     */
    private static function version_tuple(): string {
        global $CFG;

        return $CFG->version . ':' . get_config(self::CONFIG_COMPONENT, 'version');
    }

    /**
     * Provides cached violations.
     *
     * @param string $modname
     * @return string[]
     */
    private static function cached_violations(string $modname): array {
        $raw = get_config(self::CONFIG_COMPONENT, self::CONFIG_VIOLATIONS_PREFIX . $modname);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
