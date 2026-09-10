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
        ]);
    }

    /**
     * @param string $path
     * @param string $content
     * @return array
     * @throws \moodle_exception invalidcontextpath, contextfilenotmarkdown,
     *         contextfiletoolarge, contextfilelocked, contextquotaexceeded
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(string $path, string $content): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'path' => $path,
            'content' => $content,
        ]);

        $context = context_files::own_context();
        self::validate_context($context);
        context_files::require_manage_own_files();

        [$directory, $filename] = context_files::resolve_writable_file($params['path']);
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
