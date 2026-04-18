/* ========================================
   SHVQ V2 — Table Utility  (SHV.table)
   체크박스 선택 · Shift/Ctrl · 전체선택 · 벌크바
   정렬 헤더 · 디바운스 검색 · row 액션 · 키보드 내비
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {

    function getEl(v) {
        return typeof v === 'string' ? document.getElementById(v) : v;
    }

    /**
     * SHV.table.init(config) → instance
     *
     * config:
     *   tbody         HTMLElement|string   — 필수
     *   getRowId      function(tr)→string  — row 식별자 (기본: data-row-id)
     *   onSelect      function(Set)        — 선택 변경 콜백
     *   onSort        function(Array)      — [{key,dir}] 정렬 변경 콜백
     *   sortable      boolean (default true)
     *   searchInput   HTMLElement|string   — 검색 input
     *   onSearch      function(string)     — 디바운스 검색 콜백
     *   searchDelay   number (default 300)
     *   bulkBar       HTMLElement|string   — 벌크 바 컨테이너
     *   bulkCount     HTMLElement|string   — 선택 건수 표시 span
     *   rowActions    Array [{icon,title,cls,onClick(id,tr)}]
     *   onEnter       function(id,tr)      — Enter 키 콜백
     *   keyboard      boolean (default true)
     */
    function init(config) {
        var tbody = getEl(config.tbody);
        if (!tbody) return null;

        var table = tbody.closest('table');

        /* ── 상태 ── */
        var selected   = new Set();
        var lastIndex  = null;
        var focusIndex = null;
        var sorts      = [];

        /* ── rows 헬퍼 ── */
        function getRows() {
            return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-row-id]'));
        }

        function getRowId(tr) {
            return config.getRowId ? config.getRowId(tr) : (tr.dataset.rowId || '');
        }

        /* ── 헤더 체크박스 3상태 갱신 ── */
        function syncHeaderCheck() {
            if (!table) return;
            var hChk = table.querySelector('.tbl-check-all');
            if (!hChk) return;
            var rows  = getRows();
            var total = rows.length;
            var cnt   = 0;
            rows.forEach(function (tr) { if (selected.has(getRowId(tr))) cnt++; });
            if (total === 0 || cnt === 0) {
                hChk.checked = false; hChk.indeterminate = false;
            } else if (cnt === total) {
                hChk.checked = true;  hChk.indeterminate = false;
            } else {
                hChk.checked = false; hChk.indeterminate = true;
            }
        }

        /* ── 벌크 바 갱신 ── */
        function syncBulkBar() {
            var bar = getEl(config.bulkBar);
            if (!bar) return;
            bar.classList.toggle('active', selected.size > 0);
            var cnt = getEl(config.bulkCount);
            if (cnt) cnt.textContent = selected.size;
        }

        /* ── 전체 UI 동기화 ── */
        function syncUI() {
            var rows = getRows();
            rows.forEach(function (tr) {
                var id  = getRowId(tr);
                var on  = selected.has(id);
                tr.classList.toggle('selected', on);
                var chk = tr.querySelector('.tbl-row-check');
                if (chk) chk.checked = on;
            });
            syncHeaderCheck();
            syncBulkBar();
            if (config.onSelect) config.onSelect(new Set(selected));
        }

        /* ── 행 선택 로직 ── */
        function selectRow(tr, mode) {
            var id    = getRowId(tr);
            var rows  = getRows();
            var index = rows.indexOf(tr);

            if (mode === 'shift' && lastIndex !== null) {
                var s = Math.min(lastIndex, index);
                var e = Math.max(lastIndex, index);
                for (var i = s; i <= e; i++) selected.add(getRowId(rows[i]));
            } else if (mode === 'toggle') {
                if (selected.has(id)) selected.delete(id);
                else                  selected.add(id);
                lastIndex = index;
            } else { /* single */
                selected.clear();
                selected.add(id);
                lastIndex = index;
            }

            focusIndex = index;
            syncUI();
        }

        /* ── tbody 클릭 ── */
        tbody.addEventListener('click', function (e) {
            var tr = e.target.closest('tr[data-row-id]');
            if (!tr) return;

            /* row-action 버튼 클릭은 선택 전파 방지 */
            if (e.target.closest('.tbl-row-actions')) return;

            /* 체크박스 직접 클릭 */
            var chk = e.target.closest('.tbl-row-check');
            if (chk) {
                var id = getRowId(tr);
                if (chk.checked) selected.add(id);
                else             selected.delete(id);
                lastIndex  = getRows().indexOf(tr);
                focusIndex = lastIndex;
                syncUI();
                return;
            }

            if (e.shiftKey)                  selectRow(tr, 'shift');
            else if (e.ctrlKey || e.metaKey) selectRow(tr, 'toggle');
            else                             selectRow(tr, 'single');
        });

        /* ── 헤더 전체선택 체크박스 ── */
        if (table) {
            var hChk = table.querySelector('.tbl-check-all');
            if (hChk) {
                hChk.addEventListener('change', function () {
                    var rows = getRows();
                    if (this.checked) rows.forEach(function (tr) { selected.add(getRowId(tr)); });
                    else              selected.clear();
                    lastIndex = null;
                    syncUI();
                });
            }
        }

        /* ── 정렬 헤더 ── */
        if (config.sortable !== false && table) {
            Array.prototype.forEach.call(table.querySelectorAll('th[data-sort-key]'), function (th) {
                th.classList.add('th-sort');
                th.addEventListener('click', function (e) {
                    var key   = th.dataset.sortKey;
                    var multi = e.shiftKey;

                    /* sorts 초기화 전에 현재 방향 캡처 */
                    var existingDir = null;
                    for (var i = 0; i < sorts.length; i++) {
                        if (sorts[i].key === key) { existingDir = sorts[i].dir; break; }
                    }

                    if (!multi) sorts = [];

                    if (!existingDir) {
                        /* 미정렬 → ASC */
                        sorts.push({ key: key, dir: 'asc' });
                    } else if (existingDir === 'asc') {
                        /* ASC → DESC */
                        sorts.push({ key: key, dir: 'desc' });
                    } else {
                        /* DESC → NONE: multi면 filter, single이면 이미 sorts=[] */
                        if (multi) {
                            sorts = sorts.filter(function (s) { return s.key !== key; });
                        }
                    }

                    syncSortHeaders();
                    if (config.onSort) config.onSort(sorts.slice());
                });
            });
        }

        function syncSortHeaders() {
            if (!table) return;
            Array.prototype.forEach.call(table.querySelectorAll('th[data-sort-key]'), function (th) {
                var key  = th.dataset.sortKey;
                var sort = null;
                for (var i = 0; i < sorts.length; i++) {
                    if (sorts[i].key === key) { sort = sorts[i]; break; }
                }
                th.classList.toggle('sorted',    !!sort);
                th.classList.toggle('sort-asc',  !!(sort && sort.dir === 'asc'));
                th.classList.toggle('sort-desc', !!(sort && sort.dir === 'desc'));
                var icon = th.querySelector('.sort-icon');
                if (icon) icon.textContent = !sort ? '↕' : (sort.dir === 'asc' ? '↑' : '↓');
            });
        }

        /* ── 디바운스 검색 ── */
        var searchEl = getEl(config.searchInput);
        if (searchEl && config.onSearch) {
            var _timer = null;
            var _delay = (config.searchDelay != null) ? config.searchDelay : 300;
            searchEl.addEventListener('input', function () {
                clearTimeout(_timer);
                var q = searchEl.value;
                _timer = setTimeout(function () { config.onSearch(q); }, _delay);
            });
            searchEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    clearTimeout(_timer);
                    config.onSearch(searchEl.value);
                }
            });
        }

        /* ── row hover 액션 주입 ── */
        function injectActions(tr) {
            if (!config.rowActions || config.rowActions.length === 0) return;
            if (tr.querySelector('.tbl-row-actions')) return;
            var lastTd = tr.querySelector('td:last-child');
            if (!lastTd) return;

            var wrap = document.createElement('span');
            wrap.className = 'tbl-row-actions';

            config.rowActions.forEach(function (act) {
                var btn = document.createElement('button');
                btn.type      = 'button';
                btn.className = 'tbl-row-action-btn' + (act.cls ? ' ' + act.cls : '');
                btn.title     = act.title || '';
                btn.innerHTML = act.icon ? '<i class="fa ' + act.icon + '"></i>' : (act.label || '');
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (act.onClick) act.onClick(getRowId(tr), tr);
                });
                wrap.appendChild(btn);
            });

            lastTd.appendChild(wrap);
        }

        getRows().forEach(injectActions);

        /* ── 키보드 내비게이션 ── */
        if (config.keyboard !== false) {
            tbody.setAttribute('tabindex', '0');
            tbody.addEventListener('keydown', function (e) {
                var rows = getRows();
                if (rows.length === 0) return;
                if (focusIndex === null) focusIndex = 0;

                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    var next = e.key === 'ArrowDown'
                        ? Math.min(focusIndex + 1, rows.length - 1)
                        : Math.max(focusIndex - 1, 0);

                    if (e.shiftKey) {
                        selected.add(getRowId(rows[next]));
                        lastIndex = next;
                    } else {
                        selected.clear();
                        selected.add(getRowId(rows[next]));
                        lastIndex = next;
                    }

                    focusIndex = next;
                    rows[focusIndex].scrollIntoView({ block: 'nearest' });
                    syncUI();

                } else if (e.key === ' ') {
                    e.preventDefault();
                    var tr = rows[focusIndex];
                    if (tr) selectRow(tr, 'toggle');

                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    var tr = rows[focusIndex];
                    if (tr && config.onEnter) config.onEnter(getRowId(tr), tr);
                }
            });
        }

        /* ── 공개 API ── */
        return {
            /** 현재 선택된 id Set 복사본 반환 */
            getSelected: function () { return new Set(selected); },

            /** 선택 전체 해제 */
            clearSelection: function () {
                selected.clear();
                lastIndex  = null;
                focusIndex = null;
                syncUI();
            },

            /** 페이지 reload 후 새 rows에 액션 재주입 */
            refresh: function () {
                selected.clear();
                lastIndex  = null;
                focusIndex = null;
                getRows().forEach(injectActions);
                syncUI();
            },

            /** 현재 정렬 배열 반환 */
            getSorts: function ()  { return sorts.slice(); },

            /** 정렬 초기화 */
            resetSorts: function () { sorts = []; syncSortHeaders(); }
        };
    }

    SHV.table = { init: init };

})(window.SHV);
