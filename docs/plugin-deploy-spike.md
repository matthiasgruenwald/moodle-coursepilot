# Plugin-Deploy auf die Kurspilot-Spike-Instanz

Schritt-für-Schritt-Anleitung, um Änderungen in `Plugin/src/local_coursepilot/`
auf die native-MCP-Testinstanz (`https://spike.gruenwald.fun`) zu deployen.
Gegenstück zu [`plugin-deploy.md`](plugin-deploy.md) (dort: `local_coursepilot`
auf dem alten LXC-Container) — betrifft ausschließlich `local_coursepilot`,
`local_coursepilot` bleibt unberührt.

## Voraussetzung: läuft nur auf der Kurspilot-Spike-LXC

Anders als `scripts/deploy-plugin.sh` (SSH auf einen entfernten Host) läuft
`scripts/deploy-plugin-spike.sh` **lokal auf derselben LXC**, auf der auch
der Spike-Docker-Stack (`/opt/kurspilot-spike`) liegt — kein SSH-Umweg.
Von einem Laptop-Checkout aus schlägt das Skript mit einer klaren
Fehlermeldung fehl (`/opt/kurspilot-spike/scripts/deploy-plugin.sh` fehlt).
Details zum Stack: `/opt/kurspilot-spike/README.md`.

## Überblick

```bash
bash scripts/deploy-plugin-spike.sh
```

Macht (per Wrapper um `/opt/kurspilot-spike/scripts/deploy-plugin.sh`):

1. rsync von `Plugin/src/local_coursepilot/` nach `/opt/plugins/local_coursepilot`
   (Bind-Mount des Spike-Containers) — inkl. `Plugin/src/well-known/`
   (RFC-8414/9728-Discovery-Pfade, Geschwisterverzeichnis).
2. `docker compose up -d` im Spike-Stack.
3. `admin/cli/upgrade.php --non-interactive` **im Spike-Container** —
   registriert neue/geänderte Webservices (`db/services.php`) und vor
   allem neue **Capabilities** (`db/access.php`). Genau dieser Schritt hat
   in der Vergangenheit gefehlt und zu `ErrorException: Capability
   "local/coursepilot:viewhistory" was not found` geführt (Kursnavigation
   *und* MCP-Tool-Aufrufe schlugen dadurch mit HTTP 500 fehl, siehe
   `local_coursepilot_extend_navigation_course` in `lib.php`).
4. `admin/cli/purge_caches.php` **im Spike-Container** — `upgrade.php`
   räumt die Sprachdateien *nicht* ab, wenn sich die Plugin-Version nicht
   geändert hat. Geänderte Strings in `lang/de|en` bleiben sonst unsichtbar:
   der Endpunkt liefert weiter den alten Text oder, bei einem ganz neuen
   String, den blanken Bezeichner (`local_coursepilot/readonlyvocabularyfield`).
   Im Codex-Livetest zu #400 sah das nach einem Plugin-Fehler aus, war aber
   nur der Cache — PHPUnit fällt darauf nie herein, weil die Testumgebung
   ihre Caches ohnehin frisch aufbaut.

Erfolgreicher Lauf endet mit `Deploy auf https://spike.gruenwald.fun
abgeschlossen.` — `set -e`, das Skript bricht bei Fehlern selbst ab.

## Vor Schema-Änderungen: Snapshot

`admin/cli/upgrade.php`-Läufe sind nicht reversibel. Vor einem Deploy, der
neue Tabellen/Capabilities einführt (z. B. nach einem Ticket mit neuer
`db/install.xml`- oder `db/access.php`-Änderung):

```bash
/opt/kurspilot-spike/scripts/rollback.sh snapshot
```

Rollback bei kaputtem Zwischenstand:

```bash
/opt/kurspilot-spike/scripts/rollback.sh restore <dump-datei>
```

## Wann ausführen

Nach jeder Änderung an `Plugin/src/local_coursepilot/` (Code, `db/access.php`,
`db/services.php`, `version.php`), bevor gegen die Spike-Instanz getestet
wird — Codex-Sitzungen, PHPUnit (`scripts/phpunit.sh`) und manuelle
MCP-Tool-Aufrufe sehen sonst einen veralteten Stand. Analog zum bestehenden
Muster für `local_coursepilot`: kein automatischer Hook auf jeden
Datei-Edit (ein `upgrade.php`-Lauf ist ein nicht-reversibler DB-Schritt,
siehe oben — deshalb bewusst kein `PostToolUse`-Hook in
`.claude/settings.json`, anders als die reinen Syntaxchecks/`npm test` dort).
Stattdessen: nach Abschluss einer Änderung (eigener Checkpoint, z. B. vor
`scripts/phpunit.sh` oder vor einem Codex-Testlauf) `bash
scripts/deploy-plugin-spike.sh` explizit ausführen.

## Verifizieren

```bash
bash /opt/kurspilot-spike/scripts/phpunit.sh
```

oder ein MCP-Tool-Aufruf aus Codex/Claude gegen `https://spike.gruenwald.fun/local/coursepilot/mcp.php`.
