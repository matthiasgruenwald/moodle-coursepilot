'use strict';

/**
 * Issue #562: "In Moodle lassen" gab bisher kein sichtbares Feedback nach
 * dem Klick - "Verbindung waehlen" bekommt eins ueber das sich oeffnende
 * Fenster, "In Moodle lassen" veraenderte visuell nichts (die Auswahl
 * wurde erst am fernen Fortschrittsband sichtbar). Der Knopf selbst muss
 * jetzt sofort einen "ausgewaehlt"-Zustand zeigen.
 *
 * Gleicher minimaler DOM/fetch-Stub wie
 * ortswahl-createfolder-lastresult.test.js (kein jsdom im Plugin), hier um
 * classList/querySelector/remove() erweitert, weil das Feature genau die
 * anfasst.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');

const SCRIPT_PATH = path.join(__dirname, '..', 'Plugin', 'src', 'local_coursepilot', 'javascript', 'ortswahl.js');
const SCRIPT_SOURCE = fs.readFileSync(SCRIPT_PATH, 'utf8');

function makeElement(id) {
  var children = [];
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
    _children: children,
    _removed: false,
    addEventListener: function (type, fn) {
      el._listeners[type] = el._listeners[type] || [];
      el._listeners[type].push(fn);
    },
    dispatch: function (type) {
      (el._listeners[type] || []).forEach(function (fn) {
        fn({ preventDefault: function () {} });
      });
    },
    appendChild: function (child) {
      children.push(child);
    },
    querySelectorAll: function () {
      return [];
    },
    querySelector: function (selector) {
      if (selector === '.coursepilot-ortswahl-selected-badge') {
        return children.filter(function (c) {
          return !c._removed && c.className.indexOf('coursepilot-ortswahl-selected-badge') !== -1;
        })[0] || null;
      }
      return null;
    },
    remove: function () {
      el._removed = true;
    },
    classList: {
      toggle: function (name, on) {
        var classes = el.className.split(' ').filter(Boolean);
        var has = classes.indexOf(name) !== -1;
        if (on && !has) {
          classes.push(name);
        } else if (!on && has) {
          classes = classes.filter(function (c) {
            return c !== name;
          });
        }
        el.className = classes.join(' ');
      }
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
    btn._attrs['data-action'] = 'open-picker';
    return btn;
  });
  var keepMoodleButtons = ['kontextbereich', 'materialbestand'].map(function (target) {
    var btn = makeElement('keep-moodle-' + target);
    btn._attrs['data-target'] = target;
    btn._attrs['data-action'] = 'keep-moodle';
    return btn;
  });

  function buttonFor(action, target) {
    var list = action === 'open-picker' ? pickerButtons : keepMoodleButtons;
    return list.filter(function (btn) {
      return btn._attrs['data-target'] === target;
    })[0] || null;
  }

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
    querySelector: function (selector) {
      var match = selector.match(/\[data-action="([^"]+)"\]\[data-target="([^"]+)"\]/);
      if (!match) {
        return null;
      }
      return buttonFor(match[1], match[2]);
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

  return { elements: elements, pickerButtons: pickerButtons, keepMoodleButtons: keepMoodleButtons };
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
      selectionincomplete: 'Bitte beide Ziele waehlen',
      selected: 'Ausgewaehlt'
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

test('"In Moodle lassen" markiert den eigenen Knopf sofort als ausgewaehlt', function () {
  var ctx = loadScript(baseConfig(), []);
  var btn = ctx.keepMoodleButtons[0];

  btn.dispatch('click');

  assert.match(btn.className, /\bactive\b/);
  var badge = btn.querySelector('.coursepilot-ortswahl-selected-badge');
  assert.ok(badge, 'Badge muss nach dem Klick vorhanden sein.');
  assert.strictEqual(badge.textContent, 'Ausgewaehlt');
});

test('"In Moodle lassen" laesst den Verbindungs-Knopf unmarkiert', function () {
  var ctx = loadScript(baseConfig(), []);
  ctx.keepMoodleButtons[0].dispatch('click');

  var pickerBtn = ctx.pickerButtons[0];
  assert.doesNotMatch(pickerBtn.className, /\bactive\b/);
  assert.strictEqual(pickerBtn.querySelector('.coursepilot-ortswahl-selected-badge'), null);
});

test('Ein externer Ordner markiert den Verbindungs-Knopf und entfernt die Markierung von "In Moodle lassen"', async function () {
  var browseResult = { ok: true, path: '', folders: [], selectable: true, reason: '', entrycount: 0, entrynames: [] };
  var ctx = loadScript(baseConfig(), [browseResult]);

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
