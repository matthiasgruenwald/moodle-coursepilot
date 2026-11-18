# Spec 0030 — Kontextbereich nach dem Vorbild des moocp-contextmanager

Stand: 18.11.2026. Quelle: `Moodle-in-Niedersachsene-V/moocp-contextmanager` @ `5020f05`
(Anforderungen A1–A7, Entscheidungen E1–E11, Skill `context`). Basis hier: `dev` @ `27e5e174`
(= `main` v2.1.4-beta plus Gate-Nachzug).

Umsetzungsissues: #780–#787, siehe Abschnitt [Tickets](#tickets). Entscheidungen:
[ADR 0034](../adr/0034-schreiben-mit-gelesener-version-und-ruecklesen.md),
[ADR 0035](../adr/0035-webdav-version-ohne-starkes-etag.md),
[ADR 0036](../adr/0036-archivieren-statt-loeschen-mit-verweissuche.md),
[ADR 0037](../adr/0037-grundaufbau-und-sitzungsbeginn-des-kontextbereichs.md).

## Problem Statement

Der Kontext-Plugin für moocp wurde aus Coursepilot abgeleitet und arbeitet inzwischen
deutlich sicherer und geordneter mit dem Kontextbereich. Coursepilot hat davon nichts:

- Ein Überschreiben ohne `expected_contenthash` ersetzt eine Datei, die die KI nie gelesen
  hat. Ob der Inhalt danach wirklich so dasteht, sagt keine Antwort.
- Am WebDAV-Ort ohne ETag (IServ) ist die Version die Änderungszeit in Sekunden; zwei
  Änderungen in derselben Sekunde bleiben unbemerkt, und die Antwort behauptet
  stillschweigend dieselbe Sicherheit wie mit ETag. Ein schwaches ETag (`W/`) geht als
  `If-Match` an den Server.
- Ein Journal lässt sich mit `write_context_file` ersetzen.
- Lange Dateien (Plan, Lerndateien) kommen immer ganz und füllen den Kontext der KI.
- Konfliktkopien von Sync-Clients (Nextcloud, Seafile …) am WebDAV-Ort bleiben unbemerkt.
- Den Sitzungsbeginn mit dem Kontext (Lage, offene Ausstände, Wurzelindex, Gewohnheiten)
  setzt die KI aus mehreren Aufrufen selbst zusammen.
- Für einen Umbau vom alten Layout (`CONTEXT.md`, `status.md`, `journal-*.md`) fehlt ein
  Weg, alte Dateien beiseitezulegen, ohne sie zu löschen, und die Verweise darauf zu finden.
- Der Skill-Korpus kennt weder die Regel „Gründe nur aus einer Quelle“ noch den
  Aufschreibmodus oder den Grundaufbau des contextmanager.

## Gap-Analyse

