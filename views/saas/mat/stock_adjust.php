<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/erp/StockService.php';

function matStockAdjustH(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="mat-stock-adjust"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$db       = DbConnection::get();
$service  = new StockService($db);
$branches = $service->branchList();

// Tb_Department 이름 컬럼 동적 감지 (depart_name 또는 name)
$deptCol = 'depart_name';
$_dc = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='Tb_Department' AND COLUMN_NAME='depart_name'");
if ($_dc && (int)$_dc->fetchColumn() === 0) { $deptCol = 'name'; }
unset($_dc);

$rows = [];
try {
    $stmt = $db->query(
        "SELECT TOP (120)
            l.idx, l.stock_type, l.item_idx,
            ISNULL(i.item_code,'') AS item_code,
            ISNULL(i.name,'') AS item_name,
            ISNULL(d.{$deptCol},'') AS branch_name,
            l.qty,
            ISNULL(l.before_qty,0) AS before_qty,
            ISNULL(l.after_qty,0)  AS after_qty,
            ISNULL(l.memo,'')      AS memo,
            ISNULL(l.emp_name,'')  AS emp_name,
            l.regdate
         FROM Tb_StockLog l
         LEFT JOIN Tb_Item i ON l.item_idx = i.idx
         LEFT JOIN Tb_Department d ON l.branch_idx = d.idx
         WHERE l.stock_type IN (5,6)
         ORDER BY l.idx DESC"
    );
    $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) { $rows = []; }
?>
<section data-page="mat-stock-adjust">
    <div class="page-header">
        <h2 class="page-title" data-title="재고조정">재고조정</h2>
        <p class="page-subtitle">실사수량 기준 재고 증감 조정</p>
    </div>

    <!-- 조정 등록 폼 -->
    <div class="card card-mt">
        <div class="card-header">
            <span><i class="fa fa-sliders icon-warn"></i>조정 등록</span>
        </div>
        <div class="card-body">
            <div class="form-row form-row--filter">
                <div class="form-group mw-110">
                    <label class="form-label">품목 IDX <span class="required">*</span></label>
                    <input id="saItemIdx" type="number" class="form-input" placeholder="품목 IDX" min="1">
                </div>
                <div class="form-group mw-140">
                    <label class="form-label">지사(창고) <span class="required">*</span></label>
                    <select id="saBranchIdx" class="form-select">
                        <option value="">선택</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int)($branch['idx'] ?? 0) ?>"><?= matStockAdjustH((string)($branch['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mw-100">
                    <label class="form-label">실사 수량 <span class="required">*</span></label>
                    <input id="saNewQty" type="number" class="form-input" placeholder="실사 수량" min="0">
                </div>
                <div class="form-group fg-2 mw-160">
                    <label class="form-label">조정 사유 <span class="required">*</span></label>
                    <input id="saMemo" type="text" class="form-input" placeholder="조정 사유 입력" maxlength="500">
                </div>
                <div class="fg-auto">
                    <button id="saBtnSubmit" class="btn btn-outline-warn"><i class="fa fa-check"></i> 조정 등록</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 최근 조정 이력 -->
    <div class="card card-mb">
        <div class="card-header">
            <span>최근 조정 이력</span>
        </div>
        <div class="card-body--table">
            <table class="tbl tbl-sticky-header" id="saHistoryTable">
                <thead>
                    <tr>
                        <th class="col-70">로그 IDX</th>
                        <th class="col-140">일시</th>
                        <th class="col-72 th-center">유형</th>
                        <th class="col-110">자재번호</th>
                        <th>품목명</th>
                        <th class="col-100">지사</th>
                        <th class="col-70 th-right">조정량</th>
                        <th class="col-70 th-right">변경전</th>
                        <th class="col-70 th-right">변경후</th>
                        <th>사유</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="10"><div class="empty-state"><p class="empty-message">조정 이력이 없습니다.</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php $isPlus = ((int)($row['stock_type'] ?? 0) === 5); ?>
                            <tr
                                data-stock-log-idx="<?= (int)($row['idx'] ?? 0) ?>"
                                data-stock-type="<?= (int)($row['stock_type'] ?? 0) ?>"
                                data-item-idx="<?= (int)($row['item_idx'] ?? 0) ?>"
                            >
                                <td class="td-muted td-mono"><?= (int)($row['idx'] ?? 0) ?></td>
                                <td class="td-nowrap td-muted"><?= matStockAdjustH((string)($row['regdate'] ?? '')) ?></td>
                                <td class="td-center">
                                    <span class="badge <?= $isPlus ? 'badge-success' : 'badge-danger' ?>">
                                        <?= $isPlus ? '조정(+)' : '조정(-)' ?>
                                    </span>
                                </td>
                                <td class="td-mono td-nowrap"><?= matStockAdjustH((string)($row['item_code'] ?? '')) ?></td>
                                <td><?= matStockAdjustH((string)($row['item_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matStockAdjustH((string)($row['branch_name'] ?? '')) ?></td>
                                <td class="td-num <?= $isPlus ? 'text-success' : 'text-danger' ?>">
                                    <?= $isPlus ? '+' : '-' ?><?= number_format((int)($row['qty'] ?? 0)) ?>
                                </td>
                                <td class="td-num td-muted"><?= number_format((int)($row['before_qty'] ?? 0)) ?></td>
                                <td class="td-num"><?= number_format((int)($row['after_qty'] ?? 0)) ?></td>
                                <td class="td-muted"><?= matStockAdjustH((string)($row['memo'] ?? '')) ?></td>
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

    document.getElementById('saBtnSubmit').addEventListener('click', function () {
        var itemIdx   = parseInt(document.getElementById('saItemIdx').value, 10);
        var branchIdx = parseInt(document.getElementById('saBranchIdx').value, 10);
        var newQty    = parseInt(document.getElementById('saNewQty').value, 10);
        var memo      = document.getElementById('saMemo').value.trim();

        if (!itemIdx || itemIdx < 1) { if (SHV.toast) SHV.toast.warn('품목 IDX를 입력하세요.'); return; }
        if (!branchIdx)              { if (SHV.toast) SHV.toast.warn('지사(창고)를 선택하세요.'); return; }
        if (isNaN(newQty) || newQty < 0) { if (SHV.toast) SHV.toast.warn('실사 수량을 0 이상 입력하세요.'); return; }
        if (!memo)                   { if (SHV.toast) SHV.toast.warn('조정 사유를 입력하세요.'); return; }

        shvConfirm({
            type: 'warn',
            title: '재고조정',
            message: '실사 수량 <strong>' + newQty + '</strong>으로 재고를 조정하시겠습니까?',
            confirmText: '조정 등록',
        }).then(function (ok) {
            if (!ok) return;
            SHV.api.post('dist_process/saas/Stock.php', {
                todo:       'stock_adjust',
                item_idx:   itemIdx,
                branch_idx: branchIdx,
                new_qty:    newQty,
                memo:       memo,
            }).then(function (res) {
                if (res.ok) {
                    if (SHV.toast) SHV.toast.success('재고조정 완료.');
                    SHV.router.navigate('stock_adjust');
                } else {
                    if (SHV.toast) SHV.toast.error(res.message || '재고조정에 실패했습니다.');
                }
            });
        });
    });

    if (window.shvTblSort) shvTblSort(document.getElementById('saHistoryTable'));

    SHV.pages = SHV.pages || {};
    SHV.pages['stock_adjust'] = { destroy: function () {} };
})();
</script>
