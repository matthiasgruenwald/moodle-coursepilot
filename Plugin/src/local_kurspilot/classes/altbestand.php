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

/**
 * Der Altbestand (Issue #498, Spec #486 §9): die Kontextdateien, die nach
 * einem Ortswechsel noch am vorherigen Ort liegen. Betrifft nur den
 * Kontextbereich - der Materialbestand kennt keinen Altbestand (die alte
 * Materialwurzel in Moodle ist die Werkbank und bleibt).
 *
 * Kein eigener Speicherplatz: der vorherige Ort steht im Feld
 * `vorheriger_ort` des Kontextpointer-Dokuments ({@see storage_anchor::write_pointer_document()}),
 * geschrieben ausschliesslich von {@see ortswahl_lib::apply()} beim
 * Abschliessen. Es gibt immer nur einen - ein neuer Wechsel verdraengt ihn,
 * die Dateien des verdraengten Ortes bleiben unberuehrt liegen (Spec §9).
 *
 * Endet nur ausdruecklich, ueber {@see dismiss()} - nie durch Zeitablauf,
 * nie durch Namensgleichheit (dasselbe Prinzip wie {@see ausstand_notice}).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class altbestand {

    /**
     * Der rohe Wert des Feldes "vorheriger_ort" im Kontextpointer-Dokument,
     * oder null, wenn kein Altbestand offen ist (kein Pointer, Pointer der
     * ersten Fassung, oder das Feld fehlt/ist ungueltig).
     *
     * @return array|null
     */
    public static function current(): ?array {
        $document = storage_anchor::read_raw_pointer();
        $value = $document['vorheriger_ort'] ?? null;
        return is_array($value) ? $value : null;
    }

    /**
     * Ob ein Altbestand offen ist - der Fakt fuer `kurspilot_list_skills`
     * (Issue #498 Akzeptanzkriterium: "ohne Zaehlung").
     *
     * @return bool
     */
    public static function open(): bool {
        return self::current() !== null;
    }

    /**
     * Der aufgeloeste vorherige Ort, fuer den Nur-Lese-Schalter an
     * `list_context_files`/`read_context_file` (Spec §6/§9). Wirkt nur,
     * solange Altbestand offen ist - ohne offenen Altbestand ein benannter
     * Fehler statt eines stillen leeren Ergebnisses.
     *
     * @return pointer_location
     * @throws \moodle_exception altbestandclosed, oder wie {@see context_pointer::resolve_previous()}.
     */
    public static function require_open_location(): pointer_location {
        $value = self::current();
        if ($value === null) {
            throw new \moodle_exception('altbestandclosed', 'local_kurspilot');
        }
        return context_pointer::resolve_previous($value);
    }

    /**
     * Beendet den Altbestand ausdruecklich (Spec §9: "Ende nur ausdruecklich") -
     * entfernt nur das Feld "vorheriger_ort" aus dem Pointer-Dokument, laesst
     * Kontextbereich, Materialbestand und Ortsverlauf unveraendert. Ruehrt
     * nie an den Dateien des vorherigen Ortes selbst (Spec §9: "seine Dateien
     * bleiben unberuehrt liegen").
     *
     * @return bool true, wenn ein offener Altbestand entfernt wurde; false,
     *         wenn keiner offen war.
     */
    public static function dismiss(): bool {
        $document = storage_anchor::read_raw_pointer();
        if ($document === null || ($document['vorheriger_ort'] ?? null) === null) {
            return false;
        }
        unset($document['vorheriger_ort']);
        storage_anchor::write_pointer_document($document);
        return true;
    }
}
