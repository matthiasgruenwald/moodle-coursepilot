# Isolierung und Personenbezug am externen Ablageort

Fortschreibung von ADR 0011 für Kontextbereich und Materialbestand in einem WebDAV-Speicher der
Lehrkraft (Karte #467, entschieden in [#471](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/471)).
Spec 0016 §1.4 und Spec #442 §4 hatten verlangt, Isolierung und Datenschutz für diesen Fall neu
zu begründen, statt die Begründung aus Moodles Dateibereichen fortzuschreiben.

## Entscheidung

**1. Isolierung zwischen Lehrkräften kommt aus dem Instanzeigentum, nicht aus einem Pfadpräfix.**
Kurspilot nimmt die Repository-Nutzerinstanz aus dem Kontextpointer. Den Pointer kann die
Lehrkraft über „Meine Dateien“ bearbeiten, und `repository::get_option()` gibt Zugangsdaten
ohne Rechteprüfung heraus (#468). Bei jeder Auflösung gilt deshalb: Die `contextid` der Instanz
muss der Nutzerkontext des Token-Inhabers sein, sonst bricht die Auflösung mit einem benannten
Fehler ab. Die Instanz kommt nie aus einer Client-Eingabe. Fremde Zugangsdaten würden die ganze
fremde Wolke öffnen. Deshalb ist das die tragende Linie.

**2. Das Pfadpräfix trennt nur innerhalb des eigenen Kontos, und es ist schwächer.** Es hält die
KI-Werkzeuge in Kontextbereich und Materialbestand. Der Pfad wird serverseitig aus Pointer und
relativem Segment gebaut und nach dem Auflösen der Prozentkodierung segmentweise verglichen.
`.`, `..` und leere Segmente werden abgelehnt. Ein Fehler hier reicht nicht zu anderen
Lehrkräften, wohl aber in die eigene Privatwolke, einschließlich eingehängter Freigaben
(IServ `Groups/`, Nextcloud-Shares). Dieser Rest wird benannt und nicht verdeckt.

**3. Am nicht zugelassenen Speicher gilt eine Schreibsperre, keine Lesesperre.** Die Schule
nennt in `local_kurspilot | personaldatahosts` die **zugelassenen Speicher**. Ein Eintrag gilt
für die Domain samt Unterdomains, und eine leere Liste bedeutet: nur Private Files. Kurspilot
schreibt eine Datei mit `kurspilot.personenbezug: true` nur dorthin. Geprüft wird die ganze
entstehende Datei, also auch beim Anhängen und Kopieren. Andere WebDAV-Speicher bleiben als Ort
erlaubt.

## Considered Options

- **Nur zugelassene Server als Ort erlauben:** abgelehnt. WebDAV schließt die großen
  Fremdanbieter ohnehin aus, und Unterrichtsplanung braucht Schülerdaten nur ausnahmsweise.
  Ein Verbot hätte die meisten Lehrkräfte ohne Grund an Private Files gebunden.
- **Schreib- und Lesesperre:** abgelehnt. Was schon in einem Speicher liegt, hat die Lehrkraft
  dort abgelegt. Das Werkzeug verantwortet nur, was es selbst hinlegt. Ob eine gelesene
  markierte Datei an die KI geht, entscheidet weiter allein `allowpersonaldata` (ADR 0011 §2).
  Eine zweite Sperre dafür hätte Kurspilot zum Sheriff über fremde Ablageentscheidungen gemacht.

## Consequences

- Die Sperre wirkt, wie in ADR 0011, auf die **Markierung**. Namen in unmarkierten Sachdateien
  und im Materialbestand hält sie nicht auf. Die Informationstexte sagen das ausdrücklich.
- Wie die KI an einem nicht zugelassenen Speicher ohne markierte Dateien plant, ist Sache der
  Skilltexte, nicht dieses ADR.
