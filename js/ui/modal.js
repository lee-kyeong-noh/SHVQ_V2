/* ========================================
   SHVQ V2 — Modal
   SHV.modal.open(url, title, size)
   SHV.modal.openHtml(html, title, size)
   SHV.modal.close()
   window.openModal(url, title, size)

   ✓ 배경 클릭으로 닫힘 없음 (× 버튼 전용)
   ✓ 헤더 드래그로 이동
   ✓ 뒤로가기/history 차단
   ✓ 사이즈: alert | sm | md | lg | xl
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {

    /* ── DOM refs ── */
    var _overlay  = null;
    var _box      = null;
    var _header   = null;
    var _titleEl  = null;
    var _bodyEl   = null;
    var _closeBtn = null;

    /* ── state ── */
    var _isOpen        = false;
    var _historyPushed = false;
    var _abortLoad     = null;
    var _onCloseCbs    = [];
    var _eventsBound   = false;   /* 중복 리스너 방지 플래그 */

    /* ── drag ── */
    var _dragX = 0, _dragY = 0;
    var _isDragging = false;
    var _dragStartX, _dragStartY;

    /* ──────────────────────────────────────────
       DOM 초기화 (footer.php 사전 삽입 DOM 재사용,
       없으면 동적 생성 — 하위 호환)
    ────────────────────────────────────────── */
    function build() {
        if (_overlay) return;

        /* footer.php가 이미 DOM에 삽입한 경우 재사용 */
        var existing = document.getElementById('shvModalOverlay');
        if (existing) {
            _overlay  = existing;
            _box      = document.getElementById('shvModalBox');
            _header   = document.getElementById('shvModalHeader');
            _titleEl  = document.getElementById('shvModalTitle');
            _bodyEl   = document.getElementById('shvModalBody');
            _closeBtn = document.getElementById('shvModalClose');
            _closeBtn.addEventListener('click', function () { SHV.modal.close(); });
        } else {
            /* 동적 생성 (footer.php 미포함 환경) */
            _overlay = document.createElement('div');
            _overlay.className = 'modal-overlay';
            _overlay.id        = 'shvModalOverlay';

            _box = document.createElement('div');
            _box.className = 'modal-box glass-panel modal-md';
            _box.id        = 'shvModalBox';

            _header = document.createElement('div');
            _header.className = 'modal-header';

            _titleEl = document.createElement('span');
            _titleEl.id = 'shvModalTitle';

            _closeBtn = document.createElement('button');
            _closeBtn.className = 'modal-close';
            _closeBtn.setAttribute('aria-label', '닫기');
            _closeBtn.innerHTML = '&times;';
            _closeBtn.addEventListener('click', function () { SHV.modal.close(); });

            _header.appendChild(_titleEl);
            _header.appendChild(_closeBtn);

            _bodyEl = document.createElement('div');
            _bodyEl.className = 'modal-body';
            _bodyEl.id        = 'shvModalBody';

            _box.appendChild(_header);
            _box.appendChild(_bodyEl);
            _overlay.appendChild(_box);
            document.body.appendChild(_overlay);
        }

        /* ── document/window 이벤트: 최초 1회만 등록 ── */
        if (!_eventsBound) {
            _eventsBound = true;

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && _isOpen) SHV.modal.close();
            });

            /* popstate: 뒤로가기 차단 */
            window.addEventListener('popstate', function () {
                if (_isOpen && _historyPushed) {
                    history.pushState({ shvModal: true }, '', window.location.href);
                }
            });

            document.addEventListener('mousemove', function (e) {
                if (!_isDragging) return;
                _dragX = e.clientX - _dragStartX;
                _dragY = e.clientY - _dragStartY;
                _box.style.transform = 'translate(' + _dragX + 'px, ' + _dragY + 'px)';
            });

            document.addEventListener('mouseup', function () {
                if (!_isDragging) return;
                _isDragging = false;
                document.body.style.userSelect = '';
            });

            document.addEventListener('touchmove', function (e) {
                if (!_isDragging || e.touches.length !== 1) return;
                _dragX = e.touches[0].clientX - _dragStartX;
                _dragY = e.touches[0].clientY - _dragStartY;
                _box.style.transform = 'translate(' + _dragX + 'px, ' + _dragY + 'px)';
            }, { passive: true });

            document.addEventListener('touchend', function () {
                _isDragging = false;
            });
        }

        /* ── 배경 클릭: 닫힘 없음 (의도적으로 제거) ── */

        /* ── 드래그 (헤더 요소별 — DOM 재생성 시 재부착 필요 없음) ── */
        _header.addEventListener('mousedown', function (e) {
            if (e.target === _closeBtn || e.button !== 0) return;
            _isDragging  = true;
            _dragStartX  = e.clientX - _dragX;
            _dragStartY  = e.clientY - _dragY;
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });

        /* 터치 드래그 (모바일) */
        _header.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) return;
            _isDragging = true;
            _dragStartX = e.touches[0].clientX - _dragX;
            _dragStartY = e.touches[0].clientY - _dragY;
            e.preventDefault();
        }, { passive: false });
    }

    /* ── 사이즈 변경 ── */
    function setSize(size) {
        _box.classList.remove('modal-alert', 'modal-sm', 'modal-md', 'modal-lg', 'modal-xl');
        var valid = { alert: 1, sm: 1, md: 1, lg: 1, xl: 1 };
        _box.classList.add('modal-' + (valid[size] ? size : 'md'));
    }

    /* ── 드래그 위치 초기화 ── */
    function resetDrag() {
        _dragX = 0; _dragY = 0;
        if (_box) _box.style.transform = '';
    }

    /* ── 로딩 스피너 ── */
    function setLoading() {
        _bodyEl.innerHTML =
            '<div style="display:flex;align-items:center;justify-content:center;min-height:160px;">'
          + '<div class="spinner spinner-lg"></div></div>';
    }

    /* ── 인라인 스크립트 실행 ── */
    function executeScripts(container) {
        container.querySelectorAll('script').forEach(function (old) {
            var s = document.createElement('script');
            if (old.src) { s.src = old.src; } else { s.textContent = old.textContent; }
            old.parentNode.replaceChild(s, old);
        });
    }

    /* ── 열기 공통 처리 ── */
    function doOpen(title, size) {
        build();
        resetDrag();
        setSize(size);
        _titleEl.textContent   = title || '';
        _isOpen                = true;
        document.body.style.overflow = 'hidden';
        _overlay.classList.add('open');

        /* history push — 뒤로가기 차단용 */
        _historyPushed = true;
        history.pushState({ shvModal: true }, '', window.location.href);
    }

    /* ──────────────────────────────────────────
       Public API
    ────────────────────────────────────────── */
    SHV.modal = {

        /**
         * AJAX URL 로드
         * @param {string} url
         * @param {string} [title]
         * @param {string} [size]  alert | sm | md | lg | xl
         */
        open: function (url, title, size) {
            if (_abortLoad) { _abortLoad(); _abortLoad = null; }

            doOpen(title, size);
            setLoading();

            var aborted = false;
            _abortLoad = function () { aborted = true; };

            fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) {
                if (res.status === 401) { window.location.href = 'login.php'; return ''; }
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function (html) {
                if (aborted || !html) return;
                _bodyEl.innerHTML = html;
                executeScripts(_bodyEl);
            })
            .catch(function () {
                if (aborted) return;
                _bodyEl.innerHTML =
                    '<div style="text-align:center;padding:48px 24px;color:var(--danger);">'
                  + '<i class="fa fa-exclamation-circle" style="font-size:36px;"></i>'
                  + '<p style="margin-top:14px;font-size:13px;color:var(--text-2);">'
                  + '페이지를 불러올 수 없습니다.</p></div>';
            });
        },

        /**
         * HTML 직접 주입
         * @param {string} html
         * @param {string} [title]
         * @param {string} [size]
         */
        openHtml: function (html, title, size) {
            if (_abortLoad) { _abortLoad(); _abortLoad = null; }
            doOpen(title, size);
            _bodyEl.innerHTML = html;
            executeScripts(_bodyEl);
        },

        /**
         * 모달 닫기
         */
        close: function () {
            if (!_isOpen) return;
            if (_abortLoad) { _abortLoad(); _abortLoad = null; }

            _isOpen = false;
            _overlay.classList.remove('open');
            document.body.style.overflow = '';

            setTimeout(function () { _bodyEl.innerHTML = ''; }, 260);

            /* history 정리 — 차단용으로 push했던 state 제거 */
            if (_historyPushed) {
                history.back();
                _historyPushed = false;
            }

            _onCloseCbs.forEach(function (cb) { try { cb(); } catch (e) {} });
            _onCloseCbs = [];
        },

        /**
         * 닫힐 때 콜백 등록 (1회성)
         */
        onClose: function (fn) {
            if (typeof fn === 'function') _onCloseCbs.push(fn);
        },

        /** 현재 열려있는지 */
        isOpen: function () { return _isOpen; },

        /** 제목 변경 */
        setTitle: function (t) { if (_titleEl) _titleEl.textContent = t; }
    };

    /* ── 전역 단축 ── */
    window.openModal = function (url, title, size) {
        SHV.modal.open(url, title, size);
    };

})(window.SHV);

