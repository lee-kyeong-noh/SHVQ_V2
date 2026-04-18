<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

function grpEdH(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$auth    = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="emp-detail"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$security  = require __DIR__ . '/../../../config/security.php';
$service   = new GroupwareService(DbConnection::get(), $security);
$scope     = $service->resolveScope($context, '', 0);
$userPk    = (int)($context['user_pk'] ?? 0);
$roleLevel = (int)($context['role_level'] ?? 0);
$isAdmin   = ($roleLevel > 0 && $roleLevel <= 4);

$missing = $service->missingTables($service->requiredTablesByDomain('employee'));
$ready   = $missing === [];

$empIdx = (int)($_GET['idx'] ?? 0);
if ($empIdx <= 0 || !$ready) {
    echo '<section data-page="emp-detail"><div class="empty-state"><p class="empty-message">직원을 선택해주세요.</p>';
    echo '<button class="btn btn-outline btn-sm" onclick="SHV.router.navigate(\'org_chart\')"><i class="fa fa-arrow-left"></i> 조직도로 돌아가기</button>';
    echo '</div></section>';
    exit;
}

$emp = $service->getEmployeeById($scope, $empIdx);
if ($emp === null) {
    echo '<section data-page="emp-detail"><div class="empty-state"><p class="empty-message">직원 정보를 찾을 수 없습니다.</p>';
    echo '<button class="btn btn-outline btn-sm" onclick="SHV.router.navigate(\'org_chart\')"><i class="fa fa-arrow-left"></i> 조직도로 돌아가기</button>';
    echo '</div></section>';
    exit;
}

$depts = $service->listDepartments($scope);

/* ── 헬퍼 ── */
$v = function (string $k) use ($emp): string {
    return grpEdH((string)($emp[$k] ?? ''));
};
$vn = function (string $k) use ($emp): string {
    $n = (int)($emp[$k] ?? 0);
    return $n > 0 ? number_format($n) : '-';
};

$statusLabels = ['ACTIVE' => '재직', 'RESIGNED' => '퇴사', 'LEAVE' => '휴직', 'INACTIVE' => '퇴사'];
$statusBadge  = ['ACTIVE' => 'badge-success', 'RESIGNED' => 'badge-danger', 'LEAVE' => 'badge-warn', 'INACTIVE' => 'badge-danger'];
$empStatus    = (string)($emp['status'] ?? 'ACTIVE');

/* ── 인라인 편집 헬퍼 ── */
function edField(string $viewHtml, string $editHtml): void {
    echo '<span class="ed-sv">' . $viewHtml . '</span>';
    echo '<span class="ed-se" style="display:none">' . $editHtml . '</span>';
}

/* ── 카드 용도 색상 ── */
$cardUsageColors = [
    '통합'     => 'badge-info',
    '주유'     => 'badge-warn',
    '복리'     => 'badge-success',
    '하이패스' => 'badge-purple',
];
?>
<section data-page="emp-detail"
         data-role="<?= $roleLevel ?>"
         data-emp-idx="<?= $empIdx ?>"
         data-scope-code="<?= grpEdH((string)($scope['service_code'] ?? '')) ?>"
         data-scope-tenant="<?= (int)($scope['tenant_id'] ?? 0) ?>">

    <!-- ════════ 프로필 헤더 ════════ -->
    <div class="card ed-profile-card">
        <div class="ed-photo-wrap" id="edPhotoWrap" title="사진 변경">
            <?php if (!empty($emp['photo_url'])): ?>
                <img class="ed-photo" id="edPhoto" src="<?= grpEdH($emp['photo_url']) ?>" alt="프로필" onerror="this.parentNode.innerHTML='<div class=\'ed-photo-def\'><i class=\'fa fa-user\'></i></div>'">
            <?php else: ?>
                <div class="ed-photo-def"><i class="fa fa-user"></i></div>
            <?php endif; ?>
            <input type="file" id="edPhotoFile" accept="image/*" class="hidden">
        </div>

        <div class="ed-profile-info">
            <div class="ed-profile-name" id="edName">
                <?= $v('emp_name') ?>
                <?php if (!empty($emp['position_name'])): ?>
                    <span class="badge badge-info"><?= $v('position_name') ?></span>
                <?php endif; ?>
                <?php if (!empty($emp['job_title'])): ?>
                    <span class="badge badge-warn"><?= $v('job_title') ?></span>
                <?php endif; ?>
            </div>
            <div class="ed-profile-meta" id="edMeta">
                <span><?= $v('emp_no') ?></span>
                <span><?= $v('dept_name') ?></span>
                <?php if (!empty($emp['hire_date']) && $emp['hire_date'] > '1900'): ?>
                    <span>입사 <?= grpEdH(substr((string)$emp['hire_date'], 0, 10)) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="ed-profile-actions">
            <button class="btn btn-ghost btn-sm" id="edBackBtn" type="button"><i class="fa fa-arrow-left"></i> 목록</button>
            <button class="btn btn-primary btn-sm" id="edEditBtn" type="button"><i class="fa fa-pencil"></i> 수정</button>
            <button class="btn btn-sm ed-btn-save hidden" id="edSaveBtn" type="button"><i class="fa fa-save"></i> 저장</button>
            <button class="btn btn-outline btn-sm hidden" id="edCancelBtn" type="button"><i class="fa fa-times"></i> 취소</button>
            <?php if ($isAdmin): ?>
                <button class="btn btn-danger btn-sm btn-ghost hidden" id="edDelBtn" type="button" title="삭제"><i class="fa fa-trash"></i></button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ════════ 기본정보 ════════ -->
    <div class="ed-section-title"><i class="fa fa-user"></i> 기본정보</div>
    <div class="ed-grid">
        <div class="ed-row">
            <div class="ed-label">성명</div>
            <div class="ed-value"><?php edField($v('emp_name'), '<input class="form-input" type="text" id="ed_emp_name" value="' . $v('emp_name') . '">'); ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">사번</div>
            <div class="ed-value"><?php edField($v('emp_no'), '<input class="form-input" type="text" id="ed_emp_no" value="' . $v('emp_no') . '">'); ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">직급</div>
            <div class="ed-value"><?php edField($v('position_name'), '<input class="form-input" type="text" id="ed_position_name" value="' . $v('position_name') . '">'); ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">직책</div>
            <div class="ed-value"><?php edField($v('job_title'), '<input class="form-input" type="text" id="ed_job_title" value="' . $v('job_title') . '">'); ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">부서</div>
            <div class="ed-value">
                <?php
                $deptView = $v('dept_name') ?: '-';
                $deptIdx  = (int)($emp['dept_idx'] ?? 0);
                $deptEdit = '<div class="ed-dd-wrap">'
                    . '<input type="hidden" id="ed_dept_idx_val" value="' . $deptIdx . '">'
                    . '<input class="form-input ed-dd-input" type="text" id="ed_dept_idx_input" value="' . grpEdH($deptView === '-' ? '' : $deptView) . '" autocomplete="off"'
                    . ' onfocus="edDdOpen(\'ed_dept_idx\')" oninput="edDdFilter(\'ed_dept_idx\')" onkeydown="edDdKey(event,\'ed_dept_idx\')">'
                    . '<div class="ed-dd-list" id="ed_dept_idx_list">';
                foreach ($depts as $d) {
                    $di = (int)($d['idx'] ?? 0);
                    $dn = grpEdH((string)($d['dept_name'] ?? ''));
                    $sel = ($di === $deptIdx) ? ' ed-dd-sel' : '';
                    $deptEdit .= '<div class="ed-dd-opt' . $sel . '" data-v="' . $di . '" data-n="' . mb_strtolower($dn) . '" onclick="edDdPick(\'ed_dept_idx\',' . $di . ',\'' . $dn . '\')">' . $dn . '</div>';
                }
                $deptEdit .= '</div></div>';
                edField($deptView, $deptEdit);
                ?>
            </div>
        </div>
        <div class="ed-row">
            <div class="ed-label">고용형태</div>
            <div class="ed-value"><?php edField($v('employment_type') ?: '-', '<input class="form-input" type="text" id="ed_employment_type" value="' . $v('employment_type') . '">'); ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">재직상태</div>
            <div class="ed-value">
                <?php
                $stView = '<span class="badge ' . ($statusBadge[$empStatus] ?? 'badge-ghost') . '">' . ($statusLabels[$empStatus] ?? $empStatus) . '</span>';
                $stEdit = '<select class="form-select" id="ed_status">'
                    . '<option value="ACTIVE"' . ($empStatus === 'ACTIVE' ? ' selected' : '') . '>재직</option>'
                    . '<option value="RESIGNED"' . ($empStatus === 'RESIGNED' ? ' selected' : '') . '>퇴사</option>'
                    . '<option value="LEAVE"' . ($empStatus === 'LEAVE' ? ' selected' : '') . '>휴직</option>'
                    . '</select>';
                edField($stView, $stEdit);
                ?>
            </div>
        </div>
        <div class="ed-row">
            <div class="ed-label">생년월일</div>
            <div class="ed-value"><?php
                $bd = $v('social_number');
                edField($bd ?: '-', '<input class="form-input" type="text" id="ed_social_number" value="' . $bd . '" maxlength="6" placeholder="YYMMDD">');
            ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">입사일</div>
            <div class="ed-value"><?php
                $hd = (!empty($emp['hire_date']) && $emp['hire_date'] > '1900') ? substr((string)$emp['hire_date'], 0, 10) : '';
                edField($hd ?: '-', '<input class="form-input" type="date" id="ed_hire_date" value="' . grpEdH($hd) . '">');
            ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">퇴사일</div>
            <div class="ed-value"><?php
                $ld = (!empty($emp['leave_date']) && $emp['leave_date'] > '1900') ? substr((string)$emp['leave_date'], 0, 10) : '';
                edField($ld ?: '-', '<input class="form-input" type="date" id="ed_leave_date" value="' . grpEdH($ld) . '">');
            ?></div>
        </div>
    </div>

    <!-- ════════ 연락처 ════════ -->
    <div class="ed-section-title"><i class="fa fa-phone"></i> 연락처</div>
    <div class="ed-grid">
        <div class="ed-row">
            <div class="ed-label">H.P</div>
            <div class="ed-value"><?php
                $hp = $v('phone');
                edField($hp ? '<a href="tel:' . $hp . '">' . $hp . '</a>' : '-', '<input class="form-input" type="tel" id="ed_phone" value="' . $hp . '" maxlength="13">');
            ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">TEL</div>
            <div class="ed-value"><?php
                $tel = $v('tel');
                edField($tel ? '<a href="tel:' . $tel . '">' . $tel . '</a>' : '-', '<input class="form-input" type="tel" id="ed_tel" value="' . $tel . '" maxlength="13">');
            ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">E-mail</div>
            <div class="ed-value"><?php
                $em = $v('email');
                edField($em ? '<a href="mailto:' . $em . '">' . $em . '</a>' : '-', '<input class="form-input" type="email" id="ed_email" value="' . $em . '">');
            ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">개인메일</div>
            <div class="ed-value"><?php
                $pe = $v('personal_email');
                edField($pe ? '<a href="mailto:' . $pe . '">' . $pe . '</a>' : '-', '<input class="form-input" type="email" id="ed_personal_email" value="' . $pe . '">');
            ?></div>
        </div>
        <div class="ed-row ed-row--full">
            <div class="ed-label">주소</div>
            <div class="ed-value"><?php
                $addr = $v('address');
                edField($addr ?: '-', '<input class="form-input" type="text" id="ed_address" value="' . $addr . '">');
            ?></div>
        </div>
    </div>

    <!-- ════════ 상세정보 ════════ -->
    <div class="ed-section-title"><i class="fa fa-info-circle"></i> 상세정보</div>
    <div class="ed-grid">
        <div class="ed-row">
            <div class="ed-label">업무구분</div>
            <div class="ed-value"><?php edField($v('work_gubun') ?: '-', '<input class="form-input" type="text" id="ed_work_gubun" value="' . $v('work_gubun') . '">'); ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">자격증</div>
            <div class="ed-value"><?php edField($v('license') ?: '-', '<input class="form-input" type="text" id="ed_license" value="' . $v('license') . '">'); ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">경력수첩</div>
            <div class="ed-value"><?php edField($v('career_note') ?: '-', '<input class="form-input" type="text" id="ed_career_note" value="' . $v('career_note') . '">'); ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">마지막진급</div>
            <div class="ed-value"><?php
                $lp = (!empty($emp['last_promotion']) && $emp['last_promotion'] > '1900') ? substr((string)$emp['last_promotion'], 0, 10) : '';
                edField($lp ?: '-', '<input class="form-input" type="date" id="ed_last_promotion" value="' . grpEdH($lp) . '">');
            ?></div>
        </div>
        <div class="ed-row ed-row--full">
            <div class="ed-label">비고</div>
            <div class="ed-value"><?php
                $memo = $v('emp_memo');
                edField($memo ?: '-', '<textarea class="form-input" id="ed_emp_memo" rows="3">' . $memo . '</textarea>');
            ?></div>
        </div>
    </div>

<?php if ($isAdmin): ?>
    <!-- ════════ 급여정보 (관리자) ════════ -->
    <div class="ed-section-title"><i class="fa fa-money"></i> 급여정보</div>
    <div class="ed-grid ed-grid--3">
        <?php
        $salaryFields = [
            'salary_basic'        => '기본급',
            'salary_qualification'=> '자격수당',
            'salary_part_position'=> '직책수당',
            'salary_position'     => '근속수당',
            'salary_overtime_fix' => '연장(고정)',
            'salary_work'         => '근무수당',
            'salary_meal'         => '식대',
            'salary_car'          => '자가운전',
            'salary_etc'          => '기타',
        ];
        foreach ($salaryFields as $fk => $fl): ?>
            <div class="ed-row">
                <div class="ed-label"><?= $fl ?></div>
                <div class="ed-value"><?php
                    $sv = $vn($fk);
                    edField($sv !== '-' ? $sv . '원' : '-', '<input class="form-input" type="number" id="ed_' . $fk . '" value="' . (int)($emp[$fk] ?? 0) . '">');
                ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ════════ 결제정보 (관리자) ════════ -->
    <div class="ed-section-title"><i class="fa fa-bank"></i> 결제정보</div>
    <div class="ed-grid">
        <div class="ed-row">
            <div class="ed-label">은행명</div>
            <div class="ed-value"><?php edField($v('bank_name') ?: '-', '<input class="form-input" type="text" id="ed_bank_name" value="' . $v('bank_name') . '">'); ?></div>
        </div>
        <div class="ed-row">
            <div class="ed-label">예금주</div>
            <div class="ed-value"><?php edField($v('bank_depositor') ?: '-', '<input class="form-input" type="text" id="ed_bank_depositor" value="' . $v('bank_depositor') . '">'); ?></div>
        </div>
        <div class="ed-row ed-row--full">
            <div class="ed-label">계좌번호</div>
            <div class="ed-value"><?php edField($v('bank_account') ?: '-', '<input class="form-input" type="text" id="ed_bank_account" value="' . $v('bank_account') . '" style="font-family:monospace">'); ?></div>
        </div>
    </div>

    <!-- ════════ 카드정보 (관리자 — 다중 카드 CRUD) ════════ -->
    <div class="ed-section-title">
        <span><i class="fa fa-credit-card"></i> 카드정보</span>
        <button class="btn btn-outline btn-xs" id="edCardAddBtn" type="button"><i class="fa fa-plus"></i> 추가</button>
    </div>
    <div class="ed-card-table-wrap">
        <table class="tbl tbl-sticky-header" id="edCardTbl">
            <colgroup>
                <col class="col-40">
                <col class="col-80">
                <col>
                <col>
                <col>
                <col class="col-80">
            </colgroup>
            <thead>
                <tr><th class="th-center">No</th><th>용도</th><th>카드명</th><th>카드번호</th><th>비고</th><th></th></tr>
            </thead>
            <tbody id="edCardBody">
                <tr><td colspan="6" class="td-center td-muted">카드 정보를 불러오는 중...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- 카드 모달 -->
    <div id="edCardModal" class="modal-overlay" style="display:none">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <span id="edCardModalTitle">카드 등록</span>
                <button class="modal-close" id="edCardModalClose" type="button"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="cf_idx" value="0">
                <div class="form-group mb-3">
                    <label class="form-label">용도</label>
                    <select class="form-select" id="cf_usage">
                        <option value="">선택</option>
                        <option value="통합">통합</option>
                        <option value="주유">주유</option>
                        <option value="복리">복리</option>
                        <option value="하이패스">하이패스</option>
                    </select>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">카드명</label>
                    <input class="form-input" type="text" id="cf_name">
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">카드번호</label>
                    <input class="form-input td-mono" type="text" id="cf_number" placeholder="xxxx-xxxx-xxxx-xxxx">
                </div>
                <div class="form-group">
                    <label class="form-label">비고</label>
                    <textarea class="form-input" id="cf_memo" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline btn-sm" id="edCardCancelBtn" type="button">취소</button>
                <button class="btn btn-primary btn-sm" id="edCardSaveBtn" type="button"><i class="fa fa-save"></i> 저장</button>
            </div>
        </div>
    </div>
<?php endif; ?>

    <!-- ════════ 연차정보 ════════ -->
    <div class="ed-section-title"><i class="fa fa-calendar-check-o"></i> 연차정보</div>
    <div class="ed-grid">
        <div class="ed-row">
            <div class="ed-label">연차일수</div>
            <div class="ed-value"><?= $vn('annual_total') ?>일</div>
        </div>
        <div class="ed-row">
            <div class="ed-label">사용일수</div>
            <div class="ed-value"><?= $vn('annual_used') ?>일</div>
        </div>
        <div class="ed-row">
            <div class="ed-label">잔여일수</div>
            <div class="ed-value">
                <?php
                $remain = (int)($emp['annual_total'] ?? 0) - (int)($emp['annual_used'] ?? 0);
                $rc = $remain > 0 ? 'text-success' : ($remain < 0 ? 'text-danger' : '');
                ?>
                <span class="<?= $rc ?> font-semibold"><?= number_format($remain) ?>일</span>
            </div>
        </div>
        <div class="ed-row">
            <div class="ed-label">퇴직금</div>
            <div class="ed-value"><?= $vn('retiring_allowance') ?>원</div>
        </div>
    </div>

</section>

<!-- ═══════════════ CSS ═══════════════ -->
<style>
/* ── 프로필 헤더 ── */
.ed-profile-card {
    display: flex;
    align-items: center;
    gap: var(--sp-5);
    padding: var(--sp-5);
    margin: 0 0 var(--sp-4);
}
.ed-photo-wrap {
    position: relative;
    width: 80px;
    height: 80px;
    flex-shrink: 0;
    cursor: pointer;
}
.ed-photo {
    width: 80px;
    height: 80px;
    border-radius: var(--radius-full);
    object-fit: cover;
    border: 3px solid var(--border);
}
.ed-photo-def {
    width: 80px;
    height: 80px;
    border-radius: var(--radius-full);
    background: var(--bg-2);
    border: 3px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-3);
    font-size: 32px;
}
.ed-photo-wrap:hover::after {
    content: '\f030';
    font-family: FontAwesome;
    position: absolute;
    inset: 0;
    border-radius: var(--radius-full);
    background: rgba(0,0,0,.45);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}
