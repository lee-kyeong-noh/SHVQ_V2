<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/erp/MaterialService.php';
require_once __DIR__ . '/../../dist_library/erp/CategoryService.php';
require_once __DIR__ . '/../../dist_library/saas/storage/StorageService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', '인증이 필요합니다', 401);
        exit;
    }

    $todoRaw = trim((string)($_GET['todo'] ?? $_POST['todo'] ?? 'material_list'));
    $todo = strtolower($todoRaw);

    $listTodos = ['material_list', 'list'];
    $detailTodos = ['material_detail', 'detail', 'view'];
    $createTodos = ['material_create', 'create', 'insert'];
    $updateTodos = ['material_update', 'update', 'edit'];
    $deleteTodos = ['material_delete', 'delete', 'remove'];
    $searchTodos = ['material_search', 'search'];
    $companyListTodos = ['company_list', 'list_company'];
    $v1LegacyListTodos = ['v1_legacy_list', 'legacy_list_v1'];
    $copyFromV1Todos = ['copy_from_v1', 'v1_copy'];
    $tabListTodos = ['tab_list', 'item_tab_list'];
    $itemPropertyMasterTodos = ['item_property_master', 'pjt_property_master'];
    $tabInsertTodos = ['tab_insert', 'item_tab_insert'];
    $tabDeleteTodos = ['tab_delete', 'item_tab_delete'];
    $moveItemsTodos = ['move_items', 'items_move', 'item_move'];
    $fillCodesTodos = ['fill_item_codes', 'generate_item_codes'];
    $frequentTodos = ['frequent_items', 'item_frequent'];
    $historyListTodos = ['history_list', 'item_history'];
    $historyRestoreTodos = ['history_restore', 'item_history_restore'];
    $inlineUpdateTodos = ['item_inline_update', 'inline_update'];
    $compListTodos = ['component_list', 'get_child_items'];
    $compSearchTodos = ['component_search', 'search_component'];
    $compAddTodos    = ['component_add'];
    $compUpdateTodos = ['component_update'];
    $compDelTodos    = ['component_delete'];

    $catListTodos    = ['category_list'];
    $catCreateTodos  = ['category_create'];
    $catUpdateTodos  = ['category_update'];
    $catDeleteTodos  = ['category_delete'];
    $catReorderTodos = ['category_reorder'];
    $catMoveParentTodos = ['cat_move_parent', 'category_move_parent'];

    $writeTodos = array_merge(
        $createTodos, $updateTodos, $deleteTodos,
        $copyFromV1Todos,
        $tabInsertTodos, $tabDeleteTodos, $moveItemsTodos, $fillCodesTodos,
        $historyRestoreTodos, $inlineUpdateTodos,
        $compAddTodos, $compUpdateTodos, $compDelTodos,
        $catCreateTodos, $catUpdateTodos, $catDeleteTodos, $catReorderTodos, $catMoveParentTodos
    );

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

    $db          = DbConnection::get();
    $service     = new MaterialService($db);
    $catService  = new CategoryService($db);
    $actorUserPk = (int)($context['user_pk'] ?? 0);

    if (in_array($todo, $itemPropertyMasterTodos, true)) {
        $serviceCode = (string)($context['service_code'] ?? 'shvq');
        $tenantId = (int)($context['tenant_id'] ?? 0);

        $normalizeColor = static function (mixed $raw): string {
            $value = strtoupper(trim((string)$raw));
            if (preg_match('/^#[0-9A-F]{6}$/', $value) === 1) {
                return $value;
            }
            if (preg_match('/^[0-9A-F]{6}$/', $value) === 1) {
                return '#' . $value;
            }
            if (preg_match('/^#[0-9A-F]{3}$/', $value) === 1) {
                return '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
            }
            if (preg_match('/^[0-9A-F]{3}$/', $value) === 1) {
                return '#' . $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
            }
            return '#FFFFFF';
        };

        $propertyMap = ['0' => '없음'];
        $colorsMap = ['0' => '#FFFFFF'];

        $tableExistsStmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'"
        );
        $columnExistsStmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?"
        );

        $tableExists = static function (string $table) use ($tableExistsStmt): bool {
            $tableExistsStmt->execute([$table]);
            return (int)$tableExistsStmt->fetchColumn() > 0;
        };
        $columnExists = static function (string $table, string $column) use ($columnExistsStmt): bool {
            $columnExistsStmt->execute([$table, $column]);
            return (int)$columnExistsStmt->fetchColumn() > 0;
        };

        $decodeConstant = static function (string $constantName): array {
            if (!defined($constantName)) {
                return [];
            }
            $value = constant($constantName);
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            return [];
        };

        $readSetting = static function (string $settingKey) use (
            $db,
            $serviceCode,
            $tenantId,
            $columnExists
        ): array {
            $where = ['setting_key IN (?, ?)'];
            $params = [$settingKey, strtolower($settingKey)];

            if ($columnExists('Tb_UserSettings', 'service_code')) {
                $where[] = 'service_code = ?';
                $params[] = $serviceCode;
            }
            if ($columnExists('Tb_UserSettings', 'tenant_id')) {
                $where[] = 'ISNULL(tenant_id, 0) = ?';
                $params[] = $tenantId;
            }
            if ($columnExists('Tb_UserSettings', 'is_deleted')) {
                $where[] = 'ISNULL(is_deleted, 0) = 0';
            }
            if ($columnExists('Tb_UserSettings', 'user_id')) {
                $where[] = "(ISNULL(user_id, '') IN ('', ?) OR user_id LIKE '__SYSTEM__:%')";
                $params[] = '__SYSTEM__:' . strtoupper($settingKey);
            }

            $orderParts = [];
            if ($columnExists('Tb_UserSettings', 'updated_at')) {
                $orderParts[] = 'updated_at DESC';
            }
            if ($columnExists('Tb_UserSettings', 'regdate')) {
                $orderParts[] = 'regdate DESC';
            }
            if ($columnExists('Tb_UserSettings', 'idx')) {
                $orderParts[] = 'idx DESC';
            }
            if ($orderParts === []) {
                $orderParts[] = '(SELECT NULL)';
            }

            $sql = 'SELECT TOP 1 setting_value FROM Tb_UserSettings WHERE '
                . implode(' AND ', $where)
                . ' ORDER BY ' . implode(', ', $orderParts);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $raw = $stmt->fetchColumn();
            if (!is_string($raw) || trim($raw) === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        };

        if (
            $tableExists('Tb_UserSettings')
            && $columnExists('Tb_UserSettings', 'setting_key')
            && $columnExists('Tb_UserSettings', 'setting_value')
        ) {
            $propertyRaw = $readSetting('ITEM_PROPERTY');
            $colorRaw = $readSetting('ITEM_PROPERTY_COLORS');

            if ($propertyRaw === []) {
                $propertyRaw = $decodeConstant('ITEM_PROPERTY');
            }
            if ($colorRaw === []) {
                $colorRaw = $decodeConstant('ITEM_PROPERTY_COLORS');
            }

            foreach ($propertyRaw as $key => $label) {
                $propertyKey = trim((string)$key);
                $name = trim((string)$label);
                if ($propertyKey === '' || $name === '') {
                    continue;
                }
                $propertyMap[$propertyKey] = $name;
            }

            foreach ($colorRaw as $key => $color) {
                $colorKey = trim((string)$key);
                if ($colorKey === '') {
                    continue;
                }
                $colorsMap[$colorKey] = $normalizeColor($color);
            }
        } else {
            $propertyRaw = $decodeConstant('ITEM_PROPERTY');
            $colorRaw = $decodeConstant('ITEM_PROPERTY_COLORS');
            foreach ($propertyRaw as $key => $label) {
                $propertyKey = trim((string)$key);
                $name = trim((string)$label);
                if ($propertyKey === '' || $name === '') {
                    continue;
                }
                $propertyMap[$propertyKey] = $name;
            }
            foreach ($colorRaw as $key => $color) {
                $colorKey = trim((string)$key);
                if ($colorKey === '') {
                    continue;
                }
                $colorsMap[$colorKey] = $normalizeColor($color);
            }
        }

        if (!array_key_exists('0', $propertyMap) || trim((string)$propertyMap['0']) === '') {
            $propertyMap['0'] = '없음';
        }
        ksort($propertyMap, SORT_NATURAL);

        foreach ($propertyMap as $key => $_name) {
            if (!array_key_exists($key, $colorsMap)) {
                $colorsMap[$key] = '#FFFFFF';
            }
        }
        $colorsMap['0'] = $normalizeColor($colorsMap['0'] ?? '#FFFFFF');
        ksort($colorsMap, SORT_NATURAL);

        $properties = [];
        foreach ($propertyMap as $key => $name) {
            $keyText = trim((string)$key);
            if ($keyText === '') {
                continue;
            }

            $properties[] = [
                'key' => ctype_digit($keyText) ? (int)$keyText : $keyText,
                'name' => (string)$name,
                'color' => (string)($colorsMap[$keyText] ?? '#FFFFFF'),
            ];
        }

        ApiResponse::success([
            'properties' => $properties,
            'property_map' => $propertyMap,
            'colors' => $colorsMap,
        ], 'OK', 'PJT 속성 마스터 조회 성공');
        exit;
    }

    if (in_array($todo, $listTodos, true)) {
        $query = $_GET + $_POST;
        $result = $service->list($query);
        $catQuery = [
            'limit' => 500,
            'include_deleted' => 0,
        ];
        if (array_key_exists('tab_idx', $query)) {
            $catQuery['tab_idx'] = (int)$query['tab_idx'];
        }
        $catResult = $catService->list($catQuery);
        $categories = is_array($catResult['list'] ?? null) ? $catResult['list'] : [];
        $result['categories'] = $categories;
        $result['categories_total'] = count($categories);
        ApiResponse::success($result, 'OK', '품목 목록 조회 성공');
        exit;
    }

    if (in_array($todo, $detailTodos, true)) {
        $idx = (int)($_GET['idx'] ?? $_GET['item_idx'] ?? $_POST['idx'] ?? $_POST['item_idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', '유효하지 않은 idx', 400);
            exit;
        }

        $row = $service->detail($idx);
        if (!is_array($row) || $row === []) {
            ApiResponse::error('NOT_FOUND', '품목을 찾을 수 없습니다', 404, ['idx' => $idx]);
            exit;
        }

        ApiResponse::success(['row' => $row], 'OK', '품목 상세 조회 성공');
        exit;
    }

    if (in_array($todo, $searchTodos, true)) {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        if ($q === '') {
            ApiResponse::success(['data' => []], 'OK', '검색어 없음');
            exit;
        }

        $limit = min(50, max(1, (int)($_GET['limit'] ?? $_POST['limit'] ?? 20)));
        $result = $service->search($q, $limit);
        ApiResponse::success(['data' => $result], 'OK', '품목 검색 성공');
        exit;
    }

    if (in_array($todo, $companyListTodos, true)) {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? $_POST['limit'] ?? 20)));
        $result = $service->companyList($q, $limit);
        ApiResponse::success($result, 'OK', '매입처 목록 조회 성공');
        exit;
    }

    if (in_array($todo, $v1LegacyListTodos, true)) {
        $result = $service->v1LegacyList($_GET + $_POST);
        ApiResponse::success($result, 'OK', 'V1 품목 조회 성공');
        exit;
    }

    if (in_array($todo, $frequentTodos, true)) {
        $limit = min(100, max(1, (int)($_GET['limit'] ?? $_POST['limit'] ?? 20)));
        $rows = $service->frequentItems($limit);
        ApiResponse::success([
            'data' => $rows,
            'total' => count($rows),
            'limit' => $limit,
        ], 'OK', '자주 쓰는 품목 조회 성공');
        exit;
    }

    if (in_array($todo, $historyListTodos, true)) {
        $itemIdx = (int)($_GET['item_idx'] ?? $_POST['item_idx'] ?? $_GET['idx'] ?? $_POST['idx'] ?? 0);
        if ($itemIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'item_idx 필수', 400);
            exit;
        }
        $page = max(1, (int)($_GET['p'] ?? $_POST['p'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? $_POST['limit'] ?? 20)));
        $result = $service->historyList($itemIdx, $page, $limit);
        ApiResponse::success($result, 'OK', '품목 이력 조회 성공');
        exit;
    }

    if (in_array($todo, $compSearchTodos, true)) {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $tabIdx = (int)($_GET['tab_idx'] ?? $_POST['tab_idx'] ?? 0);
        $categoryIdx = (int)($_GET['category_idx'] ?? $_POST['category_idx'] ?? $_GET['cat_idx'] ?? $_POST['cat_idx'] ?? 0);
        $limit = min(50, max(1, (int)($_GET['limit'] ?? $_POST['limit'] ?? 20)));
        $result = $service->searchComponent($q, $tabIdx, $categoryIdx, $limit);
        ApiResponse::success(['data' => $result], 'OK', '구성품 검색 성공');
        exit;
    }

    if (in_array($todo, $compListTodos, true)) {
        $parentIdx = (int)($_GET['parent_item_idx'] ?? $_POST['parent_item_idx'] ?? $_GET['parent_idx'] ?? $_POST['parent_idx'] ?? 0);
        if ($parentIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'parent_item_idx 필수', 400);
            exit;
        }

        $result = $service->componentList($parentIdx);
        ApiResponse::success([
            'data' => $result,
            'total' => count($result),
        ], 'OK', '구성품 목록 조회 성공');
        exit;
    }

    if (in_array($todo, $tabListTodos, true)) {
        $tableExistsStmt = $db->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'\n        ");
        $tableExistsStmt->execute(['Tb_ItemTab']);
        if ((int)$tableExistsStmt->fetchColumn() <= 0) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_ItemTab table is missing', 503);
            exit;
        }

        $columnExistsStmt = $db->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_NAME = ? AND COLUMN_NAME = ?\n        ");
        $columnExists = static function (string $column) use ($columnExistsStmt): bool {
            $columnExistsStmt->execute(['Tb_ItemTab', $column]);
            return (int)$columnExistsStmt->fetchColumn() > 0;
        };

        $nameColumn = $columnExists('name') ? 'name' : ($columnExists('tab_name') ? 'tab_name' : null);
        if ($nameColumn === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_ItemTab name column is missing', 503);
            exit;
        }

        $where = [];
        if ($columnExists('is_deleted')) {
            $where[] = 'ISNULL(is_deleted, 0) = 0';
        }
        $whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));
        $orderSql = $columnExists('sort_order') ? 'ISNULL(sort_order, 0) ASC, idx ASC' : 'idx ASC';

        $stmt = $db->query('SELECT idx, ISNULL(' . $nameColumn . ", '') AS name FROM Tb_ItemTab" . $whereSql . ' ORDER BY ' . $orderSql);
        $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        ApiResponse::success($rows, 'OK', '탭 목록 조회 성공');
        exit;
    }

    if (in_array($todo, $tabInsertTodos, true)) {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            ApiResponse::error('INVALID_PARAM', 'name 필수', 400);
            exit;
        }
        $result = $service->tabInsert($name, $actorUserPk);
        ApiResponse::success($result, 'OK', '탭 추가 성공');
        exit;
    }

    if (in_array($todo, $tabDeleteTodos, true)) {
        $idx = (int)($_POST['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx 필수', 400);
            exit;
        }
        $result = $service->tabDelete($idx, $actorUserPk);
        ApiResponse::success($result, 'OK', '탭 삭제 성공');
        exit;
    }

    if (in_array($todo, $moveItemsTodos, true)) {
        $idxInput = $_POST['idx_list'] ?? $_POST['idxs'] ?? $_POST['idx'] ?? '';
        if (is_string($idxInput)) {
            $decoded = json_decode($idxInput, true);
            if (is_array($decoded)) {
                $idxInput = $decoded;
            }
        }
        $idxList = $service->normalizeIdxList($idxInput, 0);
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list 필수', 400);
            exit;
        }

        $targetTabRaw = $_POST['target_tab_idx'] ?? $_POST['tab_idx'] ?? null;
        $targetCatRaw = $_POST['target_cat_idx'] ?? $_POST['category_idx'] ?? $_POST['cat_idx'] ?? null;
        $targetTabIdx = ($targetTabRaw === null || $targetTabRaw === '') ? null : (int)$targetTabRaw;
        $targetCatIdx = ($targetCatRaw === null || $targetCatRaw === '') ? null : (int)$targetCatRaw;
        $copy = ((int)($_POST['copy'] ?? 0)) === 1;

        $result = $service->moveItems($idxList, $targetTabIdx, $targetCatIdx, $copy, $actorUserPk);
        ApiResponse::success($result, 'OK', $copy ? '품목 복사 성공' : '품목 이동 성공');
        exit;
    }

    if (in_array($todo, $fillCodesTodos, true)) {
        $tabIdx = (int)($_POST['tab_idx'] ?? 0);
        $categoryIdx = (int)($_POST['category_idx'] ?? $_POST['cat_idx'] ?? 0);
        $prefix = trim((string)($_POST['prefix'] ?? 'MAT'));
        $result = $service->fillItemCodes($tabIdx, $categoryIdx, $prefix, $actorUserPk);
        ApiResponse::success($result, 'OK', '자재번호 일괄생성 성공');
        exit;
    }

    if (in_array($todo, $historyRestoreTodos, true)) {
        $historyIdx = (int)($_POST['history_idx'] ?? $_POST['idx'] ?? 0);
        if ($historyIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'history_idx 필수', 400);
            exit;
        }
        $result = $service->historyRestore($historyIdx, $actorUserPk);
        ApiResponse::success($result, 'OK', '이력 복구 성공');
        exit;
    }

    if (in_array($todo, $inlineUpdateTodos, true)) {
        $idx = (int)($_POST['idx'] ?? 0);
        $field = trim((string)($_POST['field'] ?? ''));
        $value = $_POST['value'] ?? null;
        if ($idx <= 0 || $field === '') {
            ApiResponse::error('INVALID_PARAM', 'idx, field 필수', 400);
            exit;
        }
        $result = $service->inlineUpdate($idx, $field, $value, $actorUserPk);
        ApiResponse::success($result, 'OK', '인라인 편집 저장 성공');
        exit;
    }

    if (in_array($todo, $copyFromV1Todos, true)) {
        $idxInput = $_POST['idx_list'] ?? $_POST['idx'] ?? '';
        if (is_string($idxInput)) {
            $decoded = json_decode($idxInput, true);
            if (is_array($decoded)) {
                $idxInput = $decoded;
            }
        }
        $idxList = $service->normalizeIdxList($idxInput, 0);
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list 필수', 400);
            exit;
        }

        $tabIdx = (int)($_POST['tab_idx'] ?? 0);
        $categoryIdx = (int)($_POST['category_idx'] ?? $_POST['cat_idx'] ?? 0);

        $result = $service->copyFromV1(
            $idxList,
            $tabIdx > 0 ? $tabIdx : null,
            $categoryIdx > 0 ? $categoryIdx : null,
            $actorUserPk
        );
        $message = (int)($result['copied'] ?? 0) . '개 복사 (' . (int)($result['skipped'] ?? 0) . '개 건너뜀)';
        ApiResponse::success([
            'copied' => (int)($result['copied'] ?? 0),
            'skipped' => (int)($result['skipped'] ?? 0),
            'copied_idx_list' => $result['copied_idx_list'] ?? [],
            'skipped_idx_list' => $result['skipped_idx_list'] ?? [],
            'missing_idx_list' => $result['missing_idx_list'] ?? [],
            'requested' => (int)($result['requested'] ?? count($idxList)),
        ], 'OK', $message);
        exit;
    }

    if (in_array($todo, $createTodos, true)) {
        $postData = $_POST;
        $tenantId = (int)($context['tenant_id'] ?? $context['company_idx'] ?? 0);
        if ($tenantId > 0) {
            $storage = StorageService::forTenant($tenantId);

            if (!empty($_FILES['upload_files_banner']['name'])) {
                $upload = $storage->upload('mat_banner', $_FILES['upload_files_banner'], 'banner');
                $postData['upload_files_banner'] = (string)($upload['filename'] ?? '');
            }
            if (!empty($_FILES['upload_files_detail']['name'])) {
                $upload = $storage->upload('mat_detail', $_FILES['upload_files_detail'], 'detail');
                $postData['upload_files_detail'] = (string)($upload['filename'] ?? '');
            }
        }

        $result = $service->create($postData, $actorUserPk);
        ApiResponse::success($result, 'OK', '품목 등록 성공');
        exit;
    }

    if (in_array($todo, $updateTodos, true)) {
        $idx = (int)($_POST['idx'] ?? $_POST['item_idx'] ?? $_GET['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', '유효하지 않은 idx', 400);
            exit;
        }

        $postData = $_POST;
        $tenantId = (int)($context['tenant_id'] ?? $context['company_idx'] ?? 0);
        if ($tenantId > 0) {
            $storage = StorageService::forTenant($tenantId);

            if (!empty($_FILES['upload_files_banner']['name'])) {
                $upload = $storage->upload('mat_banner', $_FILES['upload_files_banner'], 'banner_' . $idx);
                $postData['upload_files_banner'] = (string)($upload['filename'] ?? '');
            }
            if (!empty($_POST['del_banner'])) {
                $postData['upload_files_banner'] = '';
            }

            if (!empty($_FILES['upload_files_detail']['name'])) {
                $upload = $storage->upload('mat_detail', $_FILES['upload_files_detail'], 'detail_' . $idx);
                $postData['upload_files_detail'] = (string)($upload['filename'] ?? '');
            }
            if (!empty($_POST['del_detail'])) {
                $postData['upload_files_detail'] = '';
            }
        }

        $result = $service->update($idx, $postData, $actorUserPk);
        ApiResponse::success($result, 'OK', '품목 수정 성공');
        exit;
    }

    if (in_array($todo, $deleteTodos, true)) {
        $idxList = $service->normalizeIdxList($_POST['idx_list'] ?? $_POST['idxs'] ?? $_POST['idx'] ?? $_GET['idx'] ?? '', 0);
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', '삭제할 idx가 없습니다', 400);
            exit;
        }

        $result = $service->deleteByIds($idxList, $actorUserPk);
        ApiResponse::success($result, 'OK', '품목 삭제 성공');
        exit;
    }

    if (in_array($todo, $compAddTodos, true)) {
        $parentIdx = (int)($_POST['parent_item_idx'] ?? 0);
        $childIdx = (int)($_POST['child_item_idx'] ?? 0);
        $qty = max(1, (float)($_POST['qty'] ?? 1));
        if ($parentIdx <= 0 || $childIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'parent_item_idx, child_item_idx 필수', 400);
            exit;
        }

        $result = $service->componentAdd($parentIdx, $childIdx, $qty, $actorUserPk);
        ApiResponse::success($result, 'OK', '구성품 추가 성공');
        exit;
    }

    if (in_array($todo, $compUpdateTodos, true)) {
        $compIdx = (int)($_POST['idx'] ?? 0);
        $qty = max(1, (float)($_POST['qty'] ?? 1));
        if ($compIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx 필수', 400);
            exit;
        }

        $result = $service->componentUpdate($compIdx, $qty, $actorUserPk);
        ApiResponse::success($result, 'OK', '구성품 수량 수정 성공');
        exit;
    }

    if (in_array($todo, $compDelTodos, true)) {
        $compIdx = (int)($_POST['idx'] ?? 0);
        if ($compIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx 필수', 400);
            exit;
        }

        $result = $service->componentDelete($compIdx, $actorUserPk);
        ApiResponse::success($result, 'OK', '구성품 삭제 성공');
        exit;
    }

    /* ── 카테고리 목록 ── */
    if (in_array($todo, $catListTodos, true)) {
        $result = $catService->list($_GET + $_POST);
        ApiResponse::success($result, 'OK', '카테고리 목록 조회 성공');
        exit;
    }

    /* ── 카테고리 추가 ── */
    if (in_array($todo, $catCreateTodos, true)) {
        $result = $catService->create($_POST, $actorUserPk);
        ApiResponse::success($result, 'OK', '카테고리 추가 성공');
        exit;
    }

    /* ── 카테고리 수정 ── */
    if (in_array($todo, $catUpdateTodos, true)) {
        $idx = (int)($_POST['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', '유효하지 않은 idx', 400);
            exit;
        }
        $result = $catService->update($idx, $_POST, $actorUserPk);
        ApiResponse::success($result, 'OK', '카테고리 수정 성공');
        exit;
    }

    /* ── 카테고리 삭제 ── */
    if (in_array($todo, $catDeleteTodos, true)) {
        $idx = (int)($_POST['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', '유효하지 않은 idx', 400);
            exit;
        }
        $result = $catService->deleteByIds([$idx], $actorUserPk);
        ApiResponse::success($result, 'OK', '카테고리 삭제 성공');
        exit;
    }

    /* ── 카테고리 순서 변경 ── */
    if (in_array($todo, $catReorderTodos, true)) {
        $ordersJson = trim((string)($_POST['orders'] ?? '[]'));
        $orders = json_decode($ordersJson, true);
        if (!is_array($orders) || $orders === []) {
            ApiResponse::error('INVALID_PARAM', 'orders JSON 필요', 400);
            exit;
        }
        $result = $catService->reorder($orders, $actorUserPk);
        ApiResponse::success($result, 'OK', '카테고리 순서 저장 성공');
        exit;
    }

    /* ── 카테고리 부모 변경 ── */
    if (in_array($todo, $catMoveParentTodos, true)) {
        $idx = (int)($_POST['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx 필수', 400);
            exit;
        }

        $newParentIdx = (int)($_POST['parent_idx'] ?? $_POST['new_parent_idx'] ?? 0);
        $result = $service->moveCategoryParent($idx, $newParentIdx, $actorUserPk);
        ApiResponse::success($result, 'OK', '카테고리 부모 변경 성공');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo: ' . $todoRaw, 400, ['todo' => $todoRaw]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_PARAM', $e->getMessage(), 422);
} catch (RuntimeException $e) {
    ApiResponse::error('BUSINESS_RULE_VIOLATION', $e->getMessage(), 409);
} catch (Throwable $e) {
    error_log('[Material API] ' . $e->getMessage());
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : '서버 오류가 발생했습니다',
        500
    );
}
