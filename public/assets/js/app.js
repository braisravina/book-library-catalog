// Progressive enhancement only: every feature works without JavaScript.
// No inline scripts are used anywhere, so the CSP can forbid them.
(function () {
    'use strict';

    document.documentElement.classList.add('js');

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('[data-nav-toggle]');
        if (toggle) {
            var nav = document.getElementById(toggle.getAttribute('aria-controls'));
            toggle.addEventListener('click', function () {
                var open = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', String(!open));
                if (nav) {
                    nav.classList.toggle('is-open', !open);
                }
            });
        }

        document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
            select.addEventListener('change', function () {
                if (select.form) {
                    select.form.requestSubmit ? select.form.requestSubmit() : select.form.submit();
                }
            });
        });

        // Destructive forms ask for confirmation. The server still enforces
        // permissions and integrity; this only prevents accidental clicks.
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });

        // Copy-to-clipboard buttons (e.g. a one-time invitation link). The
        // button stays hidden without JavaScript; the text field can be copied by hand.
        document.querySelectorAll('[data-copy]').forEach(function (button) {
            var field = document.getElementById(button.getAttribute('data-copy'));
            if (!field || !navigator.clipboard) {
                return;
            }
            button.hidden = false;
            button.addEventListener('click', function () {
                navigator.clipboard.writeText(field.value).then(function () {
                    button.textContent = button.getAttribute('data-copied') || button.textContent;
                });
            });
        });
        document.querySelectorAll('input[readonly][id="invitation-link"]').forEach(function (field) {
            field.addEventListener('focus', function () { field.select(); });
        });

        // Repeating author/contributor rows: clone the last row on demand.
        document.querySelectorAll('[data-repeat]').forEach(function (group) {
            var button = group.querySelector('[data-repeat-add]');
            if (!button) {
                return;
            }
            button.hidden = false;
            button.addEventListener('click', function () {
                var rows = group.querySelectorAll('[data-repeat-row]');
                if (rows.length >= 20) {
                    return;
                }
                var last = rows[rows.length - 1];
                var clone = last.cloneNode(true);
                var index = rows.length;
                clone.querySelectorAll('input, select, label').forEach(function (el) {
                    ['id', 'for'].forEach(function (attr) {
                        var value = el.getAttribute(attr);
                        if (value) {
                            el.setAttribute(attr, value.replace(/-\d+$/, '-' + index));
                        }
                    });
                    if (el.tagName === 'INPUT') {
                        el.value = '';
                    }
                });
                last.parentNode.insertBefore(clone, last.nextSibling);
                var input = clone.querySelector('input');
                if (input) {
                    input.focus();
                }
            });
        });
    });
})();
