<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

function grpOcsH(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$auth    = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="org-chart-settings"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$security  = require __DIR__ . '/../../../config/security.php';
$service   = new GroupwareService(DbConnection::get(), $security);
$scope     = $service->resolveScope($context, '', 0);
$userPk    = (int)($context['user_pk'] ?? 0);
$roleLevel = (int)($context['role_level'] ?? 0);

if ($roleLevel > 2) {
    echo '<section data-page="org-chart-settings"><div class="empty-state"><p class="empty-message">관리자 권한이 필요합니다.</p></div></section>';
    exit;
}

$missing = $service->missingTables($service->requiredTablesByDomain('employee'));
$ready   = $missing === [];
$depts   = $ready ? $service->listDepartments($scope) : [];

/* 설정값 로드 시도 (API가 아직 없으면 빈 배열) */
$settings = [];
try {
    if (method_exists($service, 'getSettings')) {
        $settings = $service->getSettings($scope);
    }
} catch (\Throwable $ex) { /* 무시 - API 미구현 시 */ }

$posNames  = json_decode((string)($settings['positions'] ?? '{}'), true) ?: [];
$titNames  = json_decode((string)($settings['titles'] ?? '{}'), true) ?: [];
$wgNames   = json_decode((string)($settings['work_gubuns'] ?? '{}'), true) ?: [];
$etNames   = json_decode((string)($settings['employment_types'] ?? '{}'), true) ?: [];
?>
<section data-page="org-chart-settings"
         data-role="<?= $roleLevel ?>"
         data-scope-code="<?= grpOcsH((string)($scope['service_code'] ?? '')) ?>"
         data-scope-tenant="<?= (int)($scope['tenant_id'] ?? 0) ?>"
         data-depts="<?= grpOcsH(json_encode($depts, JSON_UNESCAPED_UNICODE)) ?>">

    <!-- 헤더 -->
    <div class="page-header">
        <h2 class="page-title" data-title="조직도 설정">
            <button class="btn btn-ghost btn-sm" id="ocsBackBtn" type="button"><i class="fa fa-arrow-left"></i></button>
            조직도 설정
        </h2>
        <div class="page-header-actions">
            <button class="btn btn-primary btn-sm" id="ocsSaveBtn" type="button"><i class="fa fa-save mr-1"></i>설정 저장</button>
        </div>
    </div>

    <?php if (!$ready): ?>
    <div class="ocs-wrap"><div class="card card-mt card-mb"><div class="card-body"><div class="empty-state"><p class="empty-message">그룹웨어 DB가 준비되지 않았습니다.</p></div></div></div></div>
    <?php else: ?>

    <div class="ocs-wrap">

        <!-- ════════ 직급 명칭 ════════ -->
        <div class="ocs-section">
            <div class="ocs-section-head"><i class="fa fa-star"></i> 직급 명칭</div>
            <div class="ocs-grid">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="ocs-grid-item">
                    <span class="ocs-label"><?= $i ?></span>
                    <input class="ocs-input" type="text" id="ocs_pos_<?= $i ?>" value="<?= grpOcsH($posNames[$i] ?? '') ?>" placeholder="직급 <?= $i ?>">
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ════════ 직책 명칭 ════════ -->
        <div class="ocs-section">
            <div class="ocs-section-head"><i class="fa fa-id-badge"></i> 직책 명칭</div>
            <div class="ocs-grid">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="ocs-grid-item">
                    <span class="ocs-label"><?= $i ?></span>
                    <input class="ocs-input" type="text" id="ocs_title_<?= $i ?>" value="<?= grpOcsH($titNames[$i] ?? '') ?>" placeholder="직책 <?= $i ?>">
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ════════ 업무구분 명칭 ════════ -->
        <div class="ocs-section">
            <div class="ocs-section-head"><i class="fa fa-briefcase"></i> 업무구분 명칭</div>
            <div class="ocs-grid">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="ocs-grid-item">
                    <span class="ocs-label"><?= $i ?></span>
                    <input class="ocs-input" type="text" id="ocs_wg_<?= $i ?>" value="<?= grpOcsH($wgNames[$i] ?? '') ?>" placeholder="업무구분 <?= $i ?>">
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ════════ 고용형태 명칭 ════════ -->
        <div class="ocs-section">
            <div class="ocs-section-head"><i class="fa fa-handshake-o"></i> 고용형태 명칭</div>
            <div class="ocs-grid">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="ocs-grid-item">
                    <span class="ocs-label"><?= $i ?></span>
                    <input class="ocs-input" type="text" id="ocs_et_<?= $i ?>" value="<?= grpOcsH($etNames[$i] ?? '') ?>" placeholder="고용형태 <?= $i ?>">
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ════════ 지사관리 ════════ -->
        <div class="ocs-section">
            <div class="ocs-section-head">
                <span><i class="fa fa-building"></i> 지사관리</span>
                <button class="btn btn-outline btn-xs" id="ocsBranchAddBtn" type="button"><i class="fa fa-plus"></i> 지사 추가</button>
            </div>
            <div id="ocsBranchBody" class="ocs-branch-list">
                <div class="td-center td-muted" style="padding:var(--sp-3)">지사 목록을 불러오는 중...</div>
            </div>
        </div>

        <!-- 지사 모달 -->
        <div id="ocsBranchModal" class="modal-overlay" style="display:none">
            <div class="modal-box modal-sm">
                <div class="modal-header">
                    <span id="ocsBranchModalTitle">지사 등록</span>
                    <button class="modal-close" id="ocsBranchModalClose" type="button"><i class="fa fa-times"></i></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="br_idx" value="0">
                    <div class="form-group mb-3">
                        <label class="form-label">지사명 <span class="text-danger">*</span></label>
                        <input class="form-input" type="text" id="br_name" placeholder="지사명 입력">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">주소</label>
                        <input class="form-input" type="text" id="br_address" placeholder="주소 입력">
                    </div>
                    <div class="form-group">
                        <label class="form-label">전화번호</label>
                        <input class="form-input" type="tel" id="br_tel" placeholder="02-1234-5678">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline btn-sm" id="ocsBranchCancelBtn" type="button">취소</button>
                    <button class="btn btn-primary btn-sm" id="ocsBranchSaveBtn" type="button"><i class="fa fa-save"></i> 저장</button>
                </div>
            </div>
        </div>

        <!-- ════════ 부서관리 ════════ -->
        <div class="ocs-section">
            <div class="ocs-section-head">
                <span><i class="fa fa-sitemap"></i> 부서관리</span>
                <div class="ocs-dept-actions">
                    <button class="btn btn-outline btn-xs" id="ocsDeptReorderBtn" type="button"><i class="fa fa-sort"></i> 순서변경</button>
                    <button class="btn btn-outline btn-xs" id="ocsDeptAddRootBtn" type="button"><i class="fa fa-plus"></i> 부서 추가</button>
                </div>
            </div>
            <div id="ocsDeptBody" class="ocs-dept-tree">
                <div class="td-center td-muted" style="padding:var(--sp-3)">부서 트리를 불러오는 중...</div>
            </div>
        </div>

    </div>

    <!-- 부서명 입력 모달 (prompt 대체) -->
    <div id="ocsDeptNameModal" class="modal-overlay" style="display:none">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <span id="ocsDeptNameModalTitle">부서 추가</span>
                <button class="modal-close" id="ocsDeptNameModalClose" type="button"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dn_parent_idx" value="0">
                <input type="hidden" id="dn_edit_idx" value="0">
                <div class="form-group">
                    <label class="form-label">부서명 <span class="text-danger">*</span></label>
                    <input class="form-input" type="text" id="dn_name" placeholder="부서명 입력">
                </div>
                <div id="ocsDeptNameErr" class="text-danger text-sm mt-1 hidden"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline btn-sm" id="ocsDeptNameCancelBtn" type="button">취소</button>
                <button class="btn btn-primary btn-sm" id="ocsDeptNameSaveBtn" type="button"><i class="fa fa-save"></i> 저장</button>
            </div>
        </div>
    </div>

    <?php endif; ?>

</section>

<!-- ═══════════════ CSS ═══════════════ -->
<style>
.ocs-wrap {
    padding: 0 var(--sp-5);
    max-width: 960px;
}

/* ── 섹션 ── */
.ocs-section {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin-bottom: var(--sp-4);
    overflow: hidden;
}
.ocs-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--sp-3) var(--sp-4);
    font-size: 13px;
    font-weight: 700;
    color: var(--accent);
    border-bottom: 2px solid var(--accent);
    gap: var(--sp-2);
}
.ocs-section-head i {
    font-size: 13px;
    margin-right: var(--sp-1);
}

