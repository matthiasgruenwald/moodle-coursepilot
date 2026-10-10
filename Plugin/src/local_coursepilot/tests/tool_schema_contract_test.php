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
 * Contract: MCP schemas and Moodle parameter validation derive from the same declaration.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(external_schema_converter::class)]
#[CoversClass(\local_coursepilot\tool_registry::class)]
final class tool_schema_contract_test extends \advanced_testcase {
    public function test_tool_registration_contains_no_literal_descriptions_or_schema(): void {
        $registry = new \ReflectionClass(tool_registry::class);
        $tools = $registry->getReflectionConstant('TOOLS')->getValue();

        foreach ($tools as $name => $tool) {
            $this->assertSame(
                ['classname', 'descriptionkey'],
                array_keys($tool),
                "{$name}: Registration may only contain the class and description key."
            );
        }

        foreach (tool_registry::descriptions() as $name => $description) {
            $this->assertNotSame('', $description, "{$name}: Missing language-pack description.");
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

        $this->assertCount(55, $tools);
        $this->assertSame(array_keys($tools), array_keys($schemas));

        foreach ($tools as $name => $tool) {
            $classname = $functions[$tool]['classname'];
            $parameters = $classname::execute_parameters();

            $this->assertSame(
                external_schema_converter::from_parameters($parameters),
                $schemas[$name],
                "{$name}: MCP schema differs from execute_parameters()."
            );
        }
    }

    /**
     * Moodle passes validated named inputs positionally, in declaration order.
     */
    public function test_registered_external_parameters_match_execute_positions(): void {
        foreach (tool_registry::service_function_names() as $name) {
            $function = \core_external\external_api::external_function_info($name);
            $method = new \ReflectionMethod($function->classname, $function->methodname);
            $arguments = $method->getParameters();
            $declarations = $function->parameters_desc->keys;
            $this->assertCount(count($arguments), $declarations, $name);
            foreach (array_values($declarations) as $position => $declaration) {
                $key = array_keys($declarations)[$position];
                $argument = $arguments[$position];
                $this->assertSame(
                    str_replace('_', '', $key),
                    str_replace('_', '', strtolower($argument->getName())),
                    "{$name}: parameter {$key} does not match execute position {$position}"
                );
                if ($declaration->required === VALUE_DEFAULT && $argument->isDefaultValueAvailable()) {
                    $this->assertSame($declaration->default, $argument->getDefaultValue(), "{$name}: {$key} default");
                }
            }
        }
    }

    /**
     * The public MCP contract uses English keys for inputs and nested return fields.
     *
     * #573: after removal of contract_keys, the raw execute_returns() keys ARE
     * the public contract. The old test mistakenly passed expected keys through
     * the translation it was testing: externalize() would turn "meldung" into
     * "message" before checking. Unmigrated keys such as get_version_info()
     * "datum", absent from EXTERNAL, could therefore escape detection.
     */
    public function test_every_public_contract_key_is_english(): void {
        $forbidden = [
            'aenderungen', 'angelegt', 'angelegte_felder', 'art', 'ausloeser', 'ausstand',
            'ausstaende', 'bedeutung', 'bedingungen_json', 'bestaetigt', 'dateiname', 'datum', 'eintraege', 'felder',
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
                self::structure_keys($classname::execute_returns())
            );
            $this->assertSame(
                [],
                array_values(array_intersect($forbidden, $keys)),
                "{$classname}: German public contract key found."
            );
        }
    }

    /**
     * The published input and output descriptions must use English (#605).
     */
    public function test_every_public_contract_description_is_english(): void {
        $forbidden = '/[äöüÄÖÜß]|\\b(?:der|die|das|und|oder|nicht|fuer|für|wird|werden|eine|einer|eines|einem|einen|zum|zur|mit|'
            . 'ohne|Kurs|Lehrkraft|Altbestand)\\b/u';
        foreach (tool_registry::descriptions() as $name => $description) {
            $this->assertDoesNotMatchRegularExpression($forbidden, $description, $name);
        }
        foreach (tool_registry::schemas() as $name => $schema) {
            $this->assert_english_schema_descriptions($schema, $forbidden, $name);
        }
        foreach (tool_registry::service_functions() as $name => $tool) {
            $classname = $tool['classname'];
            $this->assert_english_return_descriptions($classname::execute_returns(), $forbidden, $name);
        }
        $this->assertDoesNotMatchRegularExpression($forbidden, dispatcher::HANDSHAKE_INSTRUCTIONS);
    }

