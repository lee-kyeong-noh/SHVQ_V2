<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/erp/StockService.php';

function matStockInH(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="mat-stock-in"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
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

$recentRows = [];
try {
    $stmt = $db->query(
        "SELECT TOP (100)
            l.idx,
            l.stock_type,
            l.item_idx,
            ISNULL(i.item_code,'') AS item_code,
            ISNULL(i.name,'') AS item_name,
            ISNULL(i.standard,'') AS standard,
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
         WHERE l.stock_type IN (1,4)
         ORDER BY l.idx DESC"
    );
    $recentRows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) { $recentRows = []; }
?>
<section data-page="mat-stock-in">
    <div class="page-header">
        <h2 class="page-title" data-title="입고관리">입고관리</h2>
        <p class="page-subtitle">품목 입고 등록 및 최근 입고/이동입 이력 조회</p>
    </div>

    <!-- 입고 등록 폼 -->
    <div class="card card-mt">
        <div class="card-header">
            <span><i class="fa fa-arrow-down icon-success"></i>입고 등록</span>
        </div>
        <div class="card-body">
            <div class="form-row form-row--filter">
                <div class="form-group mw-120">
                    <label class="form-label">품목 IDX <span class="required">*</span></label>
                    <input id="siItemIdx" type="number" class="form-input" placeholder="품목 IDX" min="1">
                </div>
                <div class="form-group mw-140">
                    <label class="form-label">지사(창고) <span class="required">*</span></label>
                    <select id="siBranchIdx" class="form-select">
                        <option value="">선택</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int)($branch['idx'] ?? 0) ?>"><?= matStockInH((string)($branch['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mw-100">
                    <label class="form-label">수량 <span class="required">*</span></label>
                    <input id="siQty" type="number" class="form-input" placeholder="수량" min="1">
                </div>
                <div class="form-group fg-2 mw-160">
                    <label class="form-label">메모</label>
                    <input id="siMemo" type="text" class="form-input" placeholder="메모 (선택)" maxlength="500">
                </div>
                <div class="fg-auto">
                    <button id="siBtnSubmit" class="btn btn-success"><i class="fa fa-check"></i> 입고 등록</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 최근 이력 테이블 -->
    <div class="card card-mb">
        <div class="card-header">
            <span>최근 이력 (입고/이동입)</span>
        </div>
        <div class="card-body--table">
            <table class="tbl tbl-sticky-header" id="siHistoryTable">
                <thead>
                    <tr>
                        <th class="col-70">로그 IDX</th>
                        <th class="col-140">일시</th>
                        <th class="col-72 th-center">유형</th>
                        <th class="col-110">자재번호</th>
                        <th>품목명</th>
                        <th class="col-100">지사</th>
                        <th class="col-70 th-right">수량</th>
                        <th>메모</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recentRows === []): ?>
                        <tr><td colspan="8"><div class="empty-state"><p class="empty-message">입고 이력이 없습니다.</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($recentRows as $row): ?>
                            <tr
                                data-stock-log-idx="<?= (int)($row['idx'] ?? 0) ?>"
                                data-stock-type="<?= (int)($row['stock_type'] ?? 0) ?>"
                                data-item-idx="<?= (int)($row['item_idx'] ?? 0) ?>"
                            >
                                <td class="td-muted td-mono"><?= (int)($row['idx'] ?? 0) ?></td>
                                <td class="td-nowrap td-muted"><?= matStockInH((string)($row['regdate'] ?? '')) ?></td>
                                <td class="td-center">
                                    <span class="badge <?= ((int)($row['stock_type'] ?? 0) === 1) ? 'badge-success' : 'badge-info' ?>">
                                        <?= ((int)($row['stock_type'] ?? 0) === 1) ? '입고' : '이동(입)' ?>
                                    </span>
                                </td>
                                <td class="td-mono td-nowrap"><?= matStockInH((string)($row['item_code'] ?? '')) ?></td>
                                <td><?= matStockInH((string)($row['item_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matStockInH((string)($row['branch_name'] ?? '')) ?></td>
                                <td class="td-num text-success">+<?= number_format((int)($row['qty'] ?? 0)) ?></td>
                                <td class="td-muted"><?= matStockInH((string)($row['memo'] ?? '')) ?></td>
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

    document.getElementById('siBtnSubmit').addEventListener('click', function () {
        var itemIdx   = parseInt(document.getElementById('siItemIdx').value, 10);
        var branchIdx = parseInt(document.getElementById('siBranchIdx').value, 10);
        var qty       = parseInt(document.getElementById('siQty').value, 10);
        var memo      = document.getElementById('siMemo').value.trim();

        if (!itemIdx || itemIdx < 1)   { if (SHV.toast) SHV.toast.warn('품목 IDX를 입력하세요.'); return; }
        if (!branchIdx)                { if (SHV.toast) SHV.toast.warn('지사(창고)를 선택하세요.'); return; }
        if (!qty || qty < 1)           { if (SHV.toast) SHV.toast.warn('수량을 1 이상 입력하세요.'); return; }

        shvConfirm({
            type: 'primary',
            title: '입고 등록',
            message: '입고 수량 <strong>' + qty + '</strong>을 등록하시겠습니까?',
            confirmText: '입고 등록',
        }).then(function (ok) {
            if (!ok) return;
            SHV.api.post('dist_process/saas/Stock.php', {
                todo: 'stock_in',
                item_idx:   itemIdx,
                branch_idx: branchIdx,
                qty:        qty,
                memo:       memo,
            }).then(function (res) {
                if (res.ok) {
                    if (SHV.toast) SHV.toast.success('입고 등록 완료.');
                    SHV.router.navigate('stock_in');
                } else {
                    if (SHV.toast) SHV.toast.error(res.message || '입고 등록에 실패했습니다.');
                }
            });
        });
    });

    if (window.shvTblSort) shvTblSort(document.getElementById('siHistoryTable'));

    SHV.pages = SHV.pages || {};
    SHV.pages['stock_in'] = { destroy: function () {} };
})();
</script>
