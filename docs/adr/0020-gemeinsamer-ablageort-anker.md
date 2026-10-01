# Gemeinsamer Ablageort-Anker statt zweier paralleler Klassen

`context_files` (Kontextbereich, Spec 0016) und `material_files` (Materialordner, Spec 0018)
waren zwei fast identische Anker für je einen Unterordner in Moodles Private Files. 13
Methodennamen waren gemeinsam, neun davon bis auf Fehlerschlüssel und Wurzel-Einstellungsname
byte-identisch, drei weitere bereits Delegationen von `material_files` an `context_files`
(`remaining_quota()`, `replace()`). Genau eine Methode trug echte, bereichsspezifische Policy:
die Namensregel beim Schreiben (`resolve_writable_file()` — `.md`-Whitelist vs.
Endungs-Whitelist).

Die Auflage aus Spec 0018 §2.3 — „ein späterer Umzug ändert nur diese Klasse" — war je Klasse
für sich erfüllt, im Ganzen aber verfehlt: ein Umzug an einen anderen Ablageort (z. B. ein
angebundenes Repository) hätte zwei Klassen ändern müssen, nicht eine.

Wir entscheiden: Ort, eigener Nutzerkontext, Wurzelauflösung, Segmentprüfung, Verzeichnis-/
Dateiauflösung in beide Richtungen, Recht auf die eigenen Dateien, Restquote, Quotenprüfung,
Dateisatz und die Schreibchoreografie mit Zwischendatei liegen ab jetzt einmal, in
`storage_anchor`. Ein Bereich (`storage_area`) ist ein reiner Wertesatz — Wurzel-
Einstellungsname, Standardwurzel, Namensregel beim Schreiben (als Closure, die bei Verstoß
selbst die passende `moodle_exception` mit ihrem eigenen Fehlerschlüssel wirft),
Fehlerschlüssel für ungültige Pfade und für Quotenüberschreitung — kein eigener Typ, keine
eigene Klassenhierarchie.

`context_files` und `material_files` bleiben unter ihren heutigen Namen bestehen und behalten
ihre komplette öffentliche Schnittstelle (Konstanten, Methodennamen, Signaturen, geworfene
Fehlerschlüssel) unverändert bei — sie sind jetzt dünne Bereichsdefinitionen, die intern an
`storage_anchor` delegieren. Kein Aufrufer der rund 20 Dateien, die heute auf einen der beiden
Bereiche zugreifen, wird angefasst.

## Considered Options

- **Ortsadapter mit gemeinsamer Schnittstelle** (Interface `storage_location`, mit
  `context_files`/`material_files` als Implementierungen, ggf. später ein
  `repository_location`): saubere Erweiterbarkeit, falls ein zweiter, andersartiger
  Ablageort (Repository statt Moodle-Dateibereich) hinzukommt. Preis: ein Interface für
  aktuell eine einzige tatsächlich existierende Speicherform (Moodle Private Files) —
  spekulative Verallgemeinerung ohne zweiten Abnehmer. Abgelehnt (YAGNI).

- **Gemeinsames Modul + Bereich als Wertesatz** (diese Entscheidung): löst die Verdopplung
  auf, ohne eine Abstraktion für eine hypothetische zweite Speicherform vorwegzunehmen. Der
  Zweitort-Beweis in `tests/storage_anchor_test.php` (ein dritter, nur im Test definierter
  Bereich) zeigt, dass `storage_anchor` bereits jetzt bereichsunabhängig arbeitet — ohne dass
  dafür ein Interface nötig wäre.

- **Alles beim Alten** (zwei parallele Klassen): geringstes Risiko kurzfristig, aber die
  Verdopplung bleibt bei jeder künftigen Änderung an Ort/Quote/Schreibchoreografie bestehen
  und die Spec-0018-§2.3-Auflage bleibt im Ganzen verfehlt.

## Consequences

- Ein späterer Umzug an ein angebundenes Repository ändert `storage_anchor` an einer Stelle
  statt `context_files` und `material_files` an zweien — die eigentliche Auflage aus
  Spec 0018 §2.3.
- Sobald ein zweiter, strukturell andersartiger Ablageort real existiert (nicht nur im Test),
  ist der richtige Zeitpunkt, über einen Ortsadapter mit eigener Schnittstelle neu zu
  entscheiden — nicht vorher.
- `docs/specs/0016-kontextbereich-schreibend.md` §1 trägt einen Vermerk: die dortige
  Isolationsbegründung (Komponente/Dateibereich/Itembezug/Kontext) gilt nur für Moodle-
  Dateibereiche; ein Repository-Ablageort bräuchte ein Pfadpräfix und eine neue
  Datenschutzbewertung, weil er die Deckung durch den Core-Privacy-Provider verlässt.

## Nachtrag (Issue #445): zweistufige Auflösung über den Kontextpointer

`storage_anchor::root()` löst seither zweistufig auf: zuerst die per Einstellung konfigurierte
Standardwurzel (unverändert), dann — falls im festen Anker (`storage_anchor::ANCHOR_ROOTSETTING`/
`ANCHOR_DEFAULT_ROOT`, identisch mit der Wurzel-Einstellung des Kontextbereichs) eine Datei namens
`storage_anchor::POINTER_FILENAME` liegt — der dort für den jeweiligen Bereich genannte
tatsächliche Ort (`storage_area::$pointerkey`). Fehlt die Datei, gilt unverändert die
Standardwurzel; ist sie vorhanden, aber kein gültiges JSON-Objekt, unvollständig (beide Felder
`kontextbereich`/`materialordner` sind Pflicht, unabhängig davon, welcher Bereich gerade
auflöst) oder nennt sie einen Pfad mit `.`/`..`-Segment, wirft die Auflösung eine benannte
`moodle_exception` statt still auf den Standard zurückzufallen — siehe
`tests/storage_anchor_test.php` für alle vier Ränder (fehlend, gültig, unlesbar/unvollständig,
unerreichbar).

Die Pointer-Auflösung liest nur, wenn `$USER->id` gesetzt ist (kein DB-Zugriff ohne angemeldete
Person) — das hält `resolve_directory()`/`resolve_file()` für Aufrufer ohne echten Login
weiterhin rein pfadbasiert, genau wie vor diesem Issue.

Nicht Teil dieses Nachtrags: ein Schreibendpunkt für den Pointer (bleibt Handarbeit über "Meine
Dateien") und die Ortswahl im OAuth-Zustimmungsdialog (Spec #442 §3, eigenes Vorhaben).
