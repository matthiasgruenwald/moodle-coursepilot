# Mutationsarbeit #674: Zwischenstand vom 20.10.2026

Fortsetzung: [CI-Teilabnahme vom 21.10.](674-ci-teilabnahme-2026-10-21.md)
und [Shared-Block-Charge](674-shared-block-2026-10-21.md). Die folgenden
Zähler bleiben historische Snapshots.

**Kein Abschlussbericht.** Die Kriterien von #674 (alle Ausgangsmutanten erledigt,
MSI-Trend fortgeschrieben, Ratsche grün) sind noch nicht vollständig nachgewiesen.
Keine Tests gelöscht oder abgeschwächt, keine Schwelle oder Baseline geändert.

**Zählkorrektur nach R9:** Frühere Registerzahlen unten sind historische, inzwischen
ersetzte Zwischenstände. Die erste Auswertung unterschied benannte PHPUnit-Fehler
noch nicht von fehlgeschlagenen Assertions. Maßgeblich ist der letzte Abschnitt
„Strikte Kill-Zuordnung“; Infection-MSI-Werte werden dadurch nicht umgeschrieben.

## Nachgemessene erste Chargen

Ausgangsmessung: `4161c4fbab40cf4dcb67bf319a02ac07f9de43f3`, siehe
[Bericht #673](673-mutationsvolllauf-2026-10-19.md) einschließlich seiner Grenzen.
Neue Messungen: Plugin-Stand `11a807c540dff9e40bbafaaf681b65066cd23c82`,
Plugin-Baumdigest `eff706e3063bf4eff5615f3bc24816d271cf38354494d0ba26c7c6360522f07b`.
Die geänderte Ignore-Liste liegt außerhalb dieses Plugin-Baums; ihre Quellbindungen
wurden anschließend als `93c45a6` committed.

| Datei unter `classes/` | Mutanten | MSI vorher → nachher | Getötet nachher | Überlebt | Ungedeckt | Sekunden |
|---|---:|---:|---:|---:|---:|---:|
| `access_log.php` | 29 | 93,10 → 96,55 % | 28 | 1 | 0 | 152,469 |
| `oauth_lib.php` | 135 | 67,41 → 100,00 % | 135* | 0 | 0 | 261,405 |
| `privacy/provider.php` | 209 | 60,29 → 96,17 % | 201 | 8 | 0 | 378,048 |
| `catalog/assign.php` | 706 | 17,28 → 91,64 % | 647 | 59 | 0 | 889,229 |

MSI ist jeweils der von Infection gemessene Trendwert, keine Schwelle. Diese
Dateimessungen ergeben noch keinen neuen Gesamt-MSI und ersetzen nicht den
abschließenden Abschnittslauf mit frischer vollständiger Coverage.

\* Ein OAuth-Mutant (`ArrayItemRemoval`, Zeile 100,
Hash `9398401948f24d0f97a3bcc3a37ea0b7ef6c3e948e95bd81b52fe56288556917`)
wurde nur wegen einer PHP-Warnung erkannt. Das Register zählt ihn deshalb nicht
als nachgewiesenen Assertion-Kill. Er gehört nicht zu den ursprünglich offenen
OAuth-Identitäten; von den 44 ursprünglich offenen eindeutigen OAuth-Identitäten
zeigen 40 eine fehlgeschlagene Assertion; vier reine Fehlerkills bleiben offen.

## Zählkorrekturen

Die Originalreports enthalten OAuth zweimal: `classes-03` und `root` führen
dieselben 135 Mutanten von `classes/oauth_lib.php`. Darunter sind 43 offene
Überlebende und ein ungedeckter Mutant. Der Report `root.json.gz` enthält keine
Mutanten der eigentlich geplanten Datei `lib.php`. Diese Lücke bleibt bei einer
späteren Gesamtmessung zu berücksichtigen.

Die unveränderte ursprüngliche Arbeitsliste umfasst damit **4.748 Fälle**
(4.591 offene Überlebende plus 157 ungedeckte Fälle), aber **4.704 eindeutige
Identitäten**. Das Fortschrittsregister erhält beide Zählweisen; die
Originalberichte werden nicht still umgerechnet. Identität bedeutet Datei,
Zeile, Mutator und SHA-256 des vollständigen Quellpaars, nicht nur eine gekürzte
Änderungsbeschreibung.

Nach den vier Tabellenmessungen sind 645 eindeutige Ausgangsidentitäten vorläufig als getötet
registriert (vor der unten beschriebenen strikten Fehlerklassifizierung), sieben einzelne Privacy-Äquivalenzen geprüft,
ein Access-Log-Ignore hat noch eine offene Messbindung, 4.051 Identitäten
bleiben offen. Das sind Teilfortschritte, keine pauschale Freigabe der Dateien.

## Messbelege und Verfahren

Persistente Originale unter `/var/lib/afk-pipeline/674/verified-focus/`:

- `classes_access_log_php-2026-10-20T14-26-03-547Z.json` und `.json.gz`
- `classes_oauth_lib_php-2026-10-20T14-26-55-923Z.json` und `.json.gz`
- `classes_privacy_provider_php-2026-10-20T14-32-23-055Z.json` und `.json.gz`
- `classes_catalog_assign_php-2026-10-20T14-42-07-518Z.json` und `.json.gz`

JSON enthält SHA, Baumdigest, Quellbindungen, Testauswahl, Laufzeit und genaue
Mutantenidentitäten; Gzip enthält den unveränderten Infection-Originalreport.
Logs und Exitdateien heißen `access-verified`, `oauth-verified`,
`privacy-verified` und `assign-verified` unter demselben Arbeitsverzeichnis.
Alle vier Exitcodes sind 0. PHPUnit/Infection liefen ausschließlich im
Gate-Container, mit Lastschutz, einem Thread und nur abdeckenden Testfällen.
OAuth und Assign nutzten den privaten Mount von `i4`, Access-Log und Privacy
seriell den Haupt-Gate-Mount. Der Mountdigest wurde nach jedem Lauf geprüft.

`progress.json` und `mutants.json` werden durch `reconcile.js` aus exakten
Originalidentitäten und belegten neuen Läufen aufgebaut. Frühere Fokusläufe
ohne festgehaltenen SHA/Baumdigest zählen nicht als finale Kill-Evidenz.
Zeitüberschreitungen und Kills ohne zugeordneten Testfehler bleiben gesondert.

## Reviews und verbleibende Abnahme

Die Ignore-Charge in `93c45a6` erhielt zwei frische unabhängige Read-only-Reviews
(Standards und Spec), beide mit begrenztem PASS. Codex `gpt-6.1-sol`, Reasoning
`medium`, ersetzte auf ausdrückliche Nutzer-/Parent-Freigabe Sonnet 5.5 nach
Claude-Limitfehlern. Eine erste Codex-Runde scheiterte ebenfalls am Nutzungslimit.
Keine fehlgeschlagene Runde wird als PASS gewertet. Details und Task-Identitäten:
`/var/lib/afk-pipeline/674/ignore-reviews.json`.

Das ursprüngliche Access-Log-Messmanifest enthält noch keinen Hash von
`classes/admin/settings.php`; der neue Ignore wird damit noch nicht aktiviert.
Eine vollständige Messbindung und das abschließende Gate bleiben erforderlich.
Der reguläre pre-commit-Hook für `93c45a6` war grün; das ist kein Nachweis für
`gate full`, Push oder CI. Die übrigen Test-Chargen und alle weiteren
Äquivalenzentscheidungen brauchen noch eigene Reviews und Messbelege.

## Fortsetzung am Abend

Nach weiteren gezielten Läufen: **1.596 vorläufig als getötet registrierte eindeutige Ausgangsidentitäten,
56 einzelne Äquivalenzen geprüft, 3.052 offen**
(historischer Messstand vor der unten beschriebenen Drift-Umstellung).
Nach Originalvorkommen: 1.640 / 56 / 3.052; die Differenz sind die 44 doppelten
OAuth-Identitäten. Fehlerhafte oder unvollständige Läufe zählen nicht als Kill.

| Zusätzliche Messung | Mutanten | Getötet | Überlebt | Ungedeckt | Sekunden |
|---|---:|---:|---:|---:|---:|
| Quiz: Katalogdefinition und Zustand | 515 | 440 | 24 | 51 | 555,567 |
| Forum: Katalogdefinition und Zustand | 225 | 213 | 2 | 10 | 277,404 |
| Choice: Katalogdefinition und Zustand | 216 | 214 | 2 | 0 | 263,507 |
| Assign: ausschließlich Zustand | 706 | 71 | 47 (46 geprüft ignoriert) | 588 | 358,812 |
| Externe Funktionen: ausschließlich veröffentlichte Signaturen | 2.853 | 472 | 0 | 2.381 | 541,093 |

Diese unterschiedlichen Testauswahlen sind **keine neuen vollständigen Datei-
oder Gesamt-MSI-Werte**. Ein zuvor nachgewiesener Kill verschwindet nicht,
weil ein zusätzlicher enger Testlauf seine Zeile nicht auswählt. Das Register
erhält die einzelnen Belege statt MSI-Werte zu mitteln. Die ungedeckten Fälle
der reinen Signaturprüfung bleiben für Verhaltenstests offen.

Privacy wurde mit dem Kontext-ID-Kollisionsfall erneut gemessen: 209 Mutanten,
202 Infection-Kills (168 mit Assertion-Fehlschlag, 34 reine Fehlerkills),
sieben geprüfte Äquivalenzen, 376,064 s. Den verbleibenden
`InstanceOf_`-Mutanten in Zeile 480 tötet
`provider_stores_test::test_deleting_user_data_preserves_history_with_a_matching_numeric_module_id`.
Der Assign-Nullwert-Test tötet ausdrücklich den im Review zurückgewiesenen
`CastString`-Mutanten in Zeile 520; er steht nicht in der Ignore-Liste.
Nach kombinierten Assign-Belegen: 535 ursprüngliche Identitäten getötet,
48 äquivalent und eine offen.

Access-Log wurde wegen der fehlenden Manifestbindung erneut gemessen:
29 Mutanten, 28 Assertion-Kills, ein korrekt aktivierter geprüfter Ignore,
146,753 s. Die Bindung von `admin/settings.php` ist damit erledigt.
Alle genannten erfolgreichen Läufe haben Exit 0. Unveränderliche Rohreports
und genaue SHA-/Digest-/Testauswahl stehen unter `verified-focus/`; Dateien
mit Zeitstempel sind die Belege, `*.latest.json` zeigt jeweils den letzten Lauf.

### Quellarchive und Review-Korrekturen

Unter `/var/lib/afk-pipeline/674/source-snapshots/` sind vollständige Plugin-
Quellarchive einschließlich Tests und Fixtures gesichert. Drei ältere
Arbeitsstände wurden aus `93c45a6` und den additiven Teständerungen rekonstruiert;
ihre **vollständigen Baumdigests stimmen exakt mit den vor dem jeweiligen Lauf
festgehaltenen Digests überein**. Alle elf bis dahin erfolgreichen Läufe sind
einem Archiv zugeordnet. Sidecars dokumentieren Rekonstruktion, Basis-SHA,
zugehörige Läufe und Archiv-SHA-256; originale Run-Reports bleiben unverändert.
Neue Läufe archivieren den Quellstand direkt vor dem Start. Historische
Arbeitsstände mit noch nicht committeten Tests werden ausdrücklich von einem
späteren Commitstand unterschieden.

Zwei frische Gesamt-Reviews der Teiländerungen fanden anschließend zwei
Blocker; diese Runden zählen nicht als PASS:

- Der Ressourcen-Test eines Backups brauchte ein fachliches Ergebnis. Er
  behält die Dateideskriptor-Assertion und stellt nun **dasselbe Backup** wieder
  her; Name und vollständiger Seiteninhalt werden geprüft.
- Die neuen Drift-Tests verwendeten einen Katalog als Double eines verwalteten
  Moduls. Alle sieben Testmethoden und ihre fachlichen Assertions bleiben
  erhalten; die simulierte Katalogklasse wurde entfernt. Der produktive
  `check_catalog()` löst echte Definitionsdaten auf und verwendet dieselbe
  Datenvalidierung `check_definition()` wie die Tests. Die Tests geben normale
  Feld-, Konstanten- und Optionsdaten ein; Datenbank und Moodle-Symbole bleiben real.

Die gezielte Prüfung bestand mit 33 Tests / 56 Assertions. Das reguläre schnelle
Gate bestand mit 91 Tests / 223 Assertions, Node-Vertragstests, null Statikbefunden
und unveränderter Baseline-Ratsche. Belege: `review-fixes-fast.log` und `.exit` (0).
R7 bestätigte beide Korrekturen. Spec: scoped PASS; Standards: FAIL wegen
unbegründeter historischer Blocklisten-Äquivalenz (`ca8c6132…`). Gemeinsame und
Assign-Blockliste enthalten beide `completionsubmit`; ab Moodle 5.2 folgen weitere
Markerfelder, sodass Deduplizierung Lücken in den Schlüsseln hinterlassen kann.
Der Ignore wurde entfernt. Ein zusätzlicher öffentlicher Katalogtest für den
5.2-Zweig prüft fortlaufende Schlüssel sowie Completion- und Markerfelder.
Die Beschreibungen der beiden entfernten Merge-Arme in den History-Ignores
`0760a0a0…` und `f95efa16…` wurden entsprechend dem tatsächlichen Mutanten
korrigiert. Fünf verbleibende historische Ignores binden den neuen Drift-Digest.

Erste Drift-Nachmessung: 136 Mutanten, 128 Infection-Kills, acht Überlebende,
keine ungedeckten Mutanten, Exit 0 (`drift-data-verified.out/.exit`). Drei weitere
Grenzfälle werden über die öffentliche Definitionsprüfung abgesichert:
fehlerhafte statische Callable-Namen mit mehreren Trennern, gleich benannte
Optionsschlüssel und lückenlose Meldungslisten nach dem Entfernen bekannter
Felder. Fünf andere Überlebende haben individuelle Äquivalenzkandidaten:
Schlüssel vor `sort()`, nur durch Leerheit/`implode()` beobachtete Schlüssel
(zwei Stellen), bereits vom Moodle-Bootstrap definierte Gruppenkonstanten und
`array_merge()` ohne Argument gegenüber einem leeren Argument unter PHP 8.
Diese neuen Kandidaten benötigen ausdrücklich frische Einzelprüfung in R8;
R7 oder frühere PASS-Ergebnisse gelten dafür nicht. Der fokussierte Lauf
`drift-edge-verified` misst diese neuen Assertions und Quellbindungen.
Die gezielte Prüfung der letzten Teständerungen bestand mit acht Tests und
22 Assertions; Gesamt-Gate und neue Mutationsergebnisse bleiben ausstehend.

Die Umstellung verändert die vollständigen Quellpaarhashes der Drift-Datei.
Ihre 54 ursprünglichen offenen Identitäten bleiben bis zu ausdrücklicher
Zuordnung und Messung offen. Ein neuer Quellstand beweist nicht automatisch,
dass alte Mutanten getötet sind. Historische Kill-Belege anderer Dateien
behalten SHA und Baumdigest; ihr mutierter Quelltext muss weiterhin exakt
passen. Das abschließende vollständige Gate prüft die Integration der
geänderten Produktionsabhängigkeiten.


## Fortsetzung am 21.10.2026

R8 scheiterte zunächst auf beiden Achsen am Provider-Nutzungslimit. Nach der
regulären Limit-Fortsetzung lieferten **dieselben beiden unabhängigen Reviewer**
jeweils scoped PASS für den partiellen Milestone. Fehlgeschlagene Versuche
wurden nicht als PASS gewertet. Beide prüften die fünf Drift-Äquivalenzen einzeln;
insgesamt sind 61 neue exakte Identitäten mit 117 Quellbindungen bestätigt.
Aufgaben und frühere FAIL-Befunde stehen in `674/milestone-reviews.json`.

Abgeschlossene Nachmessungen (jeweils Exit 0):

- Drift: 136 Mutanten, 131 Infection-Kills (115 mit Assertion-Fehlschlag,
  16 reine Fehlerkills), fünf geprüfte Äquivalenzen, null
  ungedeckte Fälle; `drift-edge-verified.out/.exit`. Die drei neuen Grenzfälle
  erkennen die betreffenden Überlebenden; der Callable-Fall endet jedoch in
  `ArgumentCountError` und bleibt deshalb ohne fachlichen Assertion-Kill offen. Die 54 alten Drift-Identitäten bleiben
  bis zur expliziten Quellpaar-Zuordnung weiterhin offen.
- Backup: 198 Mutanten, 174 Infection-Kills, 17 Überlebende und sieben ungedeckte
  Fälle; `backup-domain-verified.out/.exit`. Von den Kills sind 95 einer
  fehlgeschlagenen Assertion zugeordnet; 64 sind reine Fehlerkills und 15 haben
  keine entsprechende Zuordnung. Solche Fälle bleiben offen, soweit sie zur
  Ausgangsarbeitsliste gehören. Der gemessene MSI-Trend
  dieses Dateilaufs ist 87,88 % und enthält diese Einschränkung.

Vor der strikten R9-Fehlerklassifizierung wurden nach Einbeziehung der
Backup-Belege vorläufig 1.670 eindeutige Ausgangsidentitäten als getötet
registriert, 56 mit geprüften Ignores erledigt und 2.978 als offen geführt.
Diese vorläufigen Zahlen sind durch die unten stehende Korrektur ersetzt. Die fünf neuen Drift-Ignores erhöhen die Zahl der
geprüften Einträge auf 61, reduzieren aber ohne Quellpaar-Zuordnung nicht die
Zahl der alten offenen Drift-Identitäten. Die Zählung der ursprünglichen 4.748
Vorkommen / 4.704 Identitäten bleibt unverändert.

Das schnelle Gate der letzten Korrekturen bestand auf der privaten Instanz:
105 Tests / 330 Assertions, Node-Vertragstests grün, alle statischen Prüfungen
null und Baseline-Ratsche ohne Verstöße, Exit 0 (`r8-fixes-fast.log/.exit`).
Der separate Describe-Fokuslauf prüft den entfernten historischen Ignore.
Vollständiges Gate, regulärer Pre-Push und CI sind noch ausstehend.


## Strikte Kill-Zuordnung nach R9

Beide unabhängigen R9-Reviews lehnten die beantragte Anrechnung von 49 alten
Drift-Kills ab: `5fa227a96fc0…` → `307dd7491126…` endet in einem
`ArgumentCountError`, ohne dass eine fachliche Assertion fehlschlägt. Die
Zuordnung selbst wurde für alle 54 vollständigen Quellpaare bestätigt.
Anrechenbar sind **48 Assertion-Kills und fünf einzelne Äquivalenzen**; der
Fehlerfall bleibt offen. Originalidentitäten werden nicht umnummeriert oder
ersetzt: Das Register verknüpft jeweils alte und neue exakte Identität sowie
beide Messquellen über `identityLineage`. Die fünf historischen Äquivalenzen
stehen zusätzlich mit ihren eigenen alten Identitäten und individuellen
Begründungen in der Ignore-Liste; ihre neue Einzelprüfung bleibt erforderlich.

Die Korrektur wurde auf alle bisherigen Belege angewendet. Nur Testmethoden
innerhalb eines tatsächlichen PHPUnit-Abschnitts „failure(s)“ zählen als
Assertion-Kill. Benannte Fehler, reine Warnungen und Zeitüberschreitungen
werden nicht als solche gewertet. **53 zuvor als Assertion-Kills geführte
ursprüngliche Identitäten wurden wieder geöffnet.** Die frühere Auswertung und
ihre Zähler sind unter `progress-before-r9-attribution-correction.json` und
`reconcile-before-r9-attribution-correction.js` erhalten. Rohreports bleiben
unverändert. Die neuen Klassifizierungsregeln wurden gegen Fehler-, Warnungs-,
Assertion- und gemischte Ausgaben sowie alle 54 echten Drift-Zuordnungen geprüft.

Resource ist erstmals mit Quellbeleg nachgemessen: 116 Mutanten, 114 echte
Assertion-Kills, zwei Überlebende, null ungedeckte Fälle, Exit 0,
281,989 s; gemessener Infection-MSI 98,28 %. Davon erledigen 106 Kills
ursprüngliche offene Identitäten. Beleg:
`verified-focus/classes_catalog_resource_php-2026-10-20T22-16-25-387Z.json`
mit unveränderlichem Rohreport und vollständigem Quellarchiv.

Nach strikter Klassifizierung, Resource und den 48 bestätigten Drift-Kills:
**1.771 eindeutige Assertion-Kills, 56 geprüfte Ignores, fünf historische
Ignore-Einträge noch in Review, 2.872 weitere offene Identitäten**.
Nach Originalvorkommen: 1.811 / 56 / fünf / 2.876; vier doppelte OAuth-Fälle
sind nun ebenfalls wieder offen. Insgesamt bleiben 4.748 Vorkommen und
4.704 eindeutige Identitäten erhalten. Die fünf ausstehenden Ignore-Reviews
zählen bis zur Freigabe ausdrücklich nicht als erledigt.

`ccd616e` bestand den regulären pre-commit-Hook (105 Tests / 330 Assertions,
Node, Statik und Baseline-Ratsche grün). Der anschließende reguläre Push wurde
wegen dieser Nachweiskorrektur **vor Veröffentlichung kontrolliert abgebrochen**;
kein Hook wurde umgangen. Nach Abbruch wurden die zugehörigen Testprozesse
beendet und die private Instanz auf Orphans geprüft. Remote blieb nachweislich
`5b973dd`. Belege: `milestone-commit.log/.exit` (0),
`milestone-push.log/.exit` (manueller Abbruch 130) und
`milestone-push-abort.txt`. Ein neuer Commit, vollständiges Gate, erfolgreicher
Push und CI stehen für diese Korrektur weiterhin aus.


### R10: Korrektur einzeln freigegeben

Zwei frische unabhängige Codex-Reviewer haben die fünf historischen Einträge,
die strikte Klassifizierung, die expliziten Quellpaar-Verknüpfungen und den
korrigierten Bericht jeweils mit **scoped PASS** bestätigt. Erst danach wurden
diese fünf Einträge in `approved-ignores.json` aufgenommen. Insgesamt sind
66 neue Ignore-Einträge einzeln geprüft; davon erledigen 61 ursprüngliche offene
Identitäten. Nachweis: `attribution-reviews.json`. R9 bleibt für die ursprünglich
beantragten 49 Assertion-Kills ausdrücklich FAIL; der Error-Fall bleibt offen.

Page ist anschließend ohne Codeänderung nachgemessen: 92 Mutanten, 87
Infection-Kills (86 mit Assertion-Fehlschlag, ein reiner Fehlerkill), fünf
Überlebende, null ungedeckte Fälle, Exit 0, 262,835 s. Davon betreffen
75 Kills ursprünglich offene Identitäten. Der aktuelle Registerstand nach Page
ist **1.846 eindeutige Assertion-Kills / 61 genehmigte Ignores / 2.797 offen**;
nach Vorkommen 1.886 / 61 / 2.801. Originalumfang und Duplikate bleiben erhalten.
Folder und URL werden noch mit den bereits vorhandenen Tests gemessen; der
laufende begrenzte Dateibatch ändert diese Snapshot-Angaben nicht rückwirkend.
Das schnelle Gate der Nachweiskorrektur bestand mit Node-Vertragstests, null
Statikbefunden und unveränderter Baseline-Ratsche, Exit 0
(`r9-correction-fast.log/.exit`). Neuer regulärer Commit, Full-Gate, Push und CI
stehen weiterhin aus.
