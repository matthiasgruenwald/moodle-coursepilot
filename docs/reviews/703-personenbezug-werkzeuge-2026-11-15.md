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
- **Befunde (4):** ein Fail-open in der Markierungserkennung für Kontextdateien (P2, per Probe belegt),
  ungefilterte Instanzspalten mit Zugangsdaten (P2, per Probe belegt, kein Personenbezug im engen Sinn),
  Bearbeiternamen im Verlauf (P3), Denylist-Charakter von `sanitize()` (P3, nur Code).
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
| `list_courses` | `id`, `fullname`, `shortname`, `visible` | nein (Kursnamen) | ja | n. a. | `list_courses.php:60-66` (nur `enrol_get_my_courses('id, fullname, shortname, visible')`); Probe |
| `get_course_catalog` | Abschnitt: `name`, `summary`, `availability` | Freitext möglich; `availability` ja (Profilwert) | ja | `availability` ja (`sanitize`); Freitext nein | `get_course_catalog.php:149`; Probe: Profil-E-Mail nicht enthalten |
| | Modul: `name`, `content` (`intro`/Seiteninhalt), `settings`, `availability`, `quizslots.questionname` | Freitext möglich; `availability` ja | ja | `availability` ja (`:206`); Freitext nein | `module_state`/Katalogklassen lesen nur Spalten der Aktivität (`assign.php:496-570`, `page.php:139`); keine Nutzertabelle |
| `get_modules` | `cmid`, `sectionnum`, `modname`, `name`, Sichtbarkeit | nein | ja | n. a. | `get_modules.php` `execute_returns`: kein `availability`-Feld; Probe |
| `get_sections` | `name`, `summary` | Freitext möglich | ja | Freitext nein | `get_sections.php`; kein `availability`-Feld; Probe |
| `get_module_settings` | `settings_json`: komplette Instanzzeile `SELECT *` plus `cm`-Felder | Freitext möglich (`intro`); **Zugangsdaten** je nach Modultyp (Befund F2) | Einstellungen ja, Geheimnisse nein | `availabilityconditionsjson` ja (`module_state.php:93`); sonst nein | `module_state.php:71`; Probe: `lti.password` erscheint im Klartext |
| `describe_module_fields` | Feldkatalog (Namen, Typen, Standardwerte) | nein | ja | n. a. | statischer Katalog, keine Nutzerdaten |
| `get_version_info` | Moodle-/Plugin-Version, Datum | nein | ja | n. a. | `get_version_info.php`; Probe |
| `list_skills`, `get_skill` | Skilltexte, Triggertexte | nein | ja | n. a. | statischer Korpus |

### Änderungsverlauf (Nachprüfung #632)

