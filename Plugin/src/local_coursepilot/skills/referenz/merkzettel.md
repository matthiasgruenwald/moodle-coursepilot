---
name: merkzettel
description: Lies diese Datei, wenn eine Bestandsänderung gerade nicht ausführbar ist, wenn ein Client mit lokalen Dateiwerkzeugen eine Sitzung startet, oder wenn eine Werkbankdatei in Originalqualität in den Bestand soll.
---

# Referenz: Merkzettel

Der **Merkzettel** (`merkzettel.md`) hält Aenderungen am Materialbestand
fest, die die KI gerade nicht ausführen kann, und wird am Laptop
abgearbeitet. Er ist eine gewöhnliche Kontextdatei — gelesen und geschrieben
ausschliesslich über die Werkzeuge aus `coursepilot_get_skill("kontextbereich")`,
kein eigenes Feld im Handshake. Den Merkzettel gibt es nur, wenn der
Materialbestand extern liegt (WebDAV-Speicher der Lehrkraft).

## Aufschreiben (Spec #486 §14)

Findet die KI eine Bestandsänderung (umbenennen, verschieben, anlegen), die
sie in dieser Sitzung nicht ausführen kann, hält sie das sofort mit einem
Satz fest statt nur im Gespräch zu bleiben. Ein Punkt nennt:

- **was**: die Aenderung,
- **wo**: der Pfad relativ zur Bestandswurzel,
- **warum**: der Grund, warum es gerade nicht geht,
- **wann**: das Datum,

ohne Klarnamen — ein Merkzettelpunkt trägt kein
`coursepilot.personenbezug: true` (siehe "Keine Klarnamen in unmarkierten
Dateien", `coursepilot_get_skill("kontextbereich")`).

Hat der aktuelle Client lokale Dateiwerkzeuge (Codex am Laptop, ein Client
mit Dateisystem-Server)? Ja: `merkzettel.md` zu Sitzungsbeginn lesen, bevor
der dreistufige Test unten läuft. Nein: entfällt — der Merkzettel bleibt
serverseitig unverändert erreichbar.

## Dreistufiger Test vor jedem Bestandszugriff (Spec #486 §14, Issue #477)

Vor jeder Ausführung eines Merkzettelpunkts prüft die KI der Reihe nach,
mit je Ja oder Nein:

1. **Arbeitsverzeichnis und Werkzeug vorhanden?** Nein: nichts ausführen,
   "Starte mich in deinem Materialordner" (CLI) bzw. "öffne deinen
   Materialordner als Projekt" (Codex App) nennen. Ja: weiter zu 2.
2. **Nicht ausdrücklich schreibgeschützt?** Nein (schreibgeschützt):
   nichts ausführen, das nennen. Ja: weiter zu 3.
3. **Namensmengen an der Wurzelebene gleich nach Ignorierliste?** Die vom
   Server gemeldeten Namen (`coursepilot_list_material_files`, `ort: bestand`,
   Wurzel) gegen die lokalen Namen an der Wurzel des Arbeitsverzeichnisses
   vergleichen, nach Abzug der Ignorierliste. Nein (Abweichung): die
   Abweichung benennen, nichts ausführen. Ja: der Bestand gilt als
   gefunden, weiter mit dem Angebot unten.

**Ignorierliste** (gilt ausschliesslich hier, keine Kopie an anderer
Stelle): `.DS_Store`, `._*`, `.sync_*.db*`, `.owncloudsync.log`,
`*.nextcloud`, `*.owncloud`, `Thumbs.db`, `desktop.ini`.

## Angebot: Merkzettelpunkte abarbeiten (Spec #486 §14, Issue #483)

Nach bestandenem Test macht die KI **ein** Angebot für alle offenen Punkte,
nicht Punkt für Punkt nachfragen: Punkte nennen, eine Bestätigung
einholen (Teilauswahl möglich, z.B. "die ersten zwei"). Für jeden
bestätigten Punkt gilt genau einer von drei Fällen — nie "mach das
selbst":

- **ausführen** — Werkzeug vorhanden, Aenderung eindeutig.
- **als erledigt streichen** — die Lehrkraft hat es längst selbst erledigt,
  der Punkt entfällt ohne Ausführung.
- **Lösung vorschlagen** — die Aenderung ist nicht eindeutig genug für eine
  automatische Ausführung; die KI schlägt einen konkreten Schritt vor und
  wartet auf Bestätigung.

Wechselt der Materialbestand über die Ortswahl den Ort, werden offene
Merkzettelpunkte mitübertragen, nicht verworfen. Das ist ein eigener
Vorgang, kein Altbestand: den Altbestand (vorheriger Ort,
`coursepilot_get_skill("kontextbereich")`) kennt nur der Kontextbereich, der
Materialbestand hat keinen — sein alter Ort bleibt einfach liegen, ohne
eigenen Übernahmeschritt. **Der Merkzettel ist der Zustand**, was noch
aussteht — **das Journal ist die Geschichte**, was bereits entschieden oder
erledigt wurde. Ein abgearbeiteter Punkt wandert vom Merkzettel in einen
Journal-Eintrag, nicht umgekehrt.

## Werkbank → Bestand (Spec #486 §13/§14, Issue #484)

Ein Merkzettelpunkt kann eine Werkbankdatei (Chat-Anhänge, Zuschnitte,
siehe `coursepilot_get_skill("mcp-tools")`) in Originalqualität in den Bestand
holen — z.B. ein am Handy fotografiertes Tafelbild soll im Fachordner
landen.

**Vorbedingung: Habe ich ein Shell-Werkzeug?** Nein: nichts versuchen,
stattdessen sagen: "Das kann ich in diesem Programm nicht, in Codex am
Laptop erledige ich es." Ja: weiter.

Mit Shell-Werkzeug:

1. Vor der Codex-Rückfrage nach Shell-Freigabe ankündigen, was gleich
   passiert (z.B. "Ich hole jetzt N Dateien von der Werkbank in den
   Bestand").
2. `coursepilot_create_werkbank_download_links` für **alle** Werkbankpunkte
   dieser Sitzung **in einem Aufruf**, nicht Datei für Datei.
3. Je Datei Abruf und Prüfsumme:
   - POSIX: `curl -fsSL -o <ziel> <url>`, danach `shasum -a 1 <ziel>` (oder
     `sha1sum <ziel>`).
   - Windows: `Invoke-WebRequest -OutFile <ziel> <url>` (oder `curl.exe`),
     danach `Get-FileHash -Algorithm SHA1 <ziel>`.
4. Stimmt die lokale Prüfsumme mit dem gemeldeten `sha1` überein: die
   Werkbankdatei per `coursepilot_delete_material_files` löschen — der
   Bestand hat jetzt die Originalbytes.
5. Weicht sie ab: **ein zweiter Versuch** (erneuter Abruf, erneute
   Prüfsumme) — erst danach die Lehrkraft benachrichtigen, die
   Werkbankdatei dabei nicht löschen.

## Einrichtungstext: Verbindung gilt nutzerweit (Spec #486 §14)

Schritt 1 des dreistufigen Tests ("Arbeitsverzeichnis und Werkzeug
vorhanden?") scheitert, wenn die Coursepilot-Verbindung nur für ein
Projektverzeichnis eingerichtet ist — der Materialordner ist ein anderes
Arbeitsverzeichnis. Die Coursepilot-Verbindung gilt deshalb **nutzerweit**,
nicht projektbezogen: Claude Code `claude mcp add ... --scope user`; Codex
lädt MCP-Server ohnehin global aus `~/.codex/config.toml`, keine gesonderte
Einrichtung je Ordner.
