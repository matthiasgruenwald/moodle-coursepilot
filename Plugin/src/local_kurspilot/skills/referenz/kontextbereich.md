---
name: kontextbereich
description: Lies diese Datei, wenn eine Arbeitsdatei der Lehrkraft (plan.md, status.md, Journal, Materialnotizen, Kontextprofile) gelesen, geschrieben oder angehängt werden soll.
---

# Referenz: Kontextbereich

Arbeitsdateien (`plan.md`, `status.md`, Journal, Materialnotizen,
Kontextprofile) liegen serverseitig im Kontextbereich der Lehrkraft. Es gibt
keinen lokalen Dateipfad und keinen lokal auszuführenden Code — jede
Arbeitsdatei-Operation läuft ausschließlich über die vier Werkzeuge unten.
Grundlage: Spec 0016 §7/§8 (`docs/specs/0016-kontextbereich-schreibend.md`).

## Werkzeuge

| Tool | Zweck | Antwort enthält |
|---|---|---|
| `kurspilot_list_context_files` | Ordnerinhalt auflisten, optional `vorheriger_ort` (Altbestand) | je Eintrag `contenthash`, `timemodified`, `locked` |
| `kurspilot_read_context_file` | Datei lesen, optional `vorheriger_ort` (Altbestand) | `content`, `contenthash`, `timemodified` |
| `kurspilot_write_context_file` | Anlegen/vollständig überschreiben, optional `expected_contenthash`, optional `ausstand` (Kennung), optional `nur_anlegen` (Kopieren aus dem Altbestand) | Meldung "neu angelegt" / "überschrieben"; bei Konflikt Fehler `contextfilechanged`/`contextfilealreadyexists` |
| `kurspilot_append_context_file` | Anhängen in einem Serveraufruf, kein `expected_contenthash` (kein vorheriges Lesen nötig), optional `ausstand` (Kennung) | Meldung "angehängt" / "neu angelegt", ggf. Rotationshinweis |
| `kurspilot_dismiss_ausstand` | Einen Eintrag der Ausstandsnotiz ausdrücklich verwerfen (`kennung`) | Bestätigung |
| `kurspilot_dismiss_altbestand` | Den Altbestand (vorheriger Ort) ausdrücklich beenden, kein Parameter | Bestätigung |

Nur `.md`-Dateien; Pfadsegmente `[A-Za-z0-9_-]`, kein `.`/`..`.

## Offene Ortswahl (Issue #494)

Solange die Lehrkraft noch keinen Ort gewählt hat und die Schule externe
Speicher freigeschaltet hat, liefert `kurspilot_list_skills` im Feld
`hinweise` einen Satz mit Link zur Ortswahlseite. Diesen Satz **genau einmal
je Sitzung** an die Lehrkraft weitergeben. Antwortet sie mit "später" (oder
sinngemäß), in derselben Sitzung nicht erneut ansprechen — eine offene
Ortswahl sperrt ohnehin nichts, Kurspilot arbeitet einfach weiter.

## Altbestand (vorheriger Ort)

`kurspilot_list_skills` nennt im selben Feld `hinweise` — nach den
`ausstände` gemeldet, also erst wenn offene Ausstände schon benannt sind —
ohne Zählung den Fakt "Altbestand offen", wenn nach einem Ortswechsel des
Kontextbereichs am früheren Ort noch Kontextdateien liegen.
`kurspilot_list_context_files`/`kurspilot_read_context_file` mit
`vorheriger_ort: true` lesen diesen alten Ort — nur lesend, nie schreibend
(**Nur-Lese-Schalter**). Zum Kopieren: gelesenen Inhalt per
`kurspilot_write_context_file` mit `nur_anlegen: true` an den neuen Ort
schreiben — legt nur an, überschreibt nie. Nach dem Kopieren (oder wenn die
Lehrkraft auf den Rest verzichtet) `kurspilot_dismiss_altbestand` aufrufen,
um ihn ausdrücklich zu beenden. Der Altbestand endet nie von selbst durch
Zeitablauf oder Namensgleichheit.

