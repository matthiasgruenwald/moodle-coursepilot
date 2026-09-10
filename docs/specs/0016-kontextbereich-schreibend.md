# Spec 0016 — Kontextbereich schreibend: write/append auf user/private, Schreibangebot, Import

*Karte: [Voller Funktionsumfang für `local_kurspilot`](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/346) · Ticket: [#371](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/371) · Zweites von sechs Specs des Zuschnitts [#359](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/359)*

> **Umgesetzt wird gegen [#406](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/406), nicht gegen dieses Dokument.**
> Das Issue trägt die verbindliche Form — User Stories, Abnahmekriterien je Phase als Haken.
> Dieses Dokument beantwortet das *Warum* und ist Nachschlagewerk, keine zweite
> Anforderungsquelle: **eine Anforderung, die nur hier steht, gilt als nicht beauftragt.**
> Wer in #406 eine Entscheidung umstößt, zieht dieses Dokument nach —
> sonst driften Begründung und Bau auseinander.

## Ziel

Der Kontextbereich ist serverseitig bisher **nur lesbar**. Diese Spec beschreibt den
Schreibpfad: wie Kurspilot Arbeitsdateien der Lehrkraft anlegt und fortschreibt, welche
Freigaben das erfordert, wie der Ablageort von der alten Filearea auf Moodles Private Files
umzieht und was Skill-seitig zu tun ist, damit vereinbarte Inhalte zuverlässig geschrieben
werden.

Leitmotiv: *Verwaltung Core-geschenkt, kein Kurspilot-Endpunkt für Löschen/Umbenennen.*

`local_kurspilot` bleibt eigenständiger Neubau ohne Abhängigkeit zu `local_coursepilot`
(Spec 0012 §9.2). Der lokale Weg läuft unverändert weiter.

**Namenskonvention** (Bestand): MCP-Werkzeug `kurspilot_<name>`, Webservice
`local_kurspilot_<name>`, Klasse `\local_kurspilot\external\<name>`.

---

## 1. Ablageort: Moodles Private Files

### 1.1 Warum user/private statt eigener Filearea

Die bisherige Filearea `local_kurspilot/kurspilot_context` ist für die Lehrkraft in der
Moodle-UI unsichtbar — sie kann Dateien nicht einsehen, löschen, umbenennen oder
weitergeben, ohne Kurspilot zu nutzen. Das Blackbox-Veto (Entscheidung #361) hat diese
Prämisse als nicht tragfähig eingestuft: Der Kontextbereich muss für die Lehrkraft in
Moodle sichtbar, lesbar und verwaltbar sein.

`user/private` (Moodles Private Files) erfüllt das vollständig aus dem Core:

- „Meine Dateien" — Löschen inkl. Massenlöschung, Umbenennen, Verschieben, Zip-Download
- Filepicker-Quelle „Private files" — Kopie oder Alias in jeden anderen Kontext
- Core-Privacy-Provider (`privacy\provider` in `user/classes/privacy/`) — Auskunft und
  Löschung ohne Kurspilot-Endpunkt
- Kein Zusatzrecht: `moodle/user:manageownfiles` + `repository/user:view` sind
  Standardnutzerrechte (Archetyp `user=ALLOW`)

→ ADR 0019.

### 1.2 Konstanten und Sandbox

Die Klasse `context_files` hält die Anker des Bereichs. Der Umstieg auf `user/private`
ändert `COMPONENT`, `FILEAREA` und `ITEMID`; der Rest der Klasse (Pfadprüfung, Segmentierung,
Wurzelordner über `contextroot`-Setting) trägt unverändert.

| Konstante | Alt | Neu |
|-----------|-----|-----|
| `COMPONENT` | `local_kurspilot` | `user` |
| `FILEAREA` | `kurspilot_context` | `private` |
| `ITEMID` | `0` | `0` |

Die Sandbox-Wurzel (konfigurierbar über `local_kurspilot/contextroot`, Default `kurspilot`)
wandert mit: Der Kurspilot-Unterordner liegt jetzt unter `user/private/kurspilot/` statt
in einer eigenen Filearea-Wurzel. Kein anderer Unterordner in Private Files ist erreichbar —
die Isolierung bleibt, jetzt durch den fixierten Unterordner statt durch Component/Filearea.

### 1.3 Nutzerquote

Moodle setzt die Nutzerquota (`$CFG->userquota`, Default 100 MB) über `file_storage` **nicht**
durch — nur die Core-UI tut das. Kurspilot schreibt damit sonst als einziger an der
Schulgrenze vorbei, ohne dass die Lehrkraft den Grund sieht, wenn sie in „Meine Dateien"
nichts mehr hochladen kann.

Vor jedem Schreibvorgang: `file_get_user_used_space()` gegen `$CFG->userquota`, mit Respekt
für `moodle/user:ignoreuserquota`. Absage mit Rest-Platz in Lehrkraft-Deutsch.

### 1.4 Grenze dieser Isolationsbegründung (Vermerk, Issue #444)

Die Isolationsbegründung aus §1.2 — „die Isolation kommt nicht aus einer Pfadprüfung, sondern
aus Komponente/Dateibereich/Itembezug/Kontext" — gilt **nur für Moodle-Dateibereiche**. In
einem angebundenen Repository (siehe ADR 0020, „Ortsadapter" als abgelehnte Option, solange
kein zweiter Ablageort real existiert) gibt es diese vier Größen nicht: Component, Filearea
und Itemid sind Moodle-Konzepte, kein Repository kennt sie. Dort müsste die Isolation aus
einem Pfadpräfix kommen (z. B. je Nutzer ein eigener Wurzelordner im Repository) — das ist
eine andere Verteidigungslinie mit anderen Angriffsflächen und braucht eine **erneute
Datenschutzbewertung**, keine Fortschreibung dieser hier.

Zweiter Punkt, der an derselben Stelle hängt: Auskunft und Löschung für Kontextdateien trägt
heute der **Core-Privacy-Provider** (`privacy\provider` in `user/classes/privacy/`), weil die
Dateien in `user/private` liegen (§1.1) — Kurspilot exportiert sie nicht ein zweites Mal
(siehe ADR 0019, Consequences). Ein Repository-Ablageort verlässt diese Deckung ersatzlos:
Dateien in einem angebundenen Repository liegen außerhalb von `user/private` und außerhalb
dessen, was der Core-Provider kennt. Der Plugin-eigene Privacy-Provider müsste Auskunft und
Löschung für diese Dateien dann selbst führen, statt sich wie bisher auf den Core zu
verlassen.

Beantwortet in [#471](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/471) und
ADR 0021. Die Isolierung zwischen Lehrkräften trägt am externen Ort das **Instanzeigentum**,
nicht das Pfadpräfix, und der Plugin-Provider führt die externen Dateien nicht selbst. Er
deklariert sie als externen Ort, weil Moodle dort weder exportieren noch löschen kann.

---

## 2. Leseendpunkte: contenthash + timemodified

`list_context_files` und `read_context_file` liefern heute keinen `contenthash` und kein
`timemodified`. Beide werden als neue optionale Felder additiv ergänzt — kein Vertragsbruch
für bestehende Clients.

Diese Felder sind die Grundlage für:
- `expected_contenthash` bei `write_context_file` (Gleichzeitigkeitsschutz, §5.3)
- Handänderungs-Erkennung (§7, Skill-seitig)

---

## 3. Umzug der alten Filearea

### 3.1 Upgrade-Step

Beim Plugin-Upgrade kopiert ein Upgrade-Step alle Dateien aus
`local_kurspilot/kurspilot_context/0/<usercontext>/` nach
`user/private/0/<usercontext>/kurspilot/` (dem neuen Unterordner). Kollision = überspringen
und ins Upgrade-Log schreiben. Der Altbestand wird **nicht** gelöscht — er ist der Rückweg,
falls der Upgrade-Step etwas verpatzt, und die Lehrkraft entscheidet selbst, wann sie ihn
räumt.

Der Upgrade-Step ist der Umzug für den Einmal-Fall (bisheriger Kurspilot-Bestand). Der
Weitergabe-Fall — Dateien von einer Kollegin übernehmen — läuft über den Core: „Meine
Dateien → Datei hinzufügen → Server files" gibt Zugriff auf jeden Moodle-Dateibereich,
den die Person sehen darf. Das braucht keine eigene Plugin-Seite.

### 3.2 Privacy-Provider

Der Datei-Teil des Providers (`provider.php`) deckte vor dem Umzug die aktuelle Filearea
ab. Da `context_files::COMPONENT`/`FILEAREA` jetzt auf `user`/`private` zeigen, wären
dieselben Konstanten im Provider auf den neuen Ablageort umgesprungen — mit der Folge,
dass die neuen Dateien doppelt exportiert würden (einmal durch Kurspilot, einmal durch
`core_user`) und ein Löschpfad über den Provider fremde, nicht von Kurspilot geschriebene
Dateien aus „Meine Dateien" mitreißen könnte. Der Provider wurde deshalb auf eigene
`LEGACY_COMPONENT`/`LEGACY_FILEAREA`-Konstanten umgestellt, die weiterhin auf die alte
Filearea zeigen — er deckt damit genau noch den Altbestand ab. Die neuen Dateien in
`user/private` deckt der Core-Provider; Kurspilot exportiert sie **nicht** ein zweites Mal.
Wenn der Altbestand vollständig umgezogen und von der Lehrkraft geräumt ist, kann der
Datei-Teil des Providers in einem späteren Release entfernt werden.

---

## 4. Werkzeuge

Zwei generische Endpunkte; keine semantischen Endpunkte je Dateiart.

### 4.1 write_context_file

Anlegen oder vollständiges Überschreiben einer Datei.

Parameter:
- `path` — Dateipfad relativ zum Kontextbereich (Pfadregeln §5.1)
- `content` — Dateiinhalt (Größengrenze §5.2)
- `expected_contenthash` — optional (Gleichzeitigkeitsschutz §5.3)

Antwort (§6): die Änderungsmeldung in Lehrkraft-Deutsch, mit „neu angelegt" oder
„überschrieben" und — falls die Quote sich merklich geleert hat — dem Restplatz.

### 4.2 append_context_file

Anhängen an eine bestehende Datei in einem Serveraufruf ohne vorheriges Lesen — kein
Lock gegen gleichzeitige Aufrufe (§5.3), aber besser als der lokale Read-Modify-Write,
der unbeobachtet ist.

Parameter:
- `path` — wie oben
- `content` — der Anhängsel (Größengrenze §5.2)

Antwort: „angehängt an <datei>" oder „neu angelegt als <datei>" (§5.4).

Append-Prüfung: Existiert die Zieldatei, wird ihr Frontmatter gelesen und der
Personenbezug-Schalter geprüft — exakt so wie bei `read_context_file` (§5.5). Ohne diesen
Schritt ließe sich die #344-Grenze mit einem Append umgehen: Lehrkraft markiert das
Lerngruppenjournal als personenbezogen, Schalter ist aus, Kurspilot schreibt trotzdem
weiter hinein.

---

## 5. Grenzen, Regeln, Schutz

### 5.1 Pfadregeln

Nur `.md`-Dateien. Pfadsegmente bestehen aus `[A-Za-z0-9_-]`; `.` und `..` werden
abgewiesen (bestehende `context_files::segments()`). Tippfehler werden durch die
ausdrückliche Antwort (§5.4) im Chat sichtbar, nicht durch ein Verbot.

### 5.2 Größengrenzen

| Grenze | Scope | Wirkung |
|--------|-------|---------|
| 1 MB | Einzelner Vorgang (`content`) | Harter Fehler |
| 1 MB Zieldatei | Append-Ergebnis | Weiches Signal: Append geht durch, Antwort empfiehlt Rotation |

Begründung: Ein hartes Ende am Zieldatei-Limit würde das Journal mitten im Schuljahr
abwürgen. Rotation ist Skill-Sache; das Plugin gibt das Signal, nicht die Regel.

### 5.3 Gleichzeitigkeit

Keine Locks. `write_context_file` nimmt optional `expected_contenthash`: stimmt er nicht
mit dem aktuellen `contenthash` der `stored_file` überein, bricht der Vorgang ab mit
„hier wurde seit dem letzten Lesen geändert — bitte neu lesen und nochmal". Append wird
nie auf Kontenthash geprüft (Journal-Appends sind additiv und überschreiben nichts).

„Keine Locks" heißt auch: kein Ausschluss gleichzeitiger Aufrufe gegen dieselbe Datei.
Zugesagt ist nur, was zutrifft — ein Serveraufruf ohne vorheriges Lesen, kein halb
geschriebener Zustand —, nicht Ausschluss von Nebenläufigkeit.

Der eine Schreibvorgang, den `write_context_file` und `append_context_file` sich teilen
(`context_files::replace()`), legt den neuen Inhalt zuerst unter einem Zwischennamen im
Dateipool ab und löscht die alte Datei erst danach. Die naheliegende Reihenfolge —
erst löschen, dann neu anlegen — ist nicht rettbar: `stored_file::delete()` entfernt den
Blob physisch aus dem Dateipool; eine umschließende DB-Transaktion holt beim Rollback nur
die Datenbankzeile zurück, die dann auf einen nicht mehr existierenden Blob zeigt — die
Lehrkraft hätte ihre Datei verloren, ohne dass jemand sie überschrieben hat. Bricht der
Vorgang zwischen Löschen und Umbenennen ab, bleibt stattdessen die sichtbare Zwischendatei
mit dem vollständigen neuen Inhalt in „Meine Dateien" liegen — unschön, aber nichts ist weg.

### 5.4 Antwortsemantik

- Neue Datei: „[Datei] neu angelegt" (damit Tippfehler im Chat sichtbar sind)
- Überschreiben: „[Datei] überschrieben (vorher: N Byte, jetzt: M Byte)"
- Angehängt: „[Datei] angehängt (jetzt: M Byte insgesamt)" bzw. „neu angelegt"
- Zieldatei > 1 MB: Zusatz „Journal überschreitet 1 MB — Rotation empfohlen"
- Quotenwarnung: Restplatz in MB, wenn unter 10 % verbleiben

### 5.5 Personenbezug

- Bei `write_context_file`: geprüft wird sowohl das zu schreibende `content` (Frontmatter
  parsen, `kurspilot.personenbezug: true`) als auch — falls die Zieldatei bereits existiert —
  ihr eigenes Frontmatter. Wenn #344-Schalter aus, harter Fehler mit klarer Meldung, sobald
  eine der beiden Seiten personenbezogen markiert ist. Ohne die Zieldatei-Prüfung wäre eine
  markierte Datei bei ausgeschaltetem Schalter zwar unlesbar, aber unbemerkt überschreibbar —
  genau die Lücke, die die Zieldatei-Prüfung beim Append (unten) schon schließt.
- Bei `append_context_file`: die Zieldatei wird gelesen (wenn vorhanden) und ihr Frontmatter
  geprüft. Keine Zieldatei = kein Frontmatter = kein Personenbezug = Append erlaubt.
- Keine Inhaltsprüfung: Klarnamen in unmarkierten Dateien ist Skill-Regel, kein Plugin-Zwang.

Bilder und Material haben im Kontextbereich keinen Platz; `.md`-only-Grenze (§5.1) schließt
das technisch aus.

---

## 6. Kein Änderungsverlauf für Kontextdateien

Abweichung von ADR 0018 (Änderungsverlauf im Notenbuch-Muster), domänenbegründet:

- Das **Append-Journal ist das Gedächtnis**: Kontextdateien sind Planungs- und
  Protokolldateien; ihr Wachsen ist der Verlauf. Jeder Eintrag ist bereits Historiendaten.
- Voll-Writes sind **klein und freigabekontrolliert** (Schreibangebot, §8.2): Ein
  überschriebenes `plan.md` ist keine unerwartete Änderung, sondern der Abschluss einer
  ausdrücklichen Planungsrunde.
- **Gegengewicht** ist der Aktivitäten-Rollback (ADR 0018) — der Kurs selbst ist das,
  was sich unbeabsichtigt ändern kann; die Planung, die ihn beschreibt, ist intentionell.

Nachzurüsten ist ein Verlauf jederzeit, wenn Bedarf entsteht. Zurzeit entstünde er vor dem
Nutzen.

---

## 7. Handänderungs-Erkennung

Lehrkräfte können Dateien im Kontextbereich jederzeit in „Meine Dateien" bearbeiten —
das ist ausdrücklich gewünscht. Die KI muss damit rechnen.

Erkennung über `contenthash`/`timemodified` der `stored_file` aus den Leseendpunkten (§2).
Der Vergleich findet **Skill-seitig** statt: Das Plugin liefert die Fakten, der Skill
prüft und fragt nach. Begründung: „bei Sitzungsstart prüfen" ist ein Sitzungsbegriff —
der Server hat kein Session-Konzept.

Routine: Bei Sitzungsstart und vor jedem Schreibvorgang den gespeicherten `contenthash`
gegen den aktuellen vergleichen. Bei Abweichung: Datei neu lesen und Lehrkraft fragen,
bevor weitergeschrieben wird. Nicht jede Version aufbewahren.

---

## 8. Skill-Regeln

Die Semantik des Schreibpfads ist nicht Plugin-Regel, sondern Skill-Regel — das Plugin
stellt die Werkzeuge, der Skill entscheidet, wann und was geschrieben wird. Dadurch bleibt
der Plugin-Code schlank und die Skill-Logik klar lokalisiert.

### 8.1 Journal-Appends unter der Sitzungs-Kontextfreigabe

Journal-Einträge sind automatisch — kein Schreibangebot je Eintrag. Sie fallen unter die
einmalige Sitzungs-Kontextfreigabe, die zu Beginn einer Kurspilot-Sitzung klärt, welche
Arbeitsdateien aktualisiert werden dürfen.

### 8.2 Schreibangebot für plan/status/vorlagen

`plan.md`, `status.md`, Vorlagen und ProfilDateien brauchen das **Schreibangebot**:
Die KI fasst Vereinbartes an natürlichen Haltepunkten zusammen und fragt, ob sie schreiben
soll. Nichts Vereinbartes darf ungeschrieben bleiben; nichts wird still geschrieben.
Journal-Appends sind ausdrücklich ausgenommen (§8.1).

### 8.3 Keine Klarnamen in unmarkierten Dateien

Schülernamen, Schüler-IDs und anderer Personenbezug gehören in markierte Dateien
(`kurspilot.personenbezug: true`). Das ist Skill-Regel, kein Plugin-Zwang — das Plugin
prüft nur die Markierung, nicht den Inhalt.

### 8.4 Journal-Rotation

Wenn das Plugin „Journal überschreitet 1 MB" signalisiert (§5.2), schließt der Skill
das aktuelle Journalarchiv (`journal-YYYY-MM.md` o.ä.) und legt ein neues an. Die
Implementierung der Rotation ist Skill-Sache; das Plugin gibt nur das Signal.

---

## 9. Protokollierung

Übernimmt das Regime aus Spec 0015 §9.5: Die vorhandenen `tool_access_*`-Events decken
Kontextschreibungen ab (Stufe 1: Schreibzugriffe + Fehler, Voreinstellung Stufe 2).
**Pfad und Vorgang** werden protokolliert, **nie der Inhalt** — sonst stünde der
personenbezogene Text, den der #344-Schalter gerade aussperrt, im Logstore.

---

## 10. Umsetzungsphasen

Strikt seriell; Phase 1 vor Phase 2, weil Phase 2 Endpunkte gegen den neuen Ablageort
schreibt.

### Phase 1 — Umzug

- Konstanten in `context_files` auf `user`/`private`/`0` umstellen
- Capability-Prüfung `moodle/user:manageownfiles` in den Schreibendpunkten
- Quotenprüfung (`file_get_user_used_space()`, §1.3)
- `contenthash` + `timemodified` additiv in `list_context_files` und `read_context_file`
- Upgrade-Step: Altbestand in Unterordner kopieren (Kollision überspringen + loggen)
- Privacy-Provider: auf eigene `LEGACY_COMPONENT`/`LEGACY_FILEAREA`-Konstanten umgestellt,
  damit er weiterhin nur den Altbestand abdeckt (§3.2)

### Phase 2 — Schreibpfad

- `write_context_file` (§4.1): Anlegen/Überschreiben, Pfad- + Größen- + Personenbezug-Prüfung,
  `expected_contenthash`, Antwortsemantik
- `append_context_file` (§4.2): ein Serveraufruf ohne vorheriges Lesen, Frontmatter-Prüfung
  der Zieldatei, weiches 1-MB-Signal, Antwortsemantik
- Beide Endpunkte in `db/services.php` registrieren

### Phase 3 — Skill-Regeln

- Schreibangebot an natürlichen Haltepunkten (plan/status/vorlagen)
- Journal-Appends unter der Sitzungs-Kontextfreigabe
- Handänderungs-Routine (contenthash-Vergleich bei Sitzungsstart und vor Writes)
- Journal-Rotations-Signal auswerten
