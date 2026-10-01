#!/bin/bash
set -e

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="$REPO_ROOT/Plugin/src/local_coursepilot"
SPIKE_DEPLOY=/opt/kurspilot-spike/scripts/deploy-plugin.sh

if [[ ! -x "$SPIKE_DEPLOY" ]]; then
  echo "Spike-Deploy-Skript nicht gefunden unter $SPIKE_DEPLOY - laeuft dieses Skript auf der Kurspilot-Spike-LXC?" >&2
  exit 1
fi

"$SPIKE_DEPLOY" "$PLUGIN_DIR"

echo "Deploy auf https://spike.gruenwald.fun abgeschlossen."
