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
use local_coursepilot\webdav\webdav_instance;
use local_coursepilot\webdav\webdav_setup_steps;

/**
 * Location-selection logic (#494, Spec #486 §5/§10): chooses context and
 * material locations. Lists through the owned WebDAV client, not get_listing();
 * uses {@see webdav_setup_steps} for empty states; creates folders level by
 * level, then writes the context pointer only for real changes and appends
 * one location-history entry per changed target.
 *
 * Thin I/O pages (#334 pattern): location_selection.php and
 * location_selection_browse.php delegate logic here, tested through the
 * {@see \local_coursepilot\tests\webdav\fake_webdav_transport}, never
 * through the page itself (#494 acceptance criterion).
 *
 * The initial structure provides location history; overlap/IServ/populated
 * folder guards and previous-location handling follow in #497/#498.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class location_selection {

    /** @var string[] The two target names in the pointer document (Spec §2). */
    public const TARGETS = context_pointer::TARGETS;

    /** @var int Maximum entry-name preview for folder handover confirmation (#497). */
    private const ENTRY_PREVIEW_COUNT = 5;

    /** @var string Page state: WebDAV access has not been enabled. */
    public const STATE_NOT_ENABLED = 'not_enabled';

    /** @var string Page state: enabled but no owned instance. */
    public const STATE_NO_INSTANCE = 'no_instance';

    /** @var string Page state: the folder browser is available. */
    public const STATE_READY = 'ready';

    /**
     * @var int Folder-browser request timeout in milliseconds
     *      (location-selection template and location_selection.js); then the
     *      browser shows a timeout message instead of loading forever.
     */
    public const BROWSE_TIMEOUT_MS = 8000;

    /**
     * Page readiness/empty state (#494): missing WebDAV enablement takes
     * precedence over missing instances. A teacher without access never sees
     * the wrong empty state, even with an existing instance.
     *
     * @param int $userid
     * @return array{state: string, steps: array}
     */
    public static function setup_state(int $userid): array {
        $steps = webdav_setup_steps::catalog($userid);
        // #528: all three setup steps are independent; checking only the capability
        // step is insufficient. enabled_for_user() combines them explicitly with AND.
        if (!webdav_setup_steps::enabled_for_user($userid)) {
            return ['state' => self::STATE_NOT_ENABLED, 'steps' => $steps];
        }
        if (empty(self::own_instances())) {
            return ['state' => self::STATE_NO_INSTANCE, 'steps' => $steps];
        }
        return ['state' => self::STATE_READY, 'steps' => $steps];
    }

    /**
     * Complete presentation-independent location state. Rendering creates
     * text and markup from the named keys.
     *
     * @param int $userid
     * @param array|null $browse Result of {@see browse()}, when a level is open.
     * @return array
     */
    public static function page_state(int $userid, ?array $browse = null): array {
        $setup = self::setup_state($userid);
        $locations = [];
        foreach (self::TARGETS as $target) {
            $current = self::current($target);
            $locations[$target] = [
                'state' => $current['chosen'] ? 'selected' : 'not_selected',
                'kind' => $current['location'],
                'path' => $current['path'],
                'allowed' => $current['allowed'] ? 'allowed' : 'not_allowed',
            ] + (isset($current['instanceid']) ? ['instanceid' => $current['instanceid']] : []);
        }
        $steps = [];
        foreach ($setup['steps'] as $key => $step) {
            $steps[$key] = ['state' => $step['ok'] ? 'complete' : 'missing'];
        }
        return [
            'webdav' => ['state' => $setup['state'], 'steps' => $steps],
            'locations' => $locations,
            'instances' => self::own_instances(),
            'browse' => $browse === null ? ['state' => 'idle'] : ['state' => 'ready'] + $browse,
            'previous_location' => ['state' => previous_location::open() ? 'open' : 'closed'],
            'history' => array_map(static function (array $entry): array {
                if (isset($entry['from'], $entry['to'])) {
                    return [
                        'at' => (int) ($entry['date'] ?? 0),
                        'target' => (string) ($entry['target'] ?? ''),
                        'from' => $entry['from'],
                        'to' => $entry['to'],
                    ];
                }
                return ['at' => (int) ($entry['date'] ?? 0), 'target' => (string) ($entry['target'] ?? ''), 'state' => 'legacy'];
            }, self::history()),
            'notices' => [],
            'errors' => [],
        ];
    }

    /**
     * Logged-in person's owned WebDAV instances, the folder browser's roots (#494).
     * Since #497 includes selectability: HTTPS and Basic authentication are
     * required (Spec §2 check 5/§3). Unsupported instances appear with a reason
     * instead of silently disappearing.
     *
     * @return array<int, array{id: int, name: string, selectable: bool, reasonkey: ?string}>
     */
    public static function own_instances(): array {
        global $DB;

        $contextid = storage_anchor::own_context()->id;
        $records = $DB->get_records_sql(
            'SELECT ri.id, ri.name
               FROM {repository_instances} ri
               JOIN {repository} r ON r.id = ri.typeid
              WHERE ri.contextid = :contextid AND r.type = :type
           ORDER BY ri.name ASC',
            ['contextid' => $contextid, 'type' => 'webdav']
        );
        return array_values(array_map(
            static function (\stdClass $r): array {
                $selectable = webdav_instance::has_supported_auth((int) $r->id);
                return [
                    'id' => (int) $r->id,
                    'name' => (string) $r->name,
                    'selectable' => $selectable,
                    'reasonkey' => $selectable ? null : 'locationselectioninstanceauthunsupported',
                ];
            },
            $records
        ));
    }

    /**
     * Copyable admin request for the not-enabled empty state (#494): only
     * missing steps, never already completed ones.
     *
     * @param int $userid
     * @return string One step per line; empty when no steps are missing.
     */
    public static function missing_steps_text(int $userid): string {
        $lines = [];
        foreach (webdav_setup_steps::catalog($userid) as $step) {
            if (!$step['ok']) {
                $lines[] = $step['instruction'];
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Optional school guidance for the no-instance empty state (#494),
     * configured through webdavhint.
     *
     * @return string Empty when unconfigured.
     */
    public static function school_hint(): string {
        return trim((string) (get_config('local_coursepilot', 'webdavhint') ?: ''));
    }

    /**
     * Lists one level of an owned WebDAV instance. Only folders are navigable:
     * the browser chooses a location, not a file. Apart from the network request,
     * results are never persisted, logged or forwarded to the model (Spec §5).
     *
     * Since #497 includes selection guards (instance root, IServ outside Files/)
     * and the total count/first names of all entries, files and folders, for
     * confirmation when handing over a populated folder (Spec §5).
     *
     * @param int $instanceid
     * @param string $path Relative to the instance root, e.g. "" or "Teaching/Context".
     * @return array{path: string, folders: array<int, array{name: string}>, iserv: bool,
     *         selectable: bool, reasonkey: ?string, entrycount: int, entrynames: string[]}
     * @throws \moodle_exception webdavinstancemissing/webdavinstanceforeign/webdavnotenabled/
     *         webdavauthunsupported/locationselectionexternalerror/invalidcontextpath
     */
    public static function browse(int $instanceid, string $path): array {
        $segments = self::validate_segments($path);
        $relative = implode('/', $segments);
        try {
            $listing = webdav_storage_port::browse_location($instanceid, $relative);
        } catch (webdav_error $e) {
            throw pointer_reader::webdav_exception($e, 'locationselectionexternalerror');
        }
        $raw = $listing['entries'];

        $folders = array_values(array_map(
            static fn (array $entry): array => ['name' => $entry['name']],
            array_filter($raw, static fn (array $entry): bool => $entry['type'] === 'folder')
        ));
        usort($folders, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $iserv = $listing['iserv'];
        [$selectable, $reason] = self::selectability($segments, $iserv);

        $names = array_values(array_map(static fn (array $entry): string => $entry['name'], $raw));
        usort($names, 'strnatcasecmp');

        return [
            'path' => $relative,
            'folders' => $folders,
            'iserv' => $iserv,
            'selectable' => $selectable,
            'reasonkey' => $reason,
            'entrycount' => count($raw),
            'entrynames' => array_slice($names, 0, self::ENTRY_PREVIEW_COUNT),
        ];
    }

    /**
     * Shared PROPFIND for legacy detection and {@see old_location_has_entries()}
     * (#517). Missing directories are empty; actual access/network failures
     * return the location-selection error.
     *
     * @param \local_coursepilot\webdav\resolved_webdav_instance $instance
     * @param string $relative
     * @return array
     */
    private static function fetch_raw_entries(\local_coursepilot\webdav\resolved_webdav_instance $instance, string $relative): array {
        try {
            return $instance->client()->propfind($instance->directory_url($relative), 1);
        } catch (webdav_error $e) {
            // Teacher-facing error instead of the model-oriented context-gap wording
            // (#526, Spec #486 §5/§8): shown directly on location selection.
            return webdav_error::empty_when_missing(
                $e,
                [],
                static fn (webdav_error $e): \moodle_exception => pointer_reader::webdav_exception($e, 'locationselectionexternalerror')
            );
        }
    }

    /**
     * Detects legacy context (#505 finding 7, updates #517/Spec §9): a top-level
     * .md file OR any subfolder. Previously only top-level context files counted,
     * so nested files such as 2026-27/9a/biology/... were missed on location
     * changes and the model never offered to copy them.
     *
     * No recursive search (maintainer decision #505): each WebDAV level costs
     * 1.2–1.7 seconds. An accidentally high-level folder must not block selection
     * for 30 seconds. Name the subfolder without searching it;
     * {@see webdav_setup_steps} supplies listskillspreviouslocationhint and
     * the model can inspect it with coursepilot_list_context_files as needed.
     *
     * @param array<int, array{name: string, type: string}> $entries
     * @return bool
     */
    private static function has_context_file(array $entries): bool {
        foreach ($entries as $entry) {
            $type = $entry['type'] ?? '';
            if ($type === 'folder') {
                return true;
            }
            if ($type === 'file' && preg_match('/\.md$/i', (string) ($entry['name'] ?? '')) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Folder selectability (#497, Spec #486 §5): exclude the instance root
     * and, for IServ, everything outside Files/.
     *
     * @param string[] $segments
     * @param bool $iserv
     * @return array{0: bool, 1: string|null} [Selectable, reason key]
     */
    private static function selectability(array $segments, bool $iserv): array {
        if (empty($segments)) {
            return [false, 'locationselectionrootnotselectable'];
        }
        if ($iserv && $segments[0] !== webdav_instance::ISERV_FILES_AREA) {
            return [false, 'locationselectioniservfilesonly'];
        }
        return [true, null];
    }

    /**
     * Current target for page display and folder-browser preselection (#494).
     * Without a pointer, or for an unchanged Moodle target, use the configured
     * default root.
     *
     * @param string $target "context_area" or "material_store".
     * @return array{location: string, path: string, instanceid?: int, fingerprint?: array,
     *         chosen: bool, display: string, allowed: bool}
     */
    public static function current(string $target): array {
        $value = self::current_pointer_value($target);
        return $value + [
            'display' => self::describe_location($value),
            'allowed' => self::is_allowed($value),
        ];
    }

    /**
     * Whether the resolved target allows personal data (#500, ADR 0021 §3):
     * Private Files always do; external hosts must appear in personaldatahosts.
     * Uses {@see \local_coursepilot\personal_data_hosts::allowed()} for
     * per-target display rather than raising a caller error.
     *
     * @param array{location: string, path: string, instanceid?: int, fingerprint?: array} $value
     * @return bool
     */
    private static function is_allowed(array $value): bool {
        if ($value['location'] !== pointer_location::EXTERNAL) {
            return true;
        }
        return personal_data_hosts::allowed((string) ($value['fingerprint']['server'] ?? ''));
    }

    /**
     * Formats the allowed-host label (#500, Spec #486 §11), shared by consent,
     * connection self-service and location selection.
     *
     * @param array{allowed: bool} $value Result of {@see current()}.
     * @return string
     */
    public static function allowed_label(array $value): string {
        return get_string($value['allowed'] ? 'locationselectionallowedyes' : 'locationselectionallowedno', 'local_coursepilot');
    }

    /**
     * Location history from the pointer (#494, Spec §2). Empty without a
     * pointer or for first-generation pointers that have no history.
     *
     * @return array<int, array{date: int, target: string, from: array, to: array}>
     */
    public static function history(): array {
        $document = storage_anchor::read_raw_pointer();
        $entries = $document['location_history'] ?? [];
        return is_array($entries) ? $entries : [];
    }

    /**
     * Completes location selection (#494, Spec §5): create every new external
     * folder level by level before saving the pointer. Folder failures propagate
     * without any pointer writes. Only changed targets gain history entries;
     * without real changes, leave the pointer untouched.
     *
     * @param array<string, array{type: string, instanceid?: int, path?: string, confirmed?: bool}> $selection
     *        For each target either ['type' => 'moodle'] oder
     *        ['type' => 'external', 'instanceid' => int, 'path' => string, 'confirmed' => bool].
     *        confirmed applies only to context_area (#518, Spec §5):
     *        explicit handover of a populated folder.
     * @return string[] Targets that actually changed.
     * @throws \moodle_exception for invalid selection or folder-creation failure.
     */
    public static function apply(array $selection): array {
        $wanted = self::resolve_wanted_targets($selection);
        $current = self::resolve_current_targets();

        // Check overlap before every write (#497, Spec §2 check 7), not only
        // on later resolution, so invalid combinations fail immediately.
        self::assert_no_overlap($wanted['context_area'], $wanted['material_store']);

        // Confirm populated context-folder handover on the server (#518, Spec §5),
        // not only in page JavaScript; direct requests cannot bypass confirmation.
        self::assert_folder_handover_confirmed($wanted, $current, $selection);

        self::create_new_external_folders($wanted, $current);

        ['changed' => $changed, 'location_history' => $locationhistory, 'previouslocation' => $previouslocation]
            = self::record_changes($wanted, $current);
        if (empty($changed)) {
            return [];
        }

        storage_anchor::save_location_selection($wanted, $locationhistory, $previouslocation);
        return $changed;
    }

    /**
     * Resolves and validates requested targets (root restriction, IServ, access;
     * see {@see build_target()}).
     *
     * @param array<string, array{type?: string, instanceid?: int, path?: string}> $selection
     * @return array<string, array{location: string, path: string, instanceid?: int, fingerprint?: array}>
     */
    private static function resolve_wanted_targets(array $selection): array {
        $wanted = [];
        foreach (self::TARGETS as $target) {
            $wanted[$target] = self::build_target($target, $selection[$target] ?? ['type' => pointer_location::MOODLE]);
        }
        return $wanted;
    }

    /**
     * Targets currently stored in the context pointer.
     *
     * @return array<string, array{location: string, path: string, instanceid?: int, fingerprint?: array}>
     */
    private static function resolve_current_targets(): array {
        $current = [];
        foreach (self::TARGETS as $target) {
            $current[$target] = self::current_pointer_value($target);
        }
        return $current;
    }

    /**
     * Creates folders only for changed external targets: an unchanged location
     * already exists by definition (Spec: newly selected folder). Create all
     * folders before {@see apply()} saves anything.
     *
     * @param array<string, array{location: string, path: string, instanceid?: int}> $wanted
     * @param array<string, array{location: string, path: string, instanceid?: int}> $current
     */
    private static function create_new_external_folders(array $wanted, array $current): void {
        foreach (self::TARGETS as $target) {
            if ($wanted[$target]['location'] === pointer_location::EXTERNAL && !self::same_place($current[$target], $wanted[$target])) {
                self::ensure_directory((int) $wanted[$target]['instanceid'], (string) $wanted[$target]['path']);
            }
        }
    }

    /**
     * Detects real target changes and records history/previous location (#498,
     * Spec #486 §9). A context-area move replaces the previous location only
     * with provable legacy context, keeping exactly one previous location.
     * Its files remain untouched. A material-only move leaves that field unchanged.
     *
     * @param array<string, array{location: string, path: string, instanceid?: int, fingerprint?: array}> $wanted
     * @param array<string, array{location: string, path: string, instanceid?: int, fingerprint?: array}> $current
     * @return array{changed: string[], location_history: array, previouslocation: ?array}
     */
    private static function record_changes(array $wanted, array $current): array {
        $changed = [];
        $locationhistory = self::history();
        $previouslocation = previous_location::current();
        foreach (self::TARGETS as $target) {
            if (self::same_place($current[$target], $wanted[$target])) {
                continue;
            }
            $changed[] = $target;
            $locationhistory[] = [
                'date' => time(),
                'target' => $target,
                'from' => $current[$target],
                'to' => $wanted[$target],
            ];
            if ($target === 'context_area') {
                // Every real context move replaces previous legacy context, even when
                // the location being left is empty (#517, Spec §9). Otherwise A→B→A
                // with empty B would retain the first legacy location A, now current again.
                $previouslocation = self::old_location_has_entries($current[$target]) ? $current[$target] : null;
            }
        }
        return ['changed' => $changed, 'location_history' => $locationhistory, 'previouslocation' => $previouslocation];
    }

    /**
    /**
     * @var string[] Error keys indicating the old location itself is no longer
     *      valid (missing/foreign instance, revoked
     *      access, unsupported authentication). Same
     *      list as {@see \local_coursepilot\pointer_writer::LOCATION_FAILURE_CODES}.
     *      Treat as no provable legacy context rather than blocking
     *      completion. An actual connection failure
     *      (locationselectionexternalerror) is not specific to the old
     *      location; like every other failure in this flow it propagates
     *      and rejects the entire operation rather than
     *      silently losing legacy context (Spec §5:
     *      on failure, nothing is saved).
     */
    private const OLD_LOCATION_INVALID_CODES = [
        'webdavinstancemissing',
        'webdavinstanceforeign',
        'webdavnotenabled',
        'webdavauthunsupported',
    ];

    /**
     * Whether the old context location contains provable entries (#498, Spec §5).
     * Location selection already communicates with both stores while choosing.
     *
     * @param array{location: string, path: string, instanceid?: int, fingerprint?: array} $old
     * @return bool
     * @throws \moodle_exception locationselectionexternalerror for actual connection failure
     *         (not an invalid old location).
     */
    private static function old_location_has_entries(array $old): bool {
        if ($old['location'] === pointer_location::MOODLE) {
            $directory = '/' . trim((string) $old['path'], '/') . '/';
            return self::has_context_file(storage_anchor::list_entries($directory));
        }
        try {
            $instance = webdav_instance::resolve_owned((int) $old['instanceid']);
            $relative = implode('/', self::validate_segments((string) $old['path']));
            return self::has_context_file(self::fetch_raw_entries($instance, $relative));
        } catch (\moodle_exception $e) {
            if (in_array($e->errorcode, self::OLD_LOCATION_INVALID_CODES, true)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Whether location selection is still open (no pointer) with WebDAV access
     * enabled for this person; exposed to coursepilot_list_skills (#494).
     *
     * @param int $userid
     * @return bool
     */
    public static function open_with_access(int $userid): bool {
        return webdav_setup_steps::enabled_for_user($userid) && storage_anchor::read_raw_pointer() === null;
    }

    /**
     * @param string $target
     * @return storage_area
     */
    private static function area(string $target): storage_area {
        return $target === 'material_store' ? material_files::area() : context_files::area();
    }

    /**
     * @param string $target
     * @return array{location: string, path: string, instanceid?: int, fingerprint?: array}
     */
    private static function current_pointer_value(string $target): array {
        $area = self::area($target);
        $location = storage_anchor::resolve_pointer_location($area);
        // chosen (#525, Spec §5) distinguishes a resolved pointer from a displayed
        // default root during first setup. Browser JavaScript preselects completed
        // targets without skipping that first setup.
        if ($location === null) {
            return ['location' => pointer_location::MOODLE, 'path' => storage_anchor::default_root($area), 'chosen' => false];
        }
        if ($location->kind === pointer_location::MOODLE) {
            return ['location' => pointer_location::MOODLE, 'path' => trim((string) $location->path, '/'), 'chosen' => true];
        }
        return [
            'location' => pointer_location::EXTERNAL,
            'instanceid' => (int) $location->instanceid,
            'path' => (string) $location->relativepath,
            'fingerprint' => $location->fingerprint,
            'chosen' => true,
        ];
    }

    /**
     * @param string $target
     * @param array{type?: string, instanceid?: int, path?: string} $selection
     * @return array{location: string, path: string, instanceid?: int, fingerprint?: array}
     * @throws \moodle_exception locationselectionselectioninvalid, or errors from {@see webdav_instance::resolve_owned()}.
     */
    private static function build_target(string $target, array $selection): array {
        $type = $selection['type'] ?? pointer_location::MOODLE;
        if ($type !== pointer_location::EXTERNAL) {
            $area = self::area($target);
            return ['location' => pointer_location::MOODLE, 'path' => storage_anchor::default_root($area)];
        }

        $instanceid = (int) ($selection['instanceid'] ?? 0);
        if ($instanceid <= 0) {
            throw new \moodle_exception('locationselectionselectioninvalid', 'local_coursepilot');
        }
        // Reject foreign, missing or disabled instances. The ID comes from
        // form input and must never be trusted without validation.
        webdav_instance::resolve_owned($instanceid);
        $segments = self::validate_segments((string) ($selection['path'] ?? ''));

        // Instance roots are never selectable (#497, Spec §5).
        if (empty($segments)) {
            throw new \moodle_exception('locationselectionrootnotselectable', 'local_coursepilot');
        }

        // Detect IServ afresh for each selection (#497, Spec §5/§2 check 8).
        // Store the result in the fingerprint so later checks require no network.
        try {
            $iserv = webdav_instance::detect_iserv_root($instanceid);
        } catch (webdav_error $e) {
            throw pointer_reader::webdav_exception($e, 'locationselectionexternalerror');
        }
        if ($iserv && $segments[0] !== webdav_instance::ISERV_FILES_AREA) {
            throw new \moodle_exception('locationselectioniservfilesonly', 'local_coursepilot');
        }

        return [
            'location' => pointer_location::EXTERNAL,
            'instanceid' => $instanceid,
            'path' => implode('/', $segments),
            'fingerprint' => webdav_instance::fingerprint_of($instanceid) + ['iserv' => $iserv],
        ];
    }

    /**
     * Overlap guard (#497, Spec §2 check 7, reuses comparison keys from #495):
     * material storage cannot be inside or equal to the context area. Validate
     * new targets before any folder creation or persistence.
     *
     * @param array{location: string, path: string, instanceid?: int, fingerprint?: array} $contextarea
     * @param array{location: string, path: string, instanceid?: int, fingerprint?: array} $materialstore
     * @throws \moodle_exception materialstoreincontext
     */
    private static function assert_no_overlap(array $contextarea, array $materialstore): void {
        $contextkey = self::to_pointer_location($contextarea)->comparison_key();
        $materialkey = self::to_pointer_location($materialstore)->comparison_key();
        if (str_starts_with($materialkey, $contextkey)) {
            throw new \moodle_exception('materialstoreincontext', 'local_coursepilot');
        }
    }

    /**
     * Requires explicit populated context-folder handover on the server
     * (#518, Spec §5), not only in JavaScript. Empty or unchanged selections
     * need no confirmation, only moves to populated external context folders.
     *
     * Only context_area needs confirmation; material-store changes do not.
     *
     * @param array<string, array{location: string, path: string, instanceid?: int}> $wanted
     * @param array<string, array{location: string, path: string, instanceid?: int}> $current
     * @param array<string, array{confirmed?: bool}> $selection Raw input passed to {@see apply()}.
     * @throws \moodle_exception locationselectionfolderconfirmrequired
     */
    private static function assert_folder_handover_confirmed(array $wanted, array $current, array $selection): void {
        $target = 'context_area';
        if ($wanted[$target]['location'] !== pointer_location::EXTERNAL || self::same_place($current[$target], $wanted[$target])) {
            return;
        }
        if (!empty($selection[$target]['confirmed'] ?? false)) {
            return;
        }
        $instance = webdav_instance::resolve_owned((int) $wanted[$target]['instanceid']);
        $relative = (string) $wanted[$target]['path'];
        if (count(self::fetch_raw_entries($instance, $relative)) > 0) {
            throw new \moodle_exception('locationselectionfolderconfirmrequired', 'local_coursepilot');
        }
    }

    /**
     * @param array{location: string, path: string, instanceid?: int, fingerprint?: array} $value
     * @return pointer_location
     */
    private static function to_pointer_location(array $value): pointer_location {
        if ($value['location'] === pointer_location::MOODLE) {
            return pointer_location::moodle('/' . trim((string) $value['path'], '/') . '/');
        }
        return pointer_location::external((int) $value['instanceid'], (string) $value['path'], (array) ($value['fingerprint'] ?? []));
    }

    /**
     * Creates missing instance folder levels through MKCOL (Spec §5).
     * An existing directory counts as success; see
     * {@see \local_coursepilot\webdav\webdav_client::mkcol()}.
     *
     * @param int $instanceid
     * @param string $path
     * @throws \moodle_exception locationselectionexternalerror, or errors from {@see webdav_instance::resolve_owned()}.
     */
    private static function ensure_directory(int $instanceid, string $path): void {
        $segments = self::validate_segments($path);
        if (empty($segments)) {
            return;
        }
        $instance = webdav_instance::resolve_owned($instanceid);
        try {
            $instance->client()->mkcol_chain($instance->directory_url(''), $segments);
        } catch (webdav_error $e) {
            throw pointer_reader::webdav_exception($e, 'locationselectionexternalerror');
        }
    }

    /**
     * Compares two targets for the same place. Ignore the fingerprint: an
     * unchanged instance with a freshly read equivalent fingerprint is no move.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    private static function same_place(array $a, array $b): bool {
        if ($a['location'] !== $b['location']) {
            return false;
        }
        if ($a['location'] === pointer_location::MOODLE) {
            return $a['path'] === $b['path'];
        }
        return (int) $a['instanceid'] === (int) $b['instanceid'] && $a['path'] === $b['path'];
    }

    /**
     * @param array $value
     * @return string
     */
    public static function describe_location(array $value): string {
        if ($value['location'] === pointer_location::MOODLE) {
            return get_string('locationselectionlocationmoodle', 'local_coursepilot', $value['path']);
        }
        return self::describe_external((int) $value['instanceid'], (string) $value['path']);
    }

    /**
     * @param int $instanceid
     * @param string $path
     * @return string
     */
    private static function describe_external(int $instanceid, string $path): string {
        global $DB;

        $name = $DB->get_field('repository_instances', 'name', ['id' => $instanceid]);
        $label = ($name !== false && $name !== '') ? $name : get_string('locationselectioninstanceunknown', 'local_coursepilot');
        if ($path === '') {
            return get_string('locationselectionlocationexternalalroot', 'local_coursepilot', $label);
        }
        return get_string('locationselectionlocationexternal', 'local_coursepilot', (object) ['instance' => $label, 'path' => $path]);
    }

    /**
     * Segment validation as in {@see storage_anchor}: empty normalized paths
     * are allowed for instance roots, but . and .. segments are forbidden.
     *
     * @param string $path
     * @return string[]
     * @throws \moodle_exception invalidcontextpath
     */
    private static function validate_segments(string $path): array {
        $normalised = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new \moodle_exception('invalidcontextpath', 'local_coursepilot');
            }
            $segments[] = $segment;
        }
        return $segments;
    }
}
