<?php
declare(strict_types=1);
require_once __DIR__ . '/config/env.php';
session_name(shvEnv('SESSION_NAME', 'SHVQSESSID'));
session_set_cookie_params(['lifetime'=>shvEnvInt('SESSION_LIFETIME',7200),'path'=>'/','domain'=>'','secure'=>shvEnvBool('SESSION_SECURE_COOKIE',true),'httponly'=>shvEnvBool('SESSION_HTTP_ONLY',true),'samesite'=>shvEnv('SESSION_SAME_SITE','Lax')]);
session_start();
if (empty($_SESSION['auth']['user_pk'])) { http_response_code(401); echo '<div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div>'; exit; }

$folderMap = [
    'mail_inbox'     => ['folder' => 'INBOX',     'title' => '받은편지함', 'desc' => '수신 메일 목록'],
    'mail_sent'      => ['folder' => 'Sent',      'title' => '보낸편지함', 'desc' => '발송 메일 목록'],
    'mail_spam'      => ['folder' => 'Spam',      'title' => '스팸메일함', 'desc' => '스팸으로 분류된 메일'],
    'mail_archive'   => ['folder' => 'Archive',   'title' => '보관메일함', 'desc' => '보관 처리된 메일'],
    'mail_duplicate' => ['folder' => 'Duplicate', 'title' => '중복메일함', 'desc' => '중복 수신된 메일'],
    'mail_trash'     => ['folder' => 'Trash',     'title' => '휴지통',     'desc' => '삭제된 메일'],
];

$page = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''), '.php');
$info = $folderMap[$page] ?? $folderMap['mail_inbox'];
$folder = $info['folder'];
$title  = $info['title'];
$desc   = $info['desc'];
?>
<link rel="stylesheet" href="css/v2/pages/mail.css?v=20260413c">
<script src="js/pages/mail.js?v=20260413"></script>
<section data-page="<?= $page ?>" data-title="<?= $title ?>" data-folder="<?= $folder ?>" class="mail-page" id="mailPage">

<!-- ── 헤더 ── -->
<div class="mail-header">
    <div class="mail-header-left">
        <h2><?= $title ?></h2>
        <p><?= $desc ?></p>
    </div>
    <div class="mail-header-right">
        <input type="checkbox" class="mail-check-all" id="mailCheckAll" title="전체선택">
        <button class="btn btn-ghost btn-sm" title="읽음 처리" id="btnMarkRead"><i class="fa fa-envelope-open-o"></i></button>
        <button class="btn btn-ghost btn-sm" title="삭제" id="btnDelete"><i class="fa fa-trash-o"></i></button>
        <button class="btn btn-ghost btn-sm" title="스팸" id="btnSpam"><i class="fa fa-ban"></i></button>
        <button class="btn btn-ghost btn-sm" id="btnReload" title="새로고침"><i class="fa fa-refresh"></i></button>
        <span class="mail-unread-count" id="mailUnreadCount"></span>
        <button class="btn btn-glass-primary btn-sm" onclick="SHV.router.navigate('mail_compose')">
            <i class="fa fa-edit"></i> 메일쓰기
        </button>
    </div>
</div>

<!-- ── 2단 분할 ── -->
<div class="mail-split">

    <!-- 좌측: 리스트 -->
    <div class="mail-list-panel card" id="mailListPanel">
        <div class="mail-toolbar">
            <div class="shv-search flex-1">
                <i class="fa fa-search shv-search-icon"></i>
                <input type="text" id="mailSearch" placeholder="보낸사람, 제목, 내용 검색..."
                    onkeydown="if(event.key==='Enter')mailDoSearch()"
                    oninput="this.closest('.shv-search').classList.toggle('has-value',!!this.value)">
                <span class="shv-search-clear" onclick="document.getElementById('mailSearch').value='';this.closest('.shv-search').classList.remove('has-value');mailDoSearch();">&#x2715;</span>
            </div>
        </div>

        <div class="mail-list" id="mailListBody">
            <div class="mail-detail-empty" id="mailListLoading">
                <i class="fa fa-spinner fa-spin"></i>
                <p>메일을 불러오는 중...</p>
            </div>
        </div>

        <div class="mail-pagination">
            <span class="mail-pagination-info" id="mailPagInfo">-</span>
            <div class="mail-pagination-nav">
                <button class="btn btn-ghost btn-sm" id="btnPrev" disabled><i class="fa fa-angle-left"></i></button>
                <button class="btn btn-ghost btn-sm" id="btnNext" disabled><i class="fa fa-angle-right"></i></button>
            </div>
        </div>
    </div>

    <!-- 우측: 본문 -->
    <div class="mail-detail-panel card" id="mailDetailPanel">
        <div class="mail-detail-empty" id="mailDetailEmpty">
            <i class="fa fa-envelope-open-o"></i>
            <p>메일을 선택하세요</p>
        </div>

        <div id="mailDetailContent" class="mail-detail-view">
            <div class="mail-detail-back">
                <button class="btn btn-ghost btn-sm" onclick="mailBack()">
                    <i class="fa fa-arrow-left"></i> 목록으로
                </button>
            </div>
            <div class="mail-detail-header">
                <h3 class="mail-detail-subject" id="detailSubject"></h3>
                <div class="mail-detail-info">
                    <div class="mail-detail-avatar" id="detailAvatar"></div>
                    <div class="mail-detail-from">
                        <div class="mail-detail-from-name" id="detailFromName"></div>
                        <div class="mail-detail-from-email" id="detailFromEmail"></div>
                        <div class="mail-detail-to" id="detailTo"></div>
                    </div>
                    <span class="mail-detail-date" id="detailDate"></span>
                </div>
            </div>
            <div class="mail-detail-actions">
                <button class="btn btn-ghost btn-sm" id="btnReply"><i class="fa fa-reply"></i> 답장</button>
                <button class="btn btn-ghost btn-sm" id="btnReplyAll"><i class="fa fa-reply-all"></i> 전체답장</button>
                <button class="btn btn-ghost btn-sm" id="btnForward"><i class="fa fa-share"></i> 전달</button>
                <button class="btn btn-ghost btn-sm" id="btnDetailDelete"><i class="fa fa-trash-o"></i> 삭제</button>
            </div>
            <div class="mail-detail-body" id="detailBody"></div>
            <div class="mail-detail-attachments" id="detailAttachments"></div>
        </div>
    </div>

