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

namespace local_kurspilot\tests\webdav;

/**
 * Testvorbereitung fuer eine gueltige WebDAV-Nutzerinstanz (Issue #490, Spec
 * #486 §2/§3/§12) - Core-Tabellen (`repository`, `repository_instances`,
 * `repository_instance_config`), die drei Freischaltungsschritte und ein
 * passender Kontextpointer der zweiten Fassung. Wiederverwendet von
 * {@see \local_kurspilot\webdav\webdav_instance_test} und den externen
 * Endpunkttests, damit keine der beiden Stellen ihre eigene Vorstellung
 * von "eine gueltige Instanz" entwickelt.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait webdav_instance_fixture {

    /** @var string Server der Standard-Testinstanz, fuer das Pruefmerkmal. */
    protected string $fixtureserver = 'cloud.example.test';

    /** @var string Basispfad der Standard-Testinstanz. */
    protected string $fixturebasispfad = 'Kurspilot';

    /** @var string Konto der Standard-Testinstanz. */
    protected string $fixturekonto = 'lehrerin';

    /**
     * Schaltet den Repository-Typ "webdav" aktiv und erlaubt Nutzerinstanzen
     * (Spec §12, Schritt 1+2) - `enableuserinstances` steht am Config-Plugin
     * "webdav", wie von der Auflösung gelesen.
     */
    protected function enable_webdav_repository_type(): void {
        global $DB;

        if (!$DB->record_exists('repository', ['type' => 'webdav'])) {
            $DB->insert_record('repository', (object) ['type' => 'webdav', 'visible' => 1, 'sortorder' => 1]);
        } else {
            $DB->set_field('repository', 'visible', 1, ['type' => 'webdav']);
        }
        set_config('enableuserinstances', 1, 'webdav');
    }

    /**
     * Weist einer Person `repository/webdav:view` im Systemkontext zu -
     * wirkt damit in jedem Nutzerkontext (Spec §12, empfohlener Weg: eigene
     * Systemrolle).
     *
     * @param \stdClass $user
     */
    protected function grant_webdav_capability(\stdClass $user): void {
        global $DB;

        $shortname = 'kurspilotwebdavtest';
        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
        if (!$roleid) {
            $roleid = create_role('Kurspilot WebDAV Test', $shortname, '');
        }
        assign_capability('repository/webdav:view', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Legt eine WebDAV-Nutzerinstanz an, die der uebergebenen Person gehoert
     * (`contextid` = ihr eigener Nutzerkontext, `repository_webdav` als Typ).
     *
     * @param \stdClass $user
     * @param array<string, string|int> $overrides Ueberschreibt einzelne Instanzoptionen,
     *        z.B. ['webdav_auth' => 'digest'] fuer den Auth-Randfall.
     * @return int Instanz-ID.
     */
    protected function create_webdav_instance(\stdClass $user, array $overrides = []): int {
        global $DB;

        $this->enable_webdav_repository_type();
        $typeid = $DB->get_field('repository', 'id', ['type' => 'webdav'], MUST_EXIST);
        $contextid = \context_user::instance($user->id)->id;

        $instanceid = $DB->insert_record('repository_instances', (object) [
            'name' => 'Meine Cloud',
            'typeid' => $typeid,
            'userid' => 0,
            'contextid' => $contextid,
            'timecreated' => time(),
            'timemodified' => time(),
            'readonly' => 0,
        ]);

        $options = array_merge([
            'webdav_type' => 1,
            'webdav_server' => $this->fixtureserver,
            'webdav_port' => '',
            'webdav_path' => $this->fixturebasispfad,
            'webdav_user' => $this->fixturekonto,
            'webdav_password' => 'g3h31m-nie-sichtbar',
            'webdav_auth' => 'basic',
        ], $overrides);

        foreach ($options as $name => $value) {
            $DB->insert_record('repository_instance_config', (object) [
                'instanceid' => $instanceid,
                'name' => $name,
                'value' => (string) $value,
            ]);
        }

        return (int) $instanceid;
    }

    /**
     * Das Pruefmerkmal der Standard-Testinstanz, wie es ein Pointer der
     * zweiten Fassung trueg.
     *
     * @return array{server: string, basispfad: string, konto: string}
     */
    protected function fixture_fingerprint(): array {
        return ['server' => $this->fixtureserver, 'basispfad' => $this->fixturebasispfad, 'konto' => $this->fixturekonto];
    }

    /**
     * Schreibt einen Kontextpointer der zweiten Fassung (Spec §2) in den
     * Anker der Person - ein Ziel extern, das andere per Default *in
     * Moodle*.
     *
     * @param \stdClass $user
     * @param string $externtarget "kontextbereich" oder "materialbestand".
     * @param int $instanceid
     * @param string $relativepath
     * @param array|null $fingerprint Default: {@see fixture_fingerprint()}.
     */
    protected function write_v2_pointer(
        \stdClass $user,
        string $externtarget,
        int $instanceid,
        string $relativepath,
        ?array $fingerprint = null
    ): void {
        $external = [
            'ort' => 'extern',
            'instanzid' => $instanceid,
            'pfad' => $relativepath,
            'pruefmerkmal' => $fingerprint ?? $this->fixture_fingerprint(),
        ];
        $inmoodle = ['ort' => 'moodle', 'pfad' => 'kurspilot'];

        $pointer = [
            'kontextbereich' => $externtarget === 'kontextbereich' ? $external : $inmoodle,
            'materialbestand' => $externtarget === 'materialbestand' ? $external : ['ort' => 'moodle', 'pfad' => 'kurspilot-material'],
        ];

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot/',
            'filename' => '.kurspilot-ort.json',
        ], json_encode($pointer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Rundum-Vorbereitung fuer den externen Kontextbereich-Zweig der
     * Kontextwerkzeug-Endpunkttests: legt eine gueltige, freigeschaltete
     * WebDAV-Nutzerinstanz an, richtet den Kontextbereich per Pointer der
     * zweiten Fassung auf den Ordner "Kontext" darin aus und injiziert den
     * Transport-Fake. Gemeinsam genutzt von `list_context_files_test` und
     * `read_context_file_test`, damit keine der beiden Stellen ihre eigene
     * Vorstellung von "ein gueltiger externer Kontextbereich" entwickelt.
     *
     * @return array{0: \stdClass, 1: fake_webdav_transport}
     */
    protected function set_up_external_context(): array {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Kontext');

        $fake = new fake_webdav_transport();
        \local_kurspilot\webdav\webdav_instance::use_test_transport($fake);

        return [$user, $fake];
    }

    /**
     * Gegenstueck zu {@see set_up_external_context()} fuer den externen
     * Materialbestand (Issue #495): richtet den Materialbestand per Pointer
     * der zweiten Fassung auf den Ordner "Material" aus, der Kontextbereich
     * bleibt *in Moodle*.
     *
     * @return array{0: \stdClass, 1: fake_webdav_transport}
     */
    protected function set_up_external_material(): array {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'materialbestand', $instanceid, 'Material');

        $fake = new fake_webdav_transport();
        \local_kurspilot\webdav\webdav_instance::use_test_transport($fake);

        return [$user, $fake];
    }
}
