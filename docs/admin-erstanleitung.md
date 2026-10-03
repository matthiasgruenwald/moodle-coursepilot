# Admin-Erstanleitung: Coursepilot (`local_coursepilot`) einrichten

Für eine Schulverwaltung, die Coursepilot zum ersten Mal auf einer Moodle-Instanz
einrichtet — ohne Vorwissen über das Plugin, einmal von vorn bis hinten. Betrifft
den aktuellen Server-MCP (`Plugin/src/local_coursepilot/`), nicht den eingefrorenen
Laptop-Altstand (`legacy/local_coursepilot/`) — siehe [`CLAUDE.md`](../CLAUDE.md).

Entwickler-Deploy-Anleitungen (`plugin-deploy.md`, `plugin-deploy-spike.md`) sind
für dieses Repository gedacht, nicht für eine fremde Schulinstanz — diese Anleitung
ersetzt sie für die Administration.

## 1. Plugin installieren

1. Plugin-ZIP nach `local/coursepilot` entpacken (oder über die Moodle-Oberfläche
   „Plugin installieren" hochladen).
2. `admin/cli/upgrade.php` ausführen (oder die Weboberfläche bestätigen lassen,
   die nach dem Hochladen automatisch dorthin führt). Registriert Webservices
   (`db/services.php`) und Capabilities (`db/access.php`).
3. Web Services und das REST-Protokoll aktivieren (Website-Administration ›
   Plugins › Web Services), falls noch nicht geschehen.
4. Die Fernzugriffsfreigabe für die Lehrkräfte einrichten (siehe Abschnitt 2,
   „Fernzugriffsfreigabe für Lehrkräfte").

Nach jeder späteren Aktualisierung des Plugins erneut `upgrade.php` ausführen —
Pflicht, sobald sich `db/access.php` oder `db/services.php` geändert haben, sonst
schlagen Kursnavigation und MCP-Werkzeugaufrufe mit HTTP 500 fehl (fehlende
Capability).

## 2. Einstellungen (Website-Administration › Plugins › lokale Plugins › Coursepilot)

### Fernzugriffsfreigabe für Lehrkräfte

Eine Lehrkraft darf einen KI-Chat erst verbinden, wenn die Administration ihr den
Fernzugriff freigegeben hat. Eine Einschreibung als `teacher` oder
`editingteacher` in einem Kurs reicht dafür nicht. Es gibt zwei Wege, beide ohne
eigene Coursepilot-Rolle (ADR 0026, Issue #579):

1. **Kohorte (empfohlen):** Unter Website-Administration › Plugins › lokale
   Plugins › Coursepilot bei **Fernzugriffs-Kohorten** eine oder mehrere
   bestehende **Systemkohorten** auswählen (z. B. die Kohorte, in der die Schule
   ihre Lehrkräfte ohnehin pflegt). Coursepilot legt keine Kohorte an; der
   Standard ist leer. Kohorten einer Kurskategorie sind nicht wählbar, weil
   Kategorie-Manager sie selbst pflegen. Unter dem Feld steht je gewählter
   Kohorte die Mitgliederzahl; eine inzwischen gelöschte Kohorte wird dort
   markiert und ignoriert.
2. **Vorhandene Systemrolle:** In einer Rolle, die die Schule systemweit schon
   vergibt, die Fähigkeit `local/coursepilot:useremote` auf „Erlauben" setzen.

Coursepilot prüft die Freigabe bei **jedem** Aufruf. Wer aus der Kohorte entfernt
wird oder die Rolle verliert, wird beim nächsten Aufruf abgewiesen, auch über eine
schon bestehende Verbindung. Kommt die Person zurück, funktioniert ihre Verbindung
wieder, ohne dass sie sich neu anmelden muss.

Die Fähigkeit `local/coursepilot:useremote` hat **keine** Archetyp-Vorbelegung
mehr: Eine systemweit zugewiesene `editingteacher`-Rolle bringt den Fernzugriff
nicht nebenbei mit und ist kein Einrichtungsweg. Hinweis: Moodles „Rechte prüfen"
zeigt nur den Rollenweg; ob jemand über eine Kohorte freigegeben ist, sieht man
an der Kohortenmitgliedschaft.

Die Freigabe gibt keine Kursrechte. Für Werkzeugaufrufe in einem Kurs bleiben
separat die Kursrolle und `local/coursepilot:use` (sowie die jeweils nötigen
Moodle-Kursrechte) nötig; niemand bekommt über den Fernzugriff Bearbeitungsrechte
an fremden Kursen. Fernzugriffsfreigabe und Kursberechtigung werden unabhängig
voneinander entzogen. Die Einstellung `remoteaccessenabled` unten ist nur die
instanzweite Notbremse und ersetzt keine Freigabe pro Person.

| Einstellung | Standard | Bedeutung |
|---|---|---|
| `remoteaccesscohorts` | leer | Systemkohorten, deren Mitglieder den Fernzugriff nutzen dürfen (siehe oben). |
| `remoteaccessenabled` | an | Notbremse: sperrt jeden weiteren MCP-Zugriff sofort. Bereits ausgestellte Zugriffstoken bleiben gültig — bei einem Sicherheitsvorfall zusätzlich den Sammelwiderruf auf der Verbindungsübersicht (Abschnitt 4) nutzen. Der normale Moodle-Login ist davon nicht betroffen. |
| `loglevel` | Lesezugriffe und Fehler | Steuert, was über die Moodle-Ereignis-API protokolliert wird und in den üblichen Protokollberichten erscheint. |
| `contextroot` | `coursepilot` | Wurzelordner des Kontextbereichs. Rein organisatorisch, keine Sicherheitsgrenze — die Isolation kommt aus `component`/`filearea`/`itemid`/`contextid`. Wirkt nur auf neu angelegte Dateien und nur für Lehrkräfte ohne eigenen Kontext-Pointer (externer Ablageort, siehe Abschnitt 6). |
| `materialroot` | `coursepilot-material` | Wurzelordner des Materialordners, gleiche Eigenschaften wie `contextroot`. |
| `allowpersonaldata` | **aus** | Schalter für personenbezogene Kontextdaten (ADR 0011). Wirkt auf die Markierung `coursepilot.personenbezug: true`, nicht auf den Inhalt: Solange aus, liefert kein Lese-Werkzeug eine so markierte Datei aus — sie erscheint in Listen als „gesperrt", nicht verborgen. Bewusst als Standard aus, damit eine frische Installation nie personenbezogene Daten an einen KI-Anbieter überträgt; das Einschalten ist die Dokumentationsspur, die eine Datenschutzprüfung sehen will. |
| `historyretentiondays` | 365 | Löschfrist des Änderungsverlaufs je Aktivität, mindestens 1 Tag. „Keine Frist" ist ausgeschlossen (Speicherplatz). Löschung läuft beim nächsten Schreibzugriff auf dieselbe Aktivität, kein Cron nötig. |
| `oauthregistersitelimit` | 200 | Anonyme OAuth-Clientregistrierungen pro Zeitfenster für die ganze Instanz. Darüber antwortet der Endpunkt mit HTTP 429 (`temporarily_unavailable`, `Retry-After`); bereits registrierte Clients arbeiten weiter. 0 oder negativ gilt als 1, „unbegrenzt" gibt es nicht. Details: `docs/oauth-connections.md`. |
| `oauthregistersourcelimit` | 50 | Dasselbe pro Anfragequelle (von Moodle ermittelte Absenderadresse, IPv6 je /64). KI-Anbieter registrieren von gemeinsamen Adressen aus – bei vielen Lehrkräften mit demselben Client großzügig wählen. |
| `oauthregisterwindow` | 3600 | Länge des Registrierungsfensters in Sekunden. Eine Änderung beginnt neue Fenster. Budgetdaten werden spätestens eine Stunde nach Fensterende gelöscht. Abgelaufene Codes, Tickets und Verbindungen sowie seit 30 Tagen unbenutzte Clients räumt die stündliche Aufgabe „Delete expired OAuth state“ auf (`docs/oauth-connections.md`). |
| `oauthcimdsitelimit` | 100 | Erstabrufe von Client-Metadatendokumenten (Clients, die sich mit einer https-URL ausweisen) pro Zeitfenster für die ganze Instanz. Darüber HTTP 429 ohne Abruf; bekannte Clients arbeiten weiter. Ein fehlgeschlagener Abruf wird für dieselbe URL bis zum Ende des laufenden 10-Minuten-Abschnitts nicht wiederholt. Details: `docs/oauth-connections.md`. |
| `oauthcimdsourcelimit` | 20 | Dasselbe pro Anfragequelle, gleiche Quellregeln wie bei der Registrierung. |
| `oauthcimdwindow` | 3600 | Länge des Abruffensters in Sekunden. |
| `personaldatahosts` | leer (nur Private Files) | Zugelassene externe Speicher für personenbezogene Kontextdaten (ADR 0021 §3), siehe Abschnitt 6. |
| `webdavhint` | leer | Optionaler Freitext für Lehrkräfte ohne eigene WebDAV-Verbindung auf der Ortswahlseite, zusätzlich zu den drei Einrichtungsschritten — z. B. eine Empfehlung, welchen Cloud-Dienst die Schule bereitstellt. |

Wichtig zu `allowpersonaldata`: Der Schalter hindert niemanden daran, einen Namen
in eine unmarkierte Sachdatei zu schreiben — er ist kein Inhaltsfilter. Sein Wert
liegt beim mitgebrachten Bestand (importierte Lerngruppenpakete, Migrationen), der
die Markierung schon mitbringt. Details und Begründung: ADR 0011.

## 3. Instanzvoraussetzungen prüfen (Systemoberfläche)

Erreichbar unter Website-Administration › Server (oder direkt
`/local/coursepilot/surface.php`, nur mit `moodle/site:config`). Zeigt zwei Dinge:

- **Werkzeugoberfläche:** Allowlist der registrierten MCP-Werkzeuge, verbotene
  Namensbestandteile und den Abgleich mit der tatsächlich registrierten
  Webservice-Oberfläche — reine Kontrollanzeige, kein Einstellschritt.
- **Instanzprüfung per Selbstabruf:** Coursepilot ruft die eigene
  Discovery-Adresse ab (`/local/coursepilot/oauth.php/.well-known/openid-configuration`)
  und prüft dabei faktisch drei Voraussetzungen für den Fernzugriffsbetrieb:
  - **HTTPS:** Die Instanz muss öffentlich über `https://` erreichbar sein.
  - **Egress:** die Discovery-Antwort muss die Instanz tatsächlich verlassen und
    wieder ankommen können (kein isoliertes Netz ohne Rückweg).
  - **PATH_INFO:** Der OIDC-Pfadanhang muss beim Skript ankommen. Das ist der
    typische Stolperstein: Standard-Apache liefert das über `AcceptPathInfo On`
    aus, ein Reverse-Proxy oder ein vorgelagerter Dienst kann es unterwegs
    kappen. Notausgang, falls PATH_INFO trotz „Null-Eingriff am Webserver" als
    Ziel nicht ankommt: `AcceptPathInfo On` explizit setzen.

Ein Fehlschlag hier ist die häufigste Ursache, warum ein Client sich nicht
verbinden kann, obwohl das Plugin korrekt installiert ist — vor jeder tieferen
Fehlersuche zuerst diese Seite prüfen.

## 4. OAuth-Clients und Verbindungen der Lehrkräfte

Jede Lehrkraft verbindet ihren MCP-Client selbst, ohne Zutun der Administration:
Client auf `https://<eure-instanz>/local/coursepilot/mcp.php` zeigen lassen und
einmalig über den OAuth-2.1-Consent-Bildschirm autorisieren — Discovery läuft
über RFC 8414/9728, kein Token wird von Hand weitergegeben.

Die Administration sieht und verwaltet alle aktiven Verbindungen zentral unter
Website-Administration › lokale Plugins › Coursepilot-Verbindungen (oder direkt
`/local/coursepilot/admin/connections.php`, nur mit `moodle/site:config`):
schulweite Übersicht über Personen hinweg, mit Einzelwiderruf je Verbindung und
Sammelwiderruf für einen Sicherheitsvorfall (siehe `remoteaccessenabled` oben,
das die Notbremse für neue Zugriffe ist — bestehende Token widerruft erst diese
Seite).

## 5. Admin-Statusprüfung (Drift je Aktivitätsart)

Erscheint als reguläre Moodle-Statusprüfung unter Website-Administration ›
Server › Systemstatusprüfungen — eine Prüfung je katalogisierter Aktivitätsart
(z. B. „Coursepilot: Drift quiz", „Coursepilot: Drift page"). Zeigt, ob die
Annahmen, mit denen Coursepilot eine Aktivitätsart über Moodles Formularpfad
(`add_moduleinfo()`/`update_moduleinfo()`) beschreibt, noch zur tatsächlich
installierten Version dieser Aktivität passen — wichtig nach jedem
Moodle-Kern- oder Zusatzplugin-Update, das ein Aktivitätsformular verändert
haben könnte. Rechnet bei jedem Seitenaufruf frisch, kein Cron nötig: einfach
die Statusseite neu laden, um den aktuellen Stand zu sehen.

## 6. Externer Ablageort (WebDAV)

Eine Lehrkraft kann Kontextbereich und Materialordner statt in Moodle in einem
eigenen WebDAV-Speicher führen (z. B. eine Nextcloud-Instanz) — Einrichtung
läuft über die Ortswahlseite der Lehrkraft selbst, nicht über die
Administration. Für die Schule sind vier Dinge wichtig:

- **Was an die KI geht:** Namen und Bilder aus dem Materialbestand können an
  die KI gehen, unabhängig vom Ablageort — gleiche Regel wie bei
  Moodle-eigenen Dateien.
- **Klartext-Passwort:** Eine WebDAV-Nutzerinstanz speichert das Passwort im
  Klartext. Ein App-Passwort statt des eigentlichen Kontopassworts wird der
  Lehrkraft empfohlen.
- **Core-Lücke „userid = 0":** Moodles Repository-Provider für
  Datenauskunft/-löschung sucht nach `userid`, WebDAV-Nutzerinstanzen tragen
  aber `userid = 0` und werden deshalb bei einer DSGVO-Auskunft/-Löschung
  **nicht gefunden**. Das ist eine bekannte Moodle-Kernlücke, keine
  Coursepilot-Besonderheit — bei einer Betroffenenanfrage muss die
  Administration diese Instanzen selbst mitdenken.
- **Schreibsperre, keine Lesesperre (ADR 0021 §3):** Über `personaldatahosts`
  legt die Schule fest, in welche externen Speicher Coursepilot Dateien mit
  `coursepilot.personenbezug: true` schreiben darf (ein Eintrag pro Zeile, gilt
  für die Domain samt Unterdomains, kein `*`; leere Liste = nur Private
  Files). Das ist eine Schreibsperre: Was eine Lehrkraft bereits in einem
  nicht zugelassenen Speicher abgelegt hat, bleibt lesbar — ob eine gelesene
  markierte Datei an die KI geht, entscheidet weiterhin allein
  `allowpersonaldata` (Abschnitt 2). Isolierung zwischen Lehrkräften kommt
  dabei aus dem Instanzeigentum (die `contextid` der WebDAV-Instanz muss dem
  Kontext des Token-Inhabers gehören), nicht aus einem Pfadpräfix — Details
  in ADR 0021.

Der aktuelle Stand der vier zugehörigen Statusprüfungen steht auf der
Systemstatus-Seite (Abschnitt 5).

> **⚠️ Achtung — Reverse-Proxy vor dem WebDAV-Speicher (z. B. Cloudflare):**
> Läuft der WebDAV-Speicher der Lehrkraft (z. B. eine eigene Nextcloud) hinter
> einem Reverse-Proxy wie Cloudflare, und ist dessen IP-Bereich nicht in der
> `trusted_proxies`-Einstellung des Speichers eingetragen, sieht der Speicher
> **jede** Anfrage als von der einen Proxy-IP kommend. Sein eingebauter
> Bruteforce-/Rate-Schutz drosselt dann diese eine IP — nicht die einzelne
> Lehrkraft — und blockiert dadurch ganz normale, kleine Coursepilot-Schreib-
> zugriffe für 15–30 Sekunden oder länger, teils wirkt es, als würde die
> Verbindung gar nicht ankommen (z. B. beim Speichern auf der Ortswahlseite).
> Betroffen ist praktisch jede selbstgehostete Nextcloud/WebDAV-Instanz hinter
> einem Reverse-Proxy, nicht nur bei sehr vielen Nutzenden — schon Coursepilots
> eigener Anfrage-Burst je Schreibvorgang reicht aus, den Schutz auszulösen.
> **Das ist kein Coursepilot-Fehler, sondern eine verbreitete
> Fehlkonfiguration auf Seiten des Speichers** (issue #529). Bei Meldungen wie
> „die Verbindung hängt"/„kommt nicht an"/„wartende Änderung bleibt stehen"
> zuerst `trusted_proxies` auf dem WebDAV-Speicher prüfen — nicht am
> Coursepilot-Plugin suchen. Coursepilot selbst hält sein stilles
> Wiederholungsbudget bewusst kurz (10 s) statt es an fremde, unbekannte
> Speicherqualität anzupassen; ein längeres Budget würde jeden Schreibaufruf
> für alle Lehrkräfte unnötig verzögern, auch bei korrekt konfigurierten
> Speichern.

## Weiterführend

- ADR 0011 — Personenbezogene Kontextdaten im Servermodell (`allowpersonaldata`).
- ADR 0021 — Isolierung und Personenbezug am externen Ablageort (`personaldatahosts`).
- Spec 0012 — `local_coursepilot`: Moodle-natives MCP-Plugin, Abschnitt 7
  (Instanzvoraussetzungen).
- Issue #529 — Reverse-Proxy/`trusted_proxies` am externen Ablageort, Messung
  und Entscheidung zum Wiederholungsbudget.
- `Plugin/src/local_coursepilot/README.md` — Kurzüberblick, auch für die
  Prüfung vor der Installation gedacht.
