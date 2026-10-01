---
name: aktivitaetsarten
description: Lies diese Datei, wenn Coursepilot eine Aktivität einer Art anlegen soll, die keinen Feldkatalog hat (etwa Buch, Checkliste, Glossar), oder wenn die Lehrkraft eine solche Aktivität durch eine neue Fassung ablösen will.
---

# Referenz: Aktivitätsart-Ablage und Lernschleife

Grundlage: Spec 0026 und ADR 0028
(`docs/specs/0026-erschlossene-aktivitaetsarten.md`). Setzt den Kontextbereich
aus `coursepilot_get_skill("kontextbereich")` voraus (Werkzeuge,
Schreibangebot, Handänderungs-Routine) — hier steht nur, was für
Aktivitätsarten ohne Feldkatalog zusätzlich gilt. Der Aufbau folgt
`coursepilot_get_skill("fragetypen")`.

## Welche Art ist es?

Eine Aktivitätsart ist genau eine von drei:

| Art | Weg |
|---|---|
| **katalogisiert** (hat einen Feldkatalog, z. B. `page`, `label`, `assign`, `url`) | `coursepilot_create_module`. Dieser Referenzteil gilt nicht. |
| **erschlossen** (installiert, ohne Feldkatalog, z. B. `book`, `checklist`, `glossary`) | Dieser Referenzteil: anlegen aus Aktivitäts-XML. |
| **ausgeschlossen** (`lesson`, `quiz`, Arten mit Dateien im Inhalt, Arten ohne Moodle-Backup) | Coursepilot legt sie nicht an. Das Werkzeug nennt den Grund; gib ihn der Lehrkraft weiter und schlage vor, die Aktivität in Moodle selbst anzulegen. |

`coursepilot_create_activity_from_xml` und `coursepilot_export_default_activity`
lehnen katalogisierte und ausgeschlossene Arten selbst ab und nennen den Weg.
Das Werkzeug entscheidet, nicht du aus dem Gedächtnis.

## Sprache zur Lehrkraft

Der Vorgang heißt zur Lehrkraft **„anlegen“**. „Unterstützt“ gehört dem
Feldkatalog; für erschlossene Arten sagt Coursepilot nichts zu und prüft nur
nach dem Anlegen, ob Moodle gespeichert hat, was gebaut wurde. Sag etwa: „Ein
Buch kenne ich noch nicht fest. Ich probiere das Anlegen aus und prüfe danach,
ob Moodle alles so gespeichert hat.“

Anlegen erzeugt immer eine neue Aktivität. Eine geänderte Fassung einer
bestehenden Aktivität entsteht als neue Aktivität, die die alte ablöst (siehe
„Ablösen“).

## Die Aktivitätsart-Ablage

Was beim Anlegen gelernt wird, landet als gewöhnliche Kontextdatei im Bereich
der Lehrkraft, fester Pfad **relativ zur Kontextwurzel** (siehe „Ablageordnung“
in `coursepilot_get_skill("kontextbereich")`):

```
aktivitaetsarten/<modname>.md
```

`<modname>` ist der Moodle-Kurzname der Art (`book`, `checklist`, `glossary`).
Eine Datei je Art; die Ablage hält Wissen fest und ist keine Klonvorlage.

### Verbindliche Gliederung

| Abschnitt | Inhalt |
|---|---|
| **Kopf** | Art (`modname`), Moodle-Release, Plugin-Release und -Version, zuletzt verifiziert am |
| **Minimal-Beispiel** | wortgleich die Aktivitäts-XML, die tatsächlich angelegt wurde |
| **Pflichtstruktur** | was fehlen darf und was nicht |
| **Stolpersteine** | je Eintrag: Symptom → Ursache → Abhilfe |

Der Kopf ist die Verfallsanzeige. Hole die Angaben **vor dem Schreiben** mit
`coursepilot_get_version_info` und trage sie im Klartext ein
(Moodle-Release, Plugin-Release, Plugin-Version), immer mit konkreten Werten.

### Das Minimal-Beispiel ist ein Beleg, keine Skizze

Abgelegt wird **wortgleich die XML, die `coursepilot_create_activity_from_xml`
ohne Abweichung angelegt hat**, mit `<?xml …?>`-Zeile und `<activity>`-Rahmen.
Eine gekürzte, umgebaute oder rekonstruierte Fassung kommt erst in die Datei,
wenn *sie selbst* angelegt wurde und den Round-Trip bestanden hat. Eine
Ablage, die ungeprüftes Wissen konserviert, ist schlechter als keine.

### Schreibregel

Geschrieben wird mit `coursepilot_write_context_file` samt
`expected_contenthash` (Vollersatz mit Konfliktschutz), nicht mit
`coursepilot_append_context_file`: Neues Wissen wird in den passenden Abschnitt
eingeordnet, nicht ans Ende gehängt.

Geschrieben wird nur auf Schreibangebot, nie automatisch: Nach dem ersten
erfolgreichen Anlegen einer neuen Art fragt Coursepilot, ob das Gelernte
festgehalten werden soll. Erst nach Bestätigung wird geschrieben.

## Die Lernschleife

Jeder Schritt wird der Lehrkraft in einem Satz gesagt: eine erschlossene Art
wird angesagt, je Versuch wird berichtet, was fehlschlug und was korrigiert
wurde. Die Stufen mit **Ja/Nein** stehen fest, bevor der nächste Schritt
beginnt.

