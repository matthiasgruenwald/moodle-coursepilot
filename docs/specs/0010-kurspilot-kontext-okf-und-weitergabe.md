# 0010 – Kurspilot-Kontext im OKF-Format und Weitergabe

## Ziel

Der konfigurierte Kurspilot-Arbeitsbereich speichert Unterrichtskontext als
menschen- und werkzeuglesbare Dateien. Lehrkräfte können ein
Unterrichtsvorhaben ohne personenbezogene Daten teilen oder eine Lerngruppe
bei einer Kursübergabe innerhalb der Schule einschließlich ihres Verlaufs
übergeben. Die Ablage bleibt chronologisch; ein Index bietet zusätzlich einen
thematischen Einstieg.

Das Format orientiert sich bei Feldnamen an AMB/LRMI, behauptet aber keine
AMB-Konformität: Lokale Markdown-Dateien liefern weder auflösbare URIs noch
JSON-LD über HTTP. Die Werte bleiben deshalb einfacher Klartext.

## Arbeitsbereich und Ablage

Der konfigurierte **Arbeitsbereich** ist zugleich die Schreibgrenze von
Kurspilot. Er enthält unmittelbar die Schuljahresordner; die zusätzliche Ebene
`local-context/` entfällt. Die kanonische Chronologie lautet:

```text
<arbeitsbereich>/
├── index.md
└── <schuljahr>/<klasse-oder-lerngruppe>/<fach>/<vorhaben>/
```

Profile, Pläne, Journale und Materialien liegen bei der fachlich passenden
Ebene dieser Struktur. Ein Vorhaben kann sich über mehrere Dateien erstrecken;
es gibt keinen zweiten, thematisch sortierten Ordnerbaum.

Nach dieser Umstellung akzeptiert Kurspilot ausschließlich diese Struktur.
Für die wenigen frühen Teststände gibt es weder Migrationsassistent noch
Mischbetrieb: Die Lehrkraft sichert bei Bedarf, überführt den Bestand bewusst
mit KI-Unterstützung, ergänzt Frontmatter und trennt personenbezogene Inhalte
in Sidecars.

## Frontmatter

Jede Kontextdatei beginnt mit YAML-Frontmatter. Diese Felder sind Pflicht:

```yaml
type: lerngruppe | fach | vorhaben | plan | journal | material | wegweiser | status
title: <Kurztitel>
tags: [<freie Schlagworte>]
status: aktiv | archiviert | abgeschlossen
created: YYYY-MM-DD
updated: YYYY-MM-DD
about: <Fach>
gradeLevel: <Jahrgangsstufe>
kurspilot:
  personenbezug: true | false
  weitergabe: offen | schulintern | nicht_weitergeben
```

Die technische Erkennung der Personenbezug-Markierung akzeptiert eine optionale
UTF-8-BOM und führende Leerzeilen vor dem Frontmatter, CRLF oder LF sowie den
schließenden Trenner `---` direkt am Dateiende. Leerzeichen vor und nach dem
Doppelpunkt sind erlaubt; der Wert darf wie bisher in der nächsten Zeile stehen. Verschachtelte und punktierte Schlüssel sowie der
bisherige einfache Schlüssel `personenbezug` werden erkannt. Der markierende
Wert bleibt das kleingeschriebene, unquotierte `true`; `false`, `True`, `"true"`
und `yes` markieren nicht. Eine Erweiterung dieser Werte benötigt eine eigene
Spec-Entscheidung. Inhalt außerhalb des Frontmatters setzt die Markierung nicht.

`status` im Frontmatter beschreibt den Lebenszyklus der Datei und ist nicht
der Umsetzungsstand in einer `status.md`. Kurspilot aktualisiert `updated` bei
eigenen Schreibvorgängen; bei manuellen Änderungen bleibt es eine sichtbare
Pflegekonvention.

Die optionalen, AMB-nahen Felder sind `license`, `learningResourceType`,
`author`, `inLanguage`, `competency`, `competencyUri`, `source` und
`derivedFrom`. Kompetenzbezug bleibt Freitext mit optionalem URI-Slot.
`derivedFrom` verweist nur auf das unmittelbare Ausgangsvorhaben und erzeugt
keine technische Abhängigkeit.

## Personenbezug und Varianten

Personenbezug liegt nie im Fließtext einer teilbaren Sachdatei. Er steht in
einem gleichnamigen Sidecar, etwa `CONTEXT.personen.md`, dessen Frontmatter
`kurspilot.personenbezug: true` setzt. Die Sachdatei verweist sichtbar auf ihr
Sidecar. Maßstab ist Rückführbarkeit: Kann ein Empfänger erraten, welche
Person gemeint ist, gehört die Information in das Sidecar. Nicht rückführbare
Gruppenaussagen bleiben teilbar.

Ein Vorhaben wird im Journal fortgeschrieben, solange sein pädagogischer
Auftrag gleich bleibt. Eine andere Jahrgangsstufe, ein anderes Fach oder eine
deutlich andere didaktische Grundform erzeugt eine eigenständig nutzbare
Variante. Sie trägt `derivedFrom`; Kurspilot führt weder Deltas,
Synchronisation noch automatischen Rückfluss. Ist sie die neue Fassung
desselben Auftrags, wird der Vorgänger archiviert und verweist im Journal auf
den Nachfolger.

