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
 * Lesende Oberflaeche des Aenderungsverlaufs (#394, Spec 0015 §10.6):
 * list_activity_versions (Einzeiler je Version gegenueber dem Vorgaenger) und
 * compare_activity_versions (volles Diff zweier frei gewaehlter Staende).
 * Beide berechnen serverseitig aus den Vollstaenden, die
 * {@see version_writer} anlegt - es gibt keine gespeicherte Diff-Kette.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class version_history {

    /**
     * Fester Hinweis auf die strukturellen Luecken des Verlaufs (Spec 0015
     * §10.2, Issue #394): wird nicht pro Version berechnet, sondern immer
     * unveraendert mitgeliefert - "die Luecke ist erkennbar, nicht
     * schliessbar".
     */
    private const GAPS_HINT = 'Der Verlauf ist nicht lückenlos: Quiz-Inhalt jenseits der Anordnung, das '
        . 'Notenbuch, eine Wiederherstellung eines ganzen Kurses aus dem Papierkorb (Restore) und direkte '
        . 'Datenbankschreibungen werden nicht erfasst. Ersetzte Aktivitätsdateien in freigeschalteten Feldern '
        . '(z. B. Anhänge) sind davon ausgenommen und werden bei einer Rückkehr zu einem alten Stand '
        . 'mitgeholt. Bei erschlossenen Aktivitätsarten (z. B. Buch, Glossar, Lektion) erfasst der Verlauf '
        . 'nur die Instanzzeile, nicht die Kindtabellen (Kapitel, Einträge, Seiten, Punkte). '
        . 'Die Lücke ist erkennbar, aber nicht schließbar.';

    /**
     * Alle Versionen einer Aktivitaet, aufsteigend, mit je einem Einzeiler
     * gegenueber dem Vorgaenger (Spec 0015 §10.6, Abnahmekriterium 1+2).
     *
     * @param int $cmid
     * @return array{cmid: int, modname: string, versions: array, gap_notice: string}
     */
    public static function list_versions(int $cmid): array {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $records = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cmid], 'version ASC'));

        $rows = [];
        $previous = null;
        foreach ($records as $record) {
            $rows[] = self::describe_version($record, $previous);
            $previous = $record;
        }

        return [
            'cmid' => $cmid,
            'modname' => (string) $cm->modname,
            'versions' => $rows,
            'gap_notice' => self::GAPS_HINT,
        ];
    }

    /**
     * Volles Diff zweier frei gewaehlter Staende, nicht nur benachbarter
     * (Spec 0015 §10.6, Abnahmekriterium 3).
     *
     * @param int $cmid
     * @param int $fromversion
     * @param int $toversion
     * @return array
     * @throws \moodle_exception versionnotfound
     */
    public static function compare(int $cmid, int $fromversion, int $toversion): array {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $fromstate = self::load_version($cmid, $fromversion);
        $tostate = self::load_version($cmid, $toversion);

        return [
            'cmid' => $cmid,
            'modname' => (string) $cm->modname,
            'before' => self::describe_meta($fromstate),
            'after' => self::describe_meta($tostate),
            'changes' => self::diff_fields(self::public_state($fromstate), self::public_state($tostate)),
            'files' => self::diff_files((int) $fromstate->id, (int) $tostate->id),
            'gap_notice' => self::GAPS_HINT,
        ];
    }

    /**
     * Voller Zielzustand einer Version als diffbares Array - dieselbe
     * Zusammenfuehrung (moduleinfo_json ueber coursemodule_json) wie fuer
     * compare(), oeffentlich fuer {@see \local_coursepilot\external\restore_activity_version}
     * (#395: "kein eigener Schreibmechanismus" - restore baut daraus einen
     * Patch fuer update_module_settings/set_completion, genau wie compare()
     * daraus ein Diff baut).
     *
     * @param int $cmid
     * @param int $version
     * @return array
     * @throws \moodle_exception versionnotfound
     */
    public static function state_at(int $cmid, int $version): array {
        return self::state(self::load_version($cmid, $version));
    }

    /**
     * Datei-Metadaten eines Standes - fuer den Dateiwiederherstellungsschritt
     * von {@see \local_coursepilot\external\restore_activity_version} (Spec
     * 0018 §9.1, Issue #432): welche Dateien (component/filearea/filename/
     * contenthash) gehoerten zu diesem Stand, und ist die jeweilige Zeile
     * rueckschreibbar (gap=0, siehe {@see version_writer::capture_files()})?
     *
     * @param int $cmid
     * @param int $version
     * @return \stdClass[] Je Zeile: component, filearea, filename, contenthash, gap.
     * @throws \moodle_exception versionnotfound
     */
    public static function files_at(int $cmid, int $version): array {
        $record = self::load_version($cmid, $version);
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        return self::allowed_files((int) $record->id, (string) $cm->modname);
    }

    /**
     * Read old rows through the same allowlist used by capture; also used by the privacy export.
     *
     * @param int $versionid
     * @param string $modname
     * @return \stdClass[] Per row: id, fileid, component, filearea, filename, contenthash, gap.
     */
    public static function allowed_files(int $versionid, string $modname): array {
        global $DB;
        $files = $DB->get_records_sql(
            'SELECT vf.id, vf.fileid, f.component, f.filearea, f.filename, f.contenthash, vf.gap
               FROM {local_coursepilot_cm_version_file} vf
               JOIN {local_coursepilot_cm_file} f ON f.id = vf.fileid
              WHERE vf.versionid = ?',
            [$versionid]
        );
        return array_values(array_filter($files, static fn(\stdClass $file): bool =>
            file_policy::allows($modname, $file->component, $file->filearea)));
    }

    /**
     * Anordnungs-Stand (#396, Spec 0015 §10) eines Standes - null fuer
     * Nicht-quiz-Aktivitaeten und fuer Staende, die vor #396 angelegt wurden
     * (keine arrangement_json mitgeschrieben). Oeffentlich fuer
     * {@see \local_coursepilot\external\restore_activity_version}, analog zu
     * {@see self::state_at()}.
     *
     * @param int $cmid
     * @param int $version
     * @return array|null
     * @throws \moodle_exception versionnotfound
     */
    public static function arrangement_at(int $cmid, int $version): ?array {
        $record = self::load_version($cmid, $version);
        if ($record->arrangement_json === null) {
            return null;
        }
        return json_decode($record->arrangement_json, true);
    }

    /**
     * Aktivitaeten eines Kurses, die mindestens einen erfassten Verlaufs-
     * Stand haben - Grundlage der Aktivitaetenliste auf history.php (#397).
     * Reine Existenzabfrage ueber die eigene Tabelle, gefolgt vom normalen
     * Moodle-Weg fuer die Aktivitaetsdaten (get_fast_modinfo) - damit bleibt
     * die Speicherung des Verlaufs von der Oberflaeche getrennt (Spec 0015
     * §10.6, Abnahmekriterium 7).
     *
     * @param int $courseid
     * @return array<int, array{cmid: int, name: string, modname: string}>
     */
    public static function course_activities(int $courseid): array {
        global $DB;

        $cmids = array_keys($DB->get_records_sql(
            'SELECT DISTINCT cmid FROM {local_coursepilot_cm_version} WHERE courseid = ?',
            [$courseid]
        ));
        if (!$cmids) {
            return [];
        }

        $modinfo = get_fast_modinfo($courseid);
        $activities = [];
        foreach ($cmids as $cmid) {
            try {
                $cm = $modinfo->get_cm($cmid);
            } catch (\moodle_exception $e) {
                // Aktivitaet zwischenzeitlich geloescht - Verlaufszeilen bleiben,
                // aber es gibt nichts mehr anzuzeigen (#387 Kurs-Kaskade greift
                // nur beim ganzen Kurs, nicht bei Einzelaktivitaeten).
                continue;
            }
            $activities[] = ['cmid' => (int) $cmid, 'name' => $cm->name, 'modname' => $cm->modname];
        }
        usort($activities, static fn(array $a, array $b): int => $a['name'] <=> $b['name']);
        return $activities;
    }

    /**
     * @param int $cmid
     * @param int $version
     * @return \stdClass
     * @throws \moodle_exception versionnotfound
     */
    private static function load_version(int $cmid, int $version): \stdClass {
        global $DB;

        $record = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $cmid, 'version' => $version]);
        if (!$record) {
            throw new \moodle_exception('versionnotfound', 'local_coursepilot', '', [
                'cmid' => $cmid,
                'version' => $version,
            ]);
        }
        return $record;
    }

    /**
     * @param \stdClass $record
     * @param \stdClass|null $previous
     * @return array
     */
    private static function describe_version(\stdClass $record, ?\stdClass $previous): array {
        $meta = self::describe_meta($record);
        $meta['summary_line'] = self::summary_line($previous, $record, $meta);
        return $meta;
    }

    /**
     * Metadaten eines Standes ohne Einzeiler - Grundlage sowohl fuer
     * list_versions als auch fuer die before/after-Bloecke von compare().
     *
     * @param \stdClass $record
     * @return array{version: int, source: string, discovered: bool, source_cmid: int|null, userid: int, user: string, timestamp: int}
     */
    private static function describe_meta(\stdClass $record): array {
        $source = version_source::from_record($record);
        return [
            'version' => (int) $record->version,
            'source' => $source->key,
            'discovered' => $source->is_discovered(),
            'source_cmid' => $source->refcmid,
            'userid' => (int) $record->userid,
            'user' => self::fullname((int) $record->userid),
            'timestamp' => (int) $record->timecreated,
        ];
    }

    /**
     * @param \stdClass|null $previous
     * @param \stdClass $record
     * @param array $meta
     * @return string
     */
    private static function summary_line(?\stdClass $previous, \stdClass $record, array $meta): string {
        $timetext = userdate($meta['timestamp']);

        $source = version_source::from_record($record);
        if ($previous === null || $source->is_marker()) {
            return sprintf('Version %d (%s) - %s, %s.', $meta['version'], $source->label(), $meta['user'], $timetext);
        }

        $summary = self::summarize_change($previous, $record);
        return sprintf('Version %d - %s, %s: %s.', $meta['version'], $meta['user'], $timetext, $summary);
    }

    /**
     * Lehrkraft-deutsche Kurzfassung dessen, was sich gegenueber dem
     * Vorgaenger geaendert hat - Felder und Dateien.
     *
     * @param \stdClass $before
     * @param \stdClass $after
     * @return string
     */
    private static function summarize_change(\stdClass $before, \stdClass $after): string {
        $fields = self::changed_fields(self::public_state($before), self::public_state($after));
        $filechanges = self::diff_files((int) $before->id, (int) $after->id);

        $parts = [];
        if ($fields) {
            $parts[] = (count($fields) > 4
                ? implode(', ', array_slice($fields, 0, 4)) . ' und ' . (count($fields) - 4) . ' weitere Felder'
                : implode(', ', $fields)) . ' geändert';
        }

        $added = count(array_filter($filechanges, static fn(array $c): bool => $c['change_type'] === 'added'));
        $removed = count($filechanges) - $added;
        if ($added) {
            $parts[] = $added . ' Datei' . ($added === 1 ? '' : 'en') . ' hinzugefügt';
        }
        if ($removed) {
            $parts[] = $removed . ' Datei' . ($removed === 1 ? '' : 'en') . ' entfernt';
        }

        return $parts ? implode(', ', $parts) : 'keine inhaltliche Änderung erkennbar';
    }

    /**
     * moduleinfo_json und coursemodule_json zu einem diffbaren Zustand
     * zusammengefuehrt - bei ueberlappenden Feldern gewinnt moduleinfo_json,
     * die reichhaltigere Quelle (Tags, availability, Instanzfelder).
     *
     * @param \stdClass $record
     * @return array
     */
    private static function state(\stdClass $record): array {
        $coursemodule = json_decode($record->coursemodule_json, true) ?: [];
        $moduleinfo = json_decode($record->moduleinfo_json, true) ?: [];
        return array_merge($coursemodule, $moduleinfo);
    }

    /** Safe comparison projection; raw state_at remains exclusively for native restoration. */
    private static function public_state(\stdClass $record): array {
        $state = self::state($record);
        foreach (['availability', 'availabilityconditionsjson'] as $field) {
            if (array_key_exists($field, $state)) {
                $state[$field] = \local_coursepilot\availability_privacy::sanitize((string) ($state[$field] ?? ''));
            }
        }
        return $state;
    }

    /**
     * Feldnamen, deren Wert sich zwischen $before und $after unterscheidet -
     * lose verglichen (wie {@see \local_coursepilot\external\update_module_settings::diff_and_side_effects()}),
     * damit gleichwertige, aber unterschiedlich kodierte Werte nicht faelschlich
     * als Aenderung erscheinen.
     *
     * @param array $before
     * @param array $after
     * @return string[] sortiert
     */
    private static function changed_fields(array $before, array $after): array {
        $fields = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            if (($before[$field] ?? null) != ($after[$field] ?? null)) {
                $fields[] = $field;
            }
        }
        sort($fields);
        return $fields;
    }

    /**
     * Vollstaendiges Feld-Diff mit Vorher-/Nachher-Wert je geaendertem Feld,
     * fuer compare_activity_versions.
     *
     * @param array $before
     * @param array $after
     * @return array
     */
    private static function diff_fields(array $before, array $after): array {
        $changes = [];
        foreach (self::changed_fields($before, $after) as $field) {
            $changes[] = [
                'field' => $field,
                'before_json' => json_encode($before[$field] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'after_json' => json_encode($after[$field] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        return $changes;
    }

    /**
     * Datei-id => Dateiname der zu einem Stand gehoerenden Dateien.
     *
     * @param int $versionid
     * @return array<int, string>
     */
    private static function file_map(int $versionid): array {
        global $DB;

        $cmid = $DB->get_field('local_coursepilot_cm_version', 'cmid', ['id' => $versionid], MUST_EXIST);
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $map = [];
        foreach (self::allowed_files($versionid, (string) $cm->modname) as $file) {
            $map[$file->fileid] = $file->filename;
        }
        return $map;
    }

    /**
     * Dateien, die zwischen zwei Staenden hinzugekommen bzw. weggefallen
     * sind - eine inhaltlich geaenderte Datei am gleichen Pfad erscheint als
     * ein Entfernen und ein Hinzufuegen (dedupliziert ueber den contenthash,
     * siehe {@see version_writer::dedup_file()}).
     *
     * @param int $beforeversionid
     * @param int $afterversionid
     * @return array
     */
    private static function diff_files(int $beforeversionid, int $afterversionid): array {
        $before = self::file_map($beforeversionid);
        $after = self::file_map($afterversionid);

        $changes = [];
        foreach ($before as $fileid => $filename) {
            if (!array_key_exists($fileid, $after)) {
                $changes[] = ['change_type' => 'removed', 'filename' => $filename];
            }
        }
        foreach ($after as $fileid => $filename) {
            if (!array_key_exists($fileid, $before)) {
                $changes[] = ['change_type' => 'added', 'filename' => $filename];
            }
        }
        return $changes;
    }

    /**
     * @param int $userid
     * @return string
     */
    private static function fullname(int $userid): string {
        global $DB;

        // Volle Zeile statt einer schmalen Feldauswahl: fullname() beschwert
        // sich per debugging(), wenn ihr z.B. die Zweitnamensfelder fehlen,
        // selbst wenn sie fuer die Anzeige ungenutzt bleiben.
        $user = $DB->get_record('user', ['id' => $userid]);
        return $user ? fullname($user) : ('Nutzer #' . $userid);
    }
}
