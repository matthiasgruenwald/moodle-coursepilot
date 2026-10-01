# Spec 0020 — Skill-Verteilung im Servermodell: Korpus über Werkzeuge, ohne lokalen Installer

*Karte: [Voller Funktionsumfang für `local_kurspilot`](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/346) · Ticket: [#375](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/375) · Sechstes und letztes Spec des Zuschnitts [#359](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/359)*

> **Umgesetzt wird gegen [#449](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/449), nicht gegen dieses Dokument.**
> Das Issue trägt die verbindliche Form — User Stories, Abnahmekriterien als Haken.
> Dieses Dokument beantwortet das *Warum* und ist Nachschlagewerk, keine zweite
> Anforderungsquelle: **eine Anforderung, die nur hier steht, gilt als nicht beauftragt.**

## Ziel

Die Lehrkraft bekommt die Kurspilot-Skills, ohne etwas zu installieren. Das ist der letzte
offene Punkt des Setup-Apparats: OAuth hat den Token-Speicher ersetzt, Discovery die
MCP-Client-Konfiguration, der Kontextbereich in Private Files den Arbeitsbereich
(Spec 0016). Übrig blieb die Skill-Verteilung — und sie ist nicht kosmetisch, sondern
Bedingung der Abnahme: „ein vollständiges Unterrichtsvorhaben ausschließlich serverseitig,
ohne lokale Installation" ([#351](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351))
setzt voraus, dass die Skills im Client ankommen.

Der Bestand macht die Lücke sichtbar: vier Skill-Adapter (je ~16 Zeilen) zeigen über
**relative Repo-Pfade** (`../../../skills/kurspilot-core.md`) auf 16 Referenzdateien
(2.141 Zeilen). Das funktioniert nur mit lokalem Checkout — `scripts/install-skills.js`
kopiert nach `~/.claude/skills/` bzw. `~/.agents/skills/`. Ohne Installer gibt es weder
die Adapter noch die Pfade, auf die sie zeigen.

Diese Spec beantwortet: **wie der Korpus in den Chat kommt**, wo er lebt, wie er sich
ändert, und wie er sich zu dem verhält, was die Lehrkraft im Kontextbereich selbst lernt.
Sie ist zugleich die Sammelstelle für die Skill-Regeln, die die Specs 0015–0018 hierher
delegiert haben (§9).

**Namenskonvention** (Bestand): MCP-Werkzeug `kurspilot_<name>`, Webservice
`local_kurspilot_<name>`, Klasse `\local_kurspilot\external\<name>`.

---

## 1. Der Lieferkanal: zwei Werkzeuge statt Installation

**Es wird nichts installiert.** Der Korpus bleibt auf dem Moodle-Server und kommt als
Werkzeugantwort in den Chat:

```
1. Client verbindet sich (OAuth) → initialize
   Server antwortet mit instructions: "vor Planung/Schreiben
   kurspilot_list_skills aufrufen"                        (§2)
2. Modell ruft kurspilot_list_skills auf   → Namen + Auslöser
3. Modell ruft kurspilot_get_skill("kurspilot-planen") auf → Adaptertext
4. Bei Bedarf kurspilot_get_skill("quiz-und-fragenbank")   → Referenzteil
```

Schritt 2–4 sind gewöhnliche `tools/call`-Aufrufe. Ein MCP-Client, der Werkzeuge kann,
kann das — mehr wird nicht vorausgesetzt. Damit ist der Kanal **clientneutral**: Claude
und Codex laufen denselben Weg, es gibt keine zwei Verteilwege zu pflegen.

### 1.1 Warum nicht die client-eigene Skill-Mechanik

Claude (`~/.claude/skills/`, Marketplace, Autoload über `description`) und Codex
(`~/.agents/skills/`, Aufruf mit `$`) haben je eine gewachsene Skill-Mechanik. Beide
setzen **Dateien auf dem Laptop** voraus — also genau den Installer, den diese Karte
abschafft. Sie zu bedienen hieße, den Setup-Apparat unter anderem Namen zu behalten, und
zwar zweimal.

Der Preis der Entscheidung ist benannt: **kein Slash-Menü, kein Autoload.** Die Skills
tauchen in keiner Client-Oberfläche auf; der Einstieg hängt vollständig an §2.

### 1.2 Warum nicht MCP-Prompts

`prompts/list` wäre der protokollnahe Kanal — Claude Code und VS Code zeigen Server-Prompts
als Slash-Kommandos. **Codex kann es nicht**
([openai/codex#8342](https://github.com/openai/codex/issues/8342), offen). Ein Kanal, der
in Codex nicht funktioniert, verletzt Codex-First (`CONTEXT.md`) und fällt damit aus,
unabhängig von seiner technischen Eleganz.

### 1.3 Warum nicht Skills als Kontextdateien

`read_context_file` liegt bereits vor — der Korpus im Kontextbereich der Lehrkraft käme
ohne einen einzigen neuen Endpunkt aus. Zwei Gründe dagegen, beide grundsätzlich:

- **Herkunft.** Der Korpus ist Produkt, kein Arbeitsmaterial. Im Kontextbereich wäre er
  Lehrkraft-Eigentum: löschbar, überschreibbar, weitergebbar — und damit versionsmäßig
  unbestimmt (§6).
- **Kopplung.** Die Skills beschreiben Werkzeugverträge. Liegen sie neben den Werkzeugen im
  Plugin, ändern sie sich mit ihnen; liegen sie in fremder Hand, driften sie (§5).

### 1.4 SEP-2640 ist die vorgesehene Nachrüstung

Die Standardisierung läuft:
[SEP-2640 „Skills Extension"](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2640)
definiert `skills/list`, `skill://`-Resources, SHA-256-Digests und Client-Pflichten zur
Prompt-Injection-Abwehr, unter der Extension-ID `io.modelcontextprotocol/skills`. **Status:
Draft**; Host-Implementierungen in Claude Code, codex, gemini-cli und goose sind Prototypen
und PRs, nichts Produktives. Darauf lässt sich eine Abnahme heute nicht gründen.

Deshalb: **derselbe Korpus, zwei Ausspielwege.** Wenn Claude und Codex die Extension
produktiv tragen, tritt `skills/list` neben die beiden Werkzeuge — der Korpus bleibt
unverändert, weil er ohnehin schon aus Markdown-Dateien mit Frontmatter besteht, also aus
genau dem, was die Extension ausliefert. Dann stehen die Skills wieder nativ im Slash-Menü.
Das ist keine Absichtserklärung, sondern die Bauvorgabe für §3.1: **die Dateiform ist der
Vertrag, die Endpunkte sind nur ein Ausspielweg darauf.**

## 2. Auffindbarkeit: `instructions` ist der Wegweiser

Ohne lokale Skill-Datei gibt es keine `description`, an der ein Client anspringt. Der
Dispatcher meldet heute ausschließlich `capabilities: {tools}` und setzt **kein**
`instructions`-Feld — das ändert sich hier.

`initialize` und `server/discover` liefern beide dasselbe `instructions`: einen
**Wegweiser**, der `kurspilot_list_skills` als ersten Schritt vor Planung oder
Schreibzugriff benennt. Dazu ein Satz in der Werkzeugbeschreibung von `list_skills` selbst,
für Clients, die `instructions` nicht anzeigen.

**Nur der Weg, nichts Fachliches.** `instructions` liegt in jeder Sitzung im Kontext, auch
wenn die Lehrkraft nur eine Kursliste sehen will — es ist ein **Kontextpointer** und wird
als solcher bemessen: Auslöser und Verzweigungen, keine Inhalte. Alles Fachliche steht
hinter `get_skill`, sonst hebelt der Wegweiser die Zweistufigkeit aus §3.3 aus.

**Keine Zeichengrenze.** Eine feste Zahl wirkt als Hürde und wäre beim ersten berechtigten
Zuwachs zu ändern; das Sparziel bleibt auch ohne sie. Was stattdessen gilt, steht in §8.

## 3. Der Skill-Korpus

### 3.1 Ablage: Markdown-Dateien im Plugin

Der Korpus liegt als Markdown-Dateien im Plugin-Verzeichnis und wird beim Aufruf gelesen.

Verworfen: **Datenbank** (Editierbarkeit widerspricht der Kopplung aus §5, plus
Upgrade-Migrationen für Prosa), **PHP-Konstanten** (macht 2.000 Zeilen Text zu Quellcode
und die Weiterverwendung durch SEP-2640 unnötig schwer), **externes Nachladen** (Schulnetz,
Datenschutz, keine Auslieferungszusage).

Zwei Eigenschaften, die daran hängen:

- **Das Verzeichnis ist die Quelle.** Kein zweiter Index, keine Registrierungsliste im
  Code — `list_skills` liest das Verzeichnis. Eine neue Referenzdatei ist damit eine
  Datei, kein Code-Änderungsvorgang.
- **Prosa-Änderungen sind billig.** Markdown-Dateien brauchen kein `upgrade.php` (das
  erzwingen nur `db/access.php` und `db/services.php`) und keinen Versionssprung. Eine
  Korrektur ist ein Deploy.

**Bauvorgabe: `writing-for-agents` gilt für den gesamten Korpus** — Kontextpointer,
Informationshierarchie, Leitwörter, No-Op-Prüfung. Der Korpus ist das Dokument, das ein
Agent liest; er wird nach denselben Regeln gebaut wie diese Skills selbst.

### 3.2 Zuschnitt: drei Adapter statt vier

| Adapter | Servermodell |
|---|---|
| `kurspilot` | bleibt — Einstieg, Modusklärung |
| `kurspilot-planen` | bleibt — Planung bis zur Freigabe |
| `kurspilot-umsetzen` | bleibt — Schreiben in den Kurs |
| `kurspilot-einrichten` | **entfällt** |

`kurspilot-einrichten` ist serverseitig entkernt: Token-Speicher (OAuth), MCP-Konfiguration
(Discovery) und Arbeitsbereich-Anlage (Kontextbereich) sind alle weg. Übrig bleibt das
Kontext-Onboarding (Klasse, Fach, Thema klären) — ein **Gesprächsschritt, kein Modus**. Es
wandert als Referenzdatei unter den Einstiegs-Skill. Ein Adapter, dessen Namensversprechen
nichts mehr bedeutet, kostet Auswahlaufwand und stiftet Verwirrung.

### 3.3 Zweistufigkeit ohne Pfade

Die Struktur bleibt zweistufig — **Adapter** (klein, Einstieg) und **Referenzteile** (bei
Bedarf nachgeladen). Das ist progressive Kontextfreigabe und der Grund, warum der Korpus
trotz 2.000 Zeilen billig ist.

Was sich ändert, ist die Mechanik: statt `../../../skills/kurspilot-core.md` nennt der
Adapter den **Namen** des Referenzteils, und `get_skill(name)` liefert ihn. Der Pfadbegriff
verschwindet vollständig (Spec 0012 §5.1: „ersatzlos").

### 3.4 Was wegfällt

- **Die an lokales Node gebundenen Anteile** — Arbeitsbereich, Paketexporte, Pfadbildung.
  Ersetzt durch die Specs 0015–0018; der Korpus verweist auf Werkzeuge, nicht auf
  auszuführenden Code.
- **Die `spike-*`-Provisorien** ([#410](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/410)).
  Im Servermodell sind sie nicht mehr die Ausnahme, sondern der Regelfall: `spike-planen`
  und `spike-umsetzen` werden zu `kurspilot-planen` und `kurspilot-umsetzen`,
  `spike-kontextbereich.md` und `spike-fragetypen.md` verlieren das Präfix.

## 4. Werkzeugverträge

**`kurspilot_list_skills`** — ohne Parameter. Liefert je Eintrag: Name, Auslöser (die
Beschreibung aus dem Frontmatter, deutsch), Art (`adapter` oder `referenz`) und Umfang in
Zeichen. Kein Inhalt — die Liste ist der Katalog, nicht die Lieferung.

**`kurspilot_get_skill(name)`** — liefert Inhalt (Markdown), die Namen der darin
referenzierten Teile und den Korpus-Stand (Plugin-Version). Ein unbekannter Name führt zu
einem Fehler, der **die gültigen Namen nennt** — nicht zu einem leeren Ergebnis, an dem
das Modell zu raten beginnt.

**Der Name ist ein Bezeichner, kein Pfad.** Geprüft wird gegen die Liste aus dem
Verzeichnis; alles andere wird abgewiesen. Damit ist Pfad-Traversal keine Frage der
Sorgfalt, sondern der Konstruktion.

**Rechte und Zustimmung:** `local/kurspilot:use` genügt, keine Kursbindung, keine
Kurs-Zustimmung. Der Korpus enthält keine Nutzerdaten — und die Skills sind es, die der
Lehrkraft die Zustimmung erklären; hinter das Zustimmungstor gestellt, wären sie in einer
Henne-Ei-Lage. Registrierung in Werkzeugregistrierung und Dienst wie üblich; der
Vertragstest der Datenschutzoberfläche fängt eine vergessene Registrierung.

**Kein Cache.** Zwei Dateilesevorgänge je Sitzung rechtfertigen keine MUC-Schicht.

## 5. Änderungsweg: zwei Änderungsklassen

| Klasse | Beispiel | Weg |
|---|---|---|
| **Vertragsänderung** | ein Werkzeug bekommt ein Feld, der Skill muss mit | nur im Plugin-Release, gemeinsam mit dem Code |
| **Prosa-Verbesserung** | schärfer formuliert, No-Op entfernt, Beispiel korrigiert | dieselbe Datei, kein Versionszwang, ausgerollt wie jeder Deploy |

Ein **zweiter Auslieferungskanal** für den Korpus (eigenes Skill-Paket, Admin-Upload) ist
verworfen: er erzeugt genau die Drift, die
[#356](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/356) beim Feldkatalog
mühsam eingefangen hat — dort ist Katalog-Update gleich Plugin-Release, und aus demselben
Grund ist Korpus-Update gleich Plugin-Deploy.

**Der Korpus ist nicht anpassbar** — weder durch die Lehrkraft noch durch die
Administration. Was die Lehrkraft anpassen können muss, liegt woanders: `vorlagen.md` und
die Lerndateien (§6).

## 6. Skill-Korpus gegen Lerndatei

Zwei Wissensspeicher mit derselben Funktion („so macht man das hier"), getrennt **nach
Herkunft, nicht nach Inhalt**:

| | **Skill-Korpus** | **Lerndatei** |
|---|---|---|
| Wo | Plugin-Verzeichnis | Kontextbereich (`user/private`) |
| Wem gehört er | Produkt | Lehrkraft |
| Was steht drin | was für alle gilt: Werkzeugverträge, Arbeitsabläufe, Regeln | was diese Lehrkraft gelernt hat: `kurspilot/fragetypen/<typ>.md` (Spec 0017 §3), `vorlagen.md` |
| Wer schreibt | Plugin-Release | KI und Lehrkraft, über das Schreibangebot (Spec 0016 §8.2) |

**Gelerntes schlägt Korpus im Konflikt** — es ist später und spezifischer. Der Adapter
nennt Ort und Reihenfolge: erst der Korpus als Grundlage, dann die Lerndatei als
Überschreibung.

**Kein automatischer Rückfluss.** Eine Lehrkraft-Regel wandert nicht von selbst in den
Korpus zurück — das wäre ein Produktentscheid aus einer Sitzungsbeobachtung heraus. Der
Weg zurück ist die Weitergabe: die Lerndatei ist eine gewöhnliche Datei in „Meine Dateien"
und lässt sich herunterladen und einreichen (Core-geschenkt, Spec 0016).

## 7. Sediment: ersetzen statt anhängen

Weil Verbesserungen für die Lehrkraft nur über Lerndateien möglich sind (§5), wächst dort
sonst Schicht auf Schicht — Anhängen fühlt sich sicher an, Löschen riskant. Das Ergebnis
ist ein Kontext, der mit jeder Sitzung teurer und widersprüchlicher wird.

Die feste Gliederung aus Spec 0017 §3 wird deshalb zur **Bedingung**: eine neue Erkenntnis
geht in **den vorhandenen Abschnitt** und ersetzt dort die schwächere Formulierung. Blindes
Anhängen ist der Ausnahmefall und wird benannt, wenn er eintritt.

Dazu das Größensignal nach dem Muster der Journal-Rotation (Spec 0016 §5.2): Die Antwort
des Schreibpfads trägt die Dateigröße, der Skill bietet Verdichtung an. Die 1-MB-Grenze aus
Spec 0016 §5.2 bleibt der harte Fangnetzwert; das Arbeitsmittel ist die Prüfung bei jeder
Ergänzung, nicht die Schwelle.

## 8. Optimierungsprüfung: ein Wortlaut, zwei Adressaten

> *Ändert diese Zeile gegenüber dem Default Verhalten, und sagt sie etwas, das nicht schon
> woanders steht?*

Einmal im Spec als **Änderungsregel für den Korpus** (jede Korpus-Änderung prüft die
berührten Bestandszeilen mit), einmal in der Skill-Prosa als **Regel für Lerndateien**
(§7). Zwei Formulierungen derselben Regel wären genau die Duplikation, die die Regel
verbietet.

**Kein Schwellwert — der Trend ist das Instrument.** Die Prüfgrundlage nennt den Umfang von
`instructions` und des Einstiegs-Adapters als Zahl **zum Zeitpunkt der Abnahme**. Wächst er
im nächsten Release, ist die Optimierungsprüfung fällig und wird begründet. Damit wird
Sediment sichtbar, ohne dass eine Zahl zur Hürde wird.

## 9. Die eingesammelten Skill-Regeln

Die Specs 0015–0018 haben Regeln hierher delegiert, weil sie ohne den jeweiligen
Werkzeugvertrag nicht formulierbar waren. Diese Spec ordnet sie zu; **ausformuliert werden
sie in der Umsetzung** (Phase 3), nicht hier — sonst wird das letzte Spec zum
Skill-Neuschrieb.

| Regel | Quelle | Ziel im Korpus |
|---|---|---|
| Journal-Appends unter der Sitzungs-Kontextfreigabe | 0016 §8.1 | Kontextbereich-Referenzteil |
| Schreibangebot für plan/status/vorlagen | 0016 §8.2 | Kontextbereich-Referenzteil |
| Keine Klarnamen in unmarkierten Dateien | 0016 §8.3 | Kontextbereich-Referenzteil |
| Journal-Rotation auf Plugin-Signal | 0016 §8.4 | Kontextbereich-Referenzteil |
| Handänderungs-Erkennung | 0016 §7 | Kontextbereich-Referenzteil |
| Fragetyp-Ablage: Pfad, Gliederung, Schreibangebot | 0017 §3 | Fragetypen-Referenzteil |
| Lernschleife: Bestandssuche, drei Versuche, Vorlagenanforderung | 0017 §5 | Fragetypen-Referenzteil |
| Widerspruchsprüfung vor jedem Bau | 0017 §5.2 | Fragetypen-Referenzteil |
| Weitergabe dokumentieren | 0017 §3.4 | Fragetypen-Referenzteil |
| Aufräumfrage am Ende eines Aufbaus | 0018 §8.3 | `kurspilot-umsetzen` |
| Didaktische Empfehlung zur Optionenzahl bei `choice` | 0015 §4.5 | Umsetzen-Referenzteil |

Jede Zeile ist beim Einbau der Optimierungsprüfung (§8) unterworfen: Eine Regel, die der
Korpus schon trägt, wird nicht ein zweites Mal aufgeschrieben.

## 10. Der `-neu`-Behelf wird aufgehoben

Spec 0012 §9.4 sah Parallelbetrieb per Suffix vor: MCP-Alias `kurspilot-neu`, Skills
`kurspilot-neu*`. Der Behelf stammt aus der Zeit, als beide Wege **Dateien im selben
Verzeichnis** waren. Server-gelieferte Skills liegen in keinem Verzeichnis und können mit
lokal installierten nicht kollidieren; der Client sieht sie nur über `list_skills`, der
Namensraum ist ohnehin getrennt.

Stattdessen eine Übergangsregel im Einstiegs-Adapter: **im Servermodus gelten ausschließlich
die Server-Skills.** Findet das Modell daneben lokal installierte Kurspilot-Skills, benennt
es das und arbeitet mit den Server-Skills weiter.

## 11. Prüfnähte

- **Katalog:** `list_skills` liefert jeden Korpus-Eintrag; eine neu abgelegte Markdown-Datei
  erscheint **ohne Code-Änderung** (das Verzeichnis ist die Quelle, §3.1).
- **Lieferung:** `get_skill` liefert jeden gelisteten Namen; ein unbekannter Name wird
  abgewiesen und die Antwort nennt die gültigen Namen.
- **Kein Pfad:** ein Name mit Pfadanteilen wird abgewiesen, unabhängig von der Schreibweise.
- **Wegweiser:** `initialize` und `server/discover` tragen beide `instructions`, im selben
  Wortlaut.
- **Kein Tor:** beide Werkzeuge sind ohne Kursbindung und ohne Kurs-Zustimmung aufrufbar,
  mit `local/kurspilot:use` und sonst nichts.
- **Vollständigkeit des Umbaus:** kein Adapter und kein Referenzteil enthält noch einen
  relativen Pfad, einen Verweis auf lokal auszuführenden Code oder ein `spike-`-Präfix.
- **Abnahmelauf, zweimal:** ein vollständiges Unterrichtsvorhaben rein serverseitig, in
  **Claude** und in **Codex**, mit nachweislich leerem lokalem Skill-Verzeichnis. Das ist
  zugleich die Abnahme der ganzen Karte — hier fällt die Ersetzungsschwelle (#351).
- **Umfang protokolliert:** `instructions` und Einstiegs-Adapter mit ihrer Zeichenzahl zum
  Abnahmezeitpunkt festgehalten (§8).

## 12. Umsetzungsphasen

**Phase 1 — Korpus umbauen.** Drei Adapter (§3.2), Referenzteile ohne Pfade (§3.3),
Node-gebundene Anteile und `spike-`-Präfixe raus (§3.4). Gebaut nach `writing-for-agents`.
Rein redaktionell, kein PHP.

**Phase 2 — Auslieferung.** Die beiden Endpunkte (§4), Registrierung, `instructions` im
Dispatcher (§2). Nach dem Deploy ist ein Upgrade-Lauf Pflicht, weil sich die Funktionsliste
des Dienstes ändert.

**Phase 3 — Regeln einsammeln.** Die Tabelle aus §9 abarbeiten, jede Zeile unter der
Optimierungsprüfung.

**Phase 4 — Abnahmelauf.** Zweimal, wie in §11.

Phase 1 und 2 sind unabhängig; Phase 3 setzt Phase 1 voraus, Phase 4 alles.

## 13. Begriffe

Zwei neue Einträge, weil die Trennung aus §6 sonst nicht sagbar ist — beides hieße „Skill":

- **Skill-Korpus** — das mit dem Plugin ausgelieferte, nicht anpassbare Regelwerk.
- **Lerndatei** — das von KI und Lehrkraft fortgeschriebene Gelernte im Kontextbereich.

Dazu **ADR 0020** (`docs/adr/0020-skills-ueber-werkzeuge-statt-installation.md`): Skills
über Werkzeuge ausliefern statt client-nativer Installation.

## Fog of war — bewusst nicht Teil dieser Spec

- **Umstieg auf SEP-2640.** Wird ticketfähig, wenn Claude und Codex die Skills-Extension
  produktiv tragen (§1.4). Der Korpus ist dafür vorbereitet, der Termin hängt an fremder
  Entwicklung.
- **Mehrsprachiger Korpus.** Heute deutsch, wie die gesamte Lehrkraft-Oberfläche. Wird
  ticketfähig, wenn eine Instanz außerhalb des deutschsprachigen Raums dazukommt.

## Out of scope

- **Client-native Skill-Installation** in jeder Form (§1.1) — sie setzt den Installer voraus,
  den die Karte abschafft.
- **Anpassbarkeit des Korpus** durch Lehrkraft oder Administration (§5). Anpassbar sind
  `vorlagen.md` und die Lerndateien.
- **Automatischer Rückfluss** von Lerndateien in den Korpus (§6).
- **Abschaltung des lokalen Wegs**, `install-skills.js` und Bootstrap-Vertrieb (ADR 0008).
  Der harte Schnitt (Spec 0012 §9.6) bleibt gültig und kommt **nach** der Abnahme — er ist
  ausdrücklich nicht das Kartenziel.
- **Ein Skill-Werkzeug je Skill** (~20 Einträge in der Werkzeugliste) — verworfen gegen das
  Leitmotiv der Karte, weniger Kontext für denselben Funktionsumfang.

## Quellenkarte

| Abschnitt | Quelle |
|---|---|
| §1 | [#375](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/375) Grilling; `scripts/install-skills.js`, `skills/*.md` (Bestand) |
| §1.2 | [openai/codex#8342](https://github.com/openai/codex/issues/8342) |
| §1.4 | [SEP-2640](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2640), [Skills-Extension-Entwurf](https://github.com/modelcontextprotocol/experimental-ext-skills) |
| §2 | `classes/dispatcher.php` (Bestand, `capabilities: {tools}`) |
| §3 | Spec 0012 §5.1 (Pfadbegriff ersatzlos), [#410](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/410) |
| §5 | [#356 Katalogpflege](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/356) |
| §6, §7 | Spec 0016 §5.2/§8, Spec 0017 §3 |
| §9 | Specs 0015 §4.5, 0016 §7–8, 0017 §3/§5, 0018 §8.3 |
| §10 | Spec 0012 §9.4 |
| §11 | [#351 Ersetzungsschwelle](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351) |

Bei Detailfragen: Ticket zoomen, nicht diese Spec erweitern.
