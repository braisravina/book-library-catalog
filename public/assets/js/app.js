// Progressive enhancement only: every feature works without JavaScript.
// No inline scripts are used anywhere, so the CSP can forbid them.
// ISBN rules shared by the ISBN step; mirrors DigitalLibrary\Catalogue\Isbn
// (normalize() and problem()). Keep both in step.
var DigitalLibraryIsbn = (function () {
    'use strict';

    var normalize = function (raw) {
        return String(raw).trim().toUpperCase()
            .replace(/^ISBN(?:-1[03])?:?/, '')
            .replace(/[\s\-‐-―]/g, '');
    };

    var valid10 = function (isbn) {
        var sum = 0;
        for (var i = 0; i < 10; i++) {
            sum += (isbn.charAt(i) === 'X' ? 10 : Number(isbn.charAt(i))) * (10 - i);
        }
        return sum % 11 === 0;
    };

    var valid13 = function (isbn) {
        var sum = 0;
        for (var i = 0; i < 12; i++) {
            sum += Number(isbn.charAt(i)) * (i % 2 === 0 ? 1 : 3);
        }
        return String((10 - (sum % 10)) % 10) === isbn.charAt(12);
    };

    // Returns null when valid, otherwise the reason: required, characters,
    // length, prefix or checksum (the data-msg-* suffix of the message).
    var problem = function (value) {
        if (value === '') {
            return 'required';
        }
        if (!/^\d+$/.test(value) && !/^\d{9}X$/.test(value)) {
            return 'characters';
        }
        if (value.length !== 10 && value.length !== 13) {
            return 'length';
        }
        if (value.length === 13 && !/^97[89]/.test(value)) {
            return 'prefix';
        }
        if (value.length === 10 ? !valid10(value) : !valid13(value)) {
            return 'checksum';
        }
        return null;
    };

    return { normalize: normalize, problem: problem };
})();

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

        // Fragment invitation links (/invite/redeem#CODE): the code never reaches
        // the server in a URL. Move it into the form (POST body, CSRF-protected),
        // drop it from the address bar and this history entry, then submit.
        document.querySelectorAll('form[data-invitation-fragment]').forEach(function (form) {
            var code = window.location.hash.replace(/^#/, '');
            if (code === '') {
                return;
            }
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname);
            }
            var field = form.querySelector('input[name="invitation_code"]');
            if (field && /^[A-Za-z0-9_-]{43}$/.test(code)) {
                field.value = code;
                form.submit();
            }
        });

        // ISBN step: immediate validation (same rules as Isbn::problem() on
        // the server, which stays authoritative) and an accessible loading
        // state while the lookup runs. Manual entry is never blocked.
        document.querySelectorAll('form[data-isbn-form]').forEach(function (form) {
            var input = form.querySelector('[data-isbn-input]');
            var check = form.querySelector('[data-isbn-check]');
            var status = form.querySelector('[data-lookup-status]');
            var lookup = form.querySelector('[data-isbn-lookup]');
            if (!input || !check || !lookup) {
                return;
            }
            var lookupLabel = lookup.textContent;

            var show = function (problem, valid) {
                var server = form.querySelector('[data-isbn-server-error]');
                if (server) {
                    server.parentNode.removeChild(server);
                }
                if (problem) {
                    input.setAttribute('aria-invalid', 'true');
                    check.className = 'isbn-check field-errors';
                    check.textContent = problem;
                } else {
                    input.removeAttribute('aria-invalid');
                    check.className = valid ? 'isbn-check is-valid' : 'isbn-check';
                    check.textContent = valid || '';
                }
            };

            // Returns [problem message or null, validity message or null].
            var evaluate = function (final) {
                var value = DigitalLibraryIsbn.normalize(input.value);
                var result = DigitalLibraryIsbn.problem(value);
                if (result === null) {
                    return [null, input.getAttribute(value.length === 10 ? 'data-msg-valid10' : 'data-msg-valid13')];
                }
                // While typing, an unfinished number is not an error yet: up to
                // 13 digits it may still become an ISBN-13 (only invalid
                // characters are reported at once).
                if (!final && (result === 'required' || /^I(S(B(N)?)?)?$/.test(value)
                        || (result !== 'characters' && value.length < 13))) {
                    return [null, null];
                }
                var message = input.getAttribute('data-msg-' + result) || '';
                return [message.replace(':count', String(value.length)), null];
            };

            input.addEventListener('input', function () {
                var r = evaluate(false);
                show(r[0], r[1]);
            });
            input.addEventListener('blur', function () {
                if (input.value.trim() !== '') {
                    var r = evaluate(true);
                    show(r[0], r[1]);
                }
            });

            var reset = function () {
                form.removeAttribute('aria-busy');
                lookup.disabled = false;
                lookup.textContent = lookupLabel;
                if (status) {
                    status.textContent = '';
                }
            };
            // Coming back with the Back button restores the page from cache.
            window.addEventListener('pageshow', reset);

            form.addEventListener('submit', function (event) {
                var submitter = event.submitter || document.activeElement;
                if (submitter && submitter.hasAttribute && submitter.hasAttribute('data-isbn-manual')) {
                    return;
                }
                var r = evaluate(true);
                if (r[0]) {
                    event.preventDefault();
                    show(r[0], null);
                    input.focus();
                    return;
                }
                if (form.getAttribute('aria-busy') === 'true') {
                    event.preventDefault(); // already looking up
                    return;
                }
                form.setAttribute('aria-busy', 'true');
                if (status) {
                    status.textContent = status.getAttribute('data-message') || '';
                }
                // Disabled after the submission has started (the button has no name).
                window.setTimeout(function () {
                    lookup.disabled = true;
                    lookup.textContent = lookup.getAttribute('data-busy-label') || lookupLabel;
                }, 0);
            });
        });

        // Lookup review: "Use this value" copies another source's value into
        // the matching form field. The user still reviews and submits the form.
        document.querySelectorAll('[data-fill-target]').forEach(function (button) {
            var field = document.getElementById(button.getAttribute('data-fill-target'));
            if (!field || !('value' in field)) {
                return;
            }
            button.hidden = false;
            button.addEventListener('click', function () {
                field.value = button.getAttribute('data-fill-value') || '';
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
                var details = field.closest ? field.closest('details') : null;
                if (details) {
                    details.open = true;
                }
                var announce = document.querySelector('[data-fill-status]');
                if (announce) {
                    announce.textContent = announce.getAttribute('data-message') || '';
                }
                field.focus();
            });
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
