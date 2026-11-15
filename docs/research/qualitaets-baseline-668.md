# Einstiegsskripte und Coverage – #668

Messung auf `integration/spec-0029-followup`, Start `d65d3fa18f968a19be27016dc1adfb96967017c6`.
Vorher: gesicherter echter vollständiger #687-Lauf auf genau diesem Quellstand.
Nachher: vollständiger `npm run gate -- full` mit `GATE_BASE_REF=origin/dev`, Moodle 5.1.7+,
PHP 8.4.25, MariaDB 11.4.12 und PCOV 1.0.12; Exit 0, 1.595 Tests, 131.127 Assertions,
14 bestehende Skips. Node: 157/157. Alle statischen Prüfungen ohne neue Befunde,
22 bestehende Deptrac-Ausnahmen sichtbar. Coverage-/CRAP-Ratsche: 0 Verstöße.

## Messung und Zuschreibung

| Stand | Gedeckte / ausführbare Zeilen | Coverage | Dateien |
|---|---:|---:|---:|
| Alte versionierte Baseline | 11356/14006 | 81,08 % | – |
| Vollständige Vorhermessung, bisherige Deklarationsausschlüsse | 11437/14049 | 81,41 % | 184 |
| Extraktion und neue Tests, mit bisheriger Ausschlussliste ausgewertet | 11990/14049 | 85,34 % | 189 |
| Dieselbe Vollmessung, zusätzlich 18 delegierende Skripte ausgeschlossen | 11990/13900 | 86,26 % | 171 |

Die mittlere und letzte Zeile werten **dieselbe tatsächliche vollständige Clover-Messung**
mit den beiden expliziten Filterlisten aus. Zusätzlich wurde schon vor Änderung der
Ausschlussliste eine echte Fokusmessung der extrahierten Klassen durchgeführt
(46 Tests/171 Assertions); sie ist keine vollständige Baseline.

Die neue Testdeckung schreibt 553 zusätzliche Zeilen gut: 355 in den extrahierten bzw.
erweiterten Klassen und 198 in `db/upgrade.php`. Bei identischem Gesamtnenner sind das
**+3,94 Prozentpunkte**. Der anschließende Ausschluss entfernt **149 unbedeckte Zeilen**
und verändert den Zähler nicht: **+0,91 Prozentpunkte allein durch den Ausschluss**.
Zusammen +4,85 Punkte gegenüber der tatsächlichen Vorhermessung. Der Abstand der alten
versionierten Baseline zur Vorhermessung war bereits vor dieser Änderung vorhanden.
Das sind keine 553 neu gebauten Fachfunktionen: die Klassen enthalten insbesondere die
nun direkt getestete vorhandene Seitenlogik.

Die Skripte schrumpfen durch Extraktion von 499 auf 149 ausführbare Zeilen (−350).
Klassen wachsen um 351 ausführbare Zeilen. Das unveränderte Upgrade-Skript wird nach
seiner tatsächlichen Ausführung mit 198 statt 199 Zeilen ausgewiesen (−1 durch die
Coverage-Instrumentierung, kein Quellcode-/Ausschlusswechsel). Dadurch bleibt der
Gesamtnenner vor den neuen Ausschlüssen tatsächlich 14.049.

Die Baseline wurde erst danach mit `gate baseline <vollständiges-clover>` auf 11990/13900
angehoben. Kein Kopieren ungemessener Werte, kein Senken, keine Verschiebung von `armedAt`.
Diese lokale Messung ersetzt die separate echte PR-CI-Abnahme nicht.

## Jede ausgeschlossene Datei

Alle Zahlen stammen aus vollständigem Clover. Nach Ausschluss bedeutet 0/0 nur den
Beitrag zum Gate-Nenner; die Rohdatei bleibt im Clover. Vorher und nach Extraktion
ist bei diesen Skripten keine Zeile über Covers-Metadaten gutgeschrieben.

