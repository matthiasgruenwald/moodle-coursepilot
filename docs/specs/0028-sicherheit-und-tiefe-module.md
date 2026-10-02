# Spec 0028 — Sicherheit und tiefe Module für das native Coursepilot-Plugin

Stand: 02.10.2026. Grundlage: Review von dev bei
e69f2462e12e7a40ad3b1dd538110015b4673def.

Diese versionierte Spezifikation wird vollständig als GitHub-Umsetzungsissue mit
ready-for-agent veröffentlicht. Issue und Dokument tragen denselben Vertrag.

## Problem Statement

Lehrkräfte müssen darauf vertrauen können, dass Coursepilot geschützte Daten
nicht an die KI weitergibt, Vorschauen keine Änderungen vornehmen und
fehlgeschlagene Anlagen keine fremden Aktivitäten löschen. Administratoren
müssen Fernzugriff zuverlässig entziehen und gespeicherte Daten begrenzen können.

Der geprüfte dev-Stand verletzt diese Erwartungen: Der Änderungsverlauf gibt
Abgabedateinamen und Profilwerte aus; konkurrierende Aktivitäten können bei der
Bereinigung gelöscht werden; vertauschte Parameter machen eine Vorschau unter
bestimmten Bedingungen zum Schreibaufruf. OAuth-Clientmetadaten werden ohne sichere
Zertifikatsprüfung geladen, Refresh-Replay sperrt die kompromittierte Verbindung
nicht, und Downloadtickets überleben den Entzug der Fernzugriffsfreigabe.

Hinzu kommen unvollständige Verlaufsbereinigung und Privacy-Verarbeitung,
unbegrenzte öffentliche OAuth-Zustandsanlage und ein als lesend deklarierter
Export mit sichtbarer Zwischenanlage. Für die Wartung liegen Sicherheitsregeln
in mehreren Aufrufpfaden. Tote Ablageverfahren und doppelte Interpretation von
Katalogregeln erhöhen die Wahrscheinlichkeit weiterer Fehler.

## Solution

Alle elf Review-Befunde werden durch überprüfbare Garantien geschlossen.
Vorhandene Module übernehmen die Verantwortung für sichere Verbindungen, eigene
Aktivitätsanlagen, geschützten Verlauf, ortsneutrale Ablage und Katalogschreiben.
Die Werkzeuge werden dünne Adapter dieser fachlichen Operationen.

Die Lehrkraft erhält sichere Vergleiche und echte Vorschauen. Temporäre Anlagen
bleiben versteckt; Coursepilot bereinigt ausschließlich nachweislich eigene
Objekte und meldet unvollständige Bereinigung als Fehler. Entzogene Freigaben und
erkannte Token-Wiederverwendung sperren die betroffenen Zugriffe. Fristen,
öffentliche Budgets und Privacy-Anfragen werden im Betrieb wirksam.

Die Umsetzung erfolgt in überprüfbaren Schritten auf separaten Branches aus dev
mit isolierten Moodle-Testressourcen. Die gleichzeitig verwendete
Entwicklungsinstanz und das dev-Arbeitsverzeichnis bleiben unberührt.

## User Stories

