# Spec 0025 — Server-MCP: technische Release-Reife

Tracking-Issue: [#567](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/567).

Quelle: Release-Review vom 25.09.2026, Stand `06ded34`, und anschließende
Bestätigung durch den Maintainer. Die Prüfflächen wurden ausdrücklich bestätigt.

> Umgesetzt wird gegen das zugehörige GitHub-Issue. Dieses Dokument hält die
> Begründung und den gemeinsamen Umfang fest. CI und Coverage werden ausschließlich
> im bestehenden Issue [#268](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/268)
> konkretisiert; es entsteht kein zweites CI-Ticket.

## Problem Statement

Eine Schule kann den aktuellen Server-MCP noch nicht zuverlässig als veröffentlichtes
Coursepilot-Plugin übernehmen. Die Werkzeugliste und die Eingabeprüfung widersprechen
sich, OAuth garantiert bei überlappenden Anfragen keine Einmaligkeit, und die
Erstanleitung übersieht die systemweite Fernzugriffsberechtigung einer normalen
Kurslehrkraft. Der Release-Prozess liefert außerdem noch das eingefrorene Altplugin.

Viele vorhandene Tests sichern interne Implementierungsformen statt des öffentlichen
Verhaltens. Deshalb können wichtige Grenzfehler trotz einer fast grünen PHPUnit-Suite
bestehen bleiben. Es fehlt ein automatisches, nachvollziehbares Release-Gate.

Diese Arbeit behandelt die technische Release-Reife unabhängig vom parallel laufenden
Praxistest. Sie ersetzt weder dessen Ergebnis noch die menschliche Freigabe.

## Solution

Coursepilot erhält einen widerspruchsfreien englischen Werkzeugvertrag, atomare
OAuth-Einlösung, eine nachweislich funktionierende Ersteinrichtung mit eng begrenzten
Berechtigungen und ein installierbares Server-MCP-Release-Artefakt. Vor der Freigabe
prüft CI genau den auszuliefernden Stand einschließlich des bestehenden
80%-Line-Coverage-Gates aus #268.

Der verbleibende Katalogumbau wird abgeschlossen: Typwissen gehört zum jeweiligen
Modulkatalog, und die Driftprüfung erfasst die tatsächlich verwendete Leseprojektion.

## User Stories

1. Als Lehrkraft möchte ich ein Werkzeug mit den veröffentlichten Feldnamen aufrufen können, damit Moodle dieselbe Eingabe akzeptiert, die mein KI-Client vorbereitet.
2. Als KI-Client möchte ich erfüllbare Pflichtfeldangaben erhalten, damit ein korrekter Aufruf nicht schon an widersprüchlicher Schemavalidierung scheitert.
3. Als Lehrkraft möchte ich einen Eintrag der Ausstandsnotiz ausdrücklich verwerfen können, damit erledigte oder aufgegebene Nacharbeit nicht dauerhaft wiederkehrt.
4. Als Lehrkraft möchte ich Aktivitäten anlegen, ändern, vergleichen und zurückholen können, ohne deutsche interne Parameternamen kennen zu müssen.
5. Als englischsprachige Lehrkraft möchte ich englische Werkzeugfelder und Beschreibungen erhalten, damit der technische Vertrag international verständlich ist.
6. Als Entwicklerin möchte ich genau eine Parameter- und Rückgabedeklaration pflegen, damit Übersetzungstabellen keinen zweiten Vertrag bilden.
7. Als Entwicklerin möchte ich, dass der Skill-Korpus die tatsächlichen Feldnamen verwendet, damit Beschreibung und Werkzeugaufruf zusammenpassen.
8. Als Lehrkraft möchte ich, dass mein Autorisierungscode nur einmal eingelöst werden kann, damit überlappende Anfragen keine zusätzlichen Verbindungen erzeugen.
9. Als Lehrkraft möchte ich, dass mein Refresh-Token auch bei gleichzeitigem Zugriff nur einmal rotiert, damit die zugesagte Entwertung tatsächlich gilt.
10. Als Administration möchte ich, dass ein Fehler während der Tokenausstellung einen definierten Zustand hinterlässt, damit keine nur halb angewendete Rotation bestehen bleibt.
11. Als Lehrkraft mit einer Rolle nur in meinen Kursen möchte ich nach der dokumentierten Einrichtung eine OAuth-Verbindung herstellen können, damit ein Schulwechsel keine versteckten Entwicklerkonfigurationen voraussetzt.
12. Als Administration möchte ich den Fernzugriff gezielt freischalten können, damit ich dafür keine systemweiten Kursbearbeitungsrechte vergeben muss.
13. Als Administration möchte ich, dass entzogener Fernzugriff und fehlende Kursrechte getrennt wirksam bleiben, damit eine Verbindung keine Rechte erweitert.
14. Als Administration möchte ich ein ZIP des aktuellen Server-MCP installieren, damit der Download tatsächlich das beschriebene Produkt enthält.
15. Als Lehrkraft möchte ich ohne lokale Node-Installation arbeiten können, damit die Veröffentlichung dem Servermodell entspricht.
16. Als Administration möchte ich konsistente Versionsangaben sehen, damit ich einen Fehler eindeutig einem Release zuordnen kann.
17. Als Administration möchte ich den Übergang vom Altplugin verstehen, damit ich keine nicht vorhandene Datenmigration voraussetze.
18. Als Nutzerin möchte ich das passende Lizenzmaterial und den Quellstand erhalten, damit die Veröffentlichung nachvollziehbar bleibt.
19. Als Administration möchte ich belastbare Angaben zu unterstützten Moodle-Versionen erhalten, damit ich nur zugesagte Kombinationen einsetze.
20. Als Maintainer möchte ich den vollständigen nativen Testlauf automatisch auf einem sauberen System ausführen, damit die Spike-Instanz keine Voraussetzung der Freigabe ist.
21. Als Maintainer möchte ich das bereits vereinbarte 80%-Line-Coverage-Gate behalten, damit der Wechsel zum Servermodell die Qualitätsuntergrenze nicht abschwächt.
22. Als Reviewer möchte ich fehlende Coverage oder ausgefallene Pflichtprüfungen als Fehler sehen, damit ein leerer Bericht keine Freigabe vortäuscht.
23. Als Entwicklerin möchte ich Vertragstests mit unabhängigen Erwartungen, damit ein Fehler im Konverter nicht zugleich die Test-Erwartung erzeugt.
24. Als Entwicklerin möchte ich Verhalten statt Quelltextpositionen testen, damit ein korrekter Architekturumbau keine falschen Testfehler erzeugt.
25. Als Lehrkraft möchte ich die vorgesehenen Aktivitätsfelder auch nach einem einzelnen Patch unverändert zurücklesen können, damit keine anderen Einstellungen verloren gehen.
26. Als Entwicklerin möchte ich die Leseprojektion beim jeweiligen Modulkatalog pflegen, damit ein neuer Modultyp keinen zentralen Typ-Switch benötigt.
27. Als Administration möchte ich Drift in tatsächlich gelesenen Feldern erkennen, damit eine unvollständige Deklaration keine Scheinsicherheit erzeugt.
28. Als Maintainer möchte ich technische Freigabe, Praxistest und Marketplace-Einreichung getrennt nachvollziehen können, damit ein grüner Build nicht automatisch als Veröffentlichung gilt.

## Implementation Decisions

### A. Ein kanonischer Werkzeugvertrag

- Die Moodle-External-Deklarationen tragen die englischen Eingabe- und Rückgabeschlüssel unmittelbar. Die rekursive deutsche/englische Schlüsselübersetzung am MCP-Rand entfällt.
- Der MCP-Schema-Konverter leitet Eigenschaften, Pflichtfelder, Typen und Standardwerte aus diesen Deklarationen ab. Eine zusätzliche handgepflegte Aliasliste wird nicht eingeführt.
- Werkzeugnamen und fachliches Verhalten bleiben erhalten. Der Skill-Korpus bleibt gemäß ADR 0024 deutsche Prosa; technische Feldreferenzen ziehen mit. Werkzeug- und Parameterbeschreibungen folgen der englischen Basis und der Sprachdatei-Regel.
- Die Umstellung betrifft die native Linie. Der eingefrorene Altstand wird nicht zur Voraussetzung des neuen Vertrags umgebaut.
- Die im Review belegten Lücken aus #531 werden hier nachgezogen; dessen geschlossener Status gilt nicht als Abnahmenachweis.

### B. Atomarer OAuth-Verbrauch

- Prüfung, einmaliger Anspruch und Tokenausstellung erhalten eine definierte atomare Grenze. Nur ein konkurrierender Aufruf darf erfolgreich ein neues Tokenpaar erhalten.
- Codebindung an Client, Redirect-URI, PKCE und Laufzeit sowie Refresh-Bindung an Client, Laufzeit und Widerruf bleiben erhalten. Fehlgeschlagene Bindungsprüfungen verbrauchen kein gültiges Geheimnis.
- Der vorhandene atomare Anspruch der Werkbank-Downloadtickets ist Vorbild; keine bloße Wiederholung des bisherigen Lesen-Prüfen-Schreiben-Musters und keine nur prozesslokale Sperre.
- Datenbankfehler bei der Tokenausstellung dürfen weder zusätzliche gültige Tokenpaare noch eine teilweise festgeschriebene Rotation hinterlassen. Ein etwaiger Schemawechsel benötigt einen Upgrade-Pfad.
- Geheimnisse bleiben in Antworten und Persistenz nach dem bestehenden Hash-Vertrag behandelt; keine Klartext-Token in Testberichten oder Logs.

### C. Ersteinrichtung und Rechte

- Systemweiter Fernzugriff und kursbezogene Werkzeugberechtigungen bleiben getrennte Grenzen.
- Die Administration richtet die systemweite Freigabe über eine dedizierte, eng berechtigte Rolle ein. Eine globale Zuweisung der Rolle `editingteacher` ist kein Einrichtungsweg.
- Die Anleitung erklärt ausdrücklich, dass ein Archetyp-Default keine systemweite Rollenzuweisung einer Kurslehrkraft ersetzt.
- Eine frisch eingeschriebene Kurslehrkraft wird ohne systemweite Lehrkraftrolle geprüft: vor Fernzugriffsfreischaltung abgewiesen, danach verbindungsfähig, weiterhin nur innerhalb ihrer Kursrechte handlungsfähig.

### D. Release-Artefakt und Übergang

- Der 2.0-Release-Prozess erzeugt das native Server-MCP-Plugin; ZIP, veröffentlichter Quellstand und Metadaten beziehen sich auf denselben Stand.
- AGPL-3.0-or-later gilt gemäß ADR 0025 auch für das Plugin. Historische GPL-only-Prüfungen werden ersetzt; Herkunftshinweise bleiben erhalten.
- MCP-Serverversion und Plugin-Release stammen aus einer kanonischen Versionsangabe. Eine feste Prototypversion wird nicht weiter veröffentlicht.
- Im Release-Paket liegen gemäß ADR 0024 nur englische Moodle-Sprachstrings; die deutsche Übersetzung folgt dem AMOS-Weg. Der vorerst deutsche Skill-Korpus wird im Listing ausdrücklich benannt.
- Der Übergang von Coursepilot 1.x folgt ADR 0024: vorherige Deinstallation des gleichnamigen Altplugins, keine behauptete Datenmigration, anschließend native Installation und OAuth-Einrichtung. Test- und Release-Automation führen diesen Schnitt nicht auf der laufenden Arbeitsinstanz aus.
- Der weiterhin benutzte Altstand bleibt bis zum bewussten Schnitt verfügbar. Seine Entfernung wird nicht vorgezogen, nur um neue Release-Prüfungen grün zu bekommen.
- Die veröffentlichte Supportzusage entspricht der ausdrücklich geprüften Moodle-/PHP-/Datenbankmatrix. Ausgangsnachweis ist Moodle 5.0; die geplante Anhebung der Mindestversion auf 5.1 erfordert zuvor deren Prüfung. Ein Metadatenwechsel allein ist keine Kompatibilitätsabnahme.
- Maturity und tatsächliche Veröffentlichung bleiben eine menschliche Entscheidung; dieser Auftrag erklärt das Alpha nicht automatisch für stabil.

### E. CI und Coverage im bestehenden Issue #268

- #268 bleibt der einzige Umsetzungsauftrag für Test-CI und das 80%-Line-Coverage-Gate des nativen Plugins. Die überholte Voraussetzung „0 Tests“ wird durch den tatsächlichen Stand ersetzt.
- PHPUnit, öffentliche Vertragstests, native JavaScript-Prüfungen und Release-Artefaktprüfung laufen automatisiert ohne Spike-Zugang, persönliche Zugangsdaten oder Produktivdaten.
- Fehlende Coverage, ausgefallene Pflichtprüfungen und Coverage unter 80 Prozent lassen den erforderlichen Gate-Check fehlschlagen. Messumfang und Berichte sind überprüfbar; das Gate wird nicht durch Ausblenden schlecht getesteter Produktionsdateien erfüllt.
- Legacy-/Plattformtests erhalten einen zutreffenden Ausführungsumfang. Native Prüfungen bleiben verpflichtend; ein unveränderter roter Altbestand wird nicht als allgemeine Freigabe-Baseline akzeptiert.
- Die konkrete CI-Matrix, Berichte, Gate-Prüffälle und der Umgang mit bisherigen Voraussetzungen werden in #268 festgehalten, nicht hier als zweite CI-Checkliste gepflegt.

### F. Katalogumbau abschließen

- Das modultypspezifische Lesen gehört in den jeweiligen Katalog beziehungsweise dessen tatsächlich verwendete Leseprojektion. Gemeinsame Normalisierung darf geteilt bleiben; die Rückdelegation an einen zentralen Modultyp-Switch entfällt.
- Die Driftprüfung verwendet dieselbe Leseprojektion wie der echte Leseweg. Eine separate, ungenutzte Liste erwarteter Lesefelder genügt nicht.
- Moodles Formularweg, Datenschutzgrenze, Feldbegriffe und die begründete Quiz-Sonderbehandlung aus ADR 0016/0017 bleiben erhalten.
- Die Restlücke aus #533 wird hier abgeschlossen. Es werden keine neuen Modultypen eingeführt.

## Testing Decisions

Ein guter Test beobachtet den Vertrag, den eine Lehrkraft, ein KI-Client oder eine
Administration tatsächlich nutzt. Er prüft weder die Position einer SQL-Abfrage noch
einen historischen Issue-Verweis in einer Beschreibung. Erwartungen werden nicht durch
dieselbe fehleranfällige Konvertierung erzeugt wie das Prüfergebnis.

Die folgenden vorhandenen Prüfflächen wurden vom Maintainer bestätigt; zusätzliche
Test-Abstraktionen werden nicht eingeführt:

- **MCP-Dispatcher:** Die ausgelieferte Werkzeugliste aller registrierten Werkzeuge gegen unabhängige Schema-Invarianten und Moodles akzeptierte Parameter prüfen. Alle Pflichtnamen müssen im geschlossenen Objektschema existieren. Aufrufe mit englischen Feldern einschließlich des Verwerfens eines Ausstands durch den echten Dispatch-Pfad prüfen, nicht nur direkte Methodenaufrufe. Vorbild sind Dispatcher- und Werkzeugvertragstests.
- **OAuth-Tokenpfad:** Bestehende Roundtrips und Negativfälle weiterverwenden; konkurrierende Einlösungen mit kontrollierter Überlappung und separaten Datenbankzugriffen ergänzen. Zwei Aufrufe mit demselben Code beziehungsweise Refresh-Token ergeben höchstens ein erfolgreiches neues Tokenpaar. Fehler bei der Ausstellung hinterlassen den vereinbarten konsistenten Zustand. Ein rein sequenzieller Zweitaufruf beweist diese Eigenschaft nicht.
- **Moodle-Berechtigungen und Erstanmeldung:** Den dokumentierten Freischaltungsweg für eine ausschließlich im Kurs eingeschriebene Lehrkraft nachbilden. Ablehnung vor Freischaltung, erfolgreicher OAuth-Einstieg danach, weiterhin verweigerter Zugriff auf fremde Kurse sowie Wirkung eines Rechteentzugs prüfen. Vorbild sind Capability- und Dispatcher-Tests, ohne deren globale Lehrkraftrolle als Fixture-Abkürzung.
- **Modulkatalog:** Bestehende Rundläufe über alle neun Typen fortführen: anlegen, lesen, genau ein Feld ändern, erneut lesen. Relevante übrige Werte bleiben erhalten. Auch die Moodle-Katalogansicht und die tatsächlich genutzte Leseprojektion werden geprüft; ein absichtlich nicht katalogisiertes Lesefeld muss die Driftprüfung auslösen.
- **Gebautes ZIP:** Auf einer isolierten frischen Moodle-Installation genau das erzeugte Archiv installieren und Registrierung, Discovery sowie eine autorisierte MCP-Anfrage prüfen. Komponentenidentität allein reicht nicht, weil Alt- und Neulinie dieselbe Komponente tragen. Skill-Korpus, Laufzeitressourcen, Lizenz und Versionskonsistenz gehören zur Artefaktprüfung.
- **CI-Gate:** Die vorstehenden Prüfungen werden über #268 zum reproduzierbaren Gate. Coverage ersetzt keine Verhaltensprüfung; eine grüne Quote kann keine kaputten Werkzeugschemas oder nicht geprüfte Nebenläufigkeit rechtfertigen.

### Abnahmekriterien

- [ ] Für jedes registrierte Werkzeug stimmen veröffentlichte Eigenschaften, Pflichtfelder und akzeptierte englische Eingaben überein; alle elf im Review widersprüchlichen Schemas sind behoben.
- [ ] Das Verwerfen eines Ausstands funktioniert über den öffentlichen MCP-Aufruf mit `identifier`; eine fehlende Kennung scheitert kontrolliert.
- [ ] Die doppelte Schlüsselübersetzung ist entfernt; native Deklarationen, Rückgaben und technische Skill-Referenzen verwenden denselben Vertrag.
- [ ] Gleichzeitige Code-Einlösung und Refresh-Rotation stellen jeweils höchstens ein neues Tokenpaar aus; Bindungsfehler und Ausstellungsfehler sind geprüft.
- [ ] Die dokumentierte Ersteinrichtung funktioniert für eine Kurslehrkraft ohne globale Lehrkraftrolle und erweitert keine Kursrechte.
- [ ] Das erzeugte und frisch installierte Release-ZIP ist nachweislich der native Server-MCP mit passender Lizenz, Ressourcen und konsistenten Versionen.
- [ ] Supportmatrix, Sprachpaketstrategie und Wechsel von 1.x sind in den Release-Informationen korrekt beschrieben.
- [ ] Die modultypspezifischen Leser leben beim Katalog; die Driftprüfung erfasst die tatsächlich verwendete Leseprojektion und die Rundläufe bleiben verhaltensgleich.
- [ ] #268 ist nach seinen aktualisierten Kriterien erfüllt: verpflichtende automatisierte Prüfungen einschließlich mindestens 80 Prozent Line Coverage des nativen Produktionscodes.
- [ ] Die im Review roten nativen Prüfungen sind sachlich korrigiert; Umgebungs- und Legacy-Voraussetzungen sind explizit statt still übersprungen.
- [ ] Das Einreichungs-Issue #192 beschreibt das Servermodell und verweist auf die tatsächlichen verbleibenden Freigabebedingungen statt auf den erledigten Vorgänger #191.
- [ ] Die Release-Informationen behaupten keinen bereits vorhandenen Fragenkategorie-Cleanup-Port; #443 bleibt ein ausdrücklich benannter offener Funktionsumfang.
- [ ] Der technische Abnahmenachweis nennt Commit, Artefakt und Prüfergebnisse. Praxistest und menschliche Veröffentlichung bleiben eigene Bedingungen.

## Out of Scope

- Durchführung oder Bewertung des parallel laufenden Unterrichts-Praxistests.
- Automatische Marketplace-Einreichung, Tagging, Veröffentlichung oder Stable-Hochstufung.
- Neue Werkzeuge, Modultypen, ein neuer OAuth-Provider oder ein neuer Client.
- Implementierung des Fragenkategorie-Cleanup-Ports aus #443; die technische Release-Reife hängt nicht von diesem zusätzlichen Lesewerkzeug ab.
- Datenmigration zwischen Altplugin und Server-MCP oder vorgezogene Abschaltung der laufenden Altinstanz.
- Zweisprachiger Skill-Korpus, generische Abstraktionsframeworks und rein kosmetische Dateiaufteilungen.

## Further Notes

Review-Baseline vom 25.09.2026, keine dauerhafte Sollvorgabe für Testanzahlen:

- Native PHPUnit-Suite auf Moodle 5.0.8, PHP 8.4 und MariaDB: 1.204 Tests, 5.605 Assertions, ein Fehler, eine Notice, ein Skip. Der Fehler verlangt den veralteten Beschreibungstext „Issue #481“.
- Node-Suite: 1.026 bestanden, 19 fehlgeschlagen, 43 übersprungen. Darunter Legacy-/Umgebungsannahmen sowie ein nativer Quelltexttest, der nach der Katalogextraktion SQL-Namen weiterhin am alten Ort erwartet.
- Laufzeitprüfung der Schemadeklarationen: elf widersprüchliche Pflichtfeldlisten und eine fehlende Rückübersetzung von `identifier`.
- Isolierte Simulation überlappender OAuth-Aufrufe: jeweils zwei Tokenpaare statt eines. Der abschließende Regressionstest muss auch die echte Datenbank-Nebenläufigkeit abdecken.

Reihenfolge: Werkzeugvertrag → OAuth → Rechte/Ersteinrichtung → Release-Artefakt →
integriertes CI-/Freigabegate. Katalogabschluss vor der abschließenden Abnahme;
CI-Grundgerüst aus #268 kann bereits während der Korrekturen entstehen.

Bestehende Bezüge: #268 (CI/Coverage), #531 (Werkzeugvertrag), #533 (Modulkatalog),
#192 (menschliche Einreichung), #443 (offener Cleanup-Port), ADR 0016/0017
(Formularweg/Katalogpflege), ADR 0024 (englische Basis und Ablösung), ADR 0025 (AGPL).
