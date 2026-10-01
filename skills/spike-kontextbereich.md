# Referenz: Kontextbereich ueber das native Server-MCP (Spike)

Diese Datei gilt nur fuer `spike-planen`/`spike-umsetzen` gegen das native
Plugin `local_coursepilot` (Branch `moodle-native-mcp`), **nicht** fuer die
produktiven `kurspilot-*`-Skills. Dort liegen Arbeitsdateien lokal auf der
Festplatte (Arbeitsbereich-Regel, siehe `skills/kurspilot-core.md`); hier
liegen sie serverseitig im Kontextbereich. Es gibt keinen lokalen Dateipfad
und keinen `lib/kurspilot-arbeitsbereich.js`-Zugriff — jede
Arbeitsdatei-Operation laeuft ausschliesslich ueber die vier Webservice-Tools
unten. Grundlage: Spec 0016 §7/§8 (`docs/specs/0016-kontextbereich-schreibend.md`).

## Werkzeuge

| Tool | Zweck | Antwort enthaelt |
|---|---|---|
| `coursepilot_list_context_files` | Ordnerinhalt auflisten | je Eintrag `contenthash`, `timemodified`, `locked` |
| `coursepilot_read_context_file` | Datei lesen | `content`, `contenthash`, `timemodified` |
| `coursepilot_write_context_file` | Anlegen/vollstaendig ueberschreiben, optional `expected_contenthash` | Meldung "neu angelegt" / "ueberschrieben"; bei Konflikt Fehler `contextfilechanged` |
| `coursepilot_append_context_file` | Anhaengen in einem Serveraufruf, kein `expected_contenthash` (kein vorheriges Lesen noetig) | Meldung "angehaengt" / "neu angelegt", ggf. Rotationshinweis |

Nur `.md`-Dateien; Pfadsegmente `[A-Za-z0-9_-]`, kein `.`/`..`.

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
| `fragetypen/` | ein `<fragetyp>.md` je erschlossenem Fragetyp (`spike-fragetypen.md`) |
| `<schuljahr>/<klasse-oder-lerngruppe>/<fach>/<vorhaben>/` | die eigentliche Arbeitsablage: Profile, `plan.md`, `status.md`, Journal, Material |

Neue Ablageorte kommen an die Wurzel oder in einen Vorhabenordner — kein
zweiter, thematisch sortierter Ordnerbaum.

## Schreibangebot fuer plan/status/vorlagen (Spec 0016 §8.2)

`plan.md`, `status.md`, Vorlagen und Profildateien werden nie still
geschrieben. An natuerlichen Haltepunkten (Planungsrunde abgeschlossen,
Freigabe erteilt) fasst Kurspilot das Vereinbarte zusammen und fragt, ob es
jetzt per `coursepilot_write_context_file` geschrieben werden soll. Erst nach
Bestaetigung wird geschrieben. Nichts Vereinbartes bleibt ungeschrieben liegen.

## Journal-Append unter der Sitzungs-Kontextfreigabe (Spec 0016 §8.1)

Journal-Eintraege sind davon ausdruecklich ausgenommen: Sie laufen automatisch
per `coursepilot_append_context_file`, sobald die einmalige
Sitzungs-Kontextfreigabe (siehe `CONTEXT.md`, Glossareintrag "Kontextfreigabe")
zu Sitzungsbeginn erteilt ist — keine Einzelbestaetigung je Eintrag.

## Handaenderungs-Routine (Spec 0016 §7)

Die Lehrkraft kann jede Datei jederzeit in "Meine Dateien" selbst bearbeiten.
Kurspilot merkt sich je gelesener Datei den zuletzt gesehenen `contenthash`
und prueft ihn:

1. **Bei Sitzungsstart**, fuer alle Dateien, die diese Sitzung voraussichtlich
   braucht: `coursepilot_list_context_files` (oder erneutes
   `coursepilot_read_context_file`) gegen den zuletzt bekannten Stand
   vergleichen.
2. **Vor jedem Schreibvorgang** (write und append) erneut, unmittelbar bevor
   geschrieben wird.

Weicht der `contenthash` ab: Datei neu lesen, der Lehrkraft die Aenderung
kurz benennen ("Die Datei wurde seit dem letzten Lesen extern geaendert") und
fragen, ob mit dem neuen Stand weitergearbeitet werden soll, bevor irgendetwas
geschrieben wird. Kein Verlauf alter Versionen — nur der zuletzt gelesene
`contenthash` wird vorgehalten.

