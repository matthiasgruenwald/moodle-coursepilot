# Coursepilot – Feature-Inventur (Server-MCP-Linie)

Stand: 02.10.2026, Branch `dev`, Plugin `2.0.0-beta` (`Plugin/src/local_coursepilot/version.php:20-27`).
Zweck: Grundlage für eine Dokumentationsseite (Lehrkräfte, Admins, Entwickler) und für Gespräche mit Entwicklern ähnlicher Moodle-KI-Werkzeuge.
Belege: Pfad bzw. `Pfad:Zeile`. Pfade ohne Präfix liegen unter `Plugin/src/local_coursepilot/` (`P/`).
Abgrenzung: `legacy/local_coursepilot/` + `moodle-mcp.js` = eingefrorener 1.x-Laptop-Weg (Node/stdio, Token von Hand), wird mit dem Schnitt gelöscht (`CLAUDE.md`, ADR 0024, ADR 0027). Hier nicht beschrieben.

---

## 0. Kurzprofil

- Moodle-Plugin `local_coursepilot` ist **selbst der MCP-Server** (Endpunkt `/local/coursepilot/mcp.php`); Lehrkraft installiert nichts lokal (`README.md:29-36`, `P/README.md:1-5`).
- **54 Werkzeuge** (`P/classes/tool_registry.php:15-70`), Skill-Korpus kommt als Werkzeugantwort (`coursepilot_list_skills` / `coursepilot_get_skill`).
- Anmeldung: OAuth 2.1 mit Discovery (RFC 8414/9728), Dynamic Client Registration (RFC 7591) und CIMD (`P/classes/oauth_lib.php:19-26`); kein Token von Hand.
- Schreibt Aktivitäten über Moodles eigenen Formularweg (`add_moduleinfo()`/`update_moduleinfo()`), ADR 0016.
- Lizenz AGPL-3.0-or-later, bewusst auch fürs Plugin (ADR 0025).
- Reife: `MATURITY_BETA`, Release `2.0.0-beta` (`P/version.php:25-27`, ADR 0027).

---

## 1. Lehrkräfte

### 1.1 Arbeitsablauf: planen – freigeben – umsetzen

| Phase | Was passiert | Beleg |
|---|---|---|
| Einstieg | Skill `coursepilot` ("Mach mit Bio weiter") wählt den Modus | `P/skills/adapter/coursepilot.md` |
| Planen | Kursstand lesen (nur lesend), Plan als `plan.md` + Status in `status.md` im Kontextbereich; gestufte Vorschau (erst Zusammenfassung, dann Volltext) | `P/skills/adapter/coursepilot-plan.md`, `P/skills/reference/implementation-plan-workflow.md`, CONTEXT.md "Planungsentwurf", "Gestufte Vorschau" |
| Freigeben | Lehrkraft bestätigt ausdrücklich ("Plan ist gut, leg los"); Freigabe liegt **im Chat**, nicht serverseitig | `P/skills/adapter/coursepilot-plan.md`, ADR 0018 (Absatz 1) |
| Umsetzen | Freigabeformulierung "ja, so umsetzen"; vorher read-only Umsetzungsvorprüfung (Plan ↔ Kursstand); bei Konflikt wird nicht geschrieben | `P/skills/adapter/coursepilot-implement.md`, CONTEXT.md "Umsetzungsvorpruefung" |
| Planstrenge | Nur, was aus Auftrag/Material/Kontext/Plan folgt; keine ungefragten "Extras" | CONTEXT.md "Planstrenge" |
| Weiterarbeiten | Journal, Entscheidungsnotizen, Fortsetzen einer Planung | `P/skills/reference/journal.md` |

Der Server weist die KI per Handshake-`instructions` an, zuerst `coursepilot_list_skills` aufzurufen (`P/classes/dispatcher.php:59`).

### 1.2 Was Coursepilot konkret tun kann (54 Werkzeuge, gruppiert)

Schreibend/lesend nach `tool_registry::is_write_class()` (`P/classes/tool_registry.php:119-128`). Lesend trotz Namen: `create_workbench_download_links`, `export_activity_backup`, `export_default_activity`.

| Gruppe | Werkzeuge (Präfix `coursepilot_`) |
|---|---|
| Orientierung/Lesen (7) | `list_courses`, `get_course_catalog` (kompakt/voll, filterbar; Profilfeld-Werte und Gruppennamen maskiert), `get_modules`, `get_module_settings`, `get_sections`, `describe_module_fields` (Feldkatalog je Art), `get_version_info` |
| Abschnitte (3) | `ensure_section`, `update_section`, `move_section` |
| Aktivitäten (8) | `create_module`, `update_module_settings`, `move_module`, `create_quiz`, `update_quiz_settings`, `set_completion`, `set_restriction`, `clone_activity` (im Kurs und kursübergreifend) |
| Versionen (3) | `list_activity_versions`, `compare_activity_versions`, `restore_activity_version` |
| Quiz/Fragenbank (13) | `ensure_question_bank`, `ensure_question_category`, `update_question_category`, `get_question_categories`, `plan_question_category_cleanup`, `get_question`, `move_question`, `create_mc_question`, `update_mc_question`, `import_questions_xml`, `export_questions_xml`, `add_questions_to_quiz`, `plan_quiz_cleanup` |
| Klon-Nachweis (1) | `report_clone_lineage` (Fragen kopiert oder noch mit Quellkurs geteilt) |
| Kontextbereich (6) | `list_context_files`, `read_context_file`, `write_context_file`, `append_context_file`, `dismiss_pending_entry`, `dismiss_previous_location` |
| Material (8) | `list_material_files`, `upload_material_file`, `preview_material_file`, `crop_material_file`, `compose_material_file`, `report_loose_material_files`, `delete_material_files`, `create_workbench_download_links` |
| Erschlossene Arten (3) | `create_activity_from_xml`, `export_activity_backup`, `export_default_activity` |
| Skills (2) | `list_skills`, `get_skill` |

