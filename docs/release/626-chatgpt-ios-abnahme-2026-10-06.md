# #626 – ChatGPT-iOS-Bericht vom 06.10.2026

## Nutzerbericht und Sichtprüfung

Der Nutzer übermittelt einen Bericht mit Ergebnis „BESTANDEN – technische
Clientabnahme erfolgreich“: Testseite tatsächlich angelegt und über Coursepilot
zurückgelesen. Anschließend bestätigt er im T3-Thread: **„sichtprüfung in moodle ok“**.
Die im Clientbericht noch als offen bezeichnete manuelle Prüfung ist damit durch
die nachfolgende Nutzerbestätigung fortgeschrieben. Der Worker hat diese Bedienung
nicht selbst ausgeführt und keine eigene Browser-Sichtprüfung vorgenommen.

Die Oberfläche ist ausdrücklich **ChatGPT native iOS-App**, Version **1.2026.267**,
Konto **Plus**; kein Codex. Datum 06.10.2026, Zeitpunkt laut Bericht ca. 08:36 MESZ.
Diese Angabe ist eine Näherung des Clientberichts, kein unabhängig gemessener
Schreibzeitpunkt. Der Maintainer bestätigt anschließend ausdrücklich, dass die
Chatfunktion der iOS-App für diesen Auftrag das Web-Kriterium erfüllt und der
Test bestanden ist. Diese Abnahmeentscheidung wird übernommen; kein zusätzlicher
Web-Schreibtest ist erforderlich. Der vorherige Bericht mit demselben ungefähren Zeitpunkt war als
Webseitenchat angekündigt worden und endete vor Schreiben. Die neue explizite
Oberflächenangabe wird als iOS dokumentiert. Die Gleichwertigkeit für #626 ist
die ausdrückliche Abnahmeentscheidung des Maintainers, keine Behauptung über eine
separat bediente Browseroberfläche.

## Verbindung und Kandidat

- Endpunkt laut Bericht: `https://spike.gruenwald.fun/local/coursepilot/mcp.php`.
- Nutzer-Screenshot der aktiven Verbindung laut Bericht: Coursepilot, OAuth,
  Status `DEVELOPMENT`, Konto `Primary`. Dieses Bild wurde in diesem Worker-Thread
  noch nicht als Datei übermittelt und vom Worker nicht visuell geprüft.
- Plugincommit: `75f1308a3816ec626899e800062642d1165de5fa`.
- Herkunft: weitergereichter Quellenvergleich 391/391 bytegleich, keine Abweichung;
  laut Bericht erneute Nutzerbestätigung unmittelbar vor dem Schreiben.
  Keine eigene Commit-Verifikation durch den ChatGPT-Client.
- Von Coursepilot zusätzlich gemeldet: 2.0.0-beta, Version 2026100402,
  Moodle 5.1.7+ (Build 20260928).
- Kurs 27, `testkurs spike-claude`, Abschnitt 4, Section-ID 140; vor Schreiben gelesen.
- Bestehende Codex-Seite `CP626 Codex write check`, CMID 1858: laut Bericht unverändert.

Der [Worker-Quellenvergleich](evidence/626-spike-source-comparison-2026-10-06.json)
hält den unabhängig lesend geprüften Stand um 07:37:13 MESZ fest. Er bestätigt
keinen Screenshot oder Endpunkt einer anderen Sitzung.

## Nachgereichte Werkzeugbelege

Vier Original-Screenshots des Clientberichts ergänzen den zuvor abgeschnittenen
Text. Sie sind unverändert gespeichert, visuell geprüft und über den
[SHA256-Nachweis](evidence/626-chatgpt-report-images.json) nachvollziehbar.
Keine sichtbaren Namen, E-Mails, Passwörter oder Tokens in diesen vier Bildern erkannt.
Die Bilder zeigen den ausgegebenen Bericht, keine unabhängige Rohaufzeichnung
oder Einrichtungsdialoge.

