# Spec 0022 — Ein Werkzeug, eine Deklaration

*Kandidat B aus dem Architektur-Review vom 18./19.09.2026. Tracking-Issue: [#531](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/531).*

> **Umgesetzt wird gegen das Issue, nicht gegen dieses Dokument.** Das Issue trägt die
> verbindliche Form — User Stories und Abnahmekriterien als Haken. Dieses Dokument
> beantwortet das *Warum* und ist Nachschlagewerk, keine zweite Anforderungsquelle.

## Problem Statement

Jedes der 49 Werkzeuge beschreibt seine Parameter zweimal: einmal als Schema in der
Werkzeug-Registrierung, das die KI zu sehen bekommt, und einmal in der externen Funktion,
gegen die Moodle tatsächlich prüft. Beide Fassungen können auseinanderlaufen, und sie tun
es bereits: Wo die Registrierung von einer Zahl spricht, verlangt Moodle eine Ganzzahl.
Kein Test vergleicht die beiden Seiten.

Für die Lehrkraft äußert sich das als scheinbar grundlose Ablehnung: Die KI füllt ein Feld
so aus, wie die Werkzeugbeschreibung es nahelegt, und Moodle weist den Aufruf zurück.

Dazu kommt ein zweites Problem, das dieselbe Stelle betrifft: Der Werkzeugvertrag ist
teilweise deutsch. Zehn Parameternamen und mehrere Rückgabeschlüssel tragen deutsche
Bezeichner, dazu rund 1.450 Zeilen deutscher Beschreibungstext außerhalb der Sprachdateien.
Für eine internationale Veröffentlichung ist das unbrauchbar, und nach der Veröffentlichung
ist der Vertrag nur noch mit Bruch änderbar (ADR 0024).

## Solution

Ein Werkzeug wird genau einmal deklariert. Die Registrierung leitet das Schema, das die KI
sieht, aus der Parameterbeschreibung der externen Funktion ab. Ein Registrierungseintrag
nennt danach nur noch Name, Klasse und den Schlüssel für die Beschreibung.

Im selben Zug wird der Werkzeugvertrag englisch: Parameternamen, Rückgabeschlüssel,
Beschreibungen und die verbliebenen deutschen Klassennamen.

## User-sichtbare Wirkung

Die KI sieht dieselben Typen, die Moodle prüft. Abgelehnte Aufrufe wegen abweichender
Typangaben verschwinden. Die Werkzeugliste bleibt inhaltlich gleich; die Werkzeugnamen
selbst ändern sich nicht.

## Implementation Decisions

- **Eine Quelle für Parameter und Typen:** die Parameterbeschreibung der externen Funktion.
  Die Registrierung erzeugt daraus das Schema für die KI, einschließlich Pflichtfeldern,
  Standardwerten und Aufzählungen, soweit Moodle sie ausdrückt.
- **Ein Konverter, nicht 49.** Die Umwandlung liegt einmal in der Registrierung und ist
  nicht Teil der Schnittstelle nach außen.
- **Der Registrierungseintrag schrumpft** auf Name, Klasse und Beschreibungsschlüssel. Die
  bisherige Doppelung aus Webservice-Beschreibung und Werkzeugbeschreibung entfällt.
- **Werkzeugbeschreibungen kommen aus den Sprachdateien.** Sie sind damit englisch,
  AMOS-fähig und nicht länger als Literaltext im Code. Sie richten sich an die KI, nicht an
  Menschen — trotzdem gilt die Regel „nur englische Strings ausliefern" des Marketplace.
- **Englischer Werkzeugvertrag:** Die deutschen Parameternamen und Rückgabeschlüssel werden
  englisch. Weil nichts veröffentlicht ist, gibt es keine Abwärtskompatibilität und keine
  Übergangsfrist; der Skill-Korpus zieht im selben Schritt mit.
- **Deutsche Klassennamen werden englisch**, die betroffenen Klassen behalten ihr Verhalten.
- **Die abgeleiteten Listen bleiben abgeleitet.** Webservice-Registrierung, Datenschutz-
  Oberfläche und Werkzeugliste des Dispatchers beziehen ihre Angaben weiter aus der einen
  Registrierung.
- **Der Skill-Korpus bleibt deutsch** (ADR 0024); nur die darin genannten Werkzeug- und
  Feldnamen ziehen mit.

## Testing Decisions

Ein guter Test prüft hier, was ein MCP-Client sieht und was Moodle akzeptiert — nicht, wie
die Umwandlung intern arbeitet.

- **Seam (bestehend): der Dispatcher.** Ein Vertragstest über **alle** Werkzeuge prüft, dass
  das ausgelieferte Schema und die Parameterprüfung von Moodle dasselbe beschreiben: gleiche
  Felder, gleiche Pflichtangaben, verträgliche Typen. Heute prüft der vorhandene Test fünf
  Werkzeuge stichprobenartig; er wird durch die vollständige Prüfung ersetzt.
- **Die Spracheigenschaft wird mitgeprüft:** kein deutscher Bezeichner im Werkzeugvertrag,
  keine Beschreibung als Literaltext im Code.
- **Bestehende Kopplung bleibt:** Die Prüfung, dass jedes registrierte Werkzeug im
  Skill-Korpus vorkommt und umgekehrt, gilt unverändert und fängt vergessene Umbenennungen.
- **Node-seitige Vertragstests** ziehen mit; sie lesen den Plugin-Quelltext und prüfen
  Werkzeugnamen und Beschreibungstexte.

## Out of Scope

- Umbenennung der Werkzeuge selbst (`coursepilot_*` bleibt).
- Zweisprachiger Skill-Korpus.
- Änderungen an der Werkzeugauswahl, am Zuschnitt der Werkzeuge oder an ihren Fähigkeiten.
- Die MCP-Profiltrennung (lesend/schreibend).

## Further Notes

Die Umstellung ist der letzte billige Zeitpunkt für den englischen Vertrag: Nach der
Einreichung im Marketplace hängen fremde Installationen daran.
