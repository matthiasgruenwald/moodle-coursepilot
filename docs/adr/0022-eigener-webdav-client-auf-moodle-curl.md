# Eigener WebDAV-Client auf Moodles `\curl` statt `\webdav_client`

Kontextbereich und Materialbestand können in einem WebDAV-Speicher der Lehrkraft liegen (Karte
#467). Zugangsdaten kommen aus der `repository_webdav`-Nutzerinstanz (#468). Der naheliegende
Transport wäre der Core-Client `\webdav_client` (`lib/webdavlib.php`), auf dem auch
`repository_webdav` sitzt. Kurspilot benutzt ihn bewusst **nicht**, sondern einen eigenen,
schlanken Client auf Moodles `\curl` (`lib/filelib.php`). Entschieden in
[#478](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/478).

## Entscheidung

**1. Der Core-Client lässt sich nicht sicher umhüllen.** Bei XML-Fehlern ruft er `die()` auf
(`webdavlib.php:669/766/859`). Das ist nicht abfangbar und beendet die Webservice-Anfrage mitten
in der Antwort. Er verbindet per `fsockopen` (`:194`) und umgeht damit Proxy und Moodles
Hostsperre. Content-Type, Rumpf und Antwortköpfe wirft er weg. Damit fehlt ihm genau das, woran
zwei Fälle hängen: eine gedrosselte Nextcloud (404 mit HTML-Rumpf) von einer fehlenden Datei
(404 mit DAV-XML) zu unterscheiden (#474) und bedingt zu schreiben.

**2. Der eigene Client ist klein und bleibt Moodle-Code.** Er spricht sechs Verben: `PROPFIND`
(Depth 0/1), `GET`, `PUT`, `MKCOL`, `MOVE` und `DELETE`, über `CURLOPT_CUSTOMREQUEST`. Er liefert
benannte Fehlerklassen statt nackter Statuscodes. Die Ortswahl benutzt denselben Client, nicht
`get_listing()`.

**3. Moodles Hostsperre gilt, kein `ignoresecurity`.** Maßgeblich ist, wohin der Servername vom
Moodle-Server aus auflöst. Ein Speicher im internen Netz wird über die Core-Sperrliste
freigegeben.

## Considered Options

- **`\webdav_client` mit Aufsatz:** abgelehnt. Das `die()` lässt sich nicht umgehen, und für die
  Unterscheidung der beiden 404 bräuchte es den Rumpf, den der Client gar nicht erst herausgibt.
  Ein Aufsatz hätte also genau die zwei Fälle offen gelassen, auf die es ankommt.
- **Rohes PHP-curl:** abgelehnt. Es könnte zwar Digest, übergeht aber die Hostsperre. Jede
  Lehrkraft könnte den Moodle-Server dann über ihre Instanz ins interne Netz zeigen lassen.

## Consequences

- **Nur Anmeldeart Basic, nur HTTPS.** Moodles `\curl` wirft bei einer Anmelde-Aushandlung eine
  `coding_exception` (`filelib.php:3871`, die zweite Runde zählt als Weiterleitung). Digest
  braucht diese Runde immer und ist deshalb ausgeschlossen, ebenso `none`.
- **Bedingtes Schreiben ist serverabhängig.** `If-None-Match: *` beim Neuanlegen trägt auf IServ
  und Nextcloud. `If-Match` trägt nur, wo der Server ETags liefert: Die Nextcloud tut es, IServ
  nicht. Dort bleibt `getlastmodified` mit Sekundenauflösung als schwacher Ersatz.
- **Supportfalle:** Der Core-Dateiwähler blättert auch in einem Speicher, den Kurspilot als
  gesperrt meldet, weil `fsockopen` die Sperrliste nicht kennt.
- **Die Wartungslast ist in Kauf genommen, nicht gewünscht.** Gibt der Core `\webdav_client` je
  Rumpf und Antwortköpfe heraus und verzichtet auf `die()`, ist der Rückwechsel eine Änderung an
  einer Stelle.
