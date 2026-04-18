<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/erp/StockService.php';

function matStockOutH(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="mat-stock-out"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
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

// Tb_Site 존재 여부 확인
$_ts = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='Tb_Site'");
$hasSite = $_ts && (int)$_ts->fetchColumn() > 0;
unset($_ts);
$siteJoin   = $hasSite ? "LEFT JOIN Tb_Site s ON l.site_idx = s.idx" : '';
$siteSelect = $hasSite ? "ISNULL(s.name,'') AS site_name," : "'' AS site_name,";

$recentRows = [];
try {
    $stmt = $db->query(
        "SELECT TOP (100)
            l.idx, l.stock_type, l.item_idx,
            ISNULL(i.item_code,'') AS item_code,
            ISNULL(i.name,'') AS item_name,
            ISNULL(d.{$deptCol},'') AS branch_name,
            {$siteSelect}
            l.qty,
            ISNULL(l.before_qty,0) AS before_qty,
            ISNULL(l.after_qty,0)  AS after_qty,
            ISNULL(l.memo,'')      AS memo,
            ISNULL(l.emp_name,'')  AS emp_name,
            l.regdate
         FROM Tb_StockLog l
         LEFT JOIN Tb_Item i ON l.item_idx = i.idx
         LEFT JOIN Tb_Department d ON l.branch_idx = d.idx
         {$siteJoin}
         WHERE l.stock_type IN (2,3)
         ORDER BY l.idx DESC"
    );
    $recentRows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) { $recentRows = []; }
?>
<section data-page="mat-stock-out">
    <div class="page-header">
        <h2 class="page-title" data-title="출고관리">출고관리</h2>
        <p class="page-subtitle">품목 출고 등록 및 최근 출고/이동출 이력 조회</p>
    </div>

    <!-- 출고 등록 폼 -->
    <div class="card card-mt">
        <div class="card-header">
            <span><i class="fa fa-arrow-up icon-danger"></i>출고 등록</span>
        </div>
        <div class="card-body">
            <div class="form-row form-row--filter">
                <div class="form-group mw-110">
                    <label class="form-label">품목 IDX <span class="required">*</span></label>
                    <input id="soItemIdx" type="number" class="form-input" placeholder="품목 IDX" min="1">
                </div>
                <div class="form-group mw-140">
                    <label class="form-label">출고 창고 <span class="required">*</span></label>
                    <select id="soBranchIdx" class="form-select">
                        <option value="">선택</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int)($branch['idx'] ?? 0) ?>"><?= matStockOutH((string)($branch['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mw-110">
                    <label class="form-label">현장 IDX</label>
                    <input id="soSiteIdx" type="number" class="form-input" placeholder="현장 IDX" min="0">
                </div>
                <div class="form-group mw-90">
                    <label class="form-label">수량 <span class="required">*</span></label>
                    <input id="soQty" type="number" class="form-input" placeholder="수량" min="1">
                </div>
                <div class="form-group fg-2 mw-140">
                    <label class="form-label">메모</label>
                    <input id="soMemo" type="text" class="form-input" placeholder="메모 (선택)" maxlength="500">
                </div>
                <div class="fg-auto">
                    <button id="soBtnSubmit" class="btn btn-danger"><i class="fa fa-check"></i> 출고 등록</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 최근 이력 테이블 -->
    <div class="card card-mb">
        <div class="card-header">
            <span>최근 이력 (출고/이동출)</span>
        </div>
        <div class="card-body--table">
            <table class="tbl tbl-sticky-header" id="soHistoryTable">
                <thead>
                    <tr>
                        <th class="col-70">로그 IDX</th>
                        <th class="col-140">일시</th>
                        <th class="col-72 th-center">유형</th>
                        <th class="col-110">자재번호</th>
                        <th>품목명</th>
                        <th class="col-100">지사</th>
                        <th class="col-100">현장</th>
                        <th class="col-70 th-right">수량</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recentRows === []): ?>
                        <tr><td colspan="8"><div class="empty-state"><p class="empty-message">출고 이력이 없습니다.</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($recentRows as $row): ?>
                            <tr
                                data-stock-log-idx="<?= (int)($row['idx'] ?? 0) ?>"
                                data-stock-type="<?= (int)($row['stock_type'] ?? 0) ?>"
                                data-site-idx="<?= (int)($row['site_idx'] ?? 0) ?>"
                            >
                                <td class="td-muted td-mono"><?= (int)($row['idx'] ?? 0) ?></td>
                                <td class="td-nowrap td-muted"><?= matStockOutH((string)($row['regdate'] ?? '')) ?></td>
                                <td class="td-center">
                                    <span class="badge <?= ((int)($row['stock_type'] ?? 0) === 2) ? 'badge-danger' : 'badge-warn' ?>">
                                        <?= ((int)($row['stock_type'] ?? 0) === 2) ? '출고' : '이동(출)' ?>
                                    </span>
                                </td>
                                <td class="td-mono td-nowrap"><?= matStockOutH((string)($row['item_code'] ?? '')) ?></td>
                                <td><?= matStockOutH((string)($row['item_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matStockOutH((string)($row['branch_name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matStockOutH((string)($row['site_name'] ?? '')) ?></td>
                                <td class="td-num text-danger">-<?= number_format((int)($row['qty'] ?? 0)) ?></td>
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

    document.getElementById('soBtnSubmit').addEventListener('click', function () {
        var itemIdx   = parseInt(document.getElementById('soItemIdx').value, 10);
        var branchIdx = parseInt(document.getElementById('soBranchIdx').value, 10);
        var siteIdx   = parseInt(document.getElementById('soSiteIdx').value, 10) || 0;
        var qty       = parseInt(document.getElementById('soQty').value, 10);
        var memo      = document.getElementById('soMemo').value.trim();

        if (!itemIdx || itemIdx < 1) { if (SHV.toast) SHV.toast.warn('품목 IDX를 입력하세요.'); return; }
        if (!branchIdx)              { if (SHV.toast) SHV.toast.warn('출고 창고를 선택하세요.'); return; }
        if (!qty || qty < 1)         { if (SHV.toast) SHV.toast.warn('수량을 1 이상 입력하세요.'); return; }

        shvConfirm({
            type: 'danger',
            title: '출고 등록',
            message: '수량 <strong>' + qty + '</strong>을 출고하시겠습니까?',
            confirmText: '출고 등록',
        }).then(function (ok) {
            if (!ok) return;
            SHV.api.post('dist_process/saas/Stock.php', {
                todo: 'stock_out',
                item_idx:   itemIdx,
                branch_idx: branchIdx,
                site_idx:   siteIdx,
                qty:        qty,
                memo:       memo,
            }).then(function (res) {
                if (res.ok) {
                    if (SHV.toast) SHV.toast.success('출고 등록 완료.');
                    SHV.router.navigate('stock_out');
                } else {
                    if (SHV.toast) SHV.toast.error(res.message || '출고 등록에 실패했습니다.');
                }
            });
        });
    });

    if (window.shvTblSort) shvTblSort(document.getElementById('soHistoryTable'));

    SHV.pages = SHV.pages || {};
    SHV.pages['stock_out'] = { destroy: function () {} };
})();
</script>
