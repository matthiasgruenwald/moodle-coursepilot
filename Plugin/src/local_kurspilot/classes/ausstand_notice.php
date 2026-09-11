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

namespace local_kurspilot;

/**
 * Die Ausstandsnotiz (Issue #492, ADR 0023, Spec #486 §8/§10): die
 * Aufzeichnung gescheiterter Schreibvorgaenge am festen Anker, neben dem
 * Kontextpointer ({@see storage_anchor::AUSSTAND_FILENAME}) - dort, wo
 * Kurspilot auch dann schreiben kann, wenn der externe Speicher schweigt
 * (CONTEXT.md "Ausstandsnotiz").
 *
 * Ein Eintrag je gescheitertem Vorgang, nie den Inhalt: Kennung, Zeitpunkt,
 * relativer Pfad, Vorgang (anlegen/ueberschreiben/anhaengen) und
 * Fehlerklasse. Verschwindet nur ausdruecklich - durch Nachtragen
 * ({@see pointer_writer}, ueber `ausstand=<Kennung>`) oder durch
 * ausdrueckliches Verwerfen ({@see \local_kurspilot\external\dismiss_ausstand}) -
 * nie durch Zeitablauf.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ausstand_notice {

    /**
     * Vermerkt einen gescheiterten Schreibvorgang und liefert die neu
     * vergebene Kennung.
     *
     * @param string $path Relativer Client-Pfad der Zieldatei, nie der Inhalt.
     * @param string $operation "anlegen", "ueberschreiben" oder "anhaengen".
     * @param string $errorclass Fehlerklasse (z.B. {@see \local_kurspilot\webdav\webdav_error}-Konstante
     *        oder ein webdavinstance*-Fehlerschluessel), nie ein Freitext.
     * @return string Neu vergebene Kennung.
     * @throws \moodle_exception ausstandnotequotaexceeded, wenn die Notiz selbst
     *         nicht mehr geschrieben werden kann (Private-Files-Quote voll).
     */
    public static function record(string $path, string $operation, string $errorclass): string {
        $entries = self::all();
        $kennung = self::generate_kennung($entries);
        $entries[$kennung] = [
            'zeitpunkt' => time(),
            'pfad' => $path,
            'vorgang' => $operation,
            'fehlerklasse' => $errorclass,
        ];
        self::save($entries);
        return $kennung;
    }

    /**
     * Verwirft einen Eintrag - genutzt sowohl beim Nachtragen (erfolgreiches
     * Schreiben mit `ausstand=<Kennung>`) als auch beim ausdruecklichen
     * Verwerfen durch die Lehrkraft ({@see \local_kurspilot\external\dismiss_ausstand}):
     * dieselbe Operation, zwei Anlaesse (ADR 0023 Punkt 3).
     *
     * @param string $kennung
     * @return bool true, wenn ein Eintrag mit dieser Kennung existierte und entfernt wurde.
     */
    public static function dismiss(string $kennung): bool {
        $entries = self::all();
        if (!isset($entries[$kennung])) {
            return false;
        }
        unset($entries[$kennung]);
        self::save($entries);
        return true;
    }

    /**
     * Alle offenen Eintraege, gebuendelt je Zieldatei, die aeltesten zuerst -
     * fuer den Handshake ({@see \local_kurspilot\external\list_skills}). Rein
     * lokal, ohne Netzzugriff: liest ausschliesslich die Notizdatei selbst.
     *
     * @return array<int, array{pfad: string, eintraege: array<int, array{
     *         kennung: string, zeitpunkt: int, vorgang: string, fehlerklasse: string}>}>
     */
    public static function list_grouped(): array {
        $bypath = [];
        foreach (self::all() as $kennung => $entry) {
            $bypath[$entry['pfad']][] = [
                'kennung' => $kennung,
                'zeitpunkt' => $entry['zeitpunkt'],
                'vorgang' => $entry['vorgang'],
                'fehlerklasse' => $entry['fehlerklasse'],
            ];
        }

        $groups = [];
        foreach ($bypath as $pfad => $eintraege) {
            usort($eintraege, static fn (array $a, array $b): int => $a['zeitpunkt'] <=> $b['zeitpunkt']);
            $groups[] = ['pfad' => $pfad, 'eintraege' => $eintraege];
        }
        usort($groups, static fn (array $a, array $b): int => $a['eintraege'][0]['zeitpunkt'] <=> $b['eintraege'][0]['zeitpunkt']);
        return $groups;
    }

    /**
     * Rohe Eintraege, Kennung => {zeitpunkt, pfad, vorgang, fehlerklasse}.
     * Leer, wenn keine Notizdatei existiert, keine Person angemeldet ist,
     * oder die Datei kein gueltiges JSON-Objekt enthaelt (ponytail: kein
     * eigener Reparaturpfad fuer eine von Hand kaputtgemachte Notizdatei -
     * sie wird plugin-intern geschrieben, ein defekter Bestand ist der
     * seltene Rand-fall, nicht der Normalfall).
     *
     * @return array<string, array{zeitpunkt: int, pfad: string, vorgang: string, fehlerklasse: string}>
     */
    private static function all(): array {
        global $USER;

        if (empty($USER->id)) {
            return [];
        }

        $file = get_file_storage()->get_file(
            storage_anchor::own_context()->id,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            storage_anchor::anchor_root(),
            storage_anchor::AUSSTAND_FILENAME
        );
        if (!$file) {
            return [];
        }

        $decoded = json_decode($file->get_content(), true);
        return (is_array($decoded) && !array_is_list($decoded)) ? $decoded : [];
    }

    /**
     * @param array<string, array{zeitpunkt: int, pfad: string, vorgang: string, fehlerklasse: string}> $entries
     * @throws \moodle_exception ausstandnotequotaexceeded
     */
    private static function save(array $entries): void {
        $content = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $contextid = storage_anchor::own_context()->id;
        $directory = storage_anchor::anchor_root();
        $existing = get_file_storage()->get_file(
            $contextid,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            $directory,
            storage_anchor::AUSSTAND_FILENAME
        );

        $oldsize = $existing ? $existing->get_filesize() : 0;
        $remaining = storage_anchor::remaining_quota();
        if ($remaining !== null && (strlen($content) - $oldsize) > $remaining) {
            throw new \moodle_exception('ausstandnotequotaexceeded', 'local_kurspilot');
        }

        storage_anchor::replace(
            $existing ?: null,
            storage_anchor::filerecord($contextid, $directory, storage_anchor::AUSSTAND_FILENAME),
            $content
        );
    }

    /**
     * @param array<string, mixed> $existing Bereits vergebene Kennungen (Schluessel).
     * @return string
     */
    private static function generate_kennung(array $existing): string {
        do {
            $kennung = strtoupper(random_string(8));
        } while (isset($existing[$kennung]));
        return $kennung;
    }
}
