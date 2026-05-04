/**
 * HMS shell — staggered page entrance and light motion (respects prefers-reduced-motion).
 */
(function () {
    'use strict';

    /** Iterate NodeList / array-like without relying on NodeList.prototype.forEach (older WebViews / quirks). */
    function eachNodeList(list, fn) {
        if (!list || !list.length) {
            return;
        }
        Array.prototype.forEach.call(list, fn);
    }

    function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function initStagger() {
        var shell = document.querySelector('.hms-ehr-shell');
        if (!shell) {
            return;
        }
        var content = document.querySelector('.page-wrapper .content');
        if (!content) {
            return;
        }
        var skip = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1 };
        var i;
        var el;
        var idx = 0;
        for (i = 0; i < content.children.length; i++) {
            el = content.children[i];
            if (skip[el.tagName]) {
                continue;
            }
            if (el.classList && el.classList.contains('hms-flash-toast')) {
                continue;
            }
            el.style.setProperty('--hms-stagger', idx * 55 + 'ms');
            el.classList.add('hms-enter-block');
            idx += 1;
        }

        if (idx === 0) {
            return;
        }

        document.documentElement.classList.add('hms-motion');

        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                for (i = 0; i < content.children.length; i++) {
                    el = content.children[i];
                    if (el.classList && el.classList.contains('hms-enter-block')) {
                        el.classList.add('hms-enter-block--in');
                    }
                }
            });
        });
    }

    function initHubStagger() {
        var grid = document.querySelector('.hms-hub-grid');
        if (!grid || prefersReducedMotion()) {
            return;
        }
        var cols = grid.querySelectorAll(':scope > .col-md-6, :scope > .col-lg-4');
        if (!cols.length) {
            return;
        }
        eachNodeList(cols, function (col, i) {
            col.style.setProperty('--hms-hub-delay', 80 + i * 40 + 'ms');
            col.classList.add('hms-hub-col-anim');
        });
        window.requestAnimationFrame(function () {
            eachNodeList(cols, function (col) {
                col.classList.add('hms-hub-col-anim--in');
            });
        });
    }

    function initSidebarNavHint() {
        if (prefersReducedMotion()) {
            return;
        }
        var nav = document.querySelector('#sidebar-menu.hms-sidebar-nav');
        if (!nav) {
            return;
        }
        eachNodeList(nav.querySelectorAll(':scope > li.hms-sidebar-item > a'), function (a) {
            a.classList.add('hms-sidebar-link-anim');
        });
    }

    function run() {
        if (prefersReducedMotion()) {
            document.documentElement.classList.add('hms-motion-reduced');
            return;
        }
        try {
            initStagger();
            initHubStagger();
            initSidebarNavHint();
        } catch (e) {
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('hms-shell-animations:', e);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
