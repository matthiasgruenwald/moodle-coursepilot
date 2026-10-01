# Spec 0019 — Cleanup-Ports: `plan_question_category_cleanup` portieren

*Karte: [Voller Funktionsumfang für `local_kurspilot`](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/346) · Ticket: [#374](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/374) · Fünftes von sechs Specs des Zuschnitts [#359](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/359)*

> **Umgesetzt wird gegen [#443](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/443), nicht gegen dieses Dokument.**
> Das Issue trägt die verbindliche Form — User Stories, Abnahmekriterien als Haken.
> Dieses Dokument beantwortet das *Warum* und ist Nachschlagewerk, keine zweite
> Anforderungsquelle: **eine Anforderung, die nur hier steht, gilt als nicht beauftragt.**

## Ziel

Von den sieben Lesewerkzeugen des lokalen Wegs hat genau eines kein serverseitiges
Gegenstück: `plan_question_category_cleanup`. Es ist nach dem Schnitt von Spec 333
entstanden ([#316](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/316))
und in keiner der Bestandsaufnahmen enthalten, die die Karte trägt. Diese Spec schließt
diese eine Lücke und beantwortet die zweite Frage des Tickets: ob der bereits portierte
Quiz-Bereinigungsplan eine Schreibperspektive braucht.

Das ist das kleinste Spec des Zuschnitts, und es soll klein bleiben. Es kommt zuletzt vor
der Skill-Verteilung, weil es die niedrigste Priorität der Ersetzungsschwelle trägt
([#351](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351)) — nach dem
Port ist die Lesefläche vollständig.

`local_kurspilot` bleibt eigenständiger Neubau ohne Abhängigkeit zu `local_coursepilot`
(Spec 0012 §9.2). Der lokale Weg läuft unverändert weiter.

**Namenskonvention** (Bestand): MCP-Werkzeug `kurspilot_<name>`, Webservice
`local_kurspilot_<name>`, Klasse `\local_kurspilot\external\<name>`.

---

## 1. Der Port: unveränderter Vertrag

`kurspilot_plan_question_category_cleanup` nimmt Kurs-ID und Fragensammlungs-CMID und
liefert den Namen der geprüften Fragensammlung sowie eine Liste leerer, blattloser
Kategorien — je mit ID, Name, Elternkategorie, direktem Moodle-Link und der
Handlungsanweisung, dass Kurspilot nichts löscht.

**Erkennungskriterium unverändert:** keine Einträge in der Fragenbank und keine
Unterkategorien; die oberste Kategorie der Sammlung ist ausgenommen.

**Der Werkzeugname folgt dem Schwesterwerkzeug, nicht dem lokalen Weg.** Serverseitig heißt
der Quiz-Bereinigungsplan `kurspilot_plan_quiz_cleanup`; das Gegenstück heißt entsprechend
`kurspilot_plan_question_category_cleanup`. Beide Bereinigungspläne tragen damit dieselbe
Namensform, und der Bruch zum lokalen `moodle_`-Präfix ist ohnehin durchgängig.

**Warum überhaupt portieren und nicht streichen.** Der Anlass ist kein Komfort: Solche
entarteten Testkategorien waren in
[#315](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/315) der Auslöser
eines Moodle-Core-Fehlers beim Kursrestore, der auf der Zielinstanz nicht behebbar ist.
Kurspilot vermeidet die Auslösebedingung, statt den Fehler zu beheben — und da Kurspilot
selbst solche Kategorien erzeugen kann, gehört das Aufräumwerkzeug dorthin, wo geschrieben
wird.

## 2. Was der Port nicht mitbringt

Die zweite und dritte Empfehlung aus #315 bleiben draußen, wie schon im lokalen Weg:

- **Kein Löschweg.** Das Glossar (**Fragensammlungs-Bereinigung**) führt „kein
  Löschwerkzeug" als Domänenregel, nicht als Umsetzungslücke, und die Karte hat den Satz
  „Kurspilot löscht nichts" mehrfach getragen. Der Plan nennt und verlinkt; gelöscht wird
  in Moodle von Hand.
- **Keine Verschachtelungsprüfung beim Anlegen oder Verschieben.** Sie stand in #316 schon
  als „optional" und würde eine Wertprüfung in `ensure_question_category` und
  `update_question_category` einziehen, die es dort sonst nicht gibt. Der eigentliche
  Auslöser des Restore-Fehlers — der Sortierungs-Fehler beim ersten Kind eines neuen
  Elternteils — ist im lokalen Weg bereits behoben und im Neubau nie entstanden. Bleibt
  Nebel, kein Ticket.

## 3. Die Fragensammlungs-Auflösung wird geteilt

Der Weg von Kurs-ID plus CMID zum geprüften Fragensammlungs-Kontext — Kurs laden,
Aktivitätsname der Fragenbank vom Core erfragen, Modulzeile über die drei Bedingungen
finden, bei Fehlschlag mit einer benannten Ausnahme abweisen, Modulkontext bilden und
freigeben — steht heute einmal in `get_question_categories`. Der Port bräuchte ihn ein
zweites Mal.

**Entscheidung: einmal, gemeinsam genutzt.** Der Aufrufer bekommt Modulzeile samt Name und
den bereits validierten Kontext zurück. Beide Endpunkte werden darauf gezogen.

Ausdrücklich **nicht** einbezogen ist `ensure_question_bank`: Es sucht über den *Namen* und
legt bei Fehlschlag an — eine andere Abfrage mit einem anderen Zweck, die nur oberflächlich
ähnlich aussieht. Sie mit hineinzuziehen hieße, zwei Absichten in eine Funktion zu
zwingen.

Der Nutzen ist bescheiden und der Umfang klein; er rechtfertigt sich dadurch, dass die
Auflösung eine **Rechteprüfung** trägt. Zwei Kopien einer Rechteprüfung sind zwei Orte, an
denen sie auseinanderlaufen kann.

## 4. Rechte: die Kategorieverwaltung wird zusätzlich verlangt

Der Port prüft im Fragensammlungs-Kontext neben `local/kurspilot:use` auch die native
Moodle-Berechtigung zum Verwalten von Fragenkategorien — wie das lokale Vorbild und anders
als das benachbarte `get_question_categories`, das nur liest.

Der Unterschied ist inhaltlich: Der Bereinigungsplan ist zwar technisch ein Lesevorgang,
seine Antwort ist aber eine Liste konkret löschbarer Kategorien mit Direktlinks zum
Löschen. Wer diese Kategorien in Moodle nicht verwalten dürfte, hat für diese Liste keine
Verwendung — und bekäme eine Handlungsaufforderung, die er nicht ausführen kann.

Damit folgt der Port derselben Linie wie das Schwesterwerkzeug, das ebenfalls über
`local/kurspilot:use` hinaus eine native Berechtigung verlangt.

Der Drift-Schreibschutz des Feldkatalogs greift hier nicht: Er sperrt Schreibvorgänge je
Aktivitätsart, und kein Lesewerkzeug ruft ihn auf.

## 5. Der Quiz-Bereinigungsplan bleibt beim Planen

Die zweite Frage des Tickets: Braucht `kurspilot_plan_quiz_cleanup` — serverseitig bereits
vorhanden — eine Schreibperspektive, also einen Weg, die gefundenen Slots auch zu
entfernen?

**Nein.** Die Ersetzungsschwelle hat das bereits entschieden: Es bleibt beim Planen, nicht
Löschen. Drei Gründe stützen das über die reine Regelbindung hinaus:

1. **Fähigkeits-Parität ist das Maß.** Der lokale Weg kann es nicht; ein Schreibweg wäre
   eine neue Fähigkeit und damit jenseits des Kartenziels.
2. **Die Domänenregel gilt für beide Bereinigungspläne.** „Kein Löschwerkzeug" trennt nicht
   zwischen Kategorien und Quiz-Slots.
3. **Es gäbe einen Weg dorthin, der nicht Löschen heißt.** Wenn eine neue Quizversion
   weniger Fragen enthalten soll, ist das Mittel die Quiz-Anordnung des Änderungsverlaufs
   (Rückkehr als Fortschreiben), nicht ein Löschendpunkt. Dieser Weg existiert bereits und
   trägt seine eigenen Schutzschienen — allen voran die Sperre, sobald es Versuche gibt.

Der Quiz-Bereinigungsplan wird in dieser Spec also **nicht angefasst**. Festgehalten wird
nur, dass die Frage gestellt und beantwortet wurde.

## 6. Prüfnähte

Höchste Naht ist der Endpunkt selbst, geprüft wie die vorhandenen Endpunkttests:
Testdaten anlegen, aufrufen, Rückgabe prüfen.

Was geprüft wird:

- Eine leere, blattlose Kategorie erscheint im Plan; eine Kategorie mit Fragen und eine mit
  Unterkategorien erscheinen nicht; die oberste Kategorie erscheint nie.
- Eine leere Kategorie, deren einziges Kind ebenfalls leer ist, erscheint **nicht** — sie
  hat ein Kind. Das ist der Fall aus #315 (die verschachtelte Testkategorie), und er zeigt,
  dass ein Aufräumen zwei Durchgänge braucht: erst das Blatt, dann der Elternteil. Der Plan
  behauptet nicht, in einem Durchgang vollständig zu sein.
- Eine leere Sammlung liefert eine leere Liste, keinen Fehler.
- Eine Fragensammlungs-CMID aus einem anderen Kurs wird abgewiesen.
- Ohne die Kategorieverwaltungs-Berechtigung wird abgewiesen, mit ihr nicht.

Für die geteilte Auflösung gilt: Sie bekommt **keine** eigenen Tests. Sie hat keine
Substanz außer dem, was die beiden Endpunkttests ohnehin durchlaufen; eigene Tests dafür
prüften die Zerlegung statt des Verhaltens. Dass die bestehenden Tests von
`get_question_categories` nach der Extraktion unverändert durchlaufen, ist der Beleg, dass
sich an der Schnittstelle nichts geändert hat.

## 7. Umsetzung

Zwei Schritte, in dieser Reihenfolge:

**Schritt 1 — Auflösung extrahieren.** Die CMID→Bankkontext-Auflösung aus
`get_question_categories` herauslösen, den Endpunkt darauf ziehen. Seine Tests müssen
unverändert durchlaufen; müssen sie geändert werden, ist das ein Befund.

**Schritt 2 — Port.** Endpunktklasse, Registrierung in der Werkzeugregistrierung mit
deutscher Beschreibung für die Lehrkraft, Dienstregistrierung, Tests. Der Vertragstest der
Datenschutzoberfläche fängt eine vergessene Registrierung von selbst.

Nach dem Deploy ist ein Upgrade-Lauf Pflicht, weil sich die Funktionsliste des Dienstes
ändert.

## 8. Begriffe

Kein neuer Begriff. **Fragensammlungs-Bereinigung** steht bereits im Glossar und deckt
diese Spec vollständig ab — einschließlich der Regel, dass Löschen nicht dazugehört.

## Fog of war — bewusst nicht Teil dieser Spec

- **Verschachtelungsprüfung beim Anlegen und Verschieben** (#316, Punkt 2). Wird
  ticketfähig, wenn im Neubau tatsächlich entartete Kategorien entstehen.
- **Ein Aufräumdurchlauf, der mehrere Ebenen auf einmal auflöst.** Erst nötig, wenn der
  Zwei-Durchgang-Fall in der Praxis stört.

## Out of scope

- **Jeder Löschendpunkt**, für Kategorien wie für Quiz-Slots.
- **Eine Schreibperspektive für den Quiz-Bereinigungsplan** (§5).
- **Der Sortierungs-Fehler aus #315.** Im lokalen Weg behoben, im Neubau nie entstanden.
- **Der Moodle-Core-Restore-Fehler selbst.** Nicht auf der Zielinstanz behebbar; Kurspilot
  vermeidet die Auslösebedingung.
- **Aufräumen über Kursgrenzen hinweg.** Der Plan gilt je Fragensammlung.

## Quellenkarte

| Abschnitt | Quelle |
|---|---|
| §1 | [#316](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/316), [#315](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/315), lokaler Weg (Bestand) |
| §2 | `CONTEXT.md` — Fragensammlungs-Bereinigung; #315 Empfehlung 2/3 |
| §3 | `get_question_categories` und `ensure_question_bank` (Bestand) |
| §4 | Lokales Vorbild; `get_quiz_cleanup_plan` (Bestand, native Zusatzberechtigung) |
| §5 | [#351 Ersetzungsschwelle](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351), Spec 0015 §10 (Quiz-Anordnung), Spec 0017 §4.3 |
| §6 | `tests/external/get_quiz_cleanup_plan_test.php` (Bestand) |

Bei Detailfragen: Ticket zoomen, nicht diese Spec erweitern.
