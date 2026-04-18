<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/NotificationService.php';

try {
    $auth = new AuthService();
    $context = $auth->currentContext();

    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $roleLevel = (int)($context['role_level'] ?? 0);
    $todo = (string)($_POST['todo'] ?? $_GET['todo'] ?? 'summary');

    $serviceCode = trim((string)($_POST['service_code'] ?? $_GET['service_code'] ?? ''));
    if ($serviceCode === '' || $roleLevel < 1 || $roleLevel > 5) {
        $serviceCode = (string)($context['service_code'] ?? 'shvq');
    }

    /* tenant_id는 반드시 세션에서만 가져온다 (사용자 입력 신뢰 금지) */
    $tenantId = (int)($context['tenant_id'] ?? 0);

    $service = new NotificationService(DbConnection::get());

    if ($todo === 'summary') {
        $summary = $service->summary($serviceCode, $tenantId);
        ApiResponse::success($summary, 'OK', 'notification summary loaded');
        exit;
    }

    if ($todo === 'queue_list') {
        $status = (string)($_POST['status'] ?? $_GET['status'] ?? '');
        $channel = (string)($_POST['channel'] ?? $_GET['channel'] ?? '');
        $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 20);
        $result = $service->queueList($serviceCode, $tenantId, $status, $channel, $page, $limit);
        ApiResponse::success($result, 'OK', 'notification queue loaded');
        exit;
    }

    if ($todo === 'delivery_list') {
        $channel = (string)($_POST['channel'] ?? $_GET['channel'] ?? '');
        $resultCode = (string)($_POST['result_code'] ?? $_GET['result_code'] ?? '');
        $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 20);
        $result = $service->deliveryList($serviceCode, $tenantId, $channel, $resultCode, $page, $limit);
        ApiResponse::success($result, 'OK', 'notification delivery logs loaded');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', 'unsupported todo', 400, ['todo' => $todo]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_INPUT', $e->getMessage(), 422);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}
