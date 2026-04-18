<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

function apDoneH(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="approval-done"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$security  = require __DIR__ . '/../../../config/security.php';
$service   = new GroupwareService(DbConnection::get(), $security);
$scope     = $service->resolveScope($context, '', 0);
$userPk    = (int)($context['user_pk'] ?? 0);
$roleLevel = (int)($context['role_level'] ?? 0);

$missing = $service->missingTables($service->requiredTablesByDomain('approval'));
$ready   = $missing === [];

$doneList = [];
if ($ready) {
    $doneList = $service->listApprovalDone($scope, $userPk);
}

$typeLabels = ['GENERAL' => '일반', 'OFFICIAL' => '공문'];
$statusMap  = [
    'DRAFT'     => ['label' => '임시저장', 'badge' => 'badge-ghost'],
    'SUBMITTED' => ['label' => '결재중',   'badge' => 'badge-warn'],
    'APPROVED'  => ['label' => '승인완료', 'badge' => 'badge-success'],
    'REJECTED'  => ['label' => '반려',     'badge' => 'badge-danger'],
    'CANCELED'  => ['label' => '취소',     'badge' => 'badge-ghost'],
];
$decisionMap = [
    'APPROVED' => ['label' => '승인', 'badge' => 'badge-success'],
    'REJECTED' => ['label' => '반려', 'badge' => 'badge-danger'],
];
?>
<section data-page="approval-done"
         data-user-pk="<?= $userPk ?>"
         data-role="<?= $roleLevel ?>">
    <div class="page-header">
        <h2 class="page-title" data-title="처리완료">처리완료 결재함</h2>
        <p class="page-subtitle">내가 처리(승인·반려)한 결재 문서</p>
        <div class="page-header-actions">
            <button class="btn btn-primary btn-sm" id="apdWriteBtn">
                <i class="fa fa-pencil mr-1"></i>결재 작성
            </button>
        </div>
    </div>

    <?php if (!$ready): ?>
    <div class="card card-mt card-mb">
        <div class="card-body"><div class="empty-state"><p class="empty-message">전자결재 DB가 준비되지 않았습니다.</p></div></div>
    </div>
    <?php else: ?>

    <div class="ap-layout card-mt card-mb">

        <!-- 좌: 목록 -->
        <div class="card ap-list-col">
            <div class="card-header">
                <span>처리완료 내역</span>
                <span class="card-header-meta"><?= count($doneList) ?>건</span>
            </div>
            <div class="card-body--table">
                <table class="tbl tbl-sticky-header" id="apdTable">
                    <colgroup>
                        <col class="col-88"><col class="col-60"><col>
                        <col class="col-80"><col class="col-80"><col class="col-120">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>문서번호</th><th class="th-center">분류</th><th>제목</th>
                            <th>기안자</th><th class="th-center">내 처리</th><th>처리일</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($doneList === []): ?>
                        <tr><td colspan="6">
                            <div class="empty-state"><p class="empty-message">처리완료된 결재 문서가 없습니다.</p></div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($doneList as $row):
                            $dec    = (string)($row['my_decision'] ?? 'APPROVED');
                            $decCfg = $decisionMap[$dec] ?? ['label' => $dec, 'badge' => 'badge-ghost'];
                            $date   = substr((string)($row['decided_at'] ?? $row['updated_at'] ?? ''), 0, 16);
                        ?>
                        <tr class="clickable" data-doc-idx="<?= (int)($row['idx'] ?? 0) ?>">
                            <td class="td-mono td-nowrap"><?= apDoneH((string)($row['doc_no'] ?? '')) ?></td>
                            <td class="td-center">
                                <span class="badge badge-ghost"><?= $typeLabels[(string)($row['doc_type'] ?? 'GENERAL')] ?? '일반' ?></span>
                            </td>
                            <td class="font-semibold"><?= apDoneH((string)($row['title'] ?? '')) ?></td>
                            <td class="td-muted"><?= apDoneH((string)($row['writer_name'] ?? '')) ?></td>
                            <td class="td-center"><span class="badge <?= $decCfg['badge'] ?>"><?= $decCfg['label'] ?></span></td>
                            <td class="td-muted td-nowrap"><?= apDoneH($date) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 우: 문서 상세 -->
        <div class="ap-detail-col card" id="apdDetailCol" style="display:none;">
            <div class="card-header">
                <span id="apdDetailTitle" class="font-semibold">문서 상세</span>
                <button class="modal-close" id="apdDetailClose"><i class="fa fa-times"></i></button>
            </div>
            <div class="ap-detail-body" id="apdDetailBody"></div>
            <div class="ap-detail-footer" id="apdDetailFooter" style="display:none;">
                <input id="apdCommentInput" type="text" class="form-input" placeholder="댓글 입력">
                <div class="ap-footer-btns">
                    <button class="btn btn-outline btn-sm" id="apdCommentBtn"><i class="fa fa-comment-o mr-1"></i>댓글</button>
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

    var _section = document.querySelector('[data-page="approval-done"]');
    if (!_section) return;

    var _apiUrl  = 'dist_process/saas/Approval.php';
    var _csrf    = (SHV.csrf && SHV.csrf.get) ? SHV.csrf.get() : '';
    var _curDocIdx = 0;

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

    /* 결재 작성 이동 */
    document.getElementById('apdWriteBtn').addEventListener('click', function () {
        SHV.router.navigate('approval_write');
    });

    /* ── 행 클릭 → 상세 ── */
    document.querySelector('#apdTable tbody').addEventListener('click', function (e) {
        var tr = e.target.closest('tr[data-doc-idx]');
        if (!tr) return;
        _section.querySelectorAll('#apdTable tbody tr.row-selected').forEach(function (r) { r.classList.remove('row-selected'); });
        tr.classList.add('row-selected');
        loadDetail(parseInt(tr.dataset.docIdx, 10));
    });

    function loadDetail(docIdx) {
        _curDocIdx = docIdx;
        var col  = document.getElementById('apdDetailCol');
        var body = document.getElementById('apdDetailBody');
        col.style.display = 'flex';
        body.innerHTML = '<div class="empty-state"><p class="empty-message">로딩 중...</p></div>';
        document.getElementById('apdDetailFooter').style.display = 'none';

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

        document.getElementById('apdDetailTitle').textContent = doc.title || '문서 상세';

        var html = '<dl class="ap-doc-meta">'
                 + '<dt>문서번호</dt><dd class="td-mono">' + escH(doc.doc_no || '') + '</dd>'
                 + '<dt>분류</dt><dd>' + (doc.doc_type === 'OFFICIAL' ? '공문' : '일반') + '</dd>'
                 + '<dt>상태</dt><dd><span class="badge ' + stCfg.badge + '">' + stCfg.label + '</span></dd>'
                 + '<dt>기안자</dt><dd>' + escH(doc.writer_name || '') + '</dd>'
                 + '<dt>제출일</dt><dd>' + escH((doc.submitted_at || '').slice(0, 16)) + '</dd>'
                 + '</dl>';

        html += '<div class="ap-section-lbl">내용</div>'
              + '<div class="ap-doc-body">' + escH(doc.body_text || '') + '</div>';

        html += '<div class="ap-section-lbl">결재선</div><div class="ap-lines">';
        lines.forEach(function (line) {
            var ds    = (line.decision_status || 'PENDING').toUpperCase();
            var dsCfg = _decisionCfg[ds] || { label: ds, badge: 'badge-ghost' };
            var isCur = parseInt(line.line_order, 10) === curLineOrder && status === 'SUBMITTED';
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

        document.getElementById('apdDetailBody').innerHTML = html;

        /* 댓글 푸터 (DRAFT/CANCELED 제외) */
        var showCmt = status !== 'DRAFT' && status !== 'CANCELED';
        var footer  = document.getElementById('apdDetailFooter');
        footer.style.display = showCmt ? 'flex' : 'none';
        document.getElementById('apdCommentInput').value = '';
    }

    /* 댓글 */
    document.getElementById('apdCommentBtn').addEventListener('click', function () {
        if (!_curDocIdx) return;
        var comment = document.getElementById('apdCommentInput').value.trim();
        if (!comment) { SHV.toast.error('댓글 내용을 입력해주세요.'); return; }
        SHV.api.post(_apiUrl, { csrf_token: _csrf, todo: 'approval_comment', doc_id: _curDocIdx, comment: comment })
            .then(function (res) {
                if (!res || !res.ok) { SHV.toast.error('댓글 등록 실패'); return; }
                document.getElementById('apdCommentInput').value = '';
                loadDetail(_curDocIdx);
            });
    });

    /* 닫기 */
    function closeDetail() {
        document.getElementById('apdDetailCol').style.display = 'none';
        _section.querySelectorAll('#apdTable tbody tr.row-selected').forEach(function (r) { r.classList.remove('row-selected'); });
        _curDocIdx = 0;
    }
    document.getElementById('apdDetailClose').addEventListener('click', closeDetail);

    function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    if (window.shvTblSort) shvTblSort(document.getElementById('apdTable'));

    SHV.pages = SHV.pages || {};
    SHV.pages['approval_done'] = { destroy: function () { closeDetail(); } };
})();
</script>
