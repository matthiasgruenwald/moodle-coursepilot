# Coursepilot

**Unterricht in Moodle planen und umsetzen.**

Coursepilot ist die schulbezogene Weiterentwicklung des MoodleMCP-Ansatzes: Lehrkräfte
bauen bestehende Moodle-Kurse im Gespräch mit einer KI auf, statt sie zu klicken.

> **Herkunft:** Coursepilot verweist bewusst auf MoodleMCP: Dieses Repository ist ein
> schulbezogener Fork von [`jtuttas/MoodleMcp`](https://github.com/jtuttas/MoodleMcp),
> der für Fortbildung, Testinstanz und IGS-Sprache eigenständig weiterentwickelt
> wird (siehe `docs/adr/0002-use-an-igs-fork-as-training-version.md`). Begriffe wie
> **Unterrichtseinheit**, **Unterthema** und **Lernpfad** ersetzen die im Upstream
> verwendete BBS-Sprache (z.B. "Lernsituation").

## Zwei Wege, ein Name

Coursepilot gibt es in zwei Ausführungen. Beide tragen die Moodle-Komponente
`local_coursepilot`, laufen aber nie auf derselben Instanz.

| | **Server-Weg (aktuell, Version 2)** | **Lokaler Weg (Altstand 1.x)** |
|---|---|---|
| Wo läuft der MCP-Server? | im Moodle-Plugin selbst | als Node-Server auf dem Laptop |
| Was installiert die Lehrkraft? | nichts | Node, Konfigurationsprogramm, Skills |
| Anmeldung | OAuth im KI-Client, einmalig | Webservice-Token von Hand |
| Skills | liefert der Server im Gespräch mit | Dateien im Benutzerprofil |
| Quelle im Repo | `Plugin/src/local_coursepilot/` | `legacy/local_coursepilot/` + `moodle-mcp.js` |
| Status | hier findet die Entwicklung statt | eingefroren, wird abgekündigt |

```
Server-Weg                        Lokaler Weg (Altstand)
KI-Client (Claude, Codex)         Claude Desktop (stdio)
      | HTTPS + OAuth                    |
Moodle: local_coursepilot           moodle-mcp.js  <- Node auf dem Laptop
  (MCP-Endpunkt + Werkzeuge)              |
      |                             Moodle REST API <- local_coursepilot 1.x
Moodle 5.0+                               |
                                    Moodle 5.0+
```

### Server-Weg einrichten

1. Plugin aus `Plugin/src/local_coursepilot/` nach `local/coursepilot` installieren
   und das Moodle-Upgrade ausführen.
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

---

> **Ab hier beschreibt diese Datei den lokalen Altweg.** Er läuft unverändert weiter,
> bis der Schnitt fällt, und wird dann samt `legacy/` entfernt. Für Lehrkräfte heißt
> die Skill-Familie dort weiterhin `kurspilot`: `kurspilot` ist der sichtbare Einstieg
> und wechselt je nach Anliegen in `kurspilot-einrichten`, `kurspilot-planen` oder
> `kurspilot-umsetzen`.

Im lokalen Weg spricht Claude Desktop über `moodle-mcp.js` mit der Moodle REST API und
kann Kursabschnitte, Textseiten, Labels, Aufgaben, externe Links, Dateien,
Verzeichnisse, Abstimmungen und Foren anlegen und bearbeiten. Er lädt lokal erzeugte
Dateien (z.B. PDF/DOCX/XLSX) als "Zusätzliche Dateien" in Moodle-Aufgaben hoch und
setzt **Abschlussverfolgung** sowie **Voraussetzungen/Verfügbarkeit**.

---

## Voraussetzungen

- Moodle 5.0 oder neuer
- Node.js (v24+) auf dem Rechner mit Claude Desktop
- Codex oder Claude mit lokaler Skill-Unterstuetzung
- Admin-Zugang zu Moodle
- Optional fuer `moodle_crop_image`: ImageMagick (`convert`)

---

## Schnellinstallation

Der schnellste Weg für eine Lehrkraft, Coursepilot lokal lauffähig zu bekommen:
ein Einzeiler im Terminal (macOS/Linux) bzw. in PowerShell (Windows). Kein
vorinstalliertes Node.js nötig und kein Homebrew/Chocolatey-Zwang – das
Skript lädt bei Bedarf automatisch ein offizielles Node-Tarball von
nodejs.org architektur-passend (macOS arm64/x64, Linux x64, Windows
x64/arm64) in ein eigenes Userverzeichnis (`~/.kurspilot/` bzw.
`%LOCALAPPDATA%\Kurspilot`).

**macOS/Linux:**

```bash
curl -fsSL https://raw.githubusercontent.com/matthiasgruenwald/moodle-coursepilot/main/setup.sh | bash
```

**Windows (PowerShell):**

```powershell
powershell -ExecutionPolicy Bypass -Command "iwr -useb https://raw.githubusercontent.com/matthiasgruenwald/moodle-coursepilot/main/setup.ps1 | iex"
```

Beide Einzeiler holen bei jedem Start den aktuellen Stand von `main` aus
diesem GitHub-Repository und starten daraus das
**Coursepilot-Konfigurationsprogramm** (`scripts/setup-kurspilot.js`). Das ist
dieselbe Browser-Seite, über die auch Moodle-URL/Token eingegeben werden.
Damit folgt der Installationsweg bewusst direkt dem aktuellen Hauptbranch;
bei einem geschlossenen, prüfsummenfixierten Release-Stand darf dieser
Einzeiler nicht verwendet werden.

> **Hinweis Windows SmartScreen:** Bei einem noch unbekannten Download kann
> SmartScreen warnen – das ist normal, kein Fehler. Auf "Mehr Informationen"
> und dann "Trotzdem ausführen" klicken. Alle Downloads kommen ausschließlich
> von `github.com/matthiasgruenwald/moodle-coursepilot`.

**Welcher Installationsweg für wen?**

| Weg | Für wen | Voraussetzung |
|---|---|---|
| **Schnellinstallation (curl/PowerShell, oben)** | Empfohlener Standardweg für die meisten Lehrkräfte | Terminal/PowerShell einmal öffnen, sonst keine |
| **Manuelle Schritte 1–8 (unten)** | Entwicklung, Debugging, eigene Anpassungen am Setup | Node.js manuell vorhanden, technisches Verständnis |

Schnellinstallation und manuelle Schritte führen am Ende zum selben Ziel:
lokaler MCP-Server + Coursepilot-Konfigurationsprogramm. Die manuellen Schritte
1–8 sind der granulare Unterbau, den die Schnellinstallation im Hintergrund
nutzt – sie bleiben relevant für alle, die einzelne Schritte verstehen,
anpassen oder debuggen wollen.

---

## Installation

### 1. Moodle-Plugin installieren

Das Plugin `local_coursepilot` in der Fassung 1.x stellt die benötigten
Webservice-Funktionen bereit. Quelle ist `legacy/local_coursepilot/`; für den
Server-Weg gilt stattdessen die Anleitung oben.

1. `local_coursepilot.zip` herunterladen
   (im Repository liegt die ZIP unter `Plugin/local_coursepilot.zip`, gebaut aus
   `legacy/` über `npm run build:plugin`)
2. In Moodle: **Website-Administration → Plugins → Plugin installieren**
3. ZIP hochladen und Upgrade bestätigen

> **Hinweis Neuinstallation (wichtig):** Coursepilot nutzt jetzt die Moodle-Komponente
> `local_coursepilot`. Falls auf diesem Moodle noch die alte Komponente
> `local_aicoursecreator` installiert ist, **deinstalliere sie zuerst**
> (Website-Administration → Plugins → Plugins verwalten) und installiere danach
> `local_coursepilot` neu. Eine Daten- oder Einstellungsübernahme (Migration) aus
> `local_aicoursecreator` gibt es bewusst nicht. Für ein normales Update von
> `local_coursepilot` gilt ebenfalls: zuerst deinstallieren, dann die neue ZIP
> installieren. Moodle 5.0 oder neuer wird vorausgesetzt.

### 2. Web Services in Moodle aktivieren

**Website-Administration → Erweiterte Funktionen:**
- "Webservices aktivieren" ✅

**Website-Administration → Plugins → Webservices → Protokolle verwalten:**
- REST-Protokoll aktivieren (Auge-Symbol) ✅

### 3. API-Token erstellen

**Website-Administration → Server → Webservices → Token verwalten → Token hinzufügen**

- **Nutzer:** beliebiger Nutzer mit einem Token fuer den Dienst `Coursepilot`. Der Dienst hat keine Berechtigten-Liste (`restrictedusers=0`); eine eigene Coursepilot-Rolle gibt es nicht
- **Kursrechte:** ueber die API geht nur, was der Nutzer im Kurs ohnehin duerfte – normale Moodle-Rechte (z.B. Trainerrechte) plus die Capability `local/coursepilot:use` (Default fuer Trainer/innen)
- **Dienst:** `Coursepilot`
- Token kopieren – er wird nur einmal angezeigt!

> **Sicherheit:** Den Token wie ein Passwort behandeln. Niemals in
> öffentliche Repositories oder Chats einfügen.

### 4. moodle-mcp.js einrichten

`moodle-mcp.js` auf den lokalen Rechner kopieren, z.B. nach:
- Windows: `C:\moodle-mcp\moodle-mcp.js`
- macOS/Linux: `~/moodle-mcp/moodle-mcp.js`

Keine weiteren Abhängigkeiten – nur Node.js wird benötigt.

### 5. Claude Desktop konfigurieren

Konfigurationsdatei unter macOS öffnen:
- `~/Library/Application Support/Claude/claude_desktop_config.json`

Folgenden Inhalt eintragen:

```json
{
  "mcpServers": {
    "moodle": {
      "command": "node",
      "args": ["/Users/dein-name/moodle-mcp/scripts/start-mcp.js"]
    }
  }
}
```

Windows braucht denselben Grundsatz ohne Klartext-Token in der MCP-Konfiguration;
ein Windows-Credential-Store-Wrapper ist in dieser macOS-Schicht noch nicht
implementiert.

### 6. Codex konfigurieren (optional)

Codex lädt MCP-Server aus `~/.codex/config.toml`. Folgenden Block ergänzen:

```toml
[mcp_servers.moodle]
command = "node"
args = ["/Users/dein-name/moodle-mcp/scripts/start-mcp.js"]
startup_timeout_sec = 30
```

Fuer ein schlankes Planungsprofil ohne sichtbare Schreibtools setzt du
das Profil als Wrapper-Argument:

```toml
args = ["/Users/dein-name/moodle-mcp/scripts/start-mcp.js", "--profile", "readonly"]
```

Ohne dieses Argument startet der Server im Vollprofil fuer die Umsetzung.

Lege Moodle-URL und Token nicht in `.env` oder Codex-/Claude-Konfigurationsdateien
ab. Fuer Coursepilot ist das Konfigurationsprogramm beziehungsweise
`scripts/moodle-credentials.js` der Token-Speicherweg; der Startwrapper liest die
Werte aus dem macOS-Schluesselbund und setzt sie nur fuer den laufenden
MCP-Prozess.

Danach Codex neu starten oder einen neuen Thread öffnen. Prüfen kannst du die
Einrichtung mit der Frage:

```text
Siehst du Moodle-MCP-Tools wie moodle_get_sections?
```

Wenn `moodle_get_sections` sichtbar ist, ist der Server geladen. Ein erster
Funktionstest ist:

```text
Rufe mit dem Moodle-MCP moodle_get_sections für Kurs-ID 2 auf.
```

### 7. Coursepilot-Skills fuer Codex und Claude aktivieren

Das Coursepilot-Paket besteht aus einem gemeinsamen Kern und duennen
Anbieter-Adaptern:

- Kanonischer Coursepilot-Kern: `skills/kurspilot-core.md`
- Codex-Skills: `.agents/skills/kurspilot*/SKILL.md`
- Claude-Skills: `.claude/skills/kurspilot*/SKILL.md`

Fuer Lehrkraefte ist **Coursepilot** der sichtbare Name der Skill-Familie. Die
V1-Skills sind:

- `kurspilot`: sichtbarer Einstieg, benennt den passenden Modus offen
- `kurspilot-einrichten`: lokaler Arbeitsbereich, Kontext und erste Weiche
- `kurspilot-planen`: Planen, ueberarbeiten, freigeben und `status.md` pflegen
- `kurspilot-umsetzen`: freigegebene Plaene in Moodle umsetzen

In V1 gibt es kein separates `kurspilot-fortsetzen` und kein separates
`kurspilot-materialien`. Weiterarbeit laeuft ueber den jeweils passenden
Modus.

Fuer die Paket-Skills gilt Planstrenge: Coursepilot plant und setzt nur um, was
aus Lehrkraftauftrag, bereitgestelltem Material, lokalem Kontext und dem
freigegebenen Implementierungsplan nachvollziehbar folgt. Extras wie
Ausgangssituations-Cards, farbkodierte Header, PDF-/Print-Hinweise,
Zusatzaktivitaeten oder sonstige Deko brauchen Planbezug oder ausdrueckliche
Lehrkraftfreigabe.

Fuer Planung und Umsetzung gilt **Planstrenge**: keine ungefragten Extras;
neue sichtbare Elemente, Aktivitaeten, Materialien, Dateien, Bewertungen oder
Kurslogik werden nur umgesetzt, wenn sie im Auftrag, Material, Kontext oder
freigegebenen Plan begruendet sind.

Codex erkennt die Projekt-Skills in `.agents/skills/` in einem neuen Codex-Thread
im vertrauten Repository. Teste die Erkennung mit:

```text
Welche Coursepilot-Skills siehst du?
```

Claude Code beziehungsweise Cowork erkennt die Projekt-Skills in
`.claude/skills/`, nachdem das Projekt als vertrauenswuerdig geoeffnet wurde.
Claude Code neu starten oder eine neue Session im Repo oeffnen und dann testen:

```text
Nutze kurspilot und sage mir, in welchen Modus du wechseln wuerdest.
```

Die Skills allein reichen nicht: Fuer echte Moodle-Arbeit muessen der
MCP-Server, die Moodle-Token-Konfiguration und bei Bildzuschnitt ImageMagick
eingerichtet sein. Der Windows-first Kollegiums-Installer und Token-Speicher
bleiben der gekoppelte Umsetzungspfad aus #5.

#### Coursepilot-MCP-Eintraege automatisch einrichten (macOS)

Statt die Bloecke aus Schritt 5/6 von Hand einzutragen, kann
`scripts/setup-mcp-config.js` die Planungs- (`kurspilot-planung`, Profil
`readonly`) und Umsetzungs-Eintraege (`kurspilot-umsetzung`, Profil `full`)
direkt in `claude_desktop_config.json` und `~/.codex/config.toml` anlegen oder
mergen. Beide Eintraege rufen ausschliesslich den tokenfreien Wrapper
`scripts/start-mcp.js` auf - es wird nie eine Moodle-URL oder ein Token in
diese Konfigurationsdateien geschrieben:

```bash
node scripts/moodle-credentials.js set --url <url> --token <token>  # einmalig
node scripts/setup-mcp-config.js                  # beide Clients
node scripts/setup-mcp-config.js --client claude  # nur Claude Desktop
node scripts/setup-mcp-config.js --client codex   # nur Codex
```

Vorhandene fremde Eintraege in beiden Dateien bleiben erhalten; vor jeder
Aenderung einer bestehenden Datei wird automatisch ein Backup mit
Zeitstempel-Suffix (`*.bak-<timestamp>`) angelegt.

#### Coursepilot-Skills nutzerweit installieren (Issue #66)

Damit Coursepilot auch ohne geoeffnetes Projekt-Repository verfuegbar ist
(**Nutzerweite Coursepilot-Installation**, siehe `CONTEXT.md`), kopiert
`scripts/install-skills.js` die vier Coursepilot-Skill-Adapter plus den
gemeinsamen Kern in die nutzerweiten Skill-Verzeichnisse:

```bash
node scripts/install-skills.js                 # Claude + Codex
node scripts/install-skills.js --client claude # nur ~/.claude/skills/
node scripts/install-skills.js --client codex  # nur ~/.agents/skills/
```

Zielpfade:

- Claude: `~/.claude/skills/kurspilot*/SKILL.md`
- Codex: `~/.agents/skills/kurspilot*/SKILL.md` (kanonische nutzerweite Ablage)

Wenn Claude und Codex gemeinsam eingerichtet werden, kann der Konfigurator die
Option **„Gemeinsame Skill-Ablage“** verwenden: Codex behält die echte Kopie
unter `~/.agents/skills/`; Claude erhält dafür Links (unter Windows Junctions)
unter `~/.claude/skills/`. `~/.codex/skills/` ist ausschließlich ein alter
Pfad, den der Installer vorsichtig bereinigt.

Der gemeinsame Coursepilot-Kern und die thematischen Referenzdateien (alle
`.md`-Dateien unter `skills/`, z.B. `kurspilot-core.md`, `html-vorlagen.md`,
`quiz-und-fragenbank.md`) werden nach `<zielwurzel>/kurspilot-shared/`
mitkopiert. Die installierten `SKILL.md`-Dateien verweisen relativ darauf,
sodass die Skills ohne Repo-Checkout funktionieren. Der Lauf ist idempotent
und ueberschreibt ausschliesslich Coursepilot-eigene Unterordner – fremde Skills
im selben Verzeichnis bleiben unberuehrt. Fuer Tests akzeptiert das Skript
`--home <dir>` bzw. die Umgebungsvariable `KURSPILOT_INSTALL_HOME`.

#### Coursepilot-Konfigurationsprogramm

`scripts/setup-kurspilot.js` ist das wiederaufrufbare
**Coursepilot-Konfigurationsprogramm** (siehe `CONTEXT.md`): es startet lokal
einen kurzlebigen Browser-Dienst auf `127.0.0.1`, waehlt den Port automatisch
und zeigt Coursepilot-Status sowie Wartungsbereich-Auswahl in
lehrkraftverstaendlicher Sprache. Der Dienst laesst sich ueber die Seite
wieder beenden.

Nicht-interaktiv (z.B. fuer Automatisierung/Tests), alle Werte als Flags:

```bash
node scripts/setup-kurspilot.js --non-interactive \
  --clients codex,claude \
  --workspace ~/Documents/Kurspilot \
  --moodle-url https://moodle.example.org \
  --moodle-token <token>
```

Interaktiv (Default) oeffnet automatisch den lokalen Browser:

```bash
node scripts/setup-kurspilot.js
```

Der Arbeitsbereich-Ort (**Arbeitsbereich-Ort**, siehe `CONTEXT.md`) hat den
Default `~/Documents/Kurspilot` und ist per `--workspace`-Flag bzw.
Ordnerauswahl-Dialog aenderbar; das Wurzelverzeichnis wird angelegt, falls es
fehlt. Moodle-URL/Token werden nie in den Statusreport, ein Log oder eine
Datei geschrieben – nur ein Ja/Nein-Hinweis, ob Zugangsdaten gespeichert
wurden.

Die Status- und Wartungslogik liegt in `lib/setup-flow.js`; die lokale
Browserseite liegt in `lib/setup-browser-server.js` und ist ohne echte
Browserautomation testbar (siehe `test/setup-browser-server.test.js`).

### 8. Claude Desktop neu starten

Claude Desktop vollständig beenden (auch aus dem System-Tray) und neu starten.
Unten links das Hammer-Symbol prüfen – dort sollten die Moodle-Tools erscheinen.

---

## Verfügbare Tools

| Tool | Beschreibung |
|---|---|
| `moodle_get_sections` | Alle Abschnitte eines Kurses lesen |
| `moodle_get_modules` | Alle Aktivitäten eines Abschnitts mit cmid lesen |
| `moodle_get_course_catalog` | Kompakte, filterbare read-only Moodle-Katalogansicht fuer Planung lesen |
| `moodle_update_section` | Abschnittsname und Beschreibung setzen |
| `moodle_ensure_section` | Fehlenden Kursabschnitt bei Bedarf anlegen (Fallback, wenn moodle_update_section mit invalidrecord scheitert) |
| `moodle_move_section` | Bestehenden Abschnitt ohne Inhaltsaenderung an eine neue Position verschieben |
| `moodle_move_module` | Bestehende Aktivitaet per cmid vor/nach eine andere Aktivitaet oder ans Abschnittsende verschieben |
| `moodle_update_activity_settings` | Sichtbarkeit und/oder Gruppenmodus einer bestehenden Aktivität setzen |
| `moodle_clone_activity` | Aktivität im selben Kurs duplizieren (inkl. Plugin-Konfigurationen, Bewertung, Completion, Voraussetzungen), Titel und Sichtbarkeit direkt setzen |
| `moodle_create_label` | Text- und Medienfeld anlegen (Phasen-Header) |
| `moodle_update_label` | Text- und Medienfeld bearbeiten |
| `moodle_create_page` | Textseite anlegen |
| `moodle_update_page` | Textseite bearbeiten |
| `moodle_create_url` | Externen Link anlegen |
| `moodle_update_url` | Externen Link bearbeiten |
| `moodle_create_assign` | Aufgabe anlegen |
| `moodle_update_assign` | Aufgabe bearbeiten |
| `moodle_create_resource` | Datei (mod_resource) anlegen |
| `moodle_update_resource` | Datei bearbeiten |
| `moodle_create_folder` | Verzeichnis (mod_folder) anlegen |
| `moodle_update_folder` | Verzeichnis bearbeiten |
| `moodle_upload_folder_file` | Datei in ein Verzeichnis hochladen |
| `moodle_create_choice` | Abstimmung (mod_choice) anlegen |
| `moodle_update_choice` | Abstimmung bearbeiten |
| `moodle_create_forum` | Forum (mod_forum) anlegen |
| `moodle_update_forum` | Forum bearbeiten |
| `moodle_crop_image` | Lokale Bilddatei rechteckig zuschneiden |
| `moodle_upload_assignfile` | Datei als "Zusätzliche Datei" in eine Aufgabe hochladen |
| `moodle_embed_assign_image` | Bild direkt sichtbar in eine Aufgabenbeschreibung einbetten |
| `moodle_create_quiz` | Quiz (mod_quiz) anlegen – Modus wählt Settings-Kombination (siehe unten) |
| `moodle_update_quiz_settings` | Bestehendes Quiz nachträglich auf eine Coursepilot-Settings-Kombination umstellen |
| `moodle_add_questions_to_quiz` | Fragenbank-Fragen als Referenz auf die jeweils aktuellste Version zu einem Quiz hinzufügen |
| `moodle_plan_quiz_cleanup` | Nicht-destruktiven Bereinigungsplan erstellen, wenn eine neue Quizversion weniger Fragen enthält (lesend, kein Delete) |
| `moodle_ensure_question_bank` | Benannte Kurs-/Projekt-Fragensammlung anlegen oder wiederverwenden (idempotent) |
| `moodle_create_question_category` | Fragenbank-Kategorie in ausgewählter Fragensammlung anlegen (idempotent) |
| `moodle_update_question_category` | Fragenbank-Kategorie nicht-destruktiv umbenennen und/oder in die richtige Fragensammlung/Zielkategorie verschieben |
| `moodle_get_question_categories` | Fragenbank-Kategorien einer ausgewählten Fragensammlung lesen |
| `moodle_plan_question_category_cleanup` | Nicht-destruktiven Bereinigungsplan für leere, blattlose Testkategorien einer Fragensammlung erstellen (lesend, kein Delete) |
| `moodle_create_mc_question` | Multiple-Choice-Frage in einer Fragenbank-Kategorie anlegen |
| `moodle_update_mc_question` | MC-Frage als neue Moodle-Version derselben Frage aktualisieren (ADR-0001), alte Version bleibt für bestehende Quiz-Attempts gültig |
| `moodle_get_question` | Aktuellste Version einer Frage in einer Kategorie per Name oder questionid lesen |
| `moodle_upload_question_image` | Bild in Fragetext oder Antwortfeedback einer MC-Frage hochladen (liefert `@@PLUGINFILE@@`-Snippet zum Einfügen per `moodle_update_mc_question`) |
| `moodle_move_question` | Frage mit allen Versionen nicht-destruktiv in eine Zielkategorie verschieben |
| `moodle_import_questions_xml` | Moodle-XML-Fragen (z.B. STACK) versionstreu importieren; Reimport per idnumber erzeugt eine neue Version desselben Fragenbank-Eintrags (ADR-0001) |
| `moodle_set_completion` | Abschlussverfolgung für eine Aktivität konfigurieren |
| `moodle_set_restriction` | Aktivität sperren, bis andere Aktivitäten abgeschlossen sind |

### Quiz-Modi (`moodle_create_quiz`, `moodle_update_quiz_settings`)

`moodle_create_quiz` und `moodle_update_quiz_settings` kennen drei Coursepilot-Modi
(Parameter `mode`). Jeder Modus setzt eine komplette, dokumentierte
Settings-Kombination – Fragen werden anschließend separat hinzugefügt.
`gradepass` und `timelimit` lassen sich pro Aufruf explizit setzen und
überschreiben dann den Modus-Default (Layered Defaults). Der Wert `test` wird
nicht als Modusname verwendet, damit keine Verwechslung mit der Moodle-Aktivität
Test entsteht.

| Modus | Frageverhalten | Versuche | Bewertungsmethode | Layout | Wartezeit | Review-Sichtbarkeit | gradepass |
|---|---|---|---|---|---|---|---|
| `mini-check` | `immediatefeedback` (direkte Auswertung ohne Selbsteinschätzung) | unbegrenzt (0) | beste Bewertung (`QUIZ_GRADEHIGHEST`) | eine Frage pro Seite, freie Navigation | keine | richtige Antwort nicht anzeigen, Gesamtfeedback sichtbar | 80 % |
| `lernstandscheck` (Default) | `deferredcbm` (spätere Auswertung mit Selbsteinschätzung) | unbegrenzt (0) | beste Bewertung (`QUIZ_GRADEHIGHEST`) | alle Fragen auf einer Seite, freie Navigation | mindestens 5 Minuten | richtige Antwort nicht anzeigen, Gesamtfeedback für Lernplanung sichtbar | 80 % |
| `abschlusstest` | `deferredfeedback` (spätere Auswertung ohne Selbsteinschätzung) | maximal 2 | Mittelwert (`QUIZ_GRADEAVERAGE`) | alle Fragen auf einer Seite, freie Navigation | mindestens 15 Minuten | richtige Antwort nicht anzeigen, Gesamtfeedback sichtbar | 80 % |

**Schüler-Erfahrung und Monitoring-Tradeoffs:**

- **Mini-Check (`mini-check`):** Kurzer Kompetenzcheck mit direkter Auswertung,
  unbegrenzten Versuchen und ohne Wartezeit. Gut für schnelle Orientierung und
  unmittelbares Üben.
- **Lernstandscheck (`lernstandscheck`, Default):** Spätere Auswertung mit
  Selbsteinschätzung und Gesamtfeedback für Lernplanung. Gut, wenn die Lehrkraft
  und die Schüler:innen den nächsten Lernschritt aus dem Ergebnis ableiten sollen.
- **Abschlusstest (`abschlusstest`):** Abschlusstest mit Verbesserungsmöglichkeit,
  keine Klassenarbeit. Zwei Versuche mit Wartezeit und Mittelwertbildung halten den
  Fokus auf Abschluss und Verbesserung statt auf einmalige Bewertung.

Aus Kompatibilitätsgründen nimmt das Plugin die alten Werte `intensiv`,
`lerncheck` und `bewertung` noch an und mappt sie intern auf `mini-check`,
`lernstandscheck` und `abschlusstest`. Neue Aufrufe sollen nur die neuen
Modusnamen verwenden.

### Benannte Kurs-Fragensammlung

Vor neuen Quizfragen wird zuerst eine **benannte** Fragensammlung per
`moodle_ensure_question_bank` ausgewählt oder angelegt. Der Name soll für
Lehrkräfte lesbar sein und sich am Kurs, Thema oder fachlichen Inhalt
orientieren, zum Beispiel `Biologie 9a - Immunsystem` oder
`Chemie EF - Säuren und Basen`. Danach arbeiten
`moodle_create_question_category`, `moodle_update_question_category` und
`moodle_get_question_categories` immer
gegen diese ausgewählte Fragensammlung (`questionbankid`) statt gegen eine
systemweit geteilte Altlast.

Für **Fragensammlungs-Bereinigung** gilt: keine Delete-Tools in V1. Wenn eine
Kategorie falsch einsortiert ist, wird sie über
`moodle_update_question_category` nicht-destruktiv verschoben und bei Bedarf
umbenannt. Vor dem Schreibzugriff braucht es immer eine Vorschau/Freigabe mit
Quelle, Ziel und betroffenen Kategorien; erst danach wird die Kategorie
verschoben oder umbenannt.

Leere, blattlose Testkategorien (ohne eigene Fragen, ohne Unterkategorien)
lassen sich mit `moodle_plan_question_category_cleanup` aufspüren – das Tool
schlägt sie nur zur manuellen Löschung über einen direkten Moodle-Link vor,
löscht selbst nichts. Solche Testkategorien sollten vor einem Kursexport
bereinigt werden, da sie beim Restore auf einer anderen Moodle-Instanz zu
Fehlern führen können.

### Sichtbarkeit (optional)

Viele Create/Update-Tools unterstützen den Parameter `visible`:

- `1` = sichtbar
- `0` = versteckt
- bei Update-Tools zusätzlich: `-1` = nicht ändern (Standard)

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
geprüften MCP-Tools und Webservice-Funktionen sind freigeschaltet
(`lib/data-protection-allowlist.js`). Ein neu hinzugefügtes Werkzeug wird erst
wirksam, wenn es nach einer Prüfung dort eingetragen ist; Vertragstests
(`test/data-protection-contract.test.js`) erzwingen diese Grenze automatisch.

**Das Moodle-Plugin ruft selbst keinen KI-Anbieter auf.** Der KI-Client (z.B.
Claude Desktop oder Codex) läuft lokal auf dem Rechner der Lehrkraft. Erst wenn
die Lehrkraft diesen Client nutzt und dabei Kursinhalte an ihn übergibt, können
diese Inhalte an den Anbieter des jeweils konfigurierten KI-Clients übertragen
werden. Welche Inhalte die Lehrkraft an ihren KI-Client weitergibt, entscheidet
sie selbst; das Moodle-Plugin sendet von sich aus nichts an einen externen
Dienst.

Die Moodle-Datenschutz-API (Privacy-API) beschreibt in beiden Wegen das tatsächliche
Verhalten, allerdings unterschiedlich:

- **Lokaler Weg (1.x):** Das Plugin speichert keine personenbezogenen Daten und meldet
  über den `null_provider` bewusst keine Verarbeitung von Lernendendaten
  (`legacy/local_coursepilot/classes/privacy/provider.php`).
- **Server-Weg:** Hier gibt es plugin-eigene Tabellen — OAuth-Verbindungen und Tokens
  der Lehrkraft, Markierungsgedächtnis, Werkbank-Tickets, Änderungsverlauf. Deshalb
  implementiert das Plugin einen **vollen** Privacy-Provider mit Auskunft und Löschung
  und benennt den externen WebDAV-Ablageort ausdrücklich
  (`Plugin/src/local_coursepilot/classes/privacy/provider.php`).

---

## Sprachen und Übersetzungen

Englisch ist die Basissprache. Das gilt seit
[ADR 0024](docs/adr/0024-englische-basis-und-komponente-coursepilot.md) nicht nur für die
Sprachdateien, sondern auch für den Werkzeugvertrag: Parameternamen, Rückgabeschlüssel und
Werkzeugbeschreibungen sind englisch, damit das Plugin international nutzbar bleibt.

Deutsch wird in der Übergangsphase **vorübergehend** direkt mitgeliefert
(`lang/de/local_coursepilot.php`), damit deutschsprachige Moodle-Instanzen sofort eine
vollständige Oberfläche sehen. Das ist ein Provisorium: Der Moodle Marketplace erwartet,
dass nur englische Strings ausgeliefert werden und Übersetzungen nach der Freigabe über
**AMOS** (das Moodle-Übersetzungsportal) gepflegt werden. Sobald AMOS die deutsche
Übersetzung übernimmt, wird die mitgelieferte Datei entfernt.

Der **Skill-Korpus** ist davon ausgenommen: Er ist Prosa für Lehrkräfte, kein Moodle-String,
und bleibt vorerst deutsch. Eine zweisprachige Auslieferung ist als eigener Schritt geplant.

---

## Coursepilot: Unterrichtseinheiten automatisch aufbauen

Das Projekt enthält die Coursepilot-Skillfamilie als Installationspaket:
[`skills/kurspilot-core.md`](skills/kurspilot-core.md) ist der gemeinsame Kern,
`.agents/skills/` enthaelt die Codex-Adapter und `.claude/skills/` die
Claude-Adapter. Detailwissen zu Tool-Regeln, HTML-Vorlagen und
Aktivitaetstypen steht in thematischen Referenzdateien unter `skills/`
(z.B. `skills/html-vorlagen.md`, `skills/quiz-und-fragenbank.md`), auf die
der Kern und die Adapter situationsbezogen verweisen.

Coursepilot arbeitet mit bestehenden Moodle-Kursen und nutzt ausschliesslich den
lokalen MCP-Server, keine Browser-Klicks.

V1 umfasst diese vier Skills:

- `kurspilot`: sichtbarer Einstieg, benennt den jeweils genutzten Modus offen
- `kurspilot-einrichten`: legt lokalen Kontext und Startstruktur an
- `kurspilot-planen`: erstellt und zeigt den freizugebenden Implementierungsplan
- `kurspilot-umsetzen`: schreibt freigegebene Pläne in Moodle um

**Trigger-Phrasen für Claude:**
- "Erstelle einen Moodle-Kurs für diese Unterrichtseinheit"
- "Baue den Kurs in Moodle auf"
- "Lege das Thema in Moodle an (Kurs-ID: ...)"

Coursepilot setzt im freigegebenen Plan nur die fachlich begruendeten Elemente
um. Typische planbare Bausteine sind:
- Abschnittsname und bei Bedarf ein fachlich begruendeter Abschnittseinstieg
- Phasen-Trenner, wenn die Struktur im Plan sichtbar werden soll
- Informationsblaetter als Textseiten und Aufgaben fuer echte Bearbeitung oder Abgabe
- Externe Dokumentationslinks nur bei Materialbezug

Abschnitt 0 beziehungsweise "Allgemeines" ist dabei ein normaler fachlicher
Kursabschnitt, kein Coursepilot-Prozessspeicher. Kursueberblick, Regeln oder
allgemeine Materialien koennen dort fachlich geplant landen; Versionierung,
Status, Debug-Hinweise und sonstige Prozessdaten bleiben im lokalen
Coursepilot-Arbeitsbereich.

Materialordner duerfen einen sichtbaren Wegweiser enthalten. Der kanonische
Dateiname dafuer ist ausschliesslich `KURSPILOT.md`. Dieser Wegweiser nennt den
Startkontext fuer die aktuelle Materialordner-Ebene; er ist kein Index aller
Kind-Unterrichtsvorhaben. `plan.md`, `status.md`, Journale und
Materialnotizen werden nicht im Materialordner geschrieben, sondern bleiben nur
im konfigurierten Coursepilot-Arbeitsbereich.

In V1 gibt es kein separates `kurspilot-fortsetzen` und kein separates
`kurspilot-materialien`; Weiterarbeit läuft je nach Stand über den passenden
Modus, der im sichtbaren Wechsel benannt wird.

Technische Details, HTML-Vorlagen und Entscheidungsregeln für den Aktivitätstyp
stehen in thematischen Referenzdateien unter `skills/` (z.B.
[`skills/html-vorlagen.md`](skills/html-vorlagen.md),
[`skills/implementierungsplan-workflow.md`](skills/implementierungsplan-workflow.md));
die sichtbaren Skills laden nur die fuer ihren Arbeitsschritt passende Datei.

---

## Verwendung

### Kurs-ID herausfinden

Die Kurs-ID steht in der URL des Moodle-Kurses:

```
https://moodle.example.de/moodle/course/view.php?id=42
                                                    ^^
                                               das ist die ID
```

### Beispiele

**Abschnitte eines Kurses lesen:**
> "Lies die Abschnitte von Kurs 42"

**Einen Abschnitt benennen:**
> "Benenne Abschnitt 1 in Kurs 42 als 'Unterthema 7.2 – ESP32 Webserver'"

**Komplette Unterrichtseinheit aufbauen:**
> "Baue in Kurs 42, Abschnitt 2 eine Unterrichtseinheit zum Thema ESP32 Webserver auf.
> Erstelle alle Phasen mit passenden Aktivitäten."

**Bestehende Seite bearbeiten:**
> "Lies die Module in Abschnitt 1 von Kurs 42 und ändere den Inhalt der
> Textseite 'Informationsblatt' auf einen aktualisierten Text."

**Abschlussverfolgung aktivieren (für Voraussetzungen):**
> "Lies die Module in Abschnitt 2 von Kurs 42. Aktiviere für die Aufgabe 'Arbeitsblatt' die Abschlussverfolgung: automatisch bei Einreichung."

**Aktivität sperren bis andere abgeschlossen sind:**
> "Sperre in Kurs 42 die Textseite 'Implementierung' bis die Aufgabe 'Konzept-Abgabe' abgeschlossen ist. Zeige die gesperrte Aktivität ausgegraut an."

**Datei in eine Aufgabe hochladen:**
> "Lies die Module in Abschnitt 2 von Kurs 42, finde die Aufgabe 'Arbeitsblatt' und lade die Datei `C:\\temp\\Arbeitsblatt.pdf` als zusätzliche Datei in diese Aufgabe hoch."
> Hinweis: Für `moodle_upload_assignfile` muss der Pfad absolut sein und die Datei lokal existieren (Claude kann die Datei vorher lokal generieren).

**Bild vor dem Upload zuschneiden:**
> "Schneide aus `/tmp/scan.png` den Bereich x=120, y=80, Breite=900, Hoehe=620 nach `/tmp/scan-ausschnitt.png` aus und lade danach diesen Ausschnitt in die Aufgabe hoch."
> Hinweis: `moodle_crop_image` erzeugt zuerst lokal die zugeschnittene Datei; anschließend den zurückgegebenen `filepath` mit `moodle_upload_assignfile` verwenden.

**Bild direkt in einer Aufgabenbeschreibung anzeigen:**
> "Schneide aus `/tmp/scan.png` den relevanten Bereich nach `/tmp/scan-ausschnitt.png` aus und binde diesen Ausschnitt mit Alt-Text direkt sichtbar in die Beschreibung der Aufgabe ein."
> Hinweis: Fuer sichtbare Aufgabenbilder `moodle_embed_assign_image` verwenden, nicht `moodle_upload_assignfile` – letzteres erzeugt separate zusätzliche Dateien.

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
├── Plugin/src/local_coursepilot/  <- Server-MCP (aktuell): Plugin IST der MCP-Endpunkt
│   ├── classes/external/          <- die Werkzeuge (coursepilot_*)
│   ├── skills/                    <- Skill-Korpus, wird im Gespraech ausgeliefert
│   └── tests/                     <- PHPUnit
├── Plugin/src/well-known/         <- RFC-8414/9728-Pfade fuer die Discovery
├── legacy/local_coursepilot/      <- Altstand 1.x, versorgt den lokalen Weg
├── moodle-mcp.js                  <- Lokaler MCP stdio Server (Altweg)
├── lib/, scripts/                 <- Node-Bausteine und Installer des Altwegs
├── skills/                        <- Skill-Korpus des Altwegs (kurspilot-core.md u.a.)
├── .agents/skills/, .claude/skills/  <- Skill-Adapter des Altwegs
├── docs/adr/, docs/specs/, docs/plans/
└── Plugin/local_coursepilot.zip   <- Installationsartefakt des Altstands
```

---

## Contributing: Builds

Das Repository hat bewusst keine npm-Dependencies fuer Installer-Werkzeuge.
Plattformspezifische Installer nutzen die nativen Build-Werkzeuge der jeweiligen
Build-Maschine. Wer nur an Coursepilot-Code, Moodle-Plugin oder macOS arbeitet,
muss keine Windows-Installer-Werkzeuge installieren.

Standard-Checks:

```bash
npm test
npm run build:plugin
```

---

## Fehlerbehebung

| Problem | Lösung |
|---|---|
| Hammer-Symbol fehlt in Claude Desktop | Claude Desktop neu starten; JSON-Syntax prüfen |
| `Call to undefined function add_moduleinfo()` | Plugin neu installieren (modlib.php-Fix) |
| `Incorrect string value` Datenbankfehler | Kein Emoji im Titel verwenden |
| `Access denied` | Token fuer den Dienst `Coursepilot` pruefen; der Nutzer braucht die normale Moodle-Berechtigung der Aktion im Zielkurs (z.B. Trainerrechte) plus `local/coursepilot:use` |
| `Service not found` | Token prüfen; Dienst `Coursepilot` aktiv? |
| Aktivität im falschen Abschnitt | `sectionnum` ist 0-basiert: Abschnitt 1 = `sectionnum: 1` |

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
(Server-MCP, Altweg, Skills, Tests). Es steht vollständig unter **AGPL-3.0-or-later**,
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

Teile des Altwegs basieren auf [`jtuttas/MoodleMcp`](https://github.com/jtuttas/MoodleMcp)
und behalten dessen MIT-Lizenzhinweise (siehe [`NOTICE`](NOTICE)).

**Veröffentlichung:** Das frühere Moodle Plugins Directory ist im
[Moodle Marketplace](https://marketplace.moodle.com/) aufgegangen. Eingereicht wird dort
als ZIP mit Formular; die Einreichung steht noch aus und erfolgt nach einigen Wochen
Produktivbetrieb des Server-Wegs. Das bisherige Spiegelrepository
[matthiasgruenwald/moodle-local_coursepilot](https://github.com/matthiasgruenwald/moodle-local_coursepilot)
und der Workflow
[`.github/workflows/mirror-sync.yml`](.github/workflows/mirror-sync.yml) versorgen nur noch
den eingefrorenen Altstand aus `legacy/` und entfallen mit dem Schnitt — künftig liegt das
Plugin an der Wurzel dieses Repositories.
