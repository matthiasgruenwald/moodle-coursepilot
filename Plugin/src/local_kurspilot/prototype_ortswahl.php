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

/**
 * WEGWERF-PROTOTYP zu Issue #473 (Karte #467) - kein Produktionscode.
 *
 * Drei strukturell verschiedene Varianten der Ordnerauswahl fuer den externen
 * WebDAV-Speicher, umschaltbar ueber ?variant=a|b|c, dazu Faelle ueber
 * ?fall=ok|leer|keineinstanz|langsam|fehler|echt.
 *
 * Daten sind bis auf ?fall=echt erfunden; nichts wird gespeichert, nichts
 * geschrieben. Die Seite wird nach der Entscheidung wieder geloescht.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login(null, false);

global $USER, $PAGE, $OUTPUT;

$usercontext = context_user::instance($USER->id);
$PAGE->set_context($usercontext);
$PAGE->set_url(new moodle_url('/local/kurspilot/prototype_ortswahl.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('PROTOTYP: Ordnerauswahl');
$PAGE->set_heading('PROTOTYP: Ordnerauswahl ohne Pfadeingabe');

$variant = optional_param('variant', 'a', PARAM_ALPHA);
$fall    = optional_param('fall', 'ok', PARAM_ALPHA);
$instanz = optional_param('instanz', 0, PARAM_INT);
$pfad    = trim(optional_param('pfad', '/', PARAM_RAW));
$ziel    = optional_param('ziel', 'kontext', PARAM_ALPHA);   // Variante A: Reiter.
$schritt = optional_param('schritt', 1, PARAM_INT);          // Variante B: Assistentenschritt.
$wahl    = trim(optional_param('wahl', '', PARAM_RAW));      // Gewaehlter Ordner -> Ergebnispanel.
$kontextwahl = trim(optional_param('kontextwahl', '', PARAM_RAW));

if (!in_array($variant, ['a', 'b', 'c'], true)) {
    $variant = 'a';
}
if (!in_array($fall, ['ok', 'leer', 'keineinstanz', 'langsam', 'fehler', 'echt'], true)) {
    $fall = 'ok';
}

// --------------------------------------------------------------------------
// Erfundene Serverantworten. Ordner und Dateien getrennt, so wie get_listing()
// sie liefert.
// --------------------------------------------------------------------------

$stubinstanzen = [
    1 => ['name' => 'Nextcloud Schule', 'server' => 'cloud.igs-musterstadt.de', 'typ' => 'webdav'],
    2 => ['name' => 'IServ Dateien',    'server' => 'iserv.igs-musterstadt.de', 'typ' => 'webdav'],
];

$stubbaum = [
    1 => [
        '/' => [
            'ordner' => ['Dokumente', 'Unterricht', 'Fotos', 'Vorlagen', 'Talk'],
            'dateien' => ['Notizen.odt', 'Stundenplan.pdf'],
        ],
        '/Unterricht' => [
            'ordner' => ['Bio 7a', 'Bio 9b', 'NW 5c', 'Archiv 2024', 'Kurspilot'],
            'dateien' => ['Jahresplanung.xlsx'],
        ],
        '/Unterricht/Bio 7a' => [
            'ordner' => ['Arbeitsblaetter', 'Bilder', 'Klassenarbeiten'],
            'dateien' => ['Zellen-AB.docx', 'Mikroskop.jpg', 'Verlaufsplan.md'],
        ],
        '/Unterricht/Bio 7a/Bilder' => [
            'ordner' => [],
            'dateien' => ['zelle-1.jpg', 'zelle-2.jpg', 'mikroskop-schema.png'],
        ],
        '/Unterricht/Kurspilot' => [
            'ordner' => ['kontext', 'material'],
            'dateien' => [],
        ],
    ],
    2 => [
        '/' => [
            'ordner' => ['Eigene Dateien', 'Gruppen'],
            'dateien' => [],
        ],
        '/Eigene Dateien' => [
            'ordner' => ['Bio', 'Vertretung'],
            'dateien' => ['Adressliste.csv'],
        ],
    ],
];

/**
 * Erfundenes get_listing(). Gibt Ordner und Dateien der Ebene zurueck.
 */
$listing = function (int $inst, string $path) use ($stubbaum, $fall, $stubinstanzen): array {
    if ($fall === 'echt') {
        return echt_listing($inst, $path);
    }
    if ($fall === 'leer') {
        return ['ordner' => [], 'dateien' => []];
    }
    return $stubbaum[$inst][$path] ?? ['ordner' => [], 'dateien' => []];
};

