# Stabile OAuth-Verbindungen und Replay-Sperre (#638–639)

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

Seit #639 widerruft Replay eines verbrauchten Refresh-Tokens die nachgewiesene
Verbindung und alle Generationen atomar unter derselben Grant-Sperre wie Rotation
und expliziter Widerruf. Damit werden auch alle an dieses Grant gebundenen Tickets
ungültig. Der Verbrauch wird nach Erwerb der Sperre erneut geprüft: Hat ein
konkurrierender Refresh bereits einen Nachfolger erzeugt, sperrt der zweite
Aufruf auch diesen. Das gilt auch nach Ablauf des verbrauchten Tokens; dessen
Nachfolger haben eigene Fristen. Ein falscher Client oder ein unbekannter Hash
löst keinen Widerruf aus. Andere Verbindungen derselben Person bleiben gültig.
Nach außen liefert der Token-Endpunkt unverändert nur `invalid_grant`.

Die Replay-Garantie beginnt mit nachweislich erhaltenen, verbrauchten Hashes seit
dem Upgrade 2026100300 (#638) und der aktivierten Sperrreaktion (#639). Vorher
überschriebene Hashes und unbekannte historische Familien werden nicht erfunden.
Bestandsverbindungen gewinnen den Replay-Nachweis mit ihrer ersten Rotation nach
diesem Upgrade. Erfordert ein Sicherheitsvorfall auch Schutz ohne erhaltenen
Nachweis, muss die Administration die bestehenden Verbindungen widerrufen.

## Native Prüfbarkeit

Die Regressionen verwenden synthetische Nutzer, Clients, Dateien und Geheimnisse
an den öffentlichen OAuth-/Downloadgrenzen. Separate PHP-Prozesse prüfen beide
Reihenfolgen Rotation/Widerruf, Rotation/Replay und zwei konkurrierende Rotationen; ein DB-Trigger
hält die erste Transaktion am Barrier, während die zweite die gemeinsame
Grant-Zeile aktualisieren will. Kein Rennen lässt einen dauerhaft gültigen
Nachfolger oder ein gültiges gebundenes Ticket zurück. Eine injizierte
Token-Widerrufs-Störung prüft das gemeinsame Rollback von Grant und Generationen;
ein noch uncommitteter Widerruf veröffentlicht keine Teilsperre.
Ein weiterer Trigger unterbricht den zweiten
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
