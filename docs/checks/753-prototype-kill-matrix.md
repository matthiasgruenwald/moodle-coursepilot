# PROTOTYPE #753 – alle fehlschlagenden Tests je Mutant (Wegwerf, Primärquelle)

Branch `prototype/753-kill-matrix`, Basis `6512eea`. Nur Nachweis; keine Gesamtmessung, keine Testlöschung.

## Frage
Wie liefern PHPUnit/Infection je Mutant ALLE fehlschlagenden Tests statt nur des ersten?

## Ursache
Infection 0.29.14 schreibt je Mutant eine eigene `phpunitConfiguration.<hash>.infection.xml` und setzt
`XmlConfigurationManipulator::setStopOnFailureOrDefect` → `stopOnDefect="true"` (PHPUnit ≥10).
PHPUnit hat auf der CLI kein Gegenstück (`--stop-on-defect` ohne Negation), die XML-Einstellung gewinnt.
Folge: Lauf endet nach dem ersten Defekt, `killedBy` = erster Test. Config-Option in Infection gibt es nicht.

## Lösung (ohne Vendor-Patch)
`phpUnit.customPath` zeigt auf einen Wrapper (`evidence/753-prototype/phpunit-allfail.sh`):
1. `stopOn*="true"` → `"false"` in der Mutant-XML (per `--configuration` gefunden),
2. `--log-junit …/junit/<hash>.infection.xml` je Mutant,
3. Mutant-Quelle `mutant.<hash>.infection.php` wird gesichert (Infection-JSON enthält keinen Hash;
   Zuordnung Hash→Mutant über `mutatedSourceCode`).
Aufruf wie im Gate: `--threads=1 --only-covering-test-cases --skip-initial-tests --coverage=…`,
Konfig `evidence/753-prototype/infection-config.json`, danach `exec` des echten phpunit.

## Ergebnis (86 Mutanten, `classes/availability_privacy.php` + `classes/cm_references.php`, 34 Tests)
- 86/86 Mutanten Hash-zugeordnet, 86 JUnit-Dateien. Rohdaten: `evidence/753-prototype-kill-matrix-sample.json`.
- 81 getötet, 5 überlebt (Infection-Zahlen unverändert zum Lauf ohne `stopOnDefect`-Änderung: gleiche 81/5).
- Getötete Mutanten nach Zahl fehlschlagender Tests: 0→2, 1→13, 2→35, 3→9, 4→6, 5→5, 6→4, 7→1, 8→1, 10→5.
  → **66 von 81** Kills hatten ≥2 fehlschlagende Tests; `killedBy` allein hätte sie als Einzelkills gezeigt.
- Überlebende: 0 fehlschlagende Tests (konsistent).
- 2 Kills ohne Test-Failure in JUnit: **PHP-Warnung** (`Undefined array key`), nur im PHPUnit-Output
  („tests triggered … warning, Triggered by …“), nicht in JUnit → Warnungs-Kills getrennt ausweisen, Parser für
  `processOutput` nötig (Infection-JSON `processOutput`, nur bei `--log-verbosity=all`/Debug vollständig).

## Messzeit
3 min 45 s für 86 Mutanten (≈2,6 s/Mutant, 1–10 Tests je Mutant); Lauf ohne Wrapper 3 min 44 s → kein messbarer Mehraufwand
bei kleiner Testmenge. Coverage-Erzeugung für die 3 Testklassen: ≈10 s. Instanz-Setup `p753` (eigener Container): ≈ 5 min.

## Grenzen
- `--only-covering-test-cases`: nur Tests, die die mutierte Zeile per Line-Coverage abdecken, laufen. Fehlschlagende
  Tests ohne Coverage-Treffer (Subprozess-Tests, pcov-Lücken, Vertragstests) fehlen in der Matrix → „A-Kandidat“
  bleibt Coverage-Artefakt-verdächtig; vollständige Matrix bräuchte Lauf ohne den Filter (Suite ≈1.031 s je Mutant → unrealistisch)
  oder separaten Nachlauf der Nicht-Covering-Tests für nur die überlebenden/ A-Mutanten.
- Mehr Tests je Mutant als bisher (Fortsetzung nach Defekt): Laufzeit des Volllaufs steigt für breit abgedeckte Zeilen; nicht extrapoliert.
- Timeouts/Fehler/uncovered: Infection-JSON liefert sie getrennt (`timeouted`, `errored`, `uncovered`); hier 0.
- JUnit zählt `failure`/`error`; Warnungen, Risky und Deprecations stehen nur im Text-Output.
- Eigene Gate-Instanz nötig (`mutation-full-instance.sh up <id>`), Hauptinstanz und Lock-Dateien nicht angefasst.
- Wrapper ändert die Mutant-XML in-place; Infection-Statusberechnung bleibt gleich (Exitcode ≠0 → getötet).

## Für /implement
Wrapper+JUnit-Parser (failure/error) + Warning-Parser, Sektionen wie `mutation-full.js`, Vertragstests ausnehmen (T4/Story 33),
Nachlauf für Nicht-Covering-Tests der A-Kandidaten.
