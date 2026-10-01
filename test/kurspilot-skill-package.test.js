const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.join(__dirname, '..');
const skillNames = ['kurspilot', 'kurspilot-einrichten', 'kurspilot-planen', 'kurspilot-umsetzen'];
const providerRoots = ['.agents/skills', '.claude/skills'];

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function frontmatter(markdown) {
  const match = markdown.match(/^---\n([\s\S]*?)\n---/);
  assert.ok(match, 'skill has YAML frontmatter');

  return Object.fromEntries(
    match[1]
      .split('\n')
      .map((line) => line.match(/^([^:]+):\s*(.*)$/))
      .filter(Boolean)
      .map((match) => [match[1].trim(), match[2].trim().replace(/^"|"$/g, '')])
  );
}

test('Kurspilot skill adapters exist for Codex and Claude with teacher-facing names', () => {
  for (const providerRoot of providerRoots) {
    for (const skillName of skillNames) {
      const relativePath = path.join(providerRoot, skillName, 'SKILL.md');
      const markdown = read(relativePath);
      const metadata = frontmatter(markdown);

      assert.equal(metadata.name, skillName);
      assert.match(metadata.description, /Kurspilot/);
      assert.doesNotMatch(metadata.description, new RegExp(skillName));
      assert.match(markdown, /skills\/kurspilot-core\.md/);
    }
  }
});

// Nur in Anfuehrungszeichen stehende Formulierungen zaehlen als Trigger. So faellt
// z.B. das blosse Fliesstext-Wort "freigegeben" am Satzende nicht faelschlich als
// Trigger-Treffer durch, nur weil es zufaellig in einem bekannten Trigger vorkommt.
function quotedPhrases(text) {
  return [...text.matchAll(/"([^"]+)"/g)].map((match) => match[1]);
}

const knownTeacherTriggers = [
  'Setze meine Planung fuer 7a Nawi fort.',
  'Mach mit Bio weiter.',
  'Richte mir den Moodle-Zugang fuer meine 7a in Naturwissenschaften ein.',
  'Ich will Kurspilot zum ersten Mal fuer meine Klasse nutzen.',
  'Baue in Kurs 42 die Unterrichtseinheit zum Thema Stromkreise auf.',
  'Plane den Abschnitt fuer ...',
  'Erstelle mir einen Implementierungsplan fuer',
  'Zeig mir den ganzen Text der Infoseite',
  'ja, so umsetzen',
  'Plan ist gut, leg los',
  'freigegeben',
];

test('Kurspilot adapter descriptions lead with a Leitbegriff, use real teacher trigger phrases, and match across providers', () => {
  for (const skillName of skillNames) {
    const descriptions = providerRoots.map(
      (providerRoot) => frontmatter(read(path.join(providerRoot, skillName, 'SKILL.md'))).description
    );

    assert.equal(
      descriptions[0],
      descriptions[1],
      `${skillName}: Codex- und Claude-Adapter-Description muessen inhaltsgleich sein`
    );

    const description = descriptions[0];
    assert.match(description, /^[A-ZÄÖÜ][\wÄÖÜäöüß-]*[.:]/, `${skillName}: Description beginnt nicht mit einem Leitbegriff`);

    const quotedTriggers = quotedPhrases(description);
    const usedTriggers = quotedTriggers.filter((quoted) =>
      knownTeacherTriggers.some((trigger) => quoted === trigger || quoted.startsWith(trigger))
    );
    assert.ok(usedTriggers.length > 0, `${skillName}: Description enthaelt keine bekannte Lehrkraft-Startformulierung`);
    assert.equal(
      usedTriggers.length,
      new Set(usedTriggers).size,
      `${skillName}: Trigger duerfen nicht doppelt vorkommen`
    );
  }
});

