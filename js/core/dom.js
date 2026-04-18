/* ========================================
   SHVQ V2 — DOM Helpers
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {
    /** querySelector shorthand */
    SHV.$ = function (selector, parent) {
        return (parent || document).querySelector(selector);
    };

    /** querySelectorAll → Array */
    SHV.$$ = function (selector, parent) {
        return Array.from((parent || document).querySelectorAll(selector));
    };

    SHV.dom = {
        /**
         * Create element
         * @param {string} tag
         * @param {Object} [attrs]
         * @param {(string|Node)[]} [children]
         * @returns {HTMLElement}
         */
        create: function (tag, attrs, children) {
            var el = document.createElement(tag);
            if (attrs) {
                Object.keys(attrs).forEach(function (key) {
                    if (key === 'className') {
                        el.className = attrs[key];
                    } else if (key === 'dataset') {
                        Object.keys(attrs[key]).forEach(function (dk) {
                            el.dataset[dk] = attrs[key][dk];
                        });
                    } else if (key.startsWith('on') && typeof attrs[key] === 'function') {
                        el.addEventListener(key.slice(2).toLowerCase(), attrs[key]);
                    } else {
                        el.setAttribute(key, attrs[key]);
                    }
                });
            }
            if (children) {
                children.forEach(function (child) {
                    if (typeof child === 'string') {
                        el.appendChild(document.createTextNode(child));
                    } else if (child instanceof Node) {
                        el.appendChild(child);
                    }
                });
            }
            return el;
        },

        /** Set innerHTML safely */
        html: function (el, content) {
            if (el) el.innerHTML = content;
        },

        /** Set textContent */
        text: function (el, content) {
            if (el) el.textContent = content;
        },

        show: function (el) {
            if (el) el.style.display = '';
        },

        hide: function (el) {
            if (el) el.style.display = 'none';
        },

        toggle: function (el) {
            if (!el) return;
            el.style.display = el.style.display === 'none' ? '' : 'none';
        },

        addClass: function (el, cls) {
            if (el && cls) el.classList.add(cls);
        },

        removeClass: function (el, cls) {
            if (el && cls) el.classList.remove(cls);
        },

        toggleClass: function (el, cls) {
            if (el && cls) el.classList.toggle(cls);
        },

        hasClass: function (el, cls) {
            return el ? el.classList.contains(cls) : false;
        },

        /** Remove element from DOM */
        remove: function (el) {
            if (el && el.parentNode) el.parentNode.removeChild(el);
        },

        /** Get/set attribute */
        attr: function (el, name, value) {
            if (!el) return null;
            if (value === undefined) return el.getAttribute(name);
            el.setAttribute(name, value);
            return el;
        },

        /** Get closest ancestor matching selector */
        closest: function (el, selector) {
            return el ? el.closest(selector) : null;
        },

        /** Empty element contents */
        empty: function (el) {
            if (el) el.innerHTML = '';
        }
    };
})(window.SHV);
