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

use local_coursepilot\personal_data_hosts;

// admin_setting_configtextarea ist eine legacy-globale Klasse aus
// lib/adminlib.php, nicht per PSR-4 autoloadbar - normalerweise laengst
// geladen, wenn settings.php innerhalb des Administrationsbaums laeuft, aber
// nicht zuverlaessig in PHPUnit oder anderem Fruehkontext. global $CFG, weil
// dieser Code beim Autoloaden innerhalb einer Funktion ausgefuehrt wird, wo
// $CFG sonst nicht sichtbar waere.
global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Die Einstellungsseite fuer `local_coursepilot | personaldatahosts` (Issue
 * #493): eine gewoehnliche Textarea, aber mit Ablehnung beim Speichern, wenn
 * ein Eintrag nur aus einem Namensteil besteht oder `*` enthaelt - siehe
 * {@see personal_data_hosts::first_invalid_entry()} fuer die geteilte Regel.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class personaldatahosts_setting extends \admin_setting_configtextarea {

    /**
     * @param mixed $data
     * @return mixed true, wenn gueltig, sonst eine Fehlermeldung.
     */
    public function validate($data) {
        $parentvalidation = parent::validate($data);
        if ($parentvalidation !== true) {
            return $parentvalidation;
        }

        $invalid = personal_data_hosts::first_invalid_entry((string) $data);
        if ($invalid !== null) {
            return get_string('personaldatahostsinvalid', 'local_coursepilot', $invalid);
        }
        return true;
    }
}
