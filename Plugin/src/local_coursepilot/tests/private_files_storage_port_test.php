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

use local_coursepilot\tests\storage_port_contract_test;

/**
 * Der Ablage-Vertrag gegen den ersten Adapter (Issue #536, Spec 0021):
 * {@see private_files_storage_port} tritt hier gegen die gemeinsame
 * {@see storage_port_contract_test} an.
 *
 * Der Testbereich ({@see area()}) ist frei erfunden, wie schon der
 * Zweitort-Beweis in {@see storage_anchor_test} - eigene Wurzel, eigene
 * Namensregel (.md), aber die generischen Fehlerschluessel des
 * Kontextbereichs geliehen (kein Testbereich rechtfertigt eigene
 * lang-Strings). Kein Pointer-Feld, damit der Adapter nie in die
 * Pointer-Aufloesung des bisherigen Wegs verzweigt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(private_files_storage_port::class)]
final class private_files_storage_port_test extends storage_port_contract_test {

    protected function port(): storage_port {
        return new private_files_storage_port();
    }

    protected function area(): storage_area {
        return new storage_area(
            rootsetting: 'storageportcontracttestroot',
            defaultroot: 'storageport-contract-test',
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
                if (!preg_match('/^[A-Za-z0-9_.-]+\.md$/', $filename)) {
                    throw new \moodle_exception('contextfilenotmarkdown', 'local_coursepilot', '', $filename);
                }
            },
        );
    }
}
