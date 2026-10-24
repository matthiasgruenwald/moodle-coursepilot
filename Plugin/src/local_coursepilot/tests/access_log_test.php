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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Four logging levels (#339), defaulting to reads and errors, without
 * secrets in log text.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(access_log::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\event\tool_access_failed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\event\tool_access_succeeded::class)]
final class access_log_test extends \advanced_testcase {
    /**
     * Fresh installs without a configured value default to read access
     * and errors.
     */
    public function test_default_level_is_reads_and_errors(): void {
        $this->resetAfterTest();

        $this->assertSame(access_log::LEVEL_READS, access_log::current_level());
    }

    /**
     * A never-set and an empty config value both fall back to reads and errors;
     * a stored level is returned as an integer.
     */
    public function test_unset_or_empty_level_falls_back_to_reads_and_stored_level_is_int(): void {
        $this->resetAfterTest();

        $levels = [];
        unset_config('loglevel', 'local_coursepilot');
        $levels[] = access_log::current_level();
        set_config('loglevel', '', 'local_coursepilot');
        $levels[] = access_log::current_level();
        set_config('loglevel', '0', 'local_coursepilot');
        $levels[] = access_log::current_level();

        $this->assertSame([access_log::LEVEL_READS, access_log::LEVEL_READS, access_log::LEVEL_NONE], $levels);
    }

    /**
     * With logging disabled, neither success nor failure creates an event.
     */
    public function test_level_none_logs_nothing(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_NONE, 'local_coursepilot');
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');
        access_log::log_failure('AUTHENTICATION_FAILED');

