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

namespace local_coursepilot\catalog;

/**
 * Die maschinell pruefbare Tiefenpruefung eines Katalogs gegen die laufende
 * Moodle-Instanz (Spec 0015 §11, ADR 0017, Ticket #399): Spalten (dazu/weg/
 * umbenannt), Existenz der aufrufbaren Quellen, Konstanten-Existenz.
 *
 * Dieselbe Logik wie die Repo-/Test-Vertragstests
 * (tests/catalog/*_contract_test.php, Vorbild privacy_surface_test.php) -
 * hier als wiederverwendbare Klasse, damit {@see \local_coursepilot\write_gate}
 * sie zur LAUFZEIT einsetzen kann (bei jedem Schreibvorgang bzw. bei
 * erkanntem Versionswechsel), statt die Pruefung ein zweites Mal zu
 * duplizieren.
 *
 * Was hier bewusst NICHT geprueft wird: abgeschriebene Wertelisten,
 * Kombinationsregeln, Nebenwirkungsvermerke - das ist der nicht maschinell
 * pruefbare Teil, der laut ADR 0017 ein manuelles Review je Major-Release
 * braucht ({@see module_catalog::reviewed_up_to_major()}).
 *
 * Rein: bekommt die Katalogklasse herein, greift nur lesend auf $DB-Metadaten
 * und PHP-Introspektion zu, schreibt nichts - Test und Laufzeit rufen
 * dieselbe Funktion (Bauform wie {@see \local_coursepilot\privacy_surface}).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class drift_check {

    /**
     * Die von Moodle-Kern nicht ohnehin bei jedem Request geladenen
     * Bibliotheken je Aktivitaetsart, die eine oder mehrere der von ihr
     * referenzierten aufrufbaren Quellen definieren (weblib.php/moodlelib.php
     * fehlen bewusst - lib/setup.php laedt sie bei jedem Request, siehe
     * Klassendoku). Dieselben Dateien wie in den jeweiligen
     * tests/catalog/*_contract_test.php - ohne sie waere function_exists()
     * hier ein falsches "existiert nicht mehr", weil die Datei schlicht noch
     * nicht eingebunden ist, nicht weil Moodle die Funktion entfernt hat.
     *
     * @var array<string, string[]> modname => Pfade mit Platzhaltern
     *      "{dirroot}"/"{libdir}".
     */
    private const REQUIRE_FILES = [
        'page' => ['{libdir}/resourcelib.php'],
        'url' => ['{libdir}/resourcelib.php', '{dirroot}/mod/url/locallib.php'],
        'folder' => ['{dirroot}/mod/folder/lib.php'],
        'resource' => ['{libdir}/resourcelib.php'],
        'choice' => [],
        'forum' => ['{dirroot}/mod/forum/lib.php', '{dirroot}/rating/lib.php'],
        'assign' => ['{dirroot}/mod/assign/locallib.php'],
        'quiz' => [
            '{dirroot}/mod/quiz/lib.php',
            '{dirroot}/mod/quiz/locallib.php',
            '{dirroot}/mod/quiz/classes/access_manager.php',
            '{dirroot}/question/engine/lib.php',
        ],
        'label' => [],
    ];

    /**
     * Alle Verstoesse einer Aktivitaetsart gegen ihren Katalog - leer, wenn
     * die Art gruen ist ("automatisch geprueft"/"geprueft").
     *
     * @param string $modname
     * @return string[] Deutsche Verstoss-Beschreibungen, leer wenn kein Drift.
     */
    public static function check(string $modname): array {
        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            return ["Unbekannte Aktivitätsart \"$modname\" - kein Katalog gefuehrt."];
        }

        return self::check_catalog($modname, $catalogclass);
    }

    /**
     * Wie {@see check()}, aber mit der Katalogklasse direkt statt ueber
     * {@see registry::for()} aufgeloest - testbar mit einer absichtlich
     * abweichenden Katalogklasse, ohne die Registry zu veraendern.
     *
     * @param string $modname Tabellen-/Modulname, gegen den geprueft wird.
     * @param class-string<module_catalog> $catalogclass
     * @return string[]
     */
    public static function check_catalog(string $modname, string $catalogclass): array {
        self::require_known_libraries($modname);

        return array_merge(
            self::column_violations($modname, $catalogclass),
            self::callable_violations($catalogclass),
            self::constant_violations($catalogclass),
            self::write_option_violations($catalogclass)
        );
    }

    /**
     * @param string $modname
     * @return void
     */
    private static function require_known_libraries(string $modname): void {
        global $CFG;

        foreach (self::REQUIRE_FILES[$modname] ?? [] as $path) {
            $resolved = str_replace(['{dirroot}', '{libdir}'], [$CFG->dirroot, $CFG->libdir], $path);
            if (is_readable($resolved)) {
                require_once($resolved);
            }
        }
    }

    /**
     * Spaltenabgleich: fields()+blocklist()+shared_block-Sperrliste (soweit in
     * dieser Tabelle vorhanden) plus "id" muss die reale Spaltenmenge der
     * gleichnamigen Tabelle exakt ergeben. Sperrlisten-Eintraege, die
     * zugleich Pseudofeld sind (z.B. folder::pseudofields() "files", bis
     * Spec 0018 gesperrt), sind keine echten Spalten und zaehlen hier nicht
     * mit - derselbe Ausschluss wie in
     * folder_catalog_contract_test/resource_catalog_contract_test.
     *
     * @param string $modname
     * @param class-string<module_catalog> $catalogclass
     * @return string[]
     */
    private static function column_violations(string $modname, string $catalogclass): array {
        global $DB;

        $realcolumns = array_keys($DB->get_columns($modname));
        sort($realcolumns);

        $pseudofieldnames = array_map(static fn (field $f): string => $f->name, $catalogclass::pseudofields());
        $blockedrealcolumns = array_diff($catalogclass::blocklist(), $pseudofieldnames);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, $catalogclass::fields()),
            $blockedrealcolumns,
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        $known = array_values(array_unique($known));
        sort($known);

        if ($known === $realcolumns) {
            return [];
        }

        $added = array_values(array_diff($realcolumns, $known));
        $removed = array_values(array_diff($known, $realcolumns));
        $detail = [];
        if ($added) {
            $detail[] = 'neu in der Tabelle, im Katalog unbekannt: ' . implode(', ', $added);
        }
        if ($removed) {
            $detail[] = 'im Katalog gefuehrt, in der Tabelle nicht mehr vorhanden: ' . implode(', ', $removed);
        }
        return ['Spalten der Tabelle "' . $modname . '" weichen vom Katalog ab (' . implode('; ', $detail) . ').'];
    }

    /**
     * Existenz der von Feldern und Pseudofeldern referenzierten aufrufbaren
     * Quellen - Funktionen wie statische Klassenmethoden.
     *
     * @param class-string<module_catalog> $catalogclass
     * @return string[]
     */
    private static function callable_violations(string $catalogclass): array {
        $fields = array_merge(
            shared_block::fields(),
            shared_block::pseudofields(),
            $catalogclass::fields(),
            $catalogclass::pseudofields()
        );

        $callables = array_unique(array_filter(array_map(
            static fn (field $f): ?string => $f->sourcecallable,
            $fields
        )));

        $violations = [];
        foreach ($callables as $callable) {
            $bare = rtrim($callable, '()');
            $exists = str_contains($bare, '::')
                ? method_exists(...explode('::', $bare, 2))
                : function_exists($bare);
            if (!$exists) {
                $violations[] = "Aufrufbare Quelle \"$callable\" existiert auf dieser Instanz nicht mehr.";
            }
        }
        return $violations;
    }

    /**
     * Existenz der von Katalog und gemeinsamem Block referenzierten
     * Konstanten ({@see module_catalog::checked_constants()},
     * {@see shared_block::checked_constants()}).
     *
     * @param class-string<module_catalog> $catalogclass
     * @return string[]
     */
    private static function constant_violations(string $catalogclass): array {
        $constants = array_unique(array_merge(
            shared_block::checked_constants(),
            $catalogclass::checked_constants()
        ));

        $violations = [];
        foreach ($constants as $constname) {
            if (!defined($constname)) {
                $violations[] = "Konstante \"$constname\" existiert auf dieser Instanz nicht mehr.";
            }
        }
        return $violations;
    }

    /**
     * Jede feldbezogene Schreiboption muss ein Katalogfeld benennen. Damit
     * faellt auch ein neu gelesener oder geschriebener Feldname auf, der am
     * Katalog vorbei in einen Werkzeug-Sonderfall geriete.
     *
     * @param class-string<module_catalog> $catalogclass
     * @return string[]
     */
    private static function write_option_violations(string $catalogclass): array {
        $known = array_column(array_merge(
            shared_block::fields(),
            $catalogclass::fields(),
            $catalogclass::pseudofields()
        ), 'name');
        $options = $catalogclass::write_options();
        $referenced = array_merge(
            array_keys($options['material_reference_fields'] ?? []),
            array_keys($options['missing_form_values'] ?? []),
            array_keys($options['admin_default_fields'] ?? []),
            array_keys($options['scalar_to_repeated'] ?? []),
            array_values($options['scalar_to_repeated'] ?? []),
            array_keys($options['editor_content'] ?? []),
            array_merge(...array_values($options['editor_content'] ?? [[]])),
            $options['patch_blocked_fields'] ?? [],
            isset($options['intro_image_field']) ? [$options['intro_image_field']] : []
        );
        foreach (array_merge($options['parallel_array_lengths'] ?? [], $options['date_order_rules'] ?? []) as $rule) {
            $referenced[] = $rule['reference'];
            $referenced[] = $rule['field'];
        }
        $referenced = array_merge($referenced, array_keys($options['side_effect_triggers'] ?? []));
        foreach ($options['repeated_group'] ?? [] as $spec) {
            $referenced = array_merge($referenced, array_keys($spec['fields'] ?? []));
        }
        $unknown = array_values(array_diff(array_unique($referenced), $known));
        return array_map(
            static fn(string $field): string => 'Feld "' . $field . '" wird ausserhalb des Katalogs referenziert.',
            $unknown
        );
    }
}
