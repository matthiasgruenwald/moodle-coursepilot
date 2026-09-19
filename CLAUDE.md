# Coursepilot – CLAUDE.md

Coursepilot ist die schulbezogene Weiterentwicklung von MoodleMCP. Es gibt zwei Linien, beide unter der Komponente `local_coursepilot`, aber nie auf derselben Instanz:

- **Server-MCP (aktuell, Version 2):** `Plugin/src/local_coursepilot/` — das Moodle-Plugin ist selbst der MCP-Endpunkt, die Lehrkraft installiert nichts lokal. Hier findet alle Entwicklung statt. Läuft auf der Spike-Instanz.
- **Lokaler stdio-Weg (Altstand 1.x, eingefroren):** `legacy/local_coursepilot/` plus `moodle-mcp.js` — Node-Server auf dem Laptop. Bleibt in Benutzung auf der 5.0-Instanz, bis der Schnitt fällt, und wird dann gelöscht (ADR 0024).

Fork von [`jtuttas/MoodleMcp`](https://github.com/jtuttas/MoodleMcp), IGS-Arbeitsversion (siehe `docs/adr/0002-...`).

- **Stack:** Node.js (≥24), keine npm-Laufzeit-Dependencies. PHP-Plugin für Moodle 5.0+. Ausnahme: `lib/image-crop.js` (Gezielter Bildausschnitt) benötigt das externe CLI-Tool ImageMagick (`convert`), siehe `docs/adr/0005-imagemagick-fuer-bildausschnitt.md`.
- **GitHub:** `matthiasgruenwald/Kurspilot` (origin), `jtuttas/MoodleMcp` (upstream)
- **Primäre Entwicklungsumgebung:** macOS (lokal). Windows-Tests über Parallels (siehe unten) – kein zweites Repo nötig.

---

## Wichtige Dateien

| Datei/Ordner | Zweck |
|---|---|
| `moodle-mcp.js` | Der gesamte MCP-Server – ein File, Tool-Definitionen + stdio-Loop |
| `Plugin/src/local_coursepilot/` | PHP-Plugin-Source des Server-MCP (echte Quelle, hier editieren) |
| `legacy/local_coursepilot/` | Eingefrorener Altstand 1.x, versorgt die produktive 5.0-Instanz bis zum Schnitt |
| `Plugin/local_coursepilot.zip` | **Generiert** aus `legacy/` via `npm run build:plugin` – nicht direkt editieren |
| `SKILL.md` | Claude-Skill: baut Lernsituationen automatisch in Moodle auf |
| `CONTEXT.md` | Domain-Glossar (Begriffe, Beziehungen, Beispieldialoge) |
| `docs/adr/` | Architekturentscheidungen |
| `docs/specs/` | Produktspezifikationen |
| `docs/plans/` | Repo-versionierte Implementierungspläne (lazily, nicht `~/.claude/plans/`) |

---

## Plugin-Workflow

Der Server-MCP liegt unter `Plugin/src/local_coursepilot/` und wird per rsync deployt (siehe Testing), nicht als ZIP gebaut.

`npm run build:plugin` baut `Plugin/local_coursepilot.zip` aus **`legacy/`**, also dem eingefrorenen Altstand 1.x. Der Build für die neue Linie entsteht mit dem Release 2.0.0 (siehe `docs/plans/0004-umbenennung-auf-local-coursepilot.md`).

---

## Codex/Claude – Begriffsklärung

`CONTEXT.md` definiert **Codex-First** als Produkt-Anforderung: Lehrkräfte müssen den Skill/das Plugin zuverlässig über Codex nutzen können (Zielgruppe Kollegium). Das ist unabhängig davon, womit *hier am Repo entwickelt* wird:

- **Entwicklung:** überwiegend Claude (diese Datei ist kanonisch), teils Codex (`AGENTS.md`, dünner Verweis).
- **Nutzung durch Lehrkräfte:** muss in Codex zuverlässig laufen – bei Änderungen an `SKILL.md`/Plugin-Verhalten immer auch aus Codex-Sicht denken.

---

## Aufgabenhandling

- Vor jedem Edit: Datei lesen. Vor Funktionsänderung: alle Aufrufer grep-en.
- **Code-Sprache (ADR 0024, englische Basis):** Im Server-MCP sind Bezeichner, Klassennamen **und der Werkzeugvertrag** (Parameternamen, Rückgabeschlüssel, Werkzeugbeschreibungen) englisch. Moodle-Strings liegen ausschließlich in `lang/en/`; Übersetzungen laufen nach der Freigabe über AMOS. Ausnahme: der Skill-Korpus (`skills/`) bleibt vorerst deutsche Prosa für Lehrkräfte. Im `legacy/`-Altstand gilt die alte gemischte Regel unverändert – dort wird nichts mehr umgebaut.
- Pläne gehören nach `docs/plans/` (versioniert).
- Single-context Repo: `CONTEXT.md` im Root, `docs/adr/` für Architekturentscheidungen, `docs/specs/` für Produktspezifikationen.

---

## Git/gh-Workflow

Volle Autonomie: `git add/commit/push`, `gh pr/issue` etc. ohne Rückfrage ausführen, wenn im Rahmen der Aufgabe sinnvoll. Force-Push, History-Rewrite, Branch-Löschung weiterhin nur nach Rückfrage (siehe globale Sicherheitsregeln).

---

## Testing

```bash
npm test          # node --test, u.a. Smoke-Test für moodle-mcp.js
npm run build:plugin
```

`test/smoke.test.js`: prüft, dass der Server startet ("Moodle MCP Server gestartet"), sauber bei stdin-Ende beendet, und ohne `MOODLE_URL`/`MOODLE_TOKEN` mit Fehler abbricht.

### Plugin-Deploy auf Testmoodle

```bash
bash scripts/deploy-plugin.sh
```

Deployed den **Altstand** `legacy/local_coursepilot/` per rsync direkt auf den LXC und führt `upgrade.php` aus (SSH-Key: `~/.ssh/id_moodle_deploy`). Nach dem Deploy sind die neuen/geänderten Webservices sofort registriert. **Kein neues Token nötig** — bestehende Tokens bleiben gültig, da sich nur die Funktionsliste des Dienstes ändert, nicht die Token-Bindung.

### Plugin-Deploy auf die Spike-Instanz (Server-MCP, `local_coursepilot`)

```bash
bash scripts/deploy-plugin-spike.sh
```

Gegenstück für `Plugin/src/local_coursepilot/` (Server-MCP) gegen `https://spike.gruenwald.fun` — läuft nur auf der Kurspilot-Spike-LXC selbst (kein SSH-Umweg), siehe [`docs/plugin-deploy-spike.md`](docs/plugin-deploy-spike.md). Führt ebenfalls `upgrade.php` aus — **Pflicht nach jeder Änderung an `db/access.php` oder `db/services.php`**, sonst schlagen Kursnavigation und MCP-Tool-Aufrufe mit HTTP 500 fehl (fehlende Capability). Bewusst kein automatischer Hook, da `upgrade.php`-Läufe nicht reversibel sind — vor Schema-Änderungen `/opt/kurspilot-spike/scripts/rollback.sh snapshot`.

### Integrationstests gegen Testmoodle

`test/integration/*.test.js` rufen echte Moodle-Webservices über `test/helpers/moodle-test-client.js` auf. Ohne Konfiguration werden sie automatisch übersprungen (`npm test` bleibt grün).

**Testinstanz einrichten (einmalig):**

1. Moodle-Testinstanz mit `local_coursepilot`-Plugin installieren (siehe README, Schritte 1–3: Plugin hochladen, Webservices + REST aktivieren, Token für Dienst `Coursepilot` erstellen).
2. Einen Testkurs anlegen, Kurs-ID aus der URL notieren (`course/view.php?id=X`).
3. Moodle-URL und Token im macOS-Schluesselbund speichern:
   `node scripts/moodle-credentials.js set --url <moodle-url> --token <token>`.
4. `MOODLE_TEST_COURSEID=<kurs-id> npm test` ausführen – Integrationstests laufen jetzt mit. URL/Token gehoeren nicht in `.env`.

---

## Hooks (siehe `.claude/settings.json`)

Nach Edit/Write automatisch:
- `*.js` → `node --check` (Syntax)
- `*.php` → `php -l` (Syntax, Plugin/src)
- `moodle-mcp.js` oder `test/*.test.js` → `npm test`

Codex nutzt diese Hooks nicht automatisch – `.codex/hooks.json` spiegelt dieselbe Logik.

---

## Windows-Testing (Parallels)

Repo liegt in iCloud Drive. Parallels kann den Mac-Ordner als Shared Folder ins Windows-Gast einbinden – kein separates Repo/Checkout auf Windows nötig.

Getestet wird: `moodle-mcp.js` läuft unter Windows-Node + `claude_desktop_config.json` mit Windows-Pfaden (Backslashes, `node`-Aufruf) – siehe README-Setup-Anleitung. Für die Windows-VM: **Claude Desktop** installieren (nicht Claude Code – hier wird nicht entwickelt, nur die Lehrkraft-Konfiguration verifiziert).

---

## Agent skills

### Issue tracker

GitHub Issues im Fork `matthiasgruenwald/Kurspilot` (origin), via `gh` CLI. Siehe `docs/agents/issue-tracker.md`.

### Triage labels

Standard-Vokabular: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix` (1:1-Mapping). Siehe `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` + `docs/adr/` im Root. Siehe `docs/agents/domain.md`.
