---
name: kontextbereich
description: Lies diese Datei, wenn eine Arbeitsdatei der Lehrkraft (plan.md, status.md, Journal, Materialnotizen, Kontextprofile) gelesen, geschrieben oder angehaengt werden soll.
---

# Referenz: Kontextbereich

Arbeitsdateien (`plan.md`, `status.md`, Journal, Materialnotizen,
Kontextprofile) liegen serverseitig im Kontextbereich der Lehrkraft. Es gibt
keinen lokalen Dateipfad und keinen lokal auszufuehrenden Code — jede
Arbeitsdatei-Operation laeuft ausschliesslich ueber die vier Werkzeuge unten.
Grundlage: Spec 0016 §7/§8 (`docs/specs/0016-kontextbereich-schreibend.md`).

## Werkzeuge

| Tool | Zweck | Antwort enthaelt |
|---|---|---|
| `kurspilot_list_context_files` | Ordnerinhalt auflisten, optional `vorheriger_ort` (Altbestand) | je Eintrag `contenthash`, `timemodified`, `locked` |
| `kurspilot_read_context_file` | Datei lesen, optional `vorheriger_ort` (Altbestand) | `content`, `contenthash`, `timemodified` |
| `kurspilot_write_context_file` | Anlegen/vollstaendig ueberschreiben, optional `expected_contenthash`, optional `ausstand` (Kennung), optional `nur_anlegen` (Kopieren aus dem Altbestand) | Meldung "neu angelegt" / "ueberschrieben"; bei Konflikt Fehler `contextfilechanged`/`contextfilealreadyexists` |
| `kurspilot_append_context_file` | Anhaengen in einem Serveraufruf, kein `expected_contenthash` (kein vorheriges Lesen noetig), optional `ausstand` (Kennung) | Meldung "angehaengt" / "neu angelegt", ggf. Rotationshinweis |
| `kurspilot_dismiss_ausstand` | Einen Eintrag der Ausstandsnotiz ausdruecklich verwerfen (`kennung`) | Bestaetigung |
| `kurspilot_dismiss_altbestand` | Den Altbestand (vorheriger Ort) ausdruecklich beenden, kein Parameter | Bestaetigung |

Nur `.md`-Dateien; Pfadsegmente `[A-Za-z0-9_-]`, kein `.`/`..`.

## Altbestand (vorheriger Ort)

`kurspilot_list_skills` nennt ohne Zaehlung den Fakt "Altbestand offen", wenn
nach einem Ortswechsel des Kontextbereichs am fruheren Ort noch
Kontextdateien liegen. `kurspilot_list_context_files`/`kurspilot_read_context_file`
mit `vorheriger_ort: true` lesen diesen alten Ort — nur lesend, nie
schreibend. Zum Kopieren: gelesenen Inhalt per `kurspilot_write_context_file`
mit `nur_anlegen: true` an den neuen Ort schreiben — legt nur an, ueberschreibt
nie. Nach dem Kopieren (oder wenn die Lehrkraft auf den Rest verzichtet)
`kurspilot_dismiss_altbestand` aufrufen, um ihn ausdruecklich zu beenden. Der
Altbestand endet nie von selbst durch Zeitablauf oder Namensgleichheit.

## Ablageordnung — Wurzel und relative Pfade (Spec 0012 §5, Spec 0010)

Der Kontextbereich hat **eine** Wurzel. Wie ihr Ordner heisst und wo er
liegt, loest das Plugin selbst auf — aus seiner Einstellung, oder aus dem
Ablageort, den die Lehrkraft beim Verbindungsaufbau gewaehlt hat. Der
Wurzelname ist damit nichts, was hier festgeschrieben werden koennte, und
nichts, was ein Werkzeugaufruf kennen muesste.

**Jeder Pfad, den ein Werkzeug bekommt, ist relativ zu dieser Wurzel** —
`fragetypen/match.md`, nie mit einem Wurzelordner davor. Ein vorangestellter
Wurzelname legt die Datei eine Ebene zu tief ab (`<wurzel>/<wurzel>/…`) und
ist immer ein Fehler. Dasselbe gilt fuer die Rueckgaben: der `path` einer
Auflistung ist ebenfalls relativ zur Wurzel, die Wurzel selbst ist der leere
Pfad.