.ed-profile-info {
    flex: 1;
    min-width: 0;
}
.ed-profile-name {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-1);
    display: flex;
    align-items: center;
    gap: var(--sp-2);
    flex-wrap: wrap;
}
.ed-profile-meta {
    font-size: 12px;
    color: var(--text-3);
    margin-top: var(--sp-1);
    display: flex;
    gap: var(--sp-2);
    flex-wrap: wrap;
}
.ed-profile-meta span + span::before {
    content: '\b7';
    margin-right: var(--sp-2);
}
.ed-profile-actions {
    display: flex;
    gap: var(--sp-2);
    flex-shrink: 0;
}
.ed-btn-save {
    background: var(--success);
    color: #fff;
    border: none;
}
.ed-btn-save:hover {
    opacity: .9;
}

/* ── 섹션 ── */
.ed-section-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--accent);
    padding: var(--sp-2) 0 var(--sp-1);
    border-bottom: 2px solid var(--accent);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--sp-2);
    margin: var(--sp-4) 0 0;
}
.ed-section-title i {
    font-size: 13px;
}

/* ── 그리드 ── */
.ed-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-top: none;
    border-radius: 0 0 var(--radius-sm) var(--radius-sm);
    overflow: hidden;
}
.ed-grid--3 {
    grid-template-columns: 1fr 1fr 1fr;
}
.ed-row {
    display: flex;
    border-bottom: 1px solid var(--border);
}
.ed-row--full {
    grid-column: 1 / -1;
}
.ed-label {
    width: 100px;
    padding: var(--sp-2) var(--sp-3);
    font-size: 11px;
    font-weight: 600;
    color: var(--text-3);
    background: var(--bg-2);
    flex-shrink: 0;
    display: flex;
    align-items: center;
}
.ed-value {
    flex: 1;
    padding: var(--sp-2) var(--sp-3);
    font-size: 12px;
    color: var(--text-1);
    display: flex;
    align-items: center;
    min-width: 0;
}
.ed-value a {
    color: var(--accent);
    text-decoration: none;
}
.ed-value a:hover {
    text-decoration: underline;
}

