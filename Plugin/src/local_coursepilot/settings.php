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

/**
 * Administrationseinstellungen (#338): globale Notbremse fuer den
 * Fernzugriff sowie der Navigationseintrag zur Verbindungsuebersicht.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_coursepilot', get_string('pluginname', 'local_coursepilot'));
    $ADMIN->add('localplugins', $settings);

    // Plugin-Beschreibung (Issue #500, Spec #486 §11): ein Satz zum externen
    // Ablageort, oben auf der Einstellungsseite, bevor die einzelnen
    // Einstellungen folgen. Die ausfuehrliche Admin-Erstanleitung ist noch
    // nicht geschrieben (Issue #481) - deshalb hier nur der eine Satz statt
    // eines Links auf eine noch nicht existierende Seite.
    $settings->add(new admin_setting_heading(
        'local_coursepilot/introheading',
        get_string('settingintroheading', 'local_coursepilot'),
        get_string('settingintroheading_desc', 'local_coursepilot')
    ));

    // Notbremse (#338): sperrt jeden weiteren MCP-Zugriff sofort, ohne den
    // normalen Moodle-Login zu beruehren - siehe dispatcher::handle_authorized().
    // Default aktiviert (1), damit ein frisch installiertes Plugin nutzbar bleibt.
    $settings->add(new admin_setting_configcheckbox(
        'local_coursepilot/remoteaccessenabled',
        get_string('settingremoteaccessenabled', 'local_coursepilot'),
        get_string('settingremoteaccessenabled_desc', 'local_coursepilot'),
        1
    ));

    // Protokollstufe (#339): steuert, wie viel ueber die Moodle-Ereignis-API
    // in den nativen Protokollberichten landet. Voreinstellung
    // "Lesezugriffe und Fehler" - siehe local_coursepilot\access_log.
    $settings->add(new admin_setting_configselect(
        'local_coursepilot/loglevel',
        get_string('settingloglevel', 'local_coursepilot'),
        get_string('settingloglevel_desc', 'local_coursepilot'),
        \local_coursepilot\access_log::LEVEL_READS,
        [
            \local_coursepilot\access_log::LEVEL_NONE => get_string('loglevelnone', 'local_coursepilot'),
            \local_coursepilot\access_log::LEVEL_ERRORS => get_string('loglevelerrors', 'local_coursepilot'),
            \local_coursepilot\access_log::LEVEL_READS => get_string('loglevelreads', 'local_coursepilot'),
            \local_coursepilot\access_log::LEVEL_ALL => get_string('loglevelall', 'local_coursepilot'),
        ]
    ));

    // Wurzelordner des Kontextbereichs (#297/#343): rein organisatorisch,
    // keine Sicherheitsgrenze - die Isolation zwischen Bereichen/Personen
    // kommt aus component/filearea/itemid/contextid, siehe
    // local_coursepilot\context_files.
    $settings->add(new admin_setting_configtext(
        'local_coursepilot/contextroot',
        get_string('settingcontextroot', 'local_coursepilot'),
        get_string('settingcontextroot_desc', 'local_coursepilot'),
        'coursepilot',
        PARAM_PATH
    ));

    // Wurzelordner des Materialordners (Spec 0018 §2.1, #428): Geschwister
    // zu contextroot, ebenso rein organisatorisch - die Isolation kommt aus
    // component/filearea/itemid/contextid, siehe local_coursepilot\material_files.
    $settings->add(new admin_setting_configtext(
        'local_coursepilot/materialroot',
        get_string('settingmaterialroot', 'local_coursepilot'),
        get_string('settingmaterialroot_desc', 'local_coursepilot'),
        'coursepilot-material',
        PARAM_PATH
    ));

    // Schalter fuer personenbezogene Kontextdaten (#344, ADR 0011): definitiv
    // abschaltbare Grenze fuer Dateien mit Frontmatter-Markierung
    // "coursepilot.personenbezug: true" - siehe local_coursepilot\personal_data.
    // Default aus (0), damit der datensparsame Zustand der Auslieferungs-
    // zustand ist. Nur ueber diese Admin-Seite aenderbar
    // ($hassiteconfig/'moodle/site:config') - kein Zusatzcode fuer
    // "ohne Administrationsrechte nicht aenderbar" noetig.
    $settings->add(new admin_setting_configcheckbox(
        'local_coursepilot/allowpersonaldata',
        get_string('settingallowpersonaldata', 'local_coursepilot'),
        get_string('settingallowpersonaldata_desc', 'local_coursepilot'),
        0
    ));

    // Loeschfrist des Aenderungsverlaufs (#387, Spec 0015 §10.7): Standard
    // 1 Jahr, verkuerzbar bis auf 1 Tag - "keine Frist" ist ausgeschlossen
    // (Speicherplatz). Reines Zahlenfeld ohne Unlimited-Kaestchen; die
    // Untergrenze wird zusaetzlich defensiv beim Lesen erzwungen, siehe
    // local_coursepilot\history\retention::days().
    $settings->add(new admin_setting_configtext(
        'local_coursepilot/historyretentiondays',
        get_string('settinghistoryretentiondays', 'local_coursepilot'),
        get_string('settinghistoryretentiondays_desc', 'local_coursepilot'),
        \local_coursepilot\history\retention::DEFAULT_DAYS,
        PARAM_INT
    ));

    // Ueberschriftenblock "Externer Ablageort (WebDAV)" (Issue #499, Spec
    // #486 §12): buendelt das Schulwissen aus dem Datenschutzabschnitt (§11)
    // vor den beiden zugehoerigen Einstellungen - Namen/Bilder aus dem
    // Bestand, Klartext-Passwort samt App-Passwort-Empfehlung, die
    // Core-Luecke "userid = 0", und "Schreibsperre, keine Lesesperre" -
    // dazu ein Link auf den Systemstatus (dort stehen die vier
    // Statusprüfungen).
    $settings->add(new admin_setting_heading(
        'local_coursepilot/webdavheading',
        get_string('settingwebdavheading', 'local_coursepilot'),
        get_string('settingwebdavheading_desc', 'local_coursepilot', (new moodle_url('/report/status/index.php'))->out())
    ));

    // Zugelassene Speicher fuer personenbezogene Kontextdaten (#493, ADR 0021
    // §3): eine Datei mit "coursepilot.personenbezug: true" schreibt Coursepilot
    // nur in einen hier genannten Speicher - Private Files sind immer
    // zugelassen. Ein Eintrag gilt fuer eine Domain samt Unterdomains,
    // getrennt wird nur an Punkten, ohne "*"; Eintraege mit nur einem
    // Namensteil werden beim Speichern abgelehnt (siehe
    // local_coursepilot\admin\personaldatahosts_setting). Standard leer - nur
    // Private Files.
    $settings->add(new \local_coursepilot\admin\personaldatahosts_setting(
        'local_coursepilot/personaldatahosts',
        get_string('settingpersonaldatahosts', 'local_coursepilot'),
        get_string('settingpersonaldatahosts_desc', 'local_coursepilot', get_string('externallocationprivacyinfo', 'local_coursepilot')),
        '',
        PARAM_RAW
    ));

    // Hinweis der Schule auf der Ortswahlseite (#494): optionaler Freitext,
    // zusaetzlich zu den drei Einrichtungsschritten im Leerzustand "keine
    // Instanz" - siehe local_coursepilot\location_selection::school_hint().
    $settings->add(new admin_setting_configtextarea(
        'local_coursepilot/webdavhint',
        get_string('settingwebdavhint', 'local_coursepilot'),
        get_string('settingwebdavhint_desc', 'local_coursepilot'),
        '',
        PARAM_TEXT
    ));

    // Administrationsuebersicht (#338): eigene externe Seite, damit sie im
    // Administrationsbaum erscheint und dort bereits require-capability-
    // geschuetzt ist ('moodle/site:config') - admin/connections.php prueft
    // dieselbe Capability zusaetzlich selbst, falls die Seite direkt
    // aufgerufen wird.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_coursepilot_connections',
        get_string('connections', 'local_coursepilot'),
        new moodle_url('/local/coursepilot/admin/connections.php'),
        'moodle/site:config'
    ));
}
