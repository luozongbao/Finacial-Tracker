/* ==========================================================================
   docs/mockup/assets/mockup.js
   --------------------------------------------------------------------------
   Shared behaviour for the mockups — issue-004 §7.

   Rules (mirrors .github/instructions/frontend.instructions.md):
   - no inline <script> blocks; every behaviour lives in this file
   - init*() functions are registered from a single DOMContentLoaded listener
   - never interpolate user data into JavaScript: read it from a data-
     attribute instead
   - every localStorage access is wrapped in try/catch
   - navigator.clipboard does not exist outside a secure context — always
     provide a fallback

   Mockup interactivity is ILLUSTRATIVE: a reviewer must understand each
   screen from a still image.
   ========================================================================== */

(function () {
    'use strict';

    /**
     * Marks the gallery item that corresponds to the current page, so the
     * reader can see where they are when navigating between mockups.
     *
     * @returns {void}
     */
    function initActiveMarker() {
        var items = document.querySelectorAll('[data-screen]');
        if (!items.length) {
            return;
        }

        var current = window.location.pathname.split('/').pop() || 'index.html';

        Array.prototype.forEach.call(items, function (item) {
            if (item.getAttribute('data-screen') === current) {
                item.setAttribute('aria-current', 'page');
            }
        });
    }

    /**
     * Reads a boolean flag from a data attribute.
     * Never builds a JavaScript string from interpolated data.
     *
     * @param {string} name
     * @param {boolean} fallback
     * @returns {boolean}
     */
    function readFlag(name, fallback) {
        var node = document.querySelector('[data-' + name + ']');
        if (!node) {
            return fallback;
        }
        return node.getAttribute('data-' + name) === 'true';
    }

    /**
     * Toggles the password reveal state of a password field.
     *
     * @returns {void}
     */
    function initPasswordReveal() {
        var toggles = document.querySelectorAll('[data-reveal-target]');

        Array.prototype.forEach.call(toggles, function (toggle) {
            toggle.addEventListener('click', function () {
                var target = document.getElementById(toggle.getAttribute('data-reveal-target'));
                if (!target) {
                    return;
                }
                var revealed = target.getAttribute('type') === 'text';
                target.setAttribute('type', revealed ? 'password' : 'text');
                toggle.textContent = revealed ? 'Show' : 'Hide';
                toggle.setAttribute('aria-pressed', revealed ? 'false' : 'true');
            });
        });
    }

    /**
     * Toggles the disabled state of a submit button when a required field is
     * empty. Illustrative only — the server validates regardless.
     *
     * @returns {void}
     */
    function initRequiredToggle() {
        var forms = document.querySelectorAll('[data-disable-until-filled]');

        Array.prototype.forEach.call(forms, function (form) {
            var button = form.querySelector('[data-toggle-submit]');
            if (!button) {
                return;
            }

            var required = form.querySelectorAll('[required]');

            function sync() {
                var complete = true;
                Array.prototype.forEach.call(required, function (field) {
                    if (!field.value.trim()) {
                        complete = false;
                    }
                });
                button.toggleAttribute('disabled', !complete);
            }

            Array.prototype.forEach.call(required, function (field) {
                field.addEventListener('input', sync);
            });
            sync();
        });
    }

    /**
     * Marks the active period option from a data attribute, so the mockup
     * can show "Today · This week · This month · This year · Custom" with
     * one option current without hard-coding the selection in markup.
     *
     * @returns {void}
     */
    function initPeriodMarker() {
        var groups = document.querySelectorAll('[data-period-group]');

        Array.prototype.forEach.call(groups, function (group) {
            var active = group.getAttribute('data-period-group');
            var links = group.querySelectorAll('[data-period]');

            Array.prototype.forEach.call(links, function (link) {
                if (link.getAttribute('data-period') === active) {
                    link.setAttribute('aria-current', 'true');
                }
            });
        });
    }

    /**
     * Demonstrates the period-preset behaviour: clicking a preset fills the
     * two date fields. The dates are read from data attributes — never
     * interpolated into a JavaScript string.
     *
     * @returns {void}
     */
    function initPeriodPresets() {
        var presets = document.querySelectorAll('[data-preset-from]');

        Array.prototype.forEach.call(presets, function (preset) {
            preset.addEventListener('click', function (event) {
                event.preventDefault();
                var from = document.getElementById(preset.getAttribute('data-preset-from'));
                var to = document.getElementById(preset.getAttribute('data-preset-to'));
                if (from) {
                    from.value = preset.getAttribute('data-preset-start');
                }
                if (to) {
                    to.value = preset.getAttribute('data-preset-end');
                }
            });
        });
    }

    /**
     * Opens and closes the delete-confirmation modal. The trigger carries the
     * target id in a data attribute.
     *
     * @returns {void}
     */
    function initModals() {
        var triggers = document.querySelectorAll('[data-modal-open]');

        Array.prototype.forEach.call(triggers, function (trigger) {
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                var modal = document.getElementById(trigger.getAttribute('data-modal-open'));
                if (!modal) {
                    return;
                }
                modal.hidden = false;
                var focusTarget = modal.querySelector('button, [href], input');
                if (focusTarget) {
                    focusTarget.focus();
                }
            });
        });

        var closers = document.querySelectorAll('[data-modal-close]');

        Array.prototype.forEach.call(closers, function (closer) {
            closer.addEventListener('click', function (event) {
                event.preventDefault();
                var modal = document.getElementById(closer.getAttribute('data-modal-close'));
                if (modal) {
                    modal.hidden = true;
                }
            });
        });
    }

    /**
     * Copies a value to the clipboard, with a fallback for insecure
     * contexts where navigator.clipboard is undefined.
     *
     * @returns {void}
     */
    function initCopy() {
        var buttons = document.querySelectorAll('[data-copy-target]');

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                var source = document.getElementById(button.getAttribute('data-copy-target'));
                if (!source) {
                    return;
                }
                var value = source.value || source.textContent || '';
                var original = button.getAttribute('data-label') || button.textContent;

                function done() {
                    button.textContent = 'Copied';
                    window.setTimeout(function () {
                        button.textContent = original;
                    }, 1500);
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).then(done, function () {
                        done();
                    });
                    return;
                }

                try {
                    var helper = document.createElement('textarea');
                    helper.value = value;
                    helper.setAttribute('readonly', 'readonly');
                    helper.style.position = 'absolute';
                    helper.style.left = '-9999px';
                    document.body.appendChild(helper);
                    helper.select();
                    document.execCommand('copy');
                    document.body.removeChild(helper);
                    done();
                } catch (error) {
                    button.textContent = original;
                }
            });
        });
    }

    /**
     * Demonstrates the theme toggle. The preference is stored in
     * localStorage, which may be unavailable in a sandboxed iframe or in
     * private mode — every access is wrapped in try/catch.
     *
     * @returns {void}
     */
    function initThemeToggle() {
        var buttons = document.querySelectorAll('[data-theme-toggle]');

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                var root = document.documentElement;
                var isDark = root.getAttribute('data-theme') === 'dark';

                if (isDark) {
                    root.removeAttribute('data-theme');
                } else {
                    root.setAttribute('data-theme', 'dark');
                }

                try {
                    window.localStorage.setItem('mockup-theme', isDark ? 'light' : 'dark');
                } catch (error) {
                    /* storage unavailable — the toggle still works for this page */
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initActiveMarker();
        initPasswordReveal();
        initRequiredToggle();
        initPeriodMarker();
        initPeriodPresets();
        initModals();
        initCopy();
        initThemeToggle();

        // The skeleton exposes the flag reader so screens built later can
        // reuse it; the application equivalent is a server-side predicate
        // published as a data- flag, never re-derived in the browser.
        document.documentElement.setAttribute('data-mockup-ready', 'true');
    });

    window.Mockup = {
        readFlag: readFlag
    };
})();
