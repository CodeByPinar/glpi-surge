/*
 * Surge — public notices on the self-service portal ("We are aware of the mail outage…").
 * © 2026 Pınar Topuz · GPL-3.0-or-later
 */
(function () {
    'use strict';
    var root = (window.CFG_GLPI && window.CFG_GLPI.root_doc) || '';
    var KEY = 'surge.notice.hidden';

    function hidden() {
        try { return JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { return []; }
    }
    function hide(id) {
        try { var h = hidden(); h.push(id); sessionStorage.setItem(KEY, JSON.stringify(h)); } catch (e) { /* ignore */ }
    }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text != null) { e.textContent = text; }
        return e;
    }

    function render(list) {
        var host = document.querySelector('main') || document.querySelector('.page-body') || document.body;
        var box = el('div', 'surge-notices');
        box.setAttribute('role', 'status');
        list.forEach(function (n) {
            var key = n.id + (n.resolved ? 'r' : 'o');
            if (hidden().indexOf(key) >= 0) { return; }
            var row = el('div', 'surge-notice' + (n.resolved ? ' surge-notice-ok' : ''));
            var ic = el('i', 'ti ' + (n.resolved ? 'ti-circle-check' : 'ti-bolt'));
            ic.setAttribute('aria-hidden', 'true');
            var body = el('div', 'surge-notice-text', n.message);
            var x = el('button', 'surge-notice-close');
            x.type = 'button';
            x.setAttribute('aria-label', 'Close');
            x.innerHTML = '<i class="ti ti-x"></i>';
            x.addEventListener('click', function () { hide(key); row.remove(); });
            row.appendChild(ic);
            row.appendChild(body);
            row.appendChild(x);
            box.appendChild(row);
        });
        if (box.children.length) {
            host.insertBefore(box, host.firstChild);
        }
    }

    function load() {
        fetch(root + '/plugins/surge/front/notice.php', {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.ok ? r.json() : {notices: []}; })
            .then(function (j) { if (j && j.notices && j.notices.length) { render(j.notices); } })
            .catch(function () { /* notices are best-effort */ });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', load);
    } else { load(); }
})();
