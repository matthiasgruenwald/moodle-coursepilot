# Main-Merge und nächste Beta: Abnahmeplan

Stand: 04.10.2026. Planung, keine Merge- oder Releasefreigabe.

## Ausgangslage

- `main`: Plugin `2026100102`, Release `2.0.0-beta`, Tag `v2.0.0-beta`.
- `dev`: `05fb815`, Plugin `2026100344`; Releasebezeichnung noch `2.0.0-beta`.
- 93 Commits auf `dev` zusätzlich zu `main`; keine offenen PRs.
- Neun offene Issues. Die lokale Änderung an `.claude/settings.json` und die
  Sicherung `.claude/settings.json.bak` gehören nicht zum Releaseumfang.
- Aktuelle [native CI](https://github.com/matthiasgruenwald/moodle-coursepilot/actions/runs/37213747803):
  alle fünf Checks grün, einschließlich Moodle 5.0/5.1 und frischer ZIP-Installation.
- Sicherheitsarbeit #631/#632–#648, Legacy-Entfernung #587 und englischer
  Nachzug #602/#604/#605/#649 sind abgeschlossen und auf `dev`.

## Versionsentscheidung

**Versionslinie beschlossen: `2.1.0` (Nutzerfreigabe 04.10.2026).**
`dev` enthält neue XML-Anlege-/Ablösefunktionen, keine reine Hotfix-Sammlung.
[ADR 0027](../adr/0027-versionslinien-branches-und-moodle-mindestversion.md) sieht neue
größere Funktionen erst ab 2.1 vor; 2.0.x erhält Hotfixes.

`2.0.1-beta` ist sinnvoll als eigener, gezielter Hotfix-Release auf Basis von
`main`: dort vorhandene Sicherheitsprobleme prüfen und die nötigen Fixes samt
Regressionstests zurückportieren. Der gesamte `dev`-Stand als 2.0.1 würde eine
bewusste Änderung von ADR 0027 erfordern. Sicherheitsfixes auf der veröffentlichten
Linie sollten nicht auf neue Komfortfunktionen warten.

Beta bleibt Beta; eine Stable-Freigabe ist eine zusätzliche Entscheidung.

## Beauftragte Umsetzung über sichtbare T3-Threads

Nutzerauftrag 04.10.2026: #585, #626, #603, #593 und #598/#599 jetzt angehen.
Keine normale AFK-Pipeline und kein Ponytail. Getrennte Worktrees aus `dev`,
sichtbare T3-Threads über denselben WS-RPC-Weg wie der Limit-Watcher; ein
Koordinationsthread prüft Ergebnisse und startet die Folgeaufträge.

1. #593 und #598/#599 parallel implementieren und isoliert prüfen.
2. Beide Ergebnisse auf einem eigenen Integrationszweig zusammenführen.
3. #603 darauf aufbauen: Glossar-Vorlage verweist auf das neue Werkzeug;
   Lightboxgallery-Wissen nur aus tatsächlich verifiziertem Stand übernehmen.
4. #626: Client-Schreibtests/Screenshots und Doku-Abnahme am integrierten Stand.
5. #585: abschließender Gesamt-Review und echte Claude-/Codex-MCP-Abnahme.

Harter Blocker: #599 braucht #598; beide werden im selben Auftrag bearbeitet.
#603 könnte ohne #593 umgesetzt werden; die gewählte Reihenfolge vermeidet
erneuten Vorlagen-Nachzug. #626 ist technisch unabhängig und kann vorbereitet
werden; die finale Abnahme und #585 beziehen sich auf den integrierten Stand.
Live-Zugänge, tatsächliche Client-Sitzungen und Testinstanz-Deployment sind
Umgebungsabhängigkeiten; fehlende Belege werden nicht durch simulierte
Ergebnisse ersetzt. Merge nach `main`, Tag und Marketplace sind nicht Teil
dieses Implementierungsauftrags.

## Pflicht vor vollständigem Merge

| Reihenfolge | Arbeit | Fertig, wenn |
|---|---|---|
| 1 | Beauftragte Features integrieren; danach 2.1-Metadaten und Support vorbereiten | CI prüft Moodle 5.1 und 5.2; 80%-Coverage bleibt erhalten und wird von der bisherigen 5.0-Zeile auf 5.1 übertragen; ZIP-Neuinstallation läuft auf der neuen Mindestversion; erst danach `requires = 2025100600`. Releasebezeichnung, Plugin-Version, READMEs und Doku sind konsistent. |
| 2 | Upgrade vom veröffentlichten Stand prüfen | Frische isolierte Moodle-5.1-Instanz mit Plugin aus `v2.0.0-beta`/`main`, bestehenden Verbindungen, Kontextablage, Tickets und Verlauf auf den Releasekandidaten aktualisieren; Login/Rotation/Widerruf, alte Ablageschlüssel und URLs, Privacy sowie Verlauf nachweisen. Eine Moodle-5.0-Installation muss vor dem Plugin 2.1 auf Moodle 5.1 aktualisiert werden. |
| 3 | Gesamt-Review für #585 und Release-Diff | `/code-review` gegen festen Ausgangspunkt: Spec 0026 ab `41f6003` plus gesamter Release-Diff gegen `main`; keine offenen blockierenden Befunde. Bereits erfolgtes Spec-0028-Review berücksichtigen. |
| 4 | Echter MCP-Praxistest #585 | Auf abgestimmtem Spike-Stand Claude und Codex: Vorlage exportieren, Buch/Checkliste/Glossar anlegen, Ablösen erst als Vorschau und dann schreibend; Moodle-Ergebnis und unveränderte Vorschau prüfen. Ergebnisse und geprüften Commit im Issue festhalten. |
| 5 | Doku-Abnahme #626 | Schreibende Aktion in ChatGPT Plus und Codex nachweisen. Claude-/Codex-/Moodle-Screenshots sind eingebaut; zwei ChatGPT-Platzhalter existieren noch in beiden Sprachfassungen. Ersetzen, personenbezogene Angaben entfernen, Aussagen zum getesteten Clientverhalten prüfen. |
| 6 | Finalen Kandidaten prüfen und dokumentieren | Pflicht-CI am endgültigen Commit, Release-ZIP, Versionskonsistenz, Prüfsumme und aktueller Abnahmebericht. Der historische Alpha-Bericht ist kein Nachweis für diesen Kandidaten. |

**Vorhandener Upgrade-Nachweis:** #648 prüft `dev` bei `afccf6c` /
Plugin `2026100201` → `2026100344`. Das ist wertvoll, deckt aber den direkten
Upgrade-Ausgangspunkt `main` / `2026100102` nicht ab.

## Offene Issues: Einordnung

Alle Links beziehen sich auf `matthiasgruenwald/moodle-coursepilot`.

| Issue | Tatsächlicher Rest | Einordnung |
|---|---|---|
| [#585](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/585) | MCP-Live-Abnahme Claude/Codex und Gesamt-Review. Die im Body noch offenen Entscheidungsfragen sind laut Kommentaren bereits geklärt. | Vor Merge abschließen |
| [#626](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/626) | Client-Schreibtests belegen; ChatGPT-Screenshots ergänzen. | Vor Veröffentlichung der vollständigen Doku abschließen |
| [#627](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/627) | Merge, Pages auf Actions stellen, feste URL prüfen, beide READMEs verlinken. | Vorbereitung vor Merge; Deploy-Abnahme danach |
| [#607](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/607) | Doku-Epic; deutsche und englische Seiten sind gebaut, wartet auf #626/#627. | Danach schließen |
| [#603](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/603) | Verifizierte Vorlagen bei Ortswahl ergänzen, niemals Lehrerdateien überschreiben. Kommentar enthält fertigen Agent-Brief. | Jetzt beauftragt, nach #593/#598 integrieren |
| [#593](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/593) | Glossare mit Einträgen füllen. Kommentar enthält fertigen Agent-Brief. | Jetzt beauftragt, parallel zu #598 |
| [#598](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/598) | Datei-Nachtrag aus Materialpfaden, Pilot Lightboxgallery. Kommentar enthält fertigen Agent-Brief. | Jetzt beauftragt, zusammen mit #599 |
| [#599](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/599) | Lightboxgallery mit Bildern und Bildunterschriften. | Gemeinsam mit #598 umsetzen, kein zweiter unabhängiger Auftrag |
| [#192](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/192) | Marketplace-Vorbereitung, Mirror und Einreichung. | Nach Releasefreigabe; kein Blocker für den Main-Merge |

## Merge und Veröffentlichung

1. Release-PR `dev` → `main` mit Abnahmebelegen und bekannten Grenzen vorbereiten.
2. Nach Freigabe und grünen Pflichtchecks mergen, Beta-Tag setzen und geprüftes
   Release-ZIP mit Prüfsumme veröffentlichen.
3. #627: Pages-Konfiguration und Deployment prüfen; die Pages-API lieferte am
   04.10.2026 HTTP 404, eine konfigurierte Veröffentlichung ist damit nicht
   nachgewiesen. Beide READMEs haben derzeit noch keinen Link zur Pages-Doku.
4. Deployment auf die Zielinstanzen abgestimmt durchführen; bei 2.1 nur Moodle
   ab 5.1. Vor Upgrade mit Schemaänderungen Snapshot/Rückweg gemäß Deploy-Doku.
5. #585/#626/#627/#607 anhand erfüllter Kriterien schließen, nicht nur wegen Merge.
6. #192: Der Mirror-Workflow auf `dev` baut bereits die native Linie und läuft
   bewusst nur manuell. Der Issue-Text „noch auf native Linie umstellen“ ist
   überholt. Tatsächlichen Mirrorstand, Metadaten und Zugang prüfen; danach
   Einreichung und gegebenenfalls automatische Auslösung entscheiden.

## Passender ask-matt-Ablauf

Jetzt: beauftragte Features in sichtbaren T3-Threads → `/code-review` → echte MCP-Praxistests → Release-PR.
Die bestehenden Folge-Issues haben Agent-Briefs; erneute Triage ist nicht nötig.
Für #603, #593 und #598/#599: testweise belegte Implementierung mit `/tdd`.
Kein neues Großvorhaben und keine neue Spezifikation nötig, um den vorhandenen
Stand abnahmefähig zu machen.
