/**
 * Das Dateifenster der Ortswahlseite (Issue #494, Spec #486 §5): reines
 * Client-Skript ohne Build-Schritt (kein AMD/Grunt in diesem Plugin) -
 * fetch() gegen ortswahl_browse.php, 8s-Timeout mit drei Ausweg-Aktionen,
 * Ordner-anlegen nur im Speicher des Browsers (serverseitig entsteht der
 * Ordner erst beim Abschliessen, siehe local_kurspilot\ortswahl_lib::apply()).
 *
 * Nie gespeichert, nie protokolliert: dieses Skript haelt Auflistungen nur
 * im laufenden DOM/JS-Zustand, schreibt nichts in localStorage o.ae.
 */
(function () {
    'use strict';

    var dataEl = document.getElementById('kurspilot-ortswahl-data');
    if (!dataEl) {
        return;
    }
    var config = JSON.parse(dataEl.textContent);

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

    var selections = {
        kontextbereich: Object.assign({}, config.targets.kontextbereich),
        materialbestand: Object.assign({}, config.targets.materialbestand)
    };

    function el(id) {
        return document.getElementById(id);
    }

    function pendingKey(instanceid) {
        return String(instanceid);
    }

    function isPending(instanceid, path) {
        var set = state.pendingFolders[pendingKey(instanceid)];
        return !!(set && set.indexOf(path) !== -1);
    }

    function addPending(instanceid, path) {
        var key = pendingKey(instanceid);
        if (!state.pendingFolders[key]) {
            state.pendingFolders[key] = [];
        }
        if (state.pendingFolders[key].indexOf(path) === -1) {
            state.pendingFolders[key].push(path);
        }
    }

    function joinPath(base, name) {
        return base === '' ? name : base + '/' + name;
    }

    // --- Fortschrittsband ------------------------------------------------

    function targetLabel(target) {
        return target === 'kontextbereich' ? config.strings.tabkontextbereich : config.strings.tabmaterialbestand;
    }

    function selectionDisplay(target) {
        var sel = selections[target];
        if (sel.type === 'moodle') {
            return sel.display || sel.path;
        }
        return sel.display || sel.path;
    }

    // Naeherung des Vergleichsschluessels (Issue #495, #497): fuer zwei
    // *live* auf dieser Seite gewaehlte Ziele reicht Instanz-Gleichheit
    // (bzw. beide "in Moodle") plus Pfad-Praefix - beide teilen sich
    // ohnehin dasselbe Konto/denselben Server. Die verbindliche Pruefung mit
    // dem echten Vergleichsschluessel laeuft serverseitig beim Abschliessen
    // (ortswahl_lib::apply()); dies ist nur der fruehe UI-Hinweis am Knopf.
    function normalisedPath(path) {
        var trimmed = (path || '').replace(/^\/+|\/+$/g, '');
        return trimmed === '' ? '/' : '/' + trimmed + '/';
    }

    function overlapsSameRoot(kontext, material) {
        if (kontext.type === 'moodle' && material.type === 'moodle') {
            return true;
        }
        return kontext.type === 'extern' && material.type === 'extern' && String(kontext.instanceid) === String(material.instanceid);
    }

    function computeOverlapLock() {
        var kontext = selections.kontextbereich;
        var material = selections.materialbestand;
        if (!kontext.type || !material.type || !overlapsSameRoot(kontext, material)) {
            return false;
        }
        return normalisedPath(material.path).indexOf(normalisedPath(kontext.path)) === 0;
    }

    function renderProgress() {
        var container = el('kurspilot-ortswahl-progress');
        container.innerHTML = '';
        ['kontextbereich', 'materialbestand'].forEach(function (target) {
            var sel = selections[target];
            var badge = document.createElement('span');
            var chosen = !!(sel && sel.type);
            badge.className = 'badge ' + (chosen ? 'bg-success' : 'bg-secondary');
            badge.textContent = targetLabel(target) + ': ' + (chosen
                ? config.strings.progresschosen.replace('%s', selectionDisplay(target))
                : config.strings.progressopen.replace('%s', targetLabel(target)));
            container.appendChild(badge);
        });

        var overlapLocked = computeOverlapLock();
        var overlapEl = el('kurspilot-ortswahl-overlaplock');
        overlapEl.hidden = !overlapLocked;
        overlapEl.textContent = overlapLocked ? config.strings.overlaplocked : '';

        el('kurspilot-ortswahl-finish').disabled = !(selections.kontextbereich.type && selections.materialbestand.type) || overlapLocked;
    }

    function applySelection(target, selection) {
        selections[target] = selection;
        el('kurspilot-ortswahl-' + target + '_type').value = selection.type;
        el('kurspilot-ortswahl-' + target + '_instanceid').value = selection.instanceid || '';
        el('kurspilot-ortswahl-' + target + '_path').value = selection.path || '';
        el('kurspilot-ortswahl-' + target + '_confirmed').value = selection.confirmed ? '1' : '';
        renderProgress();
    }

    // --- "In Moodle lassen" ----------------------------------------------

    document.querySelectorAll('[data-action="keep-moodle"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.getAttribute('data-target');
            applySelection(target, { type: 'moodle', path: config.targets[target].path, display: config.targets[target].display });
        });
    });

    // --- Dateifenster ------------------------------------------------------

    var modalEl = el('kurspilot-ortswahl-modal');
    var bsModal = (window.bootstrap && window.bootstrap.Modal) ? new window.bootstrap.Modal(modalEl) : null;
    // Own dismiss attribute: Moodle 5.0 has no window.bootstrap, and its data-bs-dismiss
    // handler cannot close a modal it never opened.
    modalEl.querySelectorAll('[data-kurspilot-dismiss="modal"]').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });

    function closeModal() {
        if (bsModal) {
            bsModal.hide();
        } else {
            modalEl.style.display = 'none';
        }
    }

    // Das Fenster oeffnet am Ort aus dem Pointer (Spec §5): ein bereits
    // extern gewaehltes Ziel browst sofort zu seiner Instanz/seinem Pfad;
    // ohne vorherige externe Wahl wird ersatzweise die erste eigene Instanz
    // vorgeladen ("die Wurzelebene vorgeladen").
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
        el('kurspilot-ortswahl-modal-title').textContent = config.strings.chooseinstance;
        renderInstances();
        el('kurspilot-ortswahl-breadcrumb').innerHTML = '';
        el('kurspilot-ortswahl-folders').innerHTML = '';
        if (bsModal) {
            bsModal.show();
        } else {
            modalEl.style.display = 'block';
        }
        if (state.instanceid !== null) {
            browse();
        }
    }

    document.querySelectorAll('[data-action="open-picker"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(btn.getAttribute('data-target'));
        });
    });

    function renderInstances() {
        var container = el('kurspilot-ortswahl-instances');
        container.innerHTML = '';
        var list = document.createElement('div');
        list.className = 'list-group';
        config.instances.forEach(function (instance) {
            var item = document.createElement('button');
            item.type = 'button';
            var selectable = instance.selectable !== false;
            item.className = 'list-group-item list-group-item-action' + (state.instanceid === instance.id ? ' active' : '');
            item.textContent = instance.name;
            if (!selectable) {
                item.disabled = true;
                item.title = instance.reason || '';
                item.className += ' text-muted';
            }
            item.addEventListener('click', function () {
                state.instanceid = instance.id;
                state.path = '';
                renderInstances();
                browse();
            });
            list.appendChild(item);
        });
        container.appendChild(list);
    }

    function renderBreadcrumb() {
        var container = el('kurspilot-ortswahl-breadcrumb');
        container.innerHTML = '';
        var segments = state.path === '' ? [] : state.path.split('/');
        var nav = document.createElement('nav');
        var ol = document.createElement('ol');
        ol.className = 'breadcrumb mb-0';

        function crumb(label, path, isLast) {
            var li = document.createElement('li');
            li.className = 'breadcrumb-item' + (isLast ? ' active' : '');
            if (isLast) {
                li.textContent = label;
            } else {
                var a = document.createElement('a');
                a.href = '#';
                a.textContent = label;
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    state.path = path;
                    browse();
                });
                li.appendChild(a);
            }
            ol.appendChild(li);
        }

        crumb(config.strings.breadcrumbroot, '', segments.length === 0);
        var accumulated = '';
        segments.forEach(function (segment, index) {
            accumulated = joinPath(accumulated, segment);
            crumb(segment, accumulated, index === segments.length - 1);
        });

        nav.appendChild(ol);
        container.appendChild(nav);
    }

    function renderFolders(folders) {
        var container = el('kurspilot-ortswahl-folders');
        container.innerHTML = '';
        var list = document.createElement('div');
        list.className = 'list-group';
        folders.forEach(function (folder) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action';
            item.textContent = folder.name;
            item.addEventListener('click', function () {
                state.path = joinPath(state.path, folder.name);
                if (isPending(state.instanceid, state.path)) {
                    renderBreadcrumb();
                    renderFolders([]);
                    // Im Fenster angelegter Ordner: immer leer und waehlbar,
                    // keine Uebergabe-Rueckfrage noetig (Spec §5).
                    applyBrowseResult({ path: state.path, folders: [], selectable: true, reason: '', entrycount: 0, entrynames: [] });
                } else {
                    browse();
                }
            });
            list.appendChild(item);
        });
        container.appendChild(list);
    }

    function showLoading() {
        el('kurspilot-ortswahl-folders').innerHTML =
            '<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>' + config.strings.loading + '</div>';
    }

    function showTimeout() {
        var container = el('kurspilot-ortswahl-folders');
        container.innerHTML = '';
        var box = document.createElement('div');
        box.className = 'alert alert-warning';
        var title = document.createElement('strong');
        title.textContent = config.strings.timeouttitle;
        var text = document.createElement('p');
        text.className = 'mb-2';
        text.textContent = config.strings.timeouttext;
        box.appendChild(title);
        box.appendChild(text);

        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-sm btn-primary me-2';
        retry.textContent = config.strings.retry;
        retry.addEventListener('click', browse);
        box.appendChild(retry);

        var check = document.createElement('a');
        check.className = 'btn btn-sm btn-outline-secondary me-2';
        check.href = config.manageinstancesurl;
        check.target = '_blank';
        check.rel = 'noopener';
        check.textContent = config.strings.checkcredentials;
        box.appendChild(check);

        var later = document.createElement('button');
        later.type = 'button';
        later.className = 'btn btn-sm btn-outline-secondary';
        later.textContent = config.strings.later;
        later.addEventListener('click', closeModal);
        box.appendChild(later);

        container.appendChild(box);
    }

    // --- Sperren einer Ebene (Issue #497, Spec §5): Wurzel, IServ ---------

    function applyBrowseResult(result) {
        state.lastResult = result;
        var locked = result.selectable === false;
        var reasonEl = el('kurspilot-ortswahl-modal-reason');
        reasonEl.hidden = !locked;
        reasonEl.textContent = locked ? (result.reason || '') : '';
        el('kurspilot-ortswahl-confirmfolder').disabled = locked;
        el('kurspilot-ortswahl-createfolder').disabled = locked;
    }

    function browse() {
        if (state.instanceid === null) {
            return;
        }
        renderBreadcrumb();
        showLoading();

        // POST statt GET (Spec §5: "Auflistungen werden ... nicht
        // protokolliert") - instanceid/Pfad/Sesskey landen so nicht in der
        // Query-String-Zeile eines Webserver-Zugriffsprotokolls.
        var body = 'instanceid=' + encodeURIComponent(state.instanceid)
            + '&path=' + encodeURIComponent(state.path)
            + '&sesskey=' + encodeURIComponent(config.sesskey);

        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var timedOut = false;
        var timer = setTimeout(function () {
            timedOut = true;
            if (controller) {
                controller.abort();
            }
        }, config.timeoutms);

        fetch(config.browseurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
            signal: controller ? controller.signal : undefined
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                clearTimeout(timer);
                if (!result.ok) {
                    showTimeout();
                    return;
                }
                renderFolders(result.folders || []);
                applyBrowseResult(result);
            })
            .catch(function () {
                clearTimeout(timer);
                if (timedOut) {
                    showTimeout();
                }
            });
    }

    // --- Ordner anlegen (nur im Browser, siehe Moduldoc) -----------------

    el('kurspilot-ortswahl-createfolder').addEventListener('click', function () {
        var input = el('kurspilot-ortswahl-newfolder');
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
        renderFolders([]);
    });

    // --- Ordner waehlen, mit Uebergabe-Bestaetigung eines gefuellten Ordners
    // (Issue #497, Spec §5: nur der Kontextbereich fragt nach; ein leerer
    // oder im Fenster angelegter Ordner braucht keine Rueckfrage) ----------

    var confirmModalEl = el('kurspilot-ortswahl-confirm-modal');
    var bsConfirmModal = (window.bootstrap && window.bootstrap.Modal) ? new window.bootstrap.Modal(confirmModalEl) : null;
    confirmModalEl.querySelectorAll('[data-kurspilot-dismiss="modal"]').forEach(function (btn) {
        btn.addEventListener('click', closeConfirmModal);
    });

    function closeConfirmModal() {
        if (bsConfirmModal) {
            bsConfirmModal.hide();
        } else {
            confirmModalEl.style.display = 'none';
        }
    }

    function finalizeFolderSelection(confirmed) {
        var instance = config.instances.filter(function (i) {
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

    el('kurspilot-ortswahl-confirmfolder').addEventListener('click', function () {
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
        el('kurspilot-ortswahl-confirm-count').textContent =
            config.strings.confirmcount.replace('%s', result.entrycount) + (names !== '' ? ' ' + names + ' …' : '');
        if (bsConfirmModal) {
            bsConfirmModal.show();
        } else {
            confirmModalEl.style.display = 'block';
        }
    });

    el('kurspilot-ortswahl-confirmfolder-ack').addEventListener('click', function () {
        closeConfirmModal();
        finalizeFolderSelection(true);
    });

    // --- Abschliessen: Client-seitige Vollstaendigkeitspruefung ----------

    el('kurspilot-ortswahl-form').addEventListener('submit', function (e) {
        if (!selections.kontextbereich.type || !selections.materialbestand.type) {
            e.preventDefault();
            window.alert(config.strings.selectionincomplete);
        }
    });

    renderProgress();
}());
