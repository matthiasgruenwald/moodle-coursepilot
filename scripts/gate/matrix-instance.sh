#!/usr/bin/env bash
# Private matrix instance for `gate matrix`: own Moodle worktree (other version), own database
# (mariadb|pgsql), own PHPUnit prefix and data directory, private plugin mount. The data stays,
# the containers run only during the check. moodle-ref must be an origin/<branch> ref.
#
#   bash scripts/gate/matrix-instance.sh up <name> <moodle-ref> <mariadb|pgsql> <php> [mem-gb] [cpus]
#   bash scripts/gate/matrix-instance.sh stop <name>     # Container stoppen, Daten behalten
#   bash scripts/gate/matrix-instance.sh purge <name>    # alles entfernen
# Instance GATE_DIR: ${GATE_DIR:-/opt/kurspilot-gate}-m-<name>; containers kurspilot-gate-m-<name>-*.
set -euo pipefail
ACTION="${1:?up|stop|purge}"; NAME="${2:?profile name}"
MAIN="${GATE_DIR:-/opt/kurspilot-gate}"
REPO_MOODLE="${GATE_MOODLE_REPO:-/opt/moodle}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SRC="${GATE_REPO:-$HERE/../..}/Plugin/src/local_coursepilot/"
DIR="${MAIN}-m-${NAME}"
PROJECT="kurspilot-gate-m-${NAME}"
if [[ "$ACTION" == up ]]; then DB_TYPE="${4:-mariadb}"; else DB_TYPE="*"; fi   # stop/purge cover every database profile
[[ -f "$DIR/.env" ]] && { set -a; source "$DIR/.env"; set +a; }
GATE_PHP_VERSION="${5:-8.4}"   # script argument; inside compose() $5 would be the function argument
compose() { GATE_DIR="$DIR" GATE_PHP="$GATE_PHP_VERSION" GATE_DBTYPE="$DB_TYPE" docker compose -p "$PROJECT" --profile "$DB_TYPE" -f "$HERE/compose-matrix.yml" "$@"; }
tool_container() { docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp "$@"; }
case "$ACTION" in
  up)
    REF="${3:?moodle ref}"; MEM="${6:-3}"; CPUS="${7:-2}"
    mkdir -p "$DIR"/{moodledata,phpunitdata,db}
    chmod 777 "$DIR/moodledata" "$DIR/phpunitdata"
    [[ -f "$DIR/.env" ]] || (umask 077; echo "GATE_DB_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')" > "$DIR/.env")
    set -a; source "$DIR/.env"; set +a
    if [[ ! -e "$DIR/moodle/.git" ]]; then
      git -C "$REPO_MOODLE" rev-parse --verify -q "$REF^{commit}" >/dev/null || git -C "$REPO_MOODLE" fetch -q origin "+refs/heads/${REF#origin/}:refs/remotes/$REF"
      git -C "$REPO_MOODLE" worktree add --detach "$DIR/moodle" "$REF"
    fi
    if [[ ! -f "$DIR/moodle/config.php" ]]; then
      cp "$MAIN/moodle/config.php" "$DIR/moodle/config.php"
    fi
    [[ -x "$DIR/moodle/vendor/bin/phpunit" ]] || tool_container -v "$DIR/moodle:/app" -w /app composer:2 install --ignore-platform-reqs --no-interaction --prefer-dist
    # The plugin mount comes from the checkout; the generated phpunit.xml is rebuilt below.
    rsync -a --delete --exclude phpunit.xml "$PLUGIN_SRC" "$DIR/plugin/"
    compose up -d
    for attempt in $(seq 1 90); do
      compose exec -T "db-$DB_TYPE" bash -c 'if [ "$0" = pgsql ]; then pg_isready -U moodle -q; else mariadb-admin ping -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" --silent; fi' "$DB_TYPE" 2>/dev/null && break
      [[ "$attempt" -lt 90 ]] || { echo "matrix ${NAME}: database not ready after 180 s" >&2; exit 1; }
      sleep 2
    done
    docker update --memory "${MEM}g" --memory-swap "${MEM}g" --cpus "$CPUS" "${PROJECT}-webserver-1" >/dev/null
    docker update --memory 1g --memory-swap 1g --cpus 1 "${PROJECT}-db-${DB_TYPE}-1" >/dev/null
    # PHPUnit tables must match the plugin's database definition: re-initialise when version.php or db/ changed.
    STAMP="$(cat "$PLUGIN_SRC/version.php" "$PLUGIN_SRC"/db/* | sha256sum | cut -d' ' -f1)"
    if [[ "$(cat "$DIR/phpunitdata/phpunit/.gate-initialised" 2>/dev/null)" != "$STAMP" ]]; then
      compose exec -T -w /var/www/html webserver php public/admin/tool/phpunit/cli/util.php --buildcomponentconfigs >/dev/null
      compose exec -T -w /var/www/html webserver php public/admin/tool/phpunit/cli/init.php
      echo "$STAMP" > "$DIR/phpunitdata/phpunit/.gate-initialised"
    fi
    compose exec -T -w /var/www/html webserver php public/admin/tool/phpunit/cli/util.php --buildcomponentconfigs >/dev/null
    echo "matrix ${NAME} ready: GATE_DIR=$DIR GATE_CONTAINER=${PROJECT}-webserver-1"
    ;;
  stop) compose stop ;;
  purge)
    compose down -v
    git -C "$REPO_MOODLE" worktree remove --force "$DIR/moodle" 2>/dev/null || true
    rm -rf "$DIR"
    ;;
  *) echo "unknown action: $ACTION (up|stop|purge)" >&2; exit 2 ;;
esac
