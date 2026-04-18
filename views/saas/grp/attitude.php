<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

function grpAttH(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="attitude"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$security  = require __DIR__ . '/../../../config/security.php';
$service   = new GroupwareService(DbConnection::get(), $security);
$scope     = $service->resolveScope($context, '', 0);
$userPk    = (int)($context['user_pk'] ?? 0);
$roleLevel = (int)($context['role_level'] ?? 0);

$missing = $service->missingTables($service->requiredTablesByDomain('employee'));
$ready   = $missing === [];

$filterDateS  = trim((string)($_GET['date_s'] ?? date('Y-m-01')));
$filterDateE  = trim((string)($_GET['date_e'] ?? date('Y-m-d')));
$filterEmpIdx = (int)($_GET['emp_idx'] ?? 0);

$list = [];
$emps = [];
if ($ready) {
    $list = $service->listAttendance($scope, [
        'employee_idx' => $filterEmpIdx,
        'start_date'   => $filterDateS,
        'end_date'     => $filterDateE,
        'limit'        => 500,
    ]);
    $emps = $service->listEmployees($scope, ['limit' => 300]);
}

$statusLabels = [
    'NORMAL'  => ['label' => '정상', 'badge' => 'badge-success'],
    'LATE'    => ['label' => '지각', 'badge' => 'badge-warn'],
    'EARLY'   => ['label' => '조퇴', 'badge' => 'badge-warn'],
    'ABSENT'  => ['label' => '결근', 'badge' => 'badge-danger'],
    'HOLIDAY' => ['label' => '휴가', 'badge' => 'badge-info'],
];

function grpMinToHm(int $min): string
{
    if ($min <= 0) return '-';
    return sprintf('%dh %02dm', (int)($min / 60), $min % 60);
}
?>
<section data-page="attitude"
         data-role="<?= $roleLevel ?>">
    <div class="page-header">
        <h2 class="page-title" data-title="근태현황">근태 현황</h2>
        <p class="page-subtitle">출퇴근 · 근무시간 조회</p>
        <?php if ($roleLevel <= 2): ?>
        <div class="page-header-actions">
            <button id="att-btn-add" class="btn btn-primary btn-sm"><i class="fa fa-plus mr-1"></i>근태 기록</button>
        </div>
        <?php endif; ?>
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
                    <select id="attEmpSel" class="form-select">
                        <option value="0">전체</option>
                        <?php foreach ($emps as $e): ?>
                        <option value="<?= (int)($e['idx'] ?? 0) ?>" <?= $filterEmpIdx === (int)($e['idx'] ?? 0) ? 'selected' : '' ?>>
                            <?= grpAttH((string)($e['emp_name'] ?? '')) ?>
                            <?php if ($e['dept_name']): ?> (<?= grpAttH((string)($e['dept_name'] ?? '')) ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group mw-130">
                    <label class="form-label">시작일</label>
                    <input id="attDateS" type="date" class="form-input" value="<?= grpAttH($filterDateS) ?>">
                </div>
                <div class="form-group mw-130">
                    <label class="form-label">종료일</label>
                    <input id="attDateE" type="date" class="form-input" value="<?= grpAttH($filterDateE) ?>">
                </div>
                <div class="fg-auto">
                    <button id="attSearchBtn" class="btn btn-primary"><i class="fa fa-search"></i> 조회</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 요약 -->
    <?php if ($list !== []): ?>
    <?php
        $totalMin   = array_sum(array_column($list, 'work_minutes'));
        $totalDays  = count($list);
        $normalDays = count(array_filter($list, fn($r) => (string)($r['status'] ?? '') === 'NORMAL'));
    ?>
    <div class="att-summary card-mt">
        <div class="card att-sum-card">
            <div class="att-sum-label">조회 일수</div>
            <div class="att-sum-val"><?= $totalDays ?>일</div>
        </div>
        <div class="card att-sum-card">
            <div class="att-sum-label">정상 출근</div>
            <div class="att-sum-val text-success"><?= $normalDays ?>일</div>
        </div>
        <div class="card att-sum-card">
            <div class="att-sum-label">총 근무시간</div>
            <div class="att-sum-val"><?= grpMinToHm($totalMin) ?></div>
        </div>
        <div class="card att-sum-card">
            <div class="att-sum-label">일 평균</div>
            <div class="att-sum-val"><?= $totalDays > 0 ? grpMinToHm((int)($totalMin / $totalDays)) : '-' ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 근태 테이블 -->
    <div class="card card-mt card-mb">
        <div class="card-header">
            <span>근태 내역</span>
            <span class="card-header-meta">총 <?= count($list) ?>건</span>
        </div>
        <div class="card-body--table">
            <table class="tbl tbl-sticky-header tbl-min-800" id="attTable">
                <colgroup>
                    <col class="col-60"><col class="col-100"><col class="col-120">
                    <col class="col-100"><col class="col-100"><col class="col-80">
                    <col class="col-80"><col>
                </colgroup>
                <thead>
                    <tr>
                        <th>IDX</th><th>직원</th><th>근무일</th>
                        <th>출근</th><th>퇴근</th><th class="th-right">근무시간</th>
                        <th class="th-center">상태</th><th>메모</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($list === []): ?>
                    <tr><td colspan="8">
                        <div class="empty-state"><p class="empty-message">근태 데이터가 없습니다.</p></div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($list as $row): ?>
                    <?php
                        $st     = (string)($row['status'] ?? 'NORMAL');
                        $stInfo = $statusLabels[$st] ?? ['label' => $st, 'badge' => 'badge-ghost'];
                        $min    = (int)($row['work_minutes'] ?? 0);
                        /* HH:MM 포맷 */
                        $checkIn  = (string)($row['check_in'] ?? '');
                        $checkOut = (string)($row['check_out'] ?? '');
                        if (strlen($checkIn)  >= 16) $checkIn  = substr($checkIn, 11, 5);
                        if (strlen($checkOut) >= 16) $checkOut = substr($checkOut, 11, 5);
                    ?>
                    <tr data-att-idx="<?= (int)($row['idx'] ?? 0) ?>">
                        <td class="td-muted td-mono"><?= (int)($row['idx'] ?? 0) ?></td>
                        <td class="font-semibold"><?= grpAttH((string)($row['employee_name'] ?? '')) ?></td>
                        <td class="td-nowrap td-mono"><?= grpAttH((string)($row['work_date'] ?? '')) ?></td>
                        <td class="td-mono"><?= grpAttH($checkIn) ?></td>
                        <td class="td-mono"><?= grpAttH($checkOut) ?></td>
                        <td class="td-num"><?= grpAttH(grpMinToHm($min)) ?></td>
                        <td class="td-center"><span class="badge <?= $stInfo['badge'] ?>"><?= $stInfo['label'] ?></span></td>
                        <td class="td-muted"><?= grpAttH((string)($row['note'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 근태 기록 모달 (관리자) -->
    <?php if ($roleLevel <= 2): ?>
    <div id="attModal" class="modal-overlay" style="display:none;">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <span>근태 기록</span>
                <button class="modal-close" id="attModalClose"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">직원 <span class="text-danger">*</span></label>
                    <select id="attEmpInput" class="form-select">
                        <option value="0">선택</option>
                        <?php foreach ($emps as $e): ?>
                        <option value="<?= (int)($e['idx'] ?? 0) ?>"><?= grpAttH((string)($e['emp_name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">근무일 <span class="text-danger">*</span></label>
                    <input id="attWorkDate" type="date" class="form-input" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">출근시간</label>
                    <input id="attCheckIn" type="time" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">퇴근시간</label>
                    <input id="attCheckOut" type="time" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">상태</label>
                    <select id="attStatusInput" class="form-select">
                        <option value="NORMAL">정상</option>
                        <option value="LATE">지각</option>
                        <option value="EARLY">조퇴</option>
                        <option value="ABSENT">결근</option>
                        <option value="HOLIDAY">휴가</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">메모</label>
                    <input id="attNote" type="text" class="form-input" placeholder="메모">
                </div>
                <div id="attErr" class="text-danger text-sm mt-1 hidden"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="attCancelBtn">취소</button>
                <button class="btn btn-primary" id="attSaveBtn">저장</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</section>
<style>
.page-header-actions { display: flex; gap: var(--sp-2); }
.att-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--sp-3);
    padding: 0 var(--sp-5);
}
.att-sum-card {
    padding: var(--sp-4);
    text-align: center;
}
.att-sum-label { font-size: 12px; color: var(--text-3); margin-bottom: var(--sp-1); }
.att-sum-val   { font-size: 22px; font-weight: 700; color: var(--text-1); }
.text-success  { color: #22c55e; }
@media (max-width: 768px) {
    .att-summary { grid-template-columns: repeat(2, 1fr); padding: 0 var(--sp-3); }
}
</style>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="attitude"]');
    if (!_section) return;

    var _role   = parseInt(_section.dataset.role || '0', 10);
    var _apiUrl = 'dist_process/saas/Employee.php';
    var _csrf   = (SHV.csrf && SHV.csrf.get) ? SHV.csrf.get() : '';

    /* ── 조회 ── */
    function reload() {
        var params = {
            date_s: document.getElementById('attDateS').value,
            date_e: document.getElementById('attDateE').value,
        };
        var empSel = document.getElementById('attEmpSel');
        if (empSel) params.emp_idx = empSel.value;
        SHV.router.navigate('attitude', params);
    }
    document.getElementById('attSearchBtn').addEventListener('click', reload);
    if (window.shvTblSort) shvTblSort(document.getElementById('attTable'));

    /* ── 근태 기록 모달 (관리자) ── */
    if (_role <= 2) {
        var _modal = document.getElementById('attModal');
        function openModal() {
            document.getElementById('attEmpInput').value    = '0';
            document.getElementById('attWorkDate').value    = new Date().toISOString().slice(0, 10);
            document.getElementById('attCheckIn').value     = '';
            document.getElementById('attCheckOut').value    = '';
            document.getElementById('attStatusInput').value = 'NORMAL';
            document.getElementById('attNote').value        = '';
            document.getElementById('attErr').textContent   = '';
            document.getElementById('attErr').classList.add('hidden');
            _modal.style.display = 'flex';
        }
        function closeModal() { _modal.style.display = 'none'; }

        document.getElementById('att-btn-add').addEventListener('click', openModal);
        document.getElementById('attModalClose').addEventListener('click', closeModal);
        document.getElementById('attCancelBtn').addEventListener('click', closeModal);
        _modal.addEventListener('click', function (e) { if (e.target === _modal) closeModal(); });

        document.getElementById('attSaveBtn').addEventListener('click', function () {
            var empIdx   = parseInt(document.getElementById('attEmpInput').value, 10);
            var workDate = document.getElementById('attWorkDate').value;
            if (!empIdx) { showErr('직원을 선택해주세요.'); return; }
            if (!workDate) { showErr('근무일을 입력해주세요.'); return; }
            var startTime = document.getElementById('attCheckIn').value;
            var endTime   = document.getElementById('attCheckOut').value;
            SHV.api.post(_apiUrl, {
                csrf_token:   _csrf,
                todo:         'attendance_save',
                employee_idx: empIdx,
                work_date:    workDate,
                check_in:     workDate + (startTime ? 'T' + startTime : ''),
                check_out:    workDate + (endTime   ? 'T' + endTime   : ''),
                status:       document.getElementById('attStatusInput').value,
                note:         document.getElementById('attNote').value.trim(),
            }).then(function (res) {
                if (!res || !res.ok) { showErr(res && res.message ? res.message : '저장 실패'); return; }
                closeModal();
                SHV.router.navigate('attitude');
            }).catch(function () { showErr('서버 오류가 발생했습니다.'); });
        });

        function showErr(msg) {
            var el = document.getElementById('attErr');
            el.textContent = msg;
            el.classList.remove('hidden');
        }
    }

    SHV.pages = SHV.pages || {};
    SHV.pages['attitude'] = { destroy: function () {} };
})();
</script>
