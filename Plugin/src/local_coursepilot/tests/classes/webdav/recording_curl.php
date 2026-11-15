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


namespace local_coursepilot\tests\webdav;

/**
 * Curl double that records what a transport asks for and answers from public properties.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class recording_curl extends \curl {
    /** @var array<int, array{0: string, 1: string, 2: mixed, 3: array<string, mixed>}> Recorded calls. */
    public array $calls = [];

    /** @var mixed Raw answer of every request. */
    public mixed $fakeraw = 'body';

    /** @var mixed[] Transfer info. */
    public array $fakeinfo = ['http_code' => 207];

    /** @var int Curl error number. */
    public int $fakeerrno = 0;

    /** @var mixed[] Response headers. */
    public array $fakeheaders = [];

    /**
     * Records a GET.
     *
     * @param string $url
     * @param mixed[] $params
     * @param mixed[] $options
     * @return mixed
     */
    public function get($url, $params = [], $options = []) {
        $this->calls[] = ['GET', $url, $params, $options];
        return $this->fakeraw;
    }

    /**
     * Records a DELETE.
     *
     * @param string $url
     * @param mixed[] $param
     * @param mixed[] $options
     * @return mixed
     */
    public function delete($url, $param = [], $options = []) {
        $this->calls[] = ['DELETE', $url, $param, $options];
        return $this->fakeraw;
    }

    /**
     * Records a POST.
     *
     * @param string $url
     * @param mixed[]|string $params
     * @param mixed[] $options
     * @return mixed
     */
    public function post($url, $params = '', $options = []) {
        $this->calls[] = ['POST', $url, $params, $options];
        return $this->fakeraw;
    }

    /**
     * Transfer info.
     *
     * @return mixed[]
     */
    public function get_info() {
        return $this->fakeinfo;
    }

    /**
     * Error number.
     *
     * @return int
     */
    public function get_errno() {
        return $this->fakeerrno;
    }

    /**
     * Response headers.
     *
     * @return mixed[]
     */
    public function getResponse() { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        return $this->fakeheaders;
    }
}
