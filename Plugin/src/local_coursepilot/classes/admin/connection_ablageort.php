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

/**
 * Die Spalte Ablageort der Verbindungsübersicht (Issue #499, Spec #486 §12):
 * Zustand je Ziel (*offen*, *in Moodle*, *extern: Host*) und Marker fuer
 * nicht zugelassenen Speicher, offene Ausstaende, offenen Altbestand und
 * einen defekten Pointer. Liest ausschliesslich Pointer und Datenbank -
 * ohne Netz, ohne Pfad (Akzeptanzkriterium). Ein Zuruecksetzen gibt es
 * nicht, diese Klasse liest nur.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
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
            $targets[$target] = get_string('ablageorttarget' . $target, 'local_coursepilot')
                . ': ' . self::state_label($state);

            if ($state['state'] === pointer_scan::STATE_EXTERN && $state['host'] !== null && !personal_data_hosts::allowed($state['host'])) {
                $hostblocked = true;
            }
            if ($state['defect'] !== null) {
                $defects[] = $state['defect'];
            }
        }

        $markers = [];
        if ($hostblocked) {
            $markers[] = get_string('ablageortmarkernichtzugelassen', 'local_coursepilot');
        }
        if (pointer_scan::has_open_ausstand($userid)) {
            $markers[] = get_string('ablageortmarkerausstand', 'local_coursepilot');
        }
        if (pointer_scan::has_open_altbestand($decoded)) {
            $markers[] = get_string('ablageortmarkeraltbestand', 'local_coursepilot');
        }
        foreach (array_unique($defects) as $defect) {
            $markers[] = get_string('ablageortmarkerdefekt', 'local_coursepilot', self::defect_label($defect));
        }

        return ['targets' => $targets, 'markers' => $markers];
    }

    /**
     * @param array{state: string, host: ?string, defect: ?string} $state
     * @return string
     */
    private static function state_label(array $state): string {
        return match ($state['state']) {
            pointer_scan::STATE_MOODLE => get_string('ablageortmoodle', 'local_coursepilot'),
            pointer_scan::STATE_EXTERN => get_string('ablageortextern', 'local_coursepilot', $state['host']),
            pointer_scan::STATE_BROKEN => get_string('ablageortdefektungueltig', 'local_coursepilot'),
            default => get_string('ablageortoffen', 'local_coursepilot'),
        };
    }

    /**
     * @param string $defect Einer der {@see pointer_scan}-`DEFECT_*`-Werte.
     * @return string
     */
    private static function defect_label(string $defect): string {
        return match ($defect) {
            pointer_scan::DEFECT_INSTANCE_MISSING => get_string('ablageortdefektinstanzfehlt', 'local_coursepilot'),
            pointer_scan::DEFECT_FOREIGN_INSTANCE => get_string('ablageortdefektfremdeinstanz', 'local_coursepilot'),
            pointer_scan::DEFECT_HTTP => get_string('ablageortdefekthttp', 'local_coursepilot'),
            default => get_string('ablageortdefektungueltig', 'local_coursepilot'),
        };
    }
}
