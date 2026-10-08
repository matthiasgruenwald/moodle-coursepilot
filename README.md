# Coursepilot

**Unterricht in Moodle planen und umsetzen.**

[Dokumentation und Erste Schritte](https://matthiasgruenwald.github.io/moodle-coursepilot/de/)
· [English documentation](https://matthiasgruenwald.github.io/moodle-coursepilot/en/)

Coursepilot ist die schulbezogene Weiterentwicklung des MoodleMCP-Ansatzes: Lehrkräfte
bauen bestehende Moodle-Kurse im Gespräch mit einer KI auf, statt sie zu klicken.

> **Herkunft:** Coursepilot verweist bewusst auf MoodleMCP: Dieses Repository ist ein
> schulbezogener Fork von [`jtuttas/MoodleMcp`](https://github.com/jtuttas/MoodleMcp),
> der für Fortbildung, Testinstanz und IGS-Sprache eigenständig weiterentwickelt
> wird (siehe `docs/adr/0002-use-an-igs-fork-as-training-version.md`). Begriffe wie
> **Unterrichtseinheit**, **Unterthema** und **Lernpfad** ersetzen die im Upstream
> verwendete BBS-Sprache (z.B. "Lernsituation").

## Einrichten

Coursepilot `2.1.1-beta` wird gegen Moodle **5.1, 5.2 und 5.3** geprüft. Die
verpflichtenden PHP-/Datenbankkombinationen stehen in
[der CI-Dokumentation](docs/ci-native-server-mcp.md). Eine Moodle-5.0-Instanz
muss zuerst auf Moodle 5.1 aktualisiert werden. Vor dem Plugin-Upgrade
Datenbank und Moodle-Dateibereich sichern.

1. Release-Kandidat bauen (`npm run build:native-release`, Issue #577) oder das Verzeichnis
   `Plugin/src/local_coursepilot/` direkt nach `local/coursepilot` kopieren, dann das
   Moodle-Upgrade ausführen. War auf dieser Instanz zuvor Coursepilot
   1.x installiert: zuerst deinstallieren, keine Datenübernahme — siehe
   `Plugin/src/local_coursepilot/README.md`, Abschnitt Installation.
2. Webservices und das REST-Protokoll aktivieren.
3. Lehrkräften die Capability `local/coursepilot:use` geben.
4. Im KI-Client einen Connector auf `https://<moodle>/local/coursepilot/mcp.php`
   anlegen und einmal autorisieren. Discovery läuft nach RFC 8414 und RFC 9728 und
   braucht keinen Eingriff am Webserver, solange `slasharguments` aktiv ist
   (Moodle-Standard).

Alles Weitere — Werkzeuge, Skills, Ortswahl für Kontextbereich und Materialbestand —
erklärt der Server im Gespräch selbst. Details siehe
[`docs/specs/0012-local-kurspilot-server-mcp.md`](docs/specs/0012-local-kurspilot-server-mcp.md)
(trägt noch den alten Namen) und `Plugin/src/local_coursepilot/README.md`.

Der frühere lokale Weg (Coursepilot 1.x: Node-Server `moodle-mcp.js` auf dem Laptop,
Installer, lokale Skills) ist aus diesem Repository entfernt (#587) und bleibt über den
Tag `v1.0.0` und die Git-Historie erreichbar.

---

## Datenschutz und Datenzugang

Coursepilot ist ausschließlich für die **Kursgestaltung durch die Lehrkraft**
gedacht. Über den MCP-Server und die Moodle-Webservices sind nur Informationen
erreichbar, die eine Lehrkraft zum Anlegen und Pflegen von Kursinhalten braucht:
Kursabschnitte, Textseiten, Labels, Aufgaben, Links, Quiz- und
Fragensammlungs-Einstellungen sowie von der Lehrkraft erstellte Fragen.

**Nicht erreichbar** sind dagegen von Lernenden erzeugte oder personenbezogene
Daten. Coursepilot kann insbesondere **keine** der folgenden Daten lesen oder
ausgeben:

- Aufgabenabgaben (Submissions)
- Forenbeiträge
- Quizversuche (Attempts)
- Bewertungen und Noten
- Teilnehmendenlisten

Diese Grenze ist als **positive Allowlist** umgesetzt: Nur die ausdrücklich
geprüften Werkzeuge sind freigeschaltet (`classes/tool_registry.php`, geprüft über
`classes/privacy_surface.php`). Ein neu hinzugefügtes Werkzeug wird erst wirksam, wenn
es dort eingetragen ist; der Vertragstest `tests/privacy_surface_test.php` prüft die auf
der Instanz tatsächlich registrierte Oberfläche.

**Das Moodle-Plugin ruft selbst keinen KI-Anbieter auf.** Den KI-Client (z.B.
Claude oder Codex) wählt und bedient die Lehrkraft. Erst wenn
die Lehrkraft diesen Client nutzt und dabei Kursinhalte an ihn übergibt, können
diese Inhalte an den Anbieter des jeweils konfigurierten KI-Clients übertragen
werden. Welche Inhalte die Lehrkraft an ihren KI-Client weitergibt, entscheidet
sie selbst; das Moodle-Plugin sendet von sich aus nichts an einen externen
Dienst.

Weil das Plugin eigene Tabellen führt — OAuth-Verbindungen und Tokens der Lehrkraft,
Markierungsgedächtnis, Werkbank-Tickets, Änderungsverlauf —, implementiert es einen
**vollen** Privacy-Provider mit Auskunft und Löschung und benennt den externen
WebDAV-Ablageort ausdrücklich (`Plugin/src/local_coursepilot/classes/privacy/provider.php`).

---

## Sprachen und Übersetzungen

Englisch ist die Basissprache. Das gilt seit
[ADR 0024](docs/adr/0024-englische-basis-und-komponente-coursepilot.md) nicht nur für die
Sprachdateien, sondern auch für den Werkzeugvertrag: Parameternamen, Rückgabeschlüssel und
Werkzeugbeschreibungen sind englisch, damit das Plugin international nutzbar bleibt.

Der Release-Build enthält nur `lang/en/`. Die deutsche Datei unter `lang/de/`
ist eine Entwicklungsübersetzung; veröffentlichte Übersetzungen folgen über
**AMOS**. Auch der **Skill-Korpus** ist englisch. Die KI antwortet in der Sprache
der Lehrkraft; Unterrichtsinhalte folgen der angefragten Sprache.

---

## Bekannte Einschränkungen

**Emojis in Aktivitätstiteln nicht möglich**
Die meisten Moodle-Installationen nutzen `utf8` statt `utf8mb4` als Datenbankzeichensatz.
Emojis im `name`-Feld führen zu einem Datenbankfehler. Im HTML-Inhalt funktionieren
Emojis problemlos als HTML-Entities, z.B. `&#127757;` statt 🌍.

**Sichtbarkeit von Abschnitten**
`update_section` setzt Sichtbarkeit auf Abschnittsebene. Die Sichtbarkeit einzelner
Aktivitäten wird über den `visible`-Parameter der jeweiligen Create/Update-Funktion
gesteuert.

**Voraussetzungen / Abschlussverfolgung**
Damit Voraussetzungen über abgeschlossene Aktivitäten funktionieren, muss in Moodle
die Abschlussverfolgung im Kurs (bzw. systemweit) aktiviert sein.

**Kursformat**
Das Plugin funktioniert mit allen Moodle-Kursformaten (Topics, Weekly usw.).
Die `sectionnum` ist immer 0-basiert (Abschnitt 0 = "Allgemeines"). Dieser
Abschnitt ist ein normaler fachlicher Kursabschnitt und nicht der Default-Ort
fuer Coursepilot-Status, Debug-Notizen oder andere Prozessdaten.

---

## Projektstruktur

```
moodle-coursepilot/
├── Plugin/src/local_coursepilot/  <- Server-MCP: Plugin IST der MCP-Endpunkt
│   ├── classes/external/          <- die Werkzeuge (coursepilot_*)
│   ├── skills/                    <- Skill-Korpus, wird im Gespraech ausgeliefert
│   └── tests/                     <- PHPUnit
├── Plugin/src/well-known/         <- RFC-8414/9728-Pfade fuer die Discovery
├── scripts/                       <- Release-Build, Deploy, CI-Hilfen
├── test/                          <- Node-Vertragstests, Playwright-Specs (Spike)
├── docs/site/                     <- Dokumentationsseite (GitHub Pages)
└── docs/adr/, docs/specs/, docs/plans/
```

---

## Contributing: Builds

Keine npm-Laufzeit-Dependencies; Node dient nur für Repo-Tests und Build-Skripte.

```bash
npm test                       # Node-Vertragstests
npm run build:native-release   # Release-ZIP und Quellstand nach dist/native-release/
```

PHPUnit und die Pflicht-CI: siehe [`docs/ci-native-server-mcp.md`](docs/ci-native-server-mcp.md)
und [`docs/agents/testing.md`](docs/agents/testing.md).

---

## Urheberrechtswarnung

KI-erstelltes Material (Textseiten, Aufgaben, Arbeitsblätter, Quellenhinweise usw.)
darf **nicht automatisch weiterverbreitet** werden – weder an andere Kolleginnen und
Kollegen noch in öffentliche Repositories, geteilte Ablagen oder andere Moodle-Instanzen.

- Die Nutzung im eigenen schulischen Moodle-Kurs und die Weitergabe an andere Personen
  oder Repositories haben unterschiedliche rechtliche Risiken.
- Enthält das Material Auszüge aus Lehrwerken, Schulbüchern, Screenshots oder anderen
  urheberrechtlich geschützten Quellen, bleibt die **Lehrkraft verantwortlich** für
  Prüfung und Entscheidung über eine Weitergabe.
- Dies ist **keine Rechtsberatung**, sondern ein Hinweis zur eigenen Verantwortung.
  Im Zweifel: vor Weitergabe Rücksprache mit der Schulleitung oder zuständigen Stellen
  halten und nur eine **bereinigte Fassung** (siehe `CONTEXT.md`, Begriff
  "Bereinigte Weitergabe") teilen.

---

## Lizenz

Dieses Repository ist das **primäre Entwicklungs-, Support- und Issue-Repository**
(Server-MCP, Skills, Tests). Es steht vollständig unter **AGPL-3.0-or-later**,
einschließlich des Moodle-Plugins (siehe [`LICENSE`](LICENSE) und
[ADR 0025](docs/adr/0025-agpl-fuer-das-gesamte-projekt.md)).

> Coursepilot steht unter der AGPL-3.0-or-later, nicht unter der GPL. Das ist eine
> bewusste Entscheidung. Was für die Bildung gebaut wird, soll frei bleiben — auch
> dann, wenn jemand es nur als Dienst betreibt, statt es auszuliefern. Wer Coursepilot
> verändert und anderen zugänglich macht, gibt seine Änderungen an die Allgemeinheit
> zurück.

Der Moodle Marketplace verlangt keine GPL für Plugins; GPL v3 wäre nur zwingend, wenn ein
Plugin wesentliche Teile des Moodle-Quellcodes übernähme. Das tut Coursepilot nicht — es
nutzt die Core-Schnittstellen.

Coursepilot ist aus [`jtuttas/MoodleMcp`](https://github.com/jtuttas/MoodleMcp)
hervorgegangen; dessen MIT-Lizenzhinweise stehen in [`NOTICE`](NOTICE).

**Veröffentlichung:** Das frühere Moodle Plugins Directory ist im
[Moodle Marketplace](https://marketplace.moodle.com/) aufgegangen. Eingereicht wird dort
als ZIP mit Formular; die Einreichung steht noch aus und erfolgt nach einigen Wochen
Produktivbetrieb des Server-Wegs. Das bisherige Spiegelrepository
[matthiasgruenwald/moodle-local_coursepilot](https://github.com/matthiasgruenwald/moodle-local_coursepilot)
und der Workflow
[`.github/workflows/mirror-sync.yml`](.github/workflows/mirror-sync.yml) bauen aus
dem nativen Release-Build (`npm run build:native-release`) und laufen nur manuell; ob der
Spiegel bleibt, entscheidet die Marketplace-Einreichung (#192).
