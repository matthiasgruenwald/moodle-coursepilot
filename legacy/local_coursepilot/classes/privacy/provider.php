<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursepilot\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\null_provider;

/**
 * Privacy-API-Deklaration fuer local_coursepilot (Issue #187).
 *
 * Das Plugin ist eine zustandslose Webservice-Schicht fuer die
 * lehrkraftbezogene Kursgestaltung: Es besitzt keine eigenen Datenbanktabellen
 * und speichert keine personenbezogenen Daten. Lernendendaten (Abgaben,
 * Forenbeitraege, Quizversuche, Bewertungen, Teilnehmendenlisten) werden weder
 * gelesen noch exportiert. Daher ist der null_provider die zutreffende
 * Deklaration - er behauptet bewusst keine Verarbeitung von Lernendendaten.
 */
class provider implements null_provider {

    /**
     * Begründung, warum das Plugin keine personenbezogenen Daten speichert.
     *
     * @return string Lang-String-Schluessel
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
