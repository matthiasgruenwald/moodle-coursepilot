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
 * Vertrag einer Aktivitätsart im Feldkatalog (Spec 0015 §2). Eine
 * implementierende Klasse je Modultyp unter \local_coursepilot\catalog\.
 *
 * Der modulübergreifende Block (Sichtbarkeit, Stealth, Gruppenmodus,
 * Gruppierung, idnumber, Abschnittszuordnung, {@see shared_block}) ist
 * bewusst NICHT Teil dieses Interface - er steht einmal und wird von
 * describe_module_fields für jede Art zusätzlich eingeblendet (Spec 0015
 * §2.3). Eine Implementierung darf ihn nicht duplizieren.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
interface module_catalog {

    /**
     * Die Moodle-Hauptversion (Branch-Nummer), bis zu der der Katalogbestand
     * zuletzt insgesamt manuell durchgesehen wurde (ADR 0017, Ticket #399) -
     * eine Quelle statt neun einzelner Literale, die sonst bei jedem
     * gemeinsamen Review-Durchgang einzeln nachgezogen werden muessten.
     * {@see reviewed_up_to_major()} greift hierauf zurueck; eine einzelne
     * Katalogklasse kann bei Bedarf einen abweichenden (frueheren) Wert
     * zurueckgeben, statt diese Konstante zu nutzen.
     */
    public const LAST_JOINT_REVIEW_MAJOR = 500;

    /**
     * Moodle-Modulname (mod_XXX ohne Praefix), z.B. "label".
     *
     * @return string
     */
    public static function modname(): string;

    /**
     * Kategorie 1: echte Instanz-Datenbankfelder.
     *
     * @return field[]
     */
    public static function fields(): array;

    /**
     * Wirksamer, lehrkraftlesbarer Zustand einer Instanz fuer die
     * Katalogansicht. Die Form bleibt fuer alle Modultypen gleich.
     *
     * @param int $instanceid
     * @param int $cmid
     * @param bool $fullcontent
     * @return array{name: string, content: array, settings: array, quizslots: array}
     */
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array;

    /**
     * Schreibspezifische Ausnahmen des Modultyps. Die generischen Werkzeuge
     * interpretieren nur diese Deklaration; sie kennen keine Modultypen.
     *
     * @return array<string, mixed>
     */
    public static function write_options(): array;

    /**
     * Namen der haeufig gesetzten Felder aus {@see fields()} fuer die Kurzform
     * von describe_module_fields (Spec 0015 §3.1, Ticket #382). Bei wenigen
     * Feldern (label, choice, forum, ...) ist "alle" bereits die Kurzform -
     * dann schlicht alle Feldnamen zurueckgeben. Erst bei sehr vielen Feldern
     * (assign: ~30) lohnt eine echte Teilmenge.
     *
     * @return string[]
     */
    public static function common_field_names(): array;

    /**
     * Kategorie 2: Nicht-DB-Felder, die die *_instance()-Funktionen
     * ungeschuetzt lesen (Spec 0015 §2.2).
     *
     * @return field[]
     */
    public static function pseudofields(): array;

    /**
     * Kategorie 3: modulspezifische Sperrliste - zusaetzlich zur
     * durchgaengigen Sperrliste aus {@see shared_block::BLOCKLIST}, die
     * describe_module_fields fuer jede Art anhaengt.
     *
     * @return string[] Feldnamen.
     */
    public static function blocklist(): array;

    /**
     * Kategorie 4: Kombinationsregeln - Beziehungen zwischen Feldern, die nur
     * in Moodles validation() stehen (Spec 0015 §2.2).
     *
     * @return string[] Je Regel ein deutscher Satz.
     */
    public static function combination_rules(): array;

    /**
     * Kategorie 5: Nebenwirkungsvermerke - Felder mit Wirkung ueber die
     * Aktivitaet hinaus (Spec 0015 §2.2).
     *
     * @return string[] Je Vermerk ein deutscher Satz.
     */
    public static function side_effects(): array;

    /**
     * Feldbuendel (Presets): Name => Feld=>Wert-Vorbelegung. Ueberstimmt
     * keine von der Lehrkraft ausdruecklich genannten Felder (Spec 0015
     * §2.4). Leer, wenn die Aktivitaetsart keine Buendel hat.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function bundles(): array;

    /**
     * Schreibweg: null, wenn ueber das Vehikel (update_moduleinfo()) - sonst
     * der Name des Einzelwerkzeugs, das stattdessen schreibt (Spec 0015
     * §3.1, z.B. "update_quiz_settings").
     *
     * @return string|null
     */
    public static function schreibweg(): ?string;

    /**
     * Konstanten ohne aufrufbare Wertemenge, die dieser Katalog voraussetzt
     * (Spec 0015 §11, Ticket #382/#399, "Konstanten-Existenz" aus dem
     * maschinell pruefbaren Teil der Tiefenpruefung, ADR 0017). Leer, wenn
     * der Katalog keine solchen Konstanten referenziert.
     *
     * Dieselbe Liste speist sowohl den Repo-Vertragstest
     * (tests/catalog/*_contract_test.php) als auch die Laufzeit-Tiefenpruefung
     * ({@see \local_coursepilot\catalog\drift_check}) - eine Quelle statt zweier
     * auseinanderlaufender Kopien.
     *
     * @return string[]
     */
    public static function checked_constants(): array;

    /**
     * Bis zu welcher Moodle-Hauptversion (Branch-Nummer, z.B. 500 fuer
     * Moodle 5.0) dieser Katalog manuell durchgesehen wurde - deckt den nicht
     * maschinell pruefbaren Teil ab: abgeschriebene Wertelisten,
     * Kombinationsregeln, Nebenwirkungsvermerke (ADR 0017, Ticket #399).
     *
     * Muss bei jedem neuen Major-Release, das der Katalog tatsaechlich
     * durchgesehen wurde, von Hand erhoeht werden - das ist das "manuelle
     * Review je Major-Release" aus dem Ticket. Bis dahin bleibt eine neuere,
     * nur automatisch (maschinell) gepruefte Hauptversion im Status
     * "automatisch geprueft" statt "geprueft".
     *
     * @return int
     */
    public static function reviewed_up_to_major(): int;
}
