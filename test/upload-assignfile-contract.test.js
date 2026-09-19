const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const UPLOAD_ASSIGNFILE_PATH = path.join(
  __dirname,
  '..',
  'legacy',
  'local_coursepilot',
  'classes',
  'external',
  'upload_assignfile.php'
);

test('upload_assignfile nutzt das Moodle-5.0-Dateischema ohne assign-Tabellenspalten', () => {
  const source = fs.readFileSync(UPLOAD_ASSIGNFILE_PATH, 'utf8');

  assert.doesNotMatch(source, /\$assign->introattachments/);
  assert.doesNotMatch(source, /set_field\(\s*'assign',\s*'introattachments/);
  assert.match(source, /fileupload_helper::create_file\(\s*\$context->id,\s*'mod_assign',\s*'introattachment',\s*0,/);
});

test('upload_assignfile determines the MIME type from decoded file content via fileupload_helper', () => {
  const source = fs.readFileSync(UPLOAD_ASSIGNFILE_PATH, 'utf8');

  assert.match(source, /fileupload_helper::decode_and_validate\(\$params\['content'\]\)/);
  assert.match(source, /fileupload_helper::create_file\(/);
  assert.match(source, /\$detectedmimetype/);

  const helperPath = path.join(
    __dirname,
    '..',
    'legacy',
    'local_coursepilot',
    'classes',
    'fileupload_helper.php'
  );
  const helperSource = fs.readFileSync(helperPath, 'utf8');
  assert.match(helperSource, /finfo_buffer\(new \\finfo\(FILEINFO_MIME_TYPE\), \$filedata\)/);
});
