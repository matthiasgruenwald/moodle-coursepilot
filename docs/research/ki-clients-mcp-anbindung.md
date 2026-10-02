# KI-Clients: Anbindung eines Remote-MCP-Servers (Kurspilot)

Stand: 02.10.2026. Recherche nur Primärquellen. Ziel: `https://<moodle>/local/coursepilot/mcp.php` (Streamable HTTP, OAuth) in Claude, ChatGPT und Codex nutzen.
Hinweis: `developers.openai.com/codex/*` leitet auf `learn.chatgpt.com/docs/*` (OpenAI-Domain) um; die Hilfeartikel `help.openai.com` lieferten beim Abruf HTTP 403, Aussagen daraus stammen nur aus Suchtreffer-Zusammenfassungen und sind als solche markiert.

## Übersicht

| Client | Tarife | OAuth / DCR | Schritte (Kurzform) |
|---|---|---|---|
| ChatGPT (Web) | Developer Mode: Pro, Plus, Business, Enterprise, Edu (nur Web) [1]. Voller Schreibzugriff laut Hilfeartikel evtl. nur Business/Enterprise/Edu, Plus/Pro nur lesend: widersprüchlich, siehe Abschnitt 1 | OAuth, keine Auth, gemischt [1]; DCR, CIMD oder vordefinierter Client [2] | Einstellungen > Security and login > Developer mode an; Plugins > Plus; MCP-URL, OAuth [1] |
| Codex CLI / IDE / ChatGPT-Desktop | Alle ChatGPT-Tarife inkl. Free/Go enthalten Codex; CLI/IDE: Plus, Pro, Business, Enterprise, oder API-Key [4] | `codex mcp login`; Registrierung via CIMD oder DCR [3] | `codex mcp add <name> --url <URL>`, dann `codex mcp login <name>` [3] |
| Claude (claude.ai, Desktop, Cowork) | Free (max. 1 Connector), Pro, Max, Team, Enterprise [5] | "Claude's published identity", "Register automatically" (DCR) oder eigener OAuth-Client [5][6] | Customize > Connectors > Add > Custom connector > URL [5] |
| Claude Code | Claude-Code-Zugang | DCR Standard; eigener Client möglich [7] | `claude mcp add --transport http <name> <URL>`, `/mcp` [7] |
| Le Chat (Mistral) | Tarife unbelegt | OAuth 2.1 mit DCR [8] | Custom MCP-Connector per URL; Schreibfunktionen mit manueller Freigabe empfohlen [8] |
| Gemini CLI | unbelegt | OAuth-Discovery + DCR [9] | in `settings.json` als `httpUrl`; Browser-Login [9] |
| GitHub Copilot | unbelegt | VS Code: OAuth; Copilot-Cloud-Agent: Remote-MCP mit OAuth nicht unterstützt [10] | unbelegt (nur GitHub-MCP-Doku geprüft) |

## 1. ChatGPT

- Developer Mode: "full MCP client support for all tools, both read and write", verfügbar für "Pro, Plus, Business, Enterprise, and Education accounts on the web", als erhöhtes Risiko markiert. Aktivieren: Settings > Security and login > Developer mode. [1] https://developers.openai.com/api/docs/guides/developer-mode
- Eigene App anlegen: in "Plugins" über das Plus-Symbol; Protokolle "SSE and streaming HTTP"; Auth: OAuth, keine, gemischt. Nutzung: im Chat Plus-Menü > Developer mode. [1]
- Schreibaktionen: standardmäßig manuelle Bestätigung, JSON-Payload einsehbar, Entscheidung pro Tool innerhalb einer Konversation merkbar; neue Konversation setzt zurück. [1]
- OAuth-Anforderungen: OAuth 2.1 nach MCP-Spec, `/.well-known/oauth-protected-resource`, PKCE S256; Client-Registrierung per CIMD (bevorzugt), DCR oder vordefiniertem Client. [2] https://developers.openai.com/apps-sdk/build/auth
- Workspace (Business/Enterprise/Edu): Admin muss Developer mode erst freischalten (Workspace Settings > Permissions & Roles > Developer mode / Create custom MCP connectors); Business-Admins können veröffentlichte Apps nicht aktualisieren (neu anlegen); Enterprise/Edu: Aktionen nach Veröffentlichung ein-/ausschaltbar, RBAC. Quelle: nur Suchtreffer-Zusammenfassung zu https://help.openai.com/en/articles/12584461-developer-mode-and-mcp-apps-in-chatgpt (Abruf 403, nicht direkt verifiziert).
- WIDERSPRUCH: dieselbe Zusammenfassung nennt volle MCP-Unterstützung nur für Business/Enterprise/Edu, Pro "read/fetch" in Developer Mode; [1] nennt dagegen "read and write" für Plus/Pro. Ob Free/Plus/Pro Schreibwerkzeuge dürfen: ungeklärt, vor Zusage am echten Konto prüfen. Free-Tarif: nicht belegt.
- Admin-Steuerung (Plugins/MCP nach Rolle, read-only oder eigene Aktionssets, Nachfrage-Verhalten): https://learn.chatgpt.com/docs/enterprise/apps-and-connectors

## 2. Codex