**Das Altbestandsangebot:**

- Anzahl der am alten Ort liegenden Dateien nennen (aus
  `kurspilot_list_context_files` mit `vorheriger_ort: true`), dann **eine**
  Bestätigung für alle einholen — nicht Datei für Datei fragen.
  Uebernommen wird nur nach dieser Bestätigung.
- Existiert eine Datei am neuen Ort bereits (`contextfilealreadyexists`),
  wird sie **nicht** überschrieben; die übersprungenen Namen der Lehrkraft
  nennen, statt sie stillschweigend auszulassen.
- Löschen ist Sache der Lehrkraft, nie von Kurspilot. Das **einmal** sagen
  (in "Meine Dateien" bzw. der eigenen Cloud) — nicht bei jedem weiteren
  Altbestand-Kontakt derselben Sitzung wiederholen.
- Geht der Weg zurück nach Moodle (externe Quelle → Kontextbereich in
  Moodle), vor dem Kopieren die Größe des Altbestands als **reinen
  Faktenvergleich** nennen (z.B. "der Altbestand ist 3,4 MB, in Moodle sind
  aktuell 8 MB frei") — Moodle hat eine Quote, der externe Speicher praktisch
  nicht. Das ist eine Information, keine Empfehlung gegen den Umzug.

## Ablageordnung — Wurzel und relative Pfade (Spec 0012 §5, Spec 0010)

Der Kontextbereich hat **eine** Wurzel. Wie ihr Ordner heißt und wo er
liegt, löst das Plugin selbst auf — aus seiner Einstellung, oder aus dem
Ort, den die Lehrkraft auf der Ortswahlseite gewählt hat (siehe „Offene
Ortswahl" oben) — unabhängig davon, wie die KI-Anbindung selbst eingerichtet
wurde. Der Wurzelname ist damit nichts, was hier festgeschrieben werden
könnte, und nichts, was ein Werkzeugaufruf kennen müsste.

**Jeder Pfad, den ein Werkzeug bekommt, ist relativ zu dieser Wurzel** —
`fragetypen/match.md`, nie mit einem Wurzelordner davor. Ein vorangestellter
Wurzelname legt die Datei eine Ebene zu tief ab (`<wurzel>/<wurzel>/…`) und
ist immer ein Fehler. Dasselbe gilt für die Rückgaben: der `path` einer
Auflistung ist ebenfalls relativ zur Wurzel, die Wurzel selbst ist der leere
Pfad.

An der Wurzel liegen:

| Eintrag | Was |
|---|---|
| `index.md` | globale Uebersicht über die Vorhaben (Spec 0010) |
| `vorlagen.md` | gemerkte Aktivitätsvorlagen (Spec 0013/0012 §5) |
| `fragetypen/` | ein `<fragetyp>.md` je erschlossenem Fragetyp (`kurspilot_get_skill("fragetypen")`) |
| `<schuljahr>/<klasse-oder-lerngruppe>/<fach>/<vorhaben>/` | die eigentliche Arbeitsablage: Profile, `plan.md`, `status.md`, Journal, Material |

Neue Ablageorte kommen an die Wurzel oder in einen Vorhabenordner — kein
zweiter, thematisch sortierter Ordnerbaum.

## Schreibangebot für plan/status/vorlagen (Spec 0016 §8.2)

`plan.md`, `status.md`, Vorlagen und Profildateien werden nie still
geschrieben. An natürlichen Haltepunkten (Planungsrunde abgeschlossen,
Freigabe erteilt) fasst Kurspilot das Vereinbarte zusammen und fragt, ob es
jetzt per `kurspilot_write_context_file` geschrieben werden soll. Erst nach
Bestätigung wird geschrieben. Nichts Vereinbartes bleibt ungeschrieben liegen.

## Journal-Append unter der Sitzungs-Kontextfreigabe (Spec 0016 §8.1)

Journal-Einträge sind davon ausdrücklich ausgenommen: Sie laufen automatisch
per `kurspilot_append_context_file`, sobald die einmalige
Sitzungs-Kontextfreigabe (siehe `CONTEXT.md`, Glossareintrag "Kontextfreigabe")
zu Sitzungsbeginn erteilt ist — keine Einzelbestätigung je Eintrag.

## Handänderungs-Routine (Spec 0016 §7)

Die Lehrkraft kann jede Datei jederzeit an ihrem Ablageort selbst bearbeiten
— in Moodle über "Meine Dateien", am externen Speicher über dessen eigene
Oberfläche. Kurspilot merkt sich je gelesener Datei den zuletzt gesehenen
`contenthash` und prüft ihn:

1. **Bei Sitzungsstart**, für alle Dateien, die diese Sitzung voraussichtlich
   braucht: `kurspilot_list_context_files` (oder erneutes
   `kurspilot_read_context_file`) gegen den zuletzt bekannten Stand
   vergleichen.
2. **Vor jedem Schreibvorgang** (write und append) erneut, unmittelbar bevor
   geschrieben wird.

Weicht der `contenthash` ab: Datei neu lesen, der Lehrkraft die Aenderung
kurz benennen ("Die Datei wurde seit dem letzten Lesen extern geändert") und
fragen, ob mit dem neuen Stand weitergearbeitet werden soll, bevor irgendetwas
geschrieben wird. Kein Verlauf alter Versionen — nur der zuletzt gelesene
`contenthash` wird vorgehalten.

Bei `kurspilot_write_context_file` zusätzlich technisch abgesichert: den
zuletzt gelesenen `contenthash` als `expected_contenthash` mitgeben. Bricht
der Server mit `contextfilechanged` ab, ist das derselbe Fall — neu lesen,
nachfragen. `kurspilot_append_context_file` kennt kein
`expected_contenthash` (kein vorheriges Lesen im Vertrag); die Skill-seitige
Prüfung vor dem Aufruf bleibt hier die einzige Absicherung.

## Journal-Rotation (Spec 0016 §8.4)

Antwortet `kurspilot_append_context_file` mit dem Zusatz "... überschreitet
1 MB — Rotation empfohlen" (Wortlaut laut Plugin: "Die Datei überschreitet
1 MB — Rotation empfohlen."), legt Kurspilot **nicht**
automatisch eine neue Datei an. Es benennt den Hinweis der Lehrkraft und
schlägt einen Archivnamen vor (z.B. `journal-2026-06.md` für das laufende
Archiv, neu `journal-2026-07.md`). Stimmt die Lehrkraft zu:

1. Neue Journaldatei per `kurspilot_write_context_file` anlegen (leer oder mit
   Header).
2. Künftige Appends für diesen Kontext auf die neue Datei umstellen.
3. Die bisherige Datei bleibt unverändert liegen — kein Löschen, kein
   Zusammenführen.

## Lerndatei: ersetzen statt anhängen (Spec 0020 §7)

Eine Lerndatei (`fragetypen/<typ>.md` — feste Gliederung, Schreibregel siehe
`kurspilot_get_skill("fragetypen")` — sowie `vorlagen.md`) darf sonst zu
Schicht auf Schicht wachsen: Anhängen fühlt sich sicher an, Löschen
riskant, und der Kontext wird mit jeder Sitzung teurer und widersprüchlicher.

Deshalb geht eine neue Erkenntnis in den **vorhandenen Abschnitt** und ersetzt
dort die schwächere Formulierung, statt inhaltlich ans Dateiende angehängt
zu werden. Geschrieben wird technisch ohnehin immer per
`kurspilot_write_context_file` (Vollersatz, siehe Schreibregel in
`kurspilot_get_skill("fragetypen")`) — "Anhängen" meint hier den Inhalt, nicht
das Werkzeug. Inhaltlich blindes Anhängen ist der Ausnahmefall (z. B.
`vorlagen.md`, das als freie Liste ohne feste Gliederung geführt wird und wo
ein neuer Eintrag deshalb regulär dazukommt statt einen Abschnitt zu
ersetzen) und wird als solcher benannt, wenn er eintritt.

Vor jeder Ergänzung einer Lerndatei gilt dieselbe Prüfung wie für den
Skill-Korpus selbst (Spec 0020 §8):

> Ändert diese Zeile gegenüber dem Default Verhalten, und sagt sie etwas, das
> nicht schon woanders steht?

Eine Zeile, die diese Prüfung nicht besteht, wird nicht geschrieben — weder
neu noch als Ersatz.

## Verdichtungsangebot bei wachsender Lerndatei (Spec 0020 §7)

`kurspilot_write_context_file` und `kurspilot_append_context_file` melden bei
jedem Schreibvorgang die neue Dateigröße (`size`, in Byte). Bei einer
Lerndatei ist diese Prüfung bei jeder Ergänzung das Arbeitsmittel — nicht
erst die 1-MB-Grenze aus Spec 0016 §5.2, die der harte Fangnetzwert bleibt.
Wächst eine Lerndatei spürbar, bietet Kurspilot an, sie zu verdichten
(Dopplungen, veraltete Stolpersteine oder überholte Ausbaustufen
zusammenfassen) — analog zur Journal-Rotation, aber als Angebot statt als
Umbenennung: Die Lehrkraft entscheidet, ob und wann verdichtet wird.

## Keine Klarnamen in unmarkierten Dateien (Spec 0016 §8.3)

Schülernamen, Schüler-IDs und anderer Personenbezug gehören ausschließlich
in Dateien mit Frontmatter `kurspilot.personenbezug: true`. Das Plugin prüft
nur die Markierung (Schreibsperre bei ausgeschaltetem #344-Schalter), nicht
den Inhalt — die Klarnamen-Grenze selbst ist reine Skill-Regel:

- Vor jedem Schreiben/Anhängen mit Personenbezug prüfen, ob die Zieldatei
  bereits `kurspilot.personenbezug: true` trägt; falls nicht, das
  Frontmatter beim nächsten `kurspilot_write_context_file` ergänzen statt
  Klarnamen unmarkiert abzulegen.
- Ist eine Datei nicht markiert und der Inhalt braucht Personenbezug, entweder
  die Markierung ergänzen (mit Lehrkraftfreigabe, da das den #344-Schalter
  aktiviert) oder anonymisiert/pseudonymisiert schreiben (Kürzel statt Name).

## Wenn der Speicher nicht antwortet (ADR 0023, Issue #492/#495)

Der Kontextbereich kann in Moodle oder an einem externen WebDAV-Speicher der
Lehrkraft liegen (Ortswahl, siehe unten) — was folgt, gilt für beide
gleichermaßen und benennt nie den Ort.

### Ausstand und Nachtragen (Schreibausfall)

Scheitert ein gültiger `kurspilot_write_context_file`/`kurspilot_append_context_file`-
Aufruf am Speicher, der Verbindung oder dem Ort (nicht bei einem Konflikt und
nicht bei einem Aufruffehler wie der `.md`-Regel oder der Personenbezug-Sperre),
legt das Plugin selbst einen Eintrag in der **Ausstandsnotiz** an und meldet
eine Kennung — der Inhalt liegt nirgendwo, es gibt keinen Rückfall. Die
Antwort nennt Pfad und Vorgang, die Ursache und die Kennung.

- Den Inhalt im Gespräch behalten, nicht verwerfen. Die Anweisung an die KI:
  **keinen anderen Ort nehmen** — also nicht ausweichend in eine andere
  Kontextdatei oder ein anderes Verzeichnis schreiben, auch nicht
  vorübergehend.
- Sobald die Verbindung wieder steht, denselben Aufruf erneut senden, diesmal
  mit `ausstand=<Kennung>` — gelingt er, verschwindet der Eintrag im selben
  Aufruf (**Nachtragen**). Ein Nachtragen überschreibt nie einen inzwischen
  gewachsenen Bestand.
- `kurspilot_list_skills` meldet zu Sitzungsbeginn offene Einträge im Feld
  `ausstände`, gebündelt je Zieldatei. Soll ein Eintrag nicht mehr
  nachgetragen werden: **erst** eine Rekonstruktion anbieten, wo eine
  möglich ist (z.B. aus dem Aenderungsverlauf einer Aktivität), **danach
  erst** `kurspilot_dismiss_ausstand` aufrufen — nie ohne ausdrückliches
  Wort der Lehrkraft.
- "Ausstand" ist ein interner Bezeichner; zur Lehrkraft heißt es "noch nicht
  gespeichert", nie "Ausstand".

### Kontext-Lücke (Leseausfall)

Ist der Kontextbereich nicht lesbar, obwohl Moodle antwortet, ist das eine
**Kontext-Lücke** — kein Ausstand, denn es ist nichts verloren gegangen:

- Einmal je Sitzung ausdrücklich ansagen, dass gerade ohne Journal, Profile
  und Plan gearbeitet wird — danach nicht wiederholen.
- Weiterplanen im Gespräch bleibt erlaubt, ebenso das Schreiben in Moodle.
- Gesperrt ist nur, was an einer ungelesenen Datei hängt: Soll ein
  **gespeicherter** Plan umgesetzt werden und ist er gerade nicht lesbar,
  nicht aus der Erinnerung umsetzen, sondern die Lücke benennen und auf das
  erneute Lesen warten.
- Ein Plan, der im selben Gespräch entstanden und freigegeben ist (also nie
  gelesen werden musste), trägt die Umsetzung trotzdem.

### Konflikt beim Schreiben

Meldet `kurspilot_write_context_file` einen Konflikt (`contextfilechanged`;
am externen Speicher dieselbe Fehlerklasse "Konflikt"): die Datei neu lesen,
die Aenderungen mit dem eigenen Stand zusammenführen und erst dann erneut
schreiben. Kein Ausstand, kein Aufgeben.

### Quotenfehler

Scheitert ein Schreibvorgang am Speicherplatz (`contextquotaexceeded`), steht
in der Fehlermeldung bereits ein Verweis auf die Ortswahlseite — diesen Satz
an die Lehrkraft weitergeben, statt selbst einen Ausweg zu erfinden.

## Aufräumfrage nach Aufbau (Spec 0018 §8.3, Issue #439)

Am Ende eines abgeschlossenen Aufbaus (mindestens ein Moodle-Schreibzugriff
dieser Sitzung abgeschlossen, kein offener Blocker) ruft `kurspilot-umsetzen`
einmal `kurspilot_report_loose_material_files` auf und prüft die Antwort:

- **`files` ist leer:** keine Frage. Nichts liegt lose, also gibt es nichts zu
  entscheiden.
- **`files` ist nicht leer:** fragt aktiv, ohne dass die Lehrkraft danach
  fragen muss, z.B.: *„Im Material liegen noch 3 Dateien (4,2 MB), die in
  keiner Aktivität verwendet werden: `altes-blatt.pdf` (1,1 MB, 40 Tage),
  `entwurf.png` (0,3 MB, 12 Tage), `screenshot-quelle.jpg` (2,8 MB, 3 Tage —
  Original eines bereits eingebetteten Zuschnitts). Löschen?"* — Anzahl,
  Gesamtgröße (`total_size`, in Byte geliefert, für die Anzeige in MB
  umrechnen) und jede einzelne Datei mit Pfad und Größe werden genannt,
  nicht nur die Zahl.
- Ist `remaining_quota_mb` gesetzt und knapp (Restplatz niedrig gemessen an
  dem, was diese Sitzung an Uploads/Zuschnitten gesehen hat, oder eine
  Quotenwarnung ist in dieser Sitzung bereits bei einem Schreibzugriff
  aufgetreten — Form wie Spec 0016 §5.4/§8.1: Warnung unter 10 % Restplatz,
  Restplatz in MB), nennt die Frage zusätzlich den Restplatz, z.B. „…
  löschen? Aktuell nur noch 8,4 MB Restplatz."

Gelöscht wird ausschließlich auf ausdrückliche Antwort ("ja", eine
Teilauswahl der genannten Dateien o.ae.) per `kurspilot_delete_material_files`
mit genau den bestätigten Pfaden — nie automatisch, keine Altersregel als
Löschgrund. Eine Ablehnung oder keine Antwort löscht nichts; die Dateien
bleiben liegen, ohne dass die Frage in derselben Sitzung wiederholt wird.

Diese Regel ist eine Skill-Regel, kein Serververhalten (Spec 0016 §7: „der
Server hat kein Session-Konzept"), und gilt daher unverändert für jeden
Client, der `kurspilot-umsetzen` ausführt — Claude Desktop wie Codex.

## Materialbestand: `ort`, Eintragstyp `kontextbereich` und Sperre (Issue #495)

Die lesenden Materialwerkzeuge (`kurspilot_list_material_files`,
`kurspilot_preview_material_file`, die Quelle von `kurspilot_crop_material_file`,
Materialpfade bei `kurspilot_create_module`/`kurspilot_update_module_settings`)
nehmen den Parameter `ort` mit den Werten `bestand` (Standard, der gewachsene
Materialordner der Lehrkraft — nur gelesen) und `werkbank` (Chat-Anhänge,
Zuschnitte — hier wird auch geschrieben). Liegt der Materialbestand in
Moodle, zeigen beide Werte auf denselben Ort; schreibende Materialwerkzeuge
kennen `ort` nicht, sie zielen immer auf die Werkbank.

Liegt der Kontextbereich innerhalb des Materialbestands, erscheint sein
Ordner beim Auflisten (`ort: bestand`) als eigener Eintragstyp
`kontextbereich`, nicht als `folder` — sichtbar, aber über die Materialwege
nicht zu betreten. Ein Versuch, einen Pfad darin oder darunter zu lesen oder
aufzulisten, scheitert mit einer benannten Sperrmeldung
(`materialpathiskontext`), die auf `kurspilot_list_context_files`/
`kurspilot_read_context_file` verweist — dorthin umlenken, nicht selbst einen
Workaround suchen.

## Planen an einem nicht zugelassenen Speicher

Liegt der Kontextbereich an einem externen Speicher, den die Schule nicht als
**zugelassenen Speicher** für personenbezogene Daten führt (ADR 0021 §3),
scheitert ein Schreiben mit `kurspilot.personenbezug: true` ausdrücklich
("Dieser Speicher ist für personenbezogene Daten nicht zugelassen") — ein
Aufruffehler, kein Ausstand. Geplant wird trotzdem weiter, nur ohne
Klarnamen in der Datei:

- Kürzel statt Klarnamen verwenden (wie in "Keine Klarnamen in unmarkierten
  Dateien" oben).
- Einzelheiten, die ohne Personenbezug nicht sinnvoll sind, weglassen statt
  erzwungen zu anonymisieren.
- Wo eine vollständige Notiz nicht ohne Klarnamen geht, in geringerem Detail
  schreiben statt gar nicht.
- **Lerngruppenprofile entstehen an einem solchen Speicher trotzdem** — als
  gewöhnliche, unmarkierte Kontextdatei mit Kürzeln statt Namen. Sie
  bleiben nur inhaltlich schwächer, nicht ungeschrieben.

## Was hier nicht gilt

Ein lokaler Arbeitsbereich, eine lokale Konfigurationsdatei oder lokal
auszuführender Code gelten für den Kontextbereich nicht — es gibt keinen
lokalen Pfad, den sie auflösen könnten. Planstrenge, Ein-Plan-Regel und
Statusprüfung vor Schreibzugriff (siehe `kurspilot_get_skill("kurspilot-core")`,
Ankerbegriffe) gelten inhaltlich unverändert weiter, nur das *wie* des
Lesens/Schreibens der Arbeitsdateien läuft ausschließlich über diese vier
Tools.
