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

namespace local_coursepilot\admin;

/**
 * Registers the site-wide settings and protected connection overview.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class settings {
    /**
     * Adds configuration only when Moodle grants site configuration access.
     *
     * @param \admin_root $ADMIN The real Moodle administration tree.
     * @param bool $hassiteconfig Whether the current user may configure the site.
     * @return \admin_settingpage|null Registered settings, or null for an unprivileged user.
     */
    public static function register(\admin_root $ADMIN, bool $hassiteconfig): ?\admin_settingpage {
        if (!$hassiteconfig) {
            return null;
        }
        $settings = new \admin_settingpage('local_coursepilot', get_string('pluginname', 'local_coursepilot'));
        $ADMIN->add('localplugins', $settings);

        $settings->add(new \admin_setting_heading(
            'local_coursepilot/introheading',
            get_string('settingintroheading', 'local_coursepilot'),
            get_string('settingintroheading_desc', 'local_coursepilot')
        ));

        $settings->add(new \admin_setting_configcheckbox(
            'local_coursepilot/remoteaccessenabled',
            get_string('settingremoteaccessenabled', 'local_coursepilot'),
            get_string('settingremoteaccessenabled_desc', 'local_coursepilot'),
            '1'
        ));

        $settings->add(new \local_coursepilot\admin\remoteaccesscohorts_setting());

        $settings->add(new \admin_setting_configselect(
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

        $settings->add(new \admin_setting_configtext(
            'local_coursepilot/contextroot',
            get_string('settingcontextroot', 'local_coursepilot'),
            get_string('settingcontextroot_desc', 'local_coursepilot'),
            'coursepilot',
            PARAM_PATH
        ));

        $settings->add(new \admin_setting_configtext(
            'local_coursepilot/materialroot',
            get_string('settingmaterialroot', 'local_coursepilot'),
            get_string('settingmaterialroot_desc', 'local_coursepilot'),
            'coursepilot-material',
            PARAM_PATH
        ));

        $settings->add(new \admin_setting_configcheckbox(
            'local_coursepilot/allowpersonaldata',
            get_string('settingallowpersonaldata', 'local_coursepilot'),
            get_string('settingallowpersonaldata_desc', 'local_coursepilot'),
            '0'
        ));

        $settings->add(new \admin_setting_configtext(
            'local_coursepilot/historyretentiondays',
            get_string('settinghistoryretentiondays', 'local_coursepilot'),
            get_string('settinghistoryretentiondays_desc', 'local_coursepilot'),
            (string) \local_coursepilot\history\retention::DEFAULT_DAYS,
            PARAM_INT
        ));

        foreach (
            [
            'oauthregistersitelimit' => \local_coursepilot\oauth_lib::REGISTRATION_SITE_LIMIT,
            'oauthregistersourcelimit' => \local_coursepilot\oauth_lib::REGISTRATION_SOURCE_LIMIT,
            'oauthregisterwindow' => \local_coursepilot\oauth_lib::REGISTRATION_WINDOW,
                'oauthcimdsitelimit' => \local_coursepilot\oauth_lib::CIMD_SITE_LIMIT,
            'oauthcimdsourcelimit' => \local_coursepilot\oauth_lib::CIMD_SOURCE_LIMIT,
            'oauthcimdwindow' => \local_coursepilot\oauth_lib::CIMD_WINDOW,
            ] as $name => $default
        ) {
            $settings->add(new \admin_setting_configtext(
                'local_coursepilot/' . $name,
                get_string('setting' . $name, 'local_coursepilot'),
                get_string('setting' . $name . '_desc', 'local_coursepilot'),
                (string) $default,
                PARAM_INT
            ));
        }

        $settings->add(new \admin_setting_heading(
            'local_coursepilot/webdavheading',
            get_string('settingwebdavheading', 'local_coursepilot'),
            get_string('settingwebdavheading_desc', 'local_coursepilot', (new \moodle_url('/report/status/index.php'))->out())
        ));

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

        $settings->add(new \admin_setting_configtextarea(
            'local_coursepilot/webdavhint',
            get_string('settingwebdavhint', 'local_coursepilot'),
            get_string('settingwebdavhint_desc', 'local_coursepilot'),
            '',
            PARAM_TEXT
        ));

        $ADMIN->add('localplugins', new \admin_externalpage(
            'local_coursepilot_connections',
            get_string('connections', 'local_coursepilot'),
            new \moodle_url('/local/coursepilot/admin/connections.php'),
            'moodle/site:config'
        ));
        return $settings;
    }
}
