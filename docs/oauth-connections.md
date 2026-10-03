# Stabile OAuth-Verbindungen (#638)

Eine Verbindung (Grant) gehört genau einer Person und einem Client. Jede neue
Autorisierung eröffnet eine eigene Verbindung, auch beim gleichen Nutzer und
Client. Alle nachfolgenden Tokenpaare und Downloadtickets tragen diese stabile
Kennung. Rotation verbraucht nur das bisherige Paar; gültige Tickets behalten
ihre eigenen Fristen. Die Selbstverwaltung verwendet weiterhin Tokenzeilen-IDs:
Auch ein inzwischen rotiertes Paar verweist auf die widerrufbare Verbindung.

Rotation und expliziter Widerruf aktualisieren dieselbe Grant-Zeile innerhalb
einer Datenbanktransaktion. Der Widerruf sperrt das Grant und sämtliche
Generationen. Ticketprüfung verlangt das noch aktive Grant des Ticketinhabers;
Kontostatus, Fernzugriff, Einmalverbrauch, Inhaltshash und die 15-Minuten-Frist
bleiben zusätzlich erforderlich. Der Sammelwiderruf sperrt Grants vor Tokenpaaren.

## Upgrade und historische Nachweisgrenze

Der Moodle-Schritt 2026100300 legt Tabellenfelder und Indizes idempotent an.
Je höchstens 100 noch nicht zugeordnete, unwiderrufene Bestandszeilen werden in
einer eigenen Transaktion migriert. Jede bekommt ein separates Grant. Tickets
mit dieser Tokenreferenz und demselben Nutzer bekommen das Grant; Fristen und
Geheimnishashes bleiben unverändert. Ein abgebrochener Batch wird zurückgerollt,
bereits abgeschlossene Batches bleiben erhalten. Wiederholung erzeugt für sie
keine weiteren Grants. Das Upgrade läuft während der Moodle-Wartung, nicht
parallel zum laufenden OAuth-Verkehr.

Vor #638 überschrieben Rotationen verbrauchte Refresh-Hashes mit zufälligen
Anspruchsmarkern. Dieser Zusammenhang lässt sich nachträglich nicht zuverlässig
rekonstruieren. Widerrufene historische Paare bleiben deshalb ohne Grant;
Nutzer-/Clientgleichheit oder Zeitnähe sind kein Familiennachweis. Ein Ticket mit
unbekannter alter Tokenreferenz bleibt gesperrt. Seit diesem Upgrade behalten
verbrauchte Refresh-Hashes ihre nachgewiesene Verbindungszuordnung.

**#638 schließt F5 noch nicht vollständig.** Replay eines verbrauchten Refresh-
Tokens wird weiterhin abgelehnt, löst aber noch keine automatische Sperre der
Familie aus. Diese Reaktion folgt separat in #639. Historische unbekannte
Familien dürfen dabei weiterhin nicht erfunden werden.

## Native Prüfbarkeit

Die Regressionen verwenden synthetische Nutzer, Clients, Dateien und Geheimnisse
an den öffentlichen OAuth-/Downloadgrenzen. Separate PHP-Prozesse prüfen beide
Reihenfolgen Rotation/Widerruf und zwei konkurrierende Rotationen; ein DB-Trigger
hält die erste Transaktion am Barrier, während die zweite die gemeinsame
Grant-Zeile aktualisieren will. Ein weiterer Trigger unterbricht den zweiten
Migrationsbatch; Wiederholung prüft die unveränderten ersten 100 Zuordnungen,
getrennte Grants und erhaltene Ticketfristen. Moodle prüft das resultierende
Schema gegen install.xml. Native Fresh-Install und Upgrade laufen ausschließlich
auf separaten Moodle-Codekopien, Datenverzeichnissen und synthetischen Datenbanken.

Für #638 wurde unter Moodle 5.1.7+, PHP 8.4 und MariaDB 11.4 zunächst eine neue
PHPUnit-Installation aus install.xml erstellt. Zusätzlich wurde eine zweite,
synthetische Datenbank auf das Schema vor 2026100300 zurückgesetzt und mit zwei
gültigen Paaren desselben Nutzers/Clients, einer widerrufenen historischen Zeile
und einem gültigen Ticket befüllt. Moodles admin/cli/upgrade.php führte den echten
Plugin-Schritt erfolgreich aus; anschließend wurden Savepoint, natives Schema,
bestehende Tokens, Ticketabruf nach Rotation und Verbindungswiderruf geprüft.
