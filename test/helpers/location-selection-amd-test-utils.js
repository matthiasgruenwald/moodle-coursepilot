'use strict';

/**
 * Gemeinsamer Test-Unterbau fuer amd/src/location_selection.js (Issue #551): das Skript
 * ist seit der AMD-Umstellung ein define()-Modul ohne eigenes DOM (kein
 * jsdom in diesem Plugin, siehe package.json) - dieser Loader stellt daher
 * nur die eine Handvoll DOM-/fetch-/AMD-Aufrufe bereit, die das Modul
 * tatsaechlich anfasst (kein allgemeines DOM, kein echtes Mustache-Rendern).
 *
 * `core/templates` wird durch einen Fake ersetzt, dessen replaceNodeContents
 * nur den Platzhaltertext ablegt - die beiden Testdateien pruefen Knopf-
 * Zustand und versteckte Formularfelder, nie die gerenderte Teilansicht
 * selbst.
 */

const path = require('node:path');
const fs = require('node:fs');
const vm = require('node:vm');

const SCRIPT_PATH = path.join(__dirname, '..', '..', 'Plugin', 'src', 'local_coursepilot', 'amd', 'src', 'location_selection.js');
const SCRIPT_SOURCE = fs.readFileSync(SCRIPT_PATH, 'utf8');

const ELEMENT_IDS = [
  'coursepilot-location-selection-progress', 'coursepilot-location-selection-overlaplock', 'coursepilot-location-selection-finish',
  'coursepilot-location-selection-modal', 'coursepilot-location-selection-modal-title', 'coursepilot-location-selection-instances',
  'coursepilot-location-selection-breadcrumb', 'coursepilot-location-selection-folders', 'coursepilot-location-selection-modal-reason',
  'coursepilot-location-selection-confirmfolder', 'coursepilot-location-selection-createfolder', 'coursepilot-location-selection-newfolder',
  'coursepilot-location-selection-confirm-modal', 'coursepilot-location-selection-confirm-count', 'coursepilot-location-selection-confirmfolder-ack',
  'coursepilot-location-selection-form', 'coursepilot-location-selection'
];

/**
 * Builds one minimal stub DOM element.
 *
 * @param {string} id Element id.
 * @return {Object}
 */
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
    addEventListener: function(type, fn) {
      el._listeners[type] = el._listeners[type] || [];
      el._listeners[type].push(fn);
    },
    dispatch: function(type) {
      (el._listeners[type] || []).forEach(function(fn) {
        fn({preventDefault: function() {}});
      });
    },
    appendChild: function(child) {
      children.push(child);
    },
    querySelectorAll: function() {
      return [];
    },
    querySelector: function(selector) {
      if (selector === '.coursepilot-location-selection-selected-badge') {
        return children.filter(function(c) {
          return !c._removed && c.className.indexOf('coursepilot-location-selection-selected-badge') !== -1;
        })[0] || null;
      }
      return null;
    },
    remove: function() {
      el._removed = true;
    },
    classList: {
      toggle: function(name, on) {
        var classes = el.className.split(' ').filter(Boolean);
        var has = classes.indexOf(name) !== -1;
        if (on && !has) {
          classes.push(name);
        } else if (!on && has) {
          classes = classes.filter(function(c) {
            return c !== name;
          });
        }
        el.className = classes.join(' ');
      }
    },
    setAttribute: function(k, v) {
      el._attrs[k] = v;
    },
    getAttribute: function(k) {
      return el._attrs[k];
    }
  };
  return el;
}

/**
 * Loads amd/src/location_selection.js into a fresh vm context and calls its init()
 * with a stub DOM/fetch environment.
 *
 * @param {Object} config Config object passed to init().
 * @param {Array<Object>} fetchResponses Queue of decoded browse() JSON responses.
 * @return {Object} {elements, pickerButtons, keepMoodleButtons}
 */
