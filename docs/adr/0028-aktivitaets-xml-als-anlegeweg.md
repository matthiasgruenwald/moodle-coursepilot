# ADR 0028 — Aktivitäts-XML als Anlegeweg für erschlossene Aktivitätsarten

Status: angenommen (01.10.2026)

## Context

Coursepilot schreibt Aktivitäten über den Formularweg und einen geprüften Feldkatalog je
Aktivitätsart (ADR 0016, ADR 0017). Das deckt neun Arten ab. Lehrkräfte wollen weitere Arten
nutzen (etwa book, checklist, glossary), ohne den Moodle-Import selbst zu bedienen. Einen
Feldkatalog für jede installierte Art zu pflegen, ist nicht leistbar.

ADR 0016 hat Backup/Restore als **Schreibweg** verworfen, weil Restore ausnahmslos neu
anlegt und damit nichts ändern kann. Das Anlegen war nie verworfen. Der Prototyp
`prototype/mbz-restore` (Issue #585) hat gezeigt:

- Eine von der KI gebaute `<mod>.xml` lässt sich wiederherstellen. Das Gerüst des Backups
  (16 Dateien) erzeugt das Plugin.
- Restore ist nicht atomar und schluckt Feldfehler still.
- Nutzerdaten-Inhalte (z. B. Glossar-Einträge) gehen über diesen Weg nicht.
- Eine Aktivität jeder Art lässt sich mit Moodle-Standardwerten anlegen, ohne Code je Art.

## Decision

**Aktivitäts-XML ist ein zugelassener Anlegeweg, nie ein Bearbeitungsweg.** Er gilt nur für
*erschlossene* Aktivitätsarten (ohne Feldkatalog). Für *katalogisierte* Arten bleibt er
gesperrt; dort gilt weiter ADR 0016.

- **Keine Positivliste.** Zugelassen ist jede installierte Art ohne Feldkatalog, außer Arten
  mit Fragen (lesson, quiz) und Arten mit Dateien im Inhalt. Letztere sind nur vorläufig
  gesperrt (siehe Nachtrag 2026-10-02).
- **Round-Trip-Prüfung statt Zusage.** Nach dem Anlegen exportiert das Plugin die Aktivität
  und prüft Eingabe ⊆ Ausgabe. Ids, `time*`, contextid und Datei-Verweise bleiben
  unberücksichtigt. Moodle-Vorbelegungen gehen als Hinweis zurück.
- **Fehlanlagen werden gelöscht.** Das Plugin legt intern versteckt an. Besteht die Prüfung
  nicht, löscht es die Aktivität im selben Aufruf. Besteht sie, schaltet es sichtbar, außer
  die Lehrkraft verlangt ausdrücklich „versteckt“. Das Verbot, zu löschen, schützt nur den
  Bestand der Lehrkraft, nicht eine eigene, nie sichtbare Fehlanlage.
- **Ablösen statt Ändern.** Eine Aktivität mit Nutzerdaten wird nicht überschrieben. Die neue
  kommt direkt hinter die alte, die alte wird nur versteckt. Überschreiben mit Sicherung
  („Ersetzen“) ist eine spätere Entscheidung.
- **Änderungsverlauf.** Restore löst kein `course_module_created` aus. Das Plugin schreibt den
  ersten Stand selbst, wie `clone_activity` (ADR 0018). Beim Ablösen vermerkt der Verlauf der
  alten Kursmodul-ID „abgelöst durch cmid X“.
- **Wissen bei der Lehrkraft.** Was über eine Art gelernt wird, steht in der
  Aktivitätsart-Ablage im Kontextbereich, nicht im Plugin.

## Considered Options

- **Feldkatalog für jede gewünschte Art:** verworfen. Jede Art kostet Katalog, Driftprüfung
  und Pflege je Moodle-Version (ADR 0017), auch für Arten, die nur wenige Lehrkräfte nutzen.
- **Lehrkraft importiert selbst über Moodle:** verworfen. Genau diesen Umweg soll Coursepilot
  ersparen.
- **Backup-XML auch zum Bearbeiten:** bleibt verworfen (ADR 0016). Restore legt immer neu an.

## Consequences

- Coursepilot kann Arten anlegen, für die es nichts zusagt. Die Grenze „unterstützt“ bleibt
  der Feldkatalog. Erschlossene Arten heißen nie „unterstützt“.
- Neue Werkzeuge: `create_activity_from_xml`, `export_activity_backup`,
  `export_default_activity`. Sie brauchen die Restore- bzw. Backup-Capabilities, praktisch
  also `editingteacher`.
- Der Formularweg bleibt für katalogisierte Arten unverändert. Der Verlauf kennt einen
  weiteren Schreibweg und schreibt dort ausdrücklich mit.
- Ergänzt ADR 0016, ohne sie aufzuheben.

## Nachtrag 2026-10-02: Arten mit Dateien im Inhalt

Entscheidung der Lehrkraft nach der Umsetzung (#585):

- **Kein dauerhafter Ausschluss.** `scorm`, `imscp`, `h5pactivity` und `lightboxgallery`
  sollen über einen **Datei-Nachtrag** erschlossen werden (#598): Nach dem Round-Trip und vor
  dem Sichtbarschalten füllt der Server die Dateibereiche aus Materialpfaden.
- **Dateiinhalte gehören grundsätzlich nicht in den KI-Kontext.** Regelweg: Die KI nennt
  Pfade, der Server kopiert (`material_files::resolve_into_draft`). Paketdateien sind
  zulässig, solange sie ohne Laden der Dateiinhalte in den Kontext entstehen oder übernommen
  werden.
- **Ausnahme nur nach deutlicher Rückfrage.** Geht es für die Lehrkraft nicht anders, darf die
  KI Dateiinhalte in den Kontext laden. Vorher nennt sie die Kosten transparent (voller
  Kontext, deutlich höhere KI-Kosten, ungefähre Größe) und wartet auf eine ausdrückliche
  Zustimmung. Ohne Zustimmung bleibt es beim Regelweg oder die Aktivität wird nicht angelegt.
- **Erschlossen heißt vollständig.** Eine Art gilt erst als erschlossen, wenn auch ihre Dateien
  und die zugehörigen Texte mitkommen. Für die Lightboxgallery heißt das: Bilder samt
  Bildunterschriften (#599). Bis dahin bleiben die vier Arten mit dem Grund „Datei-Nachtrag
  fehlt“ gesperrt.


## Nachtrag 2026-10-04: Verifizierte Aktivitätsart-Vorlagen (#603)

- **Mitgeliefertes Wissen ist eine Plugin-Zusage.** Für Buch (`book`), Checkliste
  (`checklist`) und Glossar (`glossary`) liefert das Plugin geprüfte Minimal-Beispiele,
  Pflichtstruktur, Stolpersteine und den Moodle-Verifikationsstand. Die Zusage gilt für
  diese Beispiele auf der genannten Version; jede konkrete Anlage durchläuft weiterhin
  den Round-Trip. „Unterstützt“ bleibt die Bezeichnung für katalogisierte Arten.
- **Ortswahl ergänzt fehlende Dateien.** Nach erfolgreicher Wahl oder Bestätigung des
  Kontextorts werden die Ressourcen aus `activity-types/` im Plugin unter demselben
  relativen Pfad im gewählten Kontextbereich abgelegt. Nur tatsächlich angelegte Dateien
  ergeben einen Hinweis. Vorhandene Dateien werden nie verglichen, aktualisiert oder
  überschrieben; wiederholte Wahl erzeugt keine Schreibzugriffe und keinen Vorlagenhinweis.
- **Ausfälle sind kein Ortswahlfehler.** Vorab-Lesefehler, fehlende Schreibrechte, Quote,
  Locks und Ablagefehler werden je Datei abgefangen. Andere fehlende Dateien können
  weiterhin ergänzt werden. Der gewöhnliche Schreibpfad behält seine Ausstandsnotizen;
  es gibt keinen Rückfall auf einen anderen Ort. Konkurrierendes Anlegen bleibt durch
  bedingte Writes geschützt (WebDAV `If-None-Match: *`, Private Files eindeutiger Dateisatz).
- **Nachweis statt erfundener Beispiele.** Grundlage sind die über den externen
  teacher_edit-Kontext lesend gesicherten Spike-Vorlagen aus #592, verifiziert auf
  Moodle 5.1.7+ (Build 20260928) am 01.10.2026. Die englischen Beispiele werden auf
  derselben Moodle-Version isoliert erneut angelegt und exportiert; die öffentliche
  Ortswahl liefert genau die anschließend geprüften Ressourcen.
- **Glossar bleibt zweistufig.** Die XML enthält keine Einträge. Danach fügt
  `coursepilot_add_glossary_entries` Lehrerinhalt hinzu (#593). Die Vorlage nennt diesen
  Weg, die Rechte, Teilfehler und die Grenzen des Änderungsverlaufs.
- **Keine erfundene Lightboxgallery-Zusage.** Der Datei-Nachtrag aus #598/#599 allein
  ersetzt keine verifizierte Aktivitätsart-Datei. Lightboxgallery wird hier nicht
  mitgeliefert. Neue Vorlagen und Neu-Verifikation je Moodle-Version bleiben eigene Arbeit.

Dieser Nachtrag ersetzt die pauschalen Aussagen „Wissen nicht im Plugin“ und
„keine Zusage“ für die ausdrücklich mitgelieferten, verifizierten Beispiele.
