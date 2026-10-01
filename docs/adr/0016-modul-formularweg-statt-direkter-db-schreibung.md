# Modul-Formularweg statt direkter DB-Schreibung

`local_coursepilot` schreibt Aktivitäten heute auf zwei Wegen: neun `create_*`-Werkzeuge über `add_moduleinfo()`, zwölf `update_*`-Werkzeuge und Sonderfälle wie `quiz_settings.php` direkt in die Instanztabelle. Beim Neubau des Schreibpfads in `local_kurspilot` (Spec 0015) entscheiden wir: **alles, was eine Modulinstanz berührt, läuft über `add_moduleinfo()`/`update_moduleinfo()` (`course/modlib.php`). Die direkte DB-Schreibung wird nicht portiert.** Ausgenommen bleibt nur, wofür Moodle kein Formularfeld hat: Positionen (`move_section_to()`, `moveto_module()`) und die Quiz-Anordnung (Kern-Struktur-API).

## Considered Options

- **Direkte DB-Schreibung beibehalten** (Status quo, geringster Umbau): verworfen. Sie baut von Hand nach, was der Formularweg generisch mitmacht — `course_modules`-Record, Intro-Editor samt Dateibereich, Tags, Abschnittszuordnung, Completion, `availability`, Grade-Items. `quiz_settings.php` schreibt heute in drei getrennten Schritten `quiz`, `grade_items` und `course_modules` selbst. Vor allem aber löst sie **kein `course_module_updated`** aus und wäre damit für den Änderungsverlauf (ADR 0018) ein blinder zweiter Mechanismus.
- **Backup/Restore als Schreibweg** (ein Vollvehikel über `.mbz`): verworfen nach Recherche #347. Kein Core-Webservice erzeugt oder nimmt eine `.mbz`, Restore ist ausnahmslos `INSERT` (legt also immer neue Objekte an, statt bestehende zu ändern), MDL-47776 ist *Won't Do*. Ein Verlauf darüber wäre eine Folge jeweils neuer Objekte gewesen — also gar keine Versionierung im brauchbaren Sinn.
- **Beide Wege nebeneinander**, je nachdem was billiger ist: verworfen, weil genau das der heutige Zustand ist und weil jede Ausnahme die Frage „schnappt der Verlauf hier mit?" einzeln neu stellt.

## Consequences

- 21 der 38 lokalen Schreibwerkzeuge lösen sich in vier Endpunkte plus einen Feldkatalog auf; ~3.100 PHP-Zeilen entfallen. Jeder gesparte Endpunkt spart zugleich vier Registrierungseinträge (`db/services.php`, `dispatcher::TOOL_DESCRIPTIONS`, `dispatcher::TOOL_SCHEMAS`, `privacy_surface::ALLOWED_TOOLS`).
- Das schlimmste bekannte Fehlerbild der Karte verschwindet: kaputtes `availability`-JSON macht die Kursseite unaufrufbar (`availability/classes/info.php:138-143`) — über `update_moduleinfo()` ist das Feld gar nicht kaputtschreibbar. Es entsteht ausschließlich auf dem heutigen Direkt-DB-Weg.
- Der Preis ist eine Feldliste je Modultyp. Moodle prüft auf dem Schreibweg selbst nichts (`course/modlib.php` enthält keine Validierung; der Kern *setzt voraus*, dass ein Formular geprüft hat) und liefert für Wertebereiche keine einheitliche Quelle. Der Katalog ist überwiegend abgeschrieben und veraltet still — deshalb ADR 0017.
- `quiz` bleibt Einzelwerkzeug: dort decken sich Feldnamen nicht mit Spaltennamen, `grade` ist über den Formularweg nicht änderbar, und die Substanz liegt in `quiz_slots`. Kurspilots Sonderbehandlung ist damit keine Altlast, sondern eine begründete Ausnahme.
- `data_postprocessing()` und `validation()` laufen ohne Formular weiterhin nicht (`$mform = null`, wie in Moodles eigenem `create_module()`/`update_module()`). Was sie leisten, bildet der Adapter **einmal** nach und der Katalog trägt es als Pseudofelder und Kombinationsregeln — nicht je Modultyp neu.
