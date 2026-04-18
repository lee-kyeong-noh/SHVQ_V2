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

$roleLevel = (int)($context['role_level'] ?? 0);
$security = require __DIR__ . '/../../../config/security.php';
$minRoleLevel = (int)($security['auth_audit']['min_role_level'] ?? 4);
if ($roleLevel < $minRoleLevel) {
    http_response_code(403);
    echo '<div class="empty-state"><p class="empty-message">권한이 없습니다.</p></div>';
    exit;
}

$csrfInfo = $auth->csrfToken();
$csrfToken = (string)($csrfInfo['csrf_token'] ?? '');
$auditApi = 'dist_process/saas/AuthAudit.php';
?>
<section
    data-page="auth-audit"
    data-title="인증감사로그"
    data-audit-api="<?= htmlspecialchars($auditApi, ENT_QUOTES, 'UTF-8') ?>"
    data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
    class="mail-page"
>
    <!-- ── 헤더 ── -->
    <div class="mail-header">
        <div class="mail-header-left">
            <h2>인증 감사로그</h2>
            <p>로그인/토큰/세션 이벤트 조회</p>
        </div>
        <div class="mail-header-right">
            <button class="btn btn-ghost btn-sm" data-action="audit-reset"><i class="fa fa-undo"></i> 필터 초기화</button>
            <button class="btn btn-glass-primary btn-sm" data-action="audit-search"><i class="fa fa-search"></i> 조회</button>
        </div>
    </div>

    <!-- ── 필터 카드 ── -->
    <div class="card audit-filter-card">
        <div class="audit-filter-grid">
            <div class="audit-filter-item">
                <label class="audit-filter-label" for="auditLoginId">로그인ID</label>
                <input class="mail-compose-input" type="text" id="auditLoginId" data-audit-login-id placeholder="login_id">
            </div>
            <div class="audit-filter-item">
                <label class="audit-filter-label" for="auditAction">Action</label>
                <input class="mail-compose-input" type="text" id="auditAction" data-audit-action-key placeholder="auth.login">
            </div>
            <div class="audit-filter-item">
                <label class="audit-filter-label" for="auditResult">결과코드</label>
                <select class="mail-compose-input" id="auditResult" data-audit-result-code>
                    <option value="">전체</option>
                    <option value="OK">OK</option>
                    <option value="DENY_LOGIN">DENY_LOGIN</option>
                    <option value="DENY_CSRF">DENY_CSRF</option>
                    <option value="DENY_RATE_LIMIT">DENY_RATE_LIMIT</option>
                    <option value="DENY_TENANT">DENY_TENANT</option>
                </select>
            </div>
            <div class="audit-filter-item">
                <label class="audit-filter-label" for="auditFrom">From</label>
                <input class="mail-compose-input" type="date" id="auditFrom" data-audit-from-at>
            </div>
            <div class="audit-filter-item">
                <label class="audit-filter-label" for="auditTo">To</label>
                <input class="mail-compose-input" type="date" id="auditTo" data-audit-to-at>
            </div>
            <div class="audit-filter-item">
                <label class="audit-filter-label" for="auditLimit">페이지 크기</label>
                <select class="mail-compose-input" id="auditLimit" data-audit-limit>
                    <option value="20" selected>20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>

    <!-- ── 데이터 카드 ── -->
    <div class="card mail-list-wrap-full">
        <div class="mail-pagination">
            <div data-audit-summary class="mail-pagination-info">총 0건</div>
            <div class="mail-pagination-nav">
                <button class="btn btn-ghost btn-sm" data-action="audit-prev-page"><i class="fa fa-angle-left"></i></button>
                <span data-audit-page-text class="mail-pagination-info">1 / 1</span>
                <button class="btn btn-ghost btn-sm" data-action="audit-next-page"><i class="fa fa-angle-right"></i></button>
            </div>
        </div>

        <div class="mail-list">
            <table class="tbl tbl-hover tbl-sticky-header">
                <thead>
                    <tr>
                        <th class="tbl-w-60">IDX</th>
                        <th class="tbl-w-160">일시</th>
                        <th class="tbl-w-160">Action</th>
                        <th class="tbl-w-120">로그인ID</th>
                        <th class="tbl-w-120">결과</th>
                        <th class="tbl-w-120">IP</th>
                        <th>메시지</th>
                    </tr>
                </thead>
                <tbody data-audit-list-body>
                    <tr><td colspan="7" class="tbl-empty">감사로그 로딩 대기</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
