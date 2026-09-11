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
}
