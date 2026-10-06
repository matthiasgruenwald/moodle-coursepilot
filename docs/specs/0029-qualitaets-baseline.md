# Spec 0029 — Qualitäts-Baseline für den Server-MCP

Stand: 05.10.2026. Grundlage: Messung auf dev @ 05fb815 (CI-Lauf 37213747803, Clover des
Moodle-5.0-Legs), Churn seit Anlage von `local_coursepilot` (27.07.2026).

Umsetzungsissue: [#655](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/655)
(ready-for-agent).

Entscheidungen: [ADR 0029](../adr/0029-qualitaets-gate-als-ratsche.md) (Gate als Ratsche),
[ADR 0030](../adr/0030-schichtregeln-per-klassenliste.md) (Schichtregeln).

**Vorbedingung:** Start nach dem Merge von PR #654 (`integration/release-2.1-issues`: #593,
#598, #599, #603, #626) nach `dev`. Ab Start des ersten Tickets gibt es keine neuen
Feature-PRs, bis das Gate scharf ist.

## Problem Statement

Ab 2.1 arbeiten mehrere Menschen und Agenten am Server-MCP. Heute sichern nur zwei Dinge die
Qualität: die PHPUnit-Suite mit einem 80-%-Coverage-Gate in CI und Prosa-Regeln in
`CLAUDE.md`/`AGENTS.md`. Agenten behandeln Prosa-Regeln als Richtlinie. Fehler fallen erst
nach dem Push in CI auf (rund 11 Minuten), oft gar nicht mehr in der Agentensitzung.

Gemessene Baseline:

- Line-Coverage 82,3 % (strikt nach `#[CoversClass]`); 45 von 183 Produktionsdateien unter 50 %.
- CRAP-Score über 8 bei 88 von 1088 Methoden, über 30 bei 5; Spitze 462 im Modulkatalog
  (Kombinationsregeln des Quiz, Komplexität 21, ungetestet).
- Kein Coding-Standard-Check (moodle-cs), keine statische Typprüfung, keine Schichtregeln,
  keine Mutationsanalyse. Der lokale `php -l`-Hook läuft ohne PHP still ins Leere.
- Rückkanten zwischen Schichten: Modulkatalog → Werkzeuge, Änderungsverlauf → Werkzeuge,
  WebDAV-Adapter → Werkzeuge.
- Hotspots (Änderungshäufigkeit × Komplexität): OAuth, Kontextbereich, Ablageort-Anker,
  MC-Frage-Update, WebDAV-Ablage-Port, Dispatcher.

Für die Marketplace-Einreichung (#192) ist moodle-cs-Konformität faktisch Pflicht.

## Solution

Ein einmaliges, gründliches Baseline-Aufräumen bringt den Server-MCP auf einen sauberen
Stand. Danach erzwingt ein deterministisches **Gate** die Qualität bei jeder Änderung, lokal
und in CI. Das Gate ist eine **Ratsche** (ADR 0029): Der Bestand muss nicht auf einmal
perfekt sein, aber nichts darf schlechter werden, und jede geänderte Stelle muss die
Prüfregel erfüllen. Agenten (`/implement`, `/implement-spec`) laufen in den Git-Hook und
arbeiten, bis er grün ist. `/code-review` liest auf der Standards-Achse eine kurze
`CODING_STANDARDS.md` mit genau den Regeln, die kein Werkzeug erzwingt.

## User Stories

1. Als Entwickler möchte ich ein einziges Gate-Kommando mit schnellem und vollem Modus, damit ich lokal dieselben Prüfungen wie CI ausführen kann.
2. Als Agent möchte ich, dass ein pre-commit-Hook roten Code am Commit hindert, damit ich Fehler in derselben Sitzung behebe statt nach dem Push.
3. Als Agent möchte ich eine knappe, maschinenlesbare Fehlerausgabe pro Prüfung (Datei, Zeile, Regel), damit ich gezielt reparieren kann.
4. Als Entwickler möchte ich, dass der schnelle Modus nur die Tests der geänderten Dateien ausführt, damit ein Commit nicht minutenlang blockiert.
5. Als Entwickler möchte ich, dass ein pre-push-Hook die volle Suite mit Coverage und CRAP prüft, damit fehlerhafter Code selten in CI ankommt.
6. Als Entwickler möchte ich die Hooks ohne neue npm-Abhängigkeit mit einem Befehl aktivieren, damit Claude, Codex und Menschen dieselben Hooks nutzen.
7. Als Entwickler möchte ich einen dauerhaften Gate-Container mit Moodle-Mindestversion von `dev`, damit lokale Läufe weder die Spike-Instanz noch flüchtige Umgebungen berühren.
8. Als Maintainer möchte ich, dass die Gesamt-Coverage nie sinkt, damit sich die Qualität nur in eine Richtung bewegt.
9. Als Maintainer möchte ich, dass jede geänderte Produktionsdatei mindestens 90 % Line-Coverage hat, damit neuer und angefasster Code getestet ist.
10. Als Maintainer möchte ich, dass jede geänderte Methode einen CRAP-Score von höchstens 8 hat, damit komplexer Code nur mit Tests oder zerlegt durchkommt.
11. Als Maintainer möchte ich Bestand über der Regel erst beim Anfassen bereinigen (Boy-Scout-Rule), damit das Gate nicht den ganzen Bestand auf einmal blockiert.
12. Als Maintainer möchte ich Gesamt-Coverage, Anzahl der Methoden über CRAP 8 und MSI als Trend sehen, damit ich die Entwicklung ohne harte Gesamtgrenze beobachte.
13. Als Maintainer möchte ich das Zielbild von 95 % Coverage erreichen oder den ungedeckten Rest pro Stelle begründet berichtet bekommen, damit klar ist, was bewusst ungetestet bleibt.
14. Als Maintainer möchte ich Coverage strikt nach `#[CoversClass]` messen, damit nur gezielt getesteter Code zählt.
15. Als Maintainer möchte ich, dass jede Testklasse eine Covers-Angabe trägt, damit die strikte Messung keine Lücken durch fehlende Metadaten hat.
16. Als Maintainer möchte ich Coverage auf der Moodle-Mindestversion von `dev` messen (ADR 0027), damit die Messung zur unterstützten Linie passt.
17. Als Maintainer möchte ich Seiten-Einstiegsskripte und Admin-Einstellungen aus dem Coverage-Nenner nehmen, sofern sie keine Logik enthalten, damit der Wert aussagekräftig ist.
18. Als Maintainer möchte ich, dass Einstiegsskripte nur Aufrufe enthalten und ihre Logik in getesteten Klassen liegt, damit der Ausschluss nichts verbirgt.
19. Als Maintainer möchte ich den Upgrade-Pfad durch einen Test abgedeckt haben, damit riskante Schemaänderungen geprüft sind.
20. Als Maintainer möchte ich, dass der Code moodle-cs ohne Errors und Warnings besteht, damit die Marketplace-Prüfung (#192) keine Stilbefunde liefert.
21. Als Maintainer möchte ich phpdoc-, savepoint-, Mustache- und ESLint-Prüfungen für Vorlagen und das AMD-Modul, damit auch Nicht-PHP-Teile geprüft werden.
22. Als Maintainer möchte ich statische Typprüfung auf Level 6 mit einer Baseline-Datei, die nur schrumpfen darf, damit neue Typfehler sofort auffallen.
23. Als Maintainer möchte ich die vier Schichten aus ADR 0030 durch einen Dependency-Regel-Checker erzwungen haben, damit Modulgrenzen nicht still erodieren.
24. Als Maintainer möchte ich, dass eine neue Wurzel-Klasse ohne Schichtzuordnung das Gate rot macht, damit keine Klasse unzugeordnet bleibt.
25. Als Maintainer möchte ich die drei gemessenen Rückkanten (Modulkatalog, Änderungsverlauf, WebDAV-Adapter → Werkzeuge) aufgelöst haben, bevor die Schichtregel scharf wird.
26. Als Maintainer möchte ich für die Hotspots eine Architekturprüfung mit Deepening-Vorschlägen, damit die meistgeänderten komplexen Module vor dem Testen tiefer werden.
27. Als Maintainer möchte ich einmalig einen vollständigen Mutationslauf über den gesamten Produktionscode, damit schwache Tests vor dem Release sichtbar werden.
28. Als Maintainer möchte ich den Mutationslauf unbeaufsichtigt in mehreren isolierten Instanzen und in wiederaufnehmbaren Abschnitten laufen lassen, damit er Tage dauern darf, ohne dass ihn jemand überwacht.
29. Als Maintainer möchte ich vorher einen Pilotlauf auf einem Bereich mit Hochrechnung der Laufzeit, damit der Volllauf planbar ist.
30. Als Maintainer möchte ich einen Bericht der überlebenden Mutanten pro Datei, damit gezielt Tests nachgeschärft werden.
31. Als Maintainer möchte ich einen Prune-Bericht der Tests, die keinen Mutanten töten, damit ich überflüssige Tests erkenne.
32. Als Maintainer möchte ich, dass Tests nie automatisch gelöscht werden und ein Agent Kandidaten nur zur Sammelfreigabe vorschlägt, damit kein schützender Test verschwindet.
33. Als Maintainer möchte ich Vertragstests (Privacy-Oberfläche, Install-Smoke, ähnliche Invarianten) vom Prune-Bericht ausgenommen haben, weil sie Invarianten schützen und keine Mutanten töten müssen.
34. Als Maintainer möchte ich nach der Baseline eine diff-basierte Mutationsprüfung in CI, die bei überlebenden Mutanten in geänderten Zeilen blockiert, damit neue Tests echte Assertions haben.
35. Als Maintainer möchte ich äquivalente Mutanten in einer versionierten Ignore-Liste mit Begründung führen, damit nicht tötbare Mutanten das Gate nicht dauerhaft blockieren und im Review sichtbar sind.
36. Als Maintainer möchte ich, dass jede Gate-Prüfung ihr Ergebnis in ein lokales Log schreibt und ein Bericht Fehlschläge nach Datei und Prüfung als Rangliste zeigt, damit gehäufte Fehlschläge als Ereignis-Trigger erkennbar sind.
37. Als Maintainer möchte ich die Regel „Steht eine Datei im Gate-Bericht oben, ist vor dem nächsten Feature an ihr eine Architekturprüfung fällig“, damit größeres Aufräumen ereignisgesteuert statt nach Rhythmus geschieht.
38. Als Vereinsentwickler möchte ich einen Entwurf der `CODING_STANDARDS.md` als Diskussionsgrundlage, getrennt in einen projektübergreifenden Kern und einen Coursepilot-Teil, damit wir gemeinsame Standards beschließen statt sie vorgesetzt zu bekommen.
39. Als Reviewer (Mensch oder `/code-review`) möchte ich eine beschlossene, kurze englische `CODING_STANDARDS.md` mit nur den nicht werkzeug-erzwungenen Regeln, jeweils mit Verweis auf ADR oder Quelle, damit die Standards-Achse prüfbar ist. Themenliste für den Entwurf: Schichtregeln mit Begründung, Einstiegsskripte ohne Logik, Formularweg (ADR 0016), Testregeln, Fehlerbehandlung, Glossarbegriffe, Boy-Scout-Rule mit Ereignis-Triggern, Begründungspflicht für Ignore-Einträge.
40. Als Agent möchte ich in `CLAUDE.md` und `AGENTS.md` nur einen Verweis auf Gate und `CODING_STANDARDS.md`, damit Regeln nicht doppelt und widersprüchlich gepflegt werden.
41. Als Agent möchte ich, dass die Claude- und Codex-Edit-Hooks dasselbe Gate-Skript aufrufen statt eigener Teilprüfungen, damit es nur eine Wahrheit gibt.
42. Als Maintainer möchte ich, dass CI das Gate im vollen Modus ausführt und die Ratsche gegen die Werte des Ziel-Branches prüft, damit CI die verbindliche Instanz bleibt.
43. Als Maintainer möchte ich die Trendwerte je CI-Lauf als Artefakt, damit die Entwicklung über Releases nachvollziehbar ist.
44. Als Maintainer möchte ich, dass das bestehende 80-%-Gesamt-Gate durch die Ratsche ersetzt wird, damit es nicht zwei widersprüchliche Coverage-Regeln gibt.
45. Als Maintainer möchte ich zum Abschluss eine Vorher/Nachher-Tabelle der Baseline-Werte, damit der Release-Stand dokumentiert ist.

## Implementation Decisions

- **Gate-Kommando** als Node-Skript ohne Laufzeit-Abhängigkeiten, Modi `fast` und `full`.
  Es ruft die PHP-Werkzeuge per `docker exec` im Gate-Container auf und wertet deren Berichte
  aus. Die Ratschenlogik (Vergleich mit gespeicherter Baseline, Zuordnung geänderter
  Dateien/Methoden aus dem Git-Diff) liegt hinter dem Kommando.
- **Baseline-Datei** versioniert im Repo: Gesamt-Coverage, PHPStan-Baseline, ggf.
  Ignore-Liste der Mutanten. Sie wird nur in Richtung „besser“ fortgeschrieben.
- **fast** (pre-commit): moodle-cs, phpdoc, PHPStan, deptrac, Node-Tests, PHPUnit nur für
  Testdateien zu geänderten Klassen. **full** (pre-push, CI): zusätzlich volle Suite mit pcov,
  Coverage-Ratsche, CRAP-Regel aus dem Clover-Bericht, savepoints, Mustache, ESLint.
- **Gate-Container**: dauerhaft, nicht unter `/tmp`, Moodle 5.1, PHP 8.4, MariaDB, eigene
  Datenbank. Spike-Instanz und `coursepilot-afk-*`-Umgebungen bleiben unberührt.
- **Tooling** (freigegeben): `moodle-plugin-ci` (moodle-cs, phpdoc, savepoints, Mustache,
  grunt/ESLint) plus PHPStan, deptrac und Infection in einer dev-only `composer.json` mit
  Lockfile. Nichts davon gelangt ins Release-ZIP.
- **Hooks**: versioniertes Hook-Verzeichnis, aktiviert über `core.hooksPath` per
  npm-Skript. Kein Husky. Der stille `php -l`-Edit-Hook entfällt.
- **Coverage-Nenner**: Seiten-Einstiegsskripte und Admin-Einstellungen werden aus der
  Coverage-Konfiguration genommen, nachdem ihre Logik in Klassen gewandert ist. Der
  Upgrade-Pfad bleibt im Nenner.
- **Schichten** nach ADR 0030; deptrac-Konfiguration mit Klassenliste für den
  Wurzel-Namespace; Werkzeugregister und Dispatcher gehören zur Einstiegsschicht.
- **Mutation**: Infection mit Threads 1 je Instanz (gemeinsame Moodle-Testdatenbank), nur
  abdeckende Tests je Mutant. Volllauf in Abschnitten je Verzeichnis über mehrere isolierte
  Instanzen; abgeschlossene Abschnitte werden nicht wiederholt. Auswertung über die
  JSON-Berichte (`killedBy`/`coveredBy`). Nach der Baseline diff-basiert
  (`--git-diff-lines`) in CI.
- **Gate-Fehlschlagslog**: eine Zeile pro Lauf und Prüfung (Datum, Prüfung, Datei, Ergebnis)
  in einer gitignorierten Datei; Bericht als Rangliste.
- **Ereignis-Trigger** für größeres Aufräumen: Hotspot, Rule of Three, Datei oben im
  Gate-Fehlschlagsbericht. Kein fester Rhythmus.
- **CI**: Das Gate ersetzt das 80-%-Skript auf dem messenden Leg; der messende Leg wird
  Moodle 5.1. Die Ratsche vergleicht mit der Baseline des Ziel-Branches.
- **Coding Standards** werden nicht vom Agenten festgelegt: Entwurf, Abstimmung mit den
  Vereinsentwicklern, dann Beschluss. Der Beschluss steht am Kopf der AFK-Kette. Die
  Gate-Regeln aus ADR 0029/0030 gelten unabhängig davon.
- **Hotspot-Architektur** über `/improve-codebase-architecture` mit Mensch im Loop; daraus
  entstehende Deepening-Tickets werden als eigene Tickets angelegt.

## Testing Decisions

- Gute Tests prüfen äußeres Verhalten an der höchsten verfügbaren Naht, nicht
  Implementierungsdetails.
- **Naht 1: Gate-Kommando.** Geprüft per `node --test` gegen feste Fixture-Berichte
  (Clover, deptrac, PHPStan, Infection) und einen kleinen Git-Diff: rot/grün je Regel,
  Ratschenfortschreibung, Zuordnung geänderter Methoden, Fehlerfälle (fehlender oder
  ungültiger Bericht ist rot, nie grün). Vorbild: der bestehende Test des Coverage-Gates.
- **Naht 2: Plugin-Code.** Werkzeuge über die externe Funktion per PHPUnit, Fachmodule über
  ihre öffentliche Schnittstelle. Test-Doubles nur an vorhandenen Adapter-Ports (Ablage-Port,
  WebDAV-Client). Keine neuen Nähte nur für Tests. Vorbild: bestehende Tests unter
  `tests/external/`.
- Jede Testmethode hat eine fachliche Assertion; jede Testklasse trägt eine Covers-Angabe.
- Vertragstests (Privacy-Oberfläche, Install-Smoke) bleiben unverändert und stehen nie im
  Prune-Bericht.

## Out of Scope

- Physisches Verschieben von Klassen in neue Namespaces (ADR 0030).
- Automatisches Löschen von Tests.
- Branch- oder Path-Coverage (Xdebug).
- Neue Moodle-Versionen in der CI-Matrix (5.2-Leg folgt ADR 0027 separat).
- Feature-Arbeit; die laufenden Issues aus PR #654 sind Vorbedingung, nicht Teil.
- Ein eigener Skill `/harden`; die diff-basierte Mutation läuft zunächst nur in CI.
- Änderungen an den Matt-Pocock-Skills.

## Further Notes

- CRAP nach Savoia/Evans: `comp² · (1 − cov)³ + comp`, aus dem Clover-Bericht der PHPUnit
  übernommen (Attribut je Methode).
- Messwerte der Baseline sind strikt nach `#[CoversClass]`; indirekt ausgeführter Code zählt
  nicht. Das erklärt 0 % bei genutzten Klassen wie den Quiz-Kombinationsregeln.
- Churn reicht nur bis zur Anlage von `local_coursepilot` am 27.07.2026 zurück (Umbenennung).
- Die Laufzeit des Mutations-Volllaufs ist geschätzt (rund 8–15 Tausend Mutanten, 1–2 Tage
  seriell, 6–12 Stunden auf vier Instanzen); der Pilotlauf ersetzt die Schätzung.
