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

namespace local_coursepilot\external;

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Die Lieferung eines Skill-Korpus-Eintrags (Spec 0020 §4, Issue #450): ohne
 * Kursbindung, 'local/coursepilot:use' im Systemkontext genuegt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(get_skill::class)]
final class get_skill_test extends \advanced_testcase {

    /**
     * Liefert Inhalt, referenzierte Teile und Korpus-Stand.
     */
    public function test_returns_content_referenced_parts_and_corpus_stand(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $result = get_skill::execute('coursepilot');
        $result = external_api::clean_returnvalue(get_skill::execute_returns(), $result);

        $this->assertStringContainsString('coursepilot-core', $result['content']);
        $this->assertContains('coursepilot-core', $result['referenced_parts']);
        $this->assertNotSame('', $result['corpus_version']);
    }

    /**
     * Unbekannter Name: die Meldung nennt die gueltigen Namen statt eines
     * leeren Ergebnisses.
     */
    public function test_unknown_name_names_valid_names(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);

        try {
            get_skill::execute('gibtsnicht');
            $this->fail('moodle_exception erwartet.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('coursepilot-core', $e->getMessage());
        }
    }

    /**
     * Ein Name mit Pfadanteilen wird abgewiesen - geprueft gegen die
     * Verzeichnisliste, nicht per Zeichenfilter.
     *
     * @param string $name
     */
    #[DataProvider('path_like_name_provider')]
    public function test_path_like_name_is_rejected(string $name): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        get_skill::execute($name);
    }

    /**
     * @return array<string, string[]>
     */
    public static function path_like_name_provider(): array {
        return [
            'dot-dot-slash' => ['../../../etc/passwd'],
            'leading-slash' => ['/etc/passwd'],
            'backslash' => ['..\\..\\coursepilot-core'],
            'encoded' => ['%2e%2e%2fcoursepilot-core'],
            'suffixed-real-name' => ['coursepilot-core/../../../etc/passwd'],
        ];
    }

    /**
     * Ohne 'local/coursepilot:use' im Systemkontext wird abgewiesen.
     */
    public function test_without_capability_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        get_skill::execute('coursepilot');
    }
}
