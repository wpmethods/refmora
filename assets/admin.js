/* Refmora: scoped, dependency-free settings controls. */
(function () {
    'use strict';
    const config = window.refmAdmin || {};
    document.addEventListener('click', function (event) {
        const button = event.target.closest('.refm-choose-payment-icon, .refm-clear-payment-icon');
        if (!button || !button.closest('.refm-admin')) return;
        const field = button.closest('.refm-payment-icon-field');
        const input = field.querySelector('.refm-payment-icon-value');
        function update(url) {
            input.value = url;
            input.dispatchEvent(new Event('change', {bubbles: true}));
        }
        if (button.classList.contains('refm-clear-payment-icon')) { update(''); return; }
        if (!window.wp || !window.wp.media) return;
        const frame = window.wp.media({title: button.title, library: {type: 'image'}, multiple: false});
        frame.on('select', function () { update(frame.state().get('selection').first().toJSON().url); });
        frame.open();
    });
    document.addEventListener('change', function (event) {
        if (!event.target.matches('.refm-admin .refm-payment-icon-value')) return;
        const field = event.target.closest('.refm-payment-icon-field');
        const image = field.querySelector('img');
        const valid = /^https?:\/\//i.test(event.target.value);
        image.hidden = !valid;
        field.querySelector('.refm-payment-icon-placeholder').hidden = valid;
        field.querySelector('.refm-clear-payment-icon').hidden = !valid;
        if (valid) image.src = event.target.value;
        else image.removeAttribute('src');
    });
    document.querySelectorAll('.refm-admin [data-refm-report-range]').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.form && select.form.requestSubmit) {
                select.form.requestSubmit();
            }
        });
    });
    const notices = Array.from(document.querySelectorAll('.refm-admin [data-refm-toast]'));
    if (notices.length) {
        const stack = document.createElement('div');
        stack.className = 'refm-toast-stack';
        document.querySelector('.refm-admin').appendChild(stack);
        notices.forEach(function (toast) {
            stack.appendChild(toast);
            let timer = 0;
            let remaining = Math.min(18000, Math.max(8000, toast.textContent.length * 45));
            let started = 0;
            let hovered = false;
            let closed = false;
            function pause() {
                if (timer) { window.clearTimeout(timer); timer = 0; remaining = Math.max(0, remaining - (Date.now() - started)); }
            }
            function resume() {
                if (closed || timer || toast.dataset.autodismiss !== 'yes' || hovered || toast.contains(document.activeElement) || document.hidden) return;
                started = Date.now(); timer = window.setTimeout(dismiss, remaining);
            }
            function dismiss() {
                if (closed) return;
                closed = true; pause();
                document.removeEventListener('visibilitychange', visibility);
                toast.classList.add('is-leaving');
                const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.setTimeout(function () { toast.remove(); if (!stack.children.length) stack.remove(); }, reduced ? 0 : 180);
            }
            function visibility() { if (document.hidden) pause(); else resume(); }
            toast.querySelector('.refm-toast-dismiss').addEventListener('click', dismiss);
            toast.addEventListener('mouseenter', function () { hovered = true; pause(); });
            toast.addEventListener('mouseleave', function () { hovered = false; resume(); });
            toast.addEventListener('focusin', pause);
            toast.addEventListener('focusout', function () { window.setTimeout(resume, 0); });
            toast.addEventListener('keydown', function (event) { if (event.key === 'Escape') { event.preventDefault(); dismiss(); } });
            document.addEventListener('visibilitychange', visibility);
            resume();
        });
        // Flash messages should not reappear on refresh or when copying the page URL.
        try {
            const url = new URL(window.location.href);
            ['saved', 'refm_message', 'refm_error'].forEach(function (key) { url.searchParams.delete(key); });
            window.history.replaceState(window.history.state, '', url.toString());
        } catch (ignore) { /* Keep notifications usable if history changes are unavailable. */ }
    }
    let dirty = false;
    document.querySelectorAll('form.refm-settings').forEach(function (form) {
        const bar = form.querySelector('.refm-save-bar') || (form.id ? document.querySelector('.refm-save-bar button[form="' + form.id + '"]')?.closest('.refm-save-bar') : null);
        const state = bar ? bar.querySelector('.refm-save-state') : null;
        if (bar) {
            const slot = document.createElement('div');
            slot.className = 'refm-save-bar-slot';
            bar.before(slot);
            slot.appendChild(bar);
            let frame = 0;
            function positionBar() {
                frame = 0;
                const rect = slot.getBoundingClientRect();
                const inset = window.innerWidth <= 600 ? 10 : 16;
                const docked = rect.bottom <= window.innerHeight - inset;
                bar.classList.toggle('refm-save-bar-fixed', !docked);
                bar.classList.toggle('refm-save-bar-docked', docked);
                if (docked) {
                    bar.style.removeProperty('left');
                    bar.style.removeProperty('width');
                } else {
                    const left = Math.max(10, rect.left);
                    const right = Math.min(document.documentElement.clientWidth - 10, rect.right);
                    bar.style.left = left + 'px';
                    bar.style.width = Math.max(0, right - left) + 'px';
                }
                slot.style.height = bar.offsetHeight + 'px';
            }
            function queuePosition() {
                if (!frame) frame = window.requestAnimationFrame(positionBar);
            }
            window.addEventListener('resize', queuePosition, {passive: true});
            window.addEventListener('scroll', queuePosition, {passive: true});
            if (window.ResizeObserver) new ResizeObserver(queuePosition).observe(slot);
            positionBar();
        }
        function markDirty() {
            dirty = true;
            if (state) { state.textContent = config.unsaved || 'You have unsaved changes.'; state.classList.add('is-dirty'); }
        }
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        form.addEventListener('click', function (event) {
            if (event.target.closest('#refm-add-method, .refm-remove-method')) markDirty();
        });
        form.addEventListener('submit', function () {
            dirty = false;
            if (state) state.textContent = config.saving || 'Saving your changes...';
        });
        const preview = form.querySelector('.refm-design-preview');
        if (preview) form.querySelectorAll('input[type="color"], #refm-radius').forEach(function (input) {
            input.addEventListener('input', function () {
                const key = input.id.replace('refm-design-', '');
                if (input.type === 'color' && /^#[a-f0-9]{6}$/i.test(input.value)) preview.style.setProperty('--refm-' + key, input.value);
                else if (input.id === 'refm-radius') preview.style.setProperty('--refm-radius', Math.min(24, Math.max(0, Number(input.value) || 0)) + 'px');
            });
        });
    });
    window.addEventListener('beforeunload', function (event) {
        if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });
    const exchange = document.getElementById('refm-exchange_source');
    const manualRates = document.getElementById('refm-manual-exchange-rates');
    const release = document.getElementById('refm-release_mode');
    const releaseDays = document.getElementById('refm-release-days');
    function conditionalFields() {
        if (exchange && manualRates) manualRates.hidden = !['auto', 'manual'].includes(exchange.value);
        if (release && releaseDays) {
            releaseDays.hidden = release.value !== 'delay';
            const input = releaseDays.querySelector('input');
            if (input) input.required = release.value === 'delay';
        }
    }
    if (exchange) exchange.addEventListener('change', conditionalFields);
    if (release) release.addEventListener('change', conditionalFields);
    document.querySelectorAll('[data-refm-provider]').forEach(function (card) {
        const provider = card.dataset.refmProvider;
        const environment = card.querySelector('select[name="refm_gateway[' + provider + '_mode]"]');
        const marker = card.querySelector('[data-refm-credential-mode]');
        const title = card.querySelector('[data-refm-credential-title]');
        const fields = Array.from(card.querySelectorAll('[data-refm-credential]'));
        if (!environment || !marker) return;
        let current = marker.value;
        const drafts = {};
        function refresh() {
            fields.forEach(function (field) {
                const saved = current === 'live' ? field.dataset.savedLive : field.dataset.savedTest;
                field.placeholder = saved === 'yes' ? field.dataset.savedPlaceholder : field.dataset.emptyPlaceholder;
            });
            if (title) title.textContent = current === 'live' ? 'Live credentials' : 'Sandbox / Test credentials';
        }
        environment.addEventListener('change', function () {
            drafts[current] = fields.map(function (field) { return field.value; });
            current = environment.value;
            fields.forEach(function (field, index) { field.value = drafts[current] ? drafts[current][index] : ''; });
            marker.value = current;
            refresh();
        });
        refresh();
    });
    conditionalFields();
})();

