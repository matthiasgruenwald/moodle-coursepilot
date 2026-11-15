# Prüfung: Personenbezug in den Werkzeugausgaben (#703)

Geprüfter Stand: `dev` @ `a07f4960` (Branch `fix/703-personal-data-audit`), 15.11.2026.
Prüfgegenstand: alle 56 in `classes/tool_registry.php` registrierten Werkzeuge,
Rückgabestruktur (`execute_returns()`) und tatsächlicher Code samt aufgerufener Klassen.
Es wurde kein Produktcode geändert. Einzige Änderung außerhalb dieses Reports: ein Hinweis im Skill-Korpus
(`skills/reference/coursepilot-core.md`, Abschnitt „Skill-Hinweise").

## Kurzfazit

- **Kein Werkzeug liest Lernenden-Daten.** Weder Abgaben, Bewertungen, Versuche, Forenbeiträge, Teilnehmer-,
  Gruppen- oder Einschreibelisten noch Logs werden gelesen oder ausgegeben. Eine Probe mit synthetischen
  Lernendendaten (Abgabe mit Text und Datei, Forenbeitrag, Glossareintrag, Wiki-Seite, Testversuch,
  Nutzer-Override, Auswahl-Antwort) über rund 70 Aufrufe von `dispatcher::handle()` fand **keinen** Treffer
  für Name, E-Mail, Nutzername oder Inhalte der Lernenden.
- **Verfügbarkeitsbedingungen** laufen in allen Lesepfaden über `availability_privacy::sanitize()`; die übrigen
  Werkzeuge geben das Feld gar nicht aus.
- **Änderungsverlauf** (Nachprüfung #632): die Korrekturen halten. Dateinamen aus Abgabebereichen und Profilwerte
  erscheinen nicht. Offen bleibt ein anderes Thema: Klarname und Benutzer-ID der Bearbeiter.
- **Befunde (4):** ungefilterte Instanzspalten mit Zugangsdaten, auch für Lehrkräfte ohne Bearbeitungsrecht
  (P2, per Probe belegt, kein Personenbezug im engen Sinn), ein Fail-open in der Markierungserkennung für
  Kontextdateien (P3, per Probe belegt), Bearbeiternamen im Verlauf (P3), Denylist-Charakter von `sanitize()`
  (P3, nur Code).
- Was der Code nicht erkennen kann (Freitext, Dateinamen, Bilder), regelte der Skill-Korpus bisher nur für das
  **Schreiben** unmarkierter Dateien, nicht für **Ausgaben von Werkzeugen**. Diese Lücke ist mit einem kurzen
  Abschnitt geschlossen.

## Maßstab

`CODING_STANDARDS.md` K5 (ohne Personenbezug arbeiten, soweit möglich) und K6 (Personenbezug nur hinter technischer
Freigabe; was Code nicht erkennt, deckt der KI-Text ab), ADR 0011 (Schalter `allowpersonaldata` wirkt auf die
**Markierung**, nicht auf den Inhalt), `privacy_surface.php` (nur Lehrkraft-Gestaltung, keine Lernendendaten).
Je Feld gelten drei Fragen: kann es Personenbezug tragen, braucht die Aufgabe des Werkzeugs es, ist es technisch
abgesichert. „Technisch abgesichert" heißt: Schalter, Kennzeichnung, `sanitize()`, hartes Weglassen im Backup oder
Feld wird nicht gelesen. Personenbezug in Freitext, den eine Lehrkraft selbst eintippt, zählt als
„möglich, nicht erkennbar" und gehört dem Skill.

## Werkzeug-Tabelle

Kürzel: **Pb** = Personenbezug möglich; **nötig** = braucht die Aufgabe das Feld; **gesichert** = technisch abgesichert.
Beleg „Probe" = `dispatcher::handle()` im Spike-Container (Abschnitt Nachweise), „Code" = nur aus dem Quelltext geschlossen.

### Lesen von Kursstruktur und Aktivitäten

| Werkzeug | Feld | Pb | nötig | gesichert | Beleg |
|---|---|---|---|---|---|
| `list_courses` | `id`, `fullname`, `shortname`, `visible` | nein (Kursnamen) | ja | n. a. | `list_courses.php:58-68` (nur `enrol_get_my_courses('id, fullname, shortname, visible')`); Probe |
| `get_course_catalog` | Abschnitt: `name`, `summary`, `availability` | Freitext möglich; `availability` ja (Profilwert) | ja | `availability` ja (`sanitize`); Freitext nein | `get_course_catalog.php:149`; Probe: Profil-E-Mail nicht enthalten |
| | Modul: `name`, `content` (`intro`/Seiteninhalt), `settings`, `availability`, `quizslots.questionname` | Freitext möglich; `availability` ja | ja | `availability` ja (`:206`); Freitext nein | `module_state`/Katalogklassen lesen nur Spalten der Aktivität (`assign.php:496-570`, `page.php:139`); keine Nutzertabelle |
| `get_modules` | `cmid`, `sectionnum`, `modname`, `name`, Sichtbarkeit | nein | ja | n. a. | `get_modules.php` `execute_returns`: kein `availability`-Feld; Probe |
| `get_sections` | `name`, `summary` | Freitext möglich | ja | Freitext nein | `get_sections.php`; kein `availability`-Feld; Probe |
| `get_module_settings` | `settings_json`: komplette Instanzzeile `SELECT *` plus `cm`-Felder | Freitext möglich (`intro`); **Zugangsdaten** je nach Modultyp (Befund F2) | Einstellungen ja, Geheimnisse nein | `availabilityconditionsjson` ja (`module_state.php:93`); sonst nein | `module_state.php:43-58,71` (`read_settings`, `SELECT *`); Probe: Klartext-Treffer siehe F2, auch für nicht erschlossene Arten und für Lehrkräfte ohne Bearbeitungsrecht |
| `describe_module_fields` | Feldkatalog (Namen, Typen, Standardwerte) | nein | ja | n. a. | statischer Katalog, keine Nutzerdaten |
| `get_version_info` | Moodle-/Plugin-Version, Datum | nein | ja | n. a. | `get_version_info.php`; Probe |
| `list_skills`, `get_skill` | Skilltexte, Triggertexte | nein | ja | n. a. | statischer Korpus |

### Änderungsverlauf (Nachprüfung #632)

| Werkzeug | Feld | Pb | nötig | gesichert | Beleg |
|---|---|---|---|---|---|
| `list_activity_versions` | `userid`, `user` (Klarname), `summary_line` („Version 2 - Name, Zeit …") | **ja** (Name und ID der Bearbeiter, auch Kolleginnen/Kollegen und Admin) | Rolle ja, Klarname/ID nein | nein | `version_history.php:250-251`; Probe: Klarname erscheint (Befund F3) |
| | `source_cmid`, `source`, `timestamp` | nein | ja | n. a. | `version_history.php` `describe_meta` |
| `compare_activity_versions` | `before`/`after`: `userid`, `user` | wie oben | wie oben | nein | `version_history.php:250-251` |
| | `changes[].before_json/after_json` (Instanzzeile + `course_modules`-Zeile) | Freitext möglich; `availability` ja; **Zugangsdaten** modultypabhängig | Änderungen ja | `availability` ja (`public_state`, `:364-372`); Rest nein | `version_writer.php:202,150`; Probe Verfügbarkeit: `***`; Probe Zugangsdaten: Treffer (F2) |
| | `files[].filename` | ja (Abgabedateien) | nur Gestaltungsdateien | ja (positive Allowlist `file_policy::allows`, Capture und Lesen) | `file_policy.php`; vorhandener Test `dispatcher_test::test_history_comparison_protects_current_and_legacy_data`; Probe |
| `restore_activity_version` | `changes[]`, `message` | wie `compare` | ja | wie `compare`: Änderungen laufen über `module_settings::write`, das `read_settings` (`sanitize`) vergleicht | `version_restore.php:65-77`; `availabilityconditionsjson` ist kein beschreibbares Feld (`shared_block.php`) |

### Aktivitäten, Abschnitte und Fragen schreiben

Schreibwerkzeuge geben nur Nachrichten und vorher/nachher-Werte der geschriebenen Felder zurück. Die Werte stammen
aus `read_settings()` (sanitisiert) oder sind die eigenen Eingaben der Lehrkraft.

| Werkzeug | Rückgabefelder | Pb | nötig | gesichert | Beleg |
|---|---|---|---|---|---|
| `update_module_settings`, `create_module`, `create_quiz`, `update_quiz_settings`, `set_completion`, `set_restriction` | `cmid`, `modname`, `message`, `changes[]`/`created_fields[]` (`before_json`, `after_json`, `value_json`), `side_effects` | nur Freitext der geschriebenen Felder; `availability` nie im Klartext (`set_restriction` gibt nur `message`) | ja | `availability` ja | `set_restriction.php` `execute_returns`; `module_settings.php` `diff_and_side_effects` liest über `read_settings` |
| `ensure_section`, `update_section`, `move_section`, `move_module` | `id`, `sectionnum`, `name`, `message`, `changes[]` | Freitext (Abschnittsname/Zusammenfassung) | ja | Freitext nein | `execute_returns` der vier Dateien |
| `ensure_question_bank`, `ensure_question_category`, `update_question_category`, `ensure_quiz_question_categories`, `get_question_categories`, `plan_question_category_cleanup` | `id`, `name`, `parent`, `contextid`, `editurl`, `reason` | nein (Kategorienamen) | ja | n. a. | `execute_returns`; Probe |
| `move_question`, `create_mc_question`, `update_mc_question` | `questionid`, `questionbankentryid`, `version`, `status`, `name`, `message` | Freitext (Fragename) | ja | Freitext nein | `execute_returns`; keine Autor-/Besitzerfelder |
| `add_questions_to_quiz`, `plan_quiz_cleanup` | `slot`, `questionid`, `name`, `categoryname`, `reason`, `editurl` | nein | ja | n. a. | `execute_returns`; Probe (`plan_quiz_cleanup`) |
| `add_glossary_entries` | `cmid`, `entries[]` (`index`, `success`, `entryid`, `approved`, `errorcode`, `message`), `gap_notice` | nein (Eintrag wird mit der Lehrkraft als Autor angelegt, der Autor wird nicht ausgegeben) | ja | n. a. | `add_glossary_entries.php` `execute_returns` |

### Fragen lesen und exportieren

| Werkzeug | Feld | Pb | nötig | gesichert | Beleg |
|---|---|---|---|---|---|
| `get_question` | `name`, `questiontext`, `generalfeedback`, `answers[].answer/feedback`, `qtype`, `defaultmark` | Freitext möglich | ja | Freitext nein | `get_question.php:228-253`: keine `createdby`/`modifiedby`/`ownerid`; Probe: Fragezeile mit `createdby`/`modifiedby`/`ownerid` auf Testlernenden gesetzt, nicht in der Ausgabe |
| `export_questions_xml` | `xml` (Moodle-XML via `qformat_xml`), `path`, `count` | Freitext möglich | ja | Autor-/Besitzerangaben schreibt `qformat_xml` nicht | `question_xml.php:157-161`; Probe: XML enthält kein `createdby`/`modifiedby`/`ownerid`/`userid` |
| `import_questions_xml` | `name`, `questionbankentryid`, `version`, `status` | nein | ja | n. a. | `execute_returns` |
| `report_clone_lineage` | `slot`, `questionbankentryid`, `questionid`, `name`, `idnumber`, `status`, `source_course_id` | nein | ja | n. a. | `report_clone_lineage.php:slot_lineage_rows`: nur `quiz_slots`, `question_references`, `question_versions`, `question`-Name; Probe mit Testversuch im Quiz |

### Aktivitäts-XML, Backup, Klonen

| Werkzeug | Feld | Pb | nötig | gesichert | Beleg |
|---|---|---|---|---|---|
| `export_activity_backup` | `xml` (`<modul>.xml` des Einzelaktivitäts-Backups) | Nutzerdaten im Backup möglich, **hart ausgeschlossen**; Freitext möglich | ja | ja: `backup_controller` mit `MODE_IMPORT`, nur `<modul>.xml` wird gelesen | `activity_backup.php:84`; Probe: assign (Abgabe), forum (Beitrag), glossary (Eintrag), choice (Antwort), wiki (Seite), quiz (Versuch plus Nutzer-Override), lti: kein Treffer, keine `<userid>`/`<users>`-Elemente |
| `export_default_activity` | `xml`, `courseid`, `modname` | nein (frische Standardaktivität, wird wieder entfernt) | ja | n. a. | Probe (`glossary`) |
| `clone_activity` | `cmid`, `courseid`, `message` | Ausgabe nein; **Kopie** übernimmt Profilbedingung samt Wert (Befund F5, nur Info) | ja | Backup ohne Nutzerdaten; Restore `MODE_IMPORT` | `activity_backup.php:281`; Probe: Klon enthält keine Glossareinträge der Lernenden, Verfügbarkeit unverändert kopiert |
| `create_activity_from_xml` | `cmid`, Pfade gefüllter Felder, Orts-/Vorgängerangaben (`location`, `successor_cmid`, `hidden_predecessors`) | nein (Aktivitäts-/Abschnittsnamen) | ja | Scaffold ohne `users.xml`, `roles`, `grades`, leere Nutzerlisten | `activity_backup.php:scaffold` (`grade_history`, `roles` leer) |

### Kontext- und Materialdateien

| Werkzeug | Feld | Pb | nötig | gesichert | Beleg |
|---|---|---|---|---|---|
| `list_context_files` | `name`, `size`, `mimetype`, `locked`, `contenthash`, `timemodified` | Dateinamen (Freitext) | ja | `locked` nur für `.md` und nur bei erkannter Markierung | `context_area.php:136`; Probe: BOM-markierte Datei erscheint mit `locked=false` (F1) |
| `read_context_file` | `content`, `filename`, `path`, … | **ja** (Klarnamen in Förderprofilen, ADR 0011) | ja (Planung) | Schalter + Markierung, **lückenhaft** (F1) | `read_context_file.php:97`; Probe: BOM-, `yes`-, `True`-markierte Dateien werden bei ausgeschaltetem Schalter geliefert; reguläre Markierung gesperrt; unmarkierte `liste.csv` wird geliefert (ADR 0011: bewusst) |
| `write_context_file`, `append_context_file` | `path`, `created`, `size`, `message` | Eingabe, nicht Ausgabe; Schreibsperre für markierte Inhalte | ja | gleiche Erkennung wie oben | `context_files.php:266-289`, `context_area.php:268-269,374-376` |
| `dismiss_pending_entry`, `dismiss_previous_location` | `identifier`/`message` | nein (Pfade der eigenen Kontextdateien in Meldungen) | ja | n. a. | `execute_returns` |
| `list_material_files`, `report_loose_material_files` | `name`/`path`, `size`, `mimetype`, `contenthash`, `timemodified`, `age_days` | Dateinamen (Freitext) | ja | Dateimetadaten ohne Autor/Besitzer | `private_files_storage_port.php:397-432` (kein `author`, `userid`) |
| `upload_material_file`, `crop_material_file`, `compose_material_file`, `delete_material_files`, `create_workbench_download_links` | `path`, `size`, `contenthash`, `message`, `url` | Dateinamen | ja | n. a. | `execute_returns`; Ticket an die Lehrkraft gebunden |
| `preview_material_file` | `image_base64` (Bildinhalt) | **Bild kann Personen zeigen** | ja (Gestaltungsprüfung) | nein (nicht erkennbar) | `dispatcher.php:341-349`; nur aus Code geschlossen; Skill-Hinweis |

### Fehlermeldungen und Meldungen

| Pfad | Pb | gesichert | Beleg |
|---|---|---|---|
| `dispatcher::error_message()` | gibt `debuginfo` nur bei `invalidparameter` zurück (Eingabetext der Lehrkraft), andere Ausnahmen mit Moodle-Standardtext ohne SQL | ja | `dispatcher.php:388-399` |
| Historienmeldungen (`historysummary*`) | enthalten den Bearbeiter-Klarnamen | nein (F3) | `lang/en/local_coursepilot.php:149-152` |
| `access_log` (Admin-Seite, nicht Werkzeugausgabe) | `userid` des Aufrufers | außerhalb des KI-Pfads | `access_log.php` |

Die Admin-Seiten (`connection::active_tokens()` mit Name/E-Mail, Cohort-Mitglieder in `remote_access.php`) sind keine
MCP-Werkzeuge und blieben außerhalb des Prüfumfangs.

## Befunde nach Schwere

### F1 – P3: Markierungserkennung ist fail-open bei Randformen (Probe belegt)

`personal_data::is_marked()` erkennt `personenbezug: true` mit `/^---\s*\n(.*?)\n---\s*\n/s` und der Zeichenfolge
`personenbezug:\s*true\b` in Kleinschreibung (`personal_data.php:52-55`). Korrekt gesperrt (Probe): kanonische Form,
`coursepilot.personenbezug:true`, CRLF, Frontmatter ohne Rumpf. Als **unmarkiert** gelten:

| Variante | `is_marked()` | Einordnung |
|---|---|---|
| UTF-8-BOM vor `---` | false | **realistisch**: Windows-Editor, Rückweg per WebDAV/Upload |
| Leerzeile vor dem ersten `---` | false | Randform |
| schließendes `---` direkt am Dateiende (ohne Zeilenumbruch) | false | Randform |
| `personenbezug : true` (Leerzeichen vor dem Doppelpunkt) | false | Randform, YAML-gültig |
| `personenbezug: True` | false | nur YAML-konform (Spec 0010 nennt `true`) |
| `personenbezug: "true"` / `yes` | false | **keine gültige Markierung** nach Spec 0010 und YAML 1.2 |

Die beiden letzten Zeilen zu sperren wäre eine bewusste fail-closed-Erweiterung, keine Fehlerbehebung einer gültigen Markierung.

**Szenario:** Eine Lehrkraft bearbeitet ein Förderprofil mit einem Editor, der eine BOM voranstellt. Die Datei ist
gemeint als markiert; `read_context_file` liefert sie bei ausgeschaltetem Schalter an die KI (Probe `bom-marked.md`;
`list_context_files` zeigt `locked=false`). Dieselbe Erkennung steuert Schreibsperre und Host-Freigabe
(`context_files.php:266-289`, `personal_data_hosts.php`): ein so geschriebener Inhalt kann auch auf einen nicht
freigegebenen externen WebDAV-Host gelangen. Der Schalter versagt dann beim „mitgebrachten Bestand" (ADR 0011,
Festlegung 2). Wegen der engen Voraussetzungen (BOM oder Sonderform) P3.

**Vorschlag:** BOM abschneiden, führende Leerzeilen und Dateiende ohne Zeilenumbruch tolerieren, Leerzeichen vor dem
Doppelpunkt tolerieren; optional (Spec-Entscheidung) `True`/`yes`/`"true"` als fail-closed aufnehmen.

**Eigene Entscheidung (nicht Teil von T1):** `locked` in der Liste wird nur für `.md` berechnet
(`context_area.php:135-141`), die Lesesperre in `read_context_file.php:97` gilt für alle Dateitypen. Die Liste kann
also `locked=false` zeigen, wo das Lesen scheitert. Ob die Liste für alle Typen prüfen soll (Kosten: Lesen jeder Datei),
ist getrennt zu entscheiden.

### F2 – P2: `SELECT *` auf die Aktivitätszeile gibt Zugangsdaten aus, auch an nicht bearbeitende Lehrkräfte (Probe belegt)

`get_module_settings` gibt über `module_state::read_settings()`/`effective_settings()` (`module_state.php:43-58,71`)
die komplette Instanzzeile aus, auch für nicht erschlossene Aktivitätsarten. `version_writer::build_moduleinfo()`
(`:202`) speichert dieselbe Zeile roh in `local_coursepilot_cm_version.moduleinfo_json`; `public_state()` maskiert dort
nur die Verfügbarkeit. Ergebnisse der Probe (Klartext-Treffer im Ergebnis von `dispatcher::handle()`):

| Aktivität | Felder | `get_module_settings` | `compare_activity_versions` | `export_activity_backup` | Verlauf gespeichert |
|---|---|---|---|---|---|
| lti | `password`, `resourcekey`, `servicesalt` | Treffer | Treffer | kein Treffer | ja |
| lesson | `password` | Treffer | Treffer | Treffer | ja |
| quiz | `password` | Treffer (gewollt, `quizpassword`, siehe T2) | Treffer | Treffer | ja |
| bigbluebuttonbn | `moderatorpass`, `viewerpass`, `guestpassword`, `guestlinkuid` | Treffer | Treffer | Treffer für `moderatorpass`, `viewerpass` | ja |

`list_activity_versions` nennt nur Feldnamen, keine Werte.

**Rechteausweitung (Probe):** Eine Lehrkraft vom Archetyp `teacher` (nicht bearbeitend; `manageactivities=0`,
`mod/lti:addinstance=0`) erhält Secret, Salt und Passwörter genauso wie die bearbeitende Lehrkraft, obwohl sie das
Bearbeitungsformular nie sieht. Der Archetyp `teacher` trägt `local/coursepilot:use` (`db/access.php:29-36`). Der
`servicesalt` wird von Moodle niemandem angezeigt; mit Secret und Salt lassen sich LTI-1.1-Bewertungsrückmeldungen
fälschen. Die Einordnung „der Gestalter sieht es ohnehin" trägt nicht. Hinzu kommt der KI-Anbieter als Empfänger
(K6). Das ist kein Personenbezug im engen Sinn, sondern ein Verstoß gegen „nur das
Nötige" und eine Rechteausweitung; P2.

**Vorschlag:** siehe T2 (Liste je Aktivitätsart; Rohverlauf bleibt, gefiltert wird bei Ausgabe).

### F3 – P3: Verlauf gibt Klarnamen und Benutzer-ID der Bearbeiter aus

Unbekannte Nutzer erscheinen als „User #<id>" (`historyunknownuser`, `lang/en/local_coursepilot.php:152`); auch das ist eine Benutzer-ID.

`describe_meta()` liefert `userid` und `user` (`fullname()`), `summary_line` wiederholt den Namen
(`version_history.php:250-251`, `lang/en/local_coursepilot.php:149-152`). Probe: „Version 2 - <Klarname>,
<Zeit>: …". Betroffen sind Kolleginnen und Kollegen sowie Administrationskonten, die den Kurs bearbeitet haben.
Lernende können nicht Bearbeiter sein (Aktivitäts-Schreibrechte), die Daten sind also Beschäftigtendaten.
Die Aufgabe „wer hat wann was geändert" braucht Rolle und Zeit, nicht zwingend Klarname und ID.

**Vorschlag (Produktentscheidung):** Eigene Änderungen als „you", fremde als „another editor" mit stabilem
Pseudonym je Verlauf, Klarname nur in der Moodle-Verlaufsseite (`history.php`) für Berechtigte.

### F4 – P3: `availability_privacy::sanitize()` ist eine Denylist (nur Code)

Maskiert wird ausschließlich `type === 'profile'` (`availability_privacy.php`). Bedingungstypen von
Drittanbieter-Plugins (z. B. nutzer- oder kohortenbezogene Verfügbarkeitsplugins) bleiben unmaskiert. Auf dem Spike
sind nur die sechs Kern-Typen installiert (`completion`, `date`, `grade`, `group`, `grouping`, `profile`), daher
heute kein Treffer. Verwendungsstellen (vollständig): `get_course_catalog.php:149,206`, `module_state.php:93`,
`version_history.php:364-372`; Probe bestätigt die Maskierung dort.

**Vorschlag:** Allowlist (`completion`, `date`, `grade`, `group`, `grouping` unverändert, alles andere maskiert).

### F5 – Info: `clone_activity` kopiert Profilbedingungen samt Wert

Der Klon trägt die Verfügbarkeit unverändert (Probe), auch kursübergreifend. Kein Ausgabepfad zur KI; relevant nur,
wenn Klone in Kurse mit anderem Teilnehmerkreis wandern. Keine Maßnahme vorgeschlagen.

### Prüfung ohne Befund

- Kein Werkzeug fragt `user`-bezogene Lerndaten ab: die einzigen `user`-Zugriffe im Plugin sind Authentifizierung
  (`dispatcher.php:483`), Verlaufsname (`version_history.php:474`), Admin-Seite und WebDAV-Check.
- Backup: Nutzerdaten im Einzelaktivitäts-Backup sind in `MODE_IMPORT` ausgeschlossen. Belegt für assign, forum,
  glossary, choice, wiki, quiz (mit Versuch und Nutzer-Override) und lti.
- Verlauf, Dateien: positive Allowlist (`file_policy`), `capture_files()` und `allowed_files()` filtern identisch.
- Fragen: `question.createdby/modifiedby` und `question_bank_entries.ownerid` werden von keinem Werkzeug gelesen.
- `get_modules`, `get_sections`, `set_restriction`, `clone_activity` geben kein `availability`-Feld aus.

## Folgeticket-Entwürfe (nicht angelegt)

### T1 – `fix(personal_data): Markierung tolerant erkennen`

**Problem:** F1 (P3). BOM, Leerzeile vor dem Frontmatter, Dateiende direkt nach `---` und `personenbezug : true` gelten
als unmarkiert. Optional (Spec-Entscheidung): `True`, `"true"`, `yes` fail-closed.
**Zusätzlich:** `mark_memory` speichert das Ergebnis je Datei in der Datenbank (`mark_memory.php:63,104`,
`ismarked`). Alte `ismarked=0`-Einträge für BOM-/Randform-Dateien blieben nach einer Codekorrektur wirksam; ein
`upgrade.php`-Schritt muss die Tabelle bereinigen. Die akzeptierte Menge von Schreibweisen ist in Spec 0010 festzuschreiben.
**Abnahmeprobe (`dispatcher::handle()`, `allowpersonaldata` aus):** Je Variante eine Datei anlegen, Liste einmal
aufrufen (füllt `mark_memory` mit der alten Logik), Upgrade ausführen; danach `coursepilot_list_context_files` zeigt
`locked=true`, `coursepilot_read_context_file` endet mit `contextfilelocked`, `coursepilot_write_context_file` und
`coursepilot_append_context_file` mit der Variante als Inhalt werden abgelehnt, auf nicht freigegebenem externen Host
ebenso. Negativfall: `personenbezug: false` bleibt les- und schreibbar.

### T2 – `fix(catalog): Zugangsdaten je Aktivitätsart aus Einstellungen und Vergleich ausblenden`

**Problem:** F2 (P2). Liste **je Aktivitätsart**, keine pauschale Spaltensperre: `quiz.password` ist gewollt lesbar
(Feld `quizpassword`, `catalog/quiz.php:526,573,840`, `learner_locks`); `lesson` ist nicht erschlossen
(`registry.php`: label, page, url, folder, resource, choice, forum, assign, quiz), `lesson.password` ist deshalb kein
Katalogfeld und kann ausgeblendet werden. Zu sperren: `lti.password`, `resourcekey`, `servicesalt`, `lesson.password`,
`bigbluebuttonbn.moderatorpass`/`viewerpass`/`guestpassword`/`guestlinkuid`. Speicherung im Verlauf bleibt roh
(Wiederherstellen braucht den Rohstand); gefiltert wird in `public_state()` und `effective_settings()`.
**Offene Entscheidung:** Passwörter im Backup-XML (`export_activity_backup`: quiz, lesson, bbb) behalten (Klon-Treue)
oder ausblenden.
**Abnahmeprobe (`dispatcher::handle()`):** Aktivitäten mit Sentinel-Werten anlegen; `coursepilot_get_module_settings`
und nach einer Änderung `coursepilot_compare_activity_versions`, je einmal mit bearbeitender **und** mit nicht
bearbeitender Lehrkraft (Archetyp `teacher`), enthalten keinen gesperrten Wert; `quizpassword` bleibt lesbar. Ein
Schreibvorgang (`coursepilot_update_module_settings`) auf ein anderes Feld leert das Secret nicht;
`coursepilot_restore_activity_version` überschreibt es nicht.

### T3 – `feat(history): Bearbeiter im Verlauf pseudonymisieren`

**Problem:** F3. Klarname und Benutzer-ID anderer Bearbeiter erreichen die KI.
**Abnahmeprobe:** Zwei Lehrkräfte bearbeiten eine Aktivität; `coursepilot_list_activity_versions` und
`coursepilot_compare_activity_versions` des einen Aufrufers enthalten weder `fullname` noch Benutzer-ID des anderen
und markieren eigene Änderungen unterscheidbar.

### T4 – `fix(availability): sanitize() auf Allowlist umstellen`

**Problem:** F4. Unbekannte Bedingungstypen bleiben unmaskiert.
**Abnahmeprobe:** `sanitize()` ist reine JSON-Funktion und wertet die Bedingung nicht aus. Die Probe setzt daher die
Bedingung direkt in `course_modules.availability` (Stub-Typ mit Sentinel-Wert, `{"type":"stubtype","v":"<sentinel>"}`)
und liest über `coursepilot_get_course_catalog`, `coursepilot_get_module_settings`,
`coursepilot_compare_activity_versions` (zwei Stände); ein Auswerten über `core_availability` findet nicht statt, die
Probe scheitert also nicht an „unknown condition". Der Wert fehlt in der Ausgabe; `completion`, `date`, `grade`,
`group`, `grouping` bleiben lesbar.

## Skill-Hinweise

Der Code erkennt Personenbezug nur über die Markierung (ADR 0011). Nicht erkennbar sind: Namen in Seiten-, Label-,
Aufgaben- und Abschnittstexten, in Frage- und Antworttexten, in Dateinamen (Material, Kontext, Verlaufsdateien),
in unmarkierten Kontextdateien und in Bildern (`preview_material_file`), außerdem die Klarnamen der Bearbeiter im
Verlauf.

Bestand im Korpus (`skills/reference/`):

| Stelle | Inhalt | Reicht für |
|---|---|---|
| `context-area.md:176-186` | Namen und IDs nur in Dateien mit `personenbezug: true`; vor Schreiben Markierung prüfen, sonst kürzeln | **Schreiben** in Kontextdateien |
| `context-onboarding.md:120-123` | Beobachtungen zu einzelnen Lernenden in `CONTEXT-people.md`, markiert | Schreiben |
| `coursepilot-core.md` (neu) | Verhalten bei Personenbezug in **Werkzeugausgaben** | Lesen |

**Lücke (geschlossen):** Keine Stelle wies die KI an, bei unerwartet auftauchendem Personenbezug in einem
Werkzeugergebnis (Aktivitätstext, Datei, Verlauf, Bild) diesen nicht weiterzuverwenden und die Lehrkraft zu
informieren. K6 verlangt genau das für alles, was der Code nicht erkennt. Ergänzt ist ein Abschnitt
„Personal data in tool results" in `coursepilot-core.md`. Er gilt für alle drei Adapter, da `coursepilot-core`
gemeinsame Grundlage ist.

**Verbleibende Lücken (nur benannt):**

- Die Werkzeugbeschreibungen in `lang/en/local_coursepilot.php` tragen keinen Hinweis; für Codex-Läufe ohne
  geladenen Skill greift der Schutz nur über den Skill. Ein knapper Satz in den Beschreibungen der Lesewerkzeuge
  (`get_course_catalog`, `get_module_settings`, `read_context_file`, `preview_material_file`) wäre möglich, ist aber
  Produktentscheidung.
- Der Abschnitt sagt „nicht weiterverwenden", die KI hat das Ergebnis aber bereits im Kontext; die Wirkung ist
  Disziplin, keine Sperre. Das ist die Grenze jedes Skill-Hinweises (ADR 0011: kein Filter für Freitext).

## Nachweise und Grenzen

**Probe (Spike-Container, PHPUnit-Scratch-Test außerhalb des Repos, kein Vollauf):** Kurs mit Lehrkraft, einer
Testschülerin (eigene Namens-, E-Mail- und Inhaltsmarken) und je einer Aktivität assign, forum, glossary, choice,
wiki, quiz, lti. Lernendendaten: Abgabetext und -datei, Forenbeitrag, Glossareintrag, Wiki-Seite, Auswahl-Antwort,
Testversuch, Nutzer-Override; Fragezeile mit `createdby`/`modifiedby`/`ownerid` der Testschülerin; Profil- und
Gruppenbedingung auf Abschnitt und Aktivität; zwei Verlaufsstände; fünf Kontextdateien (regulär, BOM, `yes`, `True`,
`.csv`). Aufgerufen wurden: `list_courses`, `get_version_info`, `get_course_catalog` (full), `get_modules`,
`get_sections`, `get_module_settings` (7 Aktivitäten plus Klone), `list_`/`compare_activity_versions`,
`export_activity_backup` (7 plus Klone), `export_default_activity`, `clone_activity` (6), `report_clone_lineage`,
`plan_quiz_cleanup`, `get_question_categories`, `plan_question_category_cleanup`, `get_question`,
`export_questions_xml`, `list_context_files`, `read_context_file` (5). Geprüft wurde, ob eine der Marken in der
Antwort steht. Treffer: nur Zugangsdaten (F2, zweite Probe) und die erwartet freigegebenen Kontextdateien (F1); alle Lernendenmarken
**ohne Treffer**. Der Bind-Mount `/opt/plugins/local_coursepilot` wurde am Ende wieder exakt auf den Branch-Stand
gesetzt (`diff -rq` identisch).

**Nur aus dem Code geschlossen, nicht per Probe belegt:** Drittanbieter-Bedingungstypen (F4), Bildinhalte von `preview_material_file`, `restore_activity_version` über die
Verfügbarkeitsfelder (kein beschreibbares Feld), reine Schreibwerkzeuge (Ausgabe sind Nachricht und eigene Eingabe).

**Nicht geprüft:** externe WebDAV-Speicher, Admin-Seiten, Moodle-Webservice-Zugriff außerhalb von MCP,
Kern-Aktivitätstypen ohne Katalog (z. B. `data`, `workshop`, `h5pactivity`) und die Drittanbieter-Plugins des Spikes
(`checklist`, `choicegroup`, `learningmap`, `lightboxgallery`, `subcourse`) mit eigenen Nutzerspalten (nur Spaltennamen
von Kerntabellen geprüft), Moodle-Versionen außer 5.1, `participant`-artige
Plugins, die ein Administrator an den Dienst anhängt (deckt `privacy_surface_test` ab).
