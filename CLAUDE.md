# Coursepilot – CLAUDE.md

Coursepilot ist die schulbezogene Weiterentwicklung von MoodleMCP: das Moodle-Plugin `local_coursepilot` (`Plugin/src/local_coursepilot/`) ist selbst der MCP-Endpunkt (Server-MCP), die Lehrkraft installiert nichts lokal. Der frühere lokale stdio-Weg (Altstand 1.x) ist entfernt (#587) und nur über Tag `v1.0.0`/Git-Historie erreichbar.

Fork von [`jtuttas/MoodleMcp`](https://github.com/jtuttas/MoodleMcp), IGS-Arbeitsversion (siehe `docs/adr/0002-...`).

- **Stack:** PHP-Plugin für Moodle (Mindestversion siehe ADR 0027). Node.js (≥24) nur für Repo-Tests und Build-Skripte, keine npm-Laufzeit-Dependencies.
- **GitHub:** `matthiasgruenwald/Kurspilot` (origin), `jtuttas/MoodleMcp` (upstream)

---

## Wichtige Dateien

| Datei/Ordner | Zweck |
|---|---|
| `Plugin/src/local_coursepilot/` | PHP-Plugin-Source des Server-MCP (echte Quelle, hier editieren) |
| `Plugin/src/local_coursepilot/skills/` | Skill-Korpus, ausgeliefert über `coursepilot_list_skills`/`coursepilot_get_skill` |
| `CONTEXT.md` | Domain-Glossar (Begriffe, Beziehungen, Beispieldialoge) |
| `docs/adr/` | Architekturentscheidungen |
| `docs/specs/` | Produktspezifikationen |
| `docs/plans/` | Repo-versionierte Implementierungspläne (lazily, nicht `~/.claude/plans/`) |

---

## Plugin-Workflow

Der Server-MCP liegt unter `Plugin/src/local_coursepilot/` und wird für Entwicklung per rsync deployt (siehe Testing). `npm run build:native-release` baut Release-ZIP und Quellstand nach `dist/native-release/`; daraus speist sich auch der Marketplace-Mirror (`.github/workflows/mirror-sync.yml`, manuell).

---

## Codex/Claude – Begriffsklärung

`CONTEXT.md` definiert **Codex-First** als Produkt-Anforderung: Lehrkräfte müssen den Skill/das Plugin zuverlässig über Codex nutzen können (Zielgruppe Kollegium). Das ist unabhängig davon, womit *hier am Repo entwickelt* wird:

- **Entwicklung:** überwiegend Claude (diese Datei ist kanonisch), teils Codex (`AGENTS.md`, dünner Verweis).
- **Nutzung durch Lehrkräfte:** muss in Codex zuverlässig laufen – bei Änderungen am Skill-Korpus/Plugin-Verhalten immer auch aus Codex-Sicht denken.

---

## Aufgabenhandling

- Vor Funktionsänderung: alle Aufrufer grep-en.
- **Coding Standards:** vor dem Schreiben oder Reviewen von Code, Tests oder Skill-Korpus [`CODING_STANDARDS.md`](CODING_STANDARDS.md) lesen.
- Pläne gehören nach `docs/plans/` (versioniert).
- Single-context Repo: `CONTEXT.md` im Root, `docs/adr/` für Architekturentscheidungen, `docs/specs/` für Produktspezifikationen.

---

## Git/gh-Workflow

Branches (ADR 0027): `main` = veröffentlichter Stand, nur Hotfixes (mit Tag, danach nach `dev` mergen). `dev` = Entwicklung für 2.1+, getestet gegen Spike (Moodle 5.1). Feature-, Forschungs- und Prototyp-Zweige zweigen von `dev` ab.

Volle Autonomie: `git add/commit/push`, `gh pr/issue` etc. ohne Rückfrage ausführen, wenn im Rahmen der Aufgabe sinnvoll. Force-Push, History-Rewrite, Branch-Löschung weiterhin nur nach Rückfrage (siehe globale Sicherheitsregeln).

---

## Testing

```bash
npm test                       # node --test, Vertragstests der nativen Linie
npm run build:native-release   # Release-ZIP aus Plugin/src/local_coursepilot
```

PHPUnit läuft nur im Spike-Container, siehe `docs/agents/testing.md`; in CI zusätzlich über `.github/workflows/native-ci.yml`.

### Plugin-Deploy auf die Spike-Instanz (Server-MCP, `local_coursepilot`)

```bash
bash scripts/deploy-plugin-spike.sh
```

Gegenstück für `Plugin/src/local_coursepilot/` (Server-MCP) gegen `https://spike.gruenwald.fun` — läuft nur auf der Kurspilot-Spike-LXC selbst (kein SSH-Umweg), siehe [`docs/plugin-deploy-spike.md`](docs/plugin-deploy-spike.md). Führt ebenfalls `upgrade.php` aus — **Pflicht nach jeder Änderung an `db/access.php` oder `db/services.php`**, sonst schlagen Kursnavigation und MCP-Tool-Aufrufe mit HTTP 500 fehl (fehlende Capability). Bewusst kein automatischer Hook, da `upgrade.php`-Läufe nicht reversibel sind — vor Schema-Änderungen `/opt/kurspilot-spike/scripts/rollback.sh snapshot`.

### Hotfix-Deploy auf die Devstack-Instanzen

```bash
bash scripts/deploy-plugin-devstack.sh <tag>
```

Entpackt Plugin und `well-known` aus dem Tag nach `/opt/plugins/*-main` und führt `upgrade.php` auf allen vier Instanzen aus, siehe [`docs/plugin-deploy.md`](docs/plugin-deploy.md).

---

## Hooks (siehe `.claude/settings.json`)

Nach Edit/Write automatisch:
- `*.js` → `node --check` (Syntax)
- `*.php` → `php -l` (Syntax, Plugin/src)
- `test/*.test.js` → `npm test`

Codex nutzt diese Hooks nicht automatisch – `.codex/hooks.json` spiegelt dieselbe Logik.

---

## Agent skills

### Issue tracker

GitHub Issues im Fork `matthiasgruenwald/Kurspilot` (origin), via `gh` CLI. Siehe `docs/agents/issue-tracker.md`.

### Triage labels

Standard-Vokabular: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix` (1:1-Mapping). Siehe `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` + `docs/adr/` im Root. Siehe `docs/agents/domain.md`.
