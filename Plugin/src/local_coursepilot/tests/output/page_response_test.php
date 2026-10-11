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

namespace local_coursepilot\output;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Delivers controller decisions through the real Moodle page renderer.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(page_response::class)]
final class page_response_test extends \advanced_testcase {
    public function test_template_response_renders_the_selected_heading_and_page(): void {
        $this->resetAfterTest();
        $this->prepare_renderer();
        $response = new page_response(
            'local_coursepilot/connections',
            ['empty' => true, 'rows' => []],
            heading: 'Classroom connections'
        );
        ob_start();
        try {
            $response->send();
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('Classroom connections', $html);
        $this->assertStringContainsString(get_string('connectionnoconnections', 'local_coursepilot'), $html);
    }

    public function test_confirmation_response_renders_both_action_and_cancel_destinations(): void {
        $this->resetAfterTest();
        $this->prepare_renderer();
        $response = new page_response(
            message: 'Restore this edition?',
            confirmurl: new \moodle_url('/local/coursepilot/history.php', ['confirmed' => 1]),
            cancelurl: new \moodle_url('/local/coursepilot/history.php', ['cmid' => 5])
        );
        ob_start();
        try {
            $response->send();
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('Restore this edition?', $html);
        $this->assertStringContainsString('name="confirmed"', $html);
        $this->assertStringContainsString('name="cmid"', $html);
    }

    public function test_redirect_response_invokes_moodles_redirect_with_the_selected_destination(): void {
        $response = new page_response(redirecturl: new \moodle_url('/local/coursepilot/connections.php'));
        $this->assertStringEndsWith('/local/coursepilot/connections.php', $response->redirecturl->get_path());
        // Moodle refuses HTTP redirects in CLI; the domain destination stays inspectable before sending.
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Unsupported redirect detected');
        $response->send();
    }

    /**
     * Sets up Moodle's actual HTML renderer, without replacing managed modules.
     */
    private function prepare_renderer(): void {
        global $PAGE, $OUTPUT;
        $this->setAdminUser();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url('/local/coursepilot/connections.php');
        $PAGE->set_pagelayout('standard');
        $PAGE->set_title('Connections');
        $PAGE->set_heading('Connections');
        $OUTPUT = new \theme_boost\output\core_renderer($PAGE, 'html');
    }
}
