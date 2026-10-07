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
 * The file picker and progress band for location selection (Issue #494,
 * Spec #486 §5; AMD migration #551, Spec 0023): fetch() calls
 * location_selection_browse.php with an 8-second timeout and three escape
 * routes. New folders exist only in browser memory until completion creates
 * them on the server (local_coursepilot\location_selection::apply()).
 *
 * All views (instances, folders, breadcrumb, loading and errors) come from
 * Mustache templates (core/templates). This module passes state to templates
 * instead of assembling markup from strings.
 *
 * Never stored or logged: listings live only in current DOM/JS state;
 * nothing is written to localStorage or similar storage.
 *
 * @module     local_coursepilot/location_selection
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
define(['core/templates', 'core/notification', 'core/str'], function(Templates, Notification, Str) {
    'use strict';

    /**
     * Load page translations through core/str instead of embedding them in
     * js_call_amd() (the 21 partly lengthy strings exceeded the 1024-character
     * "Too much data passed as arguments" debugging threshold). Each key is
     * the config.strings alias. For the three reasonkey/errorkey entries sent
     * by the server, the alias is also the Moodle string identifier.
     *
     * @type {Array<{alias: string, key: string, param: (string|undefined)}>}
     */
    var STRING_REQUESTS = [
        {alias: 'tabcontextarea', key: 'locationselectiontabcontextarea'},
        {alias: 'tabmaterialstore', key: 'locationselectiontabmaterialstore'},
        {alias: 'selected', key: 'locationselectionselected'},
        {alias: 'chooseinstance', key: 'locationselectionchooseinstance'},
        {alias: 'breadcrumbroot', key: 'locationselectionbreadcrumbroot'},
        {alias: 'loading', key: 'locationselectionloading'},
        {alias: 'progresschosen', key: 'locationselectionprogresschosen', param: '%s'},
        {alias: 'progressopen', key: 'locationselectionprogressopen', param: '%s'},
        {alias: 'selectionincomplete', key: 'locationselectionselectionincomplete'},
        {alias: 'timeouttitle', key: 'locationselectiontimeouttitle'},
        {alias: 'timeouttext', key: 'locationselectiontimeouttext'},
        {alias: 'locationselectioninstanceauthunsupported', key: 'locationselectioninstanceauthunsupported'},
        {alias: 'locationselectionrootnotselectable', key: 'locationselectionrootnotselectable'},
        {alias: 'locationselectioniservfilesonly', key: 'locationselectioniservfilesonly'},
        {alias: 'browseerrorheading', key: 'locationselectionbrowseerrorheading'},
        {alias: 'locationselectionexternalerror', key: 'locationselectionexternalerror'},
        {alias: 'retry', key: 'locationselectionretry'},
        {alias: 'checkcredentials', key: 'locationselectioncheckcredentials'},
        {alias: 'later', key: 'locationselectionlater'},
        {alias: 'confirmcount', key: 'locationselectionconfirmcount', param: '%s'},
        {alias: 'overlaplocked', key: 'locationselectionoverlaplocked'}
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
     * Wires up one rendering of the location-selection editor.
     *
     * @param {Object} config Page state delivered by local_coursepilot\output\location_selection::editor_data(),
     *   with config.strings already resolved by init().
     * @return {void}
     */
    function startEditor(config) {
        var root = document.getElementById('coursepilot-location-selection');
        if (!root) {
            return;
        }

        var state = {
            target: null,
            instanceid: null,
            path: '',
            // Segments marked as created in the browser but absent from the server:
            // a set of paths per instance, immediately browsable without network access.
            pendingFolders: {},
            // Latest browse() result for this level (Issue #497): selectability,
            // reason and entry count for populated-folder handover confirmation.
            // A folder created in this window is treated as empty (Spec §5).
            lastResult: null
        };

        /**
         * Preselect chosen targets (Issue #525, Spec #486 §5): only targets
         * explicitly resolved as "chosen" by the pointer count as complete.
         *
         * @param {string} target "context_area" or "material_store".
         * @return {Object} the selection shape used throughout this module.
         */
        function initialSelection(target) {
            var current = config.targets[target];
            if (!current.chosen) {
                return {type: null};
            }
            return {
                type: current.location,
                instanceid: current.instanceid,
                path: current.path,
                display: current.display
            };
        }

        var selections = {
            'context_area': initialSelection('context_area'),
            'material_store': initialSelection('material_store')
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
         * (Issue #562): "Choose connection" already got an immediate echo
         * through the opening window, "Keep in Moodle" did not - the click
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
            var badge = btn.querySelector('.coursepilot-location-selection-selected-badge');
            if (isSelected && !badge) {
                badge = document.createElement('span');
                badge.className = 'coursepilot-location-selection-selected-badge badge bg-success ms-2';
                badge.textContent = config.strings.selected;
                btn.appendChild(badge);
            } else if (!isSelected && badge) {
                badge.remove();
            }
        }

        /**
         * Refreshes the selected-marker on both buttons of one target.
         *
         * @param {string} target "context_area" or "material_store".
         * @return {void}
         */
        function renderButtonSelection(target) {
            var type = selections[target].type;
            markButtonSelected(keepMoodleButtons[target], type === 'moodle');
            markButtonSelected(openPickerButtons[target], type === 'external');
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
         * @param {string} target "context_area" or "material_store".
         * @return {string}
         */
        function targetLabel(target) {
            return target === 'context_area' ? config.strings.tabcontextarea : config.strings.tabmaterialstore;
        }

        /**
         * Display text for a target's current selection.
         *
         * @param {string} target "context_area" or "material_store".
         * @return {string}
         */
        function selectionDisplay(target) {
            var sel = selections[target];
            return sel.display || sel.path;
        }

        /**
         * Approximate comparison key (Issue #495, #497): for two live selections,
         * matching instances (or both in Moodle) plus path prefixes suffice.
         * The authoritative check runs server-side on completion
         * (location_selection::apply()); this is only the early button hint.
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
         * @param {Object} contextSelection Context-area selection.
         * @param {Object} material Material-store selection.
         * @return {boolean}
         */
        function overlapsSameRoot(contextSelection, material) {
            if (contextSelection.type === 'moodle' && material.type === 'moodle') {
                return true;
            }
            return contextSelection.type === 'external' && material.type === 'external' &&
                String(contextSelection.instanceid) === String(material.instanceid);
        }

        /**
         * Whether the current selections overlap and must block "Finish".
         *
         * @return {boolean}
         */
        function computeOverlapLock() {
            var contextSelection = selections.context_area;
            var material = selections.material_store;
            if (!contextSelection.type || !material.type || !overlapsSameRoot(contextSelection, material)) {
                return false;
            }
            return normalisedPath(material.path).indexOf(normalisedPath(contextSelection.path)) === 0;
        }

        /**
         * Renders the progress band and the finish-button lock state.
         *
         * @return {Promise}
         */
        function renderProgress() {
            var context = {
                targets: ['context_area', 'material_store'].map(function(target) {
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
            var overlapEl = el('coursepilot-location-selection-overlaplock');
            overlapEl.hidden = !overlapLocked;
            overlapEl.textContent = overlapLocked ? config.strings.overlaplocked : '';
            el('coursepilot-location-selection-finish').disabled =
                !(selections.context_area.type && selections.material_store.type) || overlapLocked;

            return Templates.render('local_coursepilot/location_selection_progress', context)
                .then(function(html, js) {
                    return Templates.replaceNodeContents(el('coursepilot-location-selection-progress'), html, js);
                })
                .catch(Notification.exception);
        }

        /**
         * Stores one target's selection, updates the hidden form fields and
         * re-renders the progress band and the target's buttons.
         *
         * @param {string} target "context_area" or "material_store".
         * @param {Object} selection {type, instanceid, path, display, confirmed}.
         * @return {void}
         */
        function applySelection(target, selection) {
            selections[target] = selection;
            el('coursepilot-location-selection-' + target + '_type').value = selection.type;
            el('coursepilot-location-selection-' + target + '_instanceid').value = selection.instanceid || '';
            el('coursepilot-location-selection-' + target + '_path').value = selection.path || '';
            el('coursepilot-location-selection-' + target + '_confirmed').value = selection.confirmed ? '1' : '';
            renderButtonSelection(target);
            renderProgress();
        }

        // Hidden form fields get values after interaction. Populate previously
        // chosen targets even without new interaction, or completion would reject
        // an unchanged selection as invalid.
        ['context_area', 'material_store'].forEach(function(target) {
            if (selections[target].type) {
                applySelection(target, selections[target]);
            }
        });

        // --- "Keep in Moodle" ---

        Object.keys(keepMoodleButtons).forEach(function(target) {
            keepMoodleButtons[target].addEventListener('click', function() {
                applySelection(target, {
                    type: 'moodle',
                    path: config.targets[target].path,
                    display: config.targets[target].display
                });
            });
        });

        // --- File picker ---

        var modalEl = el('coursepilot-location-selection-modal');
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
         * @param {string} target "context_area" or "material_store".
         * @return {void}
         */
        function openModal(target) {
            state.target = target;
            var selection = selections[target];
            if (selection && selection.type === 'external') {
                state.instanceid = selection.instanceid;
                state.path = selection.path || '';
            } else if (config.instances.length > 0) {
                state.instanceid = config.instances[0].id;
                state.path = '';
            } else {
                state.instanceid = null;
                state.path = '';
            }
            el('coursepilot-location-selection-modal-title').textContent = config.strings.chooseinstance;
            renderInstances();
            renderBreadcrumb();
            el('coursepilot-location-selection-folders').innerHTML = '';
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
            var container = el('coursepilot-location-selection-instances');
            return Templates.render('local_coursepilot/location_selection_instances', context)
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

            var container = el('coursepilot-location-selection-breadcrumb');
            return Templates.render('local_coursepilot/location_selection_breadcrumb', {crumbs: crumbs})
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
            var container = el('coursepilot-location-selection-folders');
            return Templates.render('local_coursepilot/location_selection_folders', {folders: folders})
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
                                    iserv: state.lastResult && state.lastResult.iserv,
                                    selectable: !(state.lastResult && state.lastResult.iserv) ||
                                        state.path.split('/')[0] === 'Files',
                                    reasonkey: state.lastResult && state.lastResult.iserv &&
                                        state.path.split('/')[0] !== 'Files' ? 'locationselectioniservfilesonly' : null,
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
            return Templates.render('local_coursepilot/location_selection_loading', {text: config.strings.loading})
                .then(function(html, js) {
                    return Templates.replaceNodeContents(el('coursepilot-location-selection-folders'), html, js);
                })
                .catch(Notification.exception);
        }

        /**
         * Shared browse error box for both failures (Issue #526, Spec #486 §5/§8):
         * a client timeout (8 seconds without a response) and a server error
         * response ({ok:false, error:...}).
         *
         * @param {string} title Error heading.
         * @param {string} text Error detail text.
         * @return {Promise}
         */
        function showBrowseError(title, text) {
            var container = el('coursepilot-location-selection-folders');
            var context = {
                title: title,
                text: text,
                retrylabel: config.strings.retry,
                checkcredentialsurl: config.manageinstancesurl,
                checkcredentialslabel: config.strings.checkcredentials,
                laterlabel: config.strings.later
            };
            return Templates.render('local_coursepilot/location_selection_browse_error', context)
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
         * Lock one level (Issue #497, Spec §5: root, IServ).
         *
         * @param {Object} result Browse result: {path, folders, selectable, reasonkey, entrycount, entrynames}.
         * @return {void}
         */
        function applyBrowseResult(result) {
            state.lastResult = result;
            var locked = result.selectable === false;
            var reasonEl = el('coursepilot-location-selection-modal-reason');
            reasonEl.hidden = !locked;
            reasonEl.textContent = locked && result.reasonkey ? (config.strings[result.reasonkey] || '') : '';
            el('coursepilot-location-selection-confirmfolder').disabled = locked;
            el('coursepilot-location-selection-createfolder').disabled = locked &&
                result.reasonkey !== 'locationselectionrootnotselectable';
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

            // POST instead of GET (Spec §5: listings must not be logged), keeping
            // instance ID, path and session key out of web-server query-string logs.
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

            // Keep state outside the promise chain so renderFolders() and
            // applyBrowseResult() run in a flat chain rather than nested then() calls
            // (promise/no-nesting).
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
                        // The server responded with an error (Issue #526). Translate its named
                        // error here instead of transporting sentences in page state.
                        // Issue #565: config.strings covers preloaded strings without placeholders;
                        // locationselectionexternalerror needs {$a->errorclass}/{$a->page}, so
                        // load it here rather than reading it from config.strings.
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
                    // Test fakes and older responses return the level directly;
                    // the endpoint includes it in page state.
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

        // --- Create folders (browser only; see module documentation) ---

        el('coursepilot-location-selection-createfolder').addEventListener('click', function() {
            var input = el('coursepilot-location-selection-newfolder');
            var name = input.value.trim();
            if (name === '' || name.indexOf('/') !== -1) {
                return;
            }
            if (state.instanceid === null) {
                return;
            }
            var path = joinPath(state.path, name);
            var iserv = state.lastResult && state.lastResult.iserv;
            addPending(state.instanceid, path);
            input.value = '';
            state.path = path;
            renderBreadcrumb();
            // A newly created folder is empty by definition (Spec §5). Otherwise
            // state.lastResult would retain the real parent browse result and folder
            // selection would incorrectly use that content for the handover warning
            // (Issue #559).
            renderFolders([])
                .then(function() {
                    var selectable = !iserv || path.split('/')[0] === 'Files';
                    applyBrowseResult({path: path, folders: [], iserv: iserv, selectable: selectable,
                        reasonkey: selectable ? null : 'locationselectioniservfilesonly', entrycount: 0, entrynames: []});
                    return undefined;
                })
                .catch(Notification.exception);
        });

        // --- Select folder with populated-folder handover confirmation ---
        // Issue #497, Spec §5: only the context area asks; empty folders and
        // folders created in this window need no confirmation.

        var confirmModalEl = el('coursepilot-location-selection-confirm-modal');
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
                type: 'external',
                instanceid: state.instanceid,
                path: state.path,
                display: display,
                confirmed: !!confirmed
            });
            closeModal();
        }

        el('coursepilot-location-selection-confirmfolder').addEventListener('click', function() {
            if (state.target === null || state.instanceid === null) {
                return;
            }
            var result = state.lastResult;
            var needsHandover = state.target === 'context_area' && result && result.entrycount > 0;
            if (!needsHandover) {
                finalizeFolderSelection(false);
                return;
            }
            var names = (result.entrynames || []).join(', ');
            el('coursepilot-location-selection-confirm-count').textContent =
                config.strings.confirmcount.replace('%s', result.entrycount) + (names !== '' ? ' ' + names + ' …' : '');
            if (bsConfirmModal) {
                bsConfirmModal.show();
            } else {
                confirmModalEl.style.display = 'block';
            }
        });

        el('coursepilot-location-selection-confirmfolder-ack').addEventListener('click', function() {
            closeConfirmModal();
            finalizeFolderSelection(true);
        });

        // --- Finish: client-side completeness check ---

        el('coursepilot-location-selection-form').addEventListener('submit', function(e) {
            if (!selections.context_area.type || !selections.material_store.type) {
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
