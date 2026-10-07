# Spec 0027: Dokumentationsseite nach Zielgruppe mit Lehrkraft-Anleitung

Stand: 2026-10-02 · Quellen: `docs/research/feature-inventur.md`, `docs/research/ki-clients-mcp-anbindung.md` · verwandt: #606 (Lehrkraft-Onboarding), #604 (Skill-Korpus englisch), #192 (Marketplace)

## Problem Statement

Coursepilot hat inzwischen viele Besonderheiten (Plugin als MCP-Endpunkt, struktureller Datenschutz, Änderungsverlauf, erschlossene Aktivitätsarten, Kontext bei der Lehrkraft), aber keine Stelle, an der man sie zusammenhängend nachlesen kann. Es gibt nur READMEs und Markdown-Dateien.

- Lehrkräfte, die neu dazukommen (z. B. BBS Einbeck/Winsen auf moo), haben keine anschauliche Anleitung: Connector einrichten, WebDAV anlegen, erste Schritte, und keine Sätze, die sie einfach kopieren können.
- Admins finden Voraussetzungen und Stolpersteine (OAuth, Reverse-Proxy, Datenschutz) verstreut.
- Entwickler ähnlicher Werkzeuge, mit denen der Maintainer in Kontakt treten will, sehen weder Architektur noch Alleinstellungsmerkmale auf einen Blick.
- Das vorhandene Nutzersicht-Diagramm zeigt noch den alten Stand, in dem Coursepilot außerhalb von Moodle sitzt.

## Solution

Eine statische HTML-**Dokumentationsseite**, gegliedert nach Zielgruppe wie die MooGPT-README (Lehrkräfte / Admins / Entwickler / Alle), veröffentlicht über GitHub Pages:

- **Startseite:** Was ist Coursepilot, Besonderheiten, Wegweiser nach Zielgruppe, Nutzersicht-Diagramm.
- **Lehrkraft-Anleitung:** Einrichten (Connector je KI-Client, WebDAV mit Schwerpunkt IServ), erste Schritte (Kontext, Gestaltungsvorlage), **Einstiegsprompts** mit Kopier-Knopf, Umgang mit Werkzeuglücken, Mail-Vorlage für die Ansprache von Kolleg:innen. Mit Screenshots.
- **Admin-Seite:** Installation, Voraussetzungen, Einstellungen, Capabilities, OAuth, Reverse-Proxy/`trusted_proxies`, Datenschutz.
- **Entwickler-Seite:** Architektur-Diagramm, Werkzeugvertrag, ADR-Landkarte, Tests/CI, Branch-Modell, bekannte Grenzen.

Erst deutsch, dann englisch übersetzt; der Maintainer liest die Übersetzung gegen.

## User Stories

