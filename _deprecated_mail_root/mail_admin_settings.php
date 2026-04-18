<?php
declare(strict_types=1);
require_once __DIR__ . '/config/env.php';
session_name(shvEnv('SESSION_NAME', 'SHVQSESSID'));
session_set_cookie_params(['lifetime'=>shvEnvInt('SESSION_LIFETIME',7200),'path'=>'/','domain'=>'','secure'=>shvEnvBool('SESSION_SECURE_COOKIE',true),'httponly'=>shvEnvBool('SESSION_HTTP_ONLY',true),'samesite'=>shvEnv('SESSION_SAME_SITE','Lax')]);
session_start();
if (empty($_SESSION['auth']['user_pk'])) { http_response_code(401); echo '<div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div>'; exit; }
$roleLevel = (int)($_SESSION['auth']['role_level'] ?? 0);
if ($roleLevel < 4) { echo '<div class="empty-state"><p class="empty-message">관리자 권한이 필요합니다.</p></div>'; exit; }
?>
<link rel="stylesheet" href="css/v2/pages/mail.css?v=20260413c">
<section data-page="mail_admin_settings" data-title="Mail관리자설정" class="mail-page">

<div class="mail-header">
    <div class="mail-header-left">
        <h2>Mail 관리자설정</h2>
        <p>전체 메일 시스템 관리 (관리자 전용)</p>
    </div>
</div>

<div class="card mail-settings-wrap">
    <div class="mail-detail-empty">
        <i class="fa fa-cogs"></i>
        <p>관리자 설정은 준비 중입니다</p>
    </div>
</div>

</section>
