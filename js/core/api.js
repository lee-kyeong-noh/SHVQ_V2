/* ========================================
   SHVQ V2 — API Wrapper
   CSRF header auto-injection + error handling
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {

    function buildUrl(url, params) {
        if (!params) return url;
        var qs = Object.keys(params)
            .filter(function (k) { return params[k] !== undefined && params[k] !== null; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
            .join('&');
        if (!qs) return url;
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + qs;
    }

    function handleResponse(res) {
        if (res.status === 401) {
            window.location.href = 'login.php';
            return Promise.reject({ error: 'UNAUTHORIZED', status: 401 });
        }

        /* 4xx/5xx — JSON 파싱 시도 후 실패 시 명확한 서버 오류 메시지 */
        if (!res.ok) {
            return res.text().then(function (text) {
                try {
                    var data = JSON.parse(text);
                    if (!data.ok) { handleApiError(data); }
                    return data;
                } catch (e) {
                    var msg = 'HTTP ' + res.status + ' 서버 오류가 발생했습니다.';
                    if (res.status >= 500) {
                        msg = '서버 오류가 발생했습니다. (' + res.status + ')';
                    } else if (res.status === 403) {
                        msg = '접근 권한이 없습니다.';
                    } else if (res.status === 404) {
                        msg = '요청한 리소스를 찾을 수 없습니다.';
                    }
                    if (SHV.toast) { SHV.toast.error(msg); }
                    return Promise.reject({ error: 'HTTP_ERROR', status: res.status, message: msg });
                }
            });
        }

        return res.json().then(function (data) {
            if (!data.ok) {
                handleApiError(data);
            }
            return data;
        });
    }

    function handleApiError(data) {
        var error = data.error || 'UNKNOWN_ERROR';
        var message = data.message || '';

        if (error === 'LOGIN_RATE_LIMITED' && SHV.toast) {
            SHV.toast.warn('로그인 시도가 너무 많습니다. ' + (data.retry_after || 300) + '초 후 재시도해주세요.');
        } else if (error === 'CSRF_TOKEN_INVALID' && SHV.toast) {
            /* 토스트 없이 조용히 처리 — post() 내부 retry 로직이 담당 */
        } else if (error === 'SERVER_ERROR' && SHV.toast) {
            SHV.toast.error(message || '서버 오류가 발생했습니다.');
        }
    }

    function handleNetworkError(err) {
        if (SHV.toast) {
            SHV.toast.error('네트워크 연결을 확인해주세요.');
        }
        return Promise.reject(err);
    }

    /** FormData에 CSRF 토큰 주입 */
    function injectCsrf(body) {
        var token = SHV.csrf ? SHV.csrf.get() : '';
        if (token) body.append('csrf_token', token);
    }

    /** 단일 POST 실행 (재시도 없음) */
    function doPost(url, data) {
        var body = new FormData();
        if (data) {
            Object.keys(data).forEach(function (key) {
                /* csrf_token은 호출자가 직접 넣어도 무시 — injectCsrf()가 최신 토큰 단독 주입 */
                if (key === 'csrf_token') return;
                if (data[key] !== undefined && data[key] !== null) {
                    body.append(key, data[key]);
                }
            });
        }
        injectCsrf(body);

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: Object.assign({
                'Accept': 'application/json'
            }, SHV.csrf ? SHV.csrf.header() : {}),
            body: body
        })
        .then(function (res) {
            return handleResponse(res).then(function (result) {
                if (result && result.csrf_token && SHV.csrf) {
                    SHV.csrf.set(result.csrf_token);
                }
                /* CSRF 실패는 retry 트리거를 위해 reject으로 변환 */
                if (result && result.error === 'CSRF_TOKEN_INVALID') {
                    return Promise.reject(result);
                }
                return result;
            });
        });
    }

    SHV.api = {
        /**
         * GET request
         * @param {string} url
         * @param {Object} [params]
         * @returns {Promise<Object>}
         */
        get: function (url, params) {
            return fetch(buildUrl(url, params), {
                method: 'GET',
                credentials: 'same-origin',
                headers: Object.assign({
                    'Accept': 'application/json'
                }, SHV.csrf ? SHV.csrf.header() : {})
            })
            .then(handleResponse)
            .catch(handleNetworkError);
        },

        /**
         * POST request (auto CSRF + CSRF 실패 시 1회 자동 재시도)
         * @param {string} url
         * @param {Object} data
         * @returns {Promise<Object>}
         */
        post: function (url, data) {
            /* CSRF 토큰 확보 후 요청 */
            var ensureToken = (SHV.csrf && SHV.csrf.ensure)
                ? SHV.csrf.ensure()
                : Promise.resolve('');

            return ensureToken.then(function () {
                return doPost(url, data);
            })
            .then(null, function (err) {
                /* CSRF 실패 → 토큰 강제 갱신 후 1회 재시도 */
                if (err && err.error === 'CSRF_TOKEN_INVALID' && SHV.csrf && SHV.csrf.init) {
                    return SHV.csrf.init().then(function () {
                        return doPost(url, data);
                    }).then(null, function () {
                        /* retry도 실패 → 사용자에게 안내 */
                        if (SHV.toast) SHV.toast.warn('보안 토큰이 만료되었습니다. 페이지를 새로고침해주세요.');
                        return Promise.reject({ error: 'CSRF_TOKEN_INVALID' });
                    });
                }
                return Promise.reject(err);
            })
            .catch(handleNetworkError);
        },

        /**
         * File upload (auto CSRF + CSRF 실패 시 1회 자동 재시도)
         * @param {string} url
         * @param {FormData} formData
         * @returns {Promise<Object>}
         */
        upload: function (url, formData) {
            var ensureToken = (SHV.csrf && SHV.csrf.ensure)
                ? SHV.csrf.ensure()
                : Promise.resolve('');

            return ensureToken.then(function () {
                injectCsrf(formData);
                return fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: Object.assign({
                        'Accept': 'application/json'
                    }, SHV.csrf ? SHV.csrf.header() : {}),
                    body: formData
                }).then(handleResponse);
            })
            .catch(handleNetworkError);
        }
    };
    /* ── Session Heartbeat ── */
    var HEARTBEAT_MS = 25 * 60 * 1000; /* 25분 */
    var _hbTimer = null;

    function heartbeat() {
        fetch('dist_process/saas/Auth.php?todo=heartbeat', {
            method: 'GET',
            credentials: 'same-origin'
        }).then(function (res) {
            if (res.status === 401) {
                window.location.href = 'login.php';
                return;
            }
            return res.json();
        }).then(function (data) {
            if (!data) return;
            if (!data.ok && data.error === 'FORCE_LOGGED_OUT') {
                stopHeartbeat();
                if (SHV.toast) {
                    SHV.toast.warn('다른 기기에서 로그인하여 자동 로그아웃됩니다.');
                }
                setTimeout(function () { window.location.href = 'login.php'; }, 2000);
            } else if (!data.ok && data.error === 'SESSION_EXPIRED') {
                window.location.href = 'login.php';
            }
        }).catch(function () { /* 네트워크 오류는 무시 — 다음 주기에 재시도 */ });
    }

    function startHeartbeat() {
        if (_hbTimer) return;
        _hbTimer = setInterval(heartbeat, HEARTBEAT_MS);
    }

    function stopHeartbeat() {
        if (_hbTimer) { clearInterval(_hbTimer); _hbTimer = null; }
    }

    /* 탭이 보일 때만 하트비트 */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stopHeartbeat(); }
        else { heartbeat(); startHeartbeat(); }
    });

    /* 페이지 로드 시 시작 */
    startHeartbeat();

})(window.SHV);
