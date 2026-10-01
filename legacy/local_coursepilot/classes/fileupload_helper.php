<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Gemeinsamer Kern fuer Base64-Datei-Uploads: Decodierung, MIME-Pruefung,
 * optionales Groessenlimit, Delete-vor-Write und Dateierzeugung.
 *
 * Genutzt von upload_assign_intro_image, upload_assignfile und
 * upload_folder_file (siehe Issue #320, docs/specs/0012). Vorbild war die
 * urspruenglich dreifach kopierte Logik in upload_assign_intro_image.
 */
class fileupload_helper {

    /**
     * Dekodiert Base64-Inhalt und ermittelt den echten MIME-Type aus dem
     * Dateiinhalt (nicht aus der Nutzerangabe). Eine MIME-Typ-Einschraenkung
     * (z. B. "nur Bilder") ist Sache des Aufrufers, da die Fehlermeldung je
     * Anwendungsfall unterschiedlich formuliert ist.
     *
     * @param string $base64content Base64-kodierter Dateiinhalt
     * @param int|null $maxbytes Groessenlimit in Byte; null = kein Limit
     * @param string $fallbackmimetype Wert falls die Inhaltserkennung fehlschlaegt
     * @return array{0: string, 1: string} [$filedata, $detectedmimetype]
     * @throws \invalid_parameter_exception
     */
    public static function decode_and_validate(
        string $base64content,
        ?int $maxbytes = null,
        string $fallbackmimetype = 'application/octet-stream'
    ): array {
        $filedata = base64_decode($base64content, true);
        if ($filedata === false) {
            throw new \invalid_parameter_exception('Ungueltige Base64-Kodierung.');
        }

        if ($maxbytes !== null && strlen($filedata) > $maxbytes) {
            throw new \invalid_parameter_exception(
                'Datei ueberschreitet das Groessenlimit von ' . $maxbytes . ' Byte.'
            );
        }

        $detectedmimetype = finfo_buffer(new \finfo(FILEINFO_MIME_TYPE), $filedata) ?: $fallbackmimetype;

        return [$filedata, $detectedmimetype];
    }

    /**
     * Loescht eine vorhandene Datei gleichen Namens im angegebenen
     * Dateibereich, falls vorhanden (Delete-vor-Write fuer Idempotenz).
     */
    public static function delete_existing(
        int $contextid,
        string $component,
        string $filearea,
        int $itemid,
        string $filepath,
        string $filename
    ): void {
        $fs = get_file_storage();
        $existing = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
        if ($existing) {
            $existing->delete();
        }
    }

    /**
     * Legt die neue Datei im Dateispeicher an.
     */
    public static function create_file(
        int $contextid,
        string $component,
        string $filearea,
        int $itemid,
        string $filepath,
        string $filename,
        string $mimetype,
        int $userid,
        string $author,
        string $filedata
    ): \stored_file {
        $fs = get_file_storage();
        $fileinfo = [
            'contextid' => $contextid,
            'component' => $component,
            'filearea'  => $filearea,
            'itemid'    => $itemid,
            'filepath'  => $filepath,
            'filename'  => $filename,
            'mimetype'  => $mimetype,
            'userid'    => $userid,
            'source'    => $filename,
            'author'    => $author,
            'license'   => 'allrightsreserved',
        ];
        return $fs->create_file_from_string($fileinfo, $filedata);
    }
}
