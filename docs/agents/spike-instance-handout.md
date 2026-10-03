# Handout: Spike-Instanz auf dem LXC aufsetzen

> **Historisch (Aufbau der Spike-Instanz, Sommer 2026).** Die hier genannten Skripte des Altstands (`scripts/deploy-plugin.sh`, `scripts/moodle-credentials.js`) sind mit #587 entfernt; aktueller Weg: [`docs/plugin-deploy-spike.md`](../plugin-deploy-spike.md).

Für eine Claude-Session, die **mit echtem Zugriff auf den LXC** läuft (SSH
erreichbar, z. B. direkt auf dem Host oder per Tunnel/VPN) — von einer
Laptop-Session aus ist `moodle-deploy@1.2.3.31` derzeit nicht erreichbar
(`No route to host`), daher dieses Handout statt direkter Ausführung.

**Ticket:** [Spike-Instanz als eigener Docker-Stack bereitstellen (#293)](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/293)
**Karte:** [Karte: Kurspilot als Moodle-natives MCP-Plugin (#289)](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/289)

Entschieden (nicht neu aufrollen, siehe Karte #289): eigener Docker-Stack
neben den vier regulären Containern, Nutzerdaten aus einem Dump (nur
Testaccounts), öffentlich erreichbares HTTPS über eine eigene Subdomain.

## 0. Discovery zuerst

Vor jeder Änderung den bestehenden Zustand lesen — nichts annehmen:

```bash
ssh -i ~/.ssh/id_moodle_deploy moodle-deploy@1.2.3.31

docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Ports}}'
docker network ls
ls -la /opt/moodle
cat /opt/moodle/docker-compose.yml 2>/dev/null || find / -maxdepth 4 -iname '*compose*.yml' 2>/dev/null
```

Reverse-Proxy identifizieren (Traefik/nginx-proxy+acme/Caddy/manuell) —
Labels auf laufenden Containern oder Config-Dateien prüfen:

```bash
docker inspect $(docker ps -q) --format '{{.Name}}: {{json .Config.Labels}}' | grep -i -E 'traefik|proxy|host|cert'
find / -maxdepth 5 \( -iname 'Caddyfile' -o -iname 'traefik*.yml' -o -iname 'nginx*.conf' \) 2>/dev/null
```

Ergebnis hier festhalten (im Deploy-Log oder als Kommentar am Ticket) —
der Rest des Handouts hängt davon ab, welches Proxy-Muster existiert.

## 1. Stack aufsetzen

- Eigenes Verzeichnis, z. B. `/opt/moodle-spike/`, getrennt von `/opt/moodle`.
- Moodle 5.x + DB (gleiche DB-Engine wie Produktiv-Stack, siehe Discovery)
  als eigener Compose-Stack, eigene benannte Volumes, eigene Container-Namen
  (Präfix `moodle-spike-`, nicht `moodle-docker-*`).
- Eigene Ports (Host-seitig), damit keine Kollision mit den vier laufenden
  Containern.

## 2. Erreichbarkeit

- Eigene Subdomain wählen (z. B. `spike.<bestehende-domain>`) — muss
  öffentlich auflösbar sein, kein lokaler-only DNS-Eintrag: OAuth-Redirect
  und Cloud-Clients (Claude Web/Desktop/Cowork) brauchen eine echte,
  global erreichbare URL mit gültigem Zertifikat.
- Ins gefundene Reverse-Proxy-Muster einhängen (Label am neuen Container
  bei Traefik, `VIRTUAL_HOST`/`LETSENCRYPT_HOST` bei nginx-proxy, Block in
  der Caddyfile bei Caddy).
- Zertifikat-Ausstellung verifizieren (`curl -I https://spike.<domain>`).

## 3. Daten

- Dump der bestehenden Instanz ziehen (DB + moodledata), **nur
  Testaccounts** — vor dem Restore prüfen, dass keine echten Lerndaten im
  Dump landen.
- Restore in den neuen Spike-Stack. Kein Rückweg: der Spike-Stack schreibt
  nie zurück in die Produktion.

## 4. Sicherungen (Pflicht vor dem ersten Hochfahren mit echten Daten)

```php
// config.php im Spike-Stack
$CFG->noemailever = true;
```

- Cron im Spike-Container aus oder auf No-Op gesetzt, damit die Kopie
  nichts nach außen schickt (Erinnerungsmails, Digest etc.).
- Gegenprobe: kurz einen Testversand auslösen und verifizieren, dass er
  nicht rausgeht (Logs prüfen, nicht nur Config vertrauen).

## 5. Deploy-Kette

Vorbild: `scripts/deploy-plugin.sh` (rsync + `upgrade.php`). Für den Spike
einen zweiten Skript-Pfad anlegen (z. B. `scripts/deploy-plugin-spike.sh`),
der `local_kurspilot` in den Spike-Stack bringt:

- Ziel-Pfad: `/opt/moodle-spike/local/kurspilot/` (eigener Plugin-Ordner,
  eigener Komponentenname — `local_coursepilot` bleibt unberührt, siehe
  Karte #289).
- `docker exec <spike-webserver-container> php /var/www/html/admin/cli/upgrade.php --non-interactive`
  gegen den **Spike**-Container, nicht `moodle-docker-webserver-1`.
- Bestehender Weg für `local_coursepilot` (`scripts/deploy-plugin.sh`)
  bleibt unverändert und zeigt weiter auf die alte Instanz.

## 6. Testkonfiguration

- Eigener `.env.e2e`-Eintrag (z. B. `.env.e2e.spike` oder separater
  `MOODLE_URL`/`MOODLE_TOKEN` via `node scripts/moodle-credentials.js set`
  mit eigenem Profilnamen, falls das Script das unterstützt — sonst
  gitignored Notizdatei analog `.env.plugin-deploy`).
- Bestehende Integrationstests (`npm test` mit `MOODLE_TEST_COURSEID`)
  müssen weiter auf die **alte** Instanz zeigen — nicht versehentlich
  umbiegen.

## 7. Snapshot-Regel (Rollback)

- Vor jeder riskanten Änderung: Volume-Snapshot oder DB-Dump des
  Spike-Stacks (nicht der Produktion).
- Rollback-Kommando notieren (z. B. `docker compose down -v && restore
  aus letztem Snapshot`) — muss ohne Rückfrage ausführbar sein, wenn ein
  Zwischenstand kaputtgeht.

## Ergebnis (am Ticket #293 dokumentieren)

Nach Abschluss als Kommentar an [#293](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/293)
und in die Karte übernehmen:

- Erreichbare URL der Spike-Instanz
- Zugangswege (SSH-Pfad, Container-Namen, Compose-Verzeichnis)
- Deploy-Kommando (`scripts/deploy-plugin-spike.sh` o. ä.)
- Rollback-Weg (konkretes Kommando)
- Welches Reverse-Proxy-Muster tatsächlich vorgefunden/genutzt wurde