1. **Ablage lesen.** Gibt es `aktivitaetsarten/<modname>.md`
   (`coursepilot_read_context_file`, mit Handänderungs-Prüfung)?
   **Ja:** weiter mit 2. **Nein:** weiter mit 3.
2. **Kopf abgleichen.** Stimmen Moodle-Release und Plugin-Version im Kopf mit
   `coursepilot_get_version_info` überein? **Ja:** weiter mit 4.
   **Nein:** als Widerspruch melden (siehe unten), dann weiter mit 4; die
   Ablage gilt nur noch als Hinweis.
3. **Muster beschaffen.** Gibt es in den Kursen der Lehrkraft schon eine
   Aktivität dieser Art, liefert `coursepilot_export_activity_backup`
   (`cmid`) ihre Aktivitäts-XML und zeigt, wie sie gebaut ist. Sonst liefert
   `coursepilot_export_default_activity` (`courseid`, `modname`) eine
   Muster-XML mit Moodle-Standardwerten; das Werkzeug legt dafür kurz an und
   entfernt wieder, im Kurs bleibt nichts zurück.
4. **Bauen.** Aus Ablage oder Muster die Aktivitäts-XML mit dem Inhalt der
   Lehrkraft bauen. Feldnamen, Reihenfolge und Wertebereiche stammen aus dem
   Muster.
5. **Anlegen und Round-Trip.** `coursepilot_create_activity_from_xml`
   (`courseid`, `modname`, `section`, `activity_xml`, optional `hidden`,
   `replaces_cmid`, `dry_run`). Das Plugin legt intern versteckt an, exportiert
   und prüft, ob alles Gebaute in Moodle angekommen ist. **Bestanden?**
   **Ja:** `cmid` nennen; `presets` (Felder, die Moodle selbst ergänzt hat)
   sind ein Hinweis, kein Fehler; weiter mit 7. **Nein:** Das Plugin hat die
   Anlage im selben Aufruf entfernt, im Kurs und im Papierkorb liegt nichts;
   die Meldung nennt die Abweichung und ist der Lernstoff für Schritt 6.
6. **Korrigieren, höchstens dreimal.** Die Abweichung gezielt beheben, zurück
   zu 5. Nach der dritten Korrektur ohne Erfolg endet das Probieren: Bitte die
   Lehrkraft ausdrücklich, eine solche Aktivität einmal selbst in Moodle
   anzulegen und die `cmid` zu nennen; daraus liefert
   `coursepilot_export_activity_backup` die Vorlage dieser Moodle-Version.
7. **Schreibangebot.** Gibt es etwas festzuhalten (**Ja/Nein**)? **Ja**, wenn
   die Art noch keine Ablage hat oder Korrekturen oder ein Widerspruch etwas
   Neues gezeigt haben: Schreibangebot machen (siehe „Schreibregel“).

### Widerspruchsprüfung

Zwei Fälle zählen als Widerspruch und werden ausdrücklich gemeldet:

- **Versionsabweichung** (Schritt 2).
- **Verhaltensabweichung:** Eine in der Ablage dokumentierte Regel stimmt
  nicht mehr, oder ein Fehler tritt auf, den die Datei ausschließt.

Coursepilot bietet an, den betroffenen Abschnitt zu überarbeiten, mit
Ursachenvermutung und aktualisiertem Kopf, und arbeitet bis dahin mit dem
Muster aus Schritt 3.

## Ablösen statt Ändern

Soll eine bestehende Aktivität einer erschlossenen Art „geändert“ werden:

1. Frage die Lehrkraft, ob die neue Fassung die alte **ablösen** soll (die
   alte bleibt versteckt erhalten, mit allen Daten der Lernenden).
2. Rufe `coursepilot_create_activity_from_xml` mit `replaces_cmid` und
   `dry_run: true` auf. Es schreibt nichts und liefert nur `references`:
   Stellen, die auf die alte Aktivität zeigen (Voraussetzungen,
   Kursabschluss-Kriterien).
3. Nenne der Lehrkraft diese Verweise. Coursepilot löst sie nicht auf; sie
   werden von Hand auf die neue Aktivität umgestellt.
4. Nach Freigabe: derselbe Aufruf ohne `dry_run`. Die neue Aktivität steht
   direkt hinter der alten, die alte ist nur versteckt (Titel bleibt, nichts
   gelöscht). `section` entfällt dabei; Art und Kurs müssen gleich sein.

## Nutzerdaten-Inhalte

Die Aktivitäts-XML trägt Einstellungen und den Inhalt, den die Lehrkraft
gestaltet (Kapitel, Checklisten-Punkte). Nutzerdaten-Inhalte besteht der
Round-Trip nicht; die Anlage wird dann wie in Schritt 5 entfernt.

**Stolperstein `glossary`: wird leer angelegt.** Glossar-Einträge gehen über
die Aktivitäts-XML nicht mit; eine XML mit `<entry>` besteht den Round-Trip
nicht. Lege das Glossar ohne Einträge an und sage der Lehrkraft vorher, dass
sie die Einträge in Moodle selbst anlegt. Gehört in die Ablage
`aktivitaetsarten/glossary.md`.
