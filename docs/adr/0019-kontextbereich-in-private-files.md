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
  Kurspilot-Schreibvorgänge im Kontextbereich nicht möglich — die Fehlermeldung benennt
  die fehlende Capability und verweist auf die Administration. Der Leseweg bleibt unberührt
  (er prüft `local/kurspilot:use`, nicht `manageownfiles`).
- Weitergabe-Fall (Dateien von Kolleginnen übernehmen): Core-Filepicker-Quelle „Server files"
  reicht — keine eigene Plugin-Seite nötig.
