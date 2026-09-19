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

/**
 * Ein fehlgeschlagener Ticketabruf (#501, Spec #486 §13), mit dem betroffenen
 * Werkbankpfad, sofern zum Fehlerzeitpunkt schon bekannt - fuer den
 * Zugriffsprotokoll-Eintrag von `werkbank/download.php` ("mit Datei und
 * Ergebnis"): ein gewoehnlicher {@see \moodle_exception} verliert den Pfad,
 * sobald {@see werkbank_ticket::redeem()} die Ticketzeile bereits geloescht
 * hat, bevor eine spaetere Pruefung scheitert.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class werkbank_ticket_redemption_failed extends \moodle_exception {

    /**
     * @param string $errorcode Sprachschluessel in local_coursepilot.
     * @param string|null $path Werkbankpfad, oder null, wenn das Ticket
     *        selbst schon unbekannt war (kein Pfad zu kennen).
     */
    public function __construct(string $errorcode, public readonly ?string $path) {
        parent::__construct($errorcode, 'local_coursepilot');
    }
}
