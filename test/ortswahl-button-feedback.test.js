'use strict';

/**
 * Issue #562: "In Moodle lassen" gab bisher kein sichtbares Feedback nach
 * dem Klick - "Verbindung waehlen" bekommt eins ueber das sich oeffnende
 * Fenster, "In Moodle lassen" veraenderte visuell nichts (die Auswahl
 * wurde erst am fernen Fortschrittsband sichtbar). Der Knopf selbst muss
 * jetzt sofort einen "ausgewaehlt"-Zustand zeigen.
 *
 * Seit Issue #551 ist amd/src/ortswahl.js ein AMD-Modul; der gemeinsame
 * Test-Unterbau (DOM-/fetch-/AMD-Stub) liegt in
 * test/helpers/ortswahl-amd-test-utils.js.
 */

const {test} = require('node:test');
const assert = require('node:assert/strict');
const {loadOrtswahlModule, baseConfig, flushPromises} = require('./helpers/ortswahl-amd-test-utils');

test('"In Moodle lassen" markiert den eigenen Knopf sofort als ausgewaehlt', function() {
  var ctx = loadOrtswahlModule(baseConfig(), []);
  var btn = ctx.keepMoodleButtons[0];

  btn.dispatch('click');

  assert.match(btn.className, /\bactive\b/);
  var badge = btn.querySelector('.coursepilot-ortswahl-selected-badge');
  assert.ok(badge, 'Badge muss nach dem Klick vorhanden sein.');
  assert.strictEqual(badge.textContent, 'Ausgewaehlt');
});

test('"In Moodle lassen" laesst den Verbindungs-Knopf unmarkiert', function() {
  var ctx = loadOrtswahlModule(baseConfig(), []);
  ctx.keepMoodleButtons[0].dispatch('click');

  var pickerBtn = ctx.pickerButtons[0];
  assert.doesNotMatch(pickerBtn.className, /\bactive\b/);
  assert.strictEqual(pickerBtn.querySelector('.coursepilot-ortswahl-selected-badge'), null);
});

test('Ein externer Ordner markiert den Verbindungs-Knopf und entfernt die Markierung von "In Moodle lassen"', async function() {
  var browseResult = {ok: true, path: '', folders: [], selectable: true, reason: '', entrycount: 0, entrynames: []};
  var ctx = loadOrtswahlModule(baseConfig(), [browseResult]);

  // Zuerst "In Moodle lassen" - markiert, dann per externer Ordnerwahl
  // wieder umentschieden (Kriterium: die Markierung folgt der aktuellen
  // Auswahl, nicht dem zuletzt geklickten Knopf).
  ctx.keepMoodleButtons[0].dispatch('click');

  ctx.pickerButtons[0].dispatch('click');
  await flushPromises();
  ctx.elements['coursepilot-ortswahl-confirmfolder'].dispatch('click');

  var keepBtn = ctx.keepMoodleButtons[0];
  var pickerBtn = ctx.pickerButtons[0];
  assert.doesNotMatch(keepBtn.className, /\bactive\b/, '"In Moodle lassen" darf nach dem Ortswechsel nicht mehr markiert sein.');
  assert.strictEqual(keepBtn.querySelector('.coursepilot-ortswahl-selected-badge'), null);
  assert.match(pickerBtn.className, /\bactive\b/);
  assert.ok(pickerBtn.querySelector('.coursepilot-ortswahl-selected-badge'));
});
