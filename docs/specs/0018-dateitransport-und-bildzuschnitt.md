# Spec 0018 — Dateitransport und Bildzuschnitt: Materialordner, Verweis, GD

*Karte: [Voller Funktionsumfang für `local_kurspilot`](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/346) · Ticket: [#373](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/373) · Viertes von sechs Specs des Zuschnitts [#359](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/359)*

> **Umgesetzt wird gegen [#427](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/427), nicht gegen dieses Dokument.**
> Das Issue trägt die verbindliche Form — User Stories, Abnahmekriterien je Phase als Haken.
> Dieses Dokument beantwortet das *Warum* und ist Nachschlagewerk, keine zweite
> Anforderungsquelle: **eine Anforderung, die nur hier steht, gilt als nicht beauftragt.**
> Wer in #427 eine Entscheidung umstößt, zieht dieses Dokument nach —
> sonst driften Begründung und Bau auseinander.

## Ziel

Der Schreibkern (Spec 0015) kann Aktivitäten anlegen und ändern, der Kontextbereich
(Spec 0016) kann Arbeitsdateien fortschreiben, die Fragenbank (Spec 0017) kann Fragen
und Quizze strukturell füllen. Was in allen dreien fehlt, ist **alles Binäre**: Spec 0015
hat die Dateifelder von `resource`, `folder` und `assign` katalogisiert und auf die
Sperrliste gesetzt (§4.3), Spec 0017 weist XML mit eingebetteten `<file>`-Blöcken ab und
exportiert Dateien als Platzhalter (§6).

Diese Spec hebt beide Sperren auf und bringt eine Fähigkeit dazu, die es serverseitig
noch nie gab: den **Bildzuschnitt**.

Leitmotiv: *das Bild soll bei Moodle ankommen, nicht beim Modell.* Der Weg dorthin ist
nicht „nie durch den Kontext" (das bricht Zuschnitt und Alt-Text) und nicht „immer durch
den Kontext" (das kostet bei Massendaten unvertretbar), sondern **die Vorschau in den
Kontext, das Original nicht.**

`local_kurspilot` bleibt eigenständiger Neubau ohne Abhängigkeit zu `local_coursepilot`
(Spec 0012 §9.2). Der lokale Weg läuft unverändert weiter.

**Namenskonvention** (Bestand): MCP-Werkzeug `kurspilot_<name>`, Webservice
`local_kurspilot_<name>`, Klasse `\local_kurspilot\external\<name>`.

---

## 1. Die Auflage aus Spec 0017 — erfüllt, aber nicht wörtlich

Spec 0017 §6.1 hat eine Auflage hinterlassen, die diese Spec ausdrücklich „entscheiden,
nicht stillschweigend fortschreiben" muss: Bilder sollen den KI-Kontext nie passieren,
weil base64 durch das Kontextfenster ein Kostenproblem ist — „ein paar hundert Kilobyte je
Bild, bei hundert Bildern Megabyte an Token, für Inhalt, den das Modell nie lesen muss."

Die Auflage steht gegen die Festlegung aus
[#351](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351), die Datei-Tools
„mit Vertragsänderung auf Chat-Anhang/Base64" zu belassen.

**Die Entscheidung: Die Auflage gewinnt in der Sache, verliert im Wortlaut.** Drei Befunde
zwingen dazu.

### 1.1 Die Prämisse der Auflage stimmt nicht

Die Auflage sagt, das Bild liege „seit Spec 0016 im Kontextbereich unter Private Files".
Spec 0016 §5.1 lässt **nur `.md`-Dateien** zu, und §5.5 sagt wörtlich: *„Bilder und Material
haben im Kontextbereich keinen Platz; `.md`-only-Grenze (§5.1) schließt das technisch aus."*

Der Verweisweg der Auflage hat also gar keinen Ablageort. Er muss erst einen bekommen —
das ist §2.

### 1.2 Zuschnitt und Alt-Text setzen Bildkenntnis voraus

Der Zuschnitt ist im Glossar als **Gezielter Bildausschnitt** definiert: „ein aus dem
Originalmaterial herausgeschnittener Bildbereich, der nur die fachlich benötigte Abbildung
enthält", ausdrücklich gegen „ganze Schulbuchseite als Bild". Und: „Jede **Fachabbildung**
braucht einen **Alt-Text**" (Glossar, KI-Qualitätsroutine).

Beides verlangt, dass das Modell das Bild **sieht**. Ein Modell, dem nur ein Dateiname
gereicht wird, kann weder einen Ausschnitt wählen noch beschreiben, was darauf zu sehen
ist. Eine Auflage, die Bildkenntnis vollständig ausschließt, streicht damit zwei Fähigkeiten,
die diese Spec liefern soll.

### 1.3 Die Auflage widerspricht sich beim Export

Die Auflage verlangt, „der Export ersetzt umgekehrt base64 durch Verweise". Eine
Moodle-XML mit Verweisen statt `<file … encoding="base64">` ist **kein gültiges Moodle-XML
mehr** — sie lässt sich in kein anderes Moodle importieren. Damit wäre genau der
Weitergabezweck kaputt, den Spec 0017 §4.2 an diese Spec delegiert hat („Für die Weitergabe
ist es unvollständig … der vollständige Weg kommt mit Spec 0018").

### 1.4 Die Auflösung

| Auflage wörtlich | Diese Spec | Wo |
|---|---|---|
| Bild passiert den Kontext nie | **Original** passiert den Kontext nie, eine **verkleinerte Vorschau** schon | §3 |
| KI nennt nur den Namen, Server löst auf | Ja — als **Normalweg**; base64 bleibt als Ausnahme für Chat-Anhänge | §4 |
| Export ersetzt base64 durch Verweise | Export schreibt die **vollständige, standardkonforme XML als Datei** ab, das Modell bekommt den Pfad | §7.2 |

Das Kostenziel der Auflage ist damit erreicht: bei hundert importierten Bildern fließt kein
einziges Byte Bildinhalt durch den Kontext. Nur dort, wo die Lehrkraft selbst ein Bild in
den Chat zieht, liegt es ohnehin schon dort — und ein Verweisweg spart nichts, sondern
erzwingt bloß einen Umweg über die Moodle-Oberfläche.

---

## 2. Der Materialordner

### 2.1 Ein Geschwisterordner, kein aufgeweiteter Kontextbereich

Binärdateien brauchen einen Ort in Private Files. Drei Wege standen zur Wahl: den
Kurspilot-Kontextordner für Binärdateien öffnen, einen eigenen Ordner danebenlegen, oder
ganz Private Files verweisbar machen.

**Entscheidung: ein eigener Geschwisterordner.**

| | Ort | Typregel |
|---|---|---|
| Kontextbereich (Spec 0016) | `user/private/<contextroot>/` | nur `.md` |
| **Materialordner** (neu) | `user/private/<materialroot>/` | Binärdateien nach Whitelist (§6) |

Beide Wurzelnamen sind konfigurierbar; der Kontextbereich hat dafür bereits das Setting
`local_kurspilot/contextroot` (Default `kurspilot`, Spec 0016 §1.2), der Materialordner
bekommt sein Gegenstück (Default `kurspilot-material`).

Den Kontextordner aufzuweiten hätte eine Grenze verwischt, die Spec 0016 **inhaltlich**
begründet hat — der Kontextbereich ist der Ort für Planungs- und Protokolldateien, und die
`.md`-Regel ist die technische Form dieser Aussage. Ganz Private Files verweisbar zu machen
fällt aus: die Isolierungszusage aus Spec 0016 §1 („Kein anderer Unterordner in Private
Files ist erreichbar") trägt den Datenschutz-Provider und den Personenbezug-Schalter.

### 2.2 Warum Private Files und nicht eine Plugin-Filearea

Eine plugin-eigene Filearea (`local_kurspilot/material`) zählte **nicht** gegen die
Nutzerquote und würde das Platzproblem aus §8 auf einen Schlag lösen. Sie fällt trotzdem
aus, und zwar aus demselben Grund, aus dem Spec 0016 §1.1 den Kontextbereich überhaupt
nach `user/private` umgezogen hat: eine plugin-eigene Filearea ist für die Lehrkraft „in
der Moodle-Oberfläche nicht auffindbar" — nicht von Hand aufräumbar, nicht über den
Dateiwähler greifbar, nicht ohne Kurspilot zu retten.

Der Materialordner ist eine **Zwischenstation**, und eine Zwischenstation, die man nicht
öffnen kann, ist eine Falle. Das Platzproblem wird stattdessen sichtbar gemacht und
aufräumbar (§8), und mittelfristig anders gelöst (§10).

### 2.3 Der Ort ist auswechselbar

Mittelfristig soll der Kurspilot-Bereich in ein **angebundenes Repository** wandern
(Nextcloud, WebDAV), um die Moodle-Nutzerquote ganz zu verlassen — Moodle hat dafür
Speicherplatz-Not, die Nextcloud der Schule nicht. Das ist **nicht Teil dieser Spec** (§10),
aber es ist eine Auflage **an** diese Spec.

Moodle trägt den späteren Wechsel bereits: `repository_nextcloud` und `repository_webdav`
liegen in Core, und der Dateipool kennt externe Referenzen (`stored_file::is_external_file()`,
`referencefileid`). Der Wechsel ist damit ein **Ortswechsel, kein Umbau** — vorausgesetzt,
diese Spec verdrahtet den Ort nicht fest.

**Auflage:** Der Materialordner bekommt einen Auflöser nach dem Muster von
`context_files` (Spec 0016 §1.2). Dort halten `COMPONENT`, `FILEAREA` und `ITEMID` als
Konstanten den Ablageort, und der Umzug von der eigenen Filearea nach `user/private` änderte
**genau diese drei** — der Rest der Klasse trug unverändert. Der Materialordner erbt diese
Bauform: Ort als Konstantensatz hinter einem Auflöser, nicht verstreut über die
Endpunkte. Kein Endpunkt kennt Component oder Filearea.

Das kostet jetzt nichts und macht die Repository-Anbindung später zu einer Änderung an
einer Stelle mit einem Test, der sie abdeckt (§11).

### 2.4 Pfad- und Verweisform

Übernommen aus Spec 0016 §5.1/§5.3, keine neue Konvention:

- Pfad relativ zur Materialordner-Wurzel; Segmente aus `[A-Za-z0-9_-]`, `.` und `..`
  abgewiesen (bestehende `segments()`-Prüfung).
- Optional `expected_contenthash` als Gleichzeitigkeitsschutz — dieselbe Semantik wie bei
  `write_context_file`.

Erweiterung gegenüber dem Kontextbereich: die Dateiendung entscheidet über die Zulässigkeit
(§6), nicht die `.md`-Regel.

---

## 3. Vorschau statt Original

### 3.1 Die Entscheidung

Das Modell bekommt eine **verkleinerte Vorschau** der Materialdatei in den Kontext:
längste Kante 768 px, JPEG. Es nennt darauf **relative** Koordinaten (0–1); GD schneidet
serverseitig aus dem **Original** in voller Auflösung.

Damit sind beide Ziele erfüllt, die sich in §1.2 widersprachen: das Modell sieht genug, um
einen Ausschnitt zu wählen und einen Alt-Text zu schreiben, und der Kostenfaktor liegt ein
bis zwei Größenordnungen unter dem Originaltransport. Ein 4-MB-Screenshot einer Buchseite
wird zu wenigen zehn Kilobyte.

Relative Koordinaten statt Pixel, damit die Vorschaugröße eine reine Serverentscheidung
bleibt: ändert sie sich, ändert sich nichts am Vertrag zur KI.

### 3.2 Der Dispatcher braucht einen zweiten Inhaltstyp

Heute liefert der Dispatcher **ausnahmslos** `type: 'text'` mit JSON plus
`structuredContent`. Ein Bild gibt es dort nicht.

MCP erlaubt Bildblöcke in Werkzeugergebnissen (base64 plus `mimeType`). Der Dispatcher
bekommt genau das als zweiten Inhaltstyp. Zwei Alternativen wurden verworfen:

- **base64 als Feld im JSON.** Kein Dispatcher-Umbau — aber das Modell bekäme eine
  Zeichenkette, kein Bild. Zuschnitt und Alt-Text funktionieren damit nicht. Scheinlösung.
- **MCP-Ressourcen.** Der Server bietet die Vorschau als Ressource an, der Client bindet
  sie ein. Clientabhängig, und `CONTEXT.md` macht die Zuverlässigkeit in Codex zur
  Produktanforderung — ein Verhalten, das dort nicht zugesichert ist, trägt diese Fähigkeit
  nicht.

Der zweite Inhaltstyp bleibt die **einzige** Erweiterung am Dispatcher; alle anderen
Antworten bleiben unverändert Text plus `structuredContent`.

### 3.3 GD kann nicht fehlen

Das Ticket sah einen Fallback vor („ohne GD klare Meldung statt Fehler"). Der ist fast
gegenstandslos: Moodles `admin/environment.xml` führt GD für **jede** Moodle-Version als
`level="required"`. Ein Moodle ohne GD installiert und upgraded nicht.

Also: eine defensive Prüfung mit klarer Meldung, kein Fallback-Design, kein zweiter
Bildpfad, kein Fähigkeitsabbau-Zweig. Falls GD wider Erwarten fehlt, sind Vorschau **und**
Zuschnitt gesperrt (beide brauchen es); Hochladen und Einbetten laufen weiter, ohne
Ausschnitt und mit Alt-Text von der Lehrkraft.

---

## 4. Transportweg: Verweis als Normalweg, base64 als Ausnahme

### 4.1 Die Herkunft entscheidet, nicht das Werkzeug

Es gibt drei Herkünfte, und sie verhalten sich verschieden:

| Herkunft | Liegt die Datei schon im Kontext? | Weg |
|---|---|---|
| Screenshot, den die Lehrkraft ins Chatfenster zieht | **ja**, unvermeidlich — so wird sie geliefert | base64 |
| Datei liegt bereits in Moodle | nein | Verweis |
| Fremder XML-Export mit hunderten `<file>`-Blöcken | nein, und darf nie | Verweis |

Ein reiner Verweisweg (Auflage wörtlich) bricht die erste Zeile: die Lehrkraft müsste erst
in die Moodle-Oberfläche wechseln und hochladen, bevor die KI etwas mit dem Bild anfangen
kann — für eine Ersparnis, die es dort gar nicht gibt, weil die Datei mit dem Chat-Anhang
bereits im Kontext ist.

Ein reiner base64-Weg bricht die dritte Zeile und damit den Massenimport.

### 4.2 Eine Eintrittstür

**Ein Chat-Anhang landet immer erst im Materialordner**, nie direkt an der Aktivität.
Danach übernimmt ausnahmslos der Verweisweg.

Das ist ein Aufruf mehr für den einfachen Fall („PDF an die Aufgabe hängen"), und zahlt
dreifach zurück:

- **Zuschnitt geht immer.** Der Zuschnitt arbeitet auf liegenden Materialdateien (§5);
  eine direkt eingebettete Datei wäre nie zuschneidbar.
- **Der Papierkorb hat einen Ort.** Ersetzte Dateien (§9) brauchen eine Ablage, die nicht
  im Kursinhalt liegt.
- **Kein Verlust im Fehlerfall.** „Hochgeladen, aber Ziel abgelehnt" verliert die Datei
  nicht mehr — sie liegt im Materialordner und der nächste Versuch verweist darauf.

Nach dem Verweisweg ist der Datenfluss für alle Herkünfte identisch. Genau ein Pfad ab
dem Materialordner, keine Verzweigung in den Endpunkten.

---

## 5. Zuschnitt als eigener Endpunkt

Der Zuschnitt wird **kein Parameter an den Uploadwerkzeugen**, sondern ein Endpunkt, der
eine im Materialordner liegende Datei beschneidet und das Ergebnis wieder dort ablegt.

Grund: Mit dem Materialordner (§2) und der einen Eintrittstür (§4.2) ist Zuschneiden ein
Vorgang **auf einer liegenden Datei**, nicht ein Zusatz an einem Transport. Das ist ein
Endpunkt statt vier Parametersätze — und der Zuschnitt wird wiederholbar, ohne neu
hochzuladen. Sitzt der Ausschnitt nicht, kostet der zweite Versuch einen Aufruf statt eines
zweiten Uploads.

Ablauf aus Sicht der Lehrkraft:

1. Screenshot in den Chat ziehen → landet im Materialordner (§4.2)
2. Vorschau ansehen lassen → Modell sieht das Bild (§3)
3. Ausschnitt wählen → neue Datei im Materialordner, Alt-Text vom Modell vorgeschlagen
4. Ausschnitt in Aufgabe oder Frage einbetten → Verweisweg

Die Herkunft des Ausschnitts wird in Moodles vorhandenem `source`-Feld der Datei
mitgeführt — kein neues Feld, keine Tabelle, und §8.2 kann später sagen, woher ein
Ausschnitt stammt.

**SVG kann nicht zugeschnitten werden.** GD ist raster-only; ein Zuschnittversuch auf SVG
wird mit klarer Meldung abgewiesen, statt still etwas anderes zu tun.

---

## 6. Dateitypen: die Bestandslisten, unverändert

Der lokale Weg führt bereits zwei Whitelists — eine für allgemeine Uploads (PDF, DOC/DOCX,
XLS/XLSX, PPT/PPTX, HTML, PNG, JPG) und eine engere für einbettbare Bilder (PNG, JPG, GIF,
SVG, WEBP). Beide werden **unverändert übernommen**.

**SVG bleibt drin.** Es zu streichen würde Kurspilot **restriktiver** machen als Moodles
eigenen Dateiwähler: dieselbe Lehrkraft darf dieselbe SVG jederzeit von Hand hochladen.
Kurspilot darf nicht mehr dürfen als die Lehrkraft in der Oberfläche — mehr Verbieten ist
aber kein Sicherheitsgewinn, sondern nur ein verlorenes Diagrammformat.

Moodles eigene Dateityp-Einstellung je Aktivität kommt als Regelquelle nicht in Frage: sie
regelt **Abgaben von Lernenden**, nicht Uploads der Lehrkraft.

---

## 7. Fragenbank: die Sperre aus Spec 0017 fällt

### 7.1 Import — zwei Türen

`import_questions_xml` nimmt **entweder** XML als Text **oder** einen Verweis auf eine
XML-Datei im Materialordner. Genau eins von beidem je Aufruf.

| Tür | Wofür | `<file>`-Blöcke |
|---|---|---|
| XML als Text | Die KI schreibt die XML selbst — STACK, exotische Fragetypen (Spec 0017 §3) | tragen einen **Materialordner-Namen**, der Server baut daraus den base64-Block |
| Verweis auf eine XML-Datei | Massenimport eines fremden Moodle-Exports | tragen **echtes base64**, rein serverseitig verarbeitet |

Zwei Türen, weil zwei belegte Fälle sich nicht gegenseitig abdecken. Nur der Verweisweg
zu bauen streicht die Fähigkeit, dass die KI eine Frage mit Bild selbst schreibt — genau
den Weg, den Spec 0017 §3 für beliebige Fragetypen aufgemacht hat. Nur den Textweg zu
bauen streicht den Massenimport, der der eigentliche Anlass der Auflage war.

Die Abweisung aus Spec 0017 §6 („XML mit eingebetteten `<file>`-Blöcken wird abgewiesen")
entfällt damit auf beiden Türen.

### 7.2 Export — die Datei ist das Ergebnis, nicht der Kontext

`export_questions_xml` schreibt die **vollständige, standardkonforme** Moodle-XML — mit
echtem base64 in den `<file>`-Blöcken — als Datei in den Materialordner. Das Modell bekommt
nur den Pfad zurück.

Beide Ziele auf einmal: die Weitergabe funktioniert (die XML lässt sich in jedes andere
Moodle importieren, was Spec 0017 §4.2 ausdrücklich als offen an diese Spec übergeben hat),
und kein Bildbyte passiert den Kontext.

Der **Platzhalter-Export** aus Spec 0017 §4.2 bleibt erhalten — als Schalter am selben
Endpunkt, nicht als Standard. Für den Vorlagenzweck („die KI soll die Struktur lernen,
nicht 400 KB Bild") ist er weiterhin die richtige Form.

---

## 8. Aufräumen und Quote

### 8.1 Die Quote ist das eigentliche Problem

Der Materialordner ist eine Zwischenstation, aber Zwischenstationen wachsen. Lehrkräfte
haben in Moodle typischerweise 100 MB Private Files — mit Screenshots und Arbeitsblättern
ist das schnell voll, und Moodle ist bei Speicherplatz grundsätzlich knapper als die
Nextcloud der Schule (§10).

**Entscheidung: in der Nutzerquote bleiben.** Kein automatisches Löschen, keine
plugin-eigene Filearea an der Quote vorbei (§2.2).

Stattdessen drei Dinge:

- **Warnung** unter 10 % Restplatz, mit Restplatz in MB — exakt die Form, die Spec 0016
  §5.4 für den Kontextbereich bereits eingeführt hat.
- **Harter Fehler** bei voller Quote, mit Verweis auf die Aufräumroutine.
- **Empfehlung an den Admin** in der Dokumentation, die Nutzerquote anzuheben. Bei einer
  selbst betriebenen Instanz ist das ein Einstellungsfeld.

### 8.2 „Lose" heißt: nirgends verwendet

Eine Materialdatei gilt als **verwendet**, wenn ihr `contenthash` in einer
Aktivitäts-Filearea der eigenen Kurse auftaucht — sonst als **lose**.

Moodles Dateipool ist contenthash-basiert und das Feld indiziert; die Prüfung ist eine
Abfrage je Datei, ohne neue Tabelle und ohne Zustand, der driften kann. Die beiden
Alternativen fallen aus: eine eigene Verwendungstabelle driftet, sobald jemand von Hand
löscht, und eine Altersregel („älter als N Tage") würde eine oft wiederverwendete Vorlage
als lose melden.

**Ein Sonderfall, bewusst so:** Ein Zuschnitt hat einen anderen `contenthash` als sein
Original, also erscheint das Original nach dem Zuschneiden als lose. Das ist die richtige
Auskunft — der Screenshot der ganzen Buchseite *ist* entbehrlich, sobald der Ausschnitt
eingebettet ist; das Glossar nennt genau das als *Avoid* („ganze Schulbuchseite als Bild").
Woher ein Ausschnitt stammt, steht im `source`-Feld (§5), falls die Lehrkraft neu
zuschneiden will.

### 8.3 Gefragt wird im Skill, nicht im Plugin

Die Aufräumfrage gehört ans Ende eines abgeschlossenen Aufbaus — „im Material liegen noch
N Dateien (M MB), die in keiner Aktivität verwendet werden: … löschen?".

Das kann der Server nicht auslösen. Spec 0016 §7 hat exakt diese Frage schon entschieden:
*„,bei Sitzungsstart prüfen' ist ein Sitzungsbegriff — der Server hat kein Session-Konzept.
Das Plugin liefert die Fakten, der Skill prüft und fragt nach."*

Also dieselbe Arbeitsteilung: Das Plugin liefert **Bericht** (welche Dateien sind lose, wie
groß, wie alt, Restquote) und **Löschweg**. `SKILL.md` trägt die Regel, wann gefragt wird.
Gelöscht wird nur auf ausdrückliche Antwort.

---

## 9. Änderungsverlauf

### 9.1 Aktivitätsdateien: verdrängen statt löschen

Spec 0015 §10.4 hält fest, dass der Änderungsverlauf Datei-**Metadaten, keine Bytes**
speichert, und dass „Dateiinhalte außerhalb des Intro nicht rückschreibbar sind und im
Stand als Lücke markiert" werden. Das war zulässig, solange Dateien gesperrt waren. Mit
dieser Spec wird die Lücke real: die KI kann die Hauptdatei einer `resource` ersetzen, und
der Rollback holt sie nicht zurück.

**Entscheidung: die ersetzte Datei wandert in einen Papierkorb-Bereich statt gelöscht zu
werden.** Das kostet **null Byte**: Moodles Dateipool ist contenthash-basiert, es entsteht
nur ein zweiter Datensatz auf denselben Inhalt. Der Rollback aus ADR 0018 wird damit für
Dateien echt.

Ersetzen ganz zu verbieten (nur Hinzufügen) wäre die Alternative gewesen — das ist aber
eine Fähigkeitslücke gegenüber dem lokalen Weg und damit gegen die Ersetzungsschwelle
(#351).

### 9.2 Materialordner: Protokoll ohne Bytes

Für den Materialordner selbst gilt etwas anderes, weil sich hier zwei Anliegen
entgegenstehen: ein Verlauf will aufheben, die Quote (§8.1) will loswerden. Ein
bytehaltender Verlauf für einen Ordner, der ohnehin zum Volllaufen neigt, verdoppelt genau
das Problem.

**Entscheidung: ein Protokoll, keine Stände.** Was wurde wann hochgeladen, zugeschnitten,
gelöscht, wohin verwendet. Das Plugin hat den Mechanismus bereits: `access_log` feuert bei
jedem Schreibwerkzeug ein Moodle-Event mit dem Werkzeugnamen — es fehlt nur das Ziel. Das
Ereignis um den Pfad zu erweitern ist eine Zeile, kein neues Schema.

Wiederherstellen kann ein Protokoll nicht. Für eine Zwischenstation ist das verkraftbar:
die Datei liegt nach der Verwendung ohnehin in der Aktivität, und dort greift §9.1.

Ein Papierkorb wie in §9.1 hilft hier nicht — bei einer *gelöschten* Materialdatei bleibt
der Inhalt liegen und zählt weiter gegen die Quote. Er würde §8 direkt entgegenarbeiten.

---

## 10. Was jetzt nicht gebaut wird, aber vorbereitet ist

**Die Repository-Anbindung** — Kurspilot-Bereich in Nextcloud oder WebDAV statt in der
Moodle-Nutzerquote — ist eine benannte, hoch priorisierte Absicht für die Zeit **nach** den
ersten Versionen. Sie ist keine Parität zum lokalen Weg, sondern eine neue Fähigkeit; sie
gehört damit nicht in die Karte #346, sondern in ein eigenes Vorhaben danach.

Was diese Spec dafür leistet, und mehr nicht: der Ablageort steckt hinter einem Auflöser mit
Konstantensatz (§2.3), so dass der Wechsel **eine** Änderung an **einer** Stelle ist, mit
einem Test, der sie abdeckt (§11).

---

## 11. Prüfnähte

Vier Nähte, drei davon bestehend. Geprüft wird äußeres Verhalten — was ein Endpunkt
zurückgibt und was danach in Dateipool und Datenbank steht —, nicht die innere Zerlegung.

| Naht | Art | Deckt ab |
|---|---|---|
| `tests/external/<endpunkt>_test.php` | bestehend, dominant (je Endpunkt einer) | Alle neuen und geänderten Endpunkte: Upload in den Materialordner, Vorschau, Zuschnitt, Einbetten, Import/Export beider Türen, `create_module` für `resource`, Aufräumbericht und Löschweg |
| `tests/catalog/resource_…`, `folder_…`, `assign_catalog_contract_test.php` | bestehend | Aufhebung der Dateifeld-Sperrliste aus Spec 0015 §4.3 — dieselben Verträge, die die Sperre eingeführt haben, belegen ihr Ende |
| `tests/dispatcher_test.php` | bestehend | Der zweite Inhaltstyp (§3.2): Bildblock korrekt geformt, alle übrigen Antworten unverändert Text plus `structuredContent` |
| Materialordner-Auflöser | **neu**, Geschwister zu `context_files_test.php` | Pfadregeln, Konstantensatz und Ortswechsel (§2.3), `contenthash`-Abgleich für „lose" (§8.2), Quotengrenzen (§8.1), Papierkorb (§9.1) |

Die eine neue Naht hält den Materialordner an **einer** Stelle prüfbar, statt sein Verhalten
über die Endpunkttests zu verstreuen. Ihr direktes Vorbild ist `context_files_test.php`,
das für den Kontextbereich genau dasselbe tut. Der Ortswechsel aus §2.3 bekommt damit einen
Test, der ihn abdeckt, ohne dass ein einziger Endpunkttest angefasst werden muss.

---

## 12. Umsetzungsphasen

### Phase 1 — Materialordner

- Auflöser mit Konstantensatz (Component/Filearea/Itemid/Wurzel), Setting für den
  Wurzelnamen
- Pfadregeln, `expected_contenthash`, Dateityp-Whitelists
- Quotenprüfung und Warnschwelle
- Privacy-Provider-Anschluss analog Spec 0016 §3

### Phase 2 — Eintrittstür und Verweisweg

- Upload eines Chat-Anhangs in den Materialordner (base64, die Ausnahme aus §4)
- Auflösung eines Materialordner-Verweises serverseitig
- Papierkorb beim Ersetzen (§9.1)
- `access_log`-Ereignis um den Pfad erweitert (§9.2)

### Phase 3 — Vorschau und Zuschnitt

- Dispatcher: zweiter Inhaltstyp (§3.2)
- Vorschau-Erzeugung über GD, 768 px, JPEG; GD-Prüfung mit klarer Meldung
- Zuschnitt-Endpunkt mit relativen Koordinaten, Ergebnis in den Materialordner,
  Herkunft im `source`-Feld
- SVG-Abweisung beim Zuschnitt

### Phase 4 — Dateifelder entsperren

- Sperrliste aus Spec 0015 §4.3 aufheben: Dateifelder von `resource`, `folder`, `assign`
- `create_module` für `resource` mit Dateiverweis als Pflichtfeld
- Einbetten in Aufgaben-Intro und Fragetext/Antwortfeedback über den Verweisweg

### Phase 5 — Fragenbank entsperren

- `import_questions_xml`: zweite Tür (`xmlpath`), Namensverweise in `<file>`-Blöcken auf
  der Textür
- `export_questions_xml`: vollständige XML in den Materialordner, Platzhalter als Schalter
- Abweisung aus Spec 0017 §6 entfernen

### Phase 6 — Aufräumen

- Bericht über lose Dateien (`contenthash`-Abgleich, Größe, Alter, Restquote)
- Löschweg auf ausdrückliche Antwort
- `SKILL.md`: Aufräumfrage am Ende eines abgeschlossenen Aufbaus

---

## 13. Begriffe

| Begriff | Bedeutung in dieser Spec |
|---|---|
| **Materialordner** | Unterordner in Private Files der Lehrkraft für Binärdateien; Zwischenstation zwischen Chat und Aktivität. Geschwister des Kontextbereichs (Spec 0016) |
| **Vorschau** | Verkleinerte Fassung einer Materialdatei (längste Kante 768 px, JPEG), die dem Modell gezeigt wird, damit es Ausschnitt und Alt-Text bestimmen kann |
| **Verweis** | Pfad relativ zur Materialordner-Wurzel; der Server löst ihn auf, statt Bytes durch den Kontext zu tragen |
| **Lose Datei** | Materialdatei, deren `contenthash` in keiner Aktivitäts-Filearea der eigenen Kurse auftaucht |
| **Gezielter Bildausschnitt** | Glossarbegriff: der fachlich benötigte Bildbereich, herausgeschnitten aus dem Originalmaterial |

---

## Fog of war — bewusst nicht Teil dieser Spec

- **Weitere Bildoperationen** (drehen, skalieren, Wasserzeichen). GD kann sie, der Bedarf
  ist nicht belegt. Wird ticketfähig, wenn Lehrkräfte danach fragen.
- **Alt-Text-Qualität als Prüfregel.** Dass jede Fachabbildung einen Alt-Text braucht, ist
  Glossarregel und bleibt Skill-Sache — nach dem Muster aus Spec 0016 §5.5 („Klarnamen in
  unmarkierten Dateien ist Skill-Regel, kein Plugin-Zwang").
- **Dateien in weiteren Modultypen** über `resource`, `folder` und `assign` hinaus. Der
  Feldkatalog trägt sie; welche als nächste, entscheidet der Bedarf.
- **Vorschau für Nicht-Bilder** (erste PDF-Seite als Bild). Denkbar, aber ohne belegten
  Anlass.

## Out of scope

- **Repository-Anbindung** (Nextcloud, WebDAV) als Ablageort — eigenes Vorhaben nach den
  ersten Versionen (§10). Diese Spec bereitet nur den Ortswechsel vor (§2.3).
- **Abschaltung des lokalen Zuschnitts** (`image-crop.js`, sips/ImageMagick, ADR 0005).
  Der lokale Weg läuft unverändert weiter; wann er abgeschaltet wird, ist laut Karte #346
  eine spätere Entscheidung nach Abnahme der Umsetzung.
- **Eigene Größengrenze.** Übernommen aus Spec 0017 §9: keine eigene Zahl, gegen die
  Serverkonfiguration melden, mit Zahl und Grund.
- **Trockenlauf oder Vorschaumodus für Schreibvorgänge.** Dateien laufen durch dasselbe
  Freigabemodell wie alle Schreibvorgänge (#350), kein Sonderweg.
- **`plan_question_category_cleanup`** und die übrigen Cleanup-Ports — Spec 0019.
- **Skill-Verteilung** — Spec 0020. Die Aufräumregel aus §8.3 gehört inhaltlich hierher,
  weil sie ohne den Bericht nicht formulierbar wäre; ihre Verteilung ist 0020.
- **Lernendendaten.** Abgegebene Dateien von Lernenden werden weder gelesen noch
  geschrieben.
- **Implementierung.** Die Karte endet bei den freigegebenen Specs.

## Quellenkarte

| Abschnitt | Quelle |
|---|---|
| §1 | Spec 0017 §6.1 (Auflage), [#351 Ersetzungsschwelle](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351) |
| §1.2, §5 | `CONTEXT.md` — Gezielter Bildausschnitt, Fachabbildung, Alt-Text, KI-Qualitätsroutine |
| §2 | Spec 0016 §1.1/§1.2/§5.1/§5.5 (Ablageort, Isolierung, `.md`-Grenze) |
| §2.3, §10 | Moodle-Core: `repository_nextcloud`, `repository_webdav`, externe Dateireferenzen |
| §3.3 | Moodle `admin/environment.xml` — GD als `level="required"` |
| §4 | [#351](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351) gegen Spec 0017 §6.1 |
| §6 | Whitelists des lokalen Wegs (Bestand) |
| §7 | Spec 0017 §4.2, §6 |
| §8 | Spec 0016 §5.4 (Quotenwarnung), §7 (Skill prüft, Plugin liefert) |
| §9.1 | Spec 0015 §10.4, ADR 0018 |
| §9.2 | `access_log` (Bestand), Spec 0016 §6 |
| §11 | `context_files_test.php`, `dispatcher_test.php`, `tests/catalog/` (Bestand) |

Bei Detailfragen: Ticket zoomen, nicht diese Spec erweitern.
