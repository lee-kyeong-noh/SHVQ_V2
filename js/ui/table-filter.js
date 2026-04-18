/* ============================================================
   SHV Table Column Filter  (js/ui/table-filter.js)
   components.css > .tbl-filter-row / .tbl-col-filter 와 쌍
   ============================================================

   Usage:
     SHV.tblFilter(tableOrSelector, options?)
     SHV.tblFilterReset(tableOrSelector)

   Options:
     skip      number[]   - 0-based column indices to skip (no input). Default: []
     debounce  number     - input debounce ms. Default: 180

   Notes:
   - thead 의 두 번째 <tr> 로 필터 행 삽입 (sticky 헤더와 함께 고정됨)
   - .th-check 컬럼은 자동 스킵
   - table-select.js 와 공존 가능 (th-check 감지)
   - MutationObserver 로 동적 추가 행도 실시간 필터 적용
   ============================================================ */

(function () {
    'use strict';

    window.SHV = window.SHV || {};

    /* ── 디바운스 헬퍼 ── */
    function debounce(fn, ms) {
        var t;
        return function () { clearTimeout(t); t = setTimeout(fn, ms); };
    }

    /**
     * SHV.tblFilter(tableOrSelector, options)
     */
    SHV.tblFilter = function (tableOrSelector, options) {
        var tbl = typeof tableOrSelector === 'string'
            ? document.querySelector(tableOrSelector)
            : tableOrSelector;
        if (!tbl || tbl._shvFilterInit) return;
        tbl._shvFilterInit = true;

        var opts       = options || {};
        var skip       = opts.skip || [];
        var debounceMs = (opts.debounce !== undefined) ? opts.debounce : 180;

        /* ── thead / 헤더 행 ── */
        var thead    = tbl.querySelector('thead');
        if (!thead) return;
        var headerRow = thead.querySelector('tr');
        if (!headerRow) return;

        /* ── 필터 행 생성 ── */
        var filterTr = document.createElement('tr');
        filterTr.className = 'tbl-filter-row';

        var ths          = headerRow.querySelectorAll('th');
        var filterInputs = []; /* null = skipped */

        ths.forEach(function (th, i) {
            var cell = document.createElement('th');

            /* th-check 컬럼 또는 명시적 skip 인덱스 → 빈 셀 */
            if (th.classList.contains('th-check') || skip.indexOf(i) > -1) {
                filterInputs.push(null);
            } else {
                var placeholder = (th.textContent || '').replace(/[▲▼↑↓]/g, '').trim();
                var inp = document.createElement('input');
                inp.type        = 'text';
                inp.className   = 'tbl-col-filter';
                inp.placeholder = placeholder;
                inp.setAttribute('data-col', i);
                inp.addEventListener('input', debounce(applyFilter, debounceMs));
                cell.appendChild(inp);
                filterInputs.push(inp);
            }
            filterTr.appendChild(cell);
        });

        thead.appendChild(filterTr);

        /* ── tbody MutationObserver — 동적 추가 행에도 필터 적용 ── */
        var tbody = tbl.querySelector('tbody');
        if (tbody) {
            var mo = new MutationObserver(function () { applyFilter(); });
            mo.observe(tbody, { childList: true });
            tbl._shvFilterMO = mo;
        }

        /* ── 필터 적용 ── */
        function applyFilter() {
            /* 활성 필터 수집 */
            var active = [];
            filterInputs.forEach(function (inp, i) {
                if (!inp) return;
                var v = inp.value.trim().toLowerCase();
                inp.classList.toggle('has-value', !!v);
                if (v) active.push({ colIdx: i, value: v });
            });

            var tb = tbl.querySelector('tbody');
            if (!tb) return;

            tb.querySelectorAll('tr:not(.tbl-skeleton-row)').forEach(function (row) {
                var show = true;
                if (active.length) {
                    active.forEach(function (f) {
                        var cells = row.querySelectorAll('td');
                        var cell  = cells[f.colIdx];
                        var text  = cell ? (cell.textContent || '').toLowerCase() : '';
                        if (text.indexOf(f.value) === -1) show = false;
                    });
                }
                row.style.display = show ? '' : 'none';
            });
        }

        /* ── 리셋 API ── */
        tbl._shvFilterReset = function () {
            filterInputs.forEach(function (inp) {
                if (inp) { inp.value = ''; inp.classList.remove('has-value'); }
            });
            var tb = tbl.querySelector('tbody');
            if (tb) tb.querySelectorAll('tr').forEach(function (r) { r.style.display = ''; });
        };
    };

    /**
     * SHV.tblFilterReset(tableOrSelector)
     */
    SHV.tblFilterReset = function (tableOrSelector) {
        var tbl = typeof tableOrSelector === 'string'
            ? document.querySelector(tableOrSelector)
            : tableOrSelector;
        if (tbl && tbl._shvFilterReset) tbl._shvFilterReset();
    };

    /* ── 전역 단축키 ── */
    window.shvTblFilter      = SHV.tblFilter;
    window.shvTblFilterReset = SHV.tblFilterReset;

})();
