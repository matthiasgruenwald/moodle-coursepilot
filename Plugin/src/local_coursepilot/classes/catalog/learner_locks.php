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

use core_external\external_multiple_structure;
use core_external\external_value;
use moodle_exception;

/**
 * Auswertung der Riegel aus dem Feldkatalog (Issue #583). Ein Riegel ist eine
 * Einstellung, nach der eine lernende Person eine Handlung der Lehrkraft
 * braucht, um weiterzuarbeiten oder nachzubessern.
 *
 * Die Katalogklassen deklarieren je Feld eine strukturierte Bedingung
 * ({@see module_catalog::learner_locks()}); die Schreibwerkzeuge werten die
 * effektiv zu schreibenden Werte hier aus und lehnen einen Aufruf mit Riegel
 * ab, solange der Aufruf ihn nicht ausdruecklich in "confirm_learner_locks"
 * bestaetigt. Eine Quelle statt einer Liste im Skill-Korpus.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class learner_locks {

    /** Erlaubte Operatoren einer Riegel-Bedingung. */
    public const OPS = ['equals', 'not_equals', 'greater', 'nonzero'];

    /** Die Note entsteht durch eine Handlung der Lehrkraft. */
    public const GRADE_TEACHER = 'teacher';

    /** Die Note entsteht automatisch (z.B. Test mit automatisch bewerteten Fragen). */
    public const GRADE_AUTOMATIC = 'automatic';

    /** Die Aktivitaet hat keine Note. */
    public const GRADE_NONE = 'none';

    /** @var string[] */
    public const GRADE_ORIGINS = [self::GRADE_TEACHER, self::GRADE_AUTOMATIC, self::GRADE_NONE];

    /**
     * Name des Werkzeugparameters, mit dem ein Aufruf Riegel bestaetigt.
     */
    public const PARAMETER = 'confirm_learner_locks';

    /**
     * Gemeinsamer Werkzeugparameter der Schreibwerkzeuge.
     *
     * @return external_multiple_structure
     */
    public static function confirm_parameter(): external_multiple_structure {
        return new external_multiple_structure(
            new external_value(PARAM_TEXT, 'Lock id as named in a rejection: a field name, or "teacher_grade:<cmid>"'),
            'Learner locks this call sets on purpose. A learner lock is a setting after which a learner needs an '
                . 'action by the teacher to continue or resubmit. A call that would set an unconfirmed lock is '
                . 'rejected with each lock and its reason, and nothing is written. Name a lock here only when the '
                . 'teacher explicitly asked for it; otherwise leave the field out or choose an open value.',
            VALUE_DEFAULT,
            []
        );
    }

    /**
     * Trifft $value die Bedingung? Ein fehlender Wert (null) ist nie ein
     * Riegel - ein nicht gesetztes Feld kann niemanden aufhalten.
     *
     * @param array{op: string, value?: mixed} $condition
     * @param mixed $value
     * @return bool
     */
    public static function matches(array $condition, $value): bool {
        if ($value === null) {
            return false;
        }
        switch ($condition['op']) {
            case 'equals':
                return self::same($value, $condition['value']);
            case 'not_equals':
                return !self::same($value, $condition['value']);
            case 'greater':
                return is_numeric($value) && (float) $value > (float) $condition['value'];
            case 'nonzero':
                return is_numeric($value) && (float) $value != 0;
        }
        return false;
    }

    /**
     * Zahlen numerisch, alles andere als Zeichenkette vergleichen - JSON und
     * Datenbank liefern dieselbe 1 mal als int, mal als "1", mal als true.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    private static function same($a, $b): bool {
        $a = is_bool($a) ? (int) $a : $a;
        $b = is_bool($b) ? (int) $b : $b;
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return (string) $a === (string) $b;
    }

    /**
     * Alle Riegel, die die zu schreibenden Werte ausloesen.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array $named Vom Aufruf (samt Feldbuendel) genannte Felder.
     * @param array $defaults Aufgefuellte Formular-Defaults - nur fuer Felder,
     *        die $named nicht nennt.
     * @return array<int, array{id: string, detail: string}>
     */
    public static function find(string $catalogclass, array $named, array $defaults = []): array {
        $found = [];
        foreach ($catalogclass::learner_locks() as $fieldname => $condition) {
            $fromdefault = !array_key_exists($fieldname, $named);
            $value = $fromdefault ? ($defaults[$fieldname] ?? null) : $named[$fieldname];
            if (!self::matches($condition, $value)) {
                continue;
            }
            $found[] = [
                'id' => $fieldname,
                'detail' => '"' . $fieldname . '" = ' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . ($fromdefault ? ' (form default, not named in this call)' : '')
                    . ': ' . $condition['reason'],
            ];
        }
        return $found;
    }

    /**
     * Wie {@see find()}, aber fuer einen Patch auf bestehende Werte: ein Feld,
     * dessen Wert der Patch nur wiederholt, braucht keine erneute
     * Bestaetigung - der Riegel besteht schon und wird nicht neu gesetzt.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array $patch
     * @param array $before
     * @return array<int, array{id: string, detail: string}>
     */
    public static function find_changed(string $catalogclass, array $patch, array $before): array {
        $changed = $patch;
        foreach (array_keys($catalogclass::learner_locks()) as $fieldname) {
            $old = $before[$fieldname] ?? null;
            $new = $patch[$fieldname] ?? null;
            if (is_scalar($old) && is_scalar($new) && self::same($new, $old)) {
                unset($changed[$fieldname]);
            }
        }
        return self::find($catalogclass, $changed);
    }

    /**
     * Bestehende Riegel einer Instanz, fuer die Lesewerkzeuge.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array $settings Ist-Stand wie get_module_settings (Datenbankspalten);
     *        write_options()['settings_aliases'] bildet abweichende
     *        Formularnamen auf die Spalte ab (quiz: quizpassword -> password).
     * @return array<int, array{field: string, value_json: string, reason: string}>
     */
    public static function existing(string $catalogclass, array $settings): array {
        $result = [];
        $aliases = $catalogclass::write_options()['settings_aliases'] ?? [];
        foreach ($catalogclass::learner_locks() as $fieldname => $condition) {
            $value = $settings[$fieldname] ?? $settings[$aliases[$fieldname] ?? ''] ?? null;
            if (self::matches($condition, $value)) {
                $result[] = [
                    'field' => $fieldname,
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'reason' => $condition['reason'],
                ];
            }
        }
        return $result;
    }

    /**
     * Die Riegel-Bedingung eines Felds als JSON fuer describe_module_fields,
     * "null", wenn das Feld kein Riegel sein kann.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param string $fieldname
     * @return string
     */
    public static function condition_json(string $catalogclass, string $fieldname): string {
        $condition = $catalogclass::learner_locks()[$fieldname] ?? null;
        return json_encode($condition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Ein ausdruecklich gewaehlter Modus (Werkzeugparameter "mode", z.B.
     * quiz "abschlusstest") bestaetigt die Riegel, die er selbst mitbringt -
     * die Wahl des Modus ist die Entscheidung der Lehrkraft fuer seine
     * Einstellungen. Ein Wert, den der Aufruf selbst ueberschreibt, bleibt
     * bestaetigungspflichtig.
     *
     * @param string[] $confirmed Ausdruecklich bestaetigte Riegel.
     * @param array $bundle Feldwerte des gewaehlten Modus.
     * @param array $named Vom Aufruf selbst genannte Felder.
     * @return string[]
     */
    public static function confirmed_with_mode(array $confirmed, array $bundle, array $named): array {
        return array_values(array_unique(array_merge($confirmed, array_keys(array_diff_key($bundle, $named)))));
    }

    /**
     * Lehnt ab, solange ein gefundener Riegel nicht bestaetigt ist. Die
     * Meldung nennt jeden offenen Riegel mit Grund, damit der Agent ohne
     * weiteres Nachschlagen entscheiden kann. Nichts wird geschrieben.
     *
     * @param string $modname
     * @param array<int, array{id: string, detail: string}> $found
     * @param string[] $confirmed
     * @return void
     * @throws moodle_exception learnerlocksunconfirmed
     */
    public static function assert_confirmed(string $modname, array $found, array $confirmed): void {
        $open = array_values(array_filter($found, static fn(array $lock): bool => !in_array($lock['id'], $confirmed, true)));
        if (!$open) {
            return;
        }
        throw new moodle_exception('learnerlocksunconfirmed', 'local_coursepilot', '', [
            'modname' => $modname,
            'locks' => implode(' | ', array_column($open, 'detail')),
            'ids' => json_encode(array_column($open, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'parameter' => self::PARAMETER,
        ]);
    }
}
