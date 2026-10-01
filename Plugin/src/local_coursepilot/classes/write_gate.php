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

use local_coursepilot\catalog\drift_check;
use local_coursepilot\catalog\registry;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Selbstfreigabe des Feldkatalogs in zwei Stufen (Spec 0015 §11, ADR 0017,
 * Ticket #399): weil der Katalog überwiegend abgeschrieben ist, veraltet er
 * still - nach einem Moodle-Update soll die Administration sehen, ob
 * Coursepilot noch passt, ohne es auszuprobieren, und wenn nicht, soll nur die
 * betroffene Aktivitätsart gesperrt werden.
 *
 * 1. Billigteil (jeder Schreibvorgang, {@see assert_writable()}): vergleicht
 *    die zwischengespeicherte Moodle-Version mit der aktuellen. Gleich? Ein
 *    einzelner get_config()-Aufruf, kein DB-Introspektions- oder
 *    Reflection-Aufwand - "kostet keinen erkennbaren Aufwand".
 * 2. Tiefenprüfung (automatisch bei erkanntem Versionswechsel, auch
 *    Point-Release, UND jederzeit abrufbar über {@see all_statuses()} bzw.
 *    die Admin-Statusprüfung): {@see drift_check::check()} pro
 *    Aktivitätsart, Ergebnis wird zwischengespeichert, bis sich die Version
 *    erneut ändert.
 *
 * Kein Cron: die Tiefenprüfung läuft ausschliesslich ausgelöst durch eine
 * erkannte Versionsänderung (Schreibvorgang oder Statusseite) - nichts prüft
 * periodisch etwas, das sich nur beim Upgrade ändert.
 *
 * Drift sperrt nur die betroffene Aktivitätsart fürs Schreiben - Lesen und
 * Nachschlagen sind hiervon nie betroffen, kein Lese-Werkzeug ruft
 * {@see assert_writable()}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class write_gate {

    /** @var string Konfigurationskomponente fuer get_config()/set_config(). */
    private const CONFIG_COMPONENT = 'local_coursepilot';

    /** @var string Config-Key: zuletzt geprueftes Versions-Tupel. */
    private const CONFIG_CHECKED_VERSION = 'driftcheckversion';

    /** @var string Config-Key-Praefix je Aktivitaetsart: JSON-Verstossliste. */
    private const CONFIG_VIOLATIONS_PREFIX = 'driftviolations_';

    /**
     * Wirft, wenn diese Aktivitätsart gerade schreibgesperrt ist - sonst
     * kehrt sie folgenlos zurueck. Von jedem Schreibendpunkt (Ticket #399:
     * update_module_settings, create_module, create_quiz,
     * update_quiz_settings) vor der eigentlichen Schreiblogik aufzurufen.
     *
     * @param string $modname
     * @return void
     * @throws moodle_exception modnamedriftlocked
     */
    public static function assert_writable(string $modname): void {
        $status = self::status_for($modname);
        if ($status['zustand'] !== 'braucht_arbeit') {
            return;
        }

        // ADR 0017: "Die Lehrkraft sieht nie die Pflege, nur die Folge" - die
        // rohen technischen Verstoesse (Spalten/Konstanten/Quellen) gehoeren
        // in die Admin-Statusprüfung ({@see \local_coursepilot\check\activity_drift}),
        // nicht in die Lehrkraft-Meldung. Hier nur als $debuginfo (nur mit
        // aktiviertem Debugging sichtbar), nicht als Platzhalter im lang-String.
        throw new moodle_exception(
            'modnamedriftlocked',
            'local_coursepilot',
            '',
            ['modname' => $modname],
            implode(' ', $status['verstoesse'])
        );
    }

    /**
     * Status einer einzelnen Aktivitätsart - einer von "geprueft",
     * "automatisch_geprueft", "braucht_arbeit" (Ticket #399: "je
     * Aktivitätsart einer von drei Zuständen").
     *
     * @param string $modname
     * @return array{modname: string, zustand: string, verstoesse: string[]}
     */
    public static function status_for(string $modname): array {
        global $CFG;

        self::ensure_fresh();

        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            return ['modname' => $modname, 'zustand' => 'braucht_arbeit', 'verstoesse' => ['Unbekannte Aktivitätsart.']];
        }

        $violations = self::cached_violations($modname);
        if ($violations) {
            $zustand = 'braucht_arbeit';
        } elseif ((int) $CFG->branch > $catalogclass::reviewed_up_to_major()) {
            // Neuere Hauptversion als das letzte manuelle Review - maschinell
            // gruen, aber das nicht pruefbare Restrisiko (Wertelisten,
            // Kombinationsregeln, Nebenwirkungen) ist noch nicht durchgesehen.
            $zustand = 'automatisch_geprueft';
        } else {
            $zustand = 'geprueft';
        }

        return ['modname' => $modname, 'zustand' => $zustand, 'verstoesse' => $violations];
    }

    /**
     * Status aller katalogisierten Aktivitätsarten - Grundlage der
     * Admin-Statusprüfung ({@see \local_coursepilot\check\activity_drift}) und
     * jederzeit auf Abruf nutzbar, unabhaengig von einem Schreibvorgang.
     *
     * @return array<int, array{modname: string, zustand: string, verstoesse: string[]}>
     */
    public static function all_statuses(): array {
        return array_map([self::class, 'status_for'], registry::known_modnames());
    }

    /**
     * Fuehrt die Tiefenprüfung fuer alle Aktivitätsarten neu aus, wenn sich
     * die Moodle-Version (inkl. Point-Release) oder die installierte
     * Coursepilot-Version seit dem letzten Aufruf geaendert hat - der
     * "erkannte Versionswechsel" aus Ticket #399. Sonst kein DB-/
     * Reflection-Zugriff (Billigteil).
     *
     * @return void
     */
    private static function ensure_fresh(): void {
        global $CFG;

        $currentversiontuple = self::version_tuple();
        $lastchecked = get_config(self::CONFIG_COMPONENT, self::CONFIG_CHECKED_VERSION);
        if ($lastchecked === $currentversiontuple) {
            return;
        }

        foreach (registry::known_modnames() as $modname) {
            $violations = drift_check::check($modname);
            set_config(self::CONFIG_VIOLATIONS_PREFIX . $modname, json_encode($violations), self::CONFIG_COMPONENT);
        }
        set_config(self::CONFIG_CHECKED_VERSION, $currentversiontuple, self::CONFIG_COMPONENT);
    }

    /**
     * Moodle-Kernversion plus installierte Coursepilot-Version als ein Tupel -
     * ein Coursepilot-Deploy (neue Katalogklasse ohne Moodle-Upgrade) loest die
     * Tiefenprüfung damit ebenso aus wie ein Moodle-Upgrade.
     *
     * @return string
     */
    private static function version_tuple(): string {
        global $CFG;

        return $CFG->version . ':' . get_config(self::CONFIG_COMPONENT, 'version');
    }

    /**
     * @param string $modname
     * @return string[]
     */
    private static function cached_violations(string $modname): array {
        $raw = get_config(self::CONFIG_COMPONENT, self::CONFIG_VIOLATIONS_PREFIX . $modname);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
