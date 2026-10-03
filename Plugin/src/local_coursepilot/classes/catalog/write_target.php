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

use context;
use context_course;
use context_module;
use local_coursepilot\activity_file_trash;
use local_coursepilot\material_files;
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
 * Construction only succeeds for an accepted write. create_activity() and
 * update_activity() add the file and native sequence (issue #647): editor
 * and file pseudofields are normalised and checked together with the
 * target, file references are validated, and only then drafts are resolved,
 * replaced files trashed and add_moduleinfo()/update_moduleinfo() run (ADR
 * 0016) - inside one delegated transaction, so neither a rejected request
 * nor a native partial failure leaves drafts, trash entries, sections or
 * activity changes behind. The quiz keeps its own route (quiz_write_bridge)
 * and only uses create()/update().
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
     * Checks and creates an activity in one sequence: normalise, check the
     * target, validate file references, then resolve files and run
     * add_moduleinfo() transactionally.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param \stdClass $course
     * @param int $sectionnum
     * @param array<string, mixed> $fields Named fields as sent by the client.
     * @param string $location {@see material_files::LOCATION_STORE}/{@see material_files::LOCATION_WORKBENCH}.
     * @param string[] $confirmedlocks
     * @return array{cmid: int, changes: array<string, mixed>} New cmid and the normalised named fields.
     * @throws moodle_exception on any rejected rule; nothing is written.
     */
    public static function create_activity(
        string $catalogclass,
        \stdClass $course,
        int $sectionnum,
        array $fields,
        string $location,
        array $confirmedlocks
    ): array {
        global $CFG;
        self::normalise_create_fields($catalogclass, $fields);
        $target = self::create($catalogclass, $fields, $confirmedlocks);
        self::assert_file_references($catalogclass, $fields);

        require_once($CFG->dirroot . '/course/modlib.php');
        $cmid = self::in_transaction(static function () use ($target, $catalogclass, $course, $sectionnum, $location): int {
            // Native capability check; creates the target section when missing.
            [$module] = \can_add_moduleinfo($course, $catalogclass::modname(), $sectionnum);
            $moduleinfo = (object) [
                'modulename' => $catalogclass::modname(),
                'module' => (int) $module->id,
                'section' => $sectionnum,
            ];
            foreach ($target->defaults() as $fieldname => $value) {
                $moduleinfo->{self::moduleinfo_property($fieldname)} = $value;
            }
            // mod_folder reads "files" (draft itemid) unguarded in
            // folder_add_instance(); an empty folder needs a "no draft" placeholder.
            foreach ($catalogclass::write_options()['missing_form_values'] ?? [] as $field => $value) {
                if (!property_exists($moduleinfo, $field)) {
                    $moduleinfo->{$field} = $value;
                }
            }
            // The module context does not exist yet: drafts are prepared
            // against the course context, add_moduleinfo() saves them.
            $target->apply_changes($catalogclass, $moduleinfo, context_course::instance($course->id), $location, false);
            return (int) \add_moduleinfo($moduleinfo, $course)->coursemodule;
        });
        return ['cmid' => $cmid, 'changes' => $fields];
    }

    /**
     * Checks and applies a patch in one sequence: normalise, check against
     * the current state, validate file references, then trash replaced
     * files, resolve drafts and run update_moduleinfo() transactionally.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param \stdClass $cm
     * @param \stdClass $course
     * @param array<string, mixed> $patch Named fields as sent by the client.
     * @param array<string, mixed> $current Current state in catalog vocabulary.
     * @param string $location
     * @param string[] $confirmedlocks
     * @return array<string, mixed> The normalised patch.
     * @throws moodle_exception on any rejected rule; nothing is written.
     */
    public static function update_activity(
        string $catalogclass,
        \stdClass $cm,
        \stdClass $course,
        array $patch,
        array $current,
        string $location,
        array $confirmedlocks
    ): array {
        global $CFG;
        pseudofield_carry_forward::normalise_editor_pseudofields($catalogclass, $patch);
        $target = self::update($catalogclass, $patch, $current, $confirmedlocks);
        self::assert_file_references($catalogclass, $patch);

        require_once($CFG->dirroot . '/course/modlib.php');
        self::in_transaction(static function () use ($target, $catalogclass, $cm, $course, $current, $location): void {
            // get_moduleinfo_data() returns [cm, context, module, data, cw];
            // "data" is the form-path object that is overlaid and written back.
            [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
            pseudofield_carry_forward::apply($catalogclass::modname(), $catalogclass, $moduleinfo, $current, $cm,
                $target->changes);
            $target->apply_changes($catalogclass, $moduleinfo, context_module::instance($cm->id), $location, true);
            \update_moduleinfo($cm, $moduleinfo, $course);
        });
        return $patch;
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
            // Only a named dependent list is judged: the native write guards a
            // missing entry (choice_update_instance(): isset($choice->limit[$key])),
            // so changing the reference list alone stays valid.
            if (!array_key_exists($rule['field'], $this->changes)) {
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
    private static function violation(string $catalogclass, string $message): never {
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

    /**
     * Writes the checked changes onto the native form object and resolves
     * file pseudofields into drafts. Runs inside the write transaction.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param \stdClass $moduleinfo Overlaid in place.
     * @param context $context Draft target context.
     * @param string $location
     * @param bool $replacing True on update: same-named attached files go to the trash first.
     * @return void
     */
    private function apply_changes(
        string $catalogclass,
        \stdClass $moduleinfo,
        context $context,
        string $location,
        bool $replacing
    ): void {
        $options = $catalogclass::write_options();
        $imagefield = $options['intro_image_field'] ?? null;
        foreach ($this->changes as $fieldname => $value) {
            if ($fieldname !== $imagefield) {
                $moduleinfo->{self::moduleinfo_property($fieldname)} = $value;
            }
        }
        foreach (array_intersect_key($options['material_reference_fields'] ?? [], $this->changes) as $fieldname => $spec) {
            $moduleinfo->{$fieldname} = self::resolve_draft($context, $spec, $this->changes[$fieldname], $location, $replacing);
        }
        if ($imagefield !== null && array_key_exists($imagefield, $this->changes)) {
            // The image lands in the intro's own draft area, so @@PLUGINFILE@@
            // references in "intro" resolve against it (Spec 0018 §4.2/§5).
            $spec = ['component' => 'mod_' . $catalogclass::modname(), 'filearea' => 'intro'];
            $draftitemid = self::resolve_draft($context, $spec, $this->changes[$imagefield], $location, $replacing);
            if (!isset($moduleinfo->introeditor) || !is_array($moduleinfo->introeditor)) {
                $moduleinfo->introeditor = [
                    'text' => $moduleinfo->intro ?? '',
                    'format' => $moduleinfo->introformat ?? FORMAT_HTML,
                ];
            }
            $moduleinfo->introeditor['itemid'] = $draftitemid;
        }
        // update_moduleinfo() always takes intro from introeditor; a pure
        // "intro" change would otherwise vanish.
        pseudofield_carry_forward::sync_intro_editor_from_patch($moduleinfo, $this->changes);
    }

    /**
     * @param context $context
     * @param array{component: string, filearea: string} $spec
     * @param array $paths Checked by {@see self::assert_file_references()}.
     * @param string $location
     * @param bool $replacing
     * @return int Draft itemid.
     */
    private static function resolve_draft(context $context, array $spec, array $paths, string $location, bool $replacing): int {
        if ($replacing) {
            // Moodle core deletes the old record deep in
            // file_save_draft_area_files(); keep it restorable (Spec 0018 §9.1).
            $newnames = array_map(static fn($entry): string => basename(material_files::entry_path($entry)), $paths);
            $existing = get_file_storage()->get_area_files($context->id, $spec['component'], $spec['filearea'], 0,
                'filename', false);
            foreach ($existing as $file) {
                if (in_array($file->get_filename(), $newnames, true)) {
                    activity_file_trash::trash($file, $context->instanceid);
                }
            }
        }
        return material_files::resolve_into_draft($context->id, $spec['component'], $spec['filearea'], 0, $paths, $location);
    }

    /**
     * Validates file pseudofields before any file is touched: the file
     * capability first, then list shapes and the embed whitelist.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array<string, mixed> $changes
     * @return void
     * @throws moodle_exception invalidmaterialreferencelist|materialfiledisallowedtype
     * @throws \required_capability_exception without moodle/user:manageownfiles
     */
    private static function assert_file_references(string $catalogclass, array $changes): void {
        $options = $catalogclass::write_options();
        $listfields = array_keys(array_intersect_key($options['material_reference_fields'] ?? [], $changes));
        $imagefield = $options['intro_image_field'] ?? null;
        $hasimages = $imagefield !== null && array_key_exists($imagefield, $changes);
        if (!$listfields && !$hasimages) {
            return;
        }
        material_files::require_manage_own_files();
        foreach ($listfields as $fieldname) {
            if (!is_array($changes[$fieldname])) {
                throw new moodle_exception('invalidmaterialreferencelist', 'local_coursepilot', '', $fieldname);
            }
        }
        if (!$hasimages) {
            return;
        }
        $paths = $changes[$imagefield];
        if (!is_array($paths) || !array_is_list($paths)) {
            throw new moodle_exception('invalidmaterialreferencelist', 'local_coursepilot', '', $imagefield);
        }
        foreach ($paths as $path) {
            if (!is_string($path) || !material_files::is_allowed_embed_image_extension($path)) {
                throw new moodle_exception('materialfiledisallowedtype', 'local_coursepilot', '', (object) [
                    'filename' => is_string($path) ? $path : '',
                    'allowed' => implode(', ', material_files::allowed_embed_image_extensions()),
                ]);
            }
        }
    }

    /**
     * Brings named create fields into the form the catalog rules judge.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array<string, mixed> $fields Normalised in place.
     * @return void
     * @throws moodle_exception invalideditorpseudofield
     */
    private static function normalise_create_fields(string $catalogclass, array &$fields): void {
        $options = $catalogclass::write_options();
        // A bundle carries e.g. choice "limit" as one value for every option;
        // the form field is one entry per option.
        foreach ($options['scalar_to_repeated'] ?? [] as $field => $reference) {
            if (array_key_exists($field, $fields) && !is_array($fields[$field])
                    && isset($fields[$reference]) && is_array($fields[$reference])) {
                $fields[$field] = array_fill(0, count($fields[$reference]), (int) $fields[$field]);
            }
        }
        // Before deriving content: a non-array editor value would otherwise
        // create an empty page silently (#405).
        pseudofield_carry_forward::normalise_editor_pseudofields($catalogclass, $fields);
        // add_moduleinfo() runs *_add_instance() without $mform, so e.g.
        // page_add_instance() never copies the editor into its columns.
        foreach ($options['editor_content'] ?? [] as $editor => [$textfield, $formatfield]) {
            if (!isset($fields[$editor]) || !is_array($fields[$editor])) {
                continue;
            }
            if (!array_key_exists($textfield, $fields)) {
                $fields[$textfield] = (string) ($fields[$editor]['text'] ?? '');
            }
            if (!array_key_exists($formatfield, $fields)) {
                $fields[$formatfield] = (int) ($fields[$editor]['format'] ?? FORMAT_HTML);
            }
        }
        // An empty path list counts as not named, so a resource without main
        // file fails the required-field check instead of getting an empty draft (#434).
        foreach (array_keys($options['material_reference_fields'] ?? []) as $fieldname) {
            if (array_key_exists($fieldname, $fields) && $fields[$fieldname] === []) {
                unset($fields[$fieldname]);
            }
        }
    }

    /**
     * Runs a native write sequence so that any failure rolls back drafts,
     * trash entries, sections and activity rows and is rethrown unchanged.
     *
     * @param callable $write
     * @return mixed The callable's result.
     */
    private static function in_transaction(callable $write): mixed {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $result = $write();
        } catch (\Throwable $e) {
            // A native helper may leave its own inner transaction open
            // (add_moduleinfo() does when a step throws an \Error); a
            // delegated rollback would then only rethrow. Roll back the
            // whole stack so no partial write survives, then rethrow.
            $DB->force_transaction_rollback();
            throw $e;
        }
        $transaction->allow_commit();
        return $result;
    }

    /**
     * Catalog field name => native $moduleinfo property. "idnumber" is the
     * teacher-facing name of the form-path property "cmidnumber"
     * (course/modlib.php reads only $moduleinfo->cmidnumber).
     *
     * @param string $fieldname
     * @return string
     */
    private static function moduleinfo_property(string $fieldname): string {
        return $fieldname === 'idnumber' ? 'cmidnumber' : $fieldname;
    }
}
