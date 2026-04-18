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
<section data-page="mail_compose" data-title="메일쓰기" class="mail-page" id="composePage">

<!-- ── 헤더 ── -->
<div class="mail-header">
    <div class="mail-header-left">
        <h2>메일쓰기</h2>
        <p>새 메일 작성</p>
    </div>
    <div class="mail-header-right">
        <button class="btn btn-ghost btn-sm" id="btnDraftSave"><i class="fa fa-floppy-o"></i> 임시저장</button>
        <button class="btn btn-glass-primary btn-sm" id="btnSend"><i class="fa fa-paper-plane"></i> 발송</button>
    </div>
</div>

<!-- ── 작성 폼 ── -->
<div class="card mail-compose-wrap">
    <form id="composeForm" enctype="multipart/form-data">

        <div class="mail-compose-field">
            <label class="mail-compose-label" for="composeTo">받는사람</label>
            <input class="mail-compose-input" type="text" id="composeTo" name="to" placeholder="example@domain.com, user2@domain.com">
        </div>

        <div class="mail-compose-field">
            <label class="mail-compose-label" for="composeCc">참조</label>
            <input class="mail-compose-input" type="text" id="composeCc" name="cc" placeholder="cc@domain.com">
        </div>

        <div class="mail-compose-field">
            <label class="mail-compose-label" for="composeBcc">숨은참조</label>
            <input class="mail-compose-input" type="text" id="composeBcc" name="bcc" placeholder="bcc@domain.com">
        </div>

        <div class="mail-compose-field">
            <label class="mail-compose-label" for="composeSubject">제목</label>
            <input class="mail-compose-input" type="text" id="composeSubject" name="subject" placeholder="제목을 입력하세요">
        </div>

        <div class="mail-compose-field mail-compose-field-body">
            <textarea class="mail-compose-body" id="composeBody" name="body_html" placeholder="본문을 입력하세요"></textarea>
        </div>

        <div class="mail-compose-field">
            <label class="mail-compose-label" for="composeAttach">첨부파일</label>
            <input class="mail-compose-input" type="file" id="composeAttach" name="attach[]" multiple>
        </div>

    </form>
</div>

</section>

<script>
(function () {
    'use strict';

    var params = new URLSearchParams(window.location.search);
    var mode      = params.get('mode') || '';
    var sourceUid = params.get('uid') || '';
    var srcFolder = params.get('folder') || 'INBOX';

    /* 답장/전달 모드일 때 원본 메일 로드 */
    if (mode && sourceUid) {
        SHV.mail.loadMailDetail(sourceUid, srcFolder).then(function (res) {
            var d = res.data || res;
            var subj = d.subject || '';

            if (mode === 'reply' || mode === 'reply_all') {
                if (subj.indexOf('Re:') !== 0) subj = 'Re: ' + subj;
                document.getElementById('composeTo').value = d.from_email || '';
                if (mode === 'reply_all' && d.cc) {
                    document.getElementById('composeCc').value = d.cc;
                }
            } else if (mode === 'forward') {
                if (subj.indexOf('Fwd:') !== 0) subj = 'Fwd: ' + subj;
            }

            document.getElementById('composeSubject').value = subj;
            var quote = '\n\n--- 원본 메일 ---\n보낸사람: ' + (d.from_name || '') + ' <' + (d.from_email || '') + '>\n날짜: ' + (d.date || '') + '\n\n';
            document.getElementById('composeBody').value = quote + (d.body_text || '');
        });
    }

    /* 발송 */
    document.getElementById('btnSend').addEventListener('click', function () {
        var to = document.getElementById('composeTo').value.trim();
        if (!to) { SHV.toast.warn('받는사람을 입력하세요.'); return; }

        var fd = new FormData(document.getElementById('composeForm'));
        SHV.mail.sendMail(fd).then(function () {
            SHV.toast.success('메일이 발송되었습니다.');
            SHV.router.navigate('mail_sent');
        }).catch(function (e) {
            SHV.toast.error('발송 실패: ' + (e.message || ''));
        });
    });

    /* 임시저장 */
    document.getElementById('btnDraftSave').addEventListener('click', function () {
        SHV.mail.saveDraft({
            subject:   document.getElementById('composeSubject').value,
            body_html: document.getElementById('composeBody').value,
            to:        document.getElementById('composeTo').value,
            cc:        document.getElementById('composeCc').value,
            bcc:       document.getElementById('composeBcc').value
        }).then(function () {
            SHV.toast.success('임시저장되었습니다.');
        }).catch(function () {
            SHV.toast.error('임시저장 실패');
        });
    });

})();
</script>