1. As a teacher, I want version comparisons to omit learner submission metadata, so that private learner information never reaches the AI through history.
2. As a teacher, I want profile conditions to be sanitized before comparison output, so that email addresses and other profile values remain protected.
3. As a teacher, I want older stored versions to receive the same output protection, so that an upgrade also closes existing disclosure paths.
4. As a teacher, I want permitted activity design files to remain restorable, so that privacy protection preserves legitimate recovery.
5. As a teacher, I want the personal-data setting for my context area to retain its existing scope, so that it cannot enable disclosure of learner submissions.
6. As a teacher, I want failed activity creation to remove only its own objects, so that another person's concurrent work remains intact.
7. As a teacher, I want successful default export to preserve concurrently created activities, so that obtaining a template cannot cause data loss.
8. As a teacher, I want uncertain ownership to prevent automatic deletion, so that missing restore metadata never authorizes course-wide cleanup.
9. As a teacher, I want a dry run to leave activities, files and history unchanged, so that I can inspect a proposed replacement safely.
10. As a teacher, I want named replacement parameters to reach the intended operation, so that a valid replacement works through the actual MCP call.
11. As a maintainer, I want all external functions to satisfy their declared positional contract, so that dispatch cannot silently change parameter meaning.
12. As an administrator, I want CIMD metadata to require a trusted certificate and matching hostname, so that client identity cannot be substituted on the network path.
13. As an administrator, I want blocked hosts, redirects and oversized metadata responses to be rejected, so that discovery respects network and resource boundaries.
14. As a client user, I want refresh rotation to retain my connection identity, so that legitimate renewal preserves connection-bound downloads.
15. As a client user, I want reuse of a consumed refresh token to revoke its entire connection, so that a stolen successor cannot continue accessing Moodle.
16. As an administrator, I want concurrent rotation and revocation to share an atomic decision, so that races cannot leave a valid successor after revocation.
17. As a client user, I want explicit revocation to invalidate access tokens, refresh tokens and download tickets together, so that disconnecting reliably ends access.
18. As an administrator, I want existing valid token pairs to migrate predictably, so that the upgrade avoids unnecessary reconnection and fabricated token ancestry.
19. As an administrator, I want removal from the remote-access cohort to invalidate existing downloads immediately, so that withdrawal applies beyond MCP.
20. As an administrator, I want withdrawal of the remote-access capability to invalidate existing downloads immediately, so that both approval paths enforce the same rule.
21. As a teacher, I want download validation to use the ticket owner's identity, so that anonymous HTTP execution cannot bypass my access restrictions.
22. As an administrator, I want old history to expire even in unchanged activities, so that the configured retention period bounds stored versions.
23. As an administrator, I want cleanup to remove orphaned history file metadata, so that deleting versions does not leave their metadata behind.
24. As a user, I want privacy discovery and export to include history I authored, so that my stored personal data can be found and exported.
25. As a user, I want approved privacy deletion to remove my authored history within approved contexts, so that my history records and personal associations are erased.
26. As a teacher, I want another user's privacy request to preserve current activities and unrelated history, so that privacy processing does not become course-content deletion.
27. As an administrator, I want anonymous registrations to have finite budgets, so that callers cannot create unbounded client records.
28. As an administrator, I want unknown CIMD identifiers to be limited before network retrieval, so that anonymous requests cannot exhaust workers through discovery.
29. As a legitimate client user, I want authorized clients to remain usable when discovery is throttled, so that public endpoint abuse does not unnecessarily stop my work.
30. As an administrator, I want expired OAuth state and unused clients to be cleaned up, so that routine operation does not accumulate records indefinitely.
31. As an administrator, I want cleanup to preserve live grants and necessary replay evidence, so that resource limits do not weaken connection security.
32. As a teacher, I want default-template export to be declared as a write operation, so that the workflow accurately represents its Moodle side effects.
33. As a learner, I want temporary export activities to remain hidden throughout their lifetime, so that I never see incomplete teaching material.
34. As a teacher, I want failed export cleanup to return an explicit failure, so that I am not told the course was left clean when objects remain.
35. As a teacher, I want context and material operations to use one storage contract, so that Private Files and WebDAV preserve the same public behavior.
36. As a teacher, I want conditional writes and append conflicts to remain enforced, so that storage refactoring cannot overwrite another edit.
37. As a teacher, I want storage ownership and personal-data markers to remain enforced, so that the unified route preserves existing boundaries.
38. As a teacher, I want a failed external write to create an Ausstandsnotiz without fallback storage, so that I can deliberately retry at the chosen location.
39. As a teacher, I want the previous location and Materialbestand to retain their permitted operations, so that refactoring does not introduce unintended writes.
40. As a maintainer, I want unreachable migration methods removed after caller verification, so that only live security decisions remain to maintain.
41. As a teacher, I want create and update to apply the same catalog rules to the effective target state, so that equivalent invalid input receives consistent treatment.
42. As a teacher, I want unrelated updates to tolerate unchanged legacy values, so that an independent edit is not blocked by historical inconsistencies.
43. As a teacher, I want authorization and validation to complete before file mutations, so that rejected changes leave no partial content behind.
44. As a teacher, I want learner protections and restricted fields to remain effective, so that simplifying the write path cannot bypass safeguards.
45. As a maintainer, I want tests to use real Moodle dispatch and database concurrency, so that passing helper tests cannot conceal security failures.
46. As a maintainer, I want upgrades and regression tests to run on isolated resources, so that implementation does not disturb concurrent dev work.

