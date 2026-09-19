'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

test('frozen assignment settings reject a real change but accept a no-op', () => {
  const moodleRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'coursepilot-grading-'));
  fs.mkdirSync(path.join(moodleRoot, 'grade', 'grading'), { recursive: true });
  fs.writeFileSync(path.join(moodleRoot, 'grade', 'grading', 'lib.php'), '<?php class grading_manager {}');
  const source = path.join(__dirname, '..', 'legacy', 'local_coursepilot', 'classes', 'assign_settings.php');
  const script = `
    class invalid_parameter_exception extends Exception {}
    define('MOODLE_INTERNAL', true);
    $CFG = (object) ['dirroot' => ${JSON.stringify(moodleRoot)}];
    class fake_db {
      public function record_exists($table, $conditions) {
        return in_array($table, ['assign_submission', 'assign_grades'], true);
      }
    }
    $DB = new fake_db();
    require ${JSON.stringify(source)};
    $module = (object) [
      'id' => 7, 'teamsubmission' => 1, 'requireallteammemberssubmit' => 0,
      'teamsubmissiongroupingid' => 0, 'blindmarking' => 0,
      'advancedgradingmethod_submissions' => 'none',
    ];
    local_coursepilot\\assign_settings::validate_frozen_core_changes($module, ['teamsubmission' => 1]);
    try {
      local_coursepilot\\assign_settings::validate_frozen_core_changes($module, ['teamsubmission' => 0]);
      exit(2);
    } catch (invalid_parameter_exception $e) {
      if (strpos($e->getMessage(), 'eingefroren') === false) exit(3);
    }
  `;
  assert.doesNotThrow(() => execFileSync('php', ['-r', script], { stdio: 'pipe' }));
});
