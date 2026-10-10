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

namespace local_coursepilot\webdav;

use local_coursepilot\pointer_location;
use local_coursepilot\storage_anchor;

/**
 * Resolves a WebDAV user instance named in the context pointer (Issue
 * #490, Spec #486 §2/§3) and in doing so runs all checks without network that
 * apply on every resolution - each with a named error, never a silent
 * fallback:
 *
 * 2. The instance exists (and belongs to the repository type "webdav").
 * 3. Instance ownership: the instance's `contextid` is the own user context,
 *    no "Login as" session. The instance ID comes exclusively from the
 *    server-side pointer, never from client input.
 * 4. WebDAV enablement for this person ({@see webdav_setup_steps}).
 * 5. HTTPS and Basic.
 * 6. The fingerprint (server, base path, account) is unchanged.
 *
 * Credentials are then read fresh from `repository_instance_config`
 * - never via Moodle's repository cache, never cached
 * (Spec §2/§3).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_instance {
    /** @var string Repository type name. */
    private const REPOSITORY_TYPE = 'webdav';

    /** @var string[] Instance option names from repository_webdav::get_instance_option_names(). */
    private const OPTION_NAMES = [
        'webdav_type', 'webdav_server', 'webdav_port', 'webdav_path', 'webdav_user', 'webdav_password', 'webdav_auth',
    ];

    /**
     * @var string[] The IServ area names, sorted (Issue #497, Spec #486
     *      §5/§2 check 8): if the root level of an instance consists exactly of
     *      these five names, it is recognised as IServ.
     */
    /** @var string The only IServ area under which a choice is allowed for IServ (Issue #497, Spec §5). */
    public const ISERV_FILES_AREA = 'Files';

    /**
     * Iserv areas.
     */
    private const ISERV_AREAS = [self::ISERV_FILES_AREA, 'Groups', 'Print', 'Temp', 'Windows'];

    /**
     * Resolves the webdav instance.
     *
     * @param pointer_location $location Must be {@see pointer_location::EXTERNAL}.
     * @param ?webdav_transport $transport The transport.
     * @return resolved_webdav_instance
     * @throws \moodle_exception webdavinstancemissing/webdavinstanceforeign/webdavnotenabled/
     *         webdavauthunsupported/webdavfingerprintchanged
     */
    public static function resolve(pointer_location $location, ?webdav_transport $transport = null): resolved_webdav_instance {
        $resolved = self::resolve_owned((int) $location->instanceid, $transport);

        $options = self::fresh_options((int) $location->instanceid);
        if (self::fingerprint($options) !== self::normalised_fingerprint($location->fingerprint ?? [])) {
            throw new \moodle_exception('webdavfingerprintchanged', 'local_coursepilot', '', webdav_setup_steps::LOCATION_SELECTION_PAGE);
        }

        return $resolved;
    }

    /**
     * Resolves a WebDAV user instance by ID without requiring a
     * fingerprint (Issue #494): existence, instance ownership (incl.
     * "Login as"), WebDAV enablement, https+Basic - the same checks 2-5 as
     * {@see resolve()}, only without check 6 (fingerprint), because the
     * location selection page browses an instance before a
     * context pointer with a stored fingerprint exists at all. The
     * freshly read fingerprint of this call is what the location selection
     * writes into the pointer on completion ({@see fingerprint_of()}).
     *
     * @param int $instanceid
     * @param ?webdav_transport $transport The transport.
     * @return resolved_webdav_instance
     * @throws \moodle_exception webdavinstancemissing/webdavinstanceforeign/webdavnotenabled/webdavauthunsupported
     */
    public static function resolve_owned(int $instanceid, ?webdav_transport $transport = null): resolved_webdav_instance {
        global $DB, $USER;

        $record = $DB->get_record_sql(
            'SELECT ri.id, ri.contextid
               FROM {repository_instances} ri
               JOIN {repository} r ON r.id = ri.typeid
              WHERE ri.id = :id AND r.type = :type',
            ['id' => $instanceid, 'type' => self::REPOSITORY_TYPE]
        );
        if (!$record) {
            throw new \moodle_exception('webdavinstancemissing', 'local_coursepilot', '', webdav_setup_steps::LOCATION_SELECTION_PAGE);
        }

        $owncontextid = storage_anchor::own_context()->id;
        if ((int) $record->contextid !== (int) $owncontextid || \core\session\manager::is_loggedinas()) {
            throw new \moodle_exception('webdavinstanceforeign', 'local_coursepilot', '', webdav_setup_steps::LOCATION_SELECTION_PAGE);
        }

        if (!webdav_setup_steps::enabled_for_user((int) $USER->id)) {
            throw new \moodle_exception('webdavnotenabled', 'local_coursepilot', '', webdav_setup_steps::LOCATION_SELECTION_PAGE);
        }

        $options = self::fresh_options($instanceid);

        if (!self::auth_supported($options)) {
            throw new \moodle_exception('webdavauthunsupported', 'local_coursepilot', '', webdav_setup_steps::LOCATION_SELECTION_PAGE);
        }

        // \curl (lib/filelib.php) is not autoloaded - plain pages like
        // location_selection_browse.php would fail with "Class curl not found".
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $transport ??= self::transport($options);
        return new resolved_webdav_instance(self::base_url($options), $transport);
    }

    /**
     * Resolves the transport at the composition root. Tests may provide their
     * fake through Moodle's request-local DI container; production receives
     * the regular cURL transport.
     *
     * @param array $options
     * @phpstan-param array<string,string|null> $options
     */
    private static function transport(array $options): webdav_transport {
        try {
            $transport = \core\di::get(webdav_transport::class);
            if ($transport instanceof webdav_transport) {
                return $transport;
            }
        } catch (\Throwable $e) {
            // No binding exists in production.
        }
        return new curl_transport(
            new \curl(),
            (string) ($options['webdav_user'] ?? ''),
            (string) ($options['webdav_password'] ?? '')
        );
    }

    /**
     * The freshly read fingerprint of an instance (Issue #494) - what the
     * location selection writes into the context pointer on completion.
     *
     * @param int $instanceid
     * @return array{server: string, basepath: string, account: string}
     */
    public static function fingerprint_of(int $instanceid): array {
        return self::fingerprint(self::fresh_options($instanceid));
    }

    /**
     * Whether an instance satisfies https+Basic, without throwing on a violation
     * (Issue #497) - unlike {@see resolve_owned()}, which is deliberately not
     * reused here: the location selection page must still *show* a
     * non-selectable instance (with a reason) instead of aborting when
     * listing all own instances.
     *
     * @param int $instanceid
     * @return bool
     */
    public static function has_supported_auth(int $instanceid): bool {
        return self::auth_supported(self::fresh_options($instanceid));
    }

    /**
     * The one predicate "https+Basic", shared by {@see resolve_owned()}
     * (throws) and {@see has_supported_auth()} (does not throw) - Issue #497
     * standards review: both previously knew the condition once each, inverted.
     *
     * @param array $options
     * @phpstan-param array<string,string|null> $options
     * @return bool
     */
    private static function auth_supported(array $options): bool {
        return (int) ($options['webdav_type'] ?? 0) === 1 && ($options['webdav_auth'] ?? '') === 'basic';
    }

    /**
     * IServ detection as a yes/no check (Issue #497, Spec #486 §5): the
     * root level of an instance consists exactly of the five IServ areas.
     * Needs network (a PROPFIND on the instance root) - the result
     * then belongs in the pointer's fingerprint, so that the later
     * resolution checks without network (§2, check 8).
     *
     * @param int $instanceid
     * @param ?webdav_transport $transport The transport.
     * @return bool
     * @throws \moodle_exception wie {@see resolve_owned()}.
     * @throws \local_coursepilot\webdav\webdav_error on a network error - to be handled by the caller.
     */
    public static function detect_iserv_root(int $instanceid, ?webdav_transport $transport = null): bool {
        $resolved = self::resolve_owned($instanceid, $transport);
        return self::is_iserv_listing($resolved->client()->propfind($resolved->directory_url(''), 1));
    }

    /**
     * Tells whether the webdav instance is iserv listing.
     *
     * @param array $entries Root level, {@see webdav_client::propfind()}.
     * @phpstan-param array<int,array{name:string,type:string}> $entries
     * @return bool
     */
    public static function is_iserv_listing(array $entries): bool {
        $names = array_values(array_map(
            static fn (array $entry): string => $entry['name'],
            array_filter($entries, static fn (array $entry): bool => $entry['type'] === 'folder')
        ));
        sort($names);
        return $names === self::ISERV_AREAS;
    }

    /**
     * Reads the instance options directly from `repository_instance_config` -
     * deliberately not via `repository::get_instance()`/its MUC cache
     * (Spec §2: "Credentials are read fresh from the instance on every
     * access and never cached").
     * @param int $instanceid
     * @return array<string, string|null>
     */
    private static function fresh_options(int $instanceid): array {
        global $DB;

        $records = $DB->get_records('repository_instance_config', ['instanceid' => $instanceid], '', 'name, value');
        $options = [];
        foreach (self::OPTION_NAMES as $name) {
            $options[$name] = isset($records[$name]) ? $records[$name]->value : null;
        }
        return $options;
    }

    /**
     * Provides fingerprint.
     *
     * @param array $options
     * @phpstan-param array<string,string|null> $options
     * @return array{server: string, basepath: string, account: string}
     */
    private static function fingerprint(array $options): array {
        return [
            'server' => (string) ($options['webdav_server'] ?? ''),
            'basepath' => trim((string) ($options['webdav_path'] ?? ''), '/'),
            'account' => (string) ($options['webdav_user'] ?? ''),
        ];
    }

    /**
     * Provides normalised fingerprint.
     *
     * @param array $fingerprint Raw from the pointer.
     * @return array{server: string, basepath: string, account: string}
     */
    private static function normalised_fingerprint(array $fingerprint): array {
        return [
            'server' => (string) ($fingerprint['server'] ?? ''),
            'basepath' => trim((string) ($fingerprint['basepath'] ?? ''), '/'),
            'account' => (string) ($fingerprint['account'] ?? ''),
        ];
    }

    /**
     * Returns https address of the instance incl. base path, with trailing "/".
     *
     * @param array $options
     * @phpstan-param array<string,string|null> $options
     * @return string https address of the instance incl. base path, with trailing "/".
     */
    private static function base_url(array $options): string {
        // Moodle's WebDAV form stores "default port" as '0'.
        $port = (int) ($options['webdav_port'] ?? 0);
        $host = $options['webdav_server'] . ($port > 0 ? ':' . $port : '');
        $path = trim((string) ($options['webdav_path'] ?? ''), '/');
        return 'https://' . $host . '/' . ($path !== '' ? $path . '/' : '');
    }
}
