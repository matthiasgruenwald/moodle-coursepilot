# Recherche: Werkbankdatei in Originalbytes zu einem Client mit lokalem Dateizugriff

**Ticket:** [#482](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/482), Karte [#467](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/467), Anlass [#477](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/477) (Merkzettel-Punkt „Werkbank → Materialbestand“)
**Stand:** 2026-09-10

**Frage:** Wie kommt eine **Werkbank**-Datei (Moodle Private Files) in Originalbytes zu einem Client, der den **Materialbestand** lokal erreicht, damit die KI sie dort ablegen kann? Base64 durch den Modellkontext scheidet bei MB-Dateien aus.

**Quellen:**

- Moodle-Core 5.0.8 (`/opt/moodle`, Commit `3087780dc Moodle release 5.0.8`): `webservice/pluginfile.php`, `tokenpluginfile.php`, `lib/filelib.php`, `lib/moodlelib.php`, `webservice/lib.php`.
- Kurspilot `Plugin/src/local_kurspilot/` (Branch `moodle-native-mcp`, Stand `6807fe8`).
- MCP-Spezifikation, Revision 2025-11-25, [Tools › Tool Result](https://modelcontextprotocol.io/specification/2025-11-25/server/tools).
- Codex-Quelltext `github.com/openai/codex`, Commit `5c01317` (2026-09-10); Codex CLI 0.153.2 live.
- Claude Code 2.1.267 live, dazu die Zeichenketten im ausgelieferten Binary (Quelltext ist nicht offen) und [code.claude.com/docs](https://code.claude.com/docs/en/mcp).
- Herstellerdoku zu Codex-Sandbox, Cowork und Claude Desktop (URLs an der Stelle).

**Live-Proben:** Spike `https://spike.gruenwald.fun`, Konto `teacher_edit`. Der Probeschlüssel ist wieder gelöscht, im Repo liegt nichts. Die Client-Proben liefen gegen einen eigenen stdio-MCP-Server, der `text`, `resource_link` und eine eingebettete Resource mit 3000 Zufallsbytes (`blob`) zurückgibt.

---

## Kurzantwort

- **Empfehlung: kurzlebiger, einmaliger Downloadlink auf einem eigenen Kurspilot-Endpunkt, abgeholt per Shell im Client** (`curl`, unter Windows auch `Invoke-WebRequest`). Das ist der einzige Weg, der in **Codex** trägt. Dort braucht er eine Freigabe, weil die Sandbox kein Netz hat. In Claude Code trägt er ebenso.
- **`webservice/pluginfile.php` trägt nicht.** Es verlangt ein Webservice-Token aus `external_tokens` als Parameter `token`. Den OAuth-Bearer von `local_kurspilot` kennt es nicht (live: `missingparam` bzw. `invalidtoken`).
- **`tokenpluginfile.php` mit einem Core-Nutzerschlüssel trägt technisch, ist aber zu breit.** Die Bytes kommen live identisch an. Derselbe Schlüssel öffnet aber jede Datei, die die Lehrkraft sehen darf (live: ein Kurs-PDF mit 4 MB), er ist beliebig oft nutzbar, und der Core räumt abgelaufene Schlüssel nicht auf. Deshalb braucht es einen eigenen, dateigebundenen Endpunkt.
- **Eingebettete Resource (`blob`) trägt nur in Claude Code.** Claude Code legt den Blob byte-identisch als Datei ab, das Modell sieht nur den Pfad. **Codex** schreibt den Block als JSON-Text in den Modellkontext, samt Base64 (live bestätigt). **Claude Desktop** zieht ihn ebenfalls in den Kontext. Nach Codex-First fällt der Weg damit aus.
- **`resource_link` ist in keinem Client ein Download.** Codex und Claude Code machen daraus Text mit der URI. Als Träger für die URL taugt er also, mehr aber nicht.
- **Claude Desktop mit Dateisystem-Server trägt keinen Weg.** Es gibt keine Shell, `write_file` nimmt nur Text, und einen Download-Befehl gibt es nicht. Der Merkzettel-Punkt kann dort nicht abgearbeitet werden.
- **Cowork trägt nur, wenn der Moodle-Host auf der Egress-Allowlist steht.** Die Sandbox hat „No access to your network by default“. Das ist offen.

---

## 1. Was heute ausgeliefert wird

- `kurspilot_preview_material_file` gibt ein verkleinertes JPEG zurück (längste Kante 768 px). Der Dispatcher hängt es als MCP-`image`-Block an (`classes/external/preview_material_file.php:30-34`, `:110-131`; `classes/dispatcher.php:325-354`). Originalbytes gibt kein Werkzeug heraus.
- Die Werkbank liegt in `user/private`, `itemid 0` (`classes/storage_anchor.php:41-47`, `classes/material_files.php:50-56`).
- Das native MCP authentifiziert allein über `Authorization: Bearer` (`mcp.php:53-66`). Das Token ist ein OAuth-Access-Token aus der eigenen Tabelle `local_kurspilot_oauth_token` mit TTL 3600 s (`classes/oauth_lib.php:45`, `:51`, `:705-712`). Die frühere Webservice-Token-Krücke (`external_tokens`) ist entfallen (`classes/dispatcher.php:448-470`).

## 2. Downloadlink: welcher Endpunkt trägt

### 2.1 `webservice/pluginfile.php` – trägt nicht

- Das Token kommt als Parameter: `required_param('token', PARAM_ALPHANUM)` (`webservice/pluginfile.php:47`). Einen Authorization-Header liest das Skript nicht.
- `webservice::authenticate_user()` sucht es in `external_tokens` (`webservice/lib.php:79`). Ein Kurspilot-OAuth-Token steht dort nicht.
- Zusätzlich muss der Dienst `downloadfiles` erlauben (`webservice/pluginfile.php:58-61`). `local_kurspilot` hat gar keinen externen Dienst mit Token.
- **Live:** Mit `Authorization: Bearer …` antwortet der Endpunkt `missingparam` („Notwendiger Parameter "token" fehlt“), mit `?token=…` antwortet er `invalidtoken`.

Möglich wäre nur, eigens ein `external_tokens`-Token auszustellen. Das wäre ein zweites Dauertoken neben OAuth und würde genau die Krücke zurückbringen, die #337 abgeschafft hat.

### 2.2 `tokenpluginfile.php` mit Nutzerschlüssel `core_files` – trägt, aber zu breit

So läuft es im Core:

- Der Schlüssel steht im Pfad `/token/<key>/…` oder im Parameter `token`, dann folgt `require_user_key_login('core_files', null, $token)` und danach das normale `pluginfile.php` (`tokenpluginfile.php:39-49`).
- `require_user_key_login()` prüft den Schlüssel (`invalidkey`, `expiredkey` bei `validuntil < time()`, optional eine IP-Sperre). Danach meldet es die Person **vollständig** an: `\core\session\manager::set_user($user)` (`lib/moodlelib.php:2838-2856`, `:2868-2901`).
- `create_user_key($script, $userid, $instance, $iprestriction, $validuntil)` legt den Schlüssel an, `validuntil` ist frei wählbar (`lib/moodlelib.php:2913-2932`). Den Dauerschlüssel ohne `validuntil` benutzt der Core für die Token-URLs von `file_rewrite_pluginfile_urls()` (`lib/filelib.php:503`).
- Private Files liefert `file_pluginfile()` nur an die Eigentümerin aus (`$USER->id !== $context->instanceid` → 404) und erzwingt einen Download (`lib/filelib.php:4864-4882`).

**Live:** Auf dem Spike habe ich einen Schlüssel mit `validuntil = +600 s` angelegt, zwei Dateien abgerufen und den Schlüssel danach wieder gelöscht.

| Probe | Ergebnis |
|---|---|
| `…/75/user/private/kurspilot-material/caesar-rad.svg`, zweimal abgerufen | beide Male `200`, SHA-1 = `contenthash` (`81cd982c…`), `content-disposition: attachment` |
| `…/1122/mod_resource/content/0/CALLIOPE MINI Plakat.pdf` mit **demselben Schlüssel** | `200`, 4 195 024 Bytes, SHA-1 = `contenthash` |
| fremder Private-Kontext oder falscher Schlüssel | Fehler (`500` mit Moodle-Fehlerseite) |
| nach dem Löschen des Schlüssels | Fehler |

Warum das nicht reicht:

- **Keine Dateibindung.** Der Schlüssel ist eine Anmeldung. Er öffnet alle Pluginfile-Bereiche, die die Lehrkraft sehen darf, auch Kursdateien. Der `instance`-Parameter hilft nicht, denn `tokenpluginfile.php` prüft fest mit `instance = null`.
- **Nicht einmalig.** Innerhalb von `validuntil` beliebig oft nutzbar (live: zweimal `200`).
- **Kein Aufräumen.** Kein Core-Task löscht abgelaufene `user_private_key`-Zeilen. Gelöscht wird nur per `delete_user_key($script, $userid)`, also alle `core_files`-Schlüssel der Person auf einmal, und beim Löschen der Person (`lib/moodlelib.php:2941-2944`, `:3698`). Ein solches Aufräumen würde auch den Dauerschlüssel entwerten, den etwa die Mobile App benutzt.
- Die URL landet im Modellkontext und im Transkript. Dort darf nur etwas stehen, das eine Datei öffnet, einmal, für kurze Zeit.

### 2.3 Eigener Endpunkt – der tragende Weg

Die Anforderungen ergeben sich aus 2.1 und 2.2. Die Bauform entscheidet die Spec.

- **Ticket statt Sitzung:** zufällig (Muster `oauth_lib::random_token()`, `classes/oauth_lib.php:829`), serverseitig nur als Hash gespeichert. Es ist gebunden an `userid`, die Werkbankdatei (`pathnamehash`) und ihren `contenthash`. Ändert sich die Datei, ist das Ticket ungültig.
- **Kurze Lebensdauer, einmalig:** Verbraucht wird es beim Ausliefern. Ein gescheiterter Abruf braucht ein neues Ticket, also einen neuen Werkzeugaufruf. Den Wert der Lebensdauer legt die Spec fest. Für einen Befehl, der sofort nach dem Werkzeugaufruf läuft, reichen Minuten.
- **Nur die Werkbank.** Der Endpunkt öffnet ausschließlich den Werkbank-Teilbaum, niemals Kontextbereich oder Materialbestand.
- **Ausliefern wie der Core:** `NO_MOODLE_COOKIES`, `send_stored_file(..., forcedownload)` (Muster `lib/filelib.php:4880-4882`), Pfad unter `/local/kurspilot/`.
- **Das Werkzeug liefert Befehl und Prüfsumme:** Dateiname, Größe, SHA-1 (der `contenthash` ist SHA-1 des Inhalts, live bestätigt), fertige Abrufzeile für POSIX und Windows. Die KI prüft nach dem Abruf die Prüfsumme und legt die Datei erst dann in den Materialbestand. Beim Merkzettel-„Verschieben“ bietet sie das Abräumen der Werkbankdatei erst nach bestandener Prüfung an.

## 3. MCP-Ausspielweg: was die Clients mit `resource_link` und `blob` tun

Die Spezifikation sagt, was gesendet werden darf. Was der Client damit tut, sagt sie nicht. `resource_link` ist eine URI, „that can be subscribed to or fetched by the client“, und muss nicht in `resources/list` stehen. Eingebettete Resources tragen `text` oder `blob` ([2025-11-25, Tool Result](https://modelcontextprotocol.io/specification/2025-11-25/server/tools)). Ob etwas als Datei abgelegt wird, entscheidet allein der Client.

### 3.1 Codex – beides landet als Text im Kontext

- `convert_mcp_content_to_items()` kennt nur `text`, `image` und `audio`. Alles andere, auch `resource_link` und `resource`, wird zu `serde_json::to_string(content)` als `InputText` (`codex-rs/protocol/src/models.rs:2319-2419`, Zweig `:2411`). Der Unit-Test `converts_unstructured_mcp_content_to_items` belegt das für `resource_link` (`:3337-3375`).
- Liefert ein Werkzeug `structuredContent`, sieht das Modell **nur** dieses als JSON-Text (`:2277-2312`).
- Auch `read_mcp_resource` gibt das Ergebnis als serialisierten JSON-Text zurück (`codex-rs/core/src/tools/handlers/mcp_resource.rs:198-205`, `:359`).
- **Live (Codex CLI 0.153.2):** Das Modell nannte die ersten Base64-Zeichen des Blobs wörtlich, schätzte „ungefähr 4.000 Zeichen“ und meldete, dass keine Datei gespeichert wurde. Der `resource_link` kam als URL-Text an.

**Folge:** Ein MB-Blob ginge vollständig als Base64 durch den Kontext. Genau das schließt das Ticket aus.

### 3.2 Claude Code – Blob wird zur Datei, Link zu Text

Aus dem Binary 2.1.267 (Konverter für MCP-Inhaltsblöcke):

- `resource` mit `blob` und einem MIME-Typ, der **kein unterstütztes Bild** ist: Die Bytes werden als `mcp-<server>-blob-<zeit>-<zufall>.<endung>` in das `tool-results`-Verzeichnis der Sitzung geschrieben. Das Modell bekommt nur einen Text mit Pfad.
- Obergrenze pro Datei: 104 857 600 Bytes (`content is … bytes, over the … byte persist limit`).
- Bild-MIME-Typen gehen dagegen als Bildblock in den Kontext, nicht auf die Platte. Ein Tafelfoto als `image/jpeg` käme also nicht als Datei an. Das ist aus dem Code abgeleitet, nicht live geprüft.
- `resource_link` wird zu `[Resource link: NAME] URI (Beschreibung)`, als reiner Text.
- Das Werkzeug `ReadMcpResource` legt Blobs ebenfalls ab (Ausgabefeld `blobSavedTo`, „Path where binary blob content was saved“).
- Die Doku nennt nur die Textgrenzen (`MAX_MCP_OUTPUT_TOKENS`, Standard 25 000; zu große Textergebnisse gehen in eine Datei im selben Verzeichnis) ([MCP output limits](https://code.claude.com/docs/en/mcp)).

**Live (Claude Code 2.1.267, `claude -p`):** Das Modell sah keine Base64-Daten, sondern „Binary content, application/octet-stream, 2,9 KB“ und einen Pfad unter `~/.claude/projects/<projekt>/<sitzung>/tool-results/mcp-probe-blob-….bin`. Die Datei hat 3000 Bytes, und ihr SHA-1 stimmt mit dem Server überein (`3796c7ce…`).

**Folge:** In Claude Code trüge der Blob. Die Datei liegt aber außerhalb des Arbeitsordners und müsste per Shell in den Materialbestand verschoben werden. Bilder müssten sich als `application/octet-stream` tarnen. Für einen Weg, der nur einen Client bedient, ist das zu viel Sonderlogik.

### 3.3 Claude Desktop – Blob landet im Kontext

- Das offene Issue [anthropics/claude-ai-mcp#287](https://github.com/anthropics/claude-ai-mcp/issues/287) (2026-05-14) beschreibt es: `EmbeddedResource`-Blöcke „are silently consumed into the LLM context — no clickable artifact, no downloadable file“.
- Laut [modelcontextprotocol/csharp-sdk#1261](https://github.com/modelcontextprotocol/csharp-sdk/issues/1261) scheitert ein `blob` (PDF) in Claude Desktop, ein `image`-Block dagegen nicht.
- Beides sind Nutzerberichte im Issue-Tracker, keine Herstellerdoku. Live geprüft ist das nicht.

## 4. Clients: Shell, Netz, Freigabe

| Client | Blob als Datei | Link + Shell | Urteil |
|---|---|---|---|
| **Codex CLI / App / IDE** | nein (JSON-Text im Kontext) | ja, mit Freigabe | **Link trägt** |
| **Claude Code** | ja (außer Bild-MIME) | ja, mit Freigabe | **Link trägt**, Blob trüge auch |
| **Claude Desktop + Dateisystem-Server** | nein | nein, keine Shell | **trägt nicht** |
| **Cowork** | nicht belegt | nur mit Egress-Freigabe des Moodle-Hosts | **offen** |

**Codex:**

- Die Standard-Sandbox `workspace-write` hat **kein Netz**: `network_access: false` (`codex-rs/protocol/src/protocol.rs:1219-1226`). Laut Doku gilt das für CLI, IDE und App: „By default, the agent runs with network access turned off“ ([Agent approvals & security](https://learn.chatgpt.com/docs/agent-approvals-security); [Sandbox](https://learn.chatgpt.com/docs/sandboxing)).
- **Live:** `curl https://spike.gruenwald.fun/…` in `workspace-write` endet mit `Could not resolve host`, Exitcode 6. Mit `-c sandbox_workspace_write.network_access=true` kommt `200`.
- **Freigabeweg:** Mit `approval_policy = on-request`, dem empfohlenen Standard, kann das Modell einen Befehl mit `sandbox_permissions: "require_escalated"` samt Begründung außerhalb der Sandbox erbitten. Es kann auch ein wiederverwendbares Präfix vorschlagen, etwa `["curl"]` (`codex-rs/core/src/tools/handlers/shell_spec.rs:239-263`). Die Lehrkraft bestätigt einmal. Ohne Freigabemöglichkeit (`approval_policy = never`) scheitert der Abruf.
- **Dauerhafte Alternative:** Netz im Profil erlauben. Das geht pauschal (`network_access = true`) oder per Domain-Allowlist über den Codex-Netzproxy (`[permissions.<profil>.network.domains]`). Achtung: Der Proxy sperrt Hostnamen, die auf private IP-Adressen auflösen, **auch wenn sie freigegeben sind** (`codex-rs/network-proxy/README.md:47`, `:50`, `:218`). Ein Schul-Moodle im internen Netz ginge darüber nicht, nur über die Eskalation.
- Die MCP-Verbindung selbst läuft nicht in der Shell-Sandbox (Start eines stdio-Servers mit `sandbox: None`, `codex-rs/rmcp-client/src/stdio_server_launcher.rs:630`). Deshalb erreicht Codex das Moodle-MCP, obwohl die Shell kein Netz hat.

**Claude Code:**

- `curl` über das Bash-Werkzeug läuft nach der normalen Freigabe.
- Ist die Bash-Sandbox aktiv, fragt Claude Code beim ersten Zugriff auf eine neue Domain nach; geblockte Befehle laufen nach Rückfrage ohne Sandbox ([Sandboxing](https://code.claude.com/docs/en/sandboxing)).
- `WebFetch` taugt nicht, weil es Seiten in Markdown umwandelt und keine Bytes ablegt.

**Claude Desktop + Dateisystem-Server:**

- Der Referenzserver `@modelcontextprotocol/server-filesystem` hat `write_file` mit `content: string`, „Handles text content with proper encoding“, und kein Werkzeug zum Herunterladen ([src/filesystem/index.ts](https://github.com/modelcontextprotocol/servers/blob/main/src/filesystem/index.ts)).
- Bytes kämen nur über den Kontext und nur als Text an, Binärdateien also gar nicht.
- Übrig bliebe ein Link, den die Lehrkraft im Browser anklickt und selbst ablegt. Das ist genau das „mach das selbst“, das #477 ausschließt.

**Cowork:**

- Die Sandbox hat „No access to your network by default. The sandbox can't reach private, internal, link-local, or cloud-metadata addresses“. Aller Verkehr läuft über einen Pflicht-Proxy, „only allow-listed destinations are reachable“ ([Architecture overview](https://support.claude.com/en/articles/14479288-claude-cowork-architecture-overview)). Die Freigabe setzt die Organisation im Admin-Bereich ([Use Cowork safely](https://support.claude.com/en/articles/13364135-use-claude-cowork-safely)).
- Ein Moodle im Schulnetz ist damit grundsätzlich nicht erreichbar, ein öffentliches nur nach Admin-Freigabe.
- Nutzerberichte melden, dass eigene Domains trotz Freigabe blockiert werden ([anthropics/claude-code#30112](https://github.com/anthropics/claude-code/issues/30112), [#37970](https://github.com/anthropics/claude-code/issues/37970)).

## 5. Empfehlung

1. **Ein Werkzeug gibt ein Einmal-Ticket für genau eine Werkbankdatei aus** (2.3). Es liefert Name, Größe, SHA-1 und die fertige Abrufzeile. Die URL steht in einem `text`-Block, zusätzlich darf sie als `resource_link` gehen. Beides kommt in allen Clients als Text an.
2. **Der Client holt die Bytes per Shell direkt in den Materialbestand** und prüft die SHA-1. In Codex braucht der Abruf eine Freigabe (Eskalation oder Netzfreigabe). Die KI sagt vorher in einem Satz, warum Codex gleich fragt.
3. **Kein eingebetteter Blob als Hauptweg.** Codex zieht ihn in den Kontext. Als Zusatz nur für Claude Code bringt er doppelte Wege ohne Gewinn für Codex.
4. **Claude Desktop mit Dateisystem-Server fällt für diesen Merkzettel-Punkt aus.** Das passt zum dreistufigen Test aus #483, der ohnehin einen Client mit Arbeitsverzeichnis voraussetzt.

## Was offen bleibt

- **Codex im Dialog.** Geprüft sind nur `codex exec` mit `approval_policy = never` (Netz aus → scheitert) und mit Netzfreigabe (→ `200`). Nicht nachgespielt ist die Rückfrage bei `on-request` in CLI und App: Wortlaut, „für diese Sitzung erlauben“, Präfixregel `curl`. Codex App unter Windows mit `Invoke-WebRequest` bzw. `curl.exe` ist ebenfalls nicht geprüft.
- **Schul-Moodle im internen Netz.** Die Eskalation in Codex umgeht den Netzproxy, die Domain-Allowlist nicht (Privat-IP-Sperre). Nicht live geprüft.
- **Cowork.** Ob ein öffentlich erreichbares Schul-Moodle auf die Egress-Allowlist kommt und ob die Freigabe dann tatsächlich wirkt, ist nicht geprüft. Die Nutzerberichte sprechen dagegen.
- **Claude Code mit Bild-MIME.** Aus dem Code folgt, dass ein `image/*`-Blob als Bildblock in den Kontext geht. Nicht live geprüft, für die Empfehlung nicht nötig.
- **Ticketparameter.** Lebensdauer, Verbrauch bei abgebrochenem Download (einmalig vs. „bis zum ersten vollständigen Abruf“) und `Range`-Anfragen entscheidet die Spec.
