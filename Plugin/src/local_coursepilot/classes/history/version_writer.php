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
 * Schnappschuss-Speicher des Aenderungsverlaufs (#385, Spec 0015 §10.4/§10.8).
 *
 * Baut absichtlich NICHT auf course/modlib.php::get_moduleinfo_data() auf:
 * die Funktion verlangt can_update_moduleinfo() (moodle/course:manageactivities)
 * und legt bei jedem Aufruf einen neuen Draft-Dateibereich fuer introeditor an
 * - ein Schreib-Nebeneffekt, den ein Beobachter, der nur lesen/serialisieren
 * soll, nicht ausloesen darf. Die Feldzusammenstellung ist deshalb hier
 * dupliziert (gleiches Vorgehen wie {@see \local_coursepilot\external\get_module_settings}),
 * ergaenzt um die dort bewusst ausgeklammerten gradepass/gradecat/Outcome-Felder,
 * die Spec 0015 §10.4 fuer den Verlauf ausdruecklich verlangt.
 *
 * Intro and allowed material files are captured without introeditor/draft side effects.
 * Only their metadata is deduplicated in local_coursepilot_cm_file.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class version_writer {

    /** @var string Ursprung, solange nur der native Moodle-Schreibweg beobachtet wird. */
    public const SOURCE_MOODLE = version_source::MOODLE;

    /** @var string Ursprung des rueckwirkend angelegten Vorher-Standes (#386, Spec 0015 §10.3). */
    public const SOURCE_DISCOVERED = version_source::DISCOVERED;

    /** @var string Ursprung eines Klons (#421, Spec 0017 §7.5) - immer Version 1, nie ueber capture_on_update(). */
    public const SOURCE_CLONED = version_source::CLONED;

    /** @var string Ursprung einer Aktivitaet aus Aktivitaets-XML (ADR 0028, #596). */
    public const SOURCE_FROM_XML = version_source::FROM_XML;

    /** @var string Vermerk-Stand an der alten cmid nach dem Abloesen; sourcecmid = neue cmid (#596). */
    public const SOURCE_SUPERSEDED = version_source::SUPERSEDED;

    /**
     * Vermerk-Stand an der alten cmid: "abgeloest durch $newcmid" (#596, Spec 0026).
     * Bezug laeuft ueber das vorhandene Feld sourcecmid, keine Schemaaenderung.
     *
     * @param int $oldcmid
     * @param int $newcmid
     * @param int $userid
     * @return int id der neuen Version
     */
    public static function capture_superseded(int $oldcmid, int $newcmid, int $userid): int {
        return self::capture($oldcmid, $userid, self::SOURCE_SUPERSEDED, $newcmid);
    }

    /**
     * Schnappt den Ist-Stand bei einer Aenderung (course_module_updated). Fehlt
     * fuer die cmid noch jeder Stand - eine Aktivitaet, die es schon vor
     * Coursepilot gab und fuer die deshalb nie ein course_module_created-Ereignis
     * beobachtet wurde -, wird zuerst rueckwirkend eine Vorgefunden-Version 1
     * angelegt (#386, Spec 0015 §10.3). Das eigentliche Vorher (der Stand vor
     * genau diesem Schreibvorgang) ist zu diesem Zeitpunkt technisch nicht
     * mehr rekonstruierbar - course_module_updated feuert nach dem Schreiben,
     * und Moodle liefert im Event keinen Volldump des Altzustands. Die
     * Vorgefunden-Version faengt deshalb den zum Event-Zeitpunkt aktuellen
     * (bereits geschriebenen) Stand ein; sie ist bewusst inhaltsgleich mit der
     * direkt danach angelegten Version 2 - besser als keine Rueckfallposition,
     * und "kostet im Leerlauf nichts" (Spec 0015 §10.3).
     *
     * @param int $cmid
     * @param int $userid
     * @param string $source
     * @return int id der neu angelegten (juengsten) Version
     */
    public static function capture_on_update(int $cmid, int $userid, string $source = self::SOURCE_MOODLE): int {
        global $DB;

        // Transaktion statt zweier freistehender Anweisungen: schliesst die
        // Check-then-Act-Luecke zwischen record_exists() und dem Insert fuer
        // den ueblichen Fall. Ein truly gleichzeitiger zweiter Schreibvorgang
        // auf dieselbe cmid waere weiterhin ein DML-Fehler statt einer zweiten
        // stillen Vorgefunden-Version - der cmid+version-Unique-Index greift.
        // ponytail: kein SELECT-FOR-UPDATE-Lock auf eine noch nicht existente
        // Zeile; bei echtem Bedarf (Massenbearbeitung mit Parallelrequests)
        // Advisory-Lock je cmid ergaenzen.
        $transaction = $DB->start_delegated_transaction();

        try {
            if (!$DB->record_exists('local_coursepilot_cm_version', ['cmid' => $cmid])) {
                self::capture($cmid, $userid, self::SOURCE_DISCOVERED);
            }
            $versionid = self::capture($cmid, $userid, $source);
            $transaction->allow_commit();
            return $versionid;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Schnappt den Ist-Stand einer Aktivitaet als neue Version.
     *
     * @param int $cmid
     * @param int $userid Nutzer/in, unter der der Schreibvorgang lief (Event-userid).
     * @param string $source
     * @param int|null $sourcecmid Bezugs-cmid, siehe {@see version_source}: Klon-Quelle (geklont) bzw. neue cmid (superseded), sonst null.
     * @return int id der neu angelegten Version
     */
    public static function capture(
        int $cmid,
        int $userid,
        string $source = self::SOURCE_MOODLE,
        ?int $sourcecmid = null
    ): int {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $transaction = $DB->start_delegated_transaction();
        try {
            $nextversion = (int) $DB->get_field_sql(
                'SELECT COALESCE(MAX(version), 0) + 1 FROM {local_coursepilot_cm_version} WHERE cmid = ?',
                [$cm->id]
            );

            $versionid = (int) $DB->insert_record('local_coursepilot_cm_version', (object) [
                'cmid' => $cm->id,
                'courseid' => (int) $cm->course,
                'version' => $nextversion,
                'source' => $source,
                'sourcecmid' => $sourcecmid,
                'userid' => $userid,
                'moduleinfo_json' => json_encode(self::build_moduleinfo($cm), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'coursemodule_json' => json_encode(
                    (array) $DB->get_record('course_modules', ['id' => $cm->id], '*', MUST_EXIST),
                    JSON_UNESCAPED_UNICODE
                ),
                'arrangement_json' => self::build_arrangement_json($cm),
                'timecreated' => time(),
            ]);

            self::capture_files($versionid, $context->id, (string) $cm->modname);

            // Purge old states of this activity after recording the complete new state.
            // The scheduled task also covers activities without further writes.
            retention::purge_expired_for_cm($cm->id);

            $transaction->allow_commit();
            return $versionid;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Anordnungs-Stand (#396, Spec 0015 §10): nur fuer quiz, sonst null - Slots,
     * Fragereferenzen, Abschnitte und Feedback laufen bei jeder anderen
     * Aktivitaetsart gar nicht ueber eine eigene Struktur-API.
     *
     * @param \stdClass $cm
     * @return string|null JSON-kodierter Anordnungs-Stand, null fuer Nicht-quiz.
     */
    private static function build_arrangement_json(\stdClass $cm): ?string {
        if ($cm->modname !== 'quiz') {
            return null;
        }
        return json_encode(\local_coursepilot\quiz\arrangement::capture((int) $cm->instance), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Repliziert das get_moduleinfo_data()-Feldobjekt (Instanz-Record, Tags,
     * availability, gradepass/gradecat/Outcomes), ohne dessen
     * Formular-Nebenwirkungen auszuloesen.
     *
     * @param \stdClass $cm
     * @return array
     */
    private static function build_moduleinfo(\stdClass $cm): array {
        global $CFG, $DB;

        $cw = $DB->get_record('course_sections', ['id' => $cm->section], 'section', MUST_EXIST);
        $instance = (array) $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);

        $data = array_merge($instance, [
            'coursemodule' => (int) $cm->id,
            'section' => (int) $cw->section,
            'visible' => (int) $cm->visible,
            'visibleoncoursepage' => (int) $cm->visibleoncoursepage,
            'cmidnumber' => (string) $cm->idnumber,
            'groupmode' => (int) groups_get_activity_groupmode($cm),
            'groupingid' => (int) $cm->groupingid,
            'course' => (int) $cm->course,
            'module' => (int) $cm->module,
            'modulename' => (string) $cm->modname,
            'instance' => (int) $cm->instance,
            'completion' => (int) $cm->completion,
            'completionview' => (int) $cm->completionview,
            'completionexpected' => (int) $cm->completionexpected,
            'completionusegrade' => $cm->completiongradeitemnumber === null ? 0 : 1,
            'completionpassgrade' => (int) $cm->completionpassgrade,
            'completiongradeitemnumber' => $cm->completiongradeitemnumber,
            'showdescription' => (int) $cm->showdescription,
            'downloadcontent' => $cm->downloadcontent,
            'lang' => (string) $cm->lang,
            'tags' => \core_tag_tag::get_item_tags_array('core', 'course_modules', $cm->id),
        ]);

        if (!empty($CFG->enableavailability)) {
            // Rohe Bedingungen wie get_moduleinfo_data(); die Profil-Maskierung
            // (ADR 0011) ist Sache der kuenftigen Ansichts-Werkzeuge, nicht der
            // Speicherung - "das Diff wird beim Ansehen berechnet" (Spec 0015 §10.1).
            $data['availabilityconditionsjson'] = (string) ($cm->availability ?? '');
        }

        self::add_grade_fields($data, $cm);

        return $data;
    }

    /**
     * gradepass/gradecat/Outcome-Felder wie course/modlib.php::get_moduleinfo_data()
     * (Zeilen 848-885), bewusst ausserhalb von get_module_settings gehalten.
     *
     * @param array $data
     * @param \stdClass $cm
     * @return void
     */
    private static function add_grade_fields(array &$data, \stdClass $cm): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $component = 'mod_' . $cm->modname;
        $items = \grade_item::fetch_all([
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'courseid' => $cm->course,
        ]);

        if (!$items) {
            return;
        }

        foreach ($items as $item) {
            if (!empty($item->outcomeid)) {
                $data['outcome_' . $item->outcomeid] = 1;
            } else if (isset($item->gradepass)) {
                $fieldname = \core_grades\component_gradeitems::get_field_name_for_itemnumber(
                    $component,
                    $item->itemnumber,
                    'gradepass'
                );
                $data[$fieldname] = format_float($item->gradepass, $item->get_decimals());
            }
        }

        $gradecat = [];
        foreach ($items as $item) {
            if (!isset($gradecat[$item->itemnumber])) {
                $gradecat[$item->itemnumber] = $item->categoryid;
            } else if ($gradecat[$item->itemnumber] != $item->categoryid) {
                $gradecat[$item->itemnumber] = false; // Gemischte Kategorien - nicht setzen.
            }
        }
        foreach ($gradecat as $itemnumber => $cat) {
            if ($cat !== false) {
                $fieldname = \core_grades\component_gradeitems::get_field_name_for_itemnumber(
                    $component,
                    $itemnumber,
                    'gradecat'
                );
                $data[$fieldname] = $cat;
            }
        }
    }

    /**
     * Capture metadata only for positively allowed teaching-design file areas.
     * Submission and unknown areas never enter history, including gap rows.
     *
     * @param int $versionid
     * @param int $contextid
     * @param string $modname
     * @return void
     */
    private static function capture_files(int $versionid, int $contextid, string $modname): void {
        global $DB;

        $files = $DB->get_records_select('files', 'contextid = ? AND filename <> ?', [$contextid, '.']);
        foreach ($files as $file) {
            if (!file_policy::allows($modname, $file->component, $file->filearea)) {
                continue;
            }
            $fileid = self::dedup_file($file);
            $DB->insert_record('local_coursepilot_cm_version_file', (object) [
                'versionid' => $versionid,
                'fileid' => $fileid,
                'gap' => 0,
            ], false);
        }
    }

    /**
     * Legt Datei-Metadaten nur an, wenn diese Kombination aus pathnamehash
     * und contenthash noch nicht bekannt ist - Dedup ueber mehrere Staende
     * hinweg. Nur der pathnamehash zu dedupen wuerde bei einer inhaltlich
     * geaenderten Datei am gleichen Pfad (z.B. ausgetauschtes Intro-Bild)
     * die veralteten Metadaten (Groesse, Mimetype, contenthash) an neuere
     * Staende zurueckgeben - der contenthash muss deshalb Teil des
     * Dedup-Schluessels sein.
     *
     * @param \stdClass $file
     * @return int
     */
    private static function dedup_file(\stdClass $file): int {
        global $DB;

        // Share the metadata row lock with retention before deciding whether to reuse it.
        // Moodle transactions use READ COMMITTED: after waiting, the read sees a
        // cleanup deletion and recreates metadata instead of linking a vanished row.
        $DB->execute('UPDATE {local_coursepilot_cm_file} SET id = id
                       WHERE pathnamehash = ? AND contenthash = ?', [$file->pathnamehash, $file->contenthash]);
        $existing = $DB->get_record('local_coursepilot_cm_file', [
            'pathnamehash' => $file->pathnamehash,
            'contenthash' => $file->contenthash,
        ], 'id');
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) $DB->insert_record('local_coursepilot_cm_file', (object) [
            'pathnamehash' => $file->pathnamehash,
            'contenthash' => $file->contenthash,
            'component' => $file->component,
            'filearea' => $file->filearea,
            'itemid' => $file->itemid,
            'filepath' => $file->filepath,
            'filename' => $file->filename,
            'filesize' => $file->filesize,
            'mimetype' => (string) ($file->mimetype ?? ''),
            'timemodified' => $file->timemodified,
        ]);
    }
}
