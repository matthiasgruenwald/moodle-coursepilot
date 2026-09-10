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
 * WEGWERF-PROTOTYP zu Issue #473 (Karte #467) – kein Produktionscode.
 *
 * Zweite Runde nach der Rückmeldung vom 09.09.2026: Variante C (Vorschlagsliste)
 * ist raus, Variante A (Dateifenster) trägt und hat dazugelernt –
 * Fortschrittsband über beiden Zielen, Ordner anlegen, Unterordner „kurspilot"
 * für den Kontextbereich.
 *
 * Umschaltbar über ?variant=a|b und ?fall=ok|leer|keineinstanz|langsam|fehler|echt.
 * Daten sind bis auf ?fall=echt erfunden; es wird nichts geschrieben und nichts
 * gespeichert – auch ein „angelegter" Ordner entsteht nur auf dem Bildschirm.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login(null, false);

global $USER, $PAGE, $OUTPUT, $CFG;

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
$ziel    = optional_param('ziel', 'kontext', PARAM_ALPHA);
$schritt = optional_param('schritt', 1, PARAM_INT);

// Getroffene Wahl, je Ziel getrennt – der Kern der Reiter-Frage.
$kontextwahl  = trim(optional_param('kontextwahl', '', PARAM_RAW));
$materialwahl = trim(optional_param('materialwahl', '', PARAM_RAW));
// Unterordner „kurspilot" unter der Kontextwahl anlegen statt den Ordner direkt nehmen.
$unterordner  = optional_param('unterordner', 1, PARAM_INT);
// Im Prototyp „angelegte" Ordner: durch | getrennte Pfadliste, rein zur Anzeige.
$neu          = trim(optional_param('neu', '', PARAM_RAW));
$fertig       = optional_param('fertig', 0, PARAM_INT);

if (!in_array($variant, ['a', 'b'], true)) {
    $variant = 'a';
}
if (!in_array($fall, ['ok', 'leer', 'keineinstanz', 'langsam', 'fehler', 'echt'], true)) {
    $fall = 'ok';
}

$KURSPILOT_ORDNER = 'kurspilot';

/** Pfad immer mit führendem und abschließendem Schrägstrich. */
function kp_norm(string $p): string {
    $p = '/' . trim($p, '/');
    return $p === '/' ? '/' : $p . '/';
}
$pfad = kp_norm($pfad);
$neuliste = array_values(array_filter(explode('|', $neu)));

// --------------------------------------------------------------------------
// Erfundene Serverantworten. Ordner tragen Anzeigename und vollen Pfad
// getrennt – der Stolperstein aus der ersten Runde: der Titel aus
// get_listing() ist relativ zur aktuellen Ebene, navigiert wird über 'path'.
// --------------------------------------------------------------------------

$stubinstanzen = [
    1 => ['name' => 'Nextcloud Schule', 'server' => 'cloud.igs-musterstadt.de'],
    2 => ['name' => 'IServ Dateien',    'server' => 'iserv.igs-musterstadt.de'],
];

$stubbaum = [
    1 => [
        '/' => ['ordner' => ['Dokumente', 'Unterricht', 'Fotos', 'Vorlagen'], 'dateien' => ['Notizen.odt']],
        '/Unterricht/' => [
            'ordner' => ['Bio 7a', 'Bio 9b', 'NW 5c', 'Archiv 2024'],
            'dateien' => ['Jahresplanung.xlsx'],
        ],
        '/Unterricht/Bio 7a/' => [
            'ordner' => ['Arbeitsblätter', 'Bilder', 'kurspilot'],
            'dateien' => ['Zellen-AB.docx', 'Mikroskop.jpg'],
        ],
        '/Unterricht/Bio 7a/Bilder/' => ['ordner' => [], 'dateien' => ['zelle-1.jpg', 'zelle-2.jpg']],
        '/Dokumente/' => ['ordner' => ['Privat', 'Steuer'], 'dateien' => ['Lebenslauf.pdf']],
    ],
    2 => [
        '/' => ['ordner' => ['Files', 'Groups'], 'dateien' => []],
        '/Files/' => ['ordner' => ['Desktop', 'Downloads', 'Unterricht'], 'dateien' => ['Adressliste.csv']],
        '/Groups/' => ['ordner' => ['Fachschaft Bio', 'Jahrgang 7'], 'dateien' => []],
    ],
];

