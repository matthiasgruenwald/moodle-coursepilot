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

namespace local_coursepilot\catalog;

/**
 * Activity-type catalog contract (Spec 0015 §2), implemented once per
 * module type under local_coursepilot\catalog.
 *
 * The cross-module block (visibility, stealth, group mode, grouping,
 * idnumber and section, {@see shared_block}) lives separately and is added
 * by describe_module_fields for each type (Spec 0015 §2.3). Catalog
 * implementations must not duplicate it.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
interface module_catalog {
    /**
     * Latest Moodle major branch reviewed jointly across all catalogs
     * (ADR 0017, Ticket #399). Shared by reviewed_up_to_major(); an individual
     * catalog may return an earlier review version where necessary.
     */
    public const LAST_JOINT_REVIEW_MAJOR = 500;

    /**
     * Moodle module name without the mod_ prefix, e.g. "label".
     *
     * @return string
     */
    public static function modname(): string;

    /**
     * Category 1: actual instance database fields.
     *
     * @return field[]
     */
    public static function fields(): array;

    /**
     * Effective teacher-readable instance state for the catalog view,
     * with the same shape across module types.
     *
     * @param int $instanceid
     * @param int $cmid
     * @param bool $fullcontent
     * @return array{name: string, content: array, settings: array, quizslots: array}
     */
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array;

    /**
     * Module-specific write exceptions. Generic tools interpret this
     * declaration without knowing module types.
     *
     * @return array<string, mixed>
     */
    public static function write_options(): array;

    /**
     * Common fields from {@see fields()} for the concise describe_module_fields
     * view (Spec 0015 §3.1, Ticket #382). For types with few fields, return all
     * names; a subset helps only with large catalogs (assign: about 30).
     *
     * @return string[]
     */
    public static function common_field_names(): array;

    /**
     * Category 2: non-DB fields read unconditionally by *_instance() functions
     * (Spec 0015 §2.2).
     *
     * @return field[]
     */
    public static function pseudofields(): array;

    /**
     * Category 3: module-specific blocklist, in addition to the cross-module
     * {@see shared_block::BLOCKLIST} added by describe_module_fields.
     *
     * @return string[] Field names.
     */
    public static function blocklist(): array;

    /**
     * Category 4: field combination rules present only in Moodle validation()
     * (Spec 0015 §2.2).
     *
     * @return string[] One English sentence per rule.
     */
    public static function combination_rules(): array;

    /**
     * Category 5: side effects beyond the activity (Spec 0015 §2.2).
     *
     * @return string[] One English sentence per side effect.
     */
    public static function side_effects(): array;

    /**
     * Field bundles (presets): bundle name to field/value defaults. Preserve
     * fields explicitly named by the teacher (Spec 0015 §2.4). Empty when the
     * activity type has no bundles.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function bundles(): array;

    /**
     * Learner locks (Issue #583): field to structured condition requiring
     * teacher action before a learner can continue or resubmit. Shape:
     * `['op' => 'equals'|'not_equals'|'greater'|'nonzero', 'value' => ..., 'reason' => '...']`
     * Omit value for nonzero; reason is an English sentence for the tool message.
     * An explicit empty list is also required. Evaluated by {@see learner_locks}.
     *
     * @return array<string, array{op: string, value?: mixed, reason: string}>
     */
    public static function learner_locks(): array;

    /**
     * Grade origin (Issue #583): GRADE_TEACHER, GRADE_AUTOMATIC or GRADE_NONE
     * from {@see learner_locks}. With $instanceid, a catalog may refine the
     * answer for a particular instance (quiz with manually graded questions: teacher).
     *
     * @param int $instanceid 0 = answer for the activity type.
     * @return string
     */
    public static function grade_origin(int $instanceid = 0): string;

    /**
     * Write route: null for update_moduleinfo(), otherwise the dedicated
     * write tool name (Spec 0015 §3.1, e.g. "update_quiz_settings").
     *
     * @return string|null
     */
    public static function write_route(): ?string;

    /**
     * Required constants without an enumerable value set (Spec 0015 §11,
     * Tickets #382/#399, ADR 0017). Empty if the catalog references none.
     *
     * The same list feeds repository contract tests and runtime
     * {@see drift_check}, avoiding separate sources that could diverge.
     *
     * @return string[]
     */
    public static function checked_constants(): array;

    /**
     * Latest Moodle major branch manually reviewed for this catalog
     * (e.g. 500 for Moodle 5.0). Covers copied enumerations, combination
     * rules and side effects that cannot be checked automatically (ADR 0017,
     * Ticket #399).
     *
     * Advance manually only after reviewing a new major release. Until then,
     * a newer branch that passes machine checks remains automatically checked
     * rather than fully reviewed.
     *
     * @return int
     */
    public static function reviewed_up_to_major(): int;
}
