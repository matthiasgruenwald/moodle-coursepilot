#!/usr/bin/env bash
# Extra isolated gate instance for the #673 full run: own Moodle copy, plugin mount, database and
# PHPUnit data directory (never the same database twice). Containers keep the `kurspilot-gate-`
# prefix so the host-load guard counts them as own. Read-only tooling is shared with the main stack.
#
#   bash scripts/gate/mutation-full-instance.sh up <id> [mem-gb] [cpus]   # GATE_DIR=/opt/kurspilot-gate-<id>
#   bash scripts/gate/mutation-full-instance.sh down <id>                  # removes only this instance
set -euo pipefail
ACTION="${1:?up|down}"; ID="${2:?instance id}"
MAIN="${GATE_DIR:-/opt/kurspilot-gate}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIR="${MAIN}-${ID}"
PROJECT="kurspilot-gate-${ID}"
compose() { GATE_DIR="$DIR" docker compose -p "$PROJECT" -f "$HERE/compose.yml" "$@"; }
case "$ACTION" in
  up)
    MEM="${3:-3}"; CPUS="${4:-1.5}"
    mkdir -p "$DIR"/{moodledata,phpunitdata,reports,db}
    chmod 777 "$DIR/moodledata" "$DIR/phpunitdata"
    cp -a "$MAIN/.env" "$DIR/.env"
    [[ -d "$DIR/moodle" ]] || { rm -rf "$DIR/moodle.partial"; cp -a "$MAIN/moodle" "$DIR/moodle.partial" && mv "$DIR/moodle.partial" "$DIR/moodle"; }
    # The plugin mount comes from the checkout, never from the shared main mount (foreign gate runs rsync into that one).
    [[ -d "$DIR/plugin" ]] || { rm -rf "$DIR/plugin.partial"; rsync -a "$HERE/../../Plugin/src/local_coursepilot/" "$DIR/plugin.partial/" && mv "$DIR/plugin.partial" "$DIR/plugin"; }
    for d in ci dev phpstan deptrac node java; do ln -sfn "$MAIN/$d" "$DIR/$d"; done
    [[ -d "$DIR/reports/infection-coverage" ]] || cp -a "$MAIN/reports/infection-coverage" "$DIR/reports/"
    cp "$MAIN/reports/infection-bootstrap.php" "$MAIN/reports/infection-environment.json" "$DIR/reports/"
    set -a; source "$DIR/.env"; set +a
    compose up -d
    until compose exec -T db bash -c 'mariadb-admin ping -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" --silent' 2>/dev/null; do sleep 2; done
    docker update --memory "${MEM}g" --memory-swap "${MEM}g" --cpus "$CPUS" "${PROJECT}-webserver-1" >/dev/null
    docker update --memory 2g --memory-swap 2g --cpus 1 "${PROJECT}-db-1" >/dev/null
    if [[ ! -f "$DIR/phpunitdata/phpunit/.gate-initialised" ]]; then
      compose exec -T -w /var/www/html webserver php public/admin/tool/phpunit/cli/util.php --buildcomponentconfigs >/dev/null 2>&1 || true
      compose exec -T -w /var/www/html webserver php public/admin/tool/phpunit/cli/init.php
      touch "$DIR/phpunitdata/phpunit/.gate-initialised"
    fi
    echo "instance ${ID} ready: GATE_DIR=$DIR GATE_CONTAINER=${PROJECT}-webserver-1"
    ;;
  down)
    compose down -v
    rm -rf "$DIR"
    ;;
esac