1. Als Lehrkraft möchte ich auf einer Seite sehen, was Coursepilot für mich tut, damit ich entscheiden kann, ob ich es ausprobiere.
2. Als Lehrkraft möchte ich Schritt für Schritt mit Screenshots den Connector in Claude einrichten, damit ich ohne Hilfe starten kann.
3. Als Lehrkraft mit ChatGPT-Abo möchte ich dieselbe Anleitung für ChatGPT finden, damit ich nicht zu Claude wechseln muss.
4. Als Lehrkraft mit Codex möchte ich wissen, wie ich Coursepilot in Codex einbinde, damit ich meinen bevorzugten Client nutzen kann.
5. Als Lehrkraft mit einem anderen MCP-fähigen Client möchte ich die allgemeinen Angaben (Endpunkt-URL, einmal autorisieren), damit ich es selbst übertragen kann.
6. Als Lehrkraft möchte ich vorab erfahren, dass Schreibrechte in ChatGPT je nach Tarif/Oberfläche schwanken können, damit ich bei Problemen weiß, woran es liegt.
7. Als Lehrkraft an einer IServ-Schule möchte ich mein WebDAV-Repository in Moodle anlegen können, damit mein Kontext bei mir bleibt.
8. Als Lehrkraft mit Nextcloud möchte ich die Abweichungen zu IServ kurz nachlesen, damit ich auch dort klarkomme.
9. Als Lehrkraft möchte ich wissen, wie ich Kurskontext und Gestaltungsvorlage einrichte, damit die KI meinen Stil trifft.
10. Als Lehrkraft möchte ich Einstiegsprompts mit einem Klick kopieren, damit ich einen Anwendungsfall ohne Formulierungsarbeit starte.
11. Als Lehrkraft möchte ich je Anwendungsfall (Abschnitt planen, Test/Fragen anlegen, Material einbinden, Bild zuschneiden, Aktivität klonen, Version wiederherstellen) einen Einstiegsprompt, damit ich die Breite des Werkzeugs entdecke.
12. Als Lehrkraft möchte ich wissen, was passiert, wenn eine Aktivitäts- oder Frageart nicht angelegt werden kann (Werkzeuglücke), damit ich die manuellen Moodle-Schritte kenne.
13. Als Lehrkraft möchte ich verstehen, welche Daten die KI sieht und welche nicht (keine Lernendendaten), damit ich es datenschutzrechtlich einordnen kann.
14. Als Lehrkraft möchte ich wissen, wie ich eine Änderung rückgängig mache, damit ich ohne Angst ausprobiere.
15. Als Multiplikator:in möchte ich eine Mail-Vorlage, damit ich Kolleg:innen einladen kann.
16. Als Lehrkraft möchte ich ein Diagramm, das zeigt, wie Lehrkraft, KI-Client, Moodle mit Coursepilot und Kontextspeicher zusammenspielen, damit ich das Prinzip verstehe.
17. Als englischsprachige Lehrkraft möchte ich dieselbe Anleitung auf Englisch, damit ich sie außerhalb Deutschlands nutzen kann.
18. Als Lesende:r möchte ich zwischen Deutsch und Englisch umschalten, damit ich in meiner Sprache bleibe.
19. Als Admin möchte ich die Voraussetzungen (Moodle 5.0+, PHP 8.2+, empfohlen 8.4) auf einen Blick, damit ich die Installation einplanen kann.
20. Als Admin möchte ich die Installationsschritte inkl. Webservices und Capabilities, damit die Lehrkräfte anschließend loslegen können.
21. Als Admin möchte ich die OAuth-Autorisierung und erlaubten Clients verstehen, damit ich weiß, wer sich verbinden kann.
22. Als Admin möchte ich den `trusted_proxies`-Stolperstein bei Reverse-Proxys kennen, damit WebDAV-Schreibzugriffe nicht gedrosselt werden.
23. Als Admin möchte ich Fernzugriff, Notbremse und Sammelwiderruf erklärt bekommen, damit ich im Ernstfall handeln kann.
24. Als Admin möchte ich die Datenschutzgrenze (Positivliste ohne Lernendendaten) belegt sehen, damit ich sie gegenüber Datenschutzbeauftragten vertreten kann.
25. Als Admin möchte ich wissen, welchen Reifegrad das Plugin hat (Beta), damit ich das Risiko einschätzen kann.
26. Als Entwickler ähnlicher Werkzeuge möchte ich die Alleinstellungsmerkmale in fünf Punkten, damit ich schnell sehe, worin sich Coursepilot unterscheidet.
27. Als Entwickler möchte ich ein Architektur-Diagramm des Server-MCP, damit ich die Struktur verstehe, ohne Code zu lesen.
28. Als Entwickler möchte ich den Werkzeugvertrag und die Werkzeugliste überblicken, damit ich Anknüpfungspunkte finde.
29. Als Entwickler möchte ich eine ADR-Landkarte mit Einzeilern, damit ich Entscheidungen nachvollziehe.
30. Als Entwickler möchte ich Tests, CI und Branch-Modell kennen, damit ich beitragen kann.
31. Als Entwickler möchte ich die bekannten Grenzen (z. B. keine Ratenbegrenzung am Endpunkt, Marketplace offen), damit ich realistisch einschätze, was fertig ist.
32. Als Maintainer möchte ich einen festen Link, den ich Entwickler-Kontakten und Kolleg:innen schicken kann.
33. Als Maintainer möchte ich, dass die Seite nur den veröffentlichten Stand zeigt (`main`), damit Lehrkräfte nichts Unveröffentlichtes suchen.
34. Als Maintainer möchte ich die Seite ohne Build-Werkzeuge pflegen, damit ich jede Datei direkt bearbeiten kann.

## Implementation Decisions

