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
 * Legt eine Datei im Kontextbereich der aufrufenden Lehrkraft an oder
 * ueberschreibt sie vollstaendig (Issue #408, Spec 0016 §4.1).
 *
 * Reihenfolge ist Absicht: erst alle Absagen (Pfad, Endung, Groesse,
 * Personenbezug, Gleichzeitigkeit, Quote), dann genau ein Schreibvorgang in
 * einer Transaktion. Nichts wird angefasst, bevor nicht alles geprueft ist.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class write_context_file extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Dateipfad relativ zum Kontextbereich, z.B. "plan.md"'),
            'content' => new external_value(PARAM_RAW, 'Vollstaendiger neuer Dateiinhalt'),
            'expected_contenthash' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: contenthash aus dem letzten Lesen - passt er nicht, bricht der Vorgang ab',
                VALUE_DEFAULT,
                ''
            ),
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
     * @param string $expectedcontenthash
     * @return array
     * @throws \moodle_exception invalidcontextpath, contextfilenotmarkdown,
     *         contextfiletoolarge, contextfilelocked, contextfilechanged,
     *         contextquotaexceeded
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(string $path, string $content, string $expectedcontenthash = '', string $ausstand = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'path' => $path,
            'content' => $content,
            'expected_contenthash' => $expectedcontenthash,
            'ausstand' => $ausstand,
        ]);

        $context = context_files::own_context();
        self::validate_context($context);

        $content = $params['content'];
        $newsize = strlen($content);
        if ($newsize > context_files::MAX_WRITE_BYTES) {
            throw new \moodle_exception('contextfiletoolarge', 'local_kurspilot', '', (object) [
                'size' => $newsize,
                'max' => context_files::MAX_WRITE_BYTES,
            ]);
        }

        // Zeigerbewusst (Issue #491, Spec #486 §6): der externe Zweig kennt
        // weder Nutzerquote noch moodle/user:manageownfiles - "fuer den
        // Kontextbereich in Moodle bleibt alles wie heute" gilt wortwoertlich,
        // die beiden Zweige bleiben deshalb getrennt statt ineinander verwoben.
        $location = context_files::resolve_pointer_location();

        // Schalter fuer personenbezogene Kontextdaten (#344, ADR 0011):
        // geprueft wird die Markierung im zu schreibenden Inhalt, nicht der
        // Inhalt selbst (Spec 0016 §5.5) - fuer beide Orte identisch (Spec
        // #486 §6: "allowpersonaldata wirkt unveraendert am Inhalt").
        //
        // Zugelassener Speicher (Issue #493, ADR 0021 §3): unabhaengig vom
        // Schalter - eine markierte Datei geht nur in einen zugelassenen
        // Speicher, Private Files sind immer zugelassen (kein Zweig hier
        // noetig, sie sind nie EXTERN).
        if (personal_data::is_marked($content)) {
            if (!personal_data::allowed()) {
                throw new \moodle_exception('contextfilelocked', 'local_kurspilot', '', $params['path']);
            }
            \local_kurspilot\personal_data_hosts::require_allowed_location($location, $params['path']);
        }

        if ($location !== null && $location->kind === pointer_location::EXTERN) {
            return self::execute_external($params['path'], $content, $params['ausstand']);
        }

        context_files::require_manage_own_files();

        [$directory, $filename] = context_files::resolve_writable_file($params['path']);

        $existing = context_files::read_content($directory, $filename);
        $oldsize = $existing ? $existing['size'] : 0;

        // Auch die Zieldatei zaehlt: eine personenbezogen markierte Datei ist
        // bei ausgeschaltetem Schalter nicht lesbar - sie darf dann erst
        // recht nicht ueberschrieben werden. Sonst waere die #344-Grenze auf
        // dem zerstoerenden Weg offen, den sie auf dem lesenden schliesst
        // (dieselbe Begruendung wie Spec 0016 §4.2 fuer Append).
        if ($existing && !personal_data::allowed()
                && personal_data::is_marked($existing['content'])) {
            throw new \moodle_exception('contextfilelocked', 'local_kurspilot', '', $params['path']);
        }

        // Gleichzeitigkeitsschutz ohne Locks (Spec 0016 §5.3): eine fehlende
        // Datei ist ebenfalls ein Konflikt - sie wurde zwischendurch geloescht.
        if ($params['expected_contenthash'] !== ''
                && (!$existing || $existing['contenthash'] !== $params['expected_contenthash'])) {
            throw new \moodle_exception('contextfilechanged', 'local_kurspilot', '', $params['path']);
        }

        context_files::require_quota($newsize - $oldsize);

        context_files::write($directory, $filename, $content);
        self::dismiss_ausstand($params['ausstand']);

        $relativepath = context_files::relative_file($directory, $filename);
        $message = $existing
            ? get_string('contextfileoverwritten', 'local_kurspilot', (object) [
                'path' => $relativepath,
                'before' => $oldsize,
                'after' => $newsize,
            ])
            : get_string('contextfilecreated', 'local_kurspilot', $relativepath);

        return [
            'path' => $relativepath,
            'created' => !$existing,
            'size' => $newsize,
            'message' => $message,
        ];
    }

    /**
     * Der externe Zweig (Issue #491, Spec #486 §4/§6): bedingtes Anlegen/
     * Ueberschreiben ueber WebDAV, siehe {@see \local_kurspilot\pointer_writer::write()}.
     * Groessen- und Personenbezugspruefung sind bereits im Aufrufer erledigt -
     * fuer beide Orte identisch, deshalb dort statt hier.
     *
     * @param string $path
     * @param string $content
     * @param string $ausstand Optional: siehe {@see execute()}.
     * @return array
     */
    private static function execute_external(string $path, string $content, string $ausstand): array {
        $result = context_files::write_pointer_aware($path, $content);
        self::dismiss_ausstand($ausstand);

        $message = $result['created']
            ? get_string('contextfilecreated', 'local_kurspilot', $result['path'])
            : get_string('contextfileoverwritten', 'local_kurspilot', (object) [
                'path' => $result['path'],
                'before' => $result['oldsize'],
                'after' => $result['size'],
            ]);

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
            'size' => new external_value(PARAM_INT, 'Neue Dateigroesse in Byte'),
            'message' => new external_value(PARAM_RAW, 'Aenderungsmeldung in Lehrkraft-Deutsch'),
        ]);
    }
}
