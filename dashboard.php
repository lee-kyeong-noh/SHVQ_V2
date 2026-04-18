<?php
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';

session_name(shvEnv('SESSION_NAME', 'SHVQSESSID'));
session_set_cookie_params([
    'lifetime' => shvEnvInt('SESSION_LIFETIME', 7200),
    'path' => '/',
    'domain' => '',
    'secure' => shvEnvBool('SESSION_SECURE_COOKIE', true),
    'httponly' => shvEnvBool('SESSION_HTTP_ONLY', true),
    'samesite' => shvEnv('SESSION_SAME_SITE', 'Lax'),
]);
session_start();

if (empty($_SESSION['auth']['user_pk'])) {
    http_response_code(401);
    echo '<div>인증이 필요합니다. 다시 로그인해주세요.</div>';
    exit;
}

$system = trim((string)($_GET['system'] ?? 'fms'));
if ($system === '') {
    $system = 'fms';
}

echo '<section data-page="dashboard" class="p-4">'
    . '<h2 class="m-0 mb-2 text-lg font-bold">대시보드 준비중</h2>'
    . '<p class="m-0 text-2">system=' . htmlspecialchars($system, ENT_QUOTES, 'UTF-8')
    . ' 화면 콘텐츠는 Phase 1에서 순차 반영됩니다.</p>'
    . '<p class="mt-2 text-3 text-xs">API: /dist_process/saas/Dashboard.php?todo=summary</p>'
    . '</section>';

