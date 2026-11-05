# #674 – CI-Fix des Quellenkopftests, 21.10.2026

Ausgangscommit ist `cbf67fa4712562e1130fecfbf172fed21d22aea5`, PR #717 gegen
`dev`. CI-Lauf `39605179464` widerlegt die bisherige Testannahme, dass eine
führende Leerzeile die gerenderte Quellenangabe unverändert lässt. Das ist ein
Fehler des neuen Tests, kein nachgewiesener Produktionsfehler. Produktion,
Baselines, Schwellen, Ausschlüsse und Hooks bleiben unverändert.

## Ursache und unveränderter Fehlernachweis

Alle fünf zusätzlichen PHPUnit-Matrixjobs scheitern ausschließlich an den fünf
Glyphenfällen dieses Tests: Moodle 5.1/5.2/5.3 auf PostgreSQL/PHP 8.4 sowie
Moodle 5.2/5.3 auf MariaDB/PHP 8.3. Bei I/F verändert sich die Breite von 31 auf
32 beziehungsweise 39 auf 40; bei _/j/g unterscheiden sich 56/68/257 Pixel.
Ein Datenbank- oder Moodle-Versionsfehler erklärt diese identische Verteilung
nicht. Der Quality-Job war bei der Diagnose noch nicht abgeschlossen; dessen
späteres Ergebnis wird vom Parent beobachtet, nicht durch einen Worker-Poller.

Quellen liegen unter `/var/lib/afk-pipeline/674/ci-fix-cbf67fa/`:
`matrix-failures.json` enthält Jobnamen, Originalpfade, Loghashes und sämtliche
Failure-Sektionen. Parent-Originale liegen unter `674/parent-ci-cbf67fa/`.
Der echte Testmerge `0507a39029c21d031a7be5d75942fc46dc1882f5` hat exakt
`f909fc4681a74bc437299c7db69bc64bdc69ccdb` und `cbf67fa` als Eltern.
Produktionsdatei, mitgelieferter Font und alter Test entsprechen dessen
Git-Blobs; `testmerge.json` und `testmerge-tree.json` sichern diese Identität.

Lokal verwendet das Gate PHP 8.4.25, gebündeltes GD („2.1.0 compatible“) und
FreeType 2.12.1; das originale PostgreSQL-5.3-Log nennt PHP 8.4.26. Die CI-Logs
enthalten keine vollständige GD-/FreeType-Version, die deshalb nicht als dort
gemessen behauptet wird. Der Font hat SHA256
`4830701fbb9bef25af87dfb50ccaf4fb5ce65a0c26ccf9523e6d379799167d3d`.

Im freien Gate wurde aus dem vorhandenen PHP-8.4.25-Quellarchiv eine zusätzliche
GD-Erweiterung gegen natives libgd 2.3.3 gebaut. Sie liegt ausschließlich im
Reports-Verzeichnis. Ein eigener INI-Scanpfad übernimmt alle normalen
Konfigurationen außer der GD-Erweiterung; die normale PHP-Konfiguration bleibt
unverändert. Der Build installierte zusätzliche Bibliotheken und aktualisierte
libexpat/liblzma/libpng/xz; anschließend wurden beide GD-Varianten mit demselben
PHP, Font, Plugin und denselben dann vorhandenen Bibliotheken verglichen.
`build.log/.exit` und `gd-linkage.txt` dokumentieren diese Grenze.

`bundled-probe.json` und `external-probe.json` reproduzieren allein durch den
GD-Wechsel den Breitenunterschied bei identischem Font/Plugin/PHP. Der alte Test
scheitert unter externem GD mit dem regulären Moodle-PHPUnit-Bootstrap exakt
fünfmal, einschließlich derselben Pixelzahlen (`red.log`, Exit 1).
Damit ist die GD-Abhängigkeit nachgewiesen; ein PHP-Patch- oder Core-Wechsel
ist für die Reproduktion nicht nötig.

## Korrigierter öffentlicher Vertrag

Der veröffentlichte Parameter beschreibt Quellenreferenztext in fester
Gestaltung, nicht die Gleichheit von Text und Text mit führender Leerzeile.
Alle fünf Glyphenfälle bleiben erhalten und prüfen jeweils beide Texteingaben
unabhängig: ohne und mit führender Leerzeile (zehn Datensätze). Der korrigierte Test prüft weiterhin
exakte Abmessungen, sichtbare blaue Schrift und vollständige Pixelgleichheit
von Quellenkopf und Originalbild – ohne Toleranz, Skip oder alternative
Fehlererwartung.

