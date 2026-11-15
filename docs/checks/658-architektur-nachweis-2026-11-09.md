# Architektur-Nachweis für Spec 0029 (US 26 und 37)

Dieser Nachtrag gleicht vorhandene Primärbelege ab; er behauptet keine neue
Architekturprüfung oder erneute Bedienung des damaligen HTML-Berichts.

## US 26: Hotspots mit Matthias geprüft

[#658](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/658) ist
abgeschlossen. Seine Abnahme bestätigt den HTML-Bericht und die Durchsprache mit
Matthias. Der [Abschlusskommentar vom 09.10.](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/658#issuecomment-6074225295)
präzisiert: Kandidaten 1–8 übernommen, Kandidat 9 nur als kleine Entkopplung;
Folgetickets #691–#700 mit Abhängigkeit vom scharfen Gate (#666) und vor den
Coverage-Tickets #669–#671. Die Einschränkung von Kandidat 9 erfolgte aus Zeitgründen.

| Bereich der Prüfung | Folgetickets | Heutige Module und Dokumentation |
|---|---|---|
| OAuth und Client-Register | #691, #692 | `connection`, `client_registry`, `cimd_fetch_port`; `docs/agents/testing.md` „Verbindung testen“ |
| Kontextbereich, Ablageort-Anker und WebDAV-Port | #693, #694, #695 | `pending_write_notice`, `storage_anchor`, `storage_write`, Ablage-Ports; Testing-Abschnitte zu Kontextablage, Ortswahl und bedingtem Schreiben |
| Modulkatalog, MC-Frage, Quiz-Kombinationen, Abschluss und Wiederherstellen | #696–#700 | `catalog/module_settings`, Fragen-Fachmodul, `catalog/quiz_write`, `catalog/completion_settings`, `history/version_restore`; Testing-Abschnitte zu Quiz, Abschluss und Verlauf |

Der Dispatcher gehört bereits zum Prüfauftrag von #658; ein eigenes
Dispatcher-Deepening-Ticket wurde dort nicht beschlossen. Die Dokumentation
erfindet nachträglich keines. ADR 0030 enthält den fachlichen Nachtrag dieser
Prüfung: Die vermuteten Rückkanten waren teilweise Docblocks, die tatsächlichen
Werkzeug-zu-Werkzeug- und Adapter-Abhängigkeiten wurden genauer erfasst und unter
dem scharfen Gate abgebaut. Die leere deptrac-Baseline belegt den heutigen Zustand.

Grenze: Das ursprüngliche HTML-Artefakt liegt hier nicht als versionierte Datei
vor. Belegt sind die ausdrückliche Abnahme im Issue, der Abschlusskommentar, die
Folgetickets und die Implementierung. Die fehlende Kopie des HTML-Berichts ist
kein Beleg für eine fehlende Durchsprache.

## US 37: Architekturprüfung vor dem nächsten Feature

`CODING_STANDARDS.md` A5 enthält bereits die verbindliche Prozessregel: Nach
Hotspot, dritter Kopie oder einem Ticket an der seit dem letzten Release im
Gate-Fehlschlagsbericht erstplatzierten Datei kommt die Architekturprüfung vor
dem nächsten Feature an ihr; das Aufräumen erhält einen eigenen Commit oder PR.

`npm run gate -- ranking` liefert die lokale Rangliste; ADR 0029 beschreibt den
Ereignis-Trigger. Eine automatische Architekturentscheidung ist nicht Teil
dieser Story. Der [US-Abgleich vom 09.11.](673-us-review-2026-11-09.md) war eine
begrenzte Sichtung; seine Aussage, die Prozessregel stehe nirgends, trifft auf
den aktuellen A5-Text nicht zu.
