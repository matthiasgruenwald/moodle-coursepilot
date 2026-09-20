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

/**
 * Der Altbestand (Issue #498, Spec #486 §9): die Kontextdateien, die nach
 * einem Ortswechsel noch am vorherigen Ort liegen. Betrifft nur den
 * Kontextbereich - der Materialbestand kennt keinen Altbestand (die alte
 * Materialwurzel in Moodle ist die Werkbank und bleibt).
 *
 * Kein eigener Speicherplatz: der vorherige Ort steht im Feld
 * `vorheriger_ort` des Kontextpointer-Dokuments ({@see storage_anchor::write_pointer_document()}),
 * geschrieben ausschliesslich von {@see location_selection::apply()} beim
 * Abschliessen. Es gibt immer nur einen - ein neuer Wechsel verdraengt ihn,
 * die Dateien des verdraengten Ortes bleiben unberuehrt liegen (Spec §9).
 *
 * Endet nur ausdruecklich, ueber {@see dismiss()} - nie durch Zeitablauf,
 * nie durch Namensgleichheit (dasselbe Prinzip wie {@see pending_write_notice}).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class previous_location {

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
     * Ob ein Altbestand offen ist - der Fakt fuer `coursepilot_list_skills`
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
            throw new \moodle_exception('altbestandclosed', 'local_coursepilot');
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
