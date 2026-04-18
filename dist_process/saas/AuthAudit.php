<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';

try {
    $security = require __DIR__ . '/../../config/security.php';
    $auth = new AuthService();
    $context = $auth->currentContext();

    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $roleLevel = (int)($context['role_level'] ?? 0);
    $minRoleLevel = (int)($security['auth_audit']['min_role_level'] ?? 4);
    if ($roleLevel < $minRoleLevel) {
        ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
            'required' => $minRoleLevel,
            'current' => $roleLevel,
        ]);
        exit;
    }

    $todo = (string)($_POST['todo'] ?? $_GET['todo'] ?? 'list');
    if ($todo !== 'list') {
        ApiResponse::error('UNSUPPORTED_TODO', 'unsupported todo', 400, ['todo' => $todo]);
        exit;
    }

    $service = new AuthAuditService(DbConnection::get(), $security);
    $result = $service->list([
        'page' => $_POST['page'] ?? $_GET['page'] ?? 1,
        'limit' => $_POST['limit'] ?? $_GET['limit'] ?? 20,
        'login_id' => $_POST['login_id'] ?? $_GET['login_id'] ?? '',
        'action_key' => $_POST['action_key'] ?? $_GET['action_key'] ?? '',
        'result_code' => $_POST['result_code'] ?? $_GET['result_code'] ?? '',
        'user_pk' => $_POST['user_pk'] ?? $_GET['user_pk'] ?? 0,
        'from_at' => $_POST['from_at'] ?? $_GET['from_at'] ?? '',
        'to_at' => $_POST['to_at'] ?? $_GET['to_at'] ?? '',
    ]);

    ApiResponse::success($result, 'OK', 'audit logs loaded');
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}