An der Wurzel liegen:

| Eintrag | Was |
|---|---|
| `index.md` | globale Uebersicht ueber die Vorhaben (Spec 0010) |
| `vorlagen.md` | gemerkte Aktivitaetsvorlagen (Spec 0013/0012 §5) |
| `fragetypen/` | ein `<fragetyp>.md` je erschlossenem Fragetyp (`kurspilot_get_skill("fragetypen")`) |
| `<schuljahr>/<klasse-oder-lerngruppe>/<fach>/<vorhaben>/` | die eigentliche Arbeitsablage: Profile, `plan.md`, `status.md`, Journal, Material |

Neue Ablageorte kommen an die Wurzel oder in einen Vorhabenordner — kein
zweiter, thematisch sortierter Ordnerbaum.

## Schreibangebot fuer plan/status/vorlagen (Spec 0016 §8.2)

`plan.md`, `status.md`, Vorlagen und Profildateien werden nie still
geschrieben. An natuerlichen Haltepunkten (Planungsrunde abgeschlossen,
Freigabe erteilt) fasst Kurspilot das Vereinbarte zusammen und fragt, ob es
jetzt per `kurspilot_write_context_file` geschrieben werden soll. Erst nach
Bestaetigung wird geschrieben. Nichts Vereinbartes bleibt ungeschrieben liegen.

## Journal-Append unter der Sitzungs-Kontextfreigabe (Spec 0016 §8.1)

Journal-Eintraege sind davon ausdruecklich ausgenommen: Sie laufen automatisch
per `kurspilot_append_context_file`, sobald die einmalige
Sitzungs-Kontextfreigabe (siehe `CONTEXT.md`, Glossareintrag "Kontextfreigabe")
zu Sitzungsbeginn erteilt ist — keine Einzelbestaetigung je Eintrag.

## Handaenderungs-Routine (Spec 0016 §7)

Die Lehrkraft kann jede Datei jederzeit in "Meine Dateien" selbst bearbeiten.
Kurspilot merkt sich je gelesener Datei den zuletzt gesehenen `contenthash`
und prueft ihn:

1. **Bei Sitzungsstart**, fuer alle Dateien, die diese Sitzung voraussichtlich
   braucht: `kurspilot_list_context_files` (oder erneutes
   `kurspilot_read_context_file`) gegen den zuletzt bekannten Stand
   vergleichen.
2. **Vor jedem Schreibvorgang** (write und append) erneut, unmittelbar bevor
   geschrieben wird.

Weicht der `contenthash` ab: Datei neu lesen, der Lehrkraft die Aenderung
kurz benennen ("Die Datei wurde seit dem letzten Lesen extern geaendert") und
fragen, ob mit dem neuen Stand weitergearbeitet werden soll, bevor irgendetwas
geschrieben wird. Kein Verlauf alter Versionen — nur der zuletzt gelesene
`contenthash` wird vorgehalten.

Bei `kurspilot_write_context_file` zusaetzlich technisch abgesichert: den
zuletzt gelesenen `contenthash` als `expected_contenthash` mitgeben. Bricht
der Server mit `contextfilechanged` ab, ist das derselbe Fall — neu lesen,
nachfragen. `kurspilot_append_context_file` kennt kein
`expected_contenthash` (kein vorheriges Lesen im Vertrag); die Skill-seitige
Pruefung vor dem Aufruf bleibt hier die einzige Absicherung.

## Journal-Rotation (Spec 0016 §8.4)

Antwortet `kurspilot_append_context_file` mit dem Zusatz "... ueberschreitet
1 MB — Rotation empfohlen" (Wortlaut laut Plugin: "Die Datei ueberschreitet
1 MB — Rotation empfohlen."), legt Kurspilot **nicht**
automatisch eine neue Datei an. Es benennt den Hinweis der Lehrkraft und
schlaegt einen Archivnamen vor (z.B. `journal-2026-06.md` fuer das laufende
Archiv, neu `journal-2026-07.md`). Stimmt die Lehrkraft zu:

1. Neue Journaldatei per `kurspilot_write_context_file` anlegen (leer oder mit
   Header).
