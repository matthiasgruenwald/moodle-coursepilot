#!/usr/bin/env bash
# Spiegelt den Plugin-Quellstand dieses Checkouts in den Gate-Container-Mount.
set -euo pipefail
GATE_DIR="${GATE_DIR:-/opt/kurspilot-gate}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
rsync -a --delete "$REPO/Plugin/src/local_coursepilot/" "$GATE_DIR/plugin/"
# PHPStan-Konfiguration und Baseline (Pfade in der Baseline sind relativ zu /var/www/phpstan-config).
rsync -a --delete "$REPO/scripts/gate/phpstan/" "$GATE_DIR/phpstan/"
