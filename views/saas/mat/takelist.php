<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/erp/StockService.php';

function matTakeListH(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$auth    = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="mat-takelist"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$service = new StockService(DbConnection::get());

$page      = max(1, (int)($_GET['p'] ?? 1));
$limit     = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$stockType = (int)($_GET['stock_type'] ?? 0); // 기본값 0 = 전체
if (!in_array($stockType, [0, 1, 4], true)) {
    $stockType = 0;
}

$query = [
    'p'          => $page,
    'limit'      => $limit,
    'branch_idx' => (int)($_GET['branch_idx'] ?? 0),
    'stock_type' => $stockType,
    'search'     => trim((string)($_GET['search'] ?? '')),
    'date_s'     => trim((string)($_GET['date_s'] ?? '')),
    'date_e'     => trim((string)($_GET['date_e'] ?? '')),
];

// stock_type=0(전체 수령)은 IN(1,4) 다중 타입 조회로 위임
if ($stockType === 0) {
    $query['stock_type_in'] = '1,4';
}

$result = $service->stockLog($query);

$list     = is_array($result['list'] ?? null) ? $result['list'] : [];
$total    = (int)($result['total'] ?? 0);
$pages    = max(1, (int)($result['pages'] ?? 1));
$branches = $service->branchList();

$typeLabels = [
    1 => '입고 수령',
    4 => '이동 수령',
];
$typeBadge = [
    1 => 'badge-success',
    4 => 'badge-info',
];
$typeOptions = [
    0 => '전체 수령',
    1 => '입고 수령',
    4 => '이동 수령',
];
?>
<section data-page="mat-takelist" data-takelist-page="<?= (int)$page ?>" data-takelist-limit="<?= (int)$limit ?>">
    <div class="page-header">
        <h2 class="page-title" data-title="품목수령리스트">품목수령리스트</h2>
        <p class="page-subtitle">입고 수령 / 창고이동 수령 내역 조회</p>
    </div>

    <div class="card card-mt">
        <div class="card-body">
            <div class="form-row form-row--filter">
                <div class="form-group mw-130">
                    <label class="form-label">수령창고</label>
                    <select id="takeBranch" class="form-select">
                        <option value="0">전체</option>
                        <?php foreach ($branches as $branch): ?>
                            <?php $bIdx = (int)($branch['idx'] ?? 0); ?>
                            <option value="<?= $bIdx ?>" <?= ((int)$query['branch_idx'] === $bIdx) ? 'selected' : '' ?>>
                                <?= matTakeListH((string)($branch['name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mw-120">
                    <label class="form-label">수령유형</label>
                    <select id="takeType" class="form-select">
                        <?php foreach ($typeOptions as $type => $label): ?>
                            <option value="<?= $type ?>" <?= ((int)$query['stock_type'] === $type) ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group fg-2 mw-160">
                    <label class="form-label">검색</label>
                    <input id="takeSearch" type="text" class="form-input" placeholder="품목명/자재번호" value="<?= matTakeListH((string)$query['search']) ?>">
                </div>
                <div class="form-group mw-120">
                    <label class="form-label">시작일</label>
                    <input id="takeDateS" type="date" class="form-input" value="<?= matTakeListH((string)$query['date_s']) ?>">
                </div>
                <div class="form-group mw-120">
                    <label class="form-label">종료일</label>
                    <input id="takeDateE" type="date" class="form-input" value="<?= matTakeListH((string)$query['date_e']) ?>">
                </div>
                <div class="form-group fg-page">
                    <label class="form-label">페이지 크기</label>
                    <select id="takeLimit" class="form-select">
                        <?php foreach ([30, 50, 100, 200] as $size): ?>
                            <option value="<?= $size ?>" <?= $limit === $size ? 'selected' : '' ?>><?= $size ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg-auto">
                    <button id="takeSearchBtn" class="btn btn-primary"><i class="fa fa-search"></i> 조회</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-mb">
        <div class="card-header">
            <span>수령 로그 목록</span>
            <span class="card-header-meta">총 <?= number_format($total) ?>건 / <?= (int)$page ?>페이지</span>
        </div>
        <div class="card-body--table">
            <table class="tbl tbl-sticky-header tbl-min-1100" id="takeListTable">
                <thead>
                    <tr>
                        <th class="col-70">로그 IDX</th>
                        <th class="col-140">수령일시</th>
                        <th class="col-88 th-center">수령유형</th>
                        <th class="col-120">자재번호</th>
                        <th>품목명</th>
                        <th class="col-120">수령창고</th>
                        <th class="col-70 th-right">수량</th>
                        <th class="col-70 th-right">변경전</th>
                        <th class="col-70 th-right">변경후</th>
                        <th class="col-100">처리자</th>
                        <th>메모</th>
                    </tr>
                </thead>
                <tbody id="takeListBody">
                    <?php if ($list === []): ?>
                        <tr>
                            <td colspan="11"><div class="empty-state"><p class="empty-message">수령 데이터가 없습니다.</p></div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($list as $row): ?>
                            <?php
                            $type      = (int)($row['stock_type'] ?? 0);
                            $typeLabel = $typeLabels[$type] ?? '기타';
                            $badge     = $typeBadge[$type] ?? 'badge-ghost';
                            ?>
                            <tr
                                class="clickable"
                                data-stock-log-idx="<?= (int)($row['idx'] ?? 0) ?>"
                                data-item-idx="<?= (int)($row['item_idx'] ?? 0) ?>"
                            >
                                <td class="td-muted td-mono"><?= (int)($row['idx'] ?? 0) ?></td>
                                <td class="td-nowrap td-muted"><?= matTakeListH((string)($row['regdate'] ?? '')) ?></td>
                                <td class="td-center"><span class="badge <?= $badge ?>"><?= $typeLabel ?></span></td>
                                <td class="td-mono td-nowrap"><?= matTakeListH((string)($row['item_code'] ?? '')) ?></td>
                                <td><?= matTakeListH((string)($row['item_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matTakeListH((string)($row['branch_name'] ?? '')) ?></td>
                                <td class="td-num"><?= number_format((int)($row['qty'] ?? 0)) ?></td>
                                <td class="td-num td-muted"><?= number_format((int)($row['before_qty'] ?? 0)) ?></td>
                                <td class="td-num"><?= number_format((int)($row['after_qty'] ?? 0)) ?></td>
                                <td class="td-muted"><?= matTakeListH((string)($row['emp_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matTakeListH((string)($row['memo'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer card-footer--pager">
            <span class="card-header-meta">페이지 <?= (int)$page ?> / <?= $pages ?></span>
            <div id="takePager" class="flex gap-1">
                <?php if ($page > 1): ?>
                    <button class="page-item" data-take-page="<?= $page - 1 ?>">‹</button>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                    <button class="page-item <?= $i === $page ? 'page-active' : '' ?>" data-take-page="<?= $i ?>"><?= $i ?></button>
                <?php endfor; ?>
                <?php if ($page < $pages): ?>
                    <button class="page-item" data-take-page="<?= $page + 1 ?>">›</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="mat-takelist"]');
    if (!_section) return;

    function getParams() {
        return {
            branch_idx: document.getElementById('takeBranch').value,
            stock_type: document.getElementById('takeType').value,
            search:     document.getElementById('takeSearch').value.trim(),
            date_s:     document.getElementById('takeDateS').value,
            date_e:     document.getElementById('takeDateE').value,
            limit:      document.getElementById('takeLimit').value,
        };
    }

    function reload(page) {
        var params = getParams();
        if (params.date_s && params.date_e && params.date_s > params.date_e) {
            if (window.SHV && SHV.toast) {
                SHV.toast.warn('종료일은 시작일보다 빠를 수 없습니다.');
            }
            return;
        }
        params.p = page || 1;
        SHV.router.navigate('material_takelist', params);
    }

    document.getElementById('takeSearchBtn').addEventListener('click', function () { reload(1); });
    document.getElementById('takeSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') reload(1);
    });

    ['takeBranch', 'takeType', 'takeLimit'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', function () { reload(1); });
    });

    var pager = document.getElementById('takePager');
    if (pager) {
        pager.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-take-page]');
            if (btn) reload(parseInt(btn.dataset.takePage, 10));
        });
    }

    var body = document.getElementById('takeListBody');
    if (body) {
        body.addEventListener('click', function (e) {
            var tr = e.target.closest('tr[data-item-idx]');
            if (!tr) return;
            var itemIdx = parseInt(tr.dataset.itemIdx || '0', 10);
            if (itemIdx > 0) SHV.router.navigate('mat_view', { idx: itemIdx });
        });
    }

    if (window.shvTblSort) shvTblSort(document.getElementById('takeListTable'));

    SHV.pages = SHV.pages || {};
    SHV.pages['material_takelist'] = { destroy: function () {} };
})();
</script>