function kp_echt_instanzen(): array {
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
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function kp_echt_listing(int $inst, string $path): array {
    global $USER, $CFG;
    require_once($CFG->dirroot . '/repository/lib.php');
    $ordner = [];
    $dateien = [];
    try {
        $repo = repository::get_repository_by_id($inst, context_user::instance($USER->id));
        $listing = $repo->get_listing($path);
        foreach (($listing['list'] ?? []) as $item) {
            if (isset($item['children'])) {
                $ordner[] = [
                    'titel' => trim((string) $item['title'], '/'),
                    'pfad' => kp_norm((string) ($item['path'] ?? $item['title'])),
                ];
            } else {
                $dateien[] = (string) $item['title'];
            }
        }
    } catch (Throwable $e) {
        return ['ordner' => [], 'dateien' => [], 'fehler' => $e->getMessage()];
    }
    return ['ordner' => $ordner, 'dateien' => $dateien];
}

$listing = function (int $inst, string $path) use ($stubbaum, $fall, $neuliste): array {
    $path = kp_norm($path);
    if ($fall === 'echt') {
        $l = kp_echt_listing($inst, $path);
    } else if ($fall === 'leer') {
        $l = ['ordner' => [], 'dateien' => []];
    } else {
        $roh = $stubbaum[$inst][$path] ?? ['ordner' => [], 'dateien' => []];
        $l = ['ordner' => [], 'dateien' => $roh['dateien']];
        foreach ($roh['ordner'] as $name) {
            $l['ordner'][] = ['titel' => $name, 'pfad' => $path . $name . '/'];
        }
    }
    // Im Prototyp angelegte Ordner erscheinen wie echte, nur markiert.
    foreach ($neuliste as $n) {
        $n = kp_norm($n);
        if (dirname(rtrim($n, '/')) . '/' === $path || (dirname(rtrim($n, '/')) === '/' && $path === '/')) {
            $l['ordner'][] = ['titel' => basename(rtrim($n, '/')), 'pfad' => $n, 'neu' => true];
        }
    }
    return $l;
};

$instanzen = $fall === 'keineinstanz' ? [] : ($fall === 'echt' ? kp_echt_instanzen() : $stubinstanzen);
if ($instanz === 0 && $instanzen) {
    $instanz = (int) array_key_first($instanzen);
}

// --------------------------------------------------------------------------
// Links und Bausteine.
// --------------------------------------------------------------------------

$url = function (array $extra = []) use (
    $variant, $fall, $instanz, $pfad, $ziel, $schritt, $kontextwahl, $materialwahl, $unterordner, $neu, $fertig
): string {
    $params = array_merge([
        'variant' => $variant, 'fall' => $fall, 'instanz' => $instanz, 'pfad' => $pfad,
        'ziel' => $ziel, 'schritt' => $schritt, 'kontextwahl' => $kontextwahl,
        'materialwahl' => $materialwahl, 'unterordner' => $unterordner, 'neu' => $neu, 'fertig' => $fertig,
    ], $extra);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
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
        $out .= ' <span class="text-muted">/</span> <a href="' . $url(['pfad' => $acc . '/']) . '">' . s($t) . '</a>';
    }
    return $out;
};

$eltern = static function (string $path): string {
    $p = rtrim($path, '/');
    $p = substr($p, 0, (int) strrpos($p, '/'));
    return kp_norm($p === '' ? '/' : $p);
};

/**
 * Der Ort, den die Kontextwahl tatsächlich ergibt – mit oder ohne Unterordner.
 */
$kontextort = function () use ($kontextwahl, $unterordner, $KURSPILOT_ORDNER): string {
    if ($kontextwahl === '') {
        return '';
    }
    return $unterordner ? kp_norm($kontextwahl) . $KURSPILOT_ORDNER . '/' : kp_norm($kontextwahl);
};

