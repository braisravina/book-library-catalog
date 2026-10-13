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
    });
})();
