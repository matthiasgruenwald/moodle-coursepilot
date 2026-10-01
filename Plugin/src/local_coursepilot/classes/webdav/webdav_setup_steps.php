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

namespace local_coursepilot\webdav;

/**
 * Der Schrittkatalog der WebDAV-Freischaltung (Issue #490, Spec #486 §12):
 * eine Quelle fuer die drei Freischaltungsschritte, die spaeter auch die
 * Statusprüfung und die Leerzustaende der Ortswahlseite lesen. Jeder Schritt
 * traegt eine Pruefung, eine Handlungsanweisung und eine Zielseite und wird
 * bei jedem Aufruf live abgelesen - nichts hier wird gespeichert oder
 * zwischengespeichert, ein Entzug wirkt sofort (Spec §2 Pruefung 4).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_setup_steps {

    /** @var string Repository-Typname, wie im Core-Table "repository" und im Config-Plugin. */
    private const REPOSITORY_TYPE = 'webdav';

    /** @var string Capability, die im eigenen Nutzerkontext der Lehrkraft wirken muss. */
    private const CAPABILITY = 'repository/webdav:view';

    /**
     * @var string Adresse der Ortswahlseite (Spec §2/§5) - hier festgelegt,
     *      die Seite selbst folgt in einem spaeteren Issue (#494). Jede
     *      Fehlermeldung der Pointer-Aufloesung verweist hierher.
     */
    public const ORTSWAHL_PAGE = '/local/coursepilot/ortswahl.php';

    public const STEP_REPOSITORY_ACTIVE = 'repository_active';
    public const STEP_USER_INSTANCES = 'user_instances';
    public const STEP_CAPABILITY = 'capability';

    /**
     * Die drei Schritte, live ausgewertet fuer eine bestimmte Person - jeder
     * Schritt fuer sich (Issue #528, Spec #486 §5): vorher koppelte diese
     * Methode `ok` an die vorherigen Schritte ("Schritt 1 aus" liess 2 und 3
     * automatisch als fehlend gelten), obwohl Konfiguration und
     * Rollenzuweisung unabhaengig voneinander gesetzt sein koennen. Der
     * kopierbare Text an die Administration ({@see \local_coursepilot\location_selection::missing_steps_text()})
     * nennt dadurch nur, was tatsaechlich fehlt. Die tatsaechliche Wirkung
     * (alle drei zusammen) bleibt {@see enabled_for_user()} vorbehalten.
     *
     * @param int $userid
     * @return array<string, array{ok: bool, instruction: string, targeturl: \moodle_url}>
     */
    public static function catalog(int $userid): array {
        global $DB;

        $repositoryrecord = $DB->get_record('repository', ['type' => self::REPOSITORY_TYPE]);
        $repositoryactive = $repositoryrecord !== false && (int) $repositoryrecord->visible === 1;
        $userinstancesallowed = (bool) get_config(self::REPOSITORY_TYPE, 'enableuserinstances');
        // $userid > 0 vor dem Kontextzugriff (Issue #505 Befund #1): die CLI
        // ruft mit $USER->id = 0 auf, context_user::instance(0) wirft dort
        // dml_missing_record. Ohne Person ist die Capability ohnehin "nein".
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
     * Ob alle drei Schritte fuer diese Person erfuellt sind - die
     * WebDAV-Freischaltung aus Spec §2 Pruefung 4. Seit Issue #528 werten
     * die drei Schritte in {@see catalog()} unabhaengig voneinander aus,
     * deshalb hier die ausdrueckliche UND-Verknuepfung statt sich auf eine
     * bereits gekoppelte `ok`-Angabe zu verlassen.
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