## Implementation Decisions

### Umfang und Reihenfolge

Die elf Befunde bilden die vollständige Abnahmebasis. P1-Befunde F1–F6 sind
Freigabeblocker; vollständige Umsetzung dieser Spezifikation schließt F7–F11 ein.

| Schritt | Befunde | Verantwortlicher Vertrag |
| --- | --- | --- |
| 1: Offenlegung und gefährliche Schreibpfade | F1, F2, F3, F6 | Verlauf, Aktivitäts-Backup/Platzierung, External-Vertrag, Downloadprüfung |
| 2: OAuth-Verbindung | F4, F5 | Öffentlicher Metadatenabruf, Verbindung und Tokenfolge |
| 3: Betrieb und Datenschutz | F7, F8, F9 | Retention/Privacy, öffentliche Budgets, Default-Export |
| 4: Ablage vereinheitlichen | F10 | Vorhandener Anker und storage_port |
| 5: Katalogschreiben vertiefen | F11 | Katalogkern und nativer Schreibweg |

Jeder Schritt enthält passende Regressionstests und ist einzeln reviewbar.
F2 und F9 verwenden dieselbe Eigentumsgarantie. F5 stellt stabile Ticketbindung
her; der Freigabecheck F6 kann zuvor unabhängig geschlossen werden.
Bestehende Module werden vertieft; zusätzliche Schichten sind keine Zielgröße.

### Geschützter Änderungsverlauf und wirksame Aufbewahrung (F1, F7)

- Eine positive Freigabe von Komponenten und Dateibereichen beschränkt die
  Erfassung auf Gestaltungsdateien, etwa Intro und katalogisierte Materialien.
  Lernenden-Abgaben und unbekannte Bereiche werden weder erfasst noch ausgegeben.
- Eine sichere Projektion wird vor jeder Serialisierung von KI-Vergleichsdaten
  angewendet. Sie verwendet die vorhandene availability_privacy-Regel für
  Profilbedingungen. Interner Wiederherstellungszustand und öffentliche
  Vergleichsdaten bleiben getrennte Verträge.
- Alte Datensätze werden unmittelbar beim Lesen gefiltert. Eine begrenzte
  Migration/Bereinigung entfernt zusätzlich unzulässige Dateimetadaten; Schutz
  darf nicht auf den nächsten Schreibvorgang warten.
- Zulässige native Wiederherstellung und N+1-Verlauf bleiben erhalten (ADR 0018).
  allowpersonaldata im Kontextbereich erweitert die Verlaufsfreigabe nicht.
- Eine indizierte Hintergrundaufgabe setzt die Aufbewahrungsfrist in Batches
  unabhängig von Aktivitätsänderungen durch: Standard 365 Tage, mindestens
  ein Tag, keine unbegrenzte Einstellung. Verknüpfungen und verwaiste
  Dateimetadaten werden konsistent entfernt. Gemeinsam referenzierte Metadaten
  bleiben bis zum Wegfall ihrer letzten Referenz erhalten.
