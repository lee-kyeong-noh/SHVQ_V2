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
    data-page="saas-mail-drafts"
    data-mail-api="<?= htmlspecialchars($mailApi, ENT_QUOTES, 'UTF-8') ?>"
    data-service-code="<?= htmlspecialchars($serviceCode, ENT_QUOTES, 'UTF-8') ?>"
    data-tenant-id="<?= $tenantId ?>"
    data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
    class="mail-page"
>
    <header class="mail-header">
        <div class="mail-header-left">
            <h2>임시보관함</h2>
            <p>임시저장된 메일</p>
        </div>
        <div class="mail-header-right">
            <button class="btn btn-ghost btn-sm" data-action="draft-refresh"><i class="fa fa-refresh"></i> 새로고침</button>
            <button class="btn btn-ghost btn-sm" data-action="draft-delete-selected"><i class="fa fa-trash-o"></i> 선택삭제</button>
            <button class="btn btn-glass-primary btn-sm" data-action="draft-open-compose"><i class="fa fa-edit"></i> 새 메일</button>
        </div>
    </header>

    <div class="card mail-list-wrap-full">
        <div class="mail-toolbar">
            <select data-draft-account-filter class="mail-compose-input">
                <option value="">전체 계정</option>
            </select>
            <span data-draft-summary class="mail-pagination-info">총 0건</span>
        </div>

        <div class="mail-list">
            <table class="tbl tbl-hover">
                <thead>
                    <tr>
                        <th class="tbl-check-col"><input type="checkbox" data-action="draft-check-all"></th>
                        <th class="tbl-w-60">ID</th>
                        <th class="tbl-w-120">계정</th>
                        <th>제목</th>
                        <th class="tbl-w-200">받는사람</th>
                        <th class="tbl-w-140">수정일</th>
                        <th class="tbl-w-140">작업</th>
                    </tr>
                </thead>
                <tbody data-draft-list-body>
                    <tr><td colspan="7" class="tbl-empty">임시저장 목록 로딩 대기</td></tr>
                </tbody>
            </table>
        </div>

        <div class="mail-pagination">
            <span class="mail-pagination-info">임시저장은 사용자 기준으로 조회됩니다.</span>
            <div class="mail-pagination-nav">
                <button class="btn btn-ghost btn-sm" data-action="draft-prev-page"><i class="fa fa-angle-left"></i></button>
                <span data-draft-page-text class="mail-pagination-info">1 / 1</span>
                <button class="btn btn-ghost btn-sm" data-action="draft-next-page"><i class="fa fa-angle-right"></i></button>
            </div>
        </div>
    </div>
</section>
