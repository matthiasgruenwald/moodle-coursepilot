<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\webdav;

/**
 * The step catalog of the WebDAV enablement (issue #490, spec #486 §12):
 * one source for the three enablement steps, which later also feed the
 * status check and the empty states of the location selection page. Each step
 * carries a check, an instruction and a target page and is
 * read live on every call - nothing here is stored or
 * cached, a revocation takes effect immediately (spec §2 check 4).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_setup_steps {
    /** @var string Repository type name, as in the core table "repository" and in the config plugin. */
    private const REPOSITORY_TYPE = 'webdav';
    /** @var string Capability that must take effect in the teacher's own user context. */
    private const CAPABILITY = 'repository/webdav:view';
    /**
     * @var string Address of the location selection page (spec §2/§5) - fixed here,
     *      the page itself follows in a later issue (#494). Every
     *      error message of the pointer resolution points here.
     */
    public const LOCATION_SELECTION_PAGE = '/local/coursepilot/location_selection.php';

    public const STEP_REPOSITORY_ACTIVE = 'repository_active';
    public const STEP_USER_INSTANCES = 'user_instances';
    public const STEP_CAPABILITY = 'capability';
    /**
     * The three steps, evaluated live for a specific person - each
     * step on its own (issue #528, spec #486 §5): previously this
     * method coupled `ok` to the previous steps ("step 1 off" made 2 and 3
     * automatically count as missing), although configuration and
     * role assignment can be set independently of each other. The
     * copyable text to the administration ({@see \local_coursepilot\location_selection::missing_steps_text()})
     * therefore names only what is actually missing. The actual effect
     * (all three together) remains reserved for {@see enabled_for_user()}.
     *
     * @param int $userid
     * @return array<string, array{ok: bool, instruction: string, targeturl: \moodle_url}>
     */
    public static function catalog(int $userid): array {
        global $DB;

        $repositoryrecord = $DB->get_record('repository', ['type' => self::REPOSITORY_TYPE]);
        $repositoryactive = $repositoryrecord !== false && (int) $repositoryrecord->visible === 1;
        $userinstancesallowed = (bool) get_config(self::REPOSITORY_TYPE, 'enableuserinstances');
        // $userid > 0 before the context access (issue #505 finding #1): the CLI
        // calls with $USER->id = 0, context_user::instance(0) throws
        // dml_missing_record there. Without a person the capability is "no" anyway.
        $hascapability = $userid > 0 && has_capability(self::CAPABILITY, \context_user::instance($userid));

        return [
            self::STEP_REPOSITORY_ACTIVE => [
                'ok' => $repositoryactive,
                'instruction' => get_string('webdavstep1instruction', 'local_coursepilot'),
                'targeturl' => new \moodle_url('/admin/repository.php'),
            ],
            self::STEP_USER_INSTANCES => [
                'ok' => $userinstancesallowed,
                'instruction' => get_string('webdavstep2instruction', 'local_coursepilot'),
                'targeturl' => new \moodle_url('/admin/repository.php'),
            ],
            self::STEP_CAPABILITY => [
                'ok' => $hascapability,
                'instruction' => get_string('webdavstep3instruction', 'local_coursepilot'),
                'targeturl' => new \moodle_url('/admin/roles/assign.php', ['contextid' => SYSCONTEXTID]),
            ],
        ];
    }

    /**
     * Whether all three steps are fulfilled for this person - the
     * WebDAV enablement from spec §2 check 4. Since issue #528 the
     * three steps in {@see catalog()} are evaluated independently of each other,
     * hence the explicit AND here instead of relying on an
     * already coupled `ok` value.
     *
     * @param int $userid
     * @return bool
     */
    public static function enabled_for_user(int $userid): bool {
        $steps = self::catalog($userid);
        return $steps[self::STEP_REPOSITORY_ACTIVE]['ok']
            && $steps[self::STEP_USER_INSTANCES]['ok']
            && $steps[self::STEP_CAPABILITY]['ok'];
    }
}
