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

namespace local_kurspilot\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_kurspilot\activity_file_trash;
use local_kurspilot\catalog\registry;
use local_kurspilot\catalog\shared_block;
use local_kurspilot\history\version_history;
use local_kurspilot\quiz\arrangement;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * "Vor drei Versionen war das besser" als ausfuehrbarer Schreibvorgang
 * (Spec 0015 §10.7, Ticket #395, Phase 4): der alte Stand wird als neue
 * juengste Version fortgeschrieben, statt die Aktivitaet zurueckzuspulen
 * oder als Sicherungskopie zu duplizieren - cmid bleibt stabil, Links und
 * Voraussetzungen bleiben gueltig.
 *
 * Kein eigener Schreibmechanismus: der Zielstand wird in zwei Patches
 * zerlegt und ausschliesslich ueber die bestehenden Schreibwege gesetzt -
 * die "normalen" Instanzfelder ueber {@see update_module_settings::execute()},
 * die fuenf Vervollstaendigungsfelder ueber {@see set_completion::execute()}
 * (Ticket #392, der einzige Schreibweg dafuer). Damit erbt dieser Endpunkt
 * automatisch jede Validierung, jede Nebenwirkungsmeldung und den
 * course_module_updated-Beobachter, der den Rueckschreibvorgang selbst als
 * neue Version erfasst (#385) - keine Sonderbehandlung noetig.
 *
 * Schutzschiene Vervollstaendigung (Spec 0015 §8): die Abschlussfelder
 * laufen ueber genau denselben Zweitakt wie jeder andere set_completion()-
 * Aufruf (Ticket #392) - "bestaetigt" wird unveraendert durchgereicht, statt
 * an dieser Stelle hart auf true gesetzt zu werden. Wuerde das Schreiben
 * bestehende Abschlussdaten von Lernenden loeschen und ist "bestaetigt"
 * nicht gesetzt, schreibt set_completion nichts und meldet die
 * Betroffenenzahl - genau diese Meldung erscheint in der Antwort dieses
 * Endpunkts (statt einer eigenen, schwaecheren Warnung). Ohne
 * Datenverlustrisiko (keine vorhandenen Abschlussdaten, oder nur
 * "completionexpected" weicht ab) laeuft die Rueckkehr wie jeder andere
 * set_completion()-Aufruf sofort durch - "completionunlocked wird nie
 * automatisch angewandt" ist set_completion's eigene Regel, die dieser
 * Endpunkt unveraendert erbt, statt sie zu verschaerfen oder zu umgehen.
 *
 * Eigene Faehigkeit local/kurspilot:restoreversion statt local/kurspilot:use
 * (Spec 0015 §10.7: die Rueckkehr ist ein eigenstaendiger, folgenreicher
 * Schreibvorgang) - das eigentliche Zurueckschreiben verlangt zusaetzlich
 * moodle/course:manageactivities ueber die aufgerufenen Endpunkte.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restore_activity_version extends external_api {

    /**
     * Die Vervollstaendigungsfelder (identisch zu
     * {@see set_completion}::ALLOWED_FIELDS plus dessen modulspezifischen
     * Feldern, Ticket #461) - hier separat gefuehrt, weil set_completion sie
     * als private Konstanten haelt und dieser Endpunkt sie nur braucht, um sie
     * aus dem generischen Patch herauszuhalten und getrennt zu behandeln.
     * "completionsubmit" steht bei jeder anderen Aktivitaetsart gar nicht erst
     * im Versionsstand, faellt dort also schon ueber die
     * array_key_exists()-Pruefung heraus.
     *
     * @var string[]
     */
    private const COMPLETION_FIELDS = [
        'completion',
        'completionview',
        'completionusegrade',
        'completionpassgrade',
        'completionexpected',
        'completionsubmit',
    ];

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID der Aktivitaet'),
            'zielversion' => new external_value(PARAM_INT, 'Versionsnummer, auf die zurueckgeschrieben werden soll'),
            'bestaetigt' => new external_value(
                PARAM_BOOL,
                'true bestaetigt ausdruecklich das Loeschen bestehender Abschlussdaten der Lernenden, falls das '
                    . 'Zurueckschreiben der Abschlussfelder das ausloesen wuerde (Zweitakt von set_completion, '
                    . 'Ticket #392). Ohne Datenverlustrisiko wirkt sich dieser Parameter nicht aus. Beim ersten '
                    . 'Aufruf weglassen oder false.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param int $cmid
     * @param int $zielversion
     * @param bool $bestaetigt
     * @return array
     */
    public static function execute(int $cmid, int $zielversion, bool $bestaetigt = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'zielversion' => $zielversion,
            'bestaetigt' => $bestaetigt,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/kurspilot:restoreversion', $context);
        // Native Berechtigungspruefung vorgezogen, wie bei jedem anderen
        // Schreibwerkzeug (Spec 0015 §3.3) - die eigentlichen Schreibaufrufe
        // (update_module_settings/set_completion) pruefen sie ohnehin erneut.
        require_capability('moodle/course:manageactivities', $context);

        $modname = (string) $cm->modname;

        // quiz hat laut ADR 0016 einen eigenen Schreibweg fuer Einstellungen
        // (update_quiz_settings) - genau wie jeder andere Aufruf mit einem
        // Katalog-Schreibweg (siehe self::catalog_for()) ist der Feld-Patch
        // dieses Endpunkts fuer quiz deshalb blockiert, unveraendert seit
        // Ticket #395/#385 (Abnahmekriterium 7). Was #396 NEU hinzufuegt, ist
        // unabhaengig davon: die Anordnung (Slots/Fragereferenzen/Abschnitte/
        // Feedback) - dafuer braucht es weder den Feldkatalog noch
        // update_module_settings.
        if ($modname === 'quiz') {
            return self::execute_quiz_arrangement_only($cm, $params['zielversion']);
        }

        $catalogclass = self::catalog_for($modname);

        $target = version_history::state_at($params['cmid'], $params['zielversion']);
        $before = self::read_settings($params['cmid']);

        $normalpatch = self::build_normal_patch($catalogclass, $before, $target);
        $completionpatch = self::build_completion_patch($before, $target);

        $changes = [];
        if ($normalpatch) {
            $result = update_module_settings::execute($params['cmid'], json_encode($normalpatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $changes = array_merge($changes, $result['aenderungen']);
        }

        $restoredfiles = self::restore_files($cm, $context, $params['zielversion']);

        $completionwarning = null;
        if ($completionpatch) {
            try {
                $result = set_completion::execute(
                    $params['cmid'],
                    json_encode($completionpatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $params['bestaetigt']
                );
                $changes = array_merge($changes, $result['aenderungen']);
            } catch (moodle_exception $e) {
                // set_completion's eigener Zweitakt greift (Ticket #392): ohne
                // Bestaetigung UND echtem Datenverlustrisiko schreibt es nichts
                // und meldet die Betroffenenzahl - genau die "Datenverlust-
                // Warnung", die dieser Endpunkt wiederverwenden soll statt
                // selbst eine zu erfinden. Alles andere (z.B. Vervollstaendigung
                // im Kurs deaktiviert) ist ein echter Fehler dieses Aufrufs.
                if ($e->errorcode !== 'completiondatalossconfirmationrequired') {
                    throw $e;
                }
                $completionwarning = $e->getMessage();
            }
        }

        return [
            'cmid' => $params['cmid'],
            'modname' => $modname,
            'meldung' => self::build_message($params['zielversion'], $changes, $completionwarning, null, $restoredfiles),
            'aenderungen' => $changes,
        ];
    }

    /**
     * Holt Dateien zurueck, die der Zielstand in einem freigeschalteten
     * Materialreferenz-Feld hatte (Spec 0018 §9.1, Issue #432): fuer jedes
     * {@see update_module_settings::material_reference_specs()}-Feld dieser
     * Aktivitaetsart wird verglichen, welche Zieldateien (aus
     * {@see version_history::files_at()}, nur gap=0-Zeilen - die anderen
     * sind eine dokumentierte Luecke) aktuell fehlen. Fehlt eine und liegt
     * sie im Papierkorb ({@see activity_file_trash::find_for_restore()}),
     * wird sie zurueckgeschrieben - alle unveraendert gebliebenen Dateien
     * bleiben unangetastet. Fehlt eine Zieldatei UND ist sie nicht im
     * Papierkorb auffindbar, bleibt es bei der bestehenden Luecke (kein
     * Fehler, keine Regression).
     *
     * @param \stdClass $cm
     * @param \context_module $context
     * @param int $zielversion
     * @return string[] Dateinamen, die tatsaechlich wiederhergestellt wurden.
     */
    private static function restore_files(\stdClass $cm, context_module $context, int $zielversion): array {
        $modname = (string) $cm->modname;
        $specs = update_module_settings::material_reference_specs($modname);
        if (!$specs) {
            return [];
        }

        $targetfiles = version_history::files_at((int) $cm->id, $zielversion);
        $fs = get_file_storage();
        $restoredfilenames = [];

        foreach ($specs as $fieldname => $spec) {
            $wanted = array_filter($targetfiles, static fn(\stdClass $f): bool =>
                $f->component === $spec['component'] && $f->filearea === $spec['filearea'] && (int) $f->gap === 0);
            if (!$wanted) {
                continue;
            }

            $tobuild = [];
            $changedfilenames = [];
            foreach ($wanted as $target) {
                $current = $fs->get_file($context->id, $spec['component'], $spec['filearea'], 0, '/', $target->filename);
                if ($current && $current->get_contenthash() === $target->contenthash) {
                    // Schon der Zielstand - unangetastet mitnehmen.
                    $tobuild[] = $current;
                    continue;
                }

                $fromtrash = activity_file_trash::find_for_restore(
                    $context->id,
                    (int) $cm->id,
                    $target->filename,
                    $target->contenthash
                );
                if ($fromtrash) {
                    $tobuild[] = $fromtrash;
                    $changedfilenames[] = $target->filename;
                } else if ($current) {
                    // Nicht im Papierkorb auffindbar (z. B. nie ersetzt,
                    // Inhalt weicht aber trotzdem ab) - lieber den
                    // vorhandenen Ist-Stand behalten als die Datei ganz zu
                    // verlieren.
                    $tobuild[] = $current;
                }
            }

            if (!$changedfilenames) {
                // Nichts weicht tatsaechlich ab - kein unnoetiger Schreibvorgang.
                continue;
            }

            $draftitemid = activity_file_trash::resolve_restore_into_draft(
                $context->id,
                $spec['component'],
                $spec['filearea'],
                $tobuild
            );
            update_module_settings::write_pseudofield_draft((int) $cm->id, $fieldname, $draftitemid);
            $restoredfilenames = array_merge($restoredfilenames, $changedfilenames);
        }

        return $restoredfilenames;
    }

    /**
     * Der komplette Rueckschreibvorgang fuer quiz (#396): NUR die Anordnung,
     * kein Feld-Patch (siehe Klassendoku dieser Methode-Aufrufstelle in
     * {@see self::execute()} - ADR 0016, Abnahmekriterium 7 "Einstellungen
     * bleiben unveraendert wie in Ticket 07"). Gibt es keine abweichende
     * Anordnung zum Zielstand, bleibt die Meldung dieselbe wie fuer jeden
     * anderen Aufruf dieses Endpunkts fuer quiz: "schreibvehicleblocked" -
     * dieser Endpunkt kann fuer quiz grundsaetzlich nichts anderes als die
     * Anordnung zurueckschreiben.
     *
     * @param \stdClass $cm
     * @param int $zielversion
     * @return array
     * @throws moodle_exception arrangementrestoreblocked, wenn der Test bereits Versuche hat und
     *         die Anordnung abweicht; writevehicleblocked, wenn die Anordnung nicht abweicht.
     */
    private static function execute_quiz_arrangement_only(\stdClass $cm, int $zielversion): array {
        $arrangementmessage = self::restore_quiz_arrangement($cm, $zielversion);
        if ($arrangementmessage === null) {
            throw new moodle_exception('writevehicleblocked', 'local_kurspilot', '', [
                'modname' => 'quiz',
                'schreibweg' => 'update_quiz_settings',
            ]);
        }

        return [
            'cmid' => (int) $cm->id,
            'modname' => 'quiz',
            'meldung' => self::build_message($zielversion, [], null, $arrangementmessage),
            'aenderungen' => [],
        ];
    }

    /**
     * Schreibt den Anordnungs-Stand (Ticket #396) zurueck, falls der
     * Zielstand eine abweichende Anordnung hat. Fehlt einem aelteren Stand
     * die arrangement_json (vor #396 angelegt) oder stimmt die Anordnung
     * bereits mit dem Ist-Stand ueberein, wird nichts unternommen - das
     * gehoert zu den dokumentierten Verlaufsluecken
     * ({@see version_history} GAPS_HINT).
     *
     * @param \stdClass $cm
     * @param int $zielversion
     * @return string|null Lehrkraft-deutscher Zusatzsatz fuer die Antwort, oder null ohne Anordnungsaenderung.
     * @throws moodle_exception arrangementrestoreblocked, wenn der Test bereits Versuche hat.
     */
    private static function restore_quiz_arrangement(\stdClass $cm, int $zielversion): ?string {
        $target = version_history::arrangement_at((int) $cm->id, $zielversion);
        if ($target === null) {
            return null;
        }

        $quizid = (int) $cm->instance;
        if (!arrangement::differs(arrangement::capture($quizid), $target)) {
            return null;
        }

        // Wirft arrangementrestoreblocked VOR jedem Schreibversuch, wenn der
        // Test bereits Versuche hat (Schutzschiene Versuche, #396) - keine
        // abgefangene Exception der Core-API.
        arrangement::restore($quizid, $target);

        return 'Die Fragenanordnung wurde ebenfalls auf Version ' . $zielversion . ' zurückgeschrieben. '
            . 'Hinweis: Fragen erscheinen dabei in der jeweils neuesten Fassung, keine Version wird nachträglich gepinnt.';
    }

    /**
     * Identisch zu {@see update_module_settings::catalog_for()} - dupliziert
     * statt geteilt (beide Klassen bleiben eigenstaendig lesbar, siehe
     * Klassendoku dieser Datei "kein eigener Schreibmechanismus").
     *
     * @param string $modname
     * @return class-string<\local_kurspilot\catalog\module_catalog>
     * @throws moodle_exception unknownmodname|writevehicleblocked
     */
    private static function catalog_for(string $modname): string {
        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            throw new moodle_exception(
                'unknownmodname',
                'local_kurspilot',
                '',
                ['modname' => $modname, 'aktivitaetsarten' => implode(', ', registry::known_modnames())]
            );
        }
        $schreibweg = $catalogclass::schreibweg();
        if ($schreibweg !== null) {
            throw new moodle_exception(
                'writevehicleblocked',
                'local_kurspilot',
                '',
                ['modname' => $modname, 'schreibweg' => $schreibweg]
            );
        }
        return $catalogclass;
    }

    /**
     * Ist-Stand als assoziatives Array, dieselbe Form wie
     * {@see version_history::state_at()} liefert - fuer den Vorher-/
     * Nachher-Vergleich beim Patch-Bau.
     *
     * @param int $cmid
     * @return array
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * Alle Nicht-Vervollstaendigungsfelder, die sich zwischen $before und
     * $target tatsaechlich unterscheiden - genau die Feldmenge, die
     * update_module_settings als Patch akzeptiert (gemeinsamer Block plus
     * Katalogfelder/-pseudofelder, ohne Sperrliste). Ein Katalogfeld, das im
     * Zielstand unter einem anderen Schluessel liegt (z.B. "sectionnum" -
     * der Zielstand kennt nur "section"), bleibt automatisch aussen vor statt
     * fehlzuschreiben.
     *
     * @param class-string<\local_kurspilot\catalog\module_catalog> $catalogclass
     * @param array $before
     * @param array $target
     * @return array
     */
    private static function build_normal_patch(string $catalogclass, array $before, array $target): array {
        $blocklist = array_unique(array_merge(shared_block::BLOCKLIST, $catalogclass::blocklist()));
        $fields = array_merge(shared_block::fields(), $catalogclass::fields(), $catalogclass::pseudofields());

        $patch = [];
        foreach ($fields as $field) {
            $name = $field->name;
            if (in_array($name, $blocklist, true) || !array_key_exists($name, $target)) {
                continue;
            }
            $newvalue = $target[$name];
            if (($before[$name] ?? null) != $newvalue) {
                $patch[$name] = $newvalue;
            }
        }
        return $patch;
    }

    /**
     * Die Vervollstaendigungsfelder, die sich zwischen $before und $target
     * tatsaechlich unterscheiden - unabhaengig von "bestaetigt": ob sie
     * tatsaechlich geschrieben werden, entscheidet {@see self::execute()}.
     *
     * @param array $before
     * @param array $target
     * @return array
     */
    private static function build_completion_patch(array $before, array $target): array {
        $patch = [];
        foreach (self::COMPLETION_FIELDS as $name) {
            if (!array_key_exists($name, $target)) {
                continue;
            }
            $newvalue = (int) $target[$name];
            if ((int) ($before[$name] ?? 0) !== $newvalue) {
                $patch[$name] = $newvalue;
            }
        }
        return $patch;
    }

    /**
     * Die Lehrkraft-deutsche Aenderungsmeldung (Spec 0015: "die Antwort ist
     * die Aenderungsmeldung") - haengt set_completion's echte
     * Datenverlust-Warnung (mit Betroffenenzahl) an, statt eine eigene,
     * schwaechere Meldung zu erfinden.
     *
     * @param int $zielversion
     * @param array $changes
     * @param string|null $completionwarning set_completion's Meldung, wenn dessen
     *        eigener Zweitakt das Schreiben der Abschlussfelder verhindert hat.
     * @param string|null $arrangementmessage Zusatzsatz von {@see self::restore_quiz_arrangement()}.
     * @param string[] $restoredfiles Dateinamen, die {@see self::restore_files()} aus dem
     *        Papierkorb zurueckgeholt hat (Spec 0018 §9.1, Issue #432).
     * @return string
     */
    private static function build_message(
        int $zielversion,
        array $changes,
        ?string $completionwarning,
        ?string $arrangementmessage = null,
        array $restoredfiles = []
    ): string {
        if (!$changes && !$restoredfiles && $arrangementmessage === null) {
            $base = 'Keine Änderung: die Aktivität entspricht bereits Version ' . $zielversion . '.';
        } else if (!$changes && !$restoredfiles) {
            $base = 'Auf Version ' . $zielversion . ' zurückgeschrieben - keine Einstellungsfelder abweichend.';
        } else {
            $parts = [];
            foreach ($changes as $change) {
                $parts[] = '"' . $change['feld'] . '" von ' . $change['von_json'] . ' auf ' . $change['auf_json'];
            }
            $base = $parts
                ? ('Auf Version ' . $zielversion . ' zurückgeschrieben - der alte Stand wird zur neuen jüngsten '
                    . 'Version fortgeschrieben: ' . implode(', ', $parts) . '.')
                : ('Auf Version ' . $zielversion . ' zurückgeschrieben.');
        }

        if ($restoredfiles) {
            $base .= ' Datei' . (count($restoredfiles) === 1 ? '' : 'en') . ' aus dem Papierkorb wiederhergestellt: '
                . implode(', ', $restoredfiles) . '.';
        }

        if ($completionwarning !== null) {
            $base .= ' Abschlussfelder nicht mitgeschrieben: ' . $completionwarning
                . ' Erneuter Aufruf von restore_activity_version mit "bestaetigt": true schreibt sie ebenfalls zurück.';
        }

        if ($arrangementmessage !== null) {
            $base .= ' ' . $arrangementmessage;
        }

        return $base;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Aktivitaetstyp'),
            'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Aenderungsmeldung'),
            'aenderungen' => new external_multiple_structure(
                new external_single_structure([
                    'feld' => new external_value(PARAM_TEXT, 'Feldname'),
                    'von_json' => new external_value(PARAM_RAW, 'JSON-kodierter Wert vor dem Schreiben'),
                    'auf_json' => new external_value(PARAM_RAW, 'JSON-kodierter Wert nach dem Schreiben'),
                ]),
                'Je tatsaechlich geaendertem Feld ein Eintrag'
            ),
        ]);
    }
}
