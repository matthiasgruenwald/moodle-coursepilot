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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kurspilot\context_files;
use local_kurspilot\personal_data;
use local_kurspilot\pointer_location;

defined('MOODLE_INTERNAL') || die();

/**
 * Haengt Inhalt an eine Datei im Kontextbereich der aufrufenden Lehrkraft an
 * (Issue #409, Spec 0016 §4.2).
 *
 * Dieselbe Reihenfolge wie {@see write_context_file}: erst alle Absagen
 * (Pfad, Endung, Groesse, Personenbezug der Zieldatei, Quote), dann genau
 * ein Schreibvorgang. Der Unterschied zum Schreiben ist die Stelle, an der
 * gelesen wird: Lesen, Zusammenfuegen und Schreiben passieren in einem
 * Serveraufruf, die Lehrkraft muss die Datei also nicht vorher lesen. Deshalb
 * auch kein `expected_contenthash` - der Aufrufer hat keinen Stand, gegen den
 * er pruefen koennte.
 *
 * Was das *nicht* heisst: Spec 0016 §5.3 verbietet Locks, zwei wirklich
 * gleichzeitige Appends koennen einander daher weiterhin verlieren. Der
 * Kontextbereich gehoert genau einer Person, gleichzeitiges Schreiben ist dort
 * der Ausnahmefall, und ein Lock waere die teurere Antwort darauf. Die
 * Transaktion in {@see \local_kurspilot\storage_anchor::replace()} (aufgerufen
 * ueber {@see context_files::append()}) sichert nur das Naheliegende zu: kein
 * halb geschriebener Zustand aus einem abgebrochenen Vorgang.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class append_context_file extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Dateipfad relativ zum Kontextbereich, z.B. "journal.md"'),
            'content' => new external_value(PARAM_RAW, 'Anzuhaengender Inhalt'),
            'ausstand' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: Kennung eines offenen Ausstands (aus kurspilot_list_skills) - gelingt das Schreiben, '
                    . 'verschwindet der Eintrag im selben Aufruf',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $ausstand
     * @return array
     * @throws \moodle_exception invalidcontextpath, contextfilenotmarkdown,
     *         contextfiletoolarge, contextfilelocked, contextquotaexceeded
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(string $path, string $content, string $ausstand = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'path' => $path,
            'content' => $content,
            'ausstand' => $ausstand,
        ]);

        $context = context_files::own_context();
        self::validate_context($context);

        $content = $params['content'];
        $addedsize = strlen($content);

        // Harte Grenze je Vorgang - sie gilt fuer das Anhaengsel, nicht fuer
        // die Zieldatei (Spec 0016 §5.2).
        if ($addedsize > context_files::MAX_WRITE_BYTES) {
            throw new \moodle_exception('contextfiletoolarge', 'local_kurspilot', '', (object) [
                'size' => $addedsize,
                'max' => context_files::MAX_WRITE_BYTES,
            ]);
        }

        // Zeigerbewusst (Issue #491, Spec #486 §6): siehe write_context_file
        // fuer die Begruendung der getrennten Zweige.
        $location = context_files::resolve_pointer_location();
        if ($location !== null && $location->kind === pointer_location::EXTERN) {
            return self::execute_external($params['path'], $content, $params['ausstand']);
        }

        context_files::require_manage_own_files();

        [$directory, $filename] = context_files::resolve_writable_file($params['path']);

        $existing = context_files::read_content($directory, $filename);

        // Geprueft wird die Markierung der Zieldatei, nicht das Anhaengsel
        // (Spec 0016 §5.5): ohne diesen Schritt liesse sich die #344-Grenze
        // mit einem Append umgehen - Journal als personenbezogen markiert,
        // Schalter aus, Kurspilot schreibt trotzdem weiter hinein. Keine
        // Zieldatei = kein Frontmatter = kein Personenbezug.
        // ponytail: kein Frontmatter-Test auf dem Anhaengsel - Spec 0016 §5.5
        // beauftragt ausdruecklich nur die Zieldatei.
        if ($existing && !personal_data::allowed()
                && personal_data::is_marked($existing['content'])) {
            throw new \moodle_exception('contextfilelocked', 'local_kurspilot', '', $params['path']);
        }

        context_files::require_quota($addedsize);

        $newsize = context_files::append($directory, $filename, $content);
        self::dismiss_ausstand($params['ausstand']);

        $relativepath = context_files::relative_file($directory, $filename);
        $message = $existing
            ? get_string('contextfileappended', 'local_kurspilot', (object) [
                'path' => $relativepath,
                'size' => $newsize,
            ])
            : get_string('contextfilecreated', 'local_kurspilot', $relativepath);

        // Weiches Signal statt hartem Ende: ein Limit auf der Zieldatei
        // wuerde das Journal mitten im Schuljahr abwuergen. Die Rotation ist
        // Skill-Sache, das Plugin gibt nur den Hinweis (Spec 0016 §5.2/§8.4).
        if ($newsize > context_files::MAX_WRITE_BYTES) {
            $message .= ' ' . get_string('contextfilerotation', 'local_kurspilot');
        }

        return [
            'path' => $relativepath,
            'created' => !$existing,
            'size' => $newsize,
            'message' => $message,
        ];
    }

    /**
     * Der externe Zweig (Issue #491, Spec #486 §4/§6): Read-modify-write mit
     * `If-Match`, siehe {@see \local_kurspilot\pointer_writer::append()}.
     * Personenbezug der Zieldatei wird ueber den pointer-bewussten Lesezweig
     * geprueft, denselben, den auch `read_context_file` benutzt.
     *
     * Der Rotationshinweis gilt extern als Pflicht (Spec §6): jedes Anhaengen
     * ueberträgt die ganze Datei zweimal, das weiche 1-MB-Signal des
     * Moodle-Zweigs reicht dafuer nicht - die Antwort traegt ihn deshalb bei
     * jedem externen Anhaengen, nicht erst ab der Groessengrenze.
     *
     * @param string $path
     * @param string $content
     * @param string $ausstand Optional: siehe {@see execute()}.
     * @return array
     */
    private static function execute_external(string $path, string $content, string $ausstand): array {
        $existing = context_files::read_content_pointer_aware($path);
        if ($existing && !personal_data::allowed() && personal_data::is_marked($existing['content'])) {
            throw new \moodle_exception('contextfilelocked', 'local_kurspilot', '', $path);
        }

        // Zugelassener Speicher (Issue #493, ADR 0021 §3): geprueft wird die
        // ganze entstehende Datei, nicht nur der bisherige Bestand - ein neu
        // angelegtes Anhaengsel kann die Markierung selbst erst mitbringen
        // (der Fall $existing === null).
        $finalcontent = ($existing['content'] ?? '') . $content;
        if (personal_data::is_marked($finalcontent)) {
            \local_kurspilot\personal_data_hosts::require_allowed_location(
                context_files::resolve_pointer_location(),
                $path
            );
        }

        $result = context_files::append_pointer_aware($path, $content);
        self::dismiss_ausstand($ausstand);

        $message = $result['created']
            ? get_string('contextfilecreated', 'local_kurspilot', $result['path'])
            : get_string('contextfileappended', 'local_kurspilot', (object) [
                'path' => $result['path'],
                'size' => $result['size'],
            ]);
        $message .= ' ' . get_string('contextfilerotation', 'local_kurspilot');

        return [
            'path' => $result['path'],
            'created' => $result['created'],
            'size' => $result['size'],
            'message' => $message,
        ];
    }

    /**
     * Hakt einen offenen Ausstand im selben Aufruf ab, in dem er gelingt
     * (Issue #492, ADR 0023 Punkt 3) - fuer beide Orte identisch, deshalb
     * hier statt in execute()/execute_external() dupliziert.
     *
     * @param string $ausstand Kennung oder leer (kein Nachtragen).
     */
    private static function dismiss_ausstand(string $ausstand): void {
        if ($ausstand !== '') {
            \local_kurspilot\ausstand_notice::dismiss($ausstand);
        }
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Aufgeloester Dateipfad, relativ zum Kontextbereich'),
            'created' => new external_value(PARAM_BOOL, 'true, wenn die Datei neu angelegt wurde'),
            'size' => new external_value(PARAM_INT, 'Gesamtgroesse der Datei nach dem Anhaengen, in Byte'),
            'message' => new external_value(PARAM_RAW, 'Aenderungsmeldung in Lehrkraft-Deutsch'),
        ]);
    }
}
