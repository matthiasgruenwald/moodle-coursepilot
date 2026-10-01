# Änderungsverlauf für Aktivitäten im Notenbuch-Muster

Die Freigabe von KI-Schreibvorgängen liegt clientseitig im Chat (Spec 0015 §9.1); ein serverseitiges Freigabe-Tor war ausdrücklich ausgeschlossen. Das Gegengewicht dazu ist ein **Änderungsverlauf in der Datenbank**. Moodle bietet dafür nichts zum Übernehmen: `tool_recyclebin` ist ein Undo fürs *Löschen* und holt als Kopie zurück, `local_courseversion` verspricht Rollback ohne Inhalt zu speichern, im Kern gibt es außer `question_versions` keine `*_versions`-Tabelle, im Tracker nur MDL-85149 (*Deferred*). Wir entscheiden: **Vollstand je Schreibvorgang in eine Schattentabelle, verankert an der stabilen `cmid`; die Rückkehr zu einem alten Stand wird Version N+1, nie ein Rückspulen.** Vorbild ist `grade_object::update()` aus dem Notenbuch — Vollkopie ohne Indirektion.

## Considered Options

- **Fragenbank-Muster** (Moodles native Fragenversionierung, ADR 0001): nicht übertragbar. Es lebt von `question_references` als Indirektionsschicht; bei Aktivitäten zeigt alles direkt auf die Instanz-ID, es gibt also nichts, wohin man eine Version einhängen könnte.
- **Aktivität duplizieren als Sicherung**: verworfen, und zwar hart. Genau so sind in der Praxis Kurse mit hunderten Aktivitäten entstanden, die nur die Lehrkraft von Hand wieder loswird. Der Verlauf liegt in der Datenbank und ist im Kurs unsichtbar.
- **Diffs statt Vollstände**: verworfen. ~1 KB gzip je Version (Quiz-Anordnung gemessen ~0,4 KB) macht Speicherplatz zum Nicht-Thema, während eine Diff-Kette jeden Lesevorgang von der Unversehrtheit aller Vorgänger abhängig macht. Das Diff wird beim Ansehen berechnet.
- **Rückspulen** (alten Stand als aktuellen Stand wiederherstellen und spätere Versionen verwerfen): verworfen zugunsten des Fortschreibens. Fortschreiben hält die `cmid` stabil, erzeugt keine sichtbaren Kopien und macht den Rückweg selbst zu einem protokollierten Schreibvorgang.
- **Eigenständiges `tool_`-Plugin von Anfang an**: verworfen als Startform, aber als Zielform vorbereitet — ein zweiter Deployment-Pfad vor der ersten Zeile Nutzen wäre verfrüht.

## Consequences

- Aufhänger ist `course_module_updated`, dazu 16 `mod_quiz`-Struktur-Ereignisse für die Anordnung. Der Verlauf schnappt damit auch **Handänderungen** der Lehrkraft mit, nicht nur Kurspilot-Schreibvorgänge.
- **Der Verlauf ist nicht lückenlos**, und das wird ausgewiesen statt kaschiert: Quiz-Inhalt jenseits der Anordnung, Notenbuch, Restore und direkte DB-Schreibungen lösen kein Ereignis aus. Die Lücke ist erkennbar, nicht schließbar; Fangnetz ist der Vergleich geplanter Stand gegen Kurs-Ist. Dafür gibt es den Glossarbegriff „außerhalb des Verlaufs geändert".
- Bestandsaktivitäten haben kein Anlegen mehr. Findet der Beobachter für eine `cmid` keinen Stand, legt er zwei Versionen an — den Vorher-Stand als Version 1 mit `quelle: "vorgefunden"`, den neuen als Version 2. Kein Massen-Backfill beim Upgrade.
- Rückgeschrieben wird ausschließlich über `update_moduleinfo()` bzw. die Quiz-Struktur-API — kein eigener Schreibmechanismus, siehe ADR 0016. Drei Schutzschienen: `completionunlocked` nie automatisch (sonst Löschung der Vervollständigungsdaten der Lernenden), `quiz_has_attempts()` **vor** dem Rückschreiben geprüft statt als Ausnahme abgefangen, Frage-Referenzen treu wiederhergestellt ohne nachträgliches Pinnen.
- Aufbewahrung: Kurs-Kaskade („Kurs weg, Verlauf weg"), Aktivität gelöscht → Verlauf mitgelöscht, Löschfrist Standard 1 Jahr und admin-seitig einstellbar. „Keine Frist" ist ausgeschlossen.
- Herauslösbarkeit als eigenständiges Moodle-Plugin ist Nebenziel und formt die Bauform: keine Kurspilot-Begriffe im DB-Schema, `source`-Feld von Anfang an, der Beobachter serialisiert nur (keine MCP- oder Webservice-Aufrufe), Speicherung getrennt von der Oberfläche.
