/* ========================================
   SHVQ V2 — Toast Notification
   SHV.toast.info / success / warn / error / dismiss

   ✓ CSS transition 기반 슬라이드 인/아웃 (translateX)
   ✓ 동일 메시지 중복 방지 (Set)
   ✓ 최대 3개 유지 (초과 시 가장 오래된 것 제거)
   ✓ 기본 5초 표시
   ✓ 클릭/× 버튼으로 즉시 닫기
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {

    var _container = null;
    var _timers    = {};
    var _seq       = 0;
    var _active    = new Set();   /* 중복 방지용 메시지 집합 */
    var MAX        = 3;           /* 동시 최대 토스트 수 */

    var ICONS = {
        info:    'fa-info-circle',
        success: 'fa-check-circle',
        warn:    'fa-exclamation-triangle',
        danger:  'fa-times-circle'
    };

    var DURATIONS = {
        info:    0,
        success: 0,
        warn:    0,
        danger:  0
    };

    /* ── 컨테이너 (최초 1회 생성) ── */
    function getContainer() {
        if (!_container) {
            _container = document.createElement('div');
            _container.className = 'toast-container';
            document.body.appendChild(_container);
        }
        return _container;
    }

    /* ── 닫기 ── */
    function dismiss(id) {
        clearTimeout(_timers[id]);
        delete _timers[id];

        var el = document.getElementById(id);
        if (!el) return;

        _active.delete(el.dataset.msg || '');

        /* CSS transition 으로 exit */
        el.classList.remove('toast-visible');
        el.classList.add('toast-hiding');

        setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, 320);
    }

    /* ── 토스트 생성 ── */
    function show(type, message, duration) {
        /* 동일 메시지 중복 차단 */
        if (_active.has(message)) return;
        _active.add(message);

        var container = getContainer();

        /* 최대 개수 초과 → 가장 오래된 것 제거 */
        var existing = container.querySelectorAll('.toast:not(.toast-hiding)');
        if (existing.length >= MAX) {
            dismiss(existing[0].id);
        }

        var id  = 'shv_toast_' + (++_seq);
        var dur = (duration != null) ? duration : DURATIONS[type] || 5000;

        var el = document.createElement('div');
        el.id          = id;
        el.className   = 'toast toast-' + type;
        el.dataset.msg = message;
        el.setAttribute('role', 'alert');
        el.setAttribute('aria-live', 'polite');

        el.innerHTML =
            '<i class="fa ' + ICONS[type] + ' toast-icon"></i>'
          + '<span style="flex:1;word-break:break-word;line-height:1.5;">'
          +   message
          + '</span>'
          + '<button class="toast-close" aria-label="닫기">&times;</button>';

        container.appendChild(el);

        var closeBtn = el.querySelector('.toast-close');
        closeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            dismiss(id);
        });

        /* 토스트 바디 클릭도 닫기 */
        el.addEventListener('click', function () { dismiss(id); });

        /* 다음 프레임에서 visible 클래스 부여 → CSS transition 시작 */
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                el.classList.add('toast-visible');
            });
        });

        /* 자동 닫기 */
        if (dur > 0) {
            _timers[id] = setTimeout(function () { dismiss(id); }, dur);
        }

        return id;
    }

    /* ── 공개 API ── */
    SHV.toast = {
        info:    function (msg, dur) { return show('info',    msg, dur); },
        success: function (msg, dur) { return show('success', msg, dur); },
        warn:    function (msg, dur) { return show('warn',    msg, dur); },
        error:   function (msg, dur) { return show('danger',  msg, dur); },
        danger:  function (msg, dur) { return show('danger',  msg, dur); },
        dismiss: dismiss
    };

    /* ── 전역 단축 (기존 호환) ── */
    window.showToast = function (msg, type) {
        var t = ({ success:'success', danger:'danger', error:'danger', warn:'warn' })[type] || 'info';
        show(t, msg);
    };

})(window.SHV);