| Bild | Sichtbarer Nachweis |
|---|---|
| [1](evidence/626-chatgpt-report-01.png) | Abschnitt 4/ID140 sichtbar; `get_course_catalog` vor Schreiben mit `courseid=27`, `sectionnum=4`, `modname=page`, `detail=compact`; Codex-Seite1858 vorhanden; `describe_module_fields` kurz/voll. |
| [2](evidence/626-chatgpt-report-02.png) | Einziger Write `coursepilot_create_module`: Kurs27/Abschnitt4/Typ page, Titel `CP626 ChatGPT write check`, Inhalt `Client acceptance check.`, Editorformat1. Ergebnis CMID1859/Typ page/Titel/Inhalt/Format1. Die lange `fields_json`-Zeile ist rechts abgeschnitten; ihr nicht sichtbarer Rest wird nicht ergänzt. |
| [3](evidence/626-chatgpt-report-03.png) | `coursepilot_get_module_settings({"cmid":1859})`: Kurs27, Abschnitt4, exakter Titel/Inhalt, `visible=1`, `visibleoncoursepage=1`; `get_course_catalog` mit `detail=full` bestätigt Seite1859 und unveränderte Codex-Seite1858. |
| [4](evidence/626-chatgpt-report-04.png) | `coursepilot_get_modules({"courseid":27,"sectionnum":4})`: beide Seiten1858/1859 vorhanden und sichtbar; erwarteter und tatsächlich zurückgelesener Titel/Inhalt exakt gleich. |

Neue Aktivitäts-ID: **1859**. Moodle-Seite:
<https://spike.gruenwald.fun/mod/page/view.php?id=1859>.
Der Clientbericht erklärt, dass die URL aus Standardroute und zurückgegebener CMID
abgeleitet ist; Coursepilot lieferte im Schreibaufruf keine vollständige URL.

Der nachgereichte Schluss meldet „erfolgreich und exakt übereinstimmend“, keine
technische Fehlermeldung, keinen fehlgeschlagenen Schreibversuch, keine Wiederholung
und keine weiteren Moodle-Änderungen. Die Schreibfreigabe war zuvor ausdrücklich
erteilt worden. Beide Seiten bleiben bestehen. Die dort noch als offen bezeichnete
Moodle-Sichtprüfung wurde vom Nutzer in diesem Thread bereits bestätigt.

## Einrichtung und abschließender Stand

Der Nutzer bestätigt ausdrücklich: Die vier markierten Einrichtungsbilder wurden
auf der **Webseite** aufgenommen und dort wurde Coursepilot eingerichtet. Die
bisherige Zuordnung der Bilder zur Desktop-App war eine falsche Interpretation
ähnlicher Oberflächen und ist in DE/EN korrigiert. Die Einrichtung wird in
`docs/site/assets/img/codex-1.png` bis `codex-4.png` gezeigt. Die Bestätigung ihrer
Herkunft stammt vom Nutzer; die ursprünglichen Anhänge dieses Einrichtungsschritts
liegen diesem Bericht nicht separat für einen erneuten Bytevergleich bei.
Die vier `626-chatgpt-report-*.png` oben zeigen dagegen den Clientbericht und sind
ein eigenes Belegpaket mit dem genannten Hash-Manifest. Die Einrichtungsbilder 2/3
ersetzen die beiden ChatGPT-Platzhalter in beiden Sprachfassungen. Der sichtbare
Endpunkt `moo.gruenwald.fun` ist Einrichtungskontext, kein Beleg des Spike-Endpunkts
des Schreibtests. Die Herkunft des aktiven Spike-Verbindungsnachweises bleibt wie
oben als Nutzer-/Clientbericht dokumentiert.

Der Nutzer erlaubt ausdrücklich, seinen eigenen Namen und die Moodle-Instanz
sichtbar zu belassen. Diese aufgabenspezifische Freigabe ersetzt für seine Angaben
die frühere Anforderung personenfreier Bilder. Keine Geheimnisse oder fremden
Personenangaben werden dadurch zur Veröffentlichung freigegeben.

Beide Client-Schreibtests, Rückleseprüfung und Moodle-Sichtprüfung sind bestanden.
Der Maintainer bestätigt anschließend in diesem T3-Thread, beide Anleitungen
bereits durchgelesen und freigegeben zu haben, und autorisiert die Ticket-Schließung.
Damit sind sämtliche Kriterien von #626 abgenommen.
Keine weiteren Screenshots oder Schreibtests werden für diesen Dokuschnitt verlangt.

Nur Dokumentation ergänzt, keine Werkzeuge gegen Moodle aufgerufen, kein
Deployment/Upgrade und keine Issue-Schließung. Ponytail bleibt ausgeschlossen.
