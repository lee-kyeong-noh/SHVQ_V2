<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/erp/StockService.php';

function matStockStatusH(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="mat-stock-status"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$service = new StockService(DbConnection::get());
$page    = max(1, (int)($_GET['p'] ?? 1));
$limit   = min(200, max(1, (int)($_GET['limit'] ?? 50)));

$list = []; $branches = []; $total = 0;
try {
    $result   = $service->stockStatus([
        'p'            => $page,
        'limit'        => $limit,
        'search'       => trim((string)($_GET['search'] ?? '')),
        'tab_idx'      => (int)($_GET['tab_idx'] ?? 0),
        'branch_idx'   => (int)($_GET['branch_idx'] ?? 0),
        'include_zero' => (int)($_GET['include_zero'] ?? 0),
    ]);
    $list     = is_array($result['list'] ?? null) ? $result['list'] : [];
    $branches = is_array($result['branches'] ?? null) ? $result['branches'] : [];
    $total    = (int)($result['total'] ?? 0);
} catch (Throwable $e) {
    // DB 스키마 불일치 시 빈 결과로 렌더링 (ChatGPT 백엔드 수정 필요)
    $branches = $service->branchList();
}
?>
<section data-page="mat-stock-status" data-stock-page="<?= (int)$page ?>" data-stock-limit="<?= (int)$limit ?>">
    <div class="page-header">
        <h2 class="page-title" data-title="재고현황">재고현황</h2>
        <p class="page-subtitle">지사(창고) × 품목 재고 매트릭스</p>
    </div>

    <!-- 필터 -->
    <div class="card card-mt">
        <div class="card-body">
            <div class="form-row form-row--filter">
                <div class="form-group fg-2 mw-160">
                    <label class="form-label">품목 검색</label>
                    <input id="ssSearch" type="text" class="form-input" placeholder="품목명/규격/자재번호" value="<?= matStockStatusH((string)($_GET['search'] ?? '')) ?>">
                </div>
                <div class="form-group mw-120">
                    <label class="form-label">0재고 포함</label>
                    <select id="ssIncludeZero" class="form-select">
                        <option value="0" <?= ((int)($_GET['include_zero'] ?? 0) === 0) ? 'selected' : '' ?>>미포함</option>
                        <option value="1" <?= ((int)($_GET['include_zero'] ?? 0) === 1) ? 'selected' : '' ?>>포함</option>
                    </select>
                </div>
                <div class="fg-auto">
                    <button id="ssSearchBtn" class="btn btn-primary"><i class="fa fa-search"></i> 조회</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 매트릭스 테이블 -->
    <div class="card card-mb">
        <div class="card-header">
            <span>재고 매트릭스</span>
            <span class="card-header-meta">총 <?= number_format($total) ?>건</span>
        </div>
        <div class="card-body--table">
            <table class="tbl tbl-sticky-header" style="min-width:<?= max(800, 260 + count($branches) * 90) ?>px;">
                <thead>
                    <tr>
                        <th class="col-60">IDX</th>
                        <th class="col-110">자재번호</th>
                        <th>품목명</th>
                        <th class="col-100">규격</th>
                        <?php foreach ($branches as $branch): ?>
                            <th class="col-90 th-right" data-branch-idx="<?= (int)($branch['idx'] ?? 0) ?>">
                                <?= matStockStatusH((string)($branch['name'] ?? '')) ?>
                            </th>
                        <?php endforeach; ?>
                        <th class="col-80 th-right">합계</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($list === []): ?>
                        <tr>
                            <td colspan="<?= 5 + count($branches) ?>">
                                <div class="empty-state"><p class="empty-message">재고 데이터가 없습니다.</p></div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($list as $row): ?>
                            <?php
                            $itemIdx = (int)($row['idx'] ?? 0);
                            $stocks  = is_array($row['stocks'] ?? null) ? $row['stocks'] : [];
                            ?>
                            <tr data-item-idx="<?= $itemIdx ?>" data-item-code="<?= matStockStatusH((string)($row['item_code'] ?? '')) ?>">
                                <td class="td-muted td-mono"><?= $itemIdx ?></td>
                                <td class="td-mono td-nowrap"><?= matStockStatusH((string)($row['item_code'] ?? '')) ?></td>
                                <td><?= matStockStatusH((string)($row['name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matStockStatusH((string)($row['standard'] ?? '')) ?></td>
                                <?php foreach ($branches as $branch): ?>
                                    <?php
                                    $branchIdx = (int)($branch['idx'] ?? 0);
                                    $qty       = (int)($stocks[$branchIdx] ?? 0);
                                    ?>
                                    <td
                                        class="td-num <?= $qty < 0 ? 'td-muted text-danger' : '' ?>"
                                        data-stock-branch-idx="<?= $branchIdx ?>"
                                        data-stock-qty="<?= $qty ?>"
                                    ><?= number_format($qty) ?></td>
                                <?php endforeach; ?>
                                <td class="td-num font-bold" data-stock-total="<?= (int)($row['total_qty'] ?? 0) ?>">
                                    <?= number_format((int)($row['total_qty'] ?? 0)) ?>
                                </td>
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
        SHV.router.navigate('stock_status', {
            search:       document.getElementById('ssSearch').value.trim(),
            include_zero: document.getElementById('ssIncludeZero').value,
        });
    }

    document.getElementById('ssSearchBtn').addEventListener('click', reload);
    document.getElementById('ssSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') reload();
    });

    SHV.pages = SHV.pages || {};
    SHV.pages['stock_status'] = { destroy: function () {} };
})();
</script>
