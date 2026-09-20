'use strict';

/**
 * Issue #559: "Ordner anlegen" muss state.lastResult sofort auf ein leeres
 * Ergebnis fuer den neuen Pfad zuruecksetzen, sonst zeigt "Ordner auswaehlen"
 * direkt danach die Uebergabe-Warnung (Issue #497) fuer den *alten*
 * Browse-Stand der Elternebene an, statt den (leeren) neuen Ordner zu sehen.
 *
 * ortswahl.js ist ein reines Browser-Skript ohne Module-Exports (IIFE gegen
 * document/window/fetch). Es gibt in diesem Plugin keine jsdom-Abhaengigkeit
 * (siehe package.json) - dieser Test baut daher den denkbar kleinsten
 * DOM/fetch-Stub, der nur die Pfade abdeckt, die das Skript tatsaechlich
 * anfasst (kein allgemeines DOM, kein Rendering-Tree).
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');

const SCRIPT_PATH = path.join(__dirname, '..', 'Plugin', 'src', 'local_coursepilot', 'javascript', 'ortswahl.js');
const SCRIPT_SOURCE = fs.readFileSync(SCRIPT_PATH, 'utf8');

function makeElement(id) {
  var el = {
    id: id,
    value: '',
    textContent: '',
    innerHTML: '',
    className: '',
    disabled: false,
    hidden: false,
    style: {},
    title: '',
    _attrs: {},
    _listeners: {},
    addEventListener: function (type, fn) {
      el._listeners[type] = el._listeners[type] || [];
      el._listeners[type].push(fn);
    },
    dispatch: function (type) {
      (el._listeners[type] || []).forEach(function (fn) {
        fn({ preventDefault: function () {} });
      });
    },
    appendChild: function () {},
    querySelectorAll: function () {
      return [];
    },
    setAttribute: function (k, v) {
      el._attrs[k] = v;
    },
    getAttribute: function (k) {
      return el._attrs[k];
    },
  };
  return el;
}

// Baut eine frische DOM/fetch-Umgebung und laedt ortswahl.js darin neu (das
// Skript ist eine bei jedem require() sofort ausgefuehrte IIFE - eigener
// Zustand pro Test braucht daher einen frischen vm-Kontext statt
// require()+Cache-Invalidierung).
function loadScript(config, fetchResponses) {
  var elements = {};
  ['coursepilot-ortswahl-progress', 'coursepilot-ortswahl-overlaplock', 'coursepilot-ortswahl-finish',
    'coursepilot-ortswahl-modal', 'coursepilot-ortswahl-modal-title', 'coursepilot-ortswahl-instances',
    'coursepilot-ortswahl-breadcrumb', 'coursepilot-ortswahl-folders', 'coursepilot-ortswahl-modal-reason',
    'coursepilot-ortswahl-confirmfolder', 'coursepilot-ortswahl-createfolder', 'coursepilot-ortswahl-newfolder',
    'coursepilot-ortswahl-confirm-modal', 'coursepilot-ortswahl-confirm-count', 'coursepilot-ortswahl-confirmfolder-ack',
    'coursepilot-ortswahl-form'
  ].forEach(function (id) {
    elements[id] = makeElement(id);
  });
  ['kontextbereich', 'materialbestand'].forEach(function (target) {
    ['_type', '_instanceid', '_path', '_confirmed'].forEach(function (suffix) {
      var id = 'coursepilot-ortswahl-' + target + suffix;
      elements[id] = makeElement(id);
    });
  });

  var dataEl = makeElement('coursepilot-ortswahl-data');
  dataEl.textContent = JSON.stringify(config);
  elements['coursepilot-ortswahl-data'] = dataEl;

  var pickerButtons = ['kontextbereich', 'materialbestand'].map(function (target) {
    var btn = makeElement('open-picker-' + target);
    btn._attrs['data-target'] = target;
    return btn;
  });
  var keepMoodleButtons = ['kontextbereich', 'materialbestand'].map(function (target) {
    var btn = makeElement('keep-moodle-' + target);
    btn._attrs['data-target'] = target;
    return btn;
  });

  var fakeDocument = {
    getElementById: function (id) {
      return elements[id] || null;
    },
    querySelectorAll: function (selector) {
      if (selector === '[data-action="open-picker"]') {
        return pickerButtons;
      }
      if (selector === '[data-action="keep-moodle"]') {
        return keepMoodleButtons;
      }
      return [];
    },
    createElement: function () {
      return makeElement(null);
    }
  };

  var fetchCallCount = 0;
  var fakeFetch = function () {
    var response = fetchResponses[fetchCallCount] || fetchResponses[fetchResponses.length - 1];
    fetchCallCount += 1;
    return Promise.resolve({
      json: function () {
        return Promise.resolve(response);
      }
    });
  };

  var sandbox = {
    document: fakeDocument,
    window: { bootstrap: undefined },
    fetch: fakeFetch,
    AbortController: undefined,
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    console: console
  };

  var vm = require('node:vm');
  vm.createContext(sandbox);
  vm.runInContext(SCRIPT_SOURCE, sandbox, { filename: SCRIPT_PATH });

  return { elements: elements, pickerButtons: pickerButtons };
}

function baseConfig() {
  return {
    targets: {
      kontextbereich: { chosen: false, ort: null, instanzid: null, pfad: '', display: '' },
      materialbestand: { chosen: false, ort: null, instanzid: null, pfad: '', display: '' }
    },
    strings: {
      tabkontextbereich: 'Kontextbereich',
      tabmaterialbestand: 'Materialbestand',
      progresschosen: '%s gewaehlt',
      progressopen: '%s offen',
      overlaplocked: 'Ueberlappung',
      chooseinstance: 'Instanz waehlen',
      breadcrumbroot: 'Wurzel',
      loading: 'Laedt...',
      retry: 'Erneut',
      checkcredentials: 'Zugang pruefen',
      later: 'Spaeter',
      timeouttitle: 'Zeitueberschreitung',
      timeouttext: 'Zeitueberschreitung-Text',
      browseerrorheading: 'Fehler',
      confirmcount: '%s Eintraege',
      selectionincomplete: 'Bitte beide Ziele waehlen'
    },
    instances: [{ id: 1, name: 'Instanz1', selectable: true }],
    sesskey: 'sess123',
    browseurl: 'http://test.example/browse.php',
    manageinstancesurl: 'http://test.example/manage.php',
    timeoutms: 8000
  };
}

async function flushPromises() {
  await new Promise(function (resolve) {
    setTimeout(resolve, 0);
  });
  await new Promise(function (resolve) {
    setTimeout(resolve, 0);
  });
}

test('Ordner anlegen setzt state.lastResult sofort auf ein leeres Ergebnis (Kriterium 1)', async function () {
  var parentBrowseResult = { ok: true, path: '', folders: [{ name: 'material' }], selectable: true, reason: '', entrycount: 1, entrynames: ['material'] };
  var ctx = loadScript(baseConfig(), [parentBrowseResult]);

  // Fenster fuer "kontextbereich" oeffnen -> browse() der Elternebene laeuft.
  ctx.pickerButtons[0].dispatch('click');
  await flushPromises();

  // Neuen Unterordner anlegen.
  ctx.elements['coursepilot-ortswahl-newfolder'].value = 'Kontext';
  ctx.elements['coursepilot-ortswahl-createfolder'].dispatch('click');

  // "Ordner auswaehlen" direkt danach darf KEINE Uebergabe-Warnung anzeigen
  // (Kriterium 2): das confirm-Modal darf nicht sichtbar geschaltet werden,
  // und die Auswahl muss sofort (unbestaetigt) uebernommen werden.
  ctx.elements['coursepilot-ortswahl-confirmfolder'].dispatch('click');

  assert.notStrictEqual(ctx.elements['coursepilot-ortswahl-confirm-modal'].style.display, 'block');
  assert.strictEqual(ctx.elements['coursepilot-ortswahl-kontextbereich_path'].value, 'Kontext');
  assert.strictEqual(ctx.elements['coursepilot-ortswahl-kontextbereich_confirmed'].value, '');
});

test('Ordner auswaehlen mit echtem Elternebenen-Inhalt zeigt weiterhin die Uebergabe-Warnung (Regression, Kriterium 3)', async function () {
  var parentBrowseResult = { ok: true, path: '', folders: [{ name: 'material' }], selectable: true, reason: '', entrycount: 1, entrynames: ['material'] };
  var ctx = loadScript(baseConfig(), [parentBrowseResult]);

  ctx.pickerButtons[0].dispatch('click');
  await flushPromises();

  // Kein "Ordner anlegen" - direkt "Ordner auswaehlen" auf der Elternebene
  // mit echtem, von browse() geliefertem Inhalt.
  ctx.elements['coursepilot-ortswahl-confirmfolder'].dispatch('click');

  assert.strictEqual(ctx.elements['coursepilot-ortswahl-confirm-modal'].style.display, 'block');
  // Die Auswahl darf noch NICHT uebernommen sein - erst nach Bestaetigung.
  assert.strictEqual(ctx.elements['coursepilot-ortswahl-kontextbereich_path'].value, '');
});
