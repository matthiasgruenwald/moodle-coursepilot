# #603 — Verifizierte Aktivitätsart-Vorlagen bei der Ortswahl

## Verhalten

Die öffentliche Ortswahl ergänzt fehlende `activity-types/book.md`, `checklist.md`
und `glossary.md` im gewählten Kontextbereich. Die Vorlagen liegen als Pluginressourcen
neben dem Skill-Korpus. Ein Hinweis nennt ausschließlich tatsächlich angelegte Dateien;
er bleibt auch vor dem Rücksprung in den OAuth-Zustimmungsdialog erhalten.

Vorhandene Lehrerdateien bleiben bytegleich, auch bei Personenbezugsmarkierung. Eine
wiederholte Wahl schreibt weder Kontextdateien noch Pointer und meldet keine neuen
Vorlagen. Vorab-Lesefehler, Rechte, Quote, Schreibausfälle und konkurrierendes Anlegen
werden je Datei abgefangen; andere fehlende Vorlagen können weiterhin ergänzt werden.
Die Ortswahl bleibt erfolgreich. Ausstandsnotizen des bestehenden Schreibpfads bleiben
erhalten; es gibt keinen Rückfall auf Moodle bei externem Ausfall.

Der bedingte Private-Files-Anlegepfad bleibt bis zum eindeutigen Moodle-Dateisatz
create-only. Zuvor konnte der erneute Lesezugriff vor `replace` eine zwischenzeitlich
angelegte Lehrerdatei überschreiben. Ein eigener Kindprozess reproduziert genau diesen
Fall über die Filesystem-Systemgrenze und die öffentliche Ortswahl. WebDAV verwendet
weiterhin `If-None-Match: *`; vorhandene ETag-/Änderungskonflikte werden nicht umgangen.

## Herkunft und reproduzierbare Verifikation

Die drei Originalvorlagen aus #592 wurden ausschließlich lesend über den externen
Kontextadapter von `teacher_edit` gesichert. Ihre Kopfzeilen nennen Moodle 5.1.7+
(Build 20260928), Coursepilot 2.0.0-beta (2026100107), Spike-Verifikation am 01.10.2026.
Sie lagen extern, nicht in Moodles Private Files. Es erfolgte keine Live-Schreiboperation.

Die ausgelieferten Texte und Beispieltitel sind englisch. Die genaue ausgelieferte XML
wird in `activity_type_templates_test` aus der nach Ortswahl gelesenen Ressource entnommen,
über `create_activity_from_xml` angelegt und über `export_activity_backup` rückgelesen.
Geprüft werden sichtbare Anlage, Titel sowie ein Buchkapitel, drei Checklistenpunkte und
ein leeres Glossar. Anschließend wird der neue Glossar-Nachtrag öffentlich aufgerufen.

Die isolierte Verifikation nutzt Moodle 5.1.7+ (Build 20260928), PHP 8.4.25,
MariaDB 11.4.12 und real mod_checklist 4.1.0.8 (2026042400). In Core-only-CI wird nur
die zusätzliche Checklist-Anlageprüfung übersprungen, falls das optionale Modul fehlt;
alle Ortswahl-/Dateischutztests laufen trotzdem. Die reale Checklist-Verifikation liegt
im eigenen Lauf vor. Lightboxgallery wird mangels verifizierter Aktivitätsart-Datei
nicht mitgeliefert; dessen separater Datei-Nachtrag begründet keine Vorlagenzusage.

## Technische Belege

Eigene Ressourcen: `/tmp/coursepilot-603`, `coursepilot-603-php`, `coursepilot-603-db`,
Netz `coursepilot-603`, Datenbank `moodle603`, PHPUnit-Präfix `t603_`, eigene Datenpfade.
Keine Live-Mounts, veröffentlichten Ports, synchronisierte Spikequelle oder Live-Upgrades.
Schwere Initialisierungen und der vollständige Coverage-Lauf verwenden den gemeinsamen
Host-Lock `/tmp/coursepilot-heavy-tests.lock`; vorher wurden Prozesse, Root-Platz und
Speicher geprüft. Zu niedrige eigene DB-Limits führten anfangs zu Infrastrukturabbrüchen;
diese gelten ausdrücklich nicht als bestandene Tests. Kleinere Tabellen-Caches und
feste Limits von 512 MiB PHP / 384 MiB DB begrenzen den anschließenden Lauf.

- `red-location.log` → `green-location2.log`: fehlende Lieferung zuerst rot, danach grün.
- `red-private-race-content.log`: alter Pfad ersetzt eine konkurrierende Lehrerdatei.
- `focused-final2.log`: 12 Tests, 75 Assertions grün, einschließlich geschützter Lehrerdatei,
  Wiederholung ohne Writes, echten Ortswechseln bei Lese-/Schreibausfall und beider Rennen.
- `npm-commit.log`: 35/35 Node-Verträge, einschließlich bytegleicher aus dem ZIP
  extrahierter Vorlagen und gestagter Quellen.
- `post-full-final.log`: gezielte Nachprüfung der endgültigen Vorlagen-Kopfzeilen und
  des optionalen Checklist-CI-Skips; XML und Produktionscode seit Vollsuite unverändert.
- `syntax.log`: Syntax aller 338 Plugin-PHP-Dateien; die endgültige Testklasse wird
  außerdem im gezielten Nachlauf vollständig geladen.
- `full.log`, `full.exit`, `data/junit.xml`, `data/coverage.xml`: vollständiger nativer Lauf.
  1.538 Tests, 130.842 Assertions, Exit 0, sieben dokumentierte Skips; Laufzeit 6:58.
  Die Konfiguration behält alle generierten nativen Produktionspfade einschließlich
  ungedeckter Zeilen. Das unveränderte 80%-Gate besteht mit 82,43% (11.946/14.492 Zeilen);
  der Quelldatei-Mengenvergleich umfasst exakt alle 187 nativen Produktionsdateien.
- Zwei ausschließlich lesende Abschlussreviews (Standards und Issue-/Spec-Abgleich):
  keine blockierenden Befunde.

## Offene Live-/Human-Kriterien

Der Koordinator übernimmt nach abgestimmtem Deployment die tatsächliche UI-Abnahme:
leerer Moodle- und externer Kontext, Hinweis mit drei Namen, unveränderte Lehrerdatei,
zweite Auswahl ohne Vorlagenhinweis, Ablageausfall ohne Abbruch sowie OAuth-Rücksprung.
Es gab keinen Worker-Deploy und kein Live-Upgrade.

Die Moodle-5.0-/GitHub-CI-Matrix und kombinierte Regression mit realem Lightboxgallery
bleiben koordiniert offen. Sechs bestehende Lightboxgallery-Integrationstests werden
in dieser Instanz mangels Zusatzplugin übersprungen; der eigene #598/#599-Nachweis mit
realem Addon ersetzt keine kombinierte Abnahme. Der bekannte WebDAV-Quota-Skip bleibt
unverändert. Die Sicherheits-, Privacy- und Coverage-Gates wurden nicht gelockert.

Automatische External-/PHPUnit-Aufrufe belegen keine tatsächliche Claude-, Codex- oder
ChatGPT-Bedienung. Die gebündelte menschliche Praxisabnahme bleibt offen. #603 wird
vom Worker nicht geschlossen; es gibt keine PR, Veröffentlichung, Tags oder main-Merges.
