# Mutations-Volllauf #673 (18.–19.10.2026)

Einmaliger Lauf über den gesamten Produktionscode. Spec 0029, Issue #673.

## Ergebnis

| Messwert | Wert |
|---|---:|
| Mutanten gesamt | 11.985 |
| Getötet (davon 14 Zeitüberschreitungen) | 7.102 |
| Überlebt | 4.726, davon 135 mit exaktem begründetem Ignore (`scripts/gate/mutation-ignore.json`), **4.591 offen** in 140 Dateien (144 Dateien mit Überlebenden) |
| Nicht gedeckt | 157 |
| Fehler / Syntaxfehler / übersprungen | 0 / 0 / 0 |
| Davon „getötet“ ohne fehlgeschlagenen Test | 174 (PHPUnit-Ausgabe „OK, but there were issues“ mit Warnungen, von Infection als getötet gewertet; die 14 Zeitüberschreitungen kommen hinzu). Ohne diese 174 und die 14 läge der MSI bei 57,69 % |
| **MSI (Trendwert, keine Schwelle)** | **59,26 %** (nur gedeckte Mutanten: 60,04 %) |
| Prune-Kandidaten | 93 (A) + 641 (B), 23 Vertragstests ausgenommen, Testbestand 1.804 Testmethoden (die 2.003 Testfälle der Coverage-Datei, Datenprovider-Fälle je Methode zusammengefasst) |

Die 135 Ignores sind dieselben exakten Äquivalenzfälle wie im Diff-Gate. Dass der Wert niedriger liegt als der Diff-MSI
der Gate-Läufe (rund 90 %), ist erwartet: Das Gate prüft nur geänderte Zeilen, dies ist der gesamte Altbestand.
Größte Befundmengen (offene Überlebende): `catalog/assign.php` 581, `catalog/quiz.php` 337, `catalog/forum.php` 177,
`catalog/choice.php` 166, `external/update_mc_question.php` 116. Häufigste Mutatoren: `ConcatOperandRemoval` 887,
`Concat` 602, `DecrementInteger` 443, `IncrementInteger` 416, `CastInt` 350. Das sind Meldungs- und Zahlenzusammensetzungen,
die Tests nicht prüfen; Handlungsbedarf ist eine fachliche Entscheidung und nicht Teil dieses Issues.

Berichte:

- [Überlebende Mutanten je Datei](673-mutationsvolllauf-2026-10-19-ueberlebende.md)
- [Prune-Bericht](673-mutationsvolllauf-2026-10-19-prune.md): Liste für eine menschliche Entscheidung. **Kein Test wurde gelöscht** und keiner wird automatisch gelöscht.

## Messquelle

- Quellstand: Commit `4161c4fbab40cf4dcb67bf319a02ac07f9de43f3` (Branch `integration/spec-0029-followup`). Plugin-Baumdigest
  (`treeDigest`) `65631bb3dda362ef8aa7a93d5f6c9deae4f46d51bb608cabb514030e083c1fbc`; am Ende des Laufs identisch im Checkout und in den
  Mounts von `i1` und `i2`. Quell-SHA-256 je Produktionsdatei: `sources.json` im Arbeitsverzeichnis.
- Coverage für die Testauswahl: `infection-coverage` (2.003 Tests) des `gate full` auf `4161c4f` vom 18.10.2026 05:29.
- Umgebung: Gate-Container (Moodle 5.1.7+ Build 20260928, PHP 8.4.25, MariaDB 11.4.12, Infection 0.29.14, PHPUnit 11.5.55, PCOV),
  4 vCPU / 16 GB Host mit fremden Läufen. Infection mit `--threads=1 --only-covering-test-cases --skip-initial-tests`,
  Zeitgrenze 1.800 s je Mutant, `@default`-Mutatoren und die Ausschlüsse des Gates (`tests`, Einträge aus `scripts/gate/baseline.json`).