2. Kuenftige Appends fuer diesen Kontext auf die neue Datei umstellen.
3. Die bisherige Datei bleibt unveraendert liegen — kein Loeschen, kein
   Zusammenfuehren.

## Lerndatei: ersetzen statt anhaengen (Spec 0020 §7)

Eine Lerndatei (`fragetypen/<typ>.md` — feste Gliederung, Schreibregel siehe
`kurspilot_get_skill("fragetypen")` — sowie `vorlagen.md`) darf sonst zu
Schicht auf Schicht wachsen: Anhaengen fuehlt sich sicher an, Loeschen
riskant, und der Kontext wird mit jeder Sitzung teurer und widerspruechlicher.

Deshalb geht eine neue Erkenntnis in den **vorhandenen Abschnitt** und ersetzt
dort die schwaechere Formulierung, statt inhaltlich ans Dateiende angehaengt
zu werden. Geschrieben wird technisch ohnehin immer per
`kurspilot_write_context_file` (Vollersatz, siehe Schreibregel in
`kurspilot_get_skill("fragetypen")`) — "Anhaengen" meint hier den Inhalt, nicht
das Werkzeug. Inhaltlich blindes Anhaengen ist der Ausnahmefall (z. B.
`vorlagen.md`, das als freie Liste ohne feste Gliederung gefuehrt wird und wo
ein neuer Eintrag deshalb regulaer dazukommt statt einen Abschnitt zu
ersetzen) und wird als solcher benannt, wenn er eintritt.

Vor jeder Ergaenzung einer Lerndatei gilt dieselbe Pruefung wie fuer den
Skill-Korpus selbst (Spec 0020 §8):

> Ändert diese Zeile gegenüber dem Default Verhalten, und sagt sie etwas, das
> nicht schon woanders steht?

Eine Zeile, die diese Pruefung nicht besteht, wird nicht geschrieben — weder
neu noch als Ersatz.

## Verdichtungsangebot bei wachsender Lerndatei (Spec 0020 §7)

`kurspilot_write_context_file` und `kurspilot_append_context_file` melden bei
jedem Schreibvorgang die neue Dateigroesse (`size`, in Byte). Bei einer
Lerndatei ist diese Pruefung bei jeder Ergaenzung das Arbeitsmittel — nicht
erst die 1-MB-Grenze aus Spec 0016 §5.2, die der harte Fangnetzwert bleibt.
Waechst eine Lerndatei spuerbar, bietet Kurspilot an, sie zu verdichten
(Dopplungen, veraltete Stolpersteine oder ueberholte Ausbaustufen
zusammenfassen) — analog zur Journal-Rotation, aber als Angebot statt als
Umbenennung: Die Lehrkraft entscheidet, ob und wann verdichtet wird.

## Keine Klarnamen in unmarkierten Dateien (Spec 0016 §8.3)

