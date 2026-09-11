<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\admin;

use local_kurspilot\personal_data_hosts;

// admin_setting_configtextarea ist eine legacy-globale Klasse aus
// lib/adminlib.php, nicht per PSR-4 autoloadbar - normalerweise laengst
// geladen, wenn settings.php innerhalb des Administrationsbaums laeuft, aber
// nicht zuverlaessig in PHPUnit oder anderem Fruehkontext. global $CFG, weil
// dieser Code beim Autoloaden innerhalb einer Funktion ausgefuehrt wird, wo
// $CFG sonst nicht sichtbar waere.
global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Die Einstellungsseite fuer `local_kurspilot | personaldatahosts` (Issue
 * #493): eine gewoehnliche Textarea, aber mit Ablehnung beim Speichern, wenn
 * ein Eintrag nur aus einem Namensteil besteht oder `*` enthaelt - siehe
 * {@see personal_data_hosts::first_invalid_entry()} fuer die geteilte Regel.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
            return get_string('personaldatahostsinvalid', 'local_kurspilot', $invalid);
        }
        return true;
    }
}
