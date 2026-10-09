# ADR 0030 — Schichtregeln per Klassenliste statt Namespace-Umzug

Status: angenommen (05.10.2026)

## Context

`local_coursepilot` hat zwölf Namespaces; der Wurzel-Namespace ist ein flacher Sammeltopf mit
48 Klassen. Gemessene Rückkanten (dev @ 05fb815): Modulkatalog nutzt Werkzeuge
(`catalog → external`), der Änderungsverlauf nutzt `restore_activity_version`
(`history → external`), der WebDAV-Adapter nutzt ein Werkzeug (`webdav → external`).

## Decision

Vier Schichten, erzwungen durch einen Dependency-Regel-Checker (deptrac):

1. **Einstieg**: Seiten-Skripte, MCP-Endpunkt, Dispatcher, Werkzeugregister. Darf alles nutzen.
2. **Werkzeuge** (`external`): dürfen Fachmodule nutzen, keine anderen Werkzeuge.
3. **Fachmodule**: Modulkatalog, Änderungsverlauf, Quiz, Kontextbereich, Materialbestand,
   Ablageort, OAuth. Dürfen nie Werkzeuge nutzen.
4. **Adapter**: WebDAV und die Ablage-Ports. Dürfen nur Ports und den Moodle-Kern nutzen.

Die Zuordnung der Wurzel-Klassen zu Schichten steht als Klassenliste in der
deptrac-Konfiguration. Klassen werden dafür **nicht** in neue Namespaces verschoben.

## Considered Options

- **Wurzel-Namespace in Unter-Namespaces aufteilen**: verworfen. Klassennamen sind
  Moodle-Vertrag (Autoload, `db/services.php`, Tasks, Events, Privacy); ein Umzug ist ein
  Wide Refactor mit Upgrade-Risiko ohne fachlichen Gewinn.

## Consequences

- Neue Wurzel-Klassen brauchen einen Eintrag in der Klassenliste; fehlt er, schlägt das Gate
  fehl (keine stille Zuordnung).
- Die drei Rückkanten werden im Architektur-Aufräumen aufgelöst, bevor die Regel scharf wird.

## Nachtrag 09.10.2026 — Rückkanten korrigiert, deptrac mit Baseline

Die Architekturprüfung aus #658 hat die Rückkanten im Abschnitt Context nachgemessen
(dev @ 9f76cdc). `catalog → external` und `webdav → external` sind nur `@see`-Docblocks,
im Code wird kein Werkzeug aufgerufen. Die echten Verstöße sind:

- **Werkzeug ruft Werkzeug**: `get_module_settings::execute` aus fünf Werkzeugen
  (`create_module`, `set_completion`, `set_restriction`, `update_module_settings`,
  `restore_activity_version`); `restore_activity_version` ruft `update_module_settings`
  und `set_completion`; `update_mc_question` und `create_mc_question` rufen
  `create_mc_question`, `export_questions_xml` und `import_questions_xml`.
- **Adapter nutzt Fachlogik**: `webdav_storage_port` übersetzt Fehlschläge selbst über
  `pending_write_translation`.

Geändert gegenüber der Decision:

- deptrac wird mit einer **versionierten Baseline** scharf, die nur schrumpfen darf
  (gleiche Ratschen-Regel wie die PHPStan-Baseline in ADR 0029). Jeder Eintrag nennt das
  Folgeticket, das ihn auflöst. Neue Verstöße blockieren sofort.
- Die Verstöße werden **nach** dem Scharfschalten des Gates aufgelöst, in eigenen
  Deepening-Tickets, damit die Umbauten unter Coverage- und CRAP-Prüfung laufen. Vorher
  aufgelöst werden nur die Docblock-Kanten.

Die Consequence „Die drei Rückkanten werden aufgelöst, bevor die Regel scharf wird“ gilt
damit nicht mehr. Ziel bleibt eine leere Baseline nach Abschluss der Deepening-Tickets.

Umsetzung in deptrac (#662): Der Ablageort-Kern (Port, Anker, Pointer, Zugriffsprotokoll, Ereignisse)
ist als Teil der Fachmodule eine eigene Schicht „Ports“, damit ein Adapter nur ihn nutzen darf und nicht
die übrige Fachlogik. Jedes Werkzeug ist eine eigene Schicht, weil deptrac Abhängigkeiten innerhalb einer
Schicht immer erlaubt. Der Vergleich der Baseline mit der des Ziel-Branches (Ratsche) folgt in einem eigenen Ticket.
