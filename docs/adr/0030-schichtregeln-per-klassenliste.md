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
