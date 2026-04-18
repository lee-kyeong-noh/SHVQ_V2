<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';

$auth    = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div>';
    exit;
}

$siteIdx   = (int)($_GET['site_idx'] ?? 0);
$memberIdx = (int)($_GET['member_idx'] ?? 0);
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">

<div class="ea-pick-wrap" id="eapkRoot">
    <div class="ea-pick-toolbar">
        <div class="ea-pick-search">
            <i class="fa fa-search ea-pick-search-icon"></i>
            <input type="text" id="eapkSearch" class="form-input form-input-sm" placeholder="품목명 / 규격 / 자재번호 검색" autocomplete="off">
            <button type="button" class="btn btn-ghost btn-sm" id="eapkSearchClear" title="검색 지우기"><i class="fa fa-times"></i></button>
        </div>
        <div class="ea-pick-tabs" id="eapkTabBar"></div>
    </div>

    <div class="ea-pick-body">
        <div class="ea-pick-side">
            <div class="ea-pick-side-head">카테고리</div>
            <div class="ea-cat-side" id="eapkCatTree"></div>
        </div>
        <div class="ea-pick-main">
            <div class="ea-pick-status" id="eapkStatus"></div>
            <div class="ea-pick-grid" id="eapkGrid">
                <div class="ea-pick-empty"><i class="fa fa-spinner fa-spin mr-1"></i>로딩 중...</div>
            </div>
        </div>
    </div>
</div>

<div class="modal-form-footer">
    <span class="text-xs text-3 flex-1">
        <i class="fa fa-info-circle mr-1"></i>품목 카드를 클릭하면 상세 정보가 펼쳐집니다. 추가 후 팝업은 열어둔 채 계속 선택할 수 있습니다.
    </span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.subModal.close()">닫기</button>
</div>

