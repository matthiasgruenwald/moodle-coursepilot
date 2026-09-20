---
name: implementierungsplan-workflow
description: Lies diese Datei vor jedem schreibenden Moodle-Zugriff, beim Aufbau und bei der Ausfuehrung eines Implementierungsplans.
---

# Referenz: Implementierungsplan-Workflow

Lies diese Datei vor jedem schreibenden Moodle-Zugriff (`coursepilot_create_module`,
`coursepilot_update_module_settings`, `coursepilot_move_section`, `coursepilot_move_module`,
`coursepilot_set_completion`, `coursepilot_set_restriction`) – also beim Aufbau und bei
der Ausfuehrung eines Implementierungsplans in `coursepilot-planen` und
`coursepilot-umsetzen`.

## Grundprinzipien fuer die Phasenanalyse

### Phasenanzahl ist flexibel

Es gibt KEINE feste Anzahl von Phasen. Analysiere die vorliegende
Unterrichtseinheit oder das Unterthema und erstelle so viele Phasen wie darin
beschrieben sind. Typisch sind 3-6 Phasen – aber es koennen auch 2 oder 8 sein.

### Phasen-Design ist frei waehlbar

Phasen muessen keinem starren Schema folgen. Moegliche Phasenmodelle:
- Handlungsorientiert: Informieren / Planen / Durchfuehren / Kontrollieren / Reflektieren
- Projektbasiert: Analyse / Konzept / Implementierung / Test / Abnahme
- Problembasiert: Problem / Hypothese / Experiment / Auswertung
- Eigene Struktur aus der Unterrichtseinheit oder dem Unterthema ableiten

### Inhalte aus der Unterrichtseinheit ableiten

Alle Texte, Aufgaben und Materialien werden AUS DER VORLIEGENDEN UNTERRICHTSEINHEIT
bzw. dem Unterthema abgeleitet. Nicht erfinden, nicht aus Beispielen kopieren.

Fuer Planung und Umsetzung gilt dabei durchgaengig die **Planstrenge** (siehe
Ankerbegriffe in `coursepilot_get_skill("coursepilot-core")`).

## Implementierungsplan-Workflow (Pflicht vor jedem Schreibzugriff)