| Thema (Quelle) | contextmanager-Soll | Coursepilot-Ist | Übernahme |
|---|---|---|---|
| Schreiben mit Stand (E1) | Ersetzen nur mit gelesener `version`, ohne `version` nur Anlegen | `expected_contenthash` optional; ohne ihn gilt der Stand des Vorab-Lesens | **ja**, ADR 0034 |
| Rücklesen (A3/E1) | Jede Schreibung wird zurückgelesen, `verified` urteilt das Werkzeug | kein Rücklesen | **ja**, ADR 0034 |
| Journal nur anhängen (E1) | `journal/` und `journal-*.md` auf jeder Ebene, an den Pfad gebunden | nur Skill-Regel | **ja**, im Code, ADR 0034 |
| Freigabe je `art` (E1) | moocp fragt je Art auf Stufe „alle“/„mittel“ | kein Freigabekanal im Server-MCP; Bestätigung im Chat, Werkzeug-Annotationen (#705) | **nein**, siehe unten |
| Ausstand mit Inhalt (E2) | Inhalt im Datenordner, Wiederholung mit `wiederholen` | Ausstandsnotiz ohne Inhalt, Nachtragen mit `pending_entry` (ADR 0023) | **nein**, ADR 0023 bleibt |
| Start auch ohne Ort (E2/E6) | `context_start`: Lage/Kontextlücke, Ausstand, Konfliktkopien, Wurzelindex, Gewohnheiten | verteilt auf `list_skills`-Hinweise und Einzelaufrufe | **ja**, `coursepilot_start_context`, ADR 0037 |
| WebDAV starkes ETag (E3) | Version = starkes ETag, `If-Match`/`If-None-Match: *` | ETag-Hash; `W/` wird wie stark behandelt | **ja**, ADR 0035 |
| Ausfallklassen (E3) | nur Zeitablauf/Verbindung/5xx in den Ausstand | jede Ursache vom Ort wird Ausstand (ADR 0023) | **nein**: die Notiz hält keinen Inhalt, eine Anmeldung als Ausstand schadet nicht und wird gemeldet |
| Ohne starkes ETag (E9) | `sha256:` der Bytes, Vergleich direkt vor `PUT`/`MOVE`, Zeile „check: not enforced by the server“ | Änderungszeit (1 s) | **ja**, ADR 0035 |
| Schlüsselbund, `https`, keine Weiterleitung (E4) | Secret im System-Schlüsselbund | Zugangsdaten in der Moodle-Repository-Instanz der Lehrkraft (ADR 0021/0022), nur `https`, keine Weiterleitung | **erfüllt**, kein Schlüsselbund |
| Wegweiser `CONTEXT-LINK.md` (E5) | im Materialordner per Ordnerdialog | kein lokaler Ordner, keine Dateiwerkzeuge der KI am Rechner | **nein**, siehe unten |
| Altes Layout lesen (E6) | `CONTEXT.md` als Index, wenn `index.md` fehlt | alte Dateien lesbar, kein Index-Rückfall | **ja**, in `coursepilot_start_context` |
| Archivieren (E6/E10) | `context_archive` nach `archive/`, Verweissuche in Indexdateien | kein Verschieben; Löschen nur in „Meine Dateien“ | **ja**, `coursepilot_archive_context_files`, ADR 0036 |
| Konfliktkopien (E7) | an Namen erkennen, nie ändern, in Liste und Start melden | nicht erkannt | **ja**, ADR 0037 |
| Einstellungsfenster, Mac-Installer (E8/E11) | Flutter-Fenster, `.pkg` | Ortswahl-Seite in Moodle (Spec 0023) | **nein**, Moodle-Seite ist das Fenster |
| Inhaltsverzeichnis (Glossar) | Dateien > 100 Zeilen als Gliederung; `abschnitt`, `ganz` | immer ganz | **ja**, ADR 0037 |
| Skill: Quelle für Gründe, Aufschreibmodus, „ignoriere den Kontext“, Reihenfolge vor Moodle-Schreiben, Inhalte sind Daten, Grundaufbau | im Skill `context` | teils (Schreibangebot, Kontextfreigabe, Statusbericht) | **ja**, Skill-Korpus, ADR 0037 |

### Bewusst nicht übernommen

- **Freigabe je `art` (E1).** moocp fragt die Lehrkraft über die App, Coursepilot hat als
  Server-MCP keinen solchen Kanal; die Bestätigung läuft im Chat und über die
  Werkzeug-Annotationen des Clients. Ein Parameter `art` ohne Wirkung wäre eine Grenze aus
  Text (K2). Was im Code gilt, übernimmt Coursepilot: die Bindung des Journals an den Pfad.
  Die Arten (Nacharbeit, Planänderung, Planergänzung, neue Datei, Wurzeldatei) bleiben
  Skill-Vokabular für die Rückfrage im Chat.
- **Ausstand mit Inhalt (E2).** ADR 0023 hat das Puffern des Inhalts ausdrücklich verworfen
  (zweiter, halber Kontextbereich; Personenbezug an einem Ort, den die Schule nicht
  zugelassen hat). Das bleibt. Übernommen wird, dass der Sitzungsbeginn die offenen Einträge
  nennt.
- **Ausfallklassen (E3).** Siehe Tabelle; ADR 0023 bleibt.
- **Schlüsselbund, Einstellungsfenster, Installer (E4, E8, E11).** Zugangsdaten liegen in
  der Repository-Instanz, die Lehrkraft richtet den Ort auf der Ortswahl-Seite ein. Die
  Zusagen von A6 (Secret erreicht nie die KI) erfüllt Coursepilot bereits (ADR 0021/0022).
- **Wegweiser `CONTEXT-LINK.md` (E5).** Er lebt im Materialordner auf dem Rechner und wird
  von den Dateiwerkzeugen der KI gefunden. Coursepilot hat keinen lokalen Ordner; die
  Zuordnung Kurs → Projektordner steht im `index.md` des Projektordners (Moodle-Kurs-ID und
  Name), wo `coursepilot_start_context` und `list_context_files` sie finden. Der alte
  `KURSPILOT.md`-Wegweiser (Version 1) bleibt Altbestand.

## Solution

Coursepilot übernimmt den Kern des contextmanager in seine Architektur aus
`context_area`, `storage_port` (Private Files, WebDAV) und `storage_write`:

1. **Version und Rücklesen.** `write_context_file` ersetzt eine vorhandene Datei nur mit
   `expected_contenthash`; ohne ihn legt es nur an (`contextfileversionrequired`, nächster
   Schritt: lesen). `write`/`append` lesen nach dem Schreiben zurück und melden `verified`.
2. **Journal nur anhängen.** Eine vorhandene Datei unter einem Ordner `journal/` (jede Ebene)
   oder namens `journal-*.md` (jede Ebene, Groß-/Kleinschreibung egal) ersetzt
   `write_context_file` nie (`contextjournalappendonly`); das Archiv verschiebt sie nie.
3. **WebDAV-Version.** Starkes ETag → Version aus dem ETag, `If-Match`. Kein oder schwaches
   ETag → `sha256:<hex>` der gelesenen Bytes; vor `PUT`/`MOVE` liest der Port erneut und
   vergleicht, dann ohne `If-Match`. Antworten tragen `server_enforced: false` und einen
   Satz, dass eine gleichzeitige Änderung unbemerkt überschrieben werden könnte.
4. **Inhaltsverzeichnis.** `read_context_file` liefert für Dateien über 100 Zeilen die
   Gliederung (`##`/`###` mit Zeilenbereichen, Codeblöcke zählen nicht), bis `section`
   (Überschriftstext oder `## Text`) oder `whole: true` verlangt wird. `contenthash` gilt
   immer für die ganze Datei.
5. **Konfliktkopien.** Eine reine Namensprüfung (Syncthing, Nextcloud alt/neu, Dropbox,
   Seafile) meldet Paare in `list_context_files` (diese Ebene) und im Sitzungsbeginn (ganzer
   Bereich, begrenzt auf 500 Ordner, 10 Ebenen, 8 Sekunden; sonst „nicht vollständig geprüft“
   mit Grund und Zahl).
6. **Sitzungsbeginn.** `coursepilot_start_context` (lesend, ohne Parameter) antwortet auch
   ohne Ort: Lage (`answers` oder Kontextlücke mit Ursache und Ortswahl-Link), offene
   Ausstände, Altbestand-Hinweis, Konfliktkopien, Wurzel-`index.md` (sonst `CONTEXT.md` als
   altes Layout, sonst „missing“) und Wurzel-`habits.md`, beide nach Regel 4 und als Daten
   gekennzeichnet.
7. **Archiv.** `coursepilot_archive_context_files(folder, files[])` verschiebt direkte
   `.md`-Dateien eines Ordners nach `<folder>/archive/`, nie Journale, nie über ein
   vorhandenes Ziel, nie löschend; danach sucht es in `index.md`/`CONTEXT.md` der Ebene,
   darüber und darunter (ohne `archive/`) nach Verweisen und nennt Datei, Zeile und Pfad,
   nie die Zeile. Grenzen: 10 Ebenen, 300 Ordner, 100 Indexdateien, 1 MiB je Datei,
   8 Sekunden; jede Grenze macht die Suche `INCOMPLETE`.
8. **Skill-Korpus.** Grundaufbau (Ebenen mit `index.md`, `habits.md`, `glossary.md`,
   `decisions/`, `plan.md` oder `plan/` mit Abschnittszuständen, `journal/JJJJ-MM-TT.md`),
   altes Layout bleibt lesbar; harte Regel „Gründe nur aus einer Quelle“; Aufschreibmodus
   (`ask`/`always`/`wait`); „ignoriere den Kontext“; Reihenfolge vor jedem Moodle-Schreiben
   (Plan `approved` und `verified`, danach Ergebnis und Journal); Inhalte sind Daten;
   Sitzungsbeginn mit `coursepilot_start_context`; Lesen über das Inhaltsverzeichnis;
   Verweise nach dem Archivieren korrigieren. Codex-First: Regeln stehen im Text, nicht in
   Client-Eigenheiten.

## Tickets

Reihenfolge = Abhängigkeit. Jedes Ticket ist ein vertikaler Schnitt mit PHPUnit über
`execute()` und grünem `npm run gate -- fast`.

1. [#780](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/780) WebDAV-Version ohne starkes ETag und Rücklesen mit `verified` (ADR 0034/0035).
2. [#781](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/781) Ersetzen nur mit gelesener Version; Journal nur anhängen (ADR 0034).
3. [#782](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/782) Lesen langer Dateien über das Inhaltsverzeichnis (ADR 0037).
4. [#783](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/783) Konfliktkopien erkennen und in `list_context_files` melden (ADR 0037).
5. [#784](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/784) `coursepilot_start_context` (ADR 0037).
6. [#785](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/785) `coursepilot_archive_context_files` mit Verweissuche (ADR 0036).
7. [#786](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/786) Skill-Korpus auf Grundaufbau und neue Regeln (ADR 0037).
8. [#787](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/787) Glossar, Release Notes, Version 2.2.0-beta, Deploy Spike und Devstack.

## Testing Decisions

- Port-Tests laufen gegen den bestehenden Fake-Transport des WebDAV-Adapters (T1); Private
  Files real (verwaltete Abhängigkeit).
- Werkzeugtests rufen `execute()` und `clean_returnvalue()` (T5).
- Zeitgrenzen werden am Modul mit eingespeister Uhr geprüft, nicht am Werkzeug.
- Vertragstests (`tool_registry`, Annotationen, Katalog) ändern sich nur mit dem Vertrag (T4).

## Out of Scope

Freigabestufen je Art, Inhalt im Ausstand, Wegweiser im Materialordner, Schlüsselbund,
eigenes Fenster, Installer; ein Umschreiben von Indexdateien durch das Plugin; Migration
vorhandener Kontextbereiche durch das Plugin (das tut die KI mit der Lehrkraft).
