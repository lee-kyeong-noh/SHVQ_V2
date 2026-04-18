<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

function grpOrgH(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="org-chart"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$security  = require __DIR__ . '/../../../config/security.php';
$service   = new GroupwareService(DbConnection::get(), $security);
$scope     = $service->resolveScope($context, '', 0);
$userPk    = (int)($context['user_pk'] ?? 0);
$roleLevel = (int)($context['role_level'] ?? 0);

$missing = $service->missingTables($service->requiredTablesByDomain('employee'));
$ready   = $missing === [];

$depts = $ready ? $service->listDepartments($scope) : [];

$search   = trim((string)($_GET['search'] ?? ''));
$deptIdx  = (int)($_GET['dept_idx'] ?? 0);
$status   = trim((string)($_GET['status'] ?? 'ACTIVE'));
$mode     = in_array($_GET['mode'] ?? '', ['thumbnail', 'list']) ? ($_GET['mode'] ?? 'thumbnail') : 'thumbnail';

$emps = $ready ? $service->listEmployees($scope, [
    'search'   => $search,
    'dept_idx' => $deptIdx,
    'status'   => $status,
    'limit'    => 300,
]) : [];

$hiddenEmps = ($ready && $roleLevel <= 2)
    ? $service->listHiddenEmployees($scope, ['limit' => 300])
    : [];

$statusLabels = ['ACTIVE' => '재직', 'RESIGNED' => '퇴사', 'LEAVE' => '휴직', 'INACTIVE' => '퇴사' /* legacy */];
$statusBadge  = ['ACTIVE' => 'badge-success', 'RESIGNED' => 'badge-danger', 'LEAVE' => 'badge-warn', 'INACTIVE' => 'badge-danger' /* legacy */];
?>
<section data-page="org-chart"
         data-role="<?= $roleLevel ?>"
         data-scope-code="<?= grpOrgH((string)($scope['service_code'] ?? '')) ?>"
         data-scope-tenant="<?= (int)($scope['tenant_id'] ?? 0) ?>"
         data-depts="<?= grpOrgH(json_encode($depts, JSON_UNESCAPED_UNICODE)) ?>">

<div class="oc-layout">
    <!-- ═══ 부서 사이드바 ═══ -->
    <aside class="oc-sidebar" id="ocSidebar">
        <div class="oc-sb-header">
            <span class="oc-sb-title"><i class="fa fa-sitemap"></i> 부서</span>
            <button class="btn btn-ghost btn-xs" id="ocSbToggle" title="사이드바 접기"><i class="fa fa-angle-double-left"></i></button>
        </div>
        <div class="oc-sb-body" id="ocSbBody">
            <div class="oc-sb-item oc-sb-item--active" data-dept-idx="0">
                <i class="fa fa-folder-open"></i> 전체 <span class="oc-sb-cnt"><?= count($emps) ?></span>
            </div>
            <!-- JS에서 트리 동적 빌드 -->
        </div>
    </aside>

    <!-- ═══ 메인 영역 ═══ -->
    <div class="oc-main">

    <div class="page-header">
        <h2 class="page-title" data-title="주소록">주소록</h2>
        <div class="page-header-actions">
            <a id="oc-view-thumb" class="btn-view-toggle<?= $mode === 'thumbnail' ? ' on' : '' ?>" title="썸네일 보기">
                <i class="fa fa-th-large"></i>
            </a>
            <a id="oc-view-list" class="btn-view-toggle<?= $mode === 'list' ? ' on' : '' ?>" title="리스트 보기">
                <i class="fa fa-bars"></i>
            </a>
            <?php if ($roleLevel <= 2): ?>
            <button id="oc-btn-add-emp" class="btn btn-primary btn-sm">직원 등록</button>
            <button id="oc-btn-add-dept" class="btn btn-outline btn-sm"><i class="fa fa-plus mr-1"></i>부서 추가</button>
            <button id="oc-btn-settings" class="btn btn-ghost btn-sm" title="조직도 설정"><i class="fa fa-cog"></i></button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$ready): ?>
    <div class="oc-wrap">
        <div class="card card-mt card-mb">
            <div class="card-body"><div class="empty-state"><p class="empty-message">그룹웨어 DB가 준비되지 않았습니다.</p></div></div>
        </div>
    </div>
    <?php else: ?>

    <!-- 검색 영역 -->
    <div class="oc-wrap">
        <div class="oc-search-box card">
            <div class="oc-search-top">
                <input id="ocSearchInput" type="text" class="form-input oc-search-main"
                       placeholder="이름 / 사번 / 연락처 / 이메일" value="<?= grpOrgH($search) ?>">
                <button id="ocSearchDetailBtn" class="btn btn-outline btn-sm">상세검색</button>
                <?php if ($roleLevel <= 2): ?>
                <button id="oc-btn-add-emp2" class="btn btn-primary btn-sm">등록</button>
                <?php endif; ?>
            </div>
            <div class="oc-search-detail" id="ocSearchDetail">
                <ul class="oc-search-list">
                    <li>
                        <span class="oc-sl-tit">부서명</span>
                        <span class="oc-sl-txt">
                            <select id="ocDeptSel" class="form-select">
                                <option value="0">전체</option>
                                <?php foreach ($depts as $d): ?>
                                <option value="<?= (int)($d['idx'] ?? 0) ?>"<?= $deptIdx === (int)($d['idx'] ?? 0) ? ' selected' : '' ?>>
                                    <?= grpOrgH((string)($d['dept_name'] ?? '')) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                    </li>
                    <li>
                        <span class="oc-sl-tit">재직상태</span>
                        <span class="oc-sl-txt">
                            <select id="ocStatus" class="form-select">
                                <option value="">전체</option>
                                <option value="ACTIVE"   <?= $status === 'ACTIVE'   ? 'selected' : '' ?>>재직</option>
                                <option value="RESIGNED" <?= $status === 'RESIGNED' ? 'selected' : '' ?>>퇴사</option>
                                <option value="LEAVE"    <?= $status === 'LEAVE'    ? 'selected' : '' ?>>휴직</option>
                            </select>
                        </span>
                    </li>
                </ul>
                <div class="oc-search-btns">
                    <button id="ocSearchBtn" class="btn btn-primary btn-sm"><i class="fa fa-search mr-1"></i>검색</button>
                    <button id="ocResetBtn"  class="btn btn-ghost btn-sm"><i class="fa fa-refresh mr-1"></i>초기화</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($roleLevel <= 2): ?>
    <!-- 탭 (재직중 / 숨김) -->
    <div class="oc-wrap oc-tab-row">
        <div class="oc-tabs">
            <button class="oc-tab on" id="ocTabActive">
                재직중 <span class="oc-tab-badge" id="ocTabActiveCnt"><?= count($emps) ?></span>
            </button>
            <button class="oc-tab" id="ocTabHidden">
                숨김 <span class="oc-tab-badge<?= count($hiddenEmps) > 0 ? ' oc-tab-badge--warn' : '' ?>" id="ocTabHiddenCnt"><?= count($hiddenEmps) ?></span>
            </button>
            <button class="oc-tab" id="ocTabTree">
                조직도 <span class="oc-tab-badge" id="ocTabTreeCnt"><i class="fa fa-sitemap"></i></span>
            </button>
            <button class="oc-tab" id="ocTabUnassigned">
                미배정 <span class="oc-tab-badge oc-tab-badge--ghost" id="ocTabUnassignedCnt">0</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- 썸네일 뷰 -->
    <div class="oc-wrap oc-card-wrap<?= $mode === 'thumbnail' ? '' : ' hidden' ?>" id="ocViewThumb">
        <?php if ($emps === []): ?>
        <div class="empty-state mt-4"><p class="empty-message">검색된 직원이 없습니다.</p></div>
        <?php else: ?>
        <div class="oc-card-grid">
            <?php foreach ($emps as $e):
                $st    = (string)($e['status'] ?? 'ACTIVE');
                $badge = $statusBadge[$st] ?? 'badge-ghost';
                $stLbl = $statusLabels[$st] ?? $st;
                $empIdx = (int)($e['idx'] ?? 0);
            ?>
            <div class="oc-card" data-emp-idx="<?= $empIdx ?>">
                <div class="oc-card-photo">
                    <?php if (!empty($e['photo_url'])): ?>
                    <img class="oc-card-img" src="<?= grpOrgH((string)$e['photo_url']) ?>" alt="<?= grpOrgH((string)($e['emp_name'] ?? '')) ?>">
                    <?php else: ?>
                    <i class="fa fa-user-circle oc-card-avatar"></i>
                    <?php endif; ?>
                </div>
                <ul class="oc-card-info">
                    <li class="oc-card-name">
                        <?= grpOrgH((string)($e['emp_name'] ?? '')) ?>
                        <span class="badge <?= $badge ?> oc-card-status"><?= $stLbl ?></span>
                    </li>
                    <li>
                        <span class="oc-ci-tit">사번</span>
                        <span class="oc-ci-txt"><?= grpOrgH((string)($e['emp_no'] ?? '-')) ?></span>
                    </li>
                    <li>
                        <span class="oc-ci-tit">부서</span>
                        <span class="oc-ci-txt"><?= grpOrgH((string)($e['dept_name'] ?? '-')) ?></span>
                    </li>
                    <li class="oc-ci-half">
                        <span class="oc-ci-tit">직급</span>
                        <span class="oc-ci-txt"><?= grpOrgH((string)($e['position_name'] ?? '-')) ?></span>
                    </li>
                    <li class="oc-ci-half">
                        <span class="oc-ci-tit">직책</span>
                        <span class="oc-ci-txt"><?= grpOrgH((string)($e['job_title'] ?? '-')) ?></span>
                    </li>
                    <li>
                        <span class="oc-ci-tit">H.P</span>
                        <span class="oc-ci-txt"><?= grpOrgH((string)($e['phone'] ?? '-')) ?></span>
                    </li>
                    <li>
                        <span class="oc-ci-tit">E-mail</span>
                        <span class="oc-ci-txt oc-ci-email"><?= grpOrgH((string)($e['email'] ?? '-')) ?></span>
                    </li>
                    <?php if ($roleLevel <= 2): ?>
                    <li class="oc-card-btns">
                        <button class="btn btn-outline btn-xs oc-edit-emp" data-emp-idx="<?= $empIdx ?>">수정</button>
                        <button class="btn btn-danger btn-xs oc-del-emp" data-emp-idx="<?= $empIdx ?>">삭제</button>
                        <button class="btn btn-ghost btn-xs oc-hide-emp" data-emp-idx="<?= $empIdx ?>" title="숨기기"><i class="fa fa-eye-slash"></i></button>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 리스트 뷰 -->
    <div class="oc-wrap<?= $mode === 'list' ? '' : ' hidden' ?>" id="ocViewList">
        <div class="card card-mt card-mb">
            <div class="card-header">
                <span>총 <?= count($emps) ?>명</span>
            </div>
            <div class="card-body--table">
                <table class="tbl tbl-sticky-header" id="ocEmpTable">
                    <colgroup>
                        <col class="col-100"><col><col class="col-110">
                        <col class="col-110"><col class="col-150"><col class="col-160">
                        <col class="col-80"><col class="col-100">
                        <?php if ($roleLevel <= 2): ?><col class="col-130"><?php endif; ?>
                    </colgroup>
                    <thead>
                        <tr>
                            <th>사번</th><th>성명</th><th>직급</th>
                            <th>직책</th><th>부서</th><th>H.P</th>
                            <th>재직상태</th><th>입사일</th>
                            <?php if ($roleLevel <= 2): ?><th class="th-center">관리</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="ocEmpBody">
                    <?php if ($emps === []): ?>
                        <tr><td colspan="<?= ($roleLevel <= 2) ? 9 : 8 ?>">
                            <div class="empty-state"><p class="empty-message">검색된 직원이 없습니다.</p></div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($emps as $e):
                            $st    = (string)($e['status'] ?? 'ACTIVE');
                            $badge = $statusBadge[$st] ?? 'badge-ghost';
                            $stLbl = $statusLabels[$st] ?? $st;
                            $empIdx = (int)($e['idx'] ?? 0);
                        ?>
                        <tr data-emp-idx="<?= $empIdx ?>">
                            <td class="td-mono"><?= grpOrgH((string)($e['emp_no'] ?? '')) ?></td>
                            <td class="font-semibold"><?= grpOrgH((string)($e['emp_name'] ?? '')) ?></td>
                            <td class="td-muted"><?= grpOrgH((string)($e['position_name'] ?? '')) ?></td>
                            <td class="td-muted"><?= grpOrgH((string)($e['job_title'] ?? '')) ?></td>
                            <td class="td-muted"><?= grpOrgH((string)($e['dept_name'] ?? '')) ?></td>
                            <td class="td-muted"><?= grpOrgH((string)($e['phone'] ?? '')) ?></td>
                            <td class="td-center"><span class="badge <?= $badge ?>"><?= $stLbl ?></span></td>
                            <td class="td-muted td-mono"><?= grpOrgH((string)($e['hire_date'] ?? '')) ?></td>
                            <?php if ($roleLevel <= 2): ?>
                            <td class="td-center oc-list-btns">
                                <button class="btn btn-outline btn-xs oc-edit-emp" data-emp-idx="<?= $empIdx ?>">수정</button>
                                <button class="btn btn-danger btn-xs oc-del-emp"  data-emp-idx="<?= $empIdx ?>">삭제</button>
                                <button class="btn btn-ghost btn-xs oc-hide-emp" data-emp-idx="<?= $empIdx ?>" title="숨기기"><i class="fa fa-eye-slash"></i></button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($roleLevel <= 2): ?>
    <!-- 숨김 직원 뷰 (관리자 전용) -->
    <div class="oc-wrap oc-hidden-wrap hidden" id="ocHiddenView">
        <?php if ($hiddenEmps === []): ?>
        <div class="empty-state mt-4"><p class="empty-message">숨김 처리된 직원이 없습니다.</p></div>
        <?php else: ?>
        <div class="oc-card-grid">
            <?php foreach ($hiddenEmps as $e):
                $st     = (string)($e['status'] ?? 'ACTIVE');
                $badge  = $statusBadge[$st] ?? 'badge-ghost';
                $stLbl  = $statusLabels[$st] ?? $st;
                $empIdx = (int)($e['idx'] ?? 0);
            ?>
            <div class="oc-card oc-card--hidden" data-emp-idx="<?= $empIdx ?>">
                <div class="oc-card-photo">
                    <?php if (!empty($e['photo_url'])): ?>
                    <img class="oc-card-img" src="<?= grpOrgH((string)$e['photo_url']) ?>" alt="<?= grpOrgH((string)($e['emp_name'] ?? '')) ?>">
                    <?php else: ?>
                    <i class="fa fa-user-circle oc-card-avatar"></i>
                    <?php endif; ?>
                </div>
                <ul class="oc-card-info">
                    <li class="oc-card-name">
                        <?= grpOrgH((string)($e['emp_name'] ?? '')) ?>
                        <span class="badge <?= $badge ?> oc-card-status"><?= $stLbl ?></span>
                    </li>
                    <li>
                        <span class="oc-ci-tit">사번</span>
                        <span class="oc-ci-txt"><?= grpOrgH((string)($e['emp_no'] ?? '-')) ?></span>
                    </li>
                    <li>
                        <span class="oc-ci-tit">부서</span>
                        <span class="oc-ci-txt"><?= grpOrgH((string)($e['dept_name'] ?? '-')) ?></span>
                    </li>
                    <li class="oc-ci-half">
                        <span class="oc-ci-tit">직급</span>
                        <span class="oc-ci-txt"><?= grpOrgH((string)($e['position_name'] ?? '-')) ?></span>
                    </li>
                    <li class="oc-ci-half">
                        <span class="oc-ci-tit">직책</span>
                        <span class="oc-ci-txt"><?= grpOrgH((string)($e['job_title'] ?? '-')) ?></span>
                    </li>
                    <li class="oc-card-btns">
                        <span class="oc-hidden-label"><i class="fa fa-eye-slash"></i> 숨김처리됨</span>
                        <button class="btn btn-outline btn-xs oc-unhide-emp" data-emp-idx="<?= $empIdx ?>">해제</button>
                    </li>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══ 트리 다이어그램 뷰 ═══ -->
    <div class="oc-wrap oc-tree-wrap hidden" id="ocTreeView">
        <div class="oc-tree-diagram" id="ocTreeDiagram">
            <div class="empty-state mt-4"><p class="empty-message">조직도를 불러오는 중...</p></div>
        </div>
    </div>

    <!-- ═══ 미배정 직원 뷰 ═══ -->
    <div class="oc-wrap hidden" id="ocUnassignedView">
        <div class="card card-mt card-mb">
            <div class="card-header"><span>미배정 직원</span></div>
            <div class="card-body--table">
                <table class="tbl tbl-sticky-header">
                    <colgroup><col class="col-100"><col><col class="col-110"><col class="col-110"><col class="col-110"><col class="col-120"><col class="col-100"></colgroup>
                    <thead><tr><th>사번</th><th>성명</th><th>직급</th><th>직책</th><th>연락처</th><th>이메일</th><th>상태</th></tr></thead>
                    <tbody id="ocUnassignedBody">
                        <tr><td colspan="7" class="td-center td-muted">불러오는 중...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <!-- 직원 상세보기 모달 -->
    <div id="ocEmpDetailModal" class="modal-overlay" style="display:none;">
        <div class="modal-box modal-lg">
            <div class="modal-header">
                <span id="ocDetailTitle">직원 상세</span>
                <button class="modal-close" id="ocDetailClose"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body oc-detail-body">
                <!-- 상단: 사진 + 요약 -->
                <div class="oc-detail-top">
                    <div class="oc-detail-photo-wrap" id="ocDetailPhotoWrap">
                        <i class="fa fa-user-circle oc-detail-avatar"></i>
                    </div>
                    <div class="oc-detail-summary">
                        <p class="oc-detail-name" id="ocDetailName">-</p>
                        <p class="oc-detail-sub" id="ocDetailSub">-</p>
                        <span class="badge" id="ocDetailBadge"></span>
                    </div>
                </div>
                <!-- 기본정보 -->
                <div class="oc-detail-section">
                    <div class="oc-detail-section-title"><i class="fa fa-user"></i> 기본정보</div>
                    <div class="oc-detail-grid">
                        <div class="oc-dr"><span class="oc-dl">사번</span><span class="oc-dv" id="dd-emp_no">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">부서</span><span class="oc-dv" id="dd-dept_name">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">직급</span><span class="oc-dv" id="dd-position_name">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">직책</span><span class="oc-dv" id="dd-job_title">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">고용형태</span><span class="oc-dv" id="dd-employment_type">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">생년월일</span><span class="oc-dv" id="dd-social_number">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">입사일</span><span class="oc-dv" id="dd-hire_date">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">퇴사일</span><span class="oc-dv" id="dd-leave_date">-</span></div>
                    </div>
                </div>
                <!-- 연락처 -->
                <div class="oc-detail-section">
                    <div class="oc-detail-section-title"><i class="fa fa-phone"></i> 연락처</div>
                    <div class="oc-detail-grid">
                        <div class="oc-dr"><span class="oc-dl">H.P</span><span class="oc-dv" id="dd-phone">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">TEL</span><span class="oc-dv" id="dd-tel">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">이메일</span><span class="oc-dv" id="dd-email">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">개인메일</span><span class="oc-dv" id="dd-personal_email">-</span></div>
                        <div class="oc-dr oc-dr-full"><span class="oc-dl">주소</span><span class="oc-dv" id="dd-address">-</span></div>
                    </div>
                </div>
                <!-- 기타정보 -->
                <div class="oc-detail-section">
                    <div class="oc-detail-section-title"><i class="fa fa-info-circle"></i> 기타정보</div>
                    <div class="oc-detail-grid">
                        <div class="oc-dr"><span class="oc-dl">업무구분</span><span class="oc-dv" id="dd-work_gubun">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">자격증</span><span class="oc-dv" id="dd-license">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">경력수첩</span><span class="oc-dv" id="dd-career_note">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">마지막진급</span><span class="oc-dv" id="dd-last_promotion">-</span></div>
                        <div class="oc-dr oc-dr-full"><span class="oc-dl">비고</span><span class="oc-dv" id="dd-emp_memo">-</span></div>
                    </div>
                </div>
                <?php if ($roleLevel <= 2): ?>
                <!-- 급여정보 -->
                <div class="oc-detail-section">
                    <div class="oc-detail-section-title"><i class="fa fa-money"></i> 급여정보</div>
                    <div class="oc-detail-grid oc-detail-grid--3">
                        <div class="oc-dr"><span class="oc-dl">기본급</span><span class="oc-dv" id="dd-salary_basic">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">자격수당</span><span class="oc-dv" id="dd-salary_qualification">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">직책수당</span><span class="oc-dv" id="dd-salary_part_position">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">근속수당</span><span class="oc-dv" id="dd-salary_position">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">연장(고정)</span><span class="oc-dv" id="dd-salary_overtime_fix">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">근무수당</span><span class="oc-dv" id="dd-salary_work">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">식대</span><span class="oc-dv" id="dd-salary_meal">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">자가운전</span><span class="oc-dv" id="dd-salary_car">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">기타</span><span class="oc-dv" id="dd-salary_etc">-</span></div>
                    </div>
                </div>
                <!-- 결제정보 -->
                <div class="oc-detail-section">
                    <div class="oc-detail-section-title"><i class="fa fa-bank"></i> 결제정보</div>
                    <div class="oc-detail-grid">
                        <div class="oc-dr"><span class="oc-dl">은행명</span><span class="oc-dv" id="dd-bank_name">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">예금주</span><span class="oc-dv" id="dd-bank_depositor">-</span></div>
                        <div class="oc-dr oc-dr-full"><span class="oc-dl">계좌번호</span><span class="oc-dv" id="dd-bank_account">-</span></div>
                    </div>
                </div>
                <!-- 카드정보 -->
                <div class="oc-detail-section">
                    <div class="oc-detail-section-title"><i class="fa fa-credit-card"></i> 카드정보</div>
                    <div class="oc-detail-grid">
                        <div class="oc-dr"><span class="oc-dl">카드명</span><span class="oc-dv" id="dd-card_name">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">카드번호</span><span class="oc-dv" id="dd-card_number">-</span></div>
                        <div class="oc-dr"><span class="oc-dl">비고</span><span class="oc-dv" id="dd-card_memo">-</span></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="ocDetailCloseBtn">닫기</button>
                <?php if ($roleLevel <= 2): ?>
                <button class="btn btn-primary" id="ocDetailEditBtn"><i class="fa fa-pencil mr-1"></i>수정</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 부서 추가/수정 모달 -->
    <div id="ocDeptModal" class="modal-overlay" style="display:none;">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <span id="ocDeptModalTitle">부서 추가</span>
                <button class="modal-close" id="ocDeptModalClose"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ocDeptIdx" value="0">
                <div class="form-group">
                    <label class="form-label">부서명 <span class="text-danger">*</span></label>
                    <input id="ocDeptName" type="text" class="form-input" placeholder="부서명 입력">
                </div>
                <div class="form-group">
                    <label class="form-label">부서코드</label>
                    <input id="ocDeptCode" type="text" class="form-input" placeholder="예: DEV, HR">
                </div>
                <div class="form-group">
                    <label class="form-label">상위 부서</label>
                    <select id="ocDeptParent" class="form-select">
                        <option value="0">없음 (최상위)</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= (int)($d['idx'] ?? 0) ?>"><?= grpOrgH((string)($d['dept_name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">정렬순서</label>
                    <input id="ocDeptSort" type="number" class="form-input" value="0" min="0">
                </div>
                <div id="ocDeptErr" class="text-danger text-sm mt-1 hidden"></div>
            </div>
            <div class="modal-footer">
                <?php if ($roleLevel <= 2): ?>
                <button id="ocDeptDelBtn" class="btn btn-danger btn-sm hidden">삭제</button>
                <?php endif; ?>
                <button class="btn btn-outline" id="ocDeptCancelBtn">취소</button>
                <button class="btn btn-primary" id="ocDeptSaveBtn">저장</button>
            </div>
        </div>
    </div>

    <!-- 직원 추가/수정 모달 -->
    <div id="ocEmpModal" class="modal-overlay" style="display:none;">
        <div class="modal-box modal-lg">
            <div class="modal-header">
                <span id="ocEmpModalTitle">직원 등록</span>
                <button class="modal-close" id="ocEmpModalClose"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body oc-emp-modal-body">
                <input type="hidden" id="ocEmpIdx" value="0">
                <!-- 사진 업로드 -->
                <div class="form-group oc-photo-area">
                    <label class="form-label">프로필 사진</label>
                    <div class="oc-photo-upload">
                        <div class="oc-photo-preview" id="ocEmpPhotoPreview">
                            <i class="fa fa-user-circle oc-photo-avatar"></i>
                        </div>
                        <div class="oc-photo-actions">
                            <button type="button" class="btn btn-outline btn-sm" id="ocPhotoPickBtn"><i class="fa fa-camera mr-1"></i>사진 선택</button>
                            <button type="button" class="btn btn-ghost btn-sm hidden" id="ocPhotoRemoveBtn"><i class="fa fa-times mr-1"></i>제거</button>
                            <p class="oc-photo-hint">JPG, PNG · 최대 5MB</p>
                        </div>
                    </div>
                    <input type="file" id="ocEmpPhotoFile" accept="image/*" class="hidden">
                    <input type="hidden" id="ocEmpPhotoUrl" value="">
                </div>
                <!-- 기본정보 -->
                <div class="oc-form-section-title"><i class="fa fa-user"></i> 기본정보</div>
                <div class="form-row form-row--3col">
                    <div class="form-group form-group--wide">
                        <label class="form-label">이름 <span class="text-danger">*</span></label>
                        <input id="ocEmpName" type="text" class="form-input" placeholder="홍길동">
                    </div>
                    <div class="form-group">
                        <label class="form-label">사번</label>
                        <input id="ocEmpNo" type="text" class="form-input" placeholder="EMP001">
                    </div>
                    <div class="form-group">
                        <label class="form-label">부서</label>
                        <select id="ocEmpDept" class="form-select">
                            <option value="0">미배정</option>
                            <?php foreach ($depts as $d): ?>
                            <option value="<?= (int)($d['idx'] ?? 0) ?>"><?= grpOrgH((string)($d['dept_name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">직급</label>
                        <input id="ocEmpPosition" type="text" class="form-input" placeholder="대리 / 과장 / 차장">
                    </div>
                    <div class="form-group">
                        <label class="form-label">직책</label>
                        <input id="ocEmpJobTitle" type="text" class="form-input" placeholder="팀장 / 사원">
                    </div>
                    <div class="form-group">
                        <label class="form-label">고용형태</label>
                        <input id="ocEmpEmpType" type="text" class="form-input" placeholder="정규직 / 계약직 / 일용직">
                    </div>
                    <div class="form-group">
                        <label class="form-label">재직상태</label>
                        <select id="ocEmpStatus" class="form-select">
                            <option value="ACTIVE">재직</option>
                            <option value="RESIGNED">퇴사</option>
                            <option value="LEAVE">휴직</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">생년월일</label>
                        <input id="ocEmpSocial" type="text" class="form-input" placeholder="YYMMDD" maxlength="6">
                    </div>
                    <div class="form-group">
                        <label class="form-label">입사일</label>
                        <input id="ocEmpHire" type="date" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">퇴사일</label>
                        <input id="ocEmpLeave" type="date" class="form-input">
                    </div>
                </div>
                <!-- 연락처 -->
                <div class="oc-form-section-title"><i class="fa fa-phone"></i> 연락처</div>
                <div class="form-row form-row--2col">
                    <div class="form-group">
                        <label class="form-label">H.P</label>
                        <input id="ocEmpPhone" type="text" class="form-input" placeholder="010-0000-0000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">TEL</label>
                        <input id="ocEmpTel" type="text" class="form-input" placeholder="02-0000-0000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">이메일</label>
                        <input id="ocEmpEmail" type="email" class="form-input" placeholder="user@company.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">개인메일</label>
                        <input id="ocEmpPersonalEmail" type="email" class="form-input" placeholder="user@gmail.com">
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label">주소</label>
                        <input id="ocEmpAddress" type="text" class="form-input" placeholder="주소 입력">
                    </div>
                </div>
                <!-- 기타정보 -->
                <div class="oc-form-section-title"><i class="fa fa-info-circle"></i> 기타정보</div>
                <div class="form-row form-row--2col">
                    <div class="form-group">
                        <label class="form-label">업무구분</label>
                        <input id="ocEmpWorkGubun" type="text" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">자격증</label>
                        <input id="ocEmpLicense" type="text" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">경력수첩</label>
                        <input id="ocEmpCareerNote" type="text" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">마지막진급</label>
                        <input id="ocEmpLastPromotion" type="date" class="form-input">
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label">비고</label>
                        <textarea id="ocEmpMemo" class="form-input" rows="2" placeholder="메모"></textarea>
                    </div>
                </div>
                <!-- 급여정보 -->
                <div class="oc-form-section-title"><i class="fa fa-money"></i> 급여정보</div>
                <div class="form-row form-row--3col">
                    <div class="form-group">
                        <label class="form-label">기본급</label>
                        <input id="ocEmpSalaryBasic" type="number" class="form-input" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">자격수당</label>
                        <input id="ocEmpSalaryQual" type="number" class="form-input" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">직책수당</label>
                        <input id="ocEmpSalaryPart" type="number" class="form-input" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">근속수당</label>
                        <input id="ocEmpSalaryPos" type="number" class="form-input" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">연장(고정)</label>
                        <input id="ocEmpSalaryOT" type="number" class="form-input" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">근무수당</label>
                        <input id="ocEmpSalaryWork" type="number" class="form-input" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">식대</label>
                        <input id="ocEmpSalaryMeal" type="number" class="form-input" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">자가운전</label>
                        <input id="ocEmpSalaryCar" type="number" class="form-input" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">기타</label>
                        <input id="ocEmpSalaryEtc" type="number" class="form-input" placeholder="0">
                    </div>
                </div>
                <!-- 결제정보 -->
                <div class="oc-form-section-title"><i class="fa fa-bank"></i> 결제정보</div>
                <div class="form-row form-row--2col">
                    <div class="form-group">
                        <label class="form-label">은행명</label>
                        <input id="ocEmpBankName" type="text" class="form-input" placeholder="국민은행">
                    </div>
                    <div class="form-group">
                        <label class="form-label">예금주</label>
                        <input id="ocEmpBankDepositor" type="text" class="form-input">
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label">계좌번호</label>
                        <input id="ocEmpBankAccount" type="text" class="form-input" placeholder="000-000000-00-000">
                    </div>
                </div>
                <!-- 카드정보 -->
                <div class="oc-form-section-title"><i class="fa fa-credit-card"></i> 카드정보</div>
                <div class="form-row form-row--3col">
                    <div class="form-group">
                        <label class="form-label">카드명</label>
                        <input id="ocEmpCardName" type="text" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">카드번호</label>
                        <input id="ocEmpCardNumber" type="text" class="form-input" placeholder="0000-0000-0000-0000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">비고</label>
                        <input id="ocEmpCardMemo" type="text" class="form-input">
                    </div>
                </div>
                <div id="ocEmpErr" class="text-danger text-sm mt-1 hidden"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="ocEmpCancelBtn">취소</button>
                <button class="btn btn-primary" id="ocEmpSaveBtn">저장</button>
            </div>
        </div>
    </div>

    <?php endif; ?>

    </div><!-- /.oc-main -->
</div><!-- /.oc-layout -->
</section>
<style>
/* ── 공통 래퍼 ── */
.oc-wrap {
    padding: 0 var(--sp-5);
}
.page-header-actions {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
}

/* ── 뷰 토글 버튼 ── */
.btn-view-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    color: var(--text-3);
    border: 1px solid var(--border-1);
    background: transparent;
    cursor: pointer;
    font-size: 14px;
    transition: background var(--duration-fast), color var(--duration-fast), border-color var(--duration-fast);
}
.btn-view-toggle:hover {
    background: var(--glass-bg-hover);
    color: var(--text-1);
}
.btn-view-toggle.on {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

/* ── 검색 박스 ── */
.oc-search-box {
    margin-top: var(--sp-3);
}
.oc-search-top {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
    padding: var(--sp-3);
}
.oc-search-main {
    flex: 1;
    min-width: 0;
}
.oc-search-detail {
    display: none;
    border-top: 1px solid var(--border-1);
    padding: var(--sp-3);
}
.oc-search-detail.open {
    display: block;
}
.oc-search-list {
    display: flex;
    flex-wrap: wrap;
    gap: var(--sp-2) var(--sp-5);
    list-style: none;
    margin: 0 0 var(--sp-3) 0;
    padding: 0;
}
.oc-search-list li {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
}
.oc-sl-tit {
    font-size: 13px;
    color: var(--text-2);
    white-space: nowrap;
    min-width: 52px;
}
.oc-sl-txt select {
    min-width: 140px;
}
.oc-search-btns {
    display: flex;
    gap: var(--sp-2);
}

/* ── 썸네일 카드 그리드 ── */
.oc-card-wrap {
    margin-top: var(--sp-3);
    margin-bottom: var(--sp-5);
}
.oc-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: var(--sp-3);
    padding-top: var(--sp-3);
}
.oc-card {
    background: var(--card-bg);
    border: 1px solid var(--border-1);
    border-radius: var(--radius-md);
    overflow: hidden;
    cursor: pointer;
    transition: box-shadow var(--duration-fast) var(--ease-default), transform var(--duration-fast);
}
.oc-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.oc-card-photo {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 130px;
    background: var(--glass-bg-hover);
    border-bottom: 1px solid var(--border-1);
    padding: var(--sp-3);
}
.oc-card-img {
    width: 90px;
    height: 110px;
    object-fit: cover;
    object-position: top center;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
}
.oc-card-avatar {
    font-size: 56px;
    color: var(--text-3);
}
.oc-card-info {
    list-style: none;
    margin: 0;
    padding: var(--sp-2) var(--sp-3) var(--sp-3);
    display: flex;
    flex-wrap: wrap;
    gap: 4px 0;
}
.oc-card-info li {
    width: 100%;
    display: flex;
    align-items: baseline;
    font-size: 12px;
    gap: var(--sp-1);
}
.oc-card-info li.oc-ci-half {
    width: 50%;
}
.oc-card-info li.oc-card-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-1);
    margin-bottom: 4px;
    align-items: center;
}
.oc-card-info li.oc-card-name .oc-card-status {
    font-size: 10px;
    margin-left: auto;
    flex-shrink: 0;
}
.oc-ci-tit {
    font-size: 11px;
    color: var(--text-3);
    min-width: 36px;
    flex-shrink: 0;
}
.oc-ci-txt {
    color: var(--text-2);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
    min-width: 0;
}
.oc-ci-email {
    font-size: 11px;
}
.oc-card-info li.oc-card-btns {
    margin-top: var(--sp-2);
    gap: var(--sp-1);
    border-top: 1px solid var(--border-1);
    padding-top: var(--sp-2);
}

