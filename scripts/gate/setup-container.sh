#!/usr/bin/env bash
# Richtet den dauerhaften Gate-Container ein (Spec 0029). Wiederholbar:
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
REPO="$(cd "$HERE/../.." && pwd)"
export GATE_DIR
case "$GATE_DIR" in /tmp|/tmp/*) echo "GATE_DIR darf nicht unter /tmp liegen" >&2; exit 1;; esac
mkdir -p "$GATE_DIR"/{moodledata,phpunitdata,reports,plugin,db,ci,dev,phpstan,deptrac,node,java}
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

# 5. Statische Werkzeuge (nur im Gate-Container, nie im Release-ZIP):
#    moodle-plugin-ci (eigenes Composer-Projekt scripts/gate/plugin-ci), Node 22 und
#    die Moodle-npm-Pakete (grunt/ESLint). Jeder Schritt überspringt, was steht.
GATE_NODE_IMAGE="${GATE_NODE_IMAGE:-node:22-bookworm-slim}"
if [[ ! -x "$GATE_DIR/ci/vendor/bin/moodle-plugin-ci" ]]; then
  mkdir -p "$GATE_DIR/ci"
  cp "$HERE/plugin-ci/composer.json" "$HERE/plugin-ci/composer.lock" "$GATE_DIR/ci/"
  # Optional GITHUB_TOKEN gegen das Anfrage-Limit von github.com (wird nie ausgegeben).
  docker run --rm ${GITHUB_TOKEN:+-e COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$GITHUB_TOKEN\"}}"} \
    -v "$GATE_DIR/ci:/app" -w /app composer:2 install --ignore-platform-reqs --no-interaction --no-dev
fi
# PHPStan, deptrac, Infection aus dem Root-composer.json.
if [[ ! -x "$GATE_DIR/dev/vendor/bin/phpstan" ]]; then
  mkdir -p "$GATE_DIR/dev"
  cp "$REPO/composer.json" "$REPO/composer.lock" "$GATE_DIR/dev/"
  docker run --rm -v "$GATE_DIR/dev:/app" -w /app composer:2 install --ignore-platform-reqs --no-interaction
fi
# moodle-plugin-ci sucht seine Hilfspakete unter moodle-plugin-ci/vendor.
ln -sfn ../.. "$GATE_DIR/ci/vendor/moodlehq/moodle-plugin-ci/vendor"
if [[ ! -x "$GATE_DIR/node/bin/node" ]]; then
  mkdir -p "$GATE_DIR/node"
  docker run --rm -v "$GATE_DIR/node:/out" "$GATE_NODE_IMAGE" sh -c 'cp -a /usr/local/. /out/'
fi
# Mustache-Lint braucht das globale vnu-jar (Node-Prefix) und Java 11 (vnu 17 nutzt eine
# JavaScript-Engine, die ab Java 15 fehlt).
if [[ ! -f "$GATE_DIR/node/lib/node_modules/vnu-jar/build/dist/vnu.jar" ]]; then
  docker run --rm -v "$GATE_DIR/node:/usr/local" "$GATE_NODE_IMAGE" npm install -g --no-audit --no-fund 'vnu-jar@>=17.3.0 <18'
fi
if [[ ! -x "$GATE_DIR/java/bin/java" ]]; then
  mkdir -p "$GATE_DIR/java"
  docker run --rm -v "$GATE_DIR/java:/out" "${GATE_JAVA_IMAGE:-eclipse-temurin:11-jre}" sh -c 'cp -a /opt/java/openjdk/. /out/'
  # Nashorn-Warnung auf stderr würde die JSON-Ausgabe von vnu zerstören.
  mv "$GATE_DIR/java/bin/java" "$GATE_DIR/java/bin/java.real"
  printf '#!/bin/sh\nexec "$(dirname "$0")/java.real" -Dnashorn.args=--no-deprecation-warning "$@"\n' > "$GATE_DIR/java/bin/java"
  chmod +x "$GATE_DIR/java/bin/java"
fi
if [[ ! -d "$GATE_DIR/moodle/node_modules/.bin" ]]; then
  docker run --rm -v "$GATE_DIR/moodle:/app" -w /app "$GATE_NODE_IMAGE" npm ci --no-audit --no-fund
fi

# 6. Plugin spiegeln, Stack starten, PHPUnit-Umgebung initialisieren.
"$HERE/sync-plugin.sh"
compose up -d
until compose exec -T db bash -c 'mariadb-admin ping -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" --silent' 2>/dev/null; do sleep 2; done
if [[ ! -f "$GATE_DIR/phpunitdata/phpunit/.gate-initialised" ]]; then
  compose exec -T -w /var/www/html webserver php public/admin/tool/phpunit/cli/init.php
  touch "$GATE_DIR/phpunitdata/phpunit/.gate-initialised"
fi
echo "Gate-Container bereit: $GATE_DIR (Projekt kurspilot-gate)"
