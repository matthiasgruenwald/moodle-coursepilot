# Infection-Pilot #672 (13.10.2026)

Quellstand: Plugin-Code `d4f43a880e7d7205b0c98f65801f76aa4964f60e`, laufende Implementierung von #672. Source-SHA256 je Pilotdatei: `/tmp/coursepilot-672-pilot-original/source-hashes.json`.

Umgebung: bestehender isolierter Gate-Container, Moodle 5.1.7+ (20260928), PHP 8.4.25, MariaDB 11.4.12, Infection 0.29.14, PHPUnit 11.5.55, PCOV. Threads 1, nur abdeckende Testfälle pro Mutant. Testdatenbank niemals parallel getestet.

Bereich `classes/check` (5 Dateien), echter Befehl `node scripts/gate/gate.js mutation-pilot classes/check`. Lauf 7: CLI Exit 0, Infection Exit 0. Der Pilot ist eine Trendmessung, kein bestandener Produktions-Diff-Gate-Lauf.

| Messwert | Ergebnis |
|---|---:|
| Gesamte gemessene Wandzeit inklusive Initialtests/Erzeugung/Bootstrap | 51,980 s |
| Erzeugte und real getestete Mutanten | 26 |
| Getötet / überlebt | 15 / 11 |
| Nicht gedeckt / Fehler / Syntaxfehler / Timeout / übersprungen | jeweils 0 |
| MSI (Trend, keine Gesamtschwelle) | 57,69 % |
| Wandzeit pro Mutant inklusive Fixkosten | 1,99923 s |
| Summe von 26 gemessenen PHPUnit-Mutant-Zeiten | 14,224 s |
| Mittlere PHPUnit-Mutant-Zeit, ohne Prozessstart/Initialtests | 0,54708 s |

Einzelzeiten aus Original-`processOutput` (PHPUnit `Time`) sind vollständig in `/tmp/coursepilot-672-pilot-original/measured-mutants.json` enthalten. Die Differenz von 37,756 s umfasst Setup/Initialtests/Erzeugung/Prozessstart und ist kein separat gemessener Initialtest-Timer.

Die korrigierte vollständige **reine Erzeugungszählung** mit demselben Default-Profil und denselben begründeten Source-Ausschlüssen liefert **11.916 mögliche Mutanten** (27 s, Exit 0). Befehl: Infection `--dry-run --coverage=/var/www/reports/infection-census-coverage --skip-initial-tests`, Threads 1, ohne Datei-/Diff-Filter. Ausschließlich die separate Zählkonfiguration setzt `timeout: 0`: Dadurch registriert Infection auch jeden gedeckten Kandidaten als nicht ausgeführt. Ohne diesen Schritt verwirft `DryProcessRunner` die ausführbaren Prozesse ohne Ergebnisereignis; die frühere Zahl 8.086 war deshalb unvollständig und ist zurückgezogen. Die Produktions-Gate-Konfiguration verwendet diese Nullgrenze nicht.

Die Coverage stammt aus der tatsächlichen grünen 1.680-Test-Initialsuite des PR-Difflaufs; sie ist für die Erzeugungszählung ausreichend, aber keine vollständige Coverage-Messung aller Produktionsdateien. **Kein Mutant wurde in diesem Zähllauf getestet.** 7.290 ungedeckte und 4.626 übersprungene Kandidaten ergeben den vollständigen Nenner; ihre Statuswerte sind keine Mutationsabnahme. Originale: `/tmp/coursepilot-672-census-complete-2.{log,exit,seconds}` und `/tmp/coursepilot-672-census-complete-original/infection-census-complete.{json,log}`, Konfiguration und Quellhashes im selben Verzeichnis.

Hochrechnung aus dem Verzeichnis-Piloten: 11.916 × 1,99923 s = **23.822,83 s beziehungsweise 6,62 Stunden seriell**; ideal auf vier vollständig isolierten Instanzen **1,65 Stunden**, jeweils Threads 1. Diese Rechnung wiederholt die Pilot-Fixkosten proportional und ist daher konservativ bezüglich Setup, nicht bezüglich teurer anderer Fachbereiche. Der Pilot ist klein und nicht repräsentativ für alle Module; dies ist ausdrücklich kein gemessener Volllauf und keine Zusage eines Laufzeitlimits.

Der zusätzliche echte PR-Difflauf bestätigt die starke Streuung: **1.177 erzeugte Kandidaten**, davon 556 getötet, 455 überlebt, 45 ungedeckt und 121 wegen der damaligen 60-s-Grenze nicht ausgeführt. Wandzeit **9.894,311 s** einschließlich der grünen Initialsuite (**1.680 Tests, 131.553 Assertions, 15 reguläre Skips, 1.030,596515 s**); Gate-Exit **1**, keine Abnahme. Bezogen auf die 1.011 tatsächlich ausgeführten Mutanten benötigt die Mutationsphase im Mittel **8,76727 s pro Mutant**. Mit dem vollständigen Nenner plus einmaliger Initialsuite ergibt das alternativ **105.501,44 s beziehungsweise 29,31 Stunden seriell**, ideal **7,33 Stunden auf vier isolierten Instanzen**. Diese zweite Schätzung ist für Import-/Verlaufs-/Dateischreibbereiche aussagekräftiger als der kleine Check-Pilot. Originale: `/tmp/coursepilot-672-diff-2-original/`, `/tmp/coursepilot-672-diff-2.{log,exit}` und `/tmp/coursepilot-672-diff-2-coverage/`. Die übersprungenen Kandidaten und Überlebenden müssen bearbeitet und anschließend tatsächlich geprüft werden.

