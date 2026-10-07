# Coursepilot 2.1.0-beta: Main-Merge-Abnahme

Stand: 07.10.2026. Nutzerauftrag: die verbleibende Releasearbeit autonom
abschließen, einschließlich Main-Merge und Pages. Beta bleibt Beta.

## Umfang und vorherige Abnahmen

Ausgangspunkt ist `main` bei `467c954508291a700215b6cb4c2940ac85d29db4` /
veröffentlichtes `v2.0.0-beta`, Plugin `2026100102`. Der endgültige Kandidat
baut auf `dev` bei `f45a56d70653b9929733305cc3e06638e7cd6268` auf und trägt
Pluginversion `2026100700`, Release `2.1.0-beta`, Moodle-Mindestversion 5.1.

[#585](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/585) und
[#626](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/626) wurden
am 06.10.2026 akzeptiert. Ihre historischen offenen Absätze sind damit überholt.
Der Release enthält XML-Anlegen/Ablösen mit Vorschau, Glossareinträge,
Lightboxgallery-Materialnachträge, verifizierte Vorlagen, Sicherheitskorrekturen,
den englischen nativen Vertrag und die zweisprachige Dokumentation.
Die bereits integrierten Qualitätsbaseline-Dokumente sind enthalten; deren
geplante Gate-Implementierung bleibt ein eigenes Vorhaben.

Moodle 5.1, PHP 8.4 und MariaDB bilden die geprüfte Linie. Moodle 5.0 muss vor
Plugin 2.1 aktualisiert werden. Moodle 5.2 bleibt bis
[#679](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/679)
ausdrücklich außerhalb des Supports. Marketplace #192 bleibt separat.

## Letzte Reviewkorrekturen

- XML-Anlegen meldet fehlgeschlagene Bereinigung ausdrücklich. Vorgängersichtbarkeit
  und Entfernung der neuen Aktivität werden unabhängig versucht; ein Fehler im
  ersten Schritt unterdrückt den zweiten nicht. Echte MariaDB-Trigger erzeugen
  die beiden Fehlerfälle, ohne interne Methoden zu mocken.
- Die veröffentlichten URLs `ortswahl.php`, `ortswahl_browse.php` und
  `werkbank/download.php` bleiben erreichbar. Alte Formular-POSTs verändern
  keine ausgewählten Ablageorte; die aktuelle Ortswahl zeigt den Wechselhinweis.
  Browse und Download übernehmen die bestehenden Schutzprüfungen.
- Anonyme Downloadtickets verwenden den individuell ausgewählten Materialort
  ihres validierten Eigentümers. Die ursprüngliche Requestidentität wird im
  `finally` exakt wiederhergestellt. Anonyme und angemeldete Fremdidentitäten
  reproduzierten vor der Korrektur `workbenchticketcontentchanged`.
- Standards- und Spec-Review des Release-Diffs und der Korrekturen melden keine
  verbleibenden belegten Mergeblocker. Die Prüfung vertiefte besonders
  OAuth/Storage/History und die schreibenden Aktivitätsgrenzen; sie ist keine
  Behauptung einer vollständigen Einzelzeilenprüfung aller Release-Dateien.

## Reale Upgrade- und HTTP-Abnahme

Eigene isolierte Moodle-5.1.7+-Instanz, PHP 8.4.25, MariaDB 11.4.12, eigenes
internes Docker-Netz ohne veröffentlichte Ports. Zuerst wurde das Plugin aus
dem veröffentlichten Tag installiert; synthetische Bestandsdaten wurden mit
dessen APIs erzeugt. Danach erfolgte der direkte Upgrade auf den endgültigen
Pluginquellstand. Keine bestehende Instanz oder persönliche Verbindung wurde
für diesen Nachweis verwendet.

[`verify-release-upgrade.php`](../../scripts/ci/verify-release-upgrade.php)
prüft den isolierten Baseline-/Upgrade-Vorgang und erlaubt ausschließlich die
fest benannten eigenen Testdatenbanken. Fixture-Dateien enthalten synthetische
Secrets, erhalten Modus `0600` und sind kein Bestandteil der Belege.

Die Abnahme deckt ab: übersetzte alte Pointer/Pending-/History-Schlüssel,
unveränderte ausgewählte Kontext-/Materialorte und Inhalte, OAuth-Verbindungen
und ursprüngliche Fristen, Rotation, Replay, unabhängig aktive zweite Verbindung,
Widerruf, Zugriffsentzug, geschützten Verlaufsvergleich, unveränderte rohe
Restorebedingungen, Privacy-Löschung mit erhaltener Aktivität, Schema und Tasks.
[Protokolle](evidence/627/final-verify.log): vollständige Datenabnahme erfolgreich;
[HTTP-Protokoll](evidence/627/final-http.log): alle sechs Prüfungen erfolgreich.
HTTP prüft die alte Download-URL ohne Login inklusive Einmalverwendung, echten
Login, alte Ortswahl inklusive ungefährlichem altem POST sowie den Browse-Sesskeyguard.

## Prüfungen und Artefakt

- Node-Vertragstests: 39 Tests, keine Fehler oder Skips; Dokumentationsverweise gültig.
- XML-Regressionen: 15 Tests / 53 Assertions; Ticketklasse: 27 / 142; OAuth-Verbindungen: 10 / 98.
- Vorherige gezielte Schreib-/Backup-/Placement-Prüfung: 57 / 152, ein optionaler Skip.
- Finaler Build: 394 Dateien im ZIP, CRC gültig, alle enthaltenen vorhandenen
  Pluginquellen bytegleich; `lang/de` gemäß Releasevertrag ausgeschlossen.
- Lokales ZIP `local_coursepilot-2.1.0-beta.zip`, SHA-256:
  `36d0dd4c040ffdcc80a84448263078c16a1ec2813fa4d9542406755eb2108bb8`.

Der erforderliche GitHub-Check `Gate (required)` wird am endgültigen PR-Head
abgewartet. Run/Head und veröffentlichte Artefaktprüfsumme werden im
[Release-PR #684](https://github.com/matthiasgruenwald/moodle-coursepilot/pull/684)
und der GitHub-Veröffentlichung festgehalten. Pages ist auf Actions konfiguriert;
der erfolgreiche Main-Deploy und die erreichbaren Sprachfassungen werden in
[#627](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/627) belegt.

Installations-Upgrades mit Schemaänderungen benötigen einen vollständigen
Snapshot von Datenbank, Moodledata und Pluginstand als Rückweg; ein bloßes
Zurückkopieren des alten Plugins ist kein Downgradeverfahren.