/* ── 직원 상세 모달 ── */
.oc-detail-body {
    /* modal-body가 flex:1 + overflow-y:auto 처리 — 중복 제거 */
}
.oc-detail-top {
    display: flex;
    align-items: center;
    gap: var(--sp-4);
    padding-bottom: var(--sp-4);
    margin-bottom: var(--sp-4);
    border-bottom: 1px solid var(--border-1);
}
.oc-detail-photo-wrap {
    width: 90px;
    height: 110px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-1);
    background: var(--glass-bg-hover);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.oc-detail-photo-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: top center;
}
.oc-detail-avatar {
    font-size: 52px;
    color: var(--text-3);
}
.oc-detail-summary {
    display: flex;
    flex-direction: column;
    gap: var(--sp-1);
}
.oc-detail-name {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-1);
    margin: 0;
}
.oc-detail-sub {
    font-size: 13px;
    color: var(--text-2);
    margin: 0;
}
.oc-detail-section {
    margin-bottom: var(--sp-4);
}
.oc-detail-section-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--primary);
    padding: var(--sp-2) 0 var(--sp-2);
    border-bottom: 2px solid var(--primary);
    display: flex;
    align-items: center;
    gap: var(--sp-1);
    margin-bottom: var(--sp-2);
}
.oc-detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
}
.oc-detail-grid--3 {
    grid-template-columns: 1fr 1fr 1fr;
}
.oc-dr {
    display: flex;
    align-items: center;
    border-bottom: 1px solid var(--border-1);
    min-height: 34px;
}
.oc-dr-full {
    grid-column: 1 / -1;
}
.oc-dl {
    width: 80px;
    padding: 6px 10px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-3);
    background: var(--glass-bg-hover);
    flex-shrink: 0;
    white-space: nowrap;
}
.oc-dv {
    padding: 6px 10px;
    font-size: 12px;
    color: var(--text-1);
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ── 직원 편집 모달 ── */
.oc-emp-modal-body {
    /* modal-body가 flex:1 + overflow-y:auto 처리 — 중복 제거 */
}
.oc-form-section-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--primary);
    padding: var(--sp-2) 0;
    border-bottom: 2px solid var(--primary);
    display: flex;
    align-items: center;
    gap: var(--sp-1);
    margin: var(--sp-3) 0 var(--sp-2);
}
.form-row--3col {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: var(--sp-3);
}
.form-group--full {
    grid-column: 1 / -1;
}