- **Format:** statisches HTML, eine gemeinsame Stylesheet-Datei, minimales JavaScript nur für Kopier-Knöpfe und Sprachumschaltung. Kein Static-Site-Generator, keine npm-Abhängigkeit (Repo-Regel: keine Laufzeit-Dependencies). Hell/Dunkel über `prefers-color-scheme`.
- **Ablage:** eigener Ordner unter `docs/` für die Seite, getrennt von ADRs/Specs, mit Unterordnern je Sprache (`de`, `en`) und gemeinsamen Assets (Stil, Skript, Bilder, Diagramme).
- **Veröffentlichung:** GitHub Pages per Actions-Workflow, nur aus `main`; nur Änderungen am Seitenordner lösen den Deploy aus, zusätzlich manuell startbar. Entwicklung auf `dev`; Merge nach `main` gebündelt, wenn die deutsche Fassung steht (Maintainer: in ein, zwei Tagen). Pages-Aktivierung in den Repo-Einstellungen ist ein menschlicher Schritt.
- **Gliederung:** wie MooGPT-README – Startseite mit Zielgruppen-Tabelle (Lehrkräfte / Admins / Entwickler / Alle), je Zielgruppe eine Seite. Repo-README verlinkt die Seite.
- **Begriffe:** **Einstiegsprompt** (nicht „Prompt-Vorlage“), **Lehrkraft-Anleitung**, **Dokumentationsseite** – siehe CONTEXT.md.
- **KI-Clients:** Claude, ChatGPT und Codex sind gleichwertig Pflicht; dazu ein allgemeiner Abschnitt „anderer MCP-Client“ (Endpunkt `/local/coursepilot/mcp.php`, einmal autorisieren). ChatGPT: ein Satz, dass Schreibrechte je nach Tarif und Oberfläche (Web/App) von OpenAI geändert werden können und Claude der verlässlichste Weg ist; Codex ist davon getrennt beschrieben. Klickschritte stammen aus `docs/research/ki-clients-mcp-anbindung.md` und werden live bestätigt.
- **Screenshots:** Moodle-Teile erzeugt der Agent per Browser auf der Spike-Instanz; Claude-Screenshots liegen beim Maintainer vor; ChatGPT- und Codex-Screenshots macht der Maintainer in der Web-Oberfläche. Bis dahin sichtbare Platzhalter mit Bildunterschrift.
- **Diagramme:** mit Archify (wie das vorhandene). Das Nutzersicht-Diagramm wird neu gezeichnet: Coursepilot sitzt **in** Moodle, der KI-Client verbindet sich über MCP/OAuth, der Kontext liegt bei der Lehrkraft (private Dateien oder eigenes WebDAV). Zusätzlich ein Architektur-Diagramm für Entwickler (Endpunkt, OAuth, Dispatcher, Werkzeugdeklarationen, Modulkatalog, Änderungsverlauf, Kontextbereich, Skill-Korpus).
- **Fakten:** Reifegrad Beta (`2.0.0-beta`); Moodle 5.0+, PHP 8.2+ (empfohlen 8.4, CI prüft 8.4); Moodle 5.1/PHP 8.4 folgt mit 2.1. Deutsch wird bis zur AMOS-Pflege mitgeliefert (#189). Bekannte Grenzen werden offen genannt.
- **Sprache:** Deutsch zuerst; englische Fassung als eigene Übersetzung, vom Maintainer gegengelesen. Echte Umlaute.
- **Inhalt aus #606:** Connector-Einrichtung, WebDAV mit IServ-Schwerpunkt, erste Routinen, Werkzeuglücken, Mail-Vorlage – #606 wird mit der deutschen Lehrkraft-Anleitung geschlossen.

## Testing Decisions

- Prüfnaht ist die **veröffentlichte Seite** von außen, nicht ihr Aufbau.
- Automatisch im Pages-Workflow (vor dem Deploy): alle internen Links und Bildpfade lösen auf; jede deutsche Seite hat ihr englisches Gegenstück (sobald die Übersetzung steht). Keine externe Abhängigkeit – kleines Node-Skript mit `node --test`, wie die vorhandenen Tests.
- Manuell je Ticket: Seite im Browser in Hell/Dunkel und Handybreite ansehen; Kopier-Knopf kopiert den Einstiegsprompt wortgleich.
- Live: Einrichtung je KI-Client einmal real gegen Spike nachvollzogen (Claude, ChatGPT Plus, Codex) inkl. einer schreibenden Aktion.

## Out of Scope

- Umstellung des Skill-Korpus auf Englisch (#604).
- Marketplace-Einreichung (#192) und Plugin-Directory-Texte.
- Ratenbegrenzung am Endpunkt (nur als bekannte Grenze dokumentiert).
- Videoanleitungen.
- Doku für den eingefrorenen lokalen Weg (`legacy/`), außer einem Satz Abgrenzung.

## Further Notes

- Grundlage für alle Inhalte: `docs/research/feature-inventur.md` (jede Aussage mit Quelle). Widersprüche daraus sind teilweise schon behoben (README: Beta, PHP-Untergrenze, `lang/de`).
- Das alte Nutzersicht-Diagramm in `docs/diagrams/` wird durch das neue ersetzt.
