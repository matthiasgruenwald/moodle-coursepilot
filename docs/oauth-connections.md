# Stabile OAuth-Verbindungen, Replay-Sperre, Registrierungs- und CIMD-Budget (#638–643)

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

## Anonyme Registrierung begrenzt (#642)

Die Clientregistrierung (`oauth/register.php`) bleibt ohne Anmeldung erreichbar,
legt aber nur innerhalb endlicher Budgets Clients an. Reihenfolge: Methode,
Größengrenze vor dem JSON-Dekodieren, Metadatenprüfung, Budget, erst dann der
Datensatz. Jede Ablehnung legt keinen Client an; abgelehnte Größen und ungültige
Metadaten verbrauchen kein Budget.

| Grenze | Wert | Art |
|---|---|---|
| Registrierungen pro Fenster, Instanz | 200 | Einstellung `oauthregistersitelimit` |
| Registrierungen pro Fenster, Quelle | 50 | Einstellung `oauthregistersourcelimit` |
| Zeitfenster | 3600 s | Einstellung `oauthregisterwindow` |
| Anfragekörper | 16 KiB | fest (`REGISTRATION_MAX_BODY_BYTES`), HTTP 413 |
| Länge je `redirect_uri` | 2048 Zeichen | fest, HTTP 400 `invalid_redirect_uri` |
| Anzahl `redirect_uris` | 10 | fest, HTTP 400 `invalid_client_metadata` |
| `client_name` | 255 Zeichen | wird gekürzt |
| Löschhorizont Budgetdaten | Fensterende | bei jeder Budgetprüfung, sonst stündliche Aufgabe |
| Löschhorizont unbenutzter Clients | – | folgt mit der OAuth-Bereinigung (#644) |

Eine leere Einstellung nutzt den Standard, 0 oder negative Werte gelten als 1 –
eine unbegrenzte Einstellung gibt es nicht. Die Fenster sind fest: An einer
Fenstergrenze sind kurzzeitig bis zu zwei Limits möglich. Eine geänderte
Fensterlänge beginnt neue Fenster und damit neue Zähler. Erschöpftes Budget liefert HTTP 429 mit
`temporarily_unavailable` und `Retry-After` bis zum Fensterende. Autorisierung,
PKCE-Pflicht, Codeeinlösung und Refresh bereits registrierter Clients sind davon
unberührt.

Die Quelle ist Moodles `getremoteaddr()`: Weitergeleitete Header zählen nur, wenn
die Administration einen Reverse-Proxy konfiguriert hat. IPv6-Adressen werden auf
ihr /64-Netz reduziert. Gespeichert wird ein HMAC der Quelle mit der
Instanzkennung, keine Klartextadresse. Die Instanzkennung ist kein Geheimnis;
der Schutz liegt vor allem in der Lebensdauer von höchstens einem Fenster.

Zustand (`local_coursepilot_oauth_budget`): je Fenster ein Instanzzähler und ein
Zähler je Quelle, die das Instanzbudget passiert hat – höchstens Instanzlimit + 1
Zeilen pro Fenster. Alle Anfragen eines Bereichs serialisieren sich in einer
Transaktion auf der Instanzzeile; die letzte erlaubte und die erste abgelehnte
Anfrage sind auch parallel exakt. Eine Quellablehnung gibt die Instanzeinheit
zurück, damit eine einzelne Quelle das Instanzbudget nicht leert. Abgelaufene
Fenster löscht jede Budgetprüfung (`oauth_budget::purge_expired()`); ohne neue
Anfragen übernimmt das die stündliche Aufgabe `oauth_budget_cleanup`. Eine an der
Fenstergrenze bereits gelöschte Instanzzeile gilt als Ablehnung, nie als leeres
Budget. Derselbe Vertrag `oauth_budget::consume()` schützt den CIMD-Abruf (#643)
im Bereich `cimd`.

Der Moodle-Schritt 2026100342 legt die Tabelle idempotent an und registriert die Aufgabe.

## Anonyme CIMD-Discovery begrenzt (#643)

Eine unbekannte `client_id` in Form einer https-URL löst am Token-Endpunkt (ohne
Anmeldung) und an der Autorisierung einen Abruf des Client-Metadatendokuments
aus. Reihenfolge in `oauth_lib::get_client()`: gespeicherter Client (kein Budget,
kein Abruf), URL-Form und -Länge, Fehlcache, Budget, erst dann Netzabruf mit den
Transportgarantien aus #635 (TLS-Prüfung von Peer und Hostname, Moodle-Host-/
Portsperren, keine Umleitungen, 1 MiB beim Empfang, 5 s). Jede Ablehnung vor dem
Budget verbraucht keines; eine Budgetablehnung startet keine Netzarbeit.

| Grenze | Wert | Art |
|---|---|---|
| Erstabrufe pro Fenster, Instanz | 100 | Einstellung `oauthcimdsitelimit` |
| Erstabrufe pro Fenster, Quelle | 20 | Einstellung `oauthcimdsourcelimit` |
| Zeitfenster | 3600 s | Einstellung `oauthcimdwindow` |
| Länge der `client_id`-URL | 255 Zeichen | fest (`CIMD_MAX_URI_LENGTH`, Spaltengröße) |
| Fehlcache je URL | bis Ende des laufenden 600-s-Abschnitts | fest (`CIMD_NEGATIVE_WINDOW`), Bereich `cimdfail` |
| Fehlcache-Einträge je 600-s-Abschnitt | Instanzlimit | dieselbe Einstellung `oauthcimdsitelimit` |
| `redirect_uris`, `client_name` im Dokument | wie Registrierung | 10 × 2048 Zeichen, Name gekürzt auf 255 |

Erschöpftes Budget liefert am Token-Endpunkt HTTP 429 `temporarily_unavailable`
mit `Retry-After`, an der Autorisierung eine Fehlerseite `temporarily_unavailable`.
Fehlgeschlagene oder ungültige Dokumente landen im Fehlcache: ein Zähler im
Bereich `cimdfail`, dessen Quellschlüssel der HMAC der URL ist; dieselbe URL
liefert bis zum Ende des laufenden festen 600-s-Abschnitts sofort `invalid_client`
ohne neuen Abruf (je nach Fehlzeitpunkt also kürzer als 600 s; weitere Versuche
begrenzt dann das Abrufbudget). Die Anzahl der Einträge ist auf das Instanzlimit
je Abschnitt begrenzt, sie verfallen mit dem Abschnitt wie alle Budgetzeilen. Gespeichert werden nur HMACs, keine URL und keine
Adresse; der Abruf protokolliert nichts. Gültige gespeicherte Clients
(DCR wie CIMD) arbeiten auch bei erschöpftem Budget ohne erneuten Abruf.

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
