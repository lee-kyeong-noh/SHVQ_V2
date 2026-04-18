/* ========================================
   SHVQ V2 — Search Dropdown
   기본 <select> 대체 컴포넌트

   new SHV.SearchDropdown({
       input:     inputElement,    // 입력 필드
       data:      [{value, label, ...}],   // 항목 배열
       labelKey:  'label',         // label 키 (기본 'label')
       valueKey:  'value',         // value 키 (기본 'value')
       placeholder: '검색...',
       onSelect:  function(item) {},
       minInput:  0,               // 최소 입력 글자 수 (기본 0 = 클릭만으로도 열림)
       maxItems:  100,
   })

   position:fixed 사용 — overflow:hidden 컨테이너 안에서 잘리지 않음.
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {

    /* ── 싱글턴 드롭다운 레이어 ── */
    var _layer      = null;
    var _activeInst = null;

    function getLayer() {
        if (!_layer) {
            _layer = document.createElement('div');
            _layer.id = 'shvSdLayer';
            _layer.style.cssText =
                'position:fixed;z-index:1200;background:var(--panel);'
              + 'border:1px solid var(--border);border-radius:var(--radius-md);'
              + 'box-shadow:0 8px 32px rgba(0,0,0,.13);'
              + 'overflow-y:auto;max-height:260px;display:none;'
              + 'font-size:13px;';
            document.body.appendChild(_layer);

            /* 외부 클릭 시 닫기 */
            document.addEventListener('mousedown', function (e) {
                if (_activeInst && !_activeInst._input.contains(e.target) && !_layer.contains(e.target)) {
                    closeLayer();
                }
            });
        }
        return _layer;
    }

    function closeLayer() {
        if (_layer) _layer.style.display = 'none';
        if (_activeInst) {
            _activeInst._open = false;
            _activeInst = null;
        }
    }

    /* ── 레이어 위치 계산 ── */
    function positionLayer(input) {
        var rect = input.getBoundingClientRect();
        var ly   = getLayer();
        var vh   = window.innerHeight;
        var spaceBelow = vh - rect.bottom;
        var spaceAbove = rect.top;

        ly.style.width  = rect.width + 'px';
        ly.style.left   = rect.left + 'px';

        if (spaceBelow >= 220 || spaceBelow >= spaceAbove) {
            ly.style.top    = (rect.bottom + 4) + 'px';
            ly.style.bottom = 'auto';
            ly.style.maxHeight = Math.min(260, spaceBelow - 12) + 'px';
        } else {
            ly.style.bottom = (vh - rect.top + 4) + 'px';
            ly.style.top    = 'auto';
            ly.style.maxHeight = Math.min(260, spaceAbove - 12) + 'px';
        }
    }

    /* ────────────────────────────────────────
       SearchDropdown class
    ──────────────────────────────────────── */
    function SearchDropdown(options) {
        this._input      = options.input;
        this._data       = options.data || [];
        this._labelKey   = options.labelKey   || 'label';
        this._valueKey   = options.valueKey   || 'value';
        this._onSelect   = options.onSelect   || function () {};
        this._minInput   = options.minInput   != null ? options.minInput : 0;
        this._maxItems   = options.maxItems   || 100;
        this._open       = false;
        this._focusIdx   = -1;
        this._filtered   = [];

        if (options.placeholder) {
            this._input.placeholder = options.placeholder;
        }

        this._bindEvents();
    }

    SearchDropdown.prototype._bindEvents = function () {
        var self = this;
        var inp  = this._input;

        inp.setAttribute('autocomplete', 'off');

        /* 입력 */
        inp.addEventListener('input', function () {
            self._focusIdx = -1;
            self._showFiltered();
        });

        /* 포커스 */
        inp.addEventListener('focus', function () {
            if (inp.value.length >= self._minInput) {
                self._showFiltered();
            }
        });

        /* 키보드 */
        inp.addEventListener('keydown', function (e) {
            if (!self._open) return;
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    self._moveFocus(1);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    self._moveFocus(-1);
                    break;
                case 'Enter':
                    e.preventDefault();
                    self._selectFocused();
                    break;
                case 'Escape':
                    closeLayer();
                    break;
            }
        });
    };

    SearchDropdown.prototype._filter = function () {
        var q   = this._input.value.trim().toLowerCase();
        var lk  = this._labelKey;
        var data = this._data;
        var max  = this._maxItems;

        if (q === '') return data.slice(0, max);

        var result = [];
        for (var i = 0; i < data.length && result.length < max; i++) {
            var label = String(data[i][lk] || '').toLowerCase();
            if (label.indexOf(q) >= 0) result.push(data[i]);
        }
        return result;
    };

    SearchDropdown.prototype._showFiltered = function () {
        var self  = this;
        var layer = getLayer();
        this._filtered = this._filter();

        if (this._filtered.length === 0) {
            layer.innerHTML = '<div style="padding:10px 14px;color:var(--text-3);font-size:12px;">검색 결과 없음</div>';
        } else {
            var html = '';
            var lk   = this._labelKey;
            this._filtered.forEach(function (item, i) {
                html += '<div class="shv-sd-item" data-idx="' + i + '">'
                      + self._highlight(String(item[lk] || ''), self._input.value.trim())
                      + '</div>';
            });
            layer.innerHTML = html;

            /* hover/클릭 이벤트 — addEventListener로 통일 */
            layer.querySelectorAll('.shv-sd-item').forEach(function (el) {
                el.addEventListener('mouseenter', function () {
                    this.classList.add('shv-sd-item--hover');
                });
                el.addEventListener('mouseleave', function () {
                    this.classList.remove('shv-sd-item--hover');
                });
                el.addEventListener('mousedown', function (e) {
                    e.preventDefault(); /* input blur 방지 */
                    var idx  = parseInt(this.dataset.idx, 10);
                    var item = self._filtered[idx];
                    if (item) self._select(item);
                });
            });
        }

        positionLayer(this._input);
        layer.style.display = 'block';
        this._open     = true;
        _activeInst    = this;
    };

    /* ── 검색어 하이라이트 ── */
    SearchDropdown.prototype._highlight = function (text, query) {
        if (!query) return text;
        var escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return text.replace(new RegExp('(' + escaped + ')', 'gi'),
            '<mark style="background:var(--accent-15);color:var(--accent);border-radius:2px;padding:0 1px;">$1</mark>');
    };

    /* ── 포커스 이동 ── */
    SearchDropdown.prototype._moveFocus = function (dir) {
        var items = getLayer().querySelectorAll('.shv-sd-item');
        var len   = items.length;
        if (!len) return;

        /* 이전 포커스 해제 */
        if (this._focusIdx >= 0 && items[this._focusIdx]) {
            items[this._focusIdx].style.background = '';
            items[this._focusIdx].style.color = 'var(--text-1)';
        }

        this._focusIdx = Math.max(0, Math.min(len - 1, this._focusIdx + dir));
        var el = items[this._focusIdx];
        if (el) {
            el.style.background = 'var(--accent-10)';
            el.style.color      = 'var(--accent)';
            el.scrollIntoView({ block: 'nearest' });
        }
    };

    /* ── 포커스된 항목 선택 ── */
    SearchDropdown.prototype._selectFocused = function () {
        if (this._focusIdx < 0 || !this._filtered[this._focusIdx]) {
            closeLayer();
            return;
        }
        this._select(this._filtered[this._focusIdx]);
    };

    /* ── 항목 선택 ── */
    SearchDropdown.prototype._select = function (item) {
        this._input.value = String(item[this._labelKey] || '');
        closeLayer();
        this._onSelect(item);
    };

    /* ── 데이터 갱신 ── */
    SearchDropdown.prototype.setData = function (data) {
        this._data = data || [];
        if (this._open) this._showFiltered();
    };

    /* ── 값 초기화 ── */
    SearchDropdown.prototype.clear = function () {
        this._input.value = '';
        closeLayer();
    };

    /* ──────────────────────────────────────
       Export
    ────────────────────────────────────── */
    SHV.SearchDropdown = SearchDropdown;

})(window.SHV);
