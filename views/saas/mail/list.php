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
<link rel="stylesheet" href="css/v2/pages/mail.css?v=20260415e">
<section
    data-page="saas-mail-list"
    data-mail-api="<?= htmlspecialchars($mailApi, ENT_QUOTES, 'UTF-8') ?>"
    data-service-code="<?= htmlspecialchars($serviceCode, ENT_QUOTES, 'UTF-8') ?>"
    data-tenant-id="<?= $tenantId ?>"
    data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
    class="mail-page"
>
    <!-- ── 헤더 ── -->
    <header class="mail-header">
        <div class="mail-header-left">
            <h2>웹메일</h2>
            <p>폴더 · 목록 · 상세</p>
        </div>
        <div class="mail-header-right">
            <span class="mail-unread-count" data-mail-unread-count></span>
            <button class="btn btn-ghost btn-sm" data-action="mail-refresh-folder" title="새로고침"><i class="fa fa-refresh"></i></button>
            <button class="btn btn-ghost btn-sm" data-action="mail-mark-read" title="읽음"><i class="fa fa-envelope-open-o"></i></button>
            <button class="btn btn-ghost btn-sm" data-action="mail-spam" title="스팸"><i class="fa fa-ban"></i></button>
            <button class="btn btn-ghost btn-sm" data-action="mail-delete" title="삭제"><i class="fa fa-trash-o"></i></button>
            <button class="btn btn-glass-primary btn-sm" data-action="mail-compose-open"><i class="fa fa-edit"></i> 메일쓰기</button>
        </div>
    </header>

    <!-- ── 2단 분할 ── -->
    <div class="mail-split">

        <!-- 좌측 -->
        <div class="mail-list-panel card">
            <!-- 검색 -->
            <div class="mail-toolbar">
                <div class="shv-search">
                    <i class="fa fa-search shv-search-icon"></i>
                    <input type="text" data-mail-search-input placeholder="제목/발신자 검색"
                        oninput="this.closest('.shv-search').classList.toggle('has-value',!!this.value)">
                    <span class="shv-search-clear" onclick="var i=this.previousElementSibling;i.value='';i.closest('.shv-search').classList.remove('has-value');">&#x2715;</span>
                </div>
                <button class="btn btn-ghost btn-sm" data-action="mail-search"><i class="fa fa-search"></i></button>
                <label class="mail-compose-check">
                    <input type="checkbox" data-mail-unread-only> 미읽음만
                </label>
            </div>

            <!-- 현재 폴더 + 전체선택 -->
            <div class="mail-folder-current">
                <label class="mail-check-all-label">
                    <input type="checkbox" class="mail-check-all" data-action="mail-check-all"> 전체
                </label>
                <div data-mail-folder-list class="mail-folder-current-info"></div>
            </div>

            <!-- 메일 목록 -->
            <div class="mail-list" data-mail-list-body>
                <div class="mail-detail-empty">
                    <i class="fa fa-spinner fa-spin"></i>
                    <p>메일을 불러오는 중...</p>
                </div>
            </div>

            <!-- 페이지네이션 -->
            <div class="mail-pagination">
                <div data-mail-list-summary class="mail-pagination-info">총 0건</div>
                <div class="mail-pagination-nav">
                    <button class="btn btn-ghost btn-sm" data-action="mail-prev-page"><i class="fa fa-angle-left"></i></button>
                    <span data-mail-page-text class="mail-pagination-info">1 / 1</span>
                    <button class="btn btn-ghost btn-sm" data-action="mail-next-page"><i class="fa fa-angle-right"></i></button>
                </div>
            </div>
        </div>

        <!-- 우측: 본문 -->
        <div class="mail-detail-panel card">
            <div class="mail-detail-header">
                <button class="btn btn-ghost btn-sm mail-detail-back" data-action="mail-back"><i class="fa fa-arrow-left"></i> 목록</button>
                <div class="mail-detail-subject" data-mail-detail-subject>메일 상세</div>
                <div class="mail-detail-info">
                    <div class="mail-detail-avatar" data-mail-detail-avatar></div>
                    <div class="mail-detail-from">
                        <span data-mail-detail-meta class="mail-detail-date">수신/발신 정보</span>
                    </div>
                </div>
                <div class="mail-detail-actions">
                    <button class="btn btn-ghost btn-sm" data-action="mail-reply"><i class="fa fa-reply"></i> 답장</button>
                    <button class="btn btn-ghost btn-sm" data-action="mail-reply-all"><i class="fa fa-reply-all"></i> 전체답장</button>
                    <button class="btn btn-ghost btn-sm" data-action="mail-forward"><i class="fa fa-share"></i> 전달</button>
                    <button class="btn btn-ghost btn-sm" data-action="mail-detail-delete"><i class="fa fa-trash-o"></i> 삭제</button>
                </div>
            </div>

            <div class="mail-detail-meta-grid">
                <span>From</span>
                <span class="mail-detail-from-row">
                    <span data-mail-detail-from>-</span>
                    <button class="btn btn-ghost btn-xs mail-auto-classify-btn" data-action="mail-auto-classify" title="이 발신자 자동분류"><i class="fa fa-filter"></i> 자동분류</button>
                </span>
                <span>To</span><span data-mail-detail-to>-</span>
                <span>Cc</span><span data-mail-detail-cc>-</span>
                <span>첨부</span><span data-mail-detail-attach>-</span>
            </div>

            <article data-mail-detail-body class="mail-detail-body">
                내용을 선택하면 상세 본문이 표시됩니다.
            </article>
        </div>

    </div>
</section>
