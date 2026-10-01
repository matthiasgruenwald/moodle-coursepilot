# Spec 0023 — Ortswahl als Seitenzustand, Templates statt Handarbeit

*Kandidat C aus dem Architektur-Review vom 18./19.09.2026. Tracking-Issue: [#532](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/532). Setzt [#530](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/530) (Spec 0021) voraus.*

> **Umgesetzt wird gegen das Issue, nicht gegen dieses Dokument.** Das Issue trägt die
> verbindliche Form — User Stories und Abnahmekriterien als Haken. Dieses Dokument
> beantwortet das *Warum* und ist Nachschlagewerk, keine zweite Anforderungsquelle.

## Problem Statement

Die **Ortswahl** ist die Seite, auf der eine Lehrkraft entscheidet, wo **Kontextbereich** und
**Materialbestand** liegen. Sie ist die einzige Stelle, an der diese Entscheidung fällt —
nie im Chat, nie in einem Zustimmungsdialog. Entsprechend wichtig ist, dass sie verlässlich
funktioniert und sich prüfen lässt.

Heute entsteht ihr HTML von Hand über drei Dateien, die Seitenlogik steckt zusammen mit dem
Schreiben des Kontextpointers und dem Blättern im WebDAV-Speicher in einem Modul, und das
JavaScript baut Inhalte aus zusammengesetzten Zeichenketten. Geprüft werden kann davon fast
nichts: Ein einziger Test deckt eine einzige Darstellungsfunktion ab. Die Fehler der letzten
Wochen lagen genau hier — Blättern, Fehleranzeige, Übergabe gefüllter Ordner.

Für die Veröffentlichung kommt hinzu: Der Marketplace prüft eingereichte Plugins
automatisch, und diese Prüfung erwartet Moodles Standardwege — Vorlagen statt
zusammengebautes HTML, ein AMD-Modul statt eines freien Skripts.

## Solution

Die Ortswahl liefert einen **Seitenzustand**: ein reines Datenobjekt, das beschreibt, was
die Lehrkraft sieht — gewählter Ort je Bereich, Zustand der WebDAV-Freischaltung, Inhalt der
aktuellen Ebene beim Blättern, Sperren, Hinweise, Fehlertexte, **Altbestand** und
**Ortsverlauf**. Vorlagen rendern diesen Zustand, ein AMD-Modul bedient die Seite.

Das Schreiben des Kontextpointers verlässt die Seitenlogik und läuft über den Anker aus
Spec 0021.

## Implementation Decisions

- **Der Seitenzustand ist die Schnittstelle.** Er enthält keine Auszeichnung, keine
  übersetzten Sätze aus dem Code, sondern benannte Zustände und Schlüssel; die Übersetzung
  geschieht beim Rendern.
- **Vorlagen für jede Ansicht** der Ortswahl, des Blätterns, der Verbindungsübersicht, des
  Änderungsverlaufs und der Statusseite. Die handgebauten Ausgaben entfallen, die globalen
  Darstellungsfunktionen verschwinden.
- **Ein AMD-Modul statt eines freien Skripts.** Kein Zusammenbauen von Auszeichnung aus
  Zeichenketten; Teilansichten kommen aus Vorlagen. Die Konfiguration erreicht das Modul auf
  Moodles Standardweg statt über eingebettete Daten.
- **Das Schreiben des Pointers geht über den Anker** (Spec 0021). Die Ortswahl liest und
  schreibt nicht selbst an der Ablage vorbei.
- **Das Blättern im externen Speicher bleibt, wo es hingehört:** beim WebDAV-Adapter. Die
  Seite fragt nach einer Ebene und bekommt eine Liste, sie spricht nicht selbst mit dem
  Speicher.
- **Capability-Prüfung auf allen Seiten.** Die Verbindungsübersicht prüft heute nur die
  Anmeldung; jede Seite bekommt die Prüfung, die zu ihr gehört.
- **Veraltete Kernaufrufe entfallen**, insbesondere die überholte Fehlerausgabe im
  Autorisierungsendpunkt.
- **Die Seitenadressen bleiben unverändert**, damit gespeicherte Links weiter funktionieren.

## Testing Decisions

Ein guter Test prüft hier den Seitenzustand, nicht das erzeugte Markup: Welche Orte stehen
zur Wahl, welche sind gesperrt und warum, was zeigt der Ortsverlauf, welcher Fehlertext
erscheint bei einem Ausfall des Speichers, was passiert beim Blättern in einen gefüllten
Ordner.

- **Seam (neu): der Seitenzustand.** Die vorhandenen Ortswahl-Tests ziehen auf ihn um. Sie
  verlieren dabei ihre direkten Zugriffe auf das Pointer-Dokument (Spec 0021) und prüfen
  stattdessen das, was die Lehrkraft sieht.
- **Der Test der Darstellungsfunktion entfällt** — das Rendern übernehmen Vorlagen, deren
  Wohlgeformtheit die automatische Prüfung des Marketplace abdeckt.
- **Playwright-E2E auf Spike.** Die frühere Entscheidung gegen Browser-Tests ist für #532
  ausdrücklich überstimmt: Die isolierte Spike-Testinstanz prüft Wahl in Moodle, Wahl extern,
  WebDAV-Browsing, Übergabe eines gefüllten Ordners und einen benannten Speicherausfall.
  Vorlagen- und Skriptprüfung laufen zusätzlich über `moodle-plugin-ci`.

## Out of Scope

- Neue Funktionen der Ortswahl; der Funktionsumfang bleibt gleich.
- Gestaltung und Layout über das hinaus, was die Umstellung auf Vorlagen ohnehin bedeutet.
- Die Admin-Einstellungsseiten des Plugins.
- Zweisprachigkeit des Skill-Korpus.

## Further Notes

Diese Spec setzt Spec 0021 voraus: Ohne den Anker müsste die Seite ihre Pointer-Schreibung
behalten, und der Umbau wäre zur Hälfte umsonst.

Zwei kleine Befunde aus dem Review hängen hier mit dran, weil sie dieselben Dateien
betreffen: die fehlende Capability-Prüfung der Verbindungsübersicht und die veraltete
Fehlerausgabe im Autorisierungsendpunkt.
