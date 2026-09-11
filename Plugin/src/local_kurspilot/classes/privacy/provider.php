<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;
use local_kurspilot\context_files;

/**
 * Voller Privacy-Provider (#336, erweitert in #345 um Kontextdateien aus
 * #343): die Autorisierungscode- und Token-Tabellen binden `userid` an die
 * Lehrkraft, die den Client autorisiert hat - `null_provider` traegt hier
 * nicht (anders als beim Moodle-Webservice-Token, siehe
 * core_webservice\privacy\provider), weil lokale, plugin-eigene Tabellen
 * betroffen sind, kein Core-Loeschmechanismus existiert. Voller Provider wie
 * im Resolutionskommentar zu #298 (Punkt 7) vereinbart: metadata\provider +
 * request\plugin\provider + core_userlist_provider.
 *
 * Zwei Kontextebenen: Autorisierungscodes/Token haengen am Systemkontext
 * (kein Kurs-/Modulbezug), Kontextdateien (#343) am privaten Nutzerkontext
 * der jeweiligen Lehrkraft ({@see \local_kurspilot\context_files}).
 *
 * Der Datei-Teil dieses Providers deckt seit dem Umzug auf Moodles Private
 * Files (#407, Spec 0016 Abschnitt 3.2) nur noch den **Altbestand** in der
 * alten Filearea `local_kurspilot/kurspilot_context` ab - deshalb stehen hier
 * ueberall LEGACY_COMPONENT/LEGACY_FILEAREA statt der aktuellen Konstanten.
 * Die neuen Kontextdateien liegen in `user/private` und werden vom
 * Core-Provider (core_user, `user/classes/privacy/provider.php`) exportiert
 * und geloescht; Kurspilot fasst sie bewusst NICHT an - ein zweiter Export
 * waere Doppelarbeit, ein zweiter Loeschpfad wuerde fremde Dateien aus
 * "Meine Dateien" mitreissen. Ist der Altbestand vollstaendig umgezogen und
 * von der Lehrkraft geraeumt, kann der Datei-Teil in einem spaeteren Release
 * ersatzlos entfallen.
 *
 * Derselbe Grund gilt fuer den Materialordner (Spec 0018 §2, #428,
 * {@see \local_kurspilot\material_files}): auch er liegt in `user/private`
 * (eigener Unterordner, gleicher Anker) und wird bereits vom Core-Provider
 * exportiert/geloescht - kein zusaetzlicher Export-/Loeschpfad hier noetig.
 *
 * Protokollereignisse (#339) sind bewusst NICHT hier abgedeckt: das Ablegen,
 * Exportieren und Loeschen der eigentlichen Log-Eintraege besorgt Moodle-Core
 * zentral ueber logstore_standard's eigenen Privacy-Provider (der exportiert/
 * loescht alle Log-Eintraege eines Nutzers unabhaengig vom ausloesenden
 * Plugin) - ein eigener Export-/Loeschpfad hier waere doppelte, mit Core
 * kollidierende Arbeit. Die Ereignisse selbst tragen bereits die dafuer
 * noetigen Merkmale (userid, contextid, component), siehe
 * classes/event/tool_access_*.php.
 *
 * Der Aenderungsverlauf (local_kurspilot_cm_version/_version_file/_cm_file,
 * #385/#386/#387) ist aus demselben Grund nur in get_metadata() beschrieben,
 * ohne eigenen Export-/Loeschpfad fuer die dort mitgefuehrte userid: dieses
 * Ticket (#387) verlangt eine beschriebene Aufbewahrung, keinen vollen
 * GDPR-Pfad je Nutzer. Anders als bei Log-Eintraegen gibt es hier aber
 * bereits eine harte Obergrenze durch Code, nicht durch einen Core-Provider:
 * die admin-seitige Loeschfrist (Standard 1 Jahr, siehe
 * local_kurspilot\history\retention) sowie die Kurs-/Aktivitaets-Kaskade
 * loeschen jeden Stand spaetestens automatisch. Ein manueller Export-/
 * Loeschpfad je Nutzer kann bei Bedarf nachgeruestet werden, sobald der
 * Verlauf ueber Ticket 10 hinaus tatsaechlich personenbezogene Inhalte traegt.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_kurspilot_oauth_code', [
            'clientid' => 'privacy:metadata:oauth_code:clientid',
            'userid' => 'privacy:metadata:oauth_code:userid',
            'redirecturi' => 'privacy:metadata:oauth_code:redirecturi',
            'codechallenge' => 'privacy:metadata:oauth_code:codechallenge',
            'expires' => 'privacy:metadata:oauth_code:expires',
            'used' => 'privacy:metadata:oauth_code:used',
        ], 'privacy:metadata:oauth_code');

        $collection->add_database_table('local_kurspilot_oauth_token', [
            'clientid' => 'privacy:metadata:oauth_token:clientid',
            'userid' => 'privacy:metadata:oauth_token:userid',
            'expires' => 'privacy:metadata:oauth_token:expires',
            'refreshexpires' => 'privacy:metadata:oauth_token:refreshexpires',
            'revoked' => 'privacy:metadata:oauth_token:revoked',
            'timecreated' => 'privacy:metadata:oauth_token:timecreated',
        ], 'privacy:metadata:oauth_token');

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');

        $collection->add_database_table('local_kurspilot_cm_version', [
            'cmid' => 'privacy:metadata:cm_version:cmid',
            'courseid' => 'privacy:metadata:cm_version:courseid',
            'userid' => 'privacy:metadata:cm_version:userid',
            'timecreated' => 'privacy:metadata:cm_version:timecreated',
        ], 'privacy:metadata:cm_version');
        $collection->add_database_table('local_kurspilot_cm_version_file', [], 'privacy:metadata:cm_version_file');
        $collection->add_database_table('local_kurspilot_cm_file', [], 'privacy:metadata:cm_file');

        // Markierungsgedaechtnis (#493, Spec #486 §6): traegt userid und den
        // Client-Pfad einer Kontextdatei, siehe local_kurspilot\mark_memory.
        $collection->add_database_table('local_kurspilot_context_mark', [
            'userid' => 'privacy:metadata:context_mark:userid',
            'path' => 'privacy:metadata:context_mark:path',
            'ismarked' => 'privacy:metadata:context_mark:ismarked',
        ], 'privacy:metadata:context_mark');

        return $collection;
    }

    /**
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $hasoauthdata = $DB->record_exists('local_kurspilot_oauth_code', ['userid' => $userid])
            || $DB->record_exists('local_kurspilot_oauth_token', ['userid' => $userid]);
        if ($hasoauthdata) {
            $contextlist->add_system_context();
        }

        $usercontext = \context_user::instance($userid);
        if (self::context_user_has_data($usercontext)) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    /**
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof \context_system) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kurspilot_oauth_code}', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kurspilot_oauth_token}', []);
            return;
        }

        if ($context instanceof \context_user && self::context_user_has_data($context)) {
            $userlist->add_user($context->instanceid);
        }
    }

    /**
     * Ob im Nutzerkontext tatsaechlich Kontextdateien oder Eintraege im
     * Markierungsgedaechtnis liegen - anders als core_user::get_users_in_context()
     * (die den Kontexteigentuemer blind hinzufuegt) prueft dieser Provider den
     * echten Bestand, damit ein beliebiger Nutzerkontext ohne Daten nicht
     * faelschlich auftaucht.
     *
     * @param \context_user $context
     * @return bool
     */
    private static function context_user_has_data(\context_user $context): bool {
        global $DB;

        if ($DB->record_exists('local_kurspilot_context_mark', ['userid' => $context->instanceid])) {
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
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                writer::with_context($context)->export_area_files(
                    [get_string('pluginname', 'local_kurspilot')],
                    context_files::LEGACY_COMPONENT,
                    context_files::LEGACY_FILEAREA,
                    context_files::ITEMID
                );

                $markrecords = $DB->get_records('local_kurspilot_context_mark', ['userid' => $userid]);
                $exportedmarks = array_map(static fn($record): \stdClass => (object) [
                    'path' => $record->path,
                    'ismarked' => transform::yesno($record->ismarked),
                ], array_values($markrecords));
                if ($exportedmarks) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_kurspilot'), get_string('privacy:metadata:context_mark', 'local_kurspilot')],
                        (object) ['entries' => $exportedmarks]
                    );
                }
                continue;
            }

            if (!$context instanceof \context_system) {
                continue;
            }

            $codes = $DB->get_records('local_kurspilot_oauth_code', ['userid' => $userid]);
            $exportedcodes = array_map(static fn($record): \stdClass => (object) [
                'clientid' => $record->clientid,
                'redirecturi' => $record->redirecturi,
                'expires' => transform::datetime($record->expires),
                'used' => transform::yesno($record->used),
            ], array_values($codes));

            $tokens = $DB->get_records('local_kurspilot_oauth_token', ['userid' => $userid]);
            $exportedtokens = array_map(static fn($record): \stdClass => (object) [
                'clientid' => $record->clientid,
                'expires' => transform::datetime($record->expires),
                'refreshexpires' => transform::datetime($record->refreshexpires),
                'revoked' => transform::yesno($record->revoked),
                'timecreated' => transform::datetime($record->timecreated),
            ], array_values($tokens));

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_kurspilot')],
                (object) ['oauth_codes' => $exportedcodes, 'oauth_tokens' => $exportedtokens]
            );
        }
    }

    /**
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_user) {
            self::delete_context_files($context);
            return;
        }

        if (!$context instanceof \context_system) {
            return;
        }
        $DB->delete_records('local_kurspilot_oauth_code');
        $DB->delete_records('local_kurspilot_oauth_token');
    }

    /**
     * Loescht alle Kontextdateien (#343) und das Markierungsgedaechtnis
     * (#493) im gegebenen Nutzerkontext.
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
            $DB->delete_records('local_kurspilot_context_mark', ['userid' => $context->instanceid]);
        }
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                self::delete_context_files($context);
            }
        }

        if (!in_array(SYSCONTEXTID, $contextlist->get_contextids(), true)) {
            return;
        }
        $DB->delete_records('local_kurspilot_oauth_code', ['userid' => $userid]);
        $DB->delete_records('local_kurspilot_oauth_token', ['userid' => $userid]);
    }

    /**
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context instanceof \context_user) {
            self::delete_context_files($context);
            return;
        }

        if (!$context instanceof \context_system) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_kurspilot_oauth_code', "userid $insql", $inparams);
        $DB->delete_records_select('local_kurspilot_oauth_token', "userid $insql", $inparams);
    }
}
