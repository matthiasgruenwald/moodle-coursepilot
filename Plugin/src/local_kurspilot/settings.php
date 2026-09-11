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

/**
 * Administrationseinstellungen (#338): globale Notbremse fuer den
 * Fernzugriff sowie der Navigationseintrag zur Verbindungsuebersicht.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_kurspilot', get_string('pluginname', 'local_kurspilot'));
    $ADMIN->add('localplugins', $settings);

    // Notbremse (#338): sperrt jeden weiteren MCP-Zugriff sofort, ohne den
    // normalen Moodle-Login zu beruehren - siehe dispatcher::handle_authorized().
    // Default aktiviert (1), damit ein frisch installiertes Plugin nutzbar bleibt.
    $settings->add(new admin_setting_configcheckbox(
        'local_kurspilot/remoteaccessenabled',
        get_string('settingremoteaccessenabled', 'local_kurspilot'),
        get_string('settingremoteaccessenabled_desc', 'local_kurspilot'),
        1
    ));

    // Protokollstufe (#339): steuert, wie viel ueber die Moodle-Ereignis-API
    // in den nativen Protokollberichten landet. Voreinstellung
    // "Lesezugriffe und Fehler" - siehe local_kurspilot\access_log.
    $settings->add(new admin_setting_configselect(
        'local_kurspilot/loglevel',
        get_string('settingloglevel', 'local_kurspilot'),
        get_string('settingloglevel_desc', 'local_kurspilot'),
        \local_kurspilot\access_log::LEVEL_READS,
        [
            \local_kurspilot\access_log::LEVEL_NONE => get_string('loglevelnone', 'local_kurspilot'),
            \local_kurspilot\access_log::LEVEL_ERRORS => get_string('loglevelerrors', 'local_kurspilot'),
            \local_kurspilot\access_log::LEVEL_READS => get_string('loglevelreads', 'local_kurspilot'),
            \local_kurspilot\access_log::LEVEL_ALL => get_string('loglevelall', 'local_kurspilot'),
        ]
    ));

    // Wurzelordner des Kontextbereichs (#297/#343): rein organisatorisch,
    // keine Sicherheitsgrenze - die Isolation zwischen Bereichen/Personen
    // kommt aus component/filearea/itemid/contextid, siehe
    // local_kurspilot\context_files.
    $settings->add(new admin_setting_configtext(
        'local_kurspilot/contextroot',
        get_string('settingcontextroot', 'local_kurspilot'),
        get_string('settingcontextroot_desc', 'local_kurspilot'),
        'kurspilot',
        PARAM_PATH
    ));

    // Wurzelordner des Materialordners (Spec 0018 §2.1, #428): Geschwister
    // zu contextroot, ebenso rein organisatorisch - die Isolation kommt aus
    // component/filearea/itemid/contextid, siehe local_kurspilot\material_files.
    $settings->add(new admin_setting_configtext(
        'local_kurspilot/materialroot',
        get_string('settingmaterialroot', 'local_kurspilot'),
        get_string('settingmaterialroot_desc', 'local_kurspilot'),
        'kurspilot-material',
        PARAM_PATH
    ));

    // Schalter fuer personenbezogene Kontextdaten (#344, ADR 0011): definitiv
    // abschaltbare Grenze fuer Dateien mit Frontmatter-Markierung
    // "kurspilot.personenbezug: true" - siehe local_kurspilot\personal_data.
    // Default aus (0), damit der datensparsame Zustand der Auslieferungs-
    // zustand ist. Nur ueber diese Admin-Seite aenderbar
    // ($hassiteconfig/'moodle/site:config') - kein Zusatzcode fuer
    // "ohne Administrationsrechte nicht aenderbar" noetig.
    $settings->add(new admin_setting_configcheckbox(
        'local_kurspilot/allowpersonaldata',
        get_string('settingallowpersonaldata', 'local_kurspilot'),
        get_string('settingallowpersonaldata_desc', 'local_kurspilot'),
        0
    ));

    // Zugelassene Speicher fuer personenbezogene Kontextdaten (#493, ADR 0021
    // §3): eine Datei mit "kurspilot.personenbezug: true" schreibt Kurspilot
    // nur in einen hier genannten Speicher - Private Files sind immer
    // zugelassen. Ein Eintrag gilt fuer eine Domain samt Unterdomains,
    // getrennt wird nur an Punkten, ohne "*"; Eintraege mit nur einem
    // Namensteil werden beim Speichern abgelehnt (siehe
    // local_kurspilot\admin\personaldatahosts_setting). Standard leer - nur
    // Private Files.
    $settings->add(new \local_kurspilot\admin\personaldatahosts_setting(
        'local_kurspilot/personaldatahosts',
        get_string('settingpersonaldatahosts', 'local_kurspilot'),
        get_string('settingpersonaldatahosts_desc', 'local_kurspilot'),
        '',
        PARAM_RAW
    ));

    // Loeschfrist des Aenderungsverlaufs (#387, Spec 0015 §10.7): Standard
    // 1 Jahr, verkuerzbar bis auf 1 Tag - "keine Frist" ist ausgeschlossen
    // (Speicherplatz). Reines Zahlenfeld ohne Unlimited-Kaestchen; die
    // Untergrenze wird zusaetzlich defensiv beim Lesen erzwungen, siehe
    // local_kurspilot\history\retention::days().
    $settings->add(new admin_setting_configtext(
        'local_kurspilot/historyretentiondays',
        get_string('settinghistoryretentiondays', 'local_kurspilot'),
        get_string('settinghistoryretentiondays_desc', 'local_kurspilot'),
        \local_kurspilot\history\retention::DEFAULT_DAYS,
        PARAM_INT
    ));

    // Administrationsuebersicht (#338): eigene externe Seite, damit sie im
    // Administrationsbaum erscheint und dort bereits require-capability-
    // geschuetzt ist ('moodle/site:config') - admin/connections.php prueft
    // dieselbe Capability zusaetzlich selbst, falls die Seite direkt
    // aufgerufen wird.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_kurspilot_connections',
        get_string('connections', 'local_kurspilot'),
        new moodle_url('/local/kurspilot/admin/connections.php'),
        'moodle/site:config'
    ));
}
