/* Clipboard controls shared by the manager and affiliate referral-link tables. */
(function () {
    'use strict';
    document.querySelectorAll('[data-refm-link-copy]').forEach(function (button) {
        const path = button.querySelector('svg path');
        const originalPath = path && path.getAttribute('d');
        const originalTitle = button.title;
        const originalLabel = button.getAttribute('aria-label');
        const section = button.closest('.refm-refer-links');
        const feedback = section && section.querySelector('[role="status"]');
        let timer;
        button.addEventListener('click', async function () {
            const url = button.dataset.refmUrl;
            if (!url || button.disabled) return;
            button.disabled = true;
            let temporary;
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(url);
                } else {
                    temporary = document.createElement('textarea');
                    temporary.value = url;
                    temporary.readOnly = true;
                    temporary.style.cssText = 'position:fixed;left:-9999px;top:0;';
                    document.body.appendChild(temporary);
                    temporary.focus();
                    temporary.select();
                    if (!document.execCommand('copy')) throw new Error('Clipboard unavailable');
                }
                const message = button.dataset.copied || 'Copied!';
                button.title = message;
                button.setAttribute('aria-label', message);
                if (path) path.setAttribute('d', 'M5 12l4 4L19 6');
                if (feedback) feedback.textContent = message;
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    button.title = originalTitle;
                    button.setAttribute('aria-label', originalLabel || originalTitle);
                    if (path) path.setAttribute('d', originalPath);
                }, 2000);
            } catch (error) {
                const message = button.dataset.copyFailed || 'Copy the referral link manually.';
                if (feedback) feedback.textContent = message;
                window.prompt(message, url);
            } finally {
                if (temporary) { temporary.remove(); button.focus(); }
                button.disabled = false;
            }
        });
    });
})();
