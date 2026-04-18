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
<section data-page="mail_account_settings" data-title="계정설정" class="mail-page" id="accountPage">

<!-- ── 헤더 ── -->
<div class="mail-header">
    <div class="mail-header-left">
        <h2>계정설정</h2>
        <p>IMAP/SMTP 메일 계정 관리</p>
    </div>
    <div class="mail-header-right">
        <button class="btn btn-ghost btn-sm" id="btnAccReload"><i class="fa fa-refresh"></i> 새로고침</button>
        <button class="btn btn-glass-primary btn-sm" id="btnAccNew"><i class="fa fa-plus"></i> 계정 추가</button>
    </div>
</div>

<!-- ── 2단: 폼 + 목록 ── -->
<div class="mail-split">

    <!-- 좌측: 계정 등록/수정 폼 -->
    <div class="mail-list-panel card" id="accFormPanel">
        <div class="mail-compose-wrap">
            <input type="hidden" id="accEditIdx" value="0">

            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accKey">계정키</label>
                <input class="mail-compose-input" type="text" id="accKey" placeholder="mail.main">
            </div>
            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accName">표시명</label>
                <input class="mail-compose-input" type="text" id="accName" placeholder="회사 메일">
            </div>
            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accHost">IMAP Host</label>
                <input class="mail-compose-input" type="text" id="accHost" placeholder="mail.example.com">
            </div>
            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accPort">IMAP Port</label>
                <input class="mail-compose-input" type="number" id="accPort" value="993">
            </div>
            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accSsl">SSL</label>
                <select class="mail-compose-input" id="accSsl">
                    <option value="1">사용</option>
                    <option value="0">미사용</option>
                </select>
            </div>
            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accLoginId">로그인 ID</label>
                <input class="mail-compose-input" type="text" id="accLoginId" placeholder="user@domain.com">
            </div>
            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accPassword">비밀번호</label>
                <input class="mail-compose-input" type="password" id="accPassword" placeholder="저장 시 갱신">
            </div>
            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accSmtpHost">SMTP Host</label>
                <input class="mail-compose-input" type="text" id="accSmtpHost" placeholder="(비우면 IMAP Host 사용)">
            </div>
            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accSmtpPort">SMTP Port</label>
                <input class="mail-compose-input" type="number" id="accSmtpPort" value="465">
            </div>
            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accFromEmail">발신 Email</label>
                <input class="mail-compose-input" type="text" id="accFromEmail" placeholder="noreply@domain.com">
            </div>
            <div class="mail-compose-field">
                <label class="mail-compose-label" for="accFromName">발신 이름</label>
                <input class="mail-compose-input" type="text" id="accFromName" placeholder="SH Vision">
            </div>

            <div class="mail-compose-actions">
                <label class="mail-compose-check">
                    <input type="checkbox" id="accPrimary"> 기본계정
                </label>
                <div class="mail-compose-btns">
                    <button class="btn btn-outline btn-sm" id="btnAccTest"><i class="fa fa-plug"></i> 연결테스트</button>
                    <button class="btn btn-ghost btn-sm" id="btnAccReset">초기화</button>
                    <button class="btn btn-glass-primary btn-sm" id="btnAccSave"><i class="fa fa-check"></i> 저장</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 우측: 계정 목록 -->
    <div class="mail-detail-panel card" id="accListPanel">
        <div class="mail-list" id="accListBody">
            <div class="mail-detail-empty">
                <i class="fa fa-spinner fa-spin"></i>
                <p>계정 목록 불러오는 중...</p>
            </div>
        </div>
    </div>

</div>
</section>

