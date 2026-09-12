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

/**
 * Die Spalte Ablageort der Verbindungsübersicht (Issue #499, Spec #486 §12):
 * Zustand je Ziel (*offen*, *in Moodle*, *extern: Host*) und Marker fuer
 * nicht zugelassenen Speicher, offene Ausstaende, offenen Altbestand und
 * einen defekten Pointer. Liest ausschliesslich Pointer und Datenbank -
 * ohne Netz, ohne Pfad (Akzeptanzkriterium). Ein Zuruecksetzen gibt es
 * nicht, diese Klasse liest nur.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connection_ablageort {

    /**
     * @param int $userid
     * @return array{targets: array<string, string>, markers: string[]}
     *         targets: je Ziel die fertige Anzeigezeile (z.B. "Kontextbereich: extern: cloud.example.test").
     *         markers: fertige Marker-Zeilen, leer wenn keine zutreffen.
     */
    public static function describe(int $userid): array {
        $decoded = pointer_scan::raw_pointer_for($userid);

        $targets = [];
        $hostblocked = false;
        $defects = [];
        foreach (pointer_scan::TARGETS as $target) {
            $state = pointer_scan::target_state($userid, $decoded, $target);
            $targets[$target] = get_string('ablageorttarget' . $target, 'local_kurspilot')
                . ': ' . self::state_label($state);

            if ($state['state'] === 'extern' && $state['host'] !== null && !personal_data_hosts::allowed($state['host'])) {
                $hostblocked = true;
            }
            if ($state['defect'] !== null) {
                $defects[] = $state['defect'];
            }
        }

        $markers = [];
        if ($hostblocked) {
            $markers[] = get_string('ablageortmarkernichtzugelassen', 'local_kurspilot');
        }
        if (pointer_scan::has_open_ausstand($userid)) {
            $markers[] = get_string('ablageortmarkerausstand', 'local_kurspilot');
        }
        if (pointer_scan::has_open_altbestand($decoded)) {
            $markers[] = get_string('ablageortmarkeraltbestand', 'local_kurspilot');
        }
        foreach (array_unique($defects) as $defect) {
            $markers[] = get_string('ablageortmarkerdefekt', 'local_kurspilot', self::defect_label($defect));
        }

        return ['targets' => $targets, 'markers' => $markers];
    }

    /**
     * @param array{state: string, host: ?string, defect: ?string} $state
     * @return string
     */
    private static function state_label(array $state): string {
        return match ($state['state']) {
            'moodle' => get_string('ablageortmoodle', 'local_kurspilot'),
            'extern' => get_string('ablageortextern', 'local_kurspilot', $state['host']),
            'kaputt' => get_string('ablageortdefektungueltig', 'local_kurspilot'),
            default => get_string('ablageortoffen', 'local_kurspilot'),
        };
    }

    /**
     * @param string $defect "instanzfehlt"|"fremdeinstanz"|"http"|"ungueltig"
     * @return string
     */
    private static function defect_label(string $defect): string {
        return match ($defect) {
            'instanzfehlt' => get_string('ablageortdefektinstanzfehlt', 'local_kurspilot'),
            'fremdeinstanz' => get_string('ablageortdefektfremdeinstanz', 'local_kurspilot'),
            'http' => get_string('ablageortdefekthttp', 'local_kurspilot'),
            default => get_string('ablageortdefektungueltig', 'local_kurspilot'),
        };
    }
}