$pointerpanel = function (array $felder, array $anlegen = []) use ($instanzen, $instanz): string {
    $inst = $instanzen[$instanz] ?? ['name' => '?', 'server' => '?'];
    $daten = array_merge([
        'speicher' => [
            'instanzid' => $instanz,
            'instanzname' => $inst['name'],
            'server' => $inst['server'],
            'prüfmerkmal' => 'sha1(' . $inst['server'] . '|' . $inst['name'] . ')',
        ],
    ], $felder);
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $out = '<div class="card mt-3 border-success"><div class="card-body">'
        . '<h5 class="card-title">Was gespeichert würde <span class="badge bg-secondary">.kurspilot-ort.json</span></h5>';
    if ($anlegen) {
        $out .= '<p>Vorher angelegt: ' . implode(', ', array_map(static fn($p) => '<code>' . s($p) . '</code>', $anlegen)) . '</p>';
    }
    $out .= '<pre class="mb-2 text-wrap">' . s($json) . '</pre>'
        . '<p class="form-text mb-0">Frage 5: Instanz und Prüfmerkmal kommen zu den Pfaden dazu &mdash; '
        . 'eine gelöschte oder umkonfigurierte Instanz sendet kein Signal (#468).</p>'
        . '</div></div>';
    return $out;
};

// Neuen Ordner "anlegen": vor jeder Ausgabe, damit redirect() sauber greift.
// Im Prototyp entsteht kein Ordner auf dem Server - der Pfad wandert nur in
// die Merkliste und wird in der Ordnerliste als "wird angelegt" gezeigt.
$neuname = trim(optional_param('neuname', '', PARAM_RAW));
$neuvon  = trim(optional_param('neuvon', '', PARAM_RAW));
if ($neuname !== '' && $neuvon !== '') {
    $neupfad = kp_norm(kp_norm($neuvon) . trim($neuname, '/'));
    if (!in_array($neupfad, $neuliste, true)) {
        $neuliste[] = $neupfad;
    }
    redirect(new moodle_url('/local/kurspilot/prototype_ortswahl.php', array_filter([
        'variant' => $variant, 'fall' => $fall, 'instanz' => $instanz, 'ziel' => $ziel,
        'pfad' => $neupfad, 'kontextwahl' => $kontextwahl, 'materialwahl' => $materialwahl,
        'unterordner' => $unterordner, 'neu' => implode('|', $neuliste),
    ], static fn($v) => $v !== '' && $v !== null)));
}

// --------------------------------------------------------------------------
// Ausgabe.
// --------------------------------------------------------------------------

echo $OUTPUT->header();

echo '<div class="alert alert-warning py-2 small"><strong>Wegwerf-Prototyp zu #473.</strong> '
    . 'Nichts wird gespeichert, „Ordner anlegen" passiert nur am Bildschirm. Umschalten: Leiste unten.</div>';

$timeout = ($fall === 'langsam');

if ($timeout) {
    echo '<div id="wartepanel" class="alert alert-info d-flex align-items-center gap-3">'
        . '<div class="spinner-border spinner-border-sm" role="status"></div>'
        . '<div>Verbindung zu <strong>cloud.igs-musterstadt.de</strong> wird aufgebaut … '
        . '<span id="wartezeit">0</span> s</div>'
        . '<a class="btn btn-sm btn-outline-secondary ms-auto" href="' . $url(['fall' => 'ok']) . '">Abbrechen</a>'
        . '</div>';
}

echo '<div id="inhalt"' . ($timeout ? ' class="opacity-25 pe-none"' : '') . '>';

