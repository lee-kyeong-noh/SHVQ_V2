<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/TenantService.php';

try {
    $security = require __DIR__ . '/../../config/security.php';
    $auth = new AuthService();
    $context = $auth->currentContext();

    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $todo = (string)($_POST['todo'] ?? $_GET['todo'] ?? 'list_tenants');
    $roleLevel = (int)($context['role_level'] ?? 0);
    if ($roleLevel < 1 || $roleLevel > 5) {
        ApiResponse::error('FORBIDDEN', 'system manager role required', 403);
        exit;
    }

    $serviceCode = trim((string)($_POST['service_code'] ?? $_GET['service_code'] ?? ''));
    if ($serviceCode === '') {
        $serviceCode = (string)($context['service_code'] ?? 'shvq');
    }

    $writeTodos = ['create_tenant', 'update_tenant_status', 'assign_tenant_user', 'init_default'];
    if (in_array($todo, $writeTodos, true)) {
        $session = new SessionManager($security);
        $csrf = new CsrfService($session, $security);
        if (!$csrf->validateFromRequest()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', 'Invalid CSRF token', 403);
            exit;
        }
    }

    $tenantService = new TenantService(DbConnection::get(), $security);
    if (!$tenantService->schemaReady()) {
        ApiResponse::error('TENANT_SCHEMA_NOT_READY', 'tenant tables are not migrated yet', 503, [
            'missing_tables' => $tenantService->missingTables(),
        ]);
        exit;
    }

    if (in_array($todo, ['list_tenants', 'list'], true)) {
        $includeInactive = in_array(
            (string)($_POST['include_inactive'] ?? $_GET['include_inactive'] ?? '1'),
            ['1', 'true', 'on', 'yes'],
            true
        );
        $tenants = $tenantService->listTenants($serviceCode, $includeInactive);

        ApiResponse::success([
            'service_code' => $serviceCode,
            'items' => $tenants,
        ], 'OK', 'tenant list loaded');
        exit;
    }

    if (in_array($todo, ['get_tenant', 'get'], true)) {
        $tenantId = (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
        $tenantCode = trim((string)($_POST['tenant_code'] ?? $_GET['tenant_code'] ?? ''));

        if ($tenantId <= 0 && $tenantCode === '') {
            ApiResponse::error('INVALID_INPUT', 'tenant_id or tenant_code is required', 422);
            exit;
        }

        $tenant = $tenantId > 0
            ? $tenantService->getTenant($tenantId)
            : $tenantService->getTenantByCode($serviceCode, $tenantCode);

        if ($tenant === null) {
            ApiResponse::error('TENANT_NOT_FOUND', 'tenant not found', 404, [
                'tenant_id' => $tenantId,
                'tenant_code' => $tenantCode,
            ]);
            exit;
        }

        ApiResponse::success([
            'service_code' => $serviceCode,
            'item' => $tenant,
        ], 'OK', 'tenant loaded');
        exit;
    }

    if (in_array($todo, ['tenant_users', 'users'], true)) {
        $tenantId = (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'tenant_id is required', 422);
            exit;
        }

        $items = $tenantService->listTenantUsers($tenantId);
        ApiResponse::success([
            'tenant_id' => $tenantId,
            'items' => $items,
        ], 'OK', 'tenant users loaded');
        exit;
    }

    if ($todo === 'create_tenant') {
        $tenantCode = trim((string)($_POST['tenant_code'] ?? $_GET['tenant_code'] ?? ''));
        $tenantName = trim((string)($_POST['tenant_name'] ?? $_GET['tenant_name'] ?? ''));
        $planCode = trim((string)($_POST['plan_code'] ?? $_GET['plan_code'] ?? 'basic'));
        $isDefault = in_array((string)($_POST['is_default'] ?? $_GET['is_default'] ?? '0'), ['1', 'true', 'on', 'yes'], true);

        if ($tenantCode === '' || $tenantName === '') {
            ApiResponse::error('INVALID_INPUT', 'tenant_code and tenant_name are required', 422);
            exit;
        }

        if (mb_strlen($tenantCode) > 60 || mb_strlen($tenantName) > 120 || mb_strlen($planCode) > 30) {
            ApiResponse::error('INVALID_INPUT_LENGTH', 'input length exceeded', 422, [
                'max_tenant_code' => 60,
                'max_tenant_name' => 120,
                'max_plan_code' => 30,
            ]);
            exit;
        }

        $created = $tenantService->createTenant(
            $serviceCode,
            $tenantCode,
            $tenantName,
            $planCode,
            $isDefault,
            (int)($context['user_pk'] ?? 0)
        );

        ApiResponse::success($created, 'OK', 'tenant created');
        exit;
    }

    if ($todo === 'update_tenant_status') {
        $tenantId = (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? $_GET['status'] ?? ''));
        if ($tenantId <= 0 || $status === '') {
            ApiResponse::error('INVALID_INPUT', 'tenant_id and status are required', 422);
            exit;
        }

        $updated = $tenantService->updateTenantStatus(
            $tenantId,
            $status,
            (int)($context['user_pk'] ?? 0)
        );
        if (!$updated) {
            ApiResponse::error('TENANT_NOT_FOUND', 'tenant not found', 404, ['tenant_id' => $tenantId]);
            exit;
        }

        ApiResponse::success(['tenant_id' => $tenantId, 'status' => strtoupper($status)], 'OK', 'tenant status updated');
        exit;
    }

    if ($todo === 'assign_tenant_user') {
        $tenantId = (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
        $userIdx = (int)($_POST['user_idx'] ?? $_GET['user_idx'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? $_GET['role_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? $_GET['status'] ?? 'ACTIVE'));

        if ($tenantId <= 0 || $userIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'tenant_id and user_idx are required', 422);
            exit;
        }

        $ok = $tenantService->assignTenantUser(
            $tenantId,
            $userIdx,
            $roleId,
            $status,
            (int)($context['user_pk'] ?? 0)
        );
        if (!$ok) {
            ApiResponse::error('TENANT_NOT_FOUND', 'tenant not found', 404, ['tenant_id' => $tenantId]);
            exit;
        }

        ApiResponse::success([
            'tenant_id' => $tenantId,
            'user_idx' => $userIdx,
            'role_id' => $roleId,
            'status' => strtoupper($status),
        ], 'OK', 'tenant user assigned');
        exit;
    }

    if ($todo === 'init_default') {
        $tenantCode = trim((string)($_POST['tenant_code'] ?? $_GET['tenant_code'] ?? 'shvision'));
        $tenantName = trim((string)($_POST['tenant_name'] ?? $_GET['tenant_name'] ?? 'SH Vision'));
        $planCode = trim((string)($_POST['plan_code'] ?? $_GET['plan_code'] ?? 'basic'));

        if (mb_strlen($tenantCode) > 60 || mb_strlen($tenantName) > 120 || mb_strlen($planCode) > 30) {
            ApiResponse::error('INVALID_INPUT_LENGTH', 'input length exceeded', 422, [
                'max_tenant_code' => 60,
                'max_tenant_name' => 120,
                'max_plan_code' => 30,
            ]);
            exit;
        }

        $result = $tenantService->initDefaultTenant(
            $serviceCode,
            $tenantCode,
            $tenantName,
            $planCode,
            (int)($context['user_pk'] ?? 0)
        );

        ApiResponse::success($result, 'OK', 'default tenant initialized');
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
