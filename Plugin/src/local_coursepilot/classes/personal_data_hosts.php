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
 * Die zugelassenen Speicher fuer personenbezogene Kontextdaten (Issue #493,
 * ADR 0021 §3, Spec #486 §6/§11): die Schule nennt sie in
 * `local_coursepilot | personaldatahosts`, ein Eintrag je Zeile. Ein Eintrag
 * gilt fuer eine Domain samt Unterdomains, getrennt wird nur an Punkten,
 * ohne `*`. Eine leere Liste bedeutet: nur Private Files - die als einziger
 * Ort immer zugelassen sind (siehe Aufrufer in den Kontextwerkzeugen, nicht
 * hier: diese Klasse kennt nur die externe Speicherliste).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class personal_data_hosts {

    /**
     * Ob ein WebDAV-Server (das Pruefmerkmal einer aufgeloesten Instanz,
     * {@see \local_coursepilot\webdav\webdav_instance::resolve()}) in der
     * konfigurierten Liste zugelassen ist - genau dann, wenn er einem
     * Eintrag entspricht oder eine seiner Unterdomains ist.
     *
     * @param string $host
     * @return bool
     */
    public static function allowed(string $host): bool {
        $host = self::normalise($host);
        if ($host === '') {
            return false;
        }
        foreach (self::configured() as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wirft den Aufruffehler "Speicher nicht zugelassen" (Issue #493, ADR
     * 0021 §3), wenn ein Ziel extern und sein Server nicht zugelassen ist -
     * Private Files (jede Position ausser EXTERN) sind immer zugelassen.
     * Geteilt von {@see \local_coursepilot\external\write_context_file} und
     * {@see \local_coursepilot\external\append_context_file}, die beide
     * "die ganze entstehende Datei" pruefen muessen (Spec #486 §6:
     * "auch beim Anhaengen und beim Kopieren").
     *
     * @param pointer_location|null $location
     * @param string $path Client-Pfad, fuer die Fehlermeldung.
     * @throws \moodle_exception contextfilehostnotallowed
     */
    public static function require_allowed_location(?pointer_location $location, string $path): void {
        if ($location !== null && $location->kind === pointer_location::EXTERN
                && !self::allowed((string) ($location->fingerprint['server'] ?? ''))) {
            throw new \moodle_exception('contextfilehostnotallowed', 'local_coursepilot', '', $path);
        }
    }

    /**
     * @return string[] Konfigurierte Domains, klein geschrieben, leere Zeilen entfernt.
     */
    public static function configured(): array {
        return self::parse((string) (get_config('local_coursepilot', 'personaldatahosts') ?: ''));
    }

    /**
     * Prueft eine noch nicht gespeicherte Einstellung auf Eintraege mit nur
     * einem Namensteil oder mit `*` - beides beim Speichern abgelehnt.
     *
     * @param string $raw Roher Einstellungswert, ein Eintrag je Zeile.
     * @return string|null Der erste ungueltige Eintrag, oder null, wenn alle gueltig sind.
     */
    public static function first_invalid_entry(string $raw): ?string {
        foreach (self::parse($raw) as $domain) {
            if (!str_contains($domain, '.') || str_contains($domain, '*')) {
                return $domain;
            }
        }
        return null;
    }

    /**
     * @param string $raw
     * @return string[]
     */
    private static function parse(string $raw): array {
        $domains = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = self::normalise($line);
            if ($line !== '') {
                $domains[] = $line;
            }
        }
        return $domains;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function normalise(string $value): string {
        return strtolower(trim($value));
    }
}
