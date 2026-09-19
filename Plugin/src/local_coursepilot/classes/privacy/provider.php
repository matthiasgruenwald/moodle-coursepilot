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

namespace local_coursepilot\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;
use local_coursepilot\context_files;

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
 * der jeweiligen Lehrkraft ({@see \local_coursepilot\context_files}).
 *
 * Der Datei-Teil dieses Providers deckt seit dem Umzug auf Moodles Private
 * Files (#407, Spec 0016 Abschnitt 3.2) nur noch den **Altbestand** in der
 * alten Filearea `local_coursepilot/coursepilot_context` ab - deshalb stehen hier
 * ueberall LEGACY_COMPONENT/LEGACY_FILEAREA statt der aktuellen Konstanten.
 * Die neuen Kontextdateien liegen in `user/private` und werden vom
 * Core-Provider (core_user, `user/classes/privacy/provider.php`) exportiert
 * und geloescht; Coursepilot fasst sie bewusst NICHT an - ein zweiter Export
 * waere Doppelarbeit, ein zweiter Loeschpfad wuerde fremde Dateien aus
 * "Meine Dateien" mitreissen. Ist der Altbestand vollstaendig umgezogen und
 * von der Lehrkraft geraeumt, kann der Datei-Teil in einem spaeteren Release
 * ersatzlos entfallen.
 *
 * Derselbe Grund gilt fuer den Materialordner (Spec 0018 §2, #428,
 * {@see \local_coursepilot\material_files}): auch er liegt in `user/private`
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
 * Der Aenderungsverlauf (local_coursepilot_cm_version/_version_file/_cm_file,
 * #385/#386/#387) ist aus demselben Grund nur in get_metadata() beschrieben,
 * ohne eigenen Export-/Loeschpfad fuer die dort mitgefuehrte userid: dieses
 * Ticket (#387) verlangt eine beschriebene Aufbewahrung, keinen vollen
 * GDPR-Pfad je Nutzer. Anders als bei Log-Eintraegen gibt es hier aber
 * bereits eine harte Obergrenze durch Code, nicht durch einen Core-Provider:
 * die admin-seitige Loeschfrist (Standard 1 Jahr, siehe
 * local_coursepilot\history\retention) sowie die Kurs-/Aktivitaets-Kaskade
 * loeschen jeden Stand spaetestens automatisch. Ein manueller Export-/
 * Loeschpfad je Nutzer kann bei Bedarf nachgeruestet werden, sobald der
 * Verlauf ueber Ticket 10 hinaus tatsaechlich personenbezogene Inhalte traegt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
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
        $collection = self::describe_oauth_and_version_tables($collection);
        $collection = self::describe_context_and_werkbank_tables($collection);

        $collection->add_external_location_link('webdav_external_storage', [
            'path' => 'privacy:metadata:webdav_external_storage:path',
            'content' => 'privacy:metadata:webdav_external_storage:content',
        ], 'privacy:metadata:webdav_external_storage');

        return $collection;
    }

    /**
     * Registriert die OAuth- und Aktivitaetsstand-Tabellen (Issue #523: aus
     * get_metadata() ausgelagert, um die Funktion unter der
     * 50-Zeilen-Grenze zu halten).
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

        $collection->add_database_table('local_coursepilot_oauth_token', [
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
            'userid' => 'privacy:metadata:cm_version:userid',
            'timecreated' => 'privacy:metadata:cm_version:timecreated',
        ], 'privacy:metadata:cm_version');
        // Beide Tabellen tragen keine userid, nur die Dateibeschreibung eines
        // Standes (siehe Klassenkommentar oben) - trotzdem mit Feldern statt
        // [] deklariert, sonst warnt Moodle-Core bei jedem get_metadata()-
        // Aufruf ("Table '...' was supplied without any fields").
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
     * Registriert das Markierungsgedaechtnis und das Werkbank-Downloadticket
     * (Issue #523: aus get_metadata() ausgelagert).
     *
     * @param collection $collection
     * @return collection
     */
    private static function describe_context_and_werkbank_tables(collection $collection): collection {
        // Markierungsgedaechtnis (#493, Spec #486 §6): traegt userid und den
        // Client-Pfad einer Kontextdatei, siehe local_coursepilot\mark_memory.
        $collection->add_database_table('local_coursepilot_context_mark', [
            'userid' => 'privacy:metadata:context_mark:userid',
            'path' => 'privacy:metadata:context_mark:path',
            'ismarked' => 'privacy:metadata:context_mark:ismarked',
        ], 'privacy:metadata:context_mark');

        // Externer Ablageort (#500, ADR 0021, Spec #486 §11): Kontextbereich
        // und Materialbestand koennen am WebDAV-Speicher der Lehrkraft
        // liegen, ausserhalb jedes Moodle-Loesch-/Exportmechanismus. Pointer
        // (Kontextpointer-Datei), Ausstandsnotiz und Werkbank bleiben davon
        // unberuehrt - sie liegen weiter in `user/private` und sind ueber
        // Moodle-Cores eigenen Provider (user/classes/privacy/provider.php)
        // gedeckt, wie im Klassenkommentar oben begruendet. Hier wird nur der
        // externe Ort selbst benannt, den eine Auskunft sonst verschweigen
        // wuerde.
        // Werkbank-Downloadticket (#501, Spec #486 §13): system-kontextgebunden
        // wie die OAuth-Tabellen (kein Kurs-/Modulbezug), nur der Hash des
        // Ticketgeheimnisses wird gefuehrt, siehe local_coursepilot\werkbank_ticket.
        $collection->add_database_table('local_coursepilot_werkbank_ticket', [
            'userid' => 'privacy:metadata:werkbank_ticket:userid',
            'path' => 'privacy:metadata:werkbank_ticket:path',
            'contenthash' => 'privacy:metadata:werkbank_ticket:contenthash',
            'oauthtokenid' => 'privacy:metadata:werkbank_ticket:oauthtokenid',
            'expires' => 'privacy:metadata:werkbank_ticket:expires',
            'timecreated' => 'privacy:metadata:werkbank_ticket:timecreated',
        ], 'privacy:metadata:werkbank_ticket');

        return $collection;
    }

    /**
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $hasoauthdata = $DB->record_exists('local_coursepilot_oauth_code', ['userid' => $userid])
            || $DB->record_exists('local_coursepilot_oauth_token', ['userid' => $userid])
            || $DB->record_exists('local_coursepilot_werkbank_ticket', ['userid' => $userid]);
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
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_coursepilot_oauth_code}', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_coursepilot_oauth_token}', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_coursepilot_werkbank_ticket}', []);
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
        }
    }

    /**
     * Exportiert Kontextdateien und Markierungsgedaechtnis fuer den eigenen
     * Nutzerkontext (Issue #523: aus export_user_data() ausgelagert, um die
     * Funktion unter der 50-Zeilen-Grenze zu halten).
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
     * Exportiert OAuth-Codes/-Tokens und Werkbank-Downloadtickets fuer den
     * Systemkontext (Issue #523: aus export_user_data() ausgelagert).
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

        $tickets = $DB->get_records('local_coursepilot_werkbank_ticket', ['userid' => $userid]);
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
                'werkbank_tickets' => $exportedtickets,
            ]
        );
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
        $DB->delete_records('local_coursepilot_oauth_code');
        $DB->delete_records('local_coursepilot_oauth_token');
        $DB->delete_records('local_coursepilot_werkbank_ticket');
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
            $DB->delete_records('local_coursepilot_context_mark', ['userid' => $context->instanceid]);
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

        // Beide Seiten sind hier Zeichenketten, nicht Zahlen: Die Kontext-IDs
        // stammen aus einer Datenbankabfrage (contextlist::add_from_sql()),
        // und SYSCONTEXTID wird beim Aufbau der Moodle-Umgebung aus einem
        // Datenbankfeld gesetzt - gemessen als string '1'. Ein strenger
        // Vergleich ohne Umwandlung traf deshalb nie zu, und eine
        // Loeschanfrage ueber den Nutzerkontext liess Verbindungen, Codes und
        // Werkbank-Tickets stehen.
        $contextids = array_map('intval', $contextlist->get_contextids());
        if (!in_array((int) SYSCONTEXTID, $contextids, true)) {
            return;
        }
        $DB->delete_records('local_coursepilot_oauth_code', ['userid' => $userid]);
        $DB->delete_records('local_coursepilot_oauth_token', ['userid' => $userid]);
        $DB->delete_records('local_coursepilot_werkbank_ticket', ['userid' => $userid]);
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
        $DB->delete_records_select('local_coursepilot_oauth_code', "userid $insql", $inparams);
        $DB->delete_records_select('local_coursepilot_oauth_token', "userid $insql", $inparams);
        $DB->delete_records_select('local_coursepilot_werkbank_ticket', "userid $insql", $inparams);
    }
}
