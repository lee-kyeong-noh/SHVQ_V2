/* ========================================
   SHVQ V2 — Event Delegation
   data-action 기반 자동 바인딩
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {
    var _handlers = {};

    SHV.events = {
        /**
         * Register delegated event
         * @param {string} selector - CSS selector
         * @param {string} eventType - click, change, input, etc.
         * @param {Function} handler - function(e, el)
         */
        on: function (selector, eventType, handler) {
            var key = eventType + '::' + selector;
            if (_handlers[key]) return; // prevent duplicate

            var listener = function (e) {
                var target = e.target.closest(selector);
                if (target && document.body.contains(target)) {
                    handler.call(target, e, target);
                }
            };

            document.addEventListener(eventType, listener, true);
            _handlers[key] = listener;
        },

        /**
         * Remove delegated event
         * @param {string} selector
         * @param {string} eventType
         */
        off: function (selector, eventType) {
            var key = eventType + '::' + selector;
            if (_handlers[key]) {
                document.removeEventListener(eventType, _handlers[key], true);
                delete _handlers[key];
            }
        },

        /**
         * One-time event
         * @param {string} selector
         * @param {string} eventType
         * @param {Function} handler
         */
        once: function (selector, eventType, handler) {
            var self = this;
            var key = eventType + '::once::' + selector;

            var wrapper = function (e) {
                var target = e.target.closest(selector);
                if (target && document.body.contains(target)) {
                    handler.call(target, e, target);
                    document.removeEventListener(eventType, wrapper, true);
                    delete _handlers[key];
                }
            };

            document.addEventListener(eventType, wrapper, true);
            _handlers[key] = wrapper;
        },

        /**
         * data-action auto binding
         * Usage: <button data-action="delete" data-id="123">
         * Register: SHV.events.action('delete', function(e, el) { ... })
         */
        action: function (actionName, handler) {
            this.on('[data-action="' + actionName + '"]', 'click', handler);
        },

        /** Remove all registered handlers */
        clear: function () {
            Object.keys(_handlers).forEach(function (key) {
                var parts = key.split('::');
                var eventType = parts[0];
                document.removeEventListener(eventType, _handlers[key], true);
            });
            _handlers = {};
        }
    };
})(window.SHV);
