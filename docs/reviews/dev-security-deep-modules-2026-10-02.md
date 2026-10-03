# Review von dev: Sicherheit und tiefe Module

Geprüfter Stand: **e69f2462e12e7a40ad3b1dd538110015b4673def**, festgehalten am 02.10.2026.
Vergleichsstand `main`: `467c954508291a700215b6cb4c2940ac85d29db4`.
Review-Branch: `review/dev-security-deep-modules-2026-10-02`.

**Urteil: keine Freigabe für diesen Stand.** Es gibt konkrete Probleme bei der
Vertraulichkeit, beim Rechteentzug und bei der Integrität von Kursinhalten.
Das Plugin hat ein brauchbares Fundament, aber wichtige Sicherheitsregeln sind
noch Eigenschaften einzelner Aufrufpfade. Sie müssen zu Garantien ihrer Module werden.
Ein grüner Testlauf reicht als Freigabeargument hier nicht aus.

## Umfang und Nachweisgrenzen

Schwerpunkt ist der native Server-MCP unter `Plugin/src/local_coursepilot/`:
HTTP/MCP und OAuth, Werkzeugregistrierung und Moodle-Rechte, Kontext-/Materialablage
und WebDAV, Aktivitätsanlage/Backup/Restore, Änderungsverlauf und Privacy-Provider,
Katalog-/Schreibwege sowie Test- und Release-Verträge. Der eingefrorene lokale
Node-/Legacy-Weg ist keine Empfehlung für einen Umbau und wurde nicht voll auditiert.

Bewertet wurde der Gesamtzustand; neue Befunde im `main...dev`-Delta sind von
bereits vorhandenen Problemen getrennt. Die Prüfung verbindet die vollständige
Produktionsdatei-Inventur mit vertieftem Lesen der genannten kritischen Pfade und
gezielten Prüfungen. Sie ist kein Vollbeweis für jede Zeile und jede Moodle-Erweiterung.
Keine Live-Angriffe, echten personenbezogenen Daten, Deployments, Schemaänderungen
oder Schreibzugriffe auf `dev`. Bestehende Container wurden für Moodle-Core-Quellcode
nur lesend benutzt; ausführbare Reproduktionen liefen in einem separaten Container.

| Messung | Ergebnis |
| --- | --- |
| PHP-Produktionsdateien einschließlich Sprachdateien | 176 |
| Produktionszeilen einschließlich Kommentare/Lizenzen | 38.678 |
| Registrierte MCP-Werkzeuge | 54 |
| Größte Produktionsdateien | `import_questions_xml.php` 952, `oauth_lib.php` 936, `catalog/assign.php` 926 Zeilen |
| Überschreitung der 1.000-Zeilen-Grenze in der nativen PHP-Produktion | Keine |
| Native JS-Suite, `npm run test:ci` | 1.068 Tests: 1.025 bestanden, 43 dokumentierte Skips, 0 Fehler |
| AMD-Syntax, `node --check .../location_selection.js` | Bestanden |
| PHP 8.4, Syntaxprüfung aller Plugin-PHP-Dateien einschließlich Tests | 306 Dateien bestanden |
| Offline-Reproduktion | 8 Beobachtungen zu fünf Befunden bestätigt |

`npm test` wurde ebenfalls ausgeführt, endet hier aber mit Fehlern in
plattformgebundenen Legacy-Tests; unter anderem fehlt die lokale PHP-CLI und der
Credential-Store unterstützt Linux nicht. Deshalb zählt der für diese Umgebung
dokumentierte native CI-Lauf oben, nicht ein behaupteter grüner Gesamtlauf.

Die native PHPUnit-Suite wurde **nicht neu ausgeführt**: Das vorhandene
`/opt/kurspilot-spike/scripts/phpunit.sh` synchronisiert vor dem Lauf den Pluginstand
nach `/opt/plugins/local_coursepilot/`. Das würde den gleichzeitig benutzten
Spike-Stand verändern. Ebenso fehlen in diesem Review eine frische
Moodle-5.0/5.1-Installationsprüfung, neue Coverage und Live-E2E. Die Offline-Proben
führen Originalmethoden mit kleinen Moodle-/DB-Doubles aus. Sie belegen die
Kontrollflüsse, nicht echte DB-Konkurrenz oder ein vollständiges HTTP-Szenario.

