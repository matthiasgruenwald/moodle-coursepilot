# Handoff — Fortsetzung Karte #289 (Kurspilot als Moodle-natives MCP-Plugin)

**Für:** Claude-Session **auf dieser LXC** (`/opt/moodle-coursepilot`, root,
direkter Zugriff auf Docker/nginx/Cloudflare-Tunnel — kein SSH-Umweg über
`moodle-deploy@1.2.3.31` nötig, siehe unten).
**Sprache:** Deutsch, knapp.
**Workflow:** [Wayfinder-Skill](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/289)
— Karte ist Issue #289, Tickets sind Child-Issues mit Label `wayfinder:<typ>`.
Vor jedem Ticket die Karte laden (`gh issue view 289 --comments`), Ticket
claimen (`gh issue edit <n> --add-assignee @me`), nach Abschluss Kommentar +
`gh issue close`, Karte um einen `Decisions so far`-Eintrag ergänzen.

## Wo wir stehen

Karte #289 ist offen, Destination: freigegebene Spec für `local_kurspilot`
unter `docs/specs/`. Vier Tickets sind geschlossen (drei Recherchen + die
Spike-Instanz), sieben `wayfinder:grilling`-Tickets liegen als Frontier
offen — noch keins bearbeitet.

**Geschlossen:**
- #290 Recherche MCP-Transport — reines POST/JSON-RPC reicht, keine
  instanzweite Serverkonfiguration nötig.
- #292 Recherche Client-Anforderungen — OAuth 2.1 ist Pflicht, Token-Rückfall
  trägt nicht für Claude Web/Desktop/Cowork.
- #291 Recherche OAuth2-AS im Plugin — Eigenbau, ca. 1.100 Zeilen, Spike
  3–5 PT, produktionsreif 15–25 PT.
- #293 Spike-Instanz bereitgestellt — Details unten.

