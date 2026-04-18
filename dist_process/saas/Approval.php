<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/GroupwareService.php';

try {
    $security = require __DIR__ . '/../../config/security.php';

    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $service = new GroupwareService(DbConnection::get(), $security);

    $todo = trim((string)($_POST['todo'] ?? $_GET['todo'] ?? 'approval_req'));
    if ($todo === '') {
        $todo = 'approval_req';
    }

    $scope = $service->resolveScope(
        $context,
        (string)($_POST['service_code'] ?? $_GET['service_code'] ?? ''),
        (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0)
    );

    $requiredTables = $service->requiredTablesByDomain('approval');
    $missingTables = $service->missingTables($requiredTables);
    if ($missingTables !== []) {
        ApiResponse::error('GROUPWARE_SCHEMA_NOT_READY', 'groupware approval tables are not ready', 503, [
            'missing_tables' => $missingTables,
            'required_tables' => $requiredTables,
        ]);
        exit;
    }

    $writeTodos = [
        'approval_write',
        'draft_create',
        'approval_submit',
        'approval_approve',
        'approval_reject',
        'approval_cancel',
        'approval_comment',
        'add_comment',
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

    if (in_array($todo, ['approval_req', 'list_req'], true)) {
        $rows = $service->listApprovalReq($scope, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'approval request list loaded');
        exit;
    }

    if (in_array($todo, ['approval_done', 'list_done'], true)) {
        $rows = $service->listApprovalDone($scope, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'approval done list loaded');
        exit;
    }

    if (in_array($todo, ['doc_all', 'list_all'], true)) {
        $rows = $service->listApprovalAll($scope, [
            'status' => (string)($_GET['status'] ?? $_POST['status'] ?? ''),
            'doc_type' => (string)($_GET['doc_type'] ?? $_POST['doc_type'] ?? ''),
            'search' => (string)($_GET['search'] ?? $_POST['search'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 200),
        ]);
        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'approval document list loaded');
        exit;
    }

    if (in_array($todo, ['approval_official', 'list_official'], true)) {
        $rows = $service->listApprovalOfficial($scope, [
            'status' => (string)($_GET['status'] ?? $_POST['status'] ?? ''),
            'search' => (string)($_GET['search'] ?? $_POST['search'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 200),
        ]);
        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'official approval list loaded');
        exit;
    }

    if (in_array($todo, ['approval_detail', 'doc_detail'], true)) {
        $docId = (int)($_GET['doc_id'] ?? $_GET['idx'] ?? $_POST['doc_id'] ?? $_POST['idx'] ?? 0);
        if ($docId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'doc_id is required', 422);
            exit;
        }

        $doc = $service->getApprovalDocument($scope, $docId, $userPk, $roleLevel);
        if ($doc === null) {
            ApiResponse::error('APPROVAL_DOC_NOT_FOUND', 'approval document not found', 404, ['doc_id' => $docId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'item' => $doc,
        ], 'OK', 'approval document loaded');
        exit;
    }

    if (in_array($todo, ['approval_write', 'draft_create'], true)) {
        $saved = $service->createApprovalDraft($scope, $userPk, $_POST + $_GET);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'approval draft created');
        exit;
    }

    if ($todo === 'approval_submit') {
        $docId = (int)($_POST['doc_id'] ?? $_POST['idx'] ?? $_GET['doc_id'] ?? $_GET['idx'] ?? 0);
        if ($docId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'doc_id is required', 422);
            exit;
        }

        $saved = $service->submitApproval($scope, $docId, $userPk, $roleLevel);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'approval submitted');
        exit;
    }

    if (in_array($todo, ['approval_approve', 'approval_reject', 'approval_cancel'], true)) {
        $docId = (int)($_POST['doc_id'] ?? $_POST['idx'] ?? $_GET['doc_id'] ?? $_GET['idx'] ?? 0);
        if ($docId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'doc_id is required', 422);
            exit;
        }

        $comment = trim((string)($_POST['comment'] ?? $_GET['comment'] ?? ''));
        $actionMap = [
            'approval_approve' => 'approve',
            'approval_reject' => 'reject',
            'approval_cancel' => 'cancel',
        ];
        $action = $actionMap[$todo];

        $saved = $service->approvalAction($scope, $docId, $userPk, $roleLevel, $action, $comment);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'approval action applied');
        exit;
    }

    if (in_array($todo, ['approval_comment', 'add_comment'], true)) {
        $docId = (int)($_POST['doc_id'] ?? $_POST['idx'] ?? $_GET['doc_id'] ?? $_GET['idx'] ?? 0);
        if ($docId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'doc_id is required', 422);
            exit;
        }

        $comment = trim((string)($_POST['comment'] ?? $_GET['comment'] ?? ''));
        if ($comment === '') {
            ApiResponse::error('INVALID_INPUT', 'comment is required', 422);
            exit;
        }

        $saved = $service->addApprovalComment($scope, $docId, $userPk, $roleLevel, $comment);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'approval comment added');
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
