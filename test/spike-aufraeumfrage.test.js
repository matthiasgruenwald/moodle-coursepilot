const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.join(__dirname, '..');
const referenceFile = 'skills/spike-kontextbereich.md';
const providerRoots = ['.agents/skills', '.claude/skills', '.opencode/skills'];

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('Aufraeumfrage: Bericht-Werkzeug wird vor der Frage aufgerufen', () => {
  const reference = read(referenceFile);
  assert.match(reference, /coursepilot_report_loose_material_files/);
});

test('Aufraeumfrage: keine Frage, wenn keine losen Dateien vorliegen', () => {
  const reference = read(referenceFile);
  assert.match(reference, /`files` ist leer:\*\*\s*keine Frage/);
});

test('Aufraeumfrage: die Frage nennt Anzahl, Gesamtgroesse und die einzelnen Dateien', () => {
  const reference = read(referenceFile);
  assert.match(reference, /Anzahl/);
  assert.match(reference, /Gesamtgroesse/);
  assert.match(reference, /jede einzelne Datei mit Pfad und\s*\n?\s*Groesse/);
});

test('Aufraeumfrage: geloescht wird nur auf ausdrueckliche Antwort, per delete_material_files', () => {
  const reference = read(referenceFile);
  assert.match(reference, /ausschliesslich auf ausdrueckliche Antwort/);
  assert.match(reference, /coursepilot_delete_material_files/);
  assert.match(reference, /nie automatisch/);
});

test('Aufraeumfrage: bei knapper Quote wird der Restplatz genannt', () => {
  const reference = read(referenceFile);
  assert.match(reference, /remaining_quota_mb/);
  assert.match(reference, /knapp[\s\S]{0,80}Restplatz/);
});

test('Aufraeumfrage: Regel ist Skill-, kein Serververhalten, gilt fuer jeden Adapter (Codex wie Claude Desktop)', () => {
  const reference = read(referenceFile);
  assert.match(reference, /Skill-Regel, kein Serververhalten/);
  assert.match(reference, /Claude Desktop wie Codex/);
});

test('Aufraeumfrage: alle drei spike-umsetzen-Adapter verweisen auf die Referenzdatei', () => {
  for (const providerRoot of providerRoots) {
    const markdown = read(path.join(providerRoot, 'spike-umsetzen', 'SKILL.md'));
    assert.match(
      markdown,
      /Aufraeumfrage nach\s*\n?\s*Aufbau/,
      `spike-umsetzen (${providerRoot}): verweist nicht auf die Aufraeumfrage in spike-kontextbereich.md`
    );
  }
});
