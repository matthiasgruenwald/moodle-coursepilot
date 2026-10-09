# ADR 0031 — Quiz-lokale Fragenkategorien

Status: angenommen für #690.

## Kontext

Lehrkräfte möchten Fragen zuerst im eigenen Quiz anlegen und später in eine
benannte Kurs-Fragensammlung übernehmen. Moodle hat dafür einen eigenen
Aktivitätskontext, initialisiert dessen Standardkategorie aber erst bei Bedarf.
Der bisherige Kategorienzugang bleibt auf `mod_qbank` begrenzt.

## Entscheidung

`coursepilot_ensure_quiz_question_categories(courseid, cmid)` validiert genau ein
Quiz im angegebenen Kurs, prüft `local/coursepilot:use` im Kurs und Quiz sowie
`moodle/question:managecategory` und `moodle/question:viewall` im Quiz. Es stellt
über Moodles Core-Funktion die Standardkategorie bereit und liefert `contextid`,
`defaultcategoryid` und `categories` (`id`, `name`, `parent`). Neu angelegte
Kategorien lösen Moodle-Ereignisse aus. Das Werkzeug ist als schreibend registriert,
weil die erste Auflösung Kategorien anlegen kann.

Die bestehenden Kategorien- und Fragewerkzeuge verwenden anschließend diese IDs.
Es entsteht kein zweiter Schreibkern und kein allgemeiner Modulkontextzugang.
`get_question_categories` und die Bereinigungsplanung für benannte Fragensammlungen
bleiben unverändert.

## Folgen

Die benannte Kurs-Fragensammlung bleibt der Standard. Quiz-lokale Ablage ist eine
bewusste Alternative der Lehrkraft. XML-Transfer folgt weiterhin ADR 0015 und
Spec 0017: neue Sammlung bedeutet einen eigenen Stand, Reimport dort eine neue
Version desselben Eintrags. Die erste Übernahme einer nicht im Ziel bekannten
idnumber folgt dem bestehenden Verdachtsfall-Gate und benötigt explizite
Bestätigung. `move_question` nutzt ausschließlich Moodle-Core und
bewahrt die Fragenidentität samt Versionen und Quiz-Referenzen.
