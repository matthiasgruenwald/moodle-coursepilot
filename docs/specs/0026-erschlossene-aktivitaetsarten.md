# Spec 0026 — Erschlossene Aktivitätsarten anlegen

*Ergebnis des Grillings zu [#585](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/585) (01.10.2026). Entscheidung: ADR 0028.*

> **Umgesetzt wird gegen die Issues, nicht gegen dieses Dokument.** Die Issues tragen die
> verbindliche Form — User Stories und Abnahmekriterien als Haken. Dieses Dokument
> beantwortet das *Warum* und ist Nachschlagewerk, keine zweite Anforderungsquelle.

## Problem Statement

Coursepilot schreibt nur Aktivitätsarten mit geprüftem Feldkatalog. Will eine Lehrkraft ein
Buch, eine Checkliste oder ein Glossar, muss sie es selbst anlegen oder über den
Moodle-Import gehen. Für jede dieser Arten einen Feldkatalog zu pflegen, lohnt sich nicht.

## Solution

Coursepilot erschließt sich solche Arten selbst, wie bei der Fragetyp-Ablage: Die KI baut
eine **Aktivitäts-XML** (`<mod>.xml`), das Plugin ergänzt das Backup-Gerüst, legt an und
prüft per Round-Trip. Was dabei gelernt wird, steht in der **Aktivitätsart-Ablage**
(`activity-types/<modname>.md`) im Kontextbereich der Lehrkraft.

Begriffe: `CONTEXT.md` — Katalogisierte/Erschlossene Aktivitätsart, Aktivitätsart-Ablage,
Anlegen aus XML, Aktivitäts-XML, Ablösen.

## Implementation Decisions

### Werkzeuge (englisch, ADR 0024)

- `export_activity_backup(cmid)` liefert die Aktivitäts-XML einer bestehenden Aktivität.
  Es dient dem Bestand („wie ist das gebaut?“). Es ist ein **lesendes** Werkzeug, deshalb
  braucht `tool_registry::is_write_class` eine Ausnahme: Das Präfix `export_` gilt heute
  pauschal als schreibend.
- `export_default_activity(courseid, modname)` legt eine Aktivität mit
  Moodle-Standardwerten an (Formular-Vorbelegung → `add_moduleinfo`), exportiert sie und
  räumt sie wieder auf (Aktivität löschen, Papierkorb-Einträge entfernen, Verlauf fällt mit
  der Lösch-Kaskade). Eine Transaktion trägt nicht: Das Backup führt DDL aus, das auf MariaDB
  implizit committet (#589, am Test belegt). Im Kurs bleibt nichts außer den Moodle-Logeinträgen. Das ersetzt in der Lernschleife den Schritt
  „Lehrkraft legt selbst an und exportiert“.
- `create_activity_from_xml(courseid, modname, section, activity_xml, hidden?, replaces_cmid?, dry_run?, files?)`
  legt an. Mit `replaces_cmid` wird die Aktivität abgelöst.

Alle drei Werkzeuge sind dünne Adapter: Sie prüfen Parameter und Rechte, rufen ein Modul
auf und formen die Antwort. Die Logik liegt in den folgenden Modulen, in dieser
Bau-Reihenfolge.

### Modulschnitt

1. **Aktivitäts-Backup** (`activity_backup`). Das Modul hat zwei Operationen:
   - `export(cm)` liefert die Aktivitäts-XML (`<mod>.xml`, ohne Nutzerdaten).
   - `restore(course, section, quelle)` gibt die neue cmid zurück. Die Quelle ist entweder
     ein echtes Backup (für das Klonen) oder eine Aktivitäts-XML. Aus der baut das Modul
     intern das Gerüst (16 Dateien); die Version kommt aus `$CFG`/`backup::VERSION`, nicht
     hart wie im Prototyp.

   Das Modul räumt selbst auf: das Tempdir und eine halbe Anlage, wenn der Restore
   scheitert (Restore ist nicht atomar). `clone_activity` zieht sofort auf dieses Modul um
   (bisher privat inline), und seine bestehenden Tests sichern den Schnitt ab.
2. **Art-Tor** in der Katalog-Registry. `registry::kind(modname)` antwortet mit genau einer
   von drei Arten:
   - *katalogisiert* (mit Katalog),
   - *erschlossen*,
   - *ausgeschlossen* (mit Grund als Sprachschlüssel).

   Ausgeschlossen sind lesson, quiz, Arten mit Dateien im Inhalt ohne deklarierten
   Datei-Nachtrag (`scorm`, `imscp`, `h5pactivity` bleiben gesperrt; `lightboxgallery`
   ist der Pilot von #598/#599), nicht installierte Arten und Arten ohne
   `FEATURE_BACKUP_MOODLE2`. Keine Positivliste. Die fünf verstreuten
   `unknownmodname`-Prüfungen (`create_module`, `set_completion`,
   `restore_activity_version`, `update_module_settings`, `set_restriction`) werden zu einem
   Aufruf `registry::require_catalogued()`. Die neuen Werkzeuge lehnen katalogisierte Arten
   ab und verweisen auf `create_module`.
3. **Anlegen aus XML** (`xml_activity_creator`). Es trägt den ganzen Ablauf mit seinen
   Reihenfolge-Regeln, Ablösen eingeschlossen:
   1. Art-Tor.
   2. XML parsen; ungültige XML schreibt nichts.
   3. Restore über das Aktivitäts-Backup, intern versteckt.
   4. Exportieren und Round-Trip-Vergleich.
   5. Bei Abweichung: im selben Aufruf entfernen (Kursmodul-Platzierung) und die Abweichung
      melden.
   6. Optionaler Datei-Nachtrag: Materialpfade serverseitig in die je Art deklarierten
      Dateibereiche kopieren; Bildunterschriften und native Thumbnails ergänzen.
      Fehler verwirft nur die eigene, noch unsichtbare Anlage. Der Nachtrag läuft nach
      dem Backup-DDL in einer eigenen Transaktion; auch temporäre Entwürfe werden entfernt.
   7. Sonst: platzieren, sichtbar schalten (außer `hidden`), **danach** den ersten Stand im
      Verlauf erfassen. Würde vor dem Sichtbarschalten erfasst, entstünde über
      `course_module_updated` eine Version 2.

   Der **Round-Trip-Vergleich** ist eine reine Funktion: Eingabe-XML und Ausgabe-XML hinein,
   Abweichungen und Moodle-Vorbelegungen heraus. Er prüft Eingabe ⊆ Ausgabe und ignoriert
   ids, `time*`, contextid und Datei-Verweise. Er ist als internes Seam ohne Moodle testbar.
   Der Fragen-Vergleich (`import_questions_xml::find_mismatch`) ist feldspezifisch und wird
   nicht wiederverwendet. Die Fehlermeldung bekommt einen eigenen Sprachschlüssel, denn
   `roundtripmismatch` sagt „zurückgerollt“.

   Für #593 bleibt Platz: Ein späterer Nachtrag von Nutzerdaten-Inhalten käme nach dem
   bestandenen Round-Trip und vor dem Sichtbarschalten. Heute wird dafür kein Seam gebaut,
   denn es gäbe nur einen Adapter (glossary).
4. **Kursmodul-Platzierung**. Drei Operationen über die erlaubten Moodle-Wege:
   - `place_after(cm, after_cm)` über `stateactions::cm_move`; Ziel ist der Nachfolger von
     `after_cm`, sonst das Abschnittsende.
   - `set_visible(cm, bool)` über `cmactions::set_visibility`.
   - `discard_failed(cm)`.

   `move_module` und `clone_activity` ziehen mit um. `discard_failed` ist das einzige
   Löschen im Plugin, und es gilt nur für cmids, die im selben Aufruf entstanden und nie
   sichtbar waren. Es löscht **sofort und ohne Papierkorb**: Eine Fehlanlage funktioniert
   nicht und muss in Moodle nicht aufbewahrt werden; nachvollziehbar bleibt sie im
   Chatverlauf der Lehrkraft. Technisch heißt das: synchron über
   `course_get_format()->delete_module($cm, false)`, nicht asynchron. Ist der Papierkorb
   aktiv, legt sein Hook trotzdem einen Eintrag an, und das Modul entfernt ihn gleich wieder.
   Die Verbotsliste (`no_deprecated_move_functions_test`) bleibt unberührt, weil
   `delete_module` dort nicht steht.
5. **Verlaufsquelle**. Die Quellen (`moodle`, `vorgefunden`, `geklont`, neu `aus_xml` (Code: `from_xml`),
   `abgelöst` (Code: `superseded`)) werden ein Begriff mit Schlüssel, Bezugs-cmid und Beschriftung. Bisher kennen
   `version_writer`, `describe_meta` und `summary_line` jede Quelle einzeln.
   - Ablösen schreibt an die alte cmid einen Vermerk-Stand: Quelle `abgelöst` (Code: `superseded`), Bezug = neue
     cmid, über das vorhandene Feld `sourcecmid`. Das braucht keine Schemaänderung. Ist die
     Umdeutung „Herkunft → Bezug“ nicht tragfähig, kommt ein eigenes Feld mit
     `upgrade.php`-Schritt.
   - `version_history::GAPS_HINT` nennt die Lücke ehrlich: Bei erschlossenen Arten erfasst
     der Verlauf nur die Instanzzeile, nicht die Kindtabellen (Kapitel, Einträge, Punkte).
6. **Verweis-Finder** (`cm_references`). `references_to(cmid)` liefert die Verweise auf eine
   cmid mit Art und Ort:
   - Voraussetzungen an Aktivitäten und Abschnitten (availability),
   - Kursabschluss-Kriterien.

   Die beiden bestehenden Baumläufe (`set_restriction::completion_pairs`,
   `clone_activity::strip_dangling_completion`) werden seine internen Teile. Die Planvorschau
   beim Ablösen nennt die Verweise, aufgelöst werden sie nicht. Das spätere „Ersetzen“ nutzt
   dasselbe Modul.

### Übriges

- **Ablösen** (`replaces_cmid`): Die neue Aktivität kommt direkt hinter die alte; die alte
  wird nur versteckt (Titel bleibt).
  Wiederholtes Überarbeiten läuft als Kette (A → B → C, sichtbar nur die neueste). Zwei
  Hinweise, kein Blockieren (Entscheidung 2026-10-02, #600):
  - Ist die Vorlage schon abgelöst, nennen Planvorschau und Antwort die Nachfolgerin und
    fragen, ob stattdessen sie ersetzt werden soll. Der Aufruf läuft trotzdem durch.
  - Bei jeder Ablösung nennen sie die Zahl der versteckten Vorgänger, mit Aufräum-Hinweis;
    keine Schwelle.
  Ein erneuter Versuch nach einer Fehlanlage braucht keine Sonderbehandlung, weil die
  Fehlanlage nichts hinterlässt.
- **Antwort** von `create_activity_from_xml`: cmid, dazu Moodle-Vorbelegungen als Hinweis,
  also Felder, die Moodle ergänzt hat, ohne dass sie in der Eingabe standen.
- **Nutzerdaten-Inhalte** gehen nicht mit, weil `MODE_IMPORT` `users=0` erzwingt. Ein
  Glossar wird leer angelegt; das steht als Stolperstein in der Ablage.
- **Rechte:** Restore- bzw. Backup-Capabilities im Kurs, praktisch `editingteacher`. Je
  Werkzeug eine Deklaration (Spec 0022) plus Erwähnung in `skills/reference/mcp-tools.md`
  (Korpus-Test), danach `version.php` anheben und `upgrade.php` ausführen.
- **Skill:** `Plugin/src/local_coursepilot/skills/reference/activity-types.md` nach dem
  Vorbild `reference/question-types.md`. Inhalt:
  - die Gliederung der Ablage,
  - die Lernschleife: Ablage lesen → Bestand oder `export_default_activity` → bauen →
    Round-Trip → höchstens dreimal korrigieren,
  - das Schreibangebot fürs Gelernte.

  Zur Lehrkraft heißt der Vorgang nur „anlegen“. Erschlossene Arten heißen nie
  „unterstützt“.

## Testing Decisions

### Datei-Nachtrag (#598/#599)

`files` ist eine optionale Liste im vorhandenen Werkzeug, kein Folgewerkzeug.
Je Eintrag: `path`, `filearea`, optional `caption` (Klartext, Standard leer) und
`location` (`store` als Standard oder `workbench`). Ohne Liste bleibt der
bestehende Anlegeweg erhalten. `dry_run` liest keine Materialdateien und prüft
deren Inhalt nicht.

Der allgemeine Ablauf kennt nur die Deklaration je Art. Lightboxgallery erlaubt
`gallery_images`, `itemid=0`, Wurzelpfad; Bildunterschriften hängen am Dateinamen.
Darum werden doppelte Basisdateinamen abgewiesen. `lightboxgallery_image` erzeugt
fehlende Thumbnails selbst mit seinem festen Crop und schreibt Captions über
`set_caption()`. Es werden keine Moodle-Nutzerkommentare angelegt. Das Materialrecht
`moodle/user:manageownfiles` und das native `mod/lightboxgallery:addimage` gelten.

Die öffentlichen Tests nutzen das reale Zusatzplugin in einer eigenen Moodle-Instanz:
drei Bilder verschiedener Seitenverhältnisse mit Captions, ein fehlender zweiter Pfad
beim Ablösen (Bestand, Dateien und Papierkorb unverändert), ungültige Zuordnungen/Bilder,
Rechteentzug, `hidden`, `workbench`, leere Liste und nicht installierte Art trotz
vorhandener Quelldateien. Native Abschnittszeitstempel und gewöhnliche Moodle-Logs
können durch Restore/Aufräumen aktualisiert werden. Exakte geprüfte Minimal-XML:
`Plugin/src/local_coursepilot/tests/fixtures/lightboxgallery.xml`.
Spike-Abnahme und verifizierte Ablage im Kontextbereich bleiben ein eigener,
abgestimmt freizugebender Schritt.

- **Jedes Modul wird über sein Interface getestet** (PHPUnit im Spike-Container):
  - Aktivitäts-Backup: Export und Restore aus beiden Quellen; eine halbe Anlage wird
    entfernt. Die bestehenden `clone_activity`-Tests laufen unverändert grün.
  - Art-Tor: alle drei Arten samt Ausschlussgründen. Die fünf Altwerkzeuge verhalten sich
    unverändert.
  - Round-Trip-Vergleich: reine Fälle ohne Datenbank (Teilmenge, ignorierte Felder,
    Vorbelegung, Abweichung).
  - Anlegen aus XML:
    - Erfolg sichtbar oder mit `hidden`.
    - Eine Fehlanlage ist danach weder im Kurs noch im Papierkorb, auch bei aktivem
      Papierkorb.
    - Der Verlauf hat genau einen Stand.
    - Beim Ablösen: Position, die alte Aktivität ist versteckt, der Vermerk-Stand ist da.
  - Kursmodul-Platzierung: `place_after` am Abschnittsende und in der Mitte. Die
    `move_module`-Tests bleiben grün.
  - Verweis-Finder: availability an Aktivität und Abschnitt, Kursabschluss.
- **`export_default_activity` hinterlässt nichts:** keine cm, kein Verlauf, kein
  Papierkorb-Eintrag.
- **Abnahme an book, checklist, glossary** auf der Spike-Instanz, jeweils mit verifiziertem
  Minimal-Beispiel in der Ablage.

## Out of Scope

- Bearbeiten über XML (ADR 0016, 0028).
- „Ersetzen“ (Überschreiben mit Sicherung und Verweisauflösung).
- Nutzerdaten-Inhalte, etwa Glossar-Einträge — eigenes Ticket, seither umgesetzt in #593 (`add_glossary_entries`).
- Textlinks (`view.php?id=`) im Verweis-Finder — erst bei Bedarf.
- lesson und quiz.
- Die Paketarten `scorm`, `imscp`, `h5pactivity` bleiben für Folge-Issues gesperrt.
  Lightboxgallery mit Datei-Nachtrag und Bildunterschriften gehört zu #598/#599.
