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

namespace local_coursepilot\history;

defined('MOODLE_INTERNAL') || die();

/**
 * Aufbewahrung/Loeschfrist des Aenderungsverlaufs (#387, Spec 0015 §10.7):
 * "Kurs weg, Verlauf weg", "Aktivitaet geloescht, Verlauf mitgeloescht" und
 * eine admin-seitige Loeschfrist (Standard 1 Jahr) ohne periodisch alles
 * scannenden Scheduled Task.
 *
 * Die Loeschfrist-Bereinigung ist deshalb opportunistisch: sie laeuft an
 * {@see version_writer::capture()} mit, begrenzt auf die gerade beschriebene
 * cmid (indizierter Query ueber cmid_timecreated, kein Tabellen-Scan). Eine
 * cmid, die nie wieder beschrieben wird, behaelt ihre angesammelten alten
 * Staende dauerhaft - das Speicherwachstum ist trotzdem begrenzt, weil ohne
 * weitere Schreibvorgaenge auch keine weiteren Staende mehr entstehen.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class retention {

    /** @var int Voreinstellung in Tagen (Spec 0015 §10.7). */
    public const DEFAULT_DAYS = 365;

    /** @var int Untergrenze: "keine Frist" ist ausgeschlossen (Spec 0015 §10.7). */
    public const MIN_DAYS = 1;

    /**
     * Die konfigurierte Loeschfrist in Tagen, gegen einen rohen/manipulierten
     * Config-Wert (0, negativ, leer) auf mindestens einen Tag geklemmt - so
     * bleibt "keine Frist" auch dann ausgeschlossen, wenn der gespeicherte
     * Wert selbst ungueltig waere.
     *
     * @return int
     */
    public static function days(): int {
        $configured = (int) get_config('local_coursepilot', 'historyretentiondays');
        return max(self::MIN_DAYS, $configured);
    }

    /**
     * Loescht Staende einer cmid, die aelter sind als die konfigurierte Frist -
     * ausgeloest vom naechsten Schreibvorgang derselben cmid, nicht von einem
     * Cron.
     *
     * @param int $cmid
     * @return void
     */
    public static function purge_expired_for_cm(int $cmid): void {
        global $DB;

        $cutoff = time() - self::days() * DAYSECS;
        $versionids = $DB->get_fieldset_select(
            'local_coursepilot_cm_version',
            'id',
            'cmid = ? AND timecreated < ?',
            [$cmid, $cutoff]
        );
        self::delete_versions($versionids);
    }

    /**
     * Loescht den gesamten Verlauf einer Aktivitaet - Aktivitaets-Kaskade
     * (course_module_deleted, #387). Unbedingt, unabhaengig von der Frist.
     *
     * @param int $cmid
     * @return void
     */
    public static function purge_cm(int $cmid): void {
        global $DB;

        $versionids = $DB->get_fieldset_select('local_coursepilot_cm_version', 'id', 'cmid = ?', [$cmid]);
        self::delete_versions($versionids);
    }

    /**
     * Loescht den gesamten Verlauf eines Kurses - Kurs-Kaskade (course_deleted,
     * #387). Unbedingt, unabhaengig von der Frist.
     *
     * course_modules ist zu diesem Zeitpunkt bereits geloescht (Moodle-Core
     * loescht die Kursinhalte vor dem Ereignis) - die Zuordnung muss deshalb
     * ueber die in {@see version_writer::capture()} mitgeschriebene courseid
     * laufen, nicht ueber einen Join gegen course_modules.
     *
     * @param int $courseid
     * @return void
     */
    public static function purge_course(int $courseid): void {
        global $DB;

        $versionids = $DB->get_fieldset_select('local_coursepilot_cm_version', 'id', 'courseid = ?', [$courseid]);
        self::delete_versions($versionids);
    }

    /**
     * Loescht die gegebenen Staende samt ihrer Datei-Verknuepfungen.
     *
     * local_coursepilot_cm_file (dedupliziertes Datei-Metadaten) bleibt dabei
     * unangetastet - moegliche Waisen sind reine, sehr kompakte Metadaten ohne
     * Dateiinhalt, eine Referenzzaehlung dafuer lohnt sich nicht.
     * ponytail: Waisen in local_coursepilot_cm_file werden nicht aufgeraeumt -
     * bei Bedarf (z.B. sichtbares Datenwachstum) per Referenzzaehlung nachruesten.
     *
     * @param array $versionids
     * @return void
     */
    private static function delete_versions(array $versionids): void {
        global $DB;

        if (!$versionids) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_values($versionids));
        $DB->delete_records_select('local_coursepilot_cm_version_file', "versionid $insql", $inparams);
        $DB->delete_records_select('local_coursepilot_cm_version', "id $insql", $inparams);
    }
}