Die Granularität des Journal- und Verlaufsformats wird mit dieser
Spezifikation nicht weiter festgelegt; auch sein Personenbezug folgt bereits
der Sidecar-Regel.

## Thematischer Index

`<arbeitsbereich>/index.md` ist eine globale, zusätzliche und lesbare Sicht.
Ein Eintrag enthält Verweis, Titel, Fach, Jahrgangsstufe, Tags und eine
Kurzbeschreibung; eine vorhandene Lizenz darf ergänzt werden. Aktive Vorhaben
sind Standardtreffer, archivierte Vorgänger bleiben nachvollziehbar und
nachrangig.

Die Lehrkraft darf den Index von Hand pflegen. Kurspilot aktualisiert ihn bei
eigenen Anlage- und Änderungsvorgängen best-effort, ohne manuelle Pflege zu
erzwingen. Fehlt ein Eintrag, bleibt ein Vorhaben über sein Frontmatter
auffindbar und teilbar. Ein Materialpaket enthält ein auf das Vorhaben
begrenztes Katalogblatt.

## Weitergabe

Das kanonische Weitergabepaket ist ein ZIP-Archiv; ein unverändert entpackter
Ordner bleibt lokal gleichwertig nutzbar. Der Transportweg ist kein Teil des
Formats und wird im jeweiligen Fall über einen von der Schule freigegebenen
Kanal vereinbart. Git-Repositories und Moodle-Backups sind kein Paketformat.

Jedes Paket hat im Wurzelordner eine menschenlesbare `manifest.md` als
Paket-Einstieg sowie eine knappe, werkzeugneutrale `AGENTS.md`. Beide erklären
Aufbau, Lesereihenfolge, Grenzen und die Paketart. Einzelne Markdown-Dateien
bleiben durch Titel, Zweck und Frontmatter auch außerhalb des Pakets lesbar.

### Material teilen

Ein Materialpaket enthält genau einen Unterrichtsvorhaben-Ordner mitsamt
nicht-personenbezogenen Plan-, Journal- und Materialdateien, jedoch kein
Fachprofil. Der Export ist reine Dateiauswahl: Alle Dateien mit
`kurspilot.personenbezug: true` bleiben unverändert draußen; Text wird nicht
redigiert. Alle übrigen bewusst abgelegten Dateien gehen unabhängig von ihrer
Endung mit, einschließlich Originalkopien und nicht-textueller Materialien.

`manifest.md` nennt mindestens Paketmodus, Titel, Absender und Schule,
Erstellungsdatum, Herkunft, Inhaltsverzeichnis und Lizenz. Fehlt eine Lizenz,
fragt Kurspilot sie erst vor einer beabsichtigten offenen Weitergabe ab; eine
Lizenz ersetzt keinen Datenschutzschutz.

### Lerngruppe übergeben

Ein Lerngruppenpaket enthält genau den Ordner einer Lerngruppe für ein
ausdrücklich benanntes Schuljahr, einschließlich Profil, Fachordnern,
Vorhaben und berechtigten personenbezogenen Sidecars. Frühere Schuljahre sind
keine implizite Beigabe, sondern bei Bedarf eigenständige Archive.

Das Paket ist im Namen und im Manifest sichtbar mit `INTERN` und
`weitergabe: schulintern` gekennzeichnet. Das Manifest nennt zusätzlich Zweck,
Zuständigkeit und Prüfzeitpunkt; es erfindet weder eine Löschfrist noch eine
Freigabeliste. Für die öffentliche Schule in Niedersachsen prüft Kurspilot
keine Speicherorte und erzwingt keinen Transportweg. Es weist darauf hin, dass
die Lehrkraft den von Schule oder Träger freigegebenen Speicherort und den
zulässigen Übergabekanal klären muss.

Die Kennzeichnung schützt vor Versehen, nicht vor absichtlicher Weitergabe.
Kurspilot warnt beim Erzeugen einmal deutlich, kann aber keine Löschung,
Kopien oder Empfängerbestätigungen technisch erzwingen.

## Ankunft und Werkzeugunabhängigkeit

Der Empfänger entpackt ein Paket zunächst unverändert als Eingangspaket. Erst
danach überführt die Lehrkraft es bewusst in ihre eigene Chronologie: Material
als neues Vorhaben oder Variante, Lerngruppenmaterial als datiertes
Schuljahresarchiv. Absenderpfade werden nie automatisch übernommen.
Gleichnamige Vorhaben bleiben als neu eindeutig benannter Ordner nebeneinander;
Kurspilot führt weder Überschreiben noch automatisches Mergen aus.

Ohne installierten Kurspilot-Skill bleiben alle nicht gesperrten Inhalte
lesbar und manuell bearbeitbar. Kurspilot-spezifisch sind nur automatisierte
Moodle-Befüllung, Planstrenge, Freigabeweiche und geführte Journalpflege. Ein
bereits umgesetztes Vorhaben verweist nicht auf einen zweiten Importweg: seine
Kursstruktur liegt im zugehörigen Moodle-Kurs.

## Nicht Bestandteil

- Implementierung in `lib/`, Skilltexten, Konfigurationsprogramm oder
  Migrationsskripten.
- Eine zentrale Tauschplattform, Synchronisation oder ein automatischer
  Paketimport.
- Vollständige AMB-/JSON-LD-Konformität.
- Eine rechtliche Freigabe für konkrete private Geräte, Clouds oder
  Speicherorte.
