<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/erp/StockService.php';

function matStockTransferH(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="mat-stock-transfer"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
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
            l.idx, l.item_idx,
            ISNULL(i.item_code,'') AS item_code,
            ISNULL(i.name,'') AS item_name,
            ISNULL(d1.{$deptCol},'') AS from_branch_name,
            ISNULL(d2.{$deptCol},'') AS to_branch_name,
            l.branch_idx AS from_branch_idx,
            ISNULL(l.target_branch_idx,0) AS to_branch_idx,
            l.qty,
            ISNULL(l.memo,'')     AS memo,
            ISNULL(l.emp_name,'') AS emp_name,
            l.regdate
         FROM Tb_StockLog l
         LEFT JOIN Tb_Item i ON l.item_idx = i.idx
         LEFT JOIN Tb_Department d1 ON l.branch_idx = d1.idx
         LEFT JOIN Tb_Department d2 ON l.target_branch_idx = d2.idx
         WHERE l.stock_type = 3
         ORDER BY l.idx DESC"
    );
    $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) { $rows = []; }
?>
<section data-page="mat-stock-transfer">
    <div class="page-header">
        <h2 class="page-title" data-title="창고간이동">창고간 이동</h2>
        <p class="page-subtitle">출발 지사 → 도착 지사 이동 처리</p>
    </div>

    <!-- 이동 등록 폼 -->
    <div class="card card-mt">
        <div class="card-header">
            <span><i class="fa fa-exchange icon-accent"></i>이동 등록</span>
        </div>
        <div class="card-body">
            <div class="form-row form-row--filter">
                <div class="form-group mw-110">
                    <label class="form-label">품목 IDX <span class="required">*</span></label>
                    <input id="stItemIdx" type="number" class="form-input" placeholder="품목 IDX" min="1">
                </div>
                <div class="form-group mw-140">
                    <label class="form-label">출발 지사 <span class="required">*</span></label>
                    <select id="stFromBranch" class="form-select">
                        <option value="">선택</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int)($branch['idx'] ?? 0) ?>"><?= matStockTransferH((string)($branch['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mw-140">
                    <label class="form-label">도착 지사 <span class="required">*</span></label>
                    <select id="stToBranch" class="form-select">
                        <option value="">선택</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int)($branch['idx'] ?? 0) ?>"><?= matStockTransferH((string)($branch['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mw-90">
                    <label class="form-label">수량 <span class="required">*</span></label>
                    <input id="stQty" type="number" class="form-input" placeholder="수량" min="1">
                </div>
                <div class="form-group fg-2 mw-140">
                    <label class="form-label">메모</label>
                    <input id="stMemo" type="text" class="form-input" placeholder="메모 (선택)" maxlength="500">
                </div>
                <div class="fg-auto">
                    <button id="stBtnSubmit" class="btn btn-primary"><i class="fa fa-check"></i> 이동 등록</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 최근 이동 이력 -->
    <div class="card card-mb">
        <div class="card-header">
            <span>최근 이동 이력</span>
        </div>
        <div class="card-body--table">
            <table class="tbl tbl-sticky-header" id="stHistoryTable">
                <thead>
                    <tr>
                        <th class="col-70">로그 IDX</th>
                        <th class="col-140">일시</th>
                        <th class="col-110">자재번호</th>
                        <th>품목명</th>
                        <th class="col-110">출발</th>
                        <th class="col-110">도착</th>
                        <th class="col-70 th-right">수량</th>
                        <th>메모</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="8"><div class="empty-state"><p class="empty-message">이동 이력이 없습니다.</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr
                                data-stock-log-idx="<?= (int)($row['idx'] ?? 0) ?>"
                                data-from-branch-idx="<?= (int)($row['from_branch_idx'] ?? 0) ?>"
                                data-to-branch-idx="<?= (int)($row['to_branch_idx'] ?? 0) ?>"
                            >
                                <td class="td-muted td-mono"><?= (int)($row['idx'] ?? 0) ?></td>
                                <td class="td-nowrap td-muted"><?= matStockTransferH((string)($row['regdate'] ?? '')) ?></td>
                                <td class="td-mono td-nowrap"><?= matStockTransferH((string)($row['item_code'] ?? '')) ?></td>
                                <td><?= matStockTransferH((string)($row['item_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matStockTransferH((string)($row['from_branch_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matStockTransferH((string)($row['to_branch_name'] ?? '')) ?></td>
                                <td class="td-num"><?= number_format((int)($row['qty'] ?? 0)) ?></td>
                                <td class="td-muted"><?= matStockTransferH((string)($row['memo'] ?? '')) ?></td>
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

    document.getElementById('stBtnSubmit').addEventListener('click', function () {
        var itemIdx    = parseInt(document.getElementById('stItemIdx').value, 10);
        var fromBranch = parseInt(document.getElementById('stFromBranch').value, 10);
        var toBranch   = parseInt(document.getElementById('stToBranch').value, 10);
        var qty        = parseInt(document.getElementById('stQty').value, 10);
        var memo       = document.getElementById('stMemo').value.trim();

        if (!itemIdx || itemIdx < 1) { if (SHV.toast) SHV.toast.warn('품목 IDX를 입력하세요.'); return; }
        if (!fromBranch)             { if (SHV.toast) SHV.toast.warn('출발 지사를 선택하세요.'); return; }
        if (!toBranch)               { if (SHV.toast) SHV.toast.warn('도착 지사를 선택하세요.'); return; }
        if (fromBranch === toBranch) { if (SHV.toast) SHV.toast.warn('출발과 도착 지사가 동일합니다.'); return; }
        if (!qty || qty < 1)         { if (SHV.toast) SHV.toast.warn('수량을 1 이상 입력하세요.'); return; }

        var fromName = document.getElementById('stFromBranch').options[document.getElementById('stFromBranch').selectedIndex].text;
        var toName   = document.getElementById('stToBranch').options[document.getElementById('stToBranch').selectedIndex].text;

        shvConfirm({
            type: 'primary',
            title: '창고간 이동',
            message: '<strong>' + fromName + '</strong> → <strong>' + toName + '</strong>으로 수량 <strong>' + qty + '</strong>을 이동하시겠습니까?',
            confirmText: '이동 등록',
        }).then(function (ok) {
            if (!ok) return;
            SHV.api.post('dist_process/saas/Stock.php', {
                todo:             'stock_transfer',
                item_idx:         itemIdx,
                from_branch_idx:  fromBranch,
                to_branch_idx:    toBranch,
                qty:              qty,
                memo:             memo,
            }).then(function (res) {
                if (res.ok) {
                    if (SHV.toast) SHV.toast.success('이동 등록 완료.');
                    SHV.router.navigate('stock_transfer');
                } else {
                    if (SHV.toast) SHV.toast.error(res.message || '이동 등록에 실패했습니다.');
                }
            });
        });
    });

    if (window.shvTblSort) shvTblSort(document.getElementById('stHistoryTable'));

    SHV.pages = SHV.pages || {};
    SHV.pages['stock_transfer'] = { destroy: function () {} };
})();
</script>
