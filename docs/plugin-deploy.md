# Plugin-Deploy auf die Devstack-Instanzen (Hotfix)

Die vier dauerhaften Devstack-Instanzen tragen den veröffentlichten Stand von
`main` (ADR 0027). Sie hängen `/opt/plugins/local_coursepilot-main` und
`/opt/plugins/well-known-main` per Bind-Mount ein
(`/opt/moodle-devstack/docker/local.yml`), eine Quelle für alle vier.
Entwicklungsstände von `dev` laufen stattdessen auf der Spike-Instanz, siehe
[`plugin-deploy-spike.md`](plugin-deploy-spike.md).

## Ablauf

Läuft auf dem Devstack-Host selbst, kein SSH-Umweg.

1. Hotfix auf `main` committen, taggen (z. B. `v2.0.1`), danach nach `dev` mergen.
2. Optional, vor Schemaänderungen: Datenbank-Dumps der Instanzen ziehen
   (`upgrade.php` ist nicht reversibel).
3. Deploy:

   ```bash
   bash scripts/deploy-plugin-devstack.sh v2.0.1
   ```

   - entpackt `Plugin/src/local_coursepilot/` und `Plugin/src/well-known/` aus
     dem Tag nach `/opt/plugins/local_coursepilot-main` bzw.
     `/opt/plugins/well-known-main` (`rsync --delete`, entfernte Dateien
     verschwinden mit)
   - ruft `/opt/moodle-devstack/bin/deploy` auf: `admin/cli/upgrade.php
     --non-interactive` auf allen vier Instanzen, Sammelbericht am Ende,
     Exit-Code ≠ 0, wenn eine Instanz fehlschlug

4. Verifizieren: in einer Instanz *Website-Administration › Plugins ›
   Plugin-Übersicht* zeigt die neue Version von `local_coursepilot`.

Nach Änderungen an `db/access.php` oder `db/services.php` ist der
`upgrade.php`-Lauf Pflicht, sonst fehlen Capabilities oder Werkzeuge.
Bestehende Tokens bleiben gültig.