/* ── 모달 사진 업로드 ── */
.oc-photo-area {
    margin-bottom: var(--sp-3);
}
.oc-photo-upload {
    display: flex;
    align-items: center;
    gap: var(--sp-4);
    margin-top: var(--sp-1);
}
.oc-photo-preview {
    width: 90px;
    height: 110px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-1);
    background: var(--glass-bg-hover);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.oc-photo-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: top center;
}
.oc-photo-avatar {
    font-size: 48px;
    color: var(--text-3);
}
.oc-photo-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--sp-1);
}
.oc-photo-hint {
    font-size: 11px;
    color: var(--text-3);
    margin: 0;
}

/* ── 리스트 뷰 ── */
.oc-list-btns {
    white-space: nowrap;
    display: flex;
    gap: var(--sp-1);
    justify-content: center;
}

/* ── 탭 (재직중 / 숨김) ── */
.oc-tab-row {
    margin-top: var(--sp-3);
}
.oc-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border-1);
}
.oc-tab {
    display: inline-flex;
    align-items: center;
    gap: var(--sp-1);
    padding: var(--sp-2) var(--sp-4);
    font-size: 13px;
    font-weight: 500;
    color: var(--text-2);
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    cursor: pointer;
    transition: color var(--duration-fast), border-color var(--duration-fast);
}
.oc-tab:hover {
    color: var(--text-1);
}
.oc-tab.on {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 600;
}
.oc-tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 700;
    background: var(--glass-bg-hover);
    color: var(--text-2);
}
.oc-tab.on .oc-tab-badge {
    background: var(--primary);
    color: #fff;
}
.oc-tab-badge--warn {
    background: #f59e0b;
    color: #fff;
}
.oc-tab.on .oc-tab-badge--warn {
    background: #f59e0b;
    color: #fff;
}

