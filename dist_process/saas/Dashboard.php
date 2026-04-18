<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/DashboardService.php';

try {
    $security = require __DIR__ . '/../../config/security.php';

    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $todo = (string)($_POST['todo'] ?? $_GET['todo'] ?? 'summary');
    $roleLevel = (int)($context['role_level'] ?? 0);

    $serviceCode = trim((string)($_POST['service_code'] ?? $_GET['service_code'] ?? ''));
    if ($serviceCode === '' || $roleLevel < 1 || $roleLevel > 5) {
        $serviceCode = (string)($context['service_code'] ?? 'shvq');
    }

    /* tenant_id는 반드시 세션에서만 가져온다 (사용자 입력 신뢰 금지) */
    $tenantId = (int)($context['tenant_id'] ?? 0);

    $service = new DashboardService(DbConnection::get());

    if ($todo === 'summary') {
        $summary = $service->summary($serviceCode, $tenantId);
        ApiResponse::success($summary, 'OK', 'dashboard summary loaded');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', 'unsupported todo', 400, ['todo' => $todo]);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}

