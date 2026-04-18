<?php
declare(strict_types=1);
require_once __DIR__ . '/config/env.php';
session_name(shvEnv('SESSION_NAME', 'SHVQSESSID'));
session_set_cookie_params(['lifetime'=>shvEnvInt('SESSION_LIFETIME',7200),'path'=>'/','domain'=>'','secure'=>shvEnvBool('SESSION_SECURE_COOKIE',true),'httponly'=>shvEnvBool('SESSION_HTTP_ONLY',true),'samesite'=>shvEnv('SESSION_SAME_SITE','Lax')]);
session_start();
if (empty($_SESSION['auth']['user_pk'])) { http_response_code(401); echo '<div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div>'; exit; }
?>
<link rel="stylesheet" href="css/v2/pages/mail.css?v=20260413c">
<script src="js/pages/mail.js?v=20260413"></script>
<section data-page="mail_drafts" data-title="임시보관함" class="mail-page" id="draftsPage">

<!-- ── 헤더 ── -->
<div class="mail-header">
    <div class="mail-header-left">
        <h2>임시보관함</h2>
        <p>임시저장된 메일</p>
    </div>
    <div class="mail-header-right">
        <input type="checkbox" class="mail-check-all" id="draftCheckAll" title="전체선택">
        <button class="btn btn-ghost btn-sm" id="btnDraftDelete" title="선택삭제"><i class="fa fa-trash-o"></i></button>
        <button class="btn btn-ghost btn-sm" id="btnDraftReload" title="새로고침"><i class="fa fa-refresh"></i></button>
        <button class="btn btn-glass-primary btn-sm" onclick="SHV.router.navigate('mail_compose')">
            <i class="fa fa-edit"></i> 메일쓰기
        </button>
    </div>
</div>

<!-- ── 리스트 ── -->
<div class="card mail-list-wrap-full">
    <div class="mail-list" id="draftListBody">
        <div class="mail-detail-empty">
            <i class="fa fa-spinner fa-spin"></i>
            <p>불러오는 중...</p>
        </div>
    </div>

    <div class="mail-pagination">
        <span class="mail-pagination-info" id="draftPagInfo">-</span>
        <div class="mail-pagination-nav">
            <button class="btn btn-ghost btn-sm" id="btnDraftPrev" disabled><i class="fa fa-angle-left"></i></button>
            <button class="btn btn-ghost btn-sm" id="btnDraftNext" disabled><i class="fa fa-angle-right"></i></button>
        </div>
    </div>
</div>

</section>

<script>
(function () {
    'use strict';

    var curPage = 1;
    var totalPages = 1;

    function loadDrafts() {
        var body = document.getElementById('draftListBody');
        body.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-spinner fa-spin"></i><p>불러오는 중...</p></div>';

        SHV.mail.loadDraftList({ page: curPage }).then(function (res) {
            var data = res.data || res;
            var items = data.items || data.list || [];
            var total = data.total || items.length;
            var limit = data.limit || 20;
            totalPages = Math.max(1, Math.ceil(total / limit));

            document.getElementById('draftPagInfo').textContent =
                items.length > 0 ? ((curPage - 1) * limit + 1) + ' - ' + Math.min(curPage * limit, total) + ' / 총 ' + total + '건' : '0건';
            document.getElementById('btnDraftPrev').disabled = curPage <= 1;
            document.getElementById('btnDraftNext').disabled = curPage >= totalPages;

            if (items.length === 0) {
                body.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-pencil-square-o"></i><p>임시저장된 메일이 없습니다</p></div>';
                return;
            }

            var html = '';
            items.forEach(function (d) {
                html += '<div class="mail-item" data-id="' + (d.id || '') + '" onclick="draftOpen(this)">';
                html += '<div class="mail-item-check"><input type="checkbox" onclick="event.stopPropagation()"></div>';
                html += '<div class="mail-item-content">';
                html += '<div class="mail-item-top">';
                html += '<span class="mail-item-sender">' + escH(d.to || '(받는사람 없음)') + '</span>';
                html += '<div class="mail-item-meta"><span class="mail-item-date">' + SHV.mail.formatDate(d.updated_at || d.created_at || '') + '</span></div>';
                html += '</div>';
                html += '<span class="mail-item-subject">' + escH(d.subject || '(제목없음)') + '</span>';
                html += '<span class="mail-item-preview">' + escH((d.body_text || '').substring(0, 80)) + '</span>';
                html += '</div></div>';
            });
            body.innerHTML = html;
        }).catch(function () {
            body.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-exclamation-circle"></i><p>목록을 불러올 수 없습니다</p></div>';
        });
    }

    window.draftOpen = function (el) {
        SHV.router.navigate('mail_compose', { draft_id: el.dataset.id });
    };

    document.getElementById('draftCheckAll').addEventListener('change', function () {
        document.querySelectorAll('.mail-item-check input').forEach(function (cb) { cb.checked = this.checked; }.bind(this));
    });

    document.getElementById('btnDraftDelete').addEventListener('click', function () {
        var ids = SHV.mail.getChecked(document.getElementById('draftListBody'), '.mail-item-check input:checked');
        if (ids.length === 0) { SHV.toast.warn('선택된 항목이 없습니다.'); return; }
        SHV.confirm.open({
            title: '임시보관 삭제',
            message: ids.length + '건을 삭제하시겠습니까?',
            onConfirm: function () {
                SHV.mail.deleteDraft(ids).then(function () { loadDrafts(); });
            }
        });
    });

    document.getElementById('btnDraftReload').addEventListener('click', function () { loadDrafts(); });
    document.getElementById('btnDraftPrev').addEventListener('click', function () { if (curPage > 1) { curPage--; loadDrafts(); } });
    document.getElementById('btnDraftNext').addEventListener('click', function () { if (curPage < totalPages) { curPage++; loadDrafts(); } });

    function escH(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    loadDrafts();
})();
</script>