/* ── 숨김 직원 뷰 ── */
.oc-hidden-wrap {
    margin-top: var(--sp-3);
    margin-bottom: var(--sp-5);
}
.oc-card--hidden {
    opacity: 0.60;
    filter: grayscale(45%);
    cursor: default;
}
.oc-card--hidden:hover {
    opacity: 0.80;
    filter: grayscale(20%);
    transform: none;
    box-shadow: none;
}
.oc-hidden-label {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--text-3);
    flex: 1;
}

/* ── 반응형 ── */
@media (max-width: 1024px) {
    .oc-card-grid {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    }
}
@media (max-width: 768px) {
    .oc-wrap {
        padding: 0 var(--sp-3);
    }
    .oc-card-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--sp-2);
    }
    .page-header-actions {
        gap: var(--sp-1);
    }
    .oc-sidebar { display: none; }
}

/* ══════════ 레이아웃: 사이드바 + 메인 ══════════ */
.oc-layout {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 0;
    min-height: 0;
}
.oc-main {
    min-width: 0;
    overflow-x: hidden;
}

/* ── 사이드바 ── */
.oc-sidebar {
    border-right: 1px solid var(--border);
    background: var(--card-bg);
    overflow-y: auto;
    max-height: calc(100vh - 52px);
    position: sticky;
    top: 52px;
}
.oc-sb-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--sp-3) var(--sp-3) var(--sp-2);
    border-bottom: 1px solid var(--border);
}
.oc-sb-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-1);
}
.oc-sb-body {
    padding: var(--sp-2) 0;
}
.oc-sb-item {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
    padding: var(--sp-2) var(--sp-3);
    font-size: 12px;
    color: var(--text-2);
    cursor: pointer;
    transition: background var(--duration-fast);
    border-left: 3px solid transparent;
}
.oc-sb-item:hover {
    background: var(--bg-2);
}
.oc-sb-item--active {
    background: rgba(59,108,247,.06);
    color: var(--accent);
    font-weight: 600;
    border-left-color: var(--accent);
}
.oc-sb-item .fa {
    width: 14px;
    text-align: center;
    font-size: 11px;
}
.oc-sb-cnt {
    margin-left: auto;
    font-size: 10px;
    color: var(--text-3);
    background: var(--bg-2);
    padding: 1px 6px;
    border-radius: 8px;
}
.oc-sb-item--active .oc-sb-cnt {
    background: rgba(59,108,247,.12);
    color: var(--accent);
}
.oc-sb-toggle {
    display: none;
    padding: var(--sp-2) var(--sp-3);
    font-size: 11px;
    color: var(--text-3);
    cursor: pointer;
}
.oc-sb-children {
    overflow: hidden;
    transition: max-height var(--duration-normal);
}
.oc-sb-d2 { padding-left: calc(var(--sp-3) + 14px); }
.oc-sb-d3 { padding-left: calc(var(--sp-3) + 28px); }

