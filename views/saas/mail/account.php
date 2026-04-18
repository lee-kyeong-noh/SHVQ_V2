<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div>';
    exit;
}

$csrfInfo = $auth->csrfToken();
$csrfToken = (string)($csrfInfo['csrf_token'] ?? '');
$serviceCode = (string)($context['service_code'] ?? 'shvq');
$tenantId = (int)($context['tenant_id'] ?? 0);
$roleLevel = (int)($context['role_level'] ?? 0);
$mailApi = 'dist_process/saas/Mail.php';
?>
<link rel="stylesheet" href="css/v2/pages/mail.css?v=20260413e">
<section
    data-page="saas-mail-account"
    data-mail-api="<?= htmlspecialchars($mailApi, ENT_QUOTES, 'UTF-8') ?>"
    data-service-code="<?= htmlspecialchars($serviceCode, ENT_QUOTES, 'UTF-8') ?>"
    data-tenant-id="<?= $tenantId ?>"
    data-role-level="<?= $roleLevel ?>"
    data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
    class="mail-page"
>
    <header class="mail-header">
        <div class="mail-header-left">
            <h2>메일 계정 설정</h2>
            <p>IMAP/SMTP 계정 관리</p>
        </div>
        <div class="mail-header-right">
            <button class="btn btn-ghost btn-sm" data-action="account-refresh"><i class="fa fa-refresh"></i> 새로고침</button>
        </div>
    </header>

    <div class="mail-split">
        <!-- 좌측: 등록/수정 폼 -->
        <div class="mail-list-panel card">
            <div class="mail-compose-wrap">
                <h3 class="mail-compose-section-title">계정 등록/수정</h3>
                <input type="hidden" data-account-edit-idx value="0">

                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-account-key">계정키</label>
                    <input class="mail-compose-input" type="text" id="acc-account-key" data-acc-account-key placeholder="mail.main">
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-display-name">표시명</label>
                    <input class="mail-compose-input" type="text" id="acc-display-name" data-acc-display-name placeholder="홍길동 메일">
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-host">IMAP Host</label>
                    <input class="mail-compose-input" type="text" id="acc-host" data-acc-host placeholder="mail.example.com">
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-port">IMAP Port</label>
                    <input class="mail-compose-input" type="number" id="acc-port" data-acc-port value="993">
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-ssl">SSL</label>
                    <select class="mail-compose-input" id="acc-ssl" data-acc-ssl>
                        <option value="1">사용</option>
                        <option value="0">미사용</option>
                    </select>
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-login-id">로그인 ID</label>
                    <input class="mail-compose-input" type="text" id="acc-login-id" data-acc-login-id placeholder="user@domain.com">
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-password">비밀번호</label>
                    <input class="mail-compose-input" type="password" id="acc-password" data-acc-password placeholder="저장 시 갱신">
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-smtp-host">SMTP Host</label>
                    <input class="mail-compose-input" type="text" id="acc-smtp-host" data-acc-smtp-host placeholder="(비우면 IMAP Host)">
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-smtp-port">SMTP Port</label>
                    <input class="mail-compose-input" type="number" id="acc-smtp-port" data-acc-smtp-port value="465">
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-smtp-ssl">SMTP SSL</label>
                    <select class="mail-compose-input" id="acc-smtp-ssl" data-acc-smtp-ssl>
                        <option value="1">사용</option>
                        <option value="0">미사용</option>
                    </select>
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-from-email">발신 Email</label>
                    <input class="mail-compose-input" type="text" id="acc-from-email" data-acc-from-email placeholder="noreply@domain.com">
                </div>
                <div class="mail-compose-field">
                    <label class="mail-compose-label" for="acc-from-name">발신 이름</label>
                    <input class="mail-compose-input" type="text" id="acc-from-name" data-acc-from-name placeholder="SH Vision">
                </div>

                <div class="mail-compose-actions">
                    <label class="mail-compose-check">
                        <input type="checkbox" data-acc-is-primary> 기본계정
                    </label>
                    <div class="mail-compose-btns">
                        <button class="btn btn-outline btn-sm" data-action="account-test"><i class="fa fa-plug"></i> 연결테스트</button>
                        <button class="btn btn-ghost btn-sm" data-action="account-reset">초기화</button>
                        <button class="btn btn-glass-primary btn-sm" data-action="account-save"><i class="fa fa-check"></i> 저장</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 우측: 계정 목록 (관리자 authority_idx 1~3만 노출) -->
        <?php if ($roleLevel >= 1 && $roleLevel <= 3): ?>
        <div class="mail-detail-panel card" data-account-list-panel>
            <div class="mail-compose-wrap">
                <h3 class="mail-compose-section-title">계정 목록</h3>
                <p class="mail-pagination-info">선택 후 수정/삭제가 가능합니다.</p>
            </div>
            <div class="mail-list">
                <table class="tbl tbl-hover">
                    <thead>
                        <tr>
                            <th class="tbl-w-60">IDX</th>
                            <th class="tbl-w-120">계정키</th>
                            <th class="tbl-w-140">표시명</th>
                            <th class="tbl-w-120">IMAP Host</th>
                            <th class="tbl-w-80">상태</th>
                            <th class="tbl-w-60">기본</th>
                            <th class="tbl-w-160">작업</th>
                        </tr>
                    </thead>
                    <tbody data-account-list-body>
                        <tr><td colspan="7" class="tbl-empty">계정 목록 로딩 대기</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="mail-detail-panel card mail-account-my-info">
            <div class="mail-detail-empty">
                <i class="fa fa-user-circle-o"></i>
                <p>내 IMAP 계정을 등록하거나 수정할 수 있습니다.</p>
                <span class="mail-pagination-info">전체 계정 관리는 Mail 관리자설정에서 가능합니다.</span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
