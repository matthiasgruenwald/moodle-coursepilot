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
 * Der aufgeloeste Zustand eines Pointer-Ziels (Kontextbereich oder
 * Materialbestand) - Kontextpointer, zweite Fassung (Issue #490, Spec #486
 * §2): entweder *in Moodle* mit einem fertig aufgeloesten Private-Files-Pfad,
 * oder *extern* mit Instanz-ID, relativem Pfad und dem beim Waehlen erfassten
 * Pruefmerkmal (Server, Basispfad, Konto). Reines Werteobjekt, keine Logik -
 * {@see context_pointer} baut es, {@see storage_anchor} und
 * {@see \local_kurspilot\webdav\webdav_instance} lesen es.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pointer_location {

    /** @var string Ziel liegt in Moodles Private Files. */
    public const MOODLE = 'moodle';

    /** @var string Ziel liegt in einer WebDAV-Nutzerinstanz. */
    public const EXTERN = 'extern';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $path = null,
        public readonly ?int $instanceid = null,
        public readonly ?string $relativepath = null,
        public readonly ?array $fingerprint = null,
    ) {
    }

    /**
     * @param string $path Immer mit fuehrendem und abschliessendem "/".
     * @return self
     */
    public static function moodle(string $path): self {
        return new self(self::MOODLE, path: $path);
    }

    /**
     * @param int $instanceid `repository_instances.id` der WebDAV-Nutzerinstanz.
     * @param string $relativepath Gewaehlter Ordner, relativ zum Basispfad der Instanz.
     * @param array{server: string, basispfad: string, konto: string} $fingerprint
     *        Stand von Server/Basispfad/Konto zum Zeitpunkt der Wahl (Spec §2).
     * @return self
     */
    public static function extern(int $instanceid, string $relativepath, array $fingerprint): self {
        return new self(self::EXTERN, instanceid: $instanceid, relativepath: $relativepath, fingerprint: $fingerprint);
    }

    /**
     * Vergleichsschluessel fuer die Ueberschneidungspruefung zwischen
     * Kontextbereich und Materialbestand (Issue #495, Spec #486 §2 Pruefung
     * 7): Server + Konto + effektiver Pfad, normalisiert mit abschliessendem
     * "/" - ortsunabhaengig, ein Moodle-Ziel und ein externes Ziel ueberschneiden
     * sich nie (unterschiedliches Praefix). Zwei Moodle-Ziele teilen sich
     * dieselbe Person/denselben Server per Definition (beide Bereiche liegen
     * immer in den Private Files derselben Lehrkraft).
     *
     * @param string $subpath Zusaetzlicher Unterpfad ab diesem Ort, bereits
     *        segmentgeprueft (z.B. ueber {@see storage_anchor::normalise_client_path()}).
     * @return string
     */
    public function comparison_key(string $subpath = ''): string {
        if ($this->kind === self::MOODLE) {
            return 'moodle|' . self::normalised_path((string) $this->path, $subpath);
        }
        $server = strtolower((string) ($this->fingerprint['server'] ?? ''));
        $konto = (string) ($this->fingerprint['konto'] ?? '');
        return 'extern|' . $server . '|' . $konto . '|' . self::normalised_path((string) $this->relativepath, $subpath);
    }

    /**
     * @param string $base
     * @param string $subpath
     * @return string Immer mit fuehrendem und abschliessendem "/", Wurzel als "/".
     */
    private static function normalised_path(string $base, string $subpath): string {
        $combined = trim($base, '/') . ($subpath !== '' ? '/' . trim($subpath, '/') : '');
        $trimmed = trim($combined, '/');
        return $trimmed === '' ? '/' : '/' . $trimmed . '/';
    }
}
