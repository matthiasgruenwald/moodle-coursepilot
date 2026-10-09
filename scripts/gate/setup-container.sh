#!/usr/bin/env bash
# Richtet den dauerhaften Gate-Container ein (Spec 0029, #660). Wiederholbar:
# jeder Schritt prüft seinen Zustand und überspringt, was schon steht.
#
#   bash scripts/gate/setup-container.sh            # alles
#   GATE_DIR=/opt/kurspilot-gate  GATE_MOODLE_REF=origin/MOODLE_501_STABLE
#
# Voraussetzung: Moodle-Core-Klon unter $GATE_MOODLE_REPO (Standard /opt/moodle),
# Docker mit Compose v2. Das DB-Passwort liegt nur in $GATE_DIR/.env (0600).
set -euo pipefail

GATE_DIR="${GATE_DIR:-/opt/kurspilot-gate}"
GATE_MOODLE_REPO="${GATE_MOODLE_REPO:-/opt/moodle}"
GATE_MOODLE_REF="${GATE_MOODLE_REF:-origin/MOODLE_501_STABLE}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export GATE_DIR
mkdir -p "$GATE_DIR"/{moodledata,phpunitdata,reports,plugin,db}
chmod 777 "$GATE_DIR/phpunitdata"

# 1. Passwort einmalig erzeugen (nie ausgeben).
if [[ ! -f "$GATE_DIR/.env" ]]; then
  (umask 077; echo "GATE_DB_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')" > "$GATE_DIR/.env")
fi
set -a; source "$GATE_DIR/.env"; set +a
compose() { docker compose -f "$HERE/compose.yml" "$@"; }

# 2. Moodle-5.1-Core als eigener Worktree (fester Stand, kein Mitlaufen).
if [[ ! -d "$GATE_DIR/moodle/.git" && ! -f "$GATE_DIR/moodle/.git" ]]; then
  git -C "$GATE_MOODLE_REPO" worktree add --detach "$GATE_DIR/moodle" "$GATE_MOODLE_REF"
fi

# 3. config.php (Datenbank und Pfade kommen aus der Container-Umgebung).
if [[ ! -f "$GATE_DIR/moodle/config.php" ]]; then
  cat > "$GATE_DIR/moodle/config.php" <<'PHP'
<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype = getenv('MOODLE_DOCKER_DBTYPE');
$CFG->dblibrary = 'native';
$CFG->dbhost = 'db';
$CFG->dbname = getenv('MOODLE_DOCKER_DBNAME');
$CFG->dbuser = getenv('MOODLE_DOCKER_DBUSER');
$CFG->dbpass = getenv('MOODLE_DOCKER_DBPASS');
$CFG->prefix = 'm_';
$CFG->dboptions = ['dbcollation' => getenv('MOODLE_DOCKER_DBCOLLATION')];
$CFG->wwwroot = 'http://localhost';
$CFG->dataroot = '/var/www/moodledata';
$CFG->admin = 'admin';
$CFG->directorypermissions = 0777;
$CFG->phpunit_dataroot = '/var/www/phpunitdata';
$CFG->phpunit_prefix = 't_';
define('PHPUNIT_LONGTEST', true);
require_once(__DIR__ . '/lib/setup.php');
PHP
fi

# 4. Core-Abhängigkeiten inkl. PHPUnit (einmalig; composer läuft im Wegwerf-Container).
if [[ ! -x "$GATE_DIR/moodle/vendor/bin/phpunit" ]]; then
  docker run --rm -v "$GATE_DIR/moodle:/app" -w /app composer:2 \
    install --ignore-platform-reqs --no-interaction --prefer-dist
fi

# 5. Plugin spiegeln, Stack starten, PHPUnit-Umgebung initialisieren.
"$HERE/sync-plugin.sh"
compose up -d
until compose exec -T db mariadb-admin ping -h127.0.0.1 -uroot -p"$GATE_DB_PASSWORD" --silent 2>/dev/null; do sleep 2; done
if [[ ! -f "$GATE_DIR/phpunitdata/phpunit/.gate-initialised" ]]; then
  compose exec -T -w /var/www/html webserver php public/admin/tool/phpunit/cli/init.php
  touch "$GATE_DIR/phpunitdata/phpunit/.gate-initialised"
fi
echo "Gate-Container bereit: $GATE_DIR (Projekt kurspilot-gate)"
