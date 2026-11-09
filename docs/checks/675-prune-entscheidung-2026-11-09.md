# Prune-Entscheidung #675 (9.11.2026)

Matthias hat im separaten Sonnet-Medium-Chat Pfad A beschlossen. Die Entscheidung
wurde anschließend ausdrücklich in den Abschlussauftrag übernommen.

## Grenzen der Freigabe

- Keine Massenlöschung der 1122 A/B-Kandidaten oder der 84 A-Kandidaten.
- `killedBy` nennt nur den ersten fehlgeschlagenen Test. Eine fehlende Zuordnung
  beweist keine Redundanz; A kann ein Coverage-Artefakt eines Subprozess-Tests sein.
- Vertragstests sind gemäß `CODING_STANDARDS.md` T4 und Spec-Story 33 von
  Prune-Kandidaten ausgenommen. Die etwa 154 Namens-Treffer der ersten Sichtung
  sind keine einzeln geprüften Ausnahmen.
- Weitere Testentfernungen brauchen einen Einzelnachweis mit genauer Testliste,
  Schutzbeweis und einer neuen Freigabe von Matthias.

## Einzeln freigegebene Duplikate

Beide Methoden jedes Paars wurden vor der Änderung vollständig gelesen und
mechanisch verglichen. Die ausführbaren Körper sind identisch; im zweiten Paar
unterscheidet sich nur ein Kommentar über den Austausch des WebDAV-Testadapters.
Alle bisherigen Assertions bleiben jeweils einmal erhalten.

| Datei unter `tests/external/` | Zusammengelegte Methoden | Behaltener Test |
|---|---|---|
| `list_skills_test.php` | `test_hinweise_field_is_empty_without_webdav_freischaltung`, `test_hinweise_field_has_no_previouslocation_hint_by_default` | `test_notices_are_empty_without_webdav_authorization_or_pending_old_content` |
| `write_context_file_test.php` | `test_ausstand_retry_creates_missing_file_without_checkvalue`, `test_ausstand_parameter_dismisses_entry_on_successful_retry` | `test_pending_retry_creates_missing_file_without_checkvalue_and_dismisses_entry` |

Titel und Docblocks der behaltenen Tests beschreiben beide bisherigen Absichten.
Die Initialisierung, Aufrufe und Assertions ihrer Körper sind unverändert.
Historische Prune-Berichte behalten die damals gemessenen Methodennamen.

Lokale Vergleichsbelege: `/tmp/spec0029-675-proof/` mit den vier ursprünglichen
Methoden und `comparison.json`. SHA-256 der Körper nach Entfernen von
Zeilenkommentaren und Whitespace:

- `list_skills_test.php`: `b110e39d64fa52cb04e300bbe8b528f0f7aac43d6a2f5b5cc577e9eb788ddcdf`
- `write_context_file_test.php`: `03132642b3620049a06691e7177433fc6fb64effab8ce2c51f1a36d68167a21c`

## Folgearbeit

[#753](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/753) erfasst
die vollständige Kill-Matrix **nach #752** (frisches Full-Gate und angehobene
Baseline). Sie muss alle fehlschlagenden Tests je Mutant erfassen, die
Vertragstests begründet ausnehmen und weitere Vorschläge erneut zur genauen
Freigabe vorlegen. Diese Aufgabe ist keine weitere Löschfreigabe und bleibt
beim Spec-Abschluss begründet offen.

## Abnahme

Der vollständige Körpervergleich ist bestanden. `npm run gate -- fast` besteht
mit Exit 0: 211 Node-Verträge, die beiden betroffenen PHPUnit-Testklassen mit
76 Tests und 280 Assertions, keine statischen Befunde oder Ratschenverletzungen.
PHPUnit meldet dabei 12 Deprecations; der Lauf ist grün. Das unabhängige
Abschlussreview wird vor dem Schließen von #675 ergänzt.
