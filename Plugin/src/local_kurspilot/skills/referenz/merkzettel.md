---
name: merkzettel
description: Lies diese Datei, wenn eine Bestandsaenderung gerade nicht ausfuehrbar ist, wenn ein Client mit lokalen Dateiwerkzeugen eine Sitzung startet, oder wenn eine Werkbankdatei in Originalqualitaet in den Bestand soll.
---

# Referenz: Merkzettel

Der **Merkzettel** (`merkzettel.md`) haelt Aenderungen am Materialbestand
fest, die die KI gerade nicht ausfuehren kann, und wird am Laptop
abgearbeitet. Er ist eine gewoehnliche Kontextdatei — gelesen und geschrieben
ausschliesslich ueber die Werkzeuge aus `kurspilot_get_skill("kontextbereich")`,
kein eigenes Feld im Handshake. Den Merkzettel gibt es nur, wenn der
Materialbestand extern liegt (WebDAV-Speicher der Lehrkraft).

## Aufschreiben (Spec #486 §14)

Findet die KI eine Bestandsaenderung (umbenennen, verschieben, anlegen), die
sie in dieser Sitzung nicht ausfuehren kann, haelt sie das sofort mit einem
Satz fest statt nur im Gespraech zu bleiben. Ein Punkt nennt:

- **was**: die Aenderung,
- **wo**: der Pfad relativ zur Bestandswurzel,
- **warum**: der Grund, warum es gerade nicht geht,
- **wann**: das Datum,

ohne Klarnamen — ein Merkzettelpunkt traegt kein
`kurspilot.personenbezug: true` (siehe "Keine Klarnamen in unmarkierten
Dateien", `kurspilot_get_skill("kontextbereich")`).

Hat der aktuelle Client lokale Dateiwerkzeuge (Codex am Laptop, ein Client
mit Dateisystem-Server)? Ja: `merkzettel.md` zu Sitzungsbeginn lesen, bevor
der dreistufige Test unten laeuft. Nein: entfaellt — der Merkzettel bleibt
serverseitig unveraendert erreichbar.

## Dreistufiger Test vor jedem Bestandszugriff (Spec #486 §14, Issue #477)

Vor jeder Ausfuehrung eines Merkzettelpunkts prueft die KI der Reihe nach,
mit je Ja oder Nein:

1. **Arbeitsverzeichnis und Werkzeug vorhanden?** Nein: nichts ausfuehren,
   "Starte mich in deinem Materialordner" (CLI) bzw. "öffne deinen
   Materialordner als Projekt" (Codex App) nennen. Ja: weiter zu 2.
2. **Nicht ausdruecklich schreibgeschuetzt?** Nein (schreibgeschuetzt):
   nichts ausfuehren, das nennen. Ja: weiter zu 3.
3. **Namensmengen an der Wurzelebene gleich nach Ignorierliste?** Die vom
   Server gemeldeten Namen (`kurspilot_list_material_files`, `ort: bestand`,
   Wurzel) gegen die lokalen Namen an der Wurzel des Arbeitsverzeichnisses
   vergleichen, nach Abzug der Ignorierliste. Nein (Abweichung): die
   Abweichung benennen, nichts ausfuehren. Ja: der Bestand gilt als
   gefunden, weiter mit dem Angebot unten.

**Ignorierliste** (gilt ausschliesslich hier, keine Kopie an anderer
Stelle): `.DS_Store`, `._*`, `.sync_*.db*`, `.owncloudsync.log`,
`*.nextcloud`, `*.owncloud`, `Thumbs.db`, `desktop.ini`.

## Angebot: Merkzettelpunkte abarbeiten (Spec #486 §14, Issue #483)

Nach bestandenem Test macht die KI **ein** Angebot fuer alle offenen Punkte,
nicht Punkt fuer Punkt nachfragen: Punkte nennen, eine Bestaetigung
einholen (Teilauswahl moeglich, z.B. "die ersten zwei"). Fuer jeden
bestaetigten Punkt gilt genau einer von drei Faellen — nie "mach das
selbst":

- **ausfuehren** — Werkzeug vorhanden, Aenderung eindeutig.
- **als erledigt streichen** — die Lehrkraft hat es laengst selbst erledigt,
  der Punkt entfaellt ohne Ausfuehrung.
- **Loesung vorschlagen** — die Aenderung ist nicht eindeutig genug fuer eine
  automatische Ausfuehrung; die KI schlaegt einen konkreten Schritt vor und
  wartet auf Bestaetigung.

Beim Wechsel des Bestands (Altbestand, `kurspilot_get_skill("kontextbereich")`)
werden offene Merkzettelpunkte mituebertragen, nicht verworfen. **Der
Merkzettel ist der Zustand**, was noch aussteht — **das Journal ist die
Geschichte**, was bereits entschieden oder erledigt wurde. Ein abgearbeiteter
Punkt wandert vom Merkzettel in einen Journal-Eintrag, nicht umgekehrt.

## Werkbank → Bestand (Spec #486 §13/§14, Issue #484)

Ein Merkzettelpunkt kann eine Werkbankdatei (Chat-Anhaenge, Zuschnitte,
siehe `kurspilot_get_skill("mcp-tools")`) in Originalqualitaet in den Bestand
holen — z.B. ein am Handy fotografiertes Tafelbild soll im Fachordner
landen.

**Vorbedingung: Habe ich ein Shell-Werkzeug?** Nein: nichts versuchen,
stattdessen sagen: "Das kann ich in diesem Programm nicht, in Codex am
Laptop erledige ich es." Ja: weiter.

Mit Shell-Werkzeug:

1. Vor der Codex-Rueckfrage nach Shell-Freigabe ankuendigen, was gleich
   passiert (z.B. "Ich hole jetzt N Dateien von der Werkbank in den
   Bestand").
2. `kurspilot_create_werkbank_download_links` fuer **alle** Werkbankpunkte
   dieser Sitzung **in einem Aufruf**, nicht Datei fuer Datei.
3. Je Datei Abruf und Pruefsumme:
   - POSIX: `curl -fsSL -o <ziel> <url>`, danach `shasum -a 1 <ziel>` (oder
     `sha1sum <ziel>`).
   - Windows: `Invoke-WebRequest -OutFile <ziel> <url>` (oder `curl.exe`),
     danach `Get-FileHash -Algorithm SHA1 <ziel>`.
4. Stimmt die lokale Pruefsumme mit dem gemeldeten `sha1` ueberein: die
   Werkbankdatei per `kurspilot_delete_material_files` loeschen — der
   Bestand hat jetzt die Originalbytes.
5. Weicht sie ab: **ein zweiter Versuch** (erneuter Abruf, erneute
   Pruefsumme) — erst danach die Lehrkraft benachrichtigen, die
   Werkbankdatei dabei nicht loeschen.

## Einrichtungstext: Verbindung gilt nutzerweit (Spec #486 §14)

Schritt 1 des dreistufigen Tests ("Arbeitsverzeichnis und Werkzeug
vorhanden?") scheitert, wenn die Kurspilot-Verbindung nur fuer ein
Projektverzeichnis eingerichtet ist — der Materialordner ist ein anderes
Arbeitsverzeichnis. Die Kurspilot-Verbindung gilt deshalb **nutzerweit**,
nicht projektbezogen: Claude Code `claude mcp add ... --scope user`; Codex
laedt MCP-Server ohnehin global aus `~/.codex/config.toml`, keine gesonderte
Einrichtung je Ordner.
