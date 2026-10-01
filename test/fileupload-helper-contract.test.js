'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const EXTERNAL_DIR = path.join(
  __dirname,
  '..',
  'legacy',
  'local_coursepilot',
  'classes',
  'external'
);
const HELPER_PATH = path.join(
  __dirname,
  '..',
  'legacy',
  'local_coursepilot',
  'classes',
  'fileupload_helper.php'
);

const UPLOAD_WEBSERVICES = [
  'upload_assign_intro_image.php',
  'upload_assignfile.php',
  'upload_folder_file.php',
  'upload_question_image.php',
];

test('fileupload_helper.php kapselt Base64-Decodierung, MIME-Pruefung, Groessenlimit und Delete-vor-Write', () => {
  assert.ok(fs.existsSync(HELPER_PATH), 'classes/fileupload_helper.php sollte existieren');

  const source = fs.readFileSync(HELPER_PATH, 'utf8');

  assert.match(source, /namespace local_coursepilot;/);
  assert.match(source, /class fileupload_helper/);

  // Base64-Decodierung mit strikter Validierung
  assert.match(source, /base64_decode\(\s*\$base64content,\s*true\s*\)/);
  assert.match(source, /invalid_parameter_exception/);

  // MIME-Pruefung anhand des tatsaechlichen Dateiinhalts
  assert.match(source, /finfo_buffer\(new \\finfo\(FILEINFO_MIME_TYPE\), \$filedata\)/);

  // Groessenlimit ist ein optionaler Parameter
  assert.match(source, /\?int \$maxbytes\s*=\s*null/);

  // Delete-vor-Write und Dateierzeugung
  assert.match(source, /function delete_existing/);
  assert.match(source, /function create_file/);
  assert.match(source, /create_file_from_string/);
});

for (const filename of UPLOAD_WEBSERVICES) {
  test(`${filename} nutzt fileupload_helper statt eigener Base64/MIME-Logik`, () => {
    const filePath = path.join(EXTERNAL_DIR, filename);
    const source = fs.readFileSync(filePath, 'utf8');

    assert.match(
      source,
      /fileupload_helper::decode_and_validate/,
      `${filename} sollte fileupload_helper::decode_and_validate() aufrufen`
    );
    assert.match(
      source,
      /fileupload_helper::delete_existing/,
      `${filename} sollte fileupload_helper::delete_existing() aufrufen`
    );
    assert.match(
      source,
      /fileupload_helper::create_file/,
      `${filename} sollte fileupload_helper::create_file() aufrufen`
    );

    // Der Base64/MIME-Kern soll nicht mehr dupliziert im Webservice stehen.
    assert.doesNotMatch(
      source,
      /base64_decode\(\s*\$params\['content'\],\s*true\s*\)/,
      `${filename} sollte Base64 nicht mehr selbst dekodieren`
    );
  });
}
