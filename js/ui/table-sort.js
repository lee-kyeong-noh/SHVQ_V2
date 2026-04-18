/* ========================================
   SHVQ V2 — Table Sort
   shvTblSort(tableOrSelector)
   SHV.tblSort(tableOrSelector)

   th[data-sort="false"]  → 정렬 비활성
   th[data-sort="num"]    → 숫자 강제
   th[data-sort="date"]   → 날짜 강제
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {

    /* ── 값 추출 ── */
    function cellValue(row, idx) {
        var cell = row.cells[idx];
        if (!cell) return '';
        /* data-sort-value 속성 우선 */
        if (cell.dataset.sortValue !== undefined) return cell.dataset.sortValue;
        return cell.textContent.trim();
    }

    /* ── 숫자 파싱 (콤마 제거, 단위 제거) ── */
    function parseNum(v) {
        return parseFloat(String(v).replace(/[^0-9.\-]/g, ''));
    }

    /* ── 날짜 파싱 ── */
    function parseDate(v) {
        var d = new Date(String(v).replace(/\./g, '-'));
        return isNaN(d) ? 0 : d.getTime();
    }

    /* ── 정렬 ── */
    function sortRows(table, colIdx, asc, hint) {
        var tbody = table.tBodies[0];
        if (!tbody) return;
        var rows = Array.prototype.slice.call(tbody.rows);

        rows.sort(function (a, b) {
            var va = cellValue(a, colIdx);
            var vb = cellValue(b, colIdx);

            var na, nb;
            if (hint === 'date') {
                na = parseDate(va); nb = parseDate(vb);
                return asc ? na - nb : nb - na;
            }
            if (hint === 'num') {
                na = parseNum(va); nb = parseNum(vb);
                return asc ? na - nb : nb - na;
            }
            /* auto detect */
            na = parseNum(va); nb = parseNum(vb);
            if (!isNaN(na) && !isNaN(nb)) {
                return asc ? na - nb : nb - na;
            }
            return asc
                ? va.localeCompare(vb, 'ko', { sensitivity: 'base' })
                : vb.localeCompare(va, 'ko', { sensitivity: 'base' });
        });

        var frag = document.createDocumentFragment();
        rows.forEach(function (r) { frag.appendChild(r); });
        tbody.appendChild(frag);
    }

    /* ── 아이콘 초기화 ── */
    function resetIcons(ths) {
        ths.forEach(function (th) {
            var ico = th.querySelector('.sort-icon');
            if (ico) ico.className = 'fa fa-sort sort-icon';
            th.classList.remove('sorted', 'sort-asc', 'sort-desc');
        });
    }

    /* ──────────────────────────────────────
       init
    ────────────────────────────────────── */
    function init(tableOrSelector) {
        var table = typeof tableOrSelector === 'string'
            ? document.querySelector(tableOrSelector)
            : tableOrSelector;
        if (!table || table._shvSortInit) return;
        table._shvSortInit = true;

        var ths = Array.prototype.slice.call(table.querySelectorAll('thead th, thead td'));

        ths.forEach(function (th, i) {
            if (th.dataset.sort === 'false') return;

            /* 아이콘 삽입 */
            if (!th.querySelector('.sort-icon')) {
                var ico = document.createElement('i');
                ico.className = 'fa fa-sort sort-icon';
                th.appendChild(document.createTextNode('\u00A0'));
                th.appendChild(ico);
            }

            var asc = true;

            th.addEventListener('click', function () {
                resetIcons(ths);
                var ico = this.querySelector('.sort-icon');
                if (ico) ico.className = 'fa fa-sort-' + (asc ? 'asc' : 'desc') + ' sort-icon';
                this.classList.add('sorted', asc ? 'sort-asc' : 'sort-desc');

                sortRows(table, i, asc, th.dataset.sort || '');
                asc = !asc;
            });
        });
    }

    SHV.tblSort = init;

    /* 전역 단축 (CLAUDE.md 규칙) */
    window.shvTblSort = init;

    /* ──────────────────────────────────────
       Auto-init: MutationObserver로 .tbl 자동 감지
       #content 내부에 추가되는 모든 .tbl 테이블 자동 초기화
    ────────────────────────────────────── */
    function autoInitAll(root) {
        var tables = (root || document).querySelectorAll('table.tbl');
        tables.forEach(function (t) { init(t); });
    }

    /* DOM 준비 후 observer 부착 */
    function attachObserver() {
        var content = document.getElementById('content');
        if (!content) return;

        /* 이미 로드된 테이블 초기화 */
        autoInitAll(content);

        /* 이후 동적으로 추가되는 테이블 감지 */
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.matches && node.matches('table.tbl')) {
                        init(node);
                    } else {
                        autoInitAll(node);
                    }
                });
            });
        });

        observer.observe(content, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachObserver);
    } else {
        attachObserver();
    }

})(window.SHV);
