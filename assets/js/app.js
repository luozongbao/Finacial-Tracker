/* ==========================================================================
   assets/js/app.js
   --------------------------------------------------------------------------
   Every behaviour in the application — issue-013 §9.

   Ported from docs/mockup/assets/mockup.js, with three deliberate changes:

   1. initActiveMarker() is NOT ported. It marks the current mockup screen
      for the reviewer; the application has no gallery to mark.

   2. The theme toggle sets the EXPLICIT data-theme attribute in both
      directions. The mockup removed the attribute when it went light,
      which would hand a system-dark visitor straight back to dark. The
      reference stylesheet declares :root[data-theme="light"] for exactly
      that case.

   3. The preference lives under the key "theme" and is mirrored into a
      cookie. The cookie is what the SERVER reads to render
      <html data-theme="...">, because there is no inline <script> anywhere
      in this application (issue-013 §11) and an unstyled flash is a
      defect, not a flourish.

   Rules (mirrors .github/instructions/frontend.instructions.md):
   - no inline <script> blocks; every behaviour lives in this file
   - init*() functions are registered from a single DOMContentLoaded listener
   - never interpolate user data into JavaScript: read it from a data-
     attribute instead
   - every localStorage access is wrapped in try/catch
   - navigator.clipboard does not exist outside a secure context — always
     provide a fallback
   ========================================================================== */

