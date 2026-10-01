# Implementierungsplan: Umbenennung auf `local_coursepilot`, AGPL und englische Basis

Quelle: Entscheidungen aus dem Architektur-Review vom 18./19.09.2026
(ADR [0024](../adr/0024-englische-basis-und-komponente-coursepilot.md),
ADR [0025](../adr/0025-agpl-fuer-das-gesamte-projekt.md)).

Dieser Plan beschreibt den mechanischen Umbau **vor** dem Produktivbetrieb auf der
Spike-Instanz. Er ist bewusst ein eigener Schritt vor den Architekturkandidaten A, B, C
und E: Der Komponentenname ist nach der Marketplace-Einreichung nicht mehr änderbar, und
jeder Tag Produktivbetrieb macht den Wechsel teurer (plugineigene Tabellen).

## Ausgangslage

| Gegenstand | Stand |
|---|---|
| Komponente | `local_kurspilot`, Build 2026091302, Release 0.1.0, `MATURITY_ALPHA` |
| Installiert | nur Spike (`spike.gruenwald.fun`), Build 2026091302 |
| Altplugin | `local_coursepilot` 1.0.54, produktiv auf der 5.0-Instanz, Quellbaum unter `Plugin/src/local_coursepilot/` |
| DB-Snapshot | `/opt/moodle-devstack-secrets/dumps/kurspilot-spike-20260919-114044.sql` |

Zu ersetzen im Plugin: `local_kurspilot` (2.025 Treffer), `kurspilot_` als Werkzeugpräfix
(689), `local/kurspilot` als Pfad (211), Produktname „Kurspilot" in Prosa (667),
8 Tabellen, 4 Capabilities, 2 Sprachdateien, 4 Skill-Dateinamen.

**Längengrenzen geprüft:** Tabellennamen dürfen 53 Zeichen haben
(`xmldb_table::NAME_MAX_LENGTH` = 63 − 10); der längste neue Name
(`local_coursepilot_cm_version_file`) hat 33. Webservice-Funktionsnamen dürfen 200 Zeichen
haben. Kein gekürztes Präfix nötig.

## Reihenfolge

### S0 — Platz schaffen, ohne das laufende Altplugin zu gefährden

- `Plugin/src/local_coursepilot/` → `legacy/local_coursepilot/` (`git mv`).
- `scripts/deploy-plugin.sh` auf den neuen Pfad zeigen lassen. Das Skript fährt
  `rsync --delete` auf die produktive 5.0-Instanz — es darf nie auf einen leeren oder
  falschen Quellpfad zeigen.
- `scripts/build-plugin.js` und `.github/workflows/mirror-sync.yml` auf `legacy/` nachziehen.

### S1 — Verzeichnisse und Dateinamen

- `Plugin/src/local_kurspilot/` → `Plugin/src/local_coursepilot/`.
- `lang/en/local_kurspilot.php`, `lang/de/local_kurspilot.php` → `local_coursepilot.php`.
- `skills/adapter/kurspilot*.md`, `skills/referenz/kurspilot-core.md` → `coursepilot*`.

### S2 — Bezeichner

In dieser Reihenfolge, jeweils als eigener Commit mit Zählprobe vorher/nachher:

1. Namensraum und Komponente: `local_kurspilot` → `local_coursepilot`.
2. Pfade: `local/kurspilot` → `local/coursepilot` (inklusive `$CFG->dirroot`-Zugriffe,
   `moodle_url`, Discovery-Pfade, `well-known`-Baum).
3. Werkzeugpräfix: `kurspilot_` → `coursepilot_` (MCP-Werkzeugnamen, Skill-Korpus,
   Registry, Tests).
4. Tabellen (8) und Capabilities (4).
5. Externer Dienst: Shortname `kurspilot` → `coursepilot`, Anzeigename `Kurspilot` →
   `Coursepilot`.
6. Produktname in Prosa und Kommentaren: „Kurspilot" → „Coursepilot".

`db/services.php` leitet Funktionsliste und Namen aus `tool_registry::service_functions()`
ab — dort ist nichts einzeln nachzuziehen.

### S3 — Lizenz

- Dateiköpfe aller PHP-Dateien auf AGPL-3.0-or-later (`@license`-Zeile und Boilerplate).
- `LICENSE` im Plugin ergänzen (fehlt heute ganz, der Marketplace verlangt sie im Paket).
- Haltungstext in README und Plugin-Beschreibung (Wortlaut in ADR 0025).

### S4 — Version

- `$plugin->release = '2.0.0-alpha'`, `$plugin->maturity = MATURITY_ALPHA`,
  Build hochzählen. `requires` bleibt bei Moodle 5.0 (`2025041400`), Wechsel auf 5.1 zum
  Supportende am 05.10.2026.

### S5 — Spike umstellen

1. Altes Plugin deinstallieren: `admin/cli/uninstall_plugins.php --plugins=local_kurspilot`.
2. Mount und Zielverzeichnis der Spike-Instanz auf `local/coursepilot` umstellen.
3. Neu deployen, `upgrade.php` laufen lassen.
4. OAuth-Verbindung im Client neu einrichten (Tokens lagen in den gelöschten Tabellen).

### S6 — Verifikation

- Syntax: `docker exec -i moodle-kurspilot-spike-webserver-1 php -l < <datei>` (auf dem
  Host ist kein PHP installiert, der `php -l`-Hook läuft dort ins Leere).
- `bash /opt/kurspilot-spike/scripts/phpunit.sh`.
- `npm test` für die Node-Tests, die Werkzeugnamen prüfen.
- Handshake in einer echten Sitzung: `coursepilot_list_skills` liefert Korpus und
  Ausstände.
- Gegenprobe: `grep -ri kurspilot Plugin/src/local_coursepilot` ist leer.

### S7 — Repo-Dokumentation

`CLAUDE.md` (Code-Sprachregel auf englische Basis), `CONTEXT.md`, `AGENTS.md`, `README.md`,
`RELEASE_NOTES.md`, `.agents/skills/spike-*`, `skills/spike-*.md`,
`scripts/spike-abnahme-426.sh`, `docs/plugin-deploy-spike.md`. Spec 0003 und Spec 0012
bekommen einen Überholt-Vermerk im Kopf, werden aber nicht umgeschrieben: In Spec 0003 ist
der Weg über das Plugins Directory samt Spiegelrepo überholt, in Spec 0012 der
Komponentenname `local_kurspilot` und die CI-Aussage in §8.

## Danach

Erst nach diesem Umbau die Architekturkandidaten: A (Anker vertiefen), B (ein Werkzeug,
eine Deklaration — inklusive englischem Werkzeugvertrag), C (Ortswahl als Seitenzustand),
dann E (Modulkatalog). Der englische Werkzeugvertrag aus ADR 0024 wird in B miterledigt,
nicht als eigener Durchgang.
