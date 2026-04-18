/* ========================================
   SHVQ V2 — Confirm Dialog
   window.shvConfirm(options) → Promise<boolean>

   options:
     message     string
     title       string   (default: '확인')
     confirmText string   (default: '확인')
     cancelText  string   (default: '취소')
     type        string   danger | primary | warn  (default: 'danger')

   사용 예:
     shvConfirm({ message: '삭제하시겠습니까?' }).then(function(ok) {
         if (ok) { ... }
     });
   ======================================== */
'use strict';

(function () {

    var _overlay = null;

    /* ── XSS 방어 이스케이프 ── */
    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /* ── CSS 색상 매핑 ── */
    var TYPE_STYLE = {
        danger:  { bg: 'var(--danger)',  shadow: 'var(--danger-30)',  icon: 'fa-exclamation-triangle', iconColor: 'var(--danger)'  },
        primary: { bg: 'var(--accent)',  shadow: 'var(--accent-30)',  icon: 'fa-question-circle',      iconColor: 'var(--accent)'  },
        warn:    { bg: 'var(--warn)',    shadow: 'var(--warn-30)',    icon: 'fa-exclamation-circle',   iconColor: 'var(--warn)'    }
    };

    /* ── overlay DOM ── */
    function getOverlay() {
        if (!_overlay) {
            _overlay = document.createElement('div');
            _overlay.id = 'shvConfirmOverlay';
            _overlay.style.cssText =
                'position:fixed;inset:0;z-index:1060;display:none;align-items:center;'
              + 'justify-content:center;background:rgba(0,0,0,.45);'
              + 'backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);';
            document.body.appendChild(_overlay);

            /* ESC 키 닫기 */
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && _overlay.style.display !== 'none') {
                    var btn = _overlay.querySelector('[data-action="cancel"]');
                    if (btn) btn.click();
                }
            });
        }
        return _overlay;
    }

    /* ──────────────────────────────────────
       shvConfirm
    ────────────────────────────────────── */
    window.shvConfirm = function (options) {
        if (typeof options === 'string') {
            options = { message: options };
        }
        options = options || {};

        var title       = options.title       || '확인';
        var message     = options.message     || '';
        var confirmText = options.confirmText || '확인';
        var cancelText  = options.cancelText  || '취소';
        var type        = TYPE_STYLE[options.type] ? options.type : 'danger';
        var s           = TYPE_STYLE[type];

        var ov = getOverlay();

        return new Promise(function (resolve) {
            ov.innerHTML =
                '<div style="'
              +   'background:var(--panel);'
              +   'border-radius:var(--radius-lg);'
              +   'width:90%;max-width:420px;'
              +   'box-shadow:0 24px 64px rgba(0,0,0,.22),inset 0 1px 0 rgba(255,255,255,.7);'
              +   'overflow:hidden;'
              +   'animation:modal-slide-in .22s cubic-bezier(0,0,.2,1);'
              + '">'

                /* header */
              + '  <div style="padding:22px 22px 0;display:flex;align-items:center;gap:12px;">'
              + '    <span style="width:34px;height:34px;border-radius:50%;'
              +        'background:' + (type === 'danger' ? 'var(--danger-10)' : type === 'primary' ? 'var(--accent-10)' : 'var(--warn-10)') + ';'
              +        'display:flex;align-items:center;justify-content:center;flex-shrink:0;">'
              +      '<i class="fa ' + s.icon + '" style="color:' + s.iconColor + ';font-size:15px;"></i>'
              +    '</span>'
              +    '<span style="font-size:15px;font-weight:700;color:var(--text-1);">' + esc(title) + '</span>'
              +  '</div>'

                /* body */
              + '  <div style="padding:12px 22px 22px;font-size:13px;color:var(--text-2);line-height:1.65;">'
              +    esc(message)
              +  '</div>'

                /* footer */
              + '  <div style="'
              +    'padding:14px 22px;'
              +    'border-top:1px solid var(--border);'
              +    'display:flex;align-items:center;justify-content:flex-end;gap:8px;">'
              +    '<button data-action="cancel" style="'
              +      'padding:8px 18px;font-size:12px;font-weight:600;border-radius:8px;'
              +      'background:transparent;color:var(--text-2);cursor:pointer;'
              +      'border:1px solid var(--border);'
              +      'transition:background .15s,color .15s;">'
              +      cancelText
              +    '</button>'
              +    '<button data-action="confirm" style="'
              +      'padding:8px 18px;font-size:12px;font-weight:600;border-radius:8px;'
              +      'background:' + s.bg + ';color:#fff;cursor:pointer;border:none;'
              +      'box-shadow:0 2px 10px ' + s.shadow + ';'
              +      'transition:filter .15s,box-shadow .15s;">'
              +      confirmText
              +    '</button>'
              + '  </div>'
              + '</div>';

            ov.style.display = 'flex';

            var confirmBtn = ov.querySelector('[data-action="confirm"]');
            var cancelBtn  = ov.querySelector('[data-action="cancel"]');

            /* hover 효과 */
            confirmBtn.addEventListener('mouseenter', function () {
                this.style.filter = 'brightness(1.1)';
            });
            confirmBtn.addEventListener('mouseleave', function () {
                this.style.filter = '';
            });
            cancelBtn.addEventListener('mouseenter', function () {
                this.style.background = 'var(--panel-2)';
                this.style.color = 'var(--text-1)';
            });
            cancelBtn.addEventListener('mouseleave', function () {
                this.style.background = 'transparent';
                this.style.color = 'var(--text-2)';
            });

            function cleanup(result) {
                ov.style.display = 'none';
                ov.innerHTML = '';
                resolve(result);
            }

            confirmBtn.addEventListener('click', function () { cleanup(true);  });
            cancelBtn.addEventListener('click',  function () { cleanup(false); });

            /* backdrop 클릭 */
            ov.addEventListener('click', function handler(e) {
                if (e.target === ov) {
                    ov.removeEventListener('click', handler);
                    cleanup(false);
                }
            });

            /* 포커스 */
            setTimeout(function () { if (confirmBtn) confirmBtn.focus(); }, 60);
        });
    };

})();
