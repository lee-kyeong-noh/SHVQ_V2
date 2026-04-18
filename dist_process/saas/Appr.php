<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/ApprovalService.php';

try {
    $security = require __DIR__ . '/../../config/security.php';

    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $service = new ApprovalService(DbConnection::get(), $security);

    $todo = trim((string)($_POST['todo'] ?? $_GET['todo'] ?? 'doc_list'));
    if ($todo === '') {
        $todo = 'doc_list';
    }

    $scope = $service->resolveScope(
        $context,
        (string)($_POST['service_code'] ?? $_GET['service_code'] ?? ''),
        (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0)
    );

    $requiredTables = $service->requiredTables();
    $missingTables = $service->missingTables($requiredTables);
    if ($missingTables !== []) {
        ApiResponse::error('APPROVAL_SCHEMA_NOT_READY', 'approval tables are not ready', 503, [
            'missing_tables' => $missingTables,
            'required_tables' => $requiredTables,
        ]);
        exit;
    }

    $writeTodos = [
        'doc_save',
        'doc_submit',
        'doc_recall',
        'line_approve',
        'line_reject',
        'preset_save',
    ];

    if (in_array($todo, $writeTodos, true)) {
        $session = new SessionManager($security);
        $csrf = new CsrfService($session, $security);
        if (!$csrf->validateFromRequest()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', 'Invalid CSRF token', 403);
            exit;
        }
    }

    $userPk = (int)($context['user_pk'] ?? 0);
    $roleLevel = (int)($context['role_level'] ?? 0);

    if ($todo === 'doc_list') {
        $rows = $service->docList($scope, $userPk, $roleLevel, [
            'tab' => (string)($_GET['tab'] ?? $_POST['tab'] ?? ''),
            'status' => (string)($_GET['status'] ?? $_POST['status'] ?? ''),
            'doc_type' => (string)($_GET['doc_type'] ?? $_POST['doc_type'] ?? ''),
            'search' => (string)($_GET['search'] ?? $_POST['search'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 200),
        ]);

        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'approval doc list loaded');
        exit;
    }

    if ($todo === 'doc_detail') {
        $docId = (int)($_GET['doc_id'] ?? $_GET['idx'] ?? $_POST['doc_id'] ?? $_POST['idx'] ?? 0);
        if ($docId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'doc_id is required', 422);
            exit;
        }

        $doc = $service->docDetail($scope, $docId, $userPk, $roleLevel);
        if ($doc === null) {
            ApiResponse::error('APPROVAL_DOC_NOT_FOUND', 'approval doc not found', 404, ['doc_id' => $docId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'item' => $doc,
        ], 'OK', 'approval doc loaded');
        exit;
    }

    if ($todo === 'doc_save') {
        $saved = $service->docSave($scope, $userPk, $roleLevel, $_POST + $_GET);

        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'approval doc saved');
        exit;
    }

    if ($todo === 'doc_submit') {
        $docId = (int)($_POST['doc_id'] ?? $_POST['idx'] ?? $_GET['doc_id'] ?? $_GET['idx'] ?? 0);
        if ($docId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'doc_id is required', 422);
            exit;
        }

        $saved = $service->docSubmit($scope, $docId, $userPk, $roleLevel);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'approval doc submitted');
        exit;
    }

    if ($todo === 'doc_recall') {
        $docId = (int)($_POST['doc_id'] ?? $_POST['idx'] ?? $_GET['doc_id'] ?? $_GET['idx'] ?? 0);
        if ($docId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'doc_id is required', 422);
            exit;
        }

        $saved = $service->docRecall($scope, $docId, $userPk, $roleLevel);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'approval doc recalled');
        exit;
    }

    if ($todo === 'line_approve' || $todo === 'line_reject') {
        $docId = (int)($_POST['doc_id'] ?? $_POST['idx'] ?? $_GET['doc_id'] ?? $_GET['idx'] ?? 0);
        if ($docId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'doc_id is required', 422);
            exit;
        }

        $comment = trim((string)($_POST['comment'] ?? $_GET['comment'] ?? ''));
        if ($todo === 'line_reject' && $comment === '') {
            ApiResponse::error('INVALID_INPUT', 'comment is required for reject', 422);
            exit;
        }

        $saved = $todo === 'line_approve'
            ? $service->lineApprove($scope, $docId, $userPk, $roleLevel, $comment)
            : $service->lineReject($scope, $docId, $userPk, $roleLevel, $comment);

        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'approval line action applied');
        exit;
    }

    if ($todo === 'preset_list') {
        $rows = $service->presetList($scope, $userPk, [
            'search' => (string)($_GET['search'] ?? $_POST['search'] ?? ''),
            'include_shared' => (string)($_GET['include_shared'] ?? $_POST['include_shared'] ?? '1'),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 100),
        ]);

        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'approval preset list loaded');
        exit;
    }

    if ($todo === 'preset_save') {
        $saved = $service->presetSave($scope, $userPk, $roleLevel, $_POST + $_GET);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'approval preset saved');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', 'unsupported todo', 400, ['todo' => $todo]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_INPUT', $e->getMessage(), 422);
} catch (RuntimeException $e) {
    $message = $e->getMessage();

    if (str_contains($message, 'forbidden') || str_contains($message, 'insufficient role')) {
        ApiResponse::error('FORBIDDEN', $message, 403);
        exit;
    }

    if (str_contains($message, 'not found')) {
        ApiResponse::error('NOT_FOUND', $message, 404);
        exit;
    }

    ApiResponse::error('CONFLICT', $message, 409);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}

