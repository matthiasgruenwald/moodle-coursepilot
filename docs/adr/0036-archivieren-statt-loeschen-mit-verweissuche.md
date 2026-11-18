# ADR 0036 — Archivieren statt Löschen, mit Verweissuche

Status: angenommen (Spec 0030). Übernommen aus E6 und E10 des moocp-contextmanager; ergänzt das
Leitmotiv aus Spec 0016 („kein Coursepilot-Endpunkt für Löschen/Umbenennen“).

## Kontext

Der Umbau vom alten Layout (`CONTEXT.md`, `status.md`, `journal-*.md`) in den Grundaufbau
(ADR 0037) braucht einen Weg, alte Dateien beiseitezulegen. Löschen bleibt Sache der Lehrkraft
in „Meine Dateien“ oder am externen Ort.

## Entscheidung

1. `coursepilot_archive_context_files(folder, files[], …)` verschiebt direkte `.md`-Dateien
   eines Ordners nach `<folder>/archive/<name>`. Nichts wird gelöscht oder überschrieben:
   Existiert ein Ziel, bricht der Aufruf vor dem ersten Verschieben ab. Journale (ADR 0034)
   wandern nie.
2. Der Ablage-Port bekommt `move` ohne Überschreiben mit Prüfwert: Private Files legen das Ziel
   an (eindeutiger Pfad-Hash) und entfernen die Quelle erst danach; WebDAV nutzt `MOVE` mit
   `Overwrite: F` und `If-Match` bei starkem ETag, sonst den Vergleich aus ADR 0035.
3. Nach dem Verschieben sucht das Werkzeug in `index.md` und `CONTEXT.md` der Ebene, aller Ebenen
   darüber und darunter (ohne `archive/`) nach Verweisen auf die verschobenen Dateien
   (Markdown-Link, Backtick, Wort; relativ zur Ebene oder zur Wurzel). Es nennt Datei, Zeile und
   Pfad, nie die Zeile selbst (Inhalte sind Daten, K3).
4. Die Suche ist begrenzt (10 Ebenen, 300 Ordner, 100 Indexdateien, 1 MiB je Datei, 8 Sekunden).
   Jede Grenze, jeder Ausfall macht sie `INCOMPLETE`; nie „nichts gefunden“.
5. Das Plugin schreibt keinen Index um; die KI korrigiert die Verweise mit Lesen und Schreiben
   (ADR 0034), die Lehrkraft bestätigt im Chat.
6. Kein Ausstand: Eine Verschiebung hat keinen Inhalt zum Nachtragen.

## Considered Options

- Kopieren mit `create_only` und Löschen von Hand: Die Lehrkraft müsste jede alte Datei einzeln
  suchen, und alte Verweise blieben unbemerkt.
- Verweise automatisch umschreiben: verändert Inhalt der Lehrkraft außerhalb ihrer Bestätigung.
- Alle Dateien durchsuchen: hängt bei großen Bereichen am Zeitlimit; Indexdateien sind, worauf
  die KI sich verlässt.

## Consequences

- Das Werkzeug ist schreibend, nicht destruktiv (`destructiveHint: false`), und nicht
  idempotent.
- Andere Verweise (Pläne, Notizen) prüft die KI selbst; der Skill sagt es.
