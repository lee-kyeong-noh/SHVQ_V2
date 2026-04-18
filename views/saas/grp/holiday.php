<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

function grpHolH(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="holiday"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$security  = require __DIR__ . '/../../../config/security.php';
$service   = new GroupwareService(DbConnection::get(), $security);
$scope     = $service->resolveScope($context, '', 0);
$userPk    = (int)($context['user_pk'] ?? 0);
$roleLevel = (int)($context['role_level'] ?? 0);

$missing = $service->missingTables($service->requiredTablesByDomain('employee'));
$ready   = $missing === [];

$filterStatus  = trim((string)($_GET['status'] ?? ''));
$filterDateS   = trim((string)($_GET['date_s'] ?? ''));
$filterDateE   = trim((string)($_GET['date_e'] ?? ''));
$filterEmpIdx  = (int)($_GET['emp_idx'] ?? 0);

$list  = [];
$emps  = [];
if ($ready) {
    $list = $service->listHoliday($scope, [
        'employee_idx' => $filterEmpIdx,
        'status'       => $filterStatus,
        'start_date'   => $filterDateS,
        'end_date'     => $filterDateE,
        'limit'        => 200,
    ]);
    /* 직원 목록 (드롭다운용, 관리자는 전체, 일반은 본인) */
    $emps = $service->listEmployees($scope, ['limit' => 300]);
}

$typeLabels = ['ANNUAL' => '연차', 'HALF' => '반차', 'SPECIAL' => '특별', 'SICK' => '병가', 'ETC' => '기타'];
$statusLabels = [
    'REQUESTED' => ['label' => '신청중', 'badge' => 'badge-warn'],
    'APPROVED'  => ['label' => '승인',   'badge' => 'badge-success'],
    'REJECTED'  => ['label' => '반려',   'badge' => 'badge-danger'],
    'CANCELED'  => ['label' => '취소',   'badge' => 'badge-ghost'],
];
?>
<section data-page="holiday"
         data-role="<?= $roleLevel ?>"
         data-user-pk="<?= $userPk ?>">
    <div class="page-header">
        <h2 class="page-title" data-title="휴가관리">휴가 관리</h2>
        <p class="page-subtitle">휴가 신청 · 승인 · 현황</p>
        <div class="page-header-actions">
            <button id="hol-btn-add" class="btn btn-primary btn-sm"><i class="fa fa-plus mr-1"></i>휴가 신청</button>
        </div>
    </div>

    <?php if (!$ready): ?>
    <div class="card card-mt card-mb">
        <div class="card-body"><div class="empty-state"><p class="empty-message">그룹웨어 DB가 준비되지 않았습니다.</p></div></div>
    </div>
    <?php else: ?>

    <!-- 필터 -->
    <div class="card card-mt">
        <div class="card-body">
            <div class="form-row form-row--filter">
                <?php if ($roleLevel <= 2): ?>
                <div class="form-group mw-140">
                    <label class="form-label">직원</label>
                    <select id="holEmpSel" class="form-select">
                        <option value="0">전체</option>
                        <?php foreach ($emps as $e): ?>
                        <option value="<?= (int)($e['idx'] ?? 0) ?>" <?= $filterEmpIdx === (int)($e['idx'] ?? 0) ? 'selected' : '' ?>>
                            <?= grpHolH((string)($e['emp_name'] ?? '')) ?>
                            <?php if ($e['dept_name']): ?> (<?= grpHolH((string)($e['dept_name'] ?? '')) ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group mw-120">
                    <label class="form-label">상태</label>
                    <select id="holStatus" class="form-select">
                        <option value="">전체</option>
                        <option value="REQUESTED" <?= $filterStatus === 'REQUESTED' ? 'selected' : '' ?>>신청중</option>
                        <option value="APPROVED"  <?= $filterStatus === 'APPROVED'  ? 'selected' : '' ?>>승인</option>
                        <option value="REJECTED"  <?= $filterStatus === 'REJECTED'  ? 'selected' : '' ?>>반려</option>
                        <option value="CANCELED"  <?= $filterStatus === 'CANCELED'  ? 'selected' : '' ?>>취소</option>
                    </select>
                </div>
                <div class="form-group mw-130">
                    <label class="form-label">시작일</label>
                    <input id="holDateS" type="date" class="form-input" value="<?= grpHolH($filterDateS) ?>">
                </div>
                <div class="form-group mw-130">
                    <label class="form-label">종료일</label>
                    <input id="holDateE" type="date" class="form-input" value="<?= grpHolH($filterDateE) ?>">
                </div>
                <div class="fg-auto">
                    <button id="holSearchBtn" class="btn btn-primary"><i class="fa fa-search"></i> 조회</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 목록 테이블 -->
    <div class="card card-mt card-mb">
        <div class="card-header">
            <span>휴가 내역</span>
            <span class="card-header-meta">총 <?= count($list) ?>건</span>
        </div>
        <div class="card-body--table">
            <table class="tbl tbl-sticky-header tbl-min-900" id="holTable">
                <colgroup>
                    <col class="col-60"><col class="col-100"><col class="col-80">
                    <col class="col-120"><col class="col-120"><col>
                    <col class="col-80"><col class="col-110">
                </colgroup>
                <thead>
                    <tr>
                        <th>IDX</th><th>직원</th><th class="th-center">유형</th>
                        <th>시작일</th><th>종료일</th><th>사유</th>
                        <th class="th-center">상태</th>
                        <?php if ($roleLevel <= 2): ?><th class="th-center">처리</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($list === []): ?>
                    <tr><td colspan="<?= $roleLevel <= 2 ? 8 : 7 ?>">
                        <div class="empty-state"><p class="empty-message">휴가 내역이 없습니다.</p></div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($list as $row): ?>
                    <?php
                        $st     = (string)($row['status'] ?? 'REQUESTED');
                        $stInfo = $statusLabels[$st] ?? ['label' => $st, 'badge' => 'badge-ghost'];
                        $type   = (string)($row['holiday_type'] ?? 'ANNUAL');
                        $typeLbl= $typeLabels[$type] ?? $type;
                    ?>
                    <tr data-hol-idx="<?= (int)($row['idx'] ?? 0) ?>" data-hol-status="<?= grpHolH($st) ?>">
                        <td class="td-muted td-mono"><?= (int)($row['idx'] ?? 0) ?></td>
                        <td class="font-semibold"><?= grpHolH((string)($row['employee_name'] ?? '')) ?></td>
                        <td class="td-center"><span class="badge badge-ghost"><?= $typeLbl ?></span></td>
                        <td class="td-nowrap"><?= grpHolH((string)($row['start_date'] ?? '')) ?></td>
                        <td class="td-nowrap"><?= grpHolH((string)($row['end_date'] ?? '')) ?></td>
                        <td class="td-muted"><?= grpHolH((string)($row['reason'] ?? '')) ?></td>
                        <td class="td-center"><span class="badge <?= $stInfo['badge'] ?>"><?= $stInfo['label'] ?></span></td>
                        <?php if ($roleLevel <= 2): ?>
                        <td class="td-center">
                            <?php if ($st === 'REQUESTED'): ?>
                            <button class="btn btn-outline btn-sm hol-approve-btn" data-idx="<?= (int)($row['idx'] ?? 0) ?>">승인</button>
                            <button class="btn btn-outline btn-sm hol-reject-btn"  data-idx="<?= (int)($row['idx'] ?? 0) ?>">반려</button>
                            <?php elseif ($st === 'APPROVED'): ?>
                            <button class="btn btn-outline btn-sm hol-cancel-btn" data-idx="<?= (int)($row['idx'] ?? 0) ?>">취소</button>
                            <?php else: ?>
                            <span class="td-muted text-xs">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 휴가 신청 모달 -->
    <div id="holModal" class="modal-overlay" style="display:none;">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <span>휴가 신청</span>
                <button class="modal-close" id="holModalClose"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">직원 <span class="text-danger">*</span></label>
                    <select id="holEmpInput" class="form-select">
                        <option value="0">선택</option>
                        <?php foreach ($emps as $e): ?>
                        <option value="<?= (int)($e['idx'] ?? 0) ?>"><?= grpHolH((string)($e['emp_name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">휴가 유형</label>
                    <select id="holTypeInput" class="form-select">
                        <option value="ANNUAL">연차</option>
                        <option value="HALF">반차</option>
                        <option value="SPECIAL">특별휴가</option>
                        <option value="SICK">병가</option>
                        <option value="ETC">기타</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">시작일 <span class="text-danger">*</span></label>
                    <input id="holStartInput" type="date" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">종료일 <span class="text-danger">*</span></label>
                    <input id="holEndInput" type="date" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">사유</label>
                    <input id="holReasonInput" type="text" class="form-input" placeholder="휴가 사유">
                </div>
                <div id="holErr" class="text-danger text-sm mt-1 hidden"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="holCancelBtn">취소</button>
                <button class="btn btn-primary" id="holSaveBtn">신청</button>
            </div>
        </div>
    </div>

    <?php endif; ?>
</section>
<style>
.page-header-actions { display: flex; gap: var(--sp-2); }
</style>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="holiday"]');
    if (!_section) return;

    var _role   = parseInt(_section.dataset.role || '0', 10);
    var _apiUrl = 'dist_process/saas/Employee.php';
    var _csrf   = (SHV.csrf && SHV.csrf.get) ? SHV.csrf.get() : '';

    /* ── 조회 ── */
    function reload() {
        var params = {
            status: document.getElementById('holStatus').value,
            date_s: document.getElementById('holDateS').value,
            date_e: document.getElementById('holDateE').value,
        };
        var empSel = document.getElementById('holEmpSel');
        if (empSel) params.emp_idx = empSel.value;
        SHV.router.navigate('holiday', params);
    }
    document.getElementById('holSearchBtn').addEventListener('click', reload);

    if (window.shvTblSort) shvTblSort(document.getElementById('holTable'));

    /* ── 신청 모달 ── */
    var _modal = document.getElementById('holModal');
    function openModal() {
        document.getElementById('holEmpInput').value    = '0';
        document.getElementById('holTypeInput').value   = 'ANNUAL';
        document.getElementById('holStartInput').value  = '';
        document.getElementById('holEndInput').value    = '';
        document.getElementById('holReasonInput').value = '';
        document.getElementById('holErr').textContent   = '';
        document.getElementById('holErr').classList.add('hidden');
        _modal.style.display = 'flex';
    }
    function closeModal() { _modal.style.display = 'none'; }

    document.getElementById('hol-btn-add').addEventListener('click', openModal);
    document.getElementById('holModalClose').addEventListener('click', closeModal);
    document.getElementById('holCancelBtn').addEventListener('click', closeModal);
    _modal.addEventListener('click', function (e) { if (e.target === _modal) closeModal(); });

    document.getElementById('holSaveBtn').addEventListener('click', function () {
        var empIdx = parseInt(document.getElementById('holEmpInput').value, 10);
        var start  = document.getElementById('holStartInput').value;
        var end    = document.getElementById('holEndInput').value;
        if (!empIdx) { showErr('직원을 선택해주세요.'); return; }
        if (!start || !end) { showErr('시작일과 종료일을 입력해주세요.'); return; }
        SHV.api.post(_apiUrl, {
            csrf_token:   _csrf,
            todo:         'save_holiday',
            employee_idx: empIdx,
            holiday_type: document.getElementById('holTypeInput').value,
            start_date:   start,
            end_date:     end,
            reason:       document.getElementById('holReasonInput').value.trim(),
        }).then(function (res) {
            if (!res || !res.ok) { showErr(res && res.message ? res.message : '저장 실패'); return; }
            closeModal();
            SHV.router.navigate('holiday');
        }).catch(function () { showErr('서버 오류가 발생했습니다.'); });
    });

    function showErr(msg) {
        var el = document.getElementById('holErr');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    /* ── 승인/반려/취소 처리 (관리자) ── */
    if (_role <= 2) {
        document.querySelector('tbody') && document.querySelector('tbody').addEventListener('click', function (e) {
            var approveBtn = e.target.closest('.hol-approve-btn');
            var rejectBtn  = e.target.closest('.hol-reject-btn');
            var cancelBtn  = e.target.closest('.hol-cancel-btn');

            if (approveBtn || rejectBtn || cancelBtn) {
                var btn    = approveBtn || rejectBtn || cancelBtn;
                var idx    = parseInt(btn.dataset.idx, 10);
                var todo   = approveBtn ? 'holiday_approve' : rejectBtn ? 'holiday_reject' : 'holiday_cancel';
                var label  = approveBtn ? '승인' : rejectBtn ? '반려' : '취소';
                SHV.modal.confirm('해당 휴가를 ' + label + '처리하시겠습니까?', function (ok) {
                    if (!ok) return;
                    SHV.api.post(_apiUrl, { csrf_token: _csrf, todo: todo, holiday_id: idx })
                        .then(function (res) {
                            if (!res || !res.ok) { SHV.toast.error(label + ' 처리 실패'); return; }
                            SHV.toast.success(label + ' 처리되었습니다.');
                            SHV.router.navigate('holiday');
                        });
                });
            }
        });
    }

    SHV.pages = SHV.pages || {};
    SHV.pages['holiday'] = { destroy: function () {} };
})();
</script>
