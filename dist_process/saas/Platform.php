<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/ShadowWriteQueueService.php';
require_once __DIR__ . '/../../dist_library/saas/TenantService.php';
require_once __DIR__ . '/../../dist_library/saas/ShadowReplayService.php';
require_once __DIR__ . '/../../dist_library/saas/Wave1ApiMatrix.php';

try {
    $security = require __DIR__ . '/../../config/security.php';
    if (!(bool)($security['shadow_write']['enabled'] ?? true)) {
        ApiResponse::error('SHADOW_WRITE_DISABLED', 'shadow write is disabled', 503);
        exit;
    }
    $auth = new AuthService();
    $context = $auth->currentContext();

    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $roleLevel = (int)($context['role_level'] ?? 0);
    $maxRoleLevel = (int)($security['shadow_write']['max_authority_idx'] ?? ($security['shadow_write']['min_role_level'] ?? 4));
    if ($roleLevel < 1 || $roleLevel > $maxRoleLevel) {
        ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
            'required_min' => 1,
            'required_max' => $maxRoleLevel,
            'current' => $roleLevel,
        ]);
        exit;
    }

    $todo = (string)($_POST['todo'] ?? $_GET['todo'] ?? 'shadow_queue_stats');
    $db = DbConnection::get();
    $service = new ShadowWriteQueueService($db, $security);
    $tenantService = new TenantService($db, $security);
    $replayService = new ShadowReplayService($db, $service, $tenantService);

    $scopeServiceCode = trim((string)($_POST['service_code'] ?? $_GET['service_code'] ?? ''));
    if ($scopeServiceCode === '') {
        $scopeServiceCode = (string)($context['service_code'] ?? 'shvq');
    }

    /* tenant_id는 반드시 세션에서만 가져온다 (사용자 입력 신뢰 금지) */
    $scopeTenantId = (int)($context['tenant_id'] ?? 0);

    if ($todo === 'shadow_queue_stats') {
        $stats = $service->stats([
            'service_code' => $scopeServiceCode,
            'tenant_id' => $scopeTenantId,
        ]);
        ApiResponse::success([
            'scope' => [
                'service_code' => $scopeServiceCode,
                'tenant_id' => $scopeTenantId,
            ],
            'stats' => $stats,
        ], 'OK', 'shadow queue stats loaded');
        exit;
    }

    if ($todo === 'shadow_queue_list') {
        $list = $service->list([
            'page' => $_POST['page'] ?? $_GET['page'] ?? 1,
            'limit' => $_POST['limit'] ?? $_GET['limit'] ?? 20,
            'status' => $_POST['status'] ?? $_GET['status'] ?? '',
            'service_code' => $scopeServiceCode,
            'tenant_id' => $scopeTenantId,
        ]);
        ApiResponse::success($list, 'OK', 'shadow queue items loaded');
        exit;
    }

    if ($todo === 'shadow_queue_get') {
        $idx = (int)($_POST['idx'] ?? $_GET['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'idx is required', 422);
            exit;
        }

        $item = $service->getByIdx($idx);
        if ($item === null) {
            ApiResponse::error('QUEUE_ITEM_NOT_FOUND', 'queue item not found', 404, ['idx' => $idx]);
            exit;
        }

        ApiResponse::success($item, 'OK', 'shadow queue item loaded');
        exit;
    }

    if ($todo === 'shadow_wave1_matrix') {
        ApiResponse::success([
            'summary' => Wave1ApiMatrix::summary(),
            'files' => Wave1ApiMatrix::fileSummary(),
            'tier1_todos' => Wave1ApiMatrix::tier1Todos(),
        ], 'OK', 'wave1 matrix loaded');
        exit;
    }

    if (in_array($todo, ['shadow_queue_requeue', 'shadow_queue_resolve', 'shadow_queue_insert_test', 'shadow_queue_replay_one', 'shadow_queue_replay_batch', 'shadow_queue_monitor'], true)) {
        $session = new SessionManager($security);
        $csrf = new CsrfService($session, $security);
        if (!$csrf->validateFromRequest()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', 'Invalid CSRF token', 403);
            exit;
        }
    }

    if ($todo === 'shadow_queue_requeue') {
        $idx = (int)($_POST['idx'] ?? $_GET['idx'] ?? 0);
        $note = trim((string)($_POST['note'] ?? $_GET['note'] ?? ''));
        if ($idx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'idx is required', 422);
            exit;
        }

        $ok = $service->requeueByOperator($idx, $note);
        if (!$ok) {
            ApiResponse::error('QUEUE_ITEM_NOT_FOUND', 'queue item not found', 404, ['idx' => $idx]);
            exit;
        }

        ApiResponse::success(['idx' => $idx], 'OK', 'queue item requeued');
        exit;
    }

    if ($todo === 'shadow_queue_resolve') {
        $idx = (int)($_POST['idx'] ?? $_GET['idx'] ?? 0);
        $note = trim((string)($_POST['note'] ?? $_GET['note'] ?? ''));
        if ($idx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'idx is required', 422);
            exit;
        }

        $ok = $service->resolveByOperator($idx, $note);
        if (!$ok) {
            ApiResponse::error('QUEUE_ITEM_NOT_FOUND', 'queue item not found', 404, ['idx' => $idx]);
            exit;
        }

        ApiResponse::success(['idx' => $idx], 'OK', 'queue item resolved');
        exit;
    }

    if ($todo === 'shadow_queue_insert_test') {
        if ($roleLevel < 1 || $roleLevel > 5) {
            ApiResponse::error('FORBIDDEN', 'system manager role required', 403);
            exit;
        }

        $job = [
            'service_code' => $scopeServiceCode,
            'tenant_id' => $scopeTenantId,
            'request_id' => 'test-' . bin2hex(random_bytes(6)),
            'api' => 'wave1.test',
            'todo' => 'shadow_queue_insert_test',
            'error_message' => 'manual test item',
            'meta' => [
                'inserted_by_user_pk' => (int)($context['user_pk'] ?? 0),
                'inserted_at' => date('Y-m-d H:i:s'),
            ],
        ];
        $newIdx = $service->enqueueFailure($job);
        ApiResponse::success(['idx' => $newIdx], 'OK', 'test queue item inserted');
        exit;
    }

    if ($todo === 'shadow_queue_monitor') {
        if ($roleLevel < 1 || $roleLevel > 5) {
            ApiResponse::error('FORBIDDEN', 'system manager role required', 403);
            exit;
        }

        $staleMinutes = (int)($_POST['stale_minutes'] ?? $_GET['stale_minutes'] ?? ($security['shadow_write']['monitor_stale_retrying_minutes'] ?? 20));
        $olderThanMinutes = (int)($_POST['older_than_minutes'] ?? $_GET['older_than_minutes'] ?? ($security['shadow_write']['monitor_backlog_older_minutes'] ?? 10));
        $threshold = (int)($_POST['threshold'] ?? $_GET['threshold'] ?? ($security['shadow_write']['monitor_backlog_threshold'] ?? 100));

        $recovered = $service->recoverStaleRetrying($staleMinutes);
        $stats = $service->stats([
            'service_code' => $scopeServiceCode,
            'tenant_id' => $scopeTenantId,
        ]);
        $openOlderThan = $service->openCountOlderThan($olderThanMinutes);
        $alert = $openOlderThan >= max(1, $threshold);

        ApiResponse::success([
            'scope' => [
                'service_code' => $scopeServiceCode,
                'tenant_id' => $scopeTenantId,
            ],
            'recovered_stale_retrying' => $recovered,
            'open_older_than_minutes' => $olderThanMinutes,
            'open_older_than_count' => $openOlderThan,
            'threshold' => max(1, $threshold),
            'alert' => $alert,
            'stats' => $stats,
        ], 'OK', 'shadow queue monitor executed');
        exit;
    }

    if ($todo === 'shadow_queue_replay_one') {
        if ($roleLevel < 1 || $roleLevel > 5) {
            ApiResponse::error('FORBIDDEN', 'system manager role required', 403);
            exit;
        }

        $idx = (int)($_POST['idx'] ?? $_GET['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'idx is required', 422);
            exit;
        }

        $result = $replayService->replayOneByIdx($idx, (int)($context['user_pk'] ?? 0));
        if (!(bool)($result['ok'] ?? false)) {
            $statusCode = (($result['code'] ?? '') === 'QUEUE_ITEM_NOT_FOUND_OR_NOT_REPLAYABLE') ? 404 : 409;
            ApiResponse::error('SHADOW_REPLAY_FAILED', (string)($result['error'] ?? 'shadow replay failed'), $statusCode, $result);
            exit;
        }

        ApiResponse::success($result, 'OK', 'shadow queue item replayed');
        exit;
    }

    if ($todo === 'shadow_queue_replay_batch') {
        if ($roleLevel < 1 || $roleLevel > 5) {
            ApiResponse::error('FORBIDDEN', 'system manager role required', 403);
            exit;
        }

        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 20);
        $result = $replayService->replayBatch($limit, [
            'service_code' => $scopeServiceCode,
            'tenant_id' => $scopeTenantId,
        ], (int)($context['user_pk'] ?? 0));

        ApiResponse::success($result, 'OK', 'shadow queue batch replay done');
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
