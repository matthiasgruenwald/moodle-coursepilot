# #626 – ChatGPT-Web-Vorprüfung vom 06.10.2026

## Herkunft und Status

Der Nutzer bezeichnet den übermittelten Bericht als Ergebnis seines ChatGPT-
Webseitenchats. Der Bericht selbst enthält noch die übernommenen Bezeichnungen
„Codex-Oberfläche“ und `CP626 Codex write check`; sie sind keine Bestätigung eines
Codex-Laufs. Kontotarif Plus ist in diesem Bericht nicht gesondert belegt.
Zeitpunkt laut Bericht: ca. **08:36 Uhr MESZ am 06.10.2026**.

**Abbruch vor Schreibzugriff.** Kein `coursepilot_create_module`, keine neue
Aktivität und keine Rückleseprüfung. Der Lauf ist weder ein bestandener Schreibtest
noch ein beobachteter Schreibfehler. Keine Aussage über mögliche ChatGPT-
Schreibbeschränkungen wird daraus abgeleitet.

## Berichtete lesende Aufrufe

- `coursepilot_list_skills({})`: Korpus 2.0.0-beta (2026100402), keine offenen
  `pending_entries`.
- `coursepilot_get_skill`: `coursepilot-implement`, `coursepilot-core`,
  `implementation-plan-workflow`, `mcp-tools`, `html-templates`, `technical-notes`.
  Zweck: Schreibregeln und Seitenanlage prüfen.
- `coursepilot_get_version_info({})`: Moodle 5.1.7+ (Build 20260928), branch 501;
  Coursepilot 2.0.0-beta, Version 2026100402; Serverdatum 2026-10-06.
  Keine MCP-Endpunkt-URL in der Antwort.
- `coursepilot_list_courses({})`: Kurs **27**, `testkurs spike-claude`, sichtbar
  und für die aufrufende Lehrkraft verfügbar. Laut Bericht nur Kursmetadaten,
  keine Lernendendaten gelesen.

Kein Schreibfreigabedialog beobachtet, weil kein Schreibaufruf begonnen wurde.

## Blockierende Vorbedingung

Vorgesehener Endpunkt:
`https://spike.gruenwald.fun/local/coursepilot/mcp.php`.
Die tatsächlich konfigurierte Transport-URL war dem Client aus seinen Werkzeugen
nicht zugänglich. Erwartete Kurse und passende Versionsnummern sind kein eindeutiger
URL-Nachweis. Eine Bestätigung aus den Einstellungen der **aktiven ChatGPT-
Verbindung** durch den Nutzer genügt als Konfigurationsnachweis; Coursepilot muss
kein zusätzliches Werkzeug zur Offenlegung der clientseitigen Transport-URL erhalten.
Der Nachweis der Codex-Konfiguration ist dafür nicht übertragbar.

Vorgegebener Plugincommit: `75f1308a3816ec626899e800062642d1165de5fa`.
Der Client nennt den vom Nutzer weitergegebenen Quellenvergleich vom 06.10.,
07:37 Uhr MESZ (391/391, keine Abweichung); keine eigene Prüfung durch ChatGPT.
Dieser [Worker-Beleg](evidence/626-spike-source-comparison-2026-10-06.json) bestätigt
die installierte Spikequelle zum Prüfzeitpunkt, keine aktive ChatGPT-Verbindung.

## Fortsetzung

1. Nutzer bestätigt oder fotografiert in den Verbindungseinstellungen die aktive
   ChatGPT-Verbindung mit exakter Spike-URL und bestätigt den Plus-Tarif.
   Diese Aufnahme kann zugleich eines der fehlenden ChatGPT-Webbilder liefern.
2. Vor Fortsetzung installierten Kandidatenstand koordiniert bzw. lesend prüfen;
   keine Quellenänderung zwischen Bestätigung und Probe voraussetzen.
3. Genau eine **neue** Seite in Kurs 27, Abschnitt 4 anlegen:
   Titel `CP626 ChatGPT write check`, Inhalt `Client acceptance check.`.
   Die bestehende Codex-Seite `cmid=1858` bleibt unverändert.
4. Titel, Inhalt, Kurs und Abschnitt über Coursepilot zurücklesen. Werkzeugname,
   genaue Argumente, Ergebnis/Fehler, Freigabeverhalten, neue Aktivitäts-ID und URL
   als ChatGPT-Web-Bericht dokumentieren; keine Geheimnisse ausgeben.
5. Sichtprüfung beider Testseiten in Moodle sowie zwei echte Webbilder und
   abschließende DE/EN-Durchsicht bleiben offene Kriterien.

Dieser Worker hat für diesen Nachtrag keine Live-Aufrufe, Schreibaktionen,
Deployments oder Upgrades ausgeführt. Keine Issue-Schließung oder PR.
