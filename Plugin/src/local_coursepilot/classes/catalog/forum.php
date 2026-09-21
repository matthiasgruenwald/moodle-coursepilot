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
 * Feldkatalog fuer mod_forum (Spec 0015 §4.1, Ticket #381).
 *
 * Fallstricke aus dem Bestand:
 * - "type" referenziert forum_get_forum_types() als Wertebereich - "news"
 *   und "social" existieren zwar als Typ (forum_get_forum_types_all()),
 *   sind aber nur bei automatisch angelegten Kurs-/Site-Foren gesetzt und
 *   ueber das Formular nicht waehlbar (mod/forum/mod_form.php:
 *   definition_after_data() blendet sie nur ein, wenn bereits gesetzt).
 * - "assessed" referenziert rating_manager::get_aggregate_types() (Klasse in
 *   rating/lib.php) statt die Werte abzuschreiben.
 * - "forcesubscribe" referenziert forum_get_subscriptionmode_options().
 *   forcesubscribe=2 (FORUM_INITIALSUBSCRIBE) ist ein Nebenwirkungsvermerk:
 *   beim Anlegen bzw. beim Wechsel auf 2 abonniert Moodle sofort ALLE
 *   potenziellen Kursteilnehmenden (mod/forum/lib.php: forum_instance_created(),
 *   forum_update_instance()) - sie bekommen ab dann Mails zu neuen Beitraegen.
 * - "ratingtime" ist ein Pseudofeld (Checkbox, keine DB-Spalte): nur wenn es
 *   gesetzt ist, uebernimmt forum_update_instance() "assesstimestart"/
 *   "assesstimefinish" - sonst werden beide auf 0 zurueckgesetzt. Deshalb
 *   stehen "assesstimestart"/"assesstimefinish" auf der Sperrliste, obwohl
 *   es echte Spalten sind.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class forum implements module_catalog {

    public static function modname(): string {
        return 'forum';
    }

    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Anzeigename des Forums.',
                true,
                null,
                null,
                null,
                'mod/forum/mod_form.php:42-46 (PARAM_TEXT bzw. PARAM_CLEANHTML je nach $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Beschreibungstext (Intro) des Forums.',
                true,
                null,
                null,
                null,
                'mod/forum/db/install.xml (forum.intro, NOTNULL ohne DB-Default)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Textformat des Intros.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/forum/db/install.xml (forum.introformat)'
            ),
            new field(
                'type',
                'PARAM_ALPHA',
                'Forumstyp. "news" und "social" sind ueber diesen Katalog nicht waehlbar - sie existieren nur '
                    . 'bei automatisch angelegten Kurs-/Site-Foren.',
                false,
                'general',
                null,
                'forum_get_forum_types()',
                'mod/forum/lib.php:5330-5336 (forum_get_forum_types()); mod/forum/mod_form.php:53-57'
            ),
            new field(
                'duedate',
                'PARAM_INT',
                'Unix-Zeitstempel: Abgabetermin, nur informativ (kein Sperrzeitpunkt fuer Beitraege). Erzeugt '
                    . 'einen Kalendereintrag (siehe Nebenwirkungen).',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:61-63 (date_time_selector, optional); Spalte '
                    . 'mod/forum/db/install.xml (forum.duedate)'
            ),
            new field(
                'cutoffdate',
                'PARAM_INT',
                'Unix-Zeitstempel: ab hier nimmt Moodle keine Forumsbeitraege mehr an. 0 = kein Cutoff.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:65-67 (date_time_selector, optional); Spalte '
                    . 'mod/forum/db/install.xml (forum.cutoffdate)'
            ),
            new field(
                'assessed',
                'PARAM_INT',
                'Aggregationstyp fuer Bewertungen (z.B. Durchschnitt, Summe, keine Bewertung).',
                false,
                0,
                null,
                'rating_manager::get_aggregate_types()',
                'rating/lib.php (Klasse rating_manager, Methode get_aggregate_types()); '
                    . 'course/moodleform_mod.php:686,719 (add_rating_settings()); Spalte '
                    . 'mod/forum/db/install.xml (forum.assessed)'
            ),
            new field(
                'scale',
                'PARAM_INT',
                'Bewertungsskala: positiv = Punkte-Maximum, negativ = ID einer benutzerdefinierten Skala. Nur '
                    . 'wirksam bei assessed != 0.',
                false,
                0,
                null,
                null,
                'course/moodleform_mod.php:743-746 (add_rating_settings(), modgrade-Element; scale-Wert = grademax/scaleid); Spalte '
                    . 'mod/forum/db/install.xml (forum.scale)'
            ),
            new field(
                'grade_forum',
                'PARAM_INT',
                'Gesamtbewertung des Forums (unabhaengig von der Beitragsbewertung ueber "assessed"): positiv = '
                    . 'Punkte-Maximum, negativ = ID einer benutzerdefinierten Skala, 0 = keine Bewertung.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:211,225-274 (add_forum_grade_settings(), modgrade-Element); Spalte '
                    . 'mod/forum/db/install.xml (forum.grade_forum)'
            ),
            new field(
                'grade_forum_notify',
                'PARAM_BOOL',
                'Lernende standardmaessig ueber neue Bewertungen benachrichtigen.',
                false,
                0,
                [0, 1],
                null,
                'mod/forum/mod_form.php:305-307 (selectyesno); Spalte '
                    . 'mod/forum/db/install.xml (forum.grade_forum_notify)'
            ),
            new field(
                'maxbytes',
                'PARAM_INT',
                'Maximale Dateigroesse je Anhang in Byte. Die waehlbaren Werte sind eine von Kurs- und '
                    . 'Serverlimit abhaengige Teilmenge, keine feste Liste.',
                false,
                0,
                null,
                'get_max_upload_sizes()',
                'lib/moodlelib.php:6453 (get_max_upload_sizes()); mod/forum/mod_form.php:72-76; Spalte '
                    . 'mod/forum/db/install.xml (forum.maxbytes)'
            ),
            new field(
                'maxattachments',
                'PARAM_INT',
                'Maximale Anzahl Anhaenge je Beitrag.',
                false,
                1,
                null,
                null,
                'mod/forum/mod_form.php:94-96; Spalte mod/forum/db/install.xml (forum.maxattachments)'
            ),
            new field(
                'displaywordcount',
                'PARAM_BOOL',
                'Wortanzahl je Beitrag anzeigen.',
                false,
                0,
                [0, 1],
                null,
                'mod/forum/mod_form.php:98-100 (selectyesno); Spalte '
                    . 'mod/forum/db/install.xml (forum.displaywordcount)'
            ),
            new field(
                'forcesubscribe',
                'PARAM_INT',
                'Abonnementmodus. Wert 2 (Auto-Abonnement) abonniert beim Anlegen bzw. Umschalten sofort alle '
                    . 'potenziellen Kursteilnehmenden - siehe Nebenwirkungen.',
                false,
                0,
                null,
                'forum_get_subscriptionmode_options()',
                'mod/forum/lib.php:39-42 (FORUM_CHOOSESUBSCRIBE/FORCESUBSCRIBE/INITIALSUBSCRIBE/DISALLOWSUBSCRIBE), '
                    . ':4870-4876 (forum_get_subscriptionmode_options()); mod/forum/mod_form.php:105-106'
            ),
            new field(
                'trackingtype',
                'PARAM_INT',
                'Lesestatus-Verfolgung: aus, optional (Lernende entscheiden) oder erzwungen.',
                false,
                1,
                [0, 1, 2],
                null,
                'mod/forum/lib.php:47-58 (FORUM_TRACKING_OFF/OPTIONAL/FORCED); mod/forum/mod_form.php:118-127'
            ),
            new field(
                'rsstype',
                'PARAM_INT',
                'RSS-Feedinhalt: aus, Diskussionen oder Beitraege. Nur waehlbar, wenn RSS serverweit aktiv ist.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:131-139'
            ),
            new field(
                'rssarticles',
                'PARAM_INT',
                'Anzahl Eintraege im RSS-Feed. Nur wirksam bei rsstype != 0.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:156-160 (hideIf rsstype eq 0)'
            ),
            new field(
                'warnafter',
                'PARAM_INT',
                'Ab dieser Beitragszahl im Blockzeitraum eine Warnung anzeigen. 0 = aus. Nur wirksam bei '
                    . 'blockperiod != 0.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:201-206 (hideIf blockperiod eq 0); Spalte '
                    . 'mod/forum/db/install.xml (forum.warnafter)'
            ),
            new field(
                'blockafter',
                'PARAM_INT',
                'Ab dieser Beitragszahl im Blockzeitraum weitere Beitraege sperren. 0 = aus. Nur wirksam bei '
                    . 'blockperiod != 0.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:194-199 (hideIf blockperiod eq 0); Spalte '
                    . 'mod/forum/db/install.xml (forum.blockafter)'
            ),
            new field(
                'blockperiod',
                'PARAM_INT',
                'Zeitraum in Sekunden, ueber den warnafter/blockafter gezaehlt werden. 0 = Sperre deaktiviert.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:181-192'
            ),
            new field(
                'completiondiscussions',
                'PARAM_INT',
                'Anzahl eroeffneter Diskussionen, ab der die Aktivitaet als abgeschlossen gilt. 0 = keine '
                    . 'Bedingung.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:405-420; Spalte mod/forum/db/install.xml (forum.completiondiscussions)'
            ),
            new field(
                'completionreplies',
                'PARAM_INT',
                'Anzahl Antworten, ab der die Aktivitaet als abgeschlossen gilt. 0 = keine Bedingung.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:405-425; Spalte mod/forum/db/install.xml (forum.completionreplies)'
            ),
            new field(
                'completionposts',
                'PARAM_INT',
                'Anzahl Beitraege (Diskussionen + Antworten zusammen), ab der die Aktivitaet als abgeschlossen '
                    . 'gilt. 0 = keine Bedingung.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:405-435; Spalte mod/forum/db/install.xml (forum.completionposts)'
            ),
            new field(
                'lockdiscussionafter',
                'PARAM_INT',
                'Zeitraum in Sekunden, nach dem eine Diskussion ohne neue Antwort automatisch gesperrt wird. '
                    . '0 = aus. Nicht wirksam bei type=single.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:164-178 (disabledIf type eq single); Spalte '
                    . 'mod/forum/db/install.xml (forum.lockdiscussionafter)'
            ),
        ];
    }

    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::for_modname(self::modname(), $instanceid, $cmid, $fullcontent);
    }

    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    public static function pseudofields(): array {
        return [
            new field(
                'ratingtime',
                'PARAM_BOOL',
                'Checkbox: Bewertungszeitraum einschraenken. Nur wenn gesetzt, uebernimmt Moodle '
                    . '"assesstimestart"/"assesstimefinish" - sonst setzt forum_update_instance() beide auf 0 '
                    . 'zurueck. Kein DB-Feld.',
                false,
                0,
                [0, 1],
                null,
                'course/moodleform_mod.php:751-760 (add_rating_settings()); mod/forum/lib.php:178-181 '
                    . '(forum_update_instance(): `if (empty($forum->ratingtime) or empty($forum->assessed))`)'
            ),
        ];
    }

    public static function blocklist(): array {
        return [
            'assesstimestart',
            'assesstimefinish',
        ];
    }

    public static function combination_rules(): array {
        return [
            '"cutoffdate" darf nicht vor "duedate" liegen (mod/forum/mod_form.php: validation()).',
            '"type"="single" ist nicht mit "groupmode"=SEPARATEGROUPS kombinierbar (mod/forum/mod_form.php: '
                . 'validation()); "groupmode" steht im gemeinsamen Block.',
        ];
    }

    public static function side_effects(): array {
        return [
            '"forcesubscribe"=2 (Auto-Abonnement) abonniert beim Anlegen bzw. beim Umschalten auf diesen Wert '
                . 'sofort alle potenziellen Kursteilnehmenden - sie bekommen ab dann Mails zu jedem neuen Beitrag '
                . '(mod/forum/lib.php: forum_instance_created(), forum_update_instance()).',
            '"duedate" erzeugt bzw. aktualisiert einen Kalendereintrag (mod/forum/locallib.php: '
                . 'forum_update_calendar()); "cutoffdate" tut das nicht.',
        ];
    }

    public static function bundles(): array {
        return [];
    }

    public static function schreibweg(): ?string {
        return null;
    }

    public static function checked_constants(): array {
        return ['FORUM_INITIALSUBSCRIBE'];
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
