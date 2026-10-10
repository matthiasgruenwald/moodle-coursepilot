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
 * Administration settings (#338): global remote-access emergency switch
 * and navigation to the connection overview.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_coursepilot', get_string('pluginname', 'local_coursepilot'));
    $ADMIN->add('localplugins', $settings);

    // Plugin introduction (#500, Spec #486 §11): one sentence about external
    // storage above the individual settings. Full admin setup instructions
    // live in docs/admin-erstanleitung.md (#481); this page cannot link to
    // a repository file, so intentionally keeps only the short introduction.
    $settings->add(new admin_setting_heading(
        'local_coursepilot/introheading',
        get_string('settingintroheading', 'local_coursepilot'),
        get_string('settingintroheading_desc', 'local_coursepilot')
    ));

    // Emergency switch (#338): immediately denies further MCP access without
    // affecting normal login; see dispatcher::handle_authorized(). Enabled
    // by default so a new installation is usable.
    $settings->add(new admin_setting_configcheckbox(
        'local_coursepilot/remoteaccessenabled',
        get_string('settingremoteaccessenabled', 'local_coursepilot'),
        get_string('settingremoteaccessenabled_desc', 'local_coursepilot'),
        1
    ));

    // Remote access via freely selected existing system cohorts (#579, ADR 0026).
    // Defaults empty. Category cohorts are excluded because category managers
    // can maintain their membership themselves.
    $settings->add(new \local_coursepilot\admin\remoteaccesscohorts_setting());

    // Logging level (#339): controls Moodle event API detail in native reports.
    // Defaults to reads and errors; see local_coursepilot\access_log.
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

    // Context-area root (#297/#343): organizational only, not a security boundary.
    // component/filearea/itemid/contextid isolate areas and users; see
    // local_coursepilot\context_files.
    $settings->add(new admin_setting_configtext(
        'local_coursepilot/contextroot',
        get_string('settingcontextroot', 'local_coursepilot'),
        get_string('settingcontextroot_desc', 'local_coursepilot'),
        'coursepilot',
        PARAM_PATH
    ));

    // Material-store root (Spec 0018 §2.1, #428): sibling of contextroot,
    // also organizational. Isolation uses component/filearea/itemid/contextid;
    // see local_coursepilot\material_files.
    $settings->add(new admin_setting_configtext(
        'local_coursepilot/materialroot',
        get_string('settingmaterialroot', 'local_coursepilot'),
        get_string('settingmaterialroot_desc', 'local_coursepilot'),
        'coursepilot-material',
        PARAM_PATH
    ));

    // Personal-data switch (#344, ADR 0011): disables access to files marked
    // coursepilot.personenbezug: true; see local_coursepilot\personal_data.
    // Defaults off for data-minimizing installation. Only this admin page
    // ($hassiteconfig, moodle/site:config) can change it, without extra checks.
    $settings->add(new admin_setting_configcheckbox(
        'local_coursepilot/allowpersonaldata',
        get_string('settingallowpersonaldata', 'local_coursepilot'),
        get_string('settingallowpersonaldata_desc', 'local_coursepilot'),
        0
    ));

    // History retention (#387, Spec 0015 §10.7): defaults to one year, minimum
    // one day. Unlimited retention is excluded to bound storage. The numeric
    // field has no unlimited checkbox; history\retention::days() also enforces
    // the minimum defensively on read.
    $settings->add(new admin_setting_configtext(
        'local_coursepilot/historyretentiondays',
        get_string('settinghistoryretentiondays', 'local_coursepilot'),
        get_string('settinghistoryretentiondays_desc', 'local_coursepilot'),
        \local_coursepilot\history\retention::DEFAULT_DAYS,
        PARAM_INT
    ));

    // Anonymous OAuth registration (#642) and CIMD fetch (#643) budgets: finite site-wide and
    // per-source limits per window; no unlimited option, non-positive values
    // fall back defensively, see local_coursepilot\oauth_budget::setting().
    foreach (
        [
        'oauthregistersitelimit' => \local_coursepilot\oauth_lib::REGISTRATION_SITE_LIMIT,
        'oauthregistersourcelimit' => \local_coursepilot\oauth_lib::REGISTRATION_SOURCE_LIMIT,
        'oauthregisterwindow' => \local_coursepilot\oauth_lib::REGISTRATION_WINDOW,
        // First-time CIMD client metadata fetches (#643).
        'oauthcimdsitelimit' => \local_coursepilot\oauth_lib::CIMD_SITE_LIMIT,
        'oauthcimdsourcelimit' => \local_coursepilot\oauth_lib::CIMD_SOURCE_LIMIT,
        'oauthcimdwindow' => \local_coursepilot\oauth_lib::CIMD_WINDOW,
        ] as $name => $default
    ) {
        $settings->add(new admin_setting_configtext(
            'local_coursepilot/' . $name,
            get_string('setting' . $name, 'local_coursepilot'),
            get_string('setting' . $name . '_desc', 'local_coursepilot'),
            $default,
            PARAM_INT
        ));
    }

    // External WebDAV storage heading (#499, Spec #486 §12) groups privacy
    // guidance (§11) before its two settings: names/images in stored files,
    // plaintext credentials with app-password recommendation, the core
    // userid=0 gap, and a write lock without a read lock. Links to system
    // status containing the four checks.
    $settings->add(new admin_setting_heading(
        'local_coursepilot/webdavheading',
        get_string('settingwebdavheading', 'local_coursepilot'),
        get_string('settingwebdavheading_desc', 'local_coursepilot', (new moodle_url('/report/status/index.php'))->out())
    ));

    // Allowed personal-data hosts (#493, ADR 0021 §3): files marked
    // coursepilot.personenbezug: true can only be written to listed hosts.
    // Private Files are always allowed. Each domain includes subdomains,
    // split on dots without wildcards; single-part entries are rejected by
    // admin\personaldatahosts_setting. Defaults empty: Private Files only.
    $settings->add(new \local_coursepilot\admin\personaldatahosts_setting(
        'local_coursepilot/personaldatahosts',
        get_string('settingpersonaldatahosts', 'local_coursepilot'),
        get_string(
            'settingpersonaldatahosts_desc',
            'local_coursepilot',
            get_string('externallocationprivacyinfo', 'local_coursepilot')
        ),
        '',
        PARAM_RAW
    ));

    // Optional school guidance on location selection (#494), alongside the
    // three setup steps when no instance exists; see
    // local_coursepilot\location_selection::school_hint().
    $settings->add(new admin_setting_configtextarea(
        'local_coursepilot/webdavhint',
        get_string('settingwebdavhint', 'local_coursepilot'),
        get_string('settingwebdavhint_desc', 'local_coursepilot'),
        '',
        PARAM_TEXT
    ));

    // Admin connection overview (#338): an external page in the admin tree
    // guarded by moodle/site:config. admin/connections.php additionally checks
    // that capability for direct requests.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_coursepilot_connections',
        get_string('connections', 'local_coursepilot'),
        new moodle_url('/local/coursepilot/admin/connections.php'),
        'moodle/site:config'
    ));
}
