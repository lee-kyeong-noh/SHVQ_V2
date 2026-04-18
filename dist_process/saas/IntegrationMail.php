<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/IntegrationService.php';

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

    $providerParam = trim((string)($_POST['provider'] ?? $_GET['provider'] ?? ''));
    if ($providerParam === '') {
        $providers = ['mail'];
    } else {
        $providers = array_values(array_filter(array_map(
            static fn(string $v): string => strtolower(trim($v)),
            explode(',', $providerParam)
        ), static fn(string $v): bool => $v !== '' && $v !== 'all'));
    }

    $service = new IntegrationService(DbConnection::get());

    if ($todo === 'summary') {
        $summary = $service->summary($serviceCode, $tenantId, $providers);
        ApiResponse::success($summary, 'OK', 'mail integration summary loaded');
        exit;
    }

    if ($todo === 'account_list') {
        $status = (string)($_POST['status'] ?? $_GET['status'] ?? '');
        $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 20);
        $result = $service->accountList(
            $serviceCode,
            $tenantId,
            $providers,
            $status,
            $page,
            $limit,
            (int)($context['user_pk'] ?? 0),
            $roleLevel
        );
        ApiResponse::success($result, 'OK', 'mail provider accounts loaded');
        exit;
    }

    if ($todo === 'checkpoint_list') {
        $status = (string)($_POST['status'] ?? $_GET['status'] ?? '');
        $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 20);
        $result = $service->checkpointList($serviceCode, $tenantId, $providers, $status, $page, $limit);
        ApiResponse::success($result, 'OK', 'mail sync checkpoints loaded');
        exit;
    }

    if ($todo === 'error_queue_list') {
        $status = (string)($_POST['status'] ?? $_GET['status'] ?? '');
        $jobType = (string)($_POST['job_type'] ?? $_GET['job_type'] ?? '');
        $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 20);
        $result = $service->errorQueueList($serviceCode, $tenantId, $providers, $status, $jobType, $page, $limit);
        ApiResponse::success($result, 'OK', 'mail error queue loaded');
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
