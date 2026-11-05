#!/bin/sh
# Infection runs in the plugin mount; supply the host's verified diff in that path space.
set -eu
case "$*" in
  "merge-base $GATE_MUTATION_BASE HEAD") printf '%s\n' "$GATE_MUTATION_BASE" ;;
  "diff $GATE_MUTATION_BASE --unified=0 --diff-filter=AM") cat /var/www/reports/infection.diff ;;
  "diff $GATE_MUTATION_BASE --diff-filter AM --name-only -- "*) cat /var/www/reports/infection-files.txt ;;
  *) echo "Unsupported Infection git request: $*" >&2; exit 1 ;;
esac
