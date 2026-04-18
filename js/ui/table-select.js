/* ========================================
   SHVQ V2 — Table Select
   shvTblSelect(tableOrSelector, opts)
   SHV.tblSelect(tableOrSelector, opts)

   opts = {
     actions : [{ key, label, icon, danger }],
     onAction: function(actionKey, selectedRows) {},
     idAttr  : 'data-idx'   // TR의 row-id 속성
   }

   selectedRows = [{ el: <tr>, id: string }]
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {

    /* ── 체크박스 td 주입 (미주입 행만) ── */
    function injectCheckboxes(tbody) {
        Array.prototype.forEach.call(tbody.rows, function (tr) {
            if (tr.cells[0] && tr.cells[0].classList.contains('td-check')) return;
            var td = document.createElement('td');
            td.className = 'td-check';
            /* 체크박스 셀 클릭 → 행 onclick 전파 차단 */
            td.addEventListener('click', function (e) { e.stopPropagation(); });
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'tbl-check-row';
            cb.setAttribute('aria-label', '행 선택');
            td.appendChild(cb);
            tr.insertBefore(td, tr.cells[0]);
        });
    }

    /* ── 전체선택 체크박스 상태 동기화 (체크/해제/indeterminate) ── */
    function syncAllCb(tbody, allCb) {
        var all = tbody.querySelectorAll('.tbl-check-row');
        var chk = tbody.querySelectorAll('.tbl-check-row:checked');
        if (!all.length || !chk.length) {
            allCb.checked = false; allCb.indeterminate = false;
        } else if (chk.length === all.length) {
            allCb.checked = true;  allCb.indeterminate = false;
        } else {
            allCb.checked = false; allCb.indeterminate = true;
        }
    }

    /* ── 선택 바 카운트·표시 업데이트 ── */
    function updateBar(tbody, bar) {
        var cnt = tbody.querySelectorAll('.tbl-check-row:checked').length;
        var cntEl = bar.querySelector('.tbl-sel-cnt');
        if (cntEl) cntEl.textContent = cnt;
        bar.style.display = cnt > 0 ? 'flex' : 'none';
    }

    /* ── 전체 선택 해제 ── */
    function clearAll(tbody, bar, allCb) {
        tbody.querySelectorAll('.tbl-check-row').forEach(function (cb) {
            cb.checked = false;
            cb.closest('tr').classList.remove('tbl-row-selected');
        });
        allCb.checked = false;
        allCb.indeterminate = false;
        bar.style.display = 'none';
    }

    /* ── 선택 bar DOM 생성 ── */
    function buildBar(actions) {
        var bar = document.createElement('div');
        bar.className = 'tbl-select-bar';
        bar.style.display = 'none';

        var html = '<span class="tbl-sel-info">'
            + '<i class="fa fa-check-square-o"></i>&nbsp;'
            + '<b class="tbl-sel-cnt">0</b>개 선택됨'
            + '</span>'
            + '<div class="tbl-sel-acts">'
            + '<button class="btn btn-ghost btn-sm tbl-sel-clear">'
            + '<i class="fa fa-times"></i> 선택해제</button>';

        actions.forEach(function (a) {
            html += '<button class="btn btn-sm '
                + (a.danger ? 'btn-danger' : 'btn-outline')
                + '" data-action="' + a.key + '">'
                + (a.icon ? '<i class="fa ' + a.icon + '"></i> ' : '')
                + a.label + '</button>';
        });
        html += '</div>';
        bar.innerHTML = html;
        return bar;
    }

    /* ── 선택된 행 배열 반환 ── */
    function getSelected(tbody, idAttr) {
        var rows = [];
        tbody.querySelectorAll('.tbl-check-row:checked').forEach(function (cb) {
            var tr = cb.closest('tr');
            rows.push({ el: tr, id: tr.getAttribute(idAttr) });
        });
        return rows;
    }

    /* ──────────────────────────────────────
       init
    ────────────────────────────────────── */
    function init(tableOrSelector, opts) {
        var table = typeof tableOrSelector === 'string'
            ? document.querySelector(tableOrSelector)
            : tableOrSelector;
        if (!table || table._shvSelInit) return;
        table._shvSelInit = true;

        opts = opts || {};
        var actions  = opts.actions  || [];
        var onAction = opts.onAction || null;
        var idAttr   = opts.idAttr   || 'data-idx';
        var tbody    = table.tBodies[0];
        var thead    = table.tHead;
        if (!thead || !thead.rows[0]) return;

        /* ── 헤더 th 삽입 ── */
        var hth = document.createElement('th');
        hth.className = 'th-check';
        hth.setAttribute('data-sort', 'false');
        var allCb = document.createElement('input');
        allCb.type = 'checkbox';
        allCb.className = 'tbl-check-all';
        allCb.setAttribute('aria-label', '전체선택');
        hth.appendChild(allCb);
        thead.rows[0].insertBefore(hth, thead.rows[0].cells[0]);

        /* ── bar 생성 및 삽입 (table 바로 앞 — scroll div 안) ── */
        var bar = buildBar(actions);
        table.parentNode.insertBefore(bar, table);

        /* ── 기존 행 체크박스 주입 ── */
        if (tbody) injectCheckboxes(tbody);

        /* ── 전체선택 이벤트 ── */
        allCb.addEventListener('change', function () {
            if (!tbody) return;
            var chk = this.checked;
            tbody.querySelectorAll('.tbl-check-row').forEach(function (cb) {
                cb.checked = chk;
                cb.closest('tr').classList.toggle('tbl-row-selected', chk);
            });
            updateBar(tbody, bar);
        });

        /* ── 행 체크박스 이벤트 위임 ── */
        if (tbody) {
            tbody.addEventListener('change', function (e) {
                if (!e.target.classList.contains('tbl-check-row')) return;
                e.target.closest('tr').classList.toggle('tbl-row-selected', e.target.checked);
                syncAllCb(tbody, allCb);
                updateBar(tbody, bar);
            });
        }

        /* ── 선택 해제 버튼 ── */
        var clearBtn = bar.querySelector('.tbl-sel-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (tbody) clearAll(tbody, bar, allCb);
            });
        }

        /* ── action 버튼 ── */
        if (onAction) {
            bar.querySelectorAll('[data-action]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    onAction(this.dataset.action, getSelected(tbody, idAttr));
                });
            });
        }

        /* ── MutationObserver: tbody 행 추가·삭제 감지 ── */
        if (tbody) {
            var observer = new MutationObserver(function (muts) {
                var added = false, removed = false;
                muts.forEach(function (m) {
                    m.addedNodes.forEach(function (n) {
                        if (n.nodeType === 1 && n.tagName === 'TR') added = true;
                    });
                    m.removedNodes.forEach(function (n) {
                        if (n.nodeType === 1 && n.tagName === 'TR') removed = true;
                    });
                });
                if (added)           injectCheckboxes(tbody);
                if (added || removed) { syncAllCb(tbody, allCb); updateBar(tbody, bar); }
            });
            observer.observe(tbody, { childList: true });
            table._shvSelObserver = observer;
        }

        table._shvSelRefs = { bar: bar, allCb: allCb, tbody: tbody };
    }

    /* ── 외부 리셋 API (hdReload 등 데이터 재로드 후 호출) ── */
    function reset(tableOrSelector) {
        var table = typeof tableOrSelector === 'string'
            ? document.querySelector(tableOrSelector)
            : tableOrSelector;
        if (!table || !table._shvSelRefs) return;
        var r = table._shvSelRefs;
        if (r.tbody) clearAll(r.tbody, r.bar, r.allCb);
    }

    SHV.tblSelect      = init;
    SHV.tblSelectReset = reset;

    /* 전역 단축 (CLAUDE.md 규칙) */
    window.shvTblSelect      = init;
    window.shvTblSelectReset = reset;

})(window.SHV);