/* ── 편집 모드 ── */
.ed-sv { display: inline; }
.ed-se { width: 100%; }
.ed-se .form-input,
.ed-se .form-select {
    width: 100%;
    padding: 5px var(--sp-2);
    font-size: 12px;
    box-sizing: border-box;
}
.ed-se textarea.form-input {
    min-height: 40px;
    resize: vertical;
}

/* ── 검색 드롭다운 ── */
.ed-dd-wrap {
    position: relative;
    width: 100%;
}
.ed-dd-list {
    display: none;
    position: fixed;
    background: var(--card-bg);
    border: 1px solid var(--accent);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-lg);
    z-index: 9999;
    max-height: 180px;
    overflow-y: auto;
    min-width: 140px;
}
.ed-dd-opt {
    padding: var(--sp-2) var(--sp-3);
    font-size: 11px;
    color: var(--text-2);
    cursor: pointer;
}
.ed-dd-opt:hover,
.ed-dd-opt.ed-dd-hl {
    background: rgba(59,108,247,.08);
    color: var(--accent);
}
.ed-dd-opt.ed-dd-sel {
    background: rgba(59,108,247,.08);
    color: var(--accent);
    font-weight: 600;
}

/* ── 카드 테이블 ── */
.ed-card-table-wrap {
    border: 1px solid var(--border);
    border-top: none;
    border-radius: 0 0 var(--radius-sm) var(--radius-sm);
    overflow-x: auto;
}
.ed-card-table-wrap .tbl {
    margin: 0;
}
.badge-purple {
    background: #ede9fe;
    color: #7c3aed;
}
.ed-card-usage {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

/* ── 반응형 ── */
@media (max-width: 1024px) {
    .ed-grid--3 {
        grid-template-columns: 1fr 1fr;
    }
}
@media (max-width: 768px) {
    .ed-profile-card {
        flex-direction: column;
        text-align: center;
        gap: var(--sp-3);
    }
    .ed-profile-meta {
        justify-content: center;
    }
    .ed-profile-actions {
        justify-content: center;
        flex-wrap: wrap;
    }
    .ed-grid,
    .ed-grid--3 {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- ═══════════════ JS ═══════════════ -->
<script>
(function () {
    'use strict';

    var _section   = document.querySelector('[data-page="emp-detail"]');
    if (!_section) return;

    var _roleLevel = parseInt(_section.dataset.role, 10) || 0;
    var _empIdx    = parseInt(_section.dataset.empIdx, 10) || 0;
    var _isAdmin   = (_roleLevel > 0 && _roleLevel <= 4);
    var _editMode  = false;
    var _apiUrl    = 'dist_process/saas/Employee.php';
    var _ddHl      = {};

    /* ── 헬퍼 ── */
    function escH(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    /* ── 뒤로 ── */
    var _backBtn = document.getElementById('edBackBtn');
    if (_backBtn) _backBtn.addEventListener('click', function () { SHV.router.navigate('org_chart'); });

    /* ══════ 편집 모드 토글 ══════ */
    var _editBtn   = document.getElementById('edEditBtn');
    var _saveBtn   = document.getElementById('edSaveBtn');
    var _cancelBtn = document.getElementById('edCancelBtn');
    var _delBtn    = document.getElementById('edDelBtn');

    function edToggleEdit(on) {
        _editMode = on;
        var svs = _section.querySelectorAll('.ed-sv');
        var ses = _section.querySelectorAll('.ed-se');
        for (var i = 0; i < svs.length; i++) svs[i].style.display = on ? 'none' : '';
        for (var j = 0; j < ses.length; j++) ses[j].style.display = on ? '' : 'none';

        _editBtn.classList.toggle('hidden', on);
        _saveBtn.classList.toggle('hidden', !on);
        _cancelBtn.classList.toggle('hidden', !on);
        if (_delBtn) _delBtn.classList.toggle('hidden', !on);

        if (on) {
            var first = _section.querySelector('.ed-se input:not([type=hidden]):not([type=file]), .ed-se select');
            if (first) first.focus();
        }
    }

    if (_editBtn) _editBtn.addEventListener('click', function () { edToggleEdit(true); });
    if (_cancelBtn) _cancelBtn.addEventListener('click', function () { edToggleEdit(false); });

    /* ══════ 저장 ══════ */
    if (_saveBtn) _saveBtn.addEventListener('click', edSave);

    function edSave() {
        var name = (document.getElementById('ed_emp_name') || {}).value;
        if (!name || !name.trim()) { SHV.toast.warn('성명을 입력하세요.'); return; }

        var data = {
            todo: 'update_employee',
            idx: _empIdx,
            emp_name:         (document.getElementById('ed_emp_name') || {}).value || '',
            emp_no:           (document.getElementById('ed_emp_no') || {}).value || '',
            position_name:    (document.getElementById('ed_position_name') || {}).value || '',
            job_title:        (document.getElementById('ed_job_title') || {}).value || '',
            dept_idx:         (document.getElementById('ed_dept_idx_val') || {}).value || '0',
            employment_type:  (document.getElementById('ed_employment_type') || {}).value || '',
            status:           (document.getElementById('ed_status') || {}).value || 'ACTIVE',
            social_number:    (document.getElementById('ed_social_number') || {}).value || '',
            hire_date:        (document.getElementById('ed_hire_date') || {}).value || '',
            leave_date:       (document.getElementById('ed_leave_date') || {}).value || '',
            phone:            (document.getElementById('ed_phone') || {}).value || '',
            tel:              (document.getElementById('ed_tel') || {}).value || '',
            email:            (document.getElementById('ed_email') || {}).value || '',
            personal_email:   (document.getElementById('ed_personal_email') || {}).value || '',
            address:          (document.getElementById('ed_address') || {}).value || '',
            work_gubun:       (document.getElementById('ed_work_gubun') || {}).value || '',
            license:          (document.getElementById('ed_license') || {}).value || '',
            career_note:      (document.getElementById('ed_career_note') || {}).value || '',
            last_promotion:   (document.getElementById('ed_last_promotion') || {}).value || '',
            emp_memo:         (document.getElementById('ed_emp_memo') || {}).value || ''
        };

        /* 관리자 전용 필드 */
        if (_isAdmin) {
            var salaryKeys = ['salary_basic','salary_qualification','salary_part_position','salary_position',
                              'salary_overtime_fix','salary_work','salary_meal','salary_car','salary_etc'];
            for (var s = 0; s < salaryKeys.length; s++) {
                var el = document.getElementById('ed_' + salaryKeys[s]);
                if (el) data[salaryKeys[s]] = el.value || '0';
            }
            data.bank_name      = (document.getElementById('ed_bank_name') || {}).value || '';
            data.bank_depositor = (document.getElementById('ed_bank_depositor') || {}).value || '';
            data.bank_account   = (document.getElementById('ed_bank_account') || {}).value || '';
        }

        SHV.api.post(_apiUrl, data)
            .then(function (res) {
                if (res && res.ok) {
                    SHV.toast.success('저장되었습니다.');
                    /* 페이지 리로드 (서버 렌더) */
                    SHV.router.navigate('emp_detail', { idx: _empIdx });
                } else {
                    SHV.toast.error((res && res.message) || '저장 실패');
                }
            })
            .catch(function () { SHV.toast.error('서버 오류'); });
    }

    /* 키보드: Enter=저장, Escape=취소 */
    _section.addEventListener('keydown', function (e) {
        if (!_editMode) return;
        if (e.target.tagName === 'TEXTAREA') return;
        /* 드롭다운 열림 상태면 무시 */
        if (e.target.classList.contains('ed-dd-input')) {
            var list = e.target.parentNode.querySelector('.ed-dd-list');
            if (list && list.style.display === 'block') {
                if (e.key === 'Enter' || e.key === 'ArrowDown' || e.key === 'ArrowUp') return;
            }
        }
        if (e.key === 'Enter') { e.preventDefault(); edSave(); }
        if (e.key === 'Escape') { e.preventDefault(); edToggleEdit(false); }
    });

    /* ══════ 사진 업로드 ══════ */
    var _photoWrap = document.getElementById('edPhotoWrap');
    var _photoFile = document.getElementById('edPhotoFile');

    if (_photoWrap && _photoFile) {
        _photoWrap.addEventListener('click', function () { _photoFile.click(); });
        _photoFile.addEventListener('change', function () {
            var file = _photoFile.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) { SHV.toast.warn('5MB 이하 파일만 가능합니다.'); return; }

            SHV.api.upload(_apiUrl, { todo: 'upload_photo', idx: _empIdx }, file, 'photo')
                .then(function (res) {
                    if (res && res.ok) {
                        SHV.toast.success('사진이 업로드되었습니다.');
                        SHV.router.navigate('emp_detail', { idx: _empIdx });
                    } else {
                        SHV.toast.error((res && res.message) || '업로드 실패');
                    }
                })
                .catch(function () { SHV.toast.error('업로드 오류'); });
        });
    }

    /* ══════ 검색 드롭다운 ══════ */
    window.edDdOpen = function (uid) {
        var input = document.getElementById(uid + '_input');
        var list  = document.getElementById(uid + '_list');
        if (!input || !list) return;
        var rect = input.getBoundingClientRect();
        var spaceBelow = window.innerHeight - rect.bottom;
        list.style.display = 'block';
        list.style.left  = rect.left + 'px';
        list.style.width = Math.max(rect.width, 140) + 'px';
        if (spaceBelow < 200) {
            list.style.bottom = (window.innerHeight - rect.top) + 'px';
            list.style.top = 'auto';
        } else {
            list.style.top = rect.bottom + 'px';
            list.style.bottom = 'auto';
        }
        _ddHl[uid] = -1;
        edDdFilter(uid);
    };

    window.edDdFilter = function (uid) {
        var q = (document.getElementById(uid + '_input').value || '').toLowerCase();
        var opts = document.querySelectorAll('#' + uid + '_list .ed-dd-opt');
        for (var i = 0; i < opts.length; i++) {
            var show = !q || (opts[i].dataset.n || '').indexOf(q) > -1 || opts[i].textContent.toLowerCase().indexOf(q) > -1;
            opts[i].style.display = show ? '' : 'none';
            opts[i].classList.remove('ed-dd-hl');
        }
        _ddHl[uid] = -1;
    };

    window.edDdKey = function (e, uid) {
        var list = document.getElementById(uid + '_list');
        if (!list || list.style.display !== 'block') { edDdOpen(uid); return; }
        var items = [];
        var all = list.querySelectorAll('.ed-dd-opt');
        for (var i = 0; i < all.length; i++) { if (all[i].style.display !== 'none') items.push(all[i]); }
        if (!items.length) return;
        var idx = _ddHl[uid] || -1;
        if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(idx + 1, items.length - 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(idx - 1, 0); }
        else if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); if (idx >= 0 && items[idx]) items[idx].click(); else list.style.display = 'none'; return; }
        else if (e.key === 'Escape') { list.style.display = 'none'; return; }
        else return;
        _ddHl[uid] = idx;
        for (var j = 0; j < items.length; j++) items[j].classList.remove('ed-dd-hl');
        if (items[idx]) { items[idx].classList.add('ed-dd-hl'); items[idx].scrollIntoView({ block: 'nearest' }); }
    };

    window.edDdPick = function (uid, val, label) {
        document.getElementById(uid + '_val').value = val;
        document.getElementById(uid + '_input').value = label;
        document.getElementById(uid + '_list').style.display = 'none';
        var opts = document.querySelectorAll('#' + uid + '_list .ed-dd-opt');
        for (var i = 0; i < opts.length; i++) opts[i].classList.remove('ed-dd-sel');
        var sel = document.querySelector('#' + uid + '_list .ed-dd-opt[data-v="' + val + '"]');
        if (sel) sel.classList.add('ed-dd-sel');
    };

    /* 드롭다운 외부 클릭 닫기 */
    function _closeDd(e) {
        if (!e.target.classList.contains('ed-dd-input')) {
            var lists = document.querySelectorAll('.ed-dd-list');
            for (var i = 0; i < lists.length; i++) lists[i].style.display = 'none';
        }
    }
    document.addEventListener('click', _closeDd);

    /* ══════ 카드 CRUD ══════ */
    if (_isAdmin) {
        var _cardModal    = document.getElementById('edCardModal');
        var _cardBody     = document.getElementById('edCardBody');
        var _cardAddBtn   = document.getElementById('edCardAddBtn');
        var _cardSaveBtn  = document.getElementById('edCardSaveBtn');
        var _cardCloseBtn = document.getElementById('edCardModalClose');
        var _cardCancelBtn= document.getElementById('edCardCancelBtn');

        var _usageColors = {
            '통합': 'badge-info', '주유': 'badge-warn',
            '복리': 'badge-success', '하이패스': 'badge-purple'
        };

        function loadCards() {
            SHV.api.get(_apiUrl, { todo: 'card_list', employee_idx: _empIdx })
                .then(function (res) {
                    if (res && res.ok && res.data && res.data.items) {
                        renderCards(res.data.items);
                    } else {
                        _cardBody.innerHTML = '<tr><td colspan="6" class="td-center td-muted">카드 정보가 없습니다.</td></tr>';
                    }
                })
                .catch(function () {
                    _cardBody.innerHTML = '<tr><td colspan="6" class="td-center td-muted">카드 정보를 불러올 수 없습니다.</td></tr>';
                });
        }

        function renderCards(cards) {
            if (!cards || cards.length === 0) {
                _cardBody.innerHTML = '<tr><td colspan="6" class="td-center td-muted">등록된 카드가 없습니다.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < cards.length; i++) {
                var c = cards[i];
                var bc = _usageColors[c.card_usage] || 'badge-ghost';
                html += '<tr data-card-idx="' + (c.idx || '') + '">'
                    + '<td class="td-center">' + (i + 1) + '</td>'
                    + '<td><span class="badge ' + bc + '">' + escH(c.card_usage) + '</span></td>'
                    + '<td>' + escH(c.card_name) + '</td>'
                    + '<td class="td-mono">' + escH(c.card_number) + '</td>'
                    + '<td class="td-muted">' + escH(c.card_memo || '-') + '</td>'
                    + '<td class="td-center">'
                    + '<button class="btn btn-ghost btn-xs ed-card-edit" data-idx="' + (c.idx || '') + '" title="수정"><i class="fa fa-pencil"></i></button> '
                    + '<button class="btn btn-ghost btn-xs text-danger ed-card-del" data-idx="' + (c.idx || '') + '" title="삭제"><i class="fa fa-trash"></i></button>'
                    + '</td></tr>';
            }
            _cardBody.innerHTML = html;
        }

        function openCardModal(data) {
            data = data || {};
            document.getElementById('cf_idx').value = data.idx || 0;
            document.getElementById('cf_usage').value = data.card_usage || '';
            document.getElementById('cf_name').value = data.card_name || '';
            document.getElementById('cf_number').value = data.card_number || '';
            document.getElementById('cf_memo').value = data.card_memo || '';
            document.getElementById('edCardModalTitle').textContent = data.idx ? '카드 수정' : '카드 등록';
            _cardModal.style.display = 'flex';
        }

        function closeCardModal() { _cardModal.style.display = 'none'; }

        _cardAddBtn.addEventListener('click', function () { openCardModal(); });
        _cardCloseBtn.addEventListener('click', closeCardModal);
        _cardCancelBtn.addEventListener('click', closeCardModal);
        _cardModal.addEventListener('click', function (e) { if (e.target === _cardModal) closeCardModal(); });

        _cardSaveBtn.addEventListener('click', function () {
            var idx = parseInt(document.getElementById('cf_idx').value, 10) || 0;
            var data = {
                todo: idx ? 'update_card' : 'insert_card',
                employee_idx: _empIdx,
                card_usage:  document.getElementById('cf_usage').value,
                card_name:   document.getElementById('cf_name').value,
                card_number: document.getElementById('cf_number').value,
                card_memo:   document.getElementById('cf_memo').value
            };
            if (idx) data.idx = idx;

            SHV.api.post(_apiUrl, data)
                .then(function (res) {
                    if (res && res.ok) {
                        SHV.toast.success('카드가 저장되었습니다.');
                        closeCardModal();
                        loadCards();
                    } else {
                        SHV.toast.error((res && res.message) || '저장 실패');
                    }
                })
                .catch(function () { SHV.toast.error('서버 오류'); });
        });

        /* 카드 테이블 이벤트 위임 */
        _cardBody.addEventListener('click', function (e) {
            var editBtn = e.target.closest('.ed-card-edit');
            if (editBtn) {
                var cardIdx = parseInt(editBtn.dataset.idx, 10);
                var tr = editBtn.closest('tr');
                var tds = tr.querySelectorAll('td');
                openCardModal({
                    idx: cardIdx,
                    card_usage:  (tds[1].textContent || '').trim(),
                    card_name:   (tds[2].textContent || '').trim(),
                    card_number: (tds[3].textContent || '').trim(),
                    card_memo:   (tds[4].textContent || '').trim() === '-' ? '' : (tds[4].textContent || '').trim()
                });
                return;
            }

            var delBtn = e.target.closest('.ed-card-del');
            if (delBtn) {
                var delIdx = parseInt(delBtn.dataset.idx, 10);
                shvConfirm({ message: '이 카드를 삭제하시겠습니까?', type: 'danger' })
                    .then(function (ok) {
                        if (!ok) return;
                        SHV.api.post(_apiUrl, { todo: 'delete_card', idx: delIdx })
                            .then(function (res) {
                                if (res && res.ok) { SHV.toast.success('삭제되었습니다.'); loadCards(); }
                                else SHV.toast.error((res && res.message) || '삭제 실패');
                            })
                            .catch(function () { SHV.toast.error('서버 오류'); });
                    });
            }
        });

        /* 카드 초기 로드 */
        loadCards();
    }

    /* ══════ 삭제 (관리자) ══════ */
    if (_isAdmin && _delBtn) {
        _delBtn.addEventListener('click', function () {
            shvConfirm({ message: '이 직원을 삭제하시겠습니까?\n삭제된 데이터는 복구할 수 없습니다.', type: 'danger' })
                .then(function (ok) {
                    if (!ok) return;
                    SHV.api.post(_apiUrl, { todo: 'delete_employee', idx: _empIdx })
                        .then(function (res) {
                            if (res && res.ok) { SHV.toast.success('삭제되었습니다.'); SHV.router.navigate('org_chart'); }
                            else SHV.toast.error((res && res.message) || '삭제 실패');
                        })
                        .catch(function () { SHV.toast.error('서버 오류'); });
                });
        });
    }

    /* ══════ 페이지 라이프사이클 ══════ */
    SHV.pages = SHV.pages || {};
    SHV.pages['emp_detail'] = {
        destroy: function () {
            document.removeEventListener('click', _closeDd);
        }
    };

})();
</script>
