/* ========================================
   SHVQ V2 — Global Notification Module
   하이브리드: WS 우선 + 10초 폴링 fallback
   - 모든 페이지에서 동작 (index.php 레벨)
   - 헤더 벨 뱃지 갱신 + 토스트
   - BroadcastChannel: 탭 1개만 연결/폴링
   - Visibility API: 탭 복귀 시 즉시 체크
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

SHV.notifications = (function () {

    var MAIL_API      = 'dist_process/saas/Mail.php';
    var POLL_MS       = 10 * 1000;      // 10초 폴링
    var POLL_BG_MS    = 30 * 1000;      // 탭 비활성 시 30초
    var BC_CHANNEL    = 'shvq_notifications';
    var TAB_ID        = Date.now() + '_' + Math.random().toString(36).slice(2, 8);

    var _pollTimer    = null;
    var _bc           = null;
    var _isLeader     = false;
    var _unreadTotal  = -1;       // -1: 초기화 전
    var _listeners    = [];
    var _wsConnected  = false;    // WS 활성 시 폴링 중지 여부
    var _lastPollAt   = 0;
    var _inited       = false;

    /* ══════════════════════════════════════
       1. 초기화
       ══════════════════════════════════════ */

    function init() {
        if (_inited) return;
        _inited = true;

        _initBroadcastChannel();
        _bindVisibility();
        _claimLeader();

        // 초기 1회 즉시 폴링
        _poll();
    }

    function destroy() {
        _stopPoll();
        _isLeader = false;
        _listeners = [];
        if (_bc) { _bc.close(); _bc = null; }
    }

    /* ══════════════════════════════════════
       2. BroadcastChannel 단일탭 제어
       ══════════════════════════════════════ */

    function _initBroadcastChannel() {
        if (_bc) return;
        if (typeof BroadcastChannel === 'undefined') {
            _isLeader = true;
            return;
        }

        _bc = new BroadcastChannel(BC_CHANNEL);

        _bc.onmessage = function (e) {
            var d = e.data;
            if (!d || !d.type) return;

            if (d.type === 'tab_active' && d.tabId !== TAB_ID) {
                _isLeader = false;
                _stopPoll();
            }

            if (d.type === 'unread_update') {
                // 리더가 아닌 탭: 결과만 반영
                _applyUnread(d.unreadTotal, d.accounts, false);
            }

            if (d.type === 'tab_closing' && d.tabId !== TAB_ID) {
                setTimeout(function () { _claimLeader(); }, Math.random() * 1000);
            }
        };

        window.addEventListener('beforeunload', function () {
            if (_bc && _isLeader) {
                _bc.postMessage({ type: 'tab_closing', tabId: TAB_ID });
            }
        });
    }

    function _claimLeader() {
        _isLeader = true;
        if (_bc) { _bc.postMessage({ type: 'tab_active', tabId: TAB_ID }); }
        _startPoll();
    }

    /* ══════════════════════════════════════
       3. 폴링
       ══════════════════════════════════════ */

    function _startPoll() {
        if (_pollTimer) return;
        _pollTimer = setInterval(function () {
            if (!_isLeader) return;
            if (_wsConnected) return;   // WS 활성 시 폴링 스킵
            _poll();
        }, _getPollInterval());
    }

    function _stopPoll() {
        if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
    }

    function _getPollInterval() {
        return document.visibilityState === 'visible' ? POLL_MS : POLL_BG_MS;
    }

    function _poll() {
        if (!_isLeader) return;

        _lastPollAt = Date.now();
        SHV.api.get(MAIL_API, { todo: 'mail_check_new' })
            .then(function (res) {
                if (!res || !res.ok || !res.data) return;
                _applyUnread(res.data.unread_total || 0, res.data.accounts || [], true);
            })
            .catch(function () {
                // 폴링 실패 무시 — 다음 주기에 재시도
            });
    }

    /* ══════════════════════════════════════
       4. unread 적용 + 뱃지 갱신
       ══════════════════════════════════════ */

    function _applyUnread(total, accounts, broadcast) {
        var prev = _unreadTotal;
        _unreadTotal = total;

        _updateBadge(total);

        // 카운트 증가 시 토스트 (최초 로드 제외)
        /* 메일 페이지에서는 mail_pages.js가 토스트 처리 → 중복 방지 */
        if (prev >= 0 && total > prev && !_wsConnected) {
            _showNewMailToast(total - prev, accounts);
        }

        // 리스너 호출
        _listeners.forEach(function (fn) {
            try { fn({ unreadTotal: total, accounts: accounts }); } catch (_) {}
        });

        // 리더탭이면 BC로 전파
        if (broadcast && _bc && _isLeader) {
            _bc.postMessage({
                type: 'unread_update',
                unreadTotal: total,
                accounts: accounts
            });
        }
    }

    function _updateBadge(count) {
        var badge = document.getElementById('notifBellBadge');
        if (!badge) return;

        if (count <= 0) {
            badge.classList.add('notif-badge-hidden');
            badge.textContent = '0';
            return;
        }

        var text = count > 99 ? '99+' : String(count);
        var changed = badge.textContent !== text;
        badge.textContent = text;
        badge.classList.remove('notif-badge-hidden');

        // 카운트 변경 시 펄스 애니메이션
        if (changed) {
            badge.classList.remove('notif-badge-pulse');
            void badge.offsetWidth;  // reflow 트리거
            badge.classList.add('notif-badge-pulse');
        }
    }

    /* ══════════════════════════════════════
       5. 토스트 알림
       ══════════════════════════════════════ */

    var _toastContainer = null;
    var _toastDedup = {};

    function _showNewMailToast(newCount, accounts) {
        // dedup: 5초 내 동일 키 방지
        var dedupKey = 'notif_' + _unreadTotal;
        var now = Date.now();
        if (_toastDedup[dedupKey] && now - _toastDedup[dedupKey] < 5000) return;
        _toastDedup[dedupKey] = now;
        // 30초 지난 키 정리
        Object.keys(_toastDedup).forEach(function (k) {
            if (now - _toastDedup[k] > 30000) delete _toastDedup[k];
        });

        if (!_toastContainer) {
            /* 기존 mail_pages.js가 만든 컨테이너 재사용 */
            _toastContainer = document.querySelector('.mail-toast');
            if (!_toastContainer) {
                _toastContainer = document.createElement('div');
                _toastContainer.className = 'mail-toast';
                document.body.appendChild(_toastContainer);
            }
        }

        var accountText = '';
        if (accounts && accounts.length > 0) {
            accountText = accounts
                .filter(function (a) { return a.unread > 0; })
                .map(function (a) { return a.email; })
                .slice(0, 2)
                .join(', ');
        }

        var item = document.createElement('div');
        item.className = 'mail-toast-item';
        item.innerHTML =
            '<div class="mail-toast-icon"><i class="fa fa-envelope"></i></div>' +
            '<div class="mail-toast-content">' +
                '<div class="mail-toast-sender">' + _esc(accountText || '메일') + '</div>' +
                '<div class="mail-toast-subject">새 메일 ' + newCount + '건이 도착했습니다.</div>' +
            '</div>' +
            '<button class="mail-toast-close">&times;</button>';

        item.querySelector('.mail-toast-close').addEventListener('click', function (e) {
            e.stopPropagation();
            _removeToast(item);
        });
        item.addEventListener('click', function () {
            // 메일 페이지로 이동
            if (SHV.router && SHV.router.navigate) {
                SHV.router.navigate('mail_inbox');
            }
            _removeToast(item);
        });

        _toastContainer.appendChild(item);

        /* 10초 후 자동 닫힘 */
        setTimeout(function () { _removeToast(item); }, 10000);
    }

    function _removeToast(el) {
        if (!el || !el.parentNode) return;
        el.classList.add('is-leaving');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
    }

    function _esc(str) {
        var d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    /* ══════════════════════════════════════
       6. Visibility API
       ══════════════════════════════════════ */

    function _bindVisibility() {
        document.addEventListener('visibilitychange', function () {
            // 활성/비활성 전환 시 폴링 주기 조정
            _stopPoll();
            if (_isLeader) {
                _startPoll();
            }
            // 탭 복귀 → 즉시 폴링
            if (document.visibilityState === 'visible' && _isLeader && !_wsConnected) {
                _poll();
            }
        });
    }

    /* ══════════════════════════════════════
       7. WS 연동 인터페이스
       ══════════════════════════════════════ */

    /**
     * WS에서 newMail 이벤트 수신 시 호출
     * mail_pages.js 또는 realtime.js에서 호출
     */
    function onWsNewMail(data) {
        _wsConnected = true;
        // 즉시 폴링하여 정확한 카운트 반영
        _poll();
    }

    /**
     * WS 연결 상태 변경 알림
     */
    function setWsConnected(connected) {
        _wsConnected = connected;
        if (!connected && _isLeader) {
            // WS 끊김 → 폴링 재개
            _stopPoll();
            _startPoll();
        }
    }

    /**
     * 외부에서 즉시 갱신 요청
     */
    function refreshNow() {
        _poll();
    }

    /**
     * 카운트 변경 리스너 등록
     */
    function onUpdate(fn) {
        if (typeof fn === 'function') {
            _listeners.push(fn);
        }
    }

    /**
     * 현재 미읽음 카운트
     */
    function getUnreadCount() {
        return Math.max(0, _unreadTotal);
    }

    /* ══════════════════════════════════════
       Public API
       ══════════════════════════════════════ */

    return {
        init:            init,
        destroy:         destroy,
        refreshNow:      refreshNow,
        onWsNewMail:     onWsNewMail,
        setWsConnected:  setWsConnected,
        onUpdate:        onUpdate,
        getUnreadCount:  getUnreadCount
    };

})();