## Priorisierte Befunde

P1 bezeichnet einen hohen Sicherheits- oder Integritätsbefund, der vor Freigabe
behoben werden sollte; P2 ein konkretes mittleres Risiko oder einen wesentlichen
Wartbarkeitsmangel. Das sind Review-Prioritäten, keine berechneten CVSS-Werte.

### F1 — P1: Der Änderungsverlauf gibt geschützte Daten an die KI weiter

Belege: [`version_writer.php:303`](../../Plugin/src/local_coursepilot/classes/history/version_writer.php#L303),
[`version_history.php:345`](../../Plugin/src/local_coursepilot/classes/history/version_history.php#L345),
[`compare_activity_versions.php`](../../Plugin/src/local_coursepilot/classes/external/compare_activity_versions.php).
**Bereits vorhanden.**

`capture_files()` liest alle `files`-Zeilen des Modulkontexts. Dazu gehören bei
Aufgaben auch `assignsubmission_file/submission_files`. `gap=1` verhindert nur das
Rückschreiben; es filtert weder Speicherung noch Ausgabe. `file_map()` und
`diff_files()` liefern diese Dateinamen an `compare_activity_versions`. Schon
`Student-Example-medical-note.pdf` ist eine unerlaubte Offenlegung von Metadaten
einer Abgabe, auch ohne PDF-Inhalt.

Zusätzlich serialisiert `diff_fields()` rohe `availability` und
`availabilityconditionsjson` aus den Ständen. Eine Profilbedingung mit einer
konkreten E-Mail-Adresse erscheint unmaskiert in `before_json`/`after_json`.
Die vorhandene `availability_privacy::sanitize()` wird hier nicht verwendet.
Der Kontextdaten-Schalter schützt diesen anderen Ausgabepfad nicht.

**Abhilfe:** Bereits beim Erfassen ausschließlich freigegebene Gestaltungsdateien
zulassen, etwa Intro- und katalogisierte Materialbereiche. Historische Zeilen
müssen zusätzlich beim Lesen nach `component/filearea` gefiltert werden.
Für KI-Ausgaben eine sichere Projektion der Felder anwenden, bevor Werte
serialisiert werden. Eine native, intern zum Wiederherstellen benötigte Rohfassung
darf nicht zugleich die öffentliche Vergleichsantwort sein. Die Namens-Allowlist
von `privacy_surface` prüft Funktionsnamen; sie ersetzt diese Inhaltsprüfung nicht.

**Abnahme:** Aufgabe mit Lernenden-Abgabedatei und geänderter Profilbedingung
anlegen; Versionsvergleich über `dispatcher::handle()` darf weder Dateiname noch
Profilwert ausgeben. Native Wiederherstellung muss unverändert möglich bleiben.
Die Offline-Proben bestätigen sowohl Speicherung/Ausgabe des Dateinamens als auch
die unmaskierte Ausgabe der Profilbedingung.

### F2 — P1: Die Bereinigung löscht parallel angelegte fremde Aktivitäten

Belege: [`activity_backup.php:272`](../../Plugin/src/local_coursepilot/classes/activity_backup.php#L272),
[`export_default_activity.php:78`](../../Plugin/src/local_coursepilot/classes/external/export_default_activity.php#L78),
[`course_module_placement.php:85`](../../Plugin/src/local_coursepilot/classes/course_module_placement.php#L85).
**Neu im Delta**, einschließlich des gemeinsamen Backup-Moduls.

Beide Aufrufpfade bestimmen eigene Fehlanlagen durch die Differenz der gesamten
Kursmodul-IDs vor/nach dem Aufruf. Ablauf: A merkt sich den Bestand; B legt im selben
Kurs regulär eine Aktivität an; A räumt auf. Bs Aktivität gehört zur Differenz und
wird ebenfalls an `discard_failed()` übergeben. Beim Default-Export genügt ein
normaler erfolgreicher Export, beim Restore ein Fehler. Die Bereinigung entfernt
auch den Papierkorbeintrag. Das ist ein tatsächlicher Datenverlustpfad.

Der Kommentar in `discard_failed()` benennt diese Konkurrenz bereits. Das macht
den Befund zu einem dokumentierten, aber unvertretbaren Sicherheitskompromiss.
Eine Sperre nur zwischen Coursepilot-Aufrufen würde native Moodle-Anlagen nicht
abdecken.

**Abhilfe:** Bereinigung ausschließlich mit IDs, die dem eigenen Anlege-/Restore-
Vorgang sicher zugeordnet sind. Beim Default-Export die zurückgegebene CM-ID
verwenden; für partielle Restore-Anlagen die vom eigenen Controller/Task angelegten
IDs zuverlässig erfassen. Kann Zugehörigkeit nicht bewiesen werden, keine
kursweite Löschheuristik verwenden. Die Interface-Garantie des Backup-Moduls muss
lauten: Es entfernt ausschließlich seine eigenen Anlagen.

**Abnahme:** Zwei über eine Barriere verschachtelte Aufrufe, darunter eine native
Moodle-Anlage; Erfolg und Fehler des ersten Aufrufs dürfen den zweiten nicht
entfernen. Die Offline-Probe bestätigt, dass die heutige Bereinigung sowohl die
eigene als auch die simulierte fremde CM-ID löscht.

### F3 — P1: `dry_run` und `replaces_cmid` sind im echten Werkzeugvertrag vertauscht

Beleg: [`create_activity_from_xml.php:50`](../../Plugin/src/local_coursepilot/classes/external/create_activity_from_xml.php#L50)
gegen die `execute()`-Parameter ab Zeile 65. **Neu im Delta.**

Die Deklaration ordnet `hidden, dry_run, replaces_cmid`; die PHP-Methode erwartet
`hidden, replacescmid, dryrun`. Moodles `external_api::call_external_function()`
sortiert nach Deklaration, bildet `array_values()` und ruft die Methode
positionsgebunden auf. Das wurde am installierten Moodle-Core nachgelesen.

Ein normaler Ablöseaufruf mit `replaces_cmid=42, dry_run=false` wird dadurch zu
`replacescmid=0, dryrun=true` und scheitert. Noch problematischer:
`dry_run=true, replaces_cmid=0` wird zu `replacescmid=1, dryrun=false` und erreicht
den Schreibpfad. Sind Aktivität 1, Art, Kurs und Berechtigungen passend, kann der
als Vorschau gedachte Aufruf schreiben. Direkte Tests von `execute()` bilden
dieses MCP-Verhalten nicht ab.

**Abhilfe:** Reihenfolge identisch halten; anschließend alle Werkzeuge gegen
Deklaration und Methode prüfen. Für diesen Fall einen Test über die echte externe
Aufrufschicht hinzufügen. Eine neue Übersetzungsschicht ist unnötig.

**Abnahme:** Vorschau ohne Vorgänger liefert den vorgesehenen Parameterfehler und
schreibt nichts; Vorschau mit gültigem Vorgänger schreibt ebenfalls nichts;
Ablösen funktioniert mit derselben benannten MCP-Eingabe. Die Offline-Probe führt
den fehlerhaften positionsgebundenen Aufruf aus und bestätigt den Eintritt in den
Schreibpfad mit Vorgänger 1; der eigentliche Restore ist dabei ein Double.

### F4 — P1: CIMD lädt OAuth-Clientmetadaten ohne Zertifikatsprüfung

Beleg: [`oauth_lib.php:319`](../../Plugin/src/local_coursepilot/classes/oauth_lib.php#L319).
**Bereits vorhanden.**

`fetch_and_cache_cimd_client()` benutzt eine frische Moodle-`curl`-Instanz und setzt
nur Timeout und Weiterleitungssperre. Moodle `curl::resetopt()` setzt standardmäßig
`CURLOPT_SSL_VERIFYPEER=0`; dies wurde im vorhandenen Core-Quellcode bestätigt.
Der WebDAV-Transport desselben Plugins korrigiert genau diesen Standard bereits
ausdrücklich. Der CIMD-Weg tut es nicht.

Bei einem erstmaligen CIMD-Abruf kann ein Angreifer auf dem Netzwerkpfad dadurch
Metadaten mit einem nicht vertrauenswürdigen Zertifikat unterschieben. Diese
Metadaten bestimmen unter anderem Clientanzeige und zugelassene Redirect-URIs und
werden dauerhaft gespeichert. HTTPS als URL-Schema genügt dafür nicht. PKCE
ersetzt die Authentizität der Clientmetadaten nicht; Code-Diebstahl allein wäre
allerdings wegen PKCE noch kein ausreichender Tokenangriff.

**Abhilfe:** Für CIMD Zertifikats- und Hostprüfung ausdrücklich aktivieren,
Weiterleitungssperre und Moodle-Hostsperre erhalten. Die Antwortgröße zusätzlich
begrenzen. Ein kleines internes Modul zum sicheren Abruf öffentlicher Metadaten
kann das kapseln; den Basic-Auth-WebDAV-Adapter dafür nicht zweckentfremden.

**Abnahme:** CIMD mit vertrauenswürdigem Zertifikat funktioniert; selbstsigniertes
Zertifikat, gesperrter Host, Redirect und zu große Antwort werden abgelehnt.
Kein echter MITM-Angriff im Review. Die SSRF-Abwehr ist konfigurationsabhängig,
aber die Moodle-Hostsperre wird hier nicht ausdrücklich umgangen; deshalb wird
kein pauschaler SSRF-Durchbruch behauptet.

### F5 — P1: Refresh-Rotation erkennt kompromittierte Verbindungen nicht

Beleg: [`oauth_lib.php:539`](../../Plugin/src/local_coursepilot/classes/oauth_lib.php#L539)
und `claim_row()` ab Zeile 595. **Bereits vorhanden.**

Die atomare Rotation verhindert die doppelte Einlösung, bewahrt aber keine
Verbindung zwischen altem und neuem Tokenpaar. Der Claim überschreibt sogar den
alten `refreshtokenhash`. Stiehlt ein Angreifer ein Refresh-Token und rotiert zuerst,
bekommt der rechtmäßige Client danach `invalid_grant`. Das neue Paar des Angreifers
bleibt gültig und weiter erneuerbar; die Wiederverwendung sperrt es nicht.

Das entspricht nicht dem Replay-Schutz der Rotation für öffentliche Clients:
[RFC 9700, Abschnitt 4.14.2](https://www.rfc-editor.org/rfc/rfc9700.html#section-4.14.2)
verlangt bei diesem Verfahren die erhaltene Beziehung und den Widerruf des aktiven
Nachfolgers nach erkannter Wiederverwendung.

**Abhilfe:** Eine stabile Verbindung/Grant-ID als Eigentümer der Tokenfolge führen.
Verbrauchte Hashes müssen zur Replay-Erkennung dieser Verbindung zuordenbar
bleiben. Rotation und familienweiter Widerruf müssen dieselbe atomare
Zustandsentscheidung verwenden. Downloadtickets sollten sich an diese stabile
Verbindung binden: Die aktuelle Bindung an eine Tokenpaar-ID macht reguläre
Rotation nebenbei zu einem Widerruf alter Tickets.

**Abnahme:** Angreifer rotiert, legitimer Client verwendet den alten Refresh-Wert;
alle daraus entstandenen Tokens und Tickets werden unbrauchbar. Parallelität und
expliziten Widerruf zusätzlich gegen eine echte DB prüfen. Die Offline-Probe
bestätigt die heutige Weiterverwendbarkeit des Nachfolger-Access-Tokens.

### F6 — P1: Downloadtickets überleben den Entzug der Fernzugriffsfreigabe

Beleg: [`workbench_ticket.php:156`](../../Plugin/src/local_coursepilot/classes/workbench_ticket.php#L156).
**Bereits vorhandener Ticketpfad; weiterhin offen nach der aktuellen Freigabeänderung.**

MCP prüft `remote_access::is_granted()` bei jedem Aufruf. Der separate
Downloadendpunkt prüft Killswitch, Tokenpaar-Widerruf, Konto und Dateihash, aber
nicht die aktuelle Fernzugriffsfreigabe. Entfernt ein Admin die Person aus der
Freigabekohorte oder entzieht `useremote`, wird MCP gesperrt; ein schon ausgestelltes
Ticket kann die Datei bis zu 15 Minuten danach trotzdem ausliefern.

**Abhilfe:** Beim Einlösen die Freigabe der Ticket-Person mit ihrer expliziten
`userid` prüfen. Nicht den anonymen aktuellen `$USER` benutzen und nicht davon
ausgehen, dass Freigabeentzug automatisch Tokens widerruft. Diese Entscheidung
gehört in das Ticket-/Verbindungsmodul, damit jeder Downloadweg dieselbe Garantie hat.

**Abnahme:** Ticket ausstellen, Kohortenmitgliedschaft entfernen bzw. Capability
entziehen, ohne Tokenwiderruf herunterladen: keine Dateibytes. Die Offline-Probe
bestätigt, dass die heutige Gültigkeitsprüfung die Freigabe überhaupt nicht abfragt.

### F7 — P2: Die Löschfrist und der Privacy-Provider decken den Verlauf nicht ab

Belege: [`retention.php:67`](../../Plugin/src/local_coursepilot/classes/history/retention.php#L67),
[`retention.php:125`](../../Plugin/src/local_coursepilot/classes/history/retention.php#L125),
[`privacy/provider.php:205`](../../Plugin/src/local_coursepilot/classes/privacy/provider.php#L205).
**Bereits vorhanden.**

Die eingestellte Frist bereinigt nur beim nächsten Schreiben derselben Aktivität.
Unveränderte, weiter bestehende Kurse behalten alte Stände unbegrenzt. Gelöschte
Versionsverknüpfungen lassen außerdem `local_coursepilot_cm_file` stehen. Der
Privacy-Provider beschreibt die Verlaufstabellen, berücksichtigt sie aber weder
bei der Ermittlung betroffener Nutzer/Kontexte noch beim Export und Löschen.
Die Aussage im Provider-Kommentar, jeder Stand verschwinde spätestens automatisch,
ist damit falsch. Schon die gespeicherte Autor-`userid` ist ein Personenbezug;
F1 zeigt weitere tatsächlich mitgespeicherte Daten.

**Abhilfe:** Frist durch begrenzte, indizierte Hintergrundbereinigung unabhängig
von Aktivitätsänderungen durchsetzen; verwaiste Dateimetadaten entfernen.
Nutzer-/Kontext-Ermittlung, Export und Löschung bzw. gezielte Anonymisierung für
den Verlauf vollständig definieren. Dies ist ein technischer Befund zur
Implementierung, keine rechtliche Bewertung einer konkreten Schule.

**Abnahme:** Überalterter Stand einer unveränderten Aktivität verschwindet durch
den Bereinigungslauf; Privacy-Anfragen erfassen den Autor im Verlauf; es bleiben
keine zugehörigen verwaisten Dateimetadaten zurück.

### F8 — P2: Öffentliche OAuth-Endpunkte erlauben unbegrenztes Zustandswachstum

Belege: [`oauth/register.php:27`](../../Plugin/src/local_coursepilot/oauth/register.php#L27),
[`oauth_lib.php:144`](../../Plugin/src/local_coursepilot/classes/oauth_lib.php#L144)
und `get_client()`/`handle_token()`. **Bewusst aufgeschobener Bestand.**

Jeder gültige anonyme Registrierungsaufruf erzeugt einen Client. Es gibt im Plugin
weder Drossel noch Client-Löschfrist. Zusätzlich kann der anonyme Tokenendpunkt
durch unbekannte HTTPS-`client_id` CIMD-Netzwerkabrufe auslösen, bevor er Grant und
Token geprüft hat. Anonyme Angreifer brauchen dafür weder Lehrkraftkonto noch
bekannten Nutzerkreis. OAuth-Codes und abgelaufene Tokenpaare haben ebenfalls
keine allgemeine Bereinigung im Plugin.

**Abhilfe:** Vor dem öffentlichen Betrieb Registrierungs-/Abrufbudgets mit
festen Grenzen und nachvollziehbarer Bereinigung einführen; Body-/Metadatengrößen
begrenzen. Ein vorgeschaltetes Limit kann eine sofortige Betriebsmaßnahme sein,
muss dann aber als Voraussetzung belegt werden. Es ersetzt keine Lebensdauer
der gespeicherten Datensätze.

**Abnahme:** Wiederholte anonyme Registrierungen und unbekannte CIMD-IDs werden
begrenzt; bereits autorisierte legitime Clients bleiben nutzbar; überalterte,
unbenutzte Datensätze werden bereinigt. Kein Lasttest gegen eine laufende Instanz.

### F9 — P2: Ein als lesend registrierter Export legt eine sichtbare Aktivität an

Belege: [`export_default_activity.php:138`](../../Plugin/src/local_coursepilot/classes/external/export_default_activity.php#L138),
[`tool_registry.php:118`](../../Plugin/src/local_coursepilot/classes/tool_registry.php#L118).
**Neu im Delta.**

`export_default_activity` ist als `read` eingestuft, ruft aber `add_moduleinfo()`
mit `visible=1, visibleoncoursepage=1` auf. Zwischen Anlage und Bereinigung steht
eine echte sichtbare Aktivität im Kurs. Ihre Ereignisse sind ebenfalls echt.
Schlägt die Bereinigung fehl, wird die Ausnahme nur mit `debugging()` protokolliert;
der Aufruf kann trotzdem erfolgreich XML zurückgeben und die Anlage zurücklassen.
Die Prüfung von `manageactivities` verhindert zwar einen Schreibzugriff ohne
Bearbeitungsrecht, macht den Vorgang aber nicht lesend.

**Abhilfe:** Mindestens von Beginn an versteckt anlegen, als schreibend
registrieren und eine fehlgeschlagene Bereinigung als fehlgeschlagenen Export
melden. In einem späteren strukturellen Schritt prüfen, ob die Vorlage ohne
Anlage im Zielkurs gewonnen werden kann; das muss mit den tatsächlichen
Moodle-Backup-Anforderungen belegt werden. Keine erfundene universelle Vorschau-API.

**Abnahme:** Während des gesamten Exports keine sichtbare Aktivität; erzwungener
Cleanup-Fehler ergibt keinen vorbehaltlosen Erfolg. Zusammen mit F2 testen.

### F10 — P2: Der Speichermigrationspfad enthält acht unerreichbare private Methoden

Beleg: [`context_area.php:311`](../../Plugin/src/local_coursepilot/classes/context_area.php#L311).
**Bereits vorhanden; die Datei hat auch auf main 787 Zeilen.**

Die öffentlichen `write()`/`append()` verwenden inzwischen `storage_anchor::port()`.
Die alten privaten Wurzeln `resolve_write_target`, `write_moodle`,
`resolve_append_target` und `append_moodle` werden nicht mehr aufgerufen. Ihre
ausschließlich von dort erreichbaren Helfer sind `guard_personal_data_for_write`,
`require_moodle_checkvalue_match`, `guard_personal_data_for_append` und
`peek_append_target_content`. Das sind zusammen **163 Methodenzeilen**, dazu
umfangreiche Kommentare. Die Aufrufsuche ergab keine lebenden Aufrufpfade zu
diesen vier Wurzeln.

Hier gibt es einen klaren strukturellen Hebel: diesen zweiten Ablauf löschen.
Aufteilen in weitere Dateien würde tote Sicherheitslogik nur verteilen.
Anschließend verbleibende `pointer_reader`-/`pointer_writer`-Verwendungen prüfen:
einige Material-/Altortwege umgehen weiterhin den neuen Adaptervertrag. Der
existierende `storage_port` ist sinnvoll, weil Private Files und WebDAV tatsächlich
zwei Adapter sind; die Migration muss ihn zum einzigen Operationsweg machen.

**Abnahme:** Native und externe Schreib-/Append-, Konflikt-, Datenschutz- und
Ausfallantworten über die öffentliche Interface prüfen. Nach Entfernen der acht
Methoden keine zweite Implementierung derselben Entscheidung behalten.

### F11 — P2: Die Feldkataloge liefern Regeln, deren Interpreter über Werkzeuge verteilt sind

Belege: [`module_catalog.php:77`](../../Plugin/src/local_coursepilot/classes/catalog/module_catalog.php#L77),
[`create_module.php:186`](../../Plugin/src/local_coursepilot/classes/external/create_module.php#L186),
[`create_module.php:543`](../../Plugin/src/local_coursepilot/classes/external/create_module.php#L543),
[`update_module_settings.php:536`](../../Plugin/src/local_coursepilot/classes/external/update_module_settings.php#L536).
**Bereits vorhanden.**

`write_options(): array<string,mixed>` ist ein offener Regelvertrag. Anlege- und
Änderungswerkzeuge interpretieren Datumsregeln, Stealth, Editor-/Dateipseudofelder,
Defaults und native Schreibobjekte selbst. Datumsprüfung und Stealth sind konkrete
Doppelimplementierungen. `create_module` und `update_module_settings` sind mit
785/740 Zeilen keine dünnen External-Adapter. Ein neuer Sicherheitsschritt muss
an mehreren Orchestrierungen richtig eingeordnet werden; reine Extraktion kleiner
Helfer senkt diese Zahl von Entscheidungen nicht.

**Abhilfe:** Den vorhandenen Katalogkern zu einem tiefen Schreibmodul ausbauen.
Extern reichen zwei klar verschiedene Operationen `create` und `update`; intern
werden benannte Werte mit Defaults bzw. Ist-Stand zusammengeführt und dieselben
Regeln gegen den effektiven Zielstand geprüft. Die Menge ausdrücklich geänderter
Felder bleibt erhalten, damit ein unabhängiger Patch bestehende Altdaten nicht
neu bewertet. Dateiauflösung und native Anwendung folgen erst nach Prüfung.
Den vorhandenen `catalog_fields`-Kern und Moodle-Schreibweg wiederverwenden.
Kein allgemeiner Policy-Interpreter, keine Factory und kein künstlicher Adapter
für einen einzigen realen Schreibweg.

**Abnahme:** Derselbe Regelverstoß wird beim Anlegen und Ändern konsistent
behandelt; unbekannte Felder, Lernendenriegel und Dateipseudofelder behalten ihre
Garantien. Tests kreuzen die öffentliche Schreib-Interface, nicht die interne
Abfolge von Helfern.

## Gesamtkonstitution und sinnvolle Modulgrenzen

Erhalten werden sollten die native Moodle-Capability-Prüfung, die einzige
Werkzeugregistrierung, das fail-closed Art-Tor, die katalogbezogene Driftprüfung,
die Benutzung von `add_moduleinfo`/`update_moduleinfo`, der echte Zwei-Adapter-
Vertrag für die Ablage sowie der gehärtete WebDAV-Transport. Atomare Claims und
gehashte Access-/Refresh-/Downloadgeheimnisse sind ebenfalls gute Grundlagen.
Bei Backup ist `MODE_IMPORT` mit ausgeschlossenen Nutzerdaten ein sinnvoller
Ansatz; der Metadatenpfad des Verlaufs ist davon unabhängig und durch F1 offen.

Das Größenproblem ist hier vor allem **verteiltes Wissen**, nicht eine neue
1.000-Zeilen-Datei. Die großen Katalogdateien bestehen wesentlich aus
Felddeklarationen und sind nicht automatisch schlechte Module. Hingegen sind
Kommentarhistorien und kleine Helfer keine Tiefe, wenn Aufrufer weiterhin die
gesamte Reihenfolge und die Sicherheitsausnahmen kennen müssen.

| Tiefes Modul | Kleine externe Interface | Intern garantieren | Was dadurch entfällt |
| --- | --- | --- | --- |
| Verbindung und Authentifizierung | Authentifizieren, erneuern, widerrufen, Verbindung prüfen | Konto/Freigabe, Tokenfolge, Replay, Ticketbindung | Tokenpaar-ID als Verbindungsersatz; einzelne unterschiedliche Freigabeprüfungen |
| Aktivitätsanlage/Backup | Anlegen/klonen/exportieren als klar getrennte Operationen | Eigene CM-IDs, versteckte Anlage, Cleanup, Veröffentlichung, Verlauf | Kursweite Vorher-/Nachher-Differenzen; Cleanup-Pflichten im External-Adapter |
| Änderungsverlauf | Erfassen, sicheren Vergleich lesen, Wiederherstellung vorbereiten | Erlaubte Felder/Dateien, sichere KI-Projektion, Aufbewahrung | Rohe interne Zustände als Tool-Antwort; unabhängige Filter je Endpunkt |
| Ablage | `read/list/write/append/delete` an einem aufgelösten Ort | Pfade, Eigentum, bedingtes Schreiben, Ortsfehler | Alte parallel gepflegte Pointer-Operationspfade und tote Hilfsabläufe |
| Katalogschreiben | `create/update` | Zielstand, Regeln, Pseudofelder, native Anwendung | Doppelinterpretation der gleichen Regeln in Werkzeugen |

Das sind vorgeschlagene Eigentumsgrenzen, keine Forderung nach fünf neuen
Abstraktionsschichten. Bestehende Module vertiefen und überflüssige Wege entfernen.
Die kleinstmögliche Schnittstelle muss reale Garantien bieten; nicht bloß eine
lange Reihe vorhandener Helpers weiterreichen.

## Empfohlene Umsetzung in getrennten, überprüfbaren Schritten

1. **Offenlegungen und gefährliche Schreibpfade schließen:** F1–F3 und F6.
   Erst sichere Projektion/Dateifilter, ID-Eigentum und korrekter Werkzeugvertrag.
   Für F2 Controller-Zuordnung klären, bevor weitere Restore-Aufrufer entstehen.
2. **OAuth-Verbindung stabilisieren:** F4–F5. TLS-Korrektur ist ein kleiner
   unabhängiger Fix. Tokenfamilien/Ticketbindung brauchen einen eigenen Plan
   einschließlich Schema, Upgrade und Konkurrenztests.
3. **Betriebs- und Datenschutzverträge erfüllen:** F7–F9. Fristen/Privacy-Provider,
   anonyme Budgets sowie wahrheitsgemäße Schreibklassifikation und versteckte
   Default-Exporte. Upgrades und Tests ausschließlich isoliert vorbereiten.
4. **Speicherpfade löschen und vervollständigen:** F10. Zuerst tote private
   Methoden entfernen; dann verbliebene lebende Altpfade über den vorhandenen
   `storage_port` führen. Keine neue Layer-Struktur darüberstellen.
5. **Schreibmodul vertiefen:** F11. Mit den belegten Doppelregeln beginnen und
   Anlegen/Ändern gemeinsam absichern; anschließend Wiederherstellungsorchestrierung
   und Teilfehler prüfen. Die Reihenfolge von Bestätigung, Prüfung und Mutation
   gehört ins Modul, nicht in jede Oberfläche.

Jeder Schritt braucht passende native Moodle-Tests. Insbesondere reichen für
Sicherheitsgarantien weder reine Quelltext-Regextests noch Line-Coverage allein.
Eine frische PHPUnit-/Installationsumgebung für den festen Commit ist vor
Freigabe erforderlich. Keine Änderung an der laufenden Spike-Instanz ableiten.

## Reproduktion

Datei: [`repros/dev-security-2026-10-02.php`](repros/dev-security-2026-10-02.php).
Sie enthält ausschließlich synthetische Fixtures und meldet `CONFIRMED`, wenn
die beschriebenen Fehler weiterhin reproduziert werden. Das ist ein
Review-Nachweis, keine nach einer Reparatur grün zu haltende Regressionstest-Suite.

Aus dem Review-Worktree, mit dem vorhandenen PHP-8.4-Image:

```bash
docker run --rm --network none --read-only \
  --tmpfs /tmp:rw,noexec,nosuid,size=8m \
  -v "$PWD":/review:ro -w /review --entrypoint php \
  moodlehq/moodle-php-apache:8.4 \
  docs/reviews/repros/dev-security-2026-10-02.php
```

Die Isolation schützt den Arbeitsstand und vermeidet Netzzugriffe. Die Doubles
ersetzen Moodle-Objekte, Datenbank, Dateibestand und im F3-Schreibnachweis den
Restore selbst. Deshalb muss die spätere Reparatur durch echte Moodle-Tests
an denselben Interfaces abgesichert werden.
