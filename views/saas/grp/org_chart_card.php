<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

function grpCardH(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="org-chart-card"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$security  = require __DIR__ . '/../../../config/security.php';
$service   = new GroupwareService(DbConnection::get(), $security);
$scope     = $service->resolveScope($context, '', 0);
$userPk    = (int)($context['user_pk'] ?? 0);
$roleLevel = (int)($context['role_level'] ?? 0);

$missing = $service->missingTables($service->requiredTablesByDomain('employee'));
$ready   = $missing === [];

$search = trim((string)($_GET['search'] ?? ''));

$contacts = $ready ? $service->listPhoneBook($scope, ['search' => $search, 'limit' => 300]) : [];

/* 이니셜(아바타 대용) */
function grpInitials(string $name): string
{
    $name = trim($name);
    if ($name === '') return '?';
    $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
    return mb_strtoupper(implode('', array_slice($chars ?: [], 0, 2)));
}

$avatarColors = ['#4f86f7','#22c55e','#8b5cf6','#ea580c','#14b8a6','#ef4444','#06b6d4','#f59e0b'];
function grpAvatarColor(int $idx): string
{
    global $avatarColors;
    return $avatarColors[abs($idx) % count($avatarColors)];
}
?>
<section data-page="org-chart-card" data-role="<?= $roleLevel ?>">
    <div class="page-header">
        <h2 class="page-title" data-title="주소록">주소록</h2>
        <p class="page-subtitle">사내/외부 연락처 카드뷰</p>
        <?php if ($roleLevel >= 3): ?>
        <div class="page-header-actions">
            <button id="occ-btn-add" class="btn btn-primary btn-sm"><i class="fa fa-plus mr-1"></i>연락처 추가</button>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$ready): ?>
    <div class="card card-mt card-mb">
        <div class="card-body"><div class="empty-state"><p class="empty-message">그룹웨어 DB가 준비되지 않았습니다.</p></div></div>
    </div>
    <?php else: ?>

    <!-- 검색 -->
    <div class="card card-mt">
        <div class="card-body">
            <div class="form-row form-row--filter">
                <div class="form-group fg-2">
                    <label class="form-label">검색</label>
                    <input id="occSearch" type="text" class="form-input" placeholder="이름/회사/부서/직위/연락처/이메일" value="<?= grpCardH($search) ?>">
                </div>
                <div class="fg-auto">
                    <button id="occSearchBtn" class="btn btn-primary"><i class="fa fa-search"></i> 조회</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 카드 그리드 -->
    <div class="card-mt card-mb">
        <div class="card-header-row">
            <span class="text-sm text-3">총 <?= count($contacts) ?>건</span>
        </div>
        <?php if ($contacts === []): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state"><p class="empty-message">연락처 데이터가 없습니다.</p></div>
            </div>
        </div>
        <?php else: ?>
        <div class="occ-grid" id="occGrid">
            <?php foreach ($contacts as $c): ?>
            <?php
                $idx      = (int)($c['idx'] ?? 0);
                $name     = (string)($c['contact_name'] ?? '');
                $company  = (string)($c['company_name'] ?? '');
                $dept     = (string)($c['department_name'] ?? '');
                $pos      = (string)($c['position_name'] ?? '');
                $phone    = (string)($c['phone'] ?? '');
                $email    = (string)($c['email'] ?? '');
                $memo     = (string)($c['memo'] ?? '');
                $initials = grpInitials($name);
                $color    = grpAvatarColor($idx);
            ?>
            <div class="card occ-card" data-phonebook-idx="<?= $idx ?>">
                <div class="occ-avatar" style="background:<?= $color ?>;"><?= grpCardH($initials) ?></div>
                <div class="occ-name"><?= grpCardH($name) ?></div>
                <?php if ($company || $dept): ?>
                <div class="occ-company text-xs text-3">
                    <?= grpCardH($company) ?><?= ($company && $dept) ? ' · ' : '' ?><?= grpCardH($dept) ?>
                </div>
                <?php endif; ?>
                <?php if ($pos): ?>
                <div class="badge badge-ghost occ-pos"><?= grpCardH($pos) ?></div>
                <?php endif; ?>
                <div class="occ-contacts">
                    <?php if ($phone): ?>
                    <a href="tel:<?= grpCardH($phone) ?>" class="occ-contact-item">
                        <i class="fa fa-phone occ-contact-icon"></i><?= grpCardH($phone) ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($email): ?>
                    <a href="mailto:<?= grpCardH($email) ?>" class="occ-contact-item">
                        <i class="fa fa-envelope-o occ-contact-icon"></i><?= grpCardH($email) ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($memo): ?>
                    <div class="occ-memo text-xs text-3"><?= grpCardH($memo) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($roleLevel >= 3): ?>
                <div class="occ-actions">
                    <button class="btn btn-outline btn-sm occ-edit-btn" data-idx="<?= $idx ?>">수정</button>
                    <button class="btn btn-outline btn-sm occ-del-btn"  data-idx="<?= $idx ?>">삭제</button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 추가/수정 모달 -->
    <div id="occModal" class="modal-overlay" style="display:none;">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <span id="occModalTitle">연락처 추가</span>
                <button class="modal-close" id="occModalClose"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="occIdx" value="0">
                <div class="form-group">
                    <label class="form-label">이름 <span class="text-danger">*</span></label>
                    <input id="occName" type="text" class="form-input" placeholder="홍길동">
                </div>
                <div class="form-group">
                    <label class="form-label">회사명</label>
                    <input id="occCompany" type="text" class="form-input" placeholder="(주)SH Vision">
                </div>
                <div class="form-group">
                    <label class="form-label">부서명</label>
                    <input id="occDeptName" type="text" class="form-input" placeholder="개발팀">
                </div>
                <div class="form-group">
                    <label class="form-label">직위</label>
                    <input id="occPosition" type="text" class="form-input" placeholder="대리">
                </div>
                <div class="form-group">
                    <label class="form-label">연락처</label>
                    <input id="occPhone" type="text" class="form-input" placeholder="010-0000-0000">
                </div>
                <div class="form-group">
                    <label class="form-label">이메일</label>
                    <input id="occEmail" type="email" class="form-input" placeholder="user@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label">메모</label>
                    <input id="occMemo" type="text" class="form-input" placeholder="메모">
                </div>
                <div id="occErr" class="text-danger text-sm mt-1 hidden"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="occCancelBtn">취소</button>
                <button class="btn btn-primary" id="occSaveBtn">저장</button>
            </div>
        </div>
    </div>

    <?php endif; ?>
