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
$mailApi = 'dist_process/saas/Mail.php';
?>
<link rel="stylesheet" href="css/v2/pages/mail.css?v=20260413d">
<section
    data-page="saas-mail-compose"
    data-mail-api="<?= htmlspecialchars($mailApi, ENT_QUOTES, 'UTF-8') ?>"
    data-service-code="<?= htmlspecialchars($serviceCode, ENT_QUOTES, 'UTF-8') ?>"
    data-tenant-id="<?= $tenantId ?>"
    data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
    class="mail-page"
>
    <header class="mail-header">
        <div class="mail-header-left">
            <h2>메일 작성</h2>
            <p>새 메일 작성</p>
        </div>
        <div class="mail-header-right">
            <button class="btn btn-ghost btn-sm" data-action="draft-save"><i class="fa fa-floppy-o"></i> 임시저장</button>
            <button class="btn btn-glass-primary btn-sm" data-action="mail-send"><i class="fa fa-paper-plane"></i> 발송</button>
        </div>
    </header>

    <div class="card mail-compose-wrap">
        <form data-mail-compose-form enctype="multipart/form-data">
            <input type="hidden" name="todo" value="mail_send">
            <input type="hidden" name="reply_mode" value="" data-mail-reply-mode>
            <input type="hidden" name="source_uid" value="0" data-mail-source-uid>
            <input type="hidden" name="source_folder" value="INBOX" data-mail-source-folder>

            <div class="mail-compose-field">
                <label class="mail-compose-label" for="mail-account-idx">계정</label>
                <select class="mail-compose-input" id="mail-account-idx" name="account_idx" data-mail-account-select>
                    <option value="">계정을 선택하세요</option>
                </select>
            </div>

            <div class="mail-compose-field">
                <label class="mail-compose-label" for="mail-to">받는사람</label>
                <input class="mail-compose-input" type="text" id="mail-to" name="to" data-mail-to placeholder="example@domain.com">
            </div>

            <div class="mail-compose-field">
                <label class="mail-compose-label" for="mail-cc">참조</label>
                <input class="mail-compose-input" type="text" id="mail-cc" name="cc" data-mail-cc placeholder="cc@domain.com">
            </div>

            <div class="mail-compose-field">
                <label class="mail-compose-label" for="mail-bcc">숨은참조</label>
                <input class="mail-compose-input" type="text" id="mail-bcc" name="bcc" data-mail-bcc placeholder="bcc@domain.com">
            </div>

            <div class="mail-compose-field">
                <label class="mail-compose-label" for="mail-subject">제목</label>
                <input class="mail-compose-input" type="text" id="mail-subject" name="subject" data-mail-subject placeholder="제목을 입력하세요">
            </div>

            <div class="mail-compose-field mail-compose-field-body">
                <textarea class="mail-compose-body" id="mail-body" name="body_html" data-mail-body placeholder="본문을 입력하세요"></textarea>
            </div>

            <div class="mail-compose-field mail-compose-field-attach">
                <label class="mail-compose-label">첨부파일</label>
                <div class="mail-attach-zone" data-mail-attach-zone>
                    <!-- 실제 파일 입력 (name 없음 — JS가 직접 FormData에 추가) -->
                    <input type="file" id="mail-attach" multiple data-mail-attach-input class="mail-attach-input-hidden">
                    <div class="mail-attach-trigger">
                        <button type="button" class="btn btn-ghost btn-sm" data-action="attach-pick">
                            <i class="fa fa-paperclip"></i> 파일 선택
                        </button>
                        <span class="mail-attach-hint" data-mail-attach-hint>또는 여기에 드래그&amp;드롭 (파일당 최대 10MB / 총 25MB)</span>
                    </div>
                    <ul class="mail-attach-list" data-mail-attach-list></ul>
                </div>
            </div>

            <div class="mail-compose-field">
                <label class="mail-compose-label" for="mail-reply-to">회신주소</label>
                <input class="mail-compose-input" type="text" id="mail-reply-to" name="reply_to" data-mail-reply-to placeholder="reply@domain.com">
            </div>

            <div class="mail-compose-actions">
                <span data-mail-compose-hint class="mail-pagination-info"></span>
                <div class="mail-compose-btns">
                    <button type="button" class="btn btn-ghost btn-sm" data-action="mail-discard">초기화</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-action="draft-save"><i class="fa fa-floppy-o"></i> 임시저장</button>
                    <button type="button" class="btn btn-glass-primary btn-sm" data-action="mail-send"><i class="fa fa-paper-plane"></i> 발송</button>
                </div>
            </div>
        </form>
    </div>
</section>
