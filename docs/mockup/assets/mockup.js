/* ==========================================================================
   docs/mockup/assets/mockup.js
   --------------------------------------------------------------------------
   Shared behaviour for the mockups.

   Rules (mirrors .github/instructions/frontend.instructions.md):
   - no inline <script> blocks; every behaviour lives in this file
   - init*() functions are registered from a single DOMContentLoaded listener
   - never interpolate user data into JavaScript: read it from a data-
     attribute instead
   - every localStorage access is wrapped in try/catch
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

    document.addEventListener('DOMContentLoaded', function () {
        initActiveMarker();

        // The skeleton exposes the flag reader so screens built later can
        // reuse it; the application equivalent is a server-side predicate
        // published as a data- flag, never re-derived in the browser.
        document.documentElement.setAttribute('data-mockup-ready', 'true');
    });

    window.Mockup = {
        readFlag: readFlag
    };
})();
