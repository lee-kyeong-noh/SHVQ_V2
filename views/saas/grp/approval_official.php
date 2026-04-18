<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

function apOffH(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="approval-official"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$security  = require __DIR__ . '/../../../config/security.php';
$service   = new GroupwareService(DbConnection::get(), $security);
$scope     = $service->resolveScope($context, '', 0);
$userPk    = (int)($context['user_pk'] ?? 0);
$roleLevel = (int)($context['role_level'] ?? 0);

$missing = $service->missingTables($service->requiredTablesByDomain('approval'));
$ready   = $missing === [];

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterSearch = trim((string)($_GET['search'] ?? ''));

$list = [];
if ($ready) {
    $list = $service->listApprovalOfficial($scope, [
        'status' => $filterStatus,
        'search' => $filterSearch,
        'limit'  => 200,
    ]);
}

$statusMap = [
    'DRAFT'     => ['label' => '임시저장', 'badge' => 'badge-ghost'],
    'SUBMITTED' => ['label' => '결재중',   'badge' => 'badge-warn'],
    'APPROVED'  => ['label' => '승인완료', 'badge' => 'badge-success'],
    'REJECTED'  => ['label' => '반려',     'badge' => 'badge-danger'],
    'CANCELED'  => ['label' => '취소',     'badge' => 'badge-ghost'],
];
?>
<section data-page="approval-official"
         data-user-pk="<?= $userPk ?>"
         data-role="<?= $roleLevel ?>">
    <div class="page-header">
        <h2 class="page-title" data-title="공문서함">공문서함</h2>
        <p class="page-subtitle">공식 결재 문서 조회 · 관리</p>
        <div class="page-header-actions">
            <button class="btn btn-primary btn-sm" id="aoWriteBtn">
                <i class="fa fa-pencil mr-1"></i>공문 작성
            </button>
        </div>
    </div>

    <?php if (!$ready): ?>
    <div class="card card-mt card-mb">
        <div class="card-body"><div class="empty-state"><p class="empty-message">전자결재 DB가 준비되지 않았습니다.</p></div></div>
    </div>
    <?php else: ?>

    <!-- 필터 -->
    <div class="card card-mt">
        <div class="card-body">
            <div class="form-row form-row--filter">
                <div class="form-group mw-120">
                    <label class="form-label">상태</label>
                    <select id="aoStatus" class="form-select">
                        <option value="">전체</option>
                        <option value="SUBMITTED" <?= $filterStatus === 'SUBMITTED' ? 'selected' : '' ?>>결재중</option>
                        <option value="APPROVED"  <?= $filterStatus === 'APPROVED'  ? 'selected' : '' ?>>승인완료</option>
                        <option value="REJECTED"  <?= $filterStatus === 'REJECTED'  ? 'selected' : '' ?>>반려</option>
                        <option value="CANCELED"  <?= $filterStatus === 'CANCELED'  ? 'selected' : '' ?>>취소</option>
                    </select>
                </div>
                <div class="form-group fg-2">
                    <label class="form-label">검색</label>
                    <input id="aoSearch" type="text" class="form-input" placeholder="제목 · 문서번호" value="<?= apOffH($filterSearch) ?>">
                </div>
                <div class="fg-auto">
                    <button id="aoSearchBtn" class="btn btn-primary"><i class="fa fa-search"></i> 조회</button>
                </div>
            </div>
        </div>
    </div>

    <div class="ap-layout card-mt card-mb">

        <!-- 좌: 목록 -->
        <div class="card ap-list-col">
            <div class="card-header">
                <span>공문 목록</span>
                <span class="card-header-meta" id="aoListCount"><?= count($list) ?>건</span>
            </div>
            <div class="card-body--table">
                <table class="tbl tbl-sticky-header" id="aoTable">
                    <colgroup>
                        <col class="col-88"><col><col class="col-80">
                        <col class="col-90"><col class="col-120">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>문서번호</th><th>제목</th>
                            <th>기안자</th><th class="th-center">상태</th><th>상신일</th>
                        </tr>
                    </thead>
                    <tbody id="aoListBody">
                    <?php if ($list === []): ?>
                        <tr><td colspan="5">
                            <div class="empty-state"><p class="empty-message">공문서가 없습니다.</p></div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($list as $row):
                            $st    = (string)($row['status'] ?? 'SUBMITTED');
                            $stCfg = $statusMap[$st] ?? ['label' => $st, 'badge' => 'badge-ghost'];
                            $date  = substr((string)($row['submitted_at'] ?? $row['created_at'] ?? ''), 0, 16);
                        ?>
                        <tr class="clickable" data-doc-idx="<?= (int)($row['idx'] ?? 0) ?>">
                            <td class="td-mono td-nowrap"><?= apOffH((string)($row['doc_no'] ?? '')) ?></td>
                            <td class="font-semibold"><?= apOffH((string)($row['title'] ?? '')) ?></td>
                            <td class="td-muted"><?= apOffH((string)($row['writer_name'] ?? '')) ?></td>
                            <td class="td-center"><span class="badge <?= $stCfg['badge'] ?>"><?= $stCfg['label'] ?></span></td>
                            <td class="td-muted td-nowrap"><?= apOffH($date) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 우: 문서 상세 -->
        <div class="ap-detail-col card" id="aoDetailCol" style="display:none;">
            <div class="card-header">
                <span id="aoDetailTitle" class="font-semibold">문서 상세</span>
                <button class="modal-close" id="aoDetailClose"><i class="fa fa-times"></i></button>
            </div>
            <div class="ap-detail-body" id="aoDetailBody"></div>
            <div class="ap-detail-footer" id="aoDetailFooter" style="display:none;">
                <input id="aoCommentInput" type="text" class="form-input" placeholder="의견 (반려 시 필수)">
                <div class="ap-footer-btns">
                    <button class="btn btn-outline btn-sm" id="aoCommentBtn"><i class="fa fa-comment-o mr-1"></i>댓글</button>
                    <button class="btn btn-danger btn-sm"  id="aoRejectBtn"  style="display:none;"><i class="fa fa-times-circle-o mr-1"></i>반려</button>
                    <button class="btn btn-primary btn-sm" id="aoApproveBtn" style="display:none;"><i class="fa fa-check-circle-o mr-1"></i>승인</button>
                </div>
            </div>
        </div>

    </div>

    <?php endif; ?>
</section>
<style>
.page-header-actions { display: flex; gap: var(--sp-2); align-items: center; }
.ap-layout {
    display: flex; gap: var(--sp-3);
    padding: 0 var(--sp-5); align-items: flex-start;
}
.ap-list-col { flex: 1; min-width: 0; }
.ap-detail-col {
    width: 420px; flex-shrink: 0;
    display: flex; flex-direction: column;
    max-height: calc(100vh - 150px);
    position: sticky; top: 60px;
}
.ap-detail-body { flex: 1; overflow-y: auto; padding: var(--sp-4); min-height: 200px; }
.ap-detail-footer {
    padding: var(--sp-3) var(--sp-4);
    border-top: 1px solid var(--divider);
    display: flex; flex-direction: column; gap: var(--sp-2);
}
.ap-footer-btns { display: flex; gap: var(--sp-2); justify-content: flex-end; }
.ap-doc-meta { display: grid; grid-template-columns: auto 1fr; gap: 4px var(--sp-3); font-size: 13px; margin-bottom: var(--sp-3); }
.ap-doc-meta dt { color: var(--text-3); white-space: nowrap; }
.ap-doc-meta dd { color: var(--text-1); margin: 0; }
.ap-doc-body {
    background: var(--glass-bg); border: 1px solid var(--divider);
    border-radius: var(--radius-sm); padding: var(--sp-3);
    font-size: 13px; line-height: 1.7;
    white-space: pre-wrap; word-break: break-all;
    color: var(--text-1); min-height: 80px; margin-bottom: var(--sp-3);
}
.ap-section-lbl { font-size: 11px; font-weight: 600; color: var(--text-3); text-transform: uppercase; margin: var(--sp-2) 0 var(--sp-1); }
.ap-lines { display: flex; flex-direction: column; gap: var(--sp-1); margin-bottom: var(--sp-3); }
.ap-line-item {
    display: flex; align-items: center; gap: var(--sp-2);
    padding: 6px var(--sp-3); border-radius: var(--radius-sm);
    background: var(--glass-bg); border: 1px solid var(--divider); font-size: 12px;
}
.ap-line-item.ap-current { border-color: var(--accent); }
.ap-line-num {
    width: 20px; height: 20px; border-radius: 50%;
    background: var(--accent); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 700; flex-shrink: 0;
}
.ap-line-num.done { background: var(--text-3); }
.ap-line-name { flex: 1; font-weight: 600; color: var(--text-1); }
.ap-line-cmnt { color: var(--text-3); font-size: 11px; }
.ap-comments { display: flex; flex-direction: column; gap: var(--sp-1); }
.ap-cmt-item { padding: 6px var(--sp-3); border-radius: var(--radius-sm); background: var(--glass-bg); border: 1px solid var(--divider); font-size: 12px; }
.ap-cmt-meta { color: var(--text-3); margin-bottom: 2px; }
.ap-cmt-text { color: var(--text-1); }
@media (max-width: 1024px) {
    .ap-layout { flex-direction: column; }
    .ap-detail-col { width: 100%; position: static; max-height: 60vh; }
}
@media (max-width: 768px) { .ap-layout { padding: 0 var(--sp-3); } }
</style>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="approval-official"]');
    if (!_section) return;

    var _userPk = parseInt(_section.dataset.userPk || '0', 10);
    var _role   = parseInt(_section.dataset.role    || '0', 10);
    var _apiUrl = 'dist_process/saas/Approval.php';
    var _csrf   = (SHV.csrf && SHV.csrf.get) ? SHV.csrf.get() : '';
    var _curDocIdx    = 0;
    var _curDocStatus = '';
    var _isMyTurn     = false;

    var _statusCfg = {
        DRAFT:     { label: '임시저장', badge: 'badge-ghost' },
        SUBMITTED: { label: '결재중',   badge: 'badge-warn' },
        APPROVED:  { label: '승인완료', badge: 'badge-success' },
        REJECTED:  { label: '반려',     badge: 'badge-danger' },
        CANCELED:  { label: '취소',     badge: 'badge-ghost' },
    };
    var _decisionCfg = {
        PENDING:  { label: '대기중', badge: 'badge-warn' },
        APPROVED: { label: '승인',   badge: 'badge-success' },
        REJECTED: { label: '반려',   badge: 'badge-danger' },
    };
    var _commentTypeLbl = { COMMENT: '댓글', APPROVE: '승인', REJECT: '반려', CANCEL: '취소' };

    /* 공문 작성 */
    document.getElementById('aoWriteBtn').addEventListener('click', function () {
        SHV.router.navigate('approval_write');
    });

    /* ── 필터 조회 ── */
    function reload() {
        SHV.router.navigate('approval_official', {
            status: document.getElementById('aoStatus').value,
            search: document.getElementById('aoSearch').value.trim(),
        });
    }
    document.getElementById('aoSearchBtn').addEventListener('click', reload);
    document.getElementById('aoSearch').addEventListener('keydown', function (e) { if (e.key === 'Enter') reload(); });

    /* ── 행 클릭 → 상세 ── */
    document.getElementById('aoListBody').addEventListener('click', function (e) {
        var tr = e.target.closest('tr[data-doc-idx]');
        if (!tr) return;
        _section.querySelectorAll('#aoListBody tr.row-selected').forEach(function (r) { r.classList.remove('row-selected'); });
        tr.classList.add('row-selected');
        loadDetail(parseInt(tr.dataset.docIdx, 10));
    });

    function loadDetail(docIdx) {
        _curDocIdx = docIdx;
        var col  = document.getElementById('aoDetailCol');
        var body = document.getElementById('aoDetailBody');
        col.style.display = 'flex';
        body.innerHTML = '<div class="empty-state"><p class="empty-message">로딩 중...</p></div>';
        document.getElementById('aoDetailFooter').style.display = 'none';

        SHV.api.get(_apiUrl, { todo: 'approval_detail', doc_id: docIdx })
            .then(function (res) {
                if (!res || !res.ok || !(res.data && res.data.item)) {
                    body.innerHTML = '<div class="empty-state"><p class="empty-message">문서를 불러올 수 없습니다.</p></div>';
                    return;
                }
                renderDetail(res.data.item);
            })
            .catch(function () {
                body.innerHTML = '<div class="empty-state"><p class="empty-message">오류가 발생했습니다.</p></div>';
            });
    }

    function renderDetail(doc) {
        var status   = (doc.status || 'DRAFT').toUpperCase();
        var stCfg    = _statusCfg[status] || { label: status, badge: 'badge-ghost' };
        var lines    = Array.isArray(doc.lines)    ? doc.lines    : [];
        var comments = Array.isArray(doc.comments) ? doc.comments : [];
        var curLineOrder = parseInt(doc.current_line_order || 0, 10);

        _curDocStatus = status;
        _isMyTurn     = false;
        document.getElementById('aoDetailTitle').textContent = doc.title || '문서 상세';

        var html = '<dl class="ap-doc-meta">'
                 + '<dt>문서번호</dt><dd class="td-mono">' + escH(doc.doc_no || '') + '</dd>'
                 + '<dt>분류</dt><dd>공문</dd>'
                 + '<dt>상태</dt><dd><span class="badge ' + stCfg.badge + '">' + stCfg.label + '</span></dd>'
                 + '<dt>기안자</dt><dd>' + escH(doc.writer_name || '') + '</dd>'
                 + '<dt>제출일</dt><dd>' + escH((doc.submitted_at || '').slice(0, 16)) + '</dd>'
                 + '</dl>';

        html += '<div class="ap-section-lbl">내용</div>'
              + '<div class="ap-doc-body">' + escH(doc.body_text || '') + '</div>';

        html += '<div class="ap-section-lbl">결재선</div><div class="ap-lines">';
        lines.forEach(function (line) {
            var ds      = (line.decision_status || 'PENDING').toUpperCase();
            var dsCfg   = _decisionCfg[ds] || { label: ds, badge: 'badge-ghost' };
            var lineOrd = parseInt(line.line_order, 10);
            var isCur   = lineOrd === curLineOrder && status === 'SUBMITTED';
            if (isCur && parseInt(line.approver_user_idx, 10) === _userPk && ds === 'PENDING') {
                _isMyTurn = true;
            }
            html += '<div class="ap-line-item' + (isCur ? ' ap-current' : '') + '">'
                  + '<div class="ap-line-num' + (ds !== 'PENDING' ? ' done' : '') + '">' + escH(String(line.line_order)) + '</div>'
                  + '<div class="ap-line-name">' + escH(line.approver_name || '') + '</div>'
                  + '<span class="badge ' + dsCfg.badge + '">' + dsCfg.label + '</span>'
                  + (line.comment_text ? '<span class="ap-line-cmnt">"' + escH(line.comment_text) + '"</span>' : '')
                  + '</div>';
        });
        html += '</div>';

        if (comments.length > 0) {
            html += '<div class="ap-section-lbl">댓글</div><div class="ap-comments">';
            comments.forEach(function (c) {
                var tLbl = _commentTypeLbl[c.comment_type] || c.comment_type;
                html += '<div class="ap-cmt-item">'
                      + '<div class="ap-cmt-meta">' + escH(c.user_name || '') + ' · ' + escH(tLbl) + ' · ' + escH((c.created_at || '').slice(0, 16)) + '</div>'
                      + '<div class="ap-cmt-text">' + escH(c.comment_text || '') + '</div>'
                      + '</div>';
            });
            html += '</div>';
        }

        document.getElementById('aoDetailBody').innerHTML = html;

        var showAction = (_isMyTurn || _role >= 4) && status === 'SUBMITTED';
        var showFooter = showAction || (status !== 'DRAFT' && status !== 'CANCELED');
        var footer     = document.getElementById('aoDetailFooter');
        footer.style.display = showFooter ? 'flex' : 'none';
        document.getElementById('aoApproveBtn').style.display = showAction ? '' : 'none';
        document.getElementById('aoRejectBtn').style.display  = showAction ? '' : 'none';
        document.getElementById('aoCommentInput').value = '';
    }

    /* 승인 / 반려 */
    function doAction(todo, label, requireComment) {
        if (!_curDocIdx) return;
        var comment = document.getElementById('aoCommentInput').value.trim();
        if (requireComment && !comment) { SHV.toast.error(label + ' 시 의견을 입력해주세요.'); return; }
        SHV.modal.confirm(label + ' 처리하시겠습니까?', function (ok) {
            if (!ok) return;
            SHV.api.post(_apiUrl, { csrf_token: _csrf, todo: todo, doc_id: _curDocIdx, comment: comment })
                .then(function (res) {
                    if (!res || !res.ok) { SHV.toast.error(label + ' 실패: ' + (res && res.message ? res.message : '')); return; }
                    SHV.toast.success(label + ' 처리되었습니다.');
                    document.getElementById('aoCommentInput').value = '';
                    loadDetail(_curDocIdx);
                });
        });
    }

    document.getElementById('aoApproveBtn').addEventListener('click', function () { doAction('approval_approve', '승인', false); });
    document.getElementById('aoRejectBtn').addEventListener('click',  function () { doAction('approval_reject',  '반려', true); });
    document.getElementById('aoCommentBtn').addEventListener('click', function () {
        if (!_curDocIdx) return;
        var comment = document.getElementById('aoCommentInput').value.trim();
        if (!comment) { SHV.toast.error('댓글 내용을 입력해주세요.'); return; }
        SHV.api.post(_apiUrl, { csrf_token: _csrf, todo: 'approval_comment', doc_id: _curDocIdx, comment: comment })
            .then(function (res) {
                if (!res || !res.ok) { SHV.toast.error('댓글 등록 실패'); return; }
                document.getElementById('aoCommentInput').value = '';
                loadDetail(_curDocIdx);
            });
    });

    /* 닫기 */
    function closeDetail() {
        document.getElementById('aoDetailCol').style.display = 'none';
        _section.querySelectorAll('#aoListBody tr.row-selected').forEach(function (r) { r.classList.remove('row-selected'); });
        _curDocIdx = 0;
    }
    document.getElementById('aoDetailClose').addEventListener('click', closeDetail);

    function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    if (window.shvTblSort) shvTblSort(document.getElementById('aoTable'));

    SHV.pages = SHV.pages || {};
    SHV.pages['approval_official'] = { destroy: function () { closeDetail(); } };
})();
</script>
