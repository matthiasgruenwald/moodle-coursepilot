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
 * Catalog allowlist (Spec 0015 §2.5): an activity type is supported when
 * its catalog has been reviewed. A new type needs a catalog file and an
 * entry here, rather than a new endpoint.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class registry {
    /**
     * Module name to catalog class.
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
     * Cataloged module types, regardless of whether they use the generic
     * write route or a dedicated tool (Spec 0015 §3.1).
     *
     * @return string[]
     */
    public static function known_modnames(): array {
        return array_keys(self::CATALOGS);
    }

    /**
     * Returns the catalog class, or null when the activity type has no catalog.
     *
     * @param string $modname
     * @return module_catalog|null The catalog class, or null when the activity type has no catalog.
     */
    public static function for(string $modname): ?string {
        return self::CATALOGS[$modname] ?? null;
    }

    /**
     * Types containing questions (Spec 0026): never use activity XML.
     */
    private const EXCLUDED_QUESTIONS = ['lesson', 'quiz'];

    /**
     * Types with embedded files (Spec 0026): XML restore carries no files.
     * Excluded until file restoration (#598) supports them.
     */
    private const EXCLUDED_FILES = ['scorm', 'imscp', 'h5pactivity', 'lightboxgallery'];

    /**
     * Activity-kind gate (ADR 0028): exactly one of three kinds.
     *
     * A catalog takes precedence over exclusion (quiz has a catalog).
     * All remaining types are developed, without a separate positive list.
     *
     * @param string $modname
     * @return activity_kind
     */
    public static function kind(string $modname): activity_kind {
        global $DB;
        $catalog = self::for($modname);
        if ($catalog !== null) {
            return new activity_kind(activity_kind::CATALOGUED, $catalog);
        }
        if (in_array($modname, self::EXCLUDED_QUESTIONS, true)) {
            return new activity_kind(activity_kind::EXCLUDED, null, 'kindexcludedquestions');
        }
        if (
            in_array($modname, self::EXCLUDED_FILES, true)
                && !\local_coursepilot\activity_file_supplement::supports($modname)
        ) {
            return new activity_kind(activity_kind::EXCLUDED, null, 'kindexcludedfiles');
        }
        if (
            !$DB->record_exists('modules', ['name' => $modname])
                || !plugin_supports('mod', $modname, FEATURE_BACKUP_MOODLE2, false)
        ) {
            return new activity_kind(activity_kind::EXCLUDED, null, 'kindexcludednobackup');
        }
        return new activity_kind(activity_kind::DEVELOPED);
    }

    /**
     * Returns the catalog class.
     *
     * @param string $modname
     * @return class-string<module_catalog> The catalog class.
     * @throws \moodle_exception unknownmodname when the type is not cataloged.
     */
    public static function require_catalogued(string $modname): string {
        $catalog = self::for($modname);
        if ($catalog === null) {
            throw new \moodle_exception(
                'unknownmodname',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'modnames' => implode(', ', self::known_modnames())]
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
