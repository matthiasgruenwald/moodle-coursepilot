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
 * Feldkatalog fuer mod_assign (Spec 0015 §2.2/§4.1, Ticket #382). Der
 * Stresstest der Zweistufigkeit: ~35 Instanzspalten, 13 Plugin-Pseudofelder,
 * dazu der gemeinsame Block (§2.3) und die 34 Konstanten ohne aufrufbare
 * Wertemenge (mod/assign/locallib.php, Zeilen 30-92 - alle "define()" dort
 * ausser ASSIGN_MARKER_FILTER_NO_MARKER, das eine Filter-UI-Kennung fuer die
 * Bewertungstabelle ist, kein Feldwert einer Instanz).
 *
 * Fallstricke aus dem Bestand:
 * - **Pseudofelder statt Enable-Spalten:** mod_assign hat fuer die
 *   Abgabe-/Feedback-Plugins keine eigenen assign-Tabellenspalten - jedes
 *   Plugin traegt seinen Zustand in {assign_plugin_config}. Der Formularweg
 *   erwartet dafuer je Plugin ein Feld "{subtype}_{type}_enabled"
 *   (assign_update_plugin_instance(), mod/assign/locallib.php:1359-1373):
 *   `if (!empty($formdata->$enabledname)) { enable() } else { disable() }`.
 *   Ein FEHLENDES Feld disabled das Plugin ebenso wie ein explizites 0 - der
 *   gefaehrlichste Fall dieses Katalogs, weil er durch Weglassen entsteht.
 *   Sind am Ende ALLE Abgabe-Plugins deaktiviert, cached
 *   is_any_submission_plugin_enabled() das als "nosubmissions=1"
 *   (add_instance()/update_instance():843/1629) - die Aufgabe nimmt dann gar
 *   keine Abgaben mehr an.
 * - **"nosubmissions" ist reiner Cache-Ausgang**, kein Eingabefeld: Moodle
 *   berechnet es selbst aus den Enable-Pseudofeldern (s.o.) unmittelbar nach
 *   jedem add_instance()/update_instance() - ein Patch darauf wuerde beim
 *   naechsten Speichern ueberschrieben. Sperrliste.
 * - **"revealidentities"** ist eine echte Spalte, aber ueber KEINEN
 *   Formularpfad erreichbar: weder add_instance() noch update_instance()
 *   uebernehmen sie aus $formdata - sie wird ausschliesslich ueber die
 *   "Identitaeten aufdecken"-Aktion gesetzt (mod/assign/locallib.php, Methode
 *   reveal_identities()). Ueber das Vehikel unschreibbar, deshalb Sperrliste
 *   statt stillem No-Op.
 * - **"completionsubmit"** ist Vervollstaendigungsfeld UND echte Spalte
 *   zugleich: update_instance() schreibt es nur, wenn "completionunlocked"
 *   gesetzt ist (mod/assign/locallib.php:1569-1571) - sonst still verworfen,
 *   ohne "completionunlocked" aber loescht ein Schreibvorgang laut Spec 0015
 *   §8 die Vervollstaendigungsdaten der Lernenden. Deshalb hier wie die
 *   generischen Vervollstaendigungsfelder (course_modules, siehe
 *   {@see shared_block::BLOCKLIST}) auf die Sperrliste - geschrieben wird es
 *   ueber `set_completion` im Zweitakt (Ticket #461; dort als
 *   modulspezifisches Vervollstaendigungsfeld fuer "assign" und "choice"
 *   freigeschaltet).
 * - **"teamsubmissiongroupingid"** listet im Formular nur Gruppierungen des
 *   eigenen Kurses (mod/assign/mod_form.php:195:
 *   `groups_get_all_groupings($assignment->get_course()->id)`), aber weder
 *   add_instance() noch update_instance() prueft das beim Schreiben nach -
 *   eine Gruppierungs-ID aus einem fremden Kurs würde unbeanstandet
 *   uebernommen. Deshalb im Feld selbst vermerkt, wie choice::optionid.
 * - **"activity"/"activityformat"** kommen im Formular als ein Editor-Feld
 *   "activityeditor" (Text+Format+Draftitem), werden hier aber wie
 *   "intro"/"introformat" als zwei flache Felder gefuehrt - gleiche
 *   Vereinfachung wie bei allen anderen Katalogen dieser Spec (§3.2: das
 *   flache get_moduleinfo_data()-Feldobjekt, kein Editor-Array-Vertrag).
 * - **"introattachments"** ("Zusaetzliche Dateien") ist ab Spec 0018/#429
 *   katalogisiert und - anders als resource/folder - NICHT gesperrt: die
 *   Sperre aus Spec 0015 §4.3 galt fuer den Fall "Coursepilot hat noch keinen
 *   Ablageort fuer Binaerdateien", der mit dem Materialordner (Spec 0018 §2)
 *   entfaellt. Aufloesung der Materialordner-Pfade in einen
 *   Dateimanager-Entwurf uebernimmt update_module_settings vor dem
 *   update_moduleinfo()-Aufruf.
 * - **"introimages"** (Issue #433) haengt Materialdateien NICHT an, sondern
 *   in den Draft-Dateibereich der Intro selbst (component=mod_assign,
 *   filearea=intro) - update_moduleinfo() liest "intro" ausschliesslich aus
 *   $moduleinfo->introeditor['text'] (course/modlib.php:675-680), ein reiner
 *   ->intro-Patch verpufft sonst stillschweigend (siehe update_module_settings).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class assign implements module_catalog {

    public static function modname(): string {
        return 'assign';
    }

    /**
     * Die haeufig gesetzten Felder fuer die Kurzform von describe_module_fields
     * (Spec 0015 §3.1, Ticket #382: "die zwoelf Felder, die eine Lehrkraft je
     * benennt, nicht markinganonymous"). Kein Interface-Vertrag - ein optionaler
     * Haken, den describe_module_fields per is_callable() abfragt; Katalogklassen
     * ohne diese Methode liefern unveraendert alle ihre Felder in der Kurzform
     * (bei sechs bis zwoelf Feldern wie label/choice/forum ist "alle" bereits
     * die Kurzform).
     *
     * @return string[]
     */
    public static function common_field_names(): array {
        return [
            'name',
            'intro',
            'duedate',
            'cutoffdate',
            'allowsubmissionsfromdate',
            'grade',
            'teamsubmission',
            'submissiondrafts',
            'maxattempts',
            'attemptreopenmethod',
            'blindmarking',
            'sendnotifications',
        ];
    }

    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Anzeigename der Aufgabe.',
                true,
                null,
                null,
                null,
                'mod/assign/mod_form.php:50-57 (PARAM_TEXT bzw. PARAM_CLEANHTML je nach $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Beschreibungstext (Intro) der Aufgabe.',
                true,
                null,
                null,
                null,
                'mod/assign/db/install.xml (assign.intro, NOTNULL ohne DB-Default)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Textformat des Intros.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/assign/db/install.xml (assign.introformat)'
            ),
            new field(
                'activity',
                'PARAM_RAW',
                'Zusaetzlicher Aktivitaetstext (eigener Editor-Block unterhalb des Intros), z.B. fuer '
                    . 'Arbeitsauftraege. Leer = kein Zusatztext.',
                false,
                null,
                null,
                null,
                'mod/assign/mod_form.php:62-66 (Editor "activityeditor"); Spalte '
                    . 'mod/assign/db/install.xml (assign.activity, NOTNULL=false)'
            ),
            new field(
                'activityformat',
                'PARAM_INT',
                'Textformat des Aktivitaetstexts.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/assign/db/install.xml (assign.activityformat)'
            ),
            new field(
                'alwaysshowdescription',
                'PARAM_BOOL',
                'Intro schon vor "allowsubmissionsfromdate" anzeigen statt erst danach.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:123-126 (checkbox); Spalte '
                    . 'mod/assign/db/install.xml (assign.alwaysshowdescription)'
            ),
            new field(
                'submissiondrafts',
                'PARAM_BOOL',
                'Abgaben gelten erst als Entwurf, bis die/der Lernende ausdruecklich abschickt.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:132-137 (selectyesno); Spalte '
                    . 'mod/assign/db/install.xml (assign.submissiondrafts)'
            ),
            new field(
                'requiresubmissionstatement',
                'PARAM_BOOL',
                'Lernende muessen vor dem Abschicken eine Selbststaendigkeitserklaerung akzeptieren.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:139-144 (selectyesno); Spalte '
                    . 'mod/assign/db/install.xml (assign.requiresubmissionstatement)'
            ),
            new field(
                'maxattempts',
                'PARAM_INT',
                'Maximale Zahl an Abgabeversuchen. -1 = unbegrenzt.',
                false,
                1,
                null,
                null,
                'mod/assign/mod_form.php:146-149 (Select 1-30 plus ASSIGN_UNLIMITED_ATTEMPTS); '
                    . 'mod/assign/locallib.php:61 (ASSIGN_UNLIMITED_ATTEMPTS = -1); Spalte '
                    . 'mod/assign/db/install.xml (assign.maxattempts, DEFAULT=1)'
            ),
            new field(
                'attemptreopenmethod',
                'PARAM_ALPHA',
                'Wie ein neuer Versuch nach dem ersten geoeffnet wird: manuell, automatisch (nach jeder '
                    . 'Bewertung) oder bis zum Bestehen. Nur sichtbar, wenn "maxattempts" != 1.',
                false,
                'untilpass',
                ['manual', 'automatic', 'untilpass'],
                null,
                'mod/assign/locallib.php:55-58 (ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL/AUTOMATIC/UNTILPASS); '
                    . 'mod/assign/mod_form.php:151-170 (choicedropdown); Spalte '
                    . 'mod/assign/db/install.xml (assign.attemptreopenmethod, DEFAULT=untilpass)'
            ),
            new field(
                'duedate',
                'PARAM_INT',
                'Unix-Zeitstempel: Abgabetermin, nur informativ (kein Sperrzeitpunkt, das ist "cutoffdate"). '
                    . 'Erzeugt einen Kalendereintrag (siehe Nebenwirkungen).',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:102-105 (date_time_selector, optional); Spalte '
                    . 'mod/assign/db/install.xml (assign.duedate)'
            ),
            new field(
                'cutoffdate',
                'PARAM_INT',
                'Unix-Zeitstempel: ab hier nimmt Moodle keine Abgaben mehr an, auch keine spaeten. '
                    . '0 = kein Cutoff.',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:107-109 (date_time_selector, optional); Spalte '
                    . 'mod/assign/db/install.xml (assign.cutoffdate)'
            ),
            new field(
                'allowsubmissionsfromdate',
                'PARAM_INT',
                'Unix-Zeitstempel: Abgaben werden erst ab hier angenommen. 0 = ab sofort.',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:81-84 (date_time_selector, optional); Spalte '
                    . 'mod/assign/db/install.xml (assign.allowsubmissionsfromdate)'
            ),
            new field(
                'gradingduedate',
                'PARAM_INT',
                'Unix-Zeitstempel: erwarteter Bewertungstermin, nur informativ. Erzeugt einen Kalendereintrag '
                    . '(siehe Nebenwirkungen).',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:111-113 (date_time_selector, optional); Spalte '
                    . 'mod/assign/db/install.xml (assign.gradingduedate)'
            ),
            new field(
                'timelimit',
                'PARAM_INT',
                'Bearbeitungszeit in Sekunden ab Versuchsbeginn, sofern der Admin Zeitlimits aktiviert hat '
                    . '($CFG->assign->enabletimelimit). 0 = kein Zeitlimit.',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:115-121 (duration, optional, nur bei aktivierter Admin-Einstellung); '
                    . 'Spalte mod/assign/db/install.xml (assign.timelimit)'
            ),
            new field(
                'grade',
                'PARAM_INT',
                'Bewertungstyp: positiv = Punkte-Maximum, negativ = ID einer benutzerdefinierten Skala, '
                    . '0 = keine Bewertung.',
                false,
                0,
                null,
                null,
                'course/moodleform_mod.php (standard_grading_coursemodule_elements(), modgrade-Element); '
                    . 'mod/assign/mod_form.php:225 (Aufruf); Spalte mod/assign/db/install.xml (assign.grade)'
            ),
            new field(
                'gradepenalty',
                'PARAM_BOOL',
                'Verspaetungsabzuege aktivieren. Nur sichtbar, wenn das Penalty-Feature serverweit aktiv ist '
                    . '(core_grades\\penalty_manager::is_penalty_enabled_for_module()).',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:253-272 (selectyesno, bedingt sichtbar); Spalte '
                    . 'mod/assign/db/install.xml (assign.gradepenalty)'
            ),
            new field(
                'sendnotifications',
                'PARAM_BOOL',
                'Lehrkraefte per Mail ueber neue Abgaben benachrichtigen. Nebenwirkung: siehe Nebenwirkungen.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:212-214 (selectyesno); Spalte '
                    . 'mod/assign/db/install.xml (assign.sendnotifications)'
            ),
            new field(
                'sendlatenotifications',
                'PARAM_BOOL',
                'Auch bei verspaeteten Abgaben benachrichtigen. Nur wirksam bei "sendnotifications"=0 - bei '
                    . '1 sind ohnehin schon alle Benachrichtigungen an.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:216-219 (selectyesno, disabledIf sendnotifications eq 1); Spalte '
                    . 'mod/assign/db/install.xml (assign.sendlatenotifications)'
            ),
            new field(
                'sendstudentnotifications',
                'PARAM_BOOL',
                'Vorbelegung der "Lernende benachrichtigen"-Checkbox beim Bewerten.',
                false,
                1,
                [0, 1],
                null,
                'mod/assign/mod_form.php:221-223 (selectyesno); Spalte '
                    . 'mod/assign/db/install.xml (assign.sendstudentnotifications, DEFAULT=1)'
            ),
            new field(
                'teamsubmission',
                'PARAM_BOOL',
                'Lernende geben in Gruppen ab statt einzeln.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:174-179 (selectyesno); Spalte '
                    . 'mod/assign/db/install.xml (assign.teamsubmission)'
            ),
            new field(
                'requireallteammemberssubmit',
                'PARAM_BOOL',
                'Eine Gruppenabgabe gilt erst als abgeschickt, wenn alle Mitglieder zugestimmt haben. Nur '
                    . 'sichtbar bei "teamsubmission"=1.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:189-193 (selectyesno, hideIf teamsubmission eq 0); Spalte '
                    . 'mod/assign/db/install.xml (assign.requireallteammemberssubmit)'
            ),
            new field(
                'teamsubmissiongroupingid',
                'PARAM_INT',
                'Gruppierung, deren Gruppen fuer Gruppenabgaben verwendet werden (0 = alle Gruppen des Kurses). '
                    . 'ACHTUNG: nur Gruppierungs-IDs desselben Kurses sind gueltig - das Formular listet nur '
                    . 'diese, Moodle prueft das aber beim Schreiben selbst nicht nach.',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:195-205 (groups_get_all_groupings($assignment->get_course()->id)); Spalte '
                    . 'mod/assign/db/install.xml (assign.teamsubmissiongroupingid)'
            ),
            new field(
                'preventsubmissionnotingroup',
                'PARAM_BOOL',
                'Abgabe verweigern, wenn die/der Lernende in keiner Gruppe ist. Nur sichtbar bei '
                    . '"teamsubmission"=1.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:181-187 (selectyesno, hideIf teamsubmission eq 0); Spalte '
                    . 'mod/assign/db/install.xml (assign.preventsubmissionnotingroup)'
            ),
            new field(
                'blindmarking',
                'PARAM_BOOL',
                'Anonyme Bewertung: Namen der Lernenden vor der Lehrkraft verbergen, bis sie aufgedeckt werden.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:226-231 (selectyesno); Spalte mod/assign/db/install.xml (assign.blindmarking)'
            ),
            new field(
                'hidegrader',
                'PARAM_BOOL',
                'Umgekehrte Anonymitaet: Name der bewertenden Person vor den Lernenden verbergen.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:233-235 (selectyesno); Spalte mod/assign/db/install.xml (assign.hidegrader)'
            ),
            new field(
                'markingworkflow',
                'PARAM_BOOL',
                'Mehrstufigen Bewertungsworkflow (in Bearbeitung/zur Durchsicht/freigegeben, ...) verwenden.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:237-239 (selectyesno); Spalte mod/assign/db/install.xml (assign.markingworkflow)'
            ),
            new field(
                'markingallocation',
                'PARAM_BOOL',
                'Bewertende Personen je Abgabe zuteilen. Nur sichtbar bei "markingworkflow"=1; wird beim '
                    . 'Speichern auf 0 erzwungen, wenn "markingworkflow"=0.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:241-244 (selectyesno, hideIf markingworkflow eq 0); '
                    . 'mod/assign/locallib.php:791-794/1587-1590 (Erzwingung); Spalte '
                    . 'mod/assign/db/install.xml (assign.markingallocation)'
            ),
            new field(
                'markinganonymous',
                'PARAM_BOOL',
                'Bewertende Personen sehen bei der Zuteilung nicht, wer wem zugeteilt ist. Nur sichtbar bei '
                    . '"markingworkflow"=1 und "blindmarking"=1; wird beim Speichern auf 0 erzwungen, wenn eine '
                    . 'der beiden Bedingungen fehlt.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:246-250 (selectyesno, hideIf markingworkflow/blindmarking eq 0); '
                    . 'mod/assign/locallib.php:795-802/1591-1595 (Erzwingung); Spalte '
                    . 'mod/assign/db/install.xml (assign.markinganonymous)'
            ),
            new field(
                'submissionattachments',
                'PARAM_BOOL',
                'Abgabe-Zusammenfassung auf der Bewertungsseite um die Liste angehaengter Dateien ergaenzen.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:73-74 (advcheckbox); Spalte '
                    . 'mod/assign/db/install.xml (assign.submissionattachments)'
            ),
        ];
    }

    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::for_modname(self::modname(), $instanceid, $cmid, $fullcontent);
    }

    public static function write_options(): array {
        return [
            'editor_content' => ['activityeditor' => ['activity', 'activityformat']],
            'material_reference_fields' => ['introattachments' => ['component' => 'mod_assign', 'filearea' => 'introattachment']],
            'intro_image_field' => 'introimages',
            'admin_default_fields' => [
                'assignsubmission_file_enabled' => 'assignsubmission_file',
                'assignsubmission_onlinetext_enabled' => 'assignsubmission_onlinetext',
                'assignfeedback_comments_enabled' => 'assignfeedback_comments',
                'assignfeedback_editpdf_enabled' => 'assignfeedback_editpdf',
                'assignfeedback_file_enabled' => 'assignfeedback_file',
                'assignfeedback_offline_enabled' => 'assignfeedback_offline',
            ],
            'date_order_rules' => [
                ['reference' => 'allowsubmissionsfromdate', 'field' => 'duedate', 'mode' => 'must_be_after'],
                ['reference' => 'duedate', 'field' => 'cutoffdate', 'mode' => 'not_before'],
                ['reference' => 'allowsubmissionsfromdate', 'field' => 'cutoffdate', 'mode' => 'not_before'],
                ['reference' => 'allowsubmissionsfromdate', 'field' => 'gradingduedate', 'mode' => 'must_be_after'],
            ],
        ];
    }

    public static function pseudofields(): array {
        return [
            new field(
                'activityeditor',
                'array{text: string, format: int, itemid: int}',
                'Editor-Array fuer den zusaetzlichen Aktivitaetstext. Der flache Vertrag nutzt "activity" und '
                    . '"activityformat"; dieses Feld dient dem nativen Formularweg.',
                false,
                null,
                null,
                null,
                'mod/assign/mod_form.php:62-66 (Editor "activityeditor")'
            ),
            new field(
                'introimages',
                'string[] (Materialordner-Pfade, nur Bild-Endungen)',
                'Fachabbildungen, die IN den Beschreibungstext eingebettet werden (Spec 0018 §4.2/§5, Issue '
                    . '#433 - schliesst den Weg aus #430/#431: Vorschau ansehen, Ausschnitt waehlen, hier '
                    . 'einbetten). Jeder Pfad muss bereits als Materialdatei vorliegen und eine Endung aus der '
                    . 'engeren Einbett-Whitelist tragen (png/jpg/jpeg/gif/svg/webp) - eine andere Endung (z.B. '
                    . 'pdf) scheitert mit klarer Meldung. Der Patch traegt den Verweis selbst: der "intro"-Text '
                    . 'muss ein Bild-Element enthalten, dessen Quelle mit "@@PLUGINFILE@@/" plus dem Dateinamen '
                    . 'beginnt (Moodles Draft-Platzhalterpraefix), mit einem Alt-Text, den die KI selbst '
                    . 'formuliert (Glossar: Alt-Text als KI-Qualitätsroutine). Ohne begleitenden "intro"-Patch '
                    . 'bleibt die Datei nur im Dateibereich verfuegbar, aber unverlinkt.',
                false,
                null,
                null,
                null,
                'course/modlib.php:675-680 (update_moduleinfo(): file_save_draft_area_files() aus '
                    . '$moduleinfo->introeditor löst @@PLUGINFILE@@ gegen den Draft-Dateibereich auf)'
            ),
            new field(
                'introattachments',
                'string[] (Materialordner-Pfade)',
                'Zusaetzliche Dateien der Aufgabe ("Zusaetzliche Dateien"/introattachments-Feld) - Liste von '
                    . 'Pfaden relativ zum Materialordner (Spec 0018 §4.2/§7: die Sperre aus Spec 0015 §4.3 entfaellt '
                    . 'fuer assign). Jeder Pfad wird serverseitig zu einer bestehenden Materialdatei aufgeloest und '
                    . 'unveraendert uebernommen; bereits vorhandene Anhaenge bleiben erhalten. Kein Chat-Anhang '
                    . 'direkt - die Datei muss zuerst ueber upload_material_file im Materialordner liegen.',
                false,
                null,
                null,
                null,
                'mod/assign/locallib.php:1648-1650 (save_intro_draft_files(), isset-Wache); '
                    . 'mod/assign/locallib.php:82 (ASSIGN_INTROATTACHMENT_FILEAREA="introattachment")'
            ),
            new field(
                'assignsubmission_file_enabled',
                'PARAM_BOOL',
                'Abgabeart "Datei" aktivieren. Kein DB-Feld: fehlt es, deaktiviert Moodle diese Abgabeart '
                    . 'still (siehe Klassendoku). Ohne JEDE aktive Abgabeart wird "nosubmissions"=1 gesetzt - '
                    . 'die Aufgabe nimmt dann gar keine Abgaben mehr an. Formular-Default ist '
                    . 'admin-konfigurierbar (Site-Administration), deshalb hier ohne festen Default.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance(), "{subtype}_{type}_enabled"); '
                    . 'mod/assign/locallib.php:1713-1716 (Default aus get_config(\'assignsubmission_file\', \'default\')); '
                    . 'mod/assign/submission/file/settings.php:28 (Admin-Einstellung "default")'
            ),
            new field(
                'assignsubmission_file_maxfiles',
                'PARAM_INT',
                'Maximale Anzahl Dateien je Abgabe. Nur wirksam bei "assignsubmission_file_enabled"=1.',
                false,
                20,
                null,
                null,
                'mod/assign/submission/file/locallib.php:88 (Select), :129 (save_settings())'
            ),
            new field(
                'assignsubmission_file_maxsizebytes',
                'PARAM_INT',
                'Maximale Dateigroesse je Datei in Byte. Die waehlbaren Werte sind eine von Kurs- und '
                    . 'Serverlimit abhaengige Teilmenge, keine feste Liste. Nur wirksam bei '
                    . '"assignsubmission_file_enabled"=1.',
                false,
                0,
                null,
                'get_max_upload_sizes()',
                'lib/moodlelib.php:6453 (get_max_upload_sizes()); '
                    . 'mod/assign/submission/file/locallib.php:106 (Select), :130 (save_settings())'
            ),
            new field(
                'assignsubmission_file_filetypes',
                'PARAM_RAW',
                'Erlaubte Dateiendungen/-typen, kommasepariert (leer = alle). Nur wirksam bei '
                    . '"assignsubmission_file_enabled"=1.',
                false,
                '',
                null,
                null,
                'mod/assign/submission/file/locallib.php:116 (filetypes-Element), :132-134 (save_settings())'
            ),
            new field(
                'assignsubmission_onlinetext_enabled',
                'PARAM_BOOL',
                'Abgabeart "Online-Text" aktivieren. Kein DB-Feld, gleiches Risiko wie '
                    . '"assignsubmission_file_enabled" (siehe Klassendoku). Formular-Default ist '
                    . 'admin-konfigurierbar, deshalb hier ohne festen Default.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/locallib.php:1713-1716 (Default aus get_config(\'assignsubmission_onlinetext\', \'default\')); '
                    . 'mod/assign/submission/onlinetext/settings.php:26 (Admin-Einstellung "default")'
            ),
            new field(
                'assignsubmission_onlinetext_wordlimit',
                'PARAM_INT',
                'Maximale Wortzahl des Online-Texts. Nur wirksam, wenn zusaetzlich '
                    . '"assignsubmission_onlinetext_wordlimit_enabled"=1 gesetzt ist.',
                false,
                0,
                null,
                null,
                'mod/assign/submission/onlinetext/locallib.php:97 (Textfeld in der Wordlimit-Gruppe)'
            ),
            new field(
                'assignsubmission_onlinetext_wordlimit_enabled',
                'PARAM_BOOL',
                'Wortlimit fuer den Online-Text ueberhaupt anwenden. Ohne dieses Feld bleibt '
                    . '"assignsubmission_onlinetext_wordlimit" wirkungslos (Kombinationsregel).',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/submission/onlinetext/locallib.php:98-99 (Checkbox in der Wordlimit-Gruppe)'
            ),
            new field(
                'assignsubmission_comments_enabled',
                'PARAM_BOOL',
                'Abgabeart "Kommentare" (Diskussion zur Abgabe) aktivieren. Kein DB-Feld, gleiches Risiko wie '
                    . '"assignsubmission_file_enabled" (siehe Klassendoku). Anders als die anderen '
                    . 'Abgabe-Plugins hat dieses keine eigene Admin-Einstellung - der Formularwert folgt '
                    . 'ausschliesslich davon, ob das Plugin serverweit aktiv ist.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/submission/comments/locallib.php:187-188 (is_configurable() === false, '
                    . 'kein settings.php)'
            ),
            new field(
                'assignfeedback_comments_enabled',
                'PARAM_BOOL',
                'Feedbackart "Kommentarfeld" aktivieren. Kein DB-Feld; ihr Fehlen deaktiviert nur diese '
                    . 'Feedbackart, nicht die Abgabe selbst. Formular-Default ist admin-konfigurierbar, '
                    . 'deshalb hier ohne festen Default.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/locallib.php:1713-1716 (Default aus get_config(\'assignfeedback_comments\', \'default\')); '
                    . 'mod/assign/feedback/comments/settings.php:26 (Admin-Einstellung "default")'
            ),
            new field(
                'assignfeedback_comments_commentinline',
                'PARAM_BOOL',
                'Feedbackkommentar direkt in die Abgabe einfuegen statt daneben anzuzeigen. Nur wirksam bei '
                    . '"assignfeedback_comments_enabled"=1.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/feedback/comments/locallib.php:246-263 (get_settings()/save_settings())'
            ),
            new field(
                'assignfeedback_editpdf_enabled',
                'PARAM_BOOL',
                'Feedbackart "Anmerkungen im PDF" (Annotate PDF) aktivieren. Kein DB-Feld, gleiches Muster '
                    . 'wie "assignfeedback_comments_enabled" - Formular-Default admin-konfigurierbar.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/locallib.php:1713-1716 (Default aus get_config(\'assignfeedback_editpdf\', \'default\')); '
                    . 'mod/assign/feedback/editpdf/settings.php:29 (Admin-Einstellung "default")'
            ),
            new field(
                'assignfeedback_file_enabled',
                'PARAM_BOOL',
                'Feedbackart "Feedback-Datei" (Datei-Rueckgabe an die Lernenden) aktivieren. Kein DB-Feld, '
                    . 'gleiches Muster wie "assignfeedback_comments_enabled" - Formular-Default '
                    . 'admin-konfigurierbar.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/locallib.php:1713-1716 (Default aus get_config(\'assignfeedback_file\', \'default\')); '
                    . 'mod/assign/feedback/file/settings.php:26 (Admin-Einstellung "default")'
            ),
            new field(
                'assignfeedback_offline_enabled',
                'PARAM_BOOL',
                'Feedbackart "Bewertungstabelle offline" (Export/Import als Tabelle) aktivieren. Kein DB-Feld, '
                    . 'gleiches Muster wie "assignfeedback_comments_enabled" - Formular-Default '
                    . 'admin-konfigurierbar.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/locallib.php:1713-1716 (Default aus get_config(\'assignfeedback_offline\', \'default\')); '
                    . 'mod/assign/feedback/offline/settings.php:26 (Admin-Einstellung "default")'
            ),
        ];
    }

    public static function blocklist(): array {
        return [
            'nosubmissions',
            'revealidentities',
            'completionsubmit',
        ];
    }

    public static function combination_rules(): array {
        return [
            '"duedate" muss nach "allowsubmissionsfromdate" liegen, wenn beide gesetzt sind '
                . '(mod/assign/mod_form.php: validation()).',
            '"cutoffdate" darf nicht vor "duedate" liegen, wenn beide gesetzt sind (validation()).',
            '"cutoffdate" darf nicht vor "allowsubmissionsfromdate" liegen, wenn beide gesetzt sind '
                . '(validation()).',
            '"gradingduedate" muss nach "allowsubmissionsfromdate" liegen, wenn beide gesetzt sind '
                . '(validation()).',
            '"gradingduedate" muss nach "duedate" liegen, wenn beide gesetzt sind (validation()).',
            '"attemptreopenmethod"="untilpass" ist nicht mit "blindmarking"=1 kombinierbar, sobald mehr als '
                . 'ein Versuch erlaubt ist ("maxattempts" > 1 oder unbegrenzt) (validation()).',
        ];
    }

    public static function side_effects(): array {
        return [
            '"sendnotifications"=1 verschickt ab dann bei jeder neuen Abgabe eine Mail an alle Lehrkraefte '
                . 'der Aufgabe (mod/assign/locallib.php: email_graders()).',
            '"duedate"/"gradingduedate" erzeugen bzw. aktualisieren je einen Kalendereintrag '
                . '(mod/assign/locallib.php: update_calendar()); "cutoffdate"/"allowsubmissionsfromdate" tun '
                . 'das nicht.',
        ];
    }

    public static function bundles(): array {
        return [
            'standard' => [
                'submissiondrafts' => 1,
                'requiresubmissionstatement' => 0,
                'teamsubmission' => 0,
                'blindmarking' => 0,
                'markingworkflow' => 0,
                'sendnotifications' => 1,
                'assignsubmission_file_enabled' => 1,
                'assignsubmission_onlinetext_enabled' => 0,
                'assignfeedback_comments_enabled' => 1,
            ],
            'übung' => [
                'grade' => 0,
                'submissiondrafts' => 1,
                'requiresubmissionstatement' => 0,
                'sendnotifications' => 0,
                'maxattempts' => -1, // ASSIGN_UNLIMITED_ATTEMPTS.
                'attemptreopenmethod' => 'untilpass',
                'assignsubmission_onlinetext_enabled' => 1,
                'assignsubmission_file_enabled' => 0,
                'assignfeedback_comments_enabled' => 1,
            ],
        ];
    }

    public static function schreibweg(): ?string {
        return null;
    }

    public static function checked_constants(): array {
        // Die 34 Konstanten aus mod/assign/locallib.php ohne aufrufbare
        // Wertemenge (Spec 0015 §11, Ticket #382/#399). Genau eine Ausnahme:
        // ASSIGN_MARKER_FILTER_NO_MARKER ist eine Filter-UI-Kennung der
        // Bewertungstabelle, kein Feldwert einer Instanz - deshalb absichtlich
        // nicht mitgezaehlt.
        return [
            'ASSIGN_SUBMISSION_STATUS_NEW',
            'ASSIGN_SUBMISSION_STATUS_REOPENED',
            'ASSIGN_SUBMISSION_STATUS_DRAFT',
            'ASSIGN_SUBMISSION_STATUS_SUBMITTED',
            'ASSIGN_FILTER_NONE',
            'ASSIGN_FILTER_SUBMITTED',
            'ASSIGN_FILTER_NOT_SUBMITTED',
            'ASSIGN_FILTER_SINGLE_USER',
            'ASSIGN_FILTER_REQUIRE_GRADING',
            'ASSIGN_FILTER_GRADED',
            'ASSIGN_FILTER_GRANTED_EXTENSION',
            'ASSIGN_FILTER_DRAFT',
            'ASSIGN_ATTEMPT_REOPEN_METHOD_NONE',
            'ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL',
            'ASSIGN_ATTEMPT_REOPEN_METHOD_AUTOMATIC',
            'ASSIGN_ATTEMPT_REOPEN_METHOD_UNTILPASS',
            'ASSIGN_UNLIMITED_ATTEMPTS',
            'ASSIGN_GRADE_NOT_SET',
            'ASSIGN_GRADING_STATUS_GRADED',
            'ASSIGN_GRADING_STATUS_NOT_GRADED',
            'ASSIGN_MARKING_WORKFLOW_STATE_NOTMARKED',
            'ASSIGN_MARKING_WORKFLOW_STATE_INMARKING',
            'ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW',
            'ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW',
            'ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE',
            'ASSIGN_MARKING_WORKFLOW_STATE_RELEASED',
            'ASSIGN_MAX_EVENT_LENGTH',
            'ASSIGN_INTROATTACHMENT_FILEAREA',
            'ASSIGN_ACTIVITYATTACHMENT_FILEAREA',
            'ASSIGN_EVENT_TYPE_DUE',
            'ASSIGN_EVENT_TYPE_GRADINGDUE',
            'ASSIGN_EVENT_TYPE_OPEN',
            'ASSIGN_EVENT_TYPE_CLOSE',
            'ASSIGN_EVENT_TYPE_EXTENSION',
        ];
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
