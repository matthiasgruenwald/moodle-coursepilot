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

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Der Skill-Korpus (Spec 0020 §3.1/§4, Issue #450): das Verzeichnis ist die
 * Quelle, der Name ist ein Bezeichner, kein Pfad.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(skill_corpus::class)]
final class skill_corpus_test extends \advanced_testcase {

    /**
     * list() liefert Name, Art, Auslöser und Umfang - keinen Inhalt.
     */
    public function test_list_reports_catalog_without_content(): void {
        $entries = skill_corpus::list();
        $this->assertNotEmpty($entries);

        $byname = [];
        foreach ($entries as $entry) {
            $byname[$entry['name']] = $entry;
            $this->assertArrayNotHasKey('content', $entry);
            $this->assertContains($entry['art'], ['adapter', 'referenz']);
            $this->assertGreaterThan(0, $entry['umfang']);
        }

        $this->assertArrayHasKey('kurspilot', $byname);
        $this->assertSame('adapter', $byname['kurspilot']['art']);
        $this->assertStringContainsString('Kurspilot-Einstieg', $byname['kurspilot']['ausloeser']);

        $this->assertArrayHasKey('kurspilot-core', $byname);
        $this->assertSame('referenz', $byname['kurspilot-core']['art']);
        $this->assertNotSame('', $byname['kurspilot-core']['ausloeser']);
    }

    /**
     * Die drei V1-Adapter (Spec 0020 §3.2) stehen als `art` = `adapter` im
     * Korpus; `kurspilot-einrichten` ist serverseitig entkernt und
     * existiert nicht mehr (die `spike-*`-Adapter fallen erst mit Issue
     * #453, spike-Praefix, weg).
     */
    public function test_lists_the_three_v1_adapters_and_not_einrichten(): void {
        $adapters = array_column(
            array_filter(skill_corpus::list(), static fn (array $entry): bool => $entry['art'] === 'adapter'),
            'name'
        );

        foreach (['kurspilot', 'kurspilot-planen', 'kurspilot-umsetzen'] as $expected) {
            $this->assertContains($expected, $adapters);
        }
        $this->assertNotContains('kurspilot-einrichten', $adapters);
    }

    /**
     * Das Verzeichnis ist die Quelle (Spec 0020 §3.1): eine neu abgelegte
     * Markdown-Datei erscheint in der Liste, ohne dass PHP geaendert wurde.
     */
    public function test_new_file_on_disk_appears_without_code_change(): void {
        global $CFG;

        $path = $CFG->dirroot . '/local/kurspilot/skills/referenz/zzz-testneuling.md';
        file_put_contents($path, "# Testneuling\n\nNur fuer diesen Test.\n");

        try {
            $names = array_column(skill_corpus::list(), 'name');
            $this->assertContains('zzz-testneuling', $names);
        } finally {
            unlink($path);
        }
    }

    /**
     * get() liefert Inhalt, referenzierte Teile (aus den Bestandspfaden
     * `skills/<name>.md` erkannt) und den Korpus-Stand.
     */
    public function test_get_returns_content_referenced_parts_and_corpus_stand(): void {
        global $CFG;

        $result = skill_corpus::get('kurspilot');

        $this->assertStringContainsString('kurspilot-core', $result['content']);
        $this->assertContains('kurspilot-core', $result['referenzierte_teile']);
        $this->assertContains('kontext-onboarding', $result['referenzierte_teile']);

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/kurspilot/version.php');
        $this->assertStringContainsString($plugin->release, $result['korpus_stand']);
        $this->assertStringContainsString((string) $plugin->version, $result['korpus_stand']);
    }

    /**
     * Unbekannter Name: die Meldung nennt die gueltigen Namen.
     */
    public function test_unknown_name_names_valid_names(): void {
        $this->expectException(\moodle_exception::class);
        try {
            skill_corpus::get('gibtsnicht');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('kurspilot-core', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Ein Name mit Pfadanteilen wird gleichermassen abgewiesen - geprueft
     * gegen die Verzeichnisliste, nicht per Zeichenfilter.
     *
     * @param string $name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('path_like_name_provider')]
    public function test_path_like_name_is_rejected(string $name): void {
        $this->expectException(\moodle_exception::class);
        skill_corpus::get($name);
    }

    /**
     * @return array<string, string[]>
     */
    public static function path_like_name_provider(): array {
        return [
            'dot-dot-slash' => ['../../../etc/passwd'],
            'leading-slash' => ['/etc/passwd'],
            'backslash' => ['..\\..\\kurspilot-core'],
            'encoded' => ['%2e%2e%2fkurspilot-core'],
            'suffixed-real-name' => ['kurspilot-core/../../../etc/passwd'],
        ];
    }

    /**
     * Jeder im Korpus genannte Werkzeugname muss in der Live-Werkzeugliste
     * vorkommen (Issue #459): ein Name aus dem lokalen Weg (`moodle_*`) oder
     * ein abgebautes Werkzeug faellt hier auf, statt erst im Abnahmelauf.
     */
    public function test_every_mentioned_tool_name_exists_in_tool_registry(): void {
        $validnames = tool_registry::allowed_tools();

        $mentioned = [];
        foreach (skill_corpus::list() as $entry) {
            $content = (string) file_get_contents($entry['path']);
            preg_match_all('/\b(?:kurspilot|moodle)_[a-z_]+\b/', $content, $matches);
            foreach ($matches[0] as $name) {
                $mentioned[$name][] = $entry['name'];
            }
        }

        foreach ($mentioned as $name => $files) {
            $this->assertArrayHasKey(
                $name,
                $validnames,
                "Werkzeugname '$name' (genannt in: " . implode(', ', array_unique($files))
                    . ") steht nicht in tool_registry::allowed_tools()."
            );
        }
    }

    /**
     * Und die Gegenrichtung (Issue #464): jedes Werkzeug aus der
     * Werkzeugliste muss im Korpus mindestens einmal vorkommen. Ein
     * Werkzeug, das der Korpus nicht nennt, existiert fuer ein Modell
     * nicht - es raet Name und Parameter und raet falsch.
     */
    public function test_every_registered_tool_is_mentioned_in_the_corpus(): void {
        $corpus = '';
        foreach (skill_corpus::list() as $entry) {
            $corpus .= (string) file_get_contents($entry['path']);
        }

        $missing = [];
        foreach (array_keys(tool_registry::allowed_tools()) as $name) {
            if (!str_contains($corpus, $name)) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Diese Werkzeuge kommen im Skill-Korpus nicht vor: ' . implode(', ', $missing)
        );
    }
}
