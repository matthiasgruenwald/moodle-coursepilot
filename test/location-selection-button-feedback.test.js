'use strict';

/**
 * Issue #562: "In Moodle lassen" gab bisher kein sichtbares Feedback nach
 * dem Klick - "Verbindung waehlen" bekommt eins ueber das sich oeffnende
 * Fenster, "In Moodle lassen" veraenderte visuell nichts (die Auswahl
 * wurde erst am fernen Fortschrittsband sichtbar). Der Knopf selbst muss
 * jetzt sofort einen "ausgewaehlt"-Zustand zeigen.
 *
 * Seit Issue #551 ist amd/src/location_selection.js ein AMD-Modul; der gemeinsame
 * Test-Unterbau (DOM-/fetch-/AMD-Stub) liegt in
 * test/helpers/location-selection-amd-test-utils.js.
 */

const {test} = require('node:test');
const assert = require('node:assert/strict');
const {loadLocationSelectionModule, baseConfig, flushPromises} = require('./helpers/location-selection-amd-test-utils');

test('"Keep in Moodle" immediately marks its own button as selected', async function() {
  var ctx = loadLocationSelectionModule(baseConfig(), []);
  await ctx.ready;
  var btn = ctx.keepMoodleButtons[0];

  btn.dispatch('click');

  assert.match(btn.className, /\bactive\b/);
  var badge = btn.querySelector('.coursepilot-location-selection-selected-badge');
  assert.ok(badge, 'Badge muss nach dem Klick vorhanden sein.');
  assert.strictEqual(badge.textContent, 'Ausgewaehlt');
});

test('"Keep in Moodle" leaves the connection button unselected', async function() {
  var ctx = loadLocationSelectionModule(baseConfig(), []);
  await ctx.ready;
  ctx.keepMoodleButtons[0].dispatch('click');

  var pickerBtn = ctx.pickerButtons[0];
  assert.doesNotMatch(pickerBtn.className, /\bactive\b/);
  assert.strictEqual(pickerBtn.querySelector('.coursepilot-location-selection-selected-badge'), null);
});

test('An external folder selects the connection button and clears "Keep in Moodle"', async function() {
  var browseResult = {ok: true, path: '', folders: [], selectable: true, reason: '', entrycount: 0, entrynames: []};
  var ctx = loadLocationSelectionModule(baseConfig(), [browseResult]);
  await ctx.ready;

  // Zuerst "In Moodle lassen" - markiert, dann per externer Ordnerwahl
  // wieder umentschieden (Kriterium: die Markierung folgt der aktuellen
  // Auswahl, nicht dem zuletzt geklickten Knopf).
  ctx.keepMoodleButtons[0].dispatch('click');

  ctx.pickerButtons[0].dispatch('click');
  await flushPromises();
  ctx.elements['coursepilot-location-selection-confirmfolder'].dispatch('click');

  var keepBtn = ctx.keepMoodleButtons[0];
  var pickerBtn = ctx.pickerButtons[0];
  assert.doesNotMatch(keepBtn.className, /\bactive\b/, '"In Moodle lassen" darf nach dem Ortswechsel nicht mehr markiert sein.');
  assert.strictEqual(keepBtn.querySelector('.coursepilot-location-selection-selected-badge'), null);
  assert.match(pickerBtn.className, /\bactive\b/);
  assert.ok(pickerBtn.querySelector('.coursepilot-location-selection-selected-badge'));
});