Die Referenz zeichnet denselben Text mit nativem `imagettftext()` auf eine
separate Fläche an einem anderen Ursprung. Die Metriken stammen aus einem
zusätzlichen nativen Zeichenaufruf am Nullpunkt. Das ist nötig, weil externes
GD negative Fontkoordinaten bei der Rückgabe anders rundet als absolute
Koordinaten an einem positiven Ursprung; bei j ist beispielsweise
`[0,5,7,5,7,-19,0,-19]` nicht die bloße ganzzahlige Rückverschiebung von
`[80,85,87,85,87,60,80,60]`. Die tatsächliche native Probe mit PHP-/GD-/
Fonthash ist in `ci-fix-cbf67fa/font-origin-bounds.json` archiviert. Das am
Nullpunkt zurückgegebene Rechteck bestimmt
die Position der vollständigen Referenzfläche. Diese erhält
die vertraglichen 12 Pixel Seitenabstand und die zentrierte 48-Pixel-Kopfleiste;
Glyphenüberhänge außerhalb des gemeldeten Rechtecks bleiben erhalten. Das Originalbild
folgt unverändert darunter. Der Test liest weder private Produktionskonstanten
noch deren `imagettfbbox()`-Ergebnis. Er kopiert keine Produktionsfunktion und
stellt keinen verwalteten Katalog oder die Datenbank nach. GD/FreeType werden
in beiden Varianten real ausgeführt. Die führende Leerzeile bleibt als Eingabe
erhalten, ihre typografischen Metriken kommen aber aus der tatsächlichen
Laufzeit statt aus einer unbelegten Äquivalenz zu anderem Text.

Die erste Korrekturiteration bestand beide GD-Varianten mit 18 Tests/64
Assertions, prüfte jedoch nur die fünf Leerzeilentexte. Der Fokus belegte,
dass damit die ursprünglichen normalen Glyphenfälle fehlen. Die endgültige
Fassung ergänzt daher fünf normale Glyphenfälle. Dabei deckte externes GD
einen eigenen Referenzfehler auf: Das Ausschneiden des Font-Rechtecks verlor
beim normalen j einen Unterschied (33 Pixel). Die zusätzliche Messung am
Nullpunkt zeigte als weitere Ursache die Rundung negativer Koordinaten;
die vollständige Referenzfläche und ursprungsgerechten Metriken beheben das
ohne Toleranz. Dieser Entwicklungsfehler und der
kontrolliert abgebrochene Fokus bleiben unter `iteration2/` erhalten; vor
Quelländerungen wurde dessen Ende samt Orphan-Freiheit bestätigt. Eine nach
rsync fehlende erzeugte phpunit.xml wurde über den normalen Moodle-
Komponentenkonfigurationsaufbau wiederhergestellt, nicht durch Umgehung.
Die finale externe GD-Klassenprüfung ist grün: 23 Tests, 84 Assertions,
Exit 0 (`final-green-external.log/.exit`). Die gebündelte GD-Klasse und das
Fast-Gate werden auf derselben endgültigen Quelle unter `final-fast.log/.exit`
festgehalten; die spätere Fokus-Auswertung nennt exakte Mutationszahlen.
Das ersetzt nicht die neue vollständige CI-Matrix.

## Zurücknahme und erneute Einzelprüfung

Der alte, unveränderliche Fokusreport bleibt erhalten. Sein Hash und die
konkret widerlegte Testmethode stehen in
`674/revoked-assertion-evidence.json`; `reconcile.js` validiert den Reporthash
und zählt aus diesem Report nur andere tatsächliche Assertion-Fehlschläge.
Die bisher damit angerechneten 25 Original-Kills werden bis zu neuer Evidenz
nicht übernommen. Auch die 25 Freigaben sind aus `approved-ignores.json`
ausgesetzt; die vorherigen Register und Freigaben bleiben unter
`ci-fix-cbf67fa/before-*` erhalten.

`revalidate.js`, Exit 0, prüft erneut alle 25 vollständigen Original-/Mutant-
Quellpaare samt JSON-Paarhash, Dateiname, Zeile, Mutator, Originalrohreport und
aktuellen `sourceHashes`; Ergebnis `revalidated-equivalences.json`.

Die 17 Materialfälle betreffen **streng leeren Text**, nicht Text mit führender
Leerzeile: 16 einzelne Koordinatenänderungen der Nullbox und eine Entfernung
einer Null. Für leeren Text ist die Kopfhöhe 0; die Breite verwendet 0 statt
der berechneten Textbreite, und der Zeichenblock wird übersprungen. Nach der
Entfernung sind die einzig gelesenen Indizes 0/2/4/6 weiterhin vorhanden.
Keine dieser Aussagen verwendet Fontmetriken oder die widerlegte
Leerzeilen-Gleichheit. Jede einzelne Begründung nennt diesen Unterschied nun
explizit. Die acht Katalogfälle verändern jeweils nur einen unbenutzten
Defaultparameter: `grade_origin()` liefert bei resource/page/folder/url
unbedingt `GRADE_NONE`. Diese Einzelbeweise sind von GD unabhängig; identische
Reflection-Metadaten werden ausdrücklich nicht behauptet.

