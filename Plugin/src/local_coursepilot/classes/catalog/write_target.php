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

use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Checked target state of a catalog write (Spec 0028 F11, issue #646).
 *
 * The single place that decides the catalog rules for create and update:
 * field release ({@see catalog_fields}), date order and parallel array
 * lengths, stealth, required fields and learner locks. The effective target
 * is the form defaults (create) or the current state (update) overlaid with
 * the explicit changes; the explicit change set is kept, so a rule only
 * fires when a change touches one of its fields and is then checked against
 * the full target. An independent patch never re-judges unchanged legacy
 * values.
 *
 * Construction only succeeds for an accepted write: callers resolve files
 * and run add_moduleinfo()/update_moduleinfo() afterwards.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class write_target {

    /**
     * @param array<string, mixed> $changes Explicitly named fields.
     * @param array<string, mixed> $state Effective target: defaults/current overlaid with $changes.
     */
    private function __construct(
        public readonly array $changes,
        public readonly array $state
    ) {
    }

    /**
     * Checks a new activity: missing fields take their catalog form default.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array<string, mixed> $changes
     * @param string[] $confirmedlocks
     * @return self
     * @throws moodle_exception on any rejected rule; nothing is written.
     */
    public static function create(string $catalogclass, array $changes, array $confirmedlocks): self {
        catalog_fields::validate($catalogclass, $changes);
        $defaults = self::form_defaults($catalogclass, $changes);
        $target = new self($changes, array_merge($defaults, $changes));
        $target->assert_rules($catalogclass);
        self::assert_no_required_field_missing($catalogclass, $changes);
        learner_locks::assert_confirmed(
            $catalogclass::modname(),
            learner_locks::find($catalogclass, $changes, $defaults),
            $confirmedlocks
        );
        return $target;
    }

    /**
     * Checks a patch on an existing activity against its current state.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $current Current state in catalog vocabulary.
     * @param string[] $confirmedlocks
     * @return self
     * @throws moodle_exception on any rejected rule; nothing is written.
     */
    public static function update(string $catalogclass, array $changes, array $current, array $confirmedlocks): self {
        catalog_fields::validate($catalogclass, $changes, true);
        $target = new self($changes, array_merge($current, $changes));
        $target->assert_rules($catalogclass);
        learner_locks::assert_confirmed(
            $catalogclass::modname(),
            learner_locks::find_changed($catalogclass, $changes, $current),
            $confirmedlocks
        );
        return $target;
    }

    /**
     * Target values that no change names (the filled form defaults on create).
     *
     * @return array<string, mixed>
     */
    public function defaults(): array {
        return array_diff_key($this->state, $this->changes);
    }

    /**
     * @param class-string<module_catalog> $catalogclass
     * @return void
     * @throws moodle_exception combinationruleviolation|stealthnotallowed
     */
    private function assert_rules(string $catalogclass): void {
        $options = $catalogclass::write_options();
        foreach ($options['parallel_array_lengths'] ?? [] as $rule) {
            if (!$this->touches($rule)) {
                continue;
            }
            $reference = $this->state[$rule['reference']] ?? null;
            $field = $this->state[$rule['field']] ?? null;
            if (is_array($reference) && is_array($field) && count($reference) !== count($field)) {
                self::violation($catalogclass, '"' . $rule['field'] . '" muss genauso viele Eintraege haben wie "'
                    . $rule['reference'] . '".');
            }
        }
        foreach ($options['date_order_rules'] ?? [] as $rule) {
            if (!$this->touches($rule)) {
                continue;
            }
            $reference = (int) ($this->state[$rule['reference']] ?? 0);
            $value = (int) ($this->state[$rule['field']] ?? 0);
            if ($reference === 0 || $value === 0) {
                continue;
            }
            if ($rule['mode'] === 'must_be_after' ? $value <= $reference : $value < $reference) {
                self::violation($catalogclass, $rule['mode'] === 'must_be_after'
                    ? '"' . $rule['field'] . '" muss nach "' . $rule['reference'] . '" liegen.'
                    : '"' . $rule['field'] . '" darf nicht vor "' . $rule['reference'] . '" liegen.');
            }
        }
        // Moodle's form path silently falls back to visible without
        // allowstealth (set_moduleinfo_defaults()); only an explicit request
        // for stealth is affected, returning to 1 always works.
        if (($this->changes['visibleoncoursepage'] ?? null) === 0 && !get_config(null, 'allowstealth')) {
            throw new moodle_exception('stealthnotallowed', 'local_coursepilot');
        }
    }

    /**
     * @param array{reference: string, field: string} $rule
     * @return bool True when a change names one of the rule's fields.
     */
    private function touches(array $rule): bool {
        return array_key_exists($rule['reference'], $this->changes) || array_key_exists($rule['field'], $this->changes);
    }

    /**
     * @param class-string<module_catalog> $catalogclass
     * @param string $message
     * @return never
     */
    private static function violation(string $catalogclass, string $message): void {
        throw new moodle_exception('combinationruleviolation', 'local_coursepilot', '', [
            'modname' => $catalogclass::modname(),
            'message' => $message,
        ]);
    }

    /**
     * Catalog form defaults (not DB column defaults) for every field not
     * named, plus the admin-configured defaults declared in write_options().
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function form_defaults(string $catalogclass, array $changes): array {
        $defaults = [];
        $adminfields = $catalogclass::write_options()['admin_default_fields'] ?? [];
        foreach (self::all_fields($catalogclass) as $field) {
            if (array_key_exists($field->name, $changes)) {
                continue;
            }
            if ($field->default !== null) {
                $defaults[$field->name] = $field->default;
            } else if (isset($adminfields[$field->name])) {
                $defaults[$field->name] = (int) (bool) get_config($adminfields[$field->name], 'default');
            }
        }
        return $defaults;
    }

    /**
     * A required field without a form default must be named; all missing
     * fields are reported at once (#404).
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array<string, mixed> $changes
     * @return void
     * @throws moodle_exception requiredfieldwithoutdefault
     */
    private static function assert_no_required_field_missing(string $catalogclass, array $changes): void {
        $missing = [];
        foreach (self::all_fields($catalogclass) as $field) {
            if ($field->required && $field->default === null && !array_key_exists($field->name, $changes)) {
                $missing[] = '"' . $field->name . '"';
            }
        }
        if ($missing) {
            throw new moodle_exception('requiredfieldwithoutdefault', 'local_coursepilot', '', [
                'field' => implode(', ', $missing),
                'modname' => $catalogclass::modname(),
            ]);
        }
    }

    /**
     * @param class-string<module_catalog> $catalogclass
     * @return field[]
     */
    private static function all_fields(string $catalogclass): array {
        return array_merge(shared_block::fields(), $catalogclass::fields(), $catalogclass::pseudofields());
    }
}