</div>
</section>

<script>
(function () {
    'use strict';

    var folder  = document.getElementById('mailPage').dataset.folder;
    var curPage = 1;
    var totalPages = 1;
    var curSearch = '';

    /* ── 메일 목록 로드 ── */
    function loadList() {
        var listBody = document.getElementById('mailListBody');
        listBody.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-spinner fa-spin"></i><p>불러오는 중...</p></div>';

        SHV.mail.loadMailList({
            folder: folder,
            page: curPage,
            search: curSearch
        }).then(function (res) {
            var data = res.data || res;
            var items = data.items || data.list || [];
            var total = data.total || items.length;
            var limit = data.limit || 20;
            totalPages = Math.max(1, Math.ceil(total / limit));

            document.getElementById('mailPagInfo').textContent =
                ((curPage - 1) * limit + 1) + ' - ' + Math.min(curPage * limit, total) + ' / 총 ' + total + '건';
            document.getElementById('btnPrev').disabled = curPage <= 1;
            document.getElementById('btnNext').disabled = curPage >= totalPages;

            if (items.length === 0) {
                listBody.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-inbox"></i><p>메일이 없습니다</p></div>';
                return;
            }

            var html = '';
            items.forEach(function (m) {
                var isUnread = !m.is_read && m.is_read !== undefined ? ' unread' : '';
                var uid = m.uid || m.id || '';
                var sender = m.from_name || m.from_email || m.sender || '';
                var subject = m.subject || '(제목없음)';
                var preview = m.preview || m.body_text || '';
                var date = SHV.mail.formatDate(m.date || m.sent_at || '');
                var hasAttach = m.has_attach || m.attachments;
                var starred = m.is_starred || m.starred;

                html += '<div class="mail-item' + isUnread + '" data-uid="' + uid + '" onclick="mailSelect(this)">';
                html += '<div class="mail-item-check"><input type="checkbox" onclick="event.stopPropagation()"></div>';
                html += '<span class="mail-item-star' + (starred ? ' starred' : '') + '" onclick="event.stopPropagation();mailToggleStar(this)">';
                html += '<i class="fa fa-star' + (starred ? '' : '-o') + '"></i></span>';
                html += '<div class="mail-item-content">';
                html += '<div class="mail-item-top">';
                html += '<span class="mail-item-sender">' + escH(sender) + '</span>';
                html += '<div class="mail-item-meta">';
                if (hasAttach) html += '<i class="fa fa-paperclip mail-item-attach"></i>';
                html += '<span class="mail-item-date">' + escH(date) + '</span>';
                html += '</div></div>';
                html += '<span class="mail-item-subject" data-sender="' + escH(sender) + '">' + escH(subject) + '</span>';
                html += '<span class="mail-item-preview">' + escH(preview.substring(0, 100)) + '</span>';
                html += '</div></div>';
            });
            listBody.innerHTML = html;

            var unreadCount = items.filter(function (m) { return !m.is_read; }).length;
            var countEl = document.getElementById('mailUnreadCount');
            if (unreadCount > 0) {
                countEl.textContent = '읽지않은 메일 ' + unreadCount + '건';
            } else {
                countEl.textContent = '';
            }
        }).catch(function () {
            listBody.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-exclamation-circle"></i><p>메일 목록을 불러올 수 없습니다</p></div>';
        });
    }

    /* ── 메일 선택 ── */
    window.mailSelect = function (el) {
        var uid = el.dataset.uid;
        document.querySelectorAll('.mail-item').forEach(function (m) { m.classList.remove('active'); });
        el.classList.add('active');
        el.classList.remove('unread');

        var detailEl = document.getElementById('mailDetailContent');
        var emptyEl  = document.getElementById('mailDetailEmpty');

        SHV.mail.loadMailDetail(uid, folder).then(function (res) {
            var d = res.data || res;
            document.getElementById('detailSubject').textContent = d.subject || '';
            document.getElementById('detailAvatar').textContent = SHV.mail.initial(d.from_name || d.from_email || '');
            document.getElementById('detailFromName').textContent = d.from_name || '';
            document.getElementById('detailFromEmail').textContent = '<' + (d.from_email || '') + '>';
            document.getElementById('detailTo').textContent = '받는사람: ' + (d.to || '');
            document.getElementById('detailDate').textContent = d.date || d.sent_at || '';
            document.getElementById('detailBody').innerHTML = d.body_html || d.body_text || '';

            var attEl = document.getElementById('detailAttachments');
            var atts = d.attachments || [];
            if (atts.length > 0) {
                var ah = '<div class="mail-detail-attachments-title"><i class="fa fa-paperclip"></i> 첨부파일 ' + atts.length + '개</div>';
                atts.forEach(function (a) {
                    ah += '<span class="mail-attach-item"><i class="fa fa-file-o"></i> ' + escH(a.name || a.filename || '') + ' <span class="mail-attach-size">(' + (a.size || '') + ')</span></span>';
                });
                attEl.innerHTML = ah;
                attEl.style.display = '';
            } else {
                attEl.style.display = 'none';
            }

            emptyEl.style.display = 'none';
            detailEl.style.display = 'flex';

            if (window.innerWidth <= 1024) {
                document.getElementById('mailPage').classList.add('detail-view');
            }
        }).catch(function () {
            if (SHV.toast) SHV.toast.error('메일을 불러올 수 없습니다.');
        });
    };

    window.mailBack = function () {
        document.getElementById('mailPage').classList.remove('detail-view');
    };

    /* ── 검색 ── */
    window.mailDoSearch = function () {
        curSearch = document.getElementById('mailSearch').value.trim();
        curPage = 1;
        loadList();
    };

    /* ── 전체선택 ── */
    document.getElementById('mailCheckAll').addEventListener('change', function () {
        var checks = document.querySelectorAll('.mail-item-check input');
        for (var i = 0; i < checks.length; i++) checks[i].checked = this.checked;
    });

    /* ── 별표 토글 ── */
    window.mailToggleStar = function (star) {
        star.classList.toggle('starred');
        var icon = star.querySelector('i');
        icon.className = star.classList.contains('starred') ? 'fa fa-star' : 'fa fa-star-o';
    };

    /* ── 액션 버튼 ── */
    document.getElementById('btnReload').addEventListener('click', function () { loadList(); });
    document.getElementById('btnPrev').addEventListener('click', function () { if (curPage > 1) { curPage--; loadList(); } });
    document.getElementById('btnNext').addEventListener('click', function () { if (curPage < totalPages) { curPage++; loadList(); } });

    document.getElementById('btnMarkRead').addEventListener('click', function () {
        var uids = SHV.mail.getChecked(document.getElementById('mailListBody'));
        if (uids.length === 0) { if (SHV.toast) SHV.toast.warn('선택된 메일이 없습니다.'); return; }
        SHV.mail.markRead(uids, folder).then(function () { loadList(); });
    });

    document.getElementById('btnDelete').addEventListener('click', function () {
        var uids = SHV.mail.getChecked(document.getElementById('mailListBody'));
        if (uids.length === 0) { if (SHV.toast) SHV.toast.warn('선택된 메일이 없습니다.'); return; }
        SHV.confirm.open({
            title: '메일 삭제',
            message: uids.length + '건의 메일을 삭제하시겠습니까?',
            onConfirm: function () {
                SHV.mail.deleteMail(uids, folder).then(function () { loadList(); });
            }
        });
    });

    /* ── 답장/전달 ── */
    document.getElementById('btnReply').addEventListener('click', function () {
        SHV.router.navigate('mail_compose', { mode: 'reply', uid: getActiveUid(), folder: folder });
    });
    document.getElementById('btnReplyAll').addEventListener('click', function () {
        SHV.router.navigate('mail_compose', { mode: 'reply_all', uid: getActiveUid(), folder: folder });
    });
    document.getElementById('btnForward').addEventListener('click', function () {
        SHV.router.navigate('mail_compose', { mode: 'forward', uid: getActiveUid(), folder: folder });
    });
    document.getElementById('btnDetailDelete').addEventListener('click', function () {
        var uid = getActiveUid();
        if (!uid) return;
        SHV.confirm.open({
            title: '메일 삭제',
            message: '이 메일을 삭제하시겠습니까?',
            onConfirm: function () {
                SHV.mail.deleteMail([uid], folder).then(function () {
                    document.getElementById('mailDetailContent').style.display = 'none';
                    document.getElementById('mailDetailEmpty').style.display = '';
                    if (window.innerWidth <= 1024) mailBack();
                    loadList();
                });
            }
        });
    });

    function getActiveUid() {
        var active = document.querySelector('.mail-item.active');
        return active ? active.dataset.uid : '';
    }

    function escH(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    /* ── 초기 로드 ── */
    loadList();

})();
</script>