- Der Privacy-Provider berücksichtigt Verlauf bei Nutzer-/Kontextermittlung,
  Export und allen einschlägigen Löschoperationen. Personenbezogene Löschung
  entfernt vom Antragsteller verfasste Verlaufsstände samt Verknüpfungen im
  genehmigten Kontext. Aktuelle Aktivitäten und Stände anderer Autoren bleiben
  erhalten. Kontextweite Löschung erfasst den dortigen Verlauf.
- Privacy-Export gibt keine fremden Abgabedaten aus. Deklarationen und Aussagen
  zur automatischen Löschung entsprechen dem tatsächlichen Verhalten.

### Eigene Aktivitätsanlagen und korrekter Werkzeugvertrag (F2, F3, F9)

- Aktivitäts-Backup und Kursmodul-Platzierung besitzen ihre Bereinigung.
  Eigentum stammt aus der eigenen Anlegeantwort oder zuverlässig zugeordneten
  Restore-Controller-/Task-Identitäten, einschließlich partieller Anlagen.
  Kursweite Vorher-/Nachher-Differenzen sind kein Eigentumsnachweis.
  Eine Sperre zwischen Pluginaufrufen schützt nicht vor nativen Moodle-Anlagen.
- Fehlt der Nachweis, wird nicht gelöscht und unvollständige Bereinigung gemeldet.
  Auch Papierkorb-Bereinigung betrifft ausschließlich eigene zugeordnete Einträge.
- Fehlanlagen werden nur als eigene, noch nie veröffentlichte Objekte gemäß
  ADR 0028 verworfen. Ablösen versteckt den Vorgänger, löscht ihn nicht.
  Art-Tor, Round-Trip, Verweishinweise und der erste erfolgreiche Verlaufsstand
  bleiben erhalten.
- Parameterdeklaration und execute-Reihenfolge von create_activity_from_xml
  stimmen überein. hidden, dry_run und replaces_cmid behalten Namen und Bedeutung.
  Vorschau mit gültigem Vorgänger schreibt nichts; ohne erforderlichen Vorgänger
  bleibt der vorgesehene Parameterfehler ohne Mutation erhalten.
- Ein Vertragstest prüft alle registrierten External-Funktionen gegen den
  positionsgebundenen Aufruf. Abweichende interne Namensschreibweisen erfordern
  keine neue Übersetzungsschicht.
- export_default_activity wird in der einzigen Werkzeugdeklaration schreibend.
  Lernschleife und Skill-Referenz berücksichtigen dies im bestehenden
  Plan-/Freigabeablauf; kein zusätzlicher serverseitiger Bestätigungsschritt
  entsteht (ADR 0018).
- Die temporäre Standardaktivität ist ab Anlage versteckt und auf der Kursseite
  nicht sichtbar. Nur Export samt erfolgreicher Bereinigung liefert Erfolg.
  Cleanup-Fehler liefern einen expliziten Fehler mit nachvollziehbarem Zustand.
  Tatsächlich erfolgte native Moodle-Ereignisse bleiben erhalten.
- Keine pauschale Restore-Transaktion: Backup-DDL kann implizit committen.
  Eigentumsnachweis und gezielte Kompensation tragen auch nach Teilfehlern.

### Verbindung, Tokenfolge und Downloads (F4, F5, F6)

- CIMD erzwingt Zertifikats- und Hostnamenprüfung, behält Redirect-Sperre und
  Moodle-Host-/Portsperren und begrenzt Antwortgröße während des Empfangs,
  auch ohne Content-Length. Der vorhandene fünfsekündige Timeout bleibt.
- Ein kleines internes Abrufmodul kann diese Garantie kapseln. Öffentliche
  Metadaten werden ohne Speicherzugangsdaten abgerufen; der Basic-Auth-
  WebDAV-Transport wird dafür nicht zweckentfremdet.
- Eine stabile Verbindung/Grant-ID gehört zu Nutzer und Client und besitzt die
  Tokenfolge. Verbrauchte Refresh-Hashes bleiben zur Replay-Erkennung
  zuordenbar. Geheimnisse bleiben gehasht.
