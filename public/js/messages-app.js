/*
 * Messages box. Private chats, group chats, the Admin's announcements, files, editing and deleting.
 *  - polling only (no websockets): about every 6 seconds while the box is open, every 45 seconds for the badge while it is closed
 *  - every message is written to the page as plain text; only http and https addresses become links
 *  - photos and videos are shown inside the chat; any other file is a download
 * The box and its open/close behaviour are in messages-panel.js; this file fills it with conversations.
 */
(function () {
    'use strict';

    var panel = document.getElementById('msgPanel');
    if (!panel || !panel.dataset.base || !window.skMessages) { return; }

    var base = panel.dataset.base;
    var isAdminPage = panel.dataset.admin === '1';
    var MAX_MB = parseFloat(panel.dataset.maxMb) || 50;
    var MAX_FILES = parseInt(panel.dataset.maxFiles, 10) || 10;
    var BLOCKED = [];
    try { BLOCKED = JSON.parse(panel.dataset.blocked || '[]'); } catch (e) { BLOCKED = []; }
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var token = csrfMeta ? csrfMeta.content : '';

    function byId(id) { return document.getElementById(id); }
    var el = {
        title: byId('msgTitle'), sub: byId('msgSub'), back: byId('msgBack'), hide: byId('msgHide'), info: byId('msgInfo'),
        listView: byId('msgListView'), threadView: byId('msgThreadView'), groupView: byId('msgGroupView'), membersView: byId('msgMembersView'),
        find: byId('msgFind'), list: byId('msgList'),
        thread: byId('msgThread'), jump: byId('msgJump'), note: byId('msgNote'),
        chips: byId('msgChips'), compose: byId('msgCompose'), input: byId('msgInput'), send: byId('msgSend'),
        attach: byId('msgAttach'), file: byId('msgFile'),
        tabAll: byId('msgTabAll'), tabGroup: byId('msgTabGroup'), tabGroupCount: byId('msgTabGroupCount'),
        gForm: byId('msgGroupForm'), gTitle: byId('msgGroupTitle'), gFind: byId('msgGroupFind'), gChosen: byId('msgGroupChosen'),
        gResults: byId('msgGroupResults'), gNote: byId('msgGroupNote'), gCreate: byId('msgGroupCreate'),
        members: byId('msgMembersBody')
    };

    var S = {
        view: 'list',          // list | thread | groupnew | members
        tab: 'all',            // the footer of the list: all | group
        conv: null,            // the open chat: { id, kind, with, can_send, can_manage, members }
        convId: null,
        canSend: true,
        lastId: 0,             // newest message shown in the open chat
        firstId: 0,            // oldest message shown
        latestId: 0,           // newest message that is not deleted (only that one can be edited)
        since: '',             // server time of the last answer: the next poll asks what was edited or deleted after it
        lastRead: 0,           // newest message already reported as read
        hasMore: false,
        earlierBtn: null,
        msgs: {},              // the messages on screen, by id
        seen: [],              // who has seen my messages in the open chat
        editing: 0,
        pinned: true,          // the chat is scrolled to the bottom
        chats: [],
        chatsSig: '',
        canCreate: false,
        suggest: null,         // people shown when there are no chats yet
        query: '',
        searchResults: null,
        searchTimer: null,
        pollP: null,
        sending: false,
        stopped: false,
        timer: null,
        pending: [],           // files chosen for the next message
        uploading: null,
        seq: 0,
        gSel: {},              // people chosen for a new group, by id
        gResults: null,
        gTimer: null,
        mData: null,           // members of the open group
        mResults: null,
        mTimer: null
    };

    // ------------------------------------------------------------------ small helpers

    function h(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }
    function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }
    function noop() {}
    function initial(name) { var t = String(name || '?').trim(); return t ? t.charAt(0).toUpperCase() : '?'; }
    function metaOf(p) { return [p.role, p.place].filter(Boolean).join(' \u00b7 '); }
    function isOpen() { return window.skMessages.isOpen(); }
    // the chat is really in front of the person: the box is open, the tab is visible, and a chat (not a list) is showing
    function onScreen() { return isOpen() && !document.hidden && S.view === 'thread'; }
    function fmtSize(n) {
        if (n >= 1048576) { return (n / 1048576).toFixed(n >= 10485760 ? 0 : 1) + ' MB'; }
        if (n >= 1024) { return Math.round(n / 1024) + ' KB'; }
        return n + ' B';
    }

    function friendly(status, data) {
        if (status === 419) { return 'Your session expired. Please refresh the page.'; }
        if (status === 401) { return 'You were signed out. Please sign in again.'; }
        if (status === 413) { return 'That file is bigger than this server allows.'; }
        if (status === 429) { return 'Too many requests. Please wait a moment.'; }
        if (data && data.errors) {
            var keys = Object.keys(data.errors);
            if (keys.length && data.errors[keys[0]].length) { return data.errors[keys[0]][0]; }
        }
        if (data && data.message) { return data.message; }
        return 'Something went wrong. Please try again.';
    }

    function api(method, path, body) {
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
        if (method !== 'GET') {
            opts.headers['X-CSRF-TOKEN'] = token;
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body || {});
        }
        return fetch(base + path, opts).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) {
                    var err = new Error(friendly(res.status, data));
                    err.status = res.status;
                    throw err;
                }
                return data;
            });
        });
    }

    function onError(e) {
        if (e && (e.status === 401 || e.status === 419)) { S.stopped = true; }
    }

    // Plain text only. http(s) addresses become links; everything else stays text.
    var URL_RE = /(https?:\/\/[^\s<>"]+)/g;
    function linkify(node, text) {
        String(text).split(URL_RE).forEach(function (part, i) {
            if (i % 2 === 1) {
                var trail = '';
                var m = part.match(/[.,;:!?)\]}'"]+$/);
                if (m) { trail = m[0]; part = part.slice(0, part.length - trail.length); }
                var a = document.createElement('a');
                a.href = part; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = part;
                node.appendChild(a);
                if (trail) { node.appendChild(document.createTextNode(trail)); }
            } else if (part) {
                node.appendChild(document.createTextNode(part));
            }
        });
    }

    function setNote(text, isError) {
        el.note.hidden = !text;
        el.note.textContent = text || '';
        el.note.classList.toggle('is-error', !!isError);
    }

    // ------------------------------------------------------------------ views

    function setView(v) {
        S.view = v;
        el.listView.hidden = v !== 'list';
        el.threadView.hidden = v !== 'thread';
        el.groupView.hidden = v !== 'groupnew';
        el.membersView.hidden = v !== 'members';
        showHead();
    }

    function showHead() {
        var c = S.conv;
        el.back.hidden = S.view === 'list';
        el.hide.hidden = !(S.view === 'thread' && c && c.kind !== 'announcement');
        el.info.hidden = !(S.view === 'thread' && c && c.kind === 'group');

        var title = 'Messages', sub = '';
        if (S.view === 'thread' && c) {
            title = c.with.name;
            sub = c.kind === 'group' ? 'Group chat \u00b7 ' + (c.members || 0) + ' ' + (c.members === 1 ? 'member' : 'members') : metaOf(c.with);
        } else if (S.view === 'groupnew') {
            title = 'New group';
        } else if (S.view === 'members' && c) {
            title = c.with.name;
            sub = 'Members';
        }
        el.title.textContent = title;
        el.sub.hidden = !sub;
        el.sub.textContent = sub;
    }

    // ------------------------------------------------------------------ the list view

    function item(o) {
        var b = h('button', 'msg-item');
        b.type = 'button';
        var av = h('span', 'msg-avatar' + (o.admin ? ' is-admin' : '') + (o.group ? ' is-group' : '') + (o.announce ? ' is-announce' : ''), o.announce ? '!' : initial(o.name));
        av.setAttribute('aria-hidden', 'true');
        var body = h('span', 'msg-item-body');
        var top = h('span', 'msg-item-top');
        top.appendChild(h('span', 'msg-item-name', o.name));
        if (o.time) { top.appendChild(h('span', 'msg-item-time', o.time)); }
        var bottom = h('span', 'msg-item-bottom');
        bottom.appendChild(h('span', 'msg-item-text', o.text || ''));
        if (o.unread > 0) {
            var c = h('span', 'msg-count', o.unread > 99 ? '99+' : String(o.unread));
            c.setAttribute('aria-label', o.unread + ' unread');
            bottom.appendChild(c);
        }
        body.appendChild(top);
        body.appendChild(bottom);
        b.appendChild(av);
        b.appendChild(body);
        b.addEventListener('click', o.onClick);
        return b;
    }

    function section(label) { return h('p', 'msg-section', label); }
    function emptyBox(title, text) {
        var d = h('div', 'msg-empty');
        d.appendChild(h('strong', '', title));
        if (text) { d.appendChild(h('p', '', text)); }
        return d;
    }

    function peopleItems(people) {
        return people.map(function (p) {
            return item({
                name: p.name, text: metaOf(p), admin: p.type === 'admin',
                onClick: function () { startWith(p); }
            });
        });
    }

    function chatItem(c) {
        return item({
            name: c.with.name,
            text: c.kind === 'announcement' && !c.preview ? 'From the SK Federation President' : c.preview,
            time: c.at, unread: c.unread,
            admin: c.with.type === 'admin', group: c.kind === 'group', announce: c.kind === 'announcement',
            onClick: function () { openThread(c.id, c.with, c.kind); }
        });
    }

    function renderTabs() {
        var unread = 0;
        S.chats.forEach(function (c) { if (c.kind === 'group') { unread += c.unread; } });
        if (el.tabGroupCount) {
            el.tabGroupCount.hidden = unread === 0;
            el.tabGroupCount.textContent = unread > 99 ? '99+' : String(unread);
        }
    }

    function renderList() {
        var keep = el.list.scrollTop;
        clear(el.list);

        // the Group tab: the group chats, and the button to make one (the Admin and the Chairmen)
        if (S.tab === 'group') {
            if (S.canCreate) {
                var nb = h('button', 'msg-newgroup', '+ New group');
                nb.type = 'button';
                nb.addEventListener('click', openGroupCreate);
                el.list.appendChild(nb);
            }
            var groups = S.chats.filter(function (c) { return c.kind === 'group'; });
            if (groups.length) {
                groups.forEach(function (c) { el.list.appendChild(chatItem(c)); });
            } else {
                el.list.appendChild(emptyBox('No group chats yet', S.canCreate
                    ? 'Use "New group" to chat with several people at once.'
                    : 'Group chats you are added to by your SK Chairman or the Admin will appear here.'));
            }
            el.list.scrollTop = 0;
            return;
        }

        if (S.query) {
            if (S.query.length < 2) {
                el.list.appendChild(emptyBox('Keep typing', 'Type at least two letters of a name, position or barangay.'));
            } else if (S.searchResults === null) {
                el.list.appendChild(emptyBox('Searching\u2026'));
            } else if (!S.searchResults.length) {
                el.list.appendChild(emptyBox('No one found', 'Try another name, position or barangay.'));
            } else {
                el.list.appendChild(section('People'));
                peopleItems(S.searchResults).forEach(function (n) { el.list.appendChild(n); });
            }
        } else {
            // the announcements are always the first line; the rest follow
            var real = S.chats.filter(function (c) { return c.kind !== 'announcement'; });
            S.chats.filter(function (c) { return c.kind === 'announcement'; }).forEach(function (c) { el.list.appendChild(chatItem(c)); });

            if (real.length) {
                real.forEach(function (c) { el.list.appendChild(chatItem(c)); });
            } else if (S.suggest && S.suggest.length) {
                el.list.appendChild(emptyBox('No conversations yet', 'Pick someone below, or search for a person above.'));
                el.list.appendChild(section('People you can message'));
                peopleItems(S.suggest).forEach(function (n) { el.list.appendChild(n); });
            } else {
                el.list.appendChild(emptyBox('No conversations yet', isAdminPage
                    ? 'Type a name, position or barangay above to find someone.'
                    : 'Search for a person above to start a conversation.'));
            }
        }

        el.list.scrollTop = keep;
    }

    function loadSuggestions() {
        if (S.suggest !== null) { return; }
        S.suggest = [];
        api('GET', '/people').then(function (data) {
            S.suggest = data.people || [];
            if (S.view === 'list' && !S.query && S.tab === 'all') { renderList(); }
        }).catch(onError);
    }

    // the footer of the list: All chats | Group
    function setTab(tab) {
        S.tab = tab;
        [[el.tabAll, 'all'], [el.tabGroup, 'group']].forEach(function (pair) {
            var on = pair[1] === tab;
            pair[0].classList.toggle('is-active', on);
            pair[0].setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        el.find.parentNode.hidden = tab === 'group';   // the search is for finding people to chat with
        renderList();
    }
    if (el.tabAll && el.tabGroup) {
        el.tabAll.addEventListener('click', function () { setTab('all'); });
        el.tabGroup.addEventListener('click', function () { setTab('group'); });
    }

    el.find.addEventListener('input', function () {
        S.query = el.find.value.trim();
        clearTimeout(S.searchTimer);
        S.searchResults = null;
        renderList();
        if (S.query.length < 2) { return; }
        var asked = S.query;
        S.searchTimer = setTimeout(function () {
            api('GET', '/people?q=' + encodeURIComponent(asked)).then(function (data) {
                if (S.query !== asked) { return; }
                S.searchResults = data.people || [];
                renderList();
            }).catch(function (e) { onError(e); S.searchResults = []; renderList(); });
        }, 250);
    });

    function startWith(person) {
        api('POST', '/direct', { type: person.type, id: person.id }).then(function (res) {
            el.find.value = ''; S.query = ''; S.searchResults = null;
            openThread(res.id, person, 'direct');
        }).catch(function (e) { onError(e); setHeadError(e.message); });
    }

    function setHeadError(text) {
        el.list.insertBefore(h('p', 'msg-note is-error', text), el.list.firstChild);
    }

    // ------------------------------------------------------------------ the chat view

    function openThread(id, who, kind) {
        discardPending();
        S.convId = id;
        S.conv = { id: id, kind: kind || 'direct', with: who, can_send: true, can_manage: false, members: 0 };
        S.lastId = 0; S.firstId = 0; S.latestId = 0; S.since = ''; S.lastRead = 0; S.hasMore = false; S.earlierBtn = null;
        S.msgs = {}; S.seen = []; S.editing = 0; S.pinned = true; S.canSend = true;
        clear(el.thread);
        el.thread.appendChild(h('p', 'msg-loading', 'Loading\u2026'));
        el.jump.hidden = true;
        setNote('');
        el.input.value = '';
        setView('thread');
        autosize();   // measured only now that the chat is visible (while hidden it measures 0 and the field collapses)
        applyCompose();

        // "seen=1": opening the chat counts as reading it, in this very request (it cannot get lost between two requests)
        var seenNow = onScreen();
        api('GET', '/' + id + (seenNow ? '?seen=1' : '')).then(function (data) {
            if (S.convId !== id) { return; }
            clear(el.thread);
            updateConversation(data.conversation);
            S.latestId = data.latest_id || 0;
            S.since = data.server_time || '';
            S.hasMore = !!data.has_more;
            syncEarlier();
            appendMessages(data.messages, true);
            S.seen = data.seen || [];
            renderSeen();
            if (seenNow && S.lastId) { S.lastRead = S.lastId; pollState().catch(noop); }   // the icon and the list clear right away
            markRead();
            if (window.innerWidth > 900 && S.canSend) { el.input.focus({ preventScroll: true }); }
        }).catch(function (e) {
            onError(e);
            if (S.convId !== id) { return; }
            clear(el.thread);
            el.thread.appendChild(h('p', 'msg-loading is-error', e.message));
        });
    }

    function showList() {
        discardPending();
        S.convId = null;
        S.conv = null;
        setView('list');
        renderList();
        refreshNow();
        el.list.focus({ preventScroll: true });
    }

    function updateConversation(c) {
        if (!c) { return; }
        S.conv = c;
        S.canSend = !!c.can_send;
        showHead();
        applyCompose();
    }

    function applyCompose() {
        var announceReadOnly = !!S.conv && S.conv.kind === 'announcement' && !S.canSend;
        el.compose.hidden = announceReadOnly;
        el.chips.hidden = announceReadOnly || !S.pending.length;
        el.input.disabled = !S.canSend;
        el.attach.disabled = !S.canSend;
        updateSend();

        if (announceReadOnly) {
            setNote('Only the SK Federation President can post announcements.', false);
        } else if (!S.canSend) {
            setNote('This account is no longer active, so you cannot send messages here.', false);
        } else if (/no longer active|can post announcements/.test(el.note.textContent)) {
            setNote('');
        }
    }

    function nearBottom() {
        return el.thread.scrollHeight - el.thread.scrollTop - el.thread.clientHeight < 90;
    }
    function toBottom() {
        el.thread.scrollTop = el.thread.scrollHeight;
        el.jump.hidden = true;
        S.pinned = true;
    }

    // a photo or video that loads after the chat was drawn must not push the chat away from the bottom
    function keepBottom(node, eventName) {
        node.addEventListener(eventName, function () { if (S.pinned) { toBottom(); } });
    }

    // ---- one message ----

    function filesBox(files) {
        var box = h('div', 'msg-files');
        files.forEach(function (f) {
            if (f.kind === 'image') {
                var a = h('a', 'msg-media');
                a.href = f.url; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.title = f.name;
                var img = document.createElement('img');
                img.src = f.url; img.alt = f.name; img.loading = 'lazy';
                keepBottom(img, 'load');
                a.appendChild(img);
                box.appendChild(a);
            } else if (f.kind === 'video') {
                var v = document.createElement('video');
                v.className = 'msg-video'; v.controls = true; v.preload = 'metadata'; v.src = f.url; v.title = f.name;
                v.setAttribute('playsinline', '');
                keepBottom(v, 'loadedmetadata');
                box.appendChild(v);
            } else {
                var d = h('a', 'msg-file');
                d.href = f.download_url; d.setAttribute('download', f.name); d.title = 'Download ' + f.name;
                d.appendChild(h('span', 'msg-file-icon', (f.name.split('.').pop() || 'file').slice(0, 4).toUpperCase()));
                var t = h('span', 'msg-file-text');
                t.appendChild(h('span', 'msg-file-name', f.name));
                t.appendChild(h('span', 'msg-file-size', fmtSize(f.size)));
                d.appendChild(t);
                box.appendChild(d);
            }
        });
        return box;
    }

    function canEditNow(m) {
        return !!m && m.mine && !m.deleted && m.id === S.latestId && S.canSend;
    }

    function row(m) {
        var wrap = h('div', 'msg-row ' + (m.mine ? 'is-mine' : 'is-theirs') + (m.deleted ? ' is-deleted' : ''));
        wrap.dataset.id = m.id;
        var bubble = h('div', 'msg-bubble');

        if (m.sender && !m.mine) {
            var s = h('p', 'msg-sender', m.sender.name);
            if (m.sender.role) { s.title = m.sender.role; }
            bubble.appendChild(s);
        }
        if (m.deleted) {
            bubble.appendChild(h('p', 'msg-text is-deleted', 'This message was deleted'));
        } else {
            if (m.body) {
                var p = h('p', 'msg-text');
                linkify(p, m.body);
                bubble.appendChild(p);
            }
            if (m.attachments && m.attachments.length) { bubble.appendChild(filesBox(m.attachments)); }
        }
        var meta = h('div', 'msg-meta');
        if (m.edited) {
            var eb = h('button', 'msg-edited', 'Edited');
            eb.type = 'button';
            eb.setAttribute('aria-expanded', 'false');
            eb.title = 'See the earlier versions of this message';
            eb.addEventListener('click', function (e) { e.stopPropagation(); toggleHistory(m, bubble, eb); });
            meta.appendChild(eb);
        }
        var t = h('time', 'msg-time', m.at);
        t.dateTime = m.iso;
        meta.appendChild(t);
        bubble.appendChild(meta);

        if (m.mine && !m.deleted) {
            var more = h('button', 'msg-more', '\u22ef');
            more.type = 'button';
            more.setAttribute('aria-label', 'Message options');
            more.setAttribute('aria-haspopup', 'true');
            more.setAttribute('aria-expanded', 'false');
            more.addEventListener('click', function (e) { e.stopPropagation(); toggleMenu(m, wrap, more); });
            wrap.appendChild(more);
        }
        wrap.appendChild(bubble);
        return wrap;
    }

    function rowEl(id) { return el.thread.querySelector('.msg-row[data-id="' + id + '"]'); }

    // like Messenger: tap "Edited" to see what the message said before
    function toggleHistory(m, bubble, btn) {
        var open = bubble.querySelector('.msg-history');
        if (open) { open.remove(); btn.setAttribute('aria-expanded', 'false'); return; }

        var box = h('div', 'msg-history');
        box.appendChild(h('p', 'msg-history-note', 'Loading\u2026'));
        bubble.insertBefore(box, bubble.querySelector('.msg-meta'));
        btn.setAttribute('aria-expanded', 'true');

        api('GET', '/' + S.convId + '/messages/' + m.id + '/edits').then(function (d) {
            clear(box);
            if (!d.edits.length) { box.appendChild(h('p', 'msg-history-note', 'No earlier version was kept for this message.')); return; }
            box.appendChild(h('p', 'msg-history-title', d.edits.length === 1 ? 'Earlier version' : 'Earlier versions'));
            d.edits.forEach(function (e) {
                var one = h('div', 'msg-old');
                one.appendChild(h('span', 'msg-old-time', 'Changed ' + e.at));
                one.appendChild(h('p', 'msg-old-text', e.body));
                box.appendChild(one);
            });
        }).catch(function (e) { onError(e); clear(box); box.appendChild(h('p', 'msg-history-note', e.message)); });
    }

    // "Seen" under the last message each person has looked at (only for your own messages)
    function renderSeen() {
        Array.prototype.forEach.call(el.thread.querySelectorAll('.msg-seen-line'), function (n) { n.remove(); });
        (S.seen || []).forEach(function (s) {
            var row = rowEl(s.message_id);
            if (!row || !row.parentNode) { return; }
            var line = h('p', 'msg-seen-line', s.label);
            if (s.names && s.names.length) { line.title = s.names.join(', '); }
            row.parentNode.insertBefore(line, row.nextSibling);
        });
    }

    // draw a message again after it was edited or deleted (here, or by the other person)
    function setRow(m) {
        S.msgs[m.id] = m;
        var old = rowEl(m.id);
        var fresh = row(m);
        if (old && old.parentNode) { old.parentNode.replaceChild(fresh, old); }
        renderSeen();
        return fresh;
    }

    // add newer messages at the bottom (the first load and every poll)
    function appendMessages(messages, forceBottom) {
        var stick = forceBottom || nearBottom();
        var fromOther = false;
        messages.forEach(function (m) {
            if (m.id <= S.lastId) { return; }
            S.msgs[m.id] = m;
            el.thread.appendChild(row(m));
            S.lastId = m.id;
            if (!S.firstId) { S.firstId = m.id; }
            if (!m.mine) { fromOther = true; }
        });
        if (stick) { toBottom(); } else if (fromOther) { el.jump.hidden = false; }
    }

    // add older messages at the top without moving what the person is reading
    function prependMessages(messages) {
        var before = el.thread.scrollHeight;
        var anchor = S.earlierBtn ? S.earlierBtn.nextSibling : el.thread.firstChild;
        messages.forEach(function (m) { S.msgs[m.id] = m; el.thread.insertBefore(row(m), anchor); });
        if (messages.length) { S.firstId = messages[0].id; }
        el.thread.scrollTop += el.thread.scrollHeight - before;
    }

    function syncEarlier() {
        if (S.hasMore && !S.earlierBtn) {
            var b = h('button', 'msg-earlier', 'Show earlier messages');
            b.type = 'button';
            b.addEventListener('click', loadEarlier);
            el.thread.insertBefore(b, el.thread.firstChild);
            S.earlierBtn = b;
        } else if (!S.hasMore && S.earlierBtn) {
            S.earlierBtn.remove();
            S.earlierBtn = null;
        }
    }

    function loadEarlier() {
        var id = S.convId;
        if (!id || !S.firstId) { return; }
        S.earlierBtn.disabled = true;
        api('GET', '/' + id + '?before=' + S.firstId).then(function (data) {
            if (S.convId !== id) { return; }
            S.hasMore = !!data.has_more;
            prependMessages(data.messages);
            syncEarlier();
            if (S.earlierBtn) { S.earlierBtn.disabled = false; }
        }).catch(function (e) { onError(e); if (S.earlierBtn) { S.earlierBtn.disabled = false; } setNote(e.message, true); });
    }

    // only the messages newer than the last one on screen, and what was edited or deleted since the last answer
    function pollThread(forceBottom) {
        var id = S.convId;
        if (!id) { return Promise.resolve(); }
        if (S.pollP) { return S.pollP.then(function () { return pollThread(forceBottom); }); }
        var seenNow = onScreen();
        var path = '/' + id + '?after=' + S.lastId + (S.since ? '&since=' + encodeURIComponent(S.since) : '') + (seenNow ? '&seen=1' : '');
        var p = api('GET', path).then(function (data) {
            if (S.convId !== id) { return; }
            updateConversation(data.conversation);
            S.latestId = data.latest_id || 0;
            if (data.server_time) { S.since = data.server_time; }
            (data.changed || []).forEach(function (m) {
                if (S.msgs[m.id] && S.editing !== m.id) { setRow(m); }
            });
            if (data.messages.length) {
                appendMessages(data.messages, forceBottom);
                if (seenNow) { S.lastRead = Math.max(S.lastRead, S.lastId); }
            }
            S.seen = data.seen || [];
            renderSeen();
            markRead();
        });
        S.pollP = p.then(function () { S.pollP = null; }, function (e) { S.pollP = null; throw e; });
        return S.pollP;
    }

    // tell the server what has been seen, only while the box is open, on screen, and showing this chat
    function markRead() {
        if (S.view !== 'thread' || !S.convId || !S.lastId || !isOpen() || document.hidden) { return; }
        if (S.lastRead >= S.lastId) { return; }
        var id = S.convId, upTo = S.lastId;
        S.lastRead = upTo;
        api('POST', '/' + id + '/read', { message_id: upTo }).then(function () { pollState().catch(noop); })
            .catch(function (e) { onError(e); if (S.convId === id) { S.lastRead = 0; } });
    }

    el.thread.addEventListener('scroll', function () {
        S.pinned = nearBottom();
        if (S.pinned) { el.jump.hidden = true; markRead(); }
    });
    el.jump.addEventListener('click', function () { toBottom(); markRead(); });

    // ---- the options of one of your own messages: edit (only the latest) and delete ----

    function closeMenus() {
        Array.prototype.forEach.call(el.thread.querySelectorAll('.msg-menu'), function (m) { m.remove(); });
        Array.prototype.forEach.call(el.thread.querySelectorAll('.msg-more[aria-expanded="true"]'), function (b) { b.setAttribute('aria-expanded', 'false'); });
    }
    document.addEventListener('click', closeMenus);

    function toggleMenu(m, wrap, btn) {
        var wasOpen = btn.getAttribute('aria-expanded') === 'true';
        closeMenus();
        if (wasOpen) { return; }

        var menu = h('div', 'msg-menu');
        menu.setAttribute('role', 'menu');
        menu.addEventListener('click', function (e) { e.stopPropagation(); });
        menu.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.stopPropagation(); closeMenus(); btn.focus(); }
        });

        var current = S.msgs[m.id] || m;
        if (canEditNow(current)) {
            var ed = h('button', 'msg-menu-item', 'Edit');
            ed.type = 'button'; ed.setAttribute('role', 'menuitem');
            ed.addEventListener('click', function () { closeMenus(); startEdit(current); });
            menu.appendChild(ed);
        }
        var del = h('button', 'msg-menu-item is-danger', 'Delete');
        del.type = 'button'; del.setAttribute('role', 'menuitem');
        del.addEventListener('click', function () { closeMenus(); deleteMessage(current); });
        menu.appendChild(del);
        if (!canEditNow(current)) {
            menu.appendChild(h('p', 'msg-menu-hint', 'Only your latest message can be edited.'));
        }

        btn.setAttribute('aria-expanded', 'true');
        wrap.appendChild(menu);
        if (menu.scrollIntoView) { menu.scrollIntoView({ block: 'nearest' }); }
        (menu.querySelector('button') || btn).focus();
    }

    function startEdit(m) {
        var wrap = rowEl(m.id);
        if (!wrap) { return; }
        var bubble = wrap.querySelector('.msg-bubble');
        S.editing = m.id;
        clear(bubble);

        var ta = h('textarea', 'msg-edit');
        ta.value = m.body;
        ta.maxLength = 2000;
        ta.rows = 2;
        ta.setAttribute('aria-label', 'Edit your message');
        var bar = h('div', 'msg-edit-bar');
        var err = h('span', 'msg-edit-error');
        var save = h('button', 'msg-edit-save', 'Save');
        save.type = 'button';
        var cancel = h('button', 'msg-edit-cancel', 'Cancel');
        cancel.type = 'button';
        bar.appendChild(err); bar.appendChild(cancel); bar.appendChild(save);
        bubble.appendChild(ta);
        bubble.appendChild(bar);
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);

        function done() { S.editing = 0; setRow(S.msgs[m.id] || m); }
        function commit() {
            var text = ta.value.replace(/\s+$/, '');
            if (!text.trim() && !(m.attachments && m.attachments.length)) { err.textContent = 'A message cannot be empty. Delete it instead.'; return; }
            if (text === m.body) { done(); return; }
            save.disabled = true;
            api('PATCH', '/' + S.convId + '/messages/' + m.id, { body: text }).then(function (res) {
                S.editing = 0;
                S.latestId = res.latest_id || S.latestId;
                setRow(res.message);
            }).catch(function (e) { onError(e); save.disabled = false; err.textContent = e.message; });
        }
        save.addEventListener('click', commit);
        cancel.addEventListener('click', done);
        ta.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.stopPropagation(); done(); }
            else if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); commit(); }
        });
    }

    function deleteMessage(m) {
        if (!window.confirm('Delete this message? It is removed for everyone in this chat, and any files in it are deleted. This cannot be undone.')) { return; }
        api('DELETE', '/' + S.convId + '/messages/' + m.id).then(function (res) {
            S.latestId = res.latest_id || 0;
            setRow(res.message);
            refreshNow();
        }).catch(function (e) { onError(e); setNote(e.message, true); });
    }

    // ------------------------------------------------------------------ files waiting to be sent

    function updateSend() {
        var text = el.input.value.trim().length > 0;
        var done = S.pending.filter(function (p) { return p.status === 'done'; }).length;
        var busy = S.pending.some(function (p) { return p.status === 'wait' || p.status === 'up'; });
        el.send.disabled = !S.canSend || S.sending || busy || (!text && !done);
    }

    function chipEl(p) {
        var c = h('div', 'msg-chip is-' + p.status);
        c.dataset.key = p.key;
        var name = h('span', 'msg-chip-name', p.name);
        var info = h('span', 'msg-chip-info', p.status === 'error' ? p.error : fmtSize(p.size));
        var text = h('span', 'msg-chip-text');
        text.appendChild(name);
        text.appendChild(info);
        c.appendChild(text);
        if (p.status === 'up' || p.status === 'wait') {
            var bar = h('span', 'msg-bar');
            var fill = h('span', 'msg-bar-fill');
            fill.style.width = Math.round(p.progress * 100) + '%';
            bar.appendChild(fill);
            c.appendChild(bar);
        }
        var x = h('button', 'msg-chip-x', '\u00d7');
        x.type = 'button';
        x.setAttribute('aria-label', 'Remove ' + p.name);
        x.addEventListener('click', function () { removePending(p); });
        c.appendChild(x);
        return c;
    }

    function renderChips() {
        clear(el.chips);
        S.pending.forEach(function (p) { el.chips.appendChild(chipEl(p)); });
        el.chips.hidden = !S.pending.length || !!el.compose.hidden;
        updateSend();
    }

    function updateChip(p) {
        var c = el.chips.querySelector('.msg-chip[data-key="' + p.key + '"]');
        var f = c && c.querySelector('.msg-bar-fill');
        if (f) { f.style.width = Math.round(p.progress * 100) + '%'; }
    }

    function addFiles(list) {
        if (!S.convId || !S.canSend) { return; }
        Array.prototype.slice.call(list || []).forEach(function (f) {
            if (S.pending.length >= MAX_FILES) { setNote('You can attach at most ' + MAX_FILES + ' files to one message.', true); return; }
            var ext = (f.name.split('.').pop() || '').toLowerCase();
            var p = { key: ++S.seq, file: f, name: f.name, size: f.size, status: 'wait', progress: 0, id: null, error: '', xhr: null, conv: S.convId };
            if (f.size > MAX_MB * 1048576) { p.status = 'error'; p.error = 'Bigger than the limit of ' + MAX_MB + ' MB'; }
            else if (f.name.indexOf('.') !== -1 && BLOCKED.indexOf(ext) !== -1) { p.status = 'error'; p.error = 'This type of file cannot be sent'; }
            S.pending.push(p);
        });
        renderChips();
        pumpUploads();
    }

    // one file at a time, each in its own request, with its own progress
    function pumpUploads() {
        if (S.uploading) { return; }
        var next = null;
        for (var i = 0; i < S.pending.length; i++) { if (S.pending[i].status === 'wait') { next = S.pending[i]; break; } }
        if (!next) { updateSend(); return; }
        S.uploading = next;
        next.status = 'up';
        renderChips();
        upload(next);
    }

    function upload(p) {
        var fd = new FormData();
        fd.append('file', p.file, p.name);
        var xhr = new XMLHttpRequest();
        p.xhr = xhr;
        xhr.open('POST', base + '/' + p.conv + '/files');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', token);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) { p.progress = e.loaded / e.total; updateChip(p); }
        };
        function finish() { p.xhr = null; S.uploading = null; renderChips(); pumpUploads(); }
        xhr.onload = function () {
            var data = {};
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = {}; }
            if (xhr.status === 201 && data.file) { p.status = 'done'; p.id = data.file.id; p.progress = 1; }
            else { p.status = 'error'; p.error = friendly(xhr.status, data); onError({ status: xhr.status }); }
            finish();
        };
        xhr.onerror = function () { p.status = 'error'; p.error = 'The upload failed. Check your connection.'; finish(); };
        xhr.onabort = function () { p.status = 'aborted'; finish(); };
        xhr.send(fd);
    }

    function removePending(p) {
        if (p.xhr) { p.xhr.abort(); }
        else if (p.status === 'done' && p.id) { api('DELETE', '/files/' + p.id).catch(noop); }
        S.pending = S.pending.filter(function (x) { return x !== p; });
        renderChips();
    }

    // leaving the chat with files that were not sent: take them back
    function discardPending() {
        S.pending.forEach(function (p) {
            if (p.xhr) { p.xhr.abort(); }
            else if (p.status === 'done' && p.id) { api('DELETE', '/files/' + p.id).catch(noop); }
        });
        S.pending = [];
        renderChips();
    }

    el.attach.addEventListener('click', function () { if (S.canSend) { el.file.click(); } });
    el.file.addEventListener('change', function () { addFiles(el.file.files); el.file.value = ''; });
    ['dragenter', 'dragover'].forEach(function (name) {
        el.threadView.addEventListener(name, function (e) {
            if (S.canSend && e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) {
                e.preventDefault();
                el.threadView.classList.add('is-dragover');
            }
        });
    });
    ['dragleave', 'drop'].forEach(function (name) {
        el.threadView.addEventListener(name, function () { el.threadView.classList.remove('is-dragover'); });
    });
    el.threadView.addEventListener('drop', function (e) {
        if (S.canSend && e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            e.preventDefault();
            addFiles(e.dataTransfer.files);
        }
    });

    // ------------------------------------------------------------------ writing a message

    // The message field is one line tall, grows with the text up to THREE lines (like Messenger), and scrolls inside
    // itself after that (no visible scroll bar). scrollHeight does not include the border, so it is added back:
    // without it the box was a few pixels too short and clipped even a single line.
    function autosize() {
        var ta = el.input;
        var cs = window.getComputedStyle(ta);
        var line = parseFloat(cs.lineHeight);
        if (!line || isNaN(line)) { line = (parseFloat(cs.fontSize) || 14) * 1.4; }
        var pad = (parseFloat(cs.paddingTop) || 0) + (parseFloat(cs.paddingBottom) || 0);
        var border = (parseFloat(cs.borderTopWidth) || 0) + (parseFloat(cs.borderBottomWidth) || 0);
        var min = Math.ceil(line + pad + border);
        var max = Math.ceil(line * 3 + pad + border);

        ta.style.height = 'auto';
        var need = Math.ceil(ta.scrollHeight + border);
        ta.style.height = Math.max(min, Math.min(need, max)) + 'px';
        ta.style.overflowY = need > max ? 'auto' : 'hidden';
    }
    window.addEventListener('resize', autosize);
    el.input.addEventListener('input', function () { autosize(); updateSend(); });
    el.input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            el.send.click();
        }
    });

    el.compose.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = el.input.value.replace(/\s+$/, '');
        var id = S.convId;
        var files = S.pending.filter(function (p) { return p.status === 'done'; });
        if (!id || !S.canSend || S.sending || (!text.trim() && !files.length)) { return; }
        if (S.pending.some(function (p) { return p.status === 'up' || p.status === 'wait'; })) { return; }

        S.sending = true;
        updateSend();
        setNote('');

        api('POST', '/' + id + '/send', { body: text, attachments: files.map(function (p) { return p.id; }) }).then(function (res) {
            if (S.convId === id) {
                el.input.value = '';
                autosize();
                // the files now belong to the message; failed ones stay as chips so they can be removed
                S.pending = S.pending.filter(function (p) { return p.status !== 'done'; });
                renderChips();
                // fetch through the same path as every other message, so order is always right
                return pollThread(true).catch(function () { appendMessages([res.message], true); });
            }
        }).catch(function (err) {
            onError(err);
            setNote(err.message, true);
            if (err.status === 403) { S.canSend = false; }
        }).then(function () {
            S.sending = false;
            applyCompose();
            if (S.canSend) { el.input.focus({ preventScroll: true }); }
            pollState().catch(noop);
        });
    });

    // ------------------------------------------------------------------ groups: make one

    function openGroupCreate() {
        S.gSel = {};
        S.gResults = isAdminPage ? [] : null;   // the Admin searches by typing; a Chairman's list is loaded below
        el.gTitle.value = '';
        el.gFind.value = '';
        el.gNote.hidden = true;
        setView('groupnew');
        renderGroupForm();
        // an Admin searches by typing; a Chairman starts with the people of their barangay
        if (!isAdminPage) { loadGroupPeople(''); }
        el.gTitle.focus({ preventScroll: true });
    }

    function loadGroupPeople(q) {
        S.gResults = null;
        renderGroupForm();
        api('GET', '/people?for=group&q=' + encodeURIComponent(q)).then(function (data) {
            if (el.gFind.value.trim() !== q) { return; }
            S.gResults = data.people || [];
            renderGroupForm();
        }).catch(function (e) { onError(e); S.gResults = []; renderGroupForm(); });
    }

    el.gFind.addEventListener('input', function () {
        var q = el.gFind.value.trim();
        clearTimeout(S.gTimer);
        if (isAdminPage && q.length < 2) { S.gResults = []; renderGroupForm(); return; }
        S.gTimer = setTimeout(function () { loadGroupPeople(q); }, 250);
    });
    el.gTitle.addEventListener('input', renderGroupForm);

    function selectedIds() { return Object.keys(S.gSel).map(function (k) { return S.gSel[k].id; }); }

    function renderGroupForm() {
        // chosen people
        clear(el.gChosen);
        var ids = selectedIds();
        if (ids.length) { el.gChosen.appendChild(h('p', 'msg-section', ids.length + (ids.length === 1 ? ' person' : ' people') + ' chosen')); }
        ids.forEach(function (id) {
            var p = S.gSel[id];
            var chip = h('span', 'msg-pill');
            chip.appendChild(h('span', 'msg-pill-name', p.name));
            var x = h('button', 'msg-pill-x', '\u00d7');
            x.type = 'button';
            x.setAttribute('aria-label', 'Remove ' + p.name);
            x.addEventListener('click', function () { delete S.gSel[id]; renderGroupForm(); });
            chip.appendChild(x);
            el.gChosen.appendChild(chip);
        });

        // search results
        clear(el.gResults);
        var q = el.gFind.value.trim();
        if (S.gResults === null) {
            el.gResults.appendChild(emptyBox('Searching\u2026'));
        } else if (isAdminPage && q.length < 2) {
            el.gResults.appendChild(emptyBox('Find people to add', 'Type a name, a position (such as "SK Chairman") or a barangay. You can add everyone shown at once.'));
        } else if (!S.gResults.length) {
            el.gResults.appendChild(emptyBox('No one found', 'Try another name, position or barangay.'));
        } else {
            var fresh = S.gResults.filter(function (p) { return !S.gSel[p.id]; });
            if (fresh.length) {
                var all = h('button', 'msg-addall', 'Add all ' + fresh.length + ' shown');
                all.type = 'button';
                all.addEventListener('click', function () { fresh.forEach(function (p) { S.gSel[p.id] = p; }); renderGroupForm(); });
                el.gResults.appendChild(all);
            }
            S.gResults.forEach(function (p) {
                var picked = !!S.gSel[p.id];
                var b = item({
                    name: p.name, text: metaOf(p) + (picked ? '  \u00b7  added' : ''),
                    onClick: function () { if (picked) { delete S.gSel[p.id]; } else { S.gSel[p.id] = p; } renderGroupForm(); }
                });
                b.classList.toggle('is-picked', picked);
                b.setAttribute('aria-pressed', picked ? 'true' : 'false');
                el.gResults.appendChild(b);
            });
        }

        el.gCreate.disabled = !(el.gTitle.value.trim().length >= 2 && ids.length >= 1);
    }

    el.gForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (el.gCreate.disabled) { return; }
        el.gCreate.disabled = true;
        el.gNote.hidden = true;
        api('POST', '/group', { title: el.gTitle.value.trim(), members: selectedIds() }).then(function (res) {
            var who = { type: 'group', id: res.id, name: el.gTitle.value.trim(), role: 'Group chat', place: (selectedIds().length + 1) + ' members' };
            openThread(res.id, who, 'group');
            refreshNow();
        }).catch(function (err) {
            onError(err);
            el.gNote.hidden = false;
            el.gNote.classList.add('is-error');
            el.gNote.textContent = err.message;
            renderGroupForm();
        });
    });

    // ------------------------------------------------------------------ groups: who is in it

    function openMembers() {
        if (!S.convId || !S.conv || S.conv.kind !== 'group') { return; }
        S.mData = null;
        S.mResults = null;
        setView('members');
        renderMembers();
        loadMembers();
    }

    function loadMembers() {
        var id = S.convId;
        api('GET', '/' + id + '/members').then(function (data) {
            if (S.convId !== id) { return; }
            S.mData = data;
            renderMembers();
        }).catch(function (e) { onError(e); clear(el.members); el.members.appendChild(h('p', 'msg-loading is-error', e.message)); });
    }

    function renderMembers() {
        var d = S.mData;
        clear(el.members);
        if (!d) { el.members.appendChild(h('p', 'msg-loading', 'Loading\u2026')); return; }

        var inGroup = {};
        d.members.forEach(function (m) { inGroup[m.type + ':' + m.id] = true; });
        var meNote = h('p', 'msg-note is-inline', '');
        meNote.hidden = true;

        // rename (the person who manages the group)
        if (d.can_manage) {
            el.members.appendChild(h('label', 'msg-label', 'Group name'));
            var row1 = h('div', 'msg-inline');
            var name = h('input', 'msg-input');
            name.type = 'text'; name.maxLength = 60; name.value = d.title;
            var save = h('button', 'btn btn-primary btn-small', 'Rename');
            save.type = 'button';
            save.addEventListener('click', function () {
                api('POST', '/' + S.convId + '/rename', { title: name.value }).then(function (res) {
                    S.conv.with.name = res.title; d.title = res.title; showHead(); refreshNow();
                    meNote.hidden = false; meNote.classList.remove('is-error'); meNote.textContent = 'Renamed.';
                }).catch(function (e) { onError(e); meNote.hidden = false; meNote.classList.add('is-error'); meNote.textContent = e.message; });
            });
            row1.appendChild(name); row1.appendChild(save);
            el.members.appendChild(row1);
            el.members.appendChild(meNote);
        }

        // the people
        el.members.appendChild(section(d.members.length + (d.members.length === 1 ? ' member' : ' members')));
        d.members.forEach(function (m) {
            var line = h('div', 'msg-member');
            var av = h('span', 'msg-avatar' + (m.type === 'admin' ? ' is-admin' : ''), initial(m.name));
            av.setAttribute('aria-hidden', 'true');
            var t = h('span', 'msg-member-text');
            t.appendChild(h('span', 'msg-item-name', m.name + (m.is_creator ? '  (created the group)' : '')));
            t.appendChild(h('span', 'msg-item-text', metaOf(m)));
            line.appendChild(av);
            line.appendChild(t);
            if (d.can_manage && m.type === 'user' && !m.is_creator) {
                var rm = h('button', 'msg-member-x', 'Remove');
                rm.type = 'button';
                rm.addEventListener('click', function () {
                    if (!window.confirm('Remove ' + m.name + ' from this group?')) { return; }
                    api('POST', '/' + S.convId + '/members/remove', { id: m.id }).then(function (res) {
                        d.members = res.members; S.conv.members = res.members.length; showHead(); renderMembers(); refreshNow();
                    }).catch(function (e) { onError(e); meNote.hidden = false; meNote.classList.add('is-error'); meNote.textContent = e.message; });
                });
                line.appendChild(rm);
            }
            el.members.appendChild(line);
        });

        // add people (the person who manages the group)
        if (d.can_manage) {
            el.members.appendChild(h('label', 'msg-label', 'Add people'));
            var find = h('input', 'msg-input');
            find.type = 'search'; find.maxLength = 60; find.placeholder = 'Search a name, position or barangay';
            var results = h('div', 'msg-pick');
            el.members.appendChild(find);
            el.members.appendChild(results);

            function drawResults() {
                clear(results);
                var q = find.value.trim();
                if (S.mResults === null) { if (q) { results.appendChild(emptyBox('Searching\u2026')); } return; }
                var fresh = S.mResults.filter(function (p) { return !inGroup[p.type + ':' + p.id]; });
                if (!fresh.length) { results.appendChild(emptyBox('No one to add', 'Everyone found is already in the group.')); return; }
                var all = h('button', 'msg-addall', 'Add all ' + fresh.length + ' shown');
                all.type = 'button';
                all.addEventListener('click', function () { addMembers(fresh.map(function (p) { return p.id; })); });
                results.appendChild(all);
                fresh.forEach(function (p) {
                    results.appendChild(item({ name: p.name, text: metaOf(p), onClick: function () { addMembers([p.id]); } }));
                });
            }
            function addMembers(ids) {
                api('POST', '/' + S.convId + '/members', { members: ids }).then(function (res) {
                    d.members = res.members; S.conv.members = res.members.length; S.mResults = null; find.value = '';
                    showHead(); renderMembers(); refreshNow();
                }).catch(function (e) { onError(e); meNote.hidden = false; meNote.classList.add('is-error'); meNote.textContent = e.message; });
            }
            find.addEventListener('input', function () {
                var q = find.value.trim();
                clearTimeout(S.mTimer);
                S.mResults = null;
                drawResults();
                if (isAdminPage && q.length < 2) { S.mResults = []; results.appendChild(emptyBox('Find people to add', 'Type a name, a position or a barangay.')); return; }
                S.mTimer = setTimeout(function () {
                    api('GET', '/people?for=group&q=' + encodeURIComponent(q)).then(function (data) {
                        if (find.value.trim() !== q) { return; }
                        S.mResults = data.people || [];
                        drawResults();
                    }).catch(function (e) { onError(e); S.mResults = []; drawResults(); });
                }, 250);
            });
        }

        // leave / delete
        var danger = h('div', 'msg-danger');
        var leave = h('button', 'msg-danger-btn', 'Leave group');
        leave.type = 'button';
        leave.addEventListener('click', function () {
            if (!window.confirm('Leave this group? You will no longer see its messages.')) { return; }
            api('POST', '/' + S.convId + '/leave').then(function () { showList(); }).catch(function (e) { onError(e); meNote.hidden = false; meNote.classList.add('is-error'); meNote.textContent = e.message; });
        });
        danger.appendChild(leave);
        if (d.can_manage) {
            var del = h('button', 'msg-danger-btn', 'Delete group');
            del.type = 'button';
            del.addEventListener('click', function () {
                if (!window.confirm('Delete this whole group, with all its messages and files, for everybody? This cannot be undone.')) { return; }
                api('DELETE', '/' + S.convId).then(function () { showList(); }).catch(function (e) { onError(e); meNote.hidden = false; meNote.classList.add('is-error'); meNote.textContent = e.message; });
            });
            danger.appendChild(del);
        }
        el.members.appendChild(danger);
    }

    // ------------------------------------------------------------------ header buttons

    el.back.addEventListener('click', function () {
        if (S.view === 'members') { setView('thread'); return; }
        if (S.view === 'groupnew') { showList(); return; }
        showList();
    });
    el.info.addEventListener('click', openMembers);
    el.hide.addEventListener('click', function () {
        var id = S.convId;
        if (!id || !window.confirm('Hide this chat from your list? Nothing is deleted, and it comes back if a new message arrives.')) { return; }
        api('POST', '/' + id + '/hide').then(function () { showList(); }).catch(function (e) { onError(e); setNote(e.message, true); });
    });

    // ------------------------------------------------------------------ polling

    function pollState() {
        return api('GET', '/state').then(function (data) {
            window.skMessages.setUnread(data.unread);
            S.canCreate = !!data.can_create_group;
            var sig = JSON.stringify(data.conversations) + (S.canCreate ? '1' : '0');
            if (sig !== S.chatsSig) {
                S.chats = data.conversations;
                S.chatsSig = sig;
                renderTabs();
                if (S.view === 'list' && (!S.query || S.tab === 'group')) { renderList(); }
            }
            if (!S.chats.some(function (c) { return c.kind !== 'announcement'; })) { loadSuggestions(); }
        }).catch(function (e) { onError(e); throw e; });
    }

    function schedule() {
        clearTimeout(S.timer);
        if (S.stopped) { return; }
        var delay = document.hidden ? 90000 : (isOpen() ? 6000 : 45000);
        S.timer = setTimeout(tick, delay);
    }

    function tick() {
        var work = (isOpen() && S.view === 'thread') ? pollThread().catch(noop) : Promise.resolve();
        work.then(pollState).catch(noop).then(schedule);
    }

    function refreshNow() {
        clearTimeout(S.timer);
        if (S.stopped) { return; }
        tick();
    }

    document.addEventListener('sk:messages-toggle', function (e) {
        if (e.detail && e.detail.open) {
            if (S.view === 'thread') { toBottom(); markRead(); }
            refreshNow();
        } else {
            schedule();
        }
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { refreshNow(); } });
    window.addEventListener('online', refreshNow);

    setView('list');
    renderList();
    setTimeout(refreshNow, 700);   // the badge shows soon after the page loads
})();