| Datei relativ zum Plugin | Vorher | Nach Extraktion | Beitrag nach Ausschluss | Nennerdelta Ausschluss |
|---|---:|---:|---:|---:|
| `admin/connections.php` | 0/23 | 0/13 | 0/0 | -13 |
| `connections.php` | 0/23 | 0/11 | 0/0 | -11 |
| `history.php` | 0/74 | 0/7 | 0/0 | -7 |
| `location_selection.php` | 0/78 | 0/14 | 0/0 | -14 |
| `location_selection_browse.php` | 0/19 | 0/8 | 0/0 | -8 |
| `mcp.php` | 0/31 | 0/10 | 0/0 | -10 |
| `oauth.php` | 0/10 | 0/6 | 0/0 | -6 |
| `oauth/authorize.php` | 0/52 | 0/18 | 0/0 | -18 |
| `oauth/jwks.php` | 0/6 | 0/6 | 0/0 | -6 |
| `oauth/protected-resource.php` | 0/6 | 0/6 | 0/0 | -6 |
| `oauth/register.php` | 0/14 | 0/10 | 0/0 | -10 |
| `oauth/token.php` | 0/14 | 0/9 | 0/0 | -9 |
| `ortswahl.php` | 0/6 | 0/6 | 0/0 | -6 |
| `ortswahl_browse.php` | 0/1 | 0/1 | 0/0 | -1 |
| `settings.php` | 0/98 | 0/1 | 0/0 | -1 |
| `surface.php` | 0/18 | 0/18 | 0/0 | -18 |
| `werkbank/download.php` | 0/1 | 0/1 | 0/0 | -1 |
| `workbench/download.php` | 0/25 | 0/4 | 0/0 | -4 |
| **Summe** | **0/499** | **0/149** | **0/0** | **−149** |

Jeder Eintrag in `scripts/gate/baseline.json` nennt seine konkret delegierte Klasse bzw.
den erhaltenen Alt-URL-Vertrag. Der Node-Vertrag `test/gate-entry-scripts.test.js`
prüft die explizite Liste, Verzweigungen, lokale Funktionen und direkten DB-Zugriff.
Die bereits delegierenden Discovery-/JWKS-/Protected-Resource-/Surface-Seiten und
Alt-URL-Shims werden unter derselben Bedingung ausgeschlossen. Keine Klasse,
`lib.php`, `db/upgrade.php` oder `db/upgradelib.php` wird ausgeschlossen.

## Klassen und echter Upgrade-Nachweis

| Datei | Gedeckt / ausführbar vorher | Gedeckt / ausführbar nachher |
|---|---:|---:|
| `classes/admin/settings.php` | 0/0 | 100/100 |
| `classes/output/admin_connections_page.php` | 24/24 | 37/37 |
| `classes/output/authorize_page.php` | 35/36 | 72/73 |
| `classes/output/connections_page.php` | 17/17 | 27/27 |
| `classes/output/history_page.php` | 31/35 | 83/83 |
| `classes/output/http_endpoint.php` | 0/0 | 52/52 |
| `classes/output/http_response.php` | 0/0 | 11/11 |
| `classes/output/location_selection.php` | 0/95 | 0/95 |
| `classes/output/location_selection_page.php` | 0/0 | 70/70 |
| `classes/output/page_response.php` | 0/0 | 10/10 |
| `classes/output/surface_page.php` | 28/28 | 28/28 |
| `db/upgrade.php` | 0/199 | 198/198 |
| `db/upgradelib.php` | 0/164 | 0/164 |

Die öffentlichen produktiven Nähte sind `admin\settings::register()` und die
`response()`-Methoden der Seiten sowie `http_endpoint`. DB, Moodle-Zugriff,
OAuth-Code-/Token-Ausgabe, Einmaltickets, Wiederherstellung und Rendering laufen real.
Nur der bestehende WebDAV-Transport-Port erhält ein Double für das externe System.
`page_response` und `http_response` werden auch produktiv zur tatsächlichen Ausgabe
benutzt; es gibt keine Produktions-Testhooks oder spekulativen Interfaces.

