'use strict';

/**
 * Issue #559: "Ordner anlegen" muss state.lastResult sofort auf ein leeres
 * Ergebnis fuer den neuen Pfad zuruecksetzen, sonst zeigt "Ordner auswaehlen"
 * direkt danach die Uebergabe-Warnung (Issue #497) fuer den *alten*
 * Browse-Stand der Elternebene an, statt den (leeren) neuen Ordner zu sehen.
 *
 * Seit Issue #551 ist amd/src/ortswahl.js ein AMD-Modul; der gemeinsame
 * Test-Unterbau (DOM-/fetch-/AMD-Stub) liegt in
 * test/helpers/ortswahl-amd-test-utils.js.
 */

const {test} = require('node:test');
const assert = require('node:assert/strict');
const {loadOrtswahlModule, baseConfig, flushPromises} = require('./helpers/ortswahl-amd-test-utils');

test('Ordner anlegen setzt state.lastResult sofort auf ein leeres Ergebnis (Kriterium 1)', async function() {
  var parentBrowseResult = {ok: true, path: '', folders: [{name: 'material'}], selectable: true, reason: '', entrycount: 1, entrynames: ['material']};
  var ctx = loadOrtswahlModule(baseConfig(), [parentBrowseResult]);
  await ctx.ready;

  // Fenster fuer "kontextbereich" oeffnen -> browse() der Elternebene laeuft.
  ctx.pickerButtons[0].dispatch('click');
  await flushPromises();

  // Neuen Unterordner anlegen.
  ctx.elements['coursepilot-ortswahl-newfolder'].value = 'Kontext';
  ctx.elements['coursepilot-ortswahl-createfolder'].dispatch('click');
  await flushPromises();

  // "Ordner auswaehlen" direkt danach darf KEINE Uebergabe-Warnung anzeigen
  // (Kriterium 2): das confirm-Modal darf nicht sichtbar geschaltet werden,
  // und die Auswahl muss sofort (unbestaetigt) uebernommen werden.
  ctx.elements['coursepilot-ortswahl-confirmfolder'].dispatch('click');

  assert.notStrictEqual(ctx.elements['coursepilot-ortswahl-confirm-modal'].style.display, 'block');
  assert.strictEqual(ctx.elements['coursepilot-ortswahl-kontextbereich_path'].value, 'Kontext');
  assert.strictEqual(ctx.elements['coursepilot-ortswahl-kontextbereich_confirmed'].value, '');
});

test('Ordner auswaehlen mit echtem Elternebenen-Inhalt zeigt weiterhin die Uebergabe-Warnung (Regression, Kriterium 3)', async function() {
  var parentBrowseResult = {ok: true, path: '', folders: [{name: 'material'}], selectable: true, reason: '', entrycount: 1, entrynames: ['material']};
  var ctx = loadOrtswahlModule(baseConfig(), [parentBrowseResult]);
  await ctx.ready;

  ctx.pickerButtons[0].dispatch('click');
  await flushPromises();

  // Kein "Ordner anlegen" - direkt "Ordner auswaehlen" auf der Elternebene
  // mit echtem, von browse() geliefertem Inhalt.
  ctx.elements['coursepilot-ortswahl-confirmfolder'].dispatch('click');

  assert.strictEqual(ctx.elements['coursepilot-ortswahl-confirm-modal'].style.display, 'block');
  // Die Auswahl darf noch NICHT uebernommen sein - erst nach Bestaetigung.
  assert.strictEqual(ctx.elements['coursepilot-ortswahl-kontextbereich_path'].value, '');
});