/* ──────────────────────────────────────────
   SHV.subModal — 기존 모달 위에 띄우는 서브 모달
   기존 모달은 그대로 보이며, 서브 모달만 위에 뜸

   SHV.subModal.openHtml(html, title, size)
   SHV.subModal.close()
   ────────────────────────────────────────── */
(function (SHV) {
    'use strict';

    var _overlay  = null;
    var _box      = null;
    var _titleEl  = null;
    var _bodyEl   = null;
    var _dragX    = 0, _dragY = 0;
    var _isDragging = false;
    var _dragStartX, _dragStartY;

    function build() {
        if (_overlay) return;

        var header = null;
        var closeBtn = null;

        /* footer.php 사전 삽입 DOM 재사용 */
        var existing = document.getElementById('shvSubModalOverlay');
        if (existing) {
            _overlay = existing;
            _box     = document.getElementById('shvSubModalBox');
            _titleEl = document.getElementById('shvSubModalTitle');
            _bodyEl  = document.getElementById('shvSubModalBody');
            header   = document.getElementById('shvSubModalHeader');
            closeBtn = document.getElementById('shvSubModalClose');
            if (closeBtn) closeBtn.addEventListener('click', function(){ SHV.subModal.close(); });
        } else {
            /* 동적 생성 (footer.php 미포함 환경) */
            _overlay = document.createElement('div');
            _overlay.id = 'shvSubModalOverlay';
            _overlay.style.cssText = [
                'position:fixed;inset:0;z-index:10000',
                'display:none;align-items:center;justify-content:center;padding:16px',
                'background:transparent;pointer-events:none'
            ].join(';');

            _box = document.createElement('div');
            _box.id = 'shvSubModalBox';
            _box.className = 'modal-box';
            _box.style.cssText = 'pointer-events:auto;';

            header = document.createElement('div');
            header.id = 'shvSubModalHeader';
            header.className = 'modal-header';

            _titleEl = document.createElement('span');

            closeBtn = document.createElement('button');
            closeBtn.className = 'modal-close';
            closeBtn.setAttribute('aria-label', '닫기');
            closeBtn.innerHTML = '&times;';
            closeBtn.addEventListener('click', function(){ SHV.subModal.close(); });

            header.appendChild(_titleEl);
            header.appendChild(closeBtn);

            _bodyEl = document.createElement('div');
            _bodyEl.id = 'shvSubModalBody';
            _bodyEl.className = 'modal-body';

            _box.appendChild(header);
            _box.appendChild(_bodyEl);
            _overlay.appendChild(_box);
            document.body.appendChild(_overlay);
        }

        /* 드래그 */
        if (header) header.addEventListener('mousedown', function(e){
            if (e.button !== 0) return;
            _isDragging = true;
            _dragStartX = e.clientX - _dragX;
            _dragStartY = e.clientY - _dragY;
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });
        document.addEventListener('mousemove', function(e){
            if (!_isDragging) return;
            _dragX = e.clientX - _dragStartX;
            _dragY = e.clientY - _dragStartY;
            _box.style.transform = 'translate(' + _dragX + 'px,' + _dragY + 'px)';
        });
        document.addEventListener('mouseup', function(){
            _isDragging = false;
            document.body.style.userSelect = '';
        });

        /* ESC */
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && _overlay.style.display === 'flex') SHV.subModal.close();
        });
    }

    function setSize(size) {
        var sizes = { alert:380, sm:480, md:540, lg:760, xl:960 };
        _box.style.maxWidth = (sizes[size] || 540) + 'px';
    }

    SHV.subModal = {
        openHtml: function(html, title, size) {
            build();
            _dragX = 0; _dragY = 0;
            _box.style.transform = '';
            setSize(size || 'md');
            _titleEl.textContent = title || '';
            _bodyEl.innerHTML = html;
            /* 인라인 스크립트 실행 */
            _bodyEl.querySelectorAll('script').forEach(function(old){
                var s = document.createElement('script');
                if (old.src) { s.src = old.src; } else { s.textContent = old.textContent; }
                old.parentNode.replaceChild(s, old);
            });
            _overlay.style.display = 'flex';
            /* 등장 애니메이션 — 끝나면 animation 속성 제거 (그래야 드래그 transform이 우선 적용됨) */
            _box.style.animation = 'none';
            void _box.offsetWidth;
            _box.style.animation = 'modal-slide-in .22s cubic-bezier(.32,.72,0,1) both';
            _box.addEventListener('animationend', function _clearAnim(){
                _box.style.animation = '';
                _box.removeEventListener('animationend', _clearAnim);
            });
        },

        close: function() {
            if (_overlay) { _overlay.style.display = 'none'; }
            if (_bodyEl)  { _bodyEl.innerHTML = ''; }
        },

        isOpen: function() { return _overlay && _overlay.style.display === 'flex'; },
        setTitle: function(t) { if (_titleEl) _titleEl.textContent = t; }
    };

})(window.SHV);
