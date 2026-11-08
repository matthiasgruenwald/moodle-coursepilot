# Modellwahl für Agenten

Diese Regel gehört in jeden Agentenbrief (Implementierer und Reviewer).

- **Haiku (medium)** implementiert Fleißarbeit mit klarem Muster: Dateien mit wenigen offenen Mutanten, Doku, Tooling nach festem Brief, Wiederholungsarbeit.
- **Sonnet (medium)** übernimmt alles Schwierige (viele offene Mutanten in einer Datei, unklare Ursachen) und jedes Review.
- **Eskalation:** Hat Haiku nach einem Durchlauf weniger als die Hälfte der Aufgabe erledigt oder stockt zweimal ohne Fortschritt, geht die Restarbeit an einen neuen Sonnet-Agenten. Haiku wird nicht fortgesetzt.
- **Gate vor Meldung:** Ein Implementierer meldet erst, wenn `npm run gate -- fast` im Worktree Exit 0 liefert.
- **Worktree:** Den Worktree legt der Auftraggeber selbst an; der Agent läuft ohne `isolation: worktree` im fertigen Verzeichnis (der Harness sperrt sonst `git`).
