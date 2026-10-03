# PROTOTYP – Aktivität per Backup-XML anlegen (Wegwerf-Code)

Primärquelle für die geplante Aktivitätstyp-Ablage. Nicht deployen, nicht in `moodle-native-mcp` übernehmen.
Lauf: Spike, Moodle 5.0.8, Kurs 38 „PROTOTYPE MBZ (wipe me)“, 2026-10-01.

```bash
C=moodle-kurspilot-spike-webserver-1
docker cp proto.php $C:/tmp/proto/ && docker cp samples $C:/tmp/proto/ && docker exec $C chmod -R 777 /tmp/proto
docker exec -u www-data $C php /tmp/proto/proto.php setup glossary book        # Frage 2
docker exec -u www-data $C php /tmp/proto/proto.php export <cmid> /tmp/proto/exp/x
docker exec -u www-data $C php /tmp/proto/proto.php synth book /tmp/proto/samples/book.xml /tmp/proto/syn/book
docker exec -u www-data $C php /tmp/proto/proto.php restore /tmp/proto/syn/book
```

## Frage 1: Lässt sich eine KI-gebaute Backup-XML zuverlässig wiederherstellen?

**Ja, mit drei Bedingungen.**

- Entpackter Ordner unter `tempdir/backup/<id>` reicht, kein ZIP. `restore_controller` mit `MODE_IMPORT` und `TARGET_CURRENT_ADDING`.
- **Die KI liefert nur `<mod>.xml`.** Die übrigen 16 Dateien sind leeres Gerüst oder leicht erzeugbar (`moodle_backup.xml`, `module.xml`); das Plugin baut sie (`proto_synth`). Fehlt eine davon, bricht der Restore ab (nur `competencies.xml`, `grade_history.xml`, `inforef.xml`, `completion.xml` sind optional).
- Ergebnis von Hand verfasster XML (`samples/`): book mit 3 Kapiteln inkl. Unterkapitel ✓, checklist mit 3 Lehrkraft-Punkten (optional/Einrückung/Farbe) ✓, Intro-HTML ✓, Zielabschnitt über `sectionnumber` ✓.

Bedingungen:

1. **Inhalte, die Moodle als Nutzerdaten sichert, gehen nicht.** `MODE_IMPORT` sperrt `users=0`. Betroffen u. a. **Glossar-Einträge** (nur unter `userinfo` gesichert und wiederhergestellt) → das Glossar wird leer angelegt. Je Typ prüfen; Abhilfe für Glossar: Einträge danach über `mod_glossary_add_entry`.
2. **Restore ist nicht atomar.** Bei Fehlern bleiben halbe Aktivitäten zurück: kaputte XML → Buch mit allen 3 Kapiteln trotz Fehlermeldung; falscher Datentyp (`numbering=abc`) → `course_modules` mit `instance=0`. Pflicht: XML vor dem Restore parsen und alle neu entstandenen cm bei Fehler mit `course_delete_module` entfernen.
3. **Moodle schluckt Fehler still.** Fehlendes Feld → DB-Standardwert; unbekanntes Feld → ignoriert. Pflicht: Round-Trip-Prüfung nach dem Restore (neue Aktivität exportieren, mit der Eingabe vergleichen), analog zur Fragetyp-Prüfung.

## Frage 2: Aktivität beliebigen Typs mit Standardwerten anlegen, ohne Code je Typ?

**Ja.** 9/9 Typen (glossary, book, checklist, h5pactivity, lesson, wiki, data, feedback, workshop) über `prepare_new_moduleinfo_data` → `mod_<x>_mod_form` → `_defaultValues` → `add_moduleinfo`. Drei allgemeine Kniffe, alle in `proto_create_default`:

- Standardwerte aus `_defaultValues` lesen, nicht `exportValues()` (bricht bei `modvisible` ohne Absendung).
- Editor-Felder ohne Standard leer befüllen (sonst scheitert feedback an `page_after_submit`).
- `data_postprocessing()` aufrufen.

Bei Fehlern im Instanz-Insert bleibt die Transaktion offen → im Werkzeug zurückrollen.
Danach `export` → so bekommt die KI eine echte, versionsgenaue Vorlage für jeden installierten Typ.

## Frage 3: Was müsste beim „Ersetzen“ (neue cm, alte gelöscht) umgehängt werden?

| Verweis auf die alte cmid | Aufwand | Spike-Bestand |
|---|---|---|
| Voraussetzungen anderer Aktivitäten/Abschnitte | gering: Core-API `core_availability\info::update_dependency_id_across_course()` | 46 cm mit cm-Verweisen |
| Kursabschluss-Kriterium Aktivität | gering (eine Tabelle) | 0 |
| Notenbuch: Kategorie, Berechnungen `##gi…##` | mittel | 0 Berechnungen |
| Links in Texten (`view.php?id=`) | hoch, unscharf (alle Textfelder des Kurses) | 2 Seiten |
| **Fremd-Plugins** (learningmap `linkedActivity`, checklist `moduleid`, …) | **nicht allgemein lösbar** | 5 Lernkarten |
| Kurspilot-Verlauf (ADR 0018, Anker cmid) | mittel: Kette „ersetzt durch“ | – |

Harter Befund: `mod_learningmap` setzt beim Löschen der verknüpften cm `linkedActivity = null` (`classes/autoupdate.php:87`). Ersetzen zerstört Lernkarten-Verknüpfungen still.
**Empfehlung:** Welle 1 = anlegen + alte verstecken, nie löschen. Ersetzen erst mit Prüfung „wer verweist auf diese cmid“ und Abbruch bei unbekannten Verweisen.
