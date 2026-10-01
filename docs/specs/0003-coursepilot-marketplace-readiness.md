# Spezifikation: Coursepilot – Marketplace-Readiness und Umbenennung

> **Überholt (19.09.2026).** Diese Spec beschreibt den Weg über das **Moodle Plugins
> Directory** und das dafür nötige Spiegelrepository. Das Directory ist inzwischen im
> [Moodle Marketplace](https://marketplace.moodle.com/) aufgegangen; eingereicht wird als
> ZIP mit Formular, die GPL-Auflage für Plugins besteht nicht mehr, und das Spiegelrepo
> entfällt mit dem Repo-Umbau. Gültig bleiben: öffentlicher Name **Coursepilot**,
> Komponente `local_coursepilot`, Moodle 5.0+, keine Lernendendaten, Privacy-API.
> Ersetzt durch [ADR 0024](../adr/0024-englische-basis-und-komponente-coursepilot.md),
> [ADR 0025](../adr/0025-agpl-fuer-das-gesamte-projekt.md) und
> [Plan 0004](../plans/0004-umbenennung-auf-local-coursepilot.md). Der Wortlaut bleibt als
> Dokumentation des damaligen Stands stehen.

> Zugehöriges Tracking-Issue: [#146](https://github.com/matthiasgruenwald/Kurspilot/issues/146).
> Diese Datei ist die kanonische Produktspezifikation. Das Issue verfolgt spätere Umsetzungsscheiben und externe Entscheidungen.

## Problem Statement

Kurspilot soll als dauerhaft kostenloses, international nutzbares Moodle-Plugin im Moodle Plugin Directory veröffentlicht werden. Der heutige Projekt- und Komponentenname `local_aicoursecreator` ist dafür weder konsistent noch marktplatzfähig. Das Produkt besteht zudem aus einem Moodle-Plugin und einem lokal laufenden MCP-Server; die Trennung muss für Installation, Quellveröffentlichung, Lizenzierung und Datenschutz verständlich bleiben.

## Solution

Das Produkt heißt öffentlich **Coursepilot**. Das Moodle-Plugin wird als Local-Plugin mit der Komponente `local_coursepilot` veröffentlicht und unterstützt Moodle 5.0 oder neuer. Es bleibt ein kostenloses Werkzeug, das nur zusammen mit dem lokal laufenden Coursepilot-MCP sinnvoll nutzbar ist.

`moodle-coursepilot` ist das primäre Entwicklungs-, Support- und Issue-Repository. Es enthält MCP, Installer, Skills, Tests und den Plugin-Quellbaum. Für das Moodle Plugin Directory entsteht daraus ein separates, schreibgeschütztes Quell-Repository `moodle-local_coursepilot`; dessen Root enthält ausschließlich das GPL-lizenzierte Moodle-Plugin. README und Marketplace-Eintrag verlinken wechselseitig beide Bestandteile und das primäre Repository.

Die bisherige Komponente wird nicht migriert. Installationen mit `local_aicoursecreator` müssen das alte Plugin deinstallieren und `local_coursepilot` neu installieren; diese notwendige Neuinstallation wird in README, Release Notes und Konfigurator deutlich erklärt.

## Scope und Entscheidungen

- Öffentlicher Produktname: **Coursepilot**; sichtbare Namen im Plugin, Konfigurator, Installer, Skills, Dokumentation und Release-Artefakten werden einheitlich umbenannt.
- Moodle-Komponente, PHP-Namespace, Webservice-Namen, Capabilities, Sprachdatei und Build-Artefakt verwenden konsistent `local_coursepilot` beziehungsweise `coursepilot`.
- Unterstützte Moodle-Version: **5.0+**. Moodle 4.x wird weder behauptet noch getestet.
- Das Plugin bleibt ein `local`-Plugin mit systemweit eingerichteten, durch Moodle-Capabilities geschützten Webservices; es wird kein Aktivitätsmodul.
- Alle Moodle-Strings liegen in `lang/en/local_coursepilot.php`. Deutsch wird in der Übergangsphase zusätzlich mitgeliefert und im Repository/Marketplace als vorläufige Übersetzung bis zur AMOS-Pflege erklärt. Nach bestätigter AMOS-Übernahme wird die ausgelieferte deutsche Sprachdatei in einem frühen Release entfernt.
- Der MCP, Installer und das primäre Repository stehen unter **AGPL-3.0-or-later**. Das Moodle-Plugin einschließlich Marketplace-ZIP steht unter **GPL-3.0-or-later**. Der MIT-Hinweis für den Upstream `jtuttas/MoodleMcp` bleibt erhalten.
- Der Build exportiert das Plugin als eigenständiges ZIP und als ausschließliches Wurzelverzeichnis des Marketplace-Mirrors. MCP, Installer, Skills und Tests dürfen dort nicht erscheinen.

## Datenschutz und Datenzugriff

Coursepilot darf über MCP und Plugin keine von Lernenden erzeugten oder personenbezogenen Lerninhalte abrufen: insbesondere keine Aufgabenabgaben, Forenbeiträge, Quizversuche, Bewertungen oder Teilnehmendenlisten. Diese Grenze wird als positive Allowlist der zulässigen MCP-Tools und Moodle-Webservices umgesetzt und getestet; ein später hinzugefügter Dienst ist nur nach ausdrücklicher Prüfung zulässig.

Lehrkraft-erstellte Kursinhalte dürfen weiterhin im lokalen Coursepilot-Client verarbeitet werden. Das Moodle-Plugin ruft selbst keinen KI-Anbieter auf. Konfigurator, README und Marketplace-Beschreibung erklären verständlich, dass die Lehrkraft bei Nutzung eines lokal konfigurierten KI-Clients Inhalte an dessen Anbieter weitergeben kann. Die Privacy-API wird vollständig implementiert und `privacy:metadata` wird gegen die tatsächlich verarbeiteten Daten geprüft.

## User Stories

1. Als Moodle-Administrator möchte ich `local_coursepilot` auf Moodle 5.0+ installieren, damit der Komponentenname und die Installationsanleitung marktplatzkonform sind.
2. Als Lehrkraft möchte ich in allen sichtbaren Oberflächen Coursepilot sehen, damit Produktname, Konfigurator und Plugin zusammenpassen.
3. Als Administrator einer bisherigen Installation möchte ich klar erkennen, dass eine Neuinstallation erforderlich ist, damit ich nicht auf eine nicht unterstützte Migration vertraue.
4. Als Lehrkraft möchte ich die Oberfläche in meiner Moodle-Sprache sehen, damit Coursepilot international nutzbar ist; während der Übergangsphase steht Deutsch zusätzlich bereit.
5. Als Datenschutzverantwortliche möchte ich sicher sein, dass Coursepilot keine Lernendeninhalte oder Leistungsdaten an MCP-Tools freigibt, damit das Werkzeug zunächst nur für die Kursgestaltung eingesetzt wird.
6. Als Marketplace-Nutzer möchte ich den Plugin-Quellcode ohne MCP- oder Installer-Ballast einsehen und trotzdem den notwendigen lokalen Coursepilot-Teil finden.

## Testing Decisions

- **Marketplace-Readiness-Vertrag:** Die bestehenden Webservice-/MCP-Vertragstests werden zu einer klaren Prüfung erweitert, dass Komponente, Services und Tool-Registry zusammenpassen, Moodle 5.0 als Mindestversion nennen und nur die explizit erlaubte, lehrkraftbezogene Datenoberfläche freigeben. Prior Art: `test/webservice-trainer-scope-contract.test.js`, `test/mcp-tool-profiles.test.js` und `test/plugin-moduleinfo-contract.test.js`.
- **Release-Artefakt:** Ein Build-Test prüft das Plugin-ZIP und den Mirror-Export auf Plugin-Root, Komponentennamen, Lizenzdateien und den Ausschluss von MCP, Installer, Skills und Tests. Prior Art: `scripts/build-plugin.js` sowie die bestehenden Installer-Build-Tests.
- **Konfigurator-Hinweis:** Der reine Render-Test sichert den sichtbaren Privacy-Hinweis und den Hinweis zur Neuinstallation bei einer alten Komponente. Prior Art: `test/setup-render.test.js`.
- **Dokumentationsverweise:** Ein schlanker Test oder Check sichert, dass README, Release Notes und Mirror-README auf primäres Repository, Installation ohne Migration, Moodle 5.0+ und Datenschutzhinweis verweisen. Prosa wird nicht wortwörtlich getestet.

## Out of Scope

- Migration von Daten, Einstellungen oder Webservices aus `local_aicoursecreator`.
- Unterstützung oder Tests für Moodle 4.x.
- Abruf, Export oder Verarbeitung von Lernendenabgaben, Forenbeiträgen, Quizversuchen, Bewertungen oder Teilnehmendenlisten.
- KI-Anbieter-Aufrufe aus dem Moodle-Plugin selbst.
- Kostenpflichtige Varianten, Marketplace-Verkauf oder bezahlte Zusatzfunktionen.

## Further Notes

- Die Umbenennung ist ein eigenständiges, später in vertikale Scheiben zu zerlegendes Vorhaben. Dieses Dokument ist kein Epic; ein Epic entsteht erst, wenn diese Scheiben als konkrete Issues geplant werden.
- Das Marketplace-Repository ist ein schreibgeschützter Veröffentlichungs-Mirror. Issues und Pull Requests bleiben ausschließlich im primären Repository `moodle-coursepilot`.
