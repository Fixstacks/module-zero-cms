/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Zero CMS countdown (templates/components/countdown.phtml): counts down to
 * data-ends (ms since epoch) once a second, from the visitor's clock. At the
 * deadline it shows the component's "over" message, or hides the component
 * when there is none. Loaded once per countdown on the page; each one is set
 * up once.
 */
(function () {
    'use strict';

    function setUp(root) {
        if (root.hasAttribute('data-ready')) {
            return;
        }
        root.setAttribute('data-ready', '');
        var ends = Number(root.getAttribute('data-ends'));
        var units = {};
        root.querySelectorAll('[data-unit]').forEach(function (box) {
            units[box.getAttribute('data-unit')] = { box: box, value: box.querySelector('.countdown > span') };
        });
        var timer = 0;

        function show(unit, value) {
            if (units[unit] && units[unit].value.textContent !== String(value)) {
                units[unit].value.style.setProperty('--value', value);
                units[unit].value.textContent = value;
            }
        }

        function finish() {
            clearInterval(timer);
            root.querySelectorAll('[data-countdown-timer], [data-countdown-when]').forEach(function (node) { node.hidden = true; });
            var ended = root.querySelector('[data-countdown-ended]');
            if (ended) {
                ended.hidden = false;
            } else {
                (root.closest('.zero-cms-component') || root).hidden = true;
            }
        }

        function tick() {
            var left = Math.max(0, Math.floor((ends - Date.now()) / 1000));
            if (!left) {
                finish();
                return;
            }
            var days = Math.min(999, Math.floor(left / 86400));
            show('days', days);
            if (units.days) {
                units.days.box.hidden = days === 0;
            }
            show('hours', Math.floor(left % 86400 / 3600));
            show('minutes', Math.floor(left % 3600 / 60));
            show('seconds', left % 60);
        }

        tick();
        if (root.querySelector('[data-countdown-timer]') && !root.querySelector('[data-countdown-timer]').hidden) {
            timer = setInterval(tick, 1000);
        }
    }

    document.querySelectorAll('[data-zero-countdown]').forEach(setUp);
})();
