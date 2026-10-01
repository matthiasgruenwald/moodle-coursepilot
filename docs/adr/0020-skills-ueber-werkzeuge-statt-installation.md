# Skills über Werkzeuge ausliefern statt client-nativer Installation

Im Servermodell soll die Lehrkraft nichts mehr installieren (Karte #346, Ersetzungsschwelle
#351). Die Kurspilot-Skills sind bisher Dateien auf dem Laptop: vier Adapter, die über
relative Repo-Pfade auf 16 Referenzdateien zeigen, kopiert von `scripts/install-skills.js`
nach `~/.claude/skills/` bzw. `~/.agents/skills/`. Ohne Installer fehlen sowohl die Adapter
als auch die Pfade, auf die sie zeigen.

Wir entscheiden: Der **Skill-Korpus** wird mit dem Plugin ausgeliefert und über zwei
MCP-Werkzeuge geholt — `kurspilot_list_skills` (Katalog) und `kurspilot_get_skill(name)`
(Lieferung). Ein `instructions`-Feld im Handshake ist der Wegweiser dorthin. Es wird nichts
installiert; der Korpus kommt als Werkzeugantwort in den Chat.

## Considered Options

- **Client-native Skill-Mechanik** (Claude `~/.claude/skills/` mit Autoload und Marketplace,
  Codex `~/.agents/skills/` mit `$`-Aufruf): beste Integration — Skills stehen im Slash-Menü
  und laden über ihre `description` von selbst. Preis: setzt Dateien auf dem Laptop voraus,
  also den Installer, den diese Karte abschafft, und zwar in zwei Ausführungen.

- **MCP-Prompts** (`prompts/list`): protokollnah, in Claude Code und VS Code als
  Slash-Kommandos sichtbar. Preis: Codex unterstützt es nicht
  ([openai/codex#8342](https://github.com/openai/codex/issues/8342), offen) — verletzt
  Codex-First.

- **Skills als Kontextdateien** (über den vorhandenen `read_context_file`): null neue
  Endpunkte. Preis: der Korpus wäre Lehrkraft-Eigentum — löschbar, überschreibbar,
  versionsmäßig unbestimmt — und die Kopplung an die Werkzeugverträge, die er beschreibt,
  wäre aufgegeben.

- **Ein Werkzeug je Skill** (Muster skillsovermcp.com): läuft überall, braucht keinen
  Katalog. Preis: ~20 Einträge in der Werkzeugliste, in jeder Sitzung im Kontext — gegen das
  Leitmotiv der Karte.

- **Zwei Werkzeuge plus `instructions` (diese Entscheidung)**: läuft in jedem MCP-Client,
  der Werkzeuge kann, also in Claude und Codex auf demselben Weg; zwei Einträge in der
  Werkzeugliste; die Zweistufigkeit des Korpus bleibt erhalten. Preis: kein Slash-Menü, kein
  Autoload — der Einstieg hängt vollständig am Wegweiser.

- **SEP-2640 Skills-Extension** (`skills/list`, `skill://`-Resources): der richtige Standard,
  löst den Preis der vorigen Option auf. Preis: Draft-Status, Host-Implementierungen in
  Claude Code, codex, gemini-cli und goose sind Prototypen und PRs — darauf lässt sich eine
  Abnahme heute nicht gründen.

## Consequences

- Der Korpus liegt als Markdown-Dateien mit Frontmatter im Plugin-Verzeichnis; das
  Verzeichnis ist die Quelle, es gibt keinen zweiten Index im Code.
- **Die Dateiform ist der Vertrag, die Endpunkte sind nur ein Ausspielweg darauf.** Wenn
  Claude und Codex SEP-2640 produktiv tragen, tritt `skills/list` neben die beiden
  Werkzeuge, ohne dass am Korpus etwas geändert wird.
- Der Dispatcher setzt erstmals `instructions` — in `initialize` und `server/discover`, im
  selben Wortlaut. Ohne ihn findet kein Client die Skills, weil es keine lokale
  `description` mehr gibt, an der er anspringt.
- Beide Werkzeuge sind ohne Kursbindung und ohne Kurs-Zustimmung aufrufbar
  (`local/kurspilot:use` genügt): Der Korpus enthält keine Nutzerdaten, und die Skills sind
  es, die der Lehrkraft die Zustimmung erklären — hinter dem Zustimmungstor wären sie in
  einer Henne-Ei-Lage.
- Der Korpus ist nicht anpassbar, auch nicht durch die Administration. Was die Lehrkraft
  anpassen können muss, liegt im Kontextbereich (`vorlagen.md`, Lerndateien) und schlägt den
  Korpus im Konflikt.
- Prosa-Korrekturen sind ein Deploy: Markdown-Dateien brauchen kein `upgrade.php` und keinen
  Versionssprung. Vertragsänderungen bleiben ans Plugin-Release gebunden.
- Der `-neu`-Behelf aus Spec 0012 §9.4 entfällt: Server-Skills liegen in keinem Verzeichnis
  und kollidieren nicht mit lokal installierten.