function loadLocationSelectionModule(config, fetchResponses) {
  var elements = {};
  ELEMENT_IDS.forEach(function(id) {
    elements[id] = makeElement(id);
  });
  ['context_area', 'material_store'].forEach(function(target) {
    ['_type', '_instanceid', '_path', '_confirmed'].forEach(function(suffix) {
      var id = 'coursepilot-location-selection-' + target + suffix;
      elements[id] = makeElement(id);
    });
  });

  var pickerButtons = ['context_area', 'material_store'].map(function(target) {
    var btn = makeElement('open-picker-' + target);
    btn._attrs['data-target'] = target;
    btn._attrs['data-action'] = 'open-picker';
    return btn;
  });
  var keepMoodleButtons = ['context_area', 'material_store'].map(function(target) {
    var btn = makeElement('keep-moodle-' + target);
    btn._attrs['data-target'] = target;
    btn._attrs['data-action'] = 'keep-moodle';
    return btn;
  });

  function buttonFor(action, target) {
    var list = action === 'open-picker' ? pickerButtons : keepMoodleButtons;
    return list.filter(function(btn) {
      return btn._attrs['data-target'] === target;
    })[0] || null;
  }

  var fakeDocument = {
    getElementById: function(id) {
      return elements[id] || null;
    },
    querySelectorAll: function(selector) {
      if (selector === '[data-action="open-picker"]') {
        return pickerButtons;
      }
      if (selector === '[data-action="keep-moodle"]') {
        return keepMoodleButtons;
      }
      return [];
    },
    querySelector: function(selector) {
      var match = selector.match(/\[data-action="([^"]+)"\]\[data-target="([^"]+)"\]/);
      if (!match) {
        return null;
      }
      return buttonFor(match[1], match[2]);
    },
    createElement: function() {
      return makeElement(null);
    }
  };

  var fetchCallCount = 0;
  var fakeFetch = function() {
    var response = fetchResponses[fetchCallCount] || fetchResponses[fetchResponses.length - 1];
    fetchCallCount += 1;
    return Promise.resolve({
      json: function() {
        return Promise.resolve(response);
      }
    });
  };

  // Fake fuer core/templates: liefert nur einen Platzhalter zurueck und legt
  // ihn im Ziel-Container ab - die Tests pruefen Knopf-/Feldzustand, nie die
  // gerenderte Teilansicht selbst (siehe Moduldoc oben).
  var fakeTemplates = {
    render: function(templatename) {
      return Promise.resolve(['<!-- ' + templatename + ' -->', '']);
    },
    replaceNodeContents: function(node, html) {
      if (node) {
        node.innerHTML = html;
      }
      return Promise.resolve();
    }
  };
  var fakeNotification = {
    exception: function() {}
  };
  var fakeStrings = {
    get_strings: function() {
      return Promise.resolve([
        'Kontextbereich', 'Materialbestand', 'Ausgewaehlt', 'Instanz waehlen', 'Wurzel', 'Laedt...',
        '%s gewaehlt', '%s offen', 'Bitte beide Ziele waehlen', 'Zeitueberschreitung',
        'Zeitueberschreitung-Text', '', '', '', 'Fehler', '', 'Erneut', 'Zugang pruefen',
        'Spaeter', '%s Eintraege', 'Ueberlappung'
      ]);
    },
    get_string: function() {
      return Promise.resolve('Fehler');
    }
  };

  var moduleExports = null;
  var fakeDefine = function(deps, factory) {
    var resolved = deps.map(function(dep) {
      if (dep === 'core/templates') {
        return fakeTemplates;
      }
      if (dep === 'core/notification') {
        return fakeNotification;
      }
      if (dep === 'core/str') {
        return fakeStrings;
      }
      throw new Error('location-selection-amd-test-utils: unstubbed AMD dependency ' + dep);
    });
    moduleExports = factory.apply(null, resolved);
  };

  var sandbox = {
    document: fakeDocument,
    window: {bootstrap: undefined, alert: function() {}},
    fetch: fakeFetch,
    AbortController: undefined,
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    define: fakeDefine,
    console: console
  };

  vm.createContext(sandbox);
  vm.runInContext(SCRIPT_SOURCE, sandbox, {filename: SCRIPT_PATH});
  var ready = moduleExports.init(config);

  return {elements: elements, pickerButtons: pickerButtons, keepMoodleButtons: keepMoodleButtons, ready: ready};
}

/**
 * Default config fixture shared by the location selection AMD tests.
 *
 * @return {Object}
 */
function baseConfig() {
  return {
    targets: {
      'context_area': {chosen: false, location: null, instanceid: null, path: '', display: ''},
      'material_store': {chosen: false, location: null, instanceid: null, path: '', display: ''}
    },
    strings: {
      tabcontextarea: 'Kontextbereich',
      tabmaterialstore: 'Materialbestand',
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
    instances: [{id: 1, name: 'Instanz1', selectable: true}],
    sesskey: 'sess123',
    browseurl: 'http://test.example/browse.php',
    manageinstancesurl: 'http://test.example/manage.php',
    timeoutms: 8000
  };
}

/**
 * Flushes the microtask queue (enough for the promise chains this module builds).
 *
 * @return {Promise}
 */
async function flushPromises() {
  await new Promise(function(resolve) {
    setTimeout(resolve, 0);
  });
  await new Promise(function(resolve) {
    setTimeout(resolve, 0);
  });
}

module.exports = {makeElement: makeElement, loadLocationSelectionModule: loadLocationSelectionModule, baseConfig: baseConfig, flushPromises: flushPromises};