/**
 * Echte Instanzen der angemeldeten Person - nur im Fall ?fall=echt.
 */
function echt_instanzen(): array {
    global $USER, $CFG;
    require_once($CFG->dirroot . '/repository/lib.php');
    $out = [];
    try {
        $instances = repository::get_instances([
            'currentcontext' => context_user::instance($USER->id),
            'type' => 'webdav',
        ]);
        foreach ($instances as $inst) {
            $out[(int) $inst->id] = [
                'name' => $inst->get_name(),
                'server' => (string) $inst->get_option('webdav_server'),
                'typ' => 'webdav',
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function echt_listing(int $inst, string $path): array {
    global $USER, $CFG;
    require_once($CFG->dirroot . '/repository/lib.php');
    try {
        $repo = repository::get_repository_by_id($inst, context_user::instance($USER->id));
        $listing = $repo->get_listing($path === '/' ? '' : $path);
        $ordner = [];
        $dateien = [];
        foreach (($listing['list'] ?? []) as $item) {
            if (!empty($item['children']) || (isset($item['path']) && empty($item['source']))) {
                $ordner[] = (string) $item['title'];
            } else {
                $dateien[] = (string) $item['title'];
            }
        }
        return ['ordner' => $ordner, 'dateien' => $dateien];
    } catch (Throwable $e) {
        return ['ordner' => [], 'dateien' => [], 'fehler' => $e->getMessage()];
    }
}

$instanzen = $fall === 'keineinstanz' ? [] : ($fall === 'echt' ? echt_instanzen() : $stubinstanzen);
if ($instanz === 0 && $instanzen) {
    $instanz = (int) array_key_first($instanzen);
}

// --------------------------------------------------------------------------
// Hilfen fuer Links und Bausteine.
// --------------------------------------------------------------------------

$url = function (array $extra = []) use ($variant, $fall, $instanz, $pfad, $ziel, $schritt, $wahl, $kontextwahl): string {
    $params = array_merge([
        'variant' => $variant,
        'fall' => $fall,
        'instanz' => $instanz,
        'pfad' => $pfad,
        'ziel' => $ziel,
        'schritt' => $schritt,
        'wahl' => $wahl,
        'kontextwahl' => $kontextwahl,
    ], $extra);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return (new moodle_url('/local/kurspilot/prototype_ortswahl.php', $params))->out(false);
};

$verwaltungsurl = (new moodle_url('/repository/manage_instances.php', [
    'contextid' => $usercontext->id,
]))->out(false);

$breadcrumb = function (string $path) use ($url): string {
    $teile = array_values(array_filter(explode('/', $path)));
    $out = '<a href="' . $url(['pfad' => '/']) . '">Start</a>';
    $acc = '';
    foreach ($teile as $t) {
        $acc .= '/' . $t;
        $out .= ' <span class="text-muted">/</span> <a href="' . $url(['pfad' => $acc]) . '">' . s($t) . '</a>';
    }
    return $out;
};

$parent = function (string $path): string {
    $p = rtrim(substr($path, 0, (int) strrpos($path, '/')), '/');
    return $p === '' ? '/' : $p;
};

$join = static function (string $path, string $name): string {
    return ($path === '/' ? '' : rtrim($path, '/')) . '/' . $name;
};

/**
 * Ergebnispanel: was der Kontextpointer nach dieser Wahl traegt (Frage 5).
 */
$pointerpanel = function (array $felder) use ($instanzen, $instanz): string {
    $inst = $instanzen[$instanz] ?? ['name' => '?', 'server' => '?'];
    $daten = array_merge([
        'speicher' => [
            'instanzid' => $instanz,
            'instanzname' => $inst['name'],
            'server' => $inst['server'],
            'pruefmerkmal' => 'sha1(' . $inst['server'] . '|' . $inst['name'] . ')',
        ],
    ], $felder);
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return '<div class="card mt-3"><div class="card-body">'
        . '<h5 class="card-title">Was gespeichert wuerde <span class="badge bg-secondary">.kurspilot-ort.json</span></h5>'
        . '<pre class="mb-2" style="white-space:pre-wrap">' . s($json) . '</pre>'
        . '<p class="text-muted mb-0">Frage 5: Der Pointer traegt heute nur Pfade. Hier kaeme die Instanz dazu '
        . '- plus ein Pruefmerkmal, weil eine geloeschte oder umkonfigurierte Instanz kein Signal sendet (#468).</p>'
        . '</div></div>';
};

$fehlerpanel = static function (string $wortlaut, string $optionen): string {
    return '<div class="alert alert-danger"><strong>' . $wortlaut . '</strong><div class="mt-2">' . $optionen . '</div></div>';
};

// --------------------------------------------------------------------------
// Ausgabe.
// --------------------------------------------------------------------------

echo $OUTPUT->header();

echo '<div class="alert alert-warning"><strong>Wegwerf-Prototyp zu Issue #473.</strong> '
    . 'Erfundene Daten (ausser Fall <code>echt</code>), nichts wird gespeichert. '
    . 'Unten umschalten: Variante und Fall.</div>';

$timeout = ($fall === 'langsam');

if ($timeout) {
    echo '<div id="wartepanel" class="alert alert-info d-flex align-items-center gap-3">'
        . '<div class="spinner-border spinner-border-sm" role="status"></div>'
        . '<div>Verbindung zu <strong>cloud.igs-musterstadt.de</strong> wird aufgebaut '
        . '&hellip; <span id="wartezeit">0</span> s</div>'
        . '<a class="btn btn-sm btn-outline-secondary ms-auto" href="' . $url(['fall' => 'ok']) . '">Abbrechen</a>'
        . '</div>';
}

echo '<div id="inhalt"' . ($timeout ? ' style="opacity:.25;pointer-events:none"' : '') . '>';

// ==========================================================================
// VARIANTE A - Dateifenster: Instanzen links, Ordner rechts, Reiter je Ziel.
// ==========================================================================
if ($variant === 'a') {
    echo '<h3>Variante A &mdash; Dateifenster</h3>';
    echo '<p class="text-muted">Zwei Reiter, zwei Ziele: Kontextbereich und Materialbestand werden nacheinander '
        . 'im selben Fenster gewaehlt. Navigation wie im Filepicker, gewaehlt wird die <em>aktuelle Ebene</em>.</p>';

    if (!$instanzen) {
        echo '<div class="card"><div class="card-body text-center py-5">'
            . '<h4>Noch kein Speicher verbunden</h4>'
            . '<p class="text-muted">Kurspilot benutzt einen Speicher, den Sie in Moodle selbst anlegen &mdash; '
            . 'Kurspilot verwaltet keine Zugangsdaten.</p>'
            . '<a class="btn btn-primary" href="' . $verwaltungsurl . '">Speicher in Moodle anlegen</a>'
            . '<p class="mt-3 mb-0"><a href="' . $url(['fall' => 'ok']) . '">Zurueck (Prototyp: Fall &bdquo;ok&ldquo;)</a></p>'
            . '</div></div>';
    } else {
        echo '<ul class="nav nav-tabs mb-3">';
        foreach (['kontext' => 'Kontextbereich', 'material' => 'Materialbestand'] as $key => $label) {
            $aktiv = ($ziel === $key) ? ' active' : '';
            $haken = ($key === 'kontext' && $kontextwahl !== '') ? ' &#10003;' : '';
            echo '<li class="nav-item"><a class="nav-link' . $aktiv . '" href="'
                . $url(['ziel' => $key, 'pfad' => '/', 'wahl' => '']) . '">' . $label . $haken . '</a></li>';
        }
        echo '</ul>';

        echo '<div class="d-flex" style="gap:1rem;align-items:flex-start">';

        // Linke Spalte: Instanzen als Wurzeln.
        echo '<div class="card" style="min-width:14rem"><div class="card-body p-2">';
        echo '<h6 class="text-uppercase text-muted px-2">Speicher</h6><ul class="list-unstyled mb-0">';
        foreach ($instanzen as $id => $inst) {
            $aktiv = ($id === $instanz) ? ' fw-bold' : '';
            echo '<li class="px-2 py-1' . $aktiv . '"><a href="' . $url(['instanz' => $id, 'pfad' => '/', 'wahl' => '']) . '">'
                . '&#128193; ' . s($inst['name']) . '</a><br><small class="text-muted">' . s($inst['server']) . '</small></li>';
        }
        echo '</ul><hr><a class="small" href="' . $verwaltungsurl . '">+ weiteren Speicher anlegen</a>';
        echo '</div></div>';

        // Rechte Spalte: Ordnerliste.
        echo '<div class="card flex-grow-1"><div class="card-body">';
        echo '<div class="mb-2">' . $breadcrumb($pfad) . '</div>';
        $l = $listing($instanz, $pfad);
        echo '<div class="border rounded" style="max-height:22rem;overflow:auto">';
        if ($pfad !== '/') {
            echo '<div class="px-3 py-2 border-bottom"><a href="' . $url(['pfad' => $parent($pfad)]) . '">&#8617; eine Ebene hoeher</a></div>';
        }
        if (!$l['ordner'] && !$l['dateien']) {
            echo '<div class="px-3 py-4 text-center text-muted">Dieser Ordner ist leer. '
                . 'Er kann trotzdem gewaehlt werden &mdash; Kurspilot legt darin an, was es braucht.</div>';
        }
        foreach ($l['ordner'] as $o) {
            echo '<div class="px-3 py-2 border-bottom"><a href="' . $url(['pfad' => $join($pfad, $o)]) . '">'
                . '&#128193; ' . s($o) . '</a></div>';
        }
        foreach ($l['dateien'] as $d) {
            echo '<div class="px-3 py-2 border-bottom text-muted">&#128196; ' . s($d) . ' <small>(Datei &mdash; nicht waehlbar)</small></div>';
        }
        echo '</div>';
        echo '<div class="d-flex justify-content-between align-items-center mt-3">';
        echo '<div><small class="text-muted">Ziel:</small> <code>' . s($pfad) . '</code></div>';
        echo '<a class="btn btn-primary" href="' . $url(['wahl' => $pfad]) . '">Diesen Ordner waehlen</a>';
        echo '</div>';
        echo '</div></div>';
        echo '</div>';

        if ($wahl !== '') {
            if ($ziel === 'kontext') {
                echo '<div class="alert alert-success mt-3">Kontextbereich steht auf <code>' . s($wahl) . '</code>. '
                    . '<a class="btn btn-sm btn-primary ms-2" href="'
                    . $url(['ziel' => 'material', 'pfad' => '/', 'wahl' => '', 'kontextwahl' => $wahl])
                    . '">Weiter zum Materialbestand</a></div>';
            } else {
                echo $pointerpanel([
                    'kontextbereich' => $kontextwahl !== '' ? $kontextwahl : '(noch nicht gewaehlt)',
                    'materialbestand' => $wahl,
                ]);
            }
        }
    }
}

// ==========================================================================
// VARIANTE B - Assistent: ein Ordner, beide Ziele daraus abgeleitet.
// ==========================================================================
if ($variant === 'b') {
    echo '<h3>Variante B &mdash; Assistent, ein Ordner</h3>';
    echo '<p class="text-muted">Die Lehrkraft waehlt <em>einen</em> Ordner. Der Kontextbereich wird daraus '
        . 'abgeleitet (Unterordner <code>kurspilot</code>), der Materialbestand ist der Ordner selbst. '
        . 'Drei Schritte, eine Entscheidung pro Bild.</p>';

    $schritte = ['Speicher', 'Ordner', 'Bestaetigen'];
    echo '<ol class="list-unstyled d-flex mb-4" style="gap:2rem">';
    foreach ($schritte as $i => $name) {
        $n = $i + 1;
        $stil = $n === $schritt ? 'fw-bold' : 'text-muted';
        echo '<li class="' . $stil . '">' . $n . '. ' . $name . '</li>';
    }
    echo '</ol>';

    if (!$instanzen) {
        echo '<div class="card"><div class="card-body">'
            . '<h4>Schritt 1: Welcher Speicher?</h4>'
            . '<div class="alert alert-secondary mt-3 mb-3">Sie haben noch keinen Speicher in Moodle angelegt. '
            . 'Kurspilot kann keinen anlegen &mdash; es benutzt nur, was Sie selbst eingerichtet haben.</div>'
            . '<ol><li>&bdquo;Meine Dateien&ldquo; &rarr; Repositories verwalten</li>'
            . '<li>WebDAV-Instanz anlegen: Serveradresse, Benutzername, Passwort</li>'
            . '<li>Hierher zurueckkommen</li></ol>'
            . '<a class="btn btn-primary" href="' . $verwaltungsurl . '">Zur Repository-Verwaltung</a>'
            . ' <a class="btn btn-link" href="' . $url(['fall' => 'ok']) . '">Zurueck (Prototyp)</a>'
            . '</div></div>';
    } else if ($schritt <= 1) {
        echo '<div class="card"><div class="card-body">';
        echo '<h4>Schritt 1: Welcher Speicher?</h4>';
        foreach ($instanzen as $id => $inst) {
            echo '<a class="d-block border rounded p-3 mb-2 text-decoration-none" href="'
                . $url(['instanz' => $id, 'schritt' => 2, 'pfad' => '/']) . '">'
                . '<strong>' . s($inst['name']) . '</strong><br>'
                . '<small class="text-muted">' . s($inst['server']) . ' &middot; WebDAV</small></a>';
        }
        echo '<p class="mt-3 mb-0"><a href="' . $verwaltungsurl . '">Ein anderer Speicher fehlt?</a></p>';
        echo '</div></div>';
    } else if ($schritt === 2) {
        $l = $listing($instanz, $pfad);
        echo '<div class="card"><div class="card-body">';
        echo '<h4>Schritt 2: In welchem Ordner soll Kurspilot arbeiten?</h4>';
        echo '<p class="text-muted">Am besten der Ordner, in dem Ihr Unterrichtsmaterial schon liegt.</p>';
        echo '<div class="mb-2">' . $breadcrumb($pfad) . '</div>';
        echo '<div class="list-group mb-3">';
        if ($pfad !== '/') {
            echo '<a class="list-group-item" href="' . $url(['pfad' => $parent($pfad)]) . '">&#8617; zurueck</a>';
        }
        foreach ($l['ordner'] as $o) {
            $kind = $join($pfad, $o);
            echo '<div class="list-group-item d-flex justify-content-between align-items-center">'
                . '<a href="' . $url(['pfad' => $kind]) . '">&#128193; ' . s($o) . '</a>'
                . '<a class="btn btn-sm btn-outline-primary" href="' . $url(['pfad' => $kind, 'schritt' => 3, 'wahl' => $kind]) . '">waehlen</a>'
                . '</div>';
        }
        if (!$l['ordner']) {
            echo '<div class="list-group-item text-muted">Keine Unterordner hier.</div>';
        }
        echo '</div>';
        echo '<a class="btn btn-primary" href="' . $url(['schritt' => 3, 'wahl' => $pfad]) . '">Diesen Ordner nehmen: <code>'
            . s($pfad) . '</code></a>';
        echo '</div></div>';
    } else {
        $gewaehlt = $wahl !== '' ? $wahl : $pfad;
        $kontext = rtrim($gewaehlt, '/') . '/kurspilot';
        echo '<div class="card"><div class="card-body">';
        echo '<h4>Schritt 3: Passt das so?</h4>';
        echo '<table class="table"><tbody>'
            . '<tr><th style="width:16rem">Ihr Material liegt in</th><td><code>' . s($gewaehlt) . '</code>'
            . '<br><small class="text-muted">wird nur gelesen, nie veraendert</small></td></tr>'
            . '<tr><th>Kurspilot legt seine Notizen in</th><td><code>' . s($kontext) . '</code>'
            . '<br><small class="text-muted">wird angelegt, falls noch nicht da</small></td></tr>'
            . '<tr><th>Chat-Anhaenge und Zuschnitte</th><td>bleiben in Moodle (Werkbank)</td></tr>'
            . '</tbody></table>';
        echo '<a class="btn btn-success" href="' . $url(['schritt' => 4]) . '">So einrichten</a> '
            . '<a class="btn btn-link" href="' . $url(['schritt' => 2, 'wahl' => '']) . '">anderen Ordner waehlen</a>';
        echo '</div></div>';
        if ($schritt >= 4) {
            echo $pointerpanel(['kontextbereich' => $kontext, 'materialbestand' => $gewaehlt]);
        }
    }
}

// ==========================================================================
// VARIANTE C - Vorschlaege: keine Navigation, Kurspilot schlaegt vor.
// ==========================================================================
if ($variant === 'c') {
    echo '<h3>Variante C &mdash; Vorschlagsliste</h3>';
    echo '<p class="text-muted">Kein Baum. Kurspilot sieht sich den Speicher einmal an und schlaegt Ordner vor; '
        . 'die Lehrkraft kreuzt an. Navigieren ist der Ausweichweg, nicht der Hauptweg.</p>';

    if (!$instanzen) {
        echo '<div class="card"><div class="card-body">'
            . '<h4>Kurspilot findet keinen Speicher</h4>'
            . '<p>Damit Kurspilot Vorschlaege machen kann, braucht es einen Speicher, den Sie in Moodle '
            . 'eingerichtet haben. Bis dahin bleibt alles in Moodle liegen.</p>'
            . '<a class="btn btn-primary" href="' . $verwaltungsurl . '">Speicher einrichten</a> '
            . '<a class="btn btn-outline-secondary" href="#">Vorerst in Moodle lassen</a>'
            . '<p class="mt-3 mb-0"><a href="' . $url(['fall' => 'ok']) . '">Zurueck (Prototyp)</a></p>'
            . '</div></div>';
    } else {
        // Vorschlaege: Wurzelebene plus eine Ebene tiefer, gewichtet nach Namen.
        $vorschlaege = [];
        $wurzel = $listing($instanz, '/');
        foreach ($wurzel['ordner'] as $o) {
            $p = '/' . $o;
            $tiefer = $listing($instanz, $p);
            $treffer = preg_match('/unterricht|schule|material|kurspilot|bio|dateien/i', $o);
            $vorschlaege[] = [
                'pfad' => $p,
                'unter' => count($tiefer['ordner']),
                'dateien' => count($tiefer['dateien']),
                'empfohlen' => (bool) $treffer,
            ];
            foreach (array_slice($tiefer['ordner'], 0, 3) as $u) {
                $vorschlaege[] = [
                    'pfad' => $join($p, $u),
                    'unter' => count($listing($instanz, $join($p, $u))['ordner']),
                    'dateien' => count($listing($instanz, $join($p, $u))['dateien']),
                    'empfohlen' => false,
                ];
            }
        }
        usort($vorschlaege, static fn($x, $y) => ($y['empfohlen'] <=> $x['empfohlen']) ?: ($y['dateien'] <=> $x['dateien']));
        $vorschlaege = array_slice($vorschlaege, 0, 6);

        echo '<div class="mb-3"><label class="me-2">Speicher:</label><select class="form-select d-inline-block" '
            . 'style="width:auto" onchange="location=this.value">';
        foreach ($instanzen as $id => $inst) {
            echo '<option value="' . $url(['instanz' => $id, 'wahl' => '']) . '"' . ($id === $instanz ? ' selected' : '') . '>'
                . s($inst['name']) . ' (' . s($inst['server']) . ')</option>';
        }
        echo '</select></div>';

        if (!$vorschlaege) {
            echo '<div class="alert alert-secondary">Auf diesem Speicher liegt noch nichts. '
                . 'Kurspilot kann einen Ordner anlegen:</div>'
                . '<a class="btn btn-primary" href="' . $url(['wahl' => '/Kurspilot']) . '">'
                . 'Ordner <code>/Kurspilot</code> anlegen und nehmen</a>';
        } else {
            echo '<div class="list-group mb-3">';
            foreach ($vorschlaege as $v) {
                $badge = $v['empfohlen'] ? ' <span class="badge bg-success">passt vermutlich</span>' : '';
                echo '<a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="'
                    . $url(['wahl' => $v['pfad']]) . '">'
                    . '<span><strong>' . s($v['pfad']) . '</strong>' . $badge
                    . '<br><small class="text-muted">' . $v['unter'] . ' Unterordner, ' . $v['dateien'] . ' Dateien</small></span>'
                    . '<span class="btn btn-sm btn-outline-primary">nehmen</span></a>';
            }
            echo '</div>';
        }

        echo '<details class="mb-3"><summary>Anderer Ordner &hellip;</summary>'
            . '<div class="border rounded p-3 mt-2">'
            . '<form method="get" class="d-flex" style="gap:.5rem">'
            . '<input type="hidden" name="variant" value="c"><input type="hidden" name="fall" value="' . s($fall) . '">'
            . '<input type="hidden" name="instanz" value="' . $instanz . '">'
            . '<input class="form-control" name="wahl" placeholder="/Unterricht/Bio 7a" value="">'
            . '<button class="btn btn-outline-primary">nehmen</button></form>'
            . '<small class="text-muted">Der Ausweichweg &mdash; genau das Tippen, das die Frage vermeiden will.</small>'
            . '</div></details>';

        if ($wahl !== '') {
            echo $pointerpanel([
                'kontextbereich' => rtrim($wahl, '/') . '/kurspilot',
                'materialbestand' => $wahl,
            ]);
        }
    }
}

echo '</div>'; // #inhalt

// Fall "fehler": Server antwortet nicht - variantenspezifisch.
if ($fall === 'fehler') {
    $optionen = '<a class="btn btn-sm btn-outline-light me-2" href="' . $url([]) . '">Erneut versuchen</a>'
        . '<a class="btn btn-sm btn-outline-light me-2" href="' . $verwaltungsurl . '">Zugangsdaten pruefen</a>'
        . '<a class="btn btn-sm btn-outline-light" href="' . $url(['fall' => 'ok']) . '">Spaeter</a>';
    echo $fehlerpanel('cloud.igs-musterstadt.de antwortet nicht (Zeitueberschreitung nach 8 Sekunden).', $optionen);
    echo '<p class="text-muted">Frage 3: Bis hierher ist nichts gespeichert &mdash; die Lehrkraft kann die Seite '
        . 'einfach verlassen. Genau das ginge im Zustimmungsdialog nicht.</p>';
}

// Fall "langsam": nach 8 s in die Zeitueberschreitung laufen.
if ($timeout) {
    $wiederholen = $url(['fall' => 'ok']);
    echo <<<HTML
<div id="timeoutpanel" style="display:none">
  <div class="alert alert-danger">
    <strong>Keine Antwort von cloud.igs-musterstadt.de.</strong>
    <p class="mb-2">Nach 8 Sekunden abgebrochen. Nichts wurde gespeichert.</p>
    <a class="btn btn-sm btn-primary" href="{$wiederholen}">Nochmal versuchen</a>
    <a class="btn btn-sm btn-outline-secondary" href="{$verwaltungsurl}">Zugangsdaten pruefen</a>
  </div>
</div>
<script>
(function () {
  var t = 0;
  var feld = document.getElementById('wartezeit');
  var iv = setInterval(function () {
    t += 1;
    feld.textContent = t;
    if (t >= 8) {
      clearInterval(iv);
      document.getElementById('wartepanel').style.display = 'none';
      document.getElementById('inhalt').style.display = 'none';
      document.getElementById('timeoutpanel').style.display = 'block';
    }
  }, 1000);
})();
</script>
HTML;
}

// --------------------------------------------------------------------------
// Schaltleiste (Prototyp-Moebel, nicht Teil des Entwurfs).
// --------------------------------------------------------------------------

$varianten = ['a' => 'Dateifenster', 'b' => 'Assistent', 'c' => 'Vorschlagsliste'];
$faelle = [
    'ok' => 'alles da',
    'leer' => 'Ordner leer',
    'keineinstanz' => 'keine Instanz',
    'langsam' => 'Server langsam',
    'fehler' => 'Server tot',
    'echt' => 'echte Instanzen',
];

echo '<div style="position:fixed;left:50%;transform:translateX(-50%);bottom:1rem;z-index:1050;'
    . 'background:#111;color:#fff;border-radius:2rem;padding:.5rem 1rem;box-shadow:0 4px 16px rgba(0,0,0,.4);'
    . 'display:flex;gap:.75rem;align-items:center;font-size:.9rem">';
echo '<span style="opacity:.6">PROTOTYP</span>';
foreach ($varianten as $key => $label) {
    $stil = $key === $variant ? 'background:#fff;color:#111;' : 'color:#fff;';
    echo '<a href="' . $url(['variant' => $key, 'schritt' => 1, 'wahl' => '', 'kontextwahl' => '', 'pfad' => '/', 'ziel' => 'kontext'])
        . '" style="' . $stil . 'padding:.15rem .6rem;border-radius:1rem;text-decoration:none">'
        . strtoupper($key) . ' ' . $label . '</a>';
}
echo '<span style="opacity:.4">|</span>';
echo '<select onchange="location=this.value" style="background:#222;color:#fff;border:0;border-radius:1rem;padding:.15rem .5rem">';
foreach ($faelle as $key => $label) {
    echo '<option value="' . $url(['fall' => $key, 'wahl' => '', 'pfad' => '/', 'schritt' => 1]) . '"'
        . ($key === $fall ? ' selected' : '') . '>' . $label . '</option>';
}
echo '</select>';
echo '</div>';

echo $OUTPUT->footer();
