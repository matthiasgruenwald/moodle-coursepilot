#!/bin/bash
# PHPUnit wrapper for the kill matrix (#753), used as Infection `phpUnit.customPath` inside the gate container.
# Infection 0.29 writes stopOnDefect="true" into every per-mutant PHPUnit XML, so only the first failing test
# is seen. Per mutant this wrapper (1) switches all stopOn* off, (2) logs JUnit, (3) keeps PHPUnit's text output
# (warnings are not in JUnit), (4) keeps the mutated source. Data dir: <dir of this script>/data/<hash>.*.
# The exit code of PHPUnit is passed through unchanged (exec), so Infection's status logic stays the same.
set -u
args=("$@"); cfg=""
for i in "${!args[@]}"; do
  if [[ "${args[$i]}" == "--configuration" ]]; then cfg="${args[$((i+1))]}"; elif [[ "${args[$i]}" == --configuration=* ]]; then cfg="${args[$i]#--configuration=}"; fi
done
REAL=/var/www/html/vendor/bin/phpunit
[[ -n "$cfg" && -f "$cfg" ]] || exec "$REAL" "${args[@]}"
data="$(cd "$(dirname "$0")" && pwd)/data"; mkdir -p "$data"
sed -i -E 's/stopOn(Defect|Failure|Error|Warning|Risky|Deprecation|Notice)="true"/stopOn\1="false"/g' "$cfg"
id="$(basename "$cfg" .xml)"; id="${id#phpunitConfiguration.}"; id="${id%.infection}"
cp "$(dirname "$cfg")/mutant.${id}.infection.php" "$data/${id}.php" 2>/dev/null
# exec keeps one PID: when Infection kills a timed-out mutant it kills PHPUnit itself, no orphan stays behind.
exec "$REAL" "${args[@]}" --log-junit "$data/${id}.junit.xml" > >(tee "$data/${id}.out") 2>&1