/* ── 명칭 그리드 ── */
.ocs-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
}
.ocs-grid-item {
    display: flex;
    align-items: center;
    padding: var(--sp-2) var(--sp-3);
    border-bottom: 1px solid var(--border);
    border-right: 1px solid var(--border);
}
.ocs-grid-item:nth-child(4n) {
    border-right: none;
}
.ocs-label {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-xs);
    background: var(--bg-2);
    font-size: 11px;
    font-weight: 700;
    color: var(--text-3);
    margin-right: var(--sp-2);
    flex-shrink: 0;
}
.ocs-input {
    flex: 1;
    padding: var(--sp-1) var(--sp-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-xs);
    font-size: 12px;
    outline: none;
    min-width: 0;
}
.ocs-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(59,108,247,.08);
}

/* ── 지사 목록 ── */
.ocs-branch-list {
    padding: 0;
}
.ocs-branch-item {
    display: flex;
    align-items: center;
    gap: var(--sp-3);
    padding: var(--sp-3) var(--sp-4);
    border-bottom: 1px solid var(--border);
    font-size: 12px;
}
.ocs-branch-item:last-child {
    border-bottom: none;
}
.ocs-branch-icon {
    color: #f59e0b;
    font-size: 14px;
    width: 18px;
    text-align: center;
}
.ocs-branch-name {
    font-weight: 700;
    color: var(--text-1);
    min-width: 80px;
}
.ocs-branch-info {
    flex: 1;
    display: flex;
    gap: var(--sp-3);
    font-size: 11px;
    color: var(--text-3);
    flex-wrap: wrap;
}
.ocs-branch-info i {
    margin-right: 2px;
}
.ocs-branch-actions {
    display: flex;
    gap: var(--sp-1);
}

