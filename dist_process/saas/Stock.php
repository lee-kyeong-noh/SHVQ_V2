<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/erp/StockService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', '인증이 필요합니다', 401);
        exit;
    }

    $todoRaw = trim((string)($_GET['todo'] ?? $_POST['todo'] ?? 'stock_status'));
    $todo = strtolower($todoRaw);

    $statusTodos = ['stock_status', 'status_list'];
    $inTodos = ['stock_in'];
    $outTodos = ['stock_out'];
    $transferTodos = ['stock_transfer'];
    $adjustTodos = ['stock_adjust'];
    $logTodos = ['stock_log', 'log_list'];
    $settingsGetTodos = ['stock_settings_get', 'settings_get'];
    $settingsSaveTodos = ['stock_settings_save', 'save_settings'];

    $branchListTodos = ['branch_list'];
    $itemSearchTodos = ['item_search'];
    $siteSearchTodos = ['site_search'];
    $itemStockDetailTodos = ['item_stock_detail'];

    $writeTodos = array_merge($inTodos, $outTodos, $transferTodos, $adjustTodos, $settingsSaveTodos);

    if (in_array($todo, $writeTodos, true)) {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }

        if (!$auth->validateCsrf()) {
            ApiResponse::error('CSRF_INVALID', '보안 검증 실패', 403);
            exit;
        }

        $roleLevel = (int)($context['role_level'] ?? 0);
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, [
                'required' => 2,
                'current' => $roleLevel,
            ]);
            exit;
        }
    }

    $service = new StockService(DbConnection::get());
    $actorUserPk = (int)($context['user_pk'] ?? 0);
    $actorName = (string)($context['login_id'] ?? '');

    if (in_array($todo, $statusTodos, true)) {
        $result = $service->stockStatus($_GET);
        ApiResponse::success($result, 'OK', '재고현황 조회 성공');
        exit;
    }

    if (in_array($todo, $inTodos, true)) {
        $result = $service->stockIn($_POST, $actorUserPk, $actorName);
        ApiResponse::success($result, 'OK', '입고 처리 완료');
        exit;
    }

    if (in_array($todo, $outTodos, true)) {
        $result = $service->stockOut($_POST, $actorUserPk, $actorName);
        ApiResponse::success($result, 'OK', '출고 처리 완료');
        exit;
    }

    if (in_array($todo, $transferTodos, true)) {
        $result = $service->stockTransfer($_POST, $actorUserPk, $actorName);
        ApiResponse::success($result, 'OK', '창고간 이동 처리 완료');
        exit;
    }

    if (in_array($todo, $adjustTodos, true)) {
        $result = $service->stockAdjust($_POST, $actorUserPk, $actorName);
        ApiResponse::success($result, 'OK', '재고 조정 처리 완료');
        exit;
    }

    if (in_array($todo, $logTodos, true)) {
        $result = $service->stockLog($_GET);
        ApiResponse::success($result, 'OK', '재고이력 조회 성공');
        exit;
    }

    if (in_array($todo, $settingsGetTodos, true)) {
        $result = $service->stockSettingsGet();
        ApiResponse::success($result, 'OK', '재고설정 조회 성공');
        exit;
    }

    if (in_array($todo, $settingsSaveTodos, true)) {
        $result = $service->stockSettingsSave($_POST, $actorUserPk);
        ApiResponse::success($result, 'OK', '재고설정 저장 성공');
        exit;
    }

    if (in_array($todo, $branchListTodos, true)) {
        ApiResponse::success($service->branchList(), 'OK', '지사 목록 조회 성공');
        exit;
    }

    if (in_array($todo, $itemSearchTodos, true)) {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $tabIdx = (int)($_GET['tab_idx'] ?? $_POST['tab_idx'] ?? 0);
        $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 20);
        ApiResponse::success($service->itemSearch($q, $tabIdx, $limit), 'OK', '품목 검색 성공');
        exit;
    }

    if (in_array($todo, $siteSearchTodos, true)) {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 20);
        ApiResponse::success($service->siteSearch($q, $limit), 'OK', '현장 검색 성공');
        exit;
    }

    if (in_array($todo, $itemStockDetailTodos, true)) {
        $itemIdx = (int)($_GET['item_idx'] ?? $_POST['item_idx'] ?? 0);
        if ($itemIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', '유효하지 않은 item_idx', 400);
            exit;
        }

        ApiResponse::success($service->itemStockDetail($itemIdx), 'OK', '품목별 재고 상세 조회 성공');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo: ' . $todoRaw, 400, ['todo' => $todoRaw]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_PARAM', $e->getMessage(), 422);
} catch (RuntimeException $e) {
    ApiResponse::error('BUSINESS_RULE_VIOLATION', $e->getMessage(), 409);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : '서버 오류가 발생했습니다',
        500
    );
}
