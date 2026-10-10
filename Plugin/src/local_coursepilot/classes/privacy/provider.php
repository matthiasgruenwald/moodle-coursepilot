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

namespace local_coursepilot\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;
use local_coursepilot\context_files;
use local_coursepilot\history\retention;
use local_coursepilot\history\version_history;

/**
 * Full privacy provider (#336, extended for context files in #345/#343).
 * OAuth codes and tokens link userid to the authorizing teacher. Local
 * plugin tables need metadata, request and userlist providers because
 * core has no deletion mechanism for them (#298 resolution, point 7).
 *
 * OAuth records belong to the system context; context data belongs to
 * the teacher's user context. After migration to Private Files (#407,
 * Spec 0016 §3.2), this provider handles only the legacy
 * local_coursepilot/coursepilot_context file area. Core user privacy
 * exports and deletes current user/private context and material files
 * (Spec 0018 §2, #428). A second path here would duplicate exports or
 * delete unrelated Private Files. Once teachers clear migrated legacy
 * files, the legacy handling can be removed.
 *
 * Log events (#339) are handled by logstore_standard's privacy provider,
 * which exports/deletes a user's logs regardless of source plugin. Events
 * already supply userid, contextid and component.
 *
 * Change history (#385/#641) belongs to module contexts. Discover it by
 * state userid for existing modules; retention handles vanished modules.
 * Export only the requester's metadata, without snapshot content, and
 * files allowed by history\file_policy. Delete through the shared
 * history\retention::delete_versions() contract.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class provider implements \core_privacy\local\metadata\provider, \core_privacy\local\request\core_userlist_provider, \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection = self::describe_oauth_and_version_tables($collection);
        $collection = self::describe_context_and_workbench_tables($collection);

        $collection->add_external_location_link('webdav_external_storage', [
            'path' => 'privacy:metadata:webdav_external_storage:path',
            'content' => 'privacy:metadata:webdav_external_storage:content',
        ], 'privacy:metadata:webdav_external_storage');

        return $collection;
    }

    /**
     * Describe OAuth and activity-version tables (Issue #523, extracted from
     * get_metadata() to keep it within 50 lines).
     *
     * @param collection $collection
     * @return collection
     */
    private static function describe_oauth_and_version_tables(collection $collection): collection {
        $collection->add_database_table('local_coursepilot_oauth_code', [
            'clientid' => 'privacy:metadata:oauth_code:clientid',
            'userid' => 'privacy:metadata:oauth_code:userid',
            'redirecturi' => 'privacy:metadata:oauth_code:redirecturi',
            'codechallenge' => 'privacy:metadata:oauth_code:codechallenge',
            'expires' => 'privacy:metadata:oauth_code:expires',
            'used' => 'privacy:metadata:oauth_code:used',
        ], 'privacy:metadata:oauth_code');

        $collection->add_database_table('local_coursepilot_oauth_grant', [
            'userid' => 'privacy:metadata:oauth_grant:userid',
            'clientid' => 'privacy:metadata:oauth_grant:clientid',
            'revoked' => 'privacy:metadata:oauth_grant:revoked',
            'timecreated' => 'privacy:metadata:oauth_grant:timecreated',
        ], 'privacy:metadata:oauth_grant');

        $collection->add_database_table('local_coursepilot_oauth_token', [
            'connectionid' => 'privacy:metadata:oauth_token:connectionid',
            'clientid' => 'privacy:metadata:oauth_token:clientid',
            'userid' => 'privacy:metadata:oauth_token:userid',
            'expires' => 'privacy:metadata:oauth_token:expires',
            'refreshexpires' => 'privacy:metadata:oauth_token:refreshexpires',
            'revoked' => 'privacy:metadata:oauth_token:revoked',
            'timecreated' => 'privacy:metadata:oauth_token:timecreated',
        ], 'privacy:metadata:oauth_token');

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');

        $collection->add_database_table('local_coursepilot_cm_version', [
            'cmid' => 'privacy:metadata:cm_version:cmid',
            'courseid' => 'privacy:metadata:cm_version:courseid',
            'version' => 'privacy:metadata:cm_version:version',
            'source' => 'privacy:metadata:cm_version:source',
            'sourcecmid' => 'privacy:metadata:cm_version:sourcecmid',
            'userid' => 'privacy:metadata:cm_version:userid',
            'moduleinfo_json' => 'privacy:metadata:cm_version:moduleinfo_json',
            'coursemodule_json' => 'privacy:metadata:cm_version:coursemodule_json',
            'arrangement_json' => 'privacy:metadata:cm_version:arrangement_json',
            'timecreated' => 'privacy:metadata:cm_version:timecreated',
        ], 'privacy:metadata:cm_version');
        // Both tables store file descriptions without userid. Still declare fields
        // rather than [] to avoid Moodle's missing-fields metadata warning.
        $collection->add_database_table('local_coursepilot_cm_version_file', [
            'versionid' => 'privacy:metadata:cm_version_file:versionid',
            'fileid' => 'privacy:metadata:cm_version_file:fileid',
            'gap' => 'privacy:metadata:cm_version_file:gap',
        ], 'privacy:metadata:cm_version_file');
        $collection->add_database_table('local_coursepilot_cm_file', [
            'pathnamehash' => 'privacy:metadata:cm_file:pathnamehash',
            'contenthash' => 'privacy:metadata:cm_file:contenthash',
            'filepath' => 'privacy:metadata:cm_file:filepath',
            'filename' => 'privacy:metadata:cm_file:filename',
            'filesize' => 'privacy:metadata:cm_file:filesize',
            'timemodified' => 'privacy:metadata:cm_file:timemodified',
        ], 'privacy:metadata:cm_file');

        return $collection;
    }

    /**
     * Describe marking memory and workbench download tickets (Issue #523,
     * extracted from get_metadata()).
     *
     * @param collection $collection
     * @return collection
     */
    private static function describe_context_and_workbench_tables(collection $collection): collection {
        // Marking memory (#493, Spec #486 §6): user ID and context-file client path.
        // See local_coursepilot\mark_memory.
        $collection->add_database_table('local_coursepilot_context_mark', [
            'userid' => 'privacy:metadata:context_mark:userid',
            'path' => 'privacy:metadata:context_mark:path',
            'ismarked' => 'privacy:metadata:context_mark:ismarked',
        ], 'privacy:metadata:context_mark');

        // External storage (#500, ADR 0021, Spec #486 §11) is outside Moodle's
        // export/deletion mechanisms. Pointer, pending note and workbench remain
        // in user/private and are covered by core user privacy. Declare the
        // external location here so the privacy report does not omit it.
        // Workbench tickets (#501, Spec #486 §13) belong to the system context,
        // like OAuth records. Store only a hash of the ticket secret.
        $collection->add_database_table('local_coursepilot_workbench_ticket', [
            'userid' => 'privacy:metadata:workbench_ticket:userid',
            'path' => 'privacy:metadata:workbench_ticket:path',
            'contenthash' => 'privacy:metadata:workbench_ticket:contenthash',
            'oauthtokenid' => 'privacy:metadata:workbench_ticket:oauthtokenid',
            'oauthconnectionid' => 'privacy:metadata:workbench_ticket:oauthconnectionid',
            'expires' => 'privacy:metadata:workbench_ticket:expires',
            'timecreated' => 'privacy:metadata:workbench_ticket:timecreated',
        ], 'privacy:metadata:workbench_ticket');

        return $collection;
    }

    /**
     * Returns contexts for userid.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $hasoauthdata = $DB->record_exists('local_coursepilot_oauth_code', ['userid' => $userid])
            || $DB->record_exists('local_coursepilot_oauth_token', ['userid' => $userid])
            || $DB->record_exists('local_coursepilot_oauth_grant', ['userid' => $userid])
            || $DB->record_exists('local_coursepilot_workbench_ticket', ['userid' => $userid]);
        if ($hasoauthdata) {
            $contextlist->add_system_context();
        }

        $usercontext = \context_user::instance($userid);
        if (self::context_user_has_data($usercontext)) {
            $contextlist->add_user_context($userid);
        }

        $contextlist->add_from_sql(
            'SELECT ctx.id
               FROM {context} ctx
               JOIN {local_coursepilot_cm_version} v ON v.cmid = ctx.instanceid
              WHERE ctx.contextlevel = :contextlevel AND v.userid = :userid',
            ['contextlevel' => CONTEXT_MODULE, 'userid' => $userid]
        );

        return $contextlist;
    }

    /**
     * Returns users in context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof \context_system) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_coursepilot_oauth_code}', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_coursepilot_oauth_token}', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_coursepilot_oauth_grant}', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_coursepilot_workbench_ticket}', []);
            return;
        }

        if ($context instanceof \context_user && self::context_user_has_data($context)) {
            $userlist->add_user($context->instanceid);
        }

        if ($context instanceof \context_module) {
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {local_coursepilot_cm_version} WHERE cmid = :cmid',
                ['cmid' => $context->instanceid]
            );
        }
    }

    /**
     * Whether a user context has actual context files or marking-memory rows.
     * Unlike core_user::get_users_in_context(), check data rather than blindly
     * adding the context owner, avoiding empty contexts in discovery.
     *
     * @param \context_user $context
     * @return bool
     */
    private static function context_user_has_data(\context_user $context): bool {
        global $DB;

        if ($DB->record_exists('local_coursepilot_context_mark', ['userid' => $context->instanceid])) {
            return true;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID
        );
        foreach ($files as $file) {
            if (!$file->is_directory()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Exports user data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                self::export_user_context($context, $userid);
                continue;
            }

            if ($context instanceof \context_system) {
                self::export_system_context($context, $userid);
            }

            if ($context instanceof \context_module) {
                self::export_history($context, $userid);
            }
        }
    }

    /**
     * Exports the requester's own history states of one activity: metadata and
     * file names allowed by the history file policy, never the snapshot content
     * (it may hold other teachers' design work) or files outside the policy.
     *
     * @param \context_module $context
     * @param int $userid
     */
    private static function export_history(\context_module $context, int $userid): void {
        global $DB;

        $records = $DB->get_records(
            'local_coursepilot_cm_version',
            ['cmid' => $context->instanceid, 'userid' => $userid],
            'version ASC'
        );
        if (!$records) {
            return;
        }
        $modname = (string) get_coursemodule_from_id('', $context->instanceid, 0, false, MUST_EXIST)->modname;
        $exportfile = static fn(\stdClass $file): \stdClass => (object) [
            'filearea' => $file->filearea,
            'filename' => $file->filename,
            'gap' => transform::yesno($file->gap),
        ];
        $versions = array_map(static fn(\stdClass $record): \stdClass => (object) [
            'version' => (int) $record->version,
            'source' => $record->source,
            'sourcecmid' => $record->sourcecmid,
            'timecreated' => transform::datetime($record->timecreated),
            'files' => array_map($exportfile, version_history::allowed_files((int) $record->id, $modname)),
        ], array_values($records));

        writer::with_context($context)->export_data(
            [get_string('pluginname', 'local_coursepilot'), get_string('historytitle', 'local_coursepilot')],
            (object) ['versions' => $versions]
        );
    }

    /**
     * Export legacy context files and marking memory for the user context.
     * Extracted from export_user_data() to keep it within 50 lines (Issue #523).
     *
     * @param \context_user $context
     * @param int $userid
     */
    private static function export_user_context(\context_user $context, int $userid): void {
        global $DB;

        writer::with_context($context)->export_area_files(
            [get_string('pluginname', 'local_coursepilot')],
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID
        );

        $markrecords = $DB->get_records('local_coursepilot_context_mark', ['userid' => $userid]);
        $exportedmarks = array_map(static fn($record): \stdClass => (object) [
            'path' => $record->path,
            'ismarked' => transform::yesno($record->ismarked),
        ], array_values($markrecords));
        if ($exportedmarks) {
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_coursepilot'), get_string('privacy:metadata:context_mark', 'local_coursepilot')],
                (object) ['entries' => $exportedmarks]
            );
        }
    }

    /**
     * Export OAuth codes/tokens and workbench download tickets for the system
     * context (Issue #523, extracted from export_user_data()).
     *
     * @param \context_system $context
     * @param int $userid
     */
    private static function export_system_context(\context_system $context, int $userid): void {
        global $DB;

        $codes = $DB->get_records('local_coursepilot_oauth_code', ['userid' => $userid]);
        $exportedcodes = array_map(static fn($record): \stdClass => (object) [
            'clientid' => $record->clientid,
            'redirecturi' => $record->redirecturi,
            'expires' => transform::datetime($record->expires),
            'used' => transform::yesno($record->used),
        ], array_values($codes));

        $tokens = $DB->get_records('local_coursepilot_oauth_token', ['userid' => $userid]);
        $exportedtokens = array_map(static fn($record): \stdClass => (object) [
            'clientid' => $record->clientid,
            'expires' => transform::datetime($record->expires),
            'refreshexpires' => transform::datetime($record->refreshexpires),
            'revoked' => transform::yesno($record->revoked),
            'timecreated' => transform::datetime($record->timecreated),
        ], array_values($tokens));

        $grants = $DB->get_records('local_coursepilot_oauth_grant', ['userid' => $userid]);
        $exportedgrants = array_map(static fn($record): \stdClass => (object) [
            'id' => $record->id, 'clientid' => $record->clientid,
            'revoked' => transform::yesno($record->revoked),
            'timecreated' => transform::datetime($record->timecreated),
        ], array_values($grants));

        $tickets = $DB->get_records('local_coursepilot_workbench_ticket', ['userid' => $userid]);
        $exportedtickets = array_map(static fn($record): \stdClass => (object) [
            'path' => $record->path,
            'expires' => transform::datetime($record->expires),
            'timecreated' => transform::datetime($record->timecreated),
        ], array_values($tickets));

        writer::with_context($context)->export_data(
            [get_string('pluginname', 'local_coursepilot')],
            (object) [
                'oauth_codes' => $exportedcodes,
                'oauth_tokens' => $exportedtokens,
                'oauth_connections' => $exportedgrants,
                'workbench_tickets' => $exportedtickets,
            ]
        );
    }

    /**
     * Deletes data for all users in context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_user) {
            self::delete_context_files($context);
            return;
        }

        if ($context instanceof \context_module) {
            retention::purge_cm((int) $context->instanceid);
            return;
        }

        if (!$context instanceof \context_system) {
            return;
        }
        $DB->delete_records('local_coursepilot_oauth_code');
        $DB->delete_records('local_coursepilot_oauth_token');
        $DB->delete_records('local_coursepilot_oauth_grant');
        $DB->delete_records('local_coursepilot_workbench_ticket');
    }

    /**
     * Delete legacy context files (#343) and marking memory (#493) in the
     * given user context.
     *
     * @param \context $context
     */
    private static function delete_context_files(\context $context): void {
        global $DB;

        get_file_storage()->delete_area_files(
            $context->id,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID
        );
        if ($context instanceof \context_user) {
            $DB->delete_records('local_coursepilot_context_mark', ['userid' => $context->instanceid]);
        }
    }

    /**
     * Deletes data for user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                self::delete_context_files($context);
            }
            if ($context instanceof \context_module) {
                retention::purge_cm_for_users((int) $context->instanceid, [$userid]);
            }
        }

        // Database context IDs and SYSCONTEXTID may be strings. Normalize both
        // to integers before strict comparison; otherwise user-context deletion
        // could leave connections, codes and workbench tickets behind.
        $contextids = array_map('intval', $contextlist->get_contextids());
        if (!in_array((int) SYSCONTEXTID, $contextids, true)) {
            return;
        }
        $DB->delete_records('local_coursepilot_oauth_code', ['userid' => $userid]);
        $DB->delete_records('local_coursepilot_oauth_token', ['userid' => $userid]);
        $DB->delete_records('local_coursepilot_oauth_grant', ['userid' => $userid]);
        $DB->delete_records('local_coursepilot_workbench_ticket', ['userid' => $userid]);
    }

    /**
     * Deletes data for users.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context instanceof \context_user) {
            self::delete_context_files($context);
            return;
        }

        if ($context instanceof \context_module) {
            retention::purge_cm_for_users((int) $context->instanceid, $userlist->get_userids());
            return;
        }

        if (!$context instanceof \context_system) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_coursepilot_oauth_code', "userid $insql", $inparams);
        $DB->delete_records_select('local_coursepilot_oauth_token', "userid $insql", $inparams);
        $DB->delete_records_select('local_coursepilot_oauth_grant', "userid $insql", $inparams);
        $DB->delete_records_select('local_coursepilot_workbench_ticket', "userid $insql", $inparams);
    }
}
