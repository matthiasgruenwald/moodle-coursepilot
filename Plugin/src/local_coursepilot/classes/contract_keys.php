<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/

namespace local_coursepilot;

/**
 * Translates the legacy Moodle adapter vocabulary at the MCP boundary.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class contract_keys {
    private const EXTERNAL = [
        'aenderungen' => 'changes', 'angelegt' => 'created', 'angelegte_felder' => 'created_fields', 'art' => 'kind',
        'ausloeser' => 'trigger', 'ausstand' => 'pending_entry', 'ausstaende' => 'pending_entries',
        'bedeutung' => 'meaning', 'bedingungen_json' => 'conditions_json', 'bestaetigt' => 'confirmed',
        'dateiname' => 'filename', 'eintraege' => 'entries',
        'felder' => 'fields', 'felder_json' => 'fields_json', 'fehlerklasse' => 'error_class',
        'feldbuendel' => 'field_bundles', 'hinweis' => 'notice', 'hinweise' => 'notices',
        'hinweis_luecken' => 'gap_notice', 'idnumber_nachgetragen' => 'idnumber_added',
        'kennung' => 'identifier', 'kombinationsregeln' => 'combination_rules',
        'korpus_stand' => 'corpus_version', 'kursid' => 'course_id', 'meldung' => 'message',
        'modul' => 'module', 'nach' => 'after', 'nach_version' => 'to_version',
        'nebenwirkungen' => 'side_effects', 'nur_anlegen' => 'create_only', 'ort' => 'location',
        'pfad' => 'path', 'pflicht' => 'required', 'pseudofelder' => 'pseudo_fields',
        'quelle' => 'source', 'quelle_callable' => 'source_callable', 'quellcmid' => 'source_cmid',
        'referenzierte_teile' => 'referenced_parts', 'schreibweg' => 'write_route',
        'sperrliste' => 'blocked_fields', 'umfang' => 'length', 'verstoesse' => 'violations',
        'versionen' => 'versions', 'vorgefunden' => 'discovered', 'vorgang' => 'operation',
        'von' => 'before', 'von_json' => 'before_json', 'von_version' => 'from_version',
        'vorheriger_ort' => 'previous_location', 'vollstaendig' => 'full',
        'wert_json' => 'value_json', 'werte_json' => 'values_json', 'wertebereich' => 'value_range', 'zeitpunkt' => 'timestamp',
        'zielversion' => 'target_version',
    ];

    private const INPUT = [
        'ausstand' => 'pending_entry', 'bedingungen_json' => 'conditions_json', 'bestaetigt' => 'confirmed',
        'felder_json' => 'fields_json', 'nach_version' => 'to_version',
        'nur_anlegen' => 'create_only', 'ort' => 'location',
        'vorheriger_ort' => 'previous_location', 'vollstaendig' => 'full',
        'von_version' => 'from_version', 'zielversion' => 'target_version',
    ];

    /** @return array<string, mixed> */
    public static function externalize(array $value): array {
        $translated = [];
        foreach ($value as $key => $item) {
            $key = self::EXTERNAL[$key] ?? $key;
            $translated[$key] = is_array($item) ? self::externalize($item) : $item;
        }
        return $translated;
    }

    /**
     * Der oeffentliche Name eines einzelnen deutschen Feldschluessels - fuer
     * Stellen, die einen Namen ausserhalb einer Datenstruktur brauchen (#568:
     * die 'required'-Liste im MCP-Schema ist eine Werteliste, keine
     * Schluesselmenge, {@see externalize()} uebersetzt deshalb nur ihre
     * Eintraege nicht automatisch mit). Bereits englische Namen kommen
     * unveraendert zurueck (kein Eintrag in EXTERNAL), das traegt die
     * Uebergangsphase aus Spec 0025 §A: eine gemischt deutsch/englisch
     * deklarierte Werkzeugmenge bleibt hierueber korrekt.
     */
    public static function externalize_key(string $key): string {
        return self::EXTERNAL[$key] ?? $key;
    }

    /**
     * @param array<string, mixed> $value
     * @param string[] $declaredkeys Die tatsaechlich deklarierten Top-Level-
     *        Parameternamen der aufgerufenen Funktion (#568). Ein
     *        ankommender Schluessel, der bereits einem davon entspricht, ist
     *        keine Uebersetzung wert - so bleibt eine bereits englisch
     *        deklarierte Funktion unangetastet, auch wenn ihr Name zufaellig
     *        mit einem Uebersetzungsziel einer anderen, noch deutschen
     *        Funktion uebereinstimmt.
     * @return array<string, mixed>
     */
    public static function internalize(array $value, array $declaredkeys = []): array {
        $keys = array_flip(self::INPUT);
        $translated = [];
        foreach ($value as $key => $item) {
            if (!in_array($key, $declaredkeys, true) && isset($keys[$key])) {
                $key = $keys[$key];
            }
            $translated[$key] = is_array($item) ? self::translate($item, $keys) : $item;
        }
        return $translated;
    }

    /** @return array<string, mixed> */
    private static function translate(array $value, array $keys): array {
        $translated = [];
        foreach ($value as $key => $item) {
            $key = $keys[$key] ?? $key;
            $translated[$key] = is_array($item) ? self::translate($item, $keys) : $item;
        }
        return $translated;
    }
}