- Rotation und Widerruf verwenden eine gemeinsame atomare Entscheidung an der
  Verbindung. Wiederverwendung eines verbrauchten Refresh-Tokens widerruft
  alle Nachfolger und Tickets dieser Verbindung. Auch beim Rennen zweier
  Einlösungen bleibt nach erkannter Wiederverwendung kein Nachfolger gültig.
- Downloadtickets binden an die Verbindung, nicht an ein einzelnes Tokenpaar.
  Normale Rotation erhält ansonsten gültige Tickets; Replay und expliziter
  Widerruf sperren sie. Andere Verbindungen desselben Nutzers bleiben unberührt.
- Jede Ticketeinlösung prüft die aktuelle Fernzugriffsfreigabe der expliziten
  Ticket-userid (ADR 0026), zusätzlich zu Killswitch, aktivem Konto, Verbindungs-
  status, Ablauf und unverändertem Dateiinhalt. Der anonyme Aufrufer ist kein
  Ersatz für diese Identität.
- Schema/Upgrade führen Verbindung, Hash-Zuordnung und benötigte Indizes ein.
  Jedes vorhandene gültige Tokenpaar erhält eine eigene Verbindung; gültige
  Tickets werden zugeordnet. Fristen bleiben erhalten; das Upgrade wird im
  Moodle-Upgradevertrag sicher wiederaufgenommen.
- Verlorene historische Hashes/Familien werden nicht rekonstruiert oder erfunden.
  Replay-Erkennung beginnt mit den nach Migration erhaltenen Zuständen.
  Übergang und verbleibende historische Grenze werden dokumentiert und getestet.
- Gemeinsame Verbindungsprüfung kapselt die Zugriffsregeln; native Kursrechte
  bleiben zusätzlich erforderlich. Freigabe ersetzt keine Bearbeitungsrechte.

### Begrenzter öffentlicher OAuth-Betrieb (F8)

- Registrierung und erstmaliger CIMD-Abruf haben endliche standortweite und
  pro Quelle wirksame Budgets vor Datensatzanlage bzw. Netzabruf. Parallele
  Anfragen können Grenzen nicht umgehen.
- Standardhöhe, Zeitfenster sowie Body-/URI-/Antwortgrenzen werden als benannte
  dokumentierte Betriebseinstellungen mit endlichen Standardwerten geliefert.
  Konkrete Zahlen sind noch nicht festgelegt; ihre Wahl gehört zum ersten
  Implementierungsschritt für F8 und muss vor dessen Abnahme dokumentiert sein.
- Limits greifen vor bzw. während Verarbeitung. Überschreitung erzeugt keinen
  Client und keine weitere Netzarbeit. Wiederholte Fehlabrufe werden begrenzt;
  negative Cacheeinträge erhalten ebenfalls endliche Lebensdauer und Anzahl.
- Bereits gespeicherte gültige Clients bleiben ohne erneuten CIMD-Abruf nutzbar.
  Drosselung liefert passende HTTP-/OAuth-Fehler; Grant-Prüfung und PKCE bleiben.
- Quellenidentität verwendet Moodles vertrauenswürdig ermittelte Request-Quelle,
  keine ungeprüften Forwarded-Header. Budgetdaten haben endliche Lebensdauer;
  Secrets und rohe personenbezogene Inhalte stehen nicht in diesen Logs.
- Begrenzte Hintergrundbereinigung entfernt abgelaufene Codes, nicht mehr
  benötigte Token-/Ticketdaten und überalterte unbenutzte Registrierungen.
  Aktive Verbindungen und Replay-Nachweise ihrer aktiven Tokenfolge bleiben
  erhalten. Fristen werden nicht verlängert. Die Löschhorizonte für unbenutzte
  Clients/Budgetdaten werden zusammen mit den Limits dokumentiert.

