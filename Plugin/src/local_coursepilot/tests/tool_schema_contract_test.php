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

use core_external\external_function_parameters;
use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Vertrag: MCP-Schema und die von Moodle validierte Parameterbeschreibung
 * stammen aus genau derselben Deklaration.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(external_schema_converter::class)]
final class tool_schema_contract_test extends \advanced_testcase {

    public function test_tool_registration_contains_no_literal_descriptions_or_schema(): void {
        $registry = new \ReflectionClass(tool_registry::class);
        $tools = $registry->getReflectionConstant('TOOLS')->getValue();

        foreach ($tools as $name => $tool) {
            $this->assertSame(
                ['classname', 'descriptionkey'],
                array_keys($tool),
                "{$name}: Registrierung darf nur Klasse und Beschreibungsschluessel enthalten."
            );
        }

        foreach (tool_registry::descriptions() as $name => $description) {
            $this->assertNotSame('', $description, "{$name}: fehlende Sprachdatei-Beschreibung.");
        }
    }

    public function test_all_public_tool_surfaces_are_derived_from_the_registration(): void {
        $tools = tool_registry::allowed_tools();
        $descriptions = tool_registry::descriptions();
        $functions = tool_registry::service_functions();

        $this->assertSame($tools, privacy_surface::allowed_tools());
        $this->assertSame(array_values($tools), tool_registry::service_function_names());
        $this->assertSame(array_values($tools), array_keys($functions));

        foreach ($tools as $name => $function) {
            $this->assertSame($descriptions[$name], $functions[$function]['description']);
        }
    }

    public function test_converter_exposes_required_defaults_and_integer_types(): void {
        $parameters = new external_function_parameters([
            'requiredinteger' => new external_value(PARAM_INT, 'Required integer'),
            'optionalinteger' => new external_value(PARAM_INT, 'Optional integer', VALUE_DEFAULT, 7),
            'optionalstring' => new external_value(PARAM_TEXT, 'Optional string', VALUE_DEFAULT, 'default'),
        ]);

        $schema = external_schema_converter::from_parameters($parameters);

        $this->assertSame(['requiredinteger'], $schema['required']);
        $this->assertSame('integer', $schema['properties']['requiredinteger']['type']);
        $this->assertSame(7, $schema['properties']['optionalinteger']['default']);
        $this->assertSame('default', $schema['properties']['optionalstring']['default']);
    }

    public function test_every_registered_tool_schema_matches_moodle_parameters(): void {
        $schemas = tool_registry::schemas();
        $tools = tool_registry::allowed_tools();
        $functions = tool_registry::service_functions();

        $this->assertCount(49, $tools);
        $this->assertSame(array_keys($tools), array_keys($schemas));

        foreach ($tools as $name => $tool) {
            $classname = $functions[$tool]['classname'];
            $parameters = $classname::execute_parameters();

            $this->assertSame(
                external_schema_converter::from_parameters($parameters),
                $schemas[$name],
                "{$name}: MCP-Schema weicht von execute_parameters() ab."
            );
        }
    }

    /**
     * Der oeffentliche MCP-Vertrag ist englisch: dieselbe Begriffsmenge gilt
     * fuer Eingaben und alle verschachtelten Rueckgabefelder.
     */
    public function test_every_public_contract_key_is_english(): void {
        $forbidden = [
            'aenderungen', 'angelegt', 'angelegte_felder', 'art', 'ausloeser', 'ausstand',
            'ausstaende', 'bedeutung', 'bedingungen_json', 'bestaetigt', 'dateiname', 'eintraege', 'felder',
            'felder_json', 'fehlerklasse', 'feldbuendel', 'hinweis', 'hinweise', 'hinweis_luecken',
            'idnumber_nachgetragen',
            'kennung', 'kombinationsregeln', 'korpus_stand', 'kursid', 'meldung',
            'modul', 'nach', 'nach_version', 'nebenwirkungen', 'nur_anlegen',
            'ort', 'pfad', 'pflicht', 'pseudofelder', 'quelle', 'quelle_callable', 'quellcmid',
            'referenzierte_teile', 'schreibweg', 'sperrliste', 'umfang',
            'verstoesse', 'versionen', 'vorgefunden', 'vorgang', 'von', 'von_json', 'von_version', 'vorheriger_ort',
            'vollstaendig', 'wert_json', 'werte_json', 'wertebereich', 'zeitpunkt', 'zielversion',
        ];

        foreach (tool_registry::service_functions() as $tool) {
            $classname = $tool['classname'];
            $keys = array_merge(
                array_keys(tool_registry::schemas()[$this->tool_name_for($classname)]['properties']),
                array_keys(contract_keys::externalize(array_fill_keys(self::structure_keys($classname::execute_returns()), true)))
            );
            $this->assertSame(
                [],
                array_values(array_intersect($forbidden, $keys)),
                "{$classname}: German public contract key found."
            );
        }
    }

    /**
     * @return string[]
     */
    private static function structure_keys(external_description $structure): array {
        if ($structure instanceof external_multiple_structure) {
            return self::structure_keys($structure->content);
        }
        if (!$structure instanceof external_single_structure) {
            return [];
        }
        $keys = [];
        foreach ($structure->keys as $key => $description) {
            $keys[] = $key;
            if ($description instanceof external_single_structure) {
                $keys = array_merge($keys, self::structure_keys($description));
            } else if ($description instanceof external_multiple_structure
                && $description->content instanceof external_single_structure) {
                $keys = array_merge($keys, self::structure_keys($description->content));
            }
        }
        return $keys;
    }

    private function tool_name_for(string $classname): string {
        foreach (tool_registry::service_functions() as $name => $tool) {
            if ($tool['classname'] === $classname) {
                return array_search($name, tool_registry::allowed_tools(), true);
            }
        }
        $this->fail("No tool registered for {$classname}.");
    }
}
