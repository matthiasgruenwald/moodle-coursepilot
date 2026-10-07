# Domain Docs

Wie Engineering-Skills die Domain-Doku dieses Repos lesen sollen.

## Vor dem Explorieren lesen

- **`CONTEXT.md`** im Repo-Root
- **`docs/adr/`** — ADRs lesen, die den betroffenen Bereich betreffen

Single-context Repo, kein `CONTEXT-MAP.md`.

## File-Struktur

```
/
├── CONTEXT.md
├── docs/adr/
│   ├── 0001-use-native-moodle-question-versioning.md
│   ├── 0002-use-an-igs-fork-as-training-version.md
│   └── 0003-allow-local-student-names-in-teacher-context.md
├── docs/specs/
└── Plugin/src/local_coursepilot/
```

## Glossar-Vokabular nutzen

Begriffe aus `CONTEXT.md` (z.B. Unterrichtseinheit, Unterthema, Bestaetigte Vorschau, Freigegebener Implementierungsplan) in Issue-Titeln, Tests und Vorschlaegen verwenden. Nicht auf Synonyme wie "Lernsituation" ausweichen — das Glossar markiert das explizit als `_Avoid_`.

## ADR-Konflikte melden

Widerspricht ein Vorschlag einer bestehenden ADR, das offen benennen statt still zu ueberschreiben.
