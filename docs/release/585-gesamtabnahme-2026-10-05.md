# #585 Gesamtabnahme 2.1-Integration (05.10.2026)

Stand: Integrationszweig `integration/release-2.1-issues`, Commit dieses Berichts darüber. Technische Abnahme, **keine** Client-Abnahme.

## Umfang

Review ab `41f6003` (Standards und Spec, read-only) gegen Spec 0026 und die Garantien aus Spec 0028; Integration von #593, #598/#599, #603 (#626 bleibt `requires-user`).

## Behoben im Review

| Befund | Behebung |
|---|---|
| Datei-Nachtrag wurde erst nach dem Restore geprüft (Spec 0028 F11: abgelehnte Eingaben mutieren nichts) | `activity_file_supplement::validate()` läuft vor `activity_backup::restore`; Test rot (`course_module_deleted` nach Ablehnung), dann grün |
| Doppeltes `optional_param('confirmed')` in `history.php` | entfernt |
| `location_selection.min.js` und `.map` trugen deutsche Kommentare (ADR 0024) | per Moodle-Grunt aus der englischen Quelle neu gebaut (nur diese zwei Dateien ändern sich) |
| Specs 0026/0028 nannten alte Pfade und widersprachen dem Code | Pfade und Out-of-Scope nachgezogen |

## Testnachweis (finaler Stand, eigene isolierte Umgebung)

- Moodle 5.1.7+ (Build 20260928), PHP 8.4.25, MariaDB 11.4.12, reale Addons `mod_checklist` und `mod_lightboxgallery`.
- Volllauf: 1539 Tests, 130898 Assertions, Exit 0, 1 bestehender Skip (WebDAV-Quota); keine Lightboxgallery-Skips.
- Coverage (natives Component-Config, pcov): 82,73 % (11991/14495 Zeilen), alle 187 Produktionsdateien des Scopes enthalten; Gate 80 % unverändert.
- `npm test` 35/35, `docs-site-check.js` grün, Release-ZIP gebaut (`local_coursepilot-2.0.0-beta.zip`).

## Echter Claude-MCP-Lauf (Spike, 05.10.2026)

Kandidat 2026100402 per `deploy-plugin-spike.sh` (nach DB-Snapshot) auf den Spike gebracht, Lauf über den claude.ai-Connector im Testkurs 27, Abschnitt 4:

- `export_default_activity` für book, checklist, glossary erfolgreich.
- `create_activity_from_xml` book (cmid 1847, Kapitel), checklist (1848, zwei Punkte), glossary (1849) erfolgreich, Round-Trip bestanden.
- Ablösen: `dry_run` (hidden_predecessors 1, nichts geschrieben), dann schreibend: 1850 sichtbar hinter 1848, 1848 versteckt.
- **Nicht prüfbar:** Der Connector kennt noch den alten Werkzeugstand. `add_glossary_entries` (#593) und der `files`-Parameter (#598/#599) sind in seiner Werkzeugliste nicht enthalten. Beides ist nur durch die native Suite belegt, nicht durch echte Clientbedienung.

## Offen (nicht als erledigt markiert)

- Claude-Lauf für Export/Anlegen/Ablösen erledigt (siehe oben). Offen: Glossar-Einträge und Lightboxgallery-Dateien über Claude (Connector-Werkzeugliste aktualisieren), Codex- und ChatGPT-Lauf samt Screenshots, Darstellung im Spike durch eine Lehrkraft.
- Verifizierte Ablage `activity-types/lightboxgallery.md` (#599) hängt an der Spike-Abnahme.
- Release-String ist `2.0.0-beta`; die vereinbarte Nummer 2.1.0 ist nicht gesetzt (Entscheidung Release-Arbeit; Maturity bleibt Beta).
- Supportmatrix/Mindestversion und Upgrade vom veröffentlichten `main` bleiben separate Releasearbeit.
- Konkreter Live-Deployplan nur mit ausdrücklicher Freigabe.
- Review-Restpunkte (Beobachtung, kein Blocker): `supplement_missing` fängt jede `\Throwable` (Ausstandsnotiz bei fehlgeschlagenem Template-PUT ungetestet); `preview_supersede` ignoriert `files` (kein Test); `glossary_entry_writer`-Docblock „no existing-entry reads“ ungenau (`glossary_concept_exists` ist Moodle-Core-Duplikatprüfung); `gallery_images` in der Verlaufs-Freigabeliste ungeprüft; Dateien über 800 Zeilen (`oauth_lib.php`, `catalog/assign.php`, `import_questions_xml.php`) als eigenes Refactoring.
