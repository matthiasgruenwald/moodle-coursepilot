# Kontextbereich in Moodles Private Files statt eigener Filearea

Der Kontextbereich der Lehrkraft — `plan.md`, Journale, Vorlagen, Fachprofile — lag bisher
in einer eigenen Filearea (`local_kurspilot/kurspilot_context`), die für die Lehrkraft in
der Moodle-UI unsichtbar war. Das Blackbox-Veto (Entscheidung #361) hat diese Prämisse
als nicht tragfähig eingestuft: Lehrkräfte müssen ihre Arbeitsdaten löschen, umbenennen
und weitergeben können — ohne Kurspilot als Vermittler.

Wir entscheiden: Der Kontextbereich liegt in Moodles Private Files (`user`/`private`),
in einem festen Kurspilot-Unterordner (`kurspilot/`, konfigurierbar). Die Verwaltung
ist damit vollständig Core-geschenkt.

## Considered Options

- **Eigene Filearea** (bisheriger Stand): vollständige Kontrolle über den Bereich, aber
  für die Lehrkraft in der Moodle-UI unsichtbar — kein Löschen, kein Umbenennen, keine
  Weitergabe ohne Kurspilot-Endpunkt. Verstößt gegen das Blackbox-Veto.

- **`user/private` (diese Entscheidung)**: Core schenkt „Meine Dateien" (Löschen inkl.
  Massenlöschung, Umbenennen, Verschieben, Zip-Download), Filepicker-Quelle „Private files"
  (Kopie/Alias in jeden anderen Kontext), Core-Privacy-Provider, Standardnutzerrechte
  (`moodle/user:manageownfiles` + `repository/user:view`). Preis: der
  `moodle/user:manageownfiles`-Schalter der Schule kann den Bereich für die Lehrkraft
  sperren — ein Plugin-seitig nicht überschreibbarer Angriffspunkt.

- **Kurspilot-Nebenbereich** (eigenes `repository_kurspilot`-Plugin, #362/#364): unabhängig
  vom Private-files-Schalter der Schule, mit eigener Filepicker-Quelle. Preis: ~550–700
  Zeilen, zweites Deployment-Artefakt, Admin-Aktivierung, drei Repository-Override-Methoden.
  6–8× Mehraufwand für einen Vorteil, der nur schlagend wird, wenn eine Schule Private Files
  abgeschaltet hat. Als Enhancement geparkt (#364).

## Consequences

- Die Klasse `context_files` wechselt `COMPONENT`→`user`, `FILEAREA`→`private`, `ITEMID`→`0`;
  Pfadprüfung und `contextroot`-Konfiguration bleiben unverändert.
- Altbestand in der alten Filearea bleibt nach dem Upgrade-Step erhalten (Rückweg, falls
  der Step etwas verpatzt); der Datei-Teil des Privacy-Providers deckt ihn weiterhin ab.
- Die neuen Dateien in `user/private` deckt der Core-Provider; Kurspilot exportiert sie
  nicht ein zweites Mal.
- Wenn eine Schule `moodle/user:manageownfiles` für Lehrkräfte deaktiviert hat, sind
  Kurspilot-Schreibvorgänge im Kontextbereich **der Moodle-Ablage** nicht möglich — die
  Fehlermeldung benennt die fehlende Capability und verweist auf die Administration.
  Der Leseweg bleibt unberührt; `manageownfiles` ist eine Schreibberechtigung.
- Weitergabe-Fall (Dateien von Kolleginnen übernehmen): Core-Filepicker-Quelle „Server files"
  reicht — keine eigene Plugin-Seite nötig.

## Präzisierung des bestehenden Lese-/Schreibvertrags

`list_context_files`, `read_context_file`, `list_material_files` und `preview_material_file`
lesen ausschließlich im validierten Nutzerkontext der angemeldeten Person. Eigene Dateien
bleiben auch bei `CAP_PROHIBIT` für `moodle/user:manageownfiles` lesbar. Die Werkzeuge verlangen
keine zusätzliche Lese-Capability und kein kursbezogenes `local/coursepilot:use`. Die historische
Angabe einer Prüfung von `local/kurspilot:use` ist damit fachlich präzisiert: Im heutigen
MCP-Weg prüft der Dispatcher vor jedem Werkzeugaufruf die eigenständige Fernzugriffsfreigabe.

Die Leseausnahme öffnet keinen Fremdzugriff. Beim Benutzerwechsel gelten der Nutzerkontext und
die Ablage der neuen Person. Externe Repository-Nutzerinstanzen werden bei jeder Auflösung
auf Eigentum geprüft ([ADR 0021](0021-isolierung-und-personenbezug-am-externen-ablageort.md)).
Markierte Kontextdateien bleiben bei ausgeschaltetem `allowpersonaldata` in Listen gesperrt
und können nicht gelesen werden.

Für Kontext-Schreibvorgänge in externen Ablagen gelten Anmeldung und Eigentumsprüfung am
Speicher sowie die Personenbezugsregeln aus ADR 0021. Die Moodle-Private-Files-Capability ist
dort kein pauschaler Schreibschalter. Die Moodle-Schreibsperre und diese externe Ausnahme
bleiben getrennt. Diese Präzisierung dokumentiert die bestehende Entscheidung; sie führt
kein neues Recht ein.