Schuelernamen, Schueler-IDs und anderer Personenbezug gehoeren ausschliesslich
in Dateien mit Frontmatter `kurspilot.personenbezug: true`. Das Plugin prueft
nur die Markierung (Schreibsperre bei ausgeschaltetem #344-Schalter), nicht
den Inhalt — die Klarnamen-Grenze selbst ist reine Skill-Regel:

- Vor jedem Schreiben/Anhaengen mit Personenbezug pruefen, ob die Zieldatei
  bereits `kurspilot.personenbezug: true` traegt; falls nicht, das
  Frontmatter beim naechsten `kurspilot_write_context_file` ergaenzen statt
  Klarnamen unmarkiert abzulegen.
- Ist eine Datei nicht markiert und der Inhalt braucht Personenbezug, entweder
  die Markierung ergaenzen (mit Lehrkraftfreigabe, da das den #344-Schalter
  aktiviert) oder anonymisiert/pseudonymisiert schreiben (Kuerzel statt Name).

## Ausfall am externen Speicher: Ausstand und Nachtragen (ADR 0023, Issue #492)

Scheitert ein gueltiger `kurspilot_write_context_file`/`kurspilot_append_context_file`-
Aufruf am externen Speicher, der Verbindung oder dem Ort (nicht bei einem
Konflikt), legt das Plugin selbst einen Eintrag in der **Ausstandsnotiz** an
und meldet eine Kennung — der Inhalt liegt nirgendwo, es gibt keinen
Rueckfall. Die Antwort nennt Pfad und Vorgang, die Ursache und die Kennung.

- Den Inhalt im Gespraech behalten, nicht verwerfen.
- Sobald die Verbindung wieder steht, denselben Aufruf erneut senden, diesmal
  mit `ausstand=<Kennung>` — gelingt er, verschwindet der Eintrag im selben
  Aufruf (**Nachtragen**).
- `kurspilot_list_skills` meldet zu Sitzungsbeginn offene Eintraege im Feld
  `ausstaende`, gebuendelt je Zieldatei. Soll ein Eintrag nicht mehr
  nachgetragen werden, vorher eine Rekonstruktion anbieten (z.B. aus dem
  Aenderungsverlauf einer Aktivitaet), dann erst `kurspilot_dismiss_ausstand`
  aufrufen — nie ohne ausdrueckliches Wort der Lehrkraft.
- "Ausstand" ist ein interner Bezeichner; zur Lehrkraft heisst es "noch nicht
  gespeichert".

## Aufraeumfrage nach Aufbau (Spec 0018 §8.3, Issue #439)

Am Ende eines abgeschlossenen Aufbaus (mindestens ein Moodle-Schreibzugriff
dieser Sitzung abgeschlossen, kein offener Blocker) ruft `kurspilot-umsetzen`
einmal `kurspilot_report_loose_material_files` auf und prueft die Antwort:

- **`files` ist leer:** keine Frage. Nichts liegt lose, also gibt es nichts zu
  entscheiden.
- **`files` ist nicht leer:** fragt aktiv, ohne dass die Lehrkraft danach
  fragen muss, z.B.: *„Im Material liegen noch 3 Dateien (4,2 MB), die in
  keiner Aktivitaet verwendet werden: `altes-blatt.pdf` (1,1 MB, 40 Tage),
  `entwurf.png` (0,3 MB, 12 Tage), `screenshot-quelle.jpg` (2,8 MB, 3 Tage —
  Original eines bereits eingebetteten Zuschnitts). Loeschen?"* — Anzahl,
  Gesamtgroesse (`total_size`, in Byte geliefert, fuer die Anzeige in MB
  umrechnen) und jede einzelne Datei mit Pfad und Groesse werden genannt,
  nicht nur die Zahl.
- Ist `remaining_quota_mb` gesetzt und knapp (Restplatz niedrig gemessen an
  dem, was diese Sitzung an Uploads/Zuschnitten gesehen hat, oder eine
  Quotenwarnung ist in dieser Sitzung bereits bei einem Schreibzugriff
  aufgetreten — Form wie Spec 0016 §5.4/§8.1: Warnung unter 10 % Restplatz,
  Restplatz in MB), nennt die Frage zusaetzlich den Restplatz, z.B. „…
  loeschen? Aktuell nur noch 8,4 MB Restplatz."

Geloescht wird ausschliesslich auf ausdrueckliche Antwort ("ja", eine
Teilauswahl der genannten Dateien o.ae.) per `kurspilot_delete_material_files`
mit genau den bestaetigten Pfaden — nie automatisch, keine Altersregel als
Loeschgrund. Eine Ablehnung oder keine Antwort loescht nichts; die Dateien
bleiben liegen, ohne dass die Frage in derselben Sitzung wiederholt wird.

Diese Regel ist eine Skill-Regel, kein Serververhalten (Spec 0016 §7: „der
Server hat kein Session-Konzept"), und gilt daher unveraendert fuer jeden
Client, der `kurspilot-umsetzen` ausfuehrt — Claude Desktop wie Codex.

## Was hier nicht gilt

Ein lokaler Arbeitsbereich, eine lokale Konfigurationsdatei oder lokal
auszufuehrender Code gelten fuer den Kontextbereich nicht — es gibt keinen
lokalen Pfad, den sie aufloesen koennten. Planstrenge, Ein-Plan-Regel und
Statuspruefung vor Schreibzugriff (siehe `kurspilot_get_skill("kurspilot-core")`,
Ankerbegriffe) gelten inhaltlich unveraendert weiter, nur das *wie* des
Lesens/Schreibens der Arbeitsdateien laeuft ausschliesslich ueber diese vier
Tools.
