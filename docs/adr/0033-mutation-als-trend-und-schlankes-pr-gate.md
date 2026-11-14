# ADR 0033 — Mutation als Trend, schlankes PR-Gate, Coverage-Untergrenze

Status: angenommen (14.11.2026, Entscheidung Matthias). Ändert ADR 0029 in den unten
genannten Punkten; alles Übrige aus ADR 0029 gilt weiter.

## Context

Review des Testapparats am 14.11.2026 nach Abschluss von Spec 0029:

- Die Suite selbst ist nicht das Problem: 3303 Fälle, rund 21 Minuten mit Coverage, flach
  verteilt (etwa 0,4 s je Test). Ein CI-Lauf mit kleinem Diff dauerte rund 28 Minuten.
- Teuer ist die Mutationsprüfung mit der Regel „keine Überlebenden auf geänderten Zeilen“:
  rund 6 s je Mutant seriell, etwa 0,9 Mutanten je Anweisung. Ein Umbau mit 1500 berührten
  Zeilen kostet Stunden Rechenzeit, dazu die Arbeit, jeden Überlebenden zu töten oder seine
  Äquivalenz einzeln zu beweisen.
- Ein Refactoring ist der schlechteste Fall: verschobene Zeilen gelten als geändert, und der
  Ignore-Hash bindet den ganzen Dateiquelltext. Alle Ignores der berührten Zeilen verfallen.
- 550 der 927 Ignores waren Cast-Mutatoren. Das Plugin läuft ohne `strict_types`; ein
  entfernter Cast vor einem typisierten Parameter, einem `PARAM_INT`-Wert oder in einem
  String-Kontext ist fast immer äquivalent.
- CI fuhr die Suite je Lauf neunmal (vier Mutations-Teile mit Coverage, fünf Matrix-Legs), im
  PR und nach dem Merge erneut. `pre-push` fuhr denselben vollen Lauf lokal ein drittes Mal.
- Seit dem 1.10.2026 betrafen 80 von 323 Commits Mutation und Spec 0029, 33 waren Features.
- Die Kill-Matrix (#753, ein Tag Rechenzeit) ergab für das Prunen „Nutzen gering“.

## Decision

1. **Mutation ist ein Trend, keine Merge-Bedingung.** Im Pull Request läuft keine Mutation.
   Nach dem Merge (Push auf `dev`/`main`, manueller Lauf) mutiert CI den Diff des Pushes in
   vier Teilen; Befunde erscheinen als Warnung und in den hochgeladenen Berichten und färben
   den Lauf nicht rot (`GATE_MUTATION=1`, `GATE_MUTATION_ADVISORY=1`). Lokal bleibt
   `npm run gate -- mutation` blockierend für den, der es aufruft.
2. **Cast-Mutatoren sind abgeschaltet** (Infection-Profil `@cast`). Ihre Einträge sind aus
   `mutation-ignore.json` entfernt.
3. **PR-CI ist schlank:** ein `quality`-Job (Suite mit Coverage, Ratsche, Statik) und zwei
   Matrix-Ecken (5.2/MariaDB/PHP 8.3, 5.3/PostgreSQL/PHP 8.4). Zusammen mit `quality`
   (5.1/MariaDB/PHP 8.4) sieht ein PR jede Moodle-Linie, jede Datenbank und jede PHP-Version
   einmal. Die volle Matrix läuft nach dem Merge.
4. **`pre-push` führt `matrix` und `fast` aus.** `full` ist verbindlich in CI und lokal auf
   Wunsch.
5. **Der Mutations-Selbsttest** (`mutation-proof.js`) läuft in CI nur, wenn `scripts/gate/`
   oder die Composer-Dateien geändert sind.
6. **Coverage-Untergrenze 95 %:** `ratchet-coverage` blockiert, wenn die Gesamt-Coverage
   unter die Baseline **und** unter 95 % fällt. Datei ≥ 90 % und CRAP ≤ 8 für Geändertes
   bleiben unverändert. `baseline.json` bleibt als Messwert und wird nie gesenkt.
7. **Kein Mutations-Volllauf und keine Prune-Aktion ohne Anlass.** Volllauf nur auf
   Entscheidung des Maintainers (etwa vor einem Release).

## Considered Options

- **Mutation blockierend nur für Sicherheitscode** (`connection`, OAuth, Privacy,
  Capability): verworfen für jetzt, weil die Ignore-Pflege für genau diese Dateien bliebe.
  Kann später ergänzt werden, wenn der Trend dort Lücken zeigt.
- **Nachtlauf per `schedule`:** verworfen, weil GitHub geplante Läufe nur vom Standardbranch
  `main` startet und die Entwicklung auf `dev` liegt. Der Lauf nach dem Merge prüft denselben
  Diff ohne diese Einschränkung.
- **Suite parallelisieren:** zurückgestellt, bis die PR-CI trotz dieser Änderungen über
  20 Minuten bleibt.

## Consequences

- Ein schwacher Test auf geänderten Zeilen fällt erst nach dem Merge auf und blockiert nichts.
  Wer den Trend ignoriert, verliert den Schutz; die Warnung am Lauf auf `dev` ist der Anlass
  für ein Folgeticket.
- Ein fehlender Cast wird von der Mutation nicht mehr gefunden; PHPStan deckt einen Teil ab.
- Ein Fehler, der nur auf einer nicht geprüften Matrix-Kombination auftritt, wird erst nach
  dem Merge auf `dev` rot.
- `CODING_STANDARDS.md` nennt Mutation noch als vom Gate erzwungen und kennt die neuen
  Testregeln nicht. Bis die Vereinsfassung geändert ist
  ([security-checks#2](https://github.com/Moodle-in-Niedersachsene-V/security-checks/issues/2)),
  gilt die Abweichung in [`docs/agents/standards-abweichungen.md`](../agents/standards-abweichungen.md).