| Werkzeug | Feld | Pb | nötig | gesichert | Beleg |
|---|---|---|---|---|---|
| `list_activity_versions` | `userid`, `user` (Klarname), `summary_line` („Version 2 - Name, Zeit …") | **ja** (Name und ID der Bearbeiter, auch Kolleginnen/Kollegen und Admin) | Rolle ja, Klarname/ID nein | nein | `version_history.php:250-251`; Probe: Klarname erscheint (Befund F3) |
| | `source_cmid`, `source`, `timestamp` | nein | ja | n. a. | `version_history.php` `describe_meta` |
| `compare_activity_versions` | `before`/`after`: `userid`, `user` | wie oben | wie oben | nein | `version_history.php:250-251` |
| | `changes[].before_json/after_json` (Instanzzeile + `course_modules`-Zeile) | Freitext möglich; `availability` ja; **Zugangsdaten** modultypabhängig | Änderungen ja | `availability` ja (`public_state`, `:364-372`); Rest nein | `version_writer.php:202,150`; Probe Verfügbarkeit: `***`; Zugangsdaten nur aus Code geschlossen |
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

### F1 – P2: Markierungserkennung ist fail-open (Probe belegt)

`personal_data::is_marked()` erkennt `personenbezug: true` nur mit dem Muster
`/^---\s*\n(.*?)\n---\s*\n/s` und der Zeichenfolge `true` in Kleinschreibung (`personal_data.php:52`).
Folgende Varianten gelten als **unmarkiert** (isolierte Prüfung der Funktion; durch die Werkzeug-Probe bestätigt):

| Variante | `is_marked()` |
|---|---|
| UTF-8-BOM vor `---` (Windows-Editoren) | false |
| `personenbezug: True` / `"true"` / `yes` | false |
| Leerzeile vor dem ersten `---` | false |
| schließendes `---` am Dateiende ohne Zeilenumbruch | false |

**Szenario:** Eine Lehrkraft bearbeitet ein Förderprofil mit einem Editor, der eine BOM voranstellt. Die Datei trägt
eine gültige Markierung, `read_context_file` liefert sie bei ausgeschaltetem Schalter trotzdem an die KI
(Probe: `bom-marked.md`, `yes-marked.md`, `upper-marked.md` → Inhalt im Ergebnis; `list_context_files` zeigt
`locked=false`). Dieselbe Erkennung steuert Schreibsperre und Host-Freigabe
(`context_files.php:266-289`, `personal_data_hosts.php`): ein so markierter Inhalt kann auch auf einen nicht
freigegebenen externen WebDAV-Host geschrieben werden. Der Schalter versagt also ausgerechnet beim „mitgebrachten
Bestand" (ADR 0011, Festlegung 2).

**Vorschlag:** Erkennung tolerant machen (BOM abschneiden, Groß-/Kleinschreibung, `"true"`/`'true'`/`yes`/`on`,
führende Leerzeilen, Dateiende ohne Zeilenumbruch) und im Zweifel sperren (fail-closed). `locked` in der Liste für
alle Dateitypen aus derselben Funktion ableiten statt nur für `.md`.

### F2 – P2: `SELECT *` auf die Aktivitätszeile gibt Zugangsdaten aus (Probe belegt; Nebenbefund, kein Personenbezug im engen Sinn)

`module_state::effective_settings()` (`module_state.php:71`) liest die komplette Instanzzeile jedes Aktivitätstyps,
nicht nur der katalogisierten. `get_module_settings` liefert sie unverändert; `version_writer::build_moduleinfo()`
(`:202`) speichert dieselbe Zeile im Verlauf, `compare_activity_versions` und `restore_activity_version` geben
geänderte Felder daraus aus. Auf dem Spike tragen mehrere Tabellen Geheimnisse: `lti.password`, `lti.servicesalt`,
`lti.resourcekey`, `bigbluebuttonbn.moderatorpass`/`viewerpass`/`guestpassword`/`guestlinkuid`, `lesson.password`.
Probe: Eine `lti`-Aktivität mit `password` liefert den Wert im Klartext aus `get_module_settings`
(`export_activity_backup` enthält ihn nicht). Personenbezug folgt nur mittelbar (Zugang zu Räumen oder Diensten);
dem Geist von K6 („nur das Nötige") widerspricht es trotzdem. Die Ausgabe für `compare` ist **nur aus dem Code
geschlossen**, nicht per Probe belegt.

**Vorschlag:** Spaltensperrliste oder -allowlist je Aktivitätstyp, einmal an `effective_settings()` und
`build_moduleinfo()` angewandt.

### F3 – P3: Verlauf gibt Klarnamen und Benutzer-ID der Bearbeiter aus

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

### T1 – `fix(personal_data): Markierung tolerant erkennen und im Zweifel sperren`

**Problem:** F1. BOM, `True`/`yes`/`"true"`, Leerzeile vor dem Frontmatter und Dateiende ohne Zeilenumbruch gelten
als unmarkiert; Lese- und Schreibsperre sowie Host-Freigabe greifen nicht.
**Abnahmeprobe (`dispatcher::handle()`, `allowpersonaldata` aus):** Je Variante eine Datei im Kontextbereich anlegen;
`coursepilot_read_context_file` endet mit `contextfilelocked`; `coursepilot_list_context_files` zeigt `locked=true`
(auch für `.csv`/`.txt` mit Markierung); `coursepilot_write_context_file` und `coursepilot_append_context_file` mit der
Variante als Inhalt werden abgelehnt, auf einem nicht freigegebenen externen Host ebenso (`personal_data_hosts`).
Negativfall: `personenbezug: false` bleibt les- und schreibbar.

### T2 – `fix(catalog): Zugangsdaten-Spalten aus Instanzzeile, Verlauf und Vergleich ausschließen`

**Problem:** F2. `SELECT *` liefert `lti.password`, `bigbluebuttonbn.moderatorpass`, `lesson.password` u. a.
**Abnahmeprobe:** `lti`-Aktivität mit `password`, `servicesalt`, `resourcekey` anlegen; `coursepilot_get_module_settings`,
nach einer Änderung `coursepilot_compare_activity_versions` und `coursepilot_list_activity_versions` enthalten
keinen dieser Werte; `coursepilot_restore_activity_version` schreibt sie nicht zurück und gibt sie nicht aus.

### T3 – `feat(history): Bearbeiter im Verlauf pseudonymisieren`

**Problem:** F3. Klarname und Benutzer-ID anderer Bearbeiter erreichen die KI.
**Abnahmeprobe:** Zwei Lehrkräfte bearbeiten eine Aktivität; `coursepilot_list_activity_versions` und
`coursepilot_compare_activity_versions` des einen Aufrufers enthalten weder `fullname` noch Benutzer-ID des anderen
und markieren eigene Änderungen unterscheidbar.

### T4 – `fix(availability): sanitize() auf Allowlist umstellen`

**Problem:** F4. Unbekannte Bedingungstypen bleiben unmaskiert.
**Abnahmeprobe:** Aktivität mit Bedingung `{"type":"x_user","v":"<sentinel>"}` per DB setzen;
`coursepilot_get_course_catalog`, `coursepilot_get_module_settings`, `coursepilot_compare_activity_versions`
enthalten den Wert nicht; Kern-Typen `completion`, `date`, `grade`, `group`, `grouping` bleiben lesbar.

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
Antwort steht. Treffer: nur `lti`-Passwort (F2) und die erwartet freigegebenen Kontextdateien (F1); alle Lernendenmarken
**ohne Treffer**. Der Bind-Mount `/opt/plugins/local_coursepilot` wurde am Ende wieder exakt auf den Branch-Stand
gesetzt (`diff -rq` identisch).

**Nur aus dem Code geschlossen, nicht per Probe belegt:** Ausgabe von `compare_activity_versions` für Zugangsdaten
(F2), Drittanbieter-Bedingungstypen (F4), Bildinhalte von `preview_material_file`, `restore_activity_version` über die
Verfügbarkeitsfelder (kein beschreibbares Feld), reine Schreibwerkzeuge (Ausgabe sind Nachricht und eigene Eingabe).

**Nicht geprüft:** externe WebDAV-Speicher, Admin-Seiten, Moodle-Webservice-Zugriff außerhalb von MCP,
Drittanbieter-Aktivitätstypen (z. B. `data`, `workshop`, `h5pactivity`) mit eigenen Nutzerspalten über die Kernspalten
hinaus (nur Spaltennamen der Kerntabellen auf dem Spike geprüft), Moodle-Versionen außer 5.1, `participant`-artige
Plugins, die ein Administrator an den Dienst anhängt (deckt `privacy_surface_test` ab).