/* ── 부서 트리 ── */
.ocs-dept-tree {
    padding: 0;
}
.ocs-dept-item {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
    padding: var(--sp-2) var(--sp-4);
    border-bottom: 1px solid var(--border);
    font-size: 12px;
}
.ocs-dept-item:last-child {
    border-bottom: none;
}
.ocs-dept-d2 { padding-left: calc(var(--sp-4) + 14px); }
.ocs-dept-d3 { padding-left: calc(var(--sp-4) + 28px); }
.ocs-dept-icon {
    color: var(--accent);
    font-size: 11px;
    width: 14px;
    text-align: center;
}
.ocs-dept-name {
    flex: 1;
    font-weight: 600;
    color: var(--text-1);
}
.ocs-dept-cnt {
    font-size: 10px;
    color: var(--text-3);
    background: var(--bg-2);
    padding: 1px 6px;
    border-radius: 8px;
}
.ocs-dept-actions {
    display: flex;
    gap: var(--sp-1);
}
.ocs-dept-move {
    display: none;
    gap: 2px;
}
.ocs-dept-tree--editing .ocs-dept-move {
    display: flex;
}

/* ── 반응형 ── */
@media (max-width: 768px) {
    .ocs-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .ocs-grid-item:nth-child(2n) {
        border-right: none;
    }
}
@media (max-width: 480px) {
    .ocs-grid {
        grid-template-columns: 1fr;
    }
    .ocs-grid-item {
        border-right: none;
    }
}
</style>