- Befehle: `node scripts/gate/mutation-full.js plan | run | report 2026-10-19` (siehe `docs/gate.md`, Abschnitt Mutations-Volllauf),
  Instanzen mit `bash scripts/gate/mutation-full-instance.sh up i1|i2`. Rohdaten (`raw/*.json.gz`, `*.log.gz`), Done-Marker und
  Quarantäne liegen unter `/var/lib/afk-pipeline/673/` (nicht im Repo).
- Auswertung: Der Töter eines Mutanten ist der im PHPUnit-Ausgabetext genannte fehlgeschlagene Test, `coveredBy` die `--filter`-Liste im
  Infection-Debug-Log. Infection 0.29 liefert beides nicht als JSON-Feld.

## Laufzeit und Instanzen

- Wandzeit: erster Start 18.10. 09:18 bis `full.done` 19.10. 23:12 (MESZ), rund 37,9 h. Summe der Abschnittszeiten der gültigen Abschnitte:
  183.540 s, rund 51,0 h (seriell gerechnet). Die frühere serielle Hochrechnung (6,6 h bzw. 28,5 h) unterschätzte die Spanne;
  der Mittelwert liegt bei rund 15,3 s je Mutant, mit starker Streuung (Meldungs-/Katalogklassen mit breiter Testabdeckung bis über 40 s).
- Verteilung: Abschnitte pro Verzeichnis, größere in Blöcken zu zehn Dateien (27 Abschnitte). Warteschlange über exklusive
  Lock-Dateien, Done-Marker atomar per Rename. Gültige Abschnitte nach Läufer: seriell/`main` 4 (3,2 h + 1,2 h), `i2` 18 (25,5 h), `i1` 5 (21,1 h).
- Ablauf: Zuerst ein serieller Läufer im Gate-Stack (`classes-01`..`04`), ab dem 18.10. 11:50 zusätzlich `i2` (eigener Stack, 3 GB / 1,5 CPU,
  DB 2 GB / 1 CPU, von hinten). Zwei Instanzen, nicht drei: 4 vCPU, Last bis rund 3, Plattenfüllstand 88–95 %. `i1` kam am 19.10. 02:07 hinzu
  und ersetzte den gestörten Stack. Wirkung auf die Laufzeit: Gesamtwandzeit rund 37,9 h gegenüber rund 51 h seriell (Faktor 1,35). Die
  Beschleunigung ist klein, weil `classes-catalog-01` (1.432 Mutanten, 57.455 s) am Ende 16 h allein lief; kleinere Blöcke für `classes/catalog`
  hätten besser verteilt. Dazu kommen rund 4,8 h verworfene Läufe (siehe Quarantäne) und eine Lücke ohne zweiten Läufer.
- Datenbanken: jeder Läufer mit eigener MariaDB, nie dieselbe Datenbank zweimal. Der Lastschutz (`host-load.js`) blieb aktiv und wurde nicht umgangen.

## Abweichungen und Grenzen

1. **Fremdüberschreibung des gemeinsamen Mounts.** Ein fremder `gate`-Lauf überschrieb `/opt/kurspilot-gate/plugin` per `rsync --delete` mit einem
   älteren Plugin-Stand (3 Dateien fehlten, mehrere Quellen und Tests anders). Der `main`-Läufer brach am 18.10. 16:28Z bei `classes-catalog-01`
   ab (`Could not find the source file`). Die erste beobachtete Überschreibung ist 17:58 lokal (15:58Z); der genaue Beginn ist nicht belegbar.
   Die Quelle jeder gemuteten Datei in allen Rohreports stimmt exakt mit dem Repo überein (PHP-Parser-Print-Hash), das beweist aber nicht den Zustand
   der übrigen geladenen Dateien und Tests. Behandlung: **`classes-05`, `classes-06`, `classes-activity_files`, `classes-admin` wurden
   verworfen** (Daten archiviert, nicht gelöscht, nicht verwendet) und auf `i1` mit privatem Mount neu gefahren.