- config.toml: `[mcp_servers.<name>]` mit `url`, optional `auth = "oauth"` oder `bearer_token_env_var`. CLI: `codex mcp add <name> --url <URL>`, danach `codex mcp login <name>`; Client-Registrierung automatisch per CIMD oder DCR. [3] https://learn.chatgpt.com/docs/extend/mcp?surface=cli (vormals developers.openai.com/codex/mcp)
- ChatGPT-Desktop-App, Codex CLI und IDE-Erweiterung teilen `~/.codex/config.toml`. IDE: Settings > MCP servers > Add server > Streamable HTTP, dann Login. [3]
- Tarife: alle ChatGPT-Tarife (Free, Go, Plus, Pro, Business, Enterprise, Edu) enthalten Codex; CLI/IDE auf Plus, Pro, Business, Enterprise oder per API-Key; Cloud (Web) für Plus/Pro/Business/Enterprise. [4] https://learn.chatgpt.com/docs/pricing
- Skills: unterstützt. Verzeichnis mit `SKILL.md` (Felder `name`, `description`), Aufruf mit `$` (Codex CLI) bzw. `@` (ChatGPT) oder implizit; Ablage `.agents/skills` (Repo, mehrere Ebenen), `~/.agents/skills` (Nutzer), Admin-/System-Orte. Verteilung über Plugins. [11] https://learn.chatgpt.com/docs/build-skills
- Unbelegt: ob Codex-Cloud (Web) Remote-MCP mit OAuth nutzt; Workspace-Freigabe von MCP für Codex in Business/Edu siehe Admin-Seite oben.

## 3. Claude

- Custom Connectors auf Claude, Cowork und Claude Desktop in Free (max. 1), Pro, Max, Team, Enterprise. [5] https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp
- Team/Enterprise-Owner: Organization settings > Connectors > Add > Custom > Web; Name + URL; Auth ("Sign in now" / "when needed" / "No sign in"); OAuth-Client wählen; Mitglieder verbinden danach einzeln unter Customize > Connectors. [5]
- Pro/Max: Customize > Connectors > + Add > Add custom connector. [5]
- OAuth-Client-Optionen: "Use Claude's published identity (Recommended)", "Register automatically", "Use your own OAuth client". [5][6]
- Server muss aus Anthropics Cloud öffentlich erreichbar sein (Allowlist der Anthropic-IPs). [5][6] Für Schulinstanzen hinter Firewall/VPN relevant.
- Claude Code: `claude mcp add --transport http <name> <URL>`, Authentifizierung über `/mcp` oder `claude mcp login <name>` (`--no-browser` möglich); DCR ist Standard, alternativ `--client-id`/`--client-secret`/`--callback-port`. [7] https://code.claude.com/docs/en/mcp
- Konkrete Redirect-URI von claude.ai: nicht belegt (Doku nennt sie nicht).

## 4. Weitere Clients (kurz)

- Le Chat / Mistral: eigene MCP-URL, Auth: keine, Bearer/Basic, OAuth 2.1 mit DCR; Funktionen einzeln auf "manuell" oder "Always allow"; keine Resources/dynamische Tool-Erkennung. Tarife unbelegt. [8] https://docs.mistral.ai/le-chat/knowledge-integrations/connectors/mcp-connectors
- Gemini CLI: OAuth-Auto-Discovery (401), DCR, Token-Speicherung. [9] https://geminicli.com/docs/tools/mcp-server/ (nur Suchtreffer-Zusammenfassung). Gemini-App/Web: unbelegt.
- GitHub Copilot: OAuth für Remote-MCP in VS Code; JetBrains/Xcode/Eclipse/Visual Studio laut Treffer PAT; Cloud-Agent ohne OAuth-Remote-MCP. [10] https://docs.github.com/en/copilot/how-tos/provide-context/use-mcp-in-your-ide/set-up-the-github-mcp-server (Suchtreffer, Aussagen zum GitHub-MCP-Server, nicht eigener Server).

## Folgerungen für Kurspilot

- Der Server sollte DCR und CIMD sowie `/.well-known/oauth-protected-resource` und PKCE S256 anbieten: deckt ChatGPT [2], Codex [3], Claude [5], Claude Code [7], Le Chat [8] ab.
- Schreibwerkzeuge: ChatGPT fragt standardmäßig nach [1]; Le Chat empfiehlt manuelle Freigabe für Schreibfunktionen [8]. Tool-Annotationen (read-only/destructive) sauber setzen: Bedeutung für die Clients in den Quellen nicht belegt.
- Öffentliche Erreichbarkeit der Moodle-Instanz nötig (Claude [5]); für ChatGPT nicht ausdrücklich belegt, aber bei cloudseitigem Aufruf zu erwarten (unbelegt).

## Quellen

1. https://developers.openai.com/api/docs/guides/developer-mode
2. https://developers.openai.com/apps-sdk/build/auth
3. https://learn.chatgpt.com/docs/extend/mcp?surface=cli
4. https://learn.chatgpt.com/docs/pricing
5. https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp
6. https://support.claude.com/en/articles/11175166-getting-started-with-custom-connectors-using-remote-mcp
7. https://code.claude.com/docs/en/mcp
8. https://docs.mistral.ai/le-chat/knowledge-integrations/connectors/mcp-connectors
9. https://geminicli.com/docs/tools/mcp-server/
10. https://docs.github.com/en/copilot/how-tos/provide-context/use-mcp-in-your-ide/set-up-the-github-mcp-server
11. https://learn.chatgpt.com/docs/build-skills
