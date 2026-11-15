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

/**
 * Delivers a Moodle page, confirmation or redirect chosen by a page controller.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class page_response {
    /**
     * Describes the response without rendering or terminating the request.
     *
     * @param string $template Moodle template name.
     * @param mixed[] $data Template data.
     * @param \moodle_url|null $redirecturl Redirect destination.
     * @param string $message Redirect notification or confirmation question.
     * @param \moodle_url|null $confirmurl Confirmation action.
     * @param \moodle_url|null $cancelurl Cancellation destination.
     * @param string $heading Optional page heading.
     */
    public function __construct(
        /** @var string Template name. */
        public readonly string $template = '',
        /** @var mixed[] Template data. */
        public readonly array $data = [],
        /** @var \moodle_url|null Redirect destination. */
        public readonly ?\moodle_url $redirecturl = null,
        /** @var string Notification or confirmation text. */
        public readonly string $message = '',
        /** @var \moodle_url|null Confirmed action. */
        public readonly ?\moodle_url $confirmurl = null,
        /** @var \moodle_url|null Cancellation destination. */
        public readonly ?\moodle_url $cancelurl = null,
        /** @var string Page heading. */
        public readonly string $heading = ''
    ) {
    }

    /**
     * Sends the selected response through Moodle's output API.
     */
    public function send(): void {
        global $OUTPUT;
        if ($this->redirecturl !== null) {
            redirect($this->redirecturl, $this->message, null, \core\output\notification::NOTIFY_SUCCESS);
        }
        echo $OUTPUT->header();
        if ($this->heading !== '') {
            echo $OUTPUT->heading($this->heading);
        }
        if ($this->confirmurl !== null) {
            echo $OUTPUT->confirm($this->message, $this->confirmurl, $this->cancelurl);
        } else {
            echo $OUTPUT->render_from_template($this->template, $this->data);
        }
        echo $OUTPUT->footer();
    }
}