Die endgültigen gebündelten und externen GD-Fokusläufe sind mit Threads 1 und
ausschließlich abdeckenden Tests getrennt in beiden Gate-Containern beendet,
jeweils Exit 0. Der externe INI-Pfad ist auch im tatsächlichen Mutanten-
PHPUnit-Kindprozess belegt (`final-external-child-context.txt`). Beide Container
sind danach ohne Testorphans. Die Klassenprüfung besteht in beiden Varianten
mit 23 Tests/84 Assertions; das finale Fast-Gate meldet 0 Verstöße.

| Native GD-Variante | Gesamt | Infection-Kills | Echte Assertion-Kills | Error-only | Warnung/unzugeordnet | Escaped | Uncovered |
|---|---:|---:|---:|---:|---:|---:|---:|
| Gebündelt | 215 | 174 | 136 | 36 | 2 | 39 | 2 |
| Extern 2.3.3 | 215 | 167 | 133 | 32 | 2 | 46 | 2 |

`final-attribution.json` bindet die beiden unveränderlichen Reports, ihre
vollständigen Rohreports und jeweiligen SHA256, Quellarchiv, Baumdigest sowie
den finalen Testhash. Jede der **25 zurückgenommenen Originalidentitäten** ist
gegen das vollständige #673-Quellpaar nachgeprüft und wird im gebündelten GD
wieder durch eine konkrete fachliche Assertion getötet; extern gilt das für
18 davon. Die unterschiedlichen GD-Metriken aktivieren unterschiedliche
Mutanteneffekte, deshalb wird kein Kill auf beiden Varianten für alle behauptet.
Die maßgeblichen Rohbelege enthalten tatsächliche PHPUnit-Failure-Sektionen,
keine Anrechnung aus Errors, Warnungen oder bloßen `killedBy`-Namen.

Zusätzlich wird ein zuvor noch offener Originalmutant an Zeile 159 getötet:
`RoundingFamily`, Hash
`c5e83ef4c5b14aa9a7467e9790c3e2d7ac2ec6f7d237d3df8d3b02df2906f99e`.
Auch dieser ist einzeln gegen Original-/Mutant-Quellpaar und endgültige Failure
nachgewiesen, nicht aus der Differenz der Kill-Anzahlen abgeleitet. Alle 17
Leertext-Boxmutanten bleiben in beiden echten GD-Läufen überlebt und sind mit
den erneut geprüften Einzelbeweisen verknüpft. Die acht unveränderten
Katalogdateien werden nicht ohne Grund erneut gemessen; deren bestehende
quellgebundene Rohbelege sind erneut gegen aktuelle Quellen geprüft.

Das Register führt vor der neuen Reviewfreigabe **4.704 eindeutige Originale =
2.166 Assertion-Kills + 61 genehmigte Ignores + 25 erneut ausstehende Freigaben +
2.452 sonstige offene Identitäten**. Vorkommen: 4.748 = 2.206 + 61 + 25 + 2.456.
Die 44 OAuth-Duplikate und deren Zustände bleiben erhalten. Erst nach beiden
frischen PASS dürfen die 25 wieder als genehmigt geführt werden. Dies ist kein
Abschnitts-/Gesamt-MSI und kein neuer Gesamtabschluss.

## Ausstehende Abnahme dieses Arbeitsstands

Die beiden frischen R14-Reviews und die erneuten Einzel-Freigaben sind am
24.10. erfolgt (siehe unten). Die R13-Codex-Reviews scheiterten am Providerlimit;
R14 verwendet nach ausdrücklicher Nutzerfreigabe Claude Sonnet 5.5. Kein
Providerfehler zählt als PASS. Bei Erstellung dieses Commit-Berichts folgen
noch regulärer Commit, Pre-Push, Push und neue genaue CI-Abnahme. Deren
anschließende Originalnachweise liegen unter `674/ci-fix-cbf67fa/`.

Kein Gesamtabschluss von #674. Andere vorbereitete Chargen bleiben unangewendet.
Die vollständige Abschnitts-/Gesamt-MSI-Nachmessung einschließlich der bekannten
root/lib.php-Grenze und die Gesamtabnahme bleiben offen. Keine Issue-Schließung,
kein Merge, Release oder Deployment.


## Historischer Reviewblocker – R13 fehlgeschlagen

