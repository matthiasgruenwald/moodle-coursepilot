# Coursepilot – Release Notes

Nutzergerichtete Release-Informationen für das Moodle-Plugin `local_coursepilot` und den
lokalen Coursepilot-MCP. Entwicklungs- und Issue-Repository ist
[matthiasgruenwald/moodle-coursepilot](https://github.com/matthiasgruenwald/moodle-coursepilot)
(primäres Repository); der Plugin-Quellbaum wird separat als Mirror für das Moodle Plugin
Directory veröffentlicht.

## Unveröffentlicht (dev) – Altstand 1.x entfernt

Der lokale stdio-Weg (Coursepilot 1.x: `legacy/local_coursepilot/`, `moodle-mcp.js`,
Installer und lokale `kurspilot-*`-Skills) ist aus dem Repository entfernt (#587). Auf
keiner Instanz läuft das Altplugin mehr. Damit entfallen `npm run build:plugin`,
`npm run release:plugin` und `npm run build:mirror`; einziger Release-Weg ist
`npm run build:native-release`, aus dem auch der Marketplace-Mirror gebaut wird. Der
Altstand bleibt über den Tag `v1.0.0` und die Git-Historie erreichbar.

## Coursepilot 2.1.0-beta – Mindestversion Moodle 5.1

- Erschlossene Aktivitätsarten (book, checklist, glossary, lightboxgallery) per Aktivitäts-XML, Ablösen mit Vorschau, Glossar-Einträge nachtragen (`add_glossary_entries`), Datei-Nachtrag mit Bildunterschriften für Lightboxgallery, mitgelieferte Vorlagen im Kontextbereich.
- Mindestversion ist jetzt Moodle 5.1 (`requires` 2025100600), die CI prüft 5.1 (ADR 0027). Moodle 5.0 gehört zur Linie 2.0.x. Moodle 5.2 wird noch nicht unterstützt (neue Spalten in `assign` und `forum`).
- Reifegrad bleibt Beta (`MATURITY_BETA`).

## Coursepilot 2.0.0-beta (Server-MCP) – Artefakt, Version, Übergang

Betrifft die native Linie unter `Plugin/src/local_coursepilot/` (Issue #577, Spec 0025
Abschnitt D). Die Angaben im vorherigen Abschnitt „Coursepilot 1.0" gelten unverändert für
den eingefrorenen Altstand (`legacy/local_coursepilot/`) und dessen `moodle-mcp.js`-Weg.

### Release-Artefakt aus der nativen Linie

`npm run build:native-release` baut aus `Plugin/src/local_coursepilot/` sowohl den
installierbaren `local_coursepilot-<release>.zip` als auch den ungezippten Quellstand
(`dist/native-release/local_coursepilot/`) – identischer Inhalt, ZIP und Quellstand können
also nicht auseinanderlaufen. Der Altstand bleibt davon unberührt und weiterhin über
`npm run build:plugin`/`npm run release:plugin` separat baubar.

### Eine kanonische Version, kein Prototypwert

`Plugin/src/local_coursepilot/version.php` (`$plugin->release`) ist die alleinige Quelle für
die Plugin-Version. Der MCP-Handshake (`initialize`/`server/discover`, `serverInfo.version`)
und das Werkzeug `coursepilot_get_version_info` lesen dieselbe Datei zur Laufzeit; der
frühere feste Platzhalter `0.1.0` wird nicht mehr gemeldet (#577).

### Lizenz und Herkunft

`Plugin/src/local_coursepilot/LICENSE` ist **AGPL-3.0-or-later** (ADR 0025) – anders als beim
Altstand, der weiterhin unter GPL-3.0-or-later steht. Der Release-Kandidat enthält eine
`NOTICE`-Datei mit dem Herkunftshinweis auf den Upstream-Fork `jtuttas/MoodleMcp` (MIT).

### Sprachen: nur Englisch im Paket, AMOS für Übersetzungen

Das Release-Paket der nativen Linie enthält ausschließlich `lang/en/`; `lang/de/` aus dem
Entwicklungsbaum wird beim Bau ausgeschlossen. Übersetzungen laufen künftig über AMOS. Der
deutsche Skill-Korpus (`skills/`) ist keine Moodle-Sprachdatei, bleibt AMOS-unabhängig und
vorerst deutsche Prosa (ADR 0024).

### Übergang von Coursepilot 1.x

Beide Linien tragen dieselbe Moodle-Komponente `local_coursepilot`, können aber nicht
gemeinsam auf einer Instanz laufen. Der Wechsel ist eine Deinstallation vor der
Installation: das laufende Altplugin zuerst deinstallieren, dann die native 2.0-ZIP
installieren. Es gibt **keine** Daten-, Einstellungs- oder Token-Migration zwischen den
Linien. Die produktiv genutzte Altinstanz wird von diesem Übergang nicht automatisch
angefasst – der Schnitt bleibt eine bewusste, separate Entscheidung.

### Unterstützte Kombination

Nachgewiesen sind **Moodle 5.0 und 5.1, PHP 8.4, MariaDB** (nativer PHPUnit-Lauf in der CI).
2.0.x verlangt Moodle 5.0 oder neuer. Ab Coursepilot 2.1 ist **Moodle 5.1** die
Mindestversion (ADR 0027). Neuere Moodle-Versionen gelten erst als unterstützt, wenn CI und
Testinstanz sie nachweisen – eine reine Metadatenänderung ist keine Kompatibilitätsabnahme.

## Coursepilot 1.0 – Produktname, Neuinstallation, Sprachen und Datenschutz

### Einheitlicher Produktname

Das Produkt heißt öffentlich **Coursepilot**. Moodle-Plugin, Konfigurator, Installer,
Skills, Dokumentation und Release-Artefakte nutzen diesen Namen einheitlich. Die
Moodle-Komponente des Plugins ist `local_coursepilot`.

### Neuinstallation erforderlich (keine Migration)

Die frühere Komponente `local_aicoursecreator` wird **nicht** migriert. Administrator:innen
einer bestehenden Installation müssen `local_aicoursecreator` zuerst **deinstallieren**
(Website-Administration → Plugins → Plugins verwalten) und anschließend `local_coursepilot`
neu installieren. Eine Daten-, Einstellungs- oder Webservice-Übernahme aus der alten
Komponente gibt es bewusst nicht.

### Moodle 5.0 oder neuer

`local_coursepilot` richtet sich an frische Moodle-Installationen und verlangt
**Moodle 5.0 oder neuer**. Moodle 4.x wird weder unterstützt noch getestet.

### Lokal konfigurierter KI-Client und Datenschutz

Das Moodle-Plugin ruft **selbst keinen KI-Anbieter** auf. Coursepilot nutzt einen **lokal**
auf dem Rechner der Lehrkraft konfigurierten KI-Client (z.B. Claude Desktop, Codex oder
opencode). Erst wenn die Lehrkraft diesen Client nutzt und dabei Kursinhalte übergibt, können
diese Inhalte an den Anbieter des jeweils konfigurierten KI-Clients übertragen werden.

Coursepilot ist ausschließlich für die Kursgestaltung durch die Lehrkraft bestimmt und gibt
**keine Lernendendaten** frei. Ausgeschlossen sind insbesondere:

- Aufgabenabgaben (Submissions)
- Forenbeiträge
- Quizversuche (Attempts)
- Bewertungen und Noten
- Teilnehmendenlisten

Diese Grenze ist als positive Allowlist umgesetzt und wird automatisch per Vertragstest
erzwungen. Die Moodle-Privacy-API des Plugins meldet über einen `null_provider` bewusst keine
Verarbeitung von Lernendendaten.

### Marketplace-Artefakt, Mirror-Export und Lizenzen

Ein wiederholbarer Release-Prozess erzeugt das Moodle-Plugin als installierbares
Archiv und als Quellinhalt für den Marketplace-Mirror:

- `npm run build:plugin` baut das installierbare `Plugin/local_coursepilot.zip`
  (ohne macOS-Metadaten wie `.DS_Store`).
- `npm run build:mirror` exportiert das Plugin als alleiniges Root eines
  schreibgeschützten Mirrors (`dist/mirror/`) – ohne MCP, Installer, Skills oder Tests.
- `npm run release:plugin` führt beide Schritte aus.

Lizenzen sind getrennt: Das Moodle-Plugin (inkl. Marketplace-ZIP) steht unter
**GPL-3.0-or-later** (`Plugin/src/local_coursepilot/LICENSE`); MCP, Installer, Skills
und Entwicklungsmaterial im primären Repository
[matthiasgruenwald/moodle-coursepilot](https://github.com/matthiasgruenwald/moodle-coursepilot) stehen
unter **AGPL-3.0-or-later**. Die Upstream-MIT-Hinweise auf `jtuttas/MoodleMcp` bleiben
erhalten (siehe `NOTICE`).

### Sprachen: Englisch als Basis, Deutsch vorübergehend

Englisch ist die Basissprache des Plugins. **Deutsch** wird in der Übergangsphase
**vorübergehend** direkt mitgeliefert, bis die Übersetzung über **AMOS** (das
Moodle-Übersetzungsportal) gepflegt wird. Sobald AMOS die deutsche Übersetzung übernimmt,
wird die mitgelieferte deutsche Sprachdatei in einem frühen Release entfernt. Dieser
Übergangscharakter ist bewusst dokumentiert, damit Marketplace-Reviewer ihn klar erkennen.