/* Refmora Pro manual method editor. */
(function(){'use strict';
    const list = document.getElementById('refm-method-list');
    const mode = document.getElementById('refm-payout_mode');
    const selection = document.getElementById('refm-method_selection');
    const add = document.getElementById('refm-add-method');
    let index = list ? list.children.length : 0;
    function sync() {
        const manual = document.getElementById('refm-manual-methods');
        if (manual && mode) manual.hidden = mode.value !== 'manual';
    }
    function single(changed) {
        if (!list || !selection || selection.value !== 'single') return;
        const checked = Array.from(list.querySelectorAll('input[type="checkbox"]:checked'));
        const keep = changed && changed.checked ? changed : checked[0];
        checked.forEach(function (box) { box.checked = box === keep; });
    }
    if (add) add.addEventListener('click', function () {
        const template = document.getElementById('refm-method-template');
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace('__INDEX__', String(index)); });
        index += 1;
        list.appendChild(fragment);
        single(); sync();
        list.lastElementChild.querySelector('input[type="text"]').focus();
    });
    if (list) {
        list.addEventListener('click', function (event) {
            if (event.target.closest('.refm-remove-method')) {
                event.target.closest('.refm-method-row').remove(); sync();
            }
        });
        list.addEventListener('change', function (event) {
            if (event.target.type === 'checkbox') single(event.target);
        });
    }
    if (mode) mode.addEventListener('change', sync);
    if (selection) selection.addEventListener('change', function () { single(); });

    sync();
})();
