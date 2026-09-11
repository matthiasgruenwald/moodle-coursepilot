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
 * Das Markierungsgedaechtnis (Issue #493, Spec #486 §6 "Markierungsgedaechtnis
 * (Latenz)"): eine Auflistung braeuchte ohne dieses Gedaechtnis 1 + N Zugriffe,
 * um die Personenbezugs-Markierung jeder `.md`-Datei zu kennen - extern
 * zusaetzlich gedrosselt. Gemerkt wird deshalb nur das eine Bit "markiert
 * ja/nein" je Datei, in Moodle, ohne Netz.
 *
 * Der Schluessel ist Pfad, Groesse, Aenderungszeit und ETag (wo vorhanden) -
 * alles bereits aus dem einen PROPFIND/Verzeichniseintrag der Auflistung
 * bekannt, kein zusaetzlicher Zugriff noetig, um den Schluessel zu bilden.
 * Passt der gespeicherte Schluessel nicht mehr zum aktuellen Eintrag, gilt
 * das Gedaechtnis als leer fuer diese Datei - {@see lookup()} liefert dann
 * `null`, der Aufrufer liest die Datei neu und traegt das Ergebnis ueber
 * {@see remember()} nach. Es wird **nie** Inhalt gespeichert, nur das Bit -
 * ein verlorenes Gedaechtnis kostet also nur Zeit, nie Richtigkeit
 * ({@see \local_kurspilot\external\read_context_file} prueft den Inhalt
 * ohnehin bei jedem Lesen selbst noch einmal).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mark_memory {

    /** @var string Tabellenname, siehe db/install.xml. */
    private const TABLE = 'local_kurspilot_context_mark';

    /**
     * Das gemerkte Bit fuer eine Datei, wenn der Schluessel noch passt.
     *
     * @param string $path Client-Pfad der Datei, relativ zum Kontextbereich.
     * @param int $size
     * @param int $timemodified
     * @param string|null $etag
     * @return bool|null null, wenn nichts gemerkt ist oder der Schluessel
     *         nicht mehr passt (Datei hat sich geaendert) - der Aufrufer
     *         liest dann selbst nach.
     */
    public static function lookup(string $path, int $size, int $timemodified, ?string $etag): ?bool {
        global $DB, $USER;

        if (empty($USER->id)) {
            return null;
        }

        $record = $DB->get_record(self::TABLE, ['userid' => (int) $USER->id, 'pathhash' => sha1($path)]);
        if (!$record) {
            return null;
        }
        if ((int) $record->filesize !== $size
                || (int) $record->timemodified !== $timemodified
                || (string) $record->etag !== (string) ($etag ?? '')) {
            return null;
        }
        return (bool) $record->ismarked;
    }

    /**
     * Merkt sich das Bit fuer eine Datei - legt den Eintrag an oder ersetzt
     * ihn, je nachdem, ob schon einer existiert.
     *
     * @param string $path
     * @param int $size
     * @param int $timemodified
     * @param string|null $etag
     * @param bool $marked
     */
    public static function remember(string $path, int $size, int $timemodified, ?string $etag, bool $marked): void {
        global $DB, $USER;

        if (empty($USER->id)) {
            return;
        }

        $pathhash = sha1($path);
        $data = (object) [
            'userid' => (int) $USER->id,
            'path' => $path,
            'pathhash' => $pathhash,
            'filesize' => $size,
            'timemodified' => $timemodified,
            'etag' => $etag,
            'ismarked' => $marked ? 1 : 0,
        ];

        $existingid = $DB->get_field(self::TABLE, 'id', ['userid' => (int) $USER->id, 'pathhash' => $pathhash]);
        if ($existingid) {
            $data->id = $existingid;
            $DB->update_record(self::TABLE, $data);
        } else {
            $DB->insert_record(self::TABLE, $data);
        }
    }
}