// ==========================================================================
// VARIANTE A – Dateifenster mit Fortschrittsband.
// ==========================================================================
if ($variant === 'a') {
    echo '<h3>Variante A &mdash; Dateifenster</h3>';

    if (!$instanzen) {
        echo '<div class="card"><div class="card-body text-center py-5">'
            . '<h4>Noch kein Speicher verbunden</h4>'
            . '<p class="text-muted">Kurspilot benutzt einen Speicher, den Sie in Moodle selbst anlegen &mdash; '
            . 'Kurspilot verwaltet keine Zugangsdaten.</p>'
            . '<a class="btn btn-primary" href="' . $verwaltungsurl . '">Speicher in Moodle anlegen</a>'
            . '<p class="mt-3 mb-0 text-muted"><small>Offen (aus der Rückmeldung): Ist das Anlegen von der Schule gar '
            . 'nicht freigegeben, führt dieser Knopf ins Leere &mdash; dann braucht es hier stattdessen den Text '
            . 'für die Administration.</small></p>'
            . '<p class="mt-2 mb-0"><a href="' . $url(['fall' => 'ok']) . '">Zurück (Prototyp)</a></p>'
            . '</div></div>';
    } else {
        $ort = $kontextort();
        $fertigbeides = ($kontextwahl !== '' && $materialwahl !== '');

        // Fortschrittsband: beide Ziele immer sichtbar, gewählt wird grün.
        $band = function (string $label, string $wert, string $key) use ($ziel, $url): string {
            $gewaehlt = $wert !== '';
            $stil = $gewaehlt ? 'border-success bg-success-subtle' : 'border-secondary';
            $aktiv = $ziel === $key ? ' shadow-sm' : '';
            $inhalt = $gewaehlt
                ? '<span class="text-success">&#10003;</span> <code>' . s($wert) . '</code>'
                : '<span class="text-muted">noch nicht gewählt</span>';
            return '<div class="border rounded p-2 flex-grow-1 ' . $stil . $aktiv . '">'
                . '<small class="text-uppercase text-muted">' . $label . '</small><br>' . $inhalt
                . ' <a class="small ms-2" href="' . $url(['ziel' => $key, 'pfad' => '/', 'fertig' => 0]) . '">'
                . ($gewaehlt ? 'ändern' : 'jetzt wählen') . '</a></div>';
        };

        echo '<div class="d-flex align-items-stretch gap-2 mb-3">';
        echo $band('Kontextbereich', $ort, 'kontext');
        echo $band('Materialbestand', $materialwahl, 'material');
        echo '<div class="d-flex align-items-center">';
        if ($fertigbeides) {
            echo '<a class="btn btn-success" href="' . $url(['fertig' => 1]) . '">Einrichten abschließen</a>';
        } else {
            echo '<button class="btn btn-success" disabled title="Erst wenn beide Orte gewählt sind">'
                . 'Einrichten abschließen</button>';
        }
        echo '</div></div>';

        if (!$fertigbeides) {
            $offen = $kontextwahl === '' ? 'Kontextbereich' : 'Materialbestand';
            echo '<div class="alert alert-info py-1 px-2 small">Es fehlt noch: <strong>' . $offen . '</strong>.</div>';
        }

        echo '<ul class="nav nav-tabs mb-3">';
        foreach (['kontext' => 'Kontextbereich', 'material' => 'Materialbestand'] as $key => $label) {
            $aktiv = ($ziel === $key) ? ' active' : '';
            $haken = (($key === 'kontext' && $kontextwahl !== '') || ($key === 'material' && $materialwahl !== ''))
                ? ' <span class="text-success">&#10003;</span>' : '';
            echo '<li class="nav-item"><a class="nav-link' . $aktiv . '" href="'
                . $url(['ziel' => $key, 'pfad' => '/', 'fertig' => 0]) . '">' . $label . $haken . '</a></li>';
        }
        echo '</ul>';

        echo '<div class="row g-3">';

        // Linke Spalte: Instanzen als Wurzeln.
        echo '<div class="col-md-3"><div class="card"><div class="card-body p-2">';
        echo '<h6 class="text-uppercase text-muted px-2">Speicher</h6><ul class="list-unstyled mb-0">';
        foreach ($instanzen as $id => $inst) {
            $aktiv = ($id === $instanz) ? ' fw-bold' : '';
            echo '<li class="px-2 py-1' . $aktiv . '"><a href="' . $url(['instanz' => $id, 'pfad' => '/']) . '">'
                . '&#128193; ' . s($inst['name']) . '</a><br><small class="text-muted">' . s($inst['server']) . '</small></li>';
        }
        echo '</ul><hr class="my-2"><a class="small" href="' . $verwaltungsurl . '">+ weiteren Speicher anlegen</a>';
        echo '</div></div></div>';

        // Rechte Spalte: Ordnerliste plus Anlegen.
        echo '<div class="col-md-9"><div class="card"><div class="card-body">';
        echo '<div class="mb-2">' . $breadcrumb($pfad) . '</div>';
        $l = $listing($instanz, $pfad);
        echo '<div class="list-group list-group-flush border rounded overflow-auto" style="max-height:18rem">';
        if ($pfad !== '/') {
            echo '<a class="list-group-item list-group-item-action py-1" href="' . $url(['pfad' => $eltern($pfad)])
                . '">&#8617; eine Ebene höher</a>';
        }
        if (!$l['ordner'] && !$l['dateien']) {
            echo '<div class="list-group-item text-center text-muted py-3">Dieser Ordner ist leer &mdash; '
                . 'er kann trotzdem gewählt werden.</div>';
        }
        foreach ($l['ordner'] as $o) {
            $marke = !empty($o['neu']) ? ' <span class="badge bg-info">wird angelegt</span>' : '';
            echo '<a class="list-group-item list-group-item-action py-1" href="' . $url(['pfad' => $o['pfad']]) . '">'
                . '&#128193; ' . s($o['titel']) . $marke . '</a>';
        }
        foreach ($l['dateien'] as $d) {
            echo '<div class="list-group-item text-muted py-1">&#128196; ' . s($d) . '</div>';
        }
        echo '</div>';

        // Neuen Ordner anlegen – aufklappbar, direkt in der Liste verankert.
        echo '<details class="mt-2"' . (optional_param('anlegen', 0, PARAM_INT) ? ' open' : '') . '>'
            . '<summary>&#10133; Neuen Ordner hier anlegen</summary>'
            . '<form method="get" class="d-flex gap-2 mt-2">';
        foreach ([
            'variant' => $variant, 'fall' => $fall, 'instanz' => $instanz, 'ziel' => $ziel,
            'kontextwahl' => $kontextwahl, 'materialwahl' => $materialwahl, 'unterordner' => $unterordner,
            'anlegen' => 1,
        ] as $k => $v) {
            echo '<input type="hidden" name="' . $k . '" value="' . s((string) $v) . '">';
        }
        echo '<input type="hidden" name="neuvon" value="' . s($pfad) . '">'
            . '<input class="form-control" name="neuname" placeholder="z. B. Kurspilot" required>'
            . '<button class="btn btn-outline-primary">Anlegen</button></form>'
            . '<div class="form-text">Wird beim Abschließen erzeugt.</div></details>';

        // Wählen – je Ziel eigener Abschluss.
        echo '<div class="border-top mt-3 pt-2">';
        echo '<div class="mb-2 small text-muted">Aktuelle Ebene: <code>' . s($pfad) . '</code></div>';

        if ($ziel === 'kontext') {
            $vorhanden = false;
            foreach ($l['ordner'] as $o) {
                if (strtolower($o['titel']) === $KURSPILOT_ORDNER) {
                    $vorhanden = true;
                }
            }
            echo '<div class="mb-2">';
            echo '<div class="form-check"><input class="form-check-input" type="radio" checked disabled>'
                . '<label class="form-check-label">Unterordner <code>' . $KURSPILOT_ORDNER . '</code> in diesem Ordner benutzen '
                . ($vorhanden
                    ? '<span class="badge bg-success">ist schon da &mdash; wird verwendet</span>'
                    : '<span class="badge bg-info">wird angelegt</span>')
                . '</label></div>';
            echo '<div class="form-check"><input class="form-check-input" type="radio" disabled>'
                . '<label class="form-check-label text-muted">Diesen Ordner direkt benutzen</label></div>';
            echo '</div>';
            echo '<a class="btn btn-primary" href="' . $url(['kontextwahl' => $pfad, 'ziel' => 'material', 'pfad' => '/'])
                . '">Diesen Ordner als Kontextbereich wählen</a>';
        } else {
            echo '<a class="btn btn-primary" href="' . $url(['materialwahl' => $pfad])
                . '">Diesen Ordner als Materialbestand wählen</a>';
            echo '<div class="form-text">Wird nur gelesen, nie verändert (#472).</div>';
        }
        echo '</div>';

        echo '</div></div></div>'; // rechte Karte
        echo '</div>'; // Grid

        if ($fertig && $fertigbeides) {
            $anlegen = [];
            foreach ($neuliste as $n) {
                $anlegen[] = $n;
            }
            if ($unterordner && $ort !== '' && !in_array($ort, $anlegen, true)) {
                $anlegen[] = $ort . '  (falls noch nicht vorhanden)';
            }
            echo $pointerpanel([
                'kontextbereich' => $ort,
                'materialbestand' => $materialwahl,
            ], $anlegen);
        }
    }
}

