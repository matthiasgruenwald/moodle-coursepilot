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
 * Papierkorb fuer ersetzte Aktivitaetsdateien (Spec 0018 §9.1, Issue #432).
 *
 * Moodle-Core loescht beim Ersetzen einer Aktivitaetsdatei (z.B. ueber
 * {@see \local_coursepilot\external\update_module_settings}'s
 * MATERIAL_REFERENCE_PSEUDOFIELDS-Weg) den alten `files`-Datensatz tief in
 * lib/filelib.php::file_save_draft_area_files() - eine Stelle, die dieses
 * Plugin nicht abfangen kann. Der einzig verlaessliche Hebel ist deshalb,
 * VOR dem eigentlichen Schreibaufruf eine zweite Kopie des Datensatzes
 * anzulegen: derselbe `contenthash`, ein eigener Dateibereich. Moodles
 * Dateipool ist contenthash-basiert - die Kopie kostet 0 Byte zusaetzlichen
 * Speicher, haelt aber den physischen Inhalt am Leben, selbst wenn der
 * Original-Datensatz geloescht wird (Spec 0018 §9.1).
 *
 * Kein eigenes DB-Schema: der Papierkorb IST ein gewoehnlicher `files`-
 * Datensatz unter einer eigenen Komponente/Filearea, geordnet nach der
 * cmid der Aktivitaet, aus der die Datei stammt. Keine Aufbewahrungsfrist -
 * anders als der Materialordner-Papierkorb (§9.2) gibt es hier keine Quote,
 * die drueckt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class activity_file_trash {

    /** @var string Eigene Komponente - kein Herunterladeweg, nur interne Ablage. */
    public const COMPONENT = 'local_coursepilot';

    /** @var string Alleiniger Papierkorb-Dateibereich. */
    public const FILEAREA = 'activityfiletrash';

    /**
     * Verdraengt eine Aktivitaetsdatei in den Papierkorb, statt sie beim
     * bevorstehenden Ersetzen verloren gehen zu lassen - aufzurufen VOR dem
     * eigentlichen Schreibvorgang (update_moduleinfo()), solange die Datei
     * noch existiert. Still, wenn genau diese Kopie (Pfad ist an
     * pathnamehash+contenthash gebunden) schon im Papierkorb liegt.
     *
     * @param \stored_file $file Die noch vorhandene Original-Aktivitaetsdatei.
     * @param int $cmid Course-Module-ID der Aktivitaet, aus der die Datei stammt.
     * @return void
     */
    public static function trash(\stored_file $file, int $cmid): void {
        $fs = get_file_storage();
        $filepath = '/' . $cmid . '/' . $file->get_pathnamehash() . '/';

        if ($fs->file_exists(
            $file->get_contextid(),
            self::COMPONENT,
            self::FILEAREA,
            $cmid,
            $filepath,
            $file->get_filename()
        )) {
            // Dieselbe Verdraengung schon einmal ausgefuehrt (z.B. zweiter
            // Schreibversuch nach einem Fehlschlag) - nichts weiter zu tun.
            return;
        }

        $fs->create_file_from_storedfile([
            'contextid' => $file->get_contextid(),
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $cmid,
            'filepath' => $filepath,
            'filename' => $file->get_filename(),
        ], $file);
    }

    /**
     * Sucht die juengste Papierkorb-Kopie einer Aktivitaetsdatei fuer den
     * Rollback ueber den Aenderungsverlauf (ADR 0018) - Treffer nur bei
     * identischem Dateinamen UND `contenthash` (derselbe Inhalt, nicht nur
     * derselbe Name).
     *
     * @param int $contextid Modulkontext der Aktivitaet.
     * @param int $cmid
     * @param string $filename
     * @param string $contenthash
     * @return \stored_file|null
     */
    public static function find_for_restore(int $contextid, int $cmid, string $filename, string $contenthash): ?\stored_file {
        global $DB;

        $records = $DB->get_records_select(
            'files',
            'contextid = ? AND component = ? AND filearea = ? AND itemid = ? AND filename = ? AND contenthash = ?',
            [$contextid, self::COMPONENT, self::FILEAREA, $cmid, $filename, $contenthash],
            'timecreated DESC',
            '*',
            0,
            1
        );
        if (!$records) {
            return null;
        }

        return get_file_storage()->get_file_instance(reset($records));
    }

    /**
     * Baut einen Dateimanager-Entwurf aus bereits vorhandenen {@see \stored_file}s
     * (statt Materialordner-Pfaden wie {@see material_files::resolve_into_draft()}) -
     * fuer den Rollback-Pfad, der Dateien aus dem Papierkorb statt aus dem
     * Materialordner zurueckschreibt. Vorbelegt mit den derzeit an der
     * Aktivitaet haengenden Dateien (file_prepare_draft_area), damit ein
     * Rollback nur die betroffenen Dateien ersetzt, alle anderen
     * unangetastet laesst.
     *
     * @param int $targetcontextid Modulkontext der Aktivitaet.
     * @param string $component
     * @param string $filearea
     * @param \stored_file[] $files Zu setzende Dateien, je Dateiname eine.
     * @return int Entwurfs-Itemid, direkt als *_update_instance()-Feldwert nutzbar.
     */
    public static function resolve_restore_into_draft(
        int $targetcontextid,
        string $component,
        string $filearea,
        array $files
    ): int {
        global $USER;

        $fs = get_file_storage();
        $draftitemid = 0;
        file_prepare_draft_area($draftitemid, $targetcontextid, $component, $filearea, 0);

        $usercontext = \context_user::instance($USER->id);
        foreach ($files as $source) {
            $filename = $source->get_filename();
            $existing = $fs->get_file($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename);
            if ($existing) {
                $existing->delete();
            }
            $fs->create_file_from_storedfile([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $source);
        }

        return $draftitemid;
    }
}
