# CI: nativer Server-MCP (Issue #268)

Reproduzierbare GitHub-Actions-Pflichtprüfung für `Plugin/src/local_coursepilot`
(nativer Server-MCP, `local_coursepilot`). Läuft auf jedem sauberen
GitHub-Actions-Runner — kein Zugriff auf die Spike-Instanz, keine
Produktivdaten, keine persönlichen Verbindungen nötig. Betrifft ausschließlich
die native Linie; der eingefrorene Altstand (`legacy/local_coursepilot`) wird
von dieser CI weder gebaut noch getestet noch verändert.

Workflow: [`.github/workflows/native-ci.yml`](../.github/workflows/native-ci.yml).

## Stabiler Gesamtcheck

Der einzige als Merge-Bedingung zu verwendende Check heißt **`Gate (required)`**
(Job `gate`). Er aggregiert `phpunit`, `js-native` und `artifact` über
`needs.*.result` und schlägt explizit fehl, wenn einer dieser Jobs
fehlschlägt **oder übersprungen wird** — ein übersprungener Pflichtjob zählt
nicht als Erfolg. Matrix-Job-Namen (z. B. „PHPUnit (MOODLE_500_STABLE)“)
dürfen sich ändern, ohne dass die Branch-Protection-Regel neu eingerichtet
werden muss, weil diese ausschließlich auf `Gate (required)` zeigt.

## Supportmatrix

| Komponente | Version | Status |
|---|---|---|
| Moodle | 5.0 (`MOODLE_500_STABLE`) | Pflicht — bestehender Nachweis (Review vom 25.09.2026) |
| Moodle | 5.1 (`MOODLE_501_STABLE`) | Pflicht — vor der in ADR 0024 vorgesehenen Mindestversionsanhebung |
| PHP | 8.4 | Pflicht |
| Datenbank | MariaDB | Pflicht |

Keine weitere Moodle-Version ist damit zugesagt. Eine Anhebung der
Mindestversion (ADR 0024) erfordert einen eigenen Prüfnachweis, keine
stillschweigende Erweiterung dieser Matrix.

## Coverage: Quellumfang und Schwelle

- Quellumfang: [`Plugin/src/local_coursepilot/tests/coverage.php`](../Plugin/src/local_coursepilot/tests/coverage.php)
  listet jede PHP-tragende Produktionsdatei/-verzeichnis des Plugins.
  Ausgeschlossen sind nur `tests/` (die Suite selbst), `amd/` und
  `templates/` (kein PHP), sowie `LICENSE`/`README.md` (keine Programmdatei).
  `lang/` ist bewusst **enthalten**.