**Offen (Frontier, kein Ticket bisher geclaimt):**
- #295 Grilling: Identitäts- und Sessionmodell
- #296 Grilling: Capabilities und Fernzugriffs-Schalter
- #297 Grilling: Kontextablage im privaten Moodle-Dateibereich
- #298 Grilling: Datenschutzbewertung der Kontextablage
- #299 Grilling: Ablösungspfad vom lokalen stdio-MCP
- #300 Grilling: Datenschutz-Vertrag im Plugin verankern
- #302 Grilling: Discovery-Metadaten `/.well-known/` + Installationsvoraussetzungen
- **#294 Prototyp: MCP-Endpunkt mit einem Lese-Tool** — trägt noch das
  `blocked`-Label, ist aber **de facto entblockt**: alle drei Blocker
  (#290, #292, #293) sind geschlossen. Label-Edit ist in dieser Session an
  der Auto-Mode-Sandbox gescheitert (öffentliche GitHub-Aktion geblockt) —
  bitte `gh issue edit 294 --remove-label blocked` nachholen.
- #301 Spezifikation schreiben — blockiert durch praktisch alle übrigen
  Tickets, das eigentliche Kartenziel.

**Nicht mehr offen, aber zu prüfen:** die Karte selbst (`## Notes`, Abschnitt
„Beim Kartenzeichnen entschieden") listet Testumgebung/Erreichbarkeit als
bereits entschieden — das ist jetzt durch #293 tatsächlich gebaut, nicht nur
entschieden. Kein Widerspruch, nur zur Einordnung.

## Repo-Layout auf dieser LXC

| Pfad | Inhalt |
|---|---|
| `/opt/moodle-coursepilot` | **dieses Repo**, Branch `moodle-native-mcp` (heute neu von `main` erstellt und gepusht, weil er vorher nirgends existierte — Laptop bleibt auf `main`, kein Konflikt) |
| `/opt/kurspilot-spike` | Compose-Overlay, Deploy-/Rollback-Skripte für die Spike-Instanz, siehe `README.md` dort |
| `/opt/moodle-worktrees/kurspilot-spike` | Moodle-5.0.8-Worktree der Spike-Instanz (`work-kurspilot-spike`, aus zentralem `/opt/moodle`-Repo) |
| `/opt/moodle-docker-kurspilot-spike` | eigener `moodlehq/moodle-docker`-Checkout für die Spike-Instanz |
| `/opt/plugins/local_kurspilot` | Ziel für den Plugin-Code (noch leer) |
| `/opt/moodle-devstack` | die vier regulären Testinstanzen (5.0/5.1/5.2-mariadb/5.2-pgsql), unberührt |
| `/opt/moodle` | `moo.gruenwald.fun`, Produktivinstanz (5.0.8), nur als Restore-Quelle angefasst |

`docs/agents/spike-instance-handout.md` in diesem Repo ist **überholt** —
ging von SSH-Zugriff über einen `moodle-deploy`-User aus, den es so nicht
gibt (diese Session lief direkt als root auf der LXC). Für die Spike-Instanz
gilt jetzt `/opt/kurspilot-spike/README.md` als Quelle.

## Was in dieser Session (#293) erledigt wurde

Spike-Instanz komplett gebaut und verifiziert, inklusive zweier Nacharbeiten
nach Rückmeldung von Matthias:

1. Eigener Compose-Stack `moodle-kurspilot-spike`, **Moodle 5.0.8**
   (`MOODLE_500_STABLE` — bewusst identisch zu `moo.gruenwald.fun`, nicht
   5.2, nach Rückfrage von Matthias korrigiert), eigener Worktree/Checkout/
   moodledata/Port.
2. Erreichbarkeit: `https://spike.gruenwald.fun` über Matthias' bestehenden
   Cloudflare-Tunnel von zuhause (kein neuer Tunnel auf dieser LXC nötig).
   Zwei Fixes waren nötig, weil das Standard-`moodle-docker`-Setup nur für
   lokale Entwicklung gedacht ist:
   - Docker-Webserver-Port von `127.0.0.1` auf die LXC-eigene Netzwerk-
     adresse umgebunden (sonst kam der Tunnel-Traffic nicht durch).
   - `config.php` von der starren `MOODLE_DOCKER_WEB_HOST`-Vorlage auf
     dynamische `wwwroot`-Ableitung aus `HTTP_HOST`/`X-Forwarded-Proto`
     umgestellt (Vorbild: `/opt/moodle/config.php`).
3. Daten: DB-Restore aus `moo.gruenwald.fun` (**vorher mit Matthias
   bestätigt**, dass dort nur Testaccounts liegen — Klassifizierer-Sperre
   hatte einen direkten `SELECT` auf `mdl_user` geblockt, zu Recht).
   `moodledata/filedir` (141 MB, echte Dateien der Testaccounts) per
   `rsync` nachgezogen, weil ein reiner DB-Restore keine Dateien mitbringt
   (führte sonst zu `getimagesize()`-Warnungen).
4. Deutsches Sprachpaket installiert (`\tool_langimport\controller`, CLI-
   Skript, da `admin/tool/langimport` kein natives CLI-Tool hat) — nötig,
   weil `moodledata` frisch ist und das Sprachpaket nicht aus der DB kommt.
5. `local_kurspilot`-Mount in `docker/local.yml` bewusst **auskommentiert**,
   solange das Plugin noch kein `version.php` hat (leeres gemountetes
   Verzeichnis löste sonst bei jedem Seitenaufruf eine `plugin_manager`-
   Warnung aus). `scripts/deploy-plugin.sh` reaktiviert die Zeile automatisch
   beim ersten echten Deploy.
6. `scripts/rollback.sh` (Snapshot/Restore) angelegt.
7. Karte #289 um den `Decisions so far`-Eintrag für #293 ergänzt.

**Nicht in dieser Session erledigt:** die vier regulären Devstack-Instanzen
prüfen lassen ergab, dass sie bereits aktuell sind (5.0.8/5.1.6/5.2.2 = die
jeweils neuesten verfügbaren Tags, `5.0.9` existiert upstream noch nicht) —
also nichts zu tun, nur zur Info, falls die Frage nochmal aufkommt.

## Zugang Spike-Instanz

- URL: `https://spike.gruenwald.fun`
- Admin-Zugang: `/opt/moodle-devstack-secrets/kurspilot-spike.env` (0600)
- Deploy: `bash /opt/kurspilot-spike/scripts/deploy-plugin.sh <pfad-zu-local_kurspilot>`

## Nächste Schritte

1. `blocked`-Label von #294 entfernen (siehe oben, Sandbox hat es in dieser
   Session verhindert).
2. Eines der sieben offenen Grilling-Tickets claimen und mit `/grilling` +
   `/domain-modeling` bearbeiten (Karte-Notes: „Skills, die jede Session
   konsultiert"). Keine feste Reihenfolge vorgegeben von der Karte — inhaltlich
   hängt #295 (Identität/Session) am engsten mit dem bereits recherchierten
   OAuth-Entschluss zusammen, wäre also ein naheliegender Einstieg.
3. Sobald Identität/Capabilities (#295, #296) stehen, ist #294 (Prototyp)
   inhaltlich sauber angehbar — auch technisch schon möglich, da die
   Spike-Instanz bereitsteht.