Beide am 21.10. gestarteten frischen Codex-Aufträge sind inzwischen terminal
`failed`, jeweils ohne ausstehende Kinder oder Rückfragen. Der Provider meldet
sein Nutzungslimit und nennt als nächsten Versuch „Oct 27th, 2026 7:32 AM“
(keine Zeitzone angegeben). Vollständige Resultate und Taskidentitäten:
`674/ci-fix-cbf67fa/reviews-r13-failed.json`.

- Standards: `674-ci-gd-standards-r13-20261021-v1`, kein Review-PASS.
- Spec: `674-ci-gd-spec-r13-20261021-v1`, kein Review-PASS.

Eine am 24.10. verspätet zugestellte alte Ignore-Spec-R1-Rückmeldung beschreibt
lediglich ihren überholten Privacy-/Access-Diff. Sie enthält ausdrücklich keine
inhaltliche Abnahme und ersetzt keines dieser neuen Reviews.

Am R13-Haltepunkt war der Status **stopped wegen fehlender vorgeschriebener
Reviewkapazität**. Der Fix und alle echten Messungen blieben erhalten; die 25
erneuten Ignorefreigaben waren ausgesetzt. Es gab zu diesem Zeitpunkt keinen
neuen Commit, Pre-Push, Push oder erfolgreiche neue CI-Abnahme. Der damalige
HEAD war `cbf67fa` mit dessen bekannt roter Matrix. Dieser historische Halt ist
durch die folgende neue Runde aufgehoben; kein blinder CI-Rerun wurde gestartet.

## R14 – zwei unabhängige PASS und erneute Freigaben, 24.10.2026

Genau zwei frische read-only Reviewer prüften den vollständigen aktuellen
CI-Fix einschließlich aller 25 Einzeläquivalenzen und der vollständigen
Assertion-/Quellpaarbeweise. Beide R14-Reviews sind **SCOPED PASS**, ohne eigene
Tests, Änderungen oder Delegation. Modell: Claude Sonnet 5.5, Effort high;
fachliche Äquivalenz-/Assertion-Prüfung gemäß Nutzerwahl. Grund des
Providerwechsels: ausdrückliche Nutzerfreigabe nach dem Codex-Nutzungslimit.
Vollständige Ergebnisse samt Taskidentitäten: `ci-fix-cbf67fa/reviews-r14.json`.

Die Standards-Achse bestätigt A2/T1–T3/T5 und führt nur nicht blockierende
Lesbarkeitsheuristiken auf. Die Spec-Achse bestätigt ausdrücklich, dass das
native Font-Oracle keine Abschwächung ist. Beide haben sämtliche 17 Leertext-
Boxfälle und acht Katalog-Defaults erneut einzeln anhand der echten Rohpaare
und aktuellen Quellbindungen geprüft. Alle 25 wiederhergestellten Original-
Assertion-Kills und der zusätzliche Rundungskill sind nachgewiesen.

Die zwei verlangten Berichtskorrekturen sind eingearbeitet: Providerchronik
und archivierte Ausgabe der Font-Ursprungsmetrik. Der Test bleibt mit exakt
dem gemessenen Hash unverändert. Die empfohlenen neueren Killbelege werden
nun bevorzugt: `reconcile.js` ersetzt einen gültigen Assertion-Kill nur durch
einen späteren echten Assertion-Kill. Die bisherige Auswahl der übrigen
Messbindungen bleibt erhalten; Warnungen und Errors werden nicht aufgewertet.
Alle 26 Material-Killbelege dieser Korrektur zeigen jetzt auf den Endreport
`classes_material_composition_php-2026-10-21T13-48-52-687Z.json` und den geprüften
Enddigest `bd979f64…530b8`, nicht mehr auf die Zwischeniteration.
`evidence-preference-r14.json` und `verify-revocation-r14.log/.exit` halten die
mechanische Prüfung fest. Die erste reine Umkehr der Reportreihenfolge wurde
korrigiert, weil sie eine ältere unvollständige Ignore-Messbindung auswählte;
kein fehlender Nachweis wurde freigegeben oder umgangen.

Erst nach beiden PASS wurden die 25 aktuellen Einträge wieder in den
Freigabe-Ledger übernommen (`renewed-approvals-r14.json`) und das Register
fortgeschrieben: **4.704 eindeutige Originale = 2.166 Assertion-Kills +
86 genehmigte Ignores + 2.452 offen**, keine ausstehende Review-/Messfreigabe.
Vorkommen: **4.748 = 2.206 + 86 + 2.456**. Die 44 OAuth-Duplikate bleiben
vollständig erhalten. Diese Freigabe ersetzt weder Vollhook noch neue CI.

Es folgt genau ein regulärer Push nach normalem Commit und Pre-Push-Fullgate.
Die neue genaue CI-Abnahme bleibt beim Parent. Weitere #674-Chargen bleiben
bis zu grüner CI unangewendet; keine Issue-Schließung, kein Merge/Release/Deployment.