Bei `coursepilot_write_context_file` zusaetzlich technisch abgesichert: den
zuletzt gelesenen `contenthash` als `expected_contenthash` mitgeben. Bricht
der Server mit `contextfilechanged` ab, ist das derselbe Fall — neu lesen,
nachfragen. `coursepilot_append_context_file` kennt kein
`expected_contenthash` (kein vorheriges Lesen im Vertrag); die Skill-seitige
Pruefung vor dem Aufruf bleibt hier die einzige Absicherung.

## Journal-Rotation (Spec 0016 §8.4)

Antwortet `coursepilot_append_context_file` mit dem Zusatz "... ueberschreitet
1 MB — Rotation empfohlen" (Wortlaut laut Plugin: "Die Datei ueberschreitet
1 MB — Rotation empfohlen."), legt Kurspilot **nicht**
automatisch eine neue Datei an. Es benennt den Hinweis der Lehrkraft und
schlaegt einen Archivnamen vor (z.B. `journal-2026-06.md` fuer das laufende
Archiv, neu `journal-2026-07.md`). Stimmt die Lehrkraft zu:

1. Neue Journaldatei per `coursepilot_write_context_file` anlegen (leer oder mit
   Header).
2. Kuenftige Appends fuer diesen Kontext auf die neue Datei umstellen.
3. Die bisherige Datei bleibt unveraendert liegen — kein Loeschen, kein
   Zusammenfuehren.

## Keine Klarnamen in unmarkierten Dateien (Spec 0016 §8.3)

Schuelernamen, Schueler-IDs und anderer Personenbezug gehoeren ausschliesslich
in Dateien mit Frontmatter `kurspilot.personenbezug: true`. Das Plugin prueft
nur die Markierung (Schreibsperre bei ausgeschaltetem #344-Schalter), nicht
den Inhalt — die Klarnamen-Grenze selbst ist reine Skill-Regel:

- Vor jedem Schreiben/Anhaengen mit Personenbezug pruefen, ob die Zieldatei
  bereits `kurspilot.personenbezug: true` traegt; falls nicht, das
  Frontmatter beim naechsten `coursepilot_write_context_file` ergaenzen statt
  Klarnamen unmarkiert abzulegen.
- Ist eine Datei nicht markiert und der Inhalt braucht Personenbezug, entweder
  die Markierung ergaenzen (mit Lehrkraftfreigabe, da das den #344-Schalter
  aktiviert) oder anonymisiert/pseudonymisiert schreiben (Kuerzel statt Name).

## Aufraeumfrage nach Aufbau (Spec 0018 §8.3, Issue #439)

Am Ende eines abgeschlossenen Aufbaus (mindestens ein Moodle-Schreibzugriff
dieser Sitzung abgeschlossen, kein offener Blocker) ruft `spike-umsetzen`
einmal `coursepilot_report_loose_material_files` auf und prueft die Antwort:

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
Teilauswahl der genannten Dateien o.ae.) per `coursepilot_delete_material_files`
mit genau den bestaetigten Pfaden — nie automatisch, keine Altersregel als
Loeschgrund. Eine Ablehnung oder keine Antwort loescht nichts; die Dateien
bleiben liegen, ohne dass die Frage in derselben Sitzung wiederholt wird.

Diese Regel ist eine Skill-Regel, kein Serververhalten (Spec 0016 §7: „der
Server hat kein Session-Konzept"), und gilt daher unveraendert fuer jeden
Adapter, der `spike-umsetzen` ausfuehrt — Claude Desktop wie Codex.

## Was hier nicht gilt

Arbeitsbereich-Regel, lokale Konfigurationsdatei, `lib/kurspilot-arbeitsbereich.js`
und alle darin gebuendelten lokalen Module gelten fuer den nativen Weg nicht —
es gibt keinen lokalen Pfad, den sie aufloesen koennten. Planstrenge,
Ein-Plan-Regel und Statuspruefung vor Schreibzugriff (siehe
`skills/kurspilot-core.md`, Ankerbegriffe) gelten inhaltlich unveraendert
weiter, nur das *wie* des Lesens/Schreibens der Arbeitsdateien ist ersetzt
durch diese vier Tools.
