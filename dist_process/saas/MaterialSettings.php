<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/erp/MaterialSettingsService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', '인증이 필요합니다', 401);
        exit;
    }

    $todoRaw = trim((string)($_GET['todo'] ?? $_POST['todo'] ?? 'material_settings_get'));
    $todo = strtolower($todoRaw);

    $getTodos = ['material_settings_get', 'settings_get', 'get'];
    $saveTodos = ['material_settings_save', 'settings_save', 'save'];
    $savePjtTodos = ['material_settings_save_pjt_items', 'save_pjt_items'];
    $saveCategoryLabelTodos = ['material_settings_save_category_option_labels', 'save_category_option_labels'];

    $writeTodos = array_merge($saveTodos, $savePjtTodos, $saveCategoryLabelTodos);

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
        $maxAuthorityIdx = max(1, shvEnvInt('MAT_WRITE_MAX_AUTHORITY_IDX', 3));
        if ($roleLevel < 1 || $roleLevel > $maxAuthorityIdx) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, [
                'required_min' => 1,
                'required_max' => $maxAuthorityIdx,
                'current' => $roleLevel,
            ]);
            exit;
        }
    }

    $service = new MaterialSettingsService(DbConnection::get());
    $actorUserPk = (int)($context['user_pk'] ?? 0);

    if (in_array($todo, $getTodos, true)) {
        ApiResponse::success($service->get(), 'OK', '품목설정 조회 성공');
        exit;
    }

    if (in_array($todo, $saveTodos, true)) {
        ApiResponse::success($service->save($_POST, $actorUserPk), 'OK', '품목설정 저장 성공');
        exit;
    }

    if (in_array($todo, $savePjtTodos, true)) {
        $raw = $_POST['pjt_items'] ?? $_POST['items'] ?? $_POST['items_json'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        ApiResponse::success($service->savePjtItems($raw, $actorUserPk), 'OK', 'PJT 항목 저장 성공');
        exit;
    }

    if (in_array($todo, $saveCategoryLabelTodos, true)) {
        $raw = $_POST['category_option_labels'] ?? $_POST['options'] ?? $_POST['options_json'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        ApiResponse::success($service->saveCategoryOptionLabels($raw, $actorUserPk), 'OK', '카테고리 옵션 저장 성공');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo: ' . $todoRaw, 400, ['todo' => $todoRaw]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_PARAM', $e->getMessage(), 422);
} catch (RuntimeException $e) {
    ApiResponse::error('BUSINESS_RULE_VIOLATION', $e->getMessage(), 409);
} catch (Throwable $e) {
    error_log('[MaterialSettings API] ' . $e->getMessage());
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : '서버 오류가 발생했습니다',
        500
    );
}
