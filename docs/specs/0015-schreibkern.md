# Spec 0015 — Schreibkern: Vehikel, Feldkatalog, Quiz, Struktur, Freigabe und Änderungsverlauf

*Karte: [Voller Funktionsumfang für `local_kurspilot`](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/346) · Ticket: [#370](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/370) · Erstes von sechs Specs des Zuschnitts [#359](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/359)*

> **Umgesetzt wird gegen [#377](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/377), nicht gegen dieses Dokument.**
> Das Issue trägt die verbindliche Form — 65 Anwendungsfälle, die drei
> Prüfschnitte, die Abnahmekriterien je Phase als Haken. Dieses Dokument
> beantwortet das *Warum* und ist Nachschlagewerk, keine zweite
> Anforderungsquelle: **eine Anforderung, die nur hier steht, gilt als nicht
> beauftragt.** Wer in #377 eine Entscheidung umstößt, zieht dieses Dokument
> nach — sonst driften Begründung und Bau auseinander.

## Ziel

Der erste Schreibcode des Servermodells. `local_kurspilot` trägt heute neun
Lesetools und keinen einzigen Schreibweg; diese Spec beschreibt, wie eine
KI-Änderung an einer Moodle-Aktivität serverseitig zustande kommt, wie sie
freigegeben wird und wie sie sich zurückholen lässt.

Der Umfang folgt dem ersten Slice der Ersetzungsschwelle
([#351](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351)):
alles, was eine Lehrkraft entlang des `umsetzen`-Workflows braucht, um eine
Lernsituation im Kurs aufzubauen — Aktivitäten anlegen und einstellen,
Abschnitte und Positionen, Sichtbarkeit, Voraussetzungen, Abschlussverfolgung.
**Ohne Dateien** (Spec 0018), **ohne Fragenbank** (Spec 0017).

Leitmotiv der Karte: *weniger Programmcode für denselben Funktionsumfang.*
Diese Spec löst 21 der 38 lokalen Schreibwerkzeuge in vier Endpunkte plus einen
Katalog auf.

`local_kurspilot` bleibt eigenständiger Neubau ohne Abhängigkeit zu
`local_coursepilot` (Spec 0012 §9.2). Der lokale Weg läuft unverändert weiter,
bis er abgekündigt wird; nichts in dieser Spec ändert an ihm etwas.

**Namenskonvention** (Bestand): MCP-Werkzeug `kurspilot_<name>`, Webservice
`local_kurspilot_<name>`, Klasse `\local_kurspilot\external\<name>`. Diese Spec
nennt durchgehend den kurzen Namen.

---

## 1. Schreibmechanismus — durchgängig der Modul-Formularweg

Alles, was eine Modulinstanz berührt, läuft über `add_moduleinfo()` bzw.
`update_moduleinfo()` (`course/modlib.php`). Die direkte DB-Schreibung, mit der
`local_coursepilot` heute zwölf Werkzeuge bedient, wird **nicht** portiert.

Grund: Der Formularweg erledigt generisch mit, was die direkte Schreibung von
Hand nachbaut — `course_modules`-Record, Intro-Editor samt Dateibereich, Tags,
Abschnittszuordnung, Completion, `availability`, Grade-Items und das
`course_module_updated`-Event. Genau dieses Event ist zugleich der Aufhänger des
Änderungsverlaufs (§10); die direkte Schreibung löst es nicht aus und wäre damit
ein blinder zweiter Mechanismus.

Ausnahme, weil es dort kein Formularfeld gibt: **Positionen**
(`move_section_to()`, `moveto_module()`, §7) und die **Quiz-Anordnung**
(Kern-Struktur-API, §10.4).

Nebenbefund, der die Entscheidung stützt: Das schlimmste bekannte Fehlerbild der
Karte — kaputtes `availability`-JSON macht die Kursseite unaufrufbar
(`availability/classes/info.php:138-143`) — ist über `update_moduleinfo()` gar
nicht erreichbar. Es entsteht ausschließlich auf dem heutigen Direkt-DB-Weg und
verschwindet mit dem Umstieg.

→ ADR 0016.

---

## 2. Der Feldkatalog

Der Katalog ist das tragende Teil dieser Spec. Nicht Code, der Felder kennt,
sondern **Daten, die beschreiben, was ein Modultyp kann** — einmal geschrieben
und dreifach genutzt: als Nachschlagewerk für die KI, als Schreibvertrag der
Endpunkte und als Prüfgrundlage der Katalogpflege.

### 2.1 Physische Form

Eine PHP-Klasse je Modultyp unter `\local_kurspilot\catalog\`, gemeinsames
Interface, plus ein gemeinsamer Block (§2.3). `describe_module_fields`
serialisiert sie nach JSON.

PHP und nicht JSON/YAML, weil zwölf Wertebereiche **aufrufbare** Moodle-Quellen
sind (`format_text_menu()`, `resourcelib_get_displayoptions($admin)`,
`forum_get_forum_types()`, `forum_get_subscriptionmode_options()`,
`rating_manager::get_aggregate_types()`, sechs Quiz-Funktionen). Eine
Datei-Repräsentation bräuchte einen eigenen Dialekt für „ruf diese Funktion
auf" — also genau die Übersetzungsschicht, die diese Spec einspart.

„Daten statt Code" bleibt trotzdem gewahrt: ein neuer Modultyp ist **eine neue
Katalogdatei**, kein neuer Endpunkt, keine Registrierung in `db/services.php`,
`dispatcher::TOOL_DESCRIPTIONS`, `dispatcher::TOOL_SCHEMAS` und
`privacy_surface::ALLOWED_TOOLS`.

### 2.2 Fünf Kategorien

Der Katalog trägt mehr als Feldzeilen. Ohne diese fünf Kategorien funktioniert
er nicht (belegt in
[#355](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/355)):

1. **Felder** — Name, Typ, Wertebereich, Default, Pflicht/optional, deutsche
   Bedeutung. Wo Moodle eine aufrufbare Quelle hat, wird sie **referenziert
   statt abgeschrieben**; sonst literal mit Quellenangabe (`Datei:Zeile`).
2. **Pseudofelder** — Nicht-DB-Felder, die die `*_instance()`-Funktionen
   ungeschützt lesen und ohne die ein Schreibvorgang scheitert oder still
   Falsches tut: `files` (resource, folder), `display`/`printintro`/
   `printlastmodified` (page), `popupwidth`/`popupheight` (url bei
   `display=6`), `page`-Array (page beim Update), `ratingtime` (forum),
   `quizpassword`, `feedbacktext[]` und 32 `review*`-Booleans (quiz), 20
   `assignsubmission_*`/`assignfeedback_*`-Felder (assign).
3. **Sperrliste** — Felder, die das Modul selbst nachrechnet und die ein Patch
   nicht setzen darf. Durchgängig: `timemodified`, `timecreated`, `course`.
   Modulweise: `revision` beim Update (page, resource, folder), `name`
   (label), `displayoptions` und `parameters` (page, url, resource),
   `assesstimestart`/`assesstimefinish` (forum), `nosubmissions` (assign),
   `grade`/`sumgrades`/`password` und die acht `review*`-Bitmasken (quiz).
   Dazu die Vervollständigungsfelder (§8) und bis Spec 0018 die Dateifelder
   (§4.3).
4. **Kombinationsregeln** — die elf Bedingungen, die in Moodle nur in
   `validation()` stehen und die ohne Formular nie greifen. Sie sind keine
   Feldeigenschaft, sondern eine Beziehung zwischen Feldern, und stehen
   deshalb als eigene Liste je Modultyp.
5. **Nebenwirkungsvermerk** — Felder, deren Wirkung über die Aktivität
   hinausreicht: Kalendereinträge (choice, forum, assign, quiz),
   `forum.forcesubscribe = 2` (Massen-Abonnement, Mails an alle
   Kursteilnehmenden), `assign.sendnotifications`, `choice.publish` (Wechsel
   anonym → namentlich), `completionunlocked` (Löschung der
   Vervollständigungsdaten der Lernenden).

### 2.3 Der modulübergreifende Block

Sichtbarkeit, Stealth (`visibleoncoursepage`, `coursepagevisibility`),
Gruppenmodus, Gruppierung, `idnumber` und die Abschnittszuordnung liegen in
`course_modules`, nicht in der Instanztabelle — laufen aber durch dieselbe
`update_moduleinfo()`.

Sie stehen **einmal** als gemeinsamer Block im Katalog und werden von allen
Modultypen geerbt. Keine eigenen Endpunkte: das wären genau die
Einzelendpunkte, die diese Spec abschafft. Nebeneffekt: Stealth (KP-014) ist
damit für jeden Modultyp da, statt achtmal nachgezogen zu werden.

Die Lese-Tools zeigen `coursepagevisibility`, `visibleoncoursepage` und
`availability_status` bereits (KP-014); die Feldnamen im Katalog sind mit ihnen
identisch — siehe §3.5.

### 2.4 Presets als Feldbündel

`mini-check`/`lernstandscheck`/`abschlusstest` (Quiz), `standard`/`übung`
(Aufgabe) und `zuteilung` (Abstimmung) sind kein Moodle-Konzept, sondern
Kurspilots didaktischer Mehrwert.
Sie überleben als **benannte Feldbündel im Katalog**, nicht als
Endpunkt-Parameter: `describe_module_fields` liefert sie mit, die KI setzt ein
Bündel ein und überschreibt einzelne Felder daraus. Damit ist der didaktische
Teil sichtbarer als heute, wo er in einer Tool-Beschreibung steckt.

`zuteilung` ist neu und zeigt, wofür Bündel taugen: Gruppeneinteilung und
Geräte-Zuordnung brauchen `limitanswers=1`, `limit[]` je Option (1 für Geräte,
2 für Partnerarbeit), `publish=CHOICE_PUBLISH_NAMES`,
`showresults=CHOICE_SHOWRESULTS_ALWAYS`, `display=CHOICE_DISPLAY_VERTICAL` und
`allowupdate=1` — sechs Felder, die einzeln zu setzen niemand im Kopf hat
(§4.5).

### 2.5 Der Katalog ist die Grenze

**Ein Modultyp ist unterstützt, wenn sein Katalog geprüft ist.** Positive
Freigabeliste, keine Deny-Liste, keine Sonderregel je Modultyp. Für Lehrkräfte
formulierbar als: *„Kurspilot kann die Aktivitätsarten, die er kennt."*

---

## 3. Die vier Endpunkte

| Endpunkt | Rolle |
|---|---|
| `describe_module_fields(modname, vollstaendig?)` | Feldkatalog als Daten |
| `get_module_settings(cmid)` | vollständiger Ist-Stand einer Aktivität |
| `update_module_settings(cmid, felder{})` | Patch auf denselben Feldern |
| `create_module(courseid, sectionnum, modname, felder{})` | Anlage |

### 3.1 `describe_module_fields` — zweistufig

Ohne Parameter: die häufig gesetzten Felder plus Presets plus ein Vermerk, dass
es mehr gibt. Mit `vollstaendig: true`: alle fünf Kategorien.

Grund: `assign` trägt rund 50 Instanzspalten plus ~20 Plugin-Pseudofelder plus
den gemeinsamen Block — mit deutscher Bedeutung je Feld ein sehr großer Abruf
mitten im Kontextfenster der KI. Im Regelfall braucht sie die zwölf Felder, die
eine Lehrkraft je benennt, nicht `markinganonymous`. Ein Feldnamen-Filter als
Alternative wäre zirkulär: er setzt voraus, dass die KI die Namen schon kennt.

Der Endpunkt antwortet **für jeden katalogisierten Modultyp**, auch für solche,
die nicht über das Vehikel geschrieben werden. Der Eintrag trägt dann
ausdrücklich `schreibweg: "update_quiz_settings"` (§5). Ein Vokabular, zwei
Schreibwege — sonst hätte die KI für Quiz gar kein Nachschlagewerk.

### 3.2 `get_module_settings` — das Dokument

Das Dokument ist das `get_moduleinfo_data()`-Feldobjekt als JSON.
**Keine Kurspilot-eigene Zwischendarstellung**, kein Markdown, kein eigenes
Schema: jede Übersetzungsschicht wäre der Code, den diese Spec einspart, und
müsste bei jedem Moodle-Update nachgeführt werden.

Die Lehrkraft liest das Dokument nicht. Sie bekommt die Klartext-Zusammenfassung
der KI und, beim Schreiben, die Änderungsmeldung des Endpunkts (§9.3).

MBZ scheidet aus: kein Core-Webservice erzeugt oder nimmt eine `.mbz`, Restore
ist ausnahmslos `INSERT`, MDL-47776 ist *Won't Do*
([#347](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/347)).

### 3.3 `update_module_settings` — Patch, read-modify-write

Nur übertragene Felder ändern sich; was nicht im Patch steht, bleibt
unangetastet. `update_moduleinfo()` will aber ein vollständiges Objekt — der
Endpunkt liest deshalb unmittelbar vor dem Schreiben den Ist-Stand über
`get_moduleinfo_data()`, überlagert ihn mit dem Patch und schreibt zurück.

**Kein Konfliktschutz, kein `expected_version`.** Das Zeitfenster liegt
*innerhalb* eines Endpunktaufrufs, nicht zwischen KI-Lesen und KI-Schreiben:
Ändert die Lehrkraft zwischendurch von Hand ein anderes Feld, überlebt ihre
Änderung, weil sie frisch gelesen und unverändert zurückgeschrieben wird. Beim
selben Feld gewinnt der spätere Schreibvorgang, und der Änderungsverlauf hat
beide Stände. Eine Pflichtangabe, die die KI raten müsste, kauft für einen
Sekundenbruchteil nichts dazu.

Vollersatz ist verworfen: er hieße, jeden Default je Modultyp zu kennen, und
würde stillschweigend Handeinstellungen zurücksetzen.

### 3.4 `create_module` — Defaults aus dem Katalog

Beim Anlegen gibt es keinen Ist-Stand zum Überlagern. Nicht übergebene Felder
füllt der Endpunkt aus dem **Katalog-Default**; Fehler nur bei einem
Pflichtfeld ohne Default.

Der Katalog-Default ist der **Formular**-Default, nicht der DB-Default — die
weichen bei mehreren Feldern ab. Das ist der Punkt, an dem §3.3 („Vollersatz
verworfen") nicht gilt: beim Anlegen gibt es keine Handänderung, die man
zurücksetzen könnte.

Das entschärft die zwei gefährlichsten belegten Fehlerbilder, und beide entstehen
durch ein **fehlendes**, nicht durch ein unerlaubtes Feld:

- `assign` ohne die `assignsubmission_*_enabled`-Flags schaltet **alle**
  Abgabe-Plugins ab;
- `url` ohne `parameter_N` löscht die URL-Parameter;
- NOT-NULL-Spalten ohne DB-Default (`choice.intro`, `forum.name`/`intro`,
  `assign.name`/`intro`, `quiz.password`/`subnet`/`browsersecurity`/
  `preferredbehaviour`) erzeugen sonst einen lauten DB-Fehler.

### 3.5 Ein Vokabular

**Bindende Auflage:** wo `get_course_catalog`, `get_modules` und der Feldkatalog
dasselbe Feld zeigen, heißt es gleich. Sonst lernt die KI zwei Vokabulare für
dieselbe Sache.

`get_module_settings` wird trotzdem ein **eigener** Endpunkt und keine weitere
Detailstufe von `get_course_catalog`: der Katalog ist der Überblick über einen
ganzen Kurs, `get_module_settings` der vollständige Ist-Stand **einer**
Aktivität in genau der Form, die `update_module_settings` zurücknimmt. Als
Katalogstufe würde er den Katalog aufblähen und die Round-Trip-Eigenschaft
verwischen.

### 3.6 Fehlerbild — eng, alles oder nichts

Unbekannter Feldname, unerlaubter Wert, gesperrtes Feld oder verletzte
Kombinationsregel → Fehler, **nichts** wird geschrieben, kein Teilergebnis. Die
Meldung nennt das betroffene Feld und verweist auf `describe_module_fields`.

Vorbild ist der XML-Frageimport: parsen ist rein lesend, geschrieben wird erst
nach der Prüfung. Nachsichtiges Ignorieren wäre der schlechteste Fall — die KI
meldet Erfolg, die Einstellung fehlt, niemand merkt es.

**Das ist netto mehr Sicherheit als heute.** Auf dem Schreibweg prüft in Moodle
niemand: `course/modlib.php` enthält keinerlei Validierung, der Kern *setzt
voraus*, dass ein Formular schon geprüft hat (Kommentar in Zeile 729), und
Kurspilot ruft wie Moodle selbst mit `$mform = null` auf. Keines der 19 heutigen
Einzelwerkzeuge prüft Wertebereiche. Der Katalog führt die Prüfung überhaupt
erst ein — an einer Stelle statt neunzehnmal.

---

## 4. Abdeckung: acht Modultypen

### 4.1 Freigabeliste

| Modultyp | Ampel | Benannte Sonderbehandlung |
|---|---|---|
| `label` | grün | `name` gesperrt (wird aus dem Intro abgeleitet) |
| `folder` | grün | Pseudofeld `files` beim Anlegen; Dateifelder gesperrt bis 0018 |
| `page` | gelb | Pseudofelder `display`/`printintro`/`printlastmodified`, `page`-Array beim Update (ohne es wird `content` auf `null` gesetzt); `printheading` existiert in Moodle 5.0 nicht mehr |
| `url` | gelb | `externalurl` **nicht** als `PARAM_URL` (§4.4); `popupwidth`/`popupheight` bei `display=6`; `parameter_N` beim Update mitschreiben |
| `resource` | gelb | Hauptdatei ist Pflicht → `create_module` gesperrt bis 0018 (§4.3) |
| `choice` | gelb | keine Kurspilot-eigene Optionsgrenze (§4.4); `optionid[]` nur aus derselben Instanz |
| `forum` | gelb | `forcesubscribe = 2` mit Nebenwirkungsvermerk; `ratingtime`-Pseudofeld |
| `assign` | gelb | 20 Plugin-Pseudofelder, ohne sie schaltet Moodle alle Abgabe-Plugins ab; `teamsubmissiongroupingid` nur aus demselben Kurs |

Gelb heißt nicht „später", sondern „mit benannter Sonderbehandlung, die im
Katalog steht".

### 4.2 Warum `quiz` nicht dabei ist

`quiz` fällt als Vehikel-Kandidat heraus — nicht weil es zu groß wäre, sondern
weil der Formularweg dort die falsche Ebene ist: Feldnamen decken sich nicht mit
Spaltennamen, `grade` ist über ihn gar nicht änderbar, und die Substanz eines
Tests liegt in `quiz_slots`, was kein Feld ist. Kurspilots heutige
Sonderbehandlung ist damit keine Altlast, sondern eine begründete Ausnahme
(§5).

### 4.3 Dateifelder — katalogisiert, gesperrt bis Spec 0018

Alle Dateifelder von `resource`, `folder` und `assign` werden **vollständig
katalogisiert**, stehen aber auf der Sperrliste. Ein Schreibversuch scheitert
mit der Meldung: *„Dateien kann Kurspilot ab Spec 0018. Lege die Datei von Hand
an, dann kann ich alles Weitere."*

Daraus folgt eine Ausnahme beim Anlegen: `resource` **ohne** Hauptdatei erzeugt
eine kaputte Aktivitätsseite (`mod/resource/view.php:69-71`). `create_module`
ist für `resource` deshalb bis 0018 gesperrt; `update_module_settings` bleibt
erlaubt (außer den Dateifeldern). `folder` bleibt anlegbar — ein leerer Ordner
ist gültig.

Erlauben-mit-Warnung wäre der schlechteste Weg: er verletzt §3.6.

### 4.4 Auflagen aus dem Bestand

Die vier Nebenbefunde aus
[#355](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/355) §5
sind auf dem lokalen Weg **bereits behoben**
([#357](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/357),
`ae58d76`, auf `moodle-native-mcp` gemergt) — ausdrücklich mit der Begründung,
„damit der Katalog nicht von fehlerhaftem Bestand abschreibt". Sie stehen hier
als Auflagen, damit der Neubau sie nicht wiedereinführt:

- **Kein `PARAM_URL` für `externalurl`.** `clean_param()` verwirft bei jeder
  Syntaxabweichung still zu `''` (`lib/classes/param.php:1039-1052`) —
  strenger als Moodles eigenes Formular, das serverrelative Links und `mailto:`
  akzeptiert. Der Bestand nimmt jetzt `PARAM_RAW_TRIMMED` plus eine explizite
  Prüfung gegen `url_appears_valid_url()`; der Katalog übernimmt genau das als
  Wertebereich.
- **Keine toten und keine wirkungslosen Felder.** `printheading` (page)
  existiert seit Moodle 5.0 nicht mehr; `displayoptions` (url, resource) wird
  von `url_add_instance()` bzw. `resource_set_display_options()` unmittelbar
  überschrieben — geschrieben wird `printintro`. Beides ist im Bestand
  bereinigt und gehört im Katalog auf die Sperrliste (§2.2).
- **Die 2–6-Grenze für Abstimmungsoptionen ist eine Kurspilot-Vorgabe, keine
  Moodle-Grenze** — im Bestand bewusst behalten und als solche kommentiert.
  Sie geht **nicht** in den Katalog (§4.5). Der Vertrag ändert sich damit
  gegenüber dem lokalen Weg; das ist ein Zugewinn, kein Fähigkeitsverlust, und
  wird in der Parität-Checkliste aus
  [#351](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351)
  als „Vertrag geändert" geführt.

### 4.5 `choice`: Optionen sind ein Feld, keine Feldreihe

Beispielhaft festgehalten, weil es die Bauform des Katalogs an einem realen
Bedarf zeigt (Gruppeneinteilung, Geräte-Zuordnung — 15 bis 30 Optionen):

`option[]` ist **ein** Feld variabler Länge, kein Feld je Option.
`choice_add_instance()` schreibt es mit einer Schleife zeilenweise nach
`choice_options` (`mod/choice/lib.php:110-122`), `choice_update_instance()`
hängt überzählige neue Optionen über den `else`-Zweig an
(`mod/choice/lib.php:151-175`). Moodle kennt **keine Obergrenze** und verlangt
nur `option[0]`.

Katalogeintrag entsprechend: Wertebereich min. 1, keine Obergrenze. Dazu eine
**Kombinationsregel** (§2.2 Kategorie 3): `limit[]` muss dieselbe Länge haben
wie `option[]`. Die didaktische Empfehlung, ab etwa acht Optionen zu prüfen, ob
eine Abstimmung das richtige Werkzeug ist, gehört in die Skill-Prosa und **nicht**
in eine Wertprüfung — eine erfundene Zahl hält die Lehrkraft sonst bei der
Kursstufe mit 34 an.

Die Optionenzahl war ohnehin nur der kleinere Teil des Problems. Der lokale Weg
verdrahtet vier Felder fest, die genau den Zuordnungsfall blockieren
(dort eigens nachgezogen:
[#376](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/376)):
`limitanswers = 0` und `limit = [0,…]` (jede Option unbegrenzt — alle wählen
dasselbe Gerät), `publish = 0` (anonym, man sieht nicht wer), `display = 0`
(horizontal), `allowupdate = 0` (keine Korrektur). Über das Vehikel sind das
vier gewöhnliche Katalogfelder ohne eine Zeile zusätzlichen Code — das ist der
Unterschied, den §1 und §3 kaufen.

---

## 5. `quiz` als Einzelwerkzeug

`create_quiz` und `update_quiz_settings` bleiben eigene Endpunkte mit eigener
Feldkenntnis, portiert nach `local_kurspilot`.

- Beide schreiben über den Formularweg, wo er trägt, und über die
  Moodle-eigenen Quiz-Wege, wo nicht — insbesondere `grade` und `sumgrades`.
- Die drei **Modus-Bündel** (`mini-check`, `lernstandscheck`, `abschlusstest`)
  bleiben und stehen als Presets im Katalog (§2.4).
- `intro` ist seit KP-015 Teil von `update_quiz_settings` und bleibt es.
- **Gesamtfeedback:** ohne `feedbacktext[]` löscht Moodle es still. Der
  Endpunkt liest deshalb wie §3.3 den Ist-Stand und schreibt ihn mit zurück.
- `describe_module_fields('quiz')` liefert Felder, Wertebereiche, deutsche
  Bedeutung und Presets, dazu `schreibweg: "update_quiz_settings"`.
- Die Katalogpflege (§11) gilt für die Einzelwerkzeuge unverändert mit.

Die Quiz-**Anordnung** (Fragen, Seiten, Abschnitte) ist nicht Teil dieser
Endpunkte. Sie wird versioniert (§10.4) und in Spec 0017 (Fragenbank) beschrieben.

---

## 6. Struktur und Positionen

Vier Endpunkte, portiert nach `local_kurspilot`:

- `ensure_section(courseid, sectionnum, name?)` — idempotent: legt an, wenn
  nicht vorhanden, sonst nur Namensabgleich.
- `update_section(courseid, sectionnum, felder{})` — Name, Zusammenfassung,
  Sichtbarkeit.
- `move_section(courseid, von, nach)`
- `move_module(cmid, sectionnum, position?)`

Die beiden `move_*` sind die einzige verbleibende Nicht-Formularweg-Schreibung
im Vehikel-Bereich (§1), weil Moodle für Positionen kein Formularfeld hat.

**Nicht** über `move_section_to()` und `moveto_module()`, die der lokale Weg
heute benutzt: beide sind in **Moodle 5.2 deprecated**
([#243](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/243),
MDL-86854/MDL-86862). Der Neubau setzt direkt auf den Ersatz:

| Vorgang | API |
|---|---|
| Modul verschieben | `core_courseformat\local\cmactions::move_before()` / `move_end_section()` |
| Abschnitt verschieben | `core_courseformat\sectionactions::move_after()` / `move_at()` |

Im selben Umfeld ebenfalls in 5.2 deprecated und deshalb **nicht neu
einzuführen**: `course_delete_module`, `duplicate_module`,
`set_coursemodule_groupmode`, `course_set_marker`, `set_section_visible`. Der
Gruppenmodus und die Sichtbarkeit laufen ohnehin über den Formularweg (§7), das
Löschen und Duplizieren ist nicht Teil dieser Spec.

Das trifft die Recherche aus
[#347](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/347)
genau an ihrer Aussage: `core_courseformat` ist ein **Kommando-Bus ohne
Round-Trip** — als Vehikel untauglich, für Positionen exakt richtig, denn eine
Verschiebung *ist* ein Kommando.

Abschnittssichtbarkeit folgt derselben Regel wie Modulsichtbarkeit: ein
unsichtbarer Abschnitt macht seine Aktivitäten unsichtbar, unabhängig von deren
eigenem Wert. Das steht als Nebenwirkungsvermerk im Katalog.

---

## 7. Sichtbarkeit, Gruppenmodus, Stealth

Kein eigener Endpunkt (§2.3). Alles läuft über
`update_module_settings`/`create_module` bzw. `update_section` auf den Feldern
des modulübergreifenden Blocks:

| Feld | Bedeutung |
|---|---|
| `visible` | im Kurs sichtbar / verborgen |
| `visibleoncoursepage` | Stealth: verfügbar, aber nicht auf der Kursseite gelistet (KP-014) |
| `coursepagevisibility` | der abgeleitete Zustand, den die Lese-Tools zeigen |
| `groupmode` | keine Gruppen / getrennt / sichtbar |
| `groupingid` | Gruppierung (nur IDs, siehe Fog of war) |
| `idnumber` | Kennung für Bewertungsberechnungen |

Stealth setzt voraus, dass die Instanz `allowstealth` erlaubt; ist es aus,
scheitert der Schreibvorgang nach §3.6 mit klarer Meldung statt still.

---

## 8. Vervollständigung und Voraussetzungen

`set_completion(cmid, …)` und `set_restriction(cmid, …)` bleiben eigene
Endpunkte, obwohl `update_moduleinfo()` beide Bereiche generisch mitbehandelt.

**Grund bei der Vervollständigung:** Ohne `completionunlocked` verwirft Moodle
die Vervollständigungsfelder still; **mit** `completionunlocked` löscht es die
Vervollständigungsdaten der Lernenden. Das ist der einzige Querschnittspfad
dieser Spec, der Lernendendaten anfasst.

Festlegung: Die Vervollständigungsfelder stehen auf der **Sperrliste** von
`update_module_settings` und `create_module`. Geschrieben wird ausschließlich
über `set_completion`, und dort nur im **benannten Zweitakt**: der erste Aufruf
meldet, dass Vervollständigungsdaten gelöscht würden, der zweite mit
ausdrücklicher Bestätigung führt aus. Derselbe Zweitakt gilt beim Rückschreiben
(§10.5).

Ein beiläufiges `update_module_settings` darf keine Lernendendaten löschen
können. Ein Pfad für den einzigen Datenverlustfall, nicht zwei.

**Modulspezifische Vervollständigungsfelder** (Ticket #461, Nachtrag zur
Erstfassung): `completionsubmit` — „Abgabe erforderlich" bei `assign`,
„Abstimmung abgegeben" bei `choice` — ist Vervollständigungsfeld *und* echte
Spalte der Instanztabelle zugleich. Fachlich gehört es hierher, nicht in einen
Feld-Patch: `mod_assign::update_instance()` schreibt es nur innerhalb von
`if (!empty($formdata->completionunlocked))`, es unterliegt also demselben
Datenverlust-Zweitakt wie die vier generischen Sperrfelder. Festlegung:

- `set_completion` führt es je Aktivitätsart frei (`assign`, `choice`); bei
  jeder anderen antwortet der Endpunkt mit einem **Wegweiser** auf die
  Aktivitätsarten, die es tragen, statt mit „Unbekanntes Feld".
- Es steht damit auf der Sperrliste **beider** Modultypen — auch bei `choice`,
  wo Moodle es über den Formularweg zwar durchschreiben würde, aber ohne
  `completionunlocked`: die Lernenden blieben unter der alten Regel als
  abgeschlossen stehen. Eine Abstimmung braucht dafür seither einen zweiten
  Aufruf nach `create_module`.
- Der Feldkatalog (`describe_module_fields`) führt es bei **keiner**
  Aktivitätsart als setzbares Feld — wie jedes Vervollständigungsfeld. Den Weg
  nennt stattdessen die Sperrmeldung (s. u.) und die Werkzeugbeschreibung von
  `set_completion`.

**Die Sperrmeldung nennt den Weg.** „Feld ist gesperrt" sagte nicht, wohin
stattdessen — im Abnahmelauf zu #456 scheiterte ein Modell daran fünfmal in
Folge. `update_module_settings` und `create_module` antworten deshalb bei jedem
Vervollständigungsfeld (den sieben generischen samt `completionunlocked` und
dem modulspezifischen `completionsubmit`) mit einem Verweis auf
`set_completion`, nicht mit der bloßen Sperrmeldung — dieselbe Haltung wie beim
Lese-Vokabular in §3.5.

**Bei den Voraussetzungen** ist der Grund schlichter: `set_restriction` baut
`availability`-JSON aus Lehrkraft-verständlichen Argumenten. Über den
Formularweg ist das Feld nicht kaputtschreibbar (§1) — die Kapselung bleibt
trotzdem, weil rohes JSON kein Vertrag ist, den eine KI zuverlässig trifft.
Der Lese-Weg maskiert `profile`-Bedingungen unverändert (ADR 0011).

---

## 9. Freigabe, Rechte, Fehler

### 9.1 Freigabe — clientseitig, ohne serverseitiges Tor

Die Lehrkraft gibt **jede Änderung im Chat frei**. Granularität: je
Schreibvorgang. Keine Klammer über mehrere Aufrufe, kein Schreibfenster, kein
serverseitiges Freigabe-Tor („dann kann man es auch selber machen").

Folge, ehrlich benannt: der Freigabeakt geschieht im Chat und ist serverseitig
nicht protokollierbar. Protokolliert wird der **Schreibvorgang** (Person, Kurs,
Ergebnis). Das Gegengewicht zur fehlenden serverseitigen Kontrolle ist der
Änderungsverlauf (§10).

**Kein Trockenlauf.** `get_module_settings` zeigt den Ist-Stand, der Katalog
prüft vor dem Schreiben, die Antwort meldet den Vollzug — ein separater
Trockenlauf verdoppelte Code ohne zusätzlichen Schutz.

### 9.2 Rechte

**Keine zusätzliche Kurspilot-Schreib-Capability.** Die nativen
Moodle-Capabilities sind das Tor: `teacher` und `editingteacher` behalten beide
`local/kurspilot:use` (`db/access.php`, keine Änderung); jeder
**Schreib**endpunkt prüft zusätzlich die native Capability des Vorgangs
(`moodle/course:manageactivities`, `moodle/course:sectionvisibility`,
`moodle/course:activityvisibility`, …) im jeweiligen Kurskontext.

Im Kurs einer Kollegin: lesen und exportieren ja, schreiben nein — mit klarer
Meldung, nicht still.

Für den Änderungsverlauf kommen zwei eigene Fähigkeiten dazu (§10.6).

### 9.3 Die Antwort ist die Änderungsmeldung

Die Antwort jedes Schreibendpunkts **ist** die Änderungsmeldung in
Lehrkraft-Deutsch — damit das Aussprechen der Änderung auf Daten steht und nicht
auf Modellwohlwollen. Sie nennt, was sich von welchem auf welchen Wert geändert
hat, und benennt ausgelöste Nebenwirkungen aus §2.2 Kategorie 5 ausdrücklich
(„Alle Kursteilnehmenden wurden für dieses Forum abonniert").

### 9.4 Fehler und Teilstände

Keine transaktionale Klammer über mehrere Aufrufe — die existiert im
Moodle-Core nicht. Scheitert Schritt 4 von 7, bleibt der Teilstand stehen; die
Fehlermeldung nennt den Punkt des Scheiterns, und der Änderungsverlauf ist der
Rückweg. Je einzelnem Endpunkt bleibt es bei alles-oder-nichts (§3.6).

### 9.5 Protokollierung

Die vier Stufen aus dem Bestand bleiben, die Bedeutung rückt: 0 aus,
1 Schreibzugriffe + Fehler, 2 zusätzlich Lesezugriffe (**Voreinstellung**),
3 alles. Sonst wären Schreibvorgänge in der Voreinstellung unprotokolliert.
Fortführung über die Moodle-Ereignis-API; die Aufbewahrung des Zugriffsprotokolls
ist im Moodle-Logstore bereits admin-seitig einstellbar.

---

## 10. Änderungsverlauf

Das Gegengewicht zur clientseitigen Freigabe. Er muss stehen, **bevor** der
erste Kurspilot-Schreibvorgang live geht (§12, Phase 2).

### 10.1 Muster und Anker

Nach dem Muster des **Notenbuchs** (`grade_object::update()` — Vollkopie in eine
Schattentabelle), nicht der Fragenbank: das Fragenbank-Muster lebt von
`question_references`, bei Aktivitäten zeigt alles direkt auf die Instanz-ID.

- Anker ist die **stabile `cmid`** — der Formularweg hält sie stabil.
- **Rückkehr heißt vorwärts fortschreiben:** ein alter Stand wird Version N+1,
  nie ein Rückspulen.
- **Keine sichtbaren Kopien im Kurs.** Der Verlauf liegt in der Datenbank.
  „Aktivität duplizieren als Sicherung" ist als Bauform ausgeschlossen — genau
  so sind in der Praxis Kurse mit hunderten Aktivitäten entstanden, die nur die
  Lehrkraft von Hand wieder loswird.

→ ADR 0018.

### 10.2 Auslöser

Jedes `course_module_updated` — Formularweg, Kursseite, Massenbearbeitung,
**einschließlich aller Handänderungen** der Lehrkraft und aller
Kurspilot-Schreibvorgänge. Dazu Beobachter auf den 16
`mod_quiz`-Struktur-Ereignissen für die Anordnung.

**Der Verlauf ist nicht lückenlos, und das wird ausgewiesen, nicht kaschiert.**
Blind bleiben: Quiz-Inhalt jenseits der Anordnung, Notenbuch, Restore, direkte
DB-Schreibungen, der Quiz-Filter-Condition-Webservice (kein Ereignis). Die
Lücke ist erkennbar, nicht schließbar. Fangnetz ist der Vergleich **geplanter
Stand gegen Kurs-Ist**; die Pläne liegen ab Spec 0016 im Kontextbereich, eine
Abweichung muss auffallen.

### 10.3 Bestandsaktivitäten — Backfill beim ersten Ereignis

Version 1 entsteht beim Anlegen. Für Aktivitäten, die **vor** Einführung des
Verlaufs existieren, gibt es kein Anlegen mehr.

Findet der Beobachter für eine `cmid` noch keinen Stand, legt er **zwei**
Versionen an: den Vorher-Stand als Version 1 mit `quelle: "vorgefunden"` und den
neuen als Version 2. Kostet im Leerlauf nichts, und der Vorher-Stand ist genau
der, den man beim ersten missglückten KI-Schreibvorgang braucht. Ein
Massen-Backfill beim Plugin-Upgrade würde tausende Stände schnappen, die nie
jemand ansieht.

Dieselbe Bauform wie der `idnumber`-Backfill aus
[#354](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/354).

### 10.4 Was ein Stand enthält

Vollstände, keine Diffs — das Diff wird beim Ansehen berechnet. Je Stand:

- `get_moduleinfo_data()` komplett (Instanz-Record, Intro mit Dateien, Tags,
  `availability`, `gradepass`/`gradecat`/Outcomes);
- die `course_modules`-Zeile;
- die **Datei-Zeilen** des Modulkontexts — nur Metadaten, dedupliziert, keine
  Bytes. Dateiinhalte außerhalb des Intro sind nicht rückschreibbar und werden
  im Stand als Lücke markiert;
- für Quiz der **Anordnungs-Stand**: `quiz_slots` + `question_references`,
  `quiz_sections`, `quiz_feedback` (gemessen ~0,4 KB gzip).

Größe unkritisch: ~1 KB gzip je Version. Keine Stückgrenze.

### 10.5 Rückschreiben und die drei Schutzschienen

Ausschließlich über `update_moduleinfo()` bzw., für die Quiz-Anordnung, die
Kern-Struktur-API (`move_slot`/`remove_slot`/`update_page_break`/
`update_slot_version`). Kein eigener Schreibmechanismus.

1. **Vervollständigung.** `completionunlocked` wird nie automatisch angewandt.
   Die Felder sind vom Rückschreiben standardmäßig ausgeklammert — der aktuelle
   Stand bleibt erhalten, die Abweichung erscheint im Diff. Zurückgespielt wird
   nur über den benannten Zweitakt aus §8 mit Datenverlust-Warnung.
2. **Quiz mit Versuchen.** `quiz_has_attempts()` wird **vor** dem Rückschreiben
   geprüft, nicht als Ausnahme abgefangen. Danach ist die Anordnung nur noch
   Chronik, und die Oberfläche sagt das klar. Die Slot-Manipulation ist
   internes Mittel des Rückschreibens und **kein** MCP-Werkzeug.
3. **Frage-Versionen.** Referenzen werden exakt wie gespeichert
   wiederhergestellt: `version=null` („immer aktuellste") bleibt `null`, eine
   inzwischen bearbeitete Frage erscheint in ihrer dann aktuellen Version. Kein
   nachträgliches Pinnen. Hinweis in der Oberfläche: „Fragen erscheinen in der
   jeweils neuesten Fassung."

### 10.6 Oberfläche

Der typische Fall ist der Mehrversionen-Überblick („in den letzten Versionen
wurde das und das geändert"), nicht die bekannte Versionsnummer.

- `list_activity_versions(cmid)` — alle Versionen mit je **einer Zeile
  Änderung** gegenüber dem Vorgänger, serverseitig aus den Vollständen
  berechnet, in Lehrkraft-Deutsch (wer, wann, wodurch). Die KI geht die Liste
  schrittweise durch.
- `compare_activity_versions(cmid, a, b)` — volles Diff zweier frei gewählter
  Stände.
- `restore_activity_version(cmid, zielversion)` — Fortschreiben als N+1.
  Rückschreiben ist ein Schreibvorgang → clientseitige Freigabe (§9.1), mit den
  Schutzschienen aus §10.5.

Dazu eine **minimale Moodle-Seite** an der Kursnavigation (Vorbild Papierkorb):
Liste, Einzeiler je Version, Rückschreiben mit Bestätigung — damit die Lehrkraft
auch ohne laufenden Chat handeln kann. Auf der Seite handelt sie selbst wie im
Modulformular; kein Kurspilot-Freigabeakt. Hübsche Diffs auf der Seite später.

Eigene Fähigkeiten: `local/kurspilot:viewhistory` und
`local/kurspilot:restoreversion`; Rückschreiben verlangt zusätzlich
`moodle/course:manageactivities`.

### 10.7 Aufbewahrung

- **Kurs weg, Verlauf weg** (Kurs-Kaskade).
- Aktivität gelöscht → Verlauf mitgelöscht. Der Papierkorb hält den Inhalt
  ohnehin sieben Tage als `.mbz`.
- Löschfrist Standard **1 Jahr**, admin-seitig einstellbar (verkürzen oder
  abschalten). „Keine Frist" ist ausgeschlossen — Speicherplatz.

### 10.8 Herauslösbarkeit

Gebaut wird in `local_kurspilot`, aber mit der Schnittebene eines späteren
eigenständigen `tool_`-Plugins: keine Kurspilot-Begriffe im DB-Schema,
`source`-Feld von Anfang an, der Beobachter serialisiert nur (keine MCP- oder
Webservice-Aufrufe), Schnappschuss und Rückschreiben streng über
`get_moduleinfo_data()`/`update_moduleinfo()` bzw. die Struktur-API, Speicherung
getrennt von der Oberfläche.

---

## 11. Katalogpflege über Moodle-Versionen

Der Katalog ist überwiegend abgeschrieben und **veraltet deshalb still**: außer
`format_text_menu()` trägt keine einzige aufrufbare Quelle über mehr als einen
Modultyp; für `assign` gibt es bei 34 Konstanten null aufrufbare Wertemengen.

**Selbstfreigabe in zwei Stufen statt Voll-Fail-closed** (→ ADR 0017):

1. **Billigteil** bei jedem Schreibvorgang: Moodle-Versionsstring gegen den
   erklärten Geltungsbereich des Katalogs.
2. **Tiefenprüfung** je Modultyp, automatisch bei erkanntem Versionswechsel
   (auch Point-Release) und jederzeit abrufbar. Maschinell prüfbar: Spalten
   (dazu / weg / umbenannt), Existenz der zwölf aufrufbaren Quellen,
   Konstanten-Existenz.
   - **Grün** ⇒ Modultyp bleibt schreibbar, Status „automatisch geprüft".
   - **Drift** ⇒ **nur dieser Modultyp** ist schreibgesperrt, Lesen läuft
     weiter, Meldung an die Lehrkraft: „bitte der Administration melden".
3. **Manuelles Review** je Major-Release ist Pflicht für das nicht maschinell
   Prüfbare — abgeschriebene Wertelisten, Kombinationsregeln,
   Nebenwirkungsvermerke. Es hebt den Status auf „geprüft".

Sichtbar über die Core-Check-API (`local_kurspilot_status_checks()`) in der
Admin-Statusprüfung, je Modultyp „geprüft / automatisch geprüft / braucht
Arbeit". **Kein Cron.**

Geltungsbereich je Modultyp und pro Major-Version. Katalog-Update = Plugin-Release,
vorbereitet gegen die Moodle-Preview, freigegeben gegen das finale Release; keine
vom Plugin getrennte Katalog-Auslieferung. Die Einzelwerkzeuge (§5) unterliegen
demselben Regime.

**Restrisiko, benannt:** Zwischen Major-Upgrade und Katalog-Release können nur
die nicht prüfbaren Kategorien driften, vor allem Auswahllisten. Folge ist ein
stiller Fehlwert oder ein lauter Fehler, **kein Datenverlust**: Konstantenwerte
sind über die vorhandenen DB-Daten de facto eingefroren, und die
Datenverlust-Klassen hängen am Schnappschuss, nicht an der Katalog-Frische.

---

## 12. Umsetzungsphasen

Strikt seriell (afk-pipeline), Blockierungskette. Die Tickets entstehen aus
dieser Spec, wenn die Umsetzung startet — nicht vorher.

| Phase | Inhalt | Warum hier |
|---|---|---|
| **1** | Feldkatalog (acht Typen + gemeinsamer Block + quiz-Eintrag), `describe_module_fields`, `get_module_settings` | rein lesend, kein Risiko; erzeugt das Vokabular, auf dem alles Weitere steht |
| **2** | Änderungsverlauf: Beobachter, Schnappschuss, Backfill, Speicherung — **ohne** Rückschreibweg | schnappt ab hier auch Handänderungen mit und muss stehen, bevor Kurspilot erstmals schreibt |
| **3** | `update_module_settings`, `create_module`, Wertprüfung, Struktur (§6), Sichtbarkeit/Stealth (§7), `set_completion`, `set_restriction` | der eigentliche Schreibkern, jetzt mit Netz |
| **4** | `list_/compare_/restore_activity_versions`, Quiz-Anordnungs-Stand, Moodle-Seite an der Kursnavigation | Rückschreiben setzt Phase 2 und 3 voraus |
| **5** | `create_quiz`, `update_quiz_settings`, Katalogpflege-Drift-Check, Admin-Check-Seite | Einzelwerkzeuge und Pflege-Regime zum Schluss |

---

## 13. Abnahmekriterien

**Je Phase** (PHPUnit gegen Moodle 5.0):

- Phase 1: Katalog-gegen-`$DB->get_columns()`-Test meckert, sobald ein
  Moodle-Update eine Spalte hinzufügt, die der Katalog nicht kennt; Wertelisten
  gegen die zwölf aufrufbaren Quellen; `privacy_surface::ALLOWED_TOOLS` bleibt
  deckungsgleich mit dem externen Dienst.
- Phase 2: Handänderung im Modulformular erzeugt einen Stand; eine Aktivität
  ohne Verlauf erzeugt beim ersten Ereignis zwei Versionen, davon Version 1 mit
  `quelle: "vorgefunden"`.
- Phase 3: unbekannter Feldname, unerlaubter Wert und gesperrtes Feld
  scheitern je alles-oder-nichts; ein Patch auf ein Feld lässt eine parallele
  Handänderung an einem anderen Feld unangetastet; `create_module('assign')`
  ohne Plugin-Flags erzeugt eine Aufgabe **mit** aktiven Abgabe-Plugins;
  `create_module('resource')` scheitert mit der 0018-Meldung; ein
  Vervollständigungsfeld in `update_module_settings` scheitert.
- Phase 4: `restore_activity_version` erzeugt N+1 statt Rückspulen und lässt
  die `cmid` unverändert; Quiz mit Versuchen verweigert die Anordnung
  **vorher**, nicht per Ausnahme.
- Phase 5: Drift in einem Modultyp sperrt genau diesen und lässt die übrigen
  sieben schreibbar; Lesen bleibt in allen Fällen möglich.

**Gesamt** (Lehrkraft-Durchlauf auf der Testinstanz): Eine vollständige
Lernsituation **ausschließlich serverseitig** angelegt — Abschnitt, Label,
Seiten, Aufgabe, Abstimmung, Forum, Quiz mit Preset, Sichtbarkeit einschließlich
Stealth, Voraussetzungen, Abschlussverfolgung — ohne einen einzigen Rückgriff
auf den lokalen Weg. Danach eine Fehländerung über `restore_activity_version`
zurückgeholt.

Das ist Spec 0012 §9.5 minus Dateien (Spec 0018) und zugleich der erste Slice
der Ersetzungsschwelle aus
[#351](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351).

---

## 14. Begriffe

Diese Spec zurrt sieben Begriffe fest; sie stehen im Glossar (`CONTEXT.md`):
**Feldkatalog**, **Feldbündel** (Preset), **Änderungsverlauf**, **Stand**,
**Fortschreiben**, **vorgefunden**, **außerhalb des Verlaufs geändert**.

Drei Entscheidungen sind ADR-wert und liegen als
[ADR 0016](../adr/0016-modul-formularweg-statt-direkter-db-schreibung.md),
[ADR 0017](../adr/0017-katalogpflege-selbstfreigabe-in-zwei-stufen.md) und
[ADR 0018](../adr/0018-aenderungsverlauf-im-notenbuch-muster.md) bei.

---

## Fog of war — bewusst nicht Teil dieser Spec

- **Gruppierungsnamen im Katalog.** V1 liefert nur IDs. Wird ticketfähig, wenn
  die ID-Regel die Aufgabenerstellung mit Gruppen praktisch blockiert.
- **Abruf-Endpunkt „was lief hier über Kurspilot".** Aus
  [#350](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/350):
  kennt nur Kurspilot-Läufe, ist bewusst keine vollständige Kurschronik. Später.
- **Hübsche Diffs auf der Moodle-Seite.** Phase 4 liefert Liste, Einzeiler und
  Rückschreiben; die Darstellung des vollen Diffs im Browser folgt.
- **Weitere Modultypen im Katalog.** Die Freigabeliste wächst durch Hinzufügen
  von Katalogdateien; welche als nächste, entscheidet der Bedarf.
- **Herauslösung des Änderungsverlaufs als eigenes `tool_`-Plugin.** §10.8
  hält die Schnittebene frei; die Herauslösung selbst ist eigene Arbeit.

## Out of scope

- **Dateien** — Upload, Dateifelder, Bildzuschnitt: Spec 0018. In dieser Spec
  katalogisiert und gesperrt (§4.3).
- **Fragenbank, XML-Import, Klonen, Quiz-Anordnung als Werkzeug** — Spec 0017.
- **Kontextbereich schreibend** — Spec 0016.
- **Cleanup-Ports, Skill-Verteilung** — Specs 0019 und 0020.
- **Abschaltung des lokalen Wegs.** Später, nach Abnahme der Umsetzung.
- **Lernendendaten** — Abgaben, Forenbeiträge, Testversuche, Bewertungen,
  Teilnehmendenlisten: weder lesend noch schreibend. Einzige Berührung ist die
  *Löschung* der Vervollständigungsdaten, und die ist doppelt eingezäunt (§8).
- **Korrektur und Bewertung.**
- **Implementierung.** Die Karte endet bei den freigegebenen Specs.

## Quellenkarte

Alle Entscheidungen dieser Spec sind auf der Karte
[#346](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/346) mit
Ticket-Verweis nachvollziehbar:

| Abschnitt | Ticket |
|---|---|
| §1, §2, §3 | [#349 Werkzeugform](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/349), [#347 Vehikel-Recherche](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/347) |
| §2.2, §4 | [#355 Feldkatalog-Recherche](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/355) · Bericht `docs/research/0355-feldkatalog-modultypen.md` |
| §5, §12 | [#351 Ersetzungsschwelle](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/351), [#359 Spec-Zuschnitt](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/359) |
| §8, §9 | [#350 Schreibpfad und Freigabemodell](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/350) |
| §10 | [#353 Versionierung](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/353), [#358 Recherche Änderungsverlauf](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/358), [#365 Quiz-Anordnung](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/365) |
| §11 | [#356 Katalogpflege](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/356) |
| Vorarbeit | [#357 Vier Feldfehler](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/357), KP-014 Stealth, KP-015 Quiz-Intro |

Bei Detailfragen: Ticket zoomen, nicht diese Spec erweitern.