// ==========================================================================
// VARIANTE B – Assistent (bleibt zum Vergleich stehen).
// ==========================================================================
if ($variant === 'b') {
    echo '<h3>Variante B &mdash; Assistent, ein Ordner</h3>';
    echo '<p class="text-muted">Eine Wahl: der Materialordner. Der Kontextbereich wird als Unterordner '
        . '<code>kurspilot</code> daraus abgeleitet.</p>';

    if (!$instanzen) {
        echo '<div class="card"><div class="card-body">'
            . '<h4>Schritt 1: Welcher Speicher?</h4>'
            . '<div class="alert alert-secondary mt-3">Sie haben noch keinen Speicher in Moodle angelegt.</div>'
            . '<a class="btn btn-primary" href="' . $verwaltungsurl . '">Zur Repository-Verwaltung</a>'
            . '</div></div>';
    } else if ($schritt <= 1) {
        echo '<div class="card"><div class="card-body"><h4>Schritt 1: Welcher Speicher?</h4>';
        foreach ($instanzen as $id => $inst) {
            echo '<a class="d-block border rounded p-3 mb-2 text-decoration-none" href="'
                . $url(['instanz' => $id, 'schritt' => 2, 'pfad' => '/']) . '">'
                . '<strong>' . s($inst['name']) . '</strong><br>'
                . '<small class="text-muted">' . s($inst['server']) . ' &middot; WebDAV</small></a>';
        }
        echo '</div></div>';
    } else if ($schritt === 2) {
        $l = $listing($instanz, $pfad);
        echo '<div class="card"><div class="card-body">';
        echo '<h4>Schritt 2: In welchem Ordner soll Kurspilot arbeiten?</h4>';
        echo '<div class="mb-2">' . $breadcrumb($pfad) . '</div><div class="list-group mb-3">';
        if ($pfad !== '/') {
            echo '<a class="list-group-item" href="' . $url(['pfad' => $eltern($pfad)]) . '">&#8617; zurück</a>';
        }
        foreach ($l['ordner'] as $o) {
            echo '<div class="list-group-item d-flex justify-content-between align-items-center">'
                . '<a href="' . $url(['pfad' => $o['pfad']]) . '">&#128193; ' . s($o['titel']) . '</a>'
                . '<a class="btn btn-sm btn-outline-primary" href="'
                . $url(['pfad' => $o['pfad'], 'schritt' => 3, 'materialwahl' => $o['pfad']]) . '">wählen</a></div>';
        }
        if (!$l['ordner']) {
            echo '<div class="list-group-item text-muted">Keine Unterordner hier.</div>';
        }
        echo '</div><a class="btn btn-primary" href="' . $url(['schritt' => 3, 'materialwahl' => $pfad])
            . '">Diesen Ordner nehmen: <code>' . s($pfad) . '</code></a>';
        echo '</div></div>';
    } else {
        $gewaehlt = $materialwahl !== '' ? kp_norm($materialwahl) : $pfad;
        $kontext = $gewaehlt . $KURSPILOT_ORDNER . '/';
        echo '<div class="card"><div class="card-body"><h4>Schritt 3: Passt das so?</h4>';
        echo '<table class="table"><tbody>'
            . '<tr><th class="w-25">Ihr Material liegt in</th><td><code>' . s($gewaehlt) . '</code>'
            . '<br><small class="text-muted">wird nur gelesen, nie verändert</small></td></tr>'
            . '<tr><th>Kurspilot legt seine Notizen in</th><td><code>' . s($kontext) . '</code>'
            . '<br><small class="text-muted">wird angelegt, falls noch nicht vorhanden</small></td></tr>'
            . '<tr><th>Chat-Anhänge und Zuschnitte</th><td>bleiben in Moodle (Werkbank)</td></tr>'
            . '</tbody></table>';
        echo '<a class="btn btn-success" href="' . $url(['schritt' => 4]) . '">So einrichten</a> '
            . '<a class="btn btn-link" href="' . $url(['schritt' => 2, 'materialwahl' => '']) . '">anderen Ordner wählen</a>';
        echo '</div></div>';
        if ($schritt >= 4) {
            echo $pointerpanel(['kontextbereich' => $kontext, 'materialbestand' => $gewaehlt], [$kontext]);
        }
    }
}

