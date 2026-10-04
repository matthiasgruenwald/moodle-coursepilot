'use strict';

/**
 * Issue #559: "Create folder" must immediately reset state.lastResult to an empty
 * result for the new path, otherwise "Choose this folder" right afterwards shows the
 * handover warning (issue #497) for the *old* browse state of the parent level
 * instead of seeing the (empty) new folder.
 *
 * Since issue #551 amd/src/location_selection.js is an AMD module; the shared
 * test scaffolding (DOM/fetch/AMD stub) lives in
 * test/helpers/location-selection-amd-test-utils.js.
 */

const {test} = require('node:test');
const assert = require('node:assert/strict');
const {loadLocationSelectionModule, baseConfig, flushPromises} = require('./helpers/location-selection-amd-test-utils');

test('Creating a folder immediately resets state.lastResult to an empty result (criterion 1)', async function() {
  var parentBrowseResult = {ok: true, path: '', folders: [{name: 'material'}], selectable: true, reason: '', entrycount: 1, entrynames: ['material']};
  var ctx = loadLocationSelectionModule(baseConfig(), [parentBrowseResult]);
  await ctx.ready;

  // Open the window for "context_area" -> browse() of the parent level runs.
  ctx.pickerButtons[0].dispatch('click');
  await flushPromises();

  // Create a new subfolder.
  ctx.elements['coursepilot-location-selection-newfolder'].value = 'Kontext';
  ctx.elements['coursepilot-location-selection-createfolder'].dispatch('click');
  await flushPromises();

  // "Choose this folder" right afterwards must NOT show a handover warning
  // (criterion 2): the confirm modal must not be made visible,
  // and the selection must be adopted immediately (unconfirmed).
  ctx.elements['coursepilot-location-selection-confirmfolder'].dispatch('click');

  assert.notStrictEqual(ctx.elements['coursepilot-location-selection-confirm-modal'].style.display, 'block');
  assert.strictEqual(ctx.elements['coursepilot-location-selection-context_area_path'].value, 'Kontext');
  assert.strictEqual(ctx.elements['coursepilot-location-selection-context_area_confirmed'].value, '');
});

test('Selecting a folder with real parent content still shows the handover warning (regression, criterion 3)', async function() {
  var parentBrowseResult = {ok: true, path: '', folders: [{name: 'material'}], selectable: true, reason: '', entrycount: 1, entrynames: ['material']};
  var ctx = loadLocationSelectionModule(baseConfig(), [parentBrowseResult]);
  await ctx.ready;

  ctx.pickerButtons[0].dispatch('click');
  await flushPromises();

  // No "Create folder" - "Choose this folder" directly on the parent level
  // with real content delivered by browse().
  ctx.elements['coursepilot-location-selection-confirmfolder'].dispatch('click');

  assert.strictEqual(ctx.elements['coursepilot-location-selection-confirm-modal'].style.display, 'block');
  // The selection must NOT be adopted yet - only after confirmation.
  assert.strictEqual(ctx.elements['coursepilot-location-selection-context_area_path'].value, '');
});
