# Coursepilot

Coursepilot ist ein Moodle-Plugin (`Plugin/src/local_coursepilot/`), das selbst der MCP-Endpunkt ist (Server-MCP).

## Immer relevant

- Kanonische Workflow-Doku: [CLAUDE.md](CLAUDE.md)
- Vor jedem Edit Datei lesen; vor Funktionsänderungen alle Aufrufer suchen.
- Kleine, fokussierte Dateien bevorzugen.

## Befehle

- `npm test` - Node-Vertragstests (native Linie)
- `npm run build:native-release` - Release-ZIP und Quellstand nach `dist/native-release/`
- `bash scripts/deploy-plugin-spike.sh` - deployt `Plugin/src/` auf die Spike-Instanz und führt `upgrade.php` aus
- `bash scripts/deploy-plugin-devstack.sh <tag>` - Hotfix-Deploy eines Tags auf die vier Devstack-Instanzen (siehe `docs/plugin-deploy.md`)

## Mehr Kontext

- [docs/agents/workflow.md](docs/agents/workflow.md)
- [docs/agents/testing.md](docs/agents/testing.md)
- [docs/agents/domain.md](docs/agents/domain.md)
- [docs/agents/issue-tracker.md](docs/agents/issue-tracker.md)
- [docs/agents/triage-labels.md](docs/agents/triage-labels.md)
