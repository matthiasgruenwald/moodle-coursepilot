# Coding Standards – Quellenprüfung und Abgleich (#656)

Stand: 06.10.2026, Branch `docs/656-coding-standards-draft` (Entwurf `CODING_STANDARDS.md`, PR #678); Teil E und die Regelnummern: 09.10.2026 (Abstimmung #657).
Zweck: Buchzitate des Entwurfs gegen Primärquellen prüfen (Teil A), die allgemeinen Regeln mit veröffentlichten Standards abgleichen (Teil B), verfügbare Buch-Volltexte prüfen (Teil C), mit den Moodle-Vorgaben abgleichen (Teil D) und die Quellen des KI-Teils prüfen (Teil E).
Belege: URL der Primärquelle (Verlags- bzw. Autorenseite, offizieller Styleguide, Moodle-/PHPUnit-Doku, moodle-cs-Quelltext). Sekundärquellen sind ausdrücklich als solche markiert und zählen nicht als Beleg.
Vorbehalt: Bei der ursprünglichen Prüfung lagen die Buch-Volltexte nicht vor. Die Nachprüfung in Teil C verwendet die im E-Book-Ordner vorhandenen Volltexte; fehlende Bücher sind dort als nicht prüfbar markiert. Die bisherigen Teile A und B beruhen weiterhin auf Verlagsinhaltsverzeichnissen, Leseproben, Autorentexten und offizieller Doku, soweit nicht ausdrücklich anders angegeben.

---

## Teil A – Zitate im Entwurf

Ergebnis: 5 bestätigt, 3 ungenau, 1 nur auf Kapitelebene belegbar (Zuschreibung nicht primär belegt).

| Regel | Zitat im Entwurf | Urteil | Korrekte Angabe |
|---|---|---|---|
| N1 | Evans, Ubiquitous Language | bestätigt | *Domain-Driven Design* (2003), Kap. 2 „Communication and the Use of Language", Abschnitt „Ubiquitous Language" |
| T1 | Khorikov, resistance to refactoring | bestätigt | *Unit Testing Principles, Practices, and Patterns* (Manning 2020), Kap. 4 „The four pillars of a good unit test", Abschn. 4.1.2 |
| S1 | Hunt/Thomas, Crash Early | bestätigt, Fundstelle fehlt | 20th Anniversary Ed., Tip 38 (S. 113), Topic 24 „Dead Programs Tell No Lies", Kap. 4 „Pragmatic Paranoia" |
| N2 | Ousterhout, Kap. 12–13 | ungenau (Inhalt) | Kapitelnummern stimmen, aber Ousterhout vertritt *nicht* „der Code sagt, was er tut" |
| E1 | Ousterhout, Kap. 4 | bestätigt | Kap. 4 „Modules Should Be Deep"; Pass-through-Methoden stehen in Kap. 7 (nur sekundär belegt) |
| A5 | Martin, Boy Scout Rule | ungenau (Wortlaut) | *Clean Code* (2008), Kap. 1, Abschnitt „The Boy Scout Rule", S. 14; Regel verlangt „cleaner", nicht „at least as clean" |
| A5 | Fowler, Rule of Three | Kapitel belegt, Zuschreibung nicht primär belegbar | *Refactoring*, Kap. 2 „Principles in Refactoring", Abschnitt „When Should You/We Refactor?" (1. Aufl. 1999 / 2. Aufl. 2018) |
| A5 | Tornhill, Hotspot = Änderungshäufigkeit × Komplexität | ungenau (Formel) | Tornhill definiert Hotspots als *Überlappung* beider Größen, kein Produkt |
| A3 | Nygard 2011 | bestätigt | Blogartikel, 15.11.2011, Cognitect |

### N1 – Evans, Ubiquitous Language

- **Bestätigt.** Verlags-Inhaltsverzeichnis: Kap. 2 „Communication and the Use of Language", erster Abschnitt „Ubiquitous Language" (Addison-Wesley 2003). <https://www.informit.com/store/domain-driven-design-tackling-complexity-in-the-heart-9780321125217>
- Wortlaut aus Evans' eigener *DDD Reference* (2015, Pattern „Ubiquitous Language", S. 3–4): „Use the model as the backbone of a language. Commit the team to exercising that language relentlessly in all communication within the team and in the code. Within a bounded context, use the same language in diagrams, writing, and especially speech." Und: „Translation blunts communication and makes knowledge crunching anemic." <https://www.domainlanguage.com/wp-content/uploads/2016/05/DDD_Reference_2015-03.pdf>
- **Überdehnung:** Bei Evans wurzelt die Sprache im *Modell* und gilt *innerhalb eines Bounded Context*; ein Glossar ist nur ein Hilfsmittel. „one term per concept" trägt die Quelle. Spannung zur festen Code-Übersetzung in **N1**: Evans warnt ausdrücklich vor Übersetzung zwischen Fach- und Codesprache; deutsches Glossar und englischer Code führen genau eine solche Übersetzungsschicht ein. Das spricht für die Variante „englische Spalte je `CONTEXT.md`-Eintrag" (eine feste Übersetzung statt vieler).

### T1 – Khorikov, resistance to refactoring

- **Bestätigt** auf Kapitel-/Abschnittsebene: Manning-Livebook, Kap. 4 „The four pillars of a good unit test"; Abschnitte 4.1 „Diving into the four pillars…", 4.1.2 „Resistance to refactoring" (Abschnittstitel), 4.1.3 „What causes false positives?". Die vier Säulen: protection against regressions, resistance to refactoring, fast feedback, maintainability. <https://livebook.manning.com/book/unit-testing/chapter-4>
- **Nicht primär belegt:** die wörtliche Definition („the degree to which a test can sustain a refactoring of the underlying application code without turning red") – nur in Sekundärzusammenfassungen gesehen, Volltextprüfung offen.
- **Überdehnung:** Die zweite Hälfte von T1 („Test doubles replace only existing adapter ports") ist Projektregel, nicht Khorikov. Khorikovs eigene Regel (Autorenartikel „When to mock", 15.04.2020): „Use real instances of managed dependencies in integration tests; replace unmanaged dependencies with mocks." <https://enterprisecraftsmanship.com/posts/when-to-mock/> – Wer Khorikov zitiert, sollte diese Unterscheidung (managed/unmanaged) entweder übernehmen oder die Port-Regel ausdrücklich als Projektentscheidung (Spec 0029) ausweisen.

### S1 – Hunt/Thomas, Crash Early

- **Bestätigt.** Offizielle Tip-Liste des Verlags (20th Anniversary Edition): „Tip 38 (pg. 113): Crash Early – A dead program normally does a lot less damage than a crippled one." <https://pragprog.com/tips/>
- Kapitel/Topic laut Verlags-Inhaltsverzeichnis: Kap. 4 „Pragmatic Paranoia", Topic 24 „Dead Programs Tell No Lies". <https://www.informit.com/store/pragmatic-programmer-your-journey-to-mastery-20th-anniversary-9780135957059>
- **Nicht belegt:** Tip-Nummer der 1. Auflage (1999) – keine Primärquelle gefunden. Im Entwurf deshalb Ausgabe angeben.
- **Überdehnung:** Tip 38 begründet „lieber abbrechen als im kaputten Zustand weiterlaufen". „Ein Fallback existiert nur, wo ein ADR oder Spec ihn nennt" ist eine Projekt-Verschärfung (ADR 0023), nicht Hunt/Thomas. Passender ergänzender Primärbeleg aus dem Moodle-Stil: „Use exceptions to report errors… Do not abuse exceptions for normal code flow." (siehe Teil A, Moodle).

### N2 – Ousterhout, Kap. 12–13

- **Kapitelnummern plausibel, aber nur sekundär belegt:** Kap. 12 „Why Write Comments? The Four Excuses", Kap. 13 „Comments Should Describe Things That Aren't Obvious from the Code" (Inhaltsverzeichnis aus Sekundärquellen, z. B. <https://danlebrero.com/2021/02/24/philosophy-of-software-design-summary/>). Die Autorenseite nennt für die 2. Auflage (Juli 2021) nur ein neues Kapitel „Decide What Matters", ein überarbeitetes Kap. 6 und Vergleiche mit *Clean Code* – die Nummern 4/12/13 dürften stabil sein, nicht primär geprüft. <https://web.stanford.edu/~ouster/cgi-bin/aposd.php> (Die Leseprobe `aposd2ndEdExtract.pdf` ist ein Bildscan ohne Textebene.)
- **Ungenau im Inhalt.** Ousterhout widerspricht der These „the code itself says what it does". Kap. 12 widerlegt gerade die Ausrede „Good code is self-documenting". In der veröffentlichten Debatte mit Martin schreibt Ousterhout: „The first reason for comments is abstraction. Simply put, without comments there is no way to have abstraction or modularity." und verlangt einen Kopfkommentar, der die *Schnittstelle* einer Methode beschreibt (also *was* sie leistet). <https://github.com/johnousterhout/aposd-vs-clean-code>
- Die These „Kommentare sagen warum, nicht was" belegen dagegen wörtlich: Google („Usually comments are useful when they explain why some code exists, and should not be explaining what some code is doing.") und GitLab („Code comments should focus more on the 'why' and not on the 'what' or 'how'") – siehe Teil B.
- **Zur Open-Frage (Issue-Nummern in Kommentaren):** Der Moodle-Stil entscheidet sie: „Do not include MDL tracker references in standard inline comments (except in TODO comments, which must include a tracker reference). Inline comments should explain the logic and purpose of the code, not historical tracker context." <https://moodledev.io/general/development/policies/codingstyle> (Abschnitt „Inline comments"). GitLab erlaubt Issue-Links nur für Kommentare, die später etwas erledigt haben wollen (technical-debt issue). Ist-Stand: 305 Kommentarzeilen im Plugin mit `#nnn`-Verweisen, 0 TODOs.

### E1 – Ousterhout, Kap. 4

- **Bestätigt** (Kapitelnummer nur sekundär, s. o.): Kap. 4 „Modules Should Be Deep". Autorentext: „The best methods are those that provide a lot of functionality but have a very simple interface… I call these methods 'deep'." / „I call these interfaces 'shallow'". <https://github.com/johnousterhout/aposd-vs-clean-code>
- Der Pass-through-Teil von E1 gehört zu Kap. 7 „Different Layer, Different Abstraction" (Abschnitt „Pass-through methods") – nur sekundär belegt. Quellenangabe auf „Kap. 4 und 7" erweitern.

### A5 – Martin, Boy Scout Rule

- **Fundstelle bestätigt:** *Clean Code*, 1. Aufl. (2008), Kap. 1 „Clean Code", Abschnitt „The Boy Scout Rule", S. 14 (Verlags-Inhaltsverzeichnis). <https://www.informit.com/store/clean-code-a-handbook-of-agile-software-craftsmanship-9780132350884> – Eine 2. Auflage ist bei Pearson erschienen; Fundstelle dort nicht geprüft.
- Ursprüngliche Zuschreibung (Martins eigener Aufsatz in *97 Things Every Programmer Should Know*, O'Reilly): Robert Stephenson Smyth Baden-Powell, „Try and leave this world a little better than you found it"; Pfadfinderregel „Always leave the campground cleaner than you found it"; auf Code übertragen: „Always check a module in cleaner than when you checked it out." <https://github.com/97-things/97-things-every-programmer-should-know/blob/master/en/thing_08/README.md>
- **Ungenau:** A5 sagt „at least as clean" – die Quelle verlangt *cleaner* (spürbar, aber klein: eine Variable umbenennen, eine Funktion zerlegen). ADR 0029 trägt die strengere Lesart bereits („Wer eine Methode mit hohem CRAP anfasst, zerlegt oder testet sie").
- Gleichwertige Primärquelle: Pragmatic Programmer Tip 5 „Don't Live with Broken Windows – Fix bad designs, wrong decisions, and poor code when you see them." <https://pragprog.com/tips/>

### A5 – Fowler, Rule of Three

- **Kapitel bestätigt:** Kap. 2 „Principles in Refactoring", Abschnitt „When Should You Refactor?" (1. Aufl. 1999) bzw. „When Should We Refactor?" (2. Aufl. 2018) laut Verlags-Inhaltsverzeichnissen. <https://www.informit.com/store/refactoring-improving-the-design-of-existing-code-9780201485677>, <https://www.informit.com/store/refactoring-improving-the-design-of-existing-code-9780134757711>
- **Nicht primär belegbar:** Unterabschnitt „The Rule of Three", das Zitat „The first time you do something, you just do it. The second time… you wince at the duplication… The third time you do something similar, you refactor." und die Zuschreibung an Don Roberts – nur über Wikipedia/Vorlesungsfolien gesehen (Sekundärquellen). Auf martinfowler.com kein Beleg gefunden. Volltextprüfung offen.
- **Überdehnung:** Die Rule of Three betrifft *Duplikation* (dritte ähnliche Stelle), nicht „größeres Aufräumen" allgemein. A5 nennt sie korrekt als Auslöser „third copy of a pattern".

### A5 – Tornhill, Hotspots

- **Ungenau (Formel).** Tornhill definiert Hotspots als *Überlappung* von Änderungshäufigkeit und Komplexität, nicht als Produkt. CodeScene-Doku (Tornhills Firma): „A hotspot is complicated code that you have to work with often." – „We use the lines of code in each file as a proxy for complexity… the change frequency of each file as a proxy for the effort… You want to look for an overlap between the two metrics." <https://docs.enterprise.codescene.io/versions/1.3.0/guides/technical/hotspots.html> Das „×" stammt aus Sekundärblogs.
- Fundstelle 2. Aufl. (Pragmatic Bookshelf, Feb. 2024): Kap. 4 „Discover Hotspots: Create an Offender Profile of Code"; passend zu C8s „architecture review" außerdem Kap. 10 „Architectural Reviews: Support Redesigns with Data". <https://pragprog.com/titles/atcrime2/your-code-as-a-crime-scene-second-edition/> 1. Aufl. 2015 (vergriffen).

### A3 – Nygard, Documenting Architecture Decisions

- **Bestätigt.** Michael Nygard, 15.11.2011: ADRs für „architecturally significant" Entscheidungen, also solche, die „affect the structure, non-functional characteristics, dependencies, interfaces, or construction techniques". Status: proposed, accepted, deprecated/superseded. <https://www.cognitect.com/blog/2011/11/15/documenting-architecture-decisions>
- **Projekt-Zusätze, nicht Nygard:** „before the code lands" und „the code links it". Nygard sagt nur, dass Entscheidungen fortlaufend festgehalten werden („Not all decisions will be made at once…"). „Hard-to-reverse" ist ebenfalls nicht seine Formulierung; seine Liste (Struktur, Abhängigkeiten, Schnittstellen, Bauweise) deckt C10s Beispiele aber ab.

### T5 – PHPUnit `requireCoverageMetadata`

- **Bestätigt, mit Einschränkung.** Attribut `requireCoverageMetadata` am Element `<phpunit>`, Default `false`: „configures whether a test will be marked as risky… when it does not indicate the code it intends to cover using an attribute." <https://docs.phpunit.de/en/11.5/configuration.html>
- Seit PHPUnit 10.0 (Umbenennung von `forceCoversAnnotation`; Migration `RenameForceCoversAnnotationAttribute` im 9.5→10-Schritt, geprüft im PHPUnit-11.5.55-Quelltext des Spike-Containers). Moodle 5.0 nutzt PHPUnit 11.4 (<https://moodledev.io/general/development/tools/phpunit>), Spike (5.1) PHPUnit 11.5.55.
- Kein CLI-Schalter dafür. `--strict-coverage` setzt nur `beStrictAboutCoverageMetadata` (anderes Verhalten: riskant, wenn Code *außerhalb* der Metadaten läuft).
- **Hürde:** Moodle erzeugt `phpunit.xml` beim `init` aus dem Core-`phpunit.xml.dist` (`lib/phpunit/classes/util.php`, `build_config_file()`/`build_component_config_files()`). Ein Plugin kann das Attribut dort nicht setzen; das Gate müsste die erzeugte Datei nachbearbeiten oder eine eigene Konfiguration übergeben.
- **Bereits vorhanden:** moodle-cs prüft Coverage-Metadaten (Sniff `moodle.PHPUnit.TestCaseCovers`, `addWarning`: „Test method %s() is missing any coverage information, own or at class level"). Läuft moodle-cs im Gate mit Warnungen als Fehler, ist die `#[CoversClass]`-Hälfte von T5 schon werkzeuggeprüft. <https://github.com/moodlehq/moodle-cs/blob/main/moodle/Sniffs/PHPUnit/TestCaseCoversSniff.php>
- Nebenbefund zu T2: Moodles `phpunit.xml.dist` setzt `beStrictAboutTestsThatDoNotTestAnything="false"`. PHPUnits eigene Prüfung auf Tests ohne Assertion ist in Moodle also abgeschaltet; T2 kann sich darauf nicht stützen.

### E4, T5, N4 – Moodle-Doku

- **N4, `\moodle_exception` vs. `use moodle_exception`:** Der Moodle-Stil lässt beides zu: „Classes from outside the current scope use the leading backslash or are imported by the use keyword." (Abschnitt „Namespaces"); für `stdClass` ausdrücklich „both acceptable". <https://moodledev.io/general/development/policies/codingstyle> moodle-cs enthält keinen Sniff, der eine Form erzwingt (Ruleset geprüft: nur `Universal.UseStatements.*` für Schreibweise, kein FullyQualified/ReferenceUsedNamesOnly). Die Open-Option „leave it to moodle-cs" läuft also ins Leere; wer eine Form will, braucht eine eigene Regel oder einen Zusatz-Sniff.
- **N4, Sprachstrings:** „Exceptions 'error codes' will be translated only when they are meant to be shown to final users." – „coding_exception: thrown when the problem seems to be caused by a developer's mistake… try to make the error message helpful to the plugin author". – „Where appropriate, you should create new subclasses of moodle_exception" (Ablage `classes/exception/`). Gleiche Quelle, Abschnitt „Exceptions". Trägt N4.
- **T5, externe Funktionen testen:** Moodle-Doku zeigt den Test über `execute()` plus `external_api::clean_returnvalue(get_fruit::execute_returns(), $returnvalue)` – „to simulate the web service server" – und einen eigenen Test für die fehlende Capability. <https://moodledev.io/docs/apis/subsystems/external/testing> Trägt T5 als Moodle-Praxis.
- **E4, dünne Seitenskripte:** Keine Moodle-Regel gefunden. Die Output-API-Doku verlangt nur, dass Renderer/Templates keine Logik tragen, und legt Logik ausdrücklich *vor* die Renderable: „we should perform all our logic such as database queries, page parameters and access checks in advance then pass the results as data to the renderable." <https://moodledev.io/docs/apis/subsystems/output> E4 ist damit eine Projektregel (Spec 0029), keine Moodle-Konvention.

---

## Teil B – Abgleich der allgemeinen Regeln mit veröffentlichten Standards

Geprüft: Google Engineering Practices („What to look for", „Small CLs"), Moodle Coding style und Moodle-PHPUnit-/External-Doku, GitLab Development Guidelines (Code Review, Code Comments, Testing best practices). PSR/PER enthalten nur Formatregeln (Werkzeugsache) und bleiben außen vor.

| Regel | Entsprechung | Quelle |
|---|---|---|
| A1 Werkzeug vor Prosa | keine direkte | – (Google: „the style guide is the absolute authority", aber keine Regel „prüfbares gehört ins Werkzeug") |
| N1 Domänenbegriffe | teilweise | Google Naming: „A good name is long enough to fully communicate what the item is or does…"; Moodle: „meaningful lower-case English words". Domänenbindung nur bei Evans. |
| T1 Verhalten über Schnittstelle | ja | Google Tests: „If the code changes beneath them, will they start producing false positives?"; Moodle External-Testing (über `execute()`). |
| T2 Domänen-Assertion | ja | Google: „Will the tests actually fail when the code is broken? … Does each test make simple and useful assertions?"; GitLab: „A test that cannot fail is not providing coverage." |
| S1 Laut scheitern | teilweise | Moodle: „Use exceptions to report errors… Do not abuse exceptions for normal code flow." |
| N2 Kommentare sagen warum | ja | Google Comments (s. o.); GitLab Code comments; Moodle Inline comments („explain the logic and purpose…, not historical tracker context") |
| E1 Tiefe Module | teilweise | Google Complexity: „‚Too complex' usually means ‚can't be understood quickly by code readers.'" Keine Entsprechung zu Tiefe/Pass-through. |
| A5 Boy Scout, Aufräumen auf Ereignis | ja (Boy Scout), Spannung (größeres Aufräumen) | Google Context: „Don't accept CLs that degrade the code health of the system."; Google Small CLs: „It's usually best to do refactorings in a separate CL from feature changes or bug fixes… Small cleanups such as fixing a local variable name can be included"; Pragmatic Tip 5. |
| A2 Ignore mit Grund | keine in den geprüften Standards | Moodle-Stil verlangt Begründung nur für generische Options-Arrays („the reason clearly explained") – nicht übertragbar. |
| A3 ADRs | keine in den geprüften Standards | nur Nygard |

Links: Google <https://google.github.io/eng-practices/review/reviewer/looking-for.html>, <https://google.github.io/eng-practices/review/developer/small-cls.html>; GitLab <https://docs.gitlab.com/development/code_review/>, <https://docs.gitlab.com/development/code_comments/>, <https://docs.gitlab.com/development/testing_guide/best_practices/>; Moodle <https://moodledev.io/general/development/policies/codingstyle>.

Hinweis: Eine WebFetch-Zusammenfassung schrieb GitLab einen Abschnitt „Avoid testing implementation details" zu; im Seitenquelltext existiert er nicht. Nicht verwendet.

### Lücken: in den Standards üblich, im Entwurf fehlend, nicht werkzeugprüfbar

1. **Keine spekulative Allgemeinheit (YAGNI).** Google Complexity: „Encourage developers to solve the problem they know needs to be solved now, not the problem that the developer speculates might need to be solved in the future." E1 deckt nur flache Schichten ab, nicht unnötige Verallgemeinerung oder ungenutzte Optionen.
2. **Größeres Refactoring getrennt vom Feature.** Google Small CLs (Zitat oben), GitLab „Keep MRs small. Around 200 lines is a good target." A5 sagt nur, *wann* größer aufgeräumt wird, nicht *in welchem Change*. Ergänzung: „Boy-Scout-Kleinkram im selben Change, größeres Aufräumen als eigener Commit/PR".
3. **Doku wandert mit dem Code.** Google Documentation: „If a CL changes how users build, test, interact with, or release code, check to see that it also updates associated documentation"; GitLab-Checkliste: „You have added/updated documentation or decided that documentation changes are unnecessary for this MR." Für Coursepilot: `CONTEXT.md`, ADRs, `docs/`, Skill-Korpus, Admin-Anleitungen.
4. **Testtitel und Setup passen zum Szenario.** GitLab „Match each example to its scenario: A passing example provides coverage only when its setup and assertions exercise the intended scenario." Ergänzt N1/T2: ein Test, dessen Name „ohne Berechtigung" sagt, muss auch ohne Berechtigung laufen.
5. **Testcode ist Produktionscode.** Google: „Remember that tests are also code that has to be maintained. Don't accept complexity in tests just because they aren't part of the main binary." Fehlt; Coverage/Mutation messen das nicht.
6. **(Teil 2) Jede externe Funktion prüft Kontext und Capability.** Moodle: „validate_context() is required in all external functions before operating on any data belonging to a context… Do NOT use require_login(), or $PAGE->set_context() in an external function." <https://moodledev.io/docs/apis/subsystems/external/writing-a-service> moodle-cs prüft nur `require_login` & Co. in Seitenskripten (`RequireLoginSniff`), nicht externe Funktionen. Für ein Plugin, dessen Werkzeuge alle externe Funktionen sind, die wichtigste fehlende Moodle-Regel.

---

## Änderungsvorschläge für CODING_STANDARDS.md

- **N1:** Quelle präzisieren: „Evans, *Domain-Driven Design* (2003), ch. 2 (Ubiquitous Language); DDD Reference 2015". Optional: „within the plugin as one bounded context".
- **T1:** Quelle präzisieren: „Khorikov (Manning 2020), ch. 4, §4.1.2". Satz zu Test-Doubles als Projektentscheidung markieren („Source: Spec 0029") oder an Khorikovs managed/unmanaged-Regel angleichen.
- **T2:** Nebenbefund aufnehmen: Moodle schaltet `beStrictAboutTestsThatDoNotTestAnything` ab; die Regel bleibt deshalb Review-Sache, bis Mutation sie deckt.
- **S1:** „Hunt/Thomas, *The Pragmatic Programmer*, 20th Anniversary Ed., Tip 38 ‚Crash Early' (Topic 24)". Ergänzen: Moodle coding style, Exceptions („Do not abuse exceptions for normal code flow"). Fallback-per-ADR als Projektverschärfung kennzeichnen.
- **N2:** Ousterhout als Quelle für „the code itself says what it does" streichen (er widerspricht). Stattdessen: Google eng-practices (Comments), GitLab code comments, Moodle coding style (Inline comments). Optional die Ousterhout-Nuance aufnehmen: Schnittstellenkommentare (was eine Methode leistet) bleiben erlaubt. Open-Frage zu Issue-Nummern: Moodle-Stil verbietet Tracker-Verweise in normalen Inline-Kommentaren; Vorschlag: übernehmen, Ausnahme nur für TODO mit Issue (betrifft 305 Bestandszeilen, Umstellung beim Anfassen nach A5).
- **E1:** „ch. 4 (deep modules), ch. 7 (pass-through methods)". YAGNI-Satz ergänzen oder als eigene Regel (Lücke 1).
- **A5:** „at least as clean" → „cleaner than it found it" (Martin; Baden-Powell). Fundstelle „*Clean Code* (2008), ch. 1, p. 14". Hotspot-Definition: „where high change frequency overlaps with high complexity" statt „×". Tornhill-Quelle: „2nd ed. 2024, ch. 4 (hotspots), ch. 10 (architectural reviews)". Rule of Three: „Fowler, *Refactoring*, ch. 2" und Don-Roberts-Zuschreibung bis zur Volltextprüfung weglassen oder als „attributed to Don Roberts (unverified)" markieren. Ergänzen: größeres Aufräumen als eigener Change (Google Small CLs).
- **A3:** Quelle mit URL; „before the code lands" und „the code links it" als Projektzusatz ausweisen. Nygards Kriterium („structure, non-functional characteristics, dependencies, interfaces, or construction techniques") kann die Klammer ersetzen.
- **T5:** Open präzisieren: `requireCoverageMetadata` (PHPUnit ≥ 10) setzt eine nachbearbeitete Moodle-`phpunit.xml` voraus; einfacher ist moodle-cs `moodle.PHPUnit.TestCaseCovers` mit Warnungen als Fehler im Gate. Dann wandert die `#[CoversClass]`-Hälfte nach A1 aus. Quelle ergänzen: Moodle External-Testing-Doku (`clean_returnvalue`).
- **N4:** Open korrigieren: moodle-cs entscheidet `\moodle_exception` vs. `use` nicht, der Moodle-Stil erlaubt beides. Entweder eine Form als Projektregel festlegen oder offen lassen; „leave it to moodle-cs" streichen. Quelle ergänzen: Moodle coding style, Exceptions.
- **E4:** Quelle bleibt Spec 0029; ausdrücklich als Projektregel kennzeichnen (Moodle verlangt nur logikfreie Renderer/Templates).
- **Neu (Kandidaten):** Doku mit dem Code ändern (Lücke 3); Testtitel = Szenario (Lücke 4); Testcode ohne unnötige Komplexität (Lücke 5); S3 „Each external function calls `validate_context()` and checks its capability before touching data" (Lücke 6).

## Teil C – Volltextprüfung

Stand: 06.10.2026. Geprüft wurden die lokalen PDFs durch Suche im extrahierten Volltext. Seitenzahlen beziehen sich auf die gedruckte Seitennummer, soweit sie im Volltext eindeutig war; andernfalls ist die PDF-Seite angegeben. Zitate bleiben kurz.

| Punkt | Urteil | Auflage / Fundstelle | Kurzbeleg |
|---|---|---|---|
| Fowler, *Refactoring* — Rule of Three | bestätigt | 2. Aufl. (2018), Kap. 2 „Principles in Refactoring“, Abschnitt „When Should We Refactor?“, Unterabschnitt „The Rule of Three“, PDF-S. 62. Beide vorhandenen Fowler-PDFs tragen „Second Edition“; die 1. Aufl. liegt nicht vor. | „Here’s a guideline Don Roberts gave me“; „The third time you do something similar, you refactor.“ |
| Ousterhout — Kap. 4, 7, 12, 13; N2/E1 | bestätigt | 1. Aufl. (2018): Kap. 4 „Modules Should Be Deep“; Kap. 7 „Different Layer, Different Abstraction“ (§7.1 „Pass-through methods“); Kap. 12 „Why Write Comments? The Four Excuses“; Kap. 13 „Comments Should Describe Things that Aren’t Obvious from the Code“ (§§13.5–13.6). Für N2 besonders S. 104–107; Pass-through S. 45. | „Interface comments provide information that someone needs to know in order to use a class or method“; Pass-through methods „make classes shallower“. |
| Khorikov, *Unit Testing* — §4.1.2 und managed/unmanaged | Fundstelle bestätigt, Wortlaut nicht prüfbar | Manning 2020. Inhaltsverzeichnis aus der Leseprobe des Autors (<https://enterprisecraftsmanship.com/files/Unit-Testing-Chapter-1-Excerpt.pdf>): §4.1.2 „The second pillar: Resistance to refactoring“, S. 69; managed/unmanaged in Kap. 8, §8.2 „Which out-of-process dependencies to test directly“ (§8.2.1 „The two types of out-of-process dependencies“, S. 190; §8.2.2, S. 191). Kein Volltext; Regelwortlaut weiter nur aus dem Autorenartikel (Teil A, T1). | – |
| Martin, *Clean Code* — Boy Scout Rule | bestätigt | 1. Aufl. (2008), Kap. 1 „Clean Code“, Abschnitt „The Boy Scout Rule“, S. 14. | „If we all checked-in our code a little cleaner than when we checked it out, the code simply could not rot.“ |
| Tornhill, *Your Code as a Crime Scene* — Hotspots und Architekturreviews | korrigiert (Zitierweise), Definition nicht prüfbar | 2. Aufl. (2024), Verlags-Inhaltsverzeichnis (<https://pragprog.com/titles/atcrime2/your-code-as-a-crime-scene-second-edition/>). Die Seite nummeriert nicht; vor Teil 1 steht „Welcome to the Crime Scene“. Je nachdem, ob das als Kapitel zählt, ist „Discover Hotspots“ Kap. 3 oder 4 und „Architectural Reviews“ Kap. 9 oder 10. `CODING_STANDARDS.md` zitiert deshalb Kapiteltitel statt Nummern. Der Abschnittstitel „Intersect Complexity and Effort“ stützt die Lesart Überlappung statt Produkt. Kein Volltext. | Abschnittstitel: „Intersect Complexity and Effort“ |
| Hunt/Thomas, *The Pragmatic Programmer* — Crash Early | bestätigt | 20th Anniversary Ed. (2. Aufl., 2019), Kap. 4 „Pragmatic Paranoia“, Topic 24 „Dead Programs Tell No Lies“, Tip 38 „Crash Early“, S. 113. Keine 1. Aufl. (1999) im Ordner; deren Tip-Nummer ist nicht prüfbar. | „A dead program normally does a lot less damage than a crippled one.“ |
| Evans, *Domain-Driven Design* — Ubiquitous Language / N1 | bestätigt, mit Einschränkung | 1. Aufl. (2003), Kap. 2 „Communication and the Use of Language“, Abschnitt „Ubiquitous Language“, S. 14–16 (Paginierung der vorliegenden PDF, kann vom Druck abweichen). N1 gedeckt. Für N1: Evans warnt vor der Trennung zwischen Gesprächs- und Codebegriffen, meint aber Fachjargon gegen Entwicklerjargon, nicht Deutsch gegen Englisch. Eine feste Übersetzung je Begriff bleibt die beste Annäherung. Zusätzlich: Begriffsänderung = Modelländerung ⇒ Code umbenennen (als `Open:` an N1). | „Translation blunts communication and makes knowledge crunching anemic.“; „Recognize that a change in the UBIQUITOUS LANGUAGE is a change to the model.“ |

### Übrige Bücher im Ordner und Regelkandidat

Die übrigen vorhandenen Werke (*The Clean Coder*, *Pro Git*, *The Agile Samurai* und *More Praise for Scrum*) ergaben bei der Sichtung keine zusätzliche, für diesen Entwurf passende, nicht werkzeugerzwungene Regel. In *Clean Code* (1. Aufl., 2008) steht eine mögliche Lücke: Command Query Separation. Das Projekt-Gate erzwingt diese Trennung nicht. Der Kandidat wurde übernommen (heute E3).

Kurzbeleg: „Functions should either do something or answer something, but not both.“ Kap. 3 „Functions“, Abschnitt „Command Query Separation“, S. 45.

---

## Teil D – Abgleich mit den Moodle-Vorgaben (06.10.2026)

Geprüft: Moodle coding style <https://moodledev.io/general/development/policies/codingstyle>, Security guidelines <https://moodledev.io/general/development/policies/security>, Plugin contribution checklist <https://moodledev.io/general/community/plugincontribution/checklist> (als „legacy“ markiert, verweist auf die Marketplace Submission Guidelines; deren Lizenzteil behandelt ADR 0025). Werkzeugabdeckung gegen den moodle-cs-Quelltext (`moodle/Sniffs/`, Stand main).

**Kein Widerspruch** zwischen Entwurf und Moodle-Vorgaben. Eine Reibung:

- **N2 / TODO:** `moodle.Commenting.TodoComment` verlangt standardmäßig `MDL-[0-9]+`. Beschluss: volle GitHub-Issue-URL; Gate setzt `moodleTodoCommentRegex` (Kommentar auf #655).

**Von moodle-cs erzwungen** (deshalb nicht im Text): Boilerplate und `@copyright` (`Files/BoilerplateComment`, `Commenting/FileExpectedTags`), Namensregeln, `require_login()` in Seitenskripten (`Files/RequireLogin`), `eval`/`goto`/Backticks (`PHP/ForbiddenTokens`), `unserialize`/`extract`/`print_r` u. a. (`PHP/ForbiddenFunctions`), Covers-Angabe (`PHPUnit/TestCaseCovers`), Sortierung der Sprachdatei (`Files/LangFilesOrdering`). **Nicht** geprüft: Zugriff auf `$_GET`/`$_POST`/`$_REQUEST` (`PHP/ForbiddenGlobalUse` betrifft nur `$PAGE`/`$OUTPUT` in Renderern und Blöcken).

**Nicht werkzeuggeprüft, neu aufgenommen:**

| Regel | Moodle-Quelle |
|---|---|
| N4 erweitert: alle sichtbaren Texte über `get_string()`, Sentence case, Sprachdatei als reine Daten | Checklist „Strings“; Coding style „Language strings / Capitals“ |
| S2 Seiten: Capability, `PARAM_*`, POST + sesskey, Bestätigung vor Massenlöschung, Ausgabe-Escaping | Security guidelines, Summary; Checklist „Security“ |
| D2 DML-API, Platzhalter, Cross-DB (CI nur MariaDB → #687) | Checklist „Cross-DB compatibility“, „Approval blockers“ |
| D4 Einstellungen `local_coursepilot/<name>`, `get_config()` | Checklist „Settings storage“ |
| D3 Schreibende Aktionen lösen Events aus (Beschluss: nur schreibende) | Security guidelines „Log every request“ |
| E5 Typisierte Parameter statt `$options`-Array; Magic Methods nur begründet | Coding style „Using arrays for options as arguments“, „Magic methods“ |

**Schon abgedeckt:** englische Kommentare und Namen (N3; `lang/de/` wird vom Release-Build ausgeschlossen, `scripts/build-native-release.js:77–99`), Privacy API (Vertragstests, T4), Formatierungs-Only-Änderungen getrennt committen (A5 ≈ MDL-43233), Webservice-Namen `{component}_{verb}_{noun}` (erzeugt über `tool_registry::function_name()`).

**Nicht übernommen:** Moodle-Commit-Format `MDL-xxxx AREA:` (gilt für Core; Projekt nutzt Conventional Commits). Der CSS-Namensraum war hier zunächst zurückgestellt (Coursepilot hat kein `styles.css`) und ist inzwischen als U1/U2 aufgenommen, weil die Standards für alle Moodle-Plugins des Vereins gelten.

---

## Teil E – Quellen des KI-Teils und der Abstimmungsänderungen (09.10.2026)

Geprüft gegen die Primärquellen am 09.10.2026.

| Regel | Quelle | Urteil | Kurzbeleg |
|---|---|---|---|
| K1 | Anthropic, „Building effective agents“, 19.12.2024, Abschnitt „When (and when not) to use agents“ <https://www.anthropic.com/engineering/building-effective-agents> | trägt den Grundsatz, nicht die Einzelfälle | „find the simplest solution possible, and only increasing complexity when needed“; Workflows = „predefined code paths“. Die Aufzählung (Prüfen, IDs auflösen, Rechnen) ist Projektauslegung. |
| K1 | OWASP Top 10 for LLM Applications 2025, LLM01 <https://genai.owasp.org/llmrisk/llm01-prompt-injection/> | ergänzend | Empfiehlt deterministischen Code zur Prüfung von Modellausgaben. |
| K2 | OWASP 2025, LLM06 Excessive Agency <https://genai.owasp.org/llmrisk/llm062025-excessive-agency/> | bestätigt | „Implement authorization in downstream systems rather than relying on an LLM to decide if an action is allowed.“ |
| K3 | MCP-Spezifikation 2025-11-25, Tools, Security Considerations <https://modelcontextprotocol.io/specification/2025-11-25/server/tools> | bestätigt | „Servers MUST: Validate all tool inputs … Sanitize tool outputs“ |
| K3 | OWASP 2025, LLM01 Prompt Injection | bestätigt | „Indirect prompt injections occur when an LLM accepts input from external sources, such as websites or files.“; „Separate and clearly denote untrusted content“ |
| K4 | MCP 2025-11-25, Tools (User Interaction Model) und Schema `ToolAnnotations` <https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts> | bestätigt | „Present confirmation prompts to the user for operations“; `readOnlyHint` Default `false`, `destructiveHint` Default `true` – ohne Angabe gilt ein Werkzeug als zerstörend schreibend. |
| K4 | OWASP 2025, LLM06 | bestätigt | „require a human to approve high-impact actions before they are taken“ |
| K5, K6 | DSGVO Art. 5 Abs. 1 lit. c, Art. 25 <https://eur-lex.europa.eu/eli/reg/2016/679/oj> | bestätigt | Datenminimierung; Art. 25 Abs. 2: „dass durch Voreinstellung grundsätzlich nur personenbezogene Daten, deren Verarbeitung für den jeweiligen bestimmten Verarbeitungszweck erforderlich ist, verarbeitet werden“ |
| K6 | OWASP 2025, LLM02 Sensitive Information Disclosure <https://genai.owasp.org/llmrisk/llm022025-sensitive-information-disclosure/> | bestätigt | „Limit access to sensitive data based on the principle of least privilege.“ |
| K7 | Anthropic, „Writing effective tools for agents — with agents“, 11.09.2025 <https://www.anthropic.com/engineering/writing-tools-for-agents> | bestätigt | „return only high signal information back to agents“; „pagination, range selection, filtering, and/or truncation with sensible default parameter values“ |
| K8 | MCP 2025-11-25, Tools, Error Handling; Anthropic 2025 (wie K7) | bestätigt | „actionable feedback that language models can use to self-correct“; „clearly communicate specific and actionable improvements“ |
| K9 | ADR 0024 | Projektentscheidung | – |
| K10 | `CONTEXT.md` (Codex-First); Abstimmung #657 | Projektentscheidung | Erweitert von „nur Codex“ auf Codex und Claude. |
| D2 | Moodle `admin/environment.xml`, Abschnitt `MOODLE version="5.0"` (Spike-Container) | bestätigt | Datenbanken: mariadb 10.11, mysql 8.4, postgres 14, mssql 14.0, auroramysql 8.0. Oracle ist in 5.0 nicht mehr enthalten. |
| U1 | Moodle Output API <https://moodledev.io/docs/apis/subsystems/output>; Component Library (Bootstrap im Boost-Theme) | Grundsatz belegt, Vorrangregel ist Abstimmungsbeschluss | – |

**Befunde am Bestand:**

- **K4:** Das Plugin setzt keine `ToolAnnotations` (kein `readOnlyHint`/`destructiveHint` im Quelltext). Nach den MCP-Voreinstellungen gelten damit alle Werkzeuge als zerstörend schreibend (#705).
- **K6:** Der Schalter `allowpersonaldata` (ADR 0011) schützt nur gekennzeichnete Kontextdateien; andere Ausgabepfade prüft #703.

## Zur Nummerierung

Die Regelnummern gelten seit der Abstimmung #657 (09.10.2026): Themenbereiche mit eigenem Buchstaben (S, D, T, E, N, U, A, K), Geltungskennzeichen und Stufen, stabile Nummern ohne Wiederverwendung. Repo-Dokumente, Tickets und Kommentare wurden auf diese Nummern umgestellt. Commit-Nachrichten vor dem 09.10.2026 zitieren frühere Entwurfsfassungen; welche Regel gemeint war, zeigt `git show <commit>:CODING_STANDARDS.md`.