echo '</div>'; // #inhalt

if ($fall === 'fehler') {
    echo '<div class="alert alert-danger"><strong>cloud.igs-musterstadt.de antwortet nicht '
        . '(Zeitüberschreitung nach 8 Sekunden).</strong><div class="mt-2">'
        . '<a class="btn btn-sm btn-outline-light me-2" href="' . $url([]) . '">Erneut versuchen</a>'
        . '<a class="btn btn-sm btn-outline-light me-2" href="' . $verwaltungsurl . '">Zugangsdaten prüfen</a>'
        . '<a class="btn btn-sm btn-outline-light" href="' . $url(['fall' => 'ok']) . '">Später</a></div></div>'
        . '<p class="text-muted">Frage 3: Bis hierher ist nichts gespeichert &mdash; die Seite kann einfach '
        . 'verlassen werden. Im Zustimmungsdialog ginge das nicht.</p>';
}

if ($timeout) {
    $wiederholen = $url(['fall' => 'ok']);
    echo <<<HTML
<div id="timeoutpanel" hidden>
  <div class="alert alert-danger">
    <strong>Keine Antwort von cloud.igs-musterstadt.de.</strong>
    <p class="mb-2">Nach 8 Sekunden abgebrochen. Nichts wurde gespeichert.</p>
    <a class="btn btn-sm btn-primary" href="{$wiederholen}">Nochmal versuchen</a>
    <a class="btn btn-sm btn-outline-secondary" href="{$verwaltungsurl}">Zugangsdaten prüfen</a>
  </div>
</div>
<script>
(function () {
  var t = 0, feld = document.getElementById('wartezeit');
  var iv = setInterval(function () {
    t += 1; feld.textContent = t;
    if (t >= 8) {
      clearInterval(iv);
      document.getElementById('wartepanel').hidden = true;
      document.getElementById('inhalt').hidden = true;
      document.getElementById('timeoutpanel').hidden = false;
    }
  }, 1000);
})();
</script>
HTML;
}

