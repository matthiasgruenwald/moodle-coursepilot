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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Every site setting is registered with its own name, text, default, parameter type and choices (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(settings::class)]
final class settings_674_final_contract_test extends \advanced_testcase {
    /**
     * Builds the administration tree with the Coursepilot page registered.
     *
     * @return array{0: \admin_root, 1: \admin_settingpage} The tree and the registered settings page.
     */
    private function register_page(): array {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $root = new \admin_root(true);
        $root->add('root', new \admin_category('localplugins', 'Local plugins'));
        $page = settings::register($root, true);
        return [$root, $page];
    }

    /**
     * Returns the setting stored under the Moodle key for the given plugin setting name.
     *
     * @param \admin_settingpage $page The registered settings page.
     * @param string $name The setting name without the plugin prefix.
     * @return \admin_setting The setting object.
     */
    private function setting(\admin_settingpage $page, string $name): \admin_setting {
        $this->assertTrue(isset($page->settings->{'local_coursepilot' . $name}), 'Missing setting ' . $name);
        return $page->settings->{'local_coursepilot' . $name};
    }

    /**
     * The page title, the page location and the intro heading are the translated Coursepilot strings.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_page_title_and_intro_heading_use_their_strings(): void {
        $this->resetAfterTest();
        [$root, $page] = $this->register_page();

        $this->assertSame(get_string('pluginname', 'local_coursepilot'), $page->visiblename);
        $this->assertSame('local_coursepilot', $page->name);
        $this->assertSame($page, $root->locate('local_coursepilot'));

        $heading = $this->setting($page, 'introheading');
        $this->assertInstanceOf(\admin_setting_heading::class, $heading);
        $this->assertSame('introheading', $heading->name);
        $this->assertSame('local_coursepilot', $heading->plugin);
        $this->assertSame(get_string('settingintroheading', 'local_coursepilot'), $heading->visiblename);
        $this->assertSame(get_string('settingintroheading_desc', 'local_coursepilot'), $heading->description);
    }

    /**
     * The remote access switch is a checkbox that defaults to on.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_remote_access_switch_is_a_checkbox_defaulting_to_on(): void {
        $this->resetAfterTest();
        [, $page] = $this->register_page();

        $setting = $this->setting($page, 'remoteaccessenabled');
        $this->assertInstanceOf(\admin_setting_configcheckbox::class, $setting);
        $this->assertSame('remoteaccessenabled', $setting->name);
        $this->assertSame(get_string('settingremoteaccessenabled', 'local_coursepilot'), $setting->visiblename);
        $this->assertSame(get_string('settingremoteaccessenabled_desc', 'local_coursepilot'), $setting->description);
        $this->assertSame('1', $setting->get_defaultsetting());
    }

    /**
     * The remote access cohort setting is registered as its own setting class.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_remote_access_cohorts_setting_is_registered(): void {
        $this->resetAfterTest();
        [, $page] = $this->register_page();

        $this->assertInstanceOf(remoteaccesscohorts_setting::class, $this->setting($page, 'remoteaccesscohorts'));
    }

    /**
     * The log level is a select with the four levels in order, defaulting to reads.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_log_level_offers_the_four_levels_and_defaults_to_reads(): void {
        $this->resetAfterTest();
        [, $page] = $this->register_page();

        $setting = $this->setting($page, 'loglevel');
        $this->assertInstanceOf(\admin_setting_configselect::class, $setting);
        $this->assertSame(get_string('settingloglevel', 'local_coursepilot'), $setting->visiblename);
        $this->assertSame(get_string('settingloglevel_desc', 'local_coursepilot'), $setting->description);
        $this->assertSame(\local_coursepilot\access_log::LEVEL_READS, $setting->get_defaultsetting());
        $this->assertSame([
            \local_coursepilot\access_log::LEVEL_NONE => get_string('loglevelnone', 'local_coursepilot'),
            \local_coursepilot\access_log::LEVEL_ERRORS => get_string('loglevelerrors', 'local_coursepilot'),
            \local_coursepilot\access_log::LEVEL_READS => get_string('loglevelreads', 'local_coursepilot'),
            \local_coursepilot\access_log::LEVEL_ALL => get_string('loglevelall', 'local_coursepilot'),
        ], $setting->choices);
    }

    /**
     * The context and material roots are text settings with path parameters and fixed defaults.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_storage_roots_are_path_text_settings_with_defaults(): void {
        $this->resetAfterTest();
        [, $page] = $this->register_page();

        $context = $this->setting($page, 'contextroot');
        $this->assertInstanceOf(\admin_setting_configtext::class, $context);
        $this->assertSame(get_string('settingcontextroot', 'local_coursepilot'), $context->visiblename);
        $this->assertSame(get_string('settingcontextroot_desc', 'local_coursepilot'), $context->description);
        $this->assertSame(PARAM_PATH, $context->paramtype);
        $this->assertSame('coursepilot', $context->get_defaultsetting());

        $material = $this->setting($page, 'materialroot');
        $this->assertInstanceOf(\admin_setting_configtext::class, $material);
        $this->assertSame(get_string('settingmaterialroot', 'local_coursepilot'), $material->visiblename);
        $this->assertSame(get_string('settingmaterialroot_desc', 'local_coursepilot'), $material->description);
        $this->assertSame(PARAM_PATH, $material->paramtype);
        $this->assertSame('coursepilot-material', $material->get_defaultsetting());
    }

    /**
     * Personal data is off by default and the approved hosts are stored as raw text.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_personal_data_switch_and_approved_hosts(): void {
        $this->resetAfterTest();
        [, $page] = $this->register_page();

        $switch = $this->setting($page, 'allowpersonaldata');
        $this->assertInstanceOf(\admin_setting_configcheckbox::class, $switch);
        $this->assertSame(get_string('settingallowpersonaldata', 'local_coursepilot'), $switch->visiblename);
        $this->assertSame(get_string('settingallowpersonaldata_desc', 'local_coursepilot'), $switch->description);
        $this->assertSame('0', $switch->get_defaultsetting());

        $hosts = $this->setting($page, 'personaldatahosts');
        $this->assertInstanceOf(personaldatahosts_setting::class, $hosts);
        $this->assertSame(get_string('settingpersonaldatahosts', 'local_coursepilot'), $hosts->visiblename);
        $this->assertSame(PARAM_RAW, $hosts->paramtype);
        $this->assertSame('', $hosts->get_defaultsetting());
        $this->assertStringContainsString(
            get_string('externallocationprivacyinfo', 'local_coursepilot'),
            $hosts->description
        );
        $this->assertStringNotContainsString('{$a}', $hosts->description);
    }

    /**
     * The retention setting is an integer text field whose default is the retention class default.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_history_retention_default_is_the_integer_day_count(): void {
        $this->resetAfterTest();
        [, $page] = $this->register_page();

        $setting = $this->setting($page, 'historyretentiondays');
        $this->assertInstanceOf(\admin_setting_configtext::class, $setting);
        $this->assertSame(get_string('settinghistoryretentiondays', 'local_coursepilot'), $setting->visiblename);
        $this->assertSame(get_string('settinghistoryretentiondays_desc', 'local_coursepilot'), $setting->description);
        $this->assertSame(PARAM_INT, $setting->paramtype);
        $this->assertSame('365', $setting->get_defaultsetting());
    }

    /**
     * Each OAuth limit is an integer text field whose default is the constant of the OAuth library.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_oauth_limits_are_integer_fields_with_their_library_defaults(): void {
        $this->resetAfterTest();
        [, $page] = $this->register_page();

        $expected = [
            'oauthregistersitelimit' => (string) \local_coursepilot\oauth_lib::REGISTRATION_SITE_LIMIT,
            'oauthregistersourcelimit' => (string) \local_coursepilot\oauth_lib::REGISTRATION_SOURCE_LIMIT,
            'oauthregisterwindow' => (string) \local_coursepilot\oauth_lib::REGISTRATION_WINDOW,
            'oauthcimdsitelimit' => (string) \local_coursepilot\oauth_lib::CIMD_SITE_LIMIT,
            'oauthcimdsourcelimit' => (string) \local_coursepilot\oauth_lib::CIMD_SOURCE_LIMIT,
            'oauthcimdwindow' => (string) \local_coursepilot\oauth_lib::CIMD_WINDOW,
        ];
        foreach ($expected as $name => $default) {
            $setting = $this->setting($page, $name);
            $this->assertInstanceOf(\admin_setting_configtext::class, $setting, $name);
            $this->assertSame(get_string('setting' . $name, 'local_coursepilot'), $setting->visiblename, $name);
            $this->assertSame(get_string('setting' . $name . '_desc', 'local_coursepilot'), $setting->description, $name);
            $this->assertSame(PARAM_INT, $setting->paramtype, $name);
            $this->assertSame($default, $setting->get_defaultsetting(), $name);
        }
    }

    /**
     * The WebDAV heading links to the system status page and the hint is an empty text area.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_webdav_heading_links_to_the_status_page_and_hint_is_empty(): void {
        $this->resetAfterTest();
        [, $page] = $this->register_page();

        $heading = $this->setting($page, 'webdavheading');
        $this->assertInstanceOf(\admin_setting_heading::class, $heading);
        $this->assertSame(get_string('settingwebdavheading', 'local_coursepilot'), $heading->visiblename);
        $this->assertStringContainsString(
            (new \moodle_url('/report/status/index.php'))->out(),
            $heading->description
        );

        $hint = $this->setting($page, 'webdavhint');
        $this->assertInstanceOf(\admin_setting_configtextarea::class, $hint);
        $this->assertSame(get_string('settingwebdavhint', 'local_coursepilot'), $hint->visiblename);
        $this->assertSame(get_string('settingwebdavhint_desc', 'local_coursepilot'), $hint->description);
        $this->assertSame(PARAM_TEXT, $hint->paramtype);
        $this->assertSame('', $hint->get_defaultsetting());
    }

    /**
     * The connections page is an external page in the local plugins category, gated by the site config capability.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_connections_page_is_an_external_page_gated_by_site_config(): void {
        $this->resetAfterTest();
        [$root] = $this->register_page();

        $page = $root->locate('local_coursepilot_connections');
        $this->assertInstanceOf(\admin_externalpage::class, $page);
        $this->assertSame('local_coursepilot_connections', $page->name);
        $this->assertSame(get_string('connections', 'local_coursepilot'), $page->visiblename);
        $this->assertSame(['moodle/site:config'], $page->req_capability);
        $this->assertStringEndsWith('/local/coursepilot/admin/connections.php', $page->get_settings_page_url()->out(false));
    }

    /**
     * Without site configuration access nothing is added to the admin tree.
     *
     * @covers \local_coursepilot\admin\settings::register
     */
    public function test_unprivileged_users_get_no_page_and_no_connections_entry(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $root = new \admin_root(true);
        $root->add('root', new \admin_category('localplugins', 'Local plugins'));

        $this->assertNull(settings::register($root, false));
        $this->assertNull($root->locate('local_coursepilot_connections'));
    }
}
