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
 * Die Freigabeliste des Feldkatalogs (Spec 0015 §2.5): "eine Aktivitätsart
 * ist unterstützt, wenn ihr Katalog geprüft ist". Eine neue Art ist eine neue
 * Katalogdatei plus ein Eintrag hier, kein neuer Endpunkt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class registry {

    /**
     * modname => Katalogklasse.
     *
     * @var array<string, class-string<module_catalog>>
     */
    private const CATALOGS = [
        'label' => label::class,
        'page' => page::class,
        'url' => url::class,
        'folder' => folder::class,
        'resource' => resource::class,
        'choice' => choice::class,
        'forum' => forum::class,
        'assign' => assign::class,
        'quiz' => quiz::class,
    ];

    /**
     * Katalogisierte Modultypen, gleich ob per Vehikel oder Einzelwerkzeug
     * geschrieben (Spec 0015 §3.1: "fuer jeden katalogisierten Modultyp").
     *
     * @return string[]
     */
    public static function known_modnames(): array {
        return array_keys(self::CATALOGS);
    }

    /**
     * @param string $modname
     * @return module_catalog|null Die Katalogklasse selbst - null, wenn die
     *         Aktivitaetsart nicht gefuehrt wird.
     */
    public static function for(string $modname): ?string {
        return self::CATALOGS[$modname] ?? null;
    }

    /** Arten mit Fragen (Spec 0026): nie ueber Aktivitaets-XML. */
    private const EXCLUDED_QUESTIONS = ['lesson', 'quiz'];

    /** Arten mit Dateien im Inhalt (Spec 0026): Restore aus XML traegt keine Dateien. */
    private const EXCLUDED_FILES = ['scorm', 'imscp', 'h5pactivity'];

    /**
     * Art-Tor (ADR 0028): genau eine von drei Arten.
     *
     * Katalogisiert gewinnt vor Ausschluss (quiz hat einen Katalog).
     * Keine Positivliste: alles Uebrige ist erschlossen.
     *
     * @param string $modname
     * @return activity_kind
     */
    public static function kind(string $modname): activity_kind {
        $catalog = self::for($modname);
        if ($catalog !== null) {
            return new activity_kind(activity_kind::CATALOGUED, $catalog);
        }
        if (in_array($modname, self::EXCLUDED_QUESTIONS, true)) {
            return new activity_kind(activity_kind::EXCLUDED, null, 'kindexcludedquestions');
        }
        if (in_array($modname, self::EXCLUDED_FILES, true)) {
            return new activity_kind(activity_kind::EXCLUDED, null, 'kindexcludedfiles');
        }
        if (!plugin_supports('mod', $modname, FEATURE_BACKUP_MOODLE2, false)) {
            return new activity_kind(activity_kind::EXCLUDED, null, 'kindexcludednobackup');
        }
        return new activity_kind(activity_kind::DEVELOPED);
    }

    /**
     * @param string $modname
     * @return class-string<module_catalog> Die Katalogklasse.
     * @throws \moodle_exception unknownmodname, wenn die Art nicht katalogisiert ist.
     */
    public static function require_catalogued(string $modname): string {
        $catalog = self::for($modname);
        if ($catalog === null) {
            throw new \moodle_exception(
                'unknownmodname',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'aktivitaetsarten' => implode(', ', self::known_modnames())]
            );
        }
        return $catalog;
    }

    /**
     * Gate for the XML paths: only developed kinds pass.
     *
     * @param string $modname
     * @param string $cataloguedkey language key for a catalogued kind (local_coursepilot, takes $a->modname)
     * @throws \moodle_exception $cataloguedkey, or the exclusion reason of the kind
     */
    public static function require_developed(string $modname, string $cataloguedkey): void {
        $kind = self::kind($modname);
        if ($kind->kind === activity_kind::CATALOGUED) {
            throw new \moodle_exception($cataloguedkey, 'local_coursepilot', '', ['modname' => $modname]);
        }
        if ($kind->kind === activity_kind::EXCLUDED) {
            throw new \moodle_exception($kind->reasonkey, 'local_coursepilot');
        }
    }
}