test('Kurspilot-planen deckt geplant/geprueft/freigegeben mit je genau einem Trigger ab (Issue #167)', () => {
  const triggersByUseCase = {
    geplant: [
      'Plane den Abschnitt fuer ...',
      'Erstelle mir einen Implementierungsplan fuer',
      'Baue in Kurs 42 die Unterrichtseinheit zum Thema Stromkreise auf.',
    ],
    geprueft: ['Zeig mir den ganzen Text der Infoseite'],
    freigegeben: ['ja, so umsetzen', 'Plan ist gut, leg los', 'freigegeben'],
  };

  const description = frontmatter(read(path.join('.claude/skills', 'kurspilot-planen', 'SKILL.md'))).description;
  const quotedTriggers = quotedPhrases(description);

  for (const [useCase, candidateTriggers] of Object.entries(triggersByUseCase)) {
    const matches = quotedTriggers.filter((quoted) => candidateTriggers.includes(quoted));
    assert.equal(
      matches.length,
      1,
      `kurspilot-planen: Anwendungsfall "${useCase}" braucht genau einen Trigger, gefunden: ${JSON.stringify(matches)}`
    );
  }

  // Alle drei Anwendungsfaelle zusammen duerfen keine Ueberschneidung/Dublette ergeben.
  const allUseCaseTriggers = quotedTriggers.filter((quoted) =>
    Object.values(triggersByUseCase).flat().includes(quoted)
  );
  assert.equal(
    allUseCaseTriggers.length,
    new Set(allUseCaseTriggers).size,
    'kurspilot-planen: Trigger duerfen nicht ueber mehrere Anwendungsfaelle hinweg doppelt vorkommen'
  );
});

