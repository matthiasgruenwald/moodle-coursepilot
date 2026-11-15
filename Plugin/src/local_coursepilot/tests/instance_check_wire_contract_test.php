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

use local_coursepilot\tests\local_http_server;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The self-request really follows redirects and gives up after five seconds (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(instance_check::class)]
final class instance_check_wire_contract_test extends \advanced_testcase {
    /** @var local_http_server|null Server of the running test. */
    private ?local_http_server $server = null;

    protected function tearDown(): void {
        $this->server?->stop();
        parent::tearDown();
    }

    /**
     * Starts a server whose behaviour depends on the first path segment.
     */
    private function start_server(): void {
        global $CFG;
        $this->resetAfterTest();
        // The loopback interface is blocked for Moodle's curl by default.
        $CFG->curlsecurityblockedhosts = '';
        $CFG->curlsecurityallowedport = '';
        $router = <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segment = explode('/', ltrim($path, '/'))[0];
if ($segment === 'redirect') {
    header('Location: /fast' . substr($path, strlen('/redirect')), true, 302);
    return;
}
if ($segment === 'slow45') {
    usleep(4500000);
} else if ($segment === 'slow55') {
    usleep(5500000);
}
header('Content-Type: application/json');
echo '{"issuer":"x"}';
PHP;
        $this->server = new local_http_server($router);
    }

    public function test_the_self_request_follows_a_redirect_to_the_discovery_document(): void {
        $this->start_server();

        $result = instance_check::self_check($this->server->base_url() . '/redirect');

        $this->assertSame(200, $result['httpcode']);
    }

    public function test_the_self_request_waits_for_a_response_that_takes_four_and_a_half_seconds(): void {
        $this->start_server();

        $result = instance_check::self_check($this->server->base_url() . '/slow45');

        $this->assertSame(200, $result['httpcode']);
    }

    public function test_the_self_request_gives_up_on_a_response_that_takes_five_and_a_half_seconds(): void {
        $this->start_server();

        $result = instance_check::self_check($this->server->base_url() . '/slow55');

        $this->assertNull($result['httpcode']);
        $this->assertSame('selfcheckrequireshttps', $result['detail']);
    }
}
