# CI: nativer Server-MCP (Issue #268)

Reproduzierbare GitHub-Actions-Pflichtprüfung für `Plugin/src/local_coursepilot`
(nativer Server-MCP, `local_coursepilot`). Läuft auf jedem sauberen
GitHub-Actions-Runner — kein Zugriff auf die Spike-Instanz, keine
Produktivdaten, keine persönlichen Verbindungen nötig.

Workflow: [`.github/workflows/native-ci.yml`](../.github/workflows/native-ci.yml).

## Stabiler Gesamtcheck

Der einzige als Merge-Bedingung zu verwendende Check heißt **`Gate (required)`**
(Job `gate`). Er aggregiert `phpunit`, `js-native` und `artifact` über
`needs.*.result` und schlägt explizit fehl, wenn einer dieser Jobs
fehlschlägt **oder übersprungen wird** — ein übersprungener Pflichtjob zählt
nicht als Erfolg. Matrix-Job-Namen (z. B. „PHPUnit (MOODLE_501_STABLE)“)
dürfen sich ändern, ohne dass die Branch-Protection-Regel neu eingerichtet
werden muss, weil diese ausschließlich auf `Gate (required)` zeigt.

## Supportmatrix

| Komponente | Version | Status |
|---|---|---|
| Moodle | 5.1 (`MOODLE_501_STABLE`) | Pflicht — Mindestversion ab 2.1 (ADR 0027) |
| Moodle | 5.2 (`MOODLE_502_STABLE`) | Pflicht |
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
- Gemessen wird ausschließlich auf dem Matrix-Leg `MOODLE_501_STABLE`
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

### Gemessener Stand

Erster grüner Gesamtlauf: Commit `9a92ef3`, Run
[36530129453](https://github.com/matthiasgruenwald/moodle-coursepilot/actions/runs/36530129453)
— **80,03 %** Line Coverage (10.364 von 12.950 Zeilen). Die Schwelle ist damit
knapp erreicht; jede neue ungetestete Produktionszeile kann sie wieder
unterschreiten. Sie bleibt trotzdem bei 80 % (Issue #268: nicht Tests
weglassen oder Schwelle senken, um grün zu erzwingen).

## Node-Suite

`scripts/ci/run-js-tests.js` (`npm run test:ci`) führt dieselbe Suite wie
`npm test` aus. Seit dem Entfernen des Altstands 1.x (#587) gibt es weder
ausgeschlossene Dateien noch selbst übersprungene Tests. Kommt eine
plattformgebundene Testdatei hinzu, wird sie im Skript einzeln begründet
ausgeschlossen.

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
4. Denselben Aufruf als MCP-Anfrage (#578): Token über den echten
   OAuth-Weg (DCR, PKCE, Code-Einlösung), dann `server/discover`,
   `tools/list` und `tools/call` über `dispatcher::handle()` — die Seam, an
   die `mcp.php` den HTTP-Rumpf übergibt. Ohne Token 401. Handshake,
   Werkzeugantwort und installierte Version müssen `version.php` des ZIPs
   entsprechen.

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

Außerdem meldete die Suite eine Notice: `oauth_lib::active_tokens()` las nur
Vor- und Nachname, `fullname()` in der Verbindungsübersicht löste deshalb
Moodles `debugging()` zu fehlenden Namensfeldern aus. Behoben in #578; der
Test baut seine Zeilen jetzt über `active_tokens()` und verlangt, dass kein
`debugging()` fällt. Übrig bleibt ein beabsichtigter Skip
(`webdav_storage_port_test::test_write_rejects_when_quota_is_exceeded`: WebDAV
unterliegt nicht der Moodle-Nutzerquote).

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

## Nachweis

Der Workflow ist seit Run
[36530129453](https://github.com/matthiasgruenwald/moodle-coursepilot/actions/runs/36530129453)
auf echten GitHub-Actions-Runnern grün; PHPUnit-Installation,
Coverage-Erhebung und Artefakt-Installation sind damit real nachgewiesen.
Die Vor-Merge-Abnahme mit Commit, ZIP-Prüfsumme und allen Kennzahlen steht in
[`docs/release/2.0.0-alpha-vor-merge-abnahme.md`](release/2.0.0-alpha-vor-merge-abnahme.md).