/* ── 탭 뱃지 변형 ── */
.oc-tab-badge--ghost {
    background: var(--bg-2);
    color: var(--text-3);
}

/* ══════════ 트리 다이어그램 뷰 ══════════ */
.oc-tree-wrap {
    overflow-x: auto;
    padding: var(--sp-5);
}
.oc-tree-diagram {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: max-content;
}
/* 형제 부서 가로 배치 */
.oc-tree-peers {
    display: flex;
    flex-wrap: nowrap;
    gap: 0;
    justify-content: center;
    position: relative;
    align-items: flex-start;
}
/* 부서 노드 */
.oc-tree-node {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0 var(--sp-3);
    position: relative;
}
/* 수평 연결선 */
.oc-tree-peers > .oc-tree-node::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 50%;
    height: 2px;
    background: var(--border);
}
.oc-tree-peers > .oc-tree-node::after {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    right: 0;
    height: 2px;
    background: var(--border);
}
.oc-tree-peers > .oc-tree-node:first-child::before { display: none; }
.oc-tree-peers > .oc-tree-node:last-child::after { display: none; }
.oc-tree-peers > .oc-tree-node:only-child::before,
.oc-tree-peers > .oc-tree-node:only-child::after { display: none; }
/* 부서 헤더 */
.oc-tree-dept {
    padding: var(--sp-2) var(--sp-4);
    background: var(--accent);
    color: #fff;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: var(--sp-2);
    box-shadow: var(--shadow-sm);
    position: relative;
    white-space: nowrap;
    margin-top: 12px;
    cursor: pointer;
}
.oc-tree-dept::before {
    content: '';
    position: absolute;
    top: -12px;
    left: 50%;
    width: 2px;
    height: 12px;
    background: var(--border);
}
.oc-tree-dept--branch {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}
.oc-tree-dept-cnt {
    font-size: 10px;
    opacity: .8;
    font-weight: 400;
}
/* 수직 연결선 */
.oc-tree-vline {
    width: 2px;
    height: 12px;
    background: var(--border);
}
/* 직원 카드 */
.oc-tree-members {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}
.oc-tree-emp {
    padding: var(--sp-1) var(--sp-3);
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: var(--radius-xs);
    font-size: 11px;
    text-align: center;
    cursor: pointer;
    transition: all var(--duration-fast);
    min-width: 120px;
    white-space: nowrap;
}
.oc-tree-emp:hover {
    border-color: var(--accent);
    box-shadow: var(--shadow-sm);
    transform: translateY(-1px);
}
.oc-tree-emp-name {
    font-weight: 600;
    color: var(--text-1);
}
.oc-tree-emp-pos {
    font-size: 10px;
    color: var(--text-3);
}
/* 트리 루트(최상위 라벨) */
.oc-tree-root-label {
    padding: var(--sp-2) var(--sp-5);
    background: linear-gradient(135deg, var(--accent), #6366f1);
    color: #fff;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 700;
    box-shadow: var(--shadow-md);
    margin-bottom: var(--sp-1);
}

/* ══════════ 인라인 편집 ══════════ */
.oc-inl-editing .oc-inl-cell {
    cursor: pointer;
}
.oc-inl-editing .oc-inl-cell:hover {
    background: rgba(59,108,247,.04);
}
.oc-inl-input {
    width: 100%;
    padding: 2px var(--sp-1);
    border: 1px solid var(--accent);
    border-radius: var(--radius-xs);
    font-size: 12px;
    outline: none;
    box-sizing: border-box;
}

/* ══════════ 반응형: 사이드바 ══════════ */
@media (max-width: 1024px) {
    .oc-layout {
        grid-template-columns: 200px 1fr;
    }
}
@media (max-width: 768px) {
    .oc-layout {
        grid-template-columns: 1fr;
    }
}
</style>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="org-chart"]');
    if (!_section) return;

    var _apiUrl = 'dist_process/saas/Employee.php';
    var _mode   = '<?= $mode ?>';

    /* 헬퍼: HTML 이스케이프 */
    function escH(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    var _btnThumb = document.getElementById('oc-view-thumb');
    var _btnList  = document.getElementById('oc-view-list');
    if (_btnThumb) _btnThumb.addEventListener('click', function () { reload('thumbnail'); });
    if (_btnList)  _btnList.addEventListener('click',  function () { reload('list'); });

    /* ── 검색 상세 토글 ── */
    var _detailBox = document.getElementById('ocSearchDetail');
    var _detailBtn = document.getElementById('ocSearchDetailBtn');
    if (_detailBtn) {
        _detailBtn.addEventListener('click', function () {
            _detailBox.classList.toggle('open');
        });
    }

    /* ── 조회 ── */
    function reload(mode) {
        SHV.router.navigate('org_chart', {
            mode:     mode || _mode,
            search:   (document.getElementById('ocSearchInput') || {}).value || '',
            dept_idx: (document.getElementById('ocDeptSel') || {}).value || '0',
            status:   (document.getElementById('ocStatus') || {}).value || '',
        });
    }

    var _searchBtn = document.getElementById('ocSearchBtn');
    var _resetBtn  = document.getElementById('ocResetBtn');
    if (_searchBtn) _searchBtn.addEventListener('click', function () { reload(); });
    if (_resetBtn)  _resetBtn.addEventListener('click',  function () { SHV.router.navigate('org_chart', { mode: _mode, status: 'ACTIVE' }); });

    var _searchInput = document.getElementById('ocSearchInput');
    if (_searchInput) {
        _searchInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') reload(); });
    }

    /* ── 탭 전환 (재직중 / 숨김) ── */
    var _tabActive  = document.getElementById('ocTabActive');
    var _tabHidden  = document.getElementById('ocTabHidden');
    var _hiddenView = document.getElementById('ocHiddenView');
    var _viewThumb  = document.getElementById('ocViewThumb');
    var _viewList   = document.getElementById('ocViewList');

    /* switchTab → switchAllTab 으로 대체 (하단 확장 코드에서 처리) */

    /* ── 숨김 토글 ── */
    function toggleHidden(empIdx, hidden) {
        var msg = hidden
            ? '이 직원을 숨기시겠습니까?\n숨김 처리된 직원은 전체 직원 선택기에서 제외됩니다.'
            : '숨김을 해제하시겠습니까?';
        shvConfirm({ message: msg, type: hidden ? 'warn' : 'primary' }).then(function (ok) {
            if (!ok) return;
            SHV.api.post(_apiUrl, { todo: 'toggle_hidden', idx: empIdx, is_hidden: hidden ? 1 : 0 })
                .then(function (res) {
                    if (!res || !res.ok) {
                        SHV.toast.error(res && res.message ? res.message : '처리 실패');
                        return;
                    }
                    SHV.router.navigate('org_chart', { mode: _mode });
                });
        });
    }

    /* ── 카드/행 클릭 → 상세 모달, 수정/삭제 버튼 ── */
    var _statusLabels = { ACTIVE: '재직', RESIGNED: '퇴사', INACTIVE: '퇴사'/* legacy */, LEAVE: '휴직' };
    var _statusBadge  = { ACTIVE: 'badge-success', RESIGNED: 'badge-danger', INACTIVE: 'badge-danger'/* legacy */, LEAVE: 'badge-warn' };

    _section.addEventListener('click', function (e) {
        /* 수정 버튼 */
        var editBtn = e.target.closest('.oc-edit-emp');
        if (editBtn) {
            e.stopPropagation();
            var empIdx = parseInt(editBtn.dataset.empIdx, 10);
            SHV.api.get(_apiUrl, { todo: 'employee_detail', idx: empIdx })
                .then(function (res) {
                    if (res && res.ok && res.data && res.data.item) { openEmpModal(res.data.item); }
                });
            return;
        }
        /* 숨김 버튼 */
        var hideBtn = e.target.closest('.oc-hide-emp');
        if (hideBtn) {
            e.stopPropagation();
            toggleHidden(parseInt(hideBtn.dataset.empIdx, 10), 1);
            return;
        }
        /* 숨김 해제 버튼 */
        var unhideBtn = e.target.closest('.oc-unhide-emp');
        if (unhideBtn) {
            e.stopPropagation();
            toggleHidden(parseInt(unhideBtn.dataset.empIdx, 10), 0);
            return;
        }
        /* 삭제 버튼 */
        var delBtn = e.target.closest('.oc-del-emp');
        if (delBtn) {
            e.stopPropagation();
            var empIdx = parseInt(delBtn.dataset.empIdx, 10);
            shvConfirm({ message: '해당 직원을 삭제하시겠습니까?', type: 'danger' }).then(function (ok) {
                if (!ok) return;
                SHV.api.post(_apiUrl, { todo: 'delete_employee', idx: empIdx })
                    .then(function (res) {
                        if (!res || !res.ok) { SHV.toast.error(res && res.message ? res.message : '삭제 실패'); return; }
                        SHV.router.navigate('org_chart', { mode: _mode });
                    });
            });
            return;
        }
        /* 카드/행 클릭 → 상세 (숨김 카드는 제외) */
        var card = e.target.closest('.oc-card:not(.oc-card--hidden), [data-emp-idx]');
        if (card && !card.classList.contains('oc-card--hidden')) {
            var empIdx = parseInt(card.dataset.empIdx, 10);
            if (empIdx > 0) {
                SHV.router.navigate('emp_detail', { idx: empIdx });
            }
        }
    });

    /* ── 상세 모달 ── */
    var _detailModal = document.getElementById('ocEmpDetailModal');
    var _detailEmp   = null;

    function dv(emp, key, fallback) {
        var v = emp ? (emp[key] || '') : '';
        return v || (fallback !== undefined ? fallback : '-');
    }
    function dvNum(emp, key) {
        var v = parseInt(emp ? (emp[key] || 0) : 0, 10);
        return v > 0 ? v.toLocaleString() : '-';
    }
    function dvDate(emp, key) {
        var v = emp ? (emp[key] || '') : '';
        return v && v > '1900' ? v.slice(0, 10) : '-';
    }

    function setPhotoEl(wrap, url, alt) {
        wrap.innerHTML = '';
        if (url) {
            var img = document.createElement('img');
            img.src = url;
            img.alt = alt || '';
            wrap.appendChild(img);
        } else {
            var icon = document.createElement('i');
            icon.className = 'fa fa-user-circle oc-detail-avatar';
            wrap.appendChild(icon);
        }
    }

    function openDetailModal(emp) {
        _detailEmp = emp;
        /* 상단 요약 */
        setPhotoEl(document.getElementById('ocDetailPhotoWrap'), emp.photo_url, emp.emp_name);
        document.getElementById('ocDetailName').textContent = emp.emp_name || '-';
        document.getElementById('ocDetailSub').textContent  =
            [emp.dept_name, emp.position_name, emp.job_title].filter(Boolean).join(' · ') || '-';
        var badge = document.getElementById('ocDetailBadge');
        var st = emp.status || 'ACTIVE';
        badge.textContent  = _statusLabels[st] || st;
        badge.className    = 'badge ' + (_statusBadge[st] || 'badge-ghost');
        /* 기본정보 */
        document.getElementById('dd-emp_no').textContent         = dv(emp, 'emp_no');
        document.getElementById('dd-dept_name').textContent      = dv(emp, 'dept_name');
        document.getElementById('dd-position_name').textContent  = dv(emp, 'position_name');
        document.getElementById('dd-job_title').textContent      = dv(emp, 'job_title');
        document.getElementById('dd-employment_type').textContent = dv(emp, 'employment_type');
        document.getElementById('dd-social_number').textContent  = dv(emp, 'social_number');
        document.getElementById('dd-hire_date').textContent      = dvDate(emp, 'hire_date');
        document.getElementById('dd-leave_date').textContent     = dvDate(emp, 'leave_date');
        /* 연락처 */
        document.getElementById('dd-phone').textContent          = dv(emp, 'phone');
        document.getElementById('dd-tel').textContent            = dv(emp, 'tel');
        document.getElementById('dd-email').textContent          = dv(emp, 'email');
        document.getElementById('dd-personal_email').textContent = dv(emp, 'personal_email');
        document.getElementById('dd-address').textContent        = dv(emp, 'address');
        /* 기타정보 */
        document.getElementById('dd-work_gubun').textContent     = dv(emp, 'work_gubun');
        document.getElementById('dd-license').textContent        = dv(emp, 'license');
        document.getElementById('dd-career_note').textContent    = dv(emp, 'career_note');
        document.getElementById('dd-last_promotion').textContent = dvDate(emp, 'last_promotion');
        document.getElementById('dd-emp_memo').textContent       = dv(emp, 'emp_memo');
        /* 급여/결제/카드 (관리자) */
        var dds = document.getElementById('dd-salary_basic');
        if (dds) {
            document.getElementById('dd-salary_basic').textContent          = dvNum(emp, 'salary_basic');
            document.getElementById('dd-salary_qualification').textContent  = dvNum(emp, 'salary_qualification');
            document.getElementById('dd-salary_part_position').textContent  = dvNum(emp, 'salary_part_position');
            document.getElementById('dd-salary_position').textContent       = dvNum(emp, 'salary_position');
            document.getElementById('dd-salary_overtime_fix').textContent   = dvNum(emp, 'salary_overtime_fix');
            document.getElementById('dd-salary_work').textContent           = dvNum(emp, 'salary_work');
            document.getElementById('dd-salary_meal').textContent           = dvNum(emp, 'salary_meal');
            document.getElementById('dd-salary_car').textContent            = dvNum(emp, 'salary_car');
            document.getElementById('dd-salary_etc').textContent            = dvNum(emp, 'salary_etc');
            document.getElementById('dd-bank_name').textContent             = dv(emp, 'bank_name');
            document.getElementById('dd-bank_depositor').textContent        = dv(emp, 'bank_depositor');
            document.getElementById('dd-bank_account').textContent          = dv(emp, 'bank_account');
            document.getElementById('dd-card_name').textContent             = dv(emp, 'card_name');
            document.getElementById('dd-card_number').textContent           = dv(emp, 'card_number');
            document.getElementById('dd-card_memo').textContent             = dv(emp, 'card_memo');
        }
        document.getElementById('ocDetailTitle').textContent = (emp.emp_name || '') + ' 상세';
        _detailModal.style.display = 'flex';
    }
    function closeDetailModal() { _detailModal.style.display = 'none'; _detailEmp = null; }

    document.getElementById('ocDetailClose').addEventListener('click', closeDetailModal);
    document.getElementById('ocDetailCloseBtn').addEventListener('click', closeDetailModal);
    _detailModal.addEventListener('click', function (e) { if (e.target === _detailModal) closeDetailModal(); });

    var _detailEditBtn = document.getElementById('ocDetailEditBtn');
    if (_detailEditBtn) {
        _detailEditBtn.addEventListener('click', function () {
            closeDetailModal();
            if (_detailEmp) openEmpModal(_detailEmp);
        });
    }

    /* ── 직원 편집 모달 ── */
    var _empModal = document.getElementById('ocEmpModal');

    function fv(id, val) {
        var el = document.getElementById(id);
        if (el) el.value = val !== null && val !== undefined ? val : '';
    }

    function openEmpModal(emp) {
        fv('ocEmpIdx',           emp ? emp.idx : 0);
        fv('ocEmpName',          emp ? emp.emp_name : '');
        fv('ocEmpNo',            emp ? emp.emp_no : '');
        fv('ocEmpDept',          emp ? emp.dept_idx : 0);
        fv('ocEmpPosition',      emp ? emp.position_name : '');
        fv('ocEmpJobTitle',      emp ? emp.job_title : '');
        fv('ocEmpEmpType',       emp ? emp.employment_type : '');
        fv('ocEmpStatus',        emp ? (emp.status || 'ACTIVE') : 'ACTIVE');
        fv('ocEmpSocial',        emp ? emp.social_number : '');
        fv('ocEmpHire',          emp && emp.hire_date && emp.hire_date > '1900' ? emp.hire_date.slice(0, 10) : '');
        fv('ocEmpLeave',         emp && emp.leave_date && emp.leave_date > '1900' ? emp.leave_date.slice(0, 10) : '');
        fv('ocEmpPhone',         emp ? emp.phone : '');
        fv('ocEmpTel',           emp ? emp.tel : '');
        fv('ocEmpEmail',         emp ? emp.email : '');
        fv('ocEmpPersonalEmail', emp ? emp.personal_email : '');
        fv('ocEmpAddress',       emp ? emp.address : '');
        fv('ocEmpWorkGubun',     emp ? emp.work_gubun : '');
        fv('ocEmpLicense',       emp ? emp.license : '');
        fv('ocEmpCareerNote',    emp ? emp.career_note : '');
        fv('ocEmpLastPromotion', emp && emp.last_promotion && emp.last_promotion > '1900' ? emp.last_promotion.slice(0, 10) : '');
        fv('ocEmpMemo',          emp ? emp.emp_memo : '');
        fv('ocEmpSalaryBasic',   emp ? (emp.salary_basic || '') : '');
        fv('ocEmpSalaryQual',    emp ? (emp.salary_qualification || '') : '');
        fv('ocEmpSalaryPart',    emp ? (emp.salary_part_position || '') : '');
        fv('ocEmpSalaryPos',     emp ? (emp.salary_position || '') : '');
        fv('ocEmpSalaryOT',      emp ? (emp.salary_overtime_fix || '') : '');
        fv('ocEmpSalaryWork',    emp ? (emp.salary_work || '') : '');
        fv('ocEmpSalaryMeal',    emp ? (emp.salary_meal || '') : '');
        fv('ocEmpSalaryCar',     emp ? (emp.salary_car || '') : '');
        fv('ocEmpSalaryEtc',     emp ? (emp.salary_etc || '') : '');
        fv('ocEmpBankName',      emp ? emp.bank_name : '');
        fv('ocEmpBankDepositor', emp ? emp.bank_depositor : '');
        fv('ocEmpBankAccount',   emp ? emp.bank_account : '');
        fv('ocEmpCardName',      emp ? emp.card_name : '');
        fv('ocEmpCardNumber',    emp ? emp.card_number : '');
        fv('ocEmpCardMemo',      emp ? emp.card_memo : '');
        document.getElementById('ocEmpModalTitle').textContent = emp ? '직원 수정' : '직원 등록';
        document.getElementById('ocEmpErr').textContent = '';
        document.getElementById('ocEmpErr').classList.add('hidden');
        /* 사진 */
        var photoUrl = emp ? (emp.photo_url || '') : '';
        fv('ocEmpPhotoUrl', photoUrl);
        document.getElementById('ocEmpPhotoFile').value = '';
        var preview = document.getElementById('ocEmpPhotoPreview');
        preview.innerHTML = '';
        if (photoUrl) {
            var pImg = document.createElement('img');
            pImg.src = photoUrl;
            pImg.alt = '프로필';
            preview.appendChild(pImg);
            document.getElementById('ocPhotoRemoveBtn').classList.remove('hidden');
        } else {
            var pIcon = document.createElement('i');
            pIcon.className = 'fa fa-user-circle oc-photo-avatar';
            preview.appendChild(pIcon);
            document.getElementById('ocPhotoRemoveBtn').classList.add('hidden');
        }
        _empModal.style.display = 'flex';
    }
    function closeEmpModal() { _empModal.style.display = 'none'; }

    var _addEmpBtn  = document.getElementById('oc-btn-add-emp');
    var _addEmpBtn2 = document.getElementById('oc-btn-add-emp2');
    if (_addEmpBtn)  _addEmpBtn.addEventListener('click',  function () { openEmpModal(null); });
    if (_addEmpBtn2) _addEmpBtn2.addEventListener('click', function () { openEmpModal(null); });
    document.getElementById('ocEmpModalClose').addEventListener('click',  closeEmpModal);
    document.getElementById('ocEmpCancelBtn').addEventListener('click',  closeEmpModal);
    _empModal.addEventListener('click', function (e) { if (e.target === _empModal) closeEmpModal(); });

    /* 사진 선택 / 미리보기 / 제거 */
    var _photoFileInput = document.getElementById('ocEmpPhotoFile');
    document.getElementById('ocPhotoPickBtn').addEventListener('click', function () { _photoFileInput.click(); });
    document.getElementById('ocPhotoRemoveBtn').addEventListener('click', function () {
        _photoFileInput.value = '';
        fv('ocEmpPhotoUrl', '');
        var prev = document.getElementById('ocEmpPhotoPreview');
        prev.innerHTML = '';
        var ic = document.createElement('i');
        ic.className = 'fa fa-user-circle oc-photo-avatar';
        prev.appendChild(ic);
        this.classList.add('hidden');
    });
    _photoFileInput.addEventListener('change', function () {
        if (!this.files || !this.files[0]) return;
        var reader = new FileReader();
        var preview = document.getElementById('ocEmpPhotoPreview');
        reader.onload = function (ev) {
            preview.innerHTML = '';
            var img = document.createElement('img');
            img.src = ev.target.result;
            img.alt = '미리보기';
            preview.appendChild(img);
            document.getElementById('ocPhotoRemoveBtn').classList.remove('hidden');
        };
        reader.readAsDataURL(this.files[0]);
    });

    function g(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }

    document.getElementById('ocEmpSaveBtn').addEventListener('click', function () {
        var name = g('ocEmpName');
        if (!name) { showEmpErr('이름을 입력해주세요.'); return; }
        var empIdx = parseInt(g('ocEmpIdx'), 10);
        var data = {
            todo:                 empIdx > 0 ? 'update_employee' : 'insert_employee',
            idx:                  empIdx,
            emp_name:             name,
            emp_no:               g('ocEmpNo'),
            dept_idx:             g('ocEmpDept'),
            position_name:        g('ocEmpPosition'),
            job_title:            g('ocEmpJobTitle'),
            employment_type:      g('ocEmpEmpType'),
            status:               g('ocEmpStatus'),
            social_number:        g('ocEmpSocial'),
            hire_date:            g('ocEmpHire'),
            leave_date:           g('ocEmpLeave'),
            phone:                g('ocEmpPhone'),
            tel:                  g('ocEmpTel'),
            email:                g('ocEmpEmail'),
            personal_email:       g('ocEmpPersonalEmail'),
            address:              g('ocEmpAddress'),
            work_gubun:           g('ocEmpWorkGubun'),
            license:              g('ocEmpLicense'),
            career_note:          g('ocEmpCareerNote'),
            last_promotion:       g('ocEmpLastPromotion'),
            emp_memo:             g('ocEmpMemo'),
            salary_basic:         g('ocEmpSalaryBasic'),
            salary_qualification: g('ocEmpSalaryQual'),
            salary_part_position: g('ocEmpSalaryPart'),
            salary_position:      g('ocEmpSalaryPos'),
            salary_overtime_fix:  g('ocEmpSalaryOT'),
            salary_work:          g('ocEmpSalaryWork'),
            salary_meal:          g('ocEmpSalaryMeal'),
            salary_car:           g('ocEmpSalaryCar'),
            salary_etc:           g('ocEmpSalaryEtc'),
            bank_name:            g('ocEmpBankName'),
            bank_depositor:       g('ocEmpBankDepositor'),
            bank_account:         g('ocEmpBankAccount'),
            card_name:            g('ocEmpCardName'),
            card_number:          g('ocEmpCardNumber'),
            card_memo:            g('ocEmpCardMemo'),
        };
        SHV.api.post(_apiUrl, data)
            .then(function (res) {
                if (!res || !res.ok) { showEmpErr(res && res.message ? res.message : '저장 실패'); return; }
                var savedIdx = (res.data && res.data.item && res.data.item.idx) ? res.data.item.idx : empIdx;
                var photoFile = document.getElementById('ocEmpPhotoFile');
                if (photoFile.files && photoFile.files[0] && savedIdx > 0) {
                    var fd = new FormData();
                    fd.append('todo', 'upload_photo');
                    fd.append('employee_id', savedIdx);
                    fd.append('photo', photoFile.files[0]);
                    return SHV.api.upload(_apiUrl, fd).then(function () {
                        closeEmpModal();
                        SHV.router.navigate('org_chart', { mode: _mode });
                    }).catch(function () {
                        closeEmpModal();
                        SHV.router.navigate('org_chart', { mode: _mode });
                    });
                }
                closeEmpModal();
                SHV.router.navigate('org_chart', { mode: _mode });
            })
            .catch(function () { showEmpErr('서버 오류가 발생했습니다.'); });
    });

    function showEmpErr(msg) {
        var el = document.getElementById('ocEmpErr');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    /* ── 부서 모달 ── */
    var _deptModal = document.getElementById('ocDeptModal');

    function openDeptModal(dept) {
        document.getElementById('ocDeptIdx').value    = dept ? dept.idx : 0;
        document.getElementById('ocDeptName').value   = dept ? dept.dept_name : '';
        document.getElementById('ocDeptCode').value   = dept ? dept.dept_code : '';
        document.getElementById('ocDeptParent').value = dept ? dept.parent_idx : 0;
        document.getElementById('ocDeptSort').value   = dept ? (dept.sort_order || 0) : 0;
        document.getElementById('ocDeptModalTitle').textContent = dept ? '부서 수정' : '부서 추가';
        document.getElementById('ocDeptErr').textContent = '';
        document.getElementById('ocDeptErr').classList.add('hidden');
        var delBtn = document.getElementById('ocDeptDelBtn');
        if (delBtn) delBtn.classList.toggle('hidden', !dept);
        _deptModal.style.display = 'flex';
    }
    function closeDeptModal() { _deptModal.style.display = 'none'; }

    var _addDeptBtn = document.getElementById('oc-btn-add-dept');
    if (_addDeptBtn) _addDeptBtn.addEventListener('click', function () { openDeptModal(null); });
    document.getElementById('ocDeptModalClose').addEventListener('click', closeDeptModal);
    document.getElementById('ocDeptCancelBtn').addEventListener('click', closeDeptModal);
    _deptModal.addEventListener('click', function (e) { if (e.target === _deptModal) closeDeptModal(); });

    document.getElementById('ocDeptSaveBtn').addEventListener('click', function () {
        var name = document.getElementById('ocDeptName').value.trim();
        if (!name) { showDeptErr('부서명을 입력해주세요.'); return; }
        var deptIdx = parseInt(document.getElementById('ocDeptIdx').value, 10);
        var data = {
            todo:       deptIdx > 0 ? 'dept_update' : 'dept_insert',
            idx:        deptIdx,
            dept_name:  name,
            dept_code:  document.getElementById('ocDeptCode').value.trim(),
            parent_idx: document.getElementById('ocDeptParent').value,
            sort_order: document.getElementById('ocDeptSort').value,
        };
        SHV.api.post(_apiUrl, data)
            .then(function (res) {
                if (!res || !res.ok) { showDeptErr(res && res.message ? res.message : '저장 실패'); return; }
                closeDeptModal();
                SHV.router.navigate('org_chart', { mode: _mode });
            })
            .catch(function () { showDeptErr('서버 오류가 발생했습니다.'); });
    });

    var _delDeptBtn = document.getElementById('ocDeptDelBtn');
    if (_delDeptBtn) {
        _delDeptBtn.addEventListener('click', function () {
            var deptIdx = parseInt(document.getElementById('ocDeptIdx').value, 10);
            if (!deptIdx) return;
            shvConfirm({ message: '해당 부서를 삭제하시겠습니까?<br>소속 직원은 미배정 처리됩니다.', type: 'danger' }).then(function (ok) {
                if (!ok) return;
                SHV.api.post(_apiUrl, { todo: 'dept_delete', dept_id: deptIdx })
                    .then(function (res) {
                        if (!res || !res.ok) { showDeptErr(res && res.message ? res.message : '삭제 실패'); return; }
                        closeDeptModal();
                        SHV.router.navigate('org_chart', { mode: _mode });
                    });
            });
        });
    }

    function showDeptErr(msg) {
        var el = document.getElementById('ocDeptErr');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    /* ══════════════════════════════════════════════════════════
     *  A-1. 부서 사이드바 트리 빌드
     * ══════════════════════════════════════════════════════════ */
    var _sbBody  = document.getElementById('ocSbBody');
    var _depts   = [];
    try { _depts = JSON.parse(_section.dataset.depts || '[]'); } catch(e) { _depts = []; }

    function buildSidebarTree() {
        if (!_sbBody || !_depts.length) return;
        var map = {}, roots = [];
        for (var i = 0; i < _depts.length; i++) {
            var d = _depts[i];
            d.children = [];
            map[d.idx] = d;
        }
        for (var j = 0; j < _depts.length; j++) {
            var dd = _depts[j];
            var pid = parseInt(dd.parent_idx, 10) || 0;
            if (pid > 0 && map[pid]) map[pid].children.push(dd);
            else roots.push(dd);
        }
        roots.sort(function(a,b){ return (a.sort_order||0) - (b.sort_order||0); });

        var html = '<div class="oc-sb-item oc-sb-item--active" data-dept-idx="0">'
            + '<i class="fa fa-folder-open"></i> 전체 <span class="oc-sb-cnt">' + (_section.querySelectorAll('.oc-card').length || 0) + '</span></div>';
        html += renderSbNodes(roots, 1);
        _sbBody.innerHTML = html;

        _sbBody.addEventListener('click', function(e) {
            var item = e.target.closest('.oc-sb-item');
            if (!item) return;
            var deptIdx = parseInt(item.dataset.deptIdx, 10) || 0;
            _sbBody.querySelectorAll('.oc-sb-item').forEach(function(el){ el.classList.remove('oc-sb-item--active'); });
            item.classList.add('oc-sb-item--active');
            /* 필터 연동 */
            var deptSel = document.getElementById('ocDeptSel');
            if (deptSel) deptSel.value = deptIdx;
            reload();
        });
    }

    function renderSbNodes(nodes, depth) {
        var html = '';
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            var cls = depth === 2 ? ' oc-sb-d2' : (depth === 3 ? ' oc-sb-d3' : '');
            var icon = n.children.length > 0 ? 'fa-folder' : 'fa-folder-o';
            var cnt = n.employee_count || n.emp_count || 0;
            html += '<div class="oc-sb-item' + cls + '" data-dept-idx="' + n.idx + '">';
            html += '<i class="fa ' + icon + '"></i> ' + escH(n.dept_name);
            if (cnt > 0) html += ' <span class="oc-sb-cnt">' + cnt + '</span>';
            html += '</div>';
            if (n.children.length > 0) {
                n.children.sort(function(a,b){ return (a.sort_order||0) - (b.sort_order||0); });
                html += renderSbNodes(n.children, depth + 1);
            }
        }
        return html;
    }

    buildSidebarTree();

    /* 사이드바 토글 */
    var _sbToggle = document.getElementById('ocSbToggle');
    var _sidebar  = document.getElementById('ocSidebar');
    if (_sbToggle && _sidebar) {
        _sbToggle.addEventListener('click', function() {
            _sidebar.classList.toggle('hidden');
            var layout = _section.querySelector('.oc-layout');
            if (layout) layout.style.gridTemplateColumns = _sidebar.classList.contains('hidden') ? '1fr' : '';
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  A-2. 트리 다이어그램 뷰
     * ══════════════════════════════════════════════════════════ */
    var _treeView    = document.getElementById('ocTreeView');
    var _treeDiagram = document.getElementById('ocTreeDiagram');
    var _treeLoaded  = false;

    function loadTreeView() {
        if (_treeLoaded || !_treeDiagram) return;
        _treeLoaded = true;

        Promise.all([
            SHV.api.get(_apiUrl, { todo: 'dept_list' }),
            SHV.api.get(_apiUrl, { todo: 'employee_list', status: 'ACTIVE', limit: 500 })
        ]).then(function(results) {
            var deptRes = results[0], empRes = results[1];
            var depts = (deptRes && deptRes.ok && deptRes.data) ? deptRes.data.items : [];
            var emps  = (empRes && empRes.ok && empRes.data) ? empRes.data.items : [];
            renderTreeDiagram(depts, emps);
        }).catch(function() {
            _treeDiagram.innerHTML = '<div class="empty-state mt-4"><p class="empty-message">조직도를 불러올 수 없습니다.</p></div>';
        });
    }

    function renderTreeDiagram(depts, emps) {
        if (!depts || depts.length === 0) {
            _treeDiagram.innerHTML = '<div class="empty-state mt-4"><p class="empty-message">부서가 없습니다.</p></div>';
            return;
        }
        /* 부서 트리 빌드 */
        var map = {}, roots = [];
        for (var i = 0; i < depts.length; i++) {
            depts[i].children = [];
            depts[i]._emps = [];
            map[depts[i].idx] = depts[i];
        }
        for (var j = 0; j < depts.length; j++) {
            var pid = parseInt(depts[j].parent_idx, 10) || 0;
            if (pid > 0 && map[pid]) map[pid].children.push(depts[j]);
            else roots.push(depts[j]);
        }
        roots.sort(function(a,b){ return (a.sort_order||0) - (b.sort_order||0); });

        /* 직원 → 부서 매핑 */
        for (var k = 0; k < emps.length; k++) {
            var dIdx = parseInt(emps[k].dept_idx, 10) || 0;
            if (dIdx > 0 && map[dIdx]) map[dIdx]._emps.push(emps[k]);
        }

        var html = '<div class="oc-tree-root-label"><i class="fa fa-building"></i> 조직도</div>';
        html += '<div class="oc-tree-vline"></div>';
        html += '<div class="oc-tree-peers">';
        for (var r = 0; r < roots.length; r++) {
            html += renderTreeNode(roots[r]);
        }
        html += '</div>';
        _treeDiagram.innerHTML = html;

        /* 직원 카드 클릭 → emp_detail */
        _treeDiagram.addEventListener('click', function(e) {
            var empCard = e.target.closest('.oc-tree-emp');
            if (empCard) {
                var idx = parseInt(empCard.dataset.empIdx, 10);
                if (idx > 0) SHV.router.navigate('emp_detail', { idx: idx });
            }
        });
    }

    function renderTreeNode(dept) {
        var isBranch = parseInt(dept.dept_type || dept.type || 0, 10) === 1;
        var deptCls = isBranch ? ' oc-tree-dept--branch' : '';
        var icon = isBranch ? 'fa-building-o' : 'fa-users';
        var empCount = dept._emps ? dept._emps.length : 0;

        var h = '<div class="oc-tree-node">';
        h += '<div class="oc-tree-dept' + deptCls + '">';
        h += '<i class="fa ' + icon + '"></i> ' + escH(dept.dept_name);
        h += ' <span class="oc-tree-dept-cnt">(' + empCount + ')</span>';
        h += '</div>';

        /* 직원 목록 */
        if (dept._emps && dept._emps.length > 0) {
            h += '<div class="oc-tree-vline"></div>';
            h += '<div class="oc-tree-members">';
            for (var i = 0; i < dept._emps.length; i++) {
                var e = dept._emps[i];
                h += '<div class="oc-tree-emp" data-emp-idx="' + (e.idx || '') + '">';
                h += '<div class="oc-tree-emp-name">' + escH(e.emp_name) + '</div>';
                var pos = [];
                if (e.position_name) pos.push(e.position_name);
                if (e.job_title) pos.push(e.job_title);
                if (pos.length) h += '<div class="oc-tree-emp-pos">' + escH(pos.join(' / ')) + '</div>';
                h += '</div>';
            }
            h += '</div>';
        }

        /* 자식 부서 재귀 */
        if (dept.children && dept.children.length > 0) {
            dept.children.sort(function(a,b){ return (a.sort_order||0) - (b.sort_order||0); });
            h += '<div class="oc-tree-vline"></div>';
            h += '<div class="oc-tree-peers">';
            for (var c = 0; c < dept.children.length; c++) {
                h += renderTreeNode(dept.children[c]);
            }
            h += '</div>';
        }

        h += '</div>';
        return h;
    }

    /* ══════════════════════════════════════════════════════════
     *  A-3. 미배정 직원 뷰
     * ══════════════════════════════════════════════════════════ */
    var _unassignedView = document.getElementById('ocUnassignedView');
    var _unassignedBody = document.getElementById('ocUnassignedBody');
    var _unassignedLoaded = false;

    function loadUnassigned() {
        if (_unassignedLoaded || !_unassignedBody) return;
        _unassignedLoaded = true;

        SHV.api.get(_apiUrl, { todo: 'employee_list', unassigned: 1, limit: 300 })
            .then(function(res) {
                var items = (res && res.ok && res.data) ? res.data.items : [];
                /* 미배정 카운트 업데이트 */
                var cntEl = document.getElementById('ocTabUnassignedCnt');
                if (cntEl) cntEl.textContent = items.length;

                if (!items.length) {
                    _unassignedBody.innerHTML = '<tr><td colspan="7" class="td-center td-muted">미배정 직원이 없습니다.</td></tr>';
                    return;
                }
                var html = '';
                for (var i = 0; i < items.length; i++) {
                    var e = items[i];
                    var st = e.status || 'ACTIVE';
                    var stLbl = { ACTIVE:'재직', RESIGNED:'퇴사', LEAVE:'휴직' }[st] || st;
                    var badge = { ACTIVE:'badge-success', RESIGNED:'badge-danger', LEAVE:'badge-warn' }[st] || 'badge-ghost';
                    html += '<tr data-emp-idx="' + (e.idx||'') + '" style="cursor:pointer">';
                    html += '<td class="td-mono">' + escH(e.emp_no) + '</td>';
                    html += '<td class="font-semibold">' + escH(e.emp_name) + '</td>';
                    html += '<td>' + escH(e.position_name || '-') + '</td>';
                    html += '<td>' + escH(e.job_title || '-') + '</td>';
                    html += '<td>' + escH(e.phone || '-') + '</td>';
                    html += '<td class="td-muted">' + escH(e.email || '-') + '</td>';
                    html += '<td class="td-center"><span class="badge ' + badge + '">' + stLbl + '</span></td>';
                    html += '</tr>';
                }
                _unassignedBody.innerHTML = html;

                /* 행 클릭 → 상세 */
                _unassignedBody.addEventListener('click', function(ev) {
                    var tr = ev.target.closest('tr[data-emp-idx]');
                    if (tr) SHV.router.navigate('emp_detail', { idx: parseInt(tr.dataset.empIdx, 10) });
                });
            })
            .catch(function() {
                _unassignedBody.innerHTML = '<tr><td colspan="7" class="td-center td-muted">불러올 수 없습니다.</td></tr>';
            });
    }

    /* ══════════════════════════════════════════════════════════
     *  탭 전환 확장 (트리 + 미배정 탭)
     * ══════════════════════════════════════════════════════════ */
    var _tabTree       = document.getElementById('ocTabTree');
    var _tabUnassigned = document.getElementById('ocTabUnassigned');
    var _allTabs       = [
        document.getElementById('ocTabActive'),
        document.getElementById('ocTabHidden'),
        _tabTree,
        _tabUnassigned
    ].filter(Boolean);
    var _allViews = {
        'active':     [document.getElementById('ocViewThumb'), document.getElementById('ocViewList')],
        'hidden':     [_hiddenView],
        'tree':       [_treeView],
        'unassigned': [_unassignedView]
    };

    function switchAllTab(tabName) {
        /* 탭 활성 표시 */
        _allTabs.forEach(function(t){ t.classList.remove('on'); });
        var tabMap = { 'active': 0, 'hidden': 1, 'tree': 2, 'unassigned': 3 };
        if (_allTabs[tabMap[tabName]]) _allTabs[tabMap[tabName]].classList.add('on');

        /* 뷰 표시/숨김 */
        Object.keys(_allViews).forEach(function(key) {
            var views = _allViews[key];
            if (!views) return;
            for (var i = 0; i < views.length; i++) {
                if (!views[i]) continue;
                if (key === tabName) views[i].classList.remove('hidden');
                else views[i].classList.add('hidden');
            }
        });

        /* 'active' 탭이면 현재 mode에 맞는 뷰만 보이기 */
        if (tabName === 'active') {
            var thumb = document.getElementById('ocViewThumb');
            var list  = document.getElementById('ocViewList');
            if (_mode === 'list') { if(thumb) thumb.classList.add('hidden'); if(list) list.classList.remove('hidden'); }
            else { if(thumb) thumb.classList.remove('hidden'); if(list) list.classList.add('hidden'); }
        }

        /* 트리: 첫 로드 */
        if (tabName === 'tree') loadTreeView();
        /* 미배정: 첫 로드 */
        if (tabName === 'unassigned') loadUnassigned();
    }

    if (_tabTree) _tabTree.addEventListener('click', function() { switchAllTab('tree'); });
    if (_tabUnassigned) _tabUnassigned.addEventListener('click', function() { switchAllTab('unassigned'); });
    /* 기존 탭도 switchAllTab으로 통합 (변수는 상단에서 이미 선언됨) */
    if (document.getElementById('ocTabActive')) {
        document.getElementById('ocTabActive').addEventListener('click', function() { switchAllTab('active'); });
    }
    if (document.getElementById('ocTabHidden')) {
        document.getElementById('ocTabHidden').addEventListener('click', function() { switchAllTab('hidden'); });
    }

    /* ══════════════════════════════════════════════════════════
     *  A-5. 설정 페이지 이동
     * ══════════════════════════════════════════════════════════ */
    var _settingsBtn = document.getElementById('oc-btn-settings');
    if (_settingsBtn) {
        _settingsBtn.addEventListener('click', function() {
            SHV.router.navigate('org_chart_settings');
        });
    }

    SHV.pages = SHV.pages || {};
    SHV.pages['org_chart'] = { destroy: function () {} };
})();
</script>
