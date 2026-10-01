# Englische Basis, Produktname Coursepilot, Komponente `local_coursepilot`

Für den Umbau zum Servermodell waren nie Produktgrundsätze festgelegt worden. Das fiel erst
auf, als die Veröffentlichung im [Moodle Marketplace](https://marketplace.moodle.com/) zum
Ziel wurde (Architektur-Review 18./19.09.2026). Drei Fragen hingen zusammen und werden hier
gemeinsam entschieden: Sprache, Produktname, Komponentenname.

## Entscheidung

**1. Englisch ist die Basis, Deutsch eine Übersetzung.** Der Marketplace verlangt, dass nur
englische Strings ausgeliefert werden; Übersetzungen laufen nach der Freigabe über AMOS. Das
betrifft aber mehr als `lang/`: Der **Werkzeugvertrag** — Parameternamen, Rückgabeschlüssel,
Werkzeugbeschreibungen — und die Klassennamen sind ebenfalls englisch. Heute sind 10 von 144
Parametern deutsch (`ort`, `felder_json`, `nur_anlegen`, `bestaetigt`, …), dazu
Rückgabeschlüssel wie `meldung` (56×) und `ort` (22×), rund 1.450 Zeilen deutscher
Werkzeugbeschreibungen und vier deutsche Klassennamen.

**2. Der Skill-Korpus bleibt vorerst deutsch.** Er ist Prosa für Lehrkräfte, kein String und
von AMOS nicht erfasst. Zweisprachigkeit (`skills/en/`, `skills/de/` mit Auswahl über die
Moodle-Sprache) ist das Ziel, aber ein eigener Schnitt. Für die Einreichung genügt, dass das
Listing es benennt.

**3. Das Produkt heißt Coursepilot, die Komponente `local_coursepilot`.** Anzeigename und
Frankenstyle-Name fallen zusammen. `local_kurspilot` war der Name des Neubaus während der
Bauzeit und wird vor dem Produktivbetrieb abgelöst.

## Considered Options

- **Nur `lang/` englisch, Werkzeugvertrag gemischt lassen:** abgelehnt. Ein Plugin, dessen
  Werkzeuge `ort` und `meldung` zurückgeben, ist international nicht brauchbar, und der
  Vertrag ist nach der Veröffentlichung nur noch mit Bruch änderbar. Der Umbau fällt
  ohnehin größtenteils in die Werkzeug-Registrierung, die im Kandidaten B geöffnet wird.
- **Auch den Skill-Korpus sofort übersetzen:** abgelehnt. Er trägt den laufenden Unterricht;
  ihn vor dem ersten Produktivmonat anzufassen riskiert die Substanz, die funktioniert.
- **Name Kurspilot behalten, Anzeigename Coursepilot:** abgelehnt. Zwei Namen für eine Sache
  sind genau der Umweg, den der Umbau beseitigen soll.
- **Komponente `local_kurspilot` behalten:** abgelehnt, solange die Umbenennung billig ist.
  Sie ist nach der Einreichung endgültig; plugineigene Tabellen machen sie mit jedem Tag
  Produktivbetrieb teurer.

## Consequences

- Die Komponente `local_coursepilot` trägt heute das Altplugin (Release 1.0.54, produktiv
  auf der 5.0-Instanz). Zwei Plugins mit demselben Frankenstyle-Namen können nicht auf einer
  Instanz liegen: Das Neue läuft auf der Spike-Instanz, das Alte auf der 5.0-Instanz, bis
  der Schnitt fällt. Auf der 5.0-Instanz ist das Alte vor einer Installation des Neuen zu
  deinstallieren — Datenmigration gibt es nicht (Spec 0003 hatte dasselbe Verfahren schon
  für `local_aicoursecreator` vorgesehen).
- Der Quellbaum des Altplugins zieht nach `legacy/local_coursepilot/` um, damit der Pfad
  frei wird, und wird erst beim Schnitt gelöscht — er versorgt bis dahin ein Arbeitsmittel,
  das in Benutzung ist.
- Die neue Linie beginnt bei Release `2.0.0`: Coursepilot 2 ist der Nachfolger von
  Coursepilot 1, nicht ein zweites Produkt. Die Maturity bleibt `MATURITY_ALPHA`, bis der
  Produktivbetrieb sie widerlegt.
- Spec 0003 (Marketplace-Readiness) und Spec 0012 §10 sind überholt: Spec 0003 beschreibt
  den alten Weg über das Plugins Directory samt Spiegelrepository, und das Plugins Directory
  ist in den Marketplace übergegangen.