Originalnachweise: `/tmp/coursepilot-672-pilot-7.log`, `.exit`, `/tmp/coursepilot-672-pilot-original/infection-pilot.{json,log,exit}`, `infection-pilot-config.json`, `infection-pilot-timer.json`, Quellhashes und Einzelzeiten. Fehlversuche 1–6 bleiben mit Rohlogs/Exits erhalten und zählen nicht als erfolgreicher Pilot. Fehlerursachen: CLI-Optionssyntax, Moodle-Autoload und der vom Infection-Parent gehaltene Moodle-Testlock; letzterer ist mit dem vorgesehenen Utility-Bootstrap behoben.

Fortschreibung am 14.10.2026: Die Mutantenanalyse deckte einen echten Fehler beim
Überschreiben langer zulässiger Dateinamen auf. Der temporäre Dateiname in
`private_files_storage_port::replace()` ist jetzt unabhängig von der Länge des
Zielnamens. Die erneute vollständige reine Erzeugungszählung auf diesem Produktionsstand
liefert **11.909 Kandidaten**, davon 7.291 nach der verwendeten historischen Coverage
ungedeckt und 4.618 in der separaten Zählkonfiguration nicht ausgeführt; Infection
meldet 26 Sekunden (27 Sekunden gemessene Prozesswandzeit), Prozess-Exit 0.
**Auch dieser Zähllauf führte keine Mutantentests aus.**
Die historischen Coverage-Statuswerte beurteilen weder die neu ergänzten Tests noch
die Qualität des aktuellen Stands. Originale, Konfiguration, Prozesszeit/Exit und
Quellhashes: `/tmp/coursepilot-672-census-current-original/` sowie
`/tmp/coursepilot-672-census-current.{log,exit,seconds}`.

Mit dem aktualisierten Nenner ergeben sich aus dem unveränderten Check-Piloten
**23.808,84 s / 6,61 h seriell**, ideal **1,65 h auf vier isolierten Instanzen**.
Die zweite Schätzung mit dem tatsächlich gemessenen PR-Mutationsphasenmittel und
einmaliger Initialsuite beträgt **105.440,07 s / 29,29 h seriell**, ideal **7,32 h
auf vier isolierten Instanzen**. Die zuvor genannten Werte bleiben als Messung und
Hochrechnung des ursprünglichen d4f43a8-Stands nachvollziehbar. Keine dieser
Hochrechnungen ersetzt einen ausgeführten vollständigen Mutationslauf.

Der erneute reguläre `gate full` vom 14.10.2026 (`full-resumed-1`) ist vollständig
beendet: **1.750 Tests, 131.839 Assertions, 15 reguläre Skips**, 1.072,710 s;
Coverage **90,50 %**, Ratsche und sämtliche statischen Werkzeuge grün. Anschließend
nutzte Infection genau diese frische Coverage mit `--skip-initial-tests` und
`--git-diff-lines`: **1.170 Mutanten, 922 getötet, 219 überlebt, 29 ungedeckt**,
keine Fehler, Syntaxfehler, Timeouts oder übersprungenen Kandidaten. Native
Infection-Wandzeit **9.719,560 s**, Prozess-Exit 0; das Gate blieb mit **186
Mutationsbefunden rot**, Gesamt-Exit 1 nach **10.878 s**. Keine Abnahmebehauptung.
Originale einschließlich Konfiguration, Coverage, Quellenhashes, Timer, Exits und
Artefaktmanifest: `/tmp/coursepilot-672-full-resumed-1-original/`.

Ohne die bereits separat gemessene Initialsuite beträgt das aktuelle
PR-Mutationsphasenmittel **8,51846 s je tatsächlich ausgeführtem Mutanten**
(1.141 = 922 + 219). Auf alle 11.909 erzeugbaren Kandidaten hochgerechnet,
zuzüglich einmaliger Initialsuite, sind das **28,48 Stunden seriell**, ideal
**7,12 Stunden auf vier isolierten Instanzen**. Die ungedeckten Kandidaten des
historischen Zähllaufs werden dabei vorsorglich wie ausführbare Kandidaten
behandelt; das ist eine Hochrechnung für den gesamten Quellbestand und kein
gemessener vollständiger Mutationslauf. Der aktuelle PR-Diff wurde dagegen
tatsächlich ohne übersprungene Mutanten ausgeführt.
