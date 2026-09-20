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

use local_coursepilot\tests\webdav\webdav_instance_fixture;

require_once(__DIR__ . '/../ortswahl_render.php');

/**
 * Escaping der Kontextbereich-/Materialbestand-Anzeige (Issue #511,
 * Sicherheitsbefund HIGH aus dem Review von #486): ein Ordner- oder
 * Instanzname aus dem Speicher der Lehrkraft - auch aus einer fremd
 * geteilten Freigabe - erschien bisher als rohes Markup auf der
 * Ortswahlseite und auf "Meine Verbindungen". Getestet ueber
 * {@see local_coursepilot_current_locations_list_items()}, die sich beide
 * Seiten teilen (nie ueber die Seite selbst, gleiche Konvention wie
 * {@see \local_coursepilot\ortswahl_lib_test}).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class ortswahl_render_test extends advanced_testcase {
    use webdav_instance_fixture;

    /**
     * Issue #511, Akzeptanzkriterium 2: ein Ordnername/Instanzname wie
     * `<img src=x onerror=alert(1)>` darf im erzeugten Markup nicht als
     * ausfuehrbares HTML ankommen.
     */
    public function test_prepared_instance_name_is_escaped_in_current_locations_markup(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);

        $payload = '<img src=x onerror=alert(1)>';
        $DB->set_field('repository_instances', 'name', $payload, ['id' => $instanceid]);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Kontext');

        $html = local_coursepilot_current_locations_list_items();

        // Kein ausfuehrbares Markup: kein rohes '<' mehr im Ergebnis - der
        // Name kommt nur noch als escapeter Text vor (Issue #511 AK2).
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<', str_replace(['<li>', '</li>'], '', $html));
        $this->assertStringContainsString('&lt;img', $html);
    }

    /**
     * Gegenprobe ohne praeparierten Namen: ein gewoehnlicher Instanzname
     * bleibt lesbar (kein Doppel-Escaping, kein kaputtes Markup).
     */
    public function test_plain_instance_name_stays_readable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Kontext');

        $html = local_coursepilot_current_locations_list_items();

        $this->assertStringContainsString('Meine Cloud', $html);
    }

    /**
     * Issue #563: der Ruecksprung aus dem OAuth-Verbindungsaufbau in die
     * Ortswahlseite braucht die Anfrageparameter als verstecktes Formularfeld
     * mit, sonst geht der Rueckweg beim Abschliessen verloren (dieselbe
     * Sackgasse wie in Issue #558, nur diesmal von der anderen Seite her).
     */
    public function test_editor_form_carries_oauth_passthrough_as_hidden_fields(): void {
        global $PAGE;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->create_webdav_instance($user);

        ob_start();
        local_coursepilot_render_ortswahl_editor($PAGE, $user, [
            'response_type' => 'code',
            'client_id' => 'claude-test',
            'redirect_uri' => 'https://example.test/callback',
            'code_challenge' => 'abc123',
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
        ]);
        $html = ob_get_clean();

        $this->assertStringContainsString('name="oauthflow" value="1"', $html);
        $this->assertStringContainsString('name="client_id" value="claude-test"', $html);
        $this->assertStringContainsString('name="redirect_uri" value="https://example.test/callback"', $html);
    }

    /**
     * Gegenprobe: ausserhalb des OAuth-Verbindungsaufbaus (leeres
     * Passthrough-Array, der bisherige und weiterhin gueltige eigenstaendige
     * Aufruf der Ortswahlseite) taucht kein oauthflow-Feld auf.
     */
    public function test_editor_form_omits_oauth_fields_outside_oauth_flow(): void {
        global $PAGE;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->create_webdav_instance($user);

        ob_start();
        local_coursepilot_render_ortswahl_editor($PAGE, $user);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('oauthflow', $html);
    }
}
