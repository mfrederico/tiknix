/**
 * Modal dialogs in place of the browser's alert() / confirm() / prompt().
 *
 *   await tkAlert('Saved, but not merged: ' + reason, {title: 'Not merged', type: 'warning'});
 *   if (!await tkConfirm('Delete this task?', {okText: 'Delete', danger: true})) return;   // false = Cancel, null = closed
 *   const name = await tkPrompt('Name for the key', {value: 'default'});   // null = cancelled
 *
 * Declarative, no script needed — a form asks before it submits, a link or button before
 * it acts:
 *
 *   <form method="post" data-confirm="Delete this task? This cannot be undone." data-confirm-ok="Delete">
 *   <a href="/x/remove?id=3" data-confirm="Remove it?">…</a>
 *
 * Bootstrap 5's bundle must be on the page. One modal element is built on first use and
 * reused; dialogs asked for while one is open wait their turn.
 */
(function () {
    'use strict';

    var queue = Promise.resolve();

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function modalEl() {
        var el = document.getElementById('tkDialog');
        if (el) return el;
        el = document.createElement('div');
        el.id = 'tkDialog';
        el.className = 'modal fade';
        el.tabIndex = -1;
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML =
            '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
            '<div class="modal-header py-2"><h6 class="modal-title d-flex align-items-center gap-2"></h6>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
            '<div class="modal-body"><div class="tk-dialog-msg" style="white-space:pre-wrap;overflow-wrap:anywhere"></div>' +
            '<input type="text" class="form-control mt-3 d-none tk-dialog-input"></div>' +
            '<div class="modal-footer py-2">' +
            '<button type="button" class="btn btn-outline-secondary btn-sm tk-dialog-cancel" data-bs-dismiss="modal">Cancel</button>' +
            '<button type="button" class="btn btn-primary btn-sm tk-dialog-ok">OK</button></div>' +
            '</div></div>';
        document.body.appendChild(el);
        return el;
    }

    var ICONS = {
        info: 'bi-info-circle text-primary', success: 'bi-check-circle text-success',
        warning: 'bi-exclamation-triangle text-warning', error: 'bi-x-circle text-danger',
        danger: 'bi-exclamation-octagon text-danger', question: 'bi-question-circle text-primary'
    };

    // kind: 'alert' | 'confirm' | 'prompt'. Resolves true/false (confirm), string/null
    // (prompt) or undefined (alert) once the modal has fully closed.
    function open(kind, message, opts) {
        opts = opts || {};
        var run = function () {
            return new Promise(function (resolve) {
                var el = modalEl();
                var type = opts.type || (kind === 'alert' ? 'info' : (opts.danger ? 'danger' : 'question'));
                var title = opts.title || (kind === 'alert' ? (type === 'error' ? 'Something went wrong' : 'Notice') : 'Please confirm');
                el.querySelector('.modal-title').innerHTML = '<i class="bi ' + (ICONS[type] || ICONS.info) + '"></i><span>' + esc(title) + '</span>';
                el.querySelector('.tk-dialog-msg').textContent = message == null ? '' : String(message);
                var input = el.querySelector('.tk-dialog-input');
                input.classList.toggle('d-none', kind !== 'prompt');
                input.value = kind === 'prompt' && opts.value != null ? String(opts.value) : '';
                input.placeholder = opts.placeholder || '';
                var cancel = el.querySelector('.tk-dialog-cancel');
                cancel.classList.toggle('d-none', kind === 'alert');
                cancel.textContent = opts.cancelText || 'Cancel';
                var ok = el.querySelector('.tk-dialog-ok');
                ok.textContent = opts.okText || 'OK';
                ok.className = 'btn btn-sm tk-dialog-ok ' + (opts.danger ? 'btn-danger' : 'btn-primary');

                var result = kind === 'alert' ? undefined : null;
                var modal = bootstrap.Modal.getOrCreateInstance(el);
                // The Cancel button is a deliberate "no" (false); closing with X, Escape or
                // the backdrop is no answer at all (null) — still falsy, but a caller whose
                // Cancel DOES something (redeploy: Cancel = re-apply) can tell them apart.
                var declined = function () { if (kind === 'confirm') result = false; };
                var accept = function () {
                    result = kind === 'confirm' ? true : (kind === 'prompt' ? input.value : undefined);
                    modal.hide();
                };
                var onKey = function (e) { if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); accept(); } };
                var onShown = function () { (kind === 'prompt' ? input : ok).focus(); if (kind === 'prompt') input.select(); };
                var onHidden = function () {
                    ok.removeEventListener('click', accept);
                    cancel.removeEventListener('click', declined);
                    input.removeEventListener('keydown', onKey);
                    el.removeEventListener('shown.bs.modal', onShown);
                    el.removeEventListener('hidden.bs.modal', onHidden);
                    // Bootstrap unlocks the page scroll on hide even when the modal we
                    // opened over is still up.
                    if (document.querySelector('.modal.show')) document.body.classList.add('modal-open');
                    resolve(result);
                };
                ok.addEventListener('click', accept);
                cancel.addEventListener('click', declined);
                input.addEventListener('keydown', onKey);
                el.addEventListener('shown.bs.modal', onShown);
                el.addEventListener('hidden.bs.modal', onHidden);
                // Over another open modal (compose, copy-key): sit above it and dim it,
                // instead of sharing its z-index with a backdrop drawn underneath it.
                var over = document.querySelector('.modal.show:not(#tkDialog)') !== null;
                el.style.zIndex = over ? '1075' : '';
                modal.show();
                if (over) {
                    var bds = document.querySelectorAll('.modal-backdrop');
                    if (bds.length) bds[bds.length - 1].style.zIndex = '1070';
                }
            });
        };
        var p = queue.then(run);
        queue = p.catch(function () {});
        return p;
    }

    window.tkAlert = function (message, opts) { return open('alert', message, opts); };
    window.tkConfirm = function (message, opts) { return open('confirm', message, opts); };
    window.tkPrompt = function (message, opts) { return open('prompt', message, opts); };

    function optsFrom(el) {
        return {
            title: el.getAttribute('data-confirm-title') || undefined,
            okText: el.getAttribute('data-confirm-ok') || undefined,
            danger: el.hasAttribute('data-confirm-danger')
        };
    }

    // <form data-confirm="…">: ask, then submit for real (the submitter's name/value kept).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
        if (form.dataset.tkConfirmed === '1') { delete form.dataset.tkConfirmed; return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        var submitter = e.submitter || null;
        tkConfirm(form.getAttribute('data-confirm'), optsFrom(form)).then(function (yes) {
            if (!yes) return;
            form.dataset.tkConfirmed = '1';
            if (form.requestSubmit) form.requestSubmit(submitter); else form.submit();
        });
    }, true);

    // <a|button data-confirm="…"> outside such a form: ask, then follow / click again.
    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest('[data-confirm]');
        if (!el || el instanceof HTMLFormElement) return;
        if (el.dataset.tkConfirmed === '1') { delete el.dataset.tkConfirmed; return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        tkConfirm(el.getAttribute('data-confirm'), optsFrom(el)).then(function (yes) {
            if (!yes) return;
            el.dataset.tkConfirmed = '1';
            el.click();
        });
    }, true);
})();
