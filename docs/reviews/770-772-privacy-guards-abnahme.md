# Datenschutz-Guards: Abnahme

Auftrag: [770](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/770)
und [772](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/772).
Basis: `3f21d2c78b2eeecc31787c48c18144a262b532be` (dev).
Ein schreibender Agent im zugewiesenen Worktree, Umsetzung nacheinander.

## Verhalten

- Markierungen tolerieren UTF-8-BOM, führende Leerzeilen, schließenden Trenner
  am Dateiende und Leerzeichen vor dem Doppelpunkt. Bestehendes mehrzeiliges
  `true`, CRLF, verschachtelte und punktierte Schlüssel bleiben erkannt.
- Keine optionale Werterweiterung: `True`, `"true"`, `yes` bleiben unmarkiert;
  akzeptierte Schreibweisen stehen in Spec 0010. Die separate Frage zur
  Markierungsprüfung von Nicht-Markdown-Dateien in Listen bleibt außerhalb.
- Upgrade `2026111500` leert nur `local_coursepilot_context_mark`; Dateien und
  sonstige Nutzdaten bleiben erhalten. Der nächste Listenzugriff erkennt neu.
- Availability gibt die Kerntypen `completion`, `date`, `grade`, `group`,
  `grouping` unverändert zurück. Profilwerte bleiben wie bisher maskiert.
  Unbekannte Typen liefern nur Typ und `v: "***"`, auch mit eigenem `c`-Feld.
  Unmaskierte historische Zustände bleiben für native Wiederherstellung erhalten.

## Red/Green

Ausgeführt im kanonisch vorbereiteten Gate-Container
`kurspilot-gate-webserver-1`: Moodle 5.1.7+, PHP 8.4.27, MariaDB 11.4.12.
Nach jedem Plugin-Sync wurde `--buildcomponentconfigs` ausgeführt, nach der
Versionsanhebung zusätzlich `admin/tool/phpunit/cli/init.php`.
Alle Upgrade-Aufrufe laufen innerhalb von `advanced_testcase` mit eigener
PHPUnit-Datenbank und Testdateibereich. Kein Spike-Deploy, kein produktives
Upgrade und keine produktive Datenmigration.

| Probe | Red (Exit 1) | Green (Exit 0) |
|---|---|---|
| Markierungsvarianten | 15 Tests, 4 Fehler (BOM, Leerzeile, EOF, Doppelpunkt) | 15 Tests, 16 Assertions |
| Alt-Cache/Upgrade via Dispatcher | 9 Tests, 4 Fehler: weiter `locked=false` | zusammen mit Parser/Upgrade: 27 Tests, 173 Assertions |
| Unbekannte Availability-Nutzdaten | 11 Tests, 1 Fehler: Sentinel und zusätzliche Felder sichtbar | 11 Tests, 15 Assertions |
| Review: unbekannter Typ mit `c: []` | 1 Test, 1 Fehler: Maskierung umgangen | im gemeinsamen Lauf grün |
| Review: mehrzeiliges `true` | 16 Tests, 1 Fehler: Markierung verloren | im gemeinsamen Lauf grün |
| Gemeinsame fokussierte Abnahme | — | 40 Tests, 245 Assertions |

Fokussierter Lauf: `vendor/bin/phpunit -c phpunit.xml --filter
'availability_privacy_test|availability_dispatcher_test|personal_data_test|privacy_marker_dispatcher_test|upgrade_test'`
im Plugin-Verzeichnis. Alle fokussierten Läufe meldeten außerdem 12 bestehende
PHPUnit-Deprecations; Exit 0 ist keine Aussage über deren Beseitigung.

## Dispatcher-Integration und HTTP-E2E

Dies sind **In-process-Integrationen über `dispatcher::handle()`**, einschließlich
OAuth-Authentifizierung, Tool-Aufruf und externer Rückgabevalidierung. Sie sind
**kein HTTP-E2E-Nachweis**: kein Netzwerk, Webserver-Routing oder echter WebDAV-Host.
WebDAV wird am vorhandenen Transport-Port durch den Testadapter ersetzt.