### Ein Ablagevertrag und ein Katalogschreibvertrag (F10, F11)

- Vor Entfernen der acht belegten privaten Migrationsmethoden wird ihre
  Unerreichbarkeit am aktuellen Integrationsstand durch Aufrufsuche bestätigt.
  Tote Sicherheitslogik wird gelöscht, nicht auf weitere Dateien verteilt.
- Vorhandener storage_port mit den zwei realen Adaptern Private Files/WebDAV
  wird zum einzigen lebenden Operationsweg für Kontextbereich, Materialbestand
  und zulässige Altortzugriffe. Bereich bleibt ein Wertesatz.
- Pfade, Repository-Instanzeigentum, Quoten, bedingtes Schreiben und persönliche
  Markierung bleiben Modulgarantien. Zugelassener Speicher gilt für die
  bestehenden markierten externen Schreibwege; keine neue Lesesperre entsteht.
  Materialbestand und Altort behalten ihre vorhandenen Operationsgrenzen.
- ADR 0023 bleibt: kein Rückfall auf einen anderen Speicher. Ausstandsnotiz
  enthält Metadaten, keinen Inhaltsersatz. Nur erfolgreiches Nachtragen mit
  passender Kennung oder ausdrückliches Verwerfen entfernt sie.
  Schwächere WebDAV-Prüfwerte werden nicht als starke atomare Garantie dargestellt.
- Katalogkern bietet create und update als getrennte Operationen. Er bildet
  intern den effektiven Zielstand aus Defaults bzw. Ist-Stand und Änderungen;
  die Menge explizit geänderter Felder bleibt erhalten.
- Datumsregeln, Stealth, Feldfreigaben, Editor-/Dateipseudofelder und native
  Schreibobjekte werden gemeinsam entschieden. Eine relevante Datumsänderung
  wird gegen den Zielstand geprüft; unabhängige Patches werden nicht wegen
  unverändert ungültiger historischer Datumswerte abgewiesen.
- Autorisierung, Katalogprüfung und Lernendenriegel gehen Datei-Schreibfolgen
  und nativer Anwendung voraus. Abgelehnte Eingaben mutieren nichts.
  Teilfehler der nativen Anwendung werden im bestehenden Schreibvertrag
  behandelt und dürfen keinen falschen Erfolg erzeugen.
- catalog_fields und add_moduleinfo/update_moduleinfo bleiben Grundlage
  (ADR 0016/0017). Der begründete Quizweg bleibt. Keine direkten Ersatz-
  schreibungen in Instanztabellen, universelle Policy-Engine oder Factory.
  Große deklarative Feldkataloge werden nicht allein wegen Zeilenzahl geteilt.
- Produktivcode und Werkzeugvertrag bleiben englisch; der deutsche Skill-Korpus
  behält die bestehenden Lehrkraftbegriffe (ADR 0024).

## Testing Decisions

Ein guter Test prüft öffentliche Ergebnisse und beobachtbare Nebenwirkungen mit
synthetischen Daten. Quelltextmuster, private Helfer-Aufrufreihenfolge und
Line-Coverage allein beweisen die Sicherheitsgarantien nicht.

- **Primäre bestehende Grenze:** dispatcher::handle und der tatsächliche
  Moodle-external_api-Aufruf. F3 nutzt benannte MCP-Eingaben durch den
  positionsgebundenen External-Aufruf, nicht nur einen direkten execute-Test.
- **Notwendige bestehende Ergänzungen:** öffentliche OAuth-Verbindungsoperationen,
  Downloadticket-Einlösung, Moodle-Privacy-Provider und geplante Aufgaben mit
  echter isolierter Moodle-Testdatenbank. Kontrollierte lokale TLS-/HTTP-
  Fixtures prüfen Transportgrenzen. Keine neue umfassende HTTP-E2E-Schicht.