<script>
(function () {
    'use strict';

    var SITE_IDX   = <?= $siteIdx ?>;
    var MEMBER_IDX = <?= $memberIdx ?>;

    var _tabs        = [];
    var _activeTab   = 0;
    var _categories  = [];
    var _activeCat   = 0;
    var _items       = [];
    var _searchTerm  = '';
    var _expanded    = {};       /* product_idx → bool */
    var _children    = {};       /* product_idx → [items] */
    var _searchTimer = null;

    function $(id) { return document.getElementById(id); }
    function escH(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
    function fmtNum(v) { var n = parseInt(v, 10); return isNaN(n) ? '0' : n.toLocaleString(); }
    function safeColor(v) { var s = String(v || '').trim(); return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(s) ? s : '#6b7280'; }
    function safeUrl(v) {
        var s = String(v || '').trim();
        if (!s) return '';
        if (/^(javascript|data|vbscript):/i.test(s)) return '';
        if (/^https?:\/\//i.test(s) || /^\//.test(s) || /^\.\.?\//.test(s) || /^[a-zA-Z0-9_\-./]+(?:\?[^<>\s]*)?(?:#[^<>\s]*)?$/.test(s)) return s;
        return '';
    }

    function setStatus(msg, isError) {
        var el = $('eapkStatus');
        if (!el) return;
        if (!msg) { el.textContent = ''; el.classList.add('hidden'); return; }
        el.textContent = msg;
        el.classList.remove('hidden');
        el.classList.toggle('text-danger', !!isError);
    }

    /* ── 탭 로드 ── */
    function loadTabs() {
        SHV.api.get('dist_process/saas/Material.php', { todo: 'tab_list' }).then(function (res) {
            if (!res.ok) { setStatus('탭을 불러오지 못했습니다.', true); return; }
            _tabs = (res.data && res.data.data) || res.data || [];
            if (!Array.isArray(_tabs)) _tabs = [];
            _activeTab = _tabs.length ? parseInt(_tabs[0].idx, 10) || 0 : 0;
            renderTabs();
            loadList();
        }).catch(function () { setStatus('탭을 불러오지 못했습니다.', true); });
    }

    function renderTabs() {
        var bar = $('eapkTabBar');
        if (!bar) return;
        if (!_tabs.length) { bar.innerHTML = '<span class="text-xs text-3 px-2">탭이 없습니다</span>'; return; }
        bar.innerHTML = _tabs.map(function (t) {
            var idx = parseInt(t.idx, 10) || 0;
            var nm = escH(t.name || '');
            var cls = (idx === _activeTab) ? 'ea-pick-tab-item ea-pick-tab-active' : 'ea-pick-tab-item';
            return '<button type="button" class="' + cls + '" data-tab="' + idx + '">' + nm + '</button>';
        }).join('');
    }

    /* ── 리스트 + 카테고리 로드 ── */
    function loadList() {
        var params = { todo: 'list', limit: 50 };
        if (_activeTab > 0)   params.tab_idx  = _activeTab;
        if (_activeCat > 0)   params.cat_idx  = _activeCat;
        if (_searchTerm)      params.search   = _searchTerm;

        var grid = $('eapkGrid');
        if (grid) grid.innerHTML = '<div class="ea-pick-empty"><i class="fa fa-spinner fa-spin mr-1"></i>로딩 중...</div>';
        setStatus('');

        SHV.api.get('dist_process/saas/Material.php', params).then(function (res) {
            if (!res.ok) { setStatus(res.message || '품목을 불러오지 못했습니다.', true); if (grid) grid.innerHTML = ''; return; }
            var d = res.data || {};
            _items = d.data || [];
            _categories = d.categories || [];
            renderCatTree();
            renderGrid();
            var total = parseInt(d.total || _items.length || 0, 10);
            setStatus(_items.length ? ('총 ' + total + '건' + (_items.length < total ? ' (상위 ' + _items.length + '건 표시)' : '')) : '');
        }).catch(function () { setStatus('품목을 불러오지 못했습니다.', true); if (grid) grid.innerHTML = ''; });
    }

    /* ── 카테고리 트리 (parent_idx 기반 depth 계산) ── */
    function renderCatTree() {
        var tree = $('eapkCatTree');
        if (!tree) return;
        if (!_categories.length) { tree.innerHTML = ''; return; }

        /* parent → children 맵 */
        var byParent = {};
        var byIdx = {};
        _categories.forEach(function (c) {
            var pid = parseInt(c.parent_idx_safe != null ? c.parent_idx_safe : (c.parent_idx || 0), 10) || 0;
            c._pid = pid;
            byIdx[parseInt(c.idx, 10) || 0] = c;
            if (!byParent[pid]) byParent[pid] = [];
            byParent[pid].push(c);
        });

        var html = '';
        html += '<div class="ea-cat-item ea-cat-d0' + (_activeCat === 0 ? ' ea-cat-active' : '') + '" data-cat="0"><i class="fa fa-list mr-1"></i>전체</div>';

        function walk(parentIdx, depth) {
            var arr = byParent[parentIdx] || [];
            arr.forEach(function (c) {
                var idx = parseInt(c.idx, 10) || 0;
                var nm = escH(c.name || '');
                var d = Math.min(5, depth);
                var cls = 'ea-cat-item ea-cat-d' + d + (idx === _activeCat ? ' ea-cat-active' : '');
                html += '<div class="' + cls + '" data-cat="' + idx + '">' + nm + '</div>';
                if (byParent[idx]) walk(idx, depth + 1);
            });
        }
        walk(0, 0);
        tree.innerHTML = html;
    }

    /* ── 카드 그리드 ── */
    function renderGrid() {
        var grid = $('eapkGrid');
        if (!grid) return;
        if (!_items.length) {
            grid.innerHTML = '<div class="ea-pick-empty"><i class="fa fa-inbox mr-1"></i>품목이 없습니다</div>';
            return;
        }

        grid.innerHTML = _items.map(function (it) {
            var idx = parseInt(it.idx, 10) || 0;
            var nm = escH(it.name || it.item_name || '');
            var std = escH(it.standard || '');
            var unit = escH(it.unit || '');
            var price = parseInt(it.price || it.sale_price || 0, 10);
            var cost = parseInt(it.cost || it.purchase_price || 0, 10);
            var attr = it.attribute || '';
            var hasChild = parseInt(it.child_count || 0, 10) > 0;
            var img = pickImage(it);
            var imgHtml = img ? '<img class="ea-pick-card-img" src="' + escH(img) + '" alt="" onerror="this.style.display=\'none\'">' : '<div class="ea-pick-card-noimg"><i class="fa fa-image"></i></div>';
            var childBadge = hasChild ? '<span class="ea-pick-child-badge"><i class="fa fa-puzzle-piece mr-1"></i>구성 ' + parseInt(it.child_count, 10) + '</span>' : '';
            var attrHtml = attr ? attrBadge(attr) : '';
            var expanded = !!_expanded[idx];

            var detail = '';
            if (expanded) detail = renderDetail(it);

            return '<div class="ea-pick-card' + (expanded ? ' is-expanded' : '') + '" data-idx="' + idx + '">' +
                '<div class="ea-pick-card-head" data-toggle="' + idx + '">' +
                    imgHtml +
                    '<div class="ea-pick-card-info">' +
                        '<div class="ea-pick-card-title">' + nm + ' ' + childBadge + '</div>' +
                        '<div class="ea-pick-card-meta"><span>' + (std || '-') + '</span>' + (unit ? '<span class="text-3"> · ' + unit + '</span>' : '') + '</div>' +
                        '<div class="ea-pick-card-price">' + fmtNum(price) + '원' + (cost > 0 ? ' <span class="text-3 text-xs">(매입 ' + fmtNum(cost) + ')</span>' : '') + ' ' + attrHtml + '</div>' +
                    '</div>' +
                    '<i class="fa fa-chevron-' + (expanded ? 'up' : 'down') + ' ea-pick-card-caret"></i>' +
                '</div>' +
                detail +
            '</div>';
        }).join('');
    }

    function attrBadge(a) {
        if (!a || a === '0' || a === '없음') return '';
        var c = safeColor('#6b7280');
        return '<span class="ea-attr-badge" style="--ac:' + c + '">' + escH(a) + '</span>';
    }

    function pickImage(it) {
        var raw = it.banner_img || it.upload_files_banner || it.upload_files_detail || '';
        if (!raw) return '';
        if (/^https?:\/\//i.test(raw)) return safeUrl(raw);
        if (raw.charAt(0) === '/') return safeUrl(raw);
        /* tenant 격리 경로 가정: V1 이관 파일은 mat/ 아래 */
        return safeUrl('uploads/mat/' + raw);
    }

    function renderDetail(it) {
        var idx = parseInt(it.idx, 10) || 0;
        var img = pickImage(it);
        var fullImg = img ? '<img class="ea-pick-detail-img" src="' + escH(img) + '" alt="" onerror="this.style.display=\'none\'">' : '';
        var price = parseInt(it.price || it.sale_price || 0, 10);
        var cost = parseInt(it.cost || it.purchase_price || 0, 10);
        var nm = escH(it.name || it.item_name || '');
        var std = escH(it.standard || '');
        var unit = escH(it.unit || '');
        var code = escH(it.item_code || '');
        var attr = it.attribute || '';
        var hasChild = parseInt(it.child_count || 0, 10) > 0;
        var childList = '';
        if (hasChild) {
            if (_children[idx] === undefined) {
                childList = '<div class="ea-pick-children"><i class="fa fa-spinner fa-spin mr-1"></i>구성품 로딩...</div>';
                queueLoadChildren(idx);
            } else {
                var ch = _children[idx] || [];
                if (!ch.length) {
                    childList = '<div class="ea-pick-children text-3"><i class="fa fa-info-circle mr-1"></i>구성품 없음</div>';
                } else {
                    childList = '<div class="ea-pick-children"><div class="ea-pick-children-head">구성품 ' + ch.length + '건</div>' +
                        ch.map(function (c) {
                            var cn = escH(c.name || c.item_name || '');
                            var cs = escH(c.standard || '');
                            var cp = parseInt(c.price || c.sale_price || 0, 10);
                            return '<div class="ea-pick-child-row"><i class="fa fa-angle-right mr-1 text-3"></i><span>' + cn + '</span>' +
                                (cs ? '<span class="text-3 text-xs ml-2">' + cs + '</span>' : '') +
                                '<span class="ml-auto text-3 text-xs">' + fmtNum(cp) + '원</span></div>';
                        }).join('') +
                    '</div>';
                }
            }
        }

        return '<div class="ea-pick-card-detail">' +
            (fullImg ? '<div class="ea-pick-detail-imgwrap">' + fullImg + '</div>' : '') +
            '<div class="ea-pick-detail-meta">' +
                (code ? '<div class="ea-pick-detail-row"><span class="text-3">자재번호</span><b>' + code + '</b></div>' : '') +
                '<div class="ea-pick-detail-row"><span class="text-3">규격</span><b>' + (std || '-') + '</b></div>' +
                (unit ? '<div class="ea-pick-detail-row"><span class="text-3">단위</span><b>' + unit + '</b></div>' : '') +
                '<div class="ea-pick-detail-row"><span class="text-3">단가</span><b>' + fmtNum(price) + '원</b></div>' +
                (cost > 0 ? '<div class="ea-pick-detail-row"><span class="text-3">매입</span><b>' + fmtNum(cost) + '원</b></div>' : '') +
                (attr ? '<div class="ea-pick-detail-row"><span class="text-3">PJT 속성</span><span>' + attrBadge(attr) + '</span></div>' : '') +
            '</div>' +
            '<div class="ea-pick-detail-actions">' +
                '<label class="ea-pick-qty-lbl">수량</label>' +
                '<input type="number" class="form-input form-input-sm ea-pick-qty-input" id="eapkQty_' + idx + '" value="1" min="1">' +
                '<button type="button" class="btn btn-glass-primary btn-sm ea-pick-add-btn" data-add="' + idx + '"><i class="fa fa-plus mr-1"></i>장바구니 추가</button>' +
            '</div>' +
            childList +
        '</div>';
    }

    /* ── 자식 로드 (디테일 펼침용) ── */
    var _childQueue = {};
    function queueLoadChildren(parentIdx) {
        if (_childQueue[parentIdx]) return;
        _childQueue[parentIdx] = true;
        SHV.api.get('dist_process/saas/Material.php', { todo: 'list', parent_idx: parentIdx, limit: 50 }).then(function (r) {
            if (!r.ok) { _children[parentIdx] = []; renderGrid(); return; }
            _children[parentIdx] = (r.data && r.data.data) || [];
            renderGrid();
        }).catch(function () { _children[parentIdx] = []; renderGrid(); });
    }

    /* ── 장바구니 추가 (부모 컨텍스트의 eaAddToCart 호출) ── */
    function addToCart(idx) {
        var it = _items.find(function (x) { return (parseInt(x.idx, 10) || 0) === idx; });
        if (!it) return;
        var qtyEl = $('eapkQty_' + idx);
        var qty = qtyEl ? Math.max(1, parseInt(qtyEl.value, 10) || 1) : 1;
        if (typeof window.eaAddToCart !== 'function') {
            if (SHV.toast) SHV.toast.warn('상위 견적 폼을 찾을 수 없습니다.');
            return;
        }
        window.eaAddToCart({
            product_idx: parseInt(it.idx, 10) || 0,
            name: it.name || it.item_name || '',
            standard: it.standard || '',
            unit: it.unit || '',
            price: parseInt(it.price || it.sale_price || 0, 10),
            cost: parseInt(it.cost || it.purchase_price || 0, 10),
            qty: qty,
            attribute: it.attribute || '',
            manual: false
        });
        var hasChild = parseInt(it.child_count || 0, 10) > 0;
        if (hasChild && typeof window.eaLoadChildren === 'function') {
            window.eaLoadChildren(parseInt(it.idx, 10) || 0);
        }
        if (SHV.toast) SHV.toast.success('"' + (it.name || it.item_name || '품목') + '" 추가됨');
    }

    /* ── 이벤트 위임 ── */
    function bindEvents() {
        var root = $('eapkRoot');
        if (!root) return;

        root.addEventListener('click', function (e) {
            var t = e.target;
            /* 추가 버튼 */
            var addBtn = t.closest && t.closest('[data-add]');
            if (addBtn) { e.stopPropagation(); addToCart(parseInt(addBtn.getAttribute('data-add'), 10) || 0); return; }
            /* 카드 토글 */
            var head = t.closest && t.closest('[data-toggle]');
            if (head) {
                var idx = parseInt(head.getAttribute('data-toggle'), 10) || 0;
                _expanded[idx] = !_expanded[idx];
                renderGrid();
                return;
            }
            /* 카테고리 클릭 */
            var cat = t.closest && t.closest('[data-cat]');
            if (cat) {
                var ci = parseInt(cat.getAttribute('data-cat'), 10) || 0;
                if (ci !== _activeCat) { _activeCat = ci; loadList(); }
                return;
            }
            /* 탭 클릭 */
            var tab = t.closest && t.closest('[data-tab]');
            if (tab) {
                var ti = parseInt(tab.getAttribute('data-tab'), 10) || 0;
                if (ti !== _activeTab) { _activeTab = ti; _activeCat = 0; loadList(); }
                return;
            }
        });

        var srch = $('eapkSearch');
        if (srch) {
            srch.addEventListener('input', function () {
                clearTimeout(_searchTimer);
                _searchTimer = setTimeout(function () {
                    _searchTerm = (srch.value || '').trim();
                    loadList();
                }, 250);
            });
            srch.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); clearTimeout(_searchTimer); _searchTerm = (srch.value || '').trim(); loadList(); }
            });
        }
        var clr = $('eapkSearchClear');
        if (clr) {
            clr.addEventListener('click', function () {
                if (srch) srch.value = '';
                _searchTerm = '';
                loadList();
            });
        }
    }

    /* ── 초기화 ── */
    bindEvents();
    loadTabs();
})();
</script>