</section>
<style>
.card-header-row {
    display: flex; align-items: center; justify-content: flex-end;
    padding: var(--sp-2) var(--sp-5);
}
.occ-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: var(--sp-3);
    padding: 0 var(--sp-5);
}
.occ-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: var(--sp-5) var(--sp-4);
    gap: var(--sp-1);
    cursor: default;
}
.occ-avatar {
    width: 52px; height: 52px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; font-weight: 700;
    color: #fff;
    margin-bottom: var(--sp-1);
    flex-shrink: 0;
}
.occ-name    { font-weight: 600; font-size: 15px; color: var(--text-1); }
.occ-company { color: var(--text-3); }
.occ-pos     { margin-top: 2px; }
.occ-contacts {
    display: flex; flex-direction: column; gap: 4px;
    width: 100%; margin-top: var(--sp-2);
}
.occ-contact-item {
    display: flex; align-items: center; justify-content: center; gap: 5px;
    font-size: 12px; color: var(--text-2);
    text-decoration: none;
}
.occ-contact-item:hover { color: var(--accent); }
.occ-contact-icon { color: var(--text-3); font-size: 11px; }
.occ-memo { padding-top: 2px; }
.occ-actions {
    display: flex; gap: var(--sp-2);
    margin-top: var(--sp-2);
    justify-content: center;
}
.page-header-actions { display: flex; gap: var(--sp-2); }
@media (max-width: 768px) {
    .occ-grid { grid-template-columns: repeat(2, 1fr); padding: 0 var(--sp-3); }
}
</style>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="org-chart-card"]');
    if (!_section) return;

    var _apiUrl = 'dist_process/saas/Employee.php';
    var _csrf   = (SHV.csrf && SHV.csrf.get) ? SHV.csrf.get() : '';

    /* ── 검색 ── */
    function reload() {
        SHV.router.navigate('org_chart_card', {
            search: document.getElementById('occSearch').value.trim()
        });
    }
    document.getElementById('occSearchBtn').addEventListener('click', reload);
    document.getElementById('occSearch').addEventListener('keydown', function (e) { if (e.key === 'Enter') reload(); });

    /* ── 모달 ── */
    var _modal = document.getElementById('occModal');

    function openModal(data) {
        document.getElementById('occIdx').value      = data ? data.idx : 0;
        document.getElementById('occName').value     = data ? data.contact_name : '';
        document.getElementById('occCompany').value  = data ? data.company_name : '';
        document.getElementById('occDeptName').value = data ? data.department_name : '';
        document.getElementById('occPosition').value = data ? data.position_name : '';
        document.getElementById('occPhone').value    = data ? data.phone : '';
        document.getElementById('occEmail').value    = data ? data.email : '';
        document.getElementById('occMemo').value     = data ? data.memo : '';
        document.getElementById('occModalTitle').textContent = data ? '연락처 수정' : '연락처 추가';
        document.getElementById('occErr').textContent = '';
        document.getElementById('occErr').classList.add('hidden');
        _modal.style.display = 'flex';
    }
    function closeModal() { _modal.style.display = 'none'; }

    var addBtn = document.getElementById('occ-btn-add');
    if (addBtn) addBtn.addEventListener('click', function () { openModal(null); });
    document.getElementById('occModalClose').addEventListener('click', closeModal);
    document.getElementById('occCancelBtn').addEventListener('click', closeModal);
    _modal.addEventListener('click', function (e) { if (e.target === _modal) closeModal(); });

    document.getElementById('occSaveBtn').addEventListener('click', function () {
        var name = document.getElementById('occName').value.trim();
        if (!name) { showErr('이름을 입력해주세요.'); return; }
        var idx = parseInt(document.getElementById('occIdx').value, 10);
        var todo = idx > 0 ? 'phonebook_update' : 'phonebook_insert';
        SHV.api.post(_apiUrl, {
            csrf_token:      _csrf,
            todo:            todo,
            idx:             idx,
            contact_name:    name,
            company_name:    document.getElementById('occCompany').value.trim(),
            department_name: document.getElementById('occDeptName').value.trim(),
            position_name:   document.getElementById('occPosition').value.trim(),
            phone:           document.getElementById('occPhone').value.trim(),
            email:           document.getElementById('occEmail').value.trim(),
            memo:            document.getElementById('occMemo').value.trim(),
        }).then(function (res) {
            if (!res || !res.ok) { showErr(res && res.message ? res.message : '저장 실패'); return; }
            closeModal();
            SHV.router.navigate('org_chart_card');
        }).catch(function () { showErr('서버 오류가 발생했습니다.'); });
    });

    /* ── 수정/삭제 버튼 ── */
    var grid = document.getElementById('occGrid');
    if (grid) {
        grid.addEventListener('click', function (e) {
            var editBtn = e.target.closest('.occ-edit-btn');
            if (editBtn) {
                var idx = parseInt(editBtn.dataset.idx, 10);
                /* 카드에서 직접 데이터 읽기 (API 호출 생략) */
                var card = editBtn.closest('.occ-card');
                var items = card ? card.querySelectorAll('.occ-contact-item') : [];
                var phone = '', email = '';
                items.forEach(function (a) {
                    if (a.href.startsWith('tel:')) phone = a.href.replace('tel:', '');
                    if (a.href.startsWith('mailto:')) email = a.href.replace('mailto:', '');
                });
                openModal({
                    idx:             idx,
                    contact_name:    card ? (card.querySelector('.occ-name') || {}).textContent || '' : '',
                    company_name:    '',
                    department_name: '',
                    position_name:   card ? (card.querySelector('.occ-pos') || {}).textContent || '' : '',
                    phone:           phone,
                    email:           email,
                    memo:            card ? (card.querySelector('.occ-memo') || {}).textContent || '' : '',
                });
                return;
            }
            var delBtn = e.target.closest('.occ-del-btn');
            if (delBtn) {
                var dIdx = parseInt(delBtn.dataset.idx, 10);
                SHV.modal.confirm('해당 연락처를 삭제하시겠습니까?', function (ok) {
                    if (!ok) return;
                    SHV.api.post(_apiUrl, { csrf_token: _csrf, todo: 'phonebook_delete', idx: dIdx })
                        .then(function (res) {
                            if (!res || !res.ok) { SHV.toast.error('삭제 실패'); return; }
                            SHV.router.navigate('org_chart_card');
                        });
                });
            }
        });
    }

    function showErr(msg) {
        var el = document.getElementById('occErr');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    SHV.pages = SHV.pages || {};
    SHV.pages['org_chart_card'] = { destroy: function () {} };
})();
</script>