- **Prior Art:** vorhandene Dispatcher-, External-, privacy_surface-, History-/
  Retention-, OAuth-/Ticket-, Backup-/Klon-, Ablage- und Katalogtests.
  Namens-Allowlist und Katalogdrift bleiben ergänzende Gates.

| Befund | Verbindliche Abnahme |
| --- | --- |
| F1 | Synthetische Aufgaben-Abgabedatei wird nicht neu erfasst. Vergleich via Dispatcher enthält weder ihren Namen noch Profilwerte, auch aus alten Zeilen. Zulässige Gestaltungsdateien bleiben nativ wiederherstellbar. |
| F2 | Zwei echte DB-Prozesse mit Barrieren verschachteln Restore/Export und native Moodle-Anlage. Fehlrestore und erfolgreicher Default-Export erhalten fremde Aktivität/Papierkorb. Eigene zugeordnete Fehlanlage verschwindet; unsichere Zuordnung löscht nichts und meldet Fehler. |
| F3 | Vorschau ohne Vorgänger meldet vorgesehenen Fehler; mit gültigem Vorgänger schreibt sie nichts. Auch gültige cmid 1 prüfen. Echter Ablöseaufruf verändert nur beabsichtigte Objekte. Dry-run ändert weder Verlauf, Dateien noch Sichtbarkeit. Alle registrierten External-Verträge bestehen. |
| F4 | Vertrauenswürdiges Zertifikat funktioniert. Selbstsigniertes Zertifikat, falscher Hostname, gesperrter Host/Port, Redirect und oversized chunked response scheitern ohne Clientpersistierung. Lokale Fixtures kontrollieren CA und Netzregeln. |
| F5 | Angreifer rotiert zuerst, legitimer Client nutzt alten Wert: Nachfolger und Tickets sind gesperrt. Parallele Rotation/Replay und Rotation/Widerruf hinterlassen keinen gültigen Nachfolger. Normale Rotation erhält Tickets; andere Verbindungen bleiben gültig. |
| F6 | Ticket ausstellen, Kohortenmitgliedschaft oder Capability entziehen, Token aktiv lassen: öffentliche Einlösung liefert keine Dateibytes. Positivfall sowie Killswitch, Konto, Frist und Dateiänderung bleiben geprüft. |
| F7 | Gealterter Stand unveränderter Aktivität verschwindet im Tasklauf; Fristgrenze und Batchfortsetzung stimmen. Keine verwaisten Metadaten; geteilte bleiben. Provider findet/exportiert Autoren und setzt Nutzer-, Mehrnutzer- und Kontextlöschung im genehmigten Umfang um. |
| F8 | Kleine konfigurierte Budgets prüfen letzte erlaubte/erste abgelehnte Anfrage und Parallelität vor DB-/Netzarbeit. Übergröße erzeugt keinen Client. Autorisierte Clients funktionieren. Cleanup erhält aktive Grants/Replay-Evidenz und begrenzt auch eigene Budgetdaten. |
| F9 | Registry/Skill-Korpus behandeln Export als schreibend. Barriere während Export zeigt nur versteckte Anlage. Cleanup-Fehler liefert keinen Erfolg; Erfolg hinterlässt weder eigene cm, Verlauf noch Papierkorbeintrag. F2 bleibt erfüllt. |
| F10 | Gleiche öffentliche Anfragen gegen beide Adapter liefern gleiche Antwortform. Konflikt, Eigentum, Pfad, Quote, persönliche Markierung, Altort, Materialbestand und Ausstand bleiben erhalten. Keine zweite lebende Ortsinterpretation. |
| F11 | create/update lehnen denselben relevanten Regelverstoß konsistent ab. Zielstand, Defaults, Datumsänderung, unabhängiger Patch mit ungültigen Altdaten, Stealth, unbekannte/gesperrte Felder, Lernendenriegel und Dateipseudofelder prüfen. Abgelehnte Eingabe hinterlässt keine Mutationen. |

