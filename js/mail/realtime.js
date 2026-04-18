/* ========================================
   SHVQ V2 — Mail Realtime Module
   설계서 v3.2 §3.4 기준
   - WS 우선, SSE fallback
   - BroadcastChannel: 단일 탭만 연결, 이벤트 루프 방지
   - reconnect jitter (thundering herd 방지)
   - Visibility API: 탭 복귀 시 unread 재확인
   - heartbeat: 매 30초 → 서버 online 상태 유지
   ======================================== */
'use strict';

window.SHV = window.SHV || {};
SHV.mail  = SHV.mail || {};

SHV.mail.realtime = (function () {

    var HEARTBEAT_MS   = 30 * 1000;
    var TAB_ID         = Date.now() + '_' + Math.random().toString(36).slice(2, 8);

    var _ws             = null;
    var _sse            = null;
    var _connected      = false;
    var _token          = null;
    var _listeners      = [];
    var _reconnectTimer = null;
    var _heartbeatTimer = null;
    var _bc             = null;
    var _isLeader       = false;
    var _lastCtx        = null;
    var _lastSection    = null;

    /* ══════════════════════════════════════
       1. URL / 토큰
       ══════════════════════════════════════ */

    function _wsBaseUrl(section) {
        return (section && section.dataset && section.dataset.mailWsUrl)
            || (window.SHV_MAIL_WS_URL)
            || 'wss://shvq.kr:2347';
    }

    function _httpBaseUrl(section) {
        return _wsBaseUrl(section)
            .replace(/^wss:\/\//, 'https://')
            .replace(/^ws:\/\//,  'http://');
    }

    function _issueToken(ctx) {
        return SHV.api.post(ctx.api, {
            todo:         'ws_token_issue',
            service_code: ctx.serviceCode,
            tenant_id:    ctx.tenantId
        });
    }

    /* ══════════════════════════════════════
       2. 이벤트 디스패치 (루프 방지)
       ══════════════════════════════════════ */

    function _dispatch(data, source) {
        if (!data || !data.type) return;

        /* reconnect 이벤트 → 즉시 재연결 */
        if (data.type === 'reconnect') {
            _reconnectWithJitter();
            return;
        }

        /* BroadcastChannel: WS/SSE에서 온 이벤트만 다른 탭에 전파 */
        if (source !== 'bc' && _bc) {
            _bc.postMessage({ type: 'mail_event', source: 'bc', payload: data });
        }

        /* 리스너 호출 */
        _listeners.forEach(function (l) {
            try { l.onEvent(data); } catch (_) {}
        });
    }

    /* ══════════════════════════════════════
       3. WebSocket 연결 (우선)
       ══════════════════════════════════════ */

    function _tryWs(wsUrl, ctx, section) {
        try {
            var ws_ = new WebSocket(wsUrl);
            _ws = ws_;

            ws_.onopen = function () {
                _connected = true;
                if (SHV.notifications && SHV.notifications.setWsConnected) {
                    SHV.notifications.setWsConnected(true);
                }
            };

            ws_.onmessage = function (e) {
                try { _dispatch(JSON.parse(e.data), 'ws'); } catch (_) {}
            };

            ws_.onclose = function () {
                _connected = false;
                _ws = null;
                if (_listeners.length > 0 && !_reconnectTimer) {
                    _reconnectWithJitter();
                }
            };

            ws_.onerror = function () {
                ws_.close();
                /* WS 실패 → SSE fallback */
                if (_token) _trySse(section);
            };
        } catch (_) {
            if (_token) _trySse(section);
        }
    }

    /* ══════════════════════════════════════
       4. SSE 연결 (fallback)
       ══════════════════════════════════════ */

    function _trySse(section) {
        if (!window.EventSource || !_token) return;

        var base = _httpBaseUrl(section);
        try {
            var es = new EventSource(base + '/sse?token=' + encodeURIComponent(_token));
            _sse = es;

            es.onopen = function () {
                _connected = true;
                if (SHV.notifications && SHV.notifications.setWsConnected) {
                    SHV.notifications.setWsConnected(true);
                }
            };

            es.onmessage = function (e) {
                try { _dispatch(JSON.parse(e.data), 'sse'); } catch (_) {}
            };

            es.onerror = function () {
                es.close();
                _sse = null;
                _connected = false;
                /* SSE도 실패 → jitter 재연결 */
                if (_listeners.length > 0 && !_reconnectTimer) {
                    _reconnectWithJitter();
                }
            };
        } catch (_) {}
    }

    /* ══════════════════════════════════════
       5. 연결 시작 / 재연결
       ══════════════════════════════════════ */

    function _initConnection(ctx, section) {
        _lastCtx = ctx;
        _lastSection = section;

        _issueToken(ctx)
            .then(function (res) {
                if (!res || !res.ok || !res.data || !res.data.token) return;
                _token = res.data.token;
                var base = _wsBaseUrl(section);

                /* WS 지원 시 WS 우선, 아니면 SSE */
                if (window.WebSocket) {
                    _tryWs(base + '/?token=' + encodeURIComponent(_token), ctx, section);
                } else {
                    _trySse(section);
                }
            })
            .catch(function () {
                /* 토큰 발급 실패 — 실시간 없이 폴링 동작 유지 */
            });
    }

    function _reconnectWithJitter() {
        _disconnectTransport();

        if (_listeners.length === 0 || _reconnectTimer) return;

        /* jitter: 5~10초 랜덤 (thundering herd 방지) */
        var jitter = 5000 + Math.random() * 5000;
        _reconnectTimer = setTimeout(function () {
            _reconnectTimer = null;
            if (_listeners.length > 0 && _lastCtx) {
                _initConnection(_lastCtx, _lastSection);
            }
        }, jitter);
    }

    function _disconnectTransport() {
        if (_ws)  { try { _ws.close();  } catch (_) {} _ws  = null; }
        if (_sse) { try { _sse.close(); } catch (_) {} _sse = null; }
        _connected = false;
        /* 글로벌 알림에 WS 해제 통보 → 폴링 재개 */
        if (SHV.notifications && SHV.notifications.setWsConnected) {
            SHV.notifications.setWsConnected(false);
        }
    }

    function _disconnect() {
        _disconnectTransport();
        if (_reconnectTimer) { clearTimeout(_reconnectTimer); _reconnectTimer = null; }
        if (_heartbeatTimer) { clearInterval(_heartbeatTimer); _heartbeatTimer = null; }
    }

    /* ══════════════════════════════════════
       6. BroadcastChannel 단일탭 제어
       ══════════════════════════════════════ */

    function _initBroadcastChannel(ctx, section) {
        if (_bc) return;
        if (typeof BroadcastChannel === 'undefined') {
            _isLeader = true;
            return;
        }

        _bc = new BroadcastChannel('shvq_mail_sse');

        _bc.onmessage = function (e) {
            var d = e.data;
            if (!d || !d.type) return;

            if (d.type === 'tab_active' && d.tabId !== TAB_ID) {
                /* 다른 탭이 리더 → 내 연결 해제 */
                _isLeader = false;
                _disconnect();
            }

            if (d.type === 'mail_event' && d.source === 'bc') {
                _dispatch(d.payload, 'bc');
            }

            if (d.type === 'tab_closing' && d.tabId !== TAB_ID && _listeners.length > 0) {
                setTimeout(function () { _claimLeader(ctx, section); }, Math.random() * 1000);
            }
        };

        window.addEventListener('beforeunload', function () {
            if (_bc && _isLeader) {
                _bc.postMessage({ type: 'tab_closing', tabId: TAB_ID });
            }
        });
    }

    function _claimLeader(ctx, section) {
        _isLeader = true;
        if (_bc) { _bc.postMessage({ type: 'tab_active', tabId: TAB_ID }); }
        if (!_connected && !_reconnectTimer) {
            _initConnection(ctx, section);
        }
        _startHeartbeat();
    }

    /* ══════════════════════════════════════
       7. Heartbeat (30초 주기)
       ══════════════════════════════════════ */

    function _startHeartbeat() {
        if (_heartbeatTimer) return;
        _heartbeatTimer = setInterval(function () {
            if (!_token) return;
            var base = _httpBaseUrl(_lastSection);
            fetch(base + '/heartbeat?token=' + encodeURIComponent(_token), {
                method: 'GET',
                mode: 'cors'
            }).catch(function () {
                /* heartbeat 실패해도 WS/SSE는 유지 — 서버 TTL(60초)로 커버 */
            });
        }, HEARTBEAT_MS);
    }

    /* ══════════════════════════════════════
       8. Visibility API
       ══════════════════════════════════════ */

    var _visibilityBound = false;

    function _bindVisibility() {
        if (_visibilityBound) return;
        _visibilityBound = true;

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                /* 탭 복귀 → unread 재확인 이벤트 */
                _listeners.forEach(function (l) {
                    try { l.onEvent({ type: 'visibility_resumed' }); } catch (_) {}
                });

                /* 리더인데 연결 끊겼으면 재연결 */
                if (_isLeader && !_ws && !_sse && !_reconnectTimer && _lastCtx) {
                    _initConnection(_lastCtx, _lastSection);
                }
            }
        });
    }

    /* ══════════════════════════════════════
       9. Public API
       ══════════════════════════════════════ */

    /**
     * connect(ctx, section, onEvent)
     * @param {object} ctx      — { api, serviceCode, tenantId }
     * @param {Element} section — 메일 페이지 섹션 DOM
     * @param {function} onEvent — 이벤트 콜백 (data 인자)
     */
    function connect(ctx, section, onEvent) {
        /* 같은 section의 중복 리스너 방지 */
        _listeners = _listeners.filter(function (l) { return l.section !== section; });
        _listeners.push({ section: section, onEvent: onEvent });

        _initBroadcastChannel(ctx, section);
        _bindVisibility();
        _claimLeader(ctx, section);
    }

    /**
     * unregister(section)
     * — destroy 시 호출, 리스너 해제
     */
    function unregister(section) {
        _listeners = _listeners.filter(function (l) { return l.section !== section; });
        if (_listeners.length === 0) {
            _disconnect();
        }
    }

    /**
     * disconnect()
     * — 모든 연결 해제
     */
    function disconnect() {
        _disconnect();
        _isLeader = false;
        _listeners = [];

        if (_bc) {
            _bc.close();
            _bc = null;
        }
    }

    function isConnected() {
        return _connected;
    }

    function isLeader() {
        return _isLeader;
    }

    return {
        connect:     connect,
        unregister:  unregister,
        disconnect:  disconnect,
        isConnected: isConnected,
        isLeader:    isLeader
    };

})();