<script>
(function () {
    'use strict';

    function loadAccList() {
        var body = document.getElementById('accListBody');
        body.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-spinner fa-spin"></i><p>불러오는 중...</p></div>';

        SHV.mail.loadAccounts().then(function (res) {
            var items = (res.data || res).items || (res.data || res).list || [];
            if (items.length === 0) {
                body.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-user-plus"></i><p>등록된 계정이 없습니다</p></div>';
                return;
            }

            var html = '';
            items.forEach(function (a) {
                html += '<div class="mail-item" data-idx="' + a.idx + '" onclick="accEdit(this)">';
                html += '<div class="mail-item-content">';
                html += '<div class="mail-item-top">';
                html += '<span class="mail-item-sender">' + escH(a.display_name || a.account_key || '') + '</span>';
                html += '<div class="mail-item-meta">';
                if (a.is_primary) html += '<span class="mail-badge mail-badge-new">기본</span>';
                html += '<span class="mail-item-date">' + (a.status || '') + '</span>';
                html += '</div></div>';
                html += '<span class="mail-item-subject">' + escH(a.host || '') + ':' + (a.port || '') + '</span>';
                html += '<span class="mail-item-preview">' + escH(a.login_id || a.from_email || '') + '</span>';
                html += '</div></div>';
            });
            body.innerHTML = html;
        }).catch(function () {
            body.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-exclamation-circle"></i><p>계정 목록을 불러올 수 없습니다</p></div>';
        });
    }

    window.accEdit = function (el) {
        var idx = el.dataset.idx;
        /* 해당 계정 정보를 폼에 채우는 로직 — API에서 상세 조회 후 */
        document.querySelectorAll('.mail-item').forEach(function (m) { m.classList.remove('active'); });
        el.classList.add('active');
        document.getElementById('accEditIdx').value = idx;
        if (SHV.toast) SHV.toast.info('계정 #' + idx + ' 선택됨. 수정 후 저장하세요.');
    };

    function collectForm() {
        return {
            idx:         document.getElementById('accEditIdx').value || '0',
            account_key: document.getElementById('accKey').value,
            display_name: document.getElementById('accName').value,
            host:        document.getElementById('accHost').value,
            port:        document.getElementById('accPort').value,
            ssl:         document.getElementById('accSsl').value,
            login_id:    document.getElementById('accLoginId').value,
            password:    document.getElementById('accPassword').value,
            smtp_host:   document.getElementById('accSmtpHost').value,
            smtp_port:   document.getElementById('accSmtpPort').value,
            from_email:  document.getElementById('accFromEmail').value,
            from_name:   document.getElementById('accFromName').value,
            is_primary:  document.getElementById('accPrimary').checked ? '1' : '0'
        };
    }

    function resetForm() {
        document.getElementById('accEditIdx').value = '0';
        ['accKey','accName','accHost','accLoginId','accPassword','accSmtpHost','accFromEmail','accFromName'].forEach(function (id) {
            document.getElementById(id).value = '';
        });
        document.getElementById('accPort').value = '993';
        document.getElementById('accSmtpPort').value = '465';
        document.getElementById('accSsl').value = '1';
        document.getElementById('accPrimary').checked = false;
    }

    document.getElementById('btnAccSave').addEventListener('click', function () {
        var p = collectForm();
        if (!p.host || !p.login_id) { SHV.toast.warn('IMAP Host와 로그인 ID는 필수입니다.'); return; }
        SHV.mail.saveAccount(p).then(function () {
            SHV.toast.success('계정이 저장되었습니다.');
            resetForm();
            loadAccList();
        }).catch(function (e) { SHV.toast.error('저장 실패: ' + (e.message || '')); });
    });

    document.getElementById('btnAccTest').addEventListener('click', function () {
        var p = collectForm();
        SHV.toast.info('연결 테스트 중...');
        SHV.mail.testAccount(p).then(function (res) {
            SHV.toast.success('연결 성공!');
        }).catch(function (e) { SHV.toast.error('연결 실패: ' + (e.message || '')); });
    });

    document.getElementById('btnAccReset').addEventListener('click', resetForm);
    document.getElementById('btnAccReload').addEventListener('click', loadAccList);
    document.getElementById('btnAccNew').addEventListener('click', resetForm);

    function escH(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    loadAccList();
})();
</script>
