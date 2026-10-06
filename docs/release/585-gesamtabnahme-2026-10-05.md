# #585 Gesamtabnahme 2.1-Integration (05.10.2026)

Stand: Integrationszweig `integration/release-2.1-issues`, geprüfter Kandidat `75f1308a3816ec626899e800062642d1165de5fa`. Technische Abnahme und echte Claude-/Codex-Basisläufe; die erweiterten Client- und menschlichen Kriterien bleiben offen. Fortschreibungen betreffen Dokumentation, synthetische Abnahmebelege und das unten dokumentierte CI-Runner-Pinning; der Pluginbaum bleibt unverändert.

## Umfang

Review ab `41f6003` (Standards und Spec, read-only) gegen Spec 0026 und die Garantien aus Spec 0028; Integration von #593, #598/#599, #603 und der technisch stabilen #626-Doku (#626 bleibt menschlich `requires-user`).

#626-Featurehistorie mit geprüftem Commit `ea96c24c4cc337e3ace92ebb46f10bbd14b4de01`
vollständig per `--no-ff` integriert, Merge `cb5b21adb48d32fbf39bddb588895d98839fb47a`.
DE/EN-Anleitung und Recherche sind übernommen; die
[#626-Abnahmecheckliste](626-client-abnahme-2026-10-05.md) grenzt den vorhandenen
Codex/T3-Lauf von weiterhin fehlenden ChatGPT-Plus-/Oberflächenbelegen ab.
Zwei ChatGPT-Bilder, Bereinigungen von `claude-1.png` und
`moodle-aenderungsverlauf.png` sowie Maintainerdurchsicht bleiben offen.
Der Koordinator hat den Quellenabgleich 391/391 zu `75f1308` unabhängig bestätigt.
Nach Integration bleiben Pluginbaum, Sicherheits-/Privacy-/Coverage-Gates unverändert;
Node 35/35 ohne Skips, Dokucheck und Diffprüfung sind am endgültigen
Dokumentationsstand grün. Das ZIP besteht die CRC-Prüfung; alle 390 ausgelieferten
Quelldateien und der Herkunftshinweis sind bytegleich mit dem Integrationsstand.
`lang/de` ist entsprechend dem unveränderten Buildvertrag ausgeschlossen.
Die Quellenprüfung auf Spike umfasst dagegen alle 391 Pluginquellen.
Testprotokolle: `/tmp/coursepilot-626-integration-node.log` und `.exit` (0),
`/tmp/coursepilot-626-integration-docs.log` und `.exit` (0).

Zusätzlicher read-only Abschlussreview beider Achsen: vollständige Release-Änderungsübersicht `origin/main...75f1308` (786 Dateien) und Commitfolge `41f6003..75f1308`. Relevante XML-, Datei-, Glossar-, History-, Katalog- und OAuth-Grenzen wurden vertieft geprüft; entfernte 1.x-Dateien und große Übersetzungsänderungen strukturell. Dies ist kein unabhängiges Zeilen-Audit aller 786 Dateien.

### Standards

Keine neuen harten Verstöße und keine zusätzlichen belastbaren Smell-Befunde. Der ungenaue Glossar-Docblock bleibt ein dokumentierter Kommentarrestpunkt. Die gemeinsame Katalog-Schreibprüfung entspricht dem genehmigten Modulschnitt.

### Spec

Keine zusätzlich belegte Implementierungsverletzung. Zwei offene Abnahmebereiche: erweiterte echte Client-/Darstellungsabnahme und separate Release-Metadaten-/Kompatibilitätsarbeit. `dry_run` prüft gemäß Spec 0026 keine Materialinhalte; das ist keine fehlende Implementierung. Die Vorabprüfung vor Restore erhält Spec 0028 F11.

Review-Zählung: Standards 0 neue harte Befunde; Spec 0 neue Implementierungsbefunde, 2 offene Abnahmebereiche.

## Behoben im Review

| Befund | Behebung |
|---|---|
| Datei-Nachtrag wurde erst nach dem Restore geprüft (Spec 0028 F11: abgelehnte Eingaben mutieren nichts) | `activity_file_supplement::validate()` läuft vor `activity_backup::restore`; Test rot (`course_module_deleted` nach Ablehnung), dann grün |
| Doppeltes `optional_param('confirmed')` in `history.php` | entfernt |
| `location_selection.min.js` und `.map` trugen deutsche Kommentare (ADR 0024) | per Moodle-Grunt aus der englischen Quelle neu gebaut (nur diese zwei Dateien ändern sich) |
| Specs 0026/0028 nannten alte Pfade und widersprachen dem Code | Pfade und Out-of-Scope nachgezogen |

## Testnachweis (finaler Stand, eigene isolierte Umgebung)

Letzter Produktionscode-Commit: `fb94a5cd3ee413cf1b7dcf372a0c44a609f21346`; der Pluginbaum ist im geprüften Kandidaten `75f1308` identisch. Die vollständige Kennung des abschließenden Integrationscommits wird in der Koordinationsdatei `results/585.json` gespeichert.

- Moodle 5.1.7+ (Build 20260928), PHP 8.4.25, MariaDB 11.4.12, reale Addons `mod_checklist` und `mod_lightboxgallery`.
- Volllauf: 1539 Tests, 130898 Assertions, Exit 0, 1 bestehender Skip (WebDAV-Quota); keine Lightboxgallery-Skips.
- Coverage (natives Component-Config, pcov): 82,73 % (11991/14495 Zeilen), alle 187 Produktionsdateien des Scopes enthalten; Gate 80 % unverändert.
- `npm test` 35/35, `docs-site-check.js` grün, Release-ZIP gebaut (`local_coursepilot-2.0.0-beta.zip`).

Die ursprünglichen Logs bleiben unter `/tmp/coursepilot-585/full.log` und `full.exit` (0), Clover/JUnit unter `/tmp/coursepilot-585/evidence/`. Am 05.10. erneut geprüft: Coverage-Gate 82,73 %, Scope exakt 187/187 Dateien, keine fehlenden oder fremden Dateien; Node 35/35 ohne Skips; Dokumentationsprüfung und `git diff --check` grün. Seit dem nativen Lauf wurde keine ausführbare Pluginlogik verändert; deshalb keine neue schwere Suite oder Initialisierung. Eigene Container `coursepilot-585-php` und `coursepilot-585-db` sind gestoppt, ihre temporäre Moodle-Kopie ist entfernt. Belege und DB-Daten bleiben erhalten; fremde Ressourcen wurden nicht verändert.

Das erneut gebaute ZIP besteht `unzip -t`. SHA-256: `ca35a1ca6845bf99b4c551f62022478568123066acb18a751b2e6a34d032ec0e`. Pfad: `dist/native-release/local_coursepilot-2.0.0-beta.zip`. Release-Nummer 2.1.0 ist vereinbart; das Setzen des Strings bleibt gemäß Koordinator-Übergabe im separaten Releaseplan, bei unveränderter Beta-Maturity.

## CI-Infrastrukturblocker und Runner-Pinning

Koordinatornachweis zu Run `37365835830` am Commit
`cdc398f6bf4b0fb4c1dae34c473a730389c17252`: Moodle 5.0 einschließlich
80%-Coverage-Gate, Moodle 5.1 und frische ZIP-Installation sind `SUCCESS`.
Native JS/AMD auf `ubuntu-latest` wurde nach 15 Minuten ohne Runner und ohne
ausgeführte Schritte `CANCELLED`. Checkrun `111950639878` meldet fehlende
Hosted-Runner-Zuteilung trotz mehrerer Versuche; gezieltes Wiederholen wurde
mit `cannot be rerun` abgewiesen. `Gate (required)` wartete ebenfalls auf
`ubuntu-latest`. Der Ursprungslauf ist **kein grüner Gesamt-CI-Nachweis**.

Reversibler Infrastrukturfix: ausschließlich `runs-on` von `js-native` und
`gate` auf `ubuntu-22.04` gepinnt, das die drei erfolgreichen Pflichtjobs
dieses Laufs bereits bediente. Das ist keine Garantie einer neuen Runner-Zuteilung.
Jobs, Schritte, Matrix, `needs`, `if: always()`, Ergebnisprüfung, Testumfang und
80%-Schwelle bleiben unverändert; kein `continue-on-error` oder abgeschalteter Test.
CLI-YAML-/Strukturprüfung vergleicht den gesamten Workflow mit `cdc398f` und
erlaubt exakt diese beiden Wertänderungen; diese Prüfung und AMD-Syntax sind grün.
Bestehende native Node-Checks (`node scripts/ci/run-js-tests.js`) bestehen mit
35/35 Tests, ohne Fehler oder Skips, Exit 0; Belege:
`/tmp/coursepilot-ci-runner-node.log` und `.exit`. Dokucheck und Diffprüfung sind
ebenfalls grün. Kein nativer LXC-Neuaufbau oder zusätzliche DB.

Gesamt-CI bleibt offen, bis der Koordinator seinen Branch gepusht und den neuen
Pflichtlauf am neuen Integrationscommit selbst geprüft hat. Dieser Worker
pusht nicht, erstellt keine PR und wiederholt den alten Lauf nicht.

## Echter Claude-MCP-Lauf (Spike, 05.10.2026)

Übernommener Nachfolger-Nachweis aus `75f1308`, in dieser Codex-Sitzung nicht wiederholt. Die genannten Aktivitäten und der sichtbare Nachfolger 1850 wurden über die echte MCP-Modulliste erneut vorgefunden.

Kandidat 2026100402 per `deploy-plugin-spike.sh` (nach DB-Snapshot) auf den Spike gebracht, Lauf über den claude.ai-Connector im Testkurs 27, Abschnitt 4:

- `export_default_activity` für book, checklist, glossary erfolgreich.
- `create_activity_from_xml` book (cmid 1847, Kapitel), checklist (1848, zwei Punkte), glossary (1849) erfolgreich, Round-Trip bestanden.
- Ablösen: `dry_run` (hidden_predecessors 1, nichts geschrieben), dann schreibend: 1850 sichtbar hinter 1848, 1848 versteckt.
- **06.10. nach Aktualisierung der Connector-Werkzeugliste (Claude):**
  - `add_glossary_entries` auf Glossar 1849: zwei Einträge angelegt (eine Kategorie neu), das Duplikat desselben Begriffs sauber abgelehnt (`errconceptalreadyexists`), `gap_notice` geliefert.
  - Lightboxgallery 1866 mit `files` aus dem Workbench-Bereich (drei Bilder, Unterschriften Rot/Gruen/Blau): Bilder (3), Thumbnails (3, 162x132) und Unterschriften serverseitig bestätigt, Aktivität versteckt wie verlangt, keine Reste im Kurs.
  - Auffälligkeit: Fehlt `<timemodified>` in der Galerie-XML, kommt die rohe PHP-Meldung `Undefined property: stdClass::$timemodified` zurück statt einer klaren Fehlermeldung; mit vollständiger Vorlage läuft der Aufruf. Kein Datenverlust, Folge-Ticket sinnvoll.
  - Werkzeugschemas im Claude-Connector zeigen `files` nicht an; der Server liefert es (55 Werkzeuge, `files` in `tools/list`). Der Aufruf mit `files` wurde dennoch angenommen.
- Codex-Basisschreibtest 05.10. (1854–1857) und Codex-Desktop 06.10. (1858), ChatGPT iOS (1859) sind aus #626 belegt (`docs/release/626-*`).

## Echter Codex-MCP-Lauf (T3, Spike, 05.10.2026)

Nach erneuter Anmeldung funktionierte der echte Coursepilot-Connector in dieser Codex-Sitzung. Keine HTTP-Testautomation als Ersatz. `get_version_info` meldet Moodle 5.1.7+ und Plugin 2026100402. Read-only Hashvergleich aller 391 Plugin-Dateien zwischen Kandidat und `/opt/plugins/local_coursepilot` auf Spike: keine fehlenden oder abweichenden Dateien. Kein erneutes Deployment oder Upgrade.

- Standardvorlagen für book, checklist und glossary exportiert; temporäre Standardaktivitäten 1851–1853 fehlen anschließend in der Modulliste.
- Testkurs 27, Abschnitt 4: Buch 1854 mit synthetischem Kapitel, Checkliste 1855 mit zwei synthetischen Punkten und leeres Glossar 1856 erstellt, zunächst versteckt. Alle Round-Trips bestanden; Moodle-Presets wurden als solche zurückgegeben.
- Ablöse-Vorschau für 1855: cmid 0, keine Verweise, kein vorhandener Nachfolger, ein versteckter Vorgänger. Modullisten unmittelbar davor/danach sind identisch.
- Schreibendes Ablösen: 1857 sichtbar direkt hinter 1855; Vorgänger versteckt, ursprünglicher Titel unverändert. Die übrigen Claude-Testaktivitäten blieben erhalten.
- Erneute echte Aktivitätsexporte von 1854–1856 bestätigen Kapiteltext, beide Checklistenpunkte und das leere Glossar. Das belegt den Moodle-Inhalt, keine visuelle Lehrkraftabnahme.

Nachvollziehbare synthetische Werkzeug-Eingaben und Antworten: [Codex-MCP-Beleg](evidence/585-codex-mcp-2026-10-05.json). Keine Tokens oder Zugangsdaten enthalten.

Grenzen: Die in dieser Sitzung sichtbare Werkzeugliste enthält weiterhin kein `add_glossary_entries` und keinen `files`-Parameter. Das erneute Anmelden aktualisierte die Autorisierung, nicht diesen Vertrag. Lesen der drei `activity-types/*.md` im Lehrer-Kontext lieferte `INVALID_ARGUMENT`; ihre tatsächliche Bereitstellung für diesen verbundenen Nutzer ist damit nicht bestätigt. Die T3-Vorschau des Buchs wurde zum Moodle-Login umgeleitet; visuelle Darstellung und Lehrkraftkontext bleiben offen. Kein neuer Claude- oder ChatGPT-Lauf in dieser Codex-Sitzung.

## Offen (nicht als erledigt markiert)

- Claude- und Codex-Basisläufe für Export/Anlegen/Ablösen erledigt (Beleggrenzen siehe oben). Offen: Glossar-Einträge und Lightboxgallery-Dateien über echte Clients mit aktualisiertem Werkzeugvertrag; ChatGPT-Lauf samt Screenshots, Darstellung im Spike durch eine Lehrkraft und Vorlagen im tatsächlichen Lehrer-Kontext.
- Verifizierte Ablage `activity-types/lightboxgallery.md` (#599) hängt an der Spike-Abnahme.
- Release-String ist `2.0.0-beta`; die vereinbarte Nummer 2.1.0 ist nicht gesetzt (separate Release-Arbeit; Maturity bleibt Beta).
- Supportmatrix/Mindestversion und Upgrade vom veröffentlichten `main` bleiben separate Releasearbeit.
- Konkreter Live-Deployplan nur mit ausdrücklicher Freigabe.
- #626: echter ChatGPT-Plus-Schreibtest, zwei ChatGPT-Webbilder, zwei Datenschutzbereinigungen und abschließende Maintainerdurchsicht von DE/EN fehlen weiterhin. Codex/T3 ist kein Nachweis der Desktop-/ChatGPT-Weboberfläche.
- Review-Restpunkte (Beobachtung, kein Blocker): `glossary_entry_writer`-Docblock „no existing-entry reads“ ungenau (`glossary_concept_exists` ist Moodle-Core-Duplikatprüfung); Dateien über 800 Zeilen (`oauth_lib.php`, `catalog/assign.php`, `import_questions_xml.php`) als eigenes Refactoring.
- Korrigierte frühere Beobachtungen: Ausstandsnotiz bei fehlgeschlagenem Template-PUT ist durch `activity_type_templates_test::test_external_write_outage_retains_new_selection_and_pending_notes` abgedeckt. `supplement_missing` erhält die normale Fehlerpolitik. `gallery_images` ist gerade nicht durch `history/file_policy.php` freigegeben; Galerie-Dateien werden über diesen Verlauf nicht wiederhergestellt. Materialinhaltsprüfung gehört ausdrücklich nicht zur Ablöse-Vorschau.
