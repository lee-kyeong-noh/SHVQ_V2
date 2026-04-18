<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

function apWrtH(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="approval-write"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$security  = require __DIR__ . '/../../../config/security.php';
$service   = new GroupwareService(DbConnection::get(), $security);
$scope     = $service->resolveScope($context, '', 0);
$userPk    = (int)($context['user_pk'] ?? 0);
$roleLevel = (int)($context['role_level'] ?? 0);

$missing = $service->missingTables($service->requiredTablesByDomain('approval'));
$ready   = $missing === [];

$emps = [];
if ($ready) {
    $emps = $service->listEmployees($scope, ['limit' => 300]);
}

?>
<section data-page="approval-write"
         data-user-pk="<?= $userPk ?>"
         data-role="<?= $roleLevel ?>">
    <div class="page-header">
        <h2 class="page-title" data-title="결재 작성">결재 작성</h2>
        <p class="page-subtitle">결재 문서 작성 · 기안</p>
        <div class="page-header-actions">
            <button class="btn btn-outline btn-sm" id="apw-cancel-btn">취소</button>
        </div>
    </div>

    <?php if (!$ready): ?>
    <div class="card card-mt card-mb">
        <div class="card-body"><div class="empty-state"><p class="empty-message">그룹웨어 DB가 준비되지 않았습니다.</p></div></div>
    </div>
    <?php else: ?>

    <div class="apw-layout card-mt card-mb">
        <!-- 좌: 문서 작성 -->
        <div class="apw-form-col card">
            <div class="card-header">문서 내용</div>
            <div class="card-body apw-form-body">
                <div class="form-group">
                    <label class="form-label">문서 유형 <span class="text-danger">*</span></label>
                    <div class="apw-radio-row">
                        <label class="apw-radio-label">
                            <input type="radio" name="apwDocType" value="GENERAL" checked> 일반 결재
                        </label>
                        <label class="apw-radio-label">
                            <input type="radio" name="apwDocType" value="OFFICIAL"> 공문
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="apwTitle">제목 <span class="text-danger">*</span></label>
                    <input id="apwTitle" type="text" class="form-input" placeholder="결재 문서 제목을 입력하세요">
                </div>
                <div class="form-group apw-body-group">
                    <label class="form-label" for="apwBody">내용 <span class="text-danger">*</span></label>
                    <textarea id="apwBody" class="form-input apw-body-textarea" placeholder="결재 문서 내용을 작성하세요.&#10;&#10;• 목적:&#10;• 내용:&#10;• 기대효과:"></textarea>
                </div>
                <div id="apwErr" class="text-danger text-sm mt-1 hidden"></div>
            </div>
            <div class="apw-form-footer">
                <button class="btn btn-outline" id="apwDraftBtn"><i class="fa fa-save mr-1"></i>임시저장</button>
                <button class="btn btn-primary" id="apwSubmitBtn"><i class="fa fa-paper-plane mr-1"></i>결재 상신</button>
            </div>
        </div>

        <!-- 우: 결재선 설정 -->
        <div class="apw-approver-col card">
            <div class="card-header">결재선 설정</div>
            <div class="card-body apw-approver-body">
                <p class="text-sm text-3 mb-2">결재자를 순서대로 추가하세요. 위에서부터 순차 결재됩니다.</p>

                <!-- 직원 검색 추가 -->
                <div class="apw-emp-search-row">
                    <select id="apwEmpSel" class="form-select">
                        <option value="0">결재자 선택</option>
                        <?php foreach ($emps as $e):
                            $uIdx = (int)($e['user_idx'] ?? 0);
                            if ($uIdx <= 0) continue;
                        ?>
                        <option value="<?= $uIdx ?>"
                                data-name="<?= apWrtH((string)($e['emp_name'] ?? '')) ?>"
                                data-dept="<?= apWrtH((string)($e['dept_name'] ?? '')) ?>">
                            <?= apWrtH((string)($e['emp_name'] ?? '')) ?>
                            <?php if ($e['dept_name']): ?> (<?= apWrtH((string)($e['dept_name'] ?? '')) ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline btn-sm" id="apwAddApproverBtn"><i class="fa fa-plus"></i> 추가</button>
                </div>

                <!-- 결재선 목록 -->
                <div id="apwLineList" class="apw-line-list">
                    <div class="apw-line-empty text-sm text-3">결재자를 추가해주세요.</div>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</section>
<style>
.apw-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: var(--sp-4);
    align-items: start;
}
.apw-form-col {
    display: flex;
    flex-direction: column;
    min-height: 560px;
}
.apw-form-body {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.apw-body-group {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.apw-body-textarea {
    flex: 1;
    resize: vertical;
    min-height: 300px;
    font-family: inherit;
    line-height: 1.6;
    white-space: pre-wrap;
}
.apw-form-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--sp-2);
    padding: var(--sp-4) var(--sp-5);
    border-top: 1px solid var(--border);
}
.apw-radio-row {
    display: flex;
    gap: var(--sp-4);
}
.apw-radio-label {
    display: flex;
    align-items: center;
    gap: var(--sp-1);
    font-size: 14px;
    color: var(--text-1);
    cursor: pointer;
}
.apw-approver-col {
    position: sticky;
    top: 80px;
}
.apw-approver-body {
    display: flex;
    flex-direction: column;
    gap: var(--sp-3);
}
.apw-emp-search-row {
    display: flex;
    gap: var(--sp-2);
    align-items: center;
}
.apw-emp-search-row .form-select { flex: 1; }
.apw-line-list {
    display: flex;
    flex-direction: column;
    gap: var(--sp-2);
    min-height: 80px;
}
.apw-line-empty {
    padding: var(--sp-4);
    text-align: center;
    color: var(--text-3);
}
.apw-line-item {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
    padding: var(--sp-2) var(--sp-3);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-2);
}
.apw-line-num {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.apw-line-name {
    flex: 1;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-1);
}
.apw-line-dept {
    font-size: 12px;
    color: var(--text-3);
}
.apw-line-actions {
    display: flex;
    gap: 4px;
}
.apw-line-move-btn,
.apw-line-del-btn {
    padding: 2px 6px;
    font-size: 12px;
    color: var(--text-3);
    background: transparent;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    line-height: 1.4;
}
.apw-line-move-btn:hover { background: var(--bg-3); color: var(--text-1); }
.apw-line-del-btn:hover  { background: #fee2e2; color: #ef4444; border-color: #fca5a5; }
@media (max-width: 1024px) {
    .apw-layout { grid-template-columns: 1fr; }
    .apw-approver-col { position: static; }
}
@media (max-width: 768px) {
    .apw-form-footer { flex-direction: column; }
    .apw-emp-search-row { flex-wrap: wrap; }
}
</style>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="approval-write"]');
    if (!_section) return;

    var _apiUrl = 'dist_process/saas/Approval.php';
    var _csrf   = (SHV.csrf && SHV.csrf.get) ? SHV.csrf.get() : '';

    /* 결재선 데이터: [{user_idx, name, dept}] */
    var _approvers = [];

    /* ── 취소 ── */
    document.getElementById('apw-cancel-btn').addEventListener('click', function () {
        SHV.modal.confirm('작성 중인 내용이 저장되지 않습니다. 취소하시겠습니까?', function (ok) {
            if (ok) SHV.router.navigate('approval_req');
        });
    });

    /* ── 결재자 추가 ── */
    document.getElementById('apwAddApproverBtn').addEventListener('click', function () {
        var sel    = document.getElementById('apwEmpSel');
        var uIdx   = parseInt(sel.value, 10);
        if (!uIdx) { SHV.toast && SHV.toast.warn('결재자를 선택해주세요.'); return; }

        /* 중복 방지 */
        if (_approvers.some(function (a) { return a.user_idx === uIdx; })) {
            SHV.toast && SHV.toast.warn('이미 추가된 결재자입니다.');
            return;
        }

        var opt  = sel.options[sel.selectedIndex];
        _approvers.push({
            user_idx: uIdx,
            name:     opt.dataset.name || opt.text,
            dept:     opt.dataset.dept || '',
        });
        sel.value = '0';
        renderLines();
    });

    /* ── 결재선 렌더링 ── */
    var _lineList = document.getElementById('apwLineList');
    _lineList.addEventListener('click', handleLineAction);

    function renderLines() {
        if (_approvers.length === 0) {
            _lineList.innerHTML = '<div class="apw-line-empty text-sm text-3">결재자를 추가해주세요.</div>';
            return;
        }
        _lineList.innerHTML = '';
        _approvers.forEach(function (a, i) {
            var item = document.createElement('div');
            item.className = 'apw-line-item';
            item.innerHTML =
                '<div class="apw-line-num">' + (i + 1) + '</div>' +
                '<div class="apw-line-name">' + escH(a.name) +
                    (a.dept ? '<br><span class="apw-line-dept">' + escH(a.dept) + '</span>' : '') +
                '</div>' +
                '<div class="apw-line-actions">' +
                    (i > 0 ? '<button class="apw-line-move-btn" data-dir="up" data-idx="' + i + '"><i class="fa fa-angle-up"></i></button>' : '') +
                    (i < _approvers.length - 1 ? '<button class="apw-line-move-btn" data-dir="down" data-idx="' + i + '"><i class="fa fa-angle-down"></i></button>' : '') +
                    '<button class="apw-line-del-btn" data-idx="' + i + '"><i class="fa fa-times"></i></button>' +
                '</div>';
            _lineList.appendChild(item);
        });
    }

    function handleLineAction(e) {
        var moveBtn = e.target.closest('.apw-line-move-btn');
        var delBtn  = e.target.closest('.apw-line-del-btn');
        if (moveBtn) {
            var idx = parseInt(moveBtn.dataset.idx, 10);
            var dir = moveBtn.dataset.dir;
            if (dir === 'up' && idx > 0) {
                var tmp = _approvers[idx - 1];
                _approvers[idx - 1] = _approvers[idx];
                _approvers[idx] = tmp;
            } else if (dir === 'down' && idx < _approvers.length - 1) {
                var tmp2 = _approvers[idx + 1];
                _approvers[idx + 1] = _approvers[idx];
                _approvers[idx] = tmp2;
            }
            renderLines();
        }
        if (delBtn) {
            var dIdx = parseInt(delBtn.dataset.idx, 10);
            _approvers.splice(dIdx, 1);
            renderLines();
        }
    }

    /* ── 유효성 검사 ── */
    function validate() {
        var title = document.getElementById('apwTitle').value.trim();
        var body  = document.getElementById('apwBody').value.trim();
        if (!title)             { showErr('제목을 입력해주세요.'); return false; }
        if (!body)              { showErr('내용을 입력해주세요.'); return false; }
        if (_approvers.length === 0) { showErr('결재자를 한 명 이상 추가해주세요.'); return false; }
        hideErr();
        return true;
    }

    function showErr(msg) {
        var el = document.getElementById('apwErr');
        el.textContent = msg;
        el.classList.remove('hidden');
    }
    function hideErr() {
        var el = document.getElementById('apwErr');
        el.textContent = '';
        el.classList.add('hidden');
    }

    /* ── 문서 유형 ── */
    function getDocType() {
        var radios = document.querySelectorAll('[name="apwDocType"]');
        for (var i = 0; i < radios.length; i++) {
            if (radios[i].checked) return radios[i].value;
        }
        return 'GENERAL';
    }

    /* ── 임시저장 ── */
    document.getElementById('apwDraftBtn').addEventListener('click', function () {
        if (!validate()) return;
        var btn = this;
        btn.disabled = true;
        SHV.api.post(_apiUrl, {
            csrf_token:   _csrf,
            todo:         'approval_write',
            title:        document.getElementById('apwTitle').value.trim(),
            body_text:    document.getElementById('apwBody').value.trim(),
            doc_type:     getDocType(),
            approver_ids: _approvers.map(function (a) { return a.user_idx; }).join(','),
        }).then(function (res) {
            btn.disabled = false;
            if (!res || !res.ok) { showErr(res && res.message ? res.message : '저장 실패'); return; }
            SHV.toast && SHV.toast.success('임시저장 되었습니다.');
            SHV.router.navigate('approval_req');
        }).catch(function () {
            btn.disabled = false;
            showErr('서버 오류가 발생했습니다.');
        });
    });

    /* ── 결재 상신 ── */
    document.getElementById('apwSubmitBtn').addEventListener('click', function () {
        if (!validate()) return;
        SHV.modal.confirm('결재를 상신하시겠습니까?\n상신 후에는 내용을 수정할 수 없습니다.', function (ok) {
            if (!ok) return;
            var btn = document.getElementById('apwSubmitBtn');
            btn.disabled = true;

            /* 1단계: 임시저장 생성 */
            SHV.api.post(_apiUrl, {
                csrf_token:   _csrf,
                todo:         'approval_write',
                title:        document.getElementById('apwTitle').value.trim(),
                body_text:    document.getElementById('apwBody').value.trim(),
                doc_type:     getDocType(),
                approver_ids: _approvers.map(function (a) { return a.user_idx; }).join(','),
            }).then(function (res) {
                if (!res || !res.ok) {
                    btn.disabled = false;
                    showErr(res && res.message ? res.message : '문서 생성 실패');
                    return;
                }
                var item  = (res.data && res.data.item) ? res.data.item : (res.item || {});
                var docId = parseInt(item.idx || 0, 10);
                if (!docId) { btn.disabled = false; showErr('문서 ID를 확인할 수 없습니다.'); return; }

                /* 2단계: 결재 상신 */
                return SHV.api.post(_apiUrl, {
                    csrf_token: _csrf,
                    todo:       'approval_submit',
                    doc_id:     docId,
                });
            }).then(function (res2) {
                if (!res2) return;
                btn.disabled = false;
                if (!res2 || !res2.ok) {
                    showErr(res2 && res2.message ? res2.message : '상신 실패');
                    return;
                }
                SHV.toast && SHV.toast.success('결재가 상신되었습니다.');
                SHV.router.navigate('approval_req');
            }).catch(function () {
                btn.disabled = false;
                showErr('서버 오류가 발생했습니다.');
            });
        });
    });

    /* ── 헬퍼 ── */
    function escH(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    SHV.pages = SHV.pages || {};
    SHV.pages['approval_write'] = { destroy: function () {} };
})();
</script>