(function () {
    'use strict';

    /**
     * The two theme values the stylesheet understands. Anything else is
     * ignored rather than written to the document.
     *
     * @type {string[]}
     */
    var THEMES = ['dark', 'light'];

    /**
     * The key the theme preference is stored under in localStorage.
     *
     * @type {string}
     */
    var STORAGE_KEY = 'theme';

    /**
     * The cookie the preference is mirrored into. The server reads it so
     * the attribute can be rendered before the first paint.
     *
     * @type {string}
     */
    var COOKIE_NAME = 'theme';

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
     * The theme in force right now.
     *
     * An explicit data-theme attribute wins. Without one, the system
     * preference decides — the same rule the stylesheet's media query
     * applies, so the toggle and the rendering agree.
     *
     * @returns {string} 'dark' or 'light'
     */
    function currentTheme() {
        var attr = document.documentElement.getAttribute('data-theme');
        if (THEMES.indexOf(attr) !== -1) {
            return attr;
        }
        if (window.matchMedia) {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return 'light';
    }

    /**
     * The stored preference, or an empty string when there is none.
     * localStorage may be unavailable in a sandboxed iframe or in private
     * mode, so every access is wrapped in try/catch.
     *
     * @returns {string}
     */
    function readStoredTheme() {
        var value = '';

        try {
            value = window.localStorage.getItem(STORAGE_KEY) || '';
        } catch (error) {
            return '';
        }

        return THEMES.indexOf(value) === -1 ? '' : value;
    }

    /**
     * Writes the preference to localStorage and to the cookie.
     *
     * Both stores are written together: localStorage is what the browser
     * reads, the cookie is what the server reads. A failure in either one
     * must not stop the other.
     *
     * @param {string} theme
     * @returns {void}
     */
    function storeTheme(theme) {
        try {
            window.localStorage.setItem(STORAGE_KEY, theme);
        } catch (error) {
            /* storage unavailable — the toggle still works for this page */
        }

        try {
            document.cookie = COOKIE_NAME + '=' + encodeURIComponent(theme)
                + '; path=/; max-age=31536000; SameSite=Lax';
        } catch (error) {
            /* cookies unavailable — the server falls back to the system theme */
        }
    }

    /**
     * Applies a theme to the document and remembers it.
     *
     * @param {string} theme
     * @returns {void}
     */
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        storeTheme(theme);
    }

    /**
     * Keeps every theme toggle button's pressed state in step with the
     * theme in force. The button is a toggle button: pressed means "dark
     * mode is on".
     *
     * @param {string} theme
     * @returns {void}
     */
    function syncThemeButtons(theme) {
        var buttons = document.querySelectorAll('[data-theme-toggle]');

        Array.prototype.forEach.call(buttons, function (button) {
            button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        });
    }

    /**
     * Applies the stored preference when the page loads.
     *
     * The server renders the attribute from the cookie when it has one, and
     * that rendering wins — it is already on screen. When there is no
     * server-rendered choice, the stored one is applied here and written to
     * the cookie, so the next request needs no flash at all.
     *
     * @returns {void}
     */
    function initThemePreference() {
        var rendered = document.documentElement.getAttribute('data-theme');

        if (THEMES.indexOf(rendered) !== -1) {
            storeTheme(rendered);
            syncThemeButtons(rendered);
            return;
        }

        var stored = readStoredTheme();

        if (stored) {
            applyTheme(stored);
            syncThemeButtons(stored);
            return;
        }

        syncThemeButtons(currentTheme());
    }

    /**
     * Flips the theme between dark and light.
     *
     * @returns {void}
     */
    function initThemeToggle() {
        var buttons = document.querySelectorAll('[data-theme-toggle]');

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                var next = currentTheme() === 'dark' ? 'light' : 'dark';
                applyTheme(next);
                syncThemeButtons(next);
            });
        });
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
     * empty. A convenience only — the server validates regardless.
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
     * Marks the active period option from a data attribute, so the markup
     * does not have to hard-code the selection.
     *
     * The marker is aria-current, not a class: the reference stylesheet
     * styles .period__link[aria-current="true"] and knows no other name.
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
     * Formats a UTC date as YYYY-MM-DD.
     *
     * The dates are UTC because the application stores transaction dates
     * in UTC — a local date would disagree with the server around
     * midnight.
     *
     * @param {Date} date
     * @returns {string}
     */
    function isoDate(date) {
        var year = date.getUTCFullYear();
        var month = date.getUTCMonth() + 1;
        var day = date.getUTCDate();

        return year
            + '-' + (month < 10 ? '0' : '') + month
            + '-' + (day < 10 ? '0' : '') + day;
    }

    /**
     * The range of a period preset, computed from the current date.
     *
     * The week respects the week start, which the server renders into
     * data-week-start — the same WEEK_START constant the server uses, so
     * the button and the server agree on where the week begins.
     *
     * @param {string} kind 'month', 'week' or 'year'
     * @param {string} weekStart the week start, 0 or 7 for Sunday
     * @returns {string[]} the [from, to] pair
     */
    function presetRange(kind, weekStart) {
        var now = new Date();
        var year = now.getUTCFullYear();
        var month = now.getUTCMonth();

        if (kind === 'year') {
            return [year + '-01-01', year + '-12-31'];
        }

        if (kind === 'week') {
            // The ISO weekday: 1 = Monday ... 7 = Sunday.
            var weekday = now.getUTCDay() === 0 ? 7 : now.getUTCDay();

            var start = parseInt(weekStart, 10);
            if (isNaN(start) || start < 0 || start > 7) {
                start = 1;
            }
            if (start === 0) {
                start = 7;
            }

            var offset = (weekday - start + 7) % 7;
            var first = new Date(Date.UTC(year, month, now.getUTCDate() - offset));
            var last = new Date(first.getTime() + (6 * 24 * 60 * 60 * 1000));

            return [isoDate(first), isoDate(last)];
        }

        // The month: the first day and the last day of the current month.
        var lastDay = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();

        return [
            isoDate(new Date(Date.UTC(year, month, 1))),
            isoDate(new Date(Date.UTC(year, month, lastDay)))
        ];
    }

    /**
     * Fills the two date fields from a preset. The dates are read from data
     * attributes — never interpolated into a JavaScript string.
     *
     * The dates are recomputed here, on the client, so a button rendered
     * on one day never carries a range that has gone stale. The server
     * rendered the attributes for a visitor without JavaScript; the
     * script replaces them with the range of the day the page is READ.
     *
     * The preset does not submit. The visitor presses "Apply filters".
     *
     * @returns {void}
     */
    function initPeriodPresets() {
        var presets = document.querySelectorAll('[data-preset]');

        Array.prototype.forEach.call(presets, function (preset) {
            var group = preset.closest('[data-week-start]');
            var weekStart = group ? group.getAttribute('data-week-start') : '1';
            var range = presetRange(preset.getAttribute('data-preset'), weekStart);

            preset.setAttribute('data-preset-from', range[0]);
            preset.setAttribute('data-preset-to', range[1]);

            preset.addEventListener('click', function (event) {
                event.preventDefault();

                var form = preset.closest('form');
                if (!form) {
                    return;
                }

                var from = form.querySelector('[name="from"]');
                var to = form.querySelector('[name="to"]');

                if (from) {
                    from.value = preset.getAttribute('data-preset-from');
                }
                if (to) {
                    to.value = preset.getAttribute('data-preset-to');
                }
            });
        });
    }

    /**
     * Opens and closes the delete-confirmation modal. The trigger carries the
     * target id in a data attribute.
     *
     * A modal that HAS a trigger is hidden on load and opened by the
     * trigger. A modal that has NO trigger is left visible: it is the only
     * way to reach it, and the delete must work without JavaScript.
     *
     * @returns {void}
     */
    function initModals() {
        var triggers = document.querySelectorAll('[data-modal-open]');

        Array.prototype.forEach.call(triggers, function (trigger) {
            var modal = document.getElementById(trigger.getAttribute('data-modal-open'));
            if (modal) {
                modal.hidden = true;
            }

            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                var target = document.getElementById(trigger.getAttribute('data-modal-open'));
                if (!target) {
                    return;
                }
                target.hidden = false;
                var focusTarget = target.querySelector('button, [href], input');
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
     * Reloads the current page. The trigger carries no data beyond its own
     * presence — it reloads the URL that is already in the address bar, so
     * the selected period survives the reload.
     *
     * @returns {void}
     */
    function initReload() {
        var buttons = document.querySelectorAll('[data-reload]');

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                window.location.reload();
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

    document.addEventListener('DOMContentLoaded', function () {
        initThemePreference();
        initThemeToggle();
        initPasswordReveal();
        initRequiredToggle();
        initPeriodMarker();
        initPeriodPresets();
        initModals();
        initReload();
        initCopy();

        // The screen is live. The flag lets a view tell "the script has not
        // run yet" from "the script ran and found nothing to do".
        document.documentElement.setAttribute('data-app-ready', 'true');
    });

    window.App = {
        readFlag: readFlag
    };
})();
