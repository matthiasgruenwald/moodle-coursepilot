# Spec 0021 — Ablage hinter einem Anker: ein Modul, zwei Adapter

*Kandidat A aus dem Architektur-Review vom 18./19.09.2026. Tracking-Issue: [#530](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/530).*

> **Umgesetzt wird gegen das Issue, nicht gegen dieses Dokument.** Das Issue trägt die
> verbindliche Form — User Stories und Abnahmekriterien als Haken. Dieses Dokument
> beantwortet das *Warum* und ist Nachschlagewerk, keine zweite Anforderungsquelle.

## Problem Statement

Eine Lehrkraft legt fest, wo **Kontextbereich** und **Materialbestand** liegen: in Moodles
Private Files oder in ihrem eigenen WebDAV-Speicher. Diese Wahl soll für die tägliche Arbeit
unsichtbar sein. Tatsächlich ist sie es nicht:

- Schlägt ein Schreibvorgang fehl, hängt die Fehlermeldung davon ab, welcher Ort gewählt ist.
  Manche Fälle fangen die Werkzeuge ab, andere reichen sie roh an die KI durch.
- Beim Auflisten unterscheiden sich die Felder je nach Ort, weil externe Einträge eine
  Prüfsumme mitführen und interne nicht.
- Das Nachtragen eines **Ausstands** greift nur am externen Ort, weil der Schreibweg für
  Private Files an einer anderen Stelle liegt.

Für die Lehrkraft heißt das: Dieselbe Bitte an die KI verhält sich unterschiedlich, je
nachdem, wo ihre Dateien liegen — und sie hat keine Möglichkeit, das zu erkennen oder zu
beeinflussen. `CONTEXT.md` schließt genau das aus: Der Begriff Kontextbereich ist
ortsunabhängig, Skills und Plugin-Code beziehen sich nie auf einen bestimmten Speicherort.

Für die Entwicklung heißt es: 33 der letzten 40 Änderungen am Plugin lagen in diesem
Bereich, und jede musste an mehreren Stellen gleichzeitig gedacht werden.

## Solution

Die Ablage bekommt **ein** tiefes Modul, den **Anker**. Er beantwortet alle Fragen, die ein
Werkzeug an die Ablage stellt: lesen, schreiben, anhängen, auflisten, löschen — jeweils für
einen **Bereich** (Kontextbereich oder Materialbestand) und einen relativen Pfad, mit einem
Prüfwert für bedingtes Schreiben.

Hinter dem Anker sitzen zwei **Adapter**: Moodles Private Files und WebDAV. Welcher greift,
entscheidet der **Kontextpointer**; die Werkzeuge erfahren es nicht. Was heute zwischen
Werkzeugen, Anker, Pointer-Leser und Pointer-Schreiber verteilt ist — Pfadprüfung,
Quotenprüfung, **zugelassener Speicher**, Schreibchoreografie mit Zwischendatei,
**Ausstandsnotiz** und **Nachtragen** — liegt danach im Anker.

Die Werkzeugantworten werden dadurch ortsneutral: gleiche Felder, gleiche Fehlerschlüssel,
gleiches Verhalten bei Konflikt und Ausfall, unabhängig vom Ort.

## Implementation Decisions

- **Ein Anker-Modul mit einer Schnittstelle für beide Bereiche.** Ein Bereich bleibt ein
  reiner Wertesatz (Wurzel-Einstellung, Standardwurzel, Namensregel beim Schreiben,
  Fehlerschlüssel) — kein eigener Typ, keine Klassenhierarchie. Das ist die Linie aus
  ADR 0020 und bleibt gültig.
- **Zwei Adapter hinter einer gemeinsamen Schnittstelle.** ADR 0020 hatte einen solchen
  Ortsadapter als spekulative Verallgemeinerung abgelehnt — damals zu Recht, weil es nur
  eine Speicherform gab. Mit ADR 0022 existiert die zweite. Die Entscheidung wird deshalb
  fortgeschrieben, nicht umgestoßen: Zwei Adapter rechtfertigen die Trennstelle, ein dritter
  ist nicht vorwegzunehmen.
- **Die Werkzeuge verlieren jede Ortsverzweigung.** Keine Verzweigung auf Fehlerschlüssel,
  keine Abfrage einer Ortsart, keine Erkennung am Vorhandensein einer Prüfsumme. Ein
  Werkzeug nennt Bereich, Pfad, Inhalt und optional den gelesenen Prüfwert.
- **Der Schreibweg ist für beide Orte derselbe.** Auch die Private-Files-Schreibung läuft
  über den Anker; sie liegt nicht länger in jedem Werkzeug einzeln.
- **Auflisten liefert für beide Orte denselben Feldsatz.** Ein ortsspezifischer Prüfwert
  wird entweder für beide Orte geliefert oder für keinen; er ist kein Erkennungsmerkmal.
- **Ausstandsnotiz und Nachtragen wandern nach innen.** Scheitert gültiger Inhalt am
  Speicher, vermerkt der Anker das im selben Aufruf und gibt erst dann den Fehler zurück
  (ADR 0023, unverändert). Ein erfolgreiches Schreiben mit Ausstandskennung hakt den Eintrag
  im selben Aufruf ab.
- **Kein Rückfall.** ADR 0023 gilt weiter: Schreibt der externe Speicher nicht, landet der
  Inhalt nirgendwo anders.
- **Isolierung bleibt am Instanzeigentum.** ADR 0021 gilt unverändert: Die
  Repository-Nutzerinstanz kommt aus dem Pointer, nie aus einer Client-Eingabe, und ihr
  Kontext muss der Nutzerkontext des Token-Inhabers sein. Diese Prüfung liegt künftig an
  genau einer Stelle.
- **Der Transport wird injiziert.** Der statische Testhaken im Produktivcode entfällt; der
  WebDAV-Adapter bekommt seinen Transport übergeben.
- **Die bestehenden Bereichsklassen bleiben als Name erhalten**, soweit Aufrufer sie noch
  nennen, und delegieren vollständig. Reine Weiterleitungsmethoden ohne eigene Aussage
  entfallen.

## Testing Decisions

Ein guter Test beschreibt hier beobachtbares Verhalten an der Werkzeugoberfläche: Was
bekommt die KI zurück, wenn eine Datei geschrieben, angehängt, gelesen, aufgelistet wird —
und was passiert bei Konflikt, Ausfall, zu großer Datei, unzulässigem Pfad. Kein Test
greift auf interne Pointer-Dokumente zu.

- **Primäre Seam (bestehend): die externen Werkzeuge.** Die vorhandenen Tests zu Schreiben,
  Anhängen, Lesen und Auflisten des Kontextbereichs sowie zu den Materialwegen bleiben die
  Abnahme. Neu ist ihre Aussage: Dieselbe Anfrage liefert dieselbe Antwort, unabhängig vom
  Ort. Dafür laufen sie gegen beide Orte.
- **Neue Seam: der Ablage-Vertrag.** Eine einzige Vertragssuite beschreibt, was ein Adapter
  leisten muss, und läuft gegen beide — Private Files echt, WebDAV über den vorhandenen
  Fake-Transport, der künftig injiziert statt statisch gesetzt wird. Prior Art sind die
  bestehenden Katalog-Vertragstests, die dasselbe Muster je Modultyp anwenden.
- **Ersetzt, nicht ergänzt:** Die heutigen Tests des Ankers und der Bereichsklassen gehen in
  der Vertragssuite auf. Die Ortswahl-Tests verlieren ihre direkten Zugriffe auf das
  Pointer-Dokument; sie prüfen künftig über die Schnittstelle.
- **Regressionsschutz für den Ausfall:** Die Prüfungen aus der Ausstands-Kette bleiben
  vollständig erhalten, insbesondere dass ein Ausfall vor dem Schreibversuch abgefangen und
  nie roh durchgereicht wird.

## Out of Scope

- Ein dritter Ablageort (eigenes Repository-Plugin, Cloud-Anbieter ohne WebDAV).
- Änderungen an der Ortswahl-Oberfläche — das ist Spec 0023.
- Der Umzug des **Skill-Korpus** oder anderer Plugin-eigener Dateien in den Anker.
- Verschlüsselung der OAuth-Tokens; das ist ein eigener Sicherheitsfix.

## Further Notes

Der Umbau fasst den Schreibweg der Arbeitsdateien an. Der Produktivbetrieb beginnt
**bewusst erst danach** — deshalb kann dieser Umbau ohne Rücksicht auf laufenden Unterricht
gefahren werden und steht als erster in der Reihe. Unverändert gilt: vor Schema- oder
Schreibwegänderungen ein Snapshot der Instanz, und die Abnahme läuft zusätzlich in einer
echten Sitzung, nicht nur über die Testsuite.

Der Report zum Architektur-Review nennt durchgehend die alten Namen `local_kurspilot` und
`kurspilot_*`; seit ADR 0024 heißen Komponente und Werkzeuge `local_coursepilot` und
`coursepilot_*`.
