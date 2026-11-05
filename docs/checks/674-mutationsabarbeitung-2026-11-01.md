# #674: Abarbeitung der überlebenden Mutanten (Stand 2026-11-01)

> **ENTWURF / ZWISCHENSTAND.** #674 ist nicht abgeschlossen: offene Identitäten bleiben, die Neumessung
> mit `mutation-full.js` steht aus. Dieser Bericht wird bei jedem Meilenstein fortgeschrieben.

Messbasis: Volllauf #673 (Baseline `4161c4f`), danach dateiweise Fokusläufe mit
Infection 0.29.14 (PCOV) im Gate-Container (Moodle 5.1.7, PHP 8.4.27, MariaDB 11.4).
Produktionsquellen unverändert gegenüber `872f842`; Tests und Ignore-Liste bis `e309f24`.
Quelle der Zahlen: das Register `/var/lib/afk-pipeline/674/progress.json`, abgeglichen mit
`reconcile.js` (nur PHPUnit-Assertionsfehler zählen als Kill; Error-, Warning-, nicht
zuordenbare Kills und Timeouts bleiben offen).

## Zahlen (4704 eindeutige Identitäten)

| Zustand | 26.10. | 01.11. |
| --- | ---: | ---: |
| Kills (Assertion) | 3786 | 3940 |
| Ignores freigegeben | 86 | 580 |
| Ignores ohne abgeschlossenes Review | – | 9 |
| Timeout ohne Review | 1 | 1 |
| offen | 399 | 174 |
| ausstehend (nicht neu gemessen) | 477 | 0 |

Trendwert MSI (Kills plus freigegebene Ignores, Timeout/ungeprüfte nicht gezählt):
vorher (3786 + 86) / 4704 = 82,3 %, nachher (3940 + 580) / 4704 = 96,1 %. Ignores zählen nicht
als Kills; sie stehen getrennt in `scripts/gate/mutation-ignore.json`.

## Ignores

514 Einzelfall-Ignores im Bundle wurden von zwei frischen, unabhängigen Sonnet-5.5-Reviews
(Standards, Spec) geprüft. Entfernt wurden 83 Einträge mit REJECT (52 aus dem Altreview vom 26.10.,
11 Standards, 20 Spec); 40 Begründungen und Hash-Bindungen
wurden nach REVISE-Befunden korrigiert. Freigabe: `approved-ignores.json` (585 Einträge)
und `lineage-approvals-write_target.json` / `lineage-approvals-move_module.json`
(59 von 59 Zeilen MAP_OK). Jeder Eintrag ist an die Quell-Hashes der Zieldatei und der
Beweisdateien gebunden.

Grenzen der Reviews: der Standards-Reviewer prüfte die Cast-Ignores nur stichprobenartig,
der Spec-Reviewer die neun `delta`-Zeilen der Lineage nicht auf gleiche Anzahl und
Reihenfolge, und etwa die Hälfte seiner PASS-Notizen übernimmt den ersten Satz der
vorhandenen Begründung. 9 historische Ignores in `write_target.php` und `move_module.php`
waren nicht Teil der Bundles und warten auf ein Review.

## Verbleibende offene Identitäten (174)

Nicht als äquivalent gebucht (`needs-analysis`), Auswahl nach Größe:

- `activity_backup.php` 22 (Aufräum-/Vorprüfpfade ohne Beweis)
- `xml_activity_creator.php` 10, `activity_file_supplement.php` 8: Lightbox-Block, in der Umgebung
  ohne `mod_lightboxgallery` nicht messbar
- `instance_check.php` 9: lokaler HTTP-Testserver startet unter Infection nicht
  (`tests/classes/local_http_server.php:64`), Test besteht allein
- `workbench_ticket.php` 7, `get_question.php` 6 (nur Concurrency/Entropie), `add_glossary_entries.php` 6
  (Sperren im selben Prozess reentrant), `update_mc_question.php` 6 und der Rest in 30 Dateien mit
  je 1 bis 5 Identitäten
- `gd_support.php` 1, `crop_material_file.php` (WebP): Umgebung

Liste mit Gründen: `/var/lib/afk-pipeline/674/notes-needs-analysis.md`.

## Grenzen

- Neumessung mit `scripts/gate/mutation-full.js` für die betroffenen Abschnitte fand nicht statt;
  die Zahlen stammen aus Fokusläufen je Datei und dem Pre-push-Lauf (1517 Mutanten, 133 überlebt,
  0 Verstöße, keine geänderte Produktionsdatei im letzten Push).
- Der Messstand der Fokusläufe ist je Datei in `verified-focus/` festgehalten.

## Befund (nicht in #674 behoben)

`update_module_settings` auf `page` löscht eingebettete Dateien: im Entwurfsbereich geht die
Datei verloren (Dateien vorher 1, nachher 0). Ursache vermutlich
`pseudofield_carry_forward::prepare_editor_content_pseudofields` mit `file_prepare_draft_area`
bei belegter Draft-ID. Eigenes Issue nötig.