2. **`classes-01`..`classes-04`** (serieller Läufer und `main`, endeten spätestens 11:42Z) wurden **nur zeitlich** als gültig bewertet: mehr als vier Stunden
   vor der ersten beobachteten Überschreibung und mit hash-belegter Quelle der gemuteten Dateien. Ein Hash des Testbaums liegt für diese Zeit nicht vor.
3. **Ab der Härtung** prüft der Läufer vor dem Lock und vor dem Done-Marker den Mount-Digest gegen den festgehaltenen Quellstand. Das gilt für die
   `i1`-Abschnitte. Die `i2`-Abschnitte liefen mit dem Läufer vor dieser Härtung, aber auf einem privaten Mount, der vor der Überschreibung als Kopie
   des damals repo-gleichen Mounts entstand und am Ende dem Repo-Digest entspricht.
4. **Fremder Checkout-Wechsel am 19.10.** Ein anderer Thread wechselte den gemeinsamen Checkout kurz auf `dev` und stashte die Arbeitsdateien; der Stand wurde
   wiederhergestellt (Stash blieb bestehen, Inhalt der vier Dateien identisch). `classes-catalog-01` (05:14Z–21:12Z) lief darüber hinweg. Der Läufer liest
   die Quellen nur beim Start, hält den Digest im Speicher und prüfte den privaten `i1`-Mount nach dem Abschnitt gegen diesen Digest, bevor der
   Done-Marker entstand; der Mount ist heute identisch mit dem Repo (`4161c4f`). Der Abschnitt ist daher gültig.
5. **Prune-Bericht ist eine Obermenge.** PHPUnit bricht beim ersten Fehlschlag ab; ein Test, der denselben Mutanten töten würde, aber später läuft, zählt
   nicht als Töter. Gruppe A (deckt keine mutierte Zeile) enthält auch Tests für ausgeschlossene Dateien (Einstiegsskripte, Registrierung). Jeder
   Kandidat braucht Einzelprüfung. Ausgenommen sind 23 Vertragstests (Privacy-Provider, Privacy-Oberfläche, Install-/Upgrade-Smoke).
6. **Zählabweichung.** Die frühere reine Erzeugungszählung nannte 11.909 Kandidaten (Stand 14.10.), der Lauf 11.985 auf `4161c4f`. Die Differenz ist
   nicht einzeln nachgewiesen (Code- und Teständerungen aus #671 seit 14.10.). Die Coverage-Statuswerte (157 ungedeckt) stammen aus der Coverage vom
   18.10. 05:29.
7. **Getötet durch Warnung.** 174 Mutanten zählt Infection als getötet, obwohl alle Tests grün waren (PHPUnit-Warnungen). Sie stehen nicht im
   Überlebenden-Bericht und liefern keinen Töter für den Prune-Bericht. Ob die Warnungen schon im unveränderten Lauf auftreten, wurde nicht geprüft.
   Der Abschnitt `db` (2 Dateien) enthält keine mutierbaren Anweisungen (0 Mutanten).
8. Kein Schwellenwert, keine Gate-Konfiguration geändert, keine Baseline gelockert. MSI ist ein Trendwert.

## Nachtrag: Merge mit dev (20.10.2026)

Die Messung beschreibt ausschließlich den Stand `4161c4f`. Danach wurde `origin/dev` per normalem Merge-Commit in den Branch geholt
(Hotfix #721: `classes/catalog/write_target.php` +44 Zeilen, neuer Test `tests/external/assign_grade_item_write_test.php`,
Sprachzeichenkette, Version 2.1.3-beta). Der CI-Lauf auf dem Merge-Stand zeigte sechs Überlebende, die bereits in
`scripts/gate/mutation-ignore.json` stehen: Ihre `sourceHashes`-Bindung an `write_target.php` passte nach der Änderung nicht mehr. Die
sechs Einträge wurden mit dem neuen SHA-256 von `write_target.php` neu gebunden; Mutant-Identität und Begründung blieben unverändert, es kamen
keine Ignores hinzu. Die Volllauf-Zahlen oben wurden nicht neu gemessen; `write_target.php` wurde im Volllauf auf dem alten Stand gemutet.