- Schemaänderungen brauchen Fresh-Install und Upgrade vom geprüften Stand mit
  bestehenden Tokenpaaren/Tickets, historischen Daten und Indizes. Begrenzte
  Migration/Cleanup kann sicher fortgesetzt werden. Nicht rekonstruierbare
  Familien erzeugen keine falschen Replay-Zuordnungen. Konkurrenztests
  koordinieren echte Prozesse durch Barrieren statt zufälliger Wartezeiten.
- Native PHPUnit-/CI-Suite und bestehende Gates müssen nach Umsetzung bestehen,
  zusätzlich PHP-Syntaxprüfung, native JS-CI und betroffene Registry-/Korpustests.
  Skips/fehlende Umgebungen bleiben ausgewiesen. Fehlerhafte Legacy-Suite wird
  nicht als grüner Gesamtstatus ausgegeben.
- dev folgt ADR 0027: mindestens frische isolierte Moodle-5.1-Installation
  prüfen. Bestehende CI-Matrix/Gates werden nicht stillschweigend abgesenkt;
  weitere Supportzusagen brauchen eigene Kompatibilitätsnachweise.
- Kein Test synchronisiert den Branch in den live verwendeten Plugin-Bind-Mount.
  Container, DB, Dateibestand und Fixtures bleiben von Spike/dev getrennt.
  Offline-Review-Proben belegen den alten Fehler und werden nicht als nach
  Reparatur grün zu haltende Regressionstests übernommen.

## Out of Scope

- Deployment/Upgrade/Lasttests gegen laufende Instanzen, Releasefreigabe,
  Merge nach dev/main, Tags und produktive Datenbereinigung.
- Umbau des eingefrorenen Node-/Legacy-Wegs oder Erzeugung seines ZIPs.
- Neue Aktivitätsarten, XML-Bearbeitung, Glossar-Inhaltsimport, Datei-Nachtrag
  für erschlossene Arten und zusätzliche Ablageadapter.
- Neuer serverseitiger Genehmigungsmechanismus, automatische Entsorgung
  versteckter Vorgänger und Auflösung bestehender Aktivitätsverweise.
- Spekulative Policy-Engine/Factory, Dateiaufteilung allein wegen Zeilenzahl
  und allgemeine Dokumentationsbereinigung.
- Eine Default-Export-API ohne Kursanlage; diese Alternative braucht später
  einen separat belegten Architekturentscheid.
- Rechtliche Zertifizierung und Vollbeweis für alle Moodle-Erweiterungen.

## Further Notes

- [Review: Befunde F1–F11 und Nachweisgrenzen](../reviews/dev-security-deep-modules-2026-10-02.md).
- [Synthetische Offline-Reproduktionen](../reviews/repros/dev-security-2026-10-02.php).
- [Domain-Glossar](../../CONTEXT.md),
  [Ablagevertrag, Spec 0021](0021-ablage-anker-mit-zwei-adaptern.md) und
  [erschlossene Aktivitätsarten, Spec 0026](0026-erschlossene-aktivitaetsarten.md)
  liefern vorhandene Begriffe und Garantien.
- Der Review meldet 1.025 bestandene native JS-Tests, 43 Skips und PHP-
  Syntaxprüfung von 306 Dateien. Native PHPUnit, Fresh-Install und reale
  Konkurrenztests wurden dabei nicht neu ausgeführt. Deren Nachweis ist
  Aufgabe der Umsetzung, kein bereits vorliegender Freigabenachweis.
- Bestehende main-Sicherheitsbefunde können separat nach ADR 0027 zurückportiert
  werden; dieses Issue enthält weder direkten Eingriff auf main noch Live-Upgrade.
- Testgrenzen wurden zur Erwartungsprüfung vorgeschlagen: vorhandener
  Dispatcher/External-Aufruf als Hauptgrenze; öffentliche OAuth-/Download-/
  Privacy-/Task-Grenzen mit echter isolierter DB als notwendige Ergänzung.
