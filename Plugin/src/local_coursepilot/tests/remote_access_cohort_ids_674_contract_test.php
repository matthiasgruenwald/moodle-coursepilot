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
 * The configured remote-access cohort list is a clean, re-indexed list of ids (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(remote_access::class)]
final class remote_access_cohort_ids_674_contract_test extends \advanced_testcase {
    public function test_the_selected_ids_are_unique_non_zero_and_consecutively_indexed(): void {
        $this->resetAfterTest();
        set_config('remoteaccesscohorts', '0,5,5,x,7', 'local_coursepilot');

        $ids = (new \ReflectionMethod(remote_access::class, 'selected_cohort_ids'))->invoke(null);

        $this->assertSame([5, 7], $ids);
    }

    public function test_an_unset_configuration_selects_no_cohort(): void {
        $this->resetAfterTest();
        unset_config('remoteaccesscohorts', 'local_coursepilot');

        $ids = (new \ReflectionMethod(remote_access::class, 'selected_cohort_ids'))->invoke(null);

        $this->assertSame([], $ids);
    }
}
