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
 * Die Materialbestand-Werkzeugoperationen (Auflisten, Hochladen, Vorschau,
 * Loeschen), ortsneutral fuer den Aufrufer (Issue #539, Spec 0021): kein
 * Werkzeug in {@see \local_coursepilot\external\list_material_files} und
 * seinen Geschwistern entfernt mehr selbst ein internes "etag"-Feld oder
 * baut die Schreib-/Loeschchoreografie von Hand nach - diese Entscheidungen
 * wandern hier herein, genau wie {@see context_area} es fuer den
 * Kontextbereich tut (Issue #538).
 *
 * Anders als beim Kontextbereich kennt kein schreibendes Materialwerkzeug
 * einen externen Ort: jedes zielt ausschliesslich auf die Werkbank
 * ({@see material_files::werkbank_area()}), die immer in Moodles Private
 * Files liegt (siehe dortige Dokumentation). Schreiben/Loeschen laufen
 * deshalb unbedingt ueber den Ablage-Vertrag {@see storage_port}, adaptiert
 * durch {@see private_files_storage_port} - denselben Adapter, den auch
 * {@see context_area} fuer sein Private-Files-Schreiben nutzt.
 *
 * {@see \local_coursepilot\external\crop_material_file} schreibt sein Ziel
 * bewusst *nicht* ueber diese Fassade: der Zuschnitt braucht das Moodle-
 * eigene `source`-Dateisatzfeld (Bildausschnitt-Herkunft), ein rein
 * Moodle-spezifisches Metadatum ausserhalb des ortsneutralen Vertrags
 * (WebDAV kennt kein Aequivalent) - {@see storage_port} bleibt deshalb ohne
 * diesen Durchgriff, und crop_material_file bleibt bei
 * {@see material_files::write()} (unveraendert seit vor Issue #539, ohne
 * eigene Ortsverzweigung: die Werkbank ist ohnehin immer Moodle).
 *
 * Auflisten/Lesen des Materialbestands ("bestand") folgen weiterhin dem
 * Kontextpointer ueber {@see material_files::list_entries_for_ort()}/
 * {@see material_files::read_content_for_ort()} - dort liegt die
 * Ort-Entscheidung (Bestand/Werkbank, Moodle/extern) bereits ortsneutral,
 * dieselbe pointer_reader-Maschinerie, die auch der Kontextbereich extern
 * nutzt. {@see list()}/{@see read_for_ort()} sind duenne Fassaden darueber,
 * die nur noch die interne Nachbearbeitung ("etag" entfernen) hier statt im
 * Werkzeug erledigen.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class material_area {

    /**
     * Listet eine Ebene des angefragten Materialorts - ortsneutral: kein
     * Werkzeug sieht das nur intern gebrauchte "etag"-Feld mehr, das
     * {@see pointer_reader} fuer den externen Bestand durchreicht (Issue
     * #539, relocated aus {@see \local_coursepilot\external\list_material_files::execute()}).
     *
     * @param string $ort {@see material_files::ORT_BESTAND}/{@see material_files::ORT_WERKBANK}.
     * @param string $path
     * @return array{directory: string, entries: array}
     * @throws \moodle_exception wie {@see material_files::list_entries_for_ort()}.
     */
    public static function list(string $ort, string $path): array {
        $result = material_files::list_entries_for_ort($ort, $path);
        $result['entries'] = array_map(
            static function (array $entry): array {
                unset($entry['etag']);
                return $entry;
            },
            $result['entries']
        );
        return $result;
    }

    /**
     * Liest eine Datei des angefragten Materialorts - ortsneutral, fuer
     * {@see \local_coursepilot\external\preview_material_file} und den
     * Quell-Lesepfad von {@see \local_coursepilot\external\crop_material_file}
     * (Issue #539). Duenne Fassade ueber {@see material_files::read_content_for_ort()}:
     * die Ort-Entscheidung (Bestand/Werkbank, Moodle/extern) bleibt dort,
     * ortsneutral ueber {@see pointer_reader}.
     *
     * @param string $ort {@see material_files::ORT_BESTAND}/{@see material_files::ORT_WERKBANK}.
     * @param string $path
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     * @throws \moodle_exception wie {@see material_files::read_content_for_ort()}.
     */
    public static function read_for_ort(string $ort, string $path): ?array {
        return material_files::read_content_for_ort($ort, $path);
    }

    /**
     * Liest eine vorhandene Werkbank-Datei ueber den Anker, oder null, wenn
     * sie fehlt - fuer den Existenz-/Groessencheck vor einem Schreiben oder
     * Loeschen ({@see write()}, {@see delete()} und
     * {@see \local_coursepilot\external\delete_material_files}).
     *
     * @param string $path
     * @return array{content: string, checksum: string, size: int, mimetype: string,
     *         timemodified: int}|null
     * @throws \moodle_exception invalidmaterialpath
     */
    public static function read(string $path): ?array {
        return self::port()->read(material_files::werkbank_area(), $path);
    }

    /**
     * Schreibt eine Datei in die Werkbank ueber den Anker (Issue #539): der
     * Schreibweg fuer {@see \local_coursepilot\external\upload_material_file} -
     * Pfad-, Endungs- und Quotenpruefung sowie die Schreibchoreografie mit
     * Zwischendatei liegen im {@see private_files_storage_port}-Adapter, nicht
     * mehr im Werkzeug selbst. Fuer den Zuschnitt-Zielpfad siehe die
     * Klassendoc: {@see \local_coursepilot\external\crop_material_file}
     * braucht das Moodle-eigene `source`-Feld und bleibt deshalb bei
     * {@see material_files::write()}.
     *
     * @param string $path
     * @param string $content Vollstaendiger neuer Inhalt.
     * @param string $expectedcontenthash '' heisst ungeprueft ueberschreiben/anlegen.
     * @return array{path: string, created: bool, size: int, oldsize: int, warning: ?string}
     * @throws \moodle_exception materialfilechanged (Pruefwert-Konflikt),
     *         materialfiledisallowedtype, invalidmaterialpath, materialquotaexceeded
     */
    public static function write(string $path, string $content, string $expectedcontenthash = ''): array {
        $area = material_files::werkbank_area();
        $port = self::port();

        $existing = $port->read($area, $path);
        $oldsize = $existing['size'] ?? 0;

        try {
            $written = $port->write($area, $path, $content, $expectedcontenthash !== '' ? $expectedcontenthash : null);
        } catch (storage_conflict_exception $e) {
            throw new \moodle_exception('materialfilechanged', 'local_coursepilot', '', $path);
        }

        return [
            'path' => $written['path'],
            'created' => $written['created'],
            'size' => $written['size'],
            'oldsize' => $oldsize,
            'warning' => material_files::quota_warning($written['size'] - $oldsize),
        ];
    }

    /**
     * Loescht eine Werkbank-Datei ueber den Anker, falls sie existiert
     * (Issue #539, fuer {@see \local_coursepilot\external\delete_material_files}).
     *
     * @param string $path
     * @return bool true, wenn eine Datei geloescht wurde; false, wenn keine existierte.
     * @throws \moodle_exception invalidmaterialpath
     */
    public static function delete(string $path): bool {
        return self::port()->delete(material_files::werkbank_area(), $path);
    }

    /**
     * Der Ablage-Vertrag-Adapter dieser Fassade - immer Private Files
     * (Issue #539): die Werkbank kennt keinen externen Ort, siehe Klassendoc.
     *
     * @return storage_port
     */
    private static function port(): storage_port {
        return new private_files_storage_port();
    }
}
