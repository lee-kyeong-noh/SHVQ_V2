/* ========================================
   SHVQ V2 — CSRF Token Manager
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {
    /* 페이지 로드 시 메타 태그에서 즉시 토큰 읽기 (세션과 동일한 토큰 보장) */
    var _metaEl = document.querySelector('meta[name="csrf-token"]');
    var _token  = (_metaEl && _metaEl.getAttribute('content')) ? _metaEl.getAttribute('content') : '';
    var _headerName = 'X-CSRF-Token';
    var _endpoint = 'dist_process/saas/Auth.php?todo=csrf';
    var _inflight = null;

    SHV.csrf = {
        /** Get current token */
        get: function () {
            return _token;
        },

        /** Set token (called after login or csrf fetch) */
        set: function (token) {
            _token = token || '';
        },

        /** Return header object for fetch */
        header: function () {
            var h = {};
            if (_token) h[_headerName] = _token;
            return h;
        },

        /**
         * Fetch fresh token from server (cache: no-store)
         * 동시 다중 호출은 하나의 요청으로 병합
         * @returns {Promise<string>}
         */
        init: function () {
            if (_inflight) return _inflight;
            _inflight = fetch(_endpoint + '&_t=' + Date.now(), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok && data.csrf_token) {
                    _token = data.csrf_token;
                }
                return _token;
            })
            .catch(function () {
                return _token;
            })
            .finally(function () {
                _inflight = null;
            });
            return _inflight;
        },

        /**
         * Ensure a valid token exists, fetching if needed
         * @returns {Promise<string>}
         */
        ensure: function () {
            if (_token) return Promise.resolve(_token);
            return SHV.csrf.init();
        }
    };
})(window.SHV);
