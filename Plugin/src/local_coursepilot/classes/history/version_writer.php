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
 * Intro-Dateien laufen nicht ueber introeditor/Draftbereich, sondern wie alle
 * anderen Dateien des Modulkontexts durch {@see self::capture_files()} -
 * Metadaten only, dedupliziert in local_coursepilot_cm_file.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class version_writer {

    /** @var string Ursprung, solange nur der native Moodle-Schreibweg beobachtet wird. */
    public const SOURCE_MOODLE = 'moodle';

    /** @var string Ursprung des rueckwirkend angelegten Vorher-Standes (#386, Spec 0015 §10.3). */
    public const SOURCE_VORGEFUNDEN = 'vorgefunden';

    /** @var string Ursprung eines Klons (#421, Spec 0017 §7.5) - immer Version 1, nie ueber capture_on_update(). */
    public const SOURCE_GEKLONT = 'geklont';

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

        if (!$DB->record_exists('local_coursepilot_cm_version', ['cmid' => $cmid])) {
            self::capture($cmid, $userid, self::SOURCE_VORGEFUNDEN);
        }
        $versionid = self::capture($cmid, $userid, $source);

        $transaction->allow_commit();

        return $versionid;
    }

    /**
     * Schnappt den Ist-Stand einer Aktivitaet als neue Version.
     *
     * @param int $cmid
     * @param int $userid Nutzer/in, unter der der Schreibvorgang lief (Event-userid).
     * @param string $source
     * @param int|null $sourcecmid Quell-Modul-ID eines Klons (#421) - nur bei source=SOURCE_GEKLONT gesetzt, sonst null.
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

        // Opportunistische Loeschfrist-Bereinigung (#387): kein Scheduled Task,
        // der die gesamte Tabelle scannt - stattdessen raeumt jeder Schreibvor-
        // gang die eigene cmid auf. Nach dem Insert, damit der frisch erzeugte
        // Stand (timecreated = jetzt) niemals mitgeloescht wird.
        retention::purge_expired_for_cm($cm->id);

        return $versionid;
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
     * Datei-Zeilen des Modulkontexts, nur Metadaten. Rueckschreibbar (gap=0)
     * sind Intro-Dateien UND Dateien in einem der
     * {@see \local_coursepilot\external\update_module_settings::material_reference_specs()}-
     * Dateibereiche (component/filearea) - fuer letztere existiert seit
     * Issue #432 ein echter Wiederherstellungsweg ueber den Papierkorb
     * ({@see \local_coursepilot\activity_file_trash}, Spec 0018 §9.1). Alles
     * andere bleibt eine ausgewiesene Luecke (gap=1) - Dateiinhalte
     * ausserhalb dieser beiden Faelle sind nicht rueckschreibbar (Spec 0015
     * §10.4).
     *
     * @param int $versionid
     * @param int $contextid
     * @param string $modname
     * @return void
     */
    private static function capture_files(int $versionid, int $contextid, string $modname): void {
        global $DB;

        $introcomponent = 'mod_' . $modname;
        $restorablespecs = \local_coursepilot\external\update_module_settings::material_reference_specs($modname);
        $files = $DB->get_records_select('files', 'contextid = ? AND filename <> ?', [$contextid, '.']);

        foreach ($files as $file) {
            $fileid = self::dedup_file($file);
            $gap = self::file_is_restorable($file, $introcomponent, $restorablespecs) ? 0 : 1;
            $DB->insert_record('local_coursepilot_cm_version_file', (object) [
                'versionid' => $versionid,
                'fileid' => $fileid,
                'gap' => $gap,
            ], false);
        }
    }

    /**
     * @param \stdClass $file
     * @param string $introcomponent
     * @param array<string, array{component: string, filearea: string}> $restorablespecs
     * @return bool
     */
    private static function file_is_restorable(\stdClass $file, string $introcomponent, array $restorablespecs): bool {
        if ($file->component === $introcomponent && $file->filearea === 'intro') {
            return true;
        }
        foreach ($restorablespecs as $spec) {
            if ($file->component === $spec['component'] && $file->filearea === $spec['filearea']) {
                return true;
            }
        }
        return false;
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