<!-- ═══════════════ JS ═══════════════ -->
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="org-chart-settings"]');
    if (!_section) return;

    var _apiUrl = 'dist_process/saas/Employee.php';
    var _depts  = [];
    try { _depts = JSON.parse(_section.dataset.depts || '[]'); } catch(e) {}

    function escH(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    /* ══════ 뒤로 ══════ */
    var _backBtn = document.getElementById('ocsBackBtn');
    if (_backBtn) _backBtn.addEventListener('click', function() { SHV.router.navigate('org_chart'); });

    /* ══════ 명칭 설정 저장 ══════ */
    var _saveBtn = document.getElementById('ocsSaveBtn');
    if (_saveBtn) _saveBtn.addEventListener('click', function() {
        var positions = {}, titles = {}, work_gubuns = {}, employment_types = {};

        for (var i = 1; i <= 5; i++) {
            var pEl = document.getElementById('ocs_pos_' + i);
            var tEl = document.getElementById('ocs_title_' + i);
            var wEl = document.getElementById('ocs_wg_' + i);
            var eEl = document.getElementById('ocs_et_' + i);
            if (pEl) positions[i] = pEl.value.trim();
            if (tEl) titles[i] = tEl.value.trim();
            if (wEl) work_gubuns[i] = wEl.value.trim();
            if (eEl) employment_types[i] = eEl.value.trim();
        }

        SHV.api.post(_apiUrl, {
            todo: 'save_settings',
            positions: JSON.stringify(positions),
            titles: JSON.stringify(titles),
            work_gubuns: JSON.stringify(work_gubuns),
            employment_types: JSON.stringify(employment_types)
        }).then(function(res) {
            if (res && res.ok) SHV.toast.success('설정이 저장되었습니다.');
            else SHV.toast.error((res && res.message) || '저장 실패');
        }).catch(function() { SHV.toast.error('서버 오류'); });
    });

    /* ══════ 지사관리 ══════ */
    var _branchBody  = document.getElementById('ocsBranchBody');
    var _branchModal = document.getElementById('ocsBranchModal');

    function loadBranches() {
        SHV.api.get(_apiUrl, { todo: 'dept_list' })
            .then(function(res) {
                var items = (res && res.ok && res.data) ? res.data.items : [];
                var branches = items.filter(function(d) { return parseInt(d.dept_type || d.type || 0, 10) === 1; });
                renderBranches(branches);
            })
            .catch(function() {
                _branchBody.innerHTML = '<div class="td-center td-muted" style="padding:var(--sp-3)">지사 목록을 불러올 수 없습니다.</div>';
            });
    }

    function renderBranches(branches) {
        if (!branches.length) {
            _branchBody.innerHTML = '<div class="td-center td-muted" style="padding:var(--sp-3)">등록된 지사가 없습니다.</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < branches.length; i++) {
            var b = branches[i];
            html += '<div class="ocs-branch-item" data-idx="' + b.idx + '">';
            html += '<span class="ocs-branch-icon"><i class="fa fa-building"></i></span>';
            html += '<span class="ocs-branch-name">' + escH(b.dept_name) + '</span>';
            html += '<span class="ocs-branch-info">';
            if (b.address) html += '<span><i class="fa fa-map-marker"></i>' + escH(b.address) + '</span>';
            if (b.tel) html += '<span><i class="fa fa-phone"></i>' + escH(b.tel) + '</span>';
            html += '</span>';
            html += '<span class="ocs-branch-actions">';
            html += '<button class="btn btn-ghost btn-xs ocs-br-edit" data-idx="' + b.idx + '" data-name="' + escH(b.dept_name) + '" data-address="' + escH(b.address || '') + '" data-tel="' + escH(b.tel || '') + '"><i class="fa fa-pencil"></i></button>';
            html += '<button class="btn btn-ghost btn-xs text-danger ocs-br-del" data-idx="' + b.idx + '"><i class="fa fa-trash"></i></button>';
            html += '</span></div>';
        }
        _branchBody.innerHTML = html;
    }

    function openBranchModal(data) {
        data = data || {};
        document.getElementById('br_idx').value = data.idx || 0;
        document.getElementById('br_name').value = data.name || '';
        document.getElementById('br_address').value = data.address || '';
        document.getElementById('br_tel').value = data.tel || '';
        document.getElementById('ocsBranchModalTitle').textContent = data.idx ? '지사 수정' : '지사 등록';
        _branchModal.style.display = 'flex';
    }
    function closeBranchModal() { _branchModal.style.display = 'none'; }

    document.getElementById('ocsBranchAddBtn').addEventListener('click', function() { openBranchModal(); });
    document.getElementById('ocsBranchModalClose').addEventListener('click', closeBranchModal);
    document.getElementById('ocsBranchCancelBtn').addEventListener('click', closeBranchModal);
    _branchModal.addEventListener('click', function(e) { if (e.target === _branchModal) closeBranchModal(); });

    document.getElementById('ocsBranchSaveBtn').addEventListener('click', function() {
        var idx     = parseInt(document.getElementById('br_idx').value, 10) || 0;
        var name    = document.getElementById('br_name').value.trim();
        var address = document.getElementById('br_address').value.trim();
        var tel     = document.getElementById('br_tel').value.trim();
        if (!name) { SHV.toast.warn('지사명을 입력하세요.'); return; }

        if (idx > 0) {
            /* 수정: 이름 변경 + 주소/전화 업데이트 */
            SHV.api.post(_apiUrl, { todo: 'dept_update', idx: idx, dept_name: name })
                .then(function() {
                    return SHV.api.post(_apiUrl, { todo: 'branch_update_info', idx: idx, address: address, tel: tel });
                })
                .then(function(res) {
                    if (res && res.ok) { SHV.toast.success('수정되었습니다.'); closeBranchModal(); loadBranches(); }
                    else SHV.toast.error((res && res.message) || '수정 실패');
                })
                .catch(function() { SHV.toast.error('서버 오류'); });
        } else {
            /* 신규: dept_insert(type=1) → branch_update_info */
            SHV.api.post(_apiUrl, { todo: 'dept_insert', dept_name: name, dept_type: 1, parent_idx: 0 })
                .then(function(res) {
                    if (res && res.ok && res.data && res.data.item) {
                        var newIdx = res.data.item.idx;
                        return SHV.api.post(_apiUrl, { todo: 'branch_update_info', idx: newIdx, address: address, tel: tel });
                    }
                    throw new Error((res && res.message) || '등록 실패');
                })
                .then(function(res) {
                    SHV.toast.success('지사가 등록되었습니다.');
                    closeBranchModal();
                    loadBranches();
                })
                .catch(function(err) { SHV.toast.error(err.message || '서버 오류'); });
        }
    });

    /* 지사 이벤트 위임 */
    _branchBody.addEventListener('click', function(e) {
        var editBtn = e.target.closest('.ocs-br-edit');
        if (editBtn) {
            openBranchModal({
                idx: parseInt(editBtn.dataset.idx, 10),
                name: editBtn.dataset.name || '',
                address: editBtn.dataset.address || '',
                tel: editBtn.dataset.tel || ''
            });
            return;
        }
        var delBtn = e.target.closest('.ocs-br-del');
        if (delBtn) {
            shvConfirm({ message: '이 지사를 삭제하시겠습니까?', type: 'danger' })
                .then(function(ok) {
                    if (!ok) return;
                    SHV.api.post(_apiUrl, { todo: 'dept_delete', dept_id: parseInt(delBtn.dataset.idx, 10) })
                        .then(function(res) {
                            if (res && res.ok) { SHV.toast.success('삭제되었습니다.'); loadBranches(); }
                            else SHV.toast.error((res && res.message) || '삭제 실패');
                        });
                });
        }
    });

    loadBranches();

    /* ══════ 부서관리 ══════ */
    var _deptBody = document.getElementById('ocsDeptBody');
    var _deptEditing = false;

    function loadDeptTree() {
        SHV.api.get(_apiUrl, { todo: 'dept_list' })
            .then(function(res) {
                var items = (res && res.ok && res.data) ? res.data.items : [];
                /* 지사 제외 */
                var depts = items.filter(function(d) { return parseInt(d.dept_type || d.type || 0, 10) !== 1; });
                renderDeptTree(depts);
            })
            .catch(function() {
                _deptBody.innerHTML = '<div class="td-center td-muted" style="padding:var(--sp-3)">부서 트리를 불러올 수 없습니다.</div>';
            });
    }

    function renderDeptTree(depts) {
        var map = {}, roots = [];
        for (var i = 0; i < depts.length; i++) {
            depts[i].children = [];
            map[depts[i].idx] = depts[i];
        }
        for (var j = 0; j < depts.length; j++) {
            var pid = parseInt(depts[j].parent_idx, 10) || 0;
            if (pid > 0 && map[pid]) map[pid].children.push(depts[j]);
            else roots.push(depts[j]);
        }
        roots.sort(function(a,b){ return (a.sort_order||0) - (b.sort_order||0); });

        if (!roots.length) {
            _deptBody.innerHTML = '<div class="td-center td-muted" style="padding:var(--sp-3)">등록된 부서가 없습니다.</div>';
            return;
        }
        _deptBody.innerHTML = renderDeptNodes(roots, 1);
        if (_deptEditing) _deptBody.classList.add('ocs-dept-tree--editing');
    }

    function renderDeptNodes(nodes, depth) {
        var html = '';
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            n.children.sort(function(a,b){ return (a.sort_order||0) - (b.sort_order||0); });
            var cls = depth === 2 ? ' ocs-dept-d2' : (depth === 3 ? ' ocs-dept-d3' : '');
            var icon = n.children.length > 0 ? 'fa-folder' : 'fa-folder-o';
            var cnt = n.employee_count || n.emp_count || 0;
            html += '<div class="ocs-dept-item' + cls + '" data-idx="' + n.idx + '" data-parent="' + (n.parent_idx||0) + '">';
            html += '<span class="ocs-dept-icon"><i class="fa ' + icon + '"></i></span>';
            html += '<span class="ocs-dept-name">' + escH(n.dept_name) + '</span>';
            if (cnt > 0) html += '<span class="ocs-dept-cnt">' + cnt + '명</span>';
            html += '<span class="ocs-dept-actions">';
            if (depth < 3) html += '<button class="btn btn-ghost btn-xs ocs-dept-add" data-idx="' + n.idx + '" title="하위 부서 추가"><i class="fa fa-plus"></i></button>';
            html += '<button class="btn btn-ghost btn-xs ocs-dept-edit" data-idx="' + n.idx + '" data-name="' + escH(n.dept_name) + '" title="수정"><i class="fa fa-pencil"></i></button>';
            html += '<button class="btn btn-ghost btn-xs text-danger ocs-dept-del" data-idx="' + n.idx + '" title="삭제"><i class="fa fa-trash"></i></button>';
            html += '</span>';
            html += '<span class="ocs-dept-move">';
            html += '<button class="btn btn-ghost btn-xs ocs-dept-up" data-idx="' + n.idx + '" title="위로"><i class="fa fa-arrow-up"></i></button>';
            html += '<button class="btn btn-ghost btn-xs ocs-dept-down" data-idx="' + n.idx + '" title="아래로"><i class="fa fa-arrow-down"></i></button>';
            html += '</span>';
            html += '</div>';
            if (n.children.length > 0) {
                html += renderDeptNodes(n.children, depth + 1);
            }
        }
        return html;
    }

    /* 순서변경 토글 */
    var _reorderBtn = document.getElementById('ocsDeptReorderBtn');
    if (_reorderBtn) {
        _reorderBtn.addEventListener('click', function() {
            _deptEditing = !_deptEditing;
            _reorderBtn.classList.toggle('btn-primary', _deptEditing);
            _reorderBtn.classList.toggle('btn-outline', !_deptEditing);
            _deptBody.classList.toggle('ocs-dept-tree--editing', _deptEditing);
        });
    }

    /* ══════ 부서명 입력 모달 (prompt 대체) ══════ */
    var _dnModal    = document.getElementById('ocsDeptNameModal');
    var _dnName     = document.getElementById('dn_name');
    var _dnParent   = document.getElementById('dn_parent_idx');
    var _dnEditIdx  = document.getElementById('dn_edit_idx');
    var _dnTitle    = document.getElementById('ocsDeptNameModalTitle');
    var _dnErr      = document.getElementById('ocsDeptNameErr');

    function openDeptNameModal(opts) {
        opts = opts || {};
        _dnParent.value  = opts.parentIdx || 0;
        _dnEditIdx.value = opts.editIdx || 0;
        _dnName.value    = opts.name || '';
        _dnTitle.textContent = opts.title || '부서 추가';
        _dnErr.classList.add('hidden');
        _dnModal.style.display = 'flex';
        setTimeout(function(){ _dnName.focus(); }, 100);
    }
    function closeDeptNameModal() { _dnModal.style.display = 'none'; }

    document.getElementById('ocsDeptNameModalClose').addEventListener('click', closeDeptNameModal);
    document.getElementById('ocsDeptNameCancelBtn').addEventListener('click', closeDeptNameModal);
    _dnModal.addEventListener('click', function(e) { if (e.target === _dnModal) closeDeptNameModal(); });

    /* 모달 저장 버튼 */
    document.getElementById('ocsDeptNameSaveBtn').addEventListener('click', function() {
        var name = _dnName.value.trim();
        if (!name) { _dnErr.textContent = '부서명을 입력하세요.'; _dnErr.classList.remove('hidden'); return; }

        var editIdx  = parseInt(_dnEditIdx.value, 10) || 0;
        var parentIdx = parseInt(_dnParent.value, 10) || 0;

        if (editIdx > 0) {
            /* 수정 */
            SHV.api.post(_apiUrl, { todo: 'dept_update', idx: editIdx, dept_name: name })
                .then(function(res) {
                    if (res && res.ok) { SHV.toast.success('수정되었습니다.'); closeDeptNameModal(); loadDeptTree(); }
                    else SHV.toast.error((res && res.message) || '수정 실패');
                })
                .catch(function() { SHV.toast.error('서버 오류'); });
        } else {
            /* 추가 */
            SHV.api.post(_apiUrl, { todo: 'dept_insert', dept_name: name, parent_idx: parentIdx })
                .then(function(res) {
                    if (res && res.ok) { SHV.toast.success('부서가 추가되었습니다.'); closeDeptNameModal(); loadDeptTree(); }
                    else SHV.toast.error((res && res.message) || '추가 실패');
                })
                .catch(function() { SHV.toast.error('서버 오류'); });
        }
    });

    /* 모달 내 Enter 키 */
    _dnName.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); document.getElementById('ocsDeptNameSaveBtn').click(); }
    });

    /* 루트 부서 추가 */
    var _addRootBtn = document.getElementById('ocsDeptAddRootBtn');
    if (_addRootBtn) {
        _addRootBtn.addEventListener('click', function() {
            openDeptNameModal({ title: '부서 추가', parentIdx: 0 });
        });
    }

    /* 부서 이벤트 위임 */
    _deptBody.addEventListener('click', function(e) {
        var addBtn = e.target.closest('.ocs-dept-add');
        if (addBtn) {
            var parentIdx = parseInt(addBtn.dataset.idx, 10);
            openDeptNameModal({ title: '하위 부서 추가', parentIdx: parentIdx });
            return;
        }

        var editBtn = e.target.closest('.ocs-dept-edit');
        if (editBtn) {
            var idx = parseInt(editBtn.dataset.idx, 10);
            var oldName = editBtn.dataset.name || '';
            openDeptNameModal({ title: '부서 이름 수정', editIdx: idx, name: oldName });
            return;
        }

        var delBtn = e.target.closest('.ocs-dept-del');
        if (delBtn) {
            shvConfirm({ message: '이 부서를 삭제하시겠습니까?\n소속 직원은 미배정 상태가 됩니다.', type: 'danger' })
                .then(function(ok) {
                    if (!ok) return;
                    SHV.api.post(_apiUrl, { todo: 'dept_delete', dept_id: parseInt(delBtn.dataset.idx, 10) })
                        .then(function(res) {
                            if (res && res.ok) { SHV.toast.success('삭제되었습니다.'); loadDeptTree(); }
                            else SHV.toast.error((res && res.message) || '삭제 실패');
                        });
                });
            return;
        }

        var upBtn = e.target.closest('.ocs-dept-up');
        var downBtn = e.target.closest('.ocs-dept-down');
        if (upBtn || downBtn) {
            var item = (upBtn || downBtn).closest('.ocs-dept-item');
            var parentVal = item.dataset.parent || '0';
            /* 같은 depth의 형제 수집 */
            var siblings = Array.from(_deptBody.querySelectorAll('.ocs-dept-item[data-parent="' + parentVal + '"]'));
            var curIdx = siblings.indexOf(item);
            if (upBtn && curIdx > 0) {
                item.parentNode.insertBefore(item, siblings[curIdx - 1]);
            } else if (downBtn && curIdx < siblings.length - 1) {
                item.parentNode.insertBefore(siblings[curIdx + 1], item);
            }
            /* 새 순서 저장 */
            var reordered = Array.from(_deptBody.querySelectorAll('.ocs-dept-item[data-parent="' + parentVal + '"]'));
            var order = reordered.map(function(el) { return parseInt(el.dataset.idx, 10); });
            SHV.api.post(_apiUrl, { todo: 'dept_reorder', order: JSON.stringify(order) })
                .then(function(res) {
                    if (res && res.ok) SHV.toast.success('순서가 변경되었습니다.');
                    else SHV.toast.error((res && res.message) || '순서 변경 실패');
                });
        }
    });

    loadDeptTree();

    /* ══════ 페이지 라이프사이클 ══════ */
    SHV.pages = SHV.pages || {};
    SHV.pages['org_chart_settings'] = { destroy: function () {} };

})();
</script>