Beschreibungen: `P/lang/en/local_coursepilot.php:35-95`.

### 1.3 Aktivitätsarten

| Status | Arten | Beleg |
|---|---|---|
| Katalogisiert ("unterstützt", Feldkatalog geprüft) | `label`, `page`, `url`, `folder`, `resource`, `choice`, `forum`, `assign`, `quiz` | `P/classes/catalog/registry.php:41-51` |
| Erschlossen (ohne Katalog, **nur anlegen**, nie "unterstützt") | jede installierte Art ohne Katalog, z. B. Buch, Checkliste, Glossar | ADR 0028, Spec 0026, CONTEXT.md "Erschlossene Aktivitätsart" |
| Ausgeschlossen | Arten mit Fragen: `lesson`, `quiz`; Arten mit Dateien im Inhalt (vorläufig, Datei-Nachtrag #598): `scorm`, `imscp`, `h5pactivity`, `lightboxgallery` | `P/classes/catalog/registry.php:67-73`, `P/lang/en/local_coursepilot.php:476` |

Erschlossene Arten (ADR 0028): Aktivitäts-XML wird aus `export_default_activity` abgeleitet; Anlage versteckt, danach Export und Round-Trip-Prüfung (Eingabe ⊆ Ausgabe); bei Abweichung wird die Aktivität im selben Aufruf wieder gelöscht, sonst sichtbar geschaltet. "Ablösen statt Ändern": neue Aktivität hinter die alte, alte versteckt, Verlauf vermerkt "abgelöst durch cmid X" (`P/classes/xml_activity_creator.php`). Gelerntes zu einer Art steht im Kontextbereich (`activity-types/`), nicht im Plugin (Skill `activity-types`).

Feldbündel (didaktische Voreinstellungen, Katalog-Inhalt, kein eigener Typ): z. B. Quiz `mini-check`, `lernstandscheck`, `abschlusstest`; Aufgabe `standard`, `übung` (CONTEXT.md "Feldbündel").

### 1.4 Quiz und Fragen

- Multiple-Choice nativ (`create_/update_mc_question`); alle anderen Fragetypen (z. B. STACK) über Moodle-XML-Import mit Varianten (`import_questions_xml`, Spec 0014).
- Überarbeitung als **neue Version derselben Frage** (native Moodle-Fragenversionierung, ADR 0001); Identität über stabile `idnumber`, Stand über den Fragenbank-Eintrag (ADR 0015).
- Verdachtsfall-Gate: bei mehrdeutiger Identität schreibt der erste Aufruf nichts, erst der bestätigte zweite (`P/classes/question_suspect_gate.php`).
- Aufräumen nur als **nicht zerstörender Plan** (`plan_question_category_cleanup`, `plan_quiz_cleanup`), Spec 0019.
- Quiz-Anordnung (Slots, Abschnitte, Feedback) über Moodles Struktur-API (`P/classes/quiz/arrangement.php`).
- Fragetyp-Wissen (Lerndatei) im Kontextbereich: Skill `question-types`.

### 1.5 Material und Bildzuschnitt

- Zwei Orte: **Materialbestand** (gewachsener Ordner der Lehrkraft; serverseitig **nur lesend**) und **Werkbank** (Zwischenstation in Moodle für Chat-Anhänge, Zuschnitte, verdrängte Dateien; wird aufgeräumt). Alle Schreib-Werkzeuge zielen nur auf die Werkbank (`P/classes/material_area.php:17-45`, CONTEXT.md "Materialbestand", "Werkbank").
- Bildvorschau (768 px, JPEG) und **Gezielter Bildausschnitt** über GD (relative Koordinaten 0-1); **kein ImageMagick im Server-MCP**; nur Raster, SVG nicht zuschneidbar (Spec 0018 §3.3, `P/classes/gd_support.php`).
- `compose_material_file`: mehrere Ausschnitte mit Quellenkopf zu einem Werkbank-PNG (feste Gestaltung; GD mit FreeType und mitgelieferter FreeSans-Bold nötig) (`P/lang/en/local_coursepilot.php:77-83`).
- Papierkorb für ersetzte Aktivitätsdateien (0 Byte Mehrverbrauch, gleicher `contenthash`) (`P/classes/activity_file_trash.php`).
- `report_loose_material_files` findet Werkbankdateien ohne Verwendung; Löschen nur ausdrücklich benannter Dateien.
- Für Clients mit Shell: Einmal-Downloadlinks für Werkbankdateien in Originalbytes (`create_workbench_download_links`, `P/workbench/download.php`, `P/classes/workbench_ticket.php`).
- **Merkzettel**: Änderungen am externen Materialbestand (umbenennen, verschieben, löschen, Datei von der Werkbank einlegen), die der Server nicht ausführen darf, werden als Liste im Kontextbereich notiert und von einem Client mit direktem Dateizugriff abgearbeitet (CONTEXT.md "Merkzettel", Skill `notepad`).

### 1.6 Kontextbereich ("Gedächtnis" der Lehrkraft)

- Ablage der Arbeitsdateien (Plan, Status, Journale, Lerngruppen-/Fachprofile, Lerndateien) in Moodles **Private Files** (`user/private`, Unterordner `coursepilot`), also für die Lehrkraft in "Meine Dateien" sichtbar, löschbar, weitergebbar (ADR 0019).
- Optional im **eigenen WebDAV-Speicher** (Nextcloud, IServ, jeder WebDAV-Ort). Wahl auf der **Ortswahl-Seite** im Profil (`P/location_selection.php`), nie im Chat (CONTEXT.md "Ortswahl"). Gemischt erlaubt (Kontextbereich extern, Material in Moodle usw.).
- IServ: nur Orte unter `Files/` (`P/lang/en/local_coursepilot.php:169,247`).
- Ausfall **ohne Rückfall**: Scheitert das Schreiben am Speicher, legt das Plugin den Inhalt nirgends sonst ab, vermerkt einen Eintrag in der **Ausstandsnotiz** (nie den Inhalt), die KI behält den Inhalt im Gespräch und schreibt nach (ADR 0023, `P/classes/pending_write_notice.php`).
- **Altbestand** nach Ortswechsel: KI kann nur den einen vorherigen Ort lesen und nach Bestätigung kopieren (nie überschreiben) (CONTEXT.md "Altbestand", "Ortsverlauf").
- Format: Markdown mit Frontmatter; Markierung `personenbezug: true` für Dateien mit Personenbezug (`P/classes/personal_data.php:56`).
- Kontextfreigabe: einmal je Sitzung klären, welche Dateien gelesen/aktualisiert werden dürfen (CONTEXT.md "Kontextfreigabe"); Schreibangebot statt stillem Schreiben.

### 1.7 Versionen und Wiederherstellung

- **Änderungsverlauf** je Aktivität in der DB (Vollstand je Schreibvorgang, verankert an der stabilen `cmid`), auch für Änderungen von Hand im Moodle-Formular (ADR 0018, `P/classes/history/version_writer.php`).
- Rückkehr = **neue jüngste Version**, nie Rückspulen; `cmid` und Verweise bleiben heil (`restore_activity_version`).
- Auch **ohne laufenden Chat** per Moodle-UI: Kursnavigation "Änderungsverlauf" (Capability `viewhistory`), Wiederherstellen-Knopf (`P/history.php`, `P/lib.php:116-140`).
- Lücken benannt: Verlauf ist nicht lückenlos (Quiz-Inhalt jenseits der Anordnung, Dateiinhalte nur als Metadaten) (`P/classes/history/version_history.php`, CONTEXT.md "Stand").
- Aufbewahrung: Standard 365 Tage, mindestens 1 Tag (`P/classes/history/retention.php:41`).
- "Vorgefunden"-Stand: bestehende Aktivitäten erhalten beim ersten Ereignis einen Ausgangsstand (CONTEXT.md "Vorgefunden").

### 1.8 Klonen

- `clone_activity`: im selben Kurs und kursübergreifend; Einstellungen/Inhalte (inkl. Quizfragen als Kopien) gehen über, Nutzerdaten nicht (Spec 0013, CONTEXT.md "Klonen").
- Geerbte Abschlussverfolgung/Voraussetzungen werden gemeldet; `report_clone_lineage` zeigt, ob Fragen geteilt oder kopiert sind.
- Klon erhält eigenen ersten Stand im Verlauf (ADR 0028 verweist auf ADR 0018).

### 1.9 Abschluss und Voraussetzungen

- `set_completion`, `set_restriction`; Lernpfad-Gates (Folgeaktivität erst nach Bestehen) im Skill `completion-tracking` (optionales Feature). Personenbezug in Verfügbarkeitsbedingungen wird vor der Ausgabe an die KI maskiert (`P/classes/availability_privacy.php`).

### 1.10 Skill-Korpus (für die KI, für Lehrkräfte unsichtbar)

- 3 Adapter (`coursepilot`, `coursepilot-plan`, `coursepilot-implement`) + 19 Referenzdateien unter `P/skills/{adapter,reference}/`: Kern, Plan-Workflow, Kontextbereich, Kontext-Onboarding, Journal, Quiz/Fragenbank, Fragetypen, Aktivitätsarten, Aktivitätssicherung, Abschlussverfolgung, HTML-Vorlagen, interaktive Elemente, Grafiken, SVG-Qualitätssicherung, Zeichen-Canvas, Arbeitsblätter (.docx), technische Hinweise, MCP-Werkzeug-Referenz, Merkzettel.
- Wird mit dem Plugin ausgeliefert, **nicht** von Lehrkraft oder Admin anpassbar; Lerndatei der Lehrkraft schlägt den Korpus im Konflikt (CONTEXT.md "Skill-Korpus", "Lerndatei"; ADR 0020 "Skills über Werkzeuge").
- Skill-Prosa bleibt vorerst deutsch (ADR 0024 §2, `CLAUDE.md`).

### 1.11 Werkzeuglücken (belegt)

- Keine Lernenden-/Leistungsdaten: Abgaben, Forenbeiträge, Quizversuche, Bewertungen, Teilnehmerlisten (Positiv-Allowlist, `P/README.md:27-31`).
- Nur Kursgestaltung der **eigenen** Kurse; keine Bearbeitungsrechte über den Fernzugriff.
- Nur Katalogarten werden bearbeitet; erschlossene Arten nur angelegt/abgelöst, nicht geändert (ADR 0028).
- Arten mit Fragen/Dateien nicht aus XML (siehe 1.3).
- Aktivitäts-Backup als Bearbeitungsweg verworfen (ADR 0016).
- Materialbestand extern: kein Schreiben/Umbenennen/Löschen durch den Server (-> Merkzettel).
- Nutzerdaten-Inhalte (z. B. Glossar-Einträge) gehen nicht über den XML-Weg (ADR 0028).
- Aktivitätsverlauf nicht lückenlos (1.7). Drift je Art sperrt nur das Schreiben dieser Art (4.3).
- Weitere Rest-Lücken im Praxistest: "kleinere Werkzeuglücken in Randfällen" (ADR 0027, ohne Liste).

### 1.12 KI-Clients

| Client | Beleg / Status |
|---|---|
| Claude (claude.ai, Claude Code) | Origin `https://claude.ai` zugelassen; Protokollrevision 2026-07-28 wird bedient (`P/classes/dispatcher.php:44-62,425-436`) |
| ChatGPT | Origin `https://chatgpt.com` zugelassen (`dispatcher.php:62`); keine Einrichtungsanleitung im Repo |
| Codex | Revision 2025-06-18 (rmcp, ab Codex 0.151) wird bedient (`dispatcher.php:425-436`); Produktanforderung "Codex-First" (CONTEXT.md "Codex-First") |
| Generischer MCP-Client | Streamable-HTTP, zustandslos, POST-only JSON; OAuth mit DCR oder CIMD (`P/mcp.php:17-19`, `oauth_lib.php:19-26`) |

### 1.13 Connector einrichten (Lehrkraft)

1. Admin hat Fernzugriff freigegeben (siehe 2.3). Sonst scheitert die Verbindung.
2. Im KI-Client einen Connector/MCP-Server mit `https://<moodle>/local/coursepilot/mcp.php` anlegen.
3. Einmal über den Moodle-Consent-Bildschirm autorisieren (`P/oauth/authorize.php`, `P/classes/output/authorize_page.php`). Zugriffstoken 1 h, Erneuerungstoken 30 Tage (`P/classes/oauth_lib.php:51-54`).
4. Eigene Verbindungen ansehen und widerrufen: Profil -> "Meine Verbindungen" (`P/connections.php`).
5. Optional: Ortswahl (Profil -> Coursepilot-Einstellungen) für WebDAV (`P/lib.php:22-110`).
6. Kein Token wird von Hand kopiert (`README.md:34-36`).

### 1.14 WebDAV-Repository (IServ / Nextcloud)

- Voraussetzung (**WebDAV-Freischaltung**, je Lehrkraft, bei jedem Zugriff live geprüft): Repository-Typ WebDAV aktiv, Nutzerinstanzen erlaubt, Recht `repository/webdav:view` im eigenen Nutzerkontext (`P/classes/webdav/webdav_setup_steps.php:17-40`).
- Zugangsdaten kommen aus der `repository_webdav`-Nutzerinstanz; Hinweis: **App-Passwort** statt Kontopasswort (`P/lang/en/local_coursepilot.php:418,600`).
- Eigener WebDAV-Client auf Moodles `\curl`, bewusst nicht `\webdav_client` (ADR 0022).
- Stilles Wiederholungsbudget 10 s (`P/README.md`, Abschnitt "WebDAV storage behind a reverse proxy").
- Ohne `trusted_proxies` am Speicher (z. B. hinter Cloudflare) drosselt dessen Bruteforce-Schutz alle Anfragen (Issue #529; `docs/admin-erstanleitung.md:169-185`).

---

## 2. Administration

### 2.1 Installation und Voraussetzungen

| Punkt | Inhalt | Beleg |
|---|---|---|
| Installation | ZIP bzw. `P/` nach `local/coursepilot`, `upgrade.php` ausführen | `docs/admin-erstanleitung.md:14-28` |
| Release-Artefakt | `npm run build:native-release` (ZIP + Quellstand) | `RELEASE_NOTES.md`, `package.json` |
| Moodle | `requires` 5.0 (`2025041400`); CI-Nachweis 5.0 und 5.1; ab 2.1 Mindestversion 5.1 geplant (noch nicht in `version.php`) | `P/version.php:20-23`, ADR 0027 |
| PHP/DB | Getestet PHP 8.4 + MariaDB 11; PostgreSQL u. a. nicht geprüft | `P/README.md` ("Supported versions"), `docs/ci-native-server-mcp.md` |
| Web Services + REST | aktivieren (Dienst `Coursepilot`, `restrictedusers=0`) | `P/db/services.php`, `docs/admin-erstanleitung.md:23` |
| GD (FreeType) | für Bildvorschau/Zuschnitt/Komposition; Moodle-Pflichtmodul | Spec 0018 §3.3, `P/classes/gd_support.php` |
| `slasharguments` | muss aktiv sein (Moodle-Standard) für Discovery | `P/README.md` Install-Schritt 4 |
| Umstieg von 1.x | zuerst deinstallieren, keine Datenmigration | `P/README.md` Install-Schritt 0, ADR 0024 |
| Nach Updates | `upgrade.php` Pflicht bei Änderung `db/access.php`/`db/services.php` (sonst HTTP 500) | `docs/admin-erstanleitung.md:30-33` |

### 2.2 Einstellungen (Website-Administration -> Plugins -> Lokale Plugins -> Coursepilot, `P/settings.php`)

| Einstellung | Standard | Wirkung |
|---|---|---|
| `remoteaccesscohorts` (`settings.php:56`) | leer | Systemkohorten mit Fernzugriff (keine Kategorie-Kohorten) |
| `remoteaccessenabled` (`:47`) | an | **Notbremse** für alle neuen Zugriffe; bestehende Token bleiben gültig |
| `loglevel` (`:62`) | Lesezugriffe + Fehler | Umfang der Moodle-Ereignisprotokollierung (`tool_access_succeeded/failed`) |
| `contextroot` (`:79`) | `coursepilot` | Wurzelordner Kontextbereich (organisatorisch, keine Sicherheitsgrenze) |
| `materialroot` (`:90`) | `coursepilot-material` | Wurzelordner Werkbank |
| `allowpersonaldata` (`:105`) | **aus** | gibt Dateien mit `personenbezug: true` an Lese-Werkzeuge frei |
| `historyretentiondays` (`:117`) | 365 | Löschfrist Änderungsverlauf (mind. 1 Tag, kein Cron) |
| `personaldatahosts` (`:146`) | leer = nur Private Files | Zulassungsliste externer Speicher fürs **Schreiben** markierter Dateien |
| `webdavhint` (`:157`) | leer | Freitext auf der Ortswahl-Seite |

Zusatzseiten: Verbindungsübersicht `admin/connections.php` (`moodle/site:config`, Einzel-/Sammelwiderruf), Systemoberfläche `surface.php` (Allowlist + Instanzprüfung).

### 2.3 Capabilities und Fernzugriff

| Capability | Kontext | Archetypen | Zweck | Beleg |
|---|---|---|---|---|
| `local/coursepilot:use` | Kurs | editingteacher, teacher | Werkzeuge im Kurs (Moodle-Kursrechte bleiben zusätzlich nötig) | `P/db/access.php:29` |
| `local/coursepilot:useremote` | System | **keine** | Fernzugriff, Weg 2 neben Kohorte | `access.php:43` |
| `local/coursepilot:viewhistory` | Kurs | editingteacher, teacher | Änderungsverlauf ansehen | `access.php:51` |
| `local/coursepilot:restoreversion` | Kurs | editingteacher, teacher | Version wiederherstellen (zusätzlich `moodle/course:manageactivities`) | `access.php:64` |

- Fernzugriffsfreigabe: Kohorte (empfohlen) **oder** `useremote` in vorhandener Systemrolle; wird **bei jedem Aufruf** geprüft; Coursepilot legt weder Kohorte noch Rolle an (ADR 0026, `P/classes/remote_access.php:17-60`).
- Fernzugriff gibt keine Kursrechte; beides wird unabhängig entzogen.

### 2.4 OAuth/Autorisierung des MCP-Endpunkts

- Dateien: `P/oauth.php` (Discovery-Aussteller), `P/oauth/{authorize,token,register,jwks,protected-resource}.php`.
- Registrierung per DCR und CIMD; Code-TTL 120 s (PKCE-gebunden), Access 1 h, Refresh 30 Tage (`P/classes/oauth_lib.php:48-54`); atomare Einlösung (Spec 0025 Stories 8-10).
- Tabellen: `local_coursepilot_oauth_client/_code/_token` (`oauth_lib.php:38-45`).
- Origin-Prüfung: Moodle-Host + `claude.ai` + `chatgpt.com` (`P/classes/dispatcher.php:62,90`).
- Bearer-Token auch über `REDIRECT_HTTP_AUTHORIZATION` (CGI/FastCGI) (`P/mcp.php:46-62`).

### 2.5 Reverse-Proxy, Erreichbarkeit

- Instanzprüfung per Selbstabruf der Discovery-URL (`surface.php`, `P/classes/instance_check.php`): HTTPS, Egress, PATH_INFO. Stolperstein: Proxy kappt PATH_INFO -> `AcceptPathInfo On` (`docs/admin-erstanleitung.md:83-97`).
- `trusted_proxies` betrifft den **externen WebDAV-Speicher** der Lehrkraft, nicht Moodle selbst (Issue #529).

### 2.6 Datenschutz

- **Kein Lernendenzugriff**: Allowlist `privacy_surface::allowed_tools()` abgeleitet aus der Registry; verbotene Namensbestandteile `submission, discussion, attempt, participant, enrol, grade, user` (`P/classes/privacy_surface.php:75-83`); erzwungen per Vertragstest `P/tests/privacy_surface_test.php`.
- **Plugin ruft keinen KI-Anbieter selbst auf**; jedes Tool-Ergebnis geht an den Anbieter des Clients (`P/README.md:33-36`, ADR 0011).
- Schule ist Verantwortliche (ADR 0011); `allowpersonaldata` standardmäßig aus; Schalter wirkt auf die **Markierung**, nicht auf Inhalte (kein Inhaltsfilter).
- Externer Ort: Isolierung durch Instanzeigentum (`contextid` der Instanz = Nutzerkontext des Token-Inhabers), nicht durch Pfadpräfix; `personaldatahosts` = Schreibsperre, keine Lesesperre (ADR 0021).
- Privacy-API: vollständiger Provider für OAuth-Tabellen, Tickets, Altbestand; Private Files exportiert/löscht Core (`P/classes/privacy/provider.php`).
- Bekannte Kernlücke: WebDAV-Nutzerinstanzen tragen `userid = 0`, werden bei Auskunft/Löschung nicht gefunden (`docs/admin-erstanleitung.md:152-159`). Klartext-Passwort in der Instanz.
- Gemountete Shares (IServ-Gruppen, Nextcloud-Shares) von außen nicht unterscheidbar (`P/lang/en/local_coursepilot.php:418`).

### 2.7 Statusprüfungen (Moodle-Systemstatus)

- Drift je katalogisierter Art (`activity_drift`, 9 Prüfungen), WebDAV: `webdav_capability_check`, `webdav_repository_check`, `webdav_user_instances_check`, `personal_data_hosts_check` (`P/classes/check/`, `P/lib.php:153-160`).

---

## 3. Weiterentwickler

### 3.1 Architektur

```
KI-Client --HTTPS+OAuth--> mcp.php (dünne Schale)
                              -> dispatcher::handle()   (Auth-Gate, Protokoll-Switch, Datenschutzvertrag)
                              -> external_api::call_external_function()  (Moodle-Webservice-Klassen)
                              -> Fassaden: context_area, material_area, version_writer, catalog/*
                              -> storage_port (private_files | webdav)
```

- `mcp.php` tut nur Ein-/Ausgabe, Entscheidungslogik in `dispatcher` (testbar ohne Webserver) (`P/mcp.php:17-27`, `P/classes/dispatcher.php:21-40`).
- Zustandslos, POST-only, dual-era: Revision 2025-06-18 (Handshake `initialize`) und 2026-07-28 (`server/discover`); Antwortmetadaten je Revision (`dispatcher.php:44-47,425-436`).
- Werkzeuge sind Moodle-**externe Funktionen** (`classes/external/*`), `WS_SERVER` definiert (`mcp.php:30-34`).
- Zusatzdienste: Verbindungsseiten, Ortswahl (Mustache `templates/` + AMD `amd/src/location_selection.js`), Workbench-Download.

### 3.2 Werkzeugvertrag

- **Eine Deklaration je Werkzeug** (Spec 0022): `tool_registry::TOOLS` (`P/classes/tool_registry.php:15`) -> `service_functions()` -> `db/services.php`; MCP-Schema wird aus `execute_parameters()` abgeleitet (`external_schema_converter`). Keine zweite Schema-Deklaration, keine Übersetzungstabelle.
- Schreibend vs. lesend per Namensmuster (`tool_registry.php:119-128`).
- Englische Basis (ADR 0024): Parameternamen, Rückgabeschlüssel, Beschreibungen englisch; Moodle-Strings nur `lang/en/` (AMOS). Mutierende Werkzeuge mit Bestätigungsparameter (Muster `confirmed`).
- Vertragstests: `P/tests/tool_schema_contract_test.php`, `privacy_surface_test.php`.

### 3.3 Wichtige Module und Nähte (Seams)

| Modul | Rolle | Beleg |
|---|---|---|
| `dispatcher` | Auth-Gate, Protokoll, Fehlerabbildung | `P/classes/dispatcher.php` |
| `oauth_lib` | Discovery, DCR/CIMD, Code/Token, Rotation | `P/classes/oauth_lib.php` |
| `remote_access` | Freigabe Kohorte/Capability | `P/classes/remote_access.php` |
| `tool_registry` / `privacy_surface` | eine Registrierung / Allowlist-Vertrag | siehe 3.2 |
| `catalog/*` (`registry`, `module_catalog`, Art-Klassen, `drift_check`) | Feldkatalog, Typwissen je Art (Spec 0024) | `P/classes/catalog/registry.php` |
| `write_gate` | Katalog-Selbstfreigabe in zwei Stufen, sperrt nur driftende Art | `P/classes/write_gate.php`, ADR 0017 |
| `history/*` | Vollstand-Schattentabelle, Retention, Wiederherstellung | `P/classes/history/` |
| `storage_anchor`, `storage_port`, `private_files_storage_port`, `webdav_storage_port`, `context_area`, `material_area` | **Ablage-Anker, ein Modul, zwei Adapter** (ADR 0020/Spec 0021) | `P/classes/storage_*.php` |
| `context_pointer`, `pointer_reader/writer`, `location_selection` | Ortswahl als Seitenzustand (Spec 0023) | `P/classes/` |
| `webdav/*` | eigener Client auf `\curl` (ADR 0022) | `P/classes/webdav/` |
| `pending_write_notice/translation` | Ausstandsnotiz (ADR 0023) | `P/classes/` |
| `xml_activity_creator`, `activity_backup`, `cm_references`, `course_module_placement` | Erschlossene Arten (ADR 0028, Spec 0026 Module 3-6) | `P/classes/` |
| `skill_corpus` | Korpus = Verzeichnisinhalt, Name = Bezeichner, kein Pfad | `P/classes/skill_corpus.php:17-60` |
| `material_composition`, `image_preview`, `gd_support` | Bildpfad (GD) | `P/classes/` |
| `access_log`, `event/*` | Protokollierung per Moodle-Ereignis-API | `P/classes/access_log.php` |

Seam-Muster: reine Funktionen (Werte rein/raus, kein `exit`) + dünne PHP-Schale (`oauth_lib`, `dispatcher`, `instance_check`).

### 3.4 Tests und CI

- PHPUnit: 126 Testdateien `P/tests/**/*_test.php`; Coverage-Quellumfang `P/tests/coverage.php`.
- CI `.github/workflows/native-ci.yml`: Jobs `phpunit` (Matrix `MOODLE_500_STABLE`, `MOODLE_501_STABLE`; PHP 8.4, MariaDB), `js-native`, `artifact` (frische Installation); einziger Merge-Check **`Gate (required)`** (`docs/ci-native-server-mcp.md:12-21`).
- Coverage-Gate 80 % Line (PCOV, nur Leg 5.0); zuletzt gemessen 80,03 % (`docs/ci-native-server-mcp.md:44-59`).
- Node-Suite `npm test` / `npm run test:ci` (Altstand + Hilfsskripte); sechs plattformgebundene Dateien aus nativem Lauf ausgeschlossen.
- Spike-Deploy: `bash scripts/deploy-plugin-spike.sh` (läuft nur auf der Spike-LXC, führt `upgrade.php` aus); PHPUnit lokal nicht möglich -> nur Spike-Container (`docs/plugin-deploy-spike.md`, Memory-Hinweis).
- Live-Abnahmen: `scripts/spike-live-abnahme-567.js`, `scripts/spike-abnahme-426.sh`.

### 3.5 Branch-Modell und Versionen (ADR 0027)

| Linie | `requires` | Zusage |
|---|---|---|
| 2.0.x (`main`) | Moodle 5.0 | 5.0 + 5.1 (CI); nur Hotfixes mit Tag, danach nach `dev` mergen |
| 2.1+ (`dev`) | Moodle 5.1 | neue größere Funktionen; gegen Spike (5.1) entwickelt; 5.2-CI-Zeile folgt |

- Feature-/Forschungs-/Prototypzweige zweigen von `dev` ab, werden danach gelöscht.
- Altstand (`legacy/`, `moodle-mcp*.js`) wird auf `dev` entfernt (ADR 0027 Folgen).
- Mirror-Sync nicht mehr automatisch; Umstellung mit Marketplace-Einreichung (#192).

### 3.6 Konventionen

- Code-Sprache englisch (Server-MCP); `legacy/` nicht anfassen; Pläne in `docs/plans/`; ADRs in `docs/adr/`; Spezifikationen in `docs/specs/` (`CLAUDE.md`).
- Hooks: `node --check`, `php -l`; Issue-Tracker GitHub (`matthiasgruenwald/moodle-coursepilot`), Labels `needs-triage` usw. (`docs/agents/`).

### 3.7 ADR-Landkarte (Einzeiler)

| ADR | Entscheidung |
|---|---|
| 0001 | Fragen werden als neue native Moodle-Version derselben Frage geändert, nie ersetzt oder dupliziert |
| 0002 | Entwicklung im schulspezifischen IGS-Fork von MoodleMcp |
| 0003 | Lokale Kontextdateien dürfen echte Schülernamen enthalten (fortgeschrieben durch 0011) |
| 0004 | OCR über Agent-Vision statt OCR-Bibliothek, mit Kontroll-Gate |
| 0005 | ImageMagick (`convert`) für Bildausschnitt im Altweg (im Server-MCP durch GD ersetzt, Spec 0018) |
| 0006 | Node-Helper für Moodle-Token-Speicher (Altweg) |
| 0007 | Aufteilung Core-MCP und Aktivitäts-MCPs, explizite Formularfelder |
| 0008 | curl/PowerShell-Bootstrap statt kompiliertem Installer (Altweg) |
| 0009 | opencode als bereitgehaltener Client (Altweg) |
| 0010 | Lieferkettenhärtung: Tag-Pin, SHA256, CSRF-Schutz Setup-Server (Altweg) |
| 0011 | Personenbezogene Kontextdaten im Servermodell: Schule verantwortlich, Schalter `allowpersonaldata` Standard aus |
| 0015 | Fragenidentität: Abstammung über `idnumber`, Stand über Bank-Eintrag |
| 0016 | Aktivitäten nur über Modul-Formularweg (`add/update_moduleinfo`), keine direkte DB-Schreibung |
| 0017 | Katalogpflege in zwei Stufen: Drift sperrt nur die betroffene Art, Lesen läuft weiter |
| 0018 | Änderungsverlauf als Vollstand-Schattentabelle nach Notenbuch-Muster, Rückkehr = neue Version |
| 0019 | Kontextbereich in Moodles Private Files statt eigener Filearea |
| 0020 (Anker) | Ein gemeinsamer Ablageort-Anker statt zweier paralleler Klassen |
| 0020 (Skills) | Skill-Korpus über Werkzeuge ausliefern statt lokaler Installation |
| 0021 | Isolierung über Instanzeigentum, Personenbezug am externen Ort als Schreibsperre |
| 0022 | Eigener schlanker WebDAV-Client auf Moodles `\curl` statt `\webdav_client` |
| 0023 | Ausfall ohne Rückfall: Ausstandsnotiz am Anker, Nachtragen durch die KI |
| 0024 | Englische Basis, Name Coursepilot, Komponente `local_coursepilot`; Skill-Korpus vorerst deutsch |
| 0025 | AGPL-3.0-or-later für das ganze Projekt inkl. Plugin |
| 0026 | Fernzugriff über gewählte Systemkohorten statt eigener Rolle |
| 0027 | Versionslinien 2.0.x/2.1+, Branches `main`/`dev`, Moodle-Mindestversion, Beta |
| 0028 | Aktivitäts-XML als Anlegeweg (nie Bearbeitungsweg) für erschlossene Arten |

(Nummern 0012-0014 existieren nicht; zwei Dateien tragen 0020.)

### 3.8 Specs (Auswahl, Server-Linie)

0012 Server-MCP (Entwurf, Namensstand überholt) · 0013 Klonen · 0014 XML-Frageimport · 0015 Schreibkern · 0016 Kontextbereich schreibend · 0017 Fragenbank/Import/Klonen · 0018 Dateitransport/Bildzuschnitt · 0019 Cleanup-Ports · 0020 Skill-Verteilung · 0021 Ablage-Anker · 0022 Ein Werkzeug, eine Deklaration · 0023 Ortswahl · 0024 Modulkatalog nimmt Typwissen auf · 0025 Release-Reife · 0026 Erschlossene Arten. Specs 0001-0011 betreffen überwiegend den Altweg; 0003 ist überholt (Marketplace statt Directory).

---

## 4. Besonderheiten / Alleinstellungsmerkmale (für Gespräche mit anderen Entwicklern)

1. **Plugin = MCP-Endpunkt, null Installation beim Lehrer.** OAuth 2.1 mit DCR **und** CIMD, dual-era-Protokoll (2025-06-18 + 2026-07-28), läuft mit Claude, Codex, ChatGPT-Origin und generischen Clients (`P/classes/dispatcher.php:44-62`).
2. **Strukturelle Datenschutz-Grenze, kein Versprechen:** positive Werkzeug-Allowlist ohne Lernendendaten, per Vertragstest gegen die real registrierte Oberfläche erzwungen; Fernzugriff pro Person, bei jedem Aufruf geprüft, mit Notbremse und Sammelwiderruf (`privacy_surface.php`, ADR 0026).
3. **Schreiben nur über Moodles eigenen Formularweg** (`add/update_moduleinfo`) mit geprüftem Feldkatalog, Drift-Check je Art nach Moodle-Updates und artbezogener Sperre statt Voll-Fail-closed (ADR 0016/0017).
4. **Änderungsverlauf mit Rückweg in der Datenbank** (Vollstand je Schreibvorgang, auch für Handänderungen, Wiederherstellung = neue Version, UI ohne laufenden Chat): Gegengewicht zur Freigabe im Chat (ADR 0018).
5. **Beliebige weitere Aktivitätsarten ohne Code je Art:** Aktivitäts-XML + Round-Trip-Prüfung + automatisches Verwerfen von Fehlanlagen + Ablösen statt Überschreiben (ADR 0028).
6. **Kontext gehört der Lehrkraft:** Arbeitsdateien in "Meine Dateien" oder eigenem WebDAV (IServ/Nextcloud), Ortswahl auf eigener Seite, **kein Rückfallspeicher**, Ausstandsnotiz ohne Inhalt, Isolierung über Instanzeigentum (ADR 0019/0021/0023).
7. **Skills als Teil des Produkts, über Werkzeuge geliefert:** pädagogische Arbeitsweise (Planen -> Freigeben -> Umsetzen, Planstrenge, Journal, Lerndatei) ohne Skill-Installation (ADR 0020).
8. **Eine Werkzeug-Deklaration** treibt MCP-Schema, Webservice-Registrierung und Datenschutz-Allowlist (Spec 0022).
9. **Serverseitige Bildverarbeitung ohne Bytes im Chat:** Vorschau, Zuschnitt, Komposition in GD; Einmal-Downloadlinks für Originalbytes (Spec 0018, `workbench_ticket`).
10. **Didaktische Feldbündel** (mini-check, lernstandscheck, abschlusstest) und Lernpfad-Gates als eingebaute Fachlogik.
11. **AGPL-3.0-or-later** bewusst auch für das Plugin (ADR 0025); native CI-Matrix Moodle 5.0/5.1, 80-%-Coverage-Gate.

---

## 5. Offene Lücken und Unklarheiten (nicht aus Quellen belegbar oder widersprüchlich)

1. **Client-Einrichtungsanleitungen** für Claude/ChatGPT/Codex Schritt für Schritt fehlen im Repo; belegt sind nur Origin-Allowlist und Protokolltoleranz. Ob ChatGPT-Connector real getestet wurde: nicht belegt.
2. **PHP-Mindestversion** nirgends gesetzt (`version.php` ohne PHP); nur PHP 8.4 geprüft.
3. **Maturity-Widerspruch:** `P/README.md` "Status: Alpha" vs. `version.php` `MATURITY_BETA` / ADR 0027.
4. **Sprache:** `P/README.md` sagt, Übersetzungen (auch Deutsch) würden nicht gebündelt; `P/lang/de/local_coursepilot.php` (505 Zeilen) existiert aber im Quellbaum.
5. **Skill-Korpus-Ort:** `CLAUDE.md` nennt `skills/` (Repo-Wurzel, deutsche Dateinamen) als Korpus; die ausgelieferte Quelle ist `P/skills/{adapter,reference}` (englische Dateinamen). Rolle des Wurzel-`skills/` unklar (vermutlich Altstand).
6. **Werkzeugzahl:** Spec 0022 nennt 49 Werkzeuge, Registry hat 54; Spec 0012 nennt 42 (Altweg). Doku-Seite sollte 54 aus der Registry ableiten.
7. **Marketplace-Status:** Einreichung (#192) offen; `RELEASE_NOTES.md` spricht noch vom "Moodle Plugin Directory"-Mirror, `mirror-sync.yml` baut noch den Altstand (ADR 0027).
8. **Mindestversion 2.1** (Moodle 5.1) beschlossen, `version.php` steht noch auf 5.0; 5.2-CI-Zeile noch nicht nachgewiesen.
9. **Markierungsname:** Admin-Anleitung/Code nennen `coursepilot.personenbezug: true`, der Parser prüft nur `personenbezug: true` im Frontmatter (`P/classes/personal_data.php:56`); deutscher Schlüssel trotz englischer Vertragsbasis (ADR 0024). Zielname ungeklärt.
10. **Rate-Limiting** des MCP-Endpunkts: im Code nicht gefunden, nicht belegt (Security-Regel fordert es).
11. **Mehrere Moodle-Rollen/Kurse:** Wie `local/coursepilot:use` (captype `read`) schreibende Werkzeuge absichert (zusätzliche Moodle-Kursrechte) ist nur indirekt belegt (`access.php:64`-Kommentar, README).
12. **Materialbestand-Schreibverbot:** CONTEXT.md ("Coursepilot liest ihn serverseitig nur") und Werkzeugnamen `upload/delete_material_files` wirken widersprüchlich; Auflösung: nur Werkbank schreibbar (`material_area.php`), Wortlaut in der Doku präzisieren. Einzelfall "Bestand in Moodle (Private Files)": CONTEXT.md erlaubt dort Schreiben ("darf Coursepilot dort selbst schreiben"), Code-Pfad nicht geprüft.
13. **Praxistest-Befund:** "kleinere Werkzeuglücken in Randfällen" (ADR 0027) ohne Liste; Tracker nicht eingesehen.
14. **Spec 0012 / ADR 0011** tragen teils alte Namen (`local_kurspilot`, `kurspilot.personenbezug`); ADR-Nummern 0012-0014 fehlen, zweimal 0020.
15. **docs/diagrams/** (`kurspilot-aus-nutzersicht`) nur oberflächlich gesichtet; Inhalt nicht inventarisiert.
16. **Windows/Parallels-Abschnitt** in `CLAUDE.md` betrifft den Altweg; Server-Weg braucht keinen lokalen Client.
17. Nutzerzahlen/Praxiserfahrung außerhalb des Autors: `P/README.md` sagt "in real teaching use by its author, not yet at another school"; aktuelle Zahl nicht belegt.