- Markierungsprobe je Randform: Datei direkt importieren, Liste aufrufen,
  den persistierten Cache mit dem alten `ismarked=0`-Ergebnis belegen (echter
  Cache-Schlüssel aus der Liste), die sichtbar falsche Entsperrung bestätigen,
  echten Upgrade-Einstieg ausführen, danach `locked=true` und `contextfilelocked`
  beim Lesen, Schreiben und Anhängen prüfen. Extern getrennt bei ausgeschaltetem
  Schalter sowie eingeschaltetem Schalter ohne Hostfreigabe ablehnen.
  `personenbezug: false` bleibt les- und schreibbar.
- Availability-Probe: unbekannte Stub-Bedingung mit Sentinels direkt in
  `course_modules.availability` und zusätzlich Abschnitts-Availability schreiben.
  Zwei echte Historienstände erfassen. Katalog (Abschnitt und Modul),
  Moduleinstellungen und beide Vergleichszustände über den Dispatcher prüfen:
  Sentinels fehlen, Typ und Maske bleiben sichtbar, fünf Kerntypen bleiben lesbar.
  Keine Auswertung durch `core_availability`, kein Stub-Plugin erforderlich.
  Der separate Dispatcher-Lauf bestand mit 1 Test und 56 Assertions (Exit 0).

## Review und Gates

`/implement` → `agent-implement/WORKFLOW.md` → `/code-review`: zwei rein lesende
Reviewachsen, ein Writer. Runde 1: Standards zwei HIGH-Befunde, Spec ein HIGH-
und ein MEDIUM-Befund (dieselben beiden Randlücken). Beide per Red/Green korrigiert.
N2-Hinweis zur Trackerreferenz im Upgrade-Kommentar ebenfalls korrigiert.
Runde 2: Standards 0, Spec 0 offene Befunde. Evidenzdatei außerhalb des Produktreviews.

Ein erster `npm test`-Lauf in der eingeschränkten Sandbox hing in Kindprozessen
und wurde gezielt abgebrochen; er zählt nicht als Abnahme. Im regulären Hostkontext
bestand die Node-Suite mit 222 Tests. Der erste `gate fast`-Lauf bestand mit
94 PHP-Tests/456 Assertions (1 bestehender Skip), Baseline-Ratsche ohne Verstöße;
Exit 1 ausschließlich wegen einer Regex-Zeile mit 133 Zeichen. Diese ist danach
mechanisch umgebrochen. Die ursprüngliche vollständige Statikprüfung hatte
sonst in allen Kategorien 0 Befunde, einschließlich PHPStan, Savepoints,
Mustache und ESLint. Der abschließende `gate fast`-Lauf nach der Formatkorrektur bestand mit Exit 0:
222 Node-Tests, 94 PHP-Tests/456 Assertions, 1 Skip, 12 PHPUnit-Deprecations,
Statik in allen fast-Kategorien 0 und Baseline-Ratsche 0 Verstöße.
Rohlog lokal: `/tmp/privacy-fast-final.log`. Commit-/Push-Hooks und der genaue
CI-Commit werden im PR protokolliert.

Verbindlich: `pre-commit` = fast; `pre-push` = matrix + fast; CI = volle Suite mit
Coverage/CRAP/Ratsche auf Moodle 5.1, volle Suite auf den beiden PR-Matrixecken
(Moodle 5.2/MariaDB/PHP 8.3 und Moodle 5.3/PostgreSQL/PHP 8.4), JS,
Release-Artefakt/Neuinstallation auf allen drei Moodle-Linien und `Gate (required)`.
Mutation ist gemäß ADR 0033 ein Trend nach dem Merge, kein PR-Gate.
Lokales `full` wurde nicht zusätzlich angefordert; die verbindliche Vollsuite
und Coverage-Abnahme erfolgen in CI. Keine Merge-, Release- oder Deploy-Freigabe.
