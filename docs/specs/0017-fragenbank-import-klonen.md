# Spec 0017 — Fragenbank, XML-Import und Klonen: rein strukturell

*Karte: [Voller Funktionsumfang für `local_kurspilot`](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/346) · Ticket: [#372](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/372) · Drittes von sechs Specs des Zuschnitts [#359](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/359)*

> **Umgesetzt wird gegen [#411](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/411), nicht gegen dieses Dokument.**
> Das Issue trägt die verbindliche Form — User Stories, Abnahmekriterien je Phase als Haken.
> Dieses Dokument beantwortet das *Warum* und ist Nachschlagewerk, keine zweite
> Anforderungsquelle: **eine Anforderung, die nur hier steht, gilt als nicht beauftragt.**
> Wer in #411 eine Entscheidung umstößt, zieht dieses Dokument nach —
> sonst driften Begründung und Bau auseinander.

## Ziel

Der Schreibkern (Spec 0015) kann Aktivitäten anlegen und ändern, der Kontextbereich
(Spec 0016) kann Arbeitsdateien fortschreiben. Was fehlt, ist der **Inhalt der Tests**:
Fragenbänke, Kategorien, Fragen, ihr Weg ins Quiz — und das Klonen als der Weg, mit dem
Lehrkräfte bewährte Aufbauten wiederverwenden.

Diese Spec ist **rein strukturell**. Bilder, eingebettete Dateien und jeder Binärtransport
bleiben gesperrt; der Weg dafür ist Spec 0018.

Leitmotiv: *ein Schreibweg für Fragen, nicht drei.* Heute schreiben `create_mc_question`,
`update_mc_question` und `import_questions_xml` je auf eigene Art in die Fragenbank, mit
je eigener Antwort auf „ist das dieselbe Frage?". ADR 0015 hat die Identitätsfrage bereits
vereinheitlicht; diese Spec vereinheitlicht den Mechanismus darunter.

`local_kurspilot` bleibt eigenständiger Neubau ohne Abhängigkeit zu `local_coursepilot`
(Spec 0012 §9.2). Der lokale Weg läuft unverändert weiter.

**Namenskonvention** (Bestand): MCP-Werkzeug `kurspilot_<name>`, Webservice
`local_kurspilot_<name>`, Klasse `\local_kurspilot\external\<name>`.

---

## 1. Ein XML-Kern statt drei Schreibwege

### 1.1 Die Entscheidung

Alle Fragen-Schreibvorgänge laufen durch **einen** Kern: XML parsen über die öffentliche
`qformat_xml`-API, schreiben über `question_type::save_question()`. Das ist der Weg, den
Spec 0014 für den XML-Import etabliert hat — er wird jetzt der einzige.

`create_mc_question` und `update_mc_question` bleiben als Werkzeuge erhalten, nehmen
weiterhin typisierte Felder entgegen und **bauen die XML serverseitig**. Die KI schreibt
für Multiple-Choice niemals XML.

Warum nicht die MC-Werkzeuge ganz streichen und alles über XML laufen lassen: Weil eine
KI, die MC-XML von Hand baut, genau die Fehlerklasse produziert, gegen die §2 antritt —
und weil die Fähigkeitsparität aus
[#351](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351) die
lehrkraftseitige Fähigkeit schützt, nicht die Werkzeugliste. Die Fassade kostet wenig und
hält den gefährlichsten Weg aus der Hand der KI.

Warum nicht die drei Wege lassen, wie sie sind: Weil jeder Schreibweg seine eigene
Abgleichregel mitbringt — beim Quiz-Klonen liefen bereits zwei parallel
([#354](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/354)). ADR 0015
verlangt eine Regel; ein Kern ist die billigste Art, sie durchzusetzen.

### 1.2 `importprocess()` bleibt außen vor

Unverändert gegenüber Spec 0014: Moodles Standard-Importpfad erzeugt immer einen neuen
`question_bank_entries`-Datensatz und ist nicht auf Versionstreue umkonfigurierbar. Wir
schreiben über `save_question()` mit gesetzter `$question->id` und erzeugen damit eine
neue Version desselben Bank-Eintrags (ADR 0001). Quiz-Slots auf „immer aktuellste
Version" folgen automatisch.

---

## 2. Zuverlässigkeit: vier Schichten

Die zentrale Sorge dieser Spec ist nicht, ob eine Frage entsteht, sondern ob sie
**funktioniert und ihr Formular nicht bricht**. Vier Schichten, gestapelt:

### 2.1 Schicht 1 — die Fassade

Für Multiple-Choice baut der Server die XML aus einer festen Vorlage. Strukturell
fehlerhafte XML kann auf diesem Weg nicht entstehen.

### 2.2 Schicht 2 — Update ist read-modify-write

Ein Update exportiert die bestehende Frage über `qformat_xml`, wendet den Patch an und
schreibt den **Vollstand** zurück. Das ist dasselbe Muster wie beim Modul-Vehikel
(Spec 0015 §3.3) und aus demselben Grund: `save_question()` mit unvollständigem `$form`
**löscht, was nicht drinsteht**. Es ist exakt die Fehlerklasse aus
[#355](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/355) — ein
*fehlender* Wert ist gefährlicher als ein falscher. Bei Vollstand-Patch kann kein
Antwortoption, kein Feedback und keine Teilbewertung unbemerkt wegfallen.

### 2.3 Schicht 3 — Round-Trip-Prüfung in einer Transaktion

**Der eigentliche Neuzugang dieser Spec.** Nach `save_question()` wird die frisch
geschriebene Frage wieder exportiert und gegen die Eingabe verglichen. Abweichung oder
Ausnahme ⇒ Rollback, nichts geschrieben.

Die Fragenbank ist der **einzige** Bereich, in dem Moodle einen echten
Export/Import-Rundlauf anbietet
([#347](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/347)). Bei Modulen
ist eine solche Prüfung unmöglich; hier ist sie fast geschenkt. Die Antwort auf „woher
weiß ich, dass es funktioniert hat" lautet damit *der Server hat nachgesehen* statt *wir
haben es getestet*.

**Vergleichstiefe: Kernfelder, nicht Byte-Gleichheit.** Verglichen werden Fragetext,
Antwortoptionen samt Bruchteilen, Feedbacktexte, `name` und `idnumber`. Moodle
normalisiert IDs, Reihenfolgen und Dateipfade beim Schreiben; ein strenger Vollvergleich
würde daran scheitern und wäre nach zwei Wochen wieder ausgebaut. Eine Prüfung auf bloße
Existenz („Frage ist lesbar") fängt den Fall „Frage da, Formular kaputt" nicht. Die
Kernfeldliste steht im Prüfgrundlage-Issue und ist die Stelle, an der bei Ärger
nachjustiert wird.

### 2.4 Schicht 4 — Roundtrip-Pilot je Fragetyp

Bevor ein Fragetyp als „geht" gilt, läuft ein Pilot auf der Spike-Instanz: Frage im UI
anlegen, exportieren, über den Adapter importieren, Optionen und Auswertungsbäume
vergleichen. Spec 0014 forderte das bereits für STACK; es wird zur Regel für jeden Typ.

### 2.5 Die Prüfung ist zugleich die Lernschleife

Ein misslungener Versuch rollt zurück und schreibt nichts. Damit existiert der
**Trockenlauf, den [#350](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/350)
ausdrücklich nicht bauen wollte**, als Nebenprodukt der Zuverlässigkeitsschicht — ohne
eigenen Modus, ohne zweiten Codepfad. Die KI kann probieren, die Abweichung sehen,
korrigieren. Das trägt §5.

---

## 3. Beliebige Fragetypen ohne Wartungslast

### 3.1 Das Ziel

Der XML-Weg soll **jeden** Fragetyp tragen, der `import_from_xml()` implementiert — ohne
dass am Plugin oder am Skill etwas nachgepflegt werden muss, wenn ein neuer Typ auftaucht.
Die typisierte Fassade bleibt bei Multiple-Choice; weitere Fassaden sind möglich, aber
jede kostet eine Feldabbildung und ist damit derselbe Katalogpreis wie bei den Modultypen
(Spec 0015 §2.5). Sie sind ausdrücklich kein Weg zur Typabdeckung.

### 3.2 Die Fragetyp-Ablage

Was eine KI beim erfolgreichen Round-Trip über einen Typ gelernt hat, wird als
Kontextdatei festgehalten — **im Kontextbereich, nicht im Plugin**. Fester Pfad:

```
kurspilot/fragetypen/<fragetyp>.md
```

Eine Datei je Typ. Das ist die Einheit, die man weitergibt, die veraltet, und die eine KI
in einem Rutsch liest, ohne fremde Typen mitzuschleppen. `vorlagen.md` (Spec 0013) bleibt
davon unberührt — das sind Aktivitätsvorlagen, etwas anderes.

Der Ablageort ist kein Zufall: Spec 0016 hat den Kontextbereich schreibbar gemacht,
`.md`-only, in Moodles Private Files. Ein Wissen, das niemand garantiert und niemand
wartet, gehört genau dorthin — in die Hand der Lehrkraft, sichtbar, löschbar, weitergebbar,
ohne einen einzigen Kurspilot-Endpunkt.

### 3.3 Feste Gliederung

Ohne vorgegebene Gliederung hängt jede KI ihr neues Wissen ans Ende, und nach fünf Runden
ist die Datei unlesbar. Verbindlich:

| Abschnitt | Inhalt |
|---|---|
| **Kopf** | Fragetyp, Moodle-Version, Plugin-Version, zuletzt verifiziert am |
| **Minimal-Beispiel** | die kleinste XML, die nachweislich durchläuft |
| **Pflichtstruktur** | was fehlen darf und was nicht |
| **Stolpersteine** | je Eintrag: Symptom → Ursache → Abhilfe |
| **Ausbaustufen** | Optionales (z. B. komplexe Auswertungsbäume), je eigener Abschnitt |

Neues Wissen wandert in den passenden Abschnitt: ein Stolperstein wird eingereiht, eine
Ausbaustufe angehängt. Geschrieben wird mit `write_context_file` samt
`expected_contenthash` — **Vollersatz mit Konfliktschutz, nicht `append_context_file`**.
Der Kopf ist die Verfallsanzeige: veraltet die Datei, merkt es die nächste Lernschleife.

### 3.4 Weitergabe

Die Ablage ist eine gewöhnliche Kontextdatei. Ihre Weitergabe läuft über den Core-Weg aus
Spec 0016 §3.1: Zip-Download aus „Meine Dateien", oder die Kollegin holt sie über den
Filepicker-Reiter „Server files". Kein Kurspilot-Endpunkt, keine Registry, keine
Kuratierung, keine Garantie.

Damit „gib mir mal deine STACK-Datei" funktioniert, muss der Pfad **konventionell und
auffindbar** sein — deshalb steht er in §3.2 fest und wird an einer Stelle im Skill
dokumentiert („wo dein Fragetyp-Wissen liegt und wie du es weitergibst").

---

## 4. Werkzeuge

Zehn Endpunkte. Sieben portiert, zwei zusammengezogen, einer neu.

| Werkzeug | Herkunft | Zweck |
|---|---|---|
| `ensure_question_bank` | portiert | Fragenbank-Aktivität anlegen oder wiederverwenden |
| `ensure_question_category` | **zusammengezogen** | Kategorie anlegen oder finden |
| `update_question_category` | portiert, verengt | Umbenennen und Verschieben |
| `move_question` | portiert, gegatet | Frage in andere Kategorie umziehen |
| `create_mc_question` | Fassade über §1 | MC-Frage aus typisierten Feldern |
| `update_mc_question` | Fassade über §1 | MC-Frage als neue Version fortschreiben |
| `import_questions_xml` | Kern aus §1 | beliebige Fragetypen aus Moodle-XML |
| `export_questions_xml` | **neu** | Fragen als Moodle-XML lesen |
| `add_questions_to_quiz` | portiert, gegatet | Fragen an ein Quiz anhängen |
| `clone_activity` | portiert, zusammengeführt | Aktivität duplizieren, auch kursübergreifend |

### 4.1 `ensure_question_category` statt `create` + `update`

Der Bestand trennt Anlegen und Ändern. `ensure_question_bank` ist dagegen bereits
idempotent gebaut („anlegen oder wiederverwenden") — und der Skill fragt sonst bei jedem
Lauf erst „gibt es die schon?". Das ist ein Rundgang, den der Server billiger selbst macht.

`ensure_question_category` findet oder legt an, über Name plus Elternkategorie.
`update_question_category` bleibt für das, was wirklich eine Änderung ist: Umbenennen und
Verschieben.

### 4.2 `export_questions_xml`

Der Motor existiert ohnehin — §2.2 und §2.3 exportieren serverseitig. Ihn als Werkzeug
herauszuführen kostet einen dünnen Endpunkt und bedient drei Fälle:

1. **Vorlage aus dem Bestand** (§5): existiert im Moodle schon eine funktionierende Frage
   des gesuchten Typs, holt die KI sie sich selbst, statt die Lehrkraft um Handarbeit zu
   bitten.
2. **Read-modify-write** beim Update (§2.2) — intern, aber derselbe Motor.
3. **Weitergabe** einer Frage an eine Kollegin.

Die Karte notiert ausdrücklich: „Es gibt **keinen** Export in irgendeiner Form."
Serverseitige Neuzugänge sind laut #351 Gewinn, nicht Scope-Creep.

**Dateien werden nicht mitexportiert** (§6): an ihre Stelle tritt ein benannter
Platzhalter. Für den Vorlagenzweck ist das sogar besser — die KI soll die Struktur lernen,
nicht 400 KB Bild. Für die Weitergabe ist es unvollständig, und die Meldung sagt das auch;
der vollständige Weg kommt mit Spec 0018.

### 4.3 `add_questions_to_quiz` — nur Anhängen

Anhängen mit Dublettenprüfung, Rückgabe des Slot-Stands wie im Bestand.

**Kein Entfernen, kein Umsortieren, keine Seitenumbrüche.** Das ist Layoutarbeit am
Bildschirm, in der Moodle-UI in Sekunden erledigt und kein sinnvoller KI-Auftrag.
[#365](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/365) hatte bereits
entschieden, dass Slot-Manipulation internes Mittel des Rollbacks bleibt und kein
MCP-Werkzeug wird; diese Spec bestätigt das und verzichtet auf das ursprünglich erwogene
`remove_questions_from_quiz`.

**Harte Grenze `quiz_has_attempts()`**: Gibt es Versuche, kommt eine Absage in
Lehrkraft-Deutsch — kein Teilerfolg, keine halb gefüllte Slot-Liste.

### 4.4 `clone_activity` — ein Endpunkt für beide Pfade

Im lokalen Weg sind das zwei Wege: der Intra-Kurs-Fall über den Core-Webservice
`core_courseformat_update_course` (Action `cm_duplicate`), der kursübergreifende über
einen Plugin-Adapter mit `backup_controller`/`restore_controller`. Im nativen Servermodell
gibt es keinen Grund, das an der Werkzeugoberfläche sichtbar zu lassen: **ein** Endpunkt
`clone_activity` wählt intern den Pfad anhand des Zielkurses. Die Lehrkraft-Sicht kennt
nur „diese Aktivität duplizieren, hierhin".

Verhalten wie in Spec 0013 festgelegt und bewährt: sauberer Titel ohne „(Kopie)"-Suffix,
Sichtbarkeit standardmäßig gesetzt, Capability-Prüfung in Quell- **und** Zielkurs.

---

## 5. Die Lernschleife

Der Ablauf, wenn die KI einen Fragetyp bauen soll, den sie nicht kennt:

1. **Ablage lesen.** Gibt es `kurspilot/fragetypen/<typ>.md`, wird danach gebaut.
2. **Bestand durchsuchen.** Gibt es keine Ablage, sucht die KI über
   `export_questions_xml` nach einem funktionierenden Exemplar im Moodle.
3. **Bauen und schreiben.** Der Round-Trip prüft; scheitert er, rollt alles zurück, und
   die Abweichung ist der Lernstoff.
4. **Höchstens drei Versuche.** Danach wird nicht weiter probiert.
5. **Vorlage anfordern.** Nach dem dritten Fehlschlag bittet die KI die Lehrkraft
   ausdrücklich: *„Bitte lege eine solche Frage einmal selbst in Moodle an, prüfe, dass
   sie funktioniert, exportiere sie und gib mir die XML."* Aus dieser Vorlage — aus
   **diesem** Moodle, mit dieser Version und diesen Plugins — lernt die KI, was schiefging.
6. **Merken, auf Nachfrage.** Nach dem ersten erfolgreichen Round-Trip eines neuen Typs
   fragt die KI, ob sie das Gelernte festhalten soll (§3.2).

### 5.1 Transparenz ist Teil der Regel

Die Lehrkraft sieht die Schleife, sie läuft nicht im Verborgenen: *„Der Fragetyp ist mir
neu, ich probiere das gerade aus"* — dann je Versuch, was fehlschlug und was korrigiert
wurde — dann die Aufforderung nach dem dritten Fehlschlag. Eine KI, die drei Minuten
schweigt und dann „hat nicht geklappt" sagt, ist der Fehlerfall, den diese Regel
verhindert.

Drei Versuche, weil einer zu wenig ist (die erste Abweichung ist oft trivial) und mehr
nur Zeit kostet, ohne die Erfolgsaussicht zu erhöhen: Was nach drei Anläufen nicht steht,
steht auch nach zehn nicht ohne eine echte Vorlage.

### 5.2 Widersprüche überwachen

Die Ablage wird ohnehin vor jedem Bau gelesen — die Prüfung kostet dann nichts extra.

Weicht das tatsächliche Verhalten von der Datei ab (eine dokumentierte Regel stimmt nicht
mehr, ein Fehler tritt auf, den die Datei ausschließt), **meldet die KI das ausdrücklich
als Widerspruch** und bietet an, den betroffenen Abschnitt zu überarbeiten — mit Vermutung
zur Ursache (naheliegend: neue Moodle- oder Plugin-Version) und aktualisiertem
Versionsstand im Kopf. Kein stilles Weiterarbeiten gegen eine Datei, die nicht mehr gilt.

Kommt Wissen hinzu, das vorher nicht auftauchte, weil bestimmte Möglichkeiten nie genutzt
wurden — komplexe Auswertungsbäume etwa —, wird es **eingeordnet, nicht angehängt**
(§3.3). Die Datei muss jederzeit weitergebbar bleiben.

---

## 6. Bilder und eingebettete Dateien: gesperrt

Spec 0015 §4.3 sperrt Dateifelder bis Spec 0018. Moodle-XML trägt Bilder als
base64-`<file>`-Blöcke **im Frageninhalt** — ein Import würde also an 0018 vorbei Dateien
schreiben, ein Export sie in den KI-Kontext zurückschütten.

- **Import:** XML mit eingebetteten `<file>`-Blöcken wird **abgewiesen**, mit Meldung
  („XML enthält N eingebettete Dateien — Bildtransport kommt mit einem späteren Ausbau").
  Nicht: Dateien entfernen und die Frage trotzdem anlegen — das liefert eine Frage aus,
  deren Diagramm fehlt, und die Lehrkraft merkt es erst vor der Klasse.
- **Export:** Dateien werden durch einen benannten Platzhalter ersetzt (§4.2).

### 6.1 Auflage an Spec 0018

Der Grund für die Sperre ist nicht technische Unmöglichkeit, sondern **Kosten**: Bilder
über die MCP-Schnittstelle zu transportieren heißt base64 durch das KI-Kontextfenster —
ein paar hundert Kilobyte je Bild, bei hundert Bildern Megabyte an Token, für Inhalt, den
das Modell nie lesen muss.

Der Ausweg ist beschreibbar und wird hier festgehalten, damit Spec 0018 ihn nicht
übergehen kann: **Der `<file>`-Block muss nicht base64 enthalten, wenn der Server ihn
selbst füllt.** Das Bild liegt bereits in Moodle — seit Spec 0016 im Kontextbereich unter
Private Files. Die KI nennt im XML nur den Namen, der Import-Endpunkt löst den Verweis
serverseitig auf und baut den base64-Block; der Export ersetzt umgekehrt base64 durch
Verweise und legt die Bilder in Private Files ab. Das Bild passiert den KI-Kontext nie.

Damit steht diese Auflage **gegen** die Festlegung aus
[#351](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351), die Datei-Tools
„mit Vertragsänderung auf Chat-Anhang/Base64" zu belassen. Spec 0018 muss das entscheiden,
nicht stillschweigend fortschreiben.

In dieser Spec wird nichts davon gebaut und **kein Parameter dafür reserviert**.

---

## 7. Fragen-Identität

ADR 0015 gilt unverändert; diese Spec setzt ihn um, statt ihn neu zu verhandeln.

- **Abstammung** trägt die stabile `idnumber`.
- **Stand** ist der Fragenbank-Eintrag (`questionbankentryid`) mit nativer Versionierung
  (ADR 0001).
- Der `stamp` ist nur Herkunftshinweis, nie Identitätsträger.

### 7.1 Das Verdachtsfall-Gate wird Allgemeinregel

Alle vier schreibenden Wege tragen es, mit **einem gemeinsamen Antwortformat** — ein Gate,
das je Endpunkt anders aussieht, sind vier Gates:

| Weg | Verdachtsfall |
|---|---|
| `import_questions_xml` | XML bringt `idnumber` mit, kein Eintrag der Zielkategorie hat sie |
| `create_mc_question` | gleichnamiger Eintrag in der Zielkategorie ohne `idnumber`-Treffer |
| `move_question` | `idnumber`-Kollision in der Zielkategorie |
| `clone_activity` (Nachbereitung) | Fragen des Klons ohne geklärte Abstammung |

Bei `move_question` ist das Gate besonders wichtig: Der Core löst eine `idnumber`-Kollision
**still mit einem `_N`-Suffix** und zerreißt damit die Abstammung genau in dem Moment, in
dem die Lehrkraft glaubt, nur aufzuräumen. Gegatet wird **vor** dem Umzug, nicht danach
gemeldet.

Ein Verdachtsfall schreibt nichts. Die Antwort nennt die mitgebrachte `idnumber`, die
Zielkategorie, nahe Kandidaten und — wo vorhanden — alten und neuen Fragetext zum
Vergleich. Erst der erneute, ausdrücklich bestätigte Aufruf schreibt.

### 7.2 idnumber-Backfill: eine Frage, nicht die Fläche

Fremd-Bestände ohne `idnumber` werden **beim ersten Kurspilot-Schreibzugriff auf genau
diese Frage** angebunden. Nicht alle Fragen der Kategorie, nicht die ganze Fragenbank.

Gleiche Bauform wie der Verlauf-Backfill in Spec 0015 §10.3: beim Ereignis, nicht in der
Fläche. Ein Massenlauf über eine fremde Fragenbank ist genau die Art stiller Änderung, die
das Gate verhindern soll — und für die Lehrkraft, die nur eine Kleinigkeit ergänzen wollte,
eine unverständliche Nebenwirkung. Wer eine Fragenbank vollständig inventarisieren will,
kann die KI ausdrücklich darum bitten; von allein geschieht es nie.

### 7.3 Klon-Nachbereitung: melden, nicht schreiben

Nach einem Klon versöhnt Kurspilot die Abstammung der entstandenen Fragen und meldet je
Frage, ob eine eigene **Kopie** oder eine **geteilte Referenz** entstand. Die Prüfung ist
billig (ein Join über `question_references`), und die Aussage „diese 12 Fragen sind eigene
Kopien, jene 3 zeigen weiter auf das Original" ist genau das, was die Lehrkraft nach einem
Kursklon wissen muss und heute nirgends sieht.

**Gemeldet, nicht geschrieben.** Der `idnumber`-Backfill folgt nach §7.2 erst beim ersten
echten Schreibzugriff auf die einzelne Frage. Die Core-Klonmechanik bleibt unangetastet.

---

## 8. Klonen: die #332-Fehlerklasse

Beim kursübergreifenden Klon kann Moodles Backup/Restore `cmid`-Verweise in `availability`
nicht auflösen und setzt `0` ein
([#332](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/332)). Das Ergebnis
ist eine Voraussetzung, die auf keine existierende Aktivität zeigt.

**Kurspilot erkennt solche Verweise und entfernt sie, und benennt, was es entfernt hat.**

Begründung: Eine `cm:0`-Bedingung ist keine Einstellung, sondern Datenmüll, den kein
UI-Weg auflösbar macht — die Lehrkraft kann sie nur löschen. Stehen zu lassen heißt, eine
Aktivität auszuliefern, die möglicherweise für alle unsichtbar ist. Die Meldung nennt die
entfernte Bedingung im Klartext („Voraussetzung ‚Abschluss von …' konnte nicht mitgenommen
werden und wurde entfernt").

Das ist **die einzige Stelle dieser Spec, an der Kurspilot etwas wegnimmt**. Deshalb steht
sie hier und nicht in einer Fußnote. Der generische Hinweis aus Spec 0013
(„Voraussetzungen wurden von der Quelle übernommen — bitte prüfen") bleibt für den
Intra-Kurs-Fall bestehen; er ist etwas anderes und deckt diesen Fall nicht ab.

---

## 9. Größengrenzen: keine eigene Zahl

Eine erfundene Konstante wäre eine zweite Grenze neben der echten, die irgendwann darunter
liegt und niemand mehr erklären kann. Der einzige Grund, überhaupt eine Grenze zu prüfen,
ist, dass PHP bei Überschreiten von `post_max_size` **nicht sauber scheitert**: Die
Anfrage kommt mit leerem `$_POST` an, der Endpunkt sieht ein fehlendes Pflichtfeld und
meldet Unsinn.

Deshalb: Der Endpunkt liest `post_max_size` und `upload_max_filesize` aus der
Serverkonfiguration und meldet gegen *die* — mit Zahl und Grund („XML ist 9 MB, dieser
Server nimmt höchstens 8 MB — bitte in zwei Aufrufe teilen").

Abweichung zu Spec 0016, wo der Kontextbereich eine feste 1-MB-Grenze hat: Dort ist die
Grenze eine **inhaltliche Aussage** über Arbeitsdateien (ein Journal, das 1 MB überschreitet,
gehört rotiert). Hier gibt es keine solche Aussage — 1 MB Text-XML sind grob 500 bis 1.000
Fragen, und wer die in einem Aufruf schickt, tut nichts Falsches.

---

## 10. Änderungsverlauf

ADR 0018 gilt; diese Spec klärt nur, was in ihrem Bereich einen Stand auslöst.

| Vorgang | Stand |
|---|---|
| **Klon** | Version 1 mit `quelle: "geklont"`, Quell-`cmid` im Stand vermerkt |
| **Slot-Operation** (`add_questions_to_quiz`) | Stand über die 16 `mod_quiz`-Struktur-Ereignisse — bereits in Spec 0015 §10.2 vorgesehen, hier nur bestätigt |
| **`mod_qbank`-Modul** aus `ensure_question_bank` | regulär, ohne Sonderregel — es ist ein Modul wie jedes andere |
| **Fragen selbst** | **nie** |

Fragen bekommen keinen Kurspilot-Verlauf, weil die Fragenbank mit ADR 0001 bereits eine
eigene, bessere Versionierung hat. Ein zweiter Verlauf daneben wäre die dritte Wahrheit
über denselben Gegenstand — genau das, was ADR 0015 für die Identitätsfrage aufgeräumt hat.

Der Klon-Stand mit `quelle: "geklont"` ist die dritte Herkunftsart neben `"angelegt"` und
`"vorgefunden"` (Spec 0015 §10.3) und macht im Verlauf sichtbar, dass diese Aktivität
nicht von Hand entstanden ist.

---

## 11. Freigabe, Rechte, Protokollierung

Unverändert aus Spec 0015 §9 übernommen:

- **Freigabe clientseitig je Schreibvorgang**, kein serverseitiges Tor. Das
  Verdachtsfall-Gate (§7.1) ist etwas anderes und liegt daneben: es schützt die
  Abstammung, nicht die Absicht.
- **Rechte** ausschließlich über native Moodle-Capabilities im jeweiligen Kontext:
  `moodle/question:add` und `moodle/question:managecategory` im Kategoriekontext,
  `moodle/course:manageactivities` im Kurskontext, für den kursübergreifenden Klon
  zusätzlich `moodle/backup:backuptargetimport` im Quell- und
  `moodle/restore:restoretargetimport` im Zielkurs. Keine Zusatz-Capability.
- **Die Antwort ist die Änderungsmeldung** in Lehrkraft-Deutsch.
- **Protokollierung** über die vorhandenen `tool_access_*`-Events. Protokolliert werden
  Vorgang und Bezugsgrößen (Kategorie, Bank-Eintrag, `cmid`), **nie der Frageninhalt**.

---

## 12. Umsetzungsphasen

Strikt seriell.

### Phase 1 — Fragenbank-Struktur

- `ensure_question_bank` portieren
- `ensure_question_category` (zusammengezogen aus `create`/`update`)
- `update_question_category` auf Umbenennen/Verschieben verengen
- `move_question` portieren, mit Verdachtsfall-Gate **vor** dem Umzug (§7.1)
- Gemeinsames Antwortformat des Gates einmal bauen

### Phase 2 — XML-Kern

- Kern: `qformat_xml` parsen, `save_question()` schreiben, Transaktion
- Round-Trip-Prüfung mit Kernfeld-Vergleich und Rollback (§2.3)
- `import_questions_xml` auf dem Kern
- `export_questions_xml` mit Datei-Platzhalter (§4.2, §6)
- `<file>`-Sperre mit Meldung (§6)
- Serverkonfigurations-Grenze mit verständlicher Meldung (§9)

### Phase 3 — MC-Fassaden

- `create_mc_question`: typisierte Felder → serverseitig gebaute XML → Kern
- `update_mc_question`: read-modify-write über Export, Patch, Kern (§2.2)
- `idnumber`-Vergabe beim Anlegen, Backfill beim ersten Schreibzugriff (§7.2)

### Phase 4 — Quiz-Anschluss

- `add_questions_to_quiz` portieren, Dublettenprüfung, Slot-Rückgabe
- `quiz_has_attempts()`-Gate (§4.3)

### Phase 5 — Klonen

- `clone_activity` als ein Endpunkt für beide Pfade (§4.4)
- Dangling-`availability`-Bereinigung mit Klartext-Meldung (§8)
- Klon-Nachbereitung: Abstammungs-Meldung je Frage (§7.3)
- Verlauf-Stand `quelle: "geklont"` (§10)

### Phase 6 — Skill-Regeln

- Fragetyp-Ablage: Pfad, Gliederung, Schreibangebot (§3)
- Lernschleife: Bestandssuche, drei Versuche, Vorlagenanforderung, Transparenz (§5)
- Widerspruchsprüfung vor jedem Bau (§5.2)
- Weitergabe dokumentieren (§3.4)

---

## 13. Begriffe

Neu oder geschärft; gehören ins Glossar:

- **XML-Kern** — der eine Schreibweg in die Fragenbank, über den alle Fragen-Werkzeuge
  laufen.
- **Fassade** — ein Werkzeug mit typisierten Feldern, das seine XML serverseitig baut und
  durch den Kern schreibt.
- **Round-Trip-Prüfung** — Wiederauslesen der frisch geschriebenen Frage und Vergleich mit
  der Eingabe; Abweichung rollt zurück.
- **Fragetyp-Ablage** — Kontextdatei je Fragetyp mit dem, was über sein XML gelernt wurde.
- **Lernschleife** — der Ablauf aus Probieren, Rollback, Korrigieren und Merken.
- **Verdachtsfall-Gate** — die Regel, dass bei ungeklärter Abstammung nichts geschrieben
  wird.
- **Abstammungs-Meldung** — die Auskunft nach einem Klon, welche Frage Kopie und welche
  geteilte Referenz ist.

---

## Fog of war — bewusst nicht Teil dieser Spec

- **Weitere typisierte Fassaden** (Wahr/Falsch, Kurzantwort). Kosten je eine
  Feldabbildung; wird ticketfähig, wenn sich zeigt, dass der XML-Weg für diese Typen in
  der Praxis hakt.
- **Verteilung der Fragetyp-Ablagen zwischen Lehrkräften** über den Core-Weg hinaus —
  Verzeichnis, Kuratierung, Versionsabgleich. Eigenes Vorhaben, und genau die
  Wartungslast, die §3 ausschließt.
- **STACK-Read-back über `get_question`** (Spec 0014, Out of Scope). Der Importweg ist
  davon unabhängig.
- **Fragen löschen oder Bank-Einträge mergen.** Nicht-destruktiver Ansatz bleibt.

## Out of scope

- **Bilder, eingebettete Dateien, jeder Binärtransport** — Spec 0018. Hier gesperrt und
  begründet (§6), mit benannter Auflage (§6.1).
- **`upload_question_image`** — Spec 0018.
- **Quiz-Anordnung als Werkzeug** (Entfernen, Umsortieren, Seitenumbrüche) — bleibt
  Moodle-UI, siehe §4.3 und #365.
- **`plan_question_category_cleanup`** und die übrigen Cleanup-Ports — Spec 0019.
- **Skill-Verteilung** — Spec 0020. Die Skill-Regeln aus §3 und §5 gehören inhaltlich in
  diese Spec, weil sie ohne den Round-Trip nicht formulierbar wären; ihre Verteilung ist
  0020.
- **Kontextbereich-Mechanik** — Spec 0016. Diese Spec nutzt `write_context_file`, ändert
  daran nichts.
- **Import anderer Formate als Moodle-XML** (GIFT, Aiken, Blackboard).
- **Lernendendaten** — Testversuche, Bewertungen, Abgaben: weder lesend noch schreibend.
  `quiz_has_attempts()` wird abgefragt, aber kein Versuch gelesen.
- **Implementierung.** Die Karte endet bei den freigegebenen Specs.

## Quellenkarte

| Abschnitt | Ticket |
|---|---|
| §1, §2 | [#354 Fragen-Identität](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/354), [#347 Vehikel-Recherche](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/347), Spec 0014 |
| §2.2 | [#355 Feldkatalog-Recherche](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/355) — Fehlerklasse „fehlender Wert" |
| §2.5, §11 | [#350 Schreibpfad und Freigabemodell](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/350) |
| §3 | Spec 0016 (Kontextbereich), [#361 Ablageort](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/361) |
| §4.3 | [#365 Quiz-Anordnung](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/365) |
| §4.4, §8 | Spec 0013, [#332 dangling restriction](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/332) |
| §6.1 | [#351 Ersetzungsschwelle](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351) — Auflage an Spec 0018 |
| §7 | ADR 0015, ADR 0001 |
| §10 | ADR 0018, Spec 0015 §10 |

Bei Detailfragen: Ticket zoomen, nicht diese Spec erweitern.