// --------------------------------------------------------------------------
// Schaltleiste (Prototyp-Möbel, nicht Teil des Entwurfs).
// --------------------------------------------------------------------------

$varianten = ['a' => 'Dateifenster', 'b' => 'Assistent'];
$faelle = [
    'ok' => 'alles da', 'leer' => 'Ordner leer', 'keineinstanz' => 'keine Instanz',
    'langsam' => 'Server langsam', 'fehler' => 'Server tot', 'echt' => 'echte Instanzen',
];

echo '<div class="position-fixed bottom-0 start-50 translate-middle-x mb-3 z-3 '
    . 'bg-dark text-white rounded-pill shadow px-3 py-2 d-flex align-items-center gap-2 small">';
echo '<span class="opacity-50">PROTOTYP</span>';
foreach ($varianten as $key => $label) {
    $stil = $key === $variant ? 'btn-light' : 'btn-dark';
    echo '<a class="btn btn-sm rounded-pill ' . $stil . '" href="' . $url([
        'variant' => $key, 'schritt' => 1, 'pfad' => '/', 'ziel' => 'kontext',
        'kontextwahl' => '', 'materialwahl' => '', 'neu' => '', 'fertig' => 0,
    ]) . '">' . strtoupper($key) . ' ' . $label . '</a>';
}
echo '<span class="opacity-25">|</span>';
echo '<select class="form-select form-select-sm w-auto rounded-pill" onchange="location=this.value">';
foreach ($faelle as $key => $label) {
    echo '<option value="' . $url([
        'fall' => $key, 'pfad' => '/', 'schritt' => 1, 'kontextwahl' => '', 'materialwahl' => '', 'neu' => '', 'fertig' => 0,
    ]) . '"' . ($key === $fall ? ' selected' : '') . '>' . $label . '</option>';
}
echo '</select></div>';

echo $OUTPUT->footer();
