#!/bin/bash
# Hotfix-Deploy eines Release-Tags auf die vier dauerhaften Devstack-Instanzen
# (#587, ADR 0027). Läuft auf dem Devstack-Host selbst.
#
#   bash scripts/deploy-plugin-devstack.sh v2.0.1
#
# 1. Plugin/src/local_coursepilot und Plugin/src/well-known aus dem Tag nach
#    /opt/plugins/local_coursepilot-main bzw. /opt/plugins/well-known-main
#    entpacken (Bind-Mounts in alle Instanzen, siehe
#    /opt/moodle-devstack/docker/local.yml).
# 2. /opt/moodle-devstack/bin/deploy: upgrade.php auf allen vier Instanzen.
#    Nicht reversibel – vorher Snapshot ziehen, falls das Upgrade Schemaänderungen bringt.
set -euo pipefail

TAG="${1:?Aufruf: $0 <tag>, z. B. v2.0.1}"
TARGET=/opt/plugins/local_coursepilot-main
WELLKNOWN_TARGET=/opt/plugins/well-known-main
REPO="$(cd "$(dirname "$0")/.." && pwd)"

git -C "$REPO" rev-parse --verify --quiet "refs/tags/$TAG" >/dev/null \
  || { echo "Tag $TAG nicht gefunden (git fetch --tags?)" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
git -C "$REPO" archive "$TAG" Plugin/src/local_coursepilot Plugin/src/well-known | tar -x -C "$STAGE"

rsync -a --delete "$STAGE/Plugin/src/local_coursepilot/" "$TARGET/"
rsync -a --delete "$STAGE/Plugin/src/well-known/" "$WELLKNOWN_TARGET/"
echo "$TAG nach $TARGET und $WELLKNOWN_TARGET entpackt."

/opt/moodle-devstack/bin/deploy
