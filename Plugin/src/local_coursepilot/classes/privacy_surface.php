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
 * Datenschutz-Vertrag der Coursepilot-Oberflaeche (Karte #289, Ticket #300).
 *
 * Coursepilot gibt ausschliesslich lehrkraftbezogene Kursgestaltung frei. Von
 * Lernenden erzeugte oder personenbezogene Daten (Abgaben, Forenbeitraege,
 * Quizversuche, Bewertungen, Teilnehmendenlisten) sind weder ueber MCP-Tools
 * noch ueber Moodle-Webservices erreichbar.
 *
 * Eine Prueffunktion, drei Aufrufer (#300, Punkte 1/5/6):
 *  - Test:     tests/privacy_surface_test.php prueft gegen die real
 *              registrierte Oberflaeche einer laufenden Instanz.
 *  - Laufzeit: mcp.php listet und ruft nur Gelistetes.
 *  - Anzeige:  surface.php zeigt Allowlist, verbotene Bestandteile und
 *              Abgleichstatus.
 *
 * Reine Datenstruktur plus reine Funktionen - kein Moodle-Zugriff ausser in
 * registered_functions(), damit die Prueflogik selbst testbar bleibt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class privacy_surface {

    /** @var string Shortname des externen Dienstes aus db/services.php. */
    public const SERVICE_SHORTNAME = 'coursepilot';

    /**
     * Positive Allowlist: MCP-Toolname => Webservice-Funktionsname.
     *
     * Abgeleitet aus {@see tool_registry} - der einen Werkzeug-Registrierung
     * (#378). Ein neues Tool ist nur nach ausdruecklicher Pruefung zulaessig
     * - der Vertragstest scheitert, solange ein registrierter Name hier
     * fehlt oder umgekehrt.
     *
     * @return array<string, string>
     */
    public static function allowed_tools(): array {
        return tool_registry::allowed_tools();
    }

    /**
     * Namensbestandteile, die in keinem registrierten Namen vorkommen duerfen.
     *
     * Sie bezeichnen Lernendendaten- oder Personenoberflaechen. Der
     * Geltungsbereich sind **registrierte Namen** (Tools, Webservice-
     * Funktionen), nicht intern aufgerufene PHP-Funktionen: ein
     * enrol_get_my_courses() im Rumpf einer erlaubten Funktion ist zulaessig
     * (#300, Punkt 3).
     *
     * "discussion" statt "forum": das Anlegen einer Forum-Aktivitaet ist
     * erlaubte Kursgestaltung, das Lesen von Beitraegen nicht - Moodles
     * forum-lesende Webservices heissen durchgaengig "...discussion(s)/...posts".
     *
     * @var string[]
     */
    public const FORBIDDEN_TOKENS = [
        'submission',
        'discussion',
        'attempt',
        'participant',
        'enrol',
        'grade',
        'user',
    ];

    /**
     * Ist dieser MCP-Toolname freigegeben?
     *
     * @param string $toolname
     * @return bool
     */
    public static function is_allowed_tool(string $toolname): bool {
        return isset(self::allowed_tools()[$toolname]);
    }

    /**
     * Webservice-Funktion zu einem freigegebenen Toolnamen.
     *
     * @param string $toolname
     * @return string|null null, wenn der Toolname nicht freigegeben ist.
     */
    public static function function_for_tool(string $toolname): ?string {
        return self::allowed_tools()[$toolname] ?? null;
    }

    /**
     * Die Webservice-Funktionen, die auf der laufenden Instanz tatsaechlich
     * am Coursepilot-Dienst haengen.
     *
     * Das ist der Fall, den kein Repo-Test fangen kann: ein Admin haengt dem
     * Dienst nachtraeglich eine Funktion an (#300, Punkt 1).
     *
     * @return string[] sortierte Funktionsnamen; leer, wenn der Dienst fehlt.
     */
    public static function registered_functions(): array {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE_SHORTNAME]);
        if (!$service) {
            return [];
        }
        $names = $DB->get_fieldset_select(
            'external_services_functions',
            'functionname',
            'externalserviceid = :id',
            ['id' => $service->id]
        );
        sort($names);
        return $names;
    }

    /**
     * Gleicht die real registrierte Oberflaeche gegen die Allowlist und die
     * verbotenen Namensbestandteile ab.
     *
     * Rein: bekommt die registrierte Liste herein, greift selbst nicht auf
     * Moodle zu. Test, Laufzeit und Anzeige rufen dieselbe Funktion.
     *
     * @param string[] $registered Real registrierte Webservice-Funktionsnamen.
     * @return array<int, array{type: string, name: string, detail: string}>
     *         Leer, wenn die Oberflaeche dem Vertrag entspricht.
     */
    public static function check(array $registered): array {
        $violations = [];
        $allowedtools = self::allowed_tools();
        $allowed = array_values($allowedtools);

        foreach (array_diff($registered, $allowed) as $name) {
            $violations[] = [
                'type' => 'unexpected',
                'name' => $name,
                'detail' => 'Registriert, aber nicht in der Allowlist.',
            ];
        }

        foreach (array_diff($allowed, $registered) as $name) {
            $violations[] = [
                'type' => 'missing',
                'name' => $name,
                'detail' => 'In der Allowlist, aber nicht registriert.',
            ];
        }

        // Verbotene Bestandteile gelten fuer beide Namensraeume: die
        // registrierten Funktionen und die nach aussen sichtbaren Toolnamen.
        $checknames = array_unique(array_merge($registered, array_keys($allowedtools), $allowed));
        foreach ($checknames as $name) {
            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (strpos(strtolower($name), $token) !== false) {
                    $violations[] = [
                        'type' => 'forbidden_token',
                        'name' => $name,
                        'detail' => 'Enthaelt den verbotenen Bestandteil "' . $token . '".',
                    ];
                }
            }
        }

        return $violations;
    }
}
