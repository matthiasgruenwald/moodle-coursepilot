#!/bin/bash
# PROTOTYPE #753: phpunit wrapper for Infection. Disables stopOnDefect/stopOnFailure in the per-mutant
# XML and logs JUnit per mutant (name = hash from the config filename), then execs real phpunit.
args=("$@"); cfg=""
for i in "${!args[@]}"; do
  if [[ "${args[$i]}" == "--configuration" ]]; then cfg="${args[$((i+1))]}"; elif [[ "${args[$i]}" == --configuration=* ]]; then cfg="${args[$i]#--configuration=}"; fi
done
if [[ -n "$cfg" && -f "$cfg" ]]; then
  sed -i -E 's/stopOn(Defect|Failure|Error|Warning|Risky)="true"/stopOn\1="false"/g' "$cfg"
  id="$(basename "$cfg" .xml)"; id="${id#phpunitConfiguration.}"
  mkdir -p /var/www/reports/p753/mutants; cp "$(dirname "$cfg")/mutant.${id%.infection}.infection.php" /var/www/reports/p753/mutants/ 2>/dev/null
  args+=(--log-junit "/var/www/reports/p753/junit/${id}.xml")
fi
exec /var/www/html/vendor/bin/phpunit "${args[@]}"
