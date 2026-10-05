# #626 – Client-Abnahme, Zwischenstand 05.10.2026

## Stand und Grenze der Belege

Geprüfte integrierte Repo-Basis: `7e9f0650b1425f154f4587300a3ccd9609bb7d15`
auf `docs/626-client-acceptance`. Das ist **kein Nachweis des installierten
Spike-Plugincommits**. Exakter live getesteter Plugincommit: **noch nicht belegt**.
Kein Deployment, Live-Upgrade oder schreibender MCP-Aufruf wurde durchgeführt.

Issue #626 einschließlich beider vollständigen Kommentare gelesen. Die Kommentare
vom 02.10. bestätigen eingebautes Moodle-/Claude-/Codex-Bildmaterial und zwei offene
ChatGPT-Platzhalter. In DE und EN stehen weiterhin jeweils dieselben zwei
ChatGPT-Platzhalter. Der Nutzer liefert echte Screenshots und Schreibtest-Belege.

T3-Prüfung: `preview_status`, `preview_open`, danach `preview_snapshot`.
Der erfolgreiche Snapshot am 05.10. zeigt die abgemeldete ChatGPT-Webseite mit
„Log in“. Kein Plus-Konto, keine Connector-Einrichtung und kein Schreibtest wurden
so bestätigt. Es wurde kein anderer Browser und kein erfundener Zugang benutzt.

## Doku- und Quellenprüfung

Beide Lehrkraft-Anleitungen sind inhaltlich parallel korrigiert:

- Die vollständig abgerufenen aktuellen OpenAI-Artikel beschreiben Plugins → Plus →
  eigener MCP-Server, OAuth/Risikohinweis, Installation und Auswahl über `@` im Chat.
  Suchvorschauen zeigten noch ältere Developer-Mode-Schritte. Die tatsächliche
  Plus-Oberfläche muss der Maintainer weiterhin bestätigen.
- Ein Schreibfehler wird nicht mehr pauschal mit OpenAI-Tarifrechten erklärt.
  Werkzeugfreigabe, Moodle-Rechte und konkrete Fehlermeldung sind ebenfalls relevant.
- Die pauschale Codex-Tarifzusage ist entfernt. Die Bilder zeigen die Desktop-App;
  CLI-/IDE-Konfiguration ist nach der aktuellen MCP-Doku geteilt.
- Die Recherchedatei hält die Nachprüfung fest. Keine Pluginverträge, Strings,
  Sicherheits-, Privacy- oder Coverage-Gates sind verändert.

Primärquellen, vollständig abgerufen am 05.10.2026:
[OpenAI Quickstart](https://developers.openai.com/plugins/build/app-quickstart),
[OpenAI Connect and test](https://developers.openai.com/plugins/deploy/connect-chatgpt),
[OpenAI MCP](https://learn.chatgpt.com/docs/extend/mcp),
[Claude Remote MCP](https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp).
Der alte Developer-Mode-Link liefert 404. Dokumentation ist kein Live-Beleg.

## Sichtprüfung aller 18 vorhandenen PNGs

| Bilder | Befund |
|---|---|
| `claude-1.png` | Vorname und Initiale im Kontomenü sichtbar; anonymisierte Originalaufnahme nötig. |
| `claude-2.png` bis `claude-4.png` | Schritte passen zur Anleitung; URL in 3/4 ist `moo.gruenwald.fun`, kein Spike-Beleg. Keine sichtbaren Zugangswerte. |
| `codex-1.png` bis `codex-4.png` | Desktop-Menüs passen; URL in 3 ist `moo.gruenwald.fun`. In 4 ist der Anzeigename `coursepilott` abweichend. Kein Beleg eines Schreibaufrufs. |
| `moodle-aenderungsverlauf.png` | Vollständiger Personenname in Nutzer- und Änderungszellen mehrfach sichtbar; anonymisierte Originalaufnahme oder neue Aufnahme mit Testkonto nötig. |
| Übrige neun Moodle-Bilder, einschließlich aller EN-Bilder | Keine sichtbaren Passwörter, Tokens, E-Mails oder echten Personennamen erkannt. WebDAV-Nutzerwerte sind Beispieltexte. Englischer Verlauf zeigt ein Testkonto. |

E-Mail-Schwärzung allein erfüllt das Datenschutzkriterium nicht. Bilder bleiben
unverändert, bis bereinigte echte Aufnahmen vorliegen; keine synthetischen Ersatzbilder.
Öffentliche Hostnamen sind Einrichtungskontext, aber keine Commit- oder Schreibbelege.

## Für die abschließende Abnahme vorzubereiten

1. Koordinator bestätigt den auf `https://spike.gruenwald.fun` installierten Kandidaten:
   vollständige Commit-SHA und Abgleich der installierten Pluginquelle, Datum/Uhrzeit,
   Testkurs-ID sowie ein berechtigtes Testkonto ohne Lernendendaten. Bei fehlendem
   Kandidaten koordiniertes Deployment anfordern; dieser Worker deployt nicht.
2. Zwei ChatGPT-Webbilder aus dem echten Plus-Konto: Plugins/eigener MCP-Server und
   Formular mit Spike-Endpunkt und OAuth. Falls das Konto andere Schritte verlangt,
   auch diesen Ablauf belegen; die Anleitung wird dann angepasst. Keine Namen,
   E-Mails, Zugangswerte oder fremden Gespräche im Bild.
3. Bereinigte Originalbilder für `claude-1.png` und `moodle-aenderungsverlauf.png`.
4. Je ein echter Schreibtest aus ChatGPT Plus Web und Codex gegen **denselben**
   bestätigten Spike-Commit: eine neue Seite im erlaubten Testkurs anlegen, z. B.
   `CP626 ChatGPT write check` bzw. `CP626 Codex write check`, Inhalt
   `Client acceptance check.`. Nur diese Testaktivitäten ausdrücklich freigeben.
5. Je Client Belegpaket: Client/Oberfläche/Version bzw. Datum und Plus-Tarif,
   Endpunkt, Plugincommit, Kurs-ID, Prompt und Freigabe, tatsächlicher Toolname mit
   Argumenten und Ergebnis oder Fehler, neue Aktivitäts-ID/URL sowie sichtbarer
   Moodle-Zustand mit Titel/Inhalt. Anmeldung, Lesen oder eine Erfolgsaussage im
   Chat allein genügen nicht. Während beider Tests Kandidatenstand unverändert halten.
6. Agent prüft Belege, baut echte Bilder in DE/EN ein, dokumentiert den getesteten
   Plugincommit und das Ergebnis. Maintainer liest beide Fassungen gegen.
   Ergebnis fürs Issue erst nach vollständiger Abnahme koordinieren; aktuell keine
   Issue-Schließung und kein globaler Statuskommentar.

## Technische Prüfung

Nur Dokumentationsänderungen, keine Feature-Implementierung und keine neuen Tests.
Bestehende Prüfnähte aus Spec 0027 bleiben unverändert.
`node scripts/docs-site-check.js` besteht; `npm test` besteht mit 35 Tests,
0 Fehlern und 0 Skips (Log: `/tmp/coursepilot-626-node-final.log`).
Der erste Sandbox-Lauf scheiterte an `spawnSync /usr/bin/node EPERM`,
der freigegebene Wiederholungslauf hat Exit 0. `git diff --check` ist sauber. PHPUnit/Coverage/Init sind für diesen Dokuschnitt nicht nötig;
es wurden keine schweren Läufe oder Testcontainer gestartet.
