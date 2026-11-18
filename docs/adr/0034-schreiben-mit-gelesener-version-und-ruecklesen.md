# ADR 0034 — Schreiben mit gelesener Version, Rücklesen und Journalbindung

Status: angenommen (Spec 0030). Übernommen aus E1 des moocp-contextmanager.

## Kontext

`write_context_file` ersetzte eine vorhandene Datei auch ohne `expected_contenthash`; dann galt
der Stand des Vorab-Lesens im selben Aufruf, also eine Datei, die die KI nie gesehen hat. Ob der
Inhalt danach so dasteht, sagte keine Antwort. Journale waren nur per Skill-Regel vor dem
Ersetzen geschützt.

## Entscheidung

1. **Ersetzen nur mit Version.** Eine vorhandene Datei ersetzt `write_context_file` nur mit
   `expected_contenthash`. Ohne ihn legt es nur an; gibt es die Datei, scheitert der Aufruf mit
   `contextfileversionrequired` (nächster Schritt: lesen). `append_context_file` bleibt ohne
   Version zulässig, prüft sie aber, wenn sie kommt.
2. **Rücklesen.** Nach jedem `write`/`append` liest `context_area` die Datei über denselben Port
   zurück und vergleicht die Bytes mit dem erwarteten Ergebnis. Die Antwort trägt `verified`;
   das Urteil fällt das Werkzeug, nicht die KI. Ein Lesefehler beim Rücklesen ist kein
   Ausstand (geschrieben ist), sondern `verified: false`.
3. **Journal nur anhängen.** Journal ist jede Datei unter einem Ordner `journal` auf jeder Ebene
   und jede Datei `journal-*.md` auf jeder Ebene, ohne Beachtung der Groß- und Kleinschreibung.
   Eine vorhandene Journaldatei ersetzt `write_context_file` nie (`contextjournalappendonly`);
   Anlegen bleibt erlaubt (Rotation). Die Bindung hängt am Pfad, nicht an einer Angabe der KI (K2).
4. **Keine Freigabestufen je Art.** Coursepilot hat als Server-MCP keinen Kanal, über den das
   Plugin die Lehrkraft fragt. Die Arten der Änderung bleiben Skill-Vokabular für die Rückfrage
   im Chat; ein wirkungsloser Parameter `art` entfällt.

## Considered Options

- Ersetzen ohne Version weiter erlauben und nur im Skill verbieten: Grenze aus Text (K2).
- Rücklesen nur am WebDAV-Ort: Auch Private Files kann eine Schreibung verlieren (Quote,
  Datenbankfehler); die Antwort soll an beiden Orten dasselbe heißen.
- Journal an der Art `journal` statt am Pfad festmachen: Eine Einstufung der KI ist keine Grenze.

## Consequences

- KI-Clients, die ohne vorheriges Lesen ersetzen, bekommen einen Fehler mit nächstem Schritt.
- Interne Aufrufer (`supplement_missing`, Altbestand-Kopie) nutzen `create_only` und sind
  nicht betroffen.
