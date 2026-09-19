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
 * Baut die Bildvorschau aus Spec 0018 §3.1: laengste Kante 768px, JPEG.
 * Dient dem Beurteilen (Modell waehlt Ausschnitt/schreibt Alt-Text), nicht
 * dem Verarbeiten - das Original bleibt unangetastet, der Zuschnitt (spaeter,
 * eigener Endpunkt, §5) schneidet aus dem Original, nicht aus dieser
 * Vorschau.
 *
 * GD ist raster-only (§3.3/§5) - SVG kann hierueber nicht gerendert werden,
 * das ist Aufgabe des Aufrufers (preview_material_file), nicht dieser Klasse.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class image_preview {

    /** @var int Laengste Kante der Vorschau in Pixeln (Spec 0018 §3.1). */
    private const MAX_EDGE = 768;

    /** @var int JPEG-Qualitaet - "wenige zehn Kilobyte" statt Bestqualitaet (Spec 0018 §3.1). */
    private const JPEG_QUALITY = 80;

    /**
     * @param string $binary Rohinhalt der Quelldatei.
     * @return array{image_base64: string, mimetype: string, width: int, height: int}
     * @throws \moodle_exception materialpreviewunsupported, wenn GD den
     *         Inhalt nicht als Rasterbild lesen kann (z.B. SVG, defektes Bild).
     */
    public static function build(string $binary): array {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new \moodle_exception('materialpreviewunsupported', 'local_coursepilot');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        $newwidth = max(1, (int) round($width * $scale));
        $newheight = max(1, (int) round($height * $scale));

        // Immer auf eine weisse, alphakanalfreie Leinwand kopieren - JPEG
        // kennt keine Transparenz, ohne diesen Schritt wuerde GD
        // transparente PNG-Bereiche als Schwarz ausgeben.
        $canvas = imagecreatetruecolor($newwidth, $newheight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $jpeg = ob_get_clean();
        imagedestroy($canvas);

        return [
            'image_base64' => base64_encode($jpeg),
            'mimetype' => 'image/jpeg',
            'width' => $newwidth,
            'height' => $newheight,
        ];
    }
}