- Moodles `tool_phpunit`-Coverage-Mechanismus zählt jede dort gelistete Datei
  auch dann in den Nenner, wenn kein einziger Test sie ausführt
  („includeUncoveredFiles"-Verhalten) — eine nie besuchte Datei senkt die
  Quote, statt aus der Zählung zu verschwinden.
- Gemessen wird ausschließlich auf dem Matrix-Leg `MOODLE_500_STABLE`
  (`measures-coverage: true` im Workflow) mit dem PCOV-Treiber. Der
  Moodle-5.1-Leg läuft die volle Suite, misst aber keine eigene Quote —
  unterschiedliche Läufe werden nicht zu einer günstigeren Quote
  zusammengerechnet.
- Schwelle: `node scripts/ci/check-coverage.js <clover.xml> --threshold=80`
  (Standardschwelle 80, `>=` besteht). Rot bei fehlendem, leerem oder
  ungültigem Bericht sowie bei einem Nenner von 0 — siehe
  `test/ci-check-coverage.test.js` für die geprüften Gate-Fälle (unter der
  Schwelle, exakt 80 %, über der Schwelle, fehlender/leerer/ungültiger
  Bericht, ungültiger Nenner).

### Bekannter Befund: Schwelle mit der vorhandenen Suite noch nicht nachgewiesen

Der Review vom 25.09.2026 (Commit `06ded34`) hat die Coverage **nicht
gemessen**. Diese CI erhebt sie jetzt erstmals automatisiert, aber der
tatsächliche Prozentsatz ist in dieser Umgebung nicht ausführbar ermittelbar
(kein PHP/PHPUnit im Entwicklungscontainer, siehe unten). Sollte der erste
reale CI-Lauf unter 80 % liegen, ist das **kein CI-Defekt** — die Schwelle
bleibt bei 80 % bestehen, siehe Issue #268 ("nicht selbst Tests weglassen
oder Schwelle senken, um grün zu erzwingen").

## Legacy- und Plattformtestumfang (Node-Suite)

`scripts/ci/run-js-tests.js` (`npm run test:ci`) führt dieselbe Suite wie
`npm test` aus, schließt aber sechs Dateien aus dem nativen Pflichtlauf aus —
jede einzeln begründet im Dateikopf des Skripts:

- `moodle-credentials.test.js`, `moodle-test-client-credentials.test.js`,
  `start-mcp.test.js`, `uninstall-kurspilot.test.js`: testen den
  plattformgebundenen OS-Credential-Store (macOS Keychain / Windows
  Credential Manager) des eingefrorenen Altstands.
- `assign-settings-freeze.test.js`: ruft `legacy/local_coursepilot` über die
  lokale `php`-CLI auf (im JS-CI-Job nicht verfügbar) und testet den
  Altstand, nicht `Plugin/src/local_coursepilot`.
- `assign-tools-crop-warning.test.js`: der macOS-Fall (#139) mockt
  `os.platform()`, während die geprüfte Logik den echten `process.platform`
  liest — auf einem Linux-Runner strukturell nicht simulierbar. Testet
  zudem `lib/` (lokaler stdio-Altstand).

Keine dieser Ausnahmen betrifft natives Verhalten unter
`Plugin/src/local_coursepilot`; keine native Testdatei wird abgeschaltet.
Die laufende Spike- oder Produktivinstanz wird durch diese CI nicht
automatisch verändert.

## Artefakt-Job

Baut das installierbare Release-ZIP (`npm run build:native-release`, #577),
entpackt es (derselbe Vertrag, den eine Administration tatsächlich
installiert — nicht nur der Quellbaum), installiert es auf einer **eigenen,
vom `phpunit`-Job getrennten** frischen Moodle-Instanz und prüft über
[`scripts/ci/verify-native-artifact.php`](../scripts/ci/verify-native-artifact.php):

1. Registrierung der Webservice-Funktion `local_coursepilot_get_version_info`
   nach dem Upgrade.
2. Discovery über den deklarierten Dienst `coursepilot`.
3. Einen autorisierten Aufruf mit einem eigens angelegten Testnutzer, der nur
   die Capability `local/coursepilot:useremote` besitzt (keine Admin-Rechte) —
   prüft die tatsächliche Capability-Kette, nicht nur „geht mit Admin-Rechten
   alles".

## Bekannte native Baseline-Fehler (Stand Review 25.09.2026, Commit `06ded34`)

Der Review nannte zwei konkrete veraltete Testerwartungen:

1. Ein PHPUnit-Fehler zu einer veralteten Erwartung auf den Beschreibungstext
   „Issue #481" — bereits vor dieser CI-Arbeit auf diesem Branch behoben
   (Commit `7c8a176`, nach dem Review-Commit `06ded34`): `tests/umlaut_test.php`
   prüft seither `docs/admin-erstanleitung.md` statt der Issue-Nummer im Text.
   Keine weitere Änderung durch #268 nötig.
2. Ein nativer Node-Vertragstest mit überholter Erwartung an die Position der
   Quiz-Slot-SQL (`quiz_slots`/`question_references`) — zum Zeitpunkt dieser
   CI-Arbeit noch offen, jetzt behoben in
   `test/coursepilot-native-catalog-contract.test.js`: die SQL liegt seit der
   Katalogextraktion (#533/#556) in `classes/catalog/quiz.php`, nicht in
   `classes/catalog/module_state.php` (dorthin verwies der Test noch).

## Frühere Bugfix-Voraussetzungen #239/#243/#244

Die historische „0 Tests"-Voraussetzung dieses Issues ist überholt: die
native Suite umfasst (Stand Review 25.09.2026) 1.204 PHPUnit-Tests. Die
geschlossenen Issues #239, #243 und #244 sind in der Übernahme des
Testfundaments (#309 u. f.) als gezielte Regressionen berücksichtigt und
werden durch diese CI nicht erneut als offene Bugfix-Voraussetzung geführt.

## Administrativer Schritt: Required Status Check

Ob `Gate (required)` als verpflichtender Branch-Protection-Check auf `main`
eingerichtet werden konnte, hängt von den Repository-Rechten zum
Ausführungszeitpunkt ab und ist im PR/Issue-Verlauf von #268 dokumentiert.
Falls nicht automatisiert eingerichtet: Repository-Einstellungen → Branches →
Branch protection rule für `main` → „Require status checks to pass before
merging" → `Gate (required)` auswählen.

## Nicht in dieser Sandbox ausführbar

Diese YAML- und PHP-Dateien wurden hier nicht gegen einen echten
GitHub-Actions-Runner oder echtes PHPUnit ausgeführt (kein PHP im
Entwicklungscontainer). Geprüft wurde stattdessen:

- `actionlint` (v1.7.12) gegen `.github/workflows/native-ci.yml` — keine
  Befunde.
- Der zugrunde liegende `moodlehq/moodle-plugin-ci`-Quellcode (Kommandos
  `install`, `phpunit`, Optionen `--coverage-clover`/`--coverage-pcov`) wurde
  gegen die tatsächliche Implementierung auf GitHub geprüft, nicht nur gegen
  Dokumentation.
- `node --check` gegen alle neuen `.js`-Dateien; die Coverage-Gate-Logik
  selbst läuft unter `test/ci-check-coverage.test.js` (8 Fälle, grün).
- `node scripts/ci/run-js-tests.js` lokal grün (1076 Tests, 0 Fehler, 43
  dokumentiert übersprungen).

Der erste reale Lauf auf GitHub Actions ist damit der erste tatsächliche
Nachweis, dass PHPUnit-Installation, Coverage-Erhebung und
Artefakt-Installation wie vorgesehen funktionieren.
