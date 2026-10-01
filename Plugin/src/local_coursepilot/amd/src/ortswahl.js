// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Das Dateifenster und Fortschrittsband der Ortswahlseite (Issue #494, Spec
 * #486 §5; AMD-Umstellung Issue #551, Spec 0023): fetch() gegen
 * ortswahl_browse.php, 8s-Timeout mit drei Ausweg-Aktionen, Ordner-anlegen
 * nur im Speicher des Browsers (serverseitig entsteht der Ordner erst beim
 * Abschliessen, siehe local_coursepilot\ortswahl_lib::apply()).
 *
 * Alle Teilansichten (Instanzliste, Ordnerliste, Breadcrumb, Ladeanzeige,
 * Fehlerbox) kommen aus Mustache-Vorlagen (core/templates); dieses Modul baut
 * keine Auszeichnung mehr aus Zeichenketten zusammen, sondern reicht nur noch
 * Zustand an die Vorlagen weiter.
 *
 * Nie gespeichert, nie protokolliert: dieses Modul haelt Auflistungen nur im
 * laufenden DOM/JS-Zustand, schreibt nichts in localStorage o.ae.
 *
 * @module     local_coursepilot/ortswahl
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
define(['core/templates', 'core/notification', 'core/str'], function(Templates, Notification, Str) {
    'use strict';

    /**
     * Alle Uebersetzungen der Seite, per core/str geladen statt ueber
     * js_call_amd() eingebettet (Debugging-Warnung "Too much data passed as
     * arguments"; ueber 1024 Zeichen bei 21 teils langen Textbausteinen). Der
     * Schluessel ist der Alias, unter dem config.strings die Uebersetzung
     * traegt; bei den drei "reasonkey"/"errorkey"-Eintraegen (vom Server als
     * String-Identifier mitgeschickt) ist er identisch mit dem Moodle-
     * String-Identifier.
     *
     * @type {Array<{alias: string, key: string, param: (string|undefined)}>}
     */
    var STRING_REQUESTS = [
        {alias: 'tabkontextbereich', key: 'ortswahltabkontextbereich'},
        {alias: 'tabmaterialbestand', key: 'ortswahltabmaterialbestand'},
        {alias: 'selected', key: 'ortswahlselected'},
        {alias: 'chooseinstance', key: 'ortswahlchooseinstance'},
        {alias: 'breadcrumbroot', key: 'ortswahlbreadcrumbroot'},
        {alias: 'loading', key: 'ortswahlloading'},
        {alias: 'progresschosen', key: 'ortswahlprogresschosen', param: '%s'},
        {alias: 'progressopen', key: 'ortswahlprogressopen', param: '%s'},
        {alias: 'selectionincomplete', key: 'ortswahlselectionincomplete'},
        {alias: 'timeouttitle', key: 'ortswahltimeouttitle'},
        {alias: 'timeouttext', key: 'ortswahltimeouttext'},
        {alias: 'ortswahlinstanceauthunsupported', key: 'ortswahlinstanceauthunsupported'},
        {alias: 'ortswahlrootnotselectable', key: 'ortswahlrootnotselectable'},
        {alias: 'ortswahliservfilesonly', key: 'ortswahliservfilesonly'},
        {alias: 'browseerrorheading', key: 'ortswahlbrowseerrorheading'},
        {alias: 'ortswahlexternalerror', key: 'ortswahlexternalerror'},
        {alias: 'retry', key: 'ortswahlretry'},
        {alias: 'checkcredentials', key: 'ortswahlcheckcredentials'},
        {alias: 'later', key: 'ortswahllater'},
        {alias: 'confirmcount', key: 'ortswahlconfirmcount', param: '%s'},
        {alias: 'overlaplocked', key: 'ortswahloverlaplocked'}
    ];

    /**
     * @param {Array<string>} translations Resolved in STRING_REQUESTS order.
     * @return {Object} map of alias/identifier to translated text.
     */
    function buildStringsMap(translations) {
        var map = {};
        STRING_REQUESTS.forEach(function(request, i) {
            map[request.alias] = translations[i];
        });
        return map;
    }

    /**
     * Wires up one rendering of the Ortswahl editor.
     *
     * @param {Object} config Page state delivered by local_coursepilot\output\location_selection::editor_data(),
     *   with config.strings already resolved by init().
     * @return {void}
     */
    function startEditor(config) {
        var root = document.getElementById('coursepilot-ortswahl');
        if (!root) {
            return;
        }

        var state = {
            target: null,
            instanceid: null,
            path: '',
            // Segmente, die im Browser als "angelegt" markiert wurden, aber auf
            // dem Server noch nicht existieren - je Instanz eine Menge von
            // Pfaden, damit sie ohne Netzzugriff sofort betretbar sind.
            pendingFolders: {},
            // Letztes browse()-Ergebnis der aktuellen Ebene (Issue #497): traegt
            // Waehlbarkeit/Begruendung und Eintragszahl fuer die Uebergabe-
            // Bestaetigung eines gefuellten Ordners. Ein im Fenster angelegter
            // Ordner braucht keine Rueckfrage (Spec §5) - dafuer gilt er als leer.
            lastResult: null
        };

        /**
         * Vorbelegung bereits gewaehlter Ziele (Issue #525, Spec #486 §5): nur
         * ein Ziel, das der Pointer schon ausdruecklich aufloest ("chosen"),
         * gilt als erledigt.
         *
         * @param {string} target "kontextbereich" or "materialbestand".
         * @return {Object} the selection shape used throughout this module.
         */
        function initialSelection(target) {
            var current = config.targets[target];
            if (!current.chosen) {
                return {type: null};
            }
            return {
                type: current.ort,
                instanceid: current.instanzid,
                path: current.pfad,
                display: current.display
            };
        }

        var selections = {
            kontextbereich: initialSelection('kontextbereich'),
            materialbestand: initialSelection('materialbestand')
        };

        /**
         * Looks up a DOM element by id.
         *
         * @param {string} id Element id.
         * @return {Element}
         */
        function el(id) {
            return document.getElementById(id);
        }

        /**
         * Builds a map from target name to its button element.
         *
         * @param {NodeList} buttons Buttons carrying a data-target attribute.
         * @return {Object} map of target name to button element.
         */
        function buttonMap(buttons) {
            var map = {};
            buttons.forEach(function(btn) {
                map[btn.getAttribute('data-target')] = btn;
            });
            return map;
        }

        var keepMoodleButtons = buttonMap(document.querySelectorAll('[data-action="keep-moodle"]'));
        var openPickerButtons = buttonMap(document.querySelectorAll('[data-action="open-picker"]'));

        /**
         * Marks (or unmarks) a target's button as the current selection
         * (Issue #562): "Verbindung waehlen" already got an immediate echo
         * through the opening window, "In Moodle lassen" did not - the click
         * looked unresponsive until the far-away progress band changed.
         *
         * @param {Element} btn Button element, or null.
         * @param {boolean} isSelected Whether the button represents the active selection.
         * @return {void}
         */
        function markButtonSelected(btn, isSelected) {
            if (!btn) {
                return;
            }
            btn.classList.toggle('active', isSelected);
            var badge = btn.querySelector('.coursepilot-ortswahl-selected-badge');
            if (isSelected && !badge) {
                badge = document.createElement('span');
                badge.className = 'coursepilot-ortswahl-selected-badge badge bg-success ms-2';
                badge.textContent = config.strings.selected;
                btn.appendChild(badge);
            } else if (!isSelected && badge) {
                badge.remove();
            }
        }

        /**
         * Refreshes the selected-marker on both buttons of one target.
         *
         * @param {string} target "kontextbereich" or "materialbestand".
         * @return {void}
         */
        function renderButtonSelection(target) {
            var type = selections[target].type;
            markButtonSelected(keepMoodleButtons[target], type === 'moodle');
            markButtonSelected(openPickerButtons[target], type === 'extern');
        }

        /**
         * Builds a nested path from a base and one more segment.
         *
         * @param {string} base Parent path, "" for the root.
         * @param {string} name Segment to append.
         * @return {string}
         */
        function joinPath(base, name) {
            return base === '' ? name : base + '/' + name;
        }

        /**
         * Key used to group pending (in-browser-only) folders per instance.
         *
         * @param {number} instanceid Instance id.
         * @return {string}
         */
        function pendingKey(instanceid) {
            return String(instanceid);
        }

        /**
         * Whether a path was created in this window but not yet on the server.
         *
         * @param {number} instanceid Instance id.
         * @param {string} path Path to check.
         * @return {boolean}
         */
        function isPending(instanceid, path) {
            var set = state.pendingFolders[pendingKey(instanceid)];
            return !!(set && set.indexOf(path) !== -1);
        }

        /**
         * Remembers a path as created in this window only.
         *
         * @param {number} instanceid Instance id.
         * @param {string} path Path to remember.
         * @return {void}
         */
        function addPending(instanceid, path) {
            var key = pendingKey(instanceid);
            if (!state.pendingFolders[key]) {
                state.pendingFolders[key] = [];
            }
            if (state.pendingFolders[key].indexOf(path) === -1) {
                state.pendingFolders[key].push(path);
            }
        }

        /**
         * Display label for a target.
         *
         * @param {string} target "kontextbereich" or "materialbestand".
         * @return {string}
         */
        function targetLabel(target) {
            return target === 'kontextbereich' ? config.strings.tabkontextbereich : config.strings.tabmaterialbestand;
        }

        /**
         * Display text for a target's current selection.
         *
         * @param {string} target "kontextbereich" or "materialbestand".
         * @return {string}
         */
        function selectionDisplay(target) {
            var sel = selections[target];
            return sel.display || sel.path;
        }

        /**
         * Naeherung des Vergleichsschluessels (Issue #495, #497): fuer zwei
         * *live* auf dieser Seite gewaehlte Ziele reicht Instanz-Gleichheit
         * (bzw. beide "in Moodle") plus Pfad-Praefix. Die verbindliche
         * Pruefung laeuft serverseitig beim Abschliessen (location_selection::apply());
         * dies ist nur der fruehe UI-Hinweis am Knopf.
         *
         * @param {string} path Raw path.
         * @return {string} Normalised path, always starting and ending with "/".
         */
        function normalisedPath(path) {
            var trimmed = (path || '').replace(/^\/+|\/+$/g, '');
            return trimmed === '' ? '/' : '/' + trimmed + '/';
        }

        /**
         * Whether both selections point at the same storage root.
         *
         * @param {Object} kontext Kontextbereich selection.
         * @param {Object} material Materialbestand selection.
         * @return {boolean}
         */
        function overlapsSameRoot(kontext, material) {
            if (kontext.type === 'moodle' && material.type === 'moodle') {
                return true;
            }
            return kontext.type === 'extern' && material.type === 'extern' &&
                String(kontext.instanceid) === String(material.instanceid);
        }

        /**
         * Whether the current selections overlap and must block "Abschliessen".
         *
         * @return {boolean}
         */
        function computeOverlapLock() {
            var kontext = selections.kontextbereich;
            var material = selections.materialbestand;
            if (!kontext.type || !material.type || !overlapsSameRoot(kontext, material)) {
                return false;
            }
            return normalisedPath(material.path).indexOf(normalisedPath(kontext.path)) === 0;
        }

        /**
         * Renders the progress band and the finish-button lock state.
         *
         * @return {Promise}
         */
        function renderProgress() {
            var context = {
                targets: ['kontextbereich', 'materialbestand'].map(function(target) {
                    var sel = selections[target];
                    var chosen = !!(sel && sel.type);
                    return {
                        badgeclass: chosen ? 'bg-success' : 'bg-secondary',
                        text: targetLabel(target) + ': ' + (chosen
                            ? config.strings.progresschosen.replace('%s', selectionDisplay(target))
                            : config.strings.progressopen.replace('%s', targetLabel(target)))
                    };
                })
            };

            var overlapLocked = computeOverlapLock();
            var overlapEl = el('coursepilot-ortswahl-overlaplock');
            overlapEl.hidden = !overlapLocked;
            overlapEl.textContent = overlapLocked ? config.strings.overlaplocked : '';
            el('coursepilot-ortswahl-finish').disabled =
                !(selections.kontextbereich.type && selections.materialbestand.type) || overlapLocked;

            return Templates.render('local_coursepilot/ortswahl_progress', context)
                .then(function(html, js) {
                    return Templates.replaceNodeContents(el('coursepilot-ortswahl-progress'), html, js);
                })
                .catch(Notification.exception);
        }

        /**
         * Stores one target's selection, updates the hidden form fields and
         * re-renders the progress band and the target's buttons.
         *
         * @param {string} target "kontextbereich" or "materialbestand".
         * @param {Object} selection {type, instanceid, path, display, confirmed}.
         * @return {void}
         */
        function applySelection(target, selection) {
            selections[target] = selection;
            el('coursepilot-ortswahl-' + target + '_type').value = selection.type;
            el('coursepilot-ortswahl-' + target + '_instanceid').value = selection.instanceid || '';
            el('coursepilot-ortswahl-' + target + '_path').value = selection.path || '';
            el('coursepilot-ortswahl-' + target + '_confirmed').value = selection.confirmed ? '1' : '';
            renderButtonSelection(target);
            renderProgress();
        }

        // Die versteckten Formularfelder tragen erst nach einer Interaktion
        // einen Wert - ein bereits gewaehltes Ziel muss aber auch ohne erneute
        // Interaktion mitgeschickt werden, sonst verwirft das Abschliessen eine
        // unveraenderte Auswahl als ungueltig.
        ['kontextbereich', 'materialbestand'].forEach(function(target) {
            if (selections[target].type) {
                applySelection(target, selections[target]);
            }
        });

        // --- "In Moodle lassen" ---------------------------------------------

        Object.keys(keepMoodleButtons).forEach(function(target) {
            keepMoodleButtons[target].addEventListener('click', function() {
                applySelection(target, {
                    type: 'moodle',
                    path: config.targets[target].pfad,
                    display: config.targets[target].display
                });
            });
        });

        // --- Dateifenster ----------------------------------------------------

        var modalEl = el('coursepilot-ortswahl-modal');
        var bsModal = (window.bootstrap && window.bootstrap.Modal) ? new window.bootstrap.Modal(modalEl) : null;
        // Own dismiss attribute: Moodle 5.0 has no window.bootstrap, and its data-bs-dismiss
        // handler cannot close a modal it never opened.
        modalEl.querySelectorAll('[data-coursepilot-dismiss="modal"]').forEach(function(btn) {
            btn.addEventListener('click', closeModal);
        });

        /**
         * Closes the file-picker modal.
         *
         * @return {void}
         */
        function closeModal() {
            if (bsModal) {
                bsModal.hide();
            } else {
                modalEl.style.display = 'none';
            }
        }

        /**
         * Opens the file-picker modal for one target (Spec §5): an already
         * external selection browses straight to its instance/path; without a
         * prior external choice the first own instance is preloaded.
         *
         * @param {string} target "kontextbereich" or "materialbestand".
         * @return {void}
         */
        function openModal(target) {
            state.target = target;
            var selection = selections[target];
            if (selection && selection.type === 'extern') {
                state.instanceid = selection.instanceid;
                state.path = selection.path || '';
            } else if (config.instances.length > 0) {
                state.instanceid = config.instances[0].id;
                state.path = '';
            } else {
                state.instanceid = null;
                state.path = '';
            }
            el('coursepilot-ortswahl-modal-title').textContent = config.strings.chooseinstance;
            renderInstances();
            renderBreadcrumb();
            el('coursepilot-ortswahl-folders').innerHTML = '';
            if (bsModal) {
                bsModal.show();
            } else {
                modalEl.style.display = 'block';
            }
            if (state.instanceid !== null) {
                browse();
            }
        }

        Object.keys(openPickerButtons).forEach(function(target) {
            openPickerButtons[target].addEventListener('click', function() {
                openModal(target);
            });
        });

        /**
         * Reason text shown when an instance cannot be picked in the modal.
         *
         * @param {Object} instance {selectable, reasonkey}.
         * @return {string}
         */
        function instanceReason(instance) {
            if (instance.selectable !== false) {
                return '';
            }
            return instance.reasonkey ? (config.strings[instance.reasonkey] || '') : '';
        }

        /**
         * Renders the instance list and wires up its click handlers.
         *
         * @return {Promise}
         */
        function renderInstances() {
            var context = {
                instances: config.instances.map(function(instance) {
                    return {
                        id: instance.id,
                        name: instance.name,
                        active: state.instanceid === instance.id,
                        disabled: instance.selectable === false,
                        reason: instanceReason(instance)
                    };
                })
            };
            var container = el('coursepilot-ortswahl-instances');
            return Templates.render('local_coursepilot/ortswahl_instances', context)
                .then(function(html, js) {
                    return Templates.replaceNodeContents(container, html, js);
                })
                .then(function() {
                    container.querySelectorAll('[data-instance-id]').forEach(function(item) {
                        if (item.disabled) {
                            return;
                        }
                        item.addEventListener('click', function() {
                            state.instanceid = parseInt(item.getAttribute('data-instance-id'), 10);
                            state.path = '';
                            renderInstances();
                            browse();
                        });
                    });
                    return undefined;
                })
                .catch(Notification.exception);
        }

        /**
         * Renders the breadcrumb for the current path and wires up its links.
         *
         * @return {Promise}
         */
        function renderBreadcrumb() {
            var segments = state.path === '' ? [] : state.path.split('/');
            var crumbs = [{label: config.strings.breadcrumbroot, path: '', last: segments.length === 0}];
            var accumulated = '';
            segments.forEach(function(segment, index) {
                accumulated = joinPath(accumulated, segment);
                crumbs.push({label: segment, path: accumulated, last: index === segments.length - 1});
            });

            var container = el('coursepilot-ortswahl-breadcrumb');
            return Templates.render('local_coursepilot/ortswahl_breadcrumb', {crumbs: crumbs})
                .then(function(html, js) {
                    return Templates.replaceNodeContents(container, html, js);
                })
                .then(function() {
                    container.querySelectorAll('[data-crumb-path]').forEach(function(link) {
                        link.addEventListener('click', function(e) {
                            e.preventDefault();
                            state.path = link.getAttribute('data-crumb-path');
                            browse();
                        });
                    });
                    return undefined;
                })
                .catch(Notification.exception);
        }

        /**
         * Renders the folder list of the current level and wires up its
         * click handlers, including the pending-folder shortcut that skips a
         * network round trip (a folder created in this window is always
         * empty and needs no handover confirmation, Spec §5).
         *
         * @param {Array} folders List of {name}.
         * @return {Promise}
         */
        function renderFolders(folders) {
            var container = el('coursepilot-ortswahl-folders');
            return Templates.render('local_coursepilot/ortswahl_folders', {folders: folders})
                .then(function(html, js) {
                    return Templates.replaceNodeContents(container, html, js);
                })
                .then(function() {
                    container.querySelectorAll('[data-folder-name]').forEach(function(item) {
                        item.addEventListener('click', function() {
                            state.path = joinPath(state.path, item.getAttribute('data-folder-name'));
                            if (isPending(state.instanceid, state.path)) {
                                renderBreadcrumb();
                                applyBrowseResult({
                                    path: state.path,
                                    folders: [],
                                    selectable: true,
                                    reasonkey: null,
                                    entrycount: 0,
                                    entrynames: []
                                });
                            } else {
                                browse();
                            }
                        });
                    });
                    return undefined;
                })
                .catch(Notification.exception);
        }

        /**
         * Shows a loading placeholder while a browse() request is in flight.
         *
         * @return {Promise}
         */
        function showLoading() {
            return Templates.render('local_coursepilot/ortswahl_loading', {text: config.strings.loading})
                .then(function(html, js) {
                    return Templates.replaceNodeContents(el('coursepilot-ortswahl-folders'), html, js);
                })
                .catch(Notification.exception);
        }

        /**
         * Gemeinsame Fehlerbox fuer beide Ausfallarten beim Blaettern (Issue
         * #526, Spec #486 §5/§8): der Client-Zeitueberschreitungsfall (8s ohne
         * Antwort) und ein vom Server tatsaechlich beantworteter Fehler
         * ({ok:false, error:...}).
         *
         * @param {string} title Error heading.
         * @param {string} text Error detail text.
         * @return {Promise}
         */
        function showBrowseError(title, text) {
            var container = el('coursepilot-ortswahl-folders');
            var context = {
                title: title,
                text: text,
                retrylabel: config.strings.retry,
                checkcredentialsurl: config.manageinstancesurl,
                checkcredentialslabel: config.strings.checkcredentials,
                laterlabel: config.strings.later
            };
            return Templates.render('local_coursepilot/ortswahl_browse_error', context)
                .then(function(html, js) {
                    return Templates.replaceNodeContents(container, html, js);
                })
                .then(function() {
                    container.querySelector('[data-action="retry"]').addEventListener('click', browse);
                    container.querySelector('[data-action="later"]').addEventListener('click', closeModal);
                    return undefined;
                })
                .catch(Notification.exception);
        }

        /**
         * Shows the timeout variant of the browse error box.
         *
         * @return {Promise}
         */
        function showTimeout() {
            return showBrowseError(config.strings.timeouttitle, config.strings.timeouttext);
        }

        /**
         * Sperren einer Ebene (Issue #497, Spec §5: Wurzel, IServ).
         *
         * @param {Object} result Browse result: {path, folders, selectable, reasonkey, entrycount, entrynames}.
         * @return {void}
         */
        function applyBrowseResult(result) {
            state.lastResult = result;
            var locked = result.selectable === false;
            var reasonEl = el('coursepilot-ortswahl-modal-reason');
            reasonEl.hidden = !locked;
            reasonEl.textContent = locked && result.reasonkey ? (config.strings[result.reasonkey] || '') : '';
            el('coursepilot-ortswahl-confirmfolder').disabled = locked;
            el('coursepilot-ortswahl-createfolder').disabled = locked;
        }

        /**
         * Fetches and renders one level of the currently browsed instance.
         *
         * @return {void}
         */
        function browse() {
            if (state.instanceid === null) {
                return;
            }
            renderBreadcrumb();
            showLoading();

            // POST statt GET (Spec §5: "Auflistungen werden ... nicht
            // protokolliert") - instanceid/Pfad/Sesskey landen so nicht in der
            // Query-String-Zeile eines Webserver-Zugriffsprotokolls.
            var body = 'instanceid=' + encodeURIComponent(state.instanceid) +
                '&path=' + encodeURIComponent(state.path) +
                '&sesskey=' + encodeURIComponent(config.sesskey);

            var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var timedOut = false;
            var timer = setTimeout(function() {
                timedOut = true;
                if (controller) {
                    controller.abort();
                }
            }, config.timeoutms);

            // Ausserhalb der Promise-Kette gemerkt statt verschachtelt
            // .then()-t, damit renderFolders()/applyBrowseResult() in einem
            // eigenen, flachen Kettenglied laufen (promise/no-nesting).
            var browseState = null;

            fetch(config.browseurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body,
                signal: controller ? controller.signal : undefined
            })
                .then(function(response) {
                    return response.json();
                })
                .then(function(result) {
                    clearTimeout(timer);
                    if (!result.ok) {
                        // Der Server hat geantwortet, aber mit einem Fehler
                        // (Issue #526) - der benannte Fehler wird erst hier
                        // uebersetzt, nie als Satz im Seitenzustand transportiert.
                        // Issue #565: config.strings deckt nur die vorab ohne
                        // Platzhalter geladenen Texte ab; ortswahlexternalerror
                        // braucht {$a->errorclass}/{$a->page} und wird deshalb
                        // hier live nachgeladen statt aus config.strings gelesen.
                        if (!result.errorkey) {
                            return showBrowseError(config.strings.browseerrorheading, config.strings.timeouttext);
                        }
                        return Str.get_string(result.errorkey, 'local_coursepilot', {
                            errorclass: result.errorclass || '',
                            page: result.page || ''
                        }).then(function(text) {
                            return showBrowseError(config.strings.browseerrorheading, text);
                        }).catch(function() {
                            return showBrowseError(config.strings.browseerrorheading, config.strings.timeouttext);
                        });
                    }
                    // Test-Fakes und aeltere Antworten liefern die Ebene direkt;
                    // der Endpunkt liefert sie als Teil des Seitenzustands.
                    browseState = result.state ? result.state.browse : result;
                    return renderFolders(browseState.folders || []);
                })
                .then(function() {
                    if (browseState) {
                        applyBrowseResult(browseState);
                    }
                    return undefined;
                })
                .catch(function() {
                    clearTimeout(timer);
                    if (timedOut) {
                        return showTimeout();
                    }
                    return null;
                });
        }

        // --- Ordner anlegen (nur im Browser, siehe Moduldoc) -----------------

        el('coursepilot-ortswahl-createfolder').addEventListener('click', function() {
            var input = el('coursepilot-ortswahl-newfolder');
            var name = input.value.trim();
            if (name === '' || name.indexOf('/') !== -1) {
                return;
            }
            if (state.instanceid === null) {
                return;
            }
            var path = joinPath(state.path, name);
            addPending(state.instanceid, path);
            input.value = '';
            state.path = path;
            renderBreadcrumb();
            // Ein gerade angelegter Ordner ist per Definition leer (Spec §5) -
            // ohne dies bliebe state.lastResult auf dem letzten echten
            // browse()-Ergebnis der Elternebene stehen und "Ordner auswaehlen"
            // wuerde faelschlich deren Inhalt fuer die Uebergabe-Warnung
            // heranziehen (Issue #559).
            renderFolders([])
                .then(function() {
                    state.lastResult = {path: path, folders: [], selectable: true, reasonkey: null, entrycount: 0, entrynames: []};
                    return undefined;
                })
                .catch(Notification.exception);
        });

        // --- Ordner waehlen, mit Uebergabe-Bestaetigung eines gefuellten Ordners
        // (Issue #497, Spec §5: nur der Kontextbereich fragt nach; ein leerer
        // oder im Fenster angelegter Ordner braucht keine Rueckfrage) ----------

        var confirmModalEl = el('coursepilot-ortswahl-confirm-modal');
        var bsConfirmModal = (window.bootstrap && window.bootstrap.Modal) ? new window.bootstrap.Modal(confirmModalEl) : null;
        confirmModalEl.querySelectorAll('[data-coursepilot-dismiss="modal"]').forEach(function(btn) {
            btn.addEventListener('click', closeConfirmModal);
        });

        /**
         * Closes the handover-confirmation modal.
         *
         * @return {void}
         */
        function closeConfirmModal() {
            if (bsConfirmModal) {
                bsConfirmModal.hide();
            } else {
                confirmModalEl.style.display = 'none';
            }
        }

        /**
         * Commits the currently browsed folder as the target's selection.
         *
         * @param {boolean} confirmed Whether the handover warning was acknowledged.
         * @return {void}
         */
        function finalizeFolderSelection(confirmed) {
            var instance = config.instances.filter(function(i) {
                return i.id === state.instanceid;
            })[0];
            var display = (instance ? instance.name : '') + (state.path === '' ? '' : ' / ' + state.path);
            applySelection(state.target, {
                type: 'extern',
                instanceid: state.instanceid,
                path: state.path,
                display: display,
                confirmed: !!confirmed
            });
            closeModal();
        }

        el('coursepilot-ortswahl-confirmfolder').addEventListener('click', function() {
            if (state.target === null || state.instanceid === null) {
                return;
            }
            var result = state.lastResult;
            var needsHandover = state.target === 'kontextbereich' && result && result.entrycount > 0;
            if (!needsHandover) {
                finalizeFolderSelection(false);
                return;
            }
            var names = (result.entrynames || []).join(', ');
            el('coursepilot-ortswahl-confirm-count').textContent =
                config.strings.confirmcount.replace('%s', result.entrycount) + (names !== '' ? ' ' + names + ' …' : '');
            if (bsConfirmModal) {
                bsConfirmModal.show();
            } else {
                confirmModalEl.style.display = 'block';
            }
        });

        el('coursepilot-ortswahl-confirmfolder-ack').addEventListener('click', function() {
            closeConfirmModal();
            finalizeFolderSelection(true);
        });

        // --- Abschliessen: Client-seitige Vollstaendigkeitspruefung ----------

        el('coursepilot-ortswahl-form').addEventListener('submit', function(e) {
            if (!selections.kontextbereich.type || !selections.materialbestand.type) {
                e.preventDefault();
                // eslint-disable-next-line no-alert
                window.alert(config.strings.selectionincomplete);
            }
        });

        renderProgress();
    }

    /**
     * Loads the page's translated strings via core/str (Issue #565: the
     * previous js_call_amd() payload with all 21 strings embedded exceeded
     * Moodle's 1024-character debugging threshold), then wires up the
     * editor.
     *
     * @param {Object} config Page state delivered by local_coursepilot\output\location_selection::editor_data().
     * @return {Promise} resolves once the editor is wired up.
     */
    function init(config) {
        var requests = STRING_REQUESTS.map(function(request) {
            return {key: request.key, component: 'local_coursepilot', param: request.param};
        });
        return Str.get_strings(requests).then(function(translations) {
            config.strings = buildStringsMap(translations);
            startEditor(config);
            return null;
        }).catch(Notification.exception);
    }

    return {init: init};
});
