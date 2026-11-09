/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Permissions module — autocomplete search and add/remove users/groups.
 */
(function () {
    'use strict';

    var baseUrl = OC.generateUrl('/apps/appdrop/admin/permissions');
    var loaded = false;
    var searchTimeout = null;

    // Lazy load on section activation
    document.addEventListener('aum:tabactivated', function (e) {
        if (e.detail.tab === 'permissions' && !loaded) {
            loaded = true;
            loadPermissions();
        }
    });

    function loadPermissions() {
        fetch(baseUrl, {
            credentials: 'same-origin',
            headers: { 'requesttoken': getRequestToken() }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                showMessage('error', data.message || 'Failed to load permissions.');
                return;
            }
            renderGroupList(data.groups || []);
            renderUserList(data.users || []);
        })
        .catch(function (err) {
            showMessage('error', 'Failed to load permissions: ' + err.message);
        });
    }

    // ── Group list ──────────────────────────────────────────────────────────

    function renderGroupList(groups) {
        var list = document.getElementById('aum-perm-group-list');
        if (!list) return;
        if (groups.length === 0) {
            list.innerHTML = '<li class="aum-perm-empty">No groups added yet.</li>';
            return;
        }
        list.innerHTML = groups.map(function (groupId) {
            return '<li class="aum-perm-item">'
                + '<span class="aum-perm-item__name">' + escapeHtml(groupId) + '</span>'
                + '<button type="button" class="aum-btn aum-btn--danger aum-btn--small aum-perm-remove" '
                + 'data-type="group" data-id="' + escapeHtml(groupId) + '">Remove</button>'
                + '</li>';
        }).join('');

        list.querySelectorAll('.aum-perm-remove').forEach(function (btn) {
            btn.addEventListener('click', function () {
                removeEntry(this.dataset.type, this.dataset.id);
            });
        });
    }

    // ── User list ───────────────────────────────────────────────────────────

    function renderUserList(users) {
        var list = document.getElementById('aum-perm-user-list');
        if (!list) return;
        if (users.length === 0) {
            list.innerHTML = '<li class="aum-perm-empty">No users added yet.</li>';
            return;
        }
        list.innerHTML = users.map(function (userId) {
            return '<li class="aum-perm-item">'
                + '<span class="aum-perm-item__name">' + escapeHtml(userId) + '</span>'
                + '<button type="button" class="aum-btn aum-btn--danger aum-btn--small aum-perm-remove" '
                + 'data-type="user" data-id="' + escapeHtml(userId) + '">Remove</button>'
                + '</li>';
        }).join('');

        list.querySelectorAll('.aum-perm-remove').forEach(function (btn) {
            btn.addEventListener('click', function () {
                removeEntry(this.dataset.type, this.dataset.id);
            });
        });
    }

    // ── Add entry ───────────────────────────────────────────────────────────

    function addEntry(type, id) {
        var url = baseUrl + '/' + type + 's';
        var body = type === 'user' ? { userId: id } : { groupId: id };

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': getRequestToken()
            },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                loaded = false;
                loadPermissions();
                clearSearch(type);
            } else {
                showMessage('error', data.message || 'Failed to add.');
            }
        })
        .catch(function (err) {
            showMessage('error', 'Failed to add: ' + err.message);
        });
    }

    // ── Remove entry ────────────────────────────────────────────────────────

    function removeEntry(type, id) {
        var url = baseUrl + '/' + type + 's/remove';
        var body = type === 'user' ? { userId: id } : { groupId: id };

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': getRequestToken()
            },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                loaded = false;
                loadPermissions();
            } else {
                showMessage('error', data.message || 'Failed to remove.');
            }
        })
        .catch(function (err) {
            showMessage('error', 'Failed to remove: ' + err.message);
        });
    }

    // ── Autocomplete search ─────────────────────────────────────────────────

    function setupSearch(type) {
        var input = document.getElementById('aum-perm-' + type + '-search');
        var resultsDiv = document.getElementById('aum-perm-' + type + '-results');
        if (!input || !resultsDiv) return;

        input.addEventListener('input', function () {
            var term = this.value.trim();
            if (term.length < 2) {
                resultsDiv.innerHTML = '';
                resultsDiv.classList.remove('aum-autocomplete--visible');
                return;
            }

            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function () {
                var searchUrl = baseUrl + '/search/' + type + 's?term=' + encodeURIComponent(term);
                fetch(searchUrl, {
                    credentials: 'same-origin',
                    headers: { 'requesttoken': getRequestToken() }
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.results.length) {
                        resultsDiv.innerHTML = '<div class="aum-autocomplete__empty">No results</div>';
                        resultsDiv.classList.add('aum-autocomplete--visible');
                        return;
                    }
                    resultsDiv.innerHTML = data.results.map(function (item) {
                        var label = item.displayName
                            ? escapeHtml(item.displayName) + ' (' + escapeHtml(item.id) + ')'
                            : escapeHtml(item.id);
                        return '<div class="aum-autocomplete__item" data-id="' + escapeHtml(item.id) + '">'
                            + label + '</div>';
                    }).join('');
                    resultsDiv.classList.add('aum-autocomplete--visible');

                    resultsDiv.querySelectorAll('.aum-autocomplete__item').forEach(function (el) {
                        el.addEventListener('click', function () {
                            addEntry(type, this.dataset.id);
                        });
                    });
                })
                .catch(function () {
                    resultsDiv.innerHTML = '';
                    resultsDiv.classList.remove('aum-autocomplete--visible');
                });
            }, 300);
        });

        // Close autocomplete when clicking outside
        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !resultsDiv.contains(e.target)) {
                resultsDiv.innerHTML = '';
                resultsDiv.classList.remove('aum-autocomplete--visible');
            }
        });
    }

    function clearSearch(type) {
        var input = document.getElementById('aum-perm-' + type + '-search');
        var resultsDiv = document.getElementById('aum-perm-' + type + '-results');
        if (input) input.value = '';
        if (resultsDiv) {
            resultsDiv.innerHTML = '';
            resultsDiv.classList.remove('aum-autocomplete--visible');
        }
    }

    // Initialize search inputs
    setupSearch('user');
    setupSearch('group');

    // ── Per-app update grants ────────────────────────────────────────────────

    var grantsUrl = baseUrl + '/updates';
    var appsUrl = OC.generateUrl('/apps/appdrop/admin/apps');
    var appNames = {};

    document.addEventListener('aum:tabactivated', function (e) {
        if (e.detail.tab === 'permissions') {
            loadApps();
            loadGrants();
        }
    });

    function loadApps() {
        fetch(appsUrl, {
            credentials: 'same-origin',
            headers: { 'requesttoken': getRequestToken() }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var select = document.getElementById('aum-grant-app');
            if (!select || !data.success) return;
            var current = select.value;
            var apps = (data.apps || []).filter(function (a) { return a.id !== 'appdrop'; });
            appNames = {};
            select.innerHTML = '<option value="">' + escapeHtml(select.options[0] ? select.options[0].text : 'Select app...') + '</option>'
                + apps.map(function (a) {
                    appNames[a.id] = a.name;
                    return '<option value="' + escapeHtml(a.id) + '">' + escapeHtml(a.name) + ' (' + escapeHtml(a.id) + ', v' + escapeHtml(a.version) + ')</option>';
                }).join('');
            select.value = current;
        })
        .catch(function () { /* list stays empty */ });
    }

    function loadGrants() {
        fetch(grantsUrl, {
            credentials: 'same-origin',
            headers: { 'requesttoken': getRequestToken() }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                showMessage('error', data.message || 'Failed to load update permissions.');
                return;
            }
            renderGrants(data.grants || {});
        })
        .catch(function (err) {
            showMessage('error', 'Failed to load update permissions: ' + err.message);
        });
    }

    function renderGrants(grants) {
        var list = document.getElementById('aum-grant-list');
        if (!list) return;
        var appIds = Object.keys(grants);
        if (appIds.length === 0) {
            list.innerHTML = '<li class="aum-perm-empty">No per-app update permissions yet.</li>';
            return;
        }
        list.innerHTML = appIds.map(function (appId) {
            var g = grants[appId];
            var chips = (g.groups || []).map(function (id) { return chip(appId, 'group', id); })
                .concat((g.users || []).map(function (id) { return chip(appId, 'user', id); }))
                .join('');
            var label = appNames[appId] ? escapeHtml(appNames[appId]) + ' (' + escapeHtml(appId) + ')' : escapeHtml(appId);
            return '<li class="aum-perm-item aum-grant-app">'
                + '<span class="aum-perm-item__name">' + label + '</span>'
                + '<div class="aum-grant-app__entries">' + chips + '</div>'
                + '</li>';
        }).join('');

        list.querySelectorAll('[data-grant-remove]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                changeGrant('remove', this.dataset.app, this.dataset.type, this.dataset.id);
            });
        });
    }

    function chip(appId, type, id) {
        var icon = type === 'group' ? '👥' : '👤';
        return '<span class="aum-grant-chip">' + icon + ' ' + escapeHtml(id)
            + '<button type="button" data-grant-remove title="Remove" aria-label="Remove ' + escapeHtml(id) + '"'
            + ' data-app="' + escapeHtml(appId) + '" data-type="' + type + '" data-id="' + escapeHtml(id) + '">✕</button>'
            + '</span>';
    }

    function changeGrant(action, appId, type, id) {
        fetch(grantsUrl + (action === 'remove' ? '/remove' : ''), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': getRequestToken()
            },
            body: JSON.stringify({ appId: appId, type: type, id: id })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            showMessage(data.success ? 'success' : 'error', data.message || (data.success ? 'Saved.' : 'Failed.'));
            if (data.success) {
                loadGrants();
                if (action === 'add') {
                    var input = document.getElementById('aum-grant-search');
                    if (input) input.value = '';
                    hideGrantResults();
                }
            }
        })
        .catch(function (err) {
            showMessage('error', 'Failed to save: ' + err.message);
        });
    }

    function hideGrantResults() {
        var resultsDiv = document.getElementById('aum-grant-results');
        if (resultsDiv) {
            resultsDiv.innerHTML = '';
            resultsDiv.classList.remove('aum-autocomplete--visible');
        }
    }

    (function setupGrantSearch() {
        var input = document.getElementById('aum-grant-search');
        var typeSelect = document.getElementById('aum-grant-type');
        var appSelect = document.getElementById('aum-grant-app');
        var resultsDiv = document.getElementById('aum-grant-results');
        if (!input || !typeSelect || !appSelect || !resultsDiv) return;
        var timer = null;

        input.addEventListener('input', function () {
            var term = this.value.trim();
            if (term.length < 2) {
                hideGrantResults();
                return;
            }
            clearTimeout(timer);
            timer = setTimeout(function () {
                var type = typeSelect.value;
                fetch(baseUrl + '/search/' + type + 's?term=' + encodeURIComponent(term), {
                    credentials: 'same-origin',
                    headers: { 'requesttoken': getRequestToken() }
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.results.length) {
                        resultsDiv.innerHTML = '<div class="aum-autocomplete__empty">No results</div>';
                        resultsDiv.classList.add('aum-autocomplete--visible');
                        return;
                    }
                    resultsDiv.innerHTML = data.results.map(function (item) {
                        var label = item.displayName
                            ? escapeHtml(item.displayName) + ' (' + escapeHtml(item.id) + ')'
                            : escapeHtml(item.id);
                        return '<div class="aum-autocomplete__item" data-id="' + escapeHtml(item.id) + '">' + label + '</div>';
                    }).join('');
                    resultsDiv.classList.add('aum-autocomplete--visible');
                    resultsDiv.querySelectorAll('.aum-autocomplete__item').forEach(function (el) {
                        el.addEventListener('click', function () {
                            if (!appSelect.value) {
                                showMessage('error', 'Please select an app first.');
                                return;
                            }
                            changeGrant('add', appSelect.value, type, this.dataset.id);
                        });
                    });
                })
                .catch(hideGrantResults);
            }, 300);
        });

        typeSelect.addEventListener('change', function () {
            input.value = '';
            hideGrantResults();
        });

        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !resultsDiv.contains(e.target)) {
                hideGrantResults();
            }
        });
    }());

    // ── Helpers ──────────────────────────────────────────────────────────────

    function showMessage(type, msg) {
        var el = document.getElementById('aum-perm-message');
        if (!el) return;
        el.textContent = msg;
        el.className = 'aum-alert aum-alert--' + type;
    }

    function getRequestToken() {
        return (typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : '';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
}());
