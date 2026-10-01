# AGPL-3.0-or-later für das gesamte Projekt, auch für das Moodle-Plugin

Bisher war die Lizenz geteilt: MCP, Installer und Entwicklungsmaterial unter
AGPL-3.0-or-later, das Moodle-Plugin unter GPL-3.0-or-later (Spec 0003). Die GPL-Hälfte war
keine Wahl, sondern eine vermutete Auflage des Plugins Directory. Mit dem Übergang zum
[Moodle Marketplace](https://marketplace.moodle.com/) fällt diese Auflage weg.

## Entscheidung

**Das ganze Projekt steht unter AGPL-3.0-or-later, einschließlich des Moodle-Plugins.**

Die [Submission Guidelines](https://moodle.atlassian.net/wiki/external/ODRhYjAyNTY4NDVmNGJlNjljN2ViMzkwYzdmYmIwMGI)
sagen: „Plugins made available through Moodle Marketplace may be licensed under the terms
specified by their provider." GPL v3 ist nur zwingend für ein Plugin, das „reproduces or
adapts a substantial part of the Moodle Platform source code" — Coursepilot nutzt
Core-APIs, übernimmt aber keinen Core-Code. Moodle stellt in den Provider Terms 4.3
ausdrücklich nicht fest, ob ein Plugin ein abgeleitetes Werk ist.

Die Begründung ist inhaltlich, nicht juristisch. Sie gehört in README und Marketplace-Seite:

> Coursepilot steht unter der AGPL-3.0-or-later, nicht unter der GPL. Das ist eine bewusste
> Entscheidung. Was für die Bildung gebaut wird, soll frei bleiben — auch dann, wenn jemand
> es nur als Dienst betreibt, statt es auszuliefern. Wer Coursepilot verändert und anderen
> zugänglich macht, gibt seine Änderungen an die Allgemeinheit zurück.

Englisch für das Listing:

> Coursepilot is licensed under AGPL-3.0-or-later rather than GPL. This is deliberate: tools
> built for education should stay free, including when they are offered as a hosted service
> rather than distributed. If you modify Coursepilot and make it available to others, your
> changes go back to the community.

## Considered Options

- **Geteilte Lizenz beibehalten** (Plugin GPL, Rest AGPL): abgelehnt. Die Teilung hatte nur
  den Grund, eine vermeintliche Directory-Auflage zu erfüllen; die gibt es nicht mehr. Zwei
  Lizenzen in einem Repository sind erklärungsbedürftig ohne Gegenwert.
- **Alles GPL-3.0-or-later:** abgelehnt. Die GPL greift nicht, wenn jemand eine veränderte
  Fassung nur als Dienst anbietet — genau der Fall, den diese Entscheidung abdecken soll.
- **Alles AGPL (diese Entscheidung):** der Unterschied trägt genau einen Fall, und den
  bewusst.

## Consequences

- Alle Dateiköpfe im Plugin wechseln von der GPL-Boilerplate auf AGPL; eine `LICENSE`-Datei
  kommt ins Plugin. Sie fehlte bisher ganz, und der Marketplace verlangt sie im Paket
  („The plugin package must also include a licence file").
- Der Wechsel ist möglich, weil das Plugin nie veröffentlicht wurde und die Urheberschaft
  bei einer Person liegt. Der MIT-Hinweis auf den Upstream `jtuttas/MoodleMcp` in `NOTICE`
  bleibt; MIT erlaubt die AGPL-Weitergabe.
- AGPL wird in den Marketplace-Regeln nirgends genannt — weder erlaubt noch ausgeschlossen;
  die Blocker-Liste enthält überhaupt keinen Lizenz-Blocker. Falls ein Reviewer nachfragt,
  ist der Haltungstext die Antwort: Absicht, nicht Versehen.
- Das Core-Boilerplate-Template von Moodle nennt fest GPL v3. Abweichende Köpfe können im
  Review auffallen; die geforderte Regel lautet aber nur „explicit licence statement".
