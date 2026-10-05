# #626 – Client-Abnahme, Zwischenstand 05.10.2026

## Stand und Grenze der Belege

Featurecommit `ea96c24c4cc337e3ace92ebb46f10bbd14b4de01` auf
`docs/626-client-acceptance` wurde vollständig per `--no-ff` in
`integration/release-2.1-issues` integriert (Merge
`cb5b21adb48d32fbf39bddb588895d98839fb47a`). Der Featureworker hatte keine
Live-Schreibabnahme durchgeführt; die Integration ergänzt die bereits vorliegenden
#585-Belege. Kein neuer Live-Deploy, Upgrade oder Snapshot.

Live getesteter Kandidat: `75f1308a3816ec626899e800062642d1165de5fa`,
Pluginversion 2026100402, Moodle 5.1.7+ (Build 20260928). Alle 391 Pluginquellen
auf Spike sind bytegleich; der Koordinator bestätigt unabhängig denselben Stand
in `/tmp/coursepilot-coordination-spike-source-comparison.json`
([versionierter Quellenabgleich](evidence/626-spike-source-comparison-2026-10-05.json)).
Der autorisierte #585-Testkurs ist **27, Abschnitt 4**. Die Integration von #626
ändert den Pluginbaum nicht. Die endgültige Integrations-SHA steht in den
Koordinationsdateien `results/585.json` und `results/626.json`.

Issue #626 einschließlich beider vollständigen Kommentare gelesen. Die Kommentare
vom 02.10. bestätigen eingebautes Moodle-/Claude-/Codex-Bildmaterial und zwei offene
ChatGPT-Platzhalter. In DE und EN stehen weiterhin jeweils dieselben zwei
ChatGPT-Platzhalter. Der Nutzer liefert echte Screenshots und Schreibtest-Belege.

T3-Prüfung: `preview_status`, `preview_open`, danach `preview_snapshot`.
Der erfolgreiche Snapshot am 05.10. zeigt die abgemeldete ChatGPT-Webseite mit
„Log in“. Kein Plus-Konto, keine Connector-Einrichtung und kein Schreibtest wurden
so bestätigt. Es wurde kein anderer Browser und kein erfundener Zugang benutzt.

## Ergänzte tatsächliche Belege und Oberflächengrenze

Der echte **Codex/T3**-MCP-Basisschreibtest vom 05.10. liegt vor:
Standardvorlagen für book/checklist/glossary exportiert, Buch 1854 mit Kapitel,
Checkliste 1855 mit zwei Punkten und leeres Glossar 1856 angelegt. Ablöse-Vorschau
für 1855 ließ die Modulliste unverändert; schreibendes Ablösen erzeugte 1857
sichtbar direkt hinter dem versteckten Vorgänger. Erneute Exporte bestätigen
Kapitel und Checklistenpunkte. Eingaben, Antworten, Kurs-/Aktivitätskennungen und
Vorher-/Nachher-Listen sind im [Codex-MCP-Beleg](evidence/585-codex-mcp-2026-10-05.json)
versioniert; Autorisierung und Freigabe stammen aus dem sichtbaren #585-Auftrag.

Dies ist ein tatsächlicher Codex/T3-Werkzeuglauf, **kein Codex-Desktop-Bild und
kein ChatGPT-Web-/Plus-Nachweis**. Die schon eingebauten Desktop-Bilder bestätigen
die Menüoberfläche, aber keinen Schreibtest. Die T3-Moodle-Vorschau verlangte einen
Login; die Exporte belegen Inhalte, keine visuelle Lehrkraftabnahme. Erweiterte
Glossar-/Galerieaktionen bleiben offen, da `add_glossary_entries` und der
`files`-Parameter im sichtbaren Clientvertrag fehlen. Lesen der drei
`activity-types/*.md` im verbundenen Lehrer-Kontext lieferte `INVALID_ARGUMENT`;
ihre tatsächliche Bereitstellung ist damit ebenfalls nicht bestätigt.

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

1. **Belegt:** Kandidat `75f1308a3816ec626899e800062642d1165de5fa` auf
   `https://spike.gruenwald.fun`, unabhängiger Quellenabgleich 391/391 am 05.10.2026,
   autorisierter Testkurs 27/Abschnitt 4 und erfolgreiche synthetische Codex/T3-Aktionen.
   Für die noch ausstehende ChatGPT-Probe diesen Kandidaten erneut read-only
   bestätigen; kein automatisches Deployment aus dieser Checkliste ableiten.
2. Zwei ChatGPT-Webbilder aus dem echten Plus-Konto: Plugins/eigener MCP-Server und
   Formular mit Spike-Endpunkt und OAuth. Falls das Konto andere Schritte verlangt,
   auch diesen Ablauf belegen; die Anleitung wird dann angepasst. Keine Namen,
   E-Mails, Zugangswerte oder fremden Gespräche im Bild.
3. Bereinigte Originalbilder für `claude-1.png` und `moodle-aenderungsverlauf.png`.
4. **Codex/T3-Basis belegt**, mit der oben genannten Oberflächengrenze. **Offen:**
   echter ChatGPT-Plus-Web-Schreibtest gegen denselben bestätigten Kandidaten,
   z. B. Seite `CP626 ChatGPT write check`, Inhalt `Client acceptance check.`.
   Vorher genau diese Aktion freigeben. Falls der Maintainer zusätzlich eine
   Codex-Desktop-/Web-Schreibprobe verlangt, diese gesondert belegen; der T3-Lauf
   ersetzt die fehlenden Oberflächenbilder nicht. Erweiterte Glossar-/Galerieaktionen
   bleiben getrennte offene Clientkriterien.
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

Finale Integrationsprüfung: Node 35/35 ohne Fehler oder Skips, Dokucheck und
`git diff --check` grün. Protokolle:
`/tmp/coursepilot-626-integration-node.log` und `.exit`,
`/tmp/coursepilot-626-integration-docs.log` und `.exit`.
Pluginbaum unverändert zu `75f1308`; daher kein schwerer nativer Neuaufbau.
Native Addon-/Coverage-Belege und ZIP-Prüfsumme stehen im
[#585-Gesamtabnahmebericht](585-gesamtabnahme-2026-10-05.md).
Technisch stabile Doku ist integriert; #626 bleibt wegen der offenen tatsächlichen
Client-/Screenshot-/Datenschutz-/Maintainerkriterien `requires-user` und offen.
