# Ausfall ohne Rückfall: Ausstandsnotiz am Anker

Kontextbereich und Materialbestand können in einem WebDAV-Speicher der Lehrkraft liegen
(Karte #467). Ein solcher Speicher kann ausfallen, Moodle selbst läuft dabei weiter. Offen war,
was mit Inhalt geschieht, der geschrieben werden soll, während der Speicher nicht antwortet.
Entschieden in [#475](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/475).

## Entscheidung

**1. Kein Rückfall.** Schreibt der externe Speicher nicht, legt Kurspilot den Inhalt nirgendwo
anders ab, auch nicht in Private Files. Der Fehler geht an die KI. Sie behält den Inhalt im
Gespräch und schreibt ihn nach, sobald die Verbindung wieder steht.

**2. Das Plugin vermerkt den Verlust selbst.** Scheitert gültiger Inhalt am Speicher, schreibt
das Plugin im selben Aufruf einen Eintrag in die **Ausstandsnotiz** (`.kurspilot-ausstand.json`
im Anker neben dem Kontextpointer) und gibt erst dann den Fehler zurück. Ein Eintrag nennt
Kennung, Zeitpunkt, relativen Pfad, Vorgang und Fehlerklasse, nie den Inhalt. Ursache ist
alles, was vom Speicher, der Verbindung oder dem Ort herrührt, auch eine gelöschte Instanz.
Ausgenommen sind Aufruffehler und `Konflikt`: In beiden Fällen liegt der Inhalt noch im
Gespräch und der Ablauf geht weiter.

**3. Abgehakt wird ausdrücklich, nie durch Zeitablauf.** Ein Eintrag verschwindet nur auf zwei
Wegen. Entweder schreibt ein erfolgreiches `write`/`append` mit `ausstand=<Kennung>` nach und
hakt ihn im selben Aufruf ab. Oder die Lehrkraft verwirft ihn ausdrücklich
(`kurspilot_dismiss_ausstand`), nachdem ihr Kurspilot eine Rekonstruktion angeboten hat, wo
eine möglich ist.

**4. Der Server meldet, nicht die KI.** `kurspilot_list_skills` ist durch den Handshake der
erste Aufruf jeder Sitzung. Er liefert die offenen Einträge im Feld `ausstaende` mit, gelesen
aus Private Files ohne Netzzugriff.

## Considered Options

- **Rückfall auf Private Files:** abgelehnt. Das erzeugt zwei halbe Kontextbereiche, gegen die
  #442 entschieden hat, und füllt genau die 100 MB, die der Umzug entlasten soll. Die Frage,
  welche Hälfte gilt, fiele beim nächsten Sitzungsbeginn der Lehrkraft zu.
- **Inhalt in der Notiz puffern:** abgelehnt. Das ist derselbe Rückfall unter anderem Namen. Die
  Notiz läge zudem immer in Private Files, auch bei Inhalten, die ein zugelassener Speicher
  tragen sollte (ADR 0021 §3).
- **Die KI legt die Notiz an:** abgelehnt. Die Notiz soll gerade das Ausbleiben von
  KI-Verhalten abfangen, zum Beispiel ein geschlossenes Chatfenster. Hinge sie selbst an
  KI-Verhalten, fiele sie in genau dem Fall aus, für den es sie gibt.
- **Automatisches Abhaken bei erfolgreichem Schreiben auf denselben Pfad:** abgelehnt. Ein
  späterer Journal-Eintrag ist nicht der verlorene.
- **Verfall nach einer Frist:** abgelehnt. Ein still verfallender Eintrag verschweigt den
  Verlust.

## Consequences

- Geht das Chatfenster zu, bevor nachgetragen wurde, ist der Inhalt verloren. Die Notiz sagt nur,
  *dass* etwas fehlt. Ein Umsetzungsbericht lässt sich aus dem Änderungsverlauf in Moodle
  zurückholen, eine Entscheidungsnotiz nicht.
- Arbeit in Moodle bleibt während des Ausfalls erlaubt. Es fehlt dann die Dokumentation darüber,
  und die Notiz macht diese Lücke sichtbar.
- Kann das Plugin die Notiz nicht schreiben, weil die Quote von Private Files voll ist, sagt die
  Fehlermeldung das ausdrücklich.
- Zur Lehrkraft heißt ein Ausstand „noch nicht gespeichert“. Der Bezeichner `ausstand` bleibt
  intern.
