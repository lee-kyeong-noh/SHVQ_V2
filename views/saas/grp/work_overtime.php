<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

function grpOtH(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="work-overtime"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
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

$list = [];
$emps = [];
if ($ready) {
    $list = $service->listOvertime($scope, [
        'employee_idx' => $filterEmpIdx,
        'status'       => $filterStatus,
        'start_date'   => $filterDateS,
        'end_date'     => $filterDateE,
        'limit'        => 200,
    ]);
    $emps = $service->listEmployees($scope, ['limit' => 300]);
}

$statusLabels = [
    'REQUESTED' => ['label' => '신청중', 'badge' => 'badge-warn'],
    'APPROVED'  => ['label' => '승인',   'badge' => 'badge-success'],
    'REJECTED'  => ['label' => '반려',   'badge' => 'badge-danger'],
    'CANCELED'  => ['label' => '취소',   'badge' => 'badge-ghost'],
];

function grpMinutesToHm(int $min): string
{
    if ($min <= 0) return '-';
    $h = (int)($min / 60);
    $m = $min % 60;
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}
?>
<section data-page="work-overtime"
         data-role="<?= $roleLevel ?>"
         data-user-pk="<?= $userPk ?>">
    <div class="page-header">
        <h2 class="page-title" data-title="초과근무">초과근무 관리</h2>
        <p class="page-subtitle">초과근무 신청 · 승인 · 현황</p>
        <div class="page-header-actions">
            <button id="ot-btn-add" class="btn btn-primary btn-sm"><i class="fa fa-plus mr-1"></i>초과근무 신청</button>
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
                    <select id="otEmpSel" class="form-select">
                        <option value="0">전체</option>
                        <?php foreach ($emps as $e): ?>
                        <option value="<?= (int)($e['idx'] ?? 0) ?>" <?= $filterEmpIdx === (int)($e['idx'] ?? 0) ? 'selected' : '' ?>>
                            <?= grpOtH((string)($e['emp_name'] ?? '')) ?>
                            <?php if ($e['dept_name']): ?> (<?= grpOtH((string)($e['dept_name'] ?? '')) ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group mw-120">
                    <label class="form-label">상태</label>
                    <select id="otStatus" class="form-select">
                        <option value="">전체</option>
                        <option value="REQUESTED" <?= $filterStatus === 'REQUESTED' ? 'selected' : '' ?>>신청중</option>
                        <option value="APPROVED"  <?= $filterStatus === 'APPROVED'  ? 'selected' : '' ?>>승인</option>
                        <option value="REJECTED"  <?= $filterStatus === 'REJECTED'  ? 'selected' : '' ?>>반려</option>
                        <option value="CANCELED"  <?= $filterStatus === 'CANCELED'  ? 'selected' : '' ?>>취소</option>
                    </select>
                </div>
                <div class="form-group mw-130">
                    <label class="form-label">시작일</label>
                    <input id="otDateS" type="date" class="form-input" value="<?= grpOtH($filterDateS) ?>">
                </div>
                <div class="form-group mw-130">
                    <label class="form-label">종료일</label>
                    <input id="otDateE" type="date" class="form-input" value="<?= grpOtH($filterDateE) ?>">
                </div>
                <div class="fg-auto">
                    <button id="otSearchBtn" class="btn btn-primary"><i class="fa fa-search"></i> 조회</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 목록 테이블 -->
    <div class="card card-mt card-mb">
        <div class="card-header">
            <span>초과근무 내역</span>
            <span class="card-header-meta">총 <?= count($list) ?>건</span>
        </div>
        <div class="card-body--table">
            <table class="tbl tbl-sticky-header tbl-min-900" id="otTable">
                <colgroup>
                    <col class="col-60"><col class="col-100"><col class="col-120">
                    <col class="col-100"><col class="col-100"><col class="col-70">
                    <col><col class="col-80"><col class="col-110">
                </colgroup>
                <thead>
                    <tr>
                        <th>IDX</th><th>직원</th><th>근무일</th>
                        <th>시작시간</th><th>종료시간</th><th class="th-right">시간</th>
                        <th>사유</th><th class="th-center">상태</th>
                        <?php if ($roleLevel <= 2): ?><th class="th-center">처리</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($list === []): ?>
                    <tr><td colspan="<?= $roleLevel <= 2 ? 9 : 8 ?>">
                        <div class="empty-state"><p class="empty-message">초과근무 내역이 없습니다.</p></div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($list as $row): ?>
                    <?php
                        $st     = (string)($row['status'] ?? 'REQUESTED');
                        $stInfo = $statusLabels[$st] ?? ['label' => $st, 'badge' => 'badge-ghost'];
                        $min    = (int)($row['minutes'] ?? 0);
                        $startTime = (string)($row['start_time'] ?? '');
                        $endTime   = (string)($row['end_time'] ?? '');
                        /* HH:MM 포맷으로 자르기 */
                        if (strlen($startTime) >= 16) $startTime = substr($startTime, 11, 5);
                        if (strlen($endTime) >= 16)   $endTime   = substr($endTime, 11, 5);
                    ?>
                    <tr data-ot-idx="<?= (int)($row['idx'] ?? 0) ?>">
                        <td class="td-muted td-mono"><?= (int)($row['idx'] ?? 0) ?></td>
                        <td class="font-semibold"><?= grpOtH((string)($row['employee_name'] ?? '')) ?></td>
                        <td class="td-nowrap td-mono"><?= grpOtH((string)($row['work_date'] ?? '')) ?></td>
                        <td class="td-mono"><?= grpOtH($startTime) ?></td>
                        <td class="td-mono"><?= grpOtH($endTime) ?></td>
                        <td class="td-num"><?= grpOtH(grpMinutesToHm($min)) ?></td>
                        <td class="td-muted"><?= grpOtH((string)($row['reason'] ?? '')) ?></td>
                        <td class="td-center"><span class="badge <?= $stInfo['badge'] ?>"><?= $stInfo['label'] ?></span></td>
                        <?php if ($roleLevel <= 2): ?>
                        <td class="td-center">
                            <?php if ($st === 'REQUESTED'): ?>
                            <button class="btn btn-outline btn-sm ot-approve-btn" data-idx="<?= (int)($row['idx'] ?? 0) ?>">승인</button>
                            <button class="btn btn-outline btn-sm ot-reject-btn"  data-idx="<?= (int)($row['idx'] ?? 0) ?>">반려</button>
                            <?php elseif ($st === 'APPROVED'): ?>
                            <button class="btn btn-outline btn-sm ot-cancel-btn" data-idx="<?= (int)($row['idx'] ?? 0) ?>">취소</button>
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

    <!-- 초과근무 신청 모달 -->
    <div id="otModal" class="modal-overlay" style="display:none;">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <span>초과근무 신청</span>
                <button class="modal-close" id="otModalClose"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">직원 <span class="text-danger">*</span></label>
                    <select id="otEmpInput" class="form-select">
                        <option value="0">선택</option>
                        <?php foreach ($emps as $e): ?>
                        <option value="<?= (int)($e['idx'] ?? 0) ?>"><?= grpOtH((string)($e['emp_name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">근무일 <span class="text-danger">*</span></label>
                    <input id="otWorkDate" type="date" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">시작시간</label>
                    <input id="otStartTime" type="time" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">종료시간</label>
                    <input id="otEndTime" type="time" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">사유</label>
                    <input id="otReason" type="text" class="form-input" placeholder="초과근무 사유">
                </div>
                <div id="otErr" class="text-danger text-sm mt-1 hidden"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="otCancelBtn">취소</button>
                <button class="btn btn-primary" id="otSaveBtn">신청</button>
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

    var _section = document.querySelector('[data-page="work-overtime"]');
    if (!_section) return;

    var _role   = parseInt(_section.dataset.role || '0', 10);
    var _apiUrl = 'dist_process/saas/Employee.php';
    var _csrf   = (SHV.csrf && SHV.csrf.get) ? SHV.csrf.get() : '';

    /* ── 조회 ── */
    function reload() {
        var params = {
            status: document.getElementById('otStatus').value,
            date_s: document.getElementById('otDateS').value,
            date_e: document.getElementById('otDateE').value,
        };
        var empSel = document.getElementById('otEmpSel');
        if (empSel) params.emp_idx = empSel.value;
        SHV.router.navigate('work_overtime', params);
    }
    document.getElementById('otSearchBtn').addEventListener('click', reload);
    if (window.shvTblSort) shvTblSort(document.getElementById('otTable'));

    /* ── 신청 모달 ── */
    var _modal = document.getElementById('otModal');
    function openModal() {
        document.getElementById('otEmpInput').value = '0';
        document.getElementById('otWorkDate').value = '';
        document.getElementById('otStartTime').value = '';
        document.getElementById('otEndTime').value = '';
        document.getElementById('otReason').value = '';
        document.getElementById('otErr').textContent = '';
        document.getElementById('otErr').classList.add('hidden');
        _modal.style.display = 'flex';
    }
    function closeModal() { _modal.style.display = 'none'; }

    document.getElementById('ot-btn-add').addEventListener('click', openModal);
    document.getElementById('otModalClose').addEventListener('click', closeModal);
    document.getElementById('otCancelBtn').addEventListener('click', closeModal);
    _modal.addEventListener('click', function (e) { if (e.target === _modal) closeModal(); });

    document.getElementById('otSaveBtn').addEventListener('click', function () {
        var empIdx   = parseInt(document.getElementById('otEmpInput').value, 10);
        var workDate = document.getElementById('otWorkDate').value;
        if (!empIdx) { showErr('직원을 선택해주세요.'); return; }
        if (!workDate) { showErr('근무일을 입력해주세요.'); return; }

        var startTime = document.getElementById('otStartTime').value;
        var endTime   = document.getElementById('otEndTime').value;

        SHV.api.post(_apiUrl, {
            csrf_token:   _csrf,
            todo:         'save_overtime',
            employee_idx: empIdx,
            work_date:    workDate,
            start_time:   workDate + (startTime ? 'T' + startTime : ''),
            end_time:     workDate + (endTime   ? 'T' + endTime   : ''),
            reason:       document.getElementById('otReason').value.trim(),
        }).then(function (res) {
            if (!res || !res.ok) { showErr(res && res.message ? res.message : '저장 실패'); return; }
            closeModal();
            SHV.router.navigate('work_overtime');
        }).catch(function () { showErr('서버 오류가 발생했습니다.'); });
    });

    function showErr(msg) {
        var el = document.getElementById('otErr');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    /* ── 승인/반려/취소 (관리자) ── */
    if (_role <= 2) {
        var tbody = document.querySelector('[data-page="work-overtime"] tbody');
        if (tbody) {
            tbody.addEventListener('click', function (e) {
                var approveBtn = e.target.closest('.ot-approve-btn');
                var rejectBtn  = e.target.closest('.ot-reject-btn');
                var cancelBtn  = e.target.closest('.ot-cancel-btn');
                if (approveBtn || rejectBtn || cancelBtn) {
                    var btn   = approveBtn || rejectBtn || cancelBtn;
                    var idx   = parseInt(btn.dataset.idx, 10);
                    var todo  = approveBtn ? 'overtime_approve' : rejectBtn ? 'overtime_reject' : 'overtime_cancel';
                    var label = approveBtn ? '승인' : rejectBtn ? '반려' : '취소';
                    SHV.modal.confirm('해당 초과근무를 ' + label + '처리하시겠습니까?', function (ok) {
                        if (!ok) return;
                        SHV.api.post(_apiUrl, { csrf_token: _csrf, todo: todo, overtime_id: idx })
                            .then(function (res) {
                                if (!res || !res.ok) { SHV.toast.error(label + ' 처리 실패'); return; }
                                SHV.toast.success(label + ' 처리되었습니다.');
                                SHV.router.navigate('work_overtime');
                            });
                    });
                }
            });
        }
    }

    SHV.pages = SHV.pages || {};
    SHV.pages['work_overtime'] = { destroy: function () {} };
})();
</script>