Die neuen bzw. erweiterten Tests prüfen Einstellungsbaum und Defaults, Rechte-/Eigentums-
ablehnung, Einzel-/Gesamtwiderruf, Bestätigung ohne Schreiben und tatsächliche
Wiederherstellung, Ortswahl mit verifizierter OAuth-Rückkehr und sichtbarem Fehler,
Consent/PKCE-Codeaustausch sowie CGI-Bearer-Präzedenz, JSON/Formular, Listing,
Einmal-Download, Originalbytes und UTF-8-Substitution. Moodle-HTML wird mit dem echten
Boost-Renderer geliefert; der HTTP-Sender läuft über PHPUnits standardmäßigen isolierten
Prozess, nicht über einen eigenen Testhook.

`tests/upgrade_test.php` ruft den tatsächlichen `xmldb_local_coursepilot_upgrade()`-Einstieg
auf: von Version 0 werden die Plugin-Tabellen neu aufgebaut, das aktuelle Schema und
Savepoint 2026100344 geprüft und der alte Tabellenname ausgeschlossen. Ein zweiter
Test startet vor dem OAuth-Budget-Upgrade, erhält eine echte vorhandene Verbindung,
prüft Budgettabellen-Neuanlage und Tokenrotation und wiederholt den aktuellen Upgrade-
Aufruf ohne Versionsrückschritt. DDL wird im `finally` auf das aktuelle Plugin-Schema
zurückgesetzt; ausschließlich die isolierte Testdatenbank ist betroffen.
`db/upgradelib.php` bleibt mit 164 unbedeckten Zeilen sichtbar: CoversFunction schreibt
nur dem genannten Upgrade-Einstieg Coverage gut, nicht automatisch seinen Helfern.

## Erhaltene Verträge und begrenzte Begleitänderungen

URLs, Zugriffs-/Sesskeyprüfungen, Template-Daten, HTTP-Status/Headers und veröffentlichte
Werkzeuge bleiben erhalten. Der alte `history.php`-Catch für eine zweite Bestätigung
war unerreichbar: unbestätigt wurde vorher die Bestätigungsseite geliefert; bestätigt
warf der Catch stets erneut. Die Klasse erhält dieses tatsächliche Verhalten und lässt
die unerreichbare HTML-Verzweigung weg. Die Restore-Werkzeugimplementierung bleibt unverändert.

Die bestehenden WebDAV-Fixtures werden von neuen HTTP-/Ortswahltests verwendet. Ihre
Dokumentation nennt tatsächliche Array-Elementtypen, einschließlich absichtlich
fehlerhafter Fingerprints in Negativtests. Damit entfallen 75 reale Typisierungsausnahmen;
12 weitere PHPStan-Einträge sind durch die Extraktion obsolet. Keine neue Suppression.
Die Include-Variablen der Einstellungen werden im Dateidocblock dokumentiert; `global`
wird nicht benutzt, weil Moodle sie im lokalen Include-Scope bereitstellt.

## Reproduzierbare Artefakte

Auf dem Implementierungsrechner: `/tmp/coursepilot-668-before-clover.xml`,
`/tmp/coursepilot-668-full-clover.xml`, `/tmp/coursepilot-668-coverage-measured.json`,
`/tmp/coursepilot-668-full.log` und `.exit`, `full-gate-report.json`, `full-gate-static.json`,
`/tmp/coursepilot-668-baseline.log` und `.exit`. Die JSON-Messung enthält jede Datei
mit beiden vollständigen Filterauswertungen; der Orchestratorbericht ergänzt Commit,
Hooks, Abschlussreviews und den erst nach Push verfügbaren CI-Nachweis.
