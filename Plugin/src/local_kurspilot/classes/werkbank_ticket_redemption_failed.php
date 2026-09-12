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

namespace local_kurspilot;

/**
 * Ein fehlgeschlagener Ticketabruf (#501, Spec #486 §13), mit dem betroffenen
 * Werkbankpfad, sofern zum Fehlerzeitpunkt schon bekannt - fuer den
 * Zugriffsprotokoll-Eintrag von `werkbank/download.php` ("mit Datei und
 * Ergebnis"): ein gewoehnlicher {@see \moodle_exception} verliert den Pfad,
 * sobald {@see werkbank_ticket::redeem()} die Ticketzeile bereits geloescht
 * hat, bevor eine spaetere Pruefung scheitert.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class werkbank_ticket_redemption_failed extends \moodle_exception {

    /**
     * @param string $errorcode Sprachschluessel in local_kurspilot.
     * @param string|null $path Werkbankpfad, oder null, wenn das Ticket
     *        selbst schon unbekannt war (kein Pfad zu kennen).
     */
    public function __construct(string $errorcode, public readonly ?string $path) {
        parent::__construct($errorcode, 'local_kurspilot');
    }
}
