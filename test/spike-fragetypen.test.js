const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.join(__dirname, '..');
const referenceFile = 'skills/spike-fragetypen.md';
const providerRoots = ['.agents/skills', '.claude/skills', '.opencode/skills'];
const skillNames = ['spike-planen', 'spike-umsetzen'];

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

// Der Pfad ist relativ zur Kontextwurzel, deren Name variabel ist (Ortswahl
// beim Verbindungsaufbau, #446) - ein vorangestelltes "kurspilot/" waere
// genau der Fehler, den die Referenzdatei verbietet.
test('Fragetyp-Ablage: fester Pfad fragetypen/<fragetyp>.md ist relativ zur Wurzel dokumentiert', () => {
  const reference = read(referenceFile);
  assert.match(reference, /^fragetypen\/<fragetyp>\.md$/m);
  assert.doesNotMatch(reference, /kurspilot\/fragetypen\//);
});

test('Fragetyp-Ablage: alle sechs spike-planen/spike-umsetzen-Adapter verweisen auf die Referenzdatei', () => {
  for (const providerRoot of providerRoots) {
    for (const skillName of skillNames) {
      const markdown = read(path.join(providerRoot, skillName, 'SKILL.md'));
      assert.match(
        markdown,
        /spike-fragetypen\.md/,
        `${skillName} (${providerRoot}): verweist nicht auf skills/spike-fragetypen.md`
      );
    }
  }
});

test('Fragetyp-Ablage: verbindliche Gliederung ist vollstaendig benannt', () => {
  const reference = read(referenceFile);

  assert.match(reference, /Kopf/);
  assert.match(reference, /Moodle-Version/);
  assert.match(reference, /Plugin-Version/);
  assert.match(reference, /zuletzt verifiziert am/);
  assert.match(reference, /Minimal-Beispiel/);
  assert.match(reference, /Pflichtstruktur/);
  assert.match(reference, /Stolpersteine/);
  assert.match(reference, /Symptom.*Ursache.*Abhilfe/);
  assert.match(reference, /Ausbaustufen/);
});

test('Fragetyp-Ablage: geschrieben mit write_context_file samt expected_contenthash, nicht append_context_file', () => {
  const reference = read(referenceFile);

  assert.match(reference, /coursepilot_write_context_file/);
  assert.match(reference, /expected_contenthash/);
  assert.match(reference, /nicht[\s\S]{0,40}coursepilot_append_context_file/);
});

test('Fragetyp-Ablage: geschrieben nur auf Schreibangebot, nie automatisch', () => {
  const reference = read(referenceFile);

  assert.match(reference, /Schreibangebot/);
  assert.match(reference, /nie automatisch/);
});

test('Lernschleife: Ablage lesen, Bestand ueber export_questions_xml durchsuchen, hoechstens drei Versuche, Vorlage anfordern', () => {
  const reference = read(referenceFile);

  assert.match(reference, /export_questions_xml/);
  assert.match(reference, /(?:hoechstens|höchstens)\s+drei\s+Versuche/i);
  assert.match(reference, /Vorlage/);
});

test('Lernschleife: Transparenzpflicht ist dokumentiert', () => {
  const reference = read(referenceFile);

  assert.match(reference, /Transparenzpflicht/);
  assert.match(reference, /was fehlschlug/);
});

test('Lernschleife: Widerspruchspruefung ist dokumentiert, neues Wissen wird eingeordnet statt angehaengt', () => {
  const reference = read(referenceFile);

  assert.match(reference, /Widerspruch/);
  assert.match(reference, /Ursachenvermutung/);
  assert.match(reference, /eingeordnet/);
  assert.match(reference, /nicht\s+ans\s+Dateiende\s+angehaengt/);
});

test('Weitergabe: Zip-Download aus Meine Dateien bzw. Filepicker-Reiter Server files, kein Kurspilot-Endpunkt', () => {
  const reference = read(referenceFile);

  assert.match(reference, /Meine Dateien/);
  assert.match(reference, /Server files/);
  assert.match(reference, /kein[\s\S]{0,20}Kurspilot-Endpunkt/i);
});
