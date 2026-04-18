<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';

$auth = new AuthService();
if ($auth->currentContext() === []) {
    http_response_code(401);
    echo '<section data-page="PAGE_PLACEHOLDER"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}
?>
<section data-page="chat">
    <div class="page-header">
        <h2 class="page-title" data-title="채팅">채팅</h2>
        <p class="page-subtitle">팀 채팅 · 메시지</p>
    </div>
    <div class="card card-mt card-mb">
        <div class="card-body">
            <div class="empty-state">
                <p class="empty-message">채팅 기능 개발 예정입니다.</p>
            </div>
        </div>
    </div>
</section>
<script>
(function () {
    'use strict';
    SHV.pages = SHV.pages || {};
    SHV.pages['chat'] = { destroy: function () {} };
})();
</script>