Bevor irgendein schreibendes MCP-Tool aufgerufen wird, wird immer zuerst ein
**Implementierungsplan** erstellt und der Lehrkraft als **gestufte Vorschau**
gezeigt. Erst nach expliziter Freigabe ("ja, so umsetzen", "Plan ist gut, leg
los", "freigegeben") werden die Aenderungen in Moodle geschrieben.

Der Plan ist `plan.md` im Kontextbereich der Lehrkraft (siehe
`coursepilot_get_skill("kontextbereich")`) — kein lokales Datenobjekt. Coursepilot
haelt Abschnitte, Aktivitaeten, Gate-Status und Planabweichungen darin fest
und aktualisiert die Datei bei jeder Planaenderung ueber das Schreibangebot.

### Natuerliche Startformulierungen

Diese Formulierungen starten den Plan-Workflow (statt direkt Tools aufzurufen):

- "Plane den Abschnitt fuer ..."
- "Erstelle mir einen Implementierungsplan fuer ..."
- "Wie wuerdest du den Kurs befuellen? Zeig mir erst den Plan."
- "Bevor du loslegst: was ist der Plan?"

### Ablauf

Vier nummerierte Schritte, jeder mit einem pruefbaren Abschlusskriterium.
Zusaetzlich gilt fuer den gesamten Ablauf ein erschoepfendes
**Gesamt-Abschlusskriterium**: Planen ist erst fertig, wenn jeder Punkt des
Lehrkraftauftrags entweder als Planelement in `plan.md` erscheint oder
ausdruecklich als Werkzeugluecke benannt ist (siehe Abschnitt "Werkzeugluecken
bei Aktivitaeten" in `coursepilot_get_skill("coursepilot-core")`). Kein
Auftragspunkt faellt stillschweigend weg.

#### Schritt 1: Plan aufbauen

Zuerst die benannte Kurs-/Projekt-Fragensammlung als eigene
Planungsentscheidung festlegen (siehe `coursepilot_get_skill("quiz-und-fragenbank")`).
Fuer jede geplante Aktivitaet danach Typ, Name, Inhalt/Beschreibung, ob sie
ein Lernpfad-Gate ist und ob eine digitale Abgabe vorgesehen ist festhalten;
daraus leitet sich automatisch die passende Completion-Konfiguration ab
(siehe Planungsgrundsaetze unten). Das Ergebnis ist `plan.md`.

**Abschlusskriterium:** Das Gesamt-Abschlusskriterium (siehe oben) ist fuer
`plan.md` erfuellt – bevor zu Schritt 2 gewechselt wird.

#### Schritt 2: Kurzuebersicht zeigen

Zeigt Abschnitte, Aktivitaeten in Reihenfolge, Typ, Gate-Status,
Completion/Restriction sowie die benannte Fragensammlung (Name + Struktur)
und die Liste der Planungsgrundsaetze und Planabweichungen – OHNE Volltext
(z.B. ganze Textseiteninhalte).

**Abschlusskriterium:** Die Lehrkraft hat die Kurzuebersicht gesehen, inklusive
aller benannten Werkzeugluecken.

#### Schritt 3: Volltext nur auf Nachfrage

Wenn die Lehrkraft z.B. "Zeig mir den ganzen Text der Infoseite" sagt, wird
der vollstaendige Inhalt einer einzelnen Aktivitaet nachgeliefert.

**Abschlusskriterium:** Jede von der Lehrkraft angefragte Aktivitaet wurde im
Volltext gezeigt, bevor weiter geplant oder freigegeben wird.

#### Schritt 4: Freigabe abwarten

Erst wenn die Lehrkraft den Plan ausdruecklich bestaetigt ("ja, so umsetzen",
"Plan ist gut, leg los", "freigegeben"), werden die Aenderungen in Moodle
geschrieben. Ohne diese Bestaetigung wird KEIN schreibendes Tool aufgerufen.
Es gilt dabei die **Ein-Plan-Regel** und die **Status-gesteuerte
Planfreigabe** (siehe `CONTEXT.md`): genau eine aktive `plan.md` pro
Unterrichtsvorhaben, Freigabe wird in `status.md` nachgefuehrt statt nur im
Chat bestaetigt.

**Abschlusskriterium:** `status.md` steht auf `freigegeben`, bevor
`coursepilot-umsetzen` einen Moodle-Schreibzugriff ausfuehrt.

### Abschnitts- und Aktivitaetsverschiebung

Fuer eine reine **Abschnittsverschiebung** wird die geplante neue
Abschnittsreihenfolge zuerst in `plan.md` nachgefuehrt und von der Lehrkraft
bestaetigt; erst danach wird `coursepilot_move_section` ausgefuehrt. Eine
planexterne Ausnahme ist nur erlaubt, wenn die Lehrkraft ausdruecklich
bestaetigt, dass der freigegebene Plan fachlich unveraendert bleibt und nur
der bestehende Moodle-Kurs organisatorisch sortiert werden soll. Dann ist vor
dem Moodle-Schreibzugriff ein Journal-Eintrag Pflicht (siehe
`coursepilot_get_skill("journal")`), und es werden keine weiteren
Abschnittsinhalte oder Sichtbarkeiten
mitveraendert.

Fuer eine reine **Aktivitaetsverschiebung** gilt dieselbe Planbindung:
`coursepilot_move_module` verschiebt nur eine bestehende Aktivitaet per `cmid`
in einen (anderen) Abschnitt, optional an eine bestimmte Position darin. Das Tool darf keine
Inhalte, Sichtbarkeit, Abschlussbedingungen, Voraussetzungen, Quizsettings,
Fragenreferenzen oder Fragedaten aendern.

### Planungsgrundsaetze (werden nicht pro Aktivitaet wiederholt)

- **Aufgabe ohne Abgabe als Gate** -> manuelle Schueler-Abschlussmarkierung
  (`completion=1`).
- **Aufgabe mit digitaler Abgabe als Gate** -> Abgabe-Completion
  (`completion=2`, `completionsubmit=1`).
- **Textseite ohne Gate per Default**; manuelle Abschlussmarkierung nur wenn
  die Textseite explizit als Pflichtlektuere geplant ist.
- **Freigabe-Voraussetzung (Restriction)** wird nur gesetzt, wenn sie im Plan
  ausdruecklich geplant und begruendet ist.

### Planabweichungen

Weicht eine Aktivitaet von einem Planungsgrundsatz ab (z.B. Textseite als
Pflichtlektuere mit Gate, oder eine zusaetzliche Restriction), MUSS beim
Hinzufuegen eine kurze Begruendung notiert werden. Ohne Begruendung wird die
Abweichung nicht in den Plan aufgenommen. Die Abweichung erscheint danach als
eigener Punkt in `plan.md` und damit auch in der Kurzuebersicht – fuer die
Lehrkraft gut sichtbar mit Begruendung, statt versteckt in einer langen
Liste.

## Ausfuehrung: Schrittfolge in Moodle

### Schritt 1: Unterrichtseinheit oder Unterthema analysieren

Vor dem ersten API-Aufruf die Unterrichtseinheit bzw. das Unterthema lesen und notieren:
- Wie viele Phasen gibt es? Wie heissen sie?
- Welche Farbe bekommt jede Phase? (Frei waehlbar, aber konsistent)
- Welche Aktivitaeten gehoeren zu welcher Phase?
- Was muessen SuS NUR LESEN? Was muessen sie ABGEBEN?

### Schritt 2: Kursstruktur pruefen

```
coursepilot_get_sections(courseid=KURS_ID)
```

Geplanten Zielabschnitt mit dem freigegebenen Plan abgleichen. Abschnitt 0
beziehungsweise "Allgemeines" ist ein normaler fachlicher Kursabschnitt und
kein technischer Ablageort fuer Coursepilot-Versionierung, Status, Debug-Hinweise
oder sonstige Prozessdaten. Ohne freigegebenen Plan keinen "freien Abschnitt"
als Default befuellen.

### Schritt 3: Abschnitt benennen und nur bei Planbezug einen Abschnittseinstieg setzen

```
coursepilot_update_section(courseid, sectionnum, fields_json='{"name": ..., "summary": ...}')
```

Ein Abschnittseinstieg im `summary` ist kein automatischer Default. Nutze ihn
nur, wenn der freigegebene Plan fuer genau diesen Abschnitt einen sichtbaren
Einstieg vorsieht.

### Schritt 4: Pro Phase die geplanten Elemente anlegen

Fuer jede Phase der Unterrichtseinheit bzw. des Unterthemas alles ueber
`coursepilot_create_module(courseid, sectionnum, modname, fields_json)` anlegen:
1. `modname="label"` – nur wenn ein sichtbarer Phasen-Trenner geplant ist
2. Je nach Inhalt: `modname="page"`, `"url"`, `"assign"`, `"resource"`,
   `"folder"`, `"choice"`, `"forum"`

## Aktivitaetstypen waehlen

| Situation | `modname` |
|---|---|
| SuS liest nur (Infoblatt, Leitfaden, Anleitung, Codebeispiel) | `page` |
| SuS fuellt etwas aus / gibt etwas ab / reflektiert | `assign` |
| Externe Dokumentation, GitHub, MDN, Referenz | `url` |
| Phasen-Trenner (direkt auf Kursseite sichtbar) | `label` |
| Datei zum Herunterladen (PDF, Arbeitsblatt, Vorlage) | `resource` |
| Datei-Sammlung in einem Ordner | `folder` |
| SuS waehlt eine Option (Umfrage, Meinungsbild) | `choice` |
| SuS diskutiert asynchron (Austausch, Frage-Antwort) | `forum` |

`resource` ist bis Spec 0018 gesperrt (kaputte Seite ohne Hauptdatei) – fuer
Dateien zum Herunterladen bis dahin `folder` verwenden.

**GOLDENE REGEL:** Sobald SuS irgendetwas ausfullen, eintragen, ankreuzen
oder hochladen sollen -> IMMER `coursepilot_create_module(modname="assign")`,
NIEMALS `modname="page"`!

### Abstimmung (`coursepilot_create_module` mit `modname="choice"`): Optionenzahl didaktisch pruefen

Moodle setzt fuer `option[]` keine Obergrenze, und Coursepilot prueft die Anzahl
nicht (Spec 0015 §4.5). Ab etwa acht Optionen lohnt trotzdem eine
Rueckfrage, ob eine Abstimmung noch das passende Werkzeug ist, statt einer
Liste, Gruppierung oder Tabelle – das ist eine didaktische Empfehlung, keine
Wertpruefung: Coursepilot lehnt keine Optionenzahl ab und erfindet keine
Obergrenze.
