# Metadaten-Standards für teilbares Unterrichtsmaterial

Recherche zu [Issue #247](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/247), Teil der Wayfinder-Karte [#246](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/246).
Stand: 2026-08-06. Alle Aussagen sind mit Primärquellen (Spezifikationstext, Schema-Datei, offizielle Portal-/Trägerdoku, Quellcode) belegt.

**Leitfrage:** Gibt es für teilbares Unterrichtsmaterial bereits etablierte Metadaten-Standards, die ein Markdown-Frontmatter-Profil für Kurspilot übernehmen kann, statt eigenes zu erfinden?

**Kurzantwort:** Ja — **AMB** ist der richtige Bezugspunkt, aber nur als *Feldvokabular*, nicht als Format. AMB verlangt eine auflösbare URL und JSON-LD-Auslieferung über HTTP; beides existiert bei einer Handvoll Markdown-Dateien im Ordner einer Lehrkraft nicht. Empfehlung: **AMB-Teilmenge in YAML-Frontmatter, feldnamensgleich**, plus ein Kurspilot-eigener Teil für das, was kein Standard abdeckt (Verlauf, Lerngruppe, Ableitungsketten). Details in Abschnitt 5.

---

## 1. Kurzprofile der Standards

### 1.1 AMB — Allgemeines Metadatenprofil für Bildungsressourcen

| | |
|---|---|
| **Zweck** | Bildungsbereichsübergreifendes Anwendungsprofil zur Beschreibung von Lehr-/Lernressourcen, „bewusst so allgemein gehalten, dass sie sowohl den Schul- als auch den Hochschulbereich abdeckt" ([Spec, Einleitung](https://dini-ag-kim.github.io/amb/20231019/)) |
| **Trägerschaft** | Kompetenzzentrum Interoperable Metadaten (KIM) der DINI, Entwicklung nach den [StöberSpecs](https://w3id.org/kim/stoeberspecs/)-Prozessen; Beteiligte u.a. hbz, TIB, GWDG, Serlo, Uni Münster ([README](https://github.com/dini-ag-kim/amb)) |
| **Format** | JSON-LD 1.1, Kontext `https://w3id.org/kim/amb/context.jsonld`; Basis-Schema ist schema.org (`CreativeWork`), ergänzt um LRMI-Properties und SKOS ([Spec](https://dini-ag-kim.github.io/amb/20231019/)) |
| **Reifegrad** | Erste offizielle Version **20231019** (einziges GitHub-Release, Stand 2026-08). Draft wird aktiv weiterentwickelt — letzte Commits Juni/Juli 2026 (u.a. `suggestedAge`, Präzisierung von `about`) |
| **Primärquelle** | Spec: <https://dini-ag-kim.github.io/amb/20231019/> · JSON Schema: <https://dini-ag-kim.github.io/amb/20231019/schemas/schema.json> · Repo: <https://github.com/dini-ag-kim/amb> |

**Pflichtfelder — exakt vier.** Das JSON Schema definiert `"required": ["@context","id","type","name"]` ([schema.json](https://dini-ag-kim.github.io/amb/20231019/schemas/schema.json)). Alle übrigen 30 Properties sind optional:
`description, about, keywords, inLanguage, image, trailer, creator, contributor, affiliation, dateCreated, datePublished, dateModified, publisher, funder, isAccessibleForFree, license, conditionsOfAccess, learningResourceType, audience, teaches, assesses, competencyRequired, educationalLevel, interactivityType, isBasedOn, isPartOf, hasPart, mainEntityOfPage, duration, encoding, caption`.

**Bauart / harte Auslieferungspflichten** (Abschnitt „Format und Bereitstellung" der Spec):

> „Das JSON-Dokument zur Beschreibung einer Bildungsressource muss entweder in die Ressource selbst bzw. – bei Nicht-HTML-basierten Ressourcen – in die HTML-Beschreibungsseite der Bildungsressource eingebettet werden oder als eigenständige Webressource – d.h. unter einer dedizierten URL abrufbar sein."

Konkret: eingebettet als `<script type="application/ld+json">` im `<head>`, **oder** unter eigener URL mit `Content-Type: application/ld+json` plus `<link rel="describedby">` bzw. `Link:`-Header. Das Feld `id` ist per Schema `{"type":"string","format":"uri"}` ([id.json](https://dini-ag-kim.github.io/amb/20231019/schemas/id.json)) — also die auflösbare Web-Adresse der Ressource.

**Kontrollierte Vokabulare (SKOS-Pflicht).** Die Spec verlangt SKOS-konform publizierte Wertelisten:

| Feld | Vokabular | URI-Basis |
|---|---|---|
| `about` (Fach) | Schulfächer (KIM) bzw. Hochschulfächersystematik | `http://w3id.org/kim/schulfaecher/`, `https://w3id.org/kim/hochschulfaechersystematik/scheme` |
| `learningResourceType` | HCRT (Hochschule) bzw. OpenEduHub `new_lrt` (Schule) | `https://w3id.org/kim/hcrt/scheme`, `http://w3id.org/openeduhub/vocabs/new_lrt/` |
| `educationalLevel` | Bildungsstufen (KIM, ISCED-basiert) | `https://w3id.org/kim/educationalLevel/` (per Schema-Pattern erzwungen) |
| `audience` | LRMI Educational Audience Roles | `http://purl.org/dcx/lrmi-vocabs/educationalAudienceRole/` |
| `interactivityType` | LRMI | `http://purl.org/dcx/lrmi-vocabs/interactivityType/` |
| `conditionsOfAccess` | KIM | `https://w3id.org/kim/conditionsOfAccess/` |

Beispiel-Konzepte: `https://w3id.org/kim/schulfaecher/s1001` = „Biologie" ([schulfaecher.ttl](https://github.com/dini-ag-kim/schulfaecher/blob/main/schulfaecher.ttl)), `https://w3id.org/kim/educationalLevel/level_2` = „Sekundarbereich I" ([educationalLevel.ttl](https://github.com/dini-ag-kim/educationalLevel/blob/main/educationalLevel.ttl)).

**Lizenzfeld ist streng.** `license.id` muss auf Creative Commons, GNU, Apache, MIT oder BSD zeigen — per Regex im Schema erzwungen ([license.json](https://dini-ag-kim.github.io/amb/20231019/schemas/license.json)). Eine „alle Rechte vorbehalten"-Ressource ist AMB-technisch nicht lizenzierbar.

**Adoption** (aus [implementations.md](https://github.com/dini-ag-kim/amb/blob/main/implementations.md), gepflegt vom KIM):
OERSI (empfiehlt AMB *und* liefert AMB aus), WirLernenOnline (empfiehlt AMB zur Datenlieferung), MeinBildungsraum (Empfehlung + offenbar Internformat), Serlo, ComeIn.nrw, HessenHub, e-teaching.org, Open Music Academy, ViFoNet.

### 1.2 LRMI / schema.org `LearningResource`

| | |
|---|---|
| **Zweck** | Erweiterung von schema.org um bildungsspezifische Terme; „builds on the extensive vocabulary of schema.org", die meisten LRMI-Terme sind als äquivalent zu schema.org-Properties definiert |
| **Trägerschaft** | DCMI (Dublin Core Metadata Initiative), LRMI Task Group; Editoren Phil Barker, Stuart Sutton |
| **Format** | RDF-Vokabular, Namespace `http://purl.org/dcx/lrmi-terms/`; in der Praxis als JSON-LD/Microdata über schema.org genutzt |
| **Reifegrad** | DCMI Community Specification, Version vom **14.06.2022**. Der schema.org-Typ `LearningResource` ist dort als „new / pending" markiert — Feedback ausdrücklich erwünscht |
| **Primärquelle** | <https://www.dublincore.org/specifications/lrmi/lrmi_terms/2022-06-14/> · <https://schema.org/LearningResource> |

**Umfang:** 3 Klassen (`AlignmentObject`, `EducationalAudience`, `LearningResource`) und 16 Properties: `alignmentType, assesses, educationalAlignment, educationalFramework, educationalLevel, educationalRole, educationalUse, interactivityType, isBasedOnUrl, learningResourceType, targetDescription, targetName, targetUrl, teaches, timeRequired, typicalAgeRange, useRightsUrl`. Der schema.org-Typ `LearningResource` selbst trägt spezifisch: `assesses, competencyRequired, educationalAlignment, educationalLevel, educationalUse, learningResourceType, teaches`.

**Wichtig:** LRMI/schema.org kennen **keine Pflichtfelder** und keine Wertelisten-Pflicht. Es ist ein Vokabular, kein Anwendungsprofil. Genau diese Lücke füllt AMB.

### 1.3 IEEE LOM (IEEE 1484.12.1)

| | |
|---|---|
| **Zweck** | Konzeptuelles Datenschema für Metadaten eines Lernobjekts, zur Unterstützung von Wiederverwendbarkeit, Auffindbarkeit und Interoperabilität — typischerweise im LMS-Kontext |
| **Trägerschaft** | IEEE Standards Association |
| **Format** | 9 Kategorien: General, Life Cycle, Meta-Metadata, Technical, Educational, Rights, Relation, Annotation, Classification; XML-Bindings in Folgeteilen |
| **Reifegrad** | **IEEE 1484.12.1-2020**, Active Standard, veröffentlicht 16.11.2020 (Revision von 2002). **Kostenpflichtig** — Zugang nur über Kauf oder IEEE-Abonnement |
| **Primärquelle** | <https://standards.ieee.org/ieee/1484.12.1/7699/> |

**Heutige Relevanz:** LOM ist in Deutschland nicht tot, aber es lebt fast ausschließlich *unter der Haube* von Portalinfrastruktur, nicht als Format, das eine Lehrkraft oder ein kleines Werkzeug produziert:

- edu-sharing: „The internal metadata structure of edu-sharing is based on LOM"; unterstützt beim Austausch LOM, LOM-DE und ELIXIER ([edu-sharing Doku 9.0](https://docs.edu-sharing.com/en/edu-sharing-documentation/9.0/metadaten-content-austausch-mit-externen-servern)).
- WirLernenOnline/OpenEduHub: das Crawler-Datenmodell ist strukturell LOM (`LomGeneralItem`, `LomLifecycleItem`, `LomEducationalItem`, `LomClassificationItem`, `LomTechnicalItem`) ([converter/items.py](https://github.com/openeduhub/oeh-search-etl/blob/master/converter/items.py)).
- Das eigene „LOM-Applikationsprofil des Open-Edu-Hubs" hat es nie über den Entwurf hinaus geschafft: „Bisher wurden keine Versionen veröffentlicht" ([README](https://github.com/openeduhub/oeh-metadata-specs)), letzte Aktivität 2023.
- Für den Hochschulbereich existiert **HS-OER-LOM** (KIM, XML, „konsequent auf die notwendigen Metadaten reduziert") — für Schule irrelevant ([Spec](https://dini-ag-kim.github.io/hs-oer-lom-profil/)).

AMB selbst grenzt sich explizit gegen LOM ab ([AMB-Spec, „Beziehung zu HS-OER-LOM"](https://dini-ag-kim.github.io/amb/20231019/)):

| | HS-OER-LOM | AMB |
|---|---|---|
| Quell-Schema | LOM | schema.org |
| Format | XML | JSON-LD |
| Linked Data | nein | ja |
| Bildungsbereich | Hochschule | Schule & Hochschule |

**Fazit LOM:** als Zielformat für Kurspilot ausgeschlossen — paywalled, XML-schwer, und die deutsche Community-Entwicklung ist erkennbar zu AMB abgewandert.

### 1.4 Portale in der Praxis

#### OERSI (OER Search Index)

Das OERSI-interne Profil „stimmt weitgehend mit dem Allgemeinen Metadatenprofil für Bildungsressourcen (AMB) überein" ([OERSI FAQ](https://oersi.org/resources/pages/de/faq/), Schema: <https://gitlab.com/oersi/oersi-schema>). Aufnahmekriterien sind bewusst niedrigschwellig: relevant, noch nicht indexiert, im Web zugänglich, offen lizenziert „at least in part", und „at least basic metadata of acceptable quality" ([Kriterien](https://oersi.org/resources/pages/de/docs/add-resources/criteria/)). **Es gibt keine harte Pflichtfeldliste jenseits von AMB.** Fokus ist Hochschule.

#### WirLernenOnline / edu-sharing

- WLO **empfiehlt AMB** zur Datenlieferung (belegt in [AMB implementations.md](https://github.com/dini-ag-kim/amb/blob/main/implementations.md)).
- Was WLO intern tatsächlich füllt, zeigt das Crawler-Modell ([items.py](https://github.com/openeduhub/oeh-search-etl/blob/master/converter/items.py)): LOM-Struktur plus ein `ValuespaceItem` mit `discipline, educationalContext, learningResourceType, new_lrt, intendedEndUserRole, oer, conditionsOfAccess, languageLevel, price, containsAdvertisement, accessibilitySummary, dataProtectionConformity, …` — bedient aus den SKOS-Vokabularen von [openeduhub/oeh-metadata-vocabs](https://github.com/openeduhub/oeh-metadata-vocabs) (>100 Vokabulare, CC0, publiziert über SkoHub unter `w3id.org/openeduhub/vocabs`).
- edu-sharing ist die Software darunter, in „more than ten federal states" ausgerollt; Import/Export läuft über konfigurierbares Mapping auf das interne LOM-Modell ([Doku](https://docs.edu-sharing.com/en/edu-sharing-documentation/9.0/metadaten-content-austausch-mit-externen-servern)).

**Praktische Folge:** Wer AMB liefert, wird von WLO verstanden. Die drei Felder, die WLO/edu-sharing wirklich zum Sortieren braucht, sind **Fach, Bildungsstufe/Schulform, Ressourcentyp** — plus Lizenz.

#### SODIX / MUNDO (FWU, Medieninstitut der Länder)

Die einzige *vollständig öffentlich dokumentierte* Feldliste im deutschen Schulbereich. Das GraphQL-Objekt `Metadata` der SODIX-API ([bmi-docs](https://github.com/FWU-DE/bmi-docs/blob/main/docs/sodixapi/types/objects/metadata.mdx)):

```graphql
type Metadata {
  id: ID!            identifier: String!    title: String!    learnResourceType: [LRT]!
  description: String        keywords: [String]      author: [String]     publishers: [Publisher]
  subject: [Subject]         schoolTypes: [String]   classLevel: String   educationalLevels: [String]
  competencies: [Competence] eafCode: [String]       targetAudience: [UserGroup]
  license: License           language: [String]      cost: Cost
  creationDate / publishedTime / updated: LocalDateTime
  parentMedia / childMedia / linkedObjects: String…
}
```

Nur **vier Pflichtfelder** (Non-Null): `id`, `identifier`, `title`, `learnResourceType`. Bemerkenswert für den Schulkontext: SODIX kennt zusätzlich zu `educationalLevels` ein **`classLevel`** (Jahrgang) und **`schoolTypes`** — beides Dinge, die AMB nicht sauber abbildet (siehe 1.5). MUNDO bindet sich in Moodle übrigens per **LTI Deep Linking** ein, nicht per Metadatenimport ([Mundo-Doku](https://github.com/FWU-DE/bmi-docs/blob/main/docs/mundo/intro.md)) — für Kurspilot als Nachbarschaft interessant, für die Frontmatter-Frage irrelevant.

#### Landesbildungsserver / ELIXIER

ELIXIER ist die gemeinsame Austauschinfrastruktur der Landesbildungsserver: „Entwicklung und Betrieb einer standardisierten Schnittstelle für den Austausch von Metadaten zwischen den deutschen Bildungsservern", 16 datenliefernde Partner (Landesbildungsserver BW, BE-BB, HH, HE, NW, SN, ST, RP, Deutscher Bildungsserver, FWU, bpb u.a.), tägliche Aktualisierung über eine gemeinsam definierte **XML-Schnittstelle** ([DIPF-Projektseite](https://www.dipf.de/en/research/projects/elixier), [bildungsserver.de/elixier](https://www.bildungsserver.de/elixier/)).

**Befund:** Die ELIXIER-Metadatenspezifikation ist **nicht öffentlich abrufbar**. Weder `bildungsserver.de/elixier` noch die DIPF-Projektseite verlinken ein Schema, eine Feldliste oder eine XSD; die Doku existiert laut Projektbeschreibung, ist aber Partnermaterial. edu-sharing führt „ELEXIER" als unterstütztes Importformat, ohne es zu spezifizieren. **ELIXIER ist damit kein Standard, den ein Werkzeug von außen bedienen kann** — Zugang läuft über Partnerschaft, nicht über eine Spec.

### 1.5 Maschinenlesbare Kompetenz-/Lehrplan-Vokabulare

Hier hat sich seit 2024 mehr bewegt als bei den Metadatenprofilen.

**FWU Lehrplan-Ontologie — der aktuelle Stand der Technik.**
Repo: <https://github.com/FWU-DE/lehrplan-ontologie> (Nachfolger von `dini-ag-kim/school-curriculum-pg`, das explizit dorthin verweist). Aktiv, letzte Commits Juli 2026.

- „provides a **formal, machine-readable knowledge representation** of curricula used in **general education schools in Germany**"
- Modularer Aufbau: Kern-Ontologie + **je Bundesland eine eigene Ontologie** — im Repo liegen `lp-land-{BW,BY,BE,BB,HB,HH,HE,MV,NI,NW,RP,SL,SN,ST,SH,TH}-full.owl`, also alle 16.
- Format: OWL/Turtle, SHACL-Shapes zur Validierung, Widoco-Doku unter <https://fwu-de.github.io/lehrplan-ontologie/>, SPARQL-Beispiele.
- **Explizit out of scope:** „The ontology does not model direct links to educational media or learning resources." Berufliche Bildung und Förderschule ebenfalls nicht abgedeckt.

Ergänzend aus derselben Trägerschaft: [FWU-DE/schulfach-ontologie](https://github.com/FWU-DE/schulfach-ontologie) (Schulfächer je Bundesland, mit Mapping auf die KIM-Schulfächer, zusätzlich SKOS-Ableitungen je Land) und `schulart-ontologie`, `mem-skos-vocabs`.

**KMK-Vokabulare (DINI AG KIM, Curricula-Gruppe).**
<https://github.com/dini-ag-kim/kmk-vocabs> — SKOS/Turtle, u.a. `lehramtstypen`, `bildungswissenschaften`, `digitalisierungsbezogene-kompetenzen` (KMK „Bildung in der digitalen Welt"), publiziert unter `https://w3id.org/kim/kmk-vocabs/…`. **Status laut README: „Entwurf"**, letzte Änderung 03/2023. Das Schwesterrepo `dini-ag-kim/modell_lehrplaene` steht seit 2022 still — die Arbeit ist erkennbar zur FWU-Ontologie migriert.

**Ältere/internationale Ansätze.** Die JOINTLY-Dokumentation ([Maschinenlesbare Lehrpläne](https://jointly.eduloop.de/loop/Maschinenlesbare_Lehrpl%C3%A4ne), [Kompetenzdarstellungen](https://jointly.eduloop.de/loop/Kompetenzdarstellungen), CC-BY, BMBF-gefördert) nennt ASN (Achievement Standards Network, RDF-basiert, `http://asn.jesandco.org/`), LRMI `educationalAlignment`/`AlignmentObject` und LOM-CH als Referenzpunkte, bewertet sie aber nicht. Sie hält den Grundbefund fest: die Lehrpläne der 16 Länder liegen überwiegend als PDF vor und sind damit nicht maschinenlesbar.

**Bewertung für Kurspilot:** Es gibt heute erstmals eine ernstzunehmende, aktiv gepflegte, ländervollständige Lehrplan-Ontologie — aber als OWL/SPARQL-Infrastruktur, die *ausdrücklich* keine Verknüpfung zu Materialien modelliert. Für ein Frontmatter heißt das: **Kompetenz-/Lehrplanbezug heute als Freitext führen, mit einem optionalen URI-Slot**, der später mit `lp-land-NI-*`-Konzepten befüllt werden kann. Ein Vokabularzwang wäre verfrüht.

---

## 2. Feldvergleich

Zeilen = fachliches Konzept. **Fett** = Pflichtfeld im jeweiligen Profil.

| Konzept | AMB | LRMI / schema.org | IEEE LOM | WLO / edu-sharing | SODIX / MUNDO |
|---|---|---|---|---|---|
| Titel | **`name`** | `name` / `headline` | 1.2 General.Title | `lom.general.title` | **`title`** |
| Beschreibung | `description` | `description` | 1.4 General.Description | `lom.general.description` | `description` |
| Identifier / URL | **`id`** (URI) | `url`, `identifier` | 1.1 General.Identifier | `sourceId`, `uuid` | **`id`**, **`identifier`** |
| Typ der Ressource (Datenmodell) | **`type`** | `@type` | — | — | — |
| Schlagworte | `keywords` | `keywords` | 1.5 General.Keyword | `lom.general.keyword` | `keywords` |
| Fach | `about` (SKOS) | `about` | 9 Classification | `valuespaces.discipline` | `subject` |
| Bildungsstufe | `educationalLevel` (SKOS) | `educationalLevel` | 5.6 Context | `valuespaces.educationalContext` | `educationalLevels` |
| Jahrgang / Schulform | *(nicht abgebildet)* | `typicalAgeRange` | 5.7 TypicalAgeRange | `educationalContext` (vermischt) | `classLevel`, `schoolTypes` |
| Ressourcentyp | `learningResourceType` (SKOS) | `learningResourceType` | 5.2 LearningResourceType | `learningResourceType`, `new_lrt` | **`learnResourceType`** |
| Lizenz | `license` (URI, eingeschränkt) | `license` | 6.3 Rights.Description | `license.url`, `oer` | `license` |
| Urheber | `creator`, `contributor` | `creator`, `author` | 2.3 Contribute | `lom.lifecycle` (role/organization) | `author`, `publishers` |
| Sprache | `inLanguage` | `inLanguage` | 1.3 General.Language | `lom.general.language` | `language` |
| Daten (erstellt/geändert) | `dateCreated`, `dateModified`, `datePublished` | dito | 2.1 Version, 2.3.3 Date | `lastModified` | `creationDate`, `updated`, `publishedTime` |
| Zielgruppenrolle | `audience` (SKOS) | `audience`, `educationalRole` | 5.5 IntendedEndUserRole | `intendedEndUserRole` | `targetAudience` |
| Kompetenzen / Lehrplanbezug | `teaches`, `assesses`, `competencyRequired` | `teaches`, `assesses`, `educationalAlignment` | 9 Classification (purpose = educational objective) | `classification.taxonPath` | `competencies`, `eafCode` |
| Zeitbedarf | `duration` | `timeRequired` | 5.9 TypicalLearningTime | `typicalLearningTime` | — |
| Teil-von / Reihe | `isPartOf`, `hasPart` | `isPartOf`, `hasPart` | 7 Relation | `collection` | `parentMedia`, `childMedia` |
| Abgeleitet von | `isBasedOn` | `isBasedOnUrl` | 7 Relation (isbasedon) | — | `linkedObjects` |

### Der gemeinsame Nenner

**Neun Felder tragen alle fünf Profile:**

1. Titel
2. Beschreibung
3. Fach
4. Bildungsstufe / Jahrgang
5. Schlagworte
6. Ressourcentyp
7. Lizenz
8. Urheber
9. Sprache

Dazu kommen zwei, die jedes Profil hat, aber lokal anders funktionieren: **Identifier** und **Datumsangaben**.

Bemerkenswerte Lücke: **kein einziges Profil bildet „Jahrgangsstufe 7" sauber ab**, außer SODIX mit `classLevel`. AMBs `educationalLevel` ist ISCED-basiert und kennt nur grobe Stufen — `level_2` = „Sekundarbereich I" umfasst Jahrgang 5–10 ([educationalLevel.ttl](https://github.com/dini-ag-kim/educationalLevel/blob/main/educationalLevel.ttl)). Für Kurspilot, wo `<klasse>` ohnehin Ordnerebene ist, muss der Jahrgang ein eigenes Feld sein.

---

## 3. Passt einer der Standards auf ein Kurspilot-Unterrichtsvorhaben?

**Als Format: nein. Als Feldvokabular: AMB, klar.**

Drei Bruchstellen, warum AMB *als Ganzes* nicht auf eine Handvoll Markdown-Dateien im Ordner einer Lehrkraft passt:

1. **`id` muss eine auflösbare URI sein.** Ein Vorhaben unter `local-context/2026-27/7a/Naturwissenschaften/zellen/` hat keine. Ein UUID- oder `urn:`-Ersatz wäre schema-technisch zulässig (`format: uri`), aber semantisch falsch und würde die Auslieferungspflicht trotzdem verletzen.
2. **Auslieferung ist Teil der Spec, nicht optional.** Die Spec schreibt MUSS-Regeln für `<script type="application/ld+json">` im HTML-`head` bzw. `Content-Type: application/ld+json` plus `Link: rel="describedby"`. Es gibt keinen konformen „Datei liegt im Ordner"-Modus.
3. **SKOS-URIs statt Klartext.** AMB-konformes Fach ist `{"type":"Concept","id":"https://w3id.org/kim/schulfaecher/s1001","prefLabel":{"de":"Biologie"}}` — nicht `fach: Biologie`. Das ist in JSON-LD richtig und in handgepflegtem Frontmatter, das ohne Kurspilot lesbar bleiben soll (Anforderung 4 aus #246: Codex, Zed, reiner Texteditor), unzumutbar.

Umgekehrt spricht viel für AMB als **Namensgeber**:

- Nur vier Pflichtfelder — die Latte liegt bewusst niedrig, ein Kurspilot-Profil bricht nichts, wenn es Optionales weglässt.
- Feldnamen sind schema.org-Namen, also selbsterklärend und dokumentiert.
- AMB ist das einzige deutsche Profil, das Schule *und* Hochschule adressiert und aktiv weiterentwickelt wird.

**Was kein Standard abdeckt und wofür Kurspilot zwingend eigene Felder braucht:**

- **Modus 2 der Karte #246** (Lerngruppe übergeben, mit Schülerdaten und Verlauf). Alle fünf untersuchten Profile beschreiben *Material*, keines beschreibt Lerngruppen, Verlauf oder „was wurde wann mit wem gemacht". Hier gibt es nichts zu übernehmen — und das ist auch gut so, weil AMB-konforme Metadaten qua Bauart für Veröffentlichung gedacht sind.
- **Ableitungsketten** (dieselbe Reihe in mehreren Jahren). `isBasedOn` (AMB) bzw. `isBasedOnUrl` (LRMI) ist der richtige Name, funktioniert aber URI-basiert. Lokal braucht es eine Pfad-/ID-Variante.
- **Jahrgangsstufe.** Siehe oben.
- **Moodle-Bezug** (Kurs-ID, Abschnitt). Naturgemäß außerhalb jedes Metadatenstandards.

---

## 4. Anschlussfähigkeit: passt ein Kurspilot-Paket ohne Zusatzarbeit in ein OER-Portal?

**„Ohne Zusatzarbeit": nein. „Mit einem mechanischen Exportschritt": ja, sofern die Feldnamen stimmen.**

Was fehlt, wenn eine Kurspilot-Vorhabensdatei bei einem Portal landen soll:

| Anforderung | Portal | Von Kurspilot lokal lieferbar? |
|---|---|---|
| Auflösbare URL der Ressource | OERSI („accessible on the web"), AMB `id` | **Nein** — entsteht erst beim Hochladen |
| Offene Lizenz | OERSI: „openly licensed – at least in part"; AMB: nur CC/GNU/Apache/MIT/BSD | Ja, wenn die Lehrkraft eine wählt — muss aber Pflichtfeld sein, sonst blockiert es später |
| Fach als SKOS-Konzept | AMB `about`, WLO `discipline` | Nur mit Mapping-Tabelle Klartext → `w3id.org/kim/schulfaecher/*` |
| Bildungsstufe als SKOS-Konzept | AMB `educationalLevel` (Pattern-erzwungen) | Nur mit Mapping Jahrgang → ISCED-Stufe (trivial: 5–10 → `level_2`, 11–13 → `level_3`) |
| Ressourcentyp | AMB, SODIX (dort Pflicht) | Mapping Klartext → `w3id.org/openeduhub/vocabs/new_lrt/*` |
| Keine personenbezogenen Daten | implizit | **Muss aktiv erzwungen werden** — Kurspilot-Kontext enthält laut ADR 0003 echte Schülernamen |

Realistischer Weg: Kurspilot-Frontmatter mit AMB-Feldnamen und Klartextwerten → ein `export --amb`-Schritt, der (a) `id` aus der Zielurl setzt, (b) drei Mapping-Tabellen anwendet, (c) `type: ["LearningResource"]` und `@context` ergänzt, (d) alles Personenbezogene abweist. Das ist überschaubarer Code, aber es *ist* Arbeit — und es ist erst dann Arbeit wert, wenn es einen konkreten Zielort gibt.

Zwei ernüchternde Befunde zum Zielort:

- **ELIXIER** (die Landesbildungsserver-Schiene) ist über eine partnergebundene, nicht publizierte XML-Schnittstelle organisiert. Kein Weg für eine einzelne Lehrkraft oder ein kleines Werkzeug.
- **SODIX/MUNDO** aggregiert aus definierten Quellen (Rundfunk, Verlage, OER-Anbieter) — die API ist eine *Abruf*-API für Länder und berechtigte Drittsysteme, kein Einreichungsweg.

Der einzige realistisch offene Kanal ist **WirLernenOnline/OERSI**, und beide wollen AMB. Das stützt die Empfehlung.

---

## 5. Empfehlung

**Teilmenge übernehmen — AMB-Feldnamen in YAML-Frontmatter, keine AMB-Konformität behaupten.**

Begründung in einem Satz: AMBs *Vokabular* ist der beste verfügbare deutsche Konsens für Schulmaterial, AMBs *Bauart* (URI-Pflicht, HTTP-Auslieferung, SKOS-Werte) setzt eine Veröffentlichung voraus, die ein lokaler Ordner per Definition nicht hat.

### Vorschlag: minimales Kurspilot-Frontmatter (10 Felder)

```yaml
---
title: Zellen und Zellteilung          # AMB name              — Pflicht
description: Sechs Doppelstunden ...   # AMB description
about: Biologie                        # AMB about            — Klartext, Mapping → w3id.org/kim/schulfaecher
educationalLevel: Sek I                # AMB educationalLevel — Mapping → w3id.org/kim/educationalLevel
gradeLevel: 7                          # KEIN AMB-Feld (vgl. SODIX classLevel) — Pflicht im Schulkontext
learningResourceType: Unterrichtsreihe # AMB learningResourceType — Mapping → openeduhub new_lrt
keywords: [Zelle, Mikroskopie, Mitose] # AMB keywords
license: CC-BY-SA-4.0                  # AMB license          — Pflicht, sonst nie teilbar
creator: M. Grünwald                   # AMB creator
inLanguage: de                         # AMB inLanguage
dateCreated: 2026-08-06                # AMB dateCreated
dateModified: 2026-08-06               # AMB dateModified
---
```

Regeln dazu:

- **Feldnamen exakt wie AMB**, wo AMB ein Feld hat. Kein `fach:`, kein `stufe:`. Das kostet nichts und macht den späteren Export zu einer Umbenennungs-freien Übung.
- **Werte als Klartext**, nicht als SKOS-Objekte. Die URI-Auflösung ist Aufgabe des Exports, nicht der Lehrkraft.
- **Pflicht sind nur vier:** `title`, `about`, `gradeLevel`, `license`. Alles andere ist Kür. (AMB selbst kommt mit vier Pflichtfeldern aus — mehr zu verlangen wäre schlechter als der Standard.)
- **`license` ist bewusst Pflicht**, obwohl AMB es optional führt: ohne Lizenz ist ein Vorhaben in Modus 1 („Material teilen") nicht weitergebbar, und die Entscheidung nachträglich einzuholen ist teurer als sie einmal beim Anlegen zu treffen.
- **Kurspilot-eigene Felder in klar getrenntem Namensraum**, damit der AMB-Teil sauber bleibt. Kandidaten aus #246: Ableitungskette (`isBasedOn` lokal als Pfad/ID), Moodle-Kursbezug, Verlaufsdatei, Lerngruppenprofil. Vorschlag: Präfix `kurspilot_` oder ein verschachtelter Block `kurspilot:`.
- **Lehrplanbezug heute als Freitext** mit optionalem URI-Slot. Die FWU-Lehrplan-Ontologie deckt alle 16 Länder ab und wird gepflegt, modelliert aber ausdrücklich keine Materialverknüpfung. Auf ein Vokabular festlegen wäre verfrüht; den Platz freihalten ist billig.

### Abgrenzung, die dokumentiert gehört

Wenn diese Empfehlung in eine Spec wandert, sollte dort ein Satz stehen wie: *„Das Kurspilot-Frontmatter ist an AMB angelehnt, aber nicht AMB-konform — es fehlen `@context`, `type` und ein auflösbares `id`, und Werte kontrollierter Vokabulare stehen als Klartext. Konformität entsteht erst beim Export in ein Portal."* Das verhindert, dass jemand später AMB-Konformität annimmt, die nicht existiert.

### Was nicht übernommen werden sollte

- **IEEE LOM / LOM-DE / HS-OER-LOM** — kostenpflichtig bzw. XML-schwer, in der deutschen Schul-OER-Praxis nur noch Infrastruktur unter der Haube.
- **ELIXIER** — Spec nicht öffentlich, Zugang partnergebunden.
- **Vollständige SKOS-URIs im Frontmatter** — bricht die Werkzeugunabhängigkeit aus #246 (lesbar in Zed/Texteditor) für einen Nutzen, der erst beim Export anfällt.
- **Eigene Feldnamen erfinden**, wo AMB welche hat. Das ist die einzige Stelle, an der „eigenes Profil" wirklich Kosten ohne Gegenwert erzeugt.

---

## Quellenverzeichnis

**Spezifikationen**
- AMB 20231019 (Spec): <https://dini-ag-kim.github.io/amb/20231019/>
- AMB JSON Schema: <https://dini-ag-kim.github.io/amb/20231019/schemas/schema.json>
- AMB Repo, Draft und Implementierungsliste: <https://github.com/dini-ag-kim/amb>
- LRMI Terms 2022-06-14 (DCMI): <https://www.dublincore.org/specifications/lrmi/lrmi_terms/2022-06-14/>
- schema.org `LearningResource`: <https://schema.org/LearningResource>
- IEEE 1484.12.1-2020: <https://standards.ieee.org/ieee/1484.12.1/7699/>
- HS-OER-LOM (KIM): <https://dini-ag-kim.github.io/hs-oer-lom-profil/>

**Vokabulare**
- KIM Schulfächer: <https://github.com/dini-ag-kim/schulfaecher>
- KIM Bildungsstufen: <https://github.com/dini-ag-kim/educationalLevel>
- KIM KMK-Vokabulare (Entwurf): <https://github.com/dini-ag-kim/kmk-vocabs>
- OpenEduHub Vokabulare: <https://github.com/openeduhub/oeh-metadata-vocabs>
- FWU Lehrplan-Ontologie: <https://github.com/FWU-DE/lehrplan-ontologie> · Doku: <https://fwu-de.github.io/lehrplan-ontologie/>
- FWU Schulfach-Ontologie: <https://github.com/FWU-DE/schulfach-ontologie>

**Portale**
- OERSI FAQ (AMB-Bezug): <https://oersi.org/resources/pages/de/faq/> · Aufnahmekriterien: <https://oersi.org/resources/pages/de/docs/add-resources/criteria/> · Schema: <https://gitlab.com/oersi/oersi-schema>
- WLO/OpenEduHub Crawler-Datenmodell: <https://github.com/openeduhub/oeh-search-etl/blob/master/converter/items.py>
- WLO LOM-Applikationsprofil (unveröffentlichter Entwurf): <https://github.com/openeduhub/oeh-metadata-specs>
- edu-sharing 9.0, Metadaten-/Content-Austausch: <https://docs.edu-sharing.com/en/edu-sharing-documentation/9.0/metadaten-content-austausch-mit-externen-servern>
- SODIX-API `Metadata`: <https://github.com/FWU-DE/bmi-docs/blob/main/docs/sodixapi/types/objects/metadata.mdx>
- MUNDO-LTI in Moodle: <https://github.com/FWU-DE/bmi-docs/blob/main/docs/mundo/intro.md>
- ELIXIER (DIPF-Projektseite): <https://www.dipf.de/en/research/projects/elixier> · Portal: <https://www.bildungsserver.de/elixier/>
- Serlo Metadata-API (AMB-Beispiel): <https://github.com/serlo/documentation/wiki/Metadata-API>

**Projektdokumentation**
- JOINTLY, Maschinenlesbare Lehrpläne: <https://jointly.eduloop.de/loop/Maschinenlesbare_Lehrpl%C3%A4ne>
- JOINTLY, Kompetenzdarstellungen: <https://jointly.eduloop.de/loop/Kompetenzdarstellungen>
- AG OER-Repositorien, Metadatenstandards: <https://www.oer-repo-ag.de/metadatenstandards/>
