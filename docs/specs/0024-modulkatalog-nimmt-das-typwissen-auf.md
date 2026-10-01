# Spec 0024 — Der Modulkatalog nimmt das Typwissen auf

*Kandidat E aus dem Architektur-Review vom 18./19.09.2026. Tracking-Issue: [#533](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/533).*

> **Umgesetzt wird gegen das Issue, nicht gegen dieses Dokument.** Das Issue trägt die
> verbindliche Form — User Stories und Abnahmekriterien als Haken. Dieses Dokument
> beantwortet das *Warum* und ist Nachschlagewerk, keine zweite Anforderungsquelle.

## Problem Statement

Der **Feldkatalog** sollte die eine Stelle sein, an der steht, welche Felder ein Modultyp
kennt (ADR 0016, ADR 0017). Für das Schreiben ist er das auch. Für das Lesen und für
Sonderfälle ist er es nicht: Das Anlegen und das Ändern von Aktivitäten führen eigene
Typtabellen mit, die Katalogansicht liest mit eigenen Lesern je Typ am Katalog vorbei, und
das Zurückholen eines Standes behandelt den Test gesondert.

Die Folge ist Drift: Wer einen Modultyp ergänzt oder ein Feld nachträgt, muss mehrere
Stellen finden, die nichts voneinander wissen. Für die Lehrkraft äußert sich das als
Ungleichheit — ein Feld lässt sich setzen, erscheint aber nicht in der Katalogansicht, oder
umgekehrt.

Zusätzlich wird die Feldangabe eines Aufrufs an vier Stellen einzeln geprüft, jeweils mit
demselben deutschen Literaltext.

## Solution

Der **Modulkatalog** übernimmt das gesamte typbezogene Wissen: welche Felder es gibt, wie
eine Feldangabe gelesen wird und wie der wirksame Zustand einer Aktivität ausgelesen wird.
Die Werkzeuge fragen nur noch ihn.

Ein neuer Modultyp ist danach eine Katalogklasse, kein Rundgang durch mehrere Werkzeuge.

## Implementation Decisions

- **Der Katalog beantwortet drei Fragen je Modultyp:** Welche Felder gibt es (mit ihren
  Regeln)? Wie wird eine übergebene Feldangabe gelesen und geprüft? Wie sieht der wirksame
  Zustand einer vorhandenen Aktivität aus?
- **Die Werkzeuge verlieren ihre eigenen Typtabellen.** Anlegen, Ändern und die
  Katalogansicht beziehen sich auf den Katalog.
- **Die Prüfung der Feldangabe liegt einmal im Katalog**, mit einem Fehlerschlüssel aus den
  Sprachdateien statt vier gleichlautender Literaltexte.
- **Der Test bleibt die begründete Ausnahme.** ADR 0016 hält fest, warum: Dort decken sich
  Feldnamen nicht mit Spaltennamen, die Bewertung ist über den Formularweg nicht änderbar,
  und die Substanz liegt in der Fragenanordnung. Der Katalog bildet diese Ausnahme ab,
  statt sie zu verstecken.
- **Der Formularweg bleibt unverändert** (ADR 0016): Alles, was eine Modulinstanz berührt,
  läuft weiter über Moodles eigene Schreibwege.
- **Die Katalogpflege bleibt zweistufig** (ADR 0017); die Driftprüfung deckt künftig auch
  den Leseweg ab.

## Testing Decisions

Ein guter Test prüft hier das beobachtbare Verhalten je Modultyp: Was lässt sich setzen, was
kommt beim Lesen zurück, was wird mit welcher Begründung abgelehnt.

- **Seam (bestehend): der Katalogvertrag.** Die vorhandenen Vertragstests je Modultyp sind
  das Muster; sie werden um Lesen und Feldangabe-Prüfung erweitert und decken danach alle
  neun Typen gleichartig ab.
- **Ein Rundlauf je Typ:** anlegen, lesen, ein einzelnes Feld ändern, erneut lesen — nicht
  genannte Werte bleiben erhalten. Dieses Muster stammt aus der Formularparitäts-Arbeit.
- **Die Driftprüfung wird erweitert**, sodass ein am Katalog vorbei gelesenes Feld auffällt.
- **Keine neuen Seams.** Die Werkzeuge bleiben die äußere Prüffläche.

## Out of Scope

- Neue Modultypen.
- Änderungen am Schreibweg oder am Änderungsverlauf.
- Die Fragenbank und ihre Werkzeuge.

## Further Notes

Dieser Umbau hat die geringste Dringlichkeit der vier: Nur drei der letzten vierzig
Änderungen lagen hier. Er zahlt sich beim nächsten Modultyp aus und sollte deshalb nach den
Specs 0021 bis 0023 kommen.
