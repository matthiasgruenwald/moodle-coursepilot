# Builds und Tests

## Standardbefehle

- `npm test` - `node --test`, Vertragstests der nativen Linie
- `npm run build:native-release` - Release-ZIP aus `Plugin/src/local_coursepilot/`
- `npm run gate -- fast|full` - Qualitäts-Gate (Messung, Gate-Container), siehe [`docs/gate.md`](../gate.md)

## Plugin-Quelle

- PHP-Quelle liegt in `Plugin/src/local_coursepilot/`.

## PHPUnit für `local_coursepilot`

`local_coursepilot` hat als erstes Kurspilot-Plugin ein Testfundament — der
Datenschutz-Vertrag wird ausschließlich per PHPUnit erzwungen, es gibt keinen
Node-Test für dieses Plugin (Kartenentscheidung
[#300](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/300),
umgesetzt in
[#309](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/309)).

**Ausführung: manuell im Spike-Container**, nicht auf den vier regulären
Devstack-Containern. Die Tests laufen gegen eine eigene Test-Datenbank
(Präfix `t_`) und ein eigenes `phpunitdata` — die restaurierten Daten der
Spike-Instanz werden nicht angefasst.

```bash
# einmalig, und nach jedem Moodle-Upgrade der Spike-Instanz
bash /opt/kurspilot-spike/scripts/phpunit.sh --init

# Testlauf (spiegelt Plugin/src/local_coursepilot vorher in den Bind-Mount)
bash /opt/kurspilot-spike/scripts/phpunit.sh
bash /opt/kurspilot-spike/scripts/phpunit.sh --filter privacy_surface_test
```

Ohne das Skript, direkt:

```bash
set -a; source /opt/kurspilot-spike/docker/kurspilot-spike.env; set +a
/opt/moodle-docker-kurspilot-spike/bin/moodle-docker-compose exec -T webserver \
  vendor/bin/phpunit --testsuite local_coursepilot_testsuite
```

Die Mindestversion ist Moodle 5.1 (`$plugin->requires = 2025100600`); die
Spike-Instanz läuft auf 5.1. Die verbindliche Matrix prüft Moodle 5.1/5.2/5.3
mit PHP 8.3/8.4, MariaDB 11.4 und PostgreSQL 17. Die genauen Kombinationen
und das volle Qualitäts-Gate mit Ratsche stehen in
[`docs/ci-native-server-mcp.md`](../ci-native-server-mcp.md) (#268).

#### Der volle Lauf braucht einen abgekoppelten Prozess

Die Suite läuft ~4:50. Agenten-Harnesses schießen Hintergrundbefehle vorher
ab — der Lauf endet dann kommentarlos mit `exit 144` und **ohne jede
Ausgabe**, weil `tail`/Pipes den Puffer nie leeren. Das sieht wie ein
Testfehler aus, ist aber keiner. Deshalb abgekoppelt starten und getrennt auf
die Schlusszeile warten:

```bash
setsid nohup bash /opt/kurspilot-spike/scripts/phpunit.sh > phpunit.log 2>&1 < /dev/null & disown
until grep -qE "OK \(|FAILURES|ERRORS" phpunit.log; do sleep 15; done; tail -12 phpunit.log
```

Einzelne Tests (`--filter <name>`) laufen in Sekunden und brauchen das nicht.

### Diagnostik synthetischer Kindprozesse

Die Prozess-Fixtures booten über `tests/fixtures/phpunit_process_bootstrap.php`.
Moodle kann dabei `display_errors` wieder einschalten. Der gemeinsame Bootstrap
leitet seine Ausgabe deshalb nach stderr weiter; stdout bleibt für JSON und
Statuswerte reserviert. Meldungen und Fehlercodes bleiben erhalten.

Validiert beim englischen Basisnachzug (#605): fünf OAuth-Races und die vier
betroffenen Klassen für Standardexport, Verlaufsbereinigung, CIMD und
Registrierungsbudget bestehen (38 Tests, 254 Assertions). Ein isolierter
Bootstrap liefert parsebares JSON; künstliche Exception und Fatalfehler enden
jeweils mit Code 255 und Diagnose auf stderr.

Auf Spike melden die Fremdplugins `block_exacomp` und `block_exaport` unter PHP
8.4 veraltete nullable Signaturen. Nur wenn **alle** gemeldeten Deprecations
nachweislich aus diesen Fremdplugins stammen, ist für den lokalen Lauf
`--do-not-fail-on-deprecation` zulässig. Die Meldungen bleiben sichtbar; die
verbindliche CI läuft unverändert ohne diese Ausnahme.

### Was die Suite abdeckt

| Datei | Zweck |
|---|---|
| `tests/install_test.php` | Install-Smoke: Version, `requires >= 5.1`, beide Capabilities, externer Dienst |
| `tests/privacy_surface_test.php` | Vertragstest: real **registrierte** Oberfläche ↔ Allowlist ↔ verbotene Namensbestandteile |
| `tests/instance_check_test.php` | Urteilsteil der Instanzprüfung per Selbstabruf (#340): Discovery-URL, Erfolgs-/Fehlerfälle, ohne echten HTTP-Request |
| `tests/external/list_courses_test.php` | Je externer Funktion ein Test, plus Capability-Test (`CAPABILITY_MISSING`, keine Daten) |

Der Vertragstest prüft nicht die Repo-Quelle, sondern die auf der laufenden
Instanz registrierte Oberfläche — er fängt damit den Fall, den kein Repo-Test
fangen kann: ein Admin hängt dem Dienst nachträglich eine Funktion an.
Dieselbe Prüffunktion (`\local_coursepilot\privacy_surface::check()`) nutzen
auch die Laufzeit (`mcp.php`) und die Anzeige (`/local/coursepilot/surface.php`).

### CI

Dieselbe Suite läuft zusätzlich in GitHub Actions, siehe
[`docs/ci-native-server-mcp.md`](../ci-native-server-mcp.md).

## E2E-Tests (Playwright) gegen die Spike-Instanz

Playwright-Specs liegen in `test/e2e/`, Config: `playwright.config.js`. Es gibt
nur noch Spike-Specs (`*.spike.e2e.spec.js`); sie laufen mit dem Profil
`spike` gegen `.env.e2e.spike` (Werte siehe nächster Abschnitt) und brauchen
zusätzlich `MOODLE_USERNAME`/`MOODLE_PASSWORD` für den Browser-Login:

```bash
KURSPILOT_E2E_PROFILE=spike npx playwright test
```

Ohne Profil oder Zugangsdaten werden die Specs übersprungen (Skip, kein Fehler).

`quiz-local-questions.spike.e2e.spec.js` prüft den OAuth/MCP-HTTP-Weg ohne
Browser-Login: Quiz-Kategorien initialisieren, Fragen schreiben/versionieren,
XML in eine benannte Fragensammlung übernehmen und Fragen samt Versionen
verschieben, jeweils mit Read-back der Quiz-Referenz. Der Test stellt sein
OAuth-Token über `spike-e2e-token.sh` aus und benötigt Docker-Zugriff auf Spike.
Er legt eindeutig benannte temporäre Aktivitäten in Kurs 6 an und entfernt sie
sowie die exportierte XML-Datei im `finally`-Block. Bei XML-Reimport aus einem
Export ist `location: 'workbench'` erforderlich, falls die Materialablage extern
liegt. Einzellauf:

```bash
KURSPILOT_E2E_PROFILE=spike npx playwright test test/e2e/quiz-local-questions.spike.e2e.spec.js
```

## Live-Tests gegen die Spike-Instanz (`local_coursepilot`)

Das Servermodell-Plugin spricht kein Webservice-Token, sondern **OAuth**: der
MCP-Endpunkt `/local/coursepilot/mcp.php` akzeptiert ausschließlich ein
Zugriffstoken aus `connection`. Für Testläufe muss deshalb kein Browser-Flow
durchlaufen werden:

```bash
bash scripts/spike-e2e-token.sh              # Token fuer teacher_edit
bash scripts/spike-e2e-token.sh grw          # anderer Nutzer
```

Das Skript registriert einen Client, stellt einen Code aus und löst ihn ein —
alles per `docker exec` im Spike-Container. Das Token gilt eine Stunde.

Feste Testkonfiguration (Vorlage: `.env.e2e.spike.example`, ausgefüllt nach
`.env.e2e.spike`, gitignored):

| Wert | |
|---|---|
| Instanz | `https://spike.gruenwald.fun` |
| Testkurs | **ID 6** (`testkurs-mcp`) |
| Nutzer | `teacher_edit` (eingeschrieben, `local/coursepilot:use`) |
| Login | Passwort in `/opt/moodle-devstack-secrets/kurspilot-spike.env` (LXC, 0600) |
| Container | `moodle-kurspilot-spike-webserver-1` |

Beispielaufruf:

```bash
TOK=$(bash scripts/spike-e2e-token.sh)
curl -s -X POST https://spike.gruenwald.fun/local/coursepilot/mcp.php \
  -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## Hooks aktivieren

Claude, Codex und Menschen nutzen dieselben Git-Hooks und dasselbe Gate-Kommando
(`scripts/gate/gate.js`, siehe [`docs/gate.md`](../gate.md)). Einmal pro Klon:

```bash
npm run hooks:install   # git config core.hooksPath scripts/githooks
```

- `pre-commit` führt `npm run gate -- fast` aus und blockiert den Commit bei Befund.
- `pre-push` führt `npm run gate -- full` aus (rund 15 Minuten) und blockiert bei
  Ratschenverletzung. Den vollen Lauf abgekoppelt starten, wenn der Harness lange
  Läufe abschießt (Exit 144), siehe oben. Der Hook blockiert, wenn ein gepushter
  Branch nicht `HEAD` ist oder versionierte Dateien geändert sind: Geprüft wird der
  Arbeitsbaum, er muss dem gepushten Stand entsprechen.
- Die Edit-Hooks in `.claude/settings.json` und `.codex/hooks.json` rufen
  `gate edit` auf (Plugin-PHP, `test/**/*.js`, `scripts/gate/**`). Codex führt
  Hooks nicht in jeder Konfiguration automatisch aus; dann vor dem Commit
  `npm run gate -- fast` selbst starten, der `pre-commit`-Hook greift in jedem Fall.

## Verbindung testen

`connection` kapselt Code-Tausch, Token-Rotation, Prüfung, Widerruf und die gemeinsame Zugriffsregel für MCP und Werkbank-Tickets. PHPUnit nutzt ihre öffentliche Schnittstelle mit der echten Testdatenbank; die Exchange-Race prüft zwei überlappende `exchange_code()`-Aufrufe. Discovery verbleibt in `oauth_lib`. Clientregistrierung, Client-Nachschlagen und exakte Redirect-Prüfung liegen in `client_registry`; `oauth_lib` delegiert seine bisherigen öffentlichen Einstiege. Registertests verwenden `cimd_fetch_port` mit einem speicherinternen Testadapter ohne Netz. `cimd_http_fetcher` erhält die bestehenden TLS-/Streaming-Regressionstests; sie schützen Moodle-Hostpolicy, CA-Vertrauen, Größenlimit und Deadline.

`connection_event_test` prüft native Moodle-Ereignisse für Freigabe, Einzel-/Massenwiderruf und nachgewiesenes Refresh-Replay mit `redirectEvents()`. Normale Rotation, zurückgewiesene Zugriffe und fehlgeschlagene Code-Tausche erzeugen keine Ereignisse. Die bestehenden Prozess-Fixtures prüfen auch überlappenden Massenwiderruf und Rotation in beiden Reihenfolgen; ihre Named-Lock-Barriere erfordert MariaDB/MySQL.

## Kontextablage und Ausstandsnotiz

`context_area::write()` und `append()` lösen den aktuellen Ablageort auf und rufen die
öffentlichen Port-Einstiege `write_to()` und `append_to()` auf. Die Kontext-Schutzregeln liegen in `context_files`; `storage_write` hält Vorab-Lesen,
Schreibbedingung und Ausfallübersetzung gemeinsam für Kontext und Material zusammen.
`material_area::write()` und `write_to()` nutzen denselben Ablauf. compose/crop erhalten
ihre Provenienz über das typisierte `source`-Argument des Ablage-Ports.
Der XML-Export nutzt denselben Schreiber mit einer eigenen Bereichspolitik, die
seine bisher permissiven Datei- und Ordnernamen erhält; Traversal bleibt gesperrt. Tests
ersetzen ausschließlich `storage_port` beziehungsweise den WebDAV-Transport; das Speichern
der Ausstandsnotiz läuft gegen die echte Moodle-Testdatenbank. `storage_failure` trägt eine
neutrale Fehlerklasse und interne Diagnostik. Adapter legen keine Ausstandsnotiz an; die
Fachseite übersetzt Ausfälle einmal und erhält die bisherigen Werkzeugfehlercodes.

## Ablageort-Anker und Datei-Grundfunktionen

`storage_anchor` löst Ortszeiger und Pfade auf und wählt den Ablage-Port. Datei-I/O,
Quota und sichere Ersetzung liegen in `private_files_storage_port`; bestehende
Kontext-/Material-Fassaden delegieren dorthin. Die Ortswahl liest Kandidatenordner
und legt Ordnerketten über `webdav_storage_port` an. Sie behält Bestätigung,
Verlauf, Altdatenprüfung und ihre bisherigen Fehlercodes.

Die Port-Vertragstests sowie `location_selection_test` prüfen das Verhalten gegen
die echte Moodle-Testdatenbank; nur der externe WebDAV-Transport wird ersetzt.
Die bisherigen Kontext-, Material- und Ausstandsnotiztests bleiben erhalten und decken die verlagerten
Datei-Grundfunktionen ausdrücklich per `CoversClass` ab.

`file_storage.stub` korrigiert für PHPStan den als `int` dokumentierten
Verzeichnispfad von Moodles `get_directory_files()` zu `string`. Der Stub
unterdrückt keine Befunde; die bisherige Ausnahme im Anker entfällt.

## Atomare Quiz-Einstellungen

`catalog/quiz_write` übernimmt für `create_quiz` und `update_quiz_settings` die
Modusauflösung, Zielprüfung und komplette native Schreibfolge in einer Moodle-Transaktion.
Die Höchstbewertung wird vor dem Modulupdate skaliert, damit ausdrücklich gesetzte
Feedbackgrenzen bereits für die neue Bewertung gelten. Quiz-Kombinationsprüfung und
Formübersetzung bleiben im Katalog (`quiz_write_bridge`, `write_target`).
Die öffentlichen Werkzeugtests prüfen einen späten Core-Hook-Fehler nach Bewertung und
Einstellungen sowie die native Ablehnung nach dem Anlegen einer Kurssektion. Beides
muss den vorherigen Datenbankstand erhalten; die Werkzeugverträge bleiben unverändert.

## Abschlussbedingungen im Modulkatalog

`catalog/completion_settings` besitzt die einzige Abschluss-Felddefinition sowie Lesen,
Validierung und die native Schreibfolge. `set_completion` validiert nur die externen
Parameter und delegiert. Blocklisten, Restore-Feldwahl und gemeinsame Abschlusswerte in
Lesern und Verlaufssnapshots werden aus dieser Definition abgeleitet.

Die öffentlichen Tests `set_completion_test` und `quiz_completion_test` prüfen weiterhin
Werkzeugantworten und beide Bestätigungen. `completionminattempts` gilt nur für Quiz;
0 schaltet die Bedingung aus, positive Werte dürfen das konfigurierte Versuchslimit nicht
überschreiten. Der notwendige native Form-Schalter wird abgeleitet; ein unveränderter Wert
setzt keinen Abschluss zurück. Die Regeln folgen ADR 0016 und Spec 0015 §8; die bestehende
Restore-Abfolge bleibt bis zum eigenen Folgeticket erhalten.

## Wiederherstellen über den Änderungsverlauf

`history/version_restore::restore()` besitzt das Fortschreiben früherer Stände. Es nutzt
`catalog/module_settings::write()`, dessen Material-Felddefinition und Pseudofeld-Entwürfe
sowie `completion_settings::write()`. Die externen Werkzeuge validieren ihre veröffentlichten
Parameter und delegieren. Beide Fachoperationen prüfen Kontext und Berechtigungen selbst.

Die bisherige Reihenfolge bleibt bestehen: Einstellungen, Dateien, Abschlussbedingungen.
Eine fehlende Abschlussbestätigung liefert weiterhin den bestehenden Warnhinweis nach den
anderen Teiländerungen. Quiz stellt weiterhin ausschließlich die Fragenanordnung wieder her;
Dateilücken und Lernendensperren behalten ihre bisherigen Regeln. Dies ist die Umsetzung
von ADR 0016/0030 und #700, keine neue Atomaritäts- oder Produktentscheidung.
Die vorhandenen Werkzeugtests bleiben erhalten und decken die neuen Eigentümer zusätzlich
mit `CoversClass` ab; öffentliche Fachmodultests prüfen dieselben Schreib-/Restore-Ergebnisse.

Die übernommenen Meldungen liegen wortgleich in englischen Moodle-Sprachstrings.
Die öffentlichen Fachmodultests sichern auch den exakten bisherigen Meldungswortlaut;
Parameter- und Rückgabeschemata bleiben unverändert.
