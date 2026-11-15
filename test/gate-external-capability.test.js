'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { findExternalGaps, checkExternalCapabilities } = require('../scripts/gate/capability');
const { FAST_CHECKS } = require('../scripts/gate/static');

const P = 'Plugin/src/local_coursepilot/classes/external/';
const tool = (body, extra = '') => ({
  file: `${P}demo_tool.php`,
  content: `<?php\nclass demo_tool extends \\core_external\\external_api {\n    public static function execute(): array {\n${body}\n    }\n${extra}}\n`,
});
const RESOLVERS = [{ call: 'bank_context::resolve', validates: true, requires: true, reason: 'Testfixture' }];
const reason = 'Testfixture';
const texts = found => found.map(f => f.text);

test('tool without any check is red for context and capability, naming class and missing call', () => {
  const found = findExternalGaps([tool('return [];')], RESOLVERS);
  assert.deepEqual(texts(found), [
    'Klasse demo_tool: validate_context() fehlt in execute()',
    'Klasse demo_tool: require_capability()/has_capability() fehlt in execute()',
  ]);
  assert.equal(found[0].rule, 'external-check-missing');
  assert.equal(found[0].line, 3);
});

test('direct validate_context and require_capability are green', () => {
  const body = "        self::validate_context($c);\n        require_capability('x/y:z', $c);";
  assert.deepEqual(findExternalGaps([tool(body)], RESOLVERS), []);
  assert.deepEqual(findExternalGaps([tool('self::validate_context($c); if (has_capability(\'a\', $c)) {}')], RESOLVERS), []);
});

test('listed resolver is green', () => {
  assert.deepEqual(findExternalGaps([tool('[$a, $b] = bank_context::resolve(1, 2);')], RESOLVERS), []);
});

test('resolver that is not listed is red', () => {
  const found = findExternalGaps([tool('[$a, $b] = other_context::resolve(1, 2);')], RESOLVERS);
  assert.equal(found.length, 2);
});

test('resolver that proves only one of the two checks leaves the other red', () => {
  const only = [{ call: 'cap_only::check', requires: true, reason }];
  assert.deepEqual(texts(findExternalGaps([tool('cap_only::check();')], only)), ['Klasse demo_tool: validate_context() fehlt in execute()']);
});

test('calls in comments and strings do not count', () => {
  const body = "        // validate_context($c); require_capability('a', $c);\n        $s = 'has_capability(';\n        /* bank_context::resolve() */";
  assert.equal(findExternalGaps([tool(body)], RESOLVERS).length, 2);
});

test('checks in a same-class helper called from execute count, in an uncalled method they do not', () => {
  const helper = "    private static function guard($c) { self::validate_context($c); require_capability('a', $c); }\n";
  assert.deepEqual(findExternalGaps([tool('self::guard($c);', helper)], RESOLVERS), []);
  assert.equal(findExternalGaps([tool('return [];', helper)], RESOLVERS).length, 2);
});

test('ignore entry with reason silences exactly the named gap; without reason it is red', () => {
  const t = tool('self::validate_context($c);');
  assert.deepEqual(findExternalGaps([t], RESOLVERS, [{ file: t.file, missing: 'capability', reason }]), []);
  const wrongKind = findExternalGaps([t], RESOLVERS, [{ file: t.file, missing: 'context', reason }]);
  assert.deepEqual(wrongKind.map(f => f.rule).sort(), ['external-check-missing', 'external-ignore-invalid']);
  const bad = findExternalGaps([t], RESOLVERS, [{ file: t.file, missing: 'capability', reason: ' ' }]);
  assert.deepEqual(bad.map(f => f.rule).sort(), ['external-check-missing', 'external-ignore-invalid']);
});

test('stale ignore entry (check is present) is red', () => {
  const t = tool("self::validate_context($c); require_capability('a', $c);");
  const found = findExternalGaps([t], RESOLVERS, [{ file: t.file, missing: 'capability', reason }]);
  assert.deepEqual(found.map(f => f.rule), ['external-ignore-invalid']);
});

test('invalid resolver entry is red; class without execute() is red', () => {
  const found = findExternalGaps([{ file: `${P}x.php`, content: '<?php\nclass x {}\n' }], [{ call: 'a::b' }]);
  assert.deepEqual(found.map(f => f.rule).sort(), ['external-check-missing', 'external-ignore-invalid']);
});

test('external check runs in fast mode', () => {
  assert.ok(FAST_CHECKS.includes('external'));
});

test('contract: shipped external functions all check context and capability', () => {
  const found = checkExternalCapabilities(path.join(__dirname, '..'));
  assert.deepEqual(found.map(f => `${f.file}:${f.line}: ${f.text}`), []);
});