        $this->assertCount(0, $sink->get_events());
        $sink->close();
    }

    /**
     * Errors-only mode records failures but not successful operations.
     */
    public function test_level_errors_only_skips_success_but_logs_failure(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_ERRORS, 'local_coursepilot');
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');
        access_log::log_failure('AUTHENTICATION_FAILED');

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(event\tool_access_failed::class, $events[0]);
        $sink->close();
    }

    /**
     * Read-access-and-errors mode records both operations.
     */
    public function test_level_reads_logs_success_and_failure(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_READS, 'local_coursepilot');
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');
        access_log::log_failure('AUTHENTICATION_FAILED');

        $events = $sink->get_events();
        $this->assertCount(2, $events);
        $sink->close();
    }

    /**
     * All-events mode also records both operations.
     */
    public function test_level_all_logs_success_and_failure(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_ALL, 'local_coursepilot');
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');
        access_log::log_failure('AUTHENTICATION_FAILED');

        $events = $sink->get_events();
        $this->assertCount(2, $events);
        $sink->close();
    }

    /**
     * Internal field paths appear only in explicitly enabled diagnostics.
     * Normal error logging omits them from routine Moodle reports (#457).
     */
    public function test_failure_detail_is_logged_only_at_level_all(): void {
        $this->resetAfterTest();
        $detail = 'Invalid response value in sections[0].modules[0].settings[2].value.';

        set_config('loglevel', access_log::LEVEL_ERRORS, 'local_coursepilot');
        $errorssink = $this->redirectEvents();
        access_log::log_failure('Invalid response value detected.', 'coursepilot_get_course_catalog', null, null, $detail);
        $errorsevent = $errorssink->get_events()[0];
        $this->assertArrayNotHasKey('detail', $errorsevent->other);
        $errorssink->close();

        set_config('loglevel', access_log::LEVEL_ALL, 'local_coursepilot');
        $allsink = $this->redirectEvents();
        access_log::log_failure('Invalid response value detected.', 'coursepilot_get_course_catalog', null, null, $detail);
        $allevent = $allsink->get_events()[0];
        $this->assertSame($detail, $allevent->other['detail']);
        $allsink->close();
    }

    /**
     * Writes-and-errors logging records writes but not reads. Reads begin
     * at logging level 2 (#388).
     */
    public function test_level_errors_logs_write_success_but_not_read_success(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_ERRORS, 'local_coursepilot');
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_update_module_settings', true);
        access_log::log_success('coursepilot_list_courses', false);

        $events = array_values($sink->get_events());
        $this->assertCount(1, $events);
        $this->assertSame('coursepilot_update_module_settings', $events[0]->other['toolname']);
        $sink->close();
    }

    /**
     * Set standard Moodle event metadata (crud, edulevel, context and component)
     * for filtering and reports.
     */
    public function test_success_event_carries_usual_moodle_characteristics(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');

        $event = $sink->get_events()[0];
        $this->assertSame('r', $event->crud);
        $this->assertSame('local_coursepilot', $event->component);
        $this->assertSame(\context_system::instance()->id, $event->contextid);
        $this->assertSame('coursepilot_list_courses', $event->other['toolname']);
        $sink->close();
    }

    /**
     * Material operations carry the file path supplied by the caller
     * (Spec 0018 §9.2, #428).
     */
    public function test_success_event_carries_path_when_given(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_upload_material_file', true, 'screenshot.png');

        $event = $sink->get_events()[0];
        $this->assertSame('screenshot.png', $event->other['path']);
        $sink->close();
    }

    /**
     * Tools without file paths still log successfully; path is optional.
     */
    public function test_success_event_path_is_null_when_not_given(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses');

        $event = $sink->get_events()[0];
        $this->assertNull($event->other['path']);
        $sink->close();
    }

    /**
     * Log fixed reason codes/text rather than access secrets or tokens.
     */
    public function test_failure_event_never_contains_a_secret_looking_token(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        $token = 'sk-' . str_repeat('a', 40);
        access_log::log_failure('AUTHENTICATION_FAILED');

        $event = $sink->get_events()[0];
        $encoded = json_encode($event->get_data());
        $this->assertStringNotContainsString($token, $encoded);
        $this->assertStringNotContainsString('sk-', $encoded);
        $sink->close();
    }

    /**
     * Events carry the given user, describe themselves for the log report and keep diagnostics at full logging.
     */
    public function test_events_describe_themselves_for_the_log_report(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_ALL, 'local_coursepilot');
        $user = $this->getDataGenerator()->create_user();
        $sink = $this->redirectEvents();

        access_log::log_success('coursepilot_list_courses', false, 'a.md', (int) $user->id);
        access_log::log_failure('Origin not allowed', 'coursepilot_list_courses', null, (int) $user->id, 'Details');
        access_log::log_failure('AUTHENTICATION_FAILED');

        [$success, $failure, $anonymous] = $sink->get_events();
        $sink->close();
        $this->assertSame((int) $user->id, (int) $success->userid);
        $this->assertStringContainsString("'coursepilot_list_courses'", $success->get_description());
        $this->assertSame(get_string('event_tool_access_succeeded', 'local_coursepilot'), $success::get_name());
        $this->assertSame((int) $user->id, (int) $failure->userid);
        $this->assertStringContainsString('Origin not allowed (tool: coursepilot_list_courses)', $failure->get_description());
        $this->assertSame('Details', $failure->other['detail']);
        $this->assertSame(get_string('event_tool_access_failed', 'local_coursepilot'), $failure::get_name());
        $this->assertSame('A Coursepilot access failed: AUTHENTICATION_FAILED.', $anonymous->get_description());
        $this->assertFalse($failure::get_other_mapping());
        $this->assertFalse($success::get_other_mapping());
    }

    /**
     * Events cannot be created without the value their description depends on.
     */
    public function test_events_require_their_identifying_value(): void {
        $this->resetAfterTest();

        foreach ([event\tool_access_failed::class, event\tool_access_succeeded::class] as $class) {
            try {
                $class::create(['other' => []]);
                $this->fail($class . ' accepted an empty other.');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('must be set in other', $e->getMessage());
            }
        }
    }

    public function test_an_empty_stored_level_falls_back_to_reads_and_errors(): void {
        $this->resetAfterTest();
        set_config('loglevel', '', 'local_coursepilot');

        $this->assertSame(access_log::LEVEL_READS, access_log::current_level());
    }
}