    /**
     * Field catalog descriptions also reach the model as tool result data (#605).
     */
    public function test_every_catalog_description_is_english(): void {
        $forbidden = '/[äöüÄÖÜß]|\\b(?:der|die|das|und|oder|nicht|fuer|für|wird|werden|eine|einer|eines|einem|einen|zum|zur|mit|'
            . 'ohne|Kurs|Lehrkraft|Altbestand)\\b/u';
        foreach (\local_coursepilot\catalog\registry::known_modnames() as $modname) {
            $catalog = \local_coursepilot\catalog\registry::for($modname);
            $fields = array_merge(
                $catalog::fields(),
                $catalog::pseudofields(),
                \local_coursepilot\catalog\shared_block::fields(),
                \local_coursepilot\catalog\shared_block::pseudofields()
            );
            foreach ($fields as $field) {
                foreach ([$field->type, $field->meaning, $field->source] as $text) {
                    $this->assertDoesNotMatchRegularExpression($forbidden, $text, "{$modname}: {$field->name}");
                }
            }
            foreach (
                array_merge(
                    $catalog::combination_rules(),
                    $catalog::side_effects(),
                    \local_coursepilot\catalog\shared_block::side_effects()
                ) as $text
            ) {
                $this->assertDoesNotMatchRegularExpression($forbidden, $text, $modname);
            }
        }
    }

    /**
     * Asserts english schema descriptions.
     *
     * @param array $schema The schema.
     * @param string $forbidden The forbidden.
     * @param string $name The name.
     */
    private function assert_english_schema_descriptions(array $schema, string $forbidden, string $name): void {
        foreach ($schema as $key => $value) {
            if ($key === 'description') {
                $this->assertDoesNotMatchRegularExpression($forbidden, $value, $name);
            } else if (is_array($value)) {
                $this->assert_english_schema_descriptions($value, $forbidden, $name);
            }
        }
    }

    /**
     * Asserts english return descriptions.
     *
     * @param external_description $description The description.
     * @param string $forbidden The forbidden.
     * @param string $name The name.
     */
    private function assert_english_return_descriptions(external_description $description, string $forbidden, string $name): void {
        $this->assertDoesNotMatchRegularExpression($forbidden, $description->desc, $name);
        if ($description instanceof external_single_structure) {
            foreach ($description->keys as $child) {
                $this->assert_english_return_descriptions($child, $forbidden, $name);
            }
        } else if ($description instanceof external_multiple_structure) {
            $this->assert_english_return_descriptions($description->content, $forbidden, $name);
        }
    }

    /**
     * #602: tool names also use English.
     */
    public function test_every_tool_name_is_english(): void {
        $tools = tool_registry::allowed_tools();
        foreach ($tools as $mcpname => $functionname) {
            $this->assertDoesNotMatchRegularExpression('/altbestand|ausstand|werkbank|ortswahl/', $mcpname . ' ' . $functionname);
        }
        $this->assertArrayHasKey('coursepilot_dismiss_previous_location', $tools);
        $this->assertArrayHasKey('coursepilot_dismiss_pending_entry', $tools);
        $this->assertArrayHasKey('coursepilot_create_workbench_download_links', $tools);
    }

    /**
     * Provides structure keys.
     *
     * @param external_description $structure The structure.
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
            } else if (
                $description instanceof external_multiple_structure
                && $description->content instanceof external_single_structure
            ) {
                $keys = array_merge($keys, self::structure_keys($description->content));
            }
        }
        return $keys;
    }

    /**
     * Provides tool name for.
     *
     * @param string $classname The classname.
     * @return string
     */
    private function tool_name_for(string $classname): string {
        foreach (tool_registry::service_functions() as $name => $tool) {
            if ($tool['classname'] === $classname) {
                return array_search($name, tool_registry::allowed_tools(), true);
            }
        }
        $this->fail("No tool registered for {$classname}.");
    }
}
