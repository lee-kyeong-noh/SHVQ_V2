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
if ($roleLevel < 1 || $roleLevel > 3) {
    echo '<div class="empty-state"><p class="empty-message">관리자 권한이 필요합니다.</p></div>';
    exit;
}

$csrfInfo = $auth->csrfToken();
$csrfToken = (string)($csrfInfo['csrf_token'] ?? '');
$mailApi = 'dist_process/saas/Mail.php';
?>
<link rel="stylesheet" href="css/v2/pages/mail.css?v=20260413e">
<section
    data-page="saas-mail-settings"
    data-mail-api="<?= htmlspecialchars($mailApi, ENT_QUOTES, 'UTF-8') ?>"
    data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
    class="mail-page"
>
    <header class="mail-header">
        <div class="mail-header-left">
            <h2>Mail 관리자설정</h2>
            <p>전체 메일 시스템 관리 (관리자 전용)</p>
        </div>
    </header>

    <div class="card mail-settings-wrap">

        <!-- 탭 네비 -->
        <nav class="mail-settings-tabs" data-admin-settings-tabs>
            <button class="mail-settings-tab is-active" data-tab="policy"><i class="fa fa-paper-plane"></i> 발송 정책</button>
            <button class="mail-settings-tab" data-tab="system"><i class="fa fa-bar-chart"></i> 시스템 현황</button>
        </nav>

        <!-- ── 발송 정책 탭 ── -->
        <div class="mail-settings-section" data-settings-tab="policy">
            <div class="mail-settings-group">
                <h3 class="mail-settings-group-title">첨부파일 용량 제한</h3>
                <div class="mail-settings-row">
                    <label class="mail-settings-label" for="admin-max-per-file-mb">파일당 최대 용량 (MB)</label>
                    <input type="number" class="mail-settings-input" id="admin-max-per-file-mb"
                        data-admin-max-per-file-mb min="1" max="100" value="10">
                    <span class="mail-settings-hint">단일 첨부파일 허용 최대 크기</span>
                </div>
                <div class="mail-settings-row">
                    <label class="mail-settings-label" for="admin-max-total-mb">총 첨부 최대 용량 (MB)</label>
                    <input type="number" class="mail-settings-input" id="admin-max-total-mb"
                        data-admin-max-total-mb min="1" max="500" value="25">
                    <span class="mail-settings-hint">메일 1통당 전체 첨부 합계 허용량</span>
                </div>
            </div>

            <div class="mail-settings-group">
                <h3 class="mail-settings-group-title">허용 확장자</h3>
                <div class="mail-settings-row">
                    <label class="mail-settings-label" for="admin-allowed-exts">허용 확장자 목록</label>
                    <input type="text" class="mail-settings-input mail-settings-input--wide" id="admin-allowed-exts"
                        data-admin-allowed-exts
                        value="jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,txt,csv,hwp">
                    <span class="mail-settings-hint">콤마(,)로 구분 · 대소문자 무시</span>
                </div>
            </div>

            <div class="mail-settings-actions">
                <button type="button" class="btn btn-ghost btn-sm" data-action="admin-settings-reload">
                    <i class="fa fa-refresh"></i> 현재값 불러오기
                </button>
                <button type="button" class="btn btn-glass-primary btn-sm" data-action="admin-settings-save">
                    <i class="fa fa-save"></i> 저장
                </button>
            </div>
        </div>

        <!-- ── 시스템 현황 탭 ── -->
        <div class="mail-settings-section mail-settings-section--hidden" data-settings-tab="system">
            <div class="mail-settings-group">
                <h3 class="mail-settings-group-title">시스템 현황</h3>
                <div class="mail-detail-empty">
                    <i class="fa fa-bar-chart"></i>
                    <p>시스템 현황 통계는 준비 중입니다.</p>
                </div>
            </div>
        </div>

    </div>
</section>
