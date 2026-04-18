<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/erp/StockService.php';

function matStockLogH(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="mat-stock-log"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$service = new StockService(DbConnection::get());
$page    = max(1, (int)($_GET['p'] ?? 1));
$limit   = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$query   = [
    'p'          => $page,
    'limit'      => $limit,
    'branch_idx' => (int)($_GET['branch_idx'] ?? 0),
    'stock_type' => (int)($_GET['stock_type'] ?? 0),
    'item_idx'   => (int)($_GET['item_idx'] ?? 0),
    'search'     => trim((string)($_GET['search'] ?? '')),
    'date_s'     => trim((string)($_GET['date_s'] ?? '')),
    'date_e'     => trim((string)($_GET['date_e'] ?? '')),
];

$result   = $service->stockLog($query);
$list     = is_array($result['list'] ?? null) ? $result['list'] : [];
$total    = (int)($result['total'] ?? 0);
$branches = $service->branchList();

$typeLabels = [
    1 => '입고', 2 => '출고', 3 => '이동(출)', 4 => '이동(입)', 5 => '조정(+)', 6 => '조정(-)',
];
$typeBadge = [
    1 => 'badge-success', 2 => 'badge-danger', 3 => 'badge-warn',
    4 => 'badge-info',    5 => 'badge-success', 6 => 'badge-danger',
];
?>
<section data-page="mat-stock-log">
    <div class="page-header">
        <h2 class="page-title" data-title="재고이력">재고이력</h2>
        <p class="page-subtitle">입고/출고/이동/조정 전체 로그 조회</p>
    </div>

    <!-- 조회 필터 -->
    <div class="card card-mt">
        <div class="card-body">
            <div class="form-row form-row--filter">
                <div class="form-group mw-130">
                    <label class="form-label">지사</label>
                    <select id="slBranch" class="form-select">
                        <option value="0">전체</option>
                        <?php foreach ($branches as $branch): ?>
                            <?php $bIdx = (int)($branch['idx'] ?? 0); ?>
                            <option value="<?= $bIdx ?>" <?= ((int)$query['branch_idx'] === $bIdx) ? 'selected' : '' ?>>
                                <?= matStockLogH((string)($branch['name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mw-110">
                    <label class="form-label">유형</label>
                    <select id="slType" class="form-select">
                        <option value="0">전체</option>
                        <?php foreach ($typeLabels as $type => $label): ?>
                            <option value="<?= $type ?>" <?= ((int)$query['stock_type'] === $type) ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group fg-2 mw-140">
                    <label class="form-label">검색</label>
                    <input id="slSearch" type="text" class="form-input" placeholder="품목명/자재번호" value="<?= matStockLogH((string)$query['search']) ?>">
                </div>
                <div class="form-group mw-120">
                    <label class="form-label">시작일</label>
                    <input id="slDateS" type="date" class="form-input" value="<?= matStockLogH((string)$query['date_s']) ?>">
                </div>
                <div class="form-group mw-120">
                    <label class="form-label">종료일</label>
                    <input id="slDateE" type="date" class="form-input" value="<?= matStockLogH((string)$query['date_e']) ?>">
                </div>
                <div class="fg-auto">
                    <button id="slSearchBtn" class="btn btn-primary"><i class="fa fa-search"></i> 조회</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 로그 테이블 -->
    <div class="card card-mb">
        <div class="card-header">
            <span>로그 목록</span>
            <span class="card-header-meta">총 <?= number_format($total) ?>건</span>
        </div>
        <div class="card-body--table">
            <table class="tbl tbl-sticky-header tbl-min-1200" id="slTable">
                <thead>
                    <tr>
                        <th class="col-70">로그 IDX</th>
                        <th class="col-140">일시</th>
                        <th class="col-72 th-center">유형</th>
                        <th class="col-110">자재번호</th>
                        <th>품목명</th>
                        <th class="col-100">지사</th>
                        <th class="col-70 th-right">수량</th>
                        <th class="col-70 th-right">변경전</th>
                        <th class="col-70 th-right">변경후</th>
                        <th class="col-100">대상</th>
                        <th class="col-100">현장</th>
                        <th>메모</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($list === []): ?>
                        <tr><td colspan="12"><div class="empty-state"><p class="empty-message">로그 데이터가 없습니다.</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($list as $row): ?>
                            <?php
                            $type      = (int)($row['stock_type'] ?? 0);
                            $typeLabel = $typeLabels[$type] ?? '기타';
                            $badge     = $typeBadge[$type] ?? 'badge-ghost';
                            ?>
                            <tr
                                data-stock-log-idx="<?= (int)($row['idx'] ?? 0) ?>"
                                data-stock-type="<?= $type ?>"
                                data-item-idx="<?= (int)($row['item_idx'] ?? 0) ?>"
                                data-branch-idx="<?= (int)($row['branch_idx'] ?? 0) ?>"
                            >
                                <td class="td-muted td-mono"><?= (int)($row['idx'] ?? 0) ?></td>
                                <td class="td-nowrap td-muted"><?= matStockLogH((string)($row['regdate'] ?? '')) ?></td>
                                <td class="td-center"><span class="badge <?= $badge ?>"><?= $typeLabel ?></span></td>
                                <td class="td-mono td-nowrap"><?= matStockLogH((string)($row['item_code'] ?? '')) ?></td>
                                <td><?= matStockLogH((string)($row['item_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matStockLogH((string)($row['branch_name'] ?? '')) ?></td>
                                <td class="td-num"><?= number_format((int)($row['qty'] ?? 0)) ?></td>
                                <td class="td-num td-muted"><?= number_format((int)($row['before_qty'] ?? 0)) ?></td>
                                <td class="td-num"><?= number_format((int)($row['after_qty'] ?? 0)) ?></td>
                                <td class="td-muted"><?= matStockLogH((string)($row['target_branch_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matStockLogH((string)($row['site_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matStockLogH((string)($row['memo'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<script>
(function () {
    'use strict';

    function reload() {
        SHV.router.navigate('stock_log', {
            branch_idx: document.getElementById('slBranch').value,
            stock_type: document.getElementById('slType').value,
            search:     document.getElementById('slSearch').value.trim(),
            date_s:     document.getElementById('slDateS').value,
            date_e:     document.getElementById('slDateE').value,
        });
    }

    document.getElementById('slSearchBtn').addEventListener('click', reload);
    document.getElementById('slSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') reload();
    });

    if (window.shvTblSort) shvTblSort(document.getElementById('slTable'));

    SHV.pages = SHV.pages || {};
    SHV.pages['stock_log'] = { destroy: function () {} };
})();
</script>
