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

use local_coursepilot\event\tool_access_failed;
use local_coursepilot\event\tool_access_succeeded;

/**
 * Protokollierung von Coursepilot-Zugriffen ueber die Moodle-Ereignis-API,
 * mit vier einstellbaren Stufen (#339), damit die nativen Protokollberichte
 * bei vielen Nutzenden nicht ueberlaufen.
 *
 * Einziger Aufrufer: dispatcher.php, an den beiden vorhandenen Antwort-
 * Funnelpunkten (error() fuer alle Fehlerantworten, handle_tools_call() fuer
 * Werkzeugerfolg/-fehler) - ponytail: kein Observer/Hook-Mechanismus, es
 * gibt genau eine Aufrufstelle je Ergebnisart.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class access_log {

    /** @var int Kein Protokoll. */
    public const LEVEL_NONE = 0;

    /** @var int Schreibzugriffe und Fehler (#388: Konstantenname bleibt, Bedeutung rueckt). */
    public const LEVEL_ERRORS = 1;

    /** @var int Zusaetzlich Lesezugriffe (Voreinstellung). */
    public const LEVEL_READS = 2;

    /** @var int Alles. */
    public const LEVEL_ALL = 3;

    /**
     * Die konfigurierte Protokollstufe, mit "Lesezugriffe und Fehler" als
     * Voreinstellung fuer eine frische Installation (Konfigwert nie gesetzt).
     *
     * @return int
     */
    public static function current_level(): int {
        $level = get_config('local_coursepilot', 'loglevel');
        if ($level === false || $level === '') {
            return self::LEVEL_READS;
        }
        return (int) $level;
    }

    /**
     * Protokolliert einen erfolgreichen Werkzeugaufruf, sofern die Stufe es
     * verlangt.
     *
     * Ticket #388 (erstes schreibendes Werkzeug) rueckt die Bedeutung der
     * Stufen: 1 protokolliert jetzt Schreibzugriffe (zusaetzlich zu Fehlern),
     * erst 2 auch Lesezugriffe - sonst waeren Schreibvorgaenge in der
     * Voreinstellung "nur Fehler" unprotokolliert, obwohl gerade sie es am
     * meisten sein sollten.
     *
     * @param string $toolname
     * @param bool $iswrite true fuer ein schreibendes Werkzeug (tool_registry::is_write()).
     * @param string|null $path Dateipfad, wenn das Werkzeug einen berührt hat
     *        (Spec 0018 §9.2) - z.B. Kontext- oder Materialordner-Pfad aus der
     *        Werkzeugantwort. Null, wenn das Werkzeug keinen Dateipfad kennt.
     * @param int|null $userid Ueberschreibt den protokollierten Nutzer (#501):
     *        der Werkbank-Downloadendpunkt laeuft ohne Moodle-Login/$USER
     *        (das Ticket ist der Berechtigungsnachweis) und muss den
     *        Ticket-Eigentuemer explizit angeben, statt sich auf das
     *        Event-Default $USER->id zu verlassen. Null (Standard) laesst
     *        core\event\base sein Default anwenden - unveraendertes
     *        Verhalten fuer jeden bisherigen Aufrufer (dispatcher.php).
     * @return void
     */
    public static function log_success(string $toolname, bool $iswrite = false, ?string $path = null, ?int $userid = null): void {
        $threshold = $iswrite ? self::LEVEL_ERRORS : self::LEVEL_READS;
        if (self::current_level() < $threshold) {
            return;
        }
        $data = ['other' => ['toolname' => $toolname, 'path' => $path]];
        if ($userid !== null) {
            $data['userid'] = $userid;
        }
        tool_access_succeeded::create($data)->trigger();
    }

    /**
     * Protokolliert einen fehlgeschlagenen Zugriff, sofern die Stufe es
     * verlangt (>= LEVEL_ERRORS).
     *
     * @param string $reason Kurze, geheimnisfreie Fehlerbeschreibung.
     * @param string|null $toolname
     * @param string|null $path Dateipfad, wenn der gescheiterte Zugriff einen
     *        berührt hat und er noch bekannt war (#501: ein Werkbank-
     *        Downloadticket kann den Pfad schon verloren haben, wenn erst
     *        eine spätere Prüfung scheitert - siehe
     *        {@see \local_coursepilot\werkbank_ticket_redemption_failed}).
     *        Null, wenn kein Pfad bekannt ist.
     * @param int|null $userid Siehe {@see log_success()}.
     * @param string|null $detail Interner Diagnosehinweis, nur bei Stufe
     *        "Alles" im Ereignis gespeichert.
     * @return void
     */
    public static function log_failure(
        string $reason,
        ?string $toolname = null,
        ?string $path = null,
        ?int $userid = null,
        ?string $detail = null
    ): void {
        if (self::current_level() < self::LEVEL_ERRORS) {
            return;
        }
        $data = ['other' => ['reason' => $reason, 'toolname' => $toolname, 'path' => $path]];
        if (self::current_level() >= self::LEVEL_ALL && $detail !== null) {
            $data['other']['detail'] = $detail;
        }
        if ($userid !== null) {
            $data['userid'] = $userid;
        }
        tool_access_failed::create($data)->trigger();
    }
}
