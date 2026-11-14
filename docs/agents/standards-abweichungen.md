# Abweichungen von `CODING_STANDARDS.md`

`CODING_STANDARDS.md` ist die mit dem Verein abgestimmte Fassung (kanonisch:
`Moodle-in-Niedersachsene-V/security-checks`, `coding-standards/CODING_STANDARDS.md`) und
wird hier nicht einseitig geändert. Diese Datei hält fest, wo Coursepilot bis zur Änderung
der Vereinsfassung abweicht. Bei Widerspruch gilt für dieses Repo diese Datei.

Abstimmung: [security-checks#2](https://github.com/Moodle-in-Niedersachsene-V/security-checks/issues/2),
Nachtrag vom 14.11.2026. Sobald die Vereinsfassung die Punkte übernimmt und hierher
gespiegelt ist, entfällt der jeweilige Eintrag.

| Stelle im Standard | Abweichung in Coursepilot | Grund |
|---|---|---|
| Einleitung und „Enforced by the gate“: „surviving mutants on changed lines“; T2 („diff-based mutation gate“) | Mutation ist ein Trend nach dem Merge und blockiert keinen PR. | [ADR 0033](../adr/0033-mutation-als-trend-und-schlankes-pr-gate.md) |
| Ignore-Regeln für äquivalente Mutanten | Cast-Mutatoren sind in der Infection-Konfiguration abgeschaltet statt je Fundstelle ignoriert. | ADR 0033 |
| „coverage ratchet“ | Die Gesamt-Coverage blockiert nur unter 95 %; Datei ≥ 90 % und CRAP ≤ 8 für Geändertes bleiben. | ADR 0033 |
| T (neu, noch nicht im Standard) | **Gleichförmige Prüfungen sind ein datengetriebener Test.** Dieselbe Prüfung über viele Einheiten (Registrierung im Dienst, Allowlist, Pflichtwortlaut) steht einmal mit Datenprovider über die deklarative Quelle, nicht als eine Methode je Werkzeug. Gilt für neue Tests; Bestand wird beim Anfassen der Datei zusammengelegt. | Review 14.11.2026: 38 Registrierungs- und 68 Wortlaut-Tests |
| T (neu, noch nicht im Standard) | **Kein Test auf Abwesenheit.** Tests, die nur prüfen, dass entfernter Code nicht mehr existiert, entstehen nicht. Ausnahme: Die Abwesenheit ist selbst ein Vertrag (etwa Datenschutz-Oberfläche), dann ist es ein Vertragstest nach T4. | Review 14.11.2026: 14 solche Tests |

T4 gilt unverändert: Vertragstests ändern sich nur mit ihrem Vertrag und sind keine
Prune-Kandidaten.
