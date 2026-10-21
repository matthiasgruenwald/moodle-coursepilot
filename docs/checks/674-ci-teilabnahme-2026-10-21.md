# #674 – CI-Teilabnahme vom 21.10.2026

Der **Teilmeilenstein** auf `a3b56dc5d316d16a3840ab6ebeee90975718faad`
ist lokal und in CI abgenommen. **Keine Gesamtabnahme von #674:** Von 4.704
eindeutigen Originalidentitäten sind 2.084 durch fachliche Assertions getötet,
61 einzeln begründet ignoriert und **2.559 weiter offen**. Die 4.748
Originalvorkommen einschließlich OAuth-Duplikaten bleiben erhalten:
2.124 getötet, 61 ignoriert, 2.563 offen. Error-only-Fälle zählen weiterhin
nicht als Assertion-Kills. Quelle: `674/progress.json`, Snapshot vom
21.10.2026, 05:09:07 UTC. Artefaktpfade hier relativ zu
`/var/lib/afk-pipeline/`.

## Identität und Pflichtjobs

- PR [#717](https://github.com/matthiasgruenwald/moodle-coursepilot/pull/717)
  ist offen, Ziel `dev`, Quellbranch `integration/spec-0029-followup`.
- [CI 39534231147](https://github.com/matthiasgruenwald/moodle-coursepilot/actions/runs/39534231147),
  Versuch 1, Ereignis `pull_request`, exakt obiger Head: **11/11 Jobs erfolgreich**.
- Tatsächlich ausgecheckter Test-Merge:
  `c7cf4988246dfe111038ab845dde5323d7ad3dc3`. Seine Eltern sind exakt
  `f909fc4681a74bc437299c7db69bc64bdc69ccdb` (`dev`) und obiger PR-Head.
  `git-context.log` bestätigt `origin/dev`, vollständige Historie und denselben
  Merge-Base. Kein Merge im Worker ausgeführt.
- Full-Gate, fünf PHPUnit-Matrixjobs (Moodle 5.1–5.3, MariaDB/PostgreSQL),
  JS/AMD, drei frische Release-Installationen und `Gate (required)` sind grün.

## Originalarchive und Inhaltsprüfung

Alle vier Original-Upload-ZIPs per Artefakt-API heruntergeladen: SHA-256
und Bytezahl stimmen mit den API-Metadaten überein; ZIP-Integrität geprüft.

| Artefakt-ID | Inhalt | Upload-SHA-256 |
|---|---|---|
| 12322783925 | Gate-Trend, Moodle 5.1 | `ffbb2dd672b744042e23dbd2e36315a4326d6cf49370447b7ba478502845be14` |
| 12316662557 | Release, Moodle 5.1 | `e50b43479564f5ab09850af3a6ad81a5774559a3ecc2e358cbea70d50e7aa7f4` |
| 12316957051 | Release, Moodle 5.2 | `e50b43479564f5ab09850af3a6ad81a5774559a3ecc2e358cbea70d50e7aa7f4` |
| 12316812079 | Release, Moodle 5.3 | `19db9221afd342eb674c9a8ea51427e165226d81bebc746bf3f970eb5c84f2fc` |

Die enthaltenen Plugin-ZIPs stimmen jeweils mit der **vor Installation**
protokollierten Job-Prüfsumme überein: 5.1/5.2
`6ef14ed1fc40932e0f25b19eb0aa1a50a5a37ccfea01a49d07f279fb4e3e285e`,
5.3 `52be15434bb90b80f5cbd61ced2cb2088d88c52e2d3c411a485150781838e900`.
Alle 523 Dateien je ZIP stimmen bytegenau mit dem Checkout überein;
`NOTICE` stammt gemäß Buildskript aus dem Repository-Root. Die Dateimengen
sind vollständig und identisch; abweichende ZIP-Metadaten ändern keinen Inhalt.
Alle drei Installationsjobs bestätigen Registrierung, Discovery und
autorisierten OAuth-MCP-Aufruf, einschließlich `tools/list` mit 56 Werkzeugen.
Keine Veröffentlichung oder Installation außerhalb der vorhandenen CI.

## Gate-Nachweise und Grenzen

CI: 2.218 Tests, 133.320 Assertions, 15 Skips; Coverage **98,42 %**
(13.806/14.028), unveränderte Baseline 86,26 %, Ratsche **0 Verstöße**,
gesamte Statik **0**. Diff-Infection: 1.512 Mutanten, 135 überlebt,
0 Fehler, **0 Gateverstöße**, 8.679,674 Sekunden. `full.exit` und
`mutation-proof.exit` jeweils 0; echte Fehlerpfadprüfung bestätigt
Überlebender=rot, unbegründeter Ignore=rot, starke Assertion=grün.
Alle 72 CI-Quellbindungen passen zum Checkout. Dies ist kein neuer
Abschnitts-/Gesamt-MSI-Trend und keine Erledigung der offenen Originalfälle.

Normaler lokaler Pre-Push und Push waren bereits erfolgreich
(`674/attribution-push.exit` = 0); alle acht lokalen Originalarchive erneut
auf Archivhash, entpackten Hash und Größe geprüft. Kein Volltest wiederholt.
Frühere scoped Review-PASS und ihre Grenzen bleiben im
[Fortschrittsbericht](674-mutationsfortschritt-2026-10-20.md) erhalten.

Prüfmanifest, Live-PR/Run/Merge-Metadaten, Original-Upload-ZIPs, entpackte
Gate-Belege und Release-Joblogs: `674/ci-acceptance-a3b56dc/`;
Ergebnis `acceptance.json`. Lokale Belege:
`674/full-gate-a3b56dc-20261021/manifest.json`. Der Parent besitzt weiterhin
den CI-Watch. Issue bleibt offen; vollständige Restbearbeitung und
Abschnittsnachmessungen einschließlich der bekannten `root/lib.php`-Lücke
stehen aus.