test('Kurspilot core documents routing modes and package boundary, without deployment knowledge', () => {
  const core = read('skills/kurspilot-core.md');
  const readme = read('README.md');

  for (const skillName of skillNames) {
    assert.match(core, new RegExp(`\\\`${skillName}\\\``));
  }

  assert.match(core, /Kanonischer Kurspilot-Kern/);
  assert.match(core, /Anbieter-Adapter/);
  assert.match(core, /vier V1-Skills aus der\s+Paketgrenze/);
  assert.match(core, /Materialklaerung als Teil von `kurspilot-planen`/);

  // Deployment-/Paketwissen (MCP-Server-Setup, Token-Ablage, Zusatztool)
  // gehoert in die Repo-Dokumentation, nicht in den Kern.
  assert.doesNotMatch(core, /MCP-Server-Konfiguration/);
  assert.doesNotMatch(core, /Moodle-Token als lokales Geheimnis/);
  assert.match(core, /README\.md/);

  assert.match(readme, /MCP-Server/);
  assert.match(readme, /Moodle-Token/);
  assert.match(readme, /ImageMagick/);
  assert.match(readme, /#5/);
});

test('Kurspilot core keeps planning in the main session and delegates Moodle writes after approval', () => {
  const core = read('skills/kurspilot-core.md');

  assert.match(core, /Hauptsession/);
  assert.match(core, /Schreibzugriffe/);
  assert.match(core, /delegiert/);
  assert.match(core, /freigegebenen Auftrag/);
  assert.match(core, /unveraendert in Moodle/);
  assert.match(core, /Status\/Journal/);
  assert.match(core, /Vorschau\/Freigabe/);
  assert.match(core, /Tests sind\s+Sicherheitsgurte/);
});

test('Kurspilot core makes Werkzeugluecken explizit und fordert manuelle Moodle-Schritte an', () => {
  const core = read('skills/kurspilot-core.md');

  assert.match(core, /Werkzeugluecke/);
  assert.match(core, /Aktivitaetsregister/);
  assert.match(core, /manuelle Moodle-Schritte|Moodle-Oberflaeche/);
  assert.match(core, /Aktivitaet oder Material anlegen/);
  assert.match(core, /statt zu verschweigen|nicht stillschweigend/);
});

test('Kurspilot entry establishes one adaptive local context permission handoff', () => {
  const core = read('skills/kurspilot-core.md');

  assert.match(core, /Kontextfreigabe/);
  assert.match(core, /einmal pro\s+Arbeitssitzung/);
  assert.match(core, /Unterrichtsvorhaben/);
  assert.match(core, /Unterrichtsordner/);
  assert.match(core, /Lerngruppenprofil/);
  assert.match(core, /relevante Elternkontexte/);
  assert.match(core, /Schreiben bleibt enger/);
  assert.match(core, /Moodle-Schreibfreigabe bleibt getrennt/);
});

test('Kurspilot package docs enforce Planstrenge and remove legacy automatic extras', () => {
  const core = read('skills/kurspilot-core.md');
  const readme = read('README.md');
  const workflow = read('skills/implementierungsplan-workflow.md');
  const htmlVorlagen = read('skills/html-vorlagen.md');

  assert.match(core, /Planstrenge/);
  assert.match(core, /keine ungefragten Extras/);
  assert.match(core, /neue sichtbare Elemente, Aktivitaeten, Materialien, Dateien, Bewertungen oder Kurslogik/);
  assert.match(core, /Planoption benannt oder rueckgefragt/);

  for (const providerRoot of providerRoots) {
    for (const skillName of skillNames) {
      const markdown = read(path.join(providerRoot, skillName, 'SKILL.md'));

      assert.match(markdown, /Planstrenge/);
      assert.doesNotMatch(markdown, /Halte dabei Planstrenge ein und fuege keine ungefragten Extras hinzu\./);
    }
  }

  assert.match(readme, /Planstrenge/);
  assert.doesNotMatch(readme, /Aufgaben mit PDF-Druckbutton/);

  assert.match(workflow, /Planstrenge/);
  assert.match(htmlVorlagen, /Planstrenge/);
  assert.doesNotMatch(htmlVorlagen, /Aufgabe anlegen \(mit PDF-Button\)/);
  assert.doesNotMatch(htmlVorlagen, /PFLICHT: Jede Aufgabe bekommt PDF-Banner oben und Abgabe-Hinweis unten\./);
});

test('Kurspilot adapters reference Planstrenge and Arbeitsbereich-Regel as named anchor terms only, without restating the rule', () => {
  const restatedRulePatterns = [
    /keine\s+ungefragten\s+Extras/,
    /nur\s+als\s+Planoption/,
    /gespeicherten?\s+Arbeitsbereich-Einstellung/,
    /Konfigurationsprogramm/,
  ];

  for (const providerRoot of providerRoots) {
    for (const skillName of skillNames) {
      const markdown = read(path.join(providerRoot, skillName, 'SKILL.md'));

      assert.match(markdown, /Planstrenge/, `${skillName} (${providerRoot}): referenziert Planstrenge nicht`);
      assert.match(
        markdown,
        /Arbeitsbereich-Regel/,
        `${skillName} (${providerRoot}): referenziert Arbeitsbereich-Regel nicht`
      );

      for (const pattern of restatedRulePatterns) {
        assert.doesNotMatch(
          markdown,
          pattern,
          `${skillName} (${providerRoot}): formuliert die Regel aus, statt nur den Begriff zu referenzieren (${pattern})`
        );
      }
    }
  }
});

test('Kurspilot core defines Planstrenge and Arbeitsbereich-Regel exactly once as named anchor terms', () => {
  const core = read('skills/kurspilot-core.md');

  assert.match(core, /## Ankerbegriffe/);

  const planstrengeHeadings = core.match(/^### Planstrenge$/gm) || [];
  const arbeitsbereichHeadings = core.match(/^### Arbeitsbereich-Regel$/gm) || [];

  assert.equal(planstrengeHeadings.length, 1, 'Planstrenge muss genau einmal als Ankerbegriff definiert sein');
  assert.equal(
    arbeitsbereichHeadings.length,
    1,
    'Arbeitsbereich-Regel muss genau einmal als Ankerbegriff definiert sein'
  );

  // Die ausformulierte Planstrenge-Regel ("keine ungefragten Extras ...") darf nur an einer
  // Stelle im Kern stehen; ueberall sonst wird nur der Begriff referenziert.
  const fullPlanstrengeRestatements = core.match(/keine ungefragten Extras/g) || [];
  assert.equal(fullPlanstrengeRestatements.length, 1, 'Planstrenge-Volltext darf nur einmal im Kern vorkommen');
});

test('Kurspilot core defines Ein-Plan-Regel and Statuspruefung vor Schreibzugriff exactly once as named anchor terms (Issue #170)', () => {
  const core = read('skills/kurspilot-core.md');

  const einPlanHeadings = core.match(/^### Ein-Plan-Regel$/gm) || [];
  const statuspruefungHeadings = core.match(/^### Statuspruefung vor Schreibzugriff$/gm) || [];

  assert.equal(einPlanHeadings.length, 1, 'Ein-Plan-Regel muss genau einmal als Ankerbegriff definiert sein');
  assert.equal(
    statuspruefungHeadings.length,
    1,
    'Statuspruefung vor Schreibzugriff muss genau einmal als Ankerbegriff definiert sein'
  );

  // Referenzdateiliste bleibt inhaltlich erhalten (Issue #170), Situationen aus der
  // frueher in kurspilot-umsetzen ausformulierten 9-Punkte-Liste bleiben im Kern auffindbar.
  assert.match(core, /html-vorlagen\.md/);
  assert.match(core, /interaktive-elemente\.md/);
  assert.match(core, /zeichen-canvas\.md/);
  assert.match(core, /grafiken\.md/);
  assert.match(core, /svg-qualitaetssicherung\.md/);
  assert.match(core, /technische-hinweise\.md/);
  assert.match(core, /abschlussverfolgung\.md/);
  assert.match(core, /arbeitsblaetter\.md/);
  assert.match(core, /journal\.md/);
  assert.match(core, /mcp-tools\.md/);
});

test('kurspilot-planen and kurspilot-umsetzen adapters reference Ein-Plan-Regel and Statuspruefung as named anchor terms only, without restating the rule (Issue #170)', () => {
  const restatedPatterns = [
    /halte die Ein-Plan-Regel ein und setze/i,
    /Pruefe `status\.md` vor jedem\s+Moodle-Schreibzugriff; bei `in_planung`/,
  ];

  for (const providerRoot of providerRoots) {
    const planen = read(path.join(providerRoot, 'kurspilot-planen', 'SKILL.md'));
    const umsetzen = read(path.join(providerRoot, 'kurspilot-umsetzen', 'SKILL.md'));

    assert.match(planen, /Ein-Plan-Regel/, `kurspilot-planen (${providerRoot}): referenziert Ein-Plan-Regel nicht`);
    assert.match(
      umsetzen,
      /Statuspruefung vor\s+Schreibzugriff/,
      `kurspilot-umsetzen (${providerRoot}): referenziert Statuspruefung nicht`
    );

    for (const pattern of restatedPatterns) {
      assert.doesNotMatch(planen, pattern, `kurspilot-planen (${providerRoot}): formuliert Regel aus (${pattern})`);
      assert.doesNotMatch(umsetzen, pattern, `kurspilot-umsetzen (${providerRoot}): formuliert Regel aus (${pattern})`);
    }

    // Die 9-Punkte-Aktivitaetsliste wird nicht mehr im Adapter dupliziert.
    assert.doesNotMatch(umsetzen, /Moodle-MCP-Tool nachschlagen:/);
    assert.doesNotMatch(umsetzen, /Eingabefelder, Checkboxen oder Tabellen einbauen:/);
  }
});

test('Kurspilot package docs keep Allgemeines fachlich and out of process storage, and integration examples avoid section 0 defaults', () => {
  const core = read('skills/kurspilot-core.md');
  const readme = read('README.md');
  const workflow = read('skills/implementierungsplan-workflow.md');
  const htmlVorlagen = read('skills/html-vorlagen.md');
  const integrationFiles = [
    'test/integration/create_quiz.integration.test.js',
    'test/integration/quiz-modes.integration.test.js',
    'test/integration/quiz-completion-restriction.integration.test.js',
    'test/integration/add-questions-to-quiz.integration.test.js',
  ];

  assert.match(core, /Abschnitt 0/i);
  assert.match(core, /Allgemeines/i);
  assert.match(core, /normaler fachlicher/i);
  assert.match(core, /Kursabschnitt/i);
  assert.match(core, /Prozessdaten[\s\S]*Kurspilot-Arbeitsbereich/i);

  assert.match(readme, /Abschnitt 0/i);
  assert.match(readme, /Allgemeines/i);
  assert.match(readme, /normaler fachlicher/i);
  assert.match(readme, /Kursabschnitt/i);
  // README traegt den neuen Produktnamen (ADR 0024); skills/kurspilot-core.md
  // gehoert zum lokalen Altweg und behaelt den alten.
  assert.match(readme, /Prozessdaten[\s\S]*Coursepilot-Arbeitsbereich/i);
  assert.doesNotMatch(readme, /local-context\//);

  assert.match(workflow, /Abschnitt 0/i);
  assert.match(workflow, /Allgemeines/i);
  assert.match(workflow, /normaler fachlicher/i);
  assert.match(workflow, /Kursabschnitt/i);
  assert.match(workflow, /nicht automatisch|kein automatischer Default/i);
  assert.doesNotMatch(workflow, /Freien Abschnitt waehlen\./);
  assert.doesNotMatch(htmlVorlagen, /Freien Abschnitt waehlen\./);

  for (const relativePath of integrationFiles) {
    const integrationTest = read(relativePath);
    assert.doesNotMatch(integrationTest, /sectionnum:\s*0/);
  }
});

test('Kurspilot package docs define KURSPILOT.md as Wegweiser to Startkontext only', () => {
  const core = read('skills/kurspilot-core.md');
  const readme = read('README.md');
  const context = read('CONTEXT.md');
  const skill = read('skills/kontext-onboarding.md');

  for (const markdown of [core, readme, context, skill]) {
    assert.match(markdown, /`KURSPILOT\.md`/);
    assert.match(markdown, /Wegweiser/);
    assert.match(markdown, /Startkontext/);
    assert.match(markdown, /nicht[\s\S]*Index[\s\S]*Kind|kein[\s\S]*Index[\s\S]*Kind/i);
    assert.match(markdown, /`plan\.md`[\s\S]*`status\.md`[\s\S]*Journal[\s\S]*Materialnotizen[\s\S]*nicht[\s\S]*Materialordner/i);
  }

  assert.match(core, /bestehenden\s+lokalen\s+Kurspilot-Kontext[\s\S]*vor Planung oder Umsetzung/i);
});

test('README documents fresh-session setup for both skill providers and MCP prerequisites', () => {
  const readme = read('README.md');

  assert.match(readme, /Fuer Lehrkraefte ist \*\*Coursepilot\*\* der sichtbare Name der Skill-Familie/);
  assert.match(readme, /`kurspilot`:/);
  assert.match(readme, /`kurspilot-einrichten`:/);
  assert.match(readme, /`kurspilot-planen`:/);
  assert.match(readme, /`kurspilot-umsetzen`:/);
  assert.match(readme, /kein separates `kurspilot-fortsetzen`/);
  assert.match(readme, /kein separates\s+`kurspilot-materialien`/);
  assert.match(readme, /\.agents\/skills/);
  assert.match(readme, /\.claude\/skills/);
  assert.match(readme, /neuen Codex-Thread/);
  assert.match(readme, /Claude Code neu starten/);
  assert.match(readme, /MCP-Server/);
  assert.match(readme, /Moodle-Token/);
  assert.match(readme, /ImageMagick/);
  assert.match(readme, /#5/);
});

test('Kontext-Onboarding beschreibt Einrichten als nummerierte Schritte mit prueffbarem Abschlusskriterium pro Schritt (Issue #161)', () => {
  const onboarding = read('skills/kontext-onboarding.md');

  assert.match(onboarding, /## Setup-Ablauf/);

  const stepHeadings = onboarding.match(/^### Schritt \d+:/gm) || [];
  assert.ok(stepHeadings.length >= 5, 'Setup-Ablauf braucht mindestens 5 nummerierte Schritte');

  const abschlusskriterien = onboarding.match(/\*\*Abschlusskriterium:\*\*/g) || [];
  assert.equal(
    abschlusskriterien.length,
    stepHeadings.length,
    'Jeder Setup-Schritt braucht genau ein Abschlusskriterium'
  );

  // Bestehende Regeln werden in den Schritten integriert statt doppelt danebengestellt.
  assert.match(onboarding, /Vorschau[\s\S]*Bestaetigung/i);
  assert.match(onboarding, /Setup-Abschlussweiche/);
  assert.match(onboarding, /fertig(?:,| ist)[\s\S]{0,40}Abschlussweiche\s+angeboten wurde/i);
});

test('Implementierungsplan-Workflow beschreibt Planen mit erschoepfendem Abschlusskriterium (Issue #161)', () => {
  const workflow = read('skills/implementierungsplan-workflow.md');

  assert.match(workflow, /#### Schritt 1: Plan aufbauen/);
  assert.match(workflow, /#### Schritt 2: Kurzuebersicht zeigen/);
  assert.match(workflow, /#### Schritt 3: Volltext nur auf Nachfrage/);
  assert.match(workflow, /#### Schritt 4: Freigabe abwarten/);

  const stepHeadings = workflow.match(/^#### Schritt \d+:.*(?:aufbauen|Kurzuebersicht|Nachfrage|Freigabe abwarten)/gm) || [];
  const abschlusskriterien = workflow.match(/\*\*Abschlusskriterium:\*\*/g) || [];
  assert.ok(abschlusskriterien.length >= stepHeadings.length, 'Jeder Planen-Schritt braucht ein Abschlusskriterium');

  // Erschoepfendes Gesamt-Abschlusskriterium: jeder Auftragspunkt als Planelement oder Werkzeugluecke.
  assert.match(workflow, /jeder Punkt des\s+Lehrkraftauftrags/i);
  assert.match(workflow, /Planelement/i);
  assert.match(workflow, /Werkzeugluecke/i);

  // Bestehende Regeln referenziert statt dupliziert.
  assert.match(workflow, /Ein-Plan-Regel/);
  assert.match(workflow, /Status-gesteuerte Planfreigabe/);
});

test('Implementierungsplan-Workflow referenziert eine echte Ueberschrift und dupliziert die Werkzeugluecken-Regel nicht (Issue #171)', () => {
  const workflow = read('skills/implementierungsplan-workflow.md');
  const core = read('skills/kurspilot-core.md');

  // AC1: die referenzierte Ueberschrift existiert tatsaechlich in kurspilot-core.md.
  const referencedHeading = 'Werkzeugluecken bei Aktivitaeten';
  assert.match(workflow, new RegExp(referencedHeading.replace(/\s+/g, '\\s+')));
  assert.match(core, new RegExp(`^###\\s+${referencedHeading}$`, 'm'));

  // AC2: die Auftragspunkt=Planelement-oder-Werkzeugluecke-Regel steht nur einmal ausformuliert;
  // Schritt 1 referenziert das Gesamt-Abschlusskriterium statt es zu wiederholen.
  const fullRestatements = workflow.match(/jeder Punkt des\s+Lehrkraftauftrags\s+entweder/gi) || [];
  assert.equal(
    fullRestatements.length,
    1,
    'Die Auftragspunkt=Planelement-oder-Werkzeugluecke-Regel darf nur einmal ausformuliert stehen'
  );
});

test('Kurspilot skillset has no reference left to the retired legacy Langfassung (Issue #160)', () => {
  assert.ok(
    !fs.existsSync(path.join(repoRoot, 'SKILL.md')),
    'Repo-Root SKILL.md (historische Langfassung) wurde entfernt und auf Referenzdateien unter skills/ verteilt'
  );

  const filesToCheck = [
    'skills/kurspilot-core.md',
    'README.md',
    'CONTEXT.md',
    ...providerRoots.flatMap((providerRoot) => skillNames.map((skillName) => path.join(providerRoot, skillName, 'SKILL.md'))),
  ];

  for (const relativePath of filesToCheck) {
    const markdown = read(relativePath);
    assert.doesNotMatch(
      markdown,
      /historische Langfassung/i,
      `${relativePath}: verweist noch auf die historische Langfassung`
    );
    assert.doesNotMatch(
      markdown,
      /\.\.\/\.\.\/\.\.\/SKILL\.md/,
      `${relativePath}: verweist noch relativ auf die Repo-Root SKILL.md`
    );
    assert.doesNotMatch(
      markdown,
      /legacy-kurspilot/i,
      `${relativePath}: verweist noch auf legacy-kurspilot.md`
    );
  }
});
