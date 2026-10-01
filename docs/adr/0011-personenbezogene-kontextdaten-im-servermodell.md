# Personenbezogene Kontextdaten im Servermodell

ADR 0003 erlaubt echte Schuelernamen in Lerngruppenprofilen und begruendet das
damit, dass diese Dateien auf dem Rechner der verantwortlichen Lehrkraft
bleiben. Im Servermodell (`local_kurspilot`, Karte #289) faellt diese
Begruendung weg: Die Kontextdateien liegen im privaten Moodle-Dateibereich der
Lehrkraft auf dem Schulserver. Damit ist die **Schule** Verantwortliche im
Sinne der DSGVO, nicht mehr die einzelne Lehrkraft. Die Entscheidung aus
ADR 0003 muss deshalb neu begruendet werden - nicht, weil sie falsch geworden
waere, sondern weil ihre alte Begruendung nicht mehr traegt.

Der Datenfluss zum KI-Anbieter aendert sich dabei **nicht**. Auch heute liest
der lokale MCP-Server Kontextdateien und gibt sie als Tool-Ergebnis in den
Modellkontext; jedes Tool-Ergebnis geht an den Anbieter. Neu ist allein der
Speicherort - und die Tatsache, dass die Schule nun eine Steuerungsmoeglichkeit
braucht, die sie beim Laptop nie hatte.

## Optionen

- **ADR 0003 unveraendert weiterfuehren**: guenstig, aber unehrlich. Die
  tragende Begruendung ("bleibt lokal beim Verantwortlichen") existiert im
  Servermodell nicht mehr; ein Leser wuerde die Entscheidung auf eine falsche
  Grundlage stuetzen.
- **Klarnamen im Servermodell verbieten** (nur Kuerzel oder Pseudonyme):
  scheinbar der sichere Weg, tatsaechlich der schlechtere. Der Massstab aus
  Spezifikation 0010 ist **Rueckfuehrbarkeit** - "S. M., 7a" ist innerhalb der
  Lerngruppe voll rueckfuehrbar. Ein Verfahren, das Pseudonymisierung
  behauptet, ohne sie zu leisten, ist gegenueber einer Pruefung schlechter als
  offen benannte Klarnamen hinter einer abschaltbaren Grenze. Ausserdem waere
  es nicht durchsetzbar: niemand hindert eine Lehrkraft daran, einen Namen in
  eine unmarkierte Sachdatei zu schreiben.
- **Entscheidung beibehalten, Begruendung ersetzen, Steuerung ergaenzen**: Der
  paedagogische Grund aus ADR 0003 gilt unveraendert - Lehrkraefte planen fuer
  echte Schuelerinnen und Schueler. Was fehlt, ist nicht eine Verschaerfung der
  Ablageregel, sondern ein Schalter, mit dem die Schule die Uebertragung
  markierter Daten an die KI definitiv unterbinden kann.

## Entscheidung

**ADR 0003 bleibt in der Sache gueltig und wird durch diesen ADR
fortgeschrieben.** Personenbezogene Kontextdaten duerfen im privaten
Moodle-Dateibereich liegen. Dazu gelten vier Festlegungen:

**1. Verantwortlichkeit.** Verantwortliche ist die Schule als Betreiberin der
Moodle-Instanz. Das ist keine Verschlechterung gegenueber ADR 0003: Planung mit
Klarnamen auf schulischer Infrastruktur ist der Normalfall, der Laptop war der
schwaechere Ort. Die Konsequenz ist keine neue Rechtfertigungspflicht, sondern
ein Anspruch der Schule auf Steuerbarkeit und Transparenz.

**2. Abschaltbarkeit.** Das Plugin erhaelt die Einstellung
`local_kurspilot | allowpersonaldata`, **Standard: aus**. Ist sie aus, liefert
kein Lese-Tool eine Kontextdatei mit `kurspilot.personenbezug: true` aus. Eine
frische Installation uebertraegt also nie markierte personenbezogene Daten an
einen KI-Anbieter; die Schule muss das bewusst einschalten, und diese
Entscheidung ist die Dokumentationsspur, die eine Datenschutzpruefung sehen
will.

Der Schalter wirkt auf der **Markierung**, nicht auf dem Inhalt: Er hindert
niemanden daran, einen Namen in eine unmarkierte Sachdatei zu schreiben. Das
ist bewusst so benannt und wird nicht als weitergehender Schutz dargestellt.
Sein eigentlicher Wert liegt beim **mitgebrachten Bestand** - importierte
Lerngruppenpakete und Migrations-Uploads bringen `personenbezug: true`-Dateien
mit, deren Entstehung die Instanz nicht kontrolliert hat.

**3. Verhalten bei ausgeschaltetem Schalter.** Gesperrte Dateien erscheinen im
Listen-Tool mit dem Vermerk "gesperrt"; das Lese-Tool scheitert mit einer
klaren Meldung. Sie werden **nicht** verborgen, weil die Sachdatei laut
Spezifikation 0010 sichtbar auf ihr Sidecar verweist - eine unsichtbare Sperre
liesse die KI in einen toten Verweis laufen und raten. Automatische
Anonymisierung oder Schwaerzung findet **nicht** statt: Das waere ein
Sicherheitsversprechen, das kein Textfilter halten kann.

**4. Kein zusaetzlicher Lesefilter im eingeschalteten Zustand.** Ist
`allowpersonaldata` an, liest die KI Sidecars mit `personenbezug: true` -
gewollt, denn genau dafuer existieren sie (Foerderbedarf in der Planung). Der
technische Schutz liegt weiterhin beim **Export**: Materialpakete zur offenen
Weitergabe schliessen `personenbezug: true` und `nicht_weitergeben`
ausnahmslos aus; Lerngruppenpakete enthalten berechtigte Sidecars und sind in
Dateiname und Manifest sichtbar als `INTERN` / `weitergabe: schulintern`
gekennzeichnet (Spezifikation 0010, Abschnitt "Weitergabe").

## Konsequenzen

- ADR 0003 erhaelt einen Verweis auf diesen ADR, bleibt aber im Wortlaut
  erhalten, damit die Entwicklung der Begruendung nachvollziehbar bleibt.
- Die Spezifikation fuer `local_kurspilot` (Issue #301) nimmt
  `allowpersonaldata`, das Sperrverhalten und die Informationstexte auf.
- `local_kurspilot\privacy\provider` ist ein **voller** Provider
  (`metadata\provider` + `request\plugin\provider` +
  `core_userlist_provider`) und deklariert OAuth-Clients, Access- und
  Refresh-Tokens, die Kontextdateien im Filearea `kurspilot_context` (ueber
  `export_area_files`) sowie die Plugin-Events. Der `null_provider` von
  `local_coursepilot` traegt hier nicht: Tokens sind einer `userid` zugeordnet,
  und fuer die eigene Token-Tabelle gibt es keinen Core-Loeschmechanismus.
- Informationspflicht an drei Orten: im **OAuth-Consent-Screen** (der Moment
  der Freigabe, den niemand ueberspringt), auf
  **`/local/kurspilot/myconnections.php`** (dauerhaft nachlesbar) und in der
  **Plugin-Beschreibung** (fuer die Schule vor der Installation). Der
  Consent-Screen zeigt den aktuellen Stand von `allowpersonaldata` an.
- Die Texte verweisen auf die Vorgaben von Schule und Landesdatenschutz statt
  auf persoenliches Ermessen. Wo es fuer die Planung reicht, wird die
  Verwendung von Kuerzeln empfohlen - als Empfehlung, nicht als Regel und ohne
  Anonymisierungsversprechen.
- Nicht Gegenstand dieses ADR: **indirekter** Personenbezug in
  Kursinhalten - insbesondere `availability`-JSON vom Typ `profile`, das
  Klarnamen im Klartext enthalten kann. Das betrifft die Tool-Oberflaeche auf
  Feldebene und wird gesondert entschieden.
- Fortgeschrieben durch ADR 0021 für den externen Ablageort (WebDAV): Isolierung aus dem
  Instanzeigentum, Schreibsperre für markierte Dateien am nicht zugelassenen Speicher.
  Der Schalter `allowpersonaldata` gilt dort unverändert.
