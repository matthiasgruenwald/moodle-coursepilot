#!/usr/bin/env bash
# Spiegelt den Plugin-Quellstand dieses Checkouts in den Gate-Container-Mount.
set -euo pipefail
GATE_DIR="${GATE_DIR:-/opt/kurspilot-gate}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
rsync -a --delete "$REPO/Plugin/src/local_coursepilot/" "$GATE_DIR/plugin/"
