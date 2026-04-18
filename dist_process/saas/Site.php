<?php
declare(strict_types=1);
/**
 * SHVQ V2 — Site API (FMS 현장/견적)
 *
 * Site:
 *   todo=list, detail, search, insert, update, delete
 *   todo=asset_list, bill_list, bill_detail, insert_bill, update_bill, delete_bill, deposit_bill
 *   todo=bill_comment_list, insert_bill_comment
 *   todo=attach_list, upload_attach, delete_attach
 *   todo=contact_list, delete_contact, floor_plan_list, subcontract_list, access_log_list
 *   todo=insert_floor_plan, delete_floor_plan
 *   todo=insert_subcontract, update_subcontract, delete_subcontract
 *   todo=insert_access_log, delete_access_log
 *   todo=toggle_contact_hidden
 *   todo=site_settings, save_site_settings, excel_template, excel_upload
 * Estimate:
 *   todo=est_list, est_detail, est_file_list, insert_est, update_est, delete_estimate,
 *   copy_est, recalc_est, upsert_est_items, update_est_item, delete_est_item,
 *   approve_est, est_pdf_data
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/security/FmsInputValidator.php';
require_once __DIR__ . '/../../dist_library/saas/ShadowWriteQueueService.php';
require_once __DIR__ . '/../../dist_library/saas/DevLogService.php';
require_once __DIR__ . '/../../dist_library/saas/storage/StorageService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', '인증이 필요합니다', 401);
        exit;
    }

    $todoRaw = trim((string)($_GET['todo'] ?? $_POST['todo'] ?? ''));
    $todo = strtolower($todoRaw);

    $db = DbConnection::get();
    $security = require __DIR__ . '/../../config/security.php';

    $tableExistsCache = [];
    $tableExists = static function (PDO $pdo, string $table) use (&$tableExistsCache): bool {
        $key = strtolower($table);
        if (array_key_exists($key, $tableExistsCache)) {
            return (bool)$tableExistsCache[$key];
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'\n        ");
        $stmt->execute([$table]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $tableExistsCache[$key] = $exists;
        return $exists;
    };

    $columnExistsCache = [];
    $columnExists = static function (PDO $pdo, string $table, string $column) use (&$columnExistsCache): bool {
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $columnExistsCache)) {
            return (bool)$columnExistsCache[$key];
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_NAME = ? AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $columnExistsCache[$key] = $exists;
        return $exists;
    };

    $firstExistingColumn = static function (PDO $pdo, callable $columnExistsFn, string $table, array $candidates): ?string {
        foreach ($candidates as $candidate) {
            $col = trim((string)$candidate);
            if ($col !== '' && $columnExistsFn($pdo, $table, $col)) {
                return $col;
            }
        }
        return null;
    };

    if (!$tableExists($db, 'Tb_Site')) {
        ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Site table is missing', 503);
        exit;
    }

    $siteNameCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['site_name', 'name']) ?? 'name';
    $siteStatusCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['site_status', 'status']) ?? 'status';
    $estimateStatusCol = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', ['estimate_status', 'status']) ?? 'status';
    $siteNumberCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['site_number', 'site_code']);
    $siteEmployeeCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['employee_idx']);
    $siteEmployee1Col = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['employee1_idx', 'sub_employee_idx', 'assistant_employee_idx']);
    $sitePhonebookCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['phonebook_idx', 'contact_idx', 'order_contact_idx']);
    $siteRegionCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['region', 'region_name', 'region_idx']);
    $siteTargetTeamCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['target_team', 'department', 'team_name']);
    $siteExternalEmployeeCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['external_employee', 'total_qty']);
    $siteTotalQtyCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['total_qty', 'external_employee']);
    $siteConstructionCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['construction', 'constructor', 'company_name']);
    $siteConstructionDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['construction_date', 'start_date']);
    $siteCompletionDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['completion_date', 'end_date']);
    $siteWarrantyCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['warranty_period']);
    $siteRegisteredDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['registered_date', 'regdate', 'created_at']);

    $estimateItemFkColumn = static function (PDO $pdo, callable $columnExistsFn, callable $firstCol): ?string {
        return $firstCol($pdo, $columnExistsFn, 'Tb_EstimateItem', ['site_estimate_idx', 'estimate_idx', 'est_idx', 'site_est_idx', 'site_estimate_id']);
    };

    $parseItems = static function (): array {
        $raw = $_POST['items_json'] ?? $_POST['items'] ?? null;
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $isAssoc = static fn(array $arr): bool => array_keys($arr) !== range(0, count($arr) - 1);
        if ($raw !== [] && $isAssoc($raw)) {
            return [$raw];
        }

        $items = [];
        foreach ($raw as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }
        return $items;
    };

    $qi = static function (string $column): string {
        return '[' . str_replace(']', ']]', $column) . ']';
    };

    $resolveContextEmployeeIdx = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, array $ctx): int {
        $fromContext = (int)($ctx['employee_idx'] ?? $ctx['emp_idx'] ?? 0);
        if ($fromContext > 0) {
            return $fromContext;
        }

        $legacy = $_SESSION['shv_user'] ?? null;
        if (is_array($legacy)) {
            $legacyIdx = (int)($legacy['employee_idx'] ?? $legacy['emp_idx'] ?? 0);
            if ($legacyIdx > 0) {
                return $legacyIdx;
            }
        }

        $userPk = (int)($ctx['user_pk'] ?? 0);
        $loginId = trim((string)($ctx['login_id'] ?? ''));

        if ($userPk > 0 && $tableExistsFn($pdo, 'Tb_Users')) {
            if ($columnExistsFn($pdo, 'Tb_Users', 'employee_idx')) {
                $stmt = $pdo->prepare('SELECT TOP 1 employee_idx FROM Tb_Users WHERE idx = ?');
                $stmt->execute([$userPk]);
                $employeeIdx = (int)$stmt->fetchColumn();
                if ($employeeIdx > 0) {
                    return $employeeIdx;
                }
            }

            if ($columnExistsFn($pdo, 'Tb_Users', 'emp_idx')) {
                $stmt = $pdo->prepare('SELECT TOP 1 emp_idx FROM Tb_Users WHERE idx = ?');
                $stmt->execute([$userPk]);
                $employeeIdx = (int)$stmt->fetchColumn();
                if ($employeeIdx > 0) {
                    return $employeeIdx;
                }
            }
        }

        if ($tableExistsFn($pdo, 'Tb_Employee')) {
            if ($userPk > 0 && $columnExistsFn($pdo, 'Tb_Employee', 'user_idx')) {
                $where = ['user_idx = ?'];
                if ($columnExistsFn($pdo, 'Tb_Employee', 'is_deleted')) {
                    $where[] = 'ISNULL(is_deleted,0)=0';
                }
                $stmt = $pdo->prepare('SELECT TOP 1 idx FROM Tb_Employee WHERE ' . implode(' AND ', $where) . ' ORDER BY idx DESC');
                $stmt->execute([$userPk]);
                $employeeIdx = (int)$stmt->fetchColumn();
                if ($employeeIdx > 0) {
                    return $employeeIdx;
                }
            }

            if ($loginId !== '') {
                foreach (['id', 'login_id', 'user_id'] as $loginColumn) {
                    if (!$columnExistsFn($pdo, 'Tb_Employee', $loginColumn)) {
                        continue;
                    }

                    $where = [$loginColumn . ' = ?'];
                    if ($columnExistsFn($pdo, 'Tb_Employee', 'is_deleted')) {
                        $where[] = 'ISNULL(is_deleted,0)=0';
                    }
                    $stmt = $pdo->prepare('SELECT TOP 1 idx FROM Tb_Employee WHERE ' . implode(' AND ', $where) . ' ORDER BY idx DESC');
                    $stmt->execute([$loginId]);
                    $employeeIdx = (int)$stmt->fetchColumn();
                    if ($employeeIdx > 0) {
                        return $employeeIdx;
                    }
                }
            }
        }

        return 0;
    };

    $upsertSystemSetting = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        callable $firstColFn,
        callable $qiFn,
        string $svcCode,
        int $tenantId,
        int $actorUserPk,
        string $settingKey,
        string $settingValue,
        string $settingType = 'json',
        string $settingGroup = 'site'
    ): int {
        if (!$tableExistsFn($pdo, 'Tb_UserSettings')) {
            return 0;
        }

        $idxCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['idx', 'id']);
        $serviceCodeCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['service_code']);
        $tenantIdCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['tenant_id']);
        $settingGroupCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['setting_group']);
        $settingKeyCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['setting_key']);
        $settingValueCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['setting_value']);
        $settingTypeCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['setting_type']);
        $userIdCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['user_id']);
        $isDeletedCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['is_deleted']);
        $createdByCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['created_by']);
        $createdAtCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['created_at']);
        $updatedByCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['updated_by']);
        $updatedAtCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['updated_at', 'updated_date']);
        $regdateCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['regdate']);

        if ($settingKeyCol === null || $settingValueCol === null) {
            return 0;
        }

        $scopeWhere = [$qiFn($settingKeyCol) . ' = ?'];
        $scopeParams = [$settingKey];
        if ($serviceCodeCol !== null) { $scopeWhere[] = $qiFn($serviceCodeCol) . ' = ?'; $scopeParams[] = $svcCode; }
        if ($tenantIdCol !== null) { $scopeWhere[] = 'ISNULL(' . $qiFn($tenantIdCol) . ',0) = ?'; $scopeParams[] = $tenantId; }
        if ($settingGroupCol !== null) { $scopeWhere[] = $qiFn($settingGroupCol) . ' = ?'; $scopeParams[] = $settingGroup; }
        if ($userIdCol !== null) { $scopeWhere[] = $qiFn($userIdCol) . ' = ?'; $scopeParams[] = '__SYSTEM__:' . $settingKey; }
        if ($isDeletedCol !== null) { $scopeWhere[] = 'ISNULL(' . $qiFn($isDeletedCol) . ',0)=0'; }

        $rowIdx = 0;
        if ($idxCol !== null) {
            $findSql = 'SELECT TOP 1 ' . $qiFn($idxCol) . ' AS row_idx FROM Tb_UserSettings WHERE ' . implode(' AND ', $scopeWhere)
                . ' ORDER BY ' . $qiFn($idxCol) . ' DESC';
            $findStmt = $pdo->prepare($findSql);
            $findStmt->execute($scopeParams);
            $found = $findStmt->fetch(PDO::FETCH_ASSOC);
            $rowIdx = is_array($found) ? (int)($found['row_idx'] ?? 0) : 0;
        }

        if ($rowIdx > 0 && $idxCol !== null) {
            $setSql = [$qiFn($settingValueCol) . ' = ?'];
            $params = [$settingValue];
            if ($settingTypeCol !== null) { $setSql[] = $qiFn($settingTypeCol) . ' = ?'; $params[] = $settingType; }
            if ($isDeletedCol !== null) { $setSql[] = $qiFn($isDeletedCol) . ' = 0'; }
            if ($updatedByCol !== null) { $setSql[] = $qiFn($updatedByCol) . ' = ?'; $params[] = $actorUserPk; }
            if ($updatedAtCol !== null) { $setSql[] = $qiFn($updatedAtCol) . ' = GETDATE()'; }
            $params[] = $rowIdx;
            $stmt = $pdo->prepare('UPDATE Tb_UserSettings SET ' . implode(', ', $setSql) . ' WHERE ' . $qiFn($idxCol) . ' = ?');
            $stmt->execute($params);
            return $rowIdx;
        }

        $columns = [];
        $valuesSql = [];
        $params = [];
        $addVal = static function (string $column, mixed $value) use (&$columns, &$valuesSql, &$params, $qiFn): void {
            $columns[] = $qiFn($column);
            $valuesSql[] = '?';
            $params[] = $value;
        };
        $addRaw = static function (string $column, string $valueSql) use (&$columns, &$valuesSql, $qiFn): void {
            $columns[] = $qiFn($column);
            $valuesSql[] = $valueSql;
        };

        $addVal($settingKeyCol, $settingKey);
        $addVal($settingValueCol, $settingValue);
        if ($settingTypeCol !== null) { $addVal($settingTypeCol, $settingType); }
        if ($serviceCodeCol !== null) { $addVal($serviceCodeCol, $svcCode); }
        if ($tenantIdCol !== null) { $addVal($tenantIdCol, $tenantId); }
        if ($settingGroupCol !== null) { $addVal($settingGroupCol, $settingGroup); }
        if ($userIdCol !== null) { $addVal($userIdCol, '__SYSTEM__:' . $settingKey); }
        if ($createdByCol !== null) { $addVal($createdByCol, $actorUserPk); }
        if ($updatedByCol !== null) { $addVal($updatedByCol, $actorUserPk); }
        if ($isDeletedCol !== null) { $addRaw($isDeletedCol, '0'); }
        if ($createdAtCol !== null) { $addRaw($createdAtCol, 'GETDATE()'); }
        if ($updatedAtCol !== null) { $addRaw($updatedAtCol, 'GETDATE()'); }
        if ($regdateCol !== null) { $addRaw($regdateCol, 'GETDATE()'); }

        if ($columns === []) {
            return 0;
        }

        $stmt = $pdo->prepare('INSERT INTO Tb_UserSettings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $valuesSql) . ')');
        $stmt->execute($params);

        if ($idxCol === null) {
            return 0;
        }
        $findSql = 'SELECT TOP 1 ' . $qiFn($idxCol) . ' AS row_idx FROM Tb_UserSettings WHERE ' . implode(' AND ', $scopeWhere)
            . ' ORDER BY ' . $qiFn($idxCol) . ' DESC';
        $findStmt = $pdo->prepare($findSql);
        $findStmt->execute($scopeParams);
        $found = $findStmt->fetch(PDO::FETCH_ASSOC);
        return is_array($found) ? (int)($found['row_idx'] ?? 0) : 0;
    };

    $normalizeSiteStatus = static function (string $status): string {
        $value = trim($status);
        if ($value === '') {
            return '';
        }

        $map = [
            '예정' => '예정',
            '신규' => '예정',
            '진행' => '진행',
            '운영' => '진행',
            '중지' => '중지',
            '보류' => '중지',
            '완료' => '완료',
            '종료' => '완료',
        ];

        return $map[$value] ?? $value;
    };

    $normalizeEstimateStatus = static function (string $status): string {
        $value = strtoupper(trim($status));
        if ($value === '') {
            return 'DRAFT';
        }

        $map = [
            'DRAFT' => 'DRAFT',
            '임시' => 'DRAFT',
            'APPROVED' => 'APPROVED',
            '확정' => 'APPROVED',
            'CANCELLED' => 'CANCELLED',
            '취소' => 'CANCELLED',
        ];

        return $map[$value] ?? $value;
    };

    $estimateStatusTransitionAllowed = static function (string $fromStatus, string $toStatus): bool {
        $from = strtoupper(trim($fromStatus));
        $to = strtoupper(trim($toStatus));
        if ($from === '' || $to === '' || $from === $to) {
            return true;
        }

        $map = [
            'DRAFT' => ['APPROVED', 'CANCELLED'],
            'APPROVED' => ['CANCELLED'],
            'CANCELLED' => [],
        ];

        if (!isset($map[$from])) {
            return true;
        }

        return in_array($to, $map[$from], true);
    };

    $validateCoordinates = static function (array $src): void {
        if (array_key_exists('latitude', $src)) {
            FmsInputValidator::decimal((string)$src['latitude'], 'latitude', true, -90, 90);
        }
        if (array_key_exists('lat', $src)) {
            FmsInputValidator::decimal((string)$src['lat'], 'lat', true, -90, 90);
        }
        if (array_key_exists('longitude', $src)) {
            FmsInputValidator::decimal((string)$src['longitude'], 'longitude', true, -180, 180);
        }
        if (array_key_exists('lng', $src)) {
            FmsInputValidator::decimal((string)$src['lng'], 'lng', true, -180, 180);
        }
    };

    $parseIntAmount = static function (mixed $raw): int {
        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_float($raw)) {
            return max(0, (int)round($raw));
        }
        $text = trim((string)$raw);
        if ($text === '') {
            return 0;
        }
        $digits = preg_replace('/[^0-9\-]/', '', $text) ?? '0';
        if ($digits === '' || $digits === '-') {
            return 0;
        }
        return max(0, (int)$digits);
    };

    $normalizePublicUrl = static function (string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw) === 1) {
            return $raw;
        }
        if (str_starts_with($raw, '//')) {
            return 'https:' . $raw;
        }
        $path = str_replace('\\', '/', $raw);
        if ($path === '') {
            return '';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }
        return 'https://shvq.kr' . $path;
    };

    $extractUploadedFiles = static function (array $source): array {
        $files = [];
        foreach ($source as $field => $meta) {
            if (!is_array($meta)) {
                continue;
            }

            $names = $meta['name'] ?? null;
            $types = $meta['type'] ?? null;
            $tmps = $meta['tmp_name'] ?? null;
            $errors = $meta['error'] ?? null;
            $sizes = $meta['size'] ?? null;

            if (is_array($names)) {
                foreach ($names as $i => $name) {
                    $files[] = [
                        'field' => (string)$field,
                        'name' => (string)($name ?? ''),
                        'type' => is_array($types) ? (string)($types[$i] ?? '') : (string)$types,
                        'tmp_name' => is_array($tmps) ? (string)($tmps[$i] ?? '') : (string)$tmps,
                        'error' => is_array($errors) ? (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE) : (int)$errors,
                        'size' => is_array($sizes) ? (int)($sizes[$i] ?? 0) : (int)$sizes,
                    ];
                }
                continue;
            }

            $files[] = [
                'field' => (string)$field,
                'name' => (string)($names ?? ''),
                'type' => (string)($types ?? ''),
                'tmp_name' => (string)($tmps ?? ''),
                'error' => (int)($errors ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($sizes ?? 0),
            ];
        }
        return $files;
    };

    $loadFieldManagers = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        callable $firstColFn,
        int $siteIdx,
        int $memberIdx,
        string $serviceCode,
        int $tenantId
    ): array {
        if ($siteIdx <= 0 || !$tableExistsFn($pdo, 'Tb_Users_fieldManager')) {
            return [];
        }

        $table = 'Tb_Users_fieldManager';
        $idxCol = $firstColFn($pdo, $columnExistsFn, $table, ['idx', 'id']) ?? 'idx';
        $siteCol = $firstColFn($pdo, $columnExistsFn, $table, ['site_idx']);
        $memberCol = $firstColFn($pdo, $columnExistsFn, $table, ['member_idx']);
        $nameCol = $firstColFn($pdo, $columnExistsFn, $table, ['name', 'manager_name']);
        $telCol = $firstColFn($pdo, $columnExistsFn, $table, ['hp', 'tel', 'phone']);
        $emailCol = $firstColFn($pdo, $columnExistsFn, $table, ['email']);
        $memoCol = $firstColFn($pdo, $columnExistsFn, $table, ['comment', 'memo', 'note']);
        $createdCol = $firstColFn($pdo, $columnExistsFn, $table, ['created_at', 'regdate', 'registered_date']);

        $scope = [];
        $scopeParams = [];
        if ($siteCol !== null) {
            $scope[] = 'ISNULL(TRY_CONVERT(INT,fm.' . $siteCol . '),0)=?';
            $scopeParams[] = $siteIdx;
        }
        if ($memberCol !== null && $memberIdx > 0) {
            $scope[] = 'ISNULL(TRY_CONVERT(INT,fm.' . $memberCol . '),0)=?';
            $scopeParams[] = $memberIdx;
        }
        if ($scope === []) {
            return [];
        }

        $where = ['(' . implode(' OR ', $scope) . ')'];
        $params = $scopeParams;
        if ($columnExistsFn($pdo, $table, 'is_deleted')) {
            $where[] = 'ISNULL(fm.is_deleted,0)=0';
        }
        if ($columnExistsFn($pdo, $table, 'service_code')) {
            $where[] = "ISNULL(fm.service_code,'') = ?";
            $params[] = $serviceCode;
        }
        if ($columnExistsFn($pdo, $table, 'tenant_id')) {
            $where[] = 'ISNULL(fm.tenant_id,0)=?';
            $params[] = $tenantId;
        }

        $nameExpr = $nameCol !== null ? 'ISNULL(CAST(fm.' . $nameCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";
        $telExpr = $telCol !== null ? 'ISNULL(CAST(fm.' . $telCol . " AS NVARCHAR(60)),'')" : "CAST('' AS NVARCHAR(60))";
        $emailExpr = $emailCol !== null ? 'ISNULL(CAST(fm.' . $emailCol . " AS NVARCHAR(200)),'')" : "CAST('' AS NVARCHAR(200))";
        $memoExpr = $memoCol !== null ? 'ISNULL(CAST(fm.' . $memoCol . " AS NVARCHAR(2000)),'')" : "CAST('' AS NVARCHAR(2000))";
        $createdExpr = $createdCol !== null ? 'fm.' . $createdCol : 'NULL';

        $sql = "SELECT\n"
            . "  ISNULL(TRY_CONVERT(BIGINT,fm.{$idxCol}),0) AS idx,\n"
            . "  {$nameExpr} AS name,\n"
            . "  {$telExpr} AS tel,\n"
            . "  {$telExpr} AS phone,\n"
            . "  {$emailExpr} AS email,\n"
            . "  {$memoExpr} AS memo,\n"
            . "  {$createdExpr} AS created_at\n"
            . 'FROM ' . $table . ' fm WHERE ' . implode(' AND ', $where)
            . " ORDER BY ISNULL(TRY_CONVERT(DATETIME, {$createdExpr}), '1900-01-01') DESC, fm.{$idxCol} DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    };

    $loadSite = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        callable $firstColFn,
        int $siteIdx
    ) use (
        $siteNameCol,
        $siteNumberCol,
        $siteEmployeeCol,
        $siteEmployee1Col,
        $sitePhonebookCol,
        $siteRegionCol,
        $siteTargetTeamCol,
        $siteExternalEmployeeCol,
        $siteTotalQtyCol,
        $siteConstructionCol,
        $siteConstructionDateCol,
        $siteCompletionDateCol,
        $siteWarrantyCol
    ): ?array {
        if ($siteIdx <= 0) {
            return null;
        }

        $where = ['s.idx = ?'];
        if ($columnExistsFn($pdo, 'Tb_Site', 'is_deleted')) {
            $where[] = 'ISNULL(s.is_deleted,0)=0';
        }

        $joinMember = $tableExistsFn($pdo, 'Tb_Members') && $columnExistsFn($pdo, 'Tb_Site', 'member_idx')
            ? 'LEFT JOIN Tb_Members m ON s.member_idx = m.idx'
            : '';
        $memberExpr = $joinMember !== ''
            ? ( $columnExistsFn($pdo, 'Tb_Members', 'name') ? 'ISNULL(m.name, \'\')' : "CAST('' AS NVARCHAR(200))" )
            : "CAST('' AS NVARCHAR(200))";

        $employeeJoin = '';
        $employeeNameExpr = "ISNULL(s.manager_name, '')";
        if ($siteEmployeeCol !== null && $tableExistsFn($pdo, 'Tb_Employee')) {
            $empIdxCol = $firstColFn($pdo, $columnExistsFn, 'Tb_Employee', ['idx', 'id']);
            $empNameCol = $firstColFn($pdo, $columnExistsFn, 'Tb_Employee', ['name', 'employee_name']);
            if ($empIdxCol !== null && $empNameCol !== null) {
                $employeeJoin = " LEFT JOIN Tb_Employee e ON e.{$empIdxCol} = s.{$siteEmployeeCol}";
                if ($columnExistsFn($pdo, 'Tb_Employee', 'is_deleted')) {
                    $employeeJoin .= ' AND ISNULL(e.is_deleted,0)=0';
                }
                $employeeNameExpr = "ISNULL(CAST(e.{$empNameCol} AS NVARCHAR(120)), ISNULL(s.manager_name, ''))";
            }
        }

        $employee1Join = '';
        $employee1NameExpr = "CAST('' AS NVARCHAR(120))";
        if ($siteEmployee1Col !== null && $tableExistsFn($pdo, 'Tb_Employee')) {
            $empIdxCol = $firstColFn($pdo, $columnExistsFn, 'Tb_Employee', ['idx', 'id']);
            $empNameCol = $firstColFn($pdo, $columnExistsFn, 'Tb_Employee', ['name', 'employee_name']);
            if ($empIdxCol !== null && $empNameCol !== null) {
                $employee1Join = " LEFT JOIN Tb_Employee e1 ON e1.{$empIdxCol} = s.{$siteEmployee1Col}";
                if ($columnExistsFn($pdo, 'Tb_Employee', 'is_deleted')) {
                    $employee1Join .= ' AND ISNULL(e1.is_deleted,0)=0';
                }
                $employee1NameExpr = "ISNULL(CAST(e1.{$empNameCol} AS NVARCHAR(120)), '')";
            }
        }

        $phonebookJoin = '';
        $phonebookNameExpr = "CAST('' AS NVARCHAR(120))";
        if ($sitePhonebookCol !== null && $tableExistsFn($pdo, 'Tb_PhoneBook')) {
            $pbIdxCol = $firstColFn($pdo, $columnExistsFn, 'Tb_PhoneBook', ['idx', 'id']);
            $pbNameCol = $firstColFn($pdo, $columnExistsFn, 'Tb_PhoneBook', ['name']);
            if ($pbIdxCol !== null && $pbNameCol !== null) {
                $phonebookJoin = " LEFT JOIN Tb_PhoneBook pb ON pb.{$pbIdxCol} = s.{$sitePhonebookCol}";
                if ($columnExistsFn($pdo, 'Tb_PhoneBook', 'is_deleted')) {
                    $phonebookJoin .= ' AND ISNULL(pb.is_deleted,0)=0';
                }
                if ($columnExistsFn($pdo, 'Tb_PhoneBook', 'service_code') && $columnExistsFn($pdo, 'Tb_Site', 'service_code')) {
                    $phonebookJoin .= " AND ISNULL(CAST(pb.service_code AS NVARCHAR(50)),'')=ISNULL(CAST(s.service_code AS NVARCHAR(50)),'')";
                }
                if ($columnExistsFn($pdo, 'Tb_PhoneBook', 'tenant_id') && $columnExistsFn($pdo, 'Tb_Site', 'tenant_id')) {
                    $phonebookJoin .= ' AND ISNULL(TRY_CONVERT(INT,pb.tenant_id),0)=ISNULL(TRY_CONVERT(INT,s.tenant_id),0)';
                }
                $phonebookNameExpr = "ISNULL(CAST(pb.{$pbNameCol} AS NVARCHAR(120)), '')";
            }
        } elseif ($tableExistsFn($pdo, 'Tb_PhoneBook') && $columnExistsFn($pdo, 'Tb_Site', 'member_idx')) {
            $pbNameCol = $firstColFn($pdo, $columnExistsFn, 'Tb_PhoneBook', ['name']);
            if ($pbNameCol !== null && $columnExistsFn($pdo, 'Tb_PhoneBook', 'member_idx')) {
                $pbWhere = ['ISNULL(TRY_CONVERT(INT,pb.member_idx),0)=ISNULL(TRY_CONVERT(INT,s.member_idx),0)'];
                if ($columnExistsFn($pdo, 'Tb_PhoneBook', 'is_deleted')) {
                    $pbWhere[] = 'ISNULL(pb.is_deleted,0)=0';
                }
                if ($columnExistsFn($pdo, 'Tb_PhoneBook', 'service_code') && $columnExistsFn($pdo, 'Tb_Site', 'service_code')) {
                    $pbWhere[] = "ISNULL(CAST(pb.service_code AS NVARCHAR(50)),'')=ISNULL(CAST(s.service_code AS NVARCHAR(50)),'')";
                }
                if ($columnExistsFn($pdo, 'Tb_PhoneBook', 'tenant_id') && $columnExistsFn($pdo, 'Tb_Site', 'tenant_id')) {
                    $pbWhere[] = 'ISNULL(TRY_CONVERT(INT,pb.tenant_id),0)=ISNULL(TRY_CONVERT(INT,s.tenant_id),0)';
                }
                $phonebookNameExpr = '(SELECT TOP 1 ISNULL(CAST(pb.' . $pbNameCol . " AS NVARCHAR(120)), '') FROM Tb_PhoneBook pb WHERE " . implode(' AND ', $pbWhere) . ')';
            }
        }

        $siteNumberExpr = $siteNumberCol !== null
            ? "ISNULL(CAST(s.{$siteNumberCol} AS NVARCHAR(60)), '')"
            : "CAST('' AS NVARCHAR(60))";
        $employee1IdxExpr = $siteEmployee1Col !== null ? 'ISNULL(TRY_CONVERT(INT,s.' . $siteEmployee1Col . '),0)' : 'CAST(0 AS INT)';
        $phonebookIdxExpr = $sitePhonebookCol !== null ? 'ISNULL(TRY_CONVERT(INT,s.' . $sitePhonebookCol . '),0)' : 'CAST(0 AS INT)';
        $regionExpr = $siteRegionCol !== null ? 'ISNULL(CAST(s.' . $siteRegionCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";
        $targetTeamExpr = $siteTargetTeamCol !== null
            ? "ISNULL(CAST(s.{$siteTargetTeamCol} AS NVARCHAR(120)), '')"
            : "CAST('' AS NVARCHAR(120))";
        $externalEmployeeExpr = $siteExternalEmployeeCol !== null
            ? "ISNULL(TRY_CONVERT(INT, s.{$siteExternalEmployeeCol}), 0)"
            : ($siteTotalQtyCol !== null ? "ISNULL(TRY_CONVERT(INT, s.{$siteTotalQtyCol}), 0)" : 'CAST(0 AS INT)');
        $totalQtyExpr = $siteTotalQtyCol !== null
            ? "ISNULL(TRY_CONVERT(INT, s.{$siteTotalQtyCol}), 0)"
            : ($siteExternalEmployeeCol !== null ? "ISNULL(TRY_CONVERT(INT, s.{$siteExternalEmployeeCol}), 0)" : 'CAST(0 AS INT)');
        $constructionExpr = $siteConstructionCol !== null
            ? "ISNULL(CAST(s.{$siteConstructionCol} AS NVARCHAR(255)), '')"
            : "CAST('' AS NVARCHAR(255))";
        $constructionDateExpr = $siteConstructionDateCol !== null ? 's.' . $siteConstructionDateCol : 'NULL';
        $completionDateExpr = $siteCompletionDateCol !== null ? 's.' . $siteCompletionDateCol : 'NULL';
        $warrantyExpr = $siteWarrantyCol !== null ? 'ISNULL(TRY_CONVERT(INT, s.' . $siteWarrantyCol . '), 0)' : 'CAST(0 AS INT)';

        $sql = 'SELECT s.*, ISNULL(s.' . $siteNameCol . ", '') AS site_display_name, {$memberExpr} AS member_name,\n"
            . "       {$siteNumberExpr} AS site_number, {$employeeNameExpr} AS employee_name,\n"
            . "       {$employee1IdxExpr} AS employee1_idx, {$employee1NameExpr} AS employee1_name,\n"
            . "       {$phonebookIdxExpr} AS phonebook_idx, {$phonebookNameExpr} AS phonebook_name,\n"
            . "       {$regionExpr} AS region,\n"
            . "       {$targetTeamExpr} AS target_team, {$externalEmployeeExpr} AS external_employee,\n"
            . "       {$totalQtyExpr} AS total_qty, {$constructionExpr} AS construction,\n"
            . "       {$constructionDateExpr} AS construction_date, {$completionDateExpr} AS completion_date,\n"
            . "       {$warrantyExpr} AS warranty_period\n"
            . 'FROM Tb_Site s ' . $joinMember . $employeeJoin . $employee1Join . $phonebookJoin . ' WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$siteIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

    $loadEstimate = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, callable $firstColFn, int $estimateIdx): ?array {
        if ($estimateIdx <= 0 || !$tableExistsFn($pdo, 'Tb_SiteEstimate')) {
            return null;
        }

        $where = ['e.idx = ?'];
        if ($columnExistsFn($pdo, 'Tb_SiteEstimate', 'is_deleted')) {
            $where[] = 'ISNULL(e.is_deleted,0)=0';
        }

        $stmt = $pdo->prepare('SELECT e.* FROM Tb_SiteEstimate e WHERE ' . implode(' AND ', $where));
        $stmt->execute([$estimateIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $row['items'] = [];
        if ($tableExistsFn($pdo, 'Tb_EstimateItem')) {
            $fk = $firstColFn($pdo, $columnExistsFn, 'Tb_EstimateItem', ['site_estimate_idx', 'estimate_idx', 'est_idx', 'site_est_idx', 'site_estimate_id']);
            if ($fk !== null) {
                $itemWhere = ["i.{$fk} = ?"];
                if ($columnExistsFn($pdo, 'Tb_EstimateItem', 'is_deleted')) {
                    $itemWhere[] = 'ISNULL(i.is_deleted,0)=0';
                }

                $order = $columnExistsFn($pdo, 'Tb_EstimateItem', 'sort_no') ? 'ISNULL(i.sort_no,0), i.idx' : 'i.idx';
                $itemStmt = $pdo->prepare('SELECT i.* FROM Tb_EstimateItem i WHERE ' . implode(' AND ', $itemWhere) . ' ORDER BY ' . $order . ' ASC');
                $itemStmt->execute([$estimateIdx]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                $row['items'] = is_array($items) ? $items : [];
            }
        }

        return $row;
    };

    $loadEstimateAttachments = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        callable $firstColFn,
        callable $normalizePublicUrlFn,
        array $ctx,
        int $estimateIdx
    ): array {
        if ($estimateIdx <= 0 || !$tableExistsFn($pdo, 'Tb_FileAttach')) {
            return [];
        }

        $table = 'Tb_FileAttach';
        $idxCol = $firstColFn($pdo, $columnExistsFn, $table, ['idx', 'id']) ?? 'idx';
        $tableNameCol = $firstColFn($pdo, $columnExistsFn, $table, ['table_name', 'to_table', 'target_table']);
        $linkCol = $firstColFn($pdo, $columnExistsFn, $table, ['table_idx', 'ref_idx', 'target_idx', 'parent_idx', 'estimate_idx']);
        $categoryCol = $firstColFn($pdo, $columnExistsFn, $table, ['category', 'subject', 'type', 'item_name']);
        $originalNameCol = $firstColFn($pdo, $columnExistsFn, $table, ['original_name', 'origin_name', 'file_name', 'name']);
        $filenameCol = $firstColFn($pdo, $columnExistsFn, $table, ['filename', 'save_name', 'stored_name', 'file_name']);
        $urlCol = $firstColFn($pdo, $columnExistsFn, $table, ['file_url', 'url', 'file_path', 'path']);
        $sizeCol = $firstColFn($pdo, $columnExistsFn, $table, ['file_size', 'size']);
        $createdCol = $firstColFn($pdo, $columnExistsFn, $table, ['created_at', 'regdate', 'registered_date', 'insert_date']);

        $where = [];
        $params = [];
        if ($columnExistsFn($pdo, $table, 'is_deleted')) {
            $where[] = 'ISNULL(f.is_deleted,0)=0';
        }
        if ($columnExistsFn($pdo, $table, 'service_code')) {
            $where[] = "ISNULL(f.service_code,'')=?";
            $params[] = (string)($ctx['service_code'] ?? 'shvq');
        }
        if ($columnExistsFn($pdo, $table, 'tenant_id')) {
            $where[] = 'ISNULL(f.tenant_id,0)=?';
            $params[] = (int)($ctx['tenant_id'] ?? 0);
        }

        $scope = [];
        $scopeParams = [];
        if ($tableNameCol !== null && $linkCol !== null) {
            $scope[] = "(LOWER(CAST(f.{$tableNameCol} AS NVARCHAR(100))) IN ('tb_siteestimate','tb_site_estimate','tb_estimate','siteestimate','estimate') AND ISNULL(TRY_CONVERT(INT,f.{$linkCol}),0)=?)";
            $scopeParams[] = $estimateIdx;
        }
        if ($columnExistsFn($pdo, $table, 'estimate_idx')) {
            $scope[] = 'ISNULL(TRY_CONVERT(INT,f.estimate_idx),0)=?';
            $scopeParams[] = $estimateIdx;
        }
        if ($columnExistsFn($pdo, $table, 'site_estimate_idx')) {
            $scope[] = 'ISNULL(TRY_CONVERT(INT,f.site_estimate_idx),0)=?';
            $scopeParams[] = $estimateIdx;
        }

        if ($scope === []) {
            return [];
        }
        $where[] = '(' . implode(' OR ', $scope) . ')';

        $originalExpr = $originalNameCol !== null ? 'ISNULL(CAST(f.' . $originalNameCol . " AS NVARCHAR(255)),'')" : "CAST('' AS NVARCHAR(255))";
        $filenameExpr = $filenameCol !== null ? 'ISNULL(CAST(f.' . $filenameCol . " AS NVARCHAR(255)),'')" : $originalExpr;
        $urlExpr = $urlCol !== null ? 'ISNULL(CAST(f.' . $urlCol . " AS NVARCHAR(1200)),'')" : "CAST('' AS NVARCHAR(1200))";
        $sizeExpr = $sizeCol !== null ? 'ISNULL(TRY_CONVERT(BIGINT,f.' . $sizeCol . '),0)' : 'CAST(0 AS BIGINT)';
        $createdExpr = $createdCol !== null ? 'f.' . $createdCol : 'NULL';
        $categoryExpr = $categoryCol !== null ? 'ISNULL(CAST(f.' . $categoryCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";

        $sql = "SELECT\n"
            . "  ISNULL(TRY_CONVERT(BIGINT,f.{$idxCol}),0) AS idx,\n"
            . "  {$categoryExpr} AS category,\n"
            . "  {$originalExpr} AS original_name,\n"
            . "  {$filenameExpr} AS filename,\n"
            . "  {$urlExpr} AS file_url,\n"
            . "  {$sizeExpr} AS file_size,\n"
            . "  {$createdExpr} AS created_at\n"
            . ' FROM ' . $table . ' f WHERE ' . implode(' AND ', $where)
            . " ORDER BY ISNULL(TRY_CONVERT(DATETIME, {$createdExpr}), '1900-01-01') DESC, f.{$idxCol} DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $scopeParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['file_url'] = $normalizePublicUrlFn((string)($row['file_url'] ?? ''));
        }
        unset($row);

        return $rows;
    };

    $loadSiteEstimateIds = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, callable $firstColFn, int $siteIdx): array {
        if ($siteIdx <= 0 || !$tableExistsFn($pdo, 'Tb_SiteEstimate')) {
            return [];
        }

        $siteFk = $firstColFn($pdo, $columnExistsFn, 'Tb_SiteEstimate', ['site_idx', 'idx_site']);
        if ($siteFk === null) {
            return [];
        }

        $where = ["{$siteFk} = ?"];
        $params = [$siteIdx];
        if ($columnExistsFn($pdo, 'Tb_SiteEstimate', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $stmt = $pdo->prepare('SELECT idx FROM Tb_SiteEstimate WHERE ' . implode(' AND ', $where) . ' ORDER BY idx DESC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $idx = (int)($row['idx'] ?? 0);
            if ($idx > 0) {
                $ids[] = $idx;
            }
        }
        return $ids;
    };

    $buildDetailRows = static function (array $defs): array {
        $rows = [];
        foreach ($defs as $def) {
            if (!is_array($def) || count($def) < 3) {
                continue;
            }

            $key = trim((string)($def[0] ?? ''));
            $label = trim((string)($def[1] ?? ''));
            $rawValue = $def[2] ?? null;
            $type = trim((string)($def[3] ?? 'text'));

            if ($key === '' || $label === '' || $rawValue === null) {
                continue;
            }

            $value = is_string($rawValue) ? trim($rawValue) : trim((string)$rawValue);
            if ($value === '') {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'value' => $value,
                'type' => $type !== '' ? $type : 'text',
            ];
        }

        return $rows;
    };

    $buildSiteDetailSections = static function (array $site) use ($buildDetailRows): array {
        $siteName = (string)($site['site_display_name'] ?? $site['name'] ?? $site['site_name'] ?? '');
        $address = trim((string)($site['address'] ?? ''));
        $addressDetail = trim((string)($site['address_detail'] ?? ''));
        $addressCombined = trim($address . ($addressDetail !== '' ? ' ' . $addressDetail : ''));

        $sections = [];

        $basicRows = $buildDetailRows([
            ['site_name', '현장명', $siteName],
            ['member_name', '사업장', $site['member_name'] ?? ''],
            ['site_status', '상태', $site['site_status'] ?? $site['status'] ?? ''],
            ['manager_name', '담당자', $site['manager_name'] ?? ''],
            ['manager_tel', '전화번호', $site['manager_tel'] ?? '', 'phone'],
        ]);
        if ($basicRows !== []) {
            $sections[] = [
                'key' => 'basic',
                'title' => '기본정보',
                'rows' => $basicRows,
            ];
        }

        $periodRows = $buildDetailRows([
            ['start_date', '착공일', $site['start_date'] ?? $site['construction_date'] ?? '', 'date'],
            ['end_date', '준공일', $site['end_date'] ?? $site['completion_date'] ?? '', 'date'],
            ['registered_date', '등록일', $site['registered_date'] ?? $site['regdate'] ?? $site['created_at'] ?? '', 'date'],
        ]);
        if ($periodRows !== []) {
            $sections[] = [
                'key' => 'period',
                'title' => '일정',
                'rows' => $periodRows,
            ];
        }

        $addressRows = $buildDetailRows([
            ['address', '주소', $addressCombined],
            ['zipcode', '우편번호', $site['zipcode'] ?? ''],
            ['memo', '메모', $site['memo'] ?? '', 'memo'],
        ]);
        if ($addressRows !== []) {
            $sections[] = [
                'key' => 'address',
                'title' => '주소/메모',
                'rows' => $addressRows,
            ];
        }

        return $sections;
    };

    $insertEstimateItems = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, callable $firstColFn, int $estimateIdx, int $siteIdx, array $items, int $actorUserPk): int {
        if ($estimateIdx <= 0 || $items === [] || !$tableExistsFn($pdo, 'Tb_EstimateItem')) {
            return 0;
        }

        $fk = $firstColFn($pdo, $columnExistsFn, 'Tb_EstimateItem', ['site_estimate_idx', 'estimate_idx', 'est_idx', 'site_est_idx', 'site_estimate_id']);
        if ($fk === null) {
            return 0;
        }

        $pick = static function (array $row, array $keys): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $row)) {
                    return $row[$key];
                }
            }
            return null;
        };

        $inserted = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $columns = [$fk];
            $values = [$estimateIdx];

            if ($columnExistsFn($pdo, 'Tb_EstimateItem', 'site_idx')) {
                $columns[] = 'site_idx';
                $values[] = $siteIdx;
            }

            $map = [
                'item_idx' => ['item_idx', 'idx', 'material_idx'],
                'item_name' => ['item_name', 'name'],
                'name' => ['name', 'item_name'],
                'spec' => ['spec', 'standard'],
                'unit' => ['unit'],
                'qty' => ['qty', 'count', 'quantity'],
                'unit_price' => ['unit_price', 'price', 'cost'],
                'supply_amount' => ['supply_amount', 'supply_price'],
                'vat_amount' => ['vat_amount', 'vat'],
                'total_amount' => ['total_amount', 'amount'],
                'currency_code' => ['currency_code', 'currency'],
                'tax_rate' => ['tax_rate'],
                'memo' => ['memo', 'note'],
                'sort_no' => ['sort_no', 'sort_order', 'ord'],
            ];

            foreach ($map as $column => $aliases) {
                if (!$columnExistsFn($pdo, 'Tb_EstimateItem', $column)) {
                    continue;
                }
                $raw = $pick($item, $aliases);
                if ($raw === null) {
                    continue;
                }
                $columns[] = $column;
                $values[] = is_string($raw) ? trim($raw) : $raw;
            }

            if ($columnExistsFn($pdo, 'Tb_EstimateItem', 'is_deleted')) { $columns[] = 'is_deleted'; $values[] = 0; }
            if ($columnExistsFn($pdo, 'Tb_EstimateItem', 'created_by')) { $columns[] = 'created_by'; $values[] = $actorUserPk; }
            if ($columnExistsFn($pdo, 'Tb_EstimateItem', 'created_at')) { $columns[] = 'created_at'; $values[] = date('Y-m-d H:i:s'); }
            if ($columnExistsFn($pdo, 'Tb_EstimateItem', 'regdate')) { $columns[] = 'regdate'; $values[] = date('Y-m-d H:i:s'); }

            $ph = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $pdo->prepare('INSERT INTO Tb_EstimateItem (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
            $stmt->execute($values);
            $inserted++;
        }

        return $inserted;
    };

    $deleteEstimateItemsByEstimateIds = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, callable $firstColFn, array $estimateIds, int $actorUserPk): int {
        if ($estimateIds === [] || !$tableExistsFn($pdo, 'Tb_EstimateItem')) {
            return 0;
        }

        $fk = $firstColFn($pdo, $columnExistsFn, 'Tb_EstimateItem', ['site_estimate_idx', 'estimate_idx', 'est_idx', 'site_est_idx', 'site_estimate_id']);
        if ($fk === null) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($estimateIds), '?'));

        if ($columnExistsFn($pdo, 'Tb_EstimateItem', 'is_deleted')) {
            $setSql = ['is_deleted = 1'];
            $params = [];
            if ($columnExistsFn($pdo, 'Tb_EstimateItem', 'deleted_at')) { $setSql[] = 'deleted_at = GETDATE()'; }
            if ($columnExistsFn($pdo, 'Tb_EstimateItem', 'deleted_by')) { $setSql[] = 'deleted_by = ?'; $params[] = $actorUserPk; }
            $params = array_merge($params, $estimateIds);
            $stmt = $pdo->prepare('UPDATE Tb_EstimateItem SET ' . implode(', ', $setSql) . " WHERE {$fk} IN ({$ph})");
            $stmt->execute($params);
            return (int)$stmt->rowCount();
        }

        $stmt = $pdo->prepare("DELETE FROM Tb_EstimateItem WHERE {$fk} IN ({$ph})");
        $stmt->execute($estimateIds);
        return (int)$stmt->rowCount();
    };

    $nextEstimateNo = static function (PDO $pdo, callable $columnExistsFn): string {
        $prefix = 'EST-' . date('ymd');
        if (!$columnExistsFn($pdo, 'Tb_SiteEstimate', 'estimate_no')) {
            return $prefix . '-0001';
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM Tb_SiteEstimate WHERE estimate_no LIKE ?');
        $stmt->execute([$prefix . '-%']);
        $seq = (int)$stmt->fetchColumn() + 1;
        return $prefix . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    };

    $recalcEstimateTotals = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, callable $firstColFn, int $estimateIdx): array {
        if ($estimateIdx <= 0 || !$tableExistsFn($pdo, 'Tb_SiteEstimate')) {
            return ['supply_amount' => 0.0, 'vat_amount' => 0.0, 'total_amount' => 0.0, 'item_count' => 0];
        }

        $items = [];
        if ($tableExistsFn($pdo, 'Tb_EstimateItem')) {
            $fk = $firstColFn($pdo, $columnExistsFn, 'Tb_EstimateItem', ['site_estimate_idx', 'estimate_idx', 'est_idx', 'site_est_idx', 'site_estimate_id']);
            if ($fk !== null) {
                $where = ["{$fk} = ?"];
                if ($columnExistsFn($pdo, 'Tb_EstimateItem', 'is_deleted')) {
                    $where[] = 'ISNULL(is_deleted,0)=0';
                }
                $stmt = $pdo->prepare('SELECT * FROM Tb_EstimateItem WHERE ' . implode(' AND ', $where));
                $stmt->execute([$estimateIdx]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $items = is_array($rows) ? $rows : [];
            }
        }

        $supply = 0.0;
        $vat = 0.0;
        $total = 0.0;

        foreach ($items as $item) {
            $qty = (float)($item['qty'] ?? $item['count'] ?? 0);
            $price = (float)($item['unit_price'] ?? $item['price'] ?? 0);
            $lineSupply = (float)($item['supply_amount'] ?? ($qty * $price));
            $lineVat = (float)($item['vat_amount'] ?? ($lineSupply * 0.1));
            $lineTotal = (float)($item['total_amount'] ?? ($lineSupply + $lineVat));

            $supply += $lineSupply;
            $vat += $lineVat;
            $total += $lineTotal;
        }

        $setSql = [];
        $params = [];
        if ($columnExistsFn($pdo, 'Tb_SiteEstimate', 'supply_amount')) { $setSql[] = 'supply_amount = ?'; $params[] = $supply; }
        if ($columnExistsFn($pdo, 'Tb_SiteEstimate', 'vat_amount')) { $setSql[] = 'vat_amount = ?'; $params[] = $vat; }
        if ($columnExistsFn($pdo, 'Tb_SiteEstimate', 'total_amount')) { $setSql[] = 'total_amount = ?'; $params[] = $total; }

        if ($setSql !== []) {
            $params[] = $estimateIdx;
            $stmt = $pdo->prepare('UPDATE Tb_SiteEstimate SET ' . implode(', ', $setSql) . ' WHERE idx = ?');
            $stmt->execute($params);
        }

        return [
            'supply_amount' => $supply,
            'vat_amount' => $vat,
            'total_amount' => $total,
            'item_count' => count($items),
        ];
    };

    $bumpEstimateVersion = static function (PDO $pdo, callable $columnExistsFn, int $estimateIdx): int {
        if ($estimateIdx <= 0 || !$columnExistsFn($pdo, 'Tb_SiteEstimate', 'version_no')) {
            return 0;
        }

        $stmt = $pdo->prepare('UPDATE Tb_SiteEstimate SET version_no = ISNULL(version_no, 0) + 1 WHERE idx = ?');
        $stmt->execute([$estimateIdx]);

        $readStmt = $pdo->prepare('SELECT ISNULL(version_no,0) FROM Tb_SiteEstimate WHERE idx = ?');
        $readStmt->execute([$estimateIdx]);
        return (int)$readStmt->fetchColumn();
    };

    $writeSvcAuditLog = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $todoName, array $payload): void {
        if (!$tableExistsFn($pdo, 'Tb_SvcAuditLog')) {
            return;
        }

        $columns = [];
        $values = [];
        $add = static function (string $column, mixed $value) use (&$columns, &$values, $pdo, $columnExistsFn): void {
            if ($columnExistsFn($pdo, 'Tb_SvcAuditLog', $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        };

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }

        $add('service_code', (string)($payload['service_code'] ?? 'shvq'));
        $add('tenant_id', (int)($payload['tenant_id'] ?? 0));
        $add('api_name', 'Site.php');
        $add('todo', $todoName);
        $add('target_table', (string)($payload['target_table'] ?? 'Tb_Site'));
        $add('target_idx', (int)($payload['target_idx'] ?? 0));
        $add('actor_user_pk', (int)($payload['actor_user_pk'] ?? 0));
        $add('actor_login_id', (string)($payload['actor_login_id'] ?? ''));
        $add('status', 'SUCCESS');
        $add('message', (string)($payload['message'] ?? 'site api write'));
        $add('detail_json', $json);
        $add('created_at', date('Y-m-d H:i:s'));
        $add('regdate', date('Y-m-d H:i:s'));

        if ($columns === []) {
            return;
        }

        $ph = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare('INSERT INTO Tb_SvcAuditLog (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
        $stmt->execute($values);
    };

    $enqueueShadow = static function (PDO $pdo, array $securityCfg, array $ctx, string $todoName, string $targetTable, int $targetIdx, array $payload = []): int {
        try {
            $svc = new ShadowWriteQueueService($pdo, $securityCfg);
            return $svc->enqueueJob([
                'service_code' => (string)($ctx['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($ctx['tenant_id'] ?? 0),
                'api' => 'dist_process/saas/Site.php',
                'todo' => $todoName,
                'target_table' => $targetTable,
                'target_idx' => $targetIdx,
                'actor_user_pk' => (int)($ctx['user_pk'] ?? 0),
                'actor_login_id' => (string)($ctx['login_id'] ?? ''),
                'requested_at' => date('c'),
                'payload' => $payload,
            ], 'PENDING', 0);
        } catch (Throwable) {
            return 0;
        }
    };

    $roleLevel = (int)($context['role_level'] ?? 0);

    $writeTodos = [
        'insert', 'update', 'delete',
        'insert_est', 'update_est', 'delete_estimate',
        'copy_est', 'recalc_est', 'upsert_est_items', 'update_est_item', 'delete_est_item', 'approve_est',
        'insert_bill', 'bill_insert',
        'update_bill', 'bill_update',
        'delete_bill', 'bill_delete',
        'deposit_bill', 'bill_deposit',
        'upload_attach',
        'delete_attach',
        'insert_floor_plan', 'floor_plan_insert',
        'delete_floor_plan', 'floor_plan_delete',
        'insert_subcontract', 'subcontract_insert',
        'update_subcontract', 'subcontract_update',
        'delete_subcontract', 'subcontract_delete',
        'insert_access_log', 'access_log_insert',
        'delete_access_log', 'access_log_delete',
        'toggle_contact_hidden',
        'delete_contact', 'contact_delete',
        'save_site_settings',
        'excel_upload',
        'insert_bill_comment',
    ];

    if (in_array($todo, $writeTodos, true)) {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }

        if (!$auth->validateCsrf()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', '보안 검증 실패', 403);
            exit;
        }

        if ($roleLevel < 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
    }

    if ($todo === 'list') {
        $search = trim((string)($_GET['search'] ?? ''));
        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));
        $includeDeleted = FmsInputValidator::bool($_GET, 'include_deleted', false);

        $sortRaw = strtolower(trim((string)($_GET['sort'] ?? 'idx')));
        $orderRaw = strtolower(trim((string)($_GET['order'] ?? 'desc')));
        $orderExpr = $orderRaw === 'asc' ? 'ASC' : 'DESC';

        $sortMap = [
            'idx' => 's.idx',
            'name' => 's.' . $siteNameCol,
            'status' => 's.' . $siteStatusCol,
        ];
        if ($siteNumberCol !== null) { $sortMap['site_number'] = 's.' . $siteNumberCol; }
        if ($siteConstructionDateCol !== null) { $sortMap['construction_date'] = 's.' . $siteConstructionDateCol; }
        if ($siteCompletionDateCol !== null) { $sortMap['completion_date'] = 's.' . $siteCompletionDateCol; }
        if ($siteRegisteredDateCol !== null) { $sortMap['registered_date'] = 's.' . $siteRegisteredDateCol; }
        $sortExpr = $sortMap[$sortRaw] ?? 's.idx';

        $where = ['1=1'];
        $params = [];

        if (!$includeDeleted && $columnExists($db, 'Tb_Site', 'is_deleted')) {
            $where[] = 'ISNULL(s.is_deleted,0)=0';
        }

        if ($search !== '') {
            $sp = '%' . $search . '%';
            $searchConds = ['s.' . $siteNameCol . ' LIKE ?', 's.address LIKE ?', 'ISNULL(m.name, \'\') LIKE ?'];
            $searchParams = [$sp, $sp, $sp];
            if ($siteNumberCol !== null) {
                $searchConds[] = 'ISNULL(CAST(s.' . $siteNumberCol . ' AS NVARCHAR(60)), \'\') LIKE ?';
                $searchParams[] = $sp;
            }
            if ($siteConstructionCol !== null) {
                $searchConds[] = 'ISNULL(CAST(s.' . $siteConstructionCol . ' AS NVARCHAR(255)), \'\') LIKE ?';
                $searchParams[] = $sp;
            }
            if ($siteTargetTeamCol !== null) {
                $searchConds[] = 'ISNULL(CAST(s.' . $siteTargetTeamCol . ' AS NVARCHAR(120)), \'\') LIKE ?';
                $searchParams[] = $sp;
            }
            $where[] = '(' . implode(' OR ', $searchConds) . ')';
            array_push($params, ...$searchParams);
        }

        $memberIdx = FmsInputValidator::int($_GET, 'member_idx', false, 1);
        if ($memberIdx !== null && $columnExists($db, 'Tb_Site', 'member_idx')) {
            $where[] = 's.member_idx = ?';
            $params[] = $memberIdx;
        }

        $employeeMode = strtolower(trim((string)($_GET['employee'] ?? '')));
        $employeeIdxFilter = FmsInputValidator::int($_GET, 'employee_idx', false, 1);
        if (($employeeMode !== '' || $employeeIdxFilter !== null) && $siteEmployeeCol !== null) {
            $targetEmployeeIdx = 0;
            if ($employeeMode === 'me') {
                $targetEmployeeIdx = $resolveContextEmployeeIdx($db, $tableExists, $columnExists, $context);
                if ($targetEmployeeIdx <= 0) {
                    $targetEmployeeIdx = (int)($context['user_pk'] ?? 0);
                }
            } elseif ($employeeIdxFilter !== null) {
                $targetEmployeeIdx = (int)$employeeIdxFilter;
            } elseif (ctype_digit($employeeMode)) {
                $targetEmployeeIdx = (int)$employeeMode;
            }

            if ($targetEmployeeIdx > 0) {
                $where[] = 'ISNULL(s.' . $siteEmployeeCol . ',0) = ?';
                $params[] = $targetEmployeeIdx;
            } else {
                $where[] = '1=0';
            }
        }

        $statusFilter = trim((string)($_GET['site_status'] ?? $_GET['status'] ?? ''));
        if ($statusFilter !== '' && $columnExists($db, 'Tb_Site', $siteStatusCol)) {
            $statusFilter = $normalizeSiteStatus($statusFilter);
            $where[] = 's.' . $siteStatusCol . ' = ?';
            $params[] = $statusFilter;
        }

        $qtyRangeRaw = trim((string)($_GET['qty_range'] ?? ''));
        $qtyFilterCol = $siteTotalQtyCol ?? $siteExternalEmployeeCol;
        if ($qtyRangeRaw !== '' && $qtyFilterCol !== null && $columnExists($db, 'Tb_Site', $qtyFilterCol)) {
            $qtyRange = strtolower(str_replace(' ', '', $qtyRangeRaw));
            if ($qtyRange === '1-10' || $qtyRange === '1~10') {
                $where[] = 'ISNULL(TRY_CONVERT(INT,s.' . $qtyFilterCol . '),0) BETWEEN 1 AND 10';
            } elseif ($qtyRange === '11+' || $qtyRange === '11이상' || $qtyRange === '11up') {
                $where[] = 'ISNULL(TRY_CONVERT(INT,s.' . $qtyFilterCol . '),0) >= 11';
            } elseif (preg_match('/^(\d+)\-(\d+)$/', $qtyRange, $m) === 1) {
                $fromQty = (int)$m[1];
                $toQty = (int)$m[2];
                if ($fromQty > $toQty) {
                    [$fromQty, $toQty] = [$toQty, $fromQty];
                }
                $where[] = 'ISNULL(TRY_CONVERT(INT,s.' . $qtyFilterCol . '),0) BETWEEN ? AND ?';
                $params[] = $fromQty;
                $params[] = $toQty;
            } elseif (preg_match('/^(\d+)\+$/', $qtyRange, $m) === 1) {
                $where[] = 'ISNULL(TRY_CONVERT(INT,s.' . $qtyFilterCol . '),0) >= ?';
                $params[] = (int)$m[1];
            }
        }

        $dateTypeRaw = strtolower(trim((string)($_GET['date_type'] ?? '')));
        $dateStart = trim((string)($_GET['date_s'] ?? ''));
        $dateEnd = trim((string)($_GET['date_e'] ?? ''));
        $dateCol = null;
        if ($dateTypeRaw === 'construction_date') {
            $dateCol = $siteConstructionDateCol;
        } elseif ($dateTypeRaw === 'completion_date') {
            $dateCol = $siteCompletionDateCol;
        } elseif ($dateTypeRaw === 'registered_date') {
            $dateCol = $siteRegisteredDateCol;
        }
        if ($dateCol !== null) {
            if ($dateStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart) === 1) {
                $where[] = 'TRY_CONVERT(DATE, s.' . $dateCol . ') >= ?';
                $params[] = $dateStart;
            }
            if ($dateEnd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd) === 1) {
                $where[] = 'TRY_CONVERT(DATE, s.' . $dateCol . ') <= ?';
                $params[] = $dateEnd;
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $joinMember = $tableExists($db, 'Tb_Members') && $columnExists($db, 'Tb_Site', 'member_idx')
            ? 'LEFT JOIN Tb_Members m ON s.member_idx = m.idx'
            : 'LEFT JOIN (SELECT CAST(NULL AS INT) AS idx, CAST(NULL AS NVARCHAR(1)) AS name) m ON 1=0';

        $employeeJoin = '';
        $employeeNameExpr = "ISNULL(s.manager_name, '')";
        if ($siteEmployeeCol !== null && $tableExists($db, 'Tb_Employee')) {
            $employeeIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['idx', 'id']);
            $employeeNameCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['name', 'employee_name']);
            if ($employeeIdxCol !== null && $employeeNameCol !== null) {
                $employeeJoin = " LEFT JOIN Tb_Employee e ON e.{$employeeIdxCol} = s.{$siteEmployeeCol}";
                if ($columnExists($db, 'Tb_Employee', 'is_deleted')) {
                    $employeeJoin .= ' AND ISNULL(e.is_deleted,0)=0';
                }
                $employeeNameExpr = "ISNULL(CAST(e.{$employeeNameCol} AS NVARCHAR(120)), ISNULL(s.manager_name, ''))";
            }
        }

        $phonebookJoin = '';
        $phonebookNameExpr = "CAST('' AS NVARCHAR(120))";
        if ($sitePhonebookCol !== null && $tableExists($db, 'Tb_PhoneBook')) {
            $pbIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_PhoneBook', ['idx', 'id']);
            $pbNameCol = $firstExistingColumn($db, $columnExists, 'Tb_PhoneBook', ['name']);
            if ($pbIdxCol !== null && $pbNameCol !== null) {
                $phonebookJoin = " LEFT JOIN Tb_PhoneBook pb ON pb.{$pbIdxCol} = s.{$sitePhonebookCol}";
                if ($columnExists($db, 'Tb_PhoneBook', 'is_deleted')) {
                    $phonebookJoin .= ' AND ISNULL(pb.is_deleted,0)=0';
                }
                if ($columnExists($db, 'Tb_PhoneBook', 'service_code') && $columnExists($db, 'Tb_Site', 'service_code')) {
                    $phonebookJoin .= " AND ISNULL(CAST(pb.service_code AS NVARCHAR(50)),'')=ISNULL(CAST(s.service_code AS NVARCHAR(50)),'')";
                }
                if ($columnExists($db, 'Tb_PhoneBook', 'tenant_id') && $columnExists($db, 'Tb_Site', 'tenant_id')) {
                    $phonebookJoin .= ' AND ISNULL(TRY_CONVERT(INT,pb.tenant_id),0)=ISNULL(TRY_CONVERT(INT,s.tenant_id),0)';
                }
                $phonebookNameExpr = "ISNULL(CAST(pb.{$pbNameCol} AS NVARCHAR(120)), '')";
            }
        } elseif ($tableExists($db, 'Tb_PhoneBook') && $columnExists($db, 'Tb_Site', 'member_idx')) {
            $pbNameCol = $firstExistingColumn($db, $columnExists, 'Tb_PhoneBook', ['name']);
            if ($pbNameCol !== null && $columnExists($db, 'Tb_PhoneBook', 'member_idx')) {
                $pbWhere = ['ISNULL(TRY_CONVERT(INT,pb.member_idx),0)=ISNULL(TRY_CONVERT(INT,s.member_idx),0)'];
                if ($columnExists($db, 'Tb_PhoneBook', 'is_deleted')) {
                    $pbWhere[] = 'ISNULL(pb.is_deleted,0)=0';
                }
                if ($columnExists($db, 'Tb_PhoneBook', 'service_code') && $columnExists($db, 'Tb_Site', 'service_code')) {
                    $pbWhere[] = "ISNULL(CAST(pb.service_code AS NVARCHAR(50)),'')=ISNULL(CAST(s.service_code AS NVARCHAR(50)),'')";
                }
                if ($columnExists($db, 'Tb_PhoneBook', 'tenant_id') && $columnExists($db, 'Tb_Site', 'tenant_id')) {
                    $pbWhere[] = 'ISNULL(TRY_CONVERT(INT,pb.tenant_id),0)=ISNULL(TRY_CONVERT(INT,s.tenant_id),0)';
                }
                $phonebookNameExpr = '(SELECT TOP 1 ISNULL(CAST(pb.' . $pbNameCol . " AS NVARCHAR(120)), '') FROM Tb_PhoneBook pb WHERE " . implode(' AND ', $pbWhere) . ')';
            }
        }

        $siteGroupNameCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['group_name', 'site_group_name']);
        $siteGroupIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['group_idx', 'site_group_idx', 'member_group_idx']);
        $groupNameExpr = "CAST('' AS NVARCHAR(200))";
        if ($siteGroupNameCol !== null) {
            $groupNameExpr = "ISNULL(CAST(s.{$siteGroupNameCol} AS NVARCHAR(200)), '')";
        } elseif ($siteGroupIdxCol !== null && $tableExists($db, 'Tb_SiteGroup')) {
            $groupIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_SiteGroup', ['idx', 'id']);
            $groupNameCol = $firstExistingColumn($db, $columnExists, 'Tb_SiteGroup', ['name', 'group_name', 'site_group_name']);
            if ($groupIdxCol !== null && $groupNameCol !== null) {
                $groupWhere = ['ISNULL(TRY_CONVERT(INT,sg.' . $groupIdxCol . '),0)=ISNULL(TRY_CONVERT(INT,s.' . $siteGroupIdxCol . '),0)'];
                if ($columnExists($db, 'Tb_SiteGroup', 'is_deleted')) {
                    $groupWhere[] = 'ISNULL(sg.is_deleted,0)=0';
                }
                if ($columnExists($db, 'Tb_SiteGroup', 'service_code') && $columnExists($db, 'Tb_Site', 'service_code')) {
                    $groupWhere[] = "ISNULL(CAST(sg.service_code AS NVARCHAR(50)),'')=ISNULL(CAST(s.service_code AS NVARCHAR(50)),'')";
                }
                if ($columnExists($db, 'Tb_SiteGroup', 'tenant_id') && $columnExists($db, 'Tb_Site', 'tenant_id')) {
                    $groupWhere[] = 'ISNULL(TRY_CONVERT(INT,sg.tenant_id),0)=ISNULL(TRY_CONVERT(INT,s.tenant_id),0)';
                }
                $groupNameExpr = '(SELECT TOP 1 ISNULL(CAST(sg.' . $groupNameCol . " AS NVARCHAR(200)), '') FROM Tb_SiteGroup sg WHERE " . implode(' AND ', $groupWhere) . ')';
            }
        } elseif ($siteGroupIdxCol !== null && $tableExists($db, 'Tb_MemberGroup')) {
            $groupIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_MemberGroup', ['idx', 'id']);
            $groupNameCol = $firstExistingColumn($db, $columnExists, 'Tb_MemberGroup', ['name', 'group_name']);
            if ($groupIdxCol !== null && $groupNameCol !== null) {
                $groupWhere = ['ISNULL(TRY_CONVERT(INT,mg.' . $groupIdxCol . '),0)=ISNULL(TRY_CONVERT(INT,s.' . $siteGroupIdxCol . '),0)'];
                if ($columnExists($db, 'Tb_MemberGroup', 'is_deleted')) {
                    $groupWhere[] = 'ISNULL(mg.is_deleted,0)=0';
                }
                if ($columnExists($db, 'Tb_MemberGroup', 'service_code') && $columnExists($db, 'Tb_Site', 'service_code')) {
                    $groupWhere[] = "ISNULL(CAST(mg.service_code AS NVARCHAR(50)),'')=ISNULL(CAST(s.service_code AS NVARCHAR(50)),'')";
                }
                if ($columnExists($db, 'Tb_MemberGroup', 'tenant_id') && $columnExists($db, 'Tb_Site', 'tenant_id')) {
                    $groupWhere[] = 'ISNULL(TRY_CONVERT(INT,mg.tenant_id),0)=ISNULL(TRY_CONVERT(INT,s.tenant_id),0)';
                }
                $groupNameExpr = '(SELECT TOP 1 ISNULL(CAST(mg.' . $groupNameCol . " AS NVARCHAR(200)), '') FROM Tb_MemberGroup mg WHERE " . implode(' AND ', $groupWhere) . ')';
            }
        }

        $siteNumberExpr = $siteNumberCol !== null
            ? "ISNULL(CAST(s.{$siteNumberCol} AS NVARCHAR(60)), '')"
            : "CAST('' AS NVARCHAR(60))";
        $targetTeamExpr = $siteTargetTeamCol !== null
            ? "ISNULL(CAST(s.{$siteTargetTeamCol} AS NVARCHAR(120)), '')"
            : "CAST('' AS NVARCHAR(120))";
        $externalEmployeeExpr = $siteExternalEmployeeCol !== null
            ? "ISNULL(TRY_CONVERT(INT, s.{$siteExternalEmployeeCol}), 0)"
            : ($siteTotalQtyCol !== null ? "ISNULL(TRY_CONVERT(INT, s.{$siteTotalQtyCol}), 0)" : 'CAST(0 AS INT)');
        $constructionExpr = $siteConstructionCol !== null
            ? "ISNULL(CAST(s.{$siteConstructionCol} AS NVARCHAR(255)), '')"
            : "CAST('' AS NVARCHAR(255))";
        $constructionDateExpr = $siteConstructionDateCol !== null ? 's.' . $siteConstructionDateCol : 'NULL';
        $completionDateExpr = $siteCompletionDateCol !== null ? 's.' . $siteCompletionDateCol : 'NULL';

        $countStmt = $db->prepare('SELECT COUNT(*) FROM Tb_Site s ' . $joinMember . $employeeJoin . ' ' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $queryParams = array_merge($params, [$rowFrom, $rowTo]);

        $listSql = "SELECT * FROM (\n"
            . " SELECT s.*, ISNULL(s.{$siteNameCol}, '') AS site_display_name, ISNULL(m.name, '') AS member_name,\n"
            . "        {$groupNameExpr} AS group_name, {$phonebookNameExpr} AS phonebook_name,\n"
            . "        {$siteNumberExpr} AS site_number, {$employeeNameExpr} AS employee_name,\n"
            . "        {$targetTeamExpr} AS target_team, {$externalEmployeeExpr} AS external_employee,\n"
            . "        {$constructionExpr} AS construction, {$constructionDateExpr} AS construction_date, {$completionDateExpr} AS completion_date,\n"
            . "        ROW_NUMBER() OVER (ORDER BY {$sortExpr} {$orderExpr}, s.idx DESC) AS rn\n"
            . " FROM Tb_Site s {$joinMember}{$employeeJoin}{$phonebookJoin} {$whereSql}\n"
            . ") t WHERE t.rn BETWEEN ? AND ?";

        $stmt = $db->prepare($listSql);
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'data' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
            'sort' => $sortRaw,
            'order' => $orderExpr,
        ], 'OK', '현장 목록 조회 성공');
        exit;
    }

    if ($todo === 'detail') {
        $siteIdx = (int)($_GET['idx'] ?? $_GET['site_idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx or site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $fieldManagers = $loadFieldManagers(
            $db,
            $tableExists,
            $columnExists,
            $firstExistingColumn,
            $siteIdx,
            (int)($site['member_idx'] ?? 0),
            (string)($context['service_code'] ?? 'shvq'),
            (int)($context['tenant_id'] ?? 0)
        );
        if ($fieldManagers === []) {
            $fallbackName = trim((string)($site['field_manager_name'] ?? $site['manager_name'] ?? ''));
            $fallbackTel = trim((string)($site['field_manager_tel'] ?? $site['manager_tel'] ?? ''));
            $fallbackEmail = trim((string)($site['field_manager_email'] ?? ''));
            $fallbackMemo = trim((string)($site['field_manager_memo'] ?? ''));
            if ($fallbackName !== '' || $fallbackTel !== '' || $fallbackEmail !== '' || $fallbackMemo !== '') {
                $fieldManagers[] = [
                    'idx' => 0,
                    'name' => $fallbackName,
                    'tel' => $fallbackTel,
                    'phone' => $fallbackTel,
                    'email' => $fallbackEmail,
                    'memo' => $fallbackMemo,
                    'created_at' => null,
                ];
            }
        }

        $site['detail_sections'] = $buildSiteDetailSections($site);
        $site['field_managers'] = $fieldManagers;

        $estimateList = [];
        if ($tableExists($db, 'Tb_SiteEstimate')) {
            $siteFk = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', ['site_idx', 'idx_site']);
            if ($siteFk !== null) {
                $where = ["{$siteFk} = ?"];
                if ($columnExists($db, 'Tb_SiteEstimate', 'is_deleted')) {
                    $where[] = 'ISNULL(is_deleted,0)=0';
                }
                $stmt = $db->prepare('SELECT * FROM Tb_SiteEstimate WHERE ' . implode(' AND ', $where) . ' ORDER BY idx DESC');
                $stmt->execute([$siteIdx]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $estimateList = is_array($rows) ? $rows : [];
            }
        }

        ApiResponse::success([
            'site' => $site,
            'field_managers' => $fieldManagers,
            'detail_sections' => $site['detail_sections'],
            'estimates' => $estimateList,
            'estimate_count' => count($estimateList),
        ], 'OK', '현장 상세 조회 성공');
        exit;
    }

    if ($todo === 'site_settings') {
        $siteIdx = (int)($_GET['site_idx'] ?? $_GET['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $defaultTabs = [
            'info' => 1,
            'estimate' => 1,
            'bill' => 1,
            'contact' => 1,
            'floor' => 1,
            'attach' => 1,
            'subcontract' => 1,
            'access' => 1,
            'mail' => 1,
            'memo' => 1,
            'pjt' => 1,
        ];
        $defaultContactRequired = [
            'name' => 0,
            'phone' => 0,
            'email' => 0,
            'company' => 0,
            'position' => 0,
            'work' => 0,
            'memo' => 0,
        ];

        $normalizeTabs = static function (mixed $raw, array $defaults): array {
            $arr = $raw;
            if (is_string($arr)) {
                $decoded = json_decode($arr, true);
                $arr = is_array($decoded) ? $decoded : [];
            }
            if (is_array($arr) && isset($arr['tabs']) && is_array($arr['tabs'])) {
                $arr = $arr['tabs'];
            }
            if (!is_array($arr)) {
                $arr = [];
            }

            $normalized = $defaults;
            foreach ($arr as $key => $value) {
                $name = trim((string)$key);
                if ($name === '') {
                    continue;
                }
                $normalized[$name] = ((int)$value) === 1 ? 1 : 0;
            }

            ksort($normalized, SORT_NATURAL);
            return $normalized;
        };
        $normalizeContactRequired = static function (mixed $raw, array $defaults): array {
            $arr = $raw;
            if (is_string($arr)) {
                $decoded = json_decode($arr, true);
                $arr = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($arr)) {
                $arr = [];
            }
            $normalized = $defaults;
            foreach ($arr as $key => $value) {
                $name = trim((string)$key);
                if ($name === '') {
                    continue;
                }
                $normalized[$name] = ((int)$value) === 1 ? 1 : 0;
            }
            ksort($normalized, SORT_NATURAL);
            return $normalized;
        };

        $tabs = $defaultTabs;
        $contactRequired = $defaultContactRequired;

        $siteSettingsCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['site_settings', 'tab_settings', 'settings_json']);
        if ($siteSettingsCol !== null && array_key_exists($siteSettingsCol, $site)) {
            $siteSettingsRaw = $site[$siteSettingsCol];
            $siteSettings = is_string($siteSettingsRaw) ? json_decode($siteSettingsRaw, true) : (is_array($siteSettingsRaw) ? $siteSettingsRaw : null);
            if (is_array($siteSettings)) {
                if (array_key_exists('tabs', $siteSettings)) {
                    $tabs = $normalizeTabs($siteSettings['tabs'], $tabs);
                } else {
                    $tabs = $normalizeTabs($siteSettings, $tabs);
                }
                if (array_key_exists('contact_required', $siteSettings)) {
                    $contactRequired = $normalizeContactRequired($siteSettings['contact_required'], $contactRequired);
                }
            } else {
                $tabs = $normalizeTabs($siteSettingsRaw, $tabs);
            }
        }

        if ($tableExists($db, 'Tb_UserSettings') && $columnExists($db, 'Tb_UserSettings', 'setting_key') && $columnExists($db, 'Tb_UserSettings', 'setting_value')) {
            $settingKey = 'SITE_SETTINGS_' . $siteIdx;
            $where = ["setting_key IN (?, ?)"];
            $params = [$settingKey, strtolower($settingKey)];

            if ($columnExists($db, 'Tb_UserSettings', 'service_code')) {
                $where[] = 'service_code = ?';
                $params[] = (string)($context['service_code'] ?? 'shvq');
            }
            if ($columnExists($db, 'Tb_UserSettings', 'tenant_id')) {
                $where[] = 'ISNULL(tenant_id,0) = ?';
                $params[] = (int)($context['tenant_id'] ?? 0);
            }
            if ($columnExists($db, 'Tb_UserSettings', 'is_deleted')) {
                $where[] = 'ISNULL(is_deleted,0)=0';
            }

            $order = [];
            if ($columnExists($db, 'Tb_UserSettings', 'updated_at')) { $order[] = 'updated_at DESC'; }
            if ($columnExists($db, 'Tb_UserSettings', 'regdate')) { $order[] = 'regdate DESC'; }
            if ($columnExists($db, 'Tb_UserSettings', 'idx')) { $order[] = 'idx DESC'; }
            if ($order === []) { $order[] = '(SELECT NULL)'; }

            $stmt = $db->prepare('SELECT TOP 1 setting_value FROM Tb_UserSettings WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . implode(', ', $order));
            $stmt->execute($params);
            $raw = $stmt->fetchColumn();
            if (is_string($raw) && trim($raw) !== '') {
                $settingData = json_decode($raw, true);
                if (is_array($settingData)) {
                    if (array_key_exists('tabs', $settingData)) {
                        $tabs = $normalizeTabs($settingData['tabs'], $tabs);
                    } else {
                        $tabs = $normalizeTabs($settingData, $tabs);
                    }
                    if (array_key_exists('contact_required', $settingData)) {
                        $contactRequired = $normalizeContactRequired($settingData['contact_required'], $contactRequired);
                    }
                } else {
                    $tabs = $normalizeTabs($raw, $tabs);
                }
            }
        }

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'tabs' => $tabs,
            'contact_required' => $contactRequired,
            'settings' => ['tabs' => $tabs, 'contact_required' => $contactRequired],
            'data' => ['tabs' => $tabs, 'contact_required' => $contactRequired],
        ], 'OK', '현장 설정 조회 성공');
        exit;
    }

    if ($todo === 'save_site_settings') {
        $siteIdx = (int)($_POST['site_idx'] ?? $_POST['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $rawInput = $_POST['tabs'] ?? ($_POST['settings'] ?? ($_POST['data'] ?? ''));
        $decoded = $rawInput;
        if (is_string($decoded)) {
            $decodedJson = json_decode($decoded, true);
            $decoded = is_array($decodedJson) ? $decodedJson : null;
        }
        if (is_array($decoded) && isset($decoded['tabs']) && is_array($decoded['tabs'])) {
            $decoded = $decoded['tabs'];
        }
        if (!is_array($decoded)) {
            ApiResponse::error('INVALID_PARAM', 'tabs/settings/data must be valid JSON object', 422);
            exit;
        }

        $tabsRaw = (is_array($decoded) && array_key_exists('tabs', $decoded)) ? $decoded['tabs'] : $decoded;
        $tabs = [];
        foreach ((array)$tabsRaw as $key => $value) {
            $name = trim((string)$key);
            if ($name === '') {
                continue;
            }
            $tabs[$name] = ((int)$value) === 1 ? 1 : 0;
        }
        if ($tabs === []) {
            ApiResponse::error('INVALID_PARAM', 'tabs is empty', 422);
            exit;
        }
        ksort($tabs, SORT_NATURAL);

        $contactRequiredRaw = $_POST['contact_required'] ?? ($decoded['contact_required'] ?? []);
        if (is_string($contactRequiredRaw)) {
            $decodedContactRequired = json_decode($contactRequiredRaw, true);
            $contactRequiredRaw = is_array($decodedContactRequired) ? $decodedContactRequired : [];
        }
        $contactRequired = [];
        foreach ((array)$contactRequiredRaw as $key => $value) {
            $name = trim((string)$key);
            if ($name === '') {
                continue;
            }
            $contactRequired[$name] = ((int)$value) === 1 ? 1 : 0;
        }
        ksort($contactRequired, SORT_NATURAL);

        $payload = ['tabs' => $tabs, 'contact_required' => $contactRequired];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('failed to encode site settings');
        }

        $targetIdx = 0;
        $siteSettingsCol = $firstExistingColumn($db, $columnExists, 'Tb_Site', ['site_settings', 'tab_settings', 'settings_json']);
        if ($siteSettingsCol !== null) {
            $setSql = [$siteSettingsCol . ' = ?'];
            $params = [$json];
            if ($columnExists($db, 'Tb_Site', 'updated_at')) { $setSql[] = 'updated_at = ?'; $params[] = date('Y-m-d H:i:s'); }
            if ($columnExists($db, 'Tb_Site', 'updated_by')) { $setSql[] = 'updated_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }
            $params[] = $siteIdx;
            $stmt = $db->prepare('UPDATE Tb_Site SET ' . implode(', ', $setSql) . ' WHERE idx = ?');
            $stmt->execute($params);
            $targetIdx = $siteIdx;
        }

        $settingKey = 'SITE_SETTINGS_' . $siteIdx;
        $settingIdx = $upsertSystemSetting(
            $db,
            $tableExists,
            $columnExists,
            $firstExistingColumn,
            $qi,
            (string)($context['service_code'] ?? 'shvq'),
            (int)($context['tenant_id'] ?? 0),
            (int)($context['user_pk'] ?? 0),
            $settingKey,
            $json,
            'json',
            'site'
        );
        if ($settingIdx > 0) {
            $targetIdx = $settingIdx;
        }

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'save_site_settings', [
            'service_code' => (string)($context['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($context['tenant_id'] ?? 0),
            'target_table' => $settingIdx > 0 ? 'Tb_UserSettings' : 'Tb_Site',
            'target_idx' => $targetIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'site settings saved',
                'site_idx' => $siteIdx,
                'tabs' => $tabs,
                'contact_required' => $contactRequired,
            ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'save_site_settings', $settingIdx > 0 ? 'Tb_UserSettings' : 'Tb_Site', $targetIdx, [
            'site_idx' => $siteIdx,
            'tabs' => $tabs,
            'contact_required' => $contactRequired,
        ]);

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'tabs' => $tabs,
            'contact_required' => $contactRequired,
            'settings' => ['tabs' => $tabs, 'contact_required' => $contactRequired],
            'target_idx' => $targetIdx,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '현장 설정 저장 성공');
        exit;
    }

    if ($todo === 'excel_template') {
        $siteIdx = (int)($_GET['site_idx'] ?? $_GET['idx'] ?? 0);
        if ($siteIdx > 0) {
            $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
            if ($site === null) {
                ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
                exit;
            }
        }

        $headers = ['name', 'phone', 'email', 'company', 'position', 'work', 'memo'];
        $sampleRows = [
            ['홍길동', '010-0000-0000', 'sample@company.com', '샘플건설', '과장', '발주', ''],
        ];

        $csv = "\xEF\xBB\xBF" . implode(',', $headers) . "\n";
        foreach ($sampleRows as $row) {
            $escaped = array_map(static function ($val): string {
                $text = (string)$val;
                $text = str_replace('"', '""', $text);
                return '"' . $text . '"';
            }, $row);
            $csv .= implode(',', $escaped) . "\n";
        }

        $url = 'data:text/csv;charset=utf-8,' . rawurlencode($csv);
        ApiResponse::success([
            'site_idx' => $siteIdx,
            'filename' => 'site_contact_template.csv',
            'columns' => $headers,
            'url' => $url,
        ], 'OK', '엑셀 템플릿 생성 성공');
        exit;
    }

    if ($todo === 'excel_upload') {
        $siteIdx = (int)($_POST['site_idx'] ?? $_POST['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }
        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }
        if (!$tableExists($db, 'Tb_PhoneBook')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_PhoneBook table is missing', 503);
            exit;
        }
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            ApiResponse::error('INVALID_PARAM', 'file is required', 422);
            exit;
        }
        $file = $_FILES['file'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            ApiResponse::error('INVALID_PARAM', 'invalid upload file', 422, ['error_code' => (int)($file['error'] ?? 0)]);
            exit;
        }
        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            ApiResponse::error('INVALID_PARAM', 'uploaded file is not valid', 422);
            exit;
        }
        $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            ApiResponse::error('UNSUPPORTED_FILE', 'csv 파일만 지원합니다', 422, ['extension' => $ext]);
            exit;
        }

        $handle = fopen($tmpPath, 'rb');
        if ($handle === false) {
            ApiResponse::error('INVALID_PARAM', 'file open failed', 422);
            exit;
        }

        $firstLine = fgets($handle);
        if (!is_string($firstLine)) {
            fclose($handle);
            ApiResponse::error('INVALID_PARAM', 'empty file', 422);
            exit;
        }
        $delimiters = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delimiters);
        $delimiter = (string)array_key_first($delimiters);
        if ($delimiter === '') {
            $delimiter = ',';
        }
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);
        if (!is_array($header)) {
            fclose($handle);
            ApiResponse::error('INVALID_PARAM', 'header read failed', 422);
            exit;
        }

        $normalizeHeader = static function (string $key): string {
            $key = mb_strtolower(trim($key), 'UTF-8');
            $key = str_replace(["\xEF\xBB\xBF", ' ', '-', '_'], '', $key);
            return $key;
        };
        $headerMap = [];
        foreach ($header as $i => $name) {
            $headerMap[$normalizeHeader((string)$name)] = (int)$i;
        }
        $findCol = static function (array $map, array $aliases) use ($normalizeHeader): ?int {
            foreach ($aliases as $alias) {
                $key = $normalizeHeader((string)$alias);
                if (array_key_exists($key, $map)) {
                    return (int)$map[$key];
                }
            }
            return null;
        };

        $colName = $findCol($headerMap, ['name', '성명', '이름', '담당자']);
        $colPhone = $findCol($headerMap, ['phone', 'hp', 'tel', '전화번호', '휴대폰']);
        $colEmail = $findCol($headerMap, ['email', '이메일']);
        $colCompany = $findCol($headerMap, ['company', 'sosok', '소속', '회사']);
        $colPosition = $findCol($headerMap, ['position', 'jobtitle', '직위', '직책']);
        $colWork = $findCol($headerMap, ['work', 'mainwork', '주요업무']);
        $colMemo = $findCol($headerMap, ['memo', '비고', 'note']);

        $useHeader = $colName !== null || $colPhone !== null || $colEmail !== null || $colCompany !== null || $colPosition !== null || $colWork !== null || $colMemo !== null;
        if (!$useHeader) {
            $colName = 0;
            $colPhone = 1;
            $colEmail = 2;
            $colCompany = 3;
            $colPosition = 4;
            $colWork = 5;
            $colMemo = 6;
            rewind($handle);
        }

        $table = 'Tb_PhoneBook';
        $nameCol = $firstExistingColumn($db, $columnExists, $table, ['name', 'manager_name']);
        $hpCol = $firstExistingColumn($db, $columnExists, $table, ['hp', 'tel', 'phone']);
        $emailCol = $firstExistingColumn($db, $columnExists, $table, ['email']);
        $companyCol = $firstExistingColumn($db, $columnExists, $table, ['company', 'sosok', 'company_name', 'organization']);
        $positionCol = $firstExistingColumn($db, $columnExists, $table, ['position', 'job_title', 'title']);
        $workCol = $firstExistingColumn($db, $columnExists, $table, ['main_work', 'work']);
        $memoCol = $firstExistingColumn($db, $columnExists, $table, ['memo', 'comment', 'note']);
        if ($nameCol === null && $hpCol === null && $emailCol === null) {
            fclose($handle);
            ApiResponse::error('SCHEMA_NOT_READY', 'insertable contact columns are missing', 503);
            exit;
        }

        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $rowNo = 0;
        try {
            $db->beginTransaction();
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNo++;
                if (!is_array($row) || $row === []) {
                    $skipped++;
                    continue;
                }

                $read = static function (?int $idx, array $source): string {
                    if ($idx === null || !array_key_exists($idx, $source)) {
                        return '';
                    }
                    return trim((string)$source[$idx]);
                };

                $name = $read($colName, $row);
                $phone = $read($colPhone, $row);
                $email = $read($colEmail, $row);
                $company = $read($colCompany, $row);
                $position = $read($colPosition, $row);
                $work = $read($colWork, $row);
                $memo = $read($colMemo, $row);

                if ($name === '' && $phone === '' && $email === '' && $company === '' && $position === '' && $work === '' && $memo === '') {
                    $skipped++;
                    continue;
                }

                $cols = [];
                $vals = [];
                $add = static function (string $col, mixed $val) use (&$cols, &$vals, $db, $columnExists): void {
                    if ($columnExists($db, 'Tb_PhoneBook', $col)) {
                        $cols[] = $col;
                        $vals[] = $val;
                    }
                };

                if ($nameCol !== null) { $add($nameCol, $name); }
                if ($hpCol !== null) { $add($hpCol, $phone); }
                if ($emailCol !== null) { $add($emailCol, $email); }
                if ($companyCol !== null) { $add($companyCol, $company); }
                if ($positionCol !== null) { $add($positionCol, $position); }
                if ($workCol !== null) { $add($workCol, $work); }
                if ($memoCol !== null) { $add($memoCol, $memo); }
                if ($columnExists($db, $table, 'site_idx')) { $add('site_idx', $siteIdx); }
                if ($columnExists($db, $table, 'member_idx')) { $add('member_idx', (int)($site['member_idx'] ?? 0)); }
                if ($columnExists($db, $table, 'employee_idx')) { $add('employee_idx', (int)($context['employee_idx'] ?? 0)); }
                if ($columnExists($db, $table, 'service_code')) { $add('service_code', (string)($context['service_code'] ?? 'shvq')); }
                if ($columnExists($db, $table, 'tenant_id')) { $add('tenant_id', (int)($context['tenant_id'] ?? 0)); }
                if ($columnExists($db, $table, 'is_deleted')) { $add('is_deleted', 0); }
                if ($columnExists($db, $table, 'created_by')) { $add('created_by', (int)($context['user_pk'] ?? 0)); }
                if ($columnExists($db, $table, 'created_at')) { $add('created_at', date('Y-m-d H:i:s')); }
                if ($columnExists($db, $table, 'regdate')) { $add('regdate', date('Y-m-d H:i:s')); }

                if ($cols === []) {
                    $skipped++;
                    continue;
                }

                $ph = implode(', ', array_fill(0, count($cols), '?'));
                $stmt = $db->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . $ph . ')');
                $stmt->execute($vals);
                $inserted++;
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = $e->getMessage();
        } finally {
            fclose($handle);
        }

        if ($errors !== []) {
            ApiResponse::error('SERVER_ERROR', '엑셀 업로드 처리 실패', 500, [
                'site_idx' => $siteIdx,
                'count' => $inserted,
                'inserted' => $inserted,
                'skipped' => $skipped,
                'errors' => $errors,
            ]);
            exit;
        }

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'excel_upload', [
            'service_code' => (string)($context['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($context['tenant_id'] ?? 0),
            'target_table' => 'Tb_PhoneBook',
            'target_idx' => $siteIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'excel contact upload',
            'site_idx' => $siteIdx,
            'inserted' => $inserted,
            'skipped' => $skipped,
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'excel_upload', 'Tb_PhoneBook', $siteIdx, [
            'site_idx' => $siteIdx,
            'inserted' => $inserted,
            'skipped' => $skipped,
        ]);

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'count' => $inserted,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '엑셀 업로드 처리 성공');
        exit;
    }

    if ($todo === 'asset_list') {
        $siteIdx = (int)($_GET['site_idx'] ?? $_GET['idx'] ?? 0);
        if ($siteIdx > 0) {
            $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
            if ($site === null) {
                ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
                exit;
            }
        }

        if (!$tableExists($db, 'Tb_Asset')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Asset table is missing', 503);
            exit;
        }

        $table = 'Tb_Asset';
        $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
        $assetNumberCol = $firstExistingColumn($db, $columnExists, $table, ['asset_number', 'number', 'account_code']);
        $assetNameCol = $firstExistingColumn($db, $columnExists, $table, ['asset_name', 'name']);
        $assetNicknameCol = $firstExistingColumn($db, $columnExists, $table, ['account_nickname', 'nickname', 'asset_alias', 'alias', 'bank_alias', 'account_alias']);
        $assetBankCol = $firstExistingColumn($db, $columnExists, $table, ['bank_id', 'bank_name', 'bank_code']);
        $assetAccountNoCol = $firstExistingColumn($db, $columnExists, $table, ['account_number', 'account_no', 'bank_account', 'account']);
        $assetDepositorCol = $firstExistingColumn($db, $columnExists, $table, ['depositor', 'depositor_name', 'account_holder', 'holder_name']);
        $assetAccountCol = $firstExistingColumn($db, $columnExists, $table, ['asset_account']);
        $assetDeletedCol = $firstExistingColumn($db, $columnExists, $table, ['is_deleted', 'isDel']);

        $where = ['1=1'];
        $params = [];
        if ($assetDeletedCol !== null) {
            $where[] = 'ISNULL(TRY_CONVERT(INT,a.' . $assetDeletedCol . '),0)=0';
        }
        if ($columnExists($db, $table, 'service_code')) {
            $where[] = "ISNULL(CAST(a.service_code AS NVARCHAR(50)),'')=?";
            $params[] = (string)($context['service_code'] ?? 'shvq');
        }
        if ($columnExists($db, $table, 'tenant_id')) {
            $where[] = 'ISNULL(TRY_CONVERT(INT,a.tenant_id),0)=?';
            $params[] = (int)($context['tenant_id'] ?? 0);
        }
        if ($assetAccountCol !== null) {
            $where[] = "(ISNULL(TRY_CONVERT(INT,a.{$assetAccountCol}),0)=100 OR ISNULL(CAST(a.{$assetAccountCol} AS NVARCHAR(20)),'')='100')";
        }

        $search = trim((string)($_GET['search'] ?? ''));
        if ($search !== '') {
            $searchCols = [];
            if ($assetNumberCol !== null) { $searchCols[] = 'ISNULL(CAST(a.' . $assetNumberCol . " AS NVARCHAR(120)),'') LIKE ?"; }
            if ($assetNameCol !== null) { $searchCols[] = 'ISNULL(CAST(a.' . $assetNameCol . " AS NVARCHAR(120)),'') LIKE ?"; }
            if ($assetNicknameCol !== null) { $searchCols[] = 'ISNULL(CAST(a.' . $assetNicknameCol . " AS NVARCHAR(120)),'') LIKE ?"; }
            if ($searchCols !== []) {
                $where[] = '(' . implode(' OR ', $searchCols) . ')';
                $searchParam = '%' . $search . '%';
                for ($i = 0; $i < count($searchCols); $i++) {
                    $params[] = $searchParam;
                }
            }
        }

        $limit = min(2000, max(1, (int)($_GET['limit'] ?? 500)));
        $assetNumberExpr = $assetNumberCol !== null ? 'ISNULL(CAST(a.' . $assetNumberCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";
        $assetNameExpr = $assetNameCol !== null ? 'ISNULL(CAST(a.' . $assetNameCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";
        $assetNicknameExpr = $assetNicknameCol !== null ? 'ISNULL(CAST(a.' . $assetNicknameCol . " AS NVARCHAR(120)),'')" : $assetNameExpr;
        $assetBankExpr = $assetBankCol !== null ? 'ISNULL(CAST(a.' . $assetBankCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";
        $assetAccountNoExpr = $assetAccountNoCol !== null ? 'ISNULL(CAST(a.' . $assetAccountNoCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";
        $assetDepositorExpr = $assetDepositorCol !== null ? 'ISNULL(CAST(a.' . $assetDepositorCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";
        $assetAccountExpr = $assetAccountCol !== null ? 'ISNULL(CAST(a.' . $assetAccountCol . " AS NVARCHAR(30)),'')" : "CAST('' AS NVARCHAR(30))";
        $assetSortCol = $firstExistingColumn($db, $columnExists, $table, ['sort_order', 'sort', 'asset_name', 'name', 'asset_number', 'idx']) ?? $idxCol;

        $sql = "SELECT TOP {$limit}\n"
            . "  ISNULL(TRY_CONVERT(INT,a.{$idxCol}),0) AS idx,\n"
            . "  {$assetNumberExpr} AS asset_number,\n"
            . "  {$assetNameExpr} AS asset_name,\n"
            . "  {$assetNicknameExpr} AS account_nickname,\n"
            . "  {$assetBankExpr} AS bank_id,\n"
            . "  {$assetAccountNoExpr} AS account_number,\n"
            . "  {$assetDepositorExpr} AS depositor_name,\n"
            . "  {$assetAccountExpr} AS asset_account\n"
            . " FROM {$table} a\n"
            . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY a.{$assetSortCol} ASC, a.{$idxCol} ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        foreach ($rows as &$row) {
            $label = trim((string)($row['account_nickname'] ?? ''));
            if ($label === '') { $label = trim((string)($row['asset_name'] ?? '')); }
            if ($label === '') { $label = trim((string)($row['asset_number'] ?? '')); }
            if ($label === '') { $label = (string)($row['idx'] ?? '0'); }

            $value = trim((string)($row['asset_number'] ?? ''));
            if ($value === '') {
                $value = (string)($row['idx'] ?? '0');
            }

            $accountNo = trim((string)($row['account_number'] ?? ''));
            $display = $label;
            if ($accountNo !== '') {
                $display .= ' (' . $accountNo . ')';
            }

            $row['account_nickname'] = $label;
            $row['name'] = $label;
            $row['label'] = $label;
            $row['value'] = $value;
            $row['display'] = $display;
        }
        unset($row);

        $options = [];
        foreach ($rows as $row) {
            $options[] = [
                'value' => (string)($row['value'] ?? ''),
                'label' => (string)($row['display'] ?? $row['label'] ?? ''),
                'asset_number' => (string)($row['asset_number'] ?? ''),
                'account_nickname' => (string)($row['account_nickname'] ?? ''),
                'account_number' => (string)($row['account_number'] ?? ''),
            ];
        }

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'data' => $rows,
            'options' => $options,
            'total' => count($rows),
        ], 'OK', '통장/자산 목록 조회 성공');
        exit;
    }

    if ($todo === 'bill_list') {
        $siteIdx = (int)($_GET['site_idx'] ?? $_GET['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));
        $estimateIds = $loadSiteEstimateIds($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);

        $rows = [];
        $total = 0;
        $summary = [
            'total_price' => 0,
            'progress_amount' => 0,
            'collected_amount' => 0,
            'remaining_amount' => 0,
        ];

        if ($tableExists($db, 'Tb_Bill')) {
            $billTable = 'Tb_Bill';
            $billIdxCol = $firstExistingColumn($db, $columnExists, $billTable, ['idx', 'id']) ?? 'idx';
            $billSiteCol = $firstExistingColumn($db, $columnExists, $billTable, ['site_idx']);
            $billEstimateCol = $firstExistingColumn($db, $columnExists, $billTable, ['estimate_idx', 'site_estimate_idx', 'est_idx']);
            $billReferCol = $firstExistingColumn($db, $columnExists, $billTable, ['refer_idx', 'billgroup_idx', 'bg_idx']);
            $billTypeCol = $firstExistingColumn($db, $columnExists, $billTable, ['bill_type', 'type', 'gubun', 'kind']);
            $billStatusCol = $firstExistingColumn($db, $columnExists, $billTable, ['bill_status', 'status']);
            $billAmountCol = $firstExistingColumn($db, $columnExists, $billTable, ['amount', 'bill_amount', 'price', 'supply_amount', 'total_amount']);
            $billCollectedCol = $firstExistingColumn($db, $columnExists, $billTable, ['deposit_amount', 'paid_amount', 'collect_amount', 'received_amount']);
            $billDateCol = $firstExistingColumn($db, $columnExists, $billTable, ['bill_date', 'bring_date', 'deposit_date', 'insert_date', 'created_at', 'regdate']);
            $billMemoCol = $firstExistingColumn($db, $columnExists, $billTable, ['memo', 'note', 'comment', 'contents']);

            $joinBg = '';
            $bgSiteCol = null;
            $bgEstimateCol = null;
            if ($billReferCol !== null && $tableExists($db, 'Tb_BillGroup')) {
                $bgIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_BillGroup', ['idx', 'id']);
                if ($bgIdxCol !== null) {
                    $joinBg = " LEFT JOIN Tb_BillGroup bg ON bg.{$bgIdxCol} = b.{$billReferCol}";
                    if ($columnExists($db, 'Tb_BillGroup', 'is_deleted')) {
                        $joinBg .= ' AND ISNULL(bg.is_deleted,0)=0';
                    }
                    $bgSiteCol = $firstExistingColumn($db, $columnExists, 'Tb_BillGroup', ['site_idx']);
                    $bgEstimateCol = $firstExistingColumn($db, $columnExists, 'Tb_BillGroup', ['estimate_idx']);
                }
            }

            $scopeConds = [];
            $scopeParams = [];
            if ($billSiteCol !== null) {
                $scopeConds[] = 'ISNULL(TRY_CONVERT(INT, b.' . $billSiteCol . '),0) = ?';
                $scopeParams[] = $siteIdx;
            }
            if ($billEstimateCol !== null && $estimateIds !== []) {
                $ph = implode(', ', array_fill(0, count($estimateIds), '?'));
                $scopeConds[] = 'ISNULL(TRY_CONVERT(INT, b.' . $billEstimateCol . '),0) IN (' . $ph . ')';
                $scopeParams = array_merge($scopeParams, $estimateIds);
            }
            if ($joinBg !== '' && $bgSiteCol !== null) {
                $scopeConds[] = 'ISNULL(TRY_CONVERT(INT, bg.' . $bgSiteCol . '),0) = ?';
                $scopeParams[] = $siteIdx;
            } elseif ($joinBg !== '' && $bgEstimateCol !== null && $estimateIds !== []) {
                $ph = implode(', ', array_fill(0, count($estimateIds), '?'));
                $scopeConds[] = 'ISNULL(TRY_CONVERT(INT, bg.' . $bgEstimateCol . '),0) IN (' . $ph . ')';
                $scopeParams = array_merge($scopeParams, $estimateIds);
            }

            if ($scopeConds !== []) {
                $where = [];
                $params = [];
                if ($columnExists($db, $billTable, 'is_deleted')) {
                    $where[] = 'ISNULL(b.is_deleted,0)=0';
                }
                if ($columnExists($db, $billTable, 'service_code')) {
                    $where[] = "ISNULL(b.service_code,'') = ?";
                    $params[] = (string)($context['service_code'] ?? 'shvq');
                }
                if ($columnExists($db, $billTable, 'tenant_id')) {
                    $where[] = 'ISNULL(b.tenant_id,0) = ?';
                    $params[] = (int)($context['tenant_id'] ?? 0);
                }
                $where[] = '(' . implode(' OR ', $scopeConds) . ')';
                $params = array_merge($params, $scopeParams);

                $whereSql = ' WHERE ' . implode(' AND ', $where);
                $fromSql = ' FROM ' . $billTable . ' b' . $joinBg;

                $amountSql = $billAmountCol !== null ? 'ISNULL(TRY_CONVERT(BIGINT,b.' . $billAmountCol . '),0)' : 'CAST(0 AS BIGINT)';
                $typeSql = $billTypeCol !== null ? 'ISNULL(CAST(b.' . $billTypeCol . " AS NVARCHAR(100)),'')" : "CAST('' AS NVARCHAR(100))";
                $statusSql = $billStatusCol !== null ? 'ISNULL(CAST(b.' . $billStatusCol . " AS NVARCHAR(40)),'')" : "CAST('' AS NVARCHAR(40))";
                $dateSql = $billDateCol !== null ? 'b.' . $billDateCol : 'NULL';
                $memoSql = $billMemoCol !== null ? 'ISNULL(CAST(b.' . $billMemoCol . " AS NVARCHAR(2000)),'')" : "CAST('' AS NVARCHAR(2000))";
                $progressSql = $billTypeCol !== null
                    ? "CASE WHEN CAST({$typeSql} AS NVARCHAR(100)) LIKE N'%기성%' THEN {$amountSql} ELSE CAST(0 AS BIGINT) END"
                    : 'CAST(0 AS BIGINT)';
                $collectedSql = $billCollectedCol !== null
                    ? 'ISNULL(TRY_CONVERT(BIGINT,b.' . $billCollectedCol . '),0)'
                    : ($billStatusCol !== null
                        ? "CASE WHEN CAST({$statusSql} AS NVARCHAR(40)) IN (N'수금', N'입금', N'완료', 'PAID', 'DONE') THEN {$amountSql} ELSE CAST(0 AS BIGINT) END"
                        : 'CAST(0 AS BIGINT)');

                $countStmt = $db->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                $rowFrom = ($page - 1) * $limit + 1;
                $rowTo = $rowFrom + $limit - 1;
                $queryParams = array_merge($params, [$rowFrom, $rowTo]);

                $orderSql = "ISNULL(TRY_CONVERT(DATETIME, {$dateSql}), '1900-01-01') DESC, b.{$billIdxCol} DESC";
                $listSql = "SELECT * FROM (\n"
                    . " SELECT\n"
                    . "   ISNULL(TRY_CONVERT(BIGINT,b.{$billIdxCol}),0) AS idx,\n"
                    . "   {$typeSql} AS bill_type,\n"
                    . "   {$statusSql} AS bill_status,\n"
                    . "   {$amountSql} AS amount,\n"
                    . "   {$dateSql} AS bill_date,\n"
                    . "   {$memoSql} AS memo,\n"
                    . "   {$progressSql} AS progress_amount,\n"
                    . "   {$collectedSql} AS collected_amount,\n"
                    . "   ROW_NUMBER() OVER (ORDER BY {$orderSql}) AS rn\n"
                    . $fromSql
                    . $whereSql
                    . "\n) t WHERE t.rn BETWEEN ? AND ?";

                $stmt = $db->prepare($listSql);
                $stmt->execute($queryParams);
                $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $rows = is_array($fetched) ? $fetched : [];

                $sumSql = 'SELECT '
                    . "ISNULL(SUM({$amountSql}),0) AS total_price, "
                    . "ISNULL(SUM({$progressSql}),0) AS progress_amount, "
                    . "ISNULL(SUM({$collectedSql}),0) AS collected_amount "
                    . $fromSql . $whereSql;
                $sumStmt = $db->prepare($sumSql);
                $sumStmt->execute($params);
                $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($sumRow)) {
                    $summary['total_price'] = (int)($sumRow['total_price'] ?? 0);
                    $summary['progress_amount'] = (int)($sumRow['progress_amount'] ?? 0);
                    $summary['collected_amount'] = (int)($sumRow['collected_amount'] ?? 0);
                    $summary['remaining_amount'] = max(0, $summary['total_price'] - $summary['collected_amount']);
                }
            }
        } elseif ($tableExists($db, 'Tb_BillGroup')) {
            $bgTable = 'Tb_BillGroup';
            $bgIdxCol = $firstExistingColumn($db, $columnExists, $bgTable, ['idx', 'id']) ?? 'idx';
            $bgSiteCol = $firstExistingColumn($db, $columnExists, $bgTable, ['site_idx']);
            $bgEstimateCol = $firstExistingColumn($db, $columnExists, $bgTable, ['estimate_idx']);
            $bgTypeCol = $firstExistingColumn($db, $columnExists, $bgTable, ['bill_type', 'type', 'gubun', 'kind']);
            $bgStatusCol = $firstExistingColumn($db, $columnExists, $bgTable, ['bill_status', 'status']);
            $bgAmountCol = $firstExistingColumn($db, $columnExists, $bgTable, ['amount', 'bill_amount', 'price', 'supply_amount', 'total_amount']);
            $bgDateCol = $firstExistingColumn($db, $columnExists, $bgTable, ['bill_date', 'created_at', 'regdate']);
            $bgMemoCol = $firstExistingColumn($db, $columnExists, $bgTable, ['memo', 'note', 'comment']);

            $scopeConds = [];
            $scopeParams = [];
            if ($bgSiteCol !== null) {
                $scopeConds[] = 'ISNULL(TRY_CONVERT(INT,bg.' . $bgSiteCol . '),0) = ?';
                $scopeParams[] = $siteIdx;
            }
            if ($bgEstimateCol !== null && $estimateIds !== []) {
                $ph = implode(', ', array_fill(0, count($estimateIds), '?'));
                $scopeConds[] = 'ISNULL(TRY_CONVERT(INT,bg.' . $bgEstimateCol . '),0) IN (' . $ph . ')';
                $scopeParams = array_merge($scopeParams, $estimateIds);
            }

            if ($scopeConds !== []) {
                $where = [];
                $params = [];
                if ($columnExists($db, $bgTable, 'is_deleted')) { $where[] = 'ISNULL(bg.is_deleted,0)=0'; }
                if ($columnExists($db, $bgTable, 'service_code')) { $where[] = "ISNULL(bg.service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
                if ($columnExists($db, $bgTable, 'tenant_id')) { $where[] = 'ISNULL(bg.tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }
                $where[] = '(' . implode(' OR ', $scopeConds) . ')';
                $params = array_merge($params, $scopeParams);

                $whereSql = ' WHERE ' . implode(' AND ', $where);
                $amountSql = $bgAmountCol !== null ? 'ISNULL(TRY_CONVERT(BIGINT,bg.' . $bgAmountCol . '),0)' : 'CAST(0 AS BIGINT)';
                $typeSql = $bgTypeCol !== null ? 'ISNULL(CAST(bg.' . $bgTypeCol . " AS NVARCHAR(100)),'')" : "CAST('' AS NVARCHAR(100))";
                $statusSql = $bgStatusCol !== null ? 'ISNULL(CAST(bg.' . $bgStatusCol . " AS NVARCHAR(40)),'')" : "CAST('' AS NVARCHAR(40))";
                $dateSql = $bgDateCol !== null ? 'bg.' . $bgDateCol : 'NULL';
                $memoSql = $bgMemoCol !== null ? 'ISNULL(CAST(bg.' . $bgMemoCol . " AS NVARCHAR(2000)),'')" : "CAST('' AS NVARCHAR(2000))";
                $progressSql = $bgTypeCol !== null
                    ? "CASE WHEN CAST({$typeSql} AS NVARCHAR(100)) LIKE N'%기성%' THEN {$amountSql} ELSE CAST(0 AS BIGINT) END"
                    : 'CAST(0 AS BIGINT)';

                $countStmt = $db->prepare('SELECT COUNT(*) FROM ' . $bgTable . ' bg' . $whereSql);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                $rowFrom = ($page - 1) * $limit + 1;
                $rowTo = $rowFrom + $limit - 1;
                $queryParams = array_merge($params, [$rowFrom, $rowTo]);

                $orderSql = "ISNULL(TRY_CONVERT(DATETIME, {$dateSql}), '1900-01-01') DESC, bg.{$bgIdxCol} DESC";
                $listSql = "SELECT * FROM (\n"
                    . " SELECT\n"
                    . "   ISNULL(TRY_CONVERT(BIGINT,bg.{$bgIdxCol}),0) AS idx,\n"
                    . "   {$typeSql} AS bill_type,\n"
                    . "   {$statusSql} AS bill_status,\n"
                    . "   {$amountSql} AS amount,\n"
                    . "   {$dateSql} AS bill_date,\n"
                    . "   {$memoSql} AS memo,\n"
                    . "   {$progressSql} AS progress_amount,\n"
                    . "   CAST(0 AS BIGINT) AS collected_amount,\n"
                    . "   ROW_NUMBER() OVER (ORDER BY {$orderSql}) AS rn\n"
                    . ' FROM ' . $bgTable . ' bg' . $whereSql
                    . "\n) t WHERE t.rn BETWEEN ? AND ?";
                $stmt = $db->prepare($listSql);
                $stmt->execute($queryParams);
                $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $rows = is_array($fetched) ? $fetched : [];

                $sumStmt = $db->prepare('SELECT ISNULL(SUM(' . $amountSql . '),0) AS total_price, ISNULL(SUM(' . $progressSql . '),0) AS progress_amount FROM ' . $bgTable . ' bg' . $whereSql);
                $sumStmt->execute($params);
                $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($sumRow)) {
                    $summary['total_price'] = (int)($sumRow['total_price'] ?? 0);
                    $summary['progress_amount'] = (int)($sumRow['progress_amount'] ?? 0);
                    $summary['collected_amount'] = 0;
                    $summary['remaining_amount'] = $summary['total_price'];
                }
            }
        }

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
            'summary' => $summary,
        ], 'OK', '수금 목록 조회 성공');
        exit;
    }

    if ($todo === 'bill_detail') {
        $billIdx = (int)($_GET['bill_idx'] ?? $_GET['idx'] ?? 0);
        if ($billIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'bill_idx is required', 422);
            exit;
        }

        $siteIdx = (int)($_GET['site_idx'] ?? 0);
        if ($siteIdx > 0) {
            $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
            if ($site === null) {
                ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
                exit;
            }
        }

        $svcCode = (string)($context['service_code'] ?? 'shvq');
        $tenantId = (int)($context['tenant_id'] ?? 0);

        if ($tableExists($db, 'Tb_Bill')) {
            $billTable = 'Tb_Bill';
            $billIdxCol = $firstExistingColumn($db, $columnExists, $billTable, ['idx', 'id']) ?? 'idx';
            $billSiteCol = $firstExistingColumn($db, $columnExists, $billTable, ['site_idx']);
            $billMemberCol = $firstExistingColumn($db, $columnExists, $billTable, ['member_idx']);
            $billEstimateCol = $firstExistingColumn($db, $columnExists, $billTable, ['estimate_idx', 'site_estimate_idx', 'est_idx']);
            $billReferCol = $firstExistingColumn($db, $columnExists, $billTable, ['refer_idx', 'billgroup_idx', 'bg_idx']);
            $billNumberCol = $firstExistingColumn($db, $columnExists, $billTable, ['bill_number', 'number', 'seq']);
            $billTypeCol = $firstExistingColumn($db, $columnExists, $billTable, ['bill_type', 'type', 'gubun', 'kind']);
            $billStatusCol = $firstExistingColumn($db, $columnExists, $billTable, ['bill_status', 'status']);
            $billAmountCol = $firstExistingColumn($db, $columnExists, $billTable, ['amount', 'bill_amount', 'price', 'supply_amount', 'total_amount']);
            $billTotalAmountCol = $firstExistingColumn($db, $columnExists, $billTable, ['total_amount', 'bill_amount', 'amount', 'price', 'supply_amount']);
            $billCollectedCol = $firstExistingColumn($db, $columnExists, $billTable, ['deposit_amount', 'paid_amount', 'collect_amount', 'received_amount']);
            $billBringDateCol = $firstExistingColumn($db, $columnExists, $billTable, ['bring_date', 'bill_date']);
            $billEndDateCol = $firstExistingColumn($db, $columnExists, $billTable, ['end_date']);
            $billDepositDateCol = $firstExistingColumn($db, $columnExists, $billTable, ['deposit_date']);
            $billInsertDateCol = $firstExistingColumn($db, $columnExists, $billTable, ['insert_date']);
            $billMemoCol = $firstExistingColumn($db, $columnExists, $billTable, ['memo', 'note', 'comment', 'contents']);
            $billEmployeeCol = $firstExistingColumn($db, $columnExists, $billTable, ['employee_idx']);
            $billCreatedCol = $firstExistingColumn($db, $columnExists, $billTable, ['created_at', 'regdate', 'registered_date']);
            $billUpdatedCol = $firstExistingColumn($db, $columnExists, $billTable, ['updated_at', 'updated_date']);

            $where = [$billIdxCol . ' = ?'];
            $params = [$billIdx];
            if ($columnExists($db, $billTable, 'is_deleted')) { $where[] = 'ISNULL(is_deleted,0)=0'; }
            if ($columnExists($db, $billTable, 'service_code')) { $where[] = "ISNULL(service_code,'')=?"; $params[] = $svcCode; }
            if ($columnExists($db, $billTable, 'tenant_id')) { $where[] = 'ISNULL(tenant_id,0)=?'; $params[] = $tenantId; }
            if ($siteIdx > 0 && $billSiteCol !== null) { $where[] = 'ISNULL(TRY_CONVERT(INT,' . $billSiteCol . '),0)=?'; $params[] = $siteIdx; }

            $stmt = $db->prepare('SELECT TOP 1 * FROM ' . $billTable . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $billRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (is_array($billRow)) {
                $bgIdx = $billReferCol !== null ? (int)($billRow[$billReferCol] ?? 0) : 0;
                $group = null;

                if ($bgIdx > 0 && $tableExists($db, 'Tb_BillGroup')) {
                    $bgTable = 'Tb_BillGroup';
                    $bgIdxCol = $firstExistingColumn($db, $columnExists, $bgTable, ['idx', 'id']) ?? 'idx';
                    $bgSiteCol = $firstExistingColumn($db, $columnExists, $bgTable, ['site_idx']);
                    $bgMemberCol = $firstExistingColumn($db, $columnExists, $bgTable, ['member_idx']);
                    $bgEstimateCol = $firstExistingColumn($db, $columnExists, $bgTable, ['estimate_idx']);
                    $bgTotalAmountCol = $firstExistingColumn($db, $columnExists, $bgTable, ['total_amount', 'amount', 'bill_amount', 'price', 'supply_amount']);
                    $bgEmployeeCol = $firstExistingColumn($db, $columnExists, $bgTable, ['employee_idx']);
                    $bgInsertDateCol = $firstExistingColumn($db, $columnExists, $bgTable, ['insert_date']);
                    $bgMemoCol = $firstExistingColumn($db, $columnExists, $bgTable, ['memo', 'note', 'comment']);
                    $bgTypeCol = $firstExistingColumn($db, $columnExists, $bgTable, ['bill_type', 'type', 'gubun', 'kind']);
                    $bgStatusCol = $firstExistingColumn($db, $columnExists, $bgTable, ['bill_status', 'status']);

                    $bgWhere = [$bgIdxCol . ' = ?'];
                    $bgParams = [$bgIdx];
                    if ($columnExists($db, $bgTable, 'is_deleted')) { $bgWhere[] = 'ISNULL(is_deleted,0)=0'; }
                    if ($columnExists($db, $bgTable, 'service_code')) { $bgWhere[] = "ISNULL(service_code,'')=?"; $bgParams[] = $svcCode; }
                    if ($columnExists($db, $bgTable, 'tenant_id')) { $bgWhere[] = 'ISNULL(tenant_id,0)=?'; $bgParams[] = $tenantId; }
                    if ($siteIdx > 0 && $bgSiteCol !== null) { $bgWhere[] = 'ISNULL(TRY_CONVERT(INT,' . $bgSiteCol . '),0)=?'; $bgParams[] = $siteIdx; }

                    $bgStmt = $db->prepare('SELECT TOP 1 * FROM ' . $bgTable . ' WHERE ' . implode(' AND ', $bgWhere));
                    $bgStmt->execute($bgParams);
                    $bgRow = $bgStmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($bgRow)) {
                        $group = [
                            'idx' => (int)($bgRow[$bgIdxCol] ?? 0),
                            'site_idx' => $bgSiteCol !== null ? (int)($bgRow[$bgSiteCol] ?? 0) : 0,
                            'member_idx' => $bgMemberCol !== null ? (int)($bgRow[$bgMemberCol] ?? 0) : 0,
                            'estimate_idx' => $bgEstimateCol !== null ? (int)($bgRow[$bgEstimateCol] ?? 0) : 0,
                            'total_amount' => $bgTotalAmountCol !== null ? (int)($bgRow[$bgTotalAmountCol] ?? 0) : 0,
                            'employee_idx' => $bgEmployeeCol !== null ? (int)($bgRow[$bgEmployeeCol] ?? 0) : 0,
                            'insert_date' => $bgInsertDateCol !== null ? ($bgRow[$bgInsertDateCol] ?? null) : null,
                            'memo' => $bgMemoCol !== null ? (string)($bgRow[$bgMemoCol] ?? '') : '',
                            'bill_type' => $bgTypeCol !== null ? (string)($bgRow[$bgTypeCol] ?? '') : '',
                            'bill_status' => $bgStatusCol !== null ? (string)($bgRow[$bgStatusCol] ?? '') : '',
                        ];
                    }
                }

                $resolvedSiteIdx = $siteIdx > 0
                    ? $siteIdx
                    : ($billSiteCol !== null ? (int)($billRow[$billSiteCol] ?? 0) : (int)($group['site_idx'] ?? 0));
                $amount = $billAmountCol !== null ? (int)($billRow[$billAmountCol] ?? 0) : 0;
                $collected = $billCollectedCol !== null ? (int)($billRow[$billCollectedCol] ?? 0) : 0;
                $totalAmount = $billTotalAmountCol !== null ? (int)($billRow[$billTotalAmountCol] ?? 0) : 0;
                if ($totalAmount <= 0) {
                    $totalAmount = (int)($group['total_amount'] ?? 0);
                }
                if ($totalAmount <= 0) {
                    $totalAmount = $amount;
                }

                $detail = [
                    'idx' => (int)($billRow[$billIdxCol] ?? 0),
                    'bill_idx' => (int)($billRow[$billIdxCol] ?? 0),
                    'site_idx' => $resolvedSiteIdx,
                    'member_idx' => $billMemberCol !== null ? (int)($billRow[$billMemberCol] ?? 0) : (int)($group['member_idx'] ?? 0),
                    'estimate_idx' => $billEstimateCol !== null ? (int)($billRow[$billEstimateCol] ?? 0) : (int)($group['estimate_idx'] ?? 0),
                    'bg_idx' => $bgIdx,
                    'bill_number' => $billNumberCol !== null ? (int)($billRow[$billNumberCol] ?? 0) : 0,
                    'bill_type' => $billTypeCol !== null ? (string)($billRow[$billTypeCol] ?? '') : (string)($group['bill_type'] ?? ''),
                    'bill_status' => $billStatusCol !== null ? (string)($billRow[$billStatusCol] ?? '') : (string)($group['bill_status'] ?? ''),
                    'status' => $billStatusCol !== null ? (string)($billRow[$billStatusCol] ?? '') : (string)($group['bill_status'] ?? ''),
                    'amount' => $amount,
                    'total_amount' => $totalAmount,
                    'deposit_amount' => $collected,
                    'collected_amount' => $collected,
                    'remaining_amount' => max(0, $totalAmount - $collected),
                    'bring_date' => $billBringDateCol !== null ? ($billRow[$billBringDateCol] ?? null) : null,
                    'end_date' => $billEndDateCol !== null ? ($billRow[$billEndDateCol] ?? null) : null,
                    'deposit_date' => $billDepositDateCol !== null ? ($billRow[$billDepositDateCol] ?? null) : null,
                    'insert_date' => $billInsertDateCol !== null ? ($billRow[$billInsertDateCol] ?? null) : ($group['insert_date'] ?? null),
                    'memo' => $billMemoCol !== null ? (string)($billRow[$billMemoCol] ?? '') : (string)($group['memo'] ?? ''),
                    'employee_idx' => $billEmployeeCol !== null ? (int)($billRow[$billEmployeeCol] ?? 0) : (int)($group['employee_idx'] ?? 0),
                    'created_at' => $billCreatedCol !== null ? ($billRow[$billCreatedCol] ?? null) : null,
                    'updated_at' => $billUpdatedCol !== null ? ($billRow[$billUpdatedCol] ?? null) : null,
                    'group' => $group,
                ];

                ApiResponse::success([
                    'bill_idx' => (int)$detail['bill_idx'],
                    'item' => $detail,
                    'bill' => $detail,
                    'data' => $detail,
                ], 'OK', '수금 상세 조회 성공');
                exit;
            }
        }

        if ($tableExists($db, 'Tb_BillGroup')) {
            $bgTable = 'Tb_BillGroup';
            $bgIdxCol = $firstExistingColumn($db, $columnExists, $bgTable, ['idx', 'id']) ?? 'idx';
            $bgSiteCol = $firstExistingColumn($db, $columnExists, $bgTable, ['site_idx']);
            $bgMemberCol = $firstExistingColumn($db, $columnExists, $bgTable, ['member_idx']);
            $bgEstimateCol = $firstExistingColumn($db, $columnExists, $bgTable, ['estimate_idx']);
            $bgTypeCol = $firstExistingColumn($db, $columnExists, $bgTable, ['bill_type', 'type', 'gubun', 'kind']);
            $bgStatusCol = $firstExistingColumn($db, $columnExists, $bgTable, ['bill_status', 'status']);
            $bgAmountCol = $firstExistingColumn($db, $columnExists, $bgTable, ['total_amount', 'amount', 'bill_amount', 'price', 'supply_amount']);
            $bgEmployeeCol = $firstExistingColumn($db, $columnExists, $bgTable, ['employee_idx']);
            $bgInsertDateCol = $firstExistingColumn($db, $columnExists, $bgTable, ['insert_date', 'created_at', 'regdate']);
            $bgMemoCol = $firstExistingColumn($db, $columnExists, $bgTable, ['memo', 'note', 'comment']);

            $where = [$bgIdxCol . ' = ?'];
            $params = [$billIdx];
            if ($columnExists($db, $bgTable, 'is_deleted')) { $where[] = 'ISNULL(is_deleted,0)=0'; }
            if ($columnExists($db, $bgTable, 'service_code')) { $where[] = "ISNULL(service_code,'')=?"; $params[] = $svcCode; }
            if ($columnExists($db, $bgTable, 'tenant_id')) { $where[] = 'ISNULL(tenant_id,0)=?'; $params[] = $tenantId; }
            if ($siteIdx > 0 && $bgSiteCol !== null) { $where[] = 'ISNULL(TRY_CONVERT(INT,' . $bgSiteCol . '),0)=?'; $params[] = $siteIdx; }

            $stmt = $db->prepare('SELECT TOP 1 * FROM ' . $bgTable . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $bgRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($bgRow)) {
                $resolvedSiteIdx = $siteIdx > 0 ? $siteIdx : ($bgSiteCol !== null ? (int)($bgRow[$bgSiteCol] ?? 0) : 0);
                $totalAmount = $bgAmountCol !== null ? (int)($bgRow[$bgAmountCol] ?? 0) : 0;

                $detail = [
                    'idx' => (int)($bgRow[$bgIdxCol] ?? 0),
                    'bill_idx' => 0,
                    'site_idx' => $resolvedSiteIdx,
                    'member_idx' => $bgMemberCol !== null ? (int)($bgRow[$bgMemberCol] ?? 0) : 0,
                    'estimate_idx' => $bgEstimateCol !== null ? (int)($bgRow[$bgEstimateCol] ?? 0) : 0,
                    'bg_idx' => (int)($bgRow[$bgIdxCol] ?? 0),
                    'bill_number' => 0,
                    'bill_type' => $bgTypeCol !== null ? (string)($bgRow[$bgTypeCol] ?? '') : '',
                    'bill_status' => $bgStatusCol !== null ? (string)($bgRow[$bgStatusCol] ?? '') : '',
                    'status' => $bgStatusCol !== null ? (string)($bgRow[$bgStatusCol] ?? '') : '',
                    'amount' => $totalAmount,
                    'total_amount' => $totalAmount,
                    'deposit_amount' => 0,
                    'collected_amount' => 0,
                    'remaining_amount' => $totalAmount,
                    'bring_date' => null,
                    'end_date' => null,
                    'deposit_date' => null,
                    'insert_date' => $bgInsertDateCol !== null ? ($bgRow[$bgInsertDateCol] ?? null) : null,
                    'memo' => $bgMemoCol !== null ? (string)($bgRow[$bgMemoCol] ?? '') : '',
                    'employee_idx' => $bgEmployeeCol !== null ? (int)($bgRow[$bgEmployeeCol] ?? 0) : 0,
                    'created_at' => $bgInsertDateCol !== null ? ($bgRow[$bgInsertDateCol] ?? null) : null,
                    'updated_at' => null,
                    'group' => null,
                ];

                ApiResponse::success([
                    'bill_idx' => 0,
                    'item' => $detail,
                    'bill' => $detail,
                    'data' => $detail,
                ], 'OK', '수금 상세 조회 성공');
                exit;
            }
        }

        ApiResponse::error('NOT_FOUND', '수금 정보를 찾을 수 없습니다', 404);
        exit;
    }

    if ($todo === 'insert_bill' || $todo === 'bill_insert') {
        $siteIdx = (int)($_POST['site_idx'] ?? $_POST['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }
        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $memberIdx = FmsInputValidator::int($_POST, 'member_idx', false, 0) ?? (int)($site['member_idx'] ?? 0);
        $estimateIdx = FmsInputValidator::int($_POST, 'estimate_idx', false, 0) ?? 0;
        $bgIdx = FmsInputValidator::int($_POST, 'bg_idx', false, 0) ?? 0;
        $amount = $parseIntAmount($_POST['amount'] ?? '0');
        $totalAmount = $parseIntAmount($_POST['total_amount'] ?? $_POST['amount'] ?? '0');
        if ($amount <= 0) {
            ApiResponse::error('INVALID_PARAM', 'amount must be greater than 0', 422);
            exit;
        }

        $statusRaw = trim((string)($_POST['status'] ?? $_POST['bill_status'] ?? '1'));
        if ($statusRaw === '') {
            $statusRaw = '1';
        }
        $employeeIdx = FmsInputValidator::int($_POST, 'employee_idx', false, 0) ?? 0;
        $billNumber = FmsInputValidator::int($_POST, 'bill_number', false, 1) ?? 1;
        $bringDate = FmsInputValidator::string($_POST, 'bring_date', 20, false);
        $endDate = FmsInputValidator::string($_POST, 'end_date', 20, false);
        $depositDate = FmsInputValidator::string($_POST, 'deposit_date', 20, false);
        $insertDate = FmsInputValidator::string($_POST, 'insert_date', 20, false);
        $memo = FmsInputValidator::string($_POST, 'memo', 2000, false);
        if ($insertDate === '') {
            $insertDate = date('Y-m-d');
        }

        $billTableExists = $tableExists($db, 'Tb_Bill');
        $billGroupTableExists = $tableExists($db, 'Tb_BillGroup');
        if (!$billTableExists && !$billGroupTableExists) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Bill or Tb_BillGroup table is missing', 503);
            exit;
        }

        $billUploaded = [];
        $billRejected = [];
        $billAttachSaved = 0;
        try {
            $db->beginTransaction();

            if ($billGroupTableExists) {
                $bgIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_BillGroup', ['idx', 'id']) ?? 'idx';
                $bgSiteCol = $firstExistingColumn($db, $columnExists, 'Tb_BillGroup', ['site_idx']);
                $bgEstimateCol = $firstExistingColumn($db, $columnExists, 'Tb_BillGroup', ['estimate_idx']);
                if ($bgIdx <= 0 && $bgSiteCol !== null && $bgEstimateCol !== null && $estimateIdx > 0) {
                    $where = ["ISNULL(TRY_CONVERT(INT, {$bgSiteCol}),0)=?", "ISNULL(TRY_CONVERT(INT, {$bgEstimateCol}),0)=?"];
                    $params = [$siteIdx, $estimateIdx];
                    if ($columnExists($db, 'Tb_BillGroup', 'is_deleted')) {
                        $where[] = 'ISNULL(is_deleted,0)=0';
                    }
                    $dupStmt = $db->prepare('SELECT TOP 1 ' . $bgIdxCol . ' FROM Tb_BillGroup WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $bgIdxCol . ' DESC');
                    $dupStmt->execute($params);
                    $bgIdx = (int)$dupStmt->fetchColumn();
                }

                if ($bgIdx <= 0) {
                    $bgCols = [];
                    $bgVals = [];
                    if ($columnExists($db, 'Tb_BillGroup', 'member_idx')) { $bgCols[] = 'member_idx'; $bgVals[] = $memberIdx; }
                    if ($columnExists($db, 'Tb_BillGroup', 'site_idx')) { $bgCols[] = 'site_idx'; $bgVals[] = $siteIdx; }
                    if ($columnExists($db, 'Tb_BillGroup', 'estimate_idx')) { $bgCols[] = 'estimate_idx'; $bgVals[] = $estimateIdx; }
                    if ($columnExists($db, 'Tb_BillGroup', 'total_amount')) { $bgCols[] = 'total_amount'; $bgVals[] = $totalAmount > 0 ? $totalAmount : $amount; }
                    if ($columnExists($db, 'Tb_BillGroup', 'employee_idx')) { $bgCols[] = 'employee_idx'; $bgVals[] = $employeeIdx; }
                    if ($columnExists($db, 'Tb_BillGroup', 'insert_date')) { $bgCols[] = 'insert_date'; $bgVals[] = $insertDate; }
                    if ($columnExists($db, 'Tb_BillGroup', 'memo')) { $bgCols[] = 'memo'; $bgVals[] = $memo; }
                    if ($columnExists($db, 'Tb_BillGroup', 'is_deleted')) { $bgCols[] = 'is_deleted'; $bgVals[] = 0; }
                    if ($columnExists($db, 'Tb_BillGroup', 'service_code')) { $bgCols[] = 'service_code'; $bgVals[] = (string)($context['service_code'] ?? 'shvq'); }
                    if ($columnExists($db, 'Tb_BillGroup', 'tenant_id')) { $bgCols[] = 'tenant_id'; $bgVals[] = (int)($context['tenant_id'] ?? 0); }
                    if ($columnExists($db, 'Tb_BillGroup', 'created_by')) { $bgCols[] = 'created_by'; $bgVals[] = (int)($context['user_pk'] ?? 0); }
                    if ($columnExists($db, 'Tb_BillGroup', 'created_at')) { $bgCols[] = 'created_at'; $bgVals[] = date('Y-m-d H:i:s'); }
                    if ($columnExists($db, 'Tb_BillGroup', 'regdate')) { $bgCols[] = 'regdate'; $bgVals[] = date('Y-m-d H:i:s'); }

                    if ($bgCols !== []) {
                        $ph = implode(', ', array_fill(0, count($bgCols), '?'));
                        $bgInsert = $db->prepare('INSERT INTO Tb_BillGroup (' . implode(', ', $bgCols) . ') VALUES (' . $ph . ')');
                        $bgInsert->execute($bgVals);
                        $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
                        $bgIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
                    }
                }

                if ($bgIdx <= 0 && $billTableExists) {
                    throw new RuntimeException('failed to resolve bill group idx');
                }
            }

            $billIdx = 0;
            if ($billTableExists) {
                $billIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['idx', 'id']) ?? 'idx';
                $billReferCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['refer_idx', 'billgroup_idx', 'bg_idx']);
                $billNumberCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['number', 'bill_number', 'seq']);
                $billAmountCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['amount', 'bill_amount', 'price', 'total_amount', 'supply_amount']);
                $billStatusCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['status', 'bill_status']);
                $billEmployeeCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['employee_idx']);
                $billBringDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['bring_date', 'bill_date']);
                $billEndDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['end_date']);
                $billDepositDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['deposit_date']);
                $billInsertDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['insert_date']);
                $billMemoCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['memo', 'note', 'comment', 'contents']);

                if ($billNumberCol !== null && $bgIdx > 0 && $billReferCol !== null && ($billNumber <= 0 || !array_key_exists('bill_number', $_POST))) {
                    $numStmt = $db->prepare('SELECT ISNULL(MAX(TRY_CONVERT(INT,' . $billNumberCol . ')),0)+1 FROM Tb_Bill WHERE ISNULL(TRY_CONVERT(INT,' . $billReferCol . '),0)=?');
                    $numStmt->execute([$bgIdx]);
                    $billNumber = max(1, (int)$numStmt->fetchColumn());
                }

                $billCols = [];
                $billVals = [];
                if ($billReferCol !== null && $bgIdx > 0) { $billCols[] = $billReferCol; $billVals[] = $bgIdx; }
                if ($billNumberCol !== null) { $billCols[] = $billNumberCol; $billVals[] = max(1, $billNumber); }
                if ($billAmountCol !== null) { $billCols[] = $billAmountCol; $billVals[] = $amount; }
                if ($billStatusCol !== null) { $billCols[] = $billStatusCol; $billVals[] = ctype_digit($statusRaw) ? (int)$statusRaw : $statusRaw; }
                if ($billEmployeeCol !== null) { $billCols[] = $billEmployeeCol; $billVals[] = $employeeIdx; }
                if ($billBringDateCol !== null && $bringDate !== '') { $billCols[] = $billBringDateCol; $billVals[] = $bringDate; }
                if ($billEndDateCol !== null && $endDate !== '') { $billCols[] = $billEndDateCol; $billVals[] = $endDate; }
                if ($billDepositDateCol !== null && $depositDate !== '') { $billCols[] = $billDepositDateCol; $billVals[] = $depositDate; }
                if ($billInsertDateCol !== null) { $billCols[] = $billInsertDateCol; $billVals[] = $insertDate; }
                if ($billMemoCol !== null && $memo !== '') { $billCols[] = $billMemoCol; $billVals[] = $memo; }
                if ($columnExists($db, 'Tb_Bill', 'site_idx')) { $billCols[] = 'site_idx'; $billVals[] = $siteIdx; }
                if ($columnExists($db, 'Tb_Bill', 'member_idx')) { $billCols[] = 'member_idx'; $billVals[] = $memberIdx; }
                if ($columnExists($db, 'Tb_Bill', 'estimate_idx')) { $billCols[] = 'estimate_idx'; $billVals[] = $estimateIdx; }
                if ($columnExists($db, 'Tb_Bill', 'is_deleted')) { $billCols[] = 'is_deleted'; $billVals[] = 0; }
                if ($columnExists($db, 'Tb_Bill', 'service_code')) { $billCols[] = 'service_code'; $billVals[] = (string)($context['service_code'] ?? 'shvq'); }
                if ($columnExists($db, 'Tb_Bill', 'tenant_id')) { $billCols[] = 'tenant_id'; $billVals[] = (int)($context['tenant_id'] ?? 0); }
                if ($columnExists($db, 'Tb_Bill', 'created_by')) { $billCols[] = 'created_by'; $billVals[] = (int)($context['user_pk'] ?? 0); }
                if ($columnExists($db, 'Tb_Bill', 'created_at')) { $billCols[] = 'created_at'; $billVals[] = date('Y-m-d H:i:s'); }
                if ($columnExists($db, 'Tb_Bill', 'regdate')) { $billCols[] = 'regdate'; $billVals[] = date('Y-m-d H:i:s'); }

                if ($billCols === []) {
                    throw new RuntimeException('insertable bill columns not found');
                }

                $ph = implode(', ', array_fill(0, count($billCols), '?'));
                $billInsert = $db->prepare('INSERT INTO Tb_Bill (' . implode(', ', $billCols) . ') VALUES (' . $ph . ')');
                $billInsert->execute($billVals);
                $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
                $billIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
                if ($billIdx <= 0) {
                    $findStmt = $db->prepare('SELECT TOP 1 ' . $billIdxCol . ' FROM Tb_Bill ORDER BY ' . $billIdxCol . ' DESC');
                    $findStmt->execute();
                    $billIdx = (int)$findStmt->fetchColumn();
                }
            }

            $billFiles = $extractUploadedFiles($_FILES);
            if ($billFiles !== []) {
                $tenantId = (int)($context['tenant_id'] ?? 0);
                if ($tenantId <= 0) {
                    foreach ($billFiles as $file) {
                        $billRejected[] = [
                            'name' => (string)($file['name'] ?? ''),
                            'reason' => 'invalid_scope',
                            'message' => 'tenant_id is required for upload',
                        ];
                    }
                } else {
                    $storage = StorageService::forTenant($tenantId);
                    $targetAttachTable = $billIdx > 0 ? 'Tb_Bill' : 'Tb_BillGroup';
                    $targetAttachIdx = $billIdx > 0 ? $billIdx : $bgIdx;
                    $attachCategory = 'bill_attach';

                    foreach ($billFiles as $file) {
                        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                            $billRejected[] = [
                                'name' => (string)($file['name'] ?? ''),
                                'reason' => 'upload_error',
                                'error_code' => (int)($file['error'] ?? 0),
                            ];
                            continue;
                        }
                        try {
                            $result = $storage->put($attachCategory, $file, 'site_' . $siteIdx . '_bill_' . max(0, $targetAttachIdx));
                            $item = [
                                'original_name' => (string)($file['name'] ?? ''),
                                'filename' => (string)($result['filename'] ?? ''),
                                'file_url' => (string)($result['url'] ?? ''),
                                'file_size' => (int)($result['size'] ?? ($file['size'] ?? 0)),
                                'created_at' => date('Y-m-d H:i:s'),
                            ];

                            if ($tableExists($db, 'Tb_FileAttach')) {
                                $cols = [];
                                $vals = [];
                                $add = static function (string $col, mixed $val) use (&$cols, &$vals, $db, $columnExists): void {
                                    if ($columnExists($db, 'Tb_FileAttach', $col)) {
                                        $cols[] = $col;
                                        $vals[] = $val;
                                    }
                                };

                                $add('table_name', $targetAttachTable);
                                $add('to_table', $targetAttachTable);
                                $add('target_table', $targetAttachTable);
                                $add('table_idx', $targetAttachIdx);
                                $add('ref_idx', $targetAttachIdx);
                                $add('target_idx', $targetAttachIdx);
                                $add('bill_idx', $billIdx);
                                $add('bg_idx', $bgIdx);
                                $add('site_idx', $siteIdx);
                                $add('member_idx', $memberIdx);
                                $add('category', $attachCategory);
                                $add('subject', $attachCategory);
                                $add('type', $attachCategory);
                                $add('file_name', (string)($file['name'] ?? ''));
                                $add('original_name', (string)($file['name'] ?? ''));
                                $add('origin_name', (string)($file['name'] ?? ''));
                                $add('filename', (string)($result['filename'] ?? ''));
                                $add('save_name', (string)($result['filename'] ?? ''));
                                $add('stored_name', (string)($result['filename'] ?? ''));
                                $add('file_url', (string)($result['url'] ?? ''));
                                $add('url', (string)($result['url'] ?? ''));
                                $add('file_path', (string)($result['path'] ?? ''));
                                $add('path', (string)($result['path'] ?? ''));
                                $add('file_size', (int)($result['size'] ?? ($file['size'] ?? 0)));
                                $add('mime_type', (string)($result['mime'] ?? ($file['type'] ?? '')));
                                $add('file_ext', strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)));
                                $add('employee_idx', (int)($context['employee_idx'] ?? 0));
                                $add('writer_idx', (int)($context['employee_idx'] ?? 0));
                                $add('created_by', (int)($context['user_pk'] ?? 0));
                                $add('service_code', (string)($context['service_code'] ?? 'shvq'));
                                $add('tenant_id', $tenantId);
                                $add('is_deleted', 0);
                                $add('created_at', date('Y-m-d H:i:s'));
                                $add('regdate', date('Y-m-d H:i:s'));

                                if ($cols !== []) {
                                    $ph = implode(', ', array_fill(0, count($cols), '?'));
                                    $stmt = $db->prepare('INSERT INTO Tb_FileAttach (' . implode(', ', $cols) . ') VALUES (' . $ph . ')');
                                    $stmt->execute($vals);
                                    $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
                                    $fileIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
                                    if ($fileIdx > 0) {
                                        $item['file_idx'] = $fileIdx;
                                    }
                                    $billAttachSaved++;
                                }
                            }

                            $item['file_url'] = $normalizePublicUrl((string)($item['file_url'] ?? ''));
                            $billUploaded[] = $item;
                        } catch (Throwable $e) {
                            $billRejected[] = [
                                'name' => (string)($file['name'] ?? ''),
                                'reason' => 'upload_failed',
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                }
            }

            $db->commit();

            $targetTable = $billIdx > 0 ? 'Tb_Bill' : 'Tb_BillGroup';
            $targetIdx = $billIdx > 0 ? $billIdx : $bgIdx;
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'insert_bill', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => $targetTable,
                'target_idx' => $targetIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'bill inserted',
                'site_idx' => $siteIdx,
                'bg_idx' => $bgIdx,
                'bill_idx' => $billIdx,
                'amount' => $amount,
                'uploaded_count' => count($billUploaded),
                'rejected_count' => count($billRejected),
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'insert_bill', $targetTable, $targetIdx, [
                'site_idx' => $siteIdx,
                'bg_idx' => $bgIdx,
                'bill_idx' => $billIdx,
                'estimate_idx' => $estimateIdx,
                'amount' => $amount,
                'uploaded_count' => count($billUploaded),
                'rejected_count' => count($billRejected),
            ]);

            ApiResponse::success([
                'site_idx' => $siteIdx,
                'bg_idx' => $bgIdx,
                'bill_idx' => $billIdx,
                'amount' => $amount,
                'uploaded' => $billUploaded,
                'uploaded_count' => count($billUploaded),
                'rejected' => $billRejected,
                'rejected_count' => count($billRejected),
                'file_meta_saved_count' => $billAttachSaved,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '수금 등록 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'update_bill' || $todo === 'bill_update') {
        $billIdx = (int)($_POST['bill_idx'] ?? $_POST['idx'] ?? 0);
        if ($billIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'bill_idx is required', 422);
            exit;
        }
        if (!$tableExists($db, 'Tb_Bill')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Bill table is missing', 503);
            exit;
        }

        $billIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['idx', 'id']) ?? 'idx';
        $billReferCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['refer_idx', 'billgroup_idx', 'bg_idx']);
        $billNumberCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['bill_number', 'number', 'seq']);
        $billEstimateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['estimate_idx', 'site_estimate_idx', 'est_idx']);
        $billAmountCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['amount', 'bill_amount', 'price', 'total_amount', 'supply_amount']);
        $billStatusCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['status', 'bill_status']);
        $billBringDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['bring_date', 'bill_date']);
        $billEndDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['end_date']);
        $billDepositDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['deposit_date']);
        $billInsertDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['insert_date']);
        $billEmployeeCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['employee_idx']);
        $billMemoCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['memo', 'note', 'comment', 'contents']);

        $findWhere = [$billIdxCol . ' = ?'];
        if ($columnExists($db, 'Tb_Bill', 'is_deleted')) {
            $findWhere[] = 'ISNULL(is_deleted,0)=0';
        }
        $findStmt = $db->prepare('SELECT TOP 1 * FROM Tb_Bill WHERE ' . implode(' AND ', $findWhere));
        $findStmt->execute([$billIdx]);
        $current = $findStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            ApiResponse::error('NOT_FOUND', '수금 정보를 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = [];
        $params = [];
        if ($billAmountCol !== null && array_key_exists('amount', $_POST)) {
            $setSql[] = $billAmountCol . ' = ?';
            $params[] = $parseIntAmount($_POST['amount']);
        }
        if ($billNumberCol !== null && array_key_exists('bill_number', $_POST)) {
            $setSql[] = $billNumberCol . ' = ?';
            $params[] = max(1, (int)($_POST['bill_number'] ?? 1));
        }
        if ($billEstimateCol !== null && array_key_exists('estimate_idx', $_POST)) {
            $setSql[] = $billEstimateCol . ' = ?';
            $params[] = FmsInputValidator::int($_POST, 'estimate_idx', false, 0) ?? 0;
        }
        if ($billStatusCol !== null && (array_key_exists('status', $_POST) || array_key_exists('bill_status', $_POST))) {
            $statusRaw = trim((string)($_POST['status'] ?? $_POST['bill_status'] ?? ''));
            if ($statusRaw !== '') {
                $setSql[] = $billStatusCol . ' = ?';
                $params[] = ctype_digit($statusRaw) ? (int)$statusRaw : $statusRaw;
            }
        }
        if ($billBringDateCol !== null && array_key_exists('bring_date', $_POST)) {
            $setSql[] = $billBringDateCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'bring_date', 20, false);
        }
        if ($billEndDateCol !== null && array_key_exists('end_date', $_POST)) {
            $setSql[] = $billEndDateCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'end_date', 20, false);
        }
        if ($billDepositDateCol !== null && array_key_exists('deposit_date', $_POST)) {
            $setSql[] = $billDepositDateCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'deposit_date', 20, false);
        }
        if ($billInsertDateCol !== null && array_key_exists('insert_date', $_POST)) {
            $setSql[] = $billInsertDateCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'insert_date', 20, false);
        }
        if ($billEmployeeCol !== null && array_key_exists('employee_idx', $_POST)) {
            $setSql[] = $billEmployeeCol . ' = ?';
            $params[] = FmsInputValidator::int($_POST, 'employee_idx', false, 0);
        }
        if ($billMemoCol !== null && array_key_exists('memo', $_POST)) {
            $setSql[] = $billMemoCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'memo', 2000, false);
        }

        $todoOption = trim((string)($_POST['todos'] ?? ''));
        if ($todoOption === 'next_month') {
            $nextM = date('Y-m-d', strtotime('+1 month', strtotime(date('Y-m-15'))));
            if ($billDepositDateCol !== null) { $setSql[] = $billDepositDateCol . ' = ?'; $params[] = $nextM; }
            if ($billBringDateCol !== null) { $setSql[] = $billBringDateCol . ' = ?'; $params[] = date('Y-m-t', strtotime($nextM)); }
            if ($billStatusCol !== null) {
                $statusCurrent = (string)($current[$billStatusCol] ?? '');
                if ($statusCurrent === '5') {
                    $setSql[] = $billStatusCol . ' = ?';
                    $params[] = 6;
                }
            }
        }

        if ($setSql === []) {
            ApiResponse::error('INVALID_PARAM', 'no updatable fields provided', 422);
            exit;
        }
        if ($columnExists($db, 'Tb_Bill', 'updated_at')) { $setSql[] = 'updated_at = ?'; $params[] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, 'Tb_Bill', 'updated_by')) { $setSql[] = 'updated_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }
        $params[] = $billIdx;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE Tb_Bill SET ' . implode(', ', $setSql) . ' WHERE ' . $billIdxCol . ' = ?');
            $stmt->execute($params);

            $bgIdx = (int)($_POST['bg_idx'] ?? ($billReferCol !== null ? (int)($current[$billReferCol] ?? 0) : 0));
            if ($bgIdx > 0 && $tableExists($db, 'Tb_BillGroup')) {
                $bgSet = [];
                $bgParams = [];
                if ($columnExists($db, 'Tb_BillGroup', 'estimate_idx') && array_key_exists('estimate_idx', $_POST)) {
                    $bgSet[] = 'estimate_idx = ?';
                    $bgParams[] = FmsInputValidator::int($_POST, 'estimate_idx', false, 0);
                }
                if ($columnExists($db, 'Tb_BillGroup', 'total_amount') && array_key_exists('total_amount', $_POST)) {
                    $bgSet[] = 'total_amount = ?';
                    $bgParams[] = $parseIntAmount($_POST['total_amount']);
                }
                if ($columnExists($db, 'Tb_BillGroup', 'employee_idx') && array_key_exists('employee_idx', $_POST)) {
                    $bgSet[] = 'employee_idx = ?';
                    $bgParams[] = FmsInputValidator::int($_POST, 'employee_idx', false, 0);
                }
                if ($columnExists($db, 'Tb_BillGroup', 'updated_at')) { $bgSet[] = 'updated_at = ?'; $bgParams[] = date('Y-m-d H:i:s'); }
                if ($columnExists($db, 'Tb_BillGroup', 'updated_by')) { $bgSet[] = 'updated_by = ?'; $bgParams[] = (int)($context['user_pk'] ?? 0); }
                if ($bgSet !== []) {
                    $bgParams[] = $bgIdx;
                    $bgStmt = $db->prepare('UPDATE Tb_BillGroup SET ' . implode(', ', $bgSet) . ' WHERE idx = ?');
                    $bgStmt->execute($bgParams);
                }
            }
            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'update_bill', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => 'Tb_Bill',
                'target_idx' => $billIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'bill updated',
                'bill_idx' => $billIdx,
                'bg_idx' => $bgIdx,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'update_bill', 'Tb_Bill', $billIdx, [
                'bill_idx' => $billIdx,
                'bg_idx' => $bgIdx,
            ]);

            ApiResponse::success([
                'bill_idx' => $billIdx,
                'bg_idx' => $bgIdx,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '수금 수정 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'deposit_bill' || $todo === 'bill_deposit') {
        $billIdx = (int)($_POST['bill_idx'] ?? $_POST['idx'] ?? 0);
        if ($billIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'bill_idx is required', 422);
            exit;
        }
        if (!$tableExists($db, 'Tb_Bill')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Bill table is missing', 503);
            exit;
        }

        $billIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['idx', 'id']) ?? 'idx';
        $amountCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['amount', 'bill_amount', 'price', 'total_amount', 'supply_amount']);
        $statusCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['status', 'bill_status']);
        $depositDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['deposit_date']);
        $depositTypeCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['deposit_type']);
        $depositAmountCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['deposit_amount', 'paid_amount', 'collect_amount', 'received_amount']);
        $depositRealDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['deposit_real_date', 'paid_date', 'received_date']);
        $depositEmployeeCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['deposit_employee_idx', 'paid_employee_idx', 'received_employee_idx']);
        $depositNameCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['deposit_name', 'payer_name', 'sender_name']);

        $findStmt = $db->prepare('SELECT TOP 1 * FROM Tb_Bill WHERE ' . $billIdxCol . ' = ?' . ($columnExists($db, 'Tb_Bill', 'is_deleted') ? ' AND ISNULL(is_deleted,0)=0' : ''));
        $findStmt->execute([$billIdx]);
        $current = $findStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            ApiResponse::error('NOT_FOUND', '수금 정보를 찾을 수 없습니다', 404);
            exit;
        }

        $amount = $parseIntAmount($_POST['amount'] ?? ($amountCol !== null ? ($current[$amountCol] ?? 0) : 0));
        $depositAmount = $parseIntAmount($_POST['deposit_amount'] ?? '0');
        if ($depositAmount <= 0) {
            ApiResponse::error('INVALID_PARAM', 'deposit_amount must be greater than 0', 422);
            exit;
        }
        if ($amount > 0 && $depositAmount > $amount) {
            ApiResponse::error('INVALID_PARAM', 'deposit_amount cannot exceed amount', 422);
            exit;
        }

        $depositType = FmsInputValidator::int($_POST, 'deposit_type', false, 0) ?? 0;
        $depositRealDate = FmsInputValidator::string($_POST, 'deposit_real_date', 20, false);
        if ($depositRealDate === '') {
            $depositRealDate = date('Y-m-d');
        }
        $depositEmployeeIdx = FmsInputValidator::int($_POST, 'deposit_employee_idx', false, 0) ?? (int)($context['employee_idx'] ?? 0);
        $depositNameInputKey = array_key_exists('depositor_name', $_POST) ? 'depositor_name' : 'deposit_name';
        $depositName = FmsInputValidator::string($_POST, $depositNameInputKey, 120, false);
        $depositDate = trim((string)($depositDateCol !== null ? ($current[$depositDateCol] ?? '') : ''));

        $newStatus = null;
        if ($amount > 0 && $depositAmount >= $amount) {
            $newStatus = '4';
        } elseif ($depositDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $depositDate) === 1 && $depositDate < date('Y-m-d')) {
            $newStatus = '5';
        }

        $setSql = [];
        $params = [];
        if ($depositTypeCol !== null) { $setSql[] = $depositTypeCol . ' = ?'; $params[] = $depositType; }
        if ($depositAmountCol !== null) { $setSql[] = $depositAmountCol . ' = ?'; $params[] = $depositAmount; }
        if ($depositRealDateCol !== null) { $setSql[] = $depositRealDateCol . ' = ?'; $params[] = $depositRealDate; }
        if ($depositEmployeeCol !== null) { $setSql[] = $depositEmployeeCol . ' = ?'; $params[] = $depositEmployeeIdx; }
        if ($depositNameCol !== null) { $setSql[] = $depositNameCol . ' = ?'; $params[] = $depositName; }
        if ($statusCol !== null && $newStatus !== null) {
            $setSql[] = $statusCol . ' = ?';
            $params[] = ctype_digit($newStatus) ? (int)$newStatus : $newStatus;
        }
        if ($columnExists($db, 'Tb_Bill', 'updated_at')) { $setSql[] = 'updated_at = ?'; $params[] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, 'Tb_Bill', 'updated_by')) { $setSql[] = 'updated_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }
        if ($setSql === []) {
            ApiResponse::error('SCHEMA_NOT_READY', 'updatable deposit columns are missing', 503);
            exit;
        }
        $params[] = $billIdx;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE Tb_Bill SET ' . implode(', ', $setSql) . ' WHERE ' . $billIdxCol . ' = ?');
            $stmt->execute($params);
            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'deposit_bill', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => 'Tb_Bill',
                'target_idx' => $billIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'bill deposited',
                'bill_idx' => $billIdx,
                'deposit_amount' => $depositAmount,
                'status' => $newStatus ?? '',
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'deposit_bill', 'Tb_Bill', $billIdx, [
                'bill_idx' => $billIdx,
                'deposit_amount' => $depositAmount,
                'status' => $newStatus ?? '',
            ]);

            ApiResponse::success([
                'bill_idx' => $billIdx,
                'deposit_amount' => $depositAmount,
                'status' => $newStatus !== null ? (ctype_digit($newStatus) ? (int)$newStatus : $newStatus) : null,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '입금 처리 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'bill_comment_list') {
        $billIdx = (int)($_GET['bill_idx'] ?? $_GET['idx'] ?? 0);
        if ($billIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'bill_idx is required', 422);
            exit;
        }

        $commentTable = null;
        if ($tableExists($db, 'Tb_BillComment')) {
            $commentTable = 'Tb_BillComment';
        } elseif ($tableExists($db, 'Tb_Comment')) {
            $commentTable = 'Tb_Comment';
        }
        if ($commentTable === null) {
            ApiResponse::success(['bill_idx' => $billIdx, 'data' => [], 'total' => 0], 'OK', '수금 코멘트 조회 성공');
            exit;
        }

        $idxCol = $firstExistingColumn($db, $columnExists, $commentTable, ['idx', 'id']) ?? 'idx';
        $contentCol = $firstExistingColumn($db, $columnExists, $commentTable, ['content', 'comment', 'memo', 'message', 'contents', 'msg']);
        $employeeCol = $firstExistingColumn($db, $columnExists, $commentTable, ['employee_idx', 'writer_idx']);
        $createdCol = $firstExistingColumn($db, $columnExists, $commentTable, ['created_at', 'regdate', 'registered_date', 'insert_date']);

        $where = [];
        $params = [];
        $billCol = $firstExistingColumn($db, $columnExists, $commentTable, ['bill_idx', 'target_idx', 'ref_idx']);
        if ($billCol !== null) {
            $where[] = 'ISNULL(TRY_CONVERT(INT,c.' . $billCol . '),0)=?';
            $params[] = $billIdx;
        } elseif ($columnExists($db, $commentTable, 'to_table') && $columnExists($db, $commentTable, 'to_idx')) {
            $where[] = "LOWER(CAST(c.to_table AS NVARCHAR(100))) IN ('tb_bill','bill')";
            $where[] = 'ISNULL(TRY_CONVERT(INT,c.to_idx),0)=?';
            $params[] = $billIdx;
        } else {
            ApiResponse::success(['bill_idx' => $billIdx, 'data' => [], 'total' => 0], 'OK', '수금 코멘트 조회 성공');
            exit;
        }
        if ($columnExists($db, $commentTable, 'is_deleted')) {
            $where[] = 'ISNULL(c.is_deleted,0)=0';
        }
        if ($columnExists($db, $commentTable, 'service_code')) {
            $where[] = "ISNULL(c.service_code,'')=?";
            $params[] = (string)($context['service_code'] ?? 'shvq');
        }
        if ($columnExists($db, $commentTable, 'tenant_id')) {
            $where[] = 'ISNULL(c.tenant_id,0)=?';
            $params[] = (int)($context['tenant_id'] ?? 0);
        }

        $joinEmployee = '';
        $employeeNameExpr = "CAST('' AS NVARCHAR(120))";
        if ($employeeCol !== null && $tableExists($db, 'Tb_Employee')) {
            $empIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['idx', 'id']);
            $empNameCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['name', 'employee_name']);
            if ($empIdxCol !== null && $empNameCol !== null) {
                $joinEmployee = " LEFT JOIN Tb_Employee e ON e.{$empIdxCol} = c.{$employeeCol}";
                if ($columnExists($db, 'Tb_Employee', 'is_deleted')) {
                    $joinEmployee .= ' AND ISNULL(e.is_deleted,0)=0';
                }
                $employeeNameExpr = "ISNULL(CAST(e.{$empNameCol} AS NVARCHAR(120)), '')";
            }
        }

        $contentExpr = $contentCol !== null ? 'ISNULL(CAST(c.' . $contentCol . " AS NVARCHAR(4000)),'')" : "CAST('' AS NVARCHAR(4000))";
        $createdExpr = $createdCol !== null ? 'c.' . $createdCol : 'NULL';

        $sql = "SELECT\n"
            . "  ISNULL(TRY_CONVERT(BIGINT,c.{$idxCol}),0) AS idx,\n"
            . "  {$contentExpr} AS content,\n"
            . "  {$contentExpr} AS comment,\n"
            . "  {$employeeNameExpr} AS employee_name,\n"
            . "  {$employeeNameExpr} AS author,\n"
            . "  {$createdExpr} AS created_at\n"
            . ' FROM ' . $commentTable . ' c' . $joinEmployee
            . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY ISNULL(TRY_CONVERT(DATETIME, {$createdExpr}), '1900-01-01') ASC, c.{$idxCol} ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        ApiResponse::success([
            'bill_idx' => $billIdx,
            'data' => $rows,
            'total' => count($rows),
        ], 'OK', '수금 코멘트 조회 성공');
        exit;
    }

    if ($todo === 'insert_bill_comment') {
        $billIdx = (int)($_POST['bill_idx'] ?? $_POST['idx'] ?? 0);
        if ($billIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'bill_idx is required', 422);
            exit;
        }
        $content = trim((string)($_POST['content'] ?? $_POST['comment'] ?? $_POST['memo'] ?? ''));
        if ($content === '') {
            ApiResponse::error('INVALID_PARAM', 'content is required', 422);
            exit;
        }

        if (!$tableExists($db, 'Tb_Bill')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Bill table is missing', 503);
            exit;
        }
        $billIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['idx', 'id']) ?? 'idx';
        $billMemberCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['member_idx']);
        $billSiteCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['site_idx']);
        $billFindSql = 'SELECT TOP 1 '
            . $billIdxCol . ' AS bill_idx, '
            . ($billMemberCol !== null ? 'ISNULL(TRY_CONVERT(INT,' . $billMemberCol . '),0)' : '0') . ' AS member_idx, '
            . ($billSiteCol !== null ? 'ISNULL(TRY_CONVERT(INT,' . $billSiteCol . '),0)' : '0') . ' AS site_idx'
            . ' FROM Tb_Bill WHERE ' . $billIdxCol . ' = ?'
            . ($columnExists($db, 'Tb_Bill', 'is_deleted') ? ' AND ISNULL(is_deleted,0)=0' : '');
        $billFind = $db->prepare($billFindSql);
        $billFind->execute([$billIdx]);
        $billRow = $billFind->fetch(PDO::FETCH_ASSOC);
        if (!is_array($billRow)) {
            ApiResponse::error('NOT_FOUND', '수금 정보를 찾을 수 없습니다', 404);
            exit;
        }

        $commentTable = null;
        if ($tableExists($db, 'Tb_BillComment')) {
            $commentTable = 'Tb_BillComment';
        } elseif ($tableExists($db, 'Tb_Comment')) {
            $commentTable = 'Tb_Comment';
        }
        if ($commentTable === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'bill comment table is missing', 503);
            exit;
        }

        $contentCol = $firstExistingColumn($db, $columnExists, $commentTable, ['content', 'comment', 'memo', 'message', 'contents', 'msg']);
        if ($contentCol === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'comment content column is missing', 503);
            exit;
        }

        $cols = [];
        $vals = [];
        $add = static function (string $col, mixed $val) use (&$cols, &$vals, $db, $columnExists, $commentTable): void {
            if ($columnExists($db, $commentTable, $col)) {
                $cols[] = $col;
                $vals[] = $val;
            }
        };

        $add($contentCol, $content);
        if ($columnExists($db, $commentTable, 'bill_idx')) { $add('bill_idx', $billIdx); }
        if ($columnExists($db, $commentTable, 'target_idx')) { $add('target_idx', $billIdx); }
        if ($columnExists($db, $commentTable, 'ref_idx')) { $add('ref_idx', $billIdx); }
        if ($columnExists($db, $commentTable, 'to_table')) { $add('to_table', 'Tb_Bill'); }
        if ($columnExists($db, $commentTable, 'to_idx')) { $add('to_idx', $billIdx); }
        if ($columnExists($db, $commentTable, 'site_idx')) { $add('site_idx', (int)($billRow['site_idx'] ?? 0)); }
        if ($columnExists($db, $commentTable, 'member_idx')) { $add('member_idx', (int)($billRow['member_idx'] ?? 0)); }
        if ($columnExists($db, $commentTable, 'employee_idx')) { $add('employee_idx', (int)($context['employee_idx'] ?? 0)); }
        if ($columnExists($db, $commentTable, 'writer_idx')) { $add('writer_idx', (int)($context['employee_idx'] ?? 0)); }
        if ($columnExists($db, $commentTable, 'created_by')) { $add('created_by', (int)($context['user_pk'] ?? 0)); }
        if ($columnExists($db, $commentTable, 'service_code')) { $add('service_code', (string)($context['service_code'] ?? 'shvq')); }
        if ($columnExists($db, $commentTable, 'tenant_id')) { $add('tenant_id', (int)($context['tenant_id'] ?? 0)); }
        if ($columnExists($db, $commentTable, 'is_deleted')) { $add('is_deleted', 0); }
        if ($columnExists($db, $commentTable, 'created_at')) { $add('created_at', date('Y-m-d H:i:s')); }
        if ($columnExists($db, $commentTable, 'regdate')) { $add('regdate', date('Y-m-d H:i:s')); }

        if ($cols === []) {
            ApiResponse::error('SCHEMA_NOT_READY', 'insertable comment columns are missing', 503);
            exit;
        }

        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $db->prepare('INSERT INTO ' . $commentTable . ' (' . implode(', ', $cols) . ') VALUES (' . $ph . ')');
        $stmt->execute($vals);
        $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
        $commentIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'insert_bill_comment', [
            'service_code' => (string)($context['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($context['tenant_id'] ?? 0),
            'target_table' => $commentTable,
            'target_idx' => $commentIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'bill comment inserted',
            'bill_idx' => $billIdx,
            'site_idx' => (int)($billRow['site_idx'] ?? 0),
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'insert_bill_comment', $commentTable, $commentIdx, [
            'bill_idx' => $billIdx,
            'site_idx' => (int)($billRow['site_idx'] ?? 0),
            'comment_idx' => $commentIdx,
        ]);

        ApiResponse::success([
            'bill_idx' => $billIdx,
            'comment_idx' => $commentIdx,
            'content' => $content,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '수금 코멘트 등록 성공');
        exit;
    }

    if ($todo === 'delete_bill' || $todo === 'bill_delete') {
        $billIdx = (int)($_POST['bill_idx'] ?? $_POST['idx'] ?? 0);
        $bgIdx = (int)($_POST['bg_idx'] ?? 0);
        $billTableExists = $tableExists($db, 'Tb_Bill');
        $bgTableExists = $tableExists($db, 'Tb_BillGroup');
        if (!$billTableExists && !$bgTableExists) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Bill or Tb_BillGroup table is missing', 503);
            exit;
        }
        if ($billIdx <= 0 && $bgIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'bill_idx or bg_idx is required', 422);
            exit;
        }

        try {
            $db->beginTransaction();
            if ($billTableExists && $billIdx > 0) {
                $billIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['idx', 'id']) ?? 'idx';
                $billReferCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['refer_idx', 'billgroup_idx', 'bg_idx']);
                if ($bgIdx <= 0 && $billReferCol !== null) {
                    $bgRead = $db->prepare('SELECT TOP 1 ' . $billReferCol . ' FROM Tb_Bill WHERE ' . $billIdxCol . ' = ?');
                    $bgRead->execute([$billIdx]);
                    $bgIdx = (int)$bgRead->fetchColumn();
                }

                if ($columnExists($db, 'Tb_Bill', 'is_deleted')) {
                    $setSql = ['is_deleted = 1'];
                    $params = [];
                    if ($columnExists($db, 'Tb_Bill', 'deleted_at')) { $setSql[] = 'deleted_at = ?'; $params[] = date('Y-m-d H:i:s'); }
                    if ($columnExists($db, 'Tb_Bill', 'deleted_by')) { $setSql[] = 'deleted_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }
                    $params[] = $billIdx;
                    $stmt = $db->prepare('UPDATE Tb_Bill SET ' . implode(', ', $setSql) . ' WHERE ' . $billIdxCol . ' = ?');
                    $stmt->execute($params);
                } else {
                    $stmt = $db->prepare('DELETE FROM Tb_Bill WHERE ' . $billIdxCol . ' = ?');
                    $stmt->execute([$billIdx]);
                }
            }

            if ($bgTableExists && $bgIdx > 0) {
                $hasActiveBills = false;
                if ($billTableExists) {
                    $billReferCol = $firstExistingColumn($db, $columnExists, 'Tb_Bill', ['refer_idx', 'billgroup_idx', 'bg_idx']);
                    if ($billReferCol !== null) {
                        $where = ['ISNULL(TRY_CONVERT(INT,' . $billReferCol . '),0)=?'];
                        if ($columnExists($db, 'Tb_Bill', 'is_deleted')) {
                            $where[] = 'ISNULL(is_deleted,0)=0';
                        }
                        $cntStmt = $db->prepare('SELECT COUNT(*) FROM Tb_Bill WHERE ' . implode(' AND ', $where));
                        $cntStmt->execute([$bgIdx]);
                        $hasActiveBills = (int)$cntStmt->fetchColumn() > 0;
                    }
                }

                if (!$hasActiveBills) {
                    if ($columnExists($db, 'Tb_BillGroup', 'is_deleted')) {
                        $bgSet = ['is_deleted = 1'];
                        $bgParams = [];
                        if ($columnExists($db, 'Tb_BillGroup', 'deleted_at')) { $bgSet[] = 'deleted_at = ?'; $bgParams[] = date('Y-m-d H:i:s'); }
                        if ($columnExists($db, 'Tb_BillGroup', 'deleted_by')) { $bgSet[] = 'deleted_by = ?'; $bgParams[] = (int)($context['user_pk'] ?? 0); }
                        $bgParams[] = $bgIdx;
                        $bgStmt = $db->prepare('UPDATE Tb_BillGroup SET ' . implode(', ', $bgSet) . ' WHERE idx = ?');
                        $bgStmt->execute($bgParams);
                    } else {
                        $bgStmt = $db->prepare('DELETE FROM Tb_BillGroup WHERE idx = ?');
                        $bgStmt->execute([$bgIdx]);
                    }
                }
            }

            $db->commit();

            $targetTable = $billIdx > 0 ? 'Tb_Bill' : 'Tb_BillGroup';
            $targetIdx = $billIdx > 0 ? $billIdx : $bgIdx;
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'delete_bill', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => $targetTable,
                'target_idx' => $targetIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'bill deleted',
                'bill_idx' => $billIdx,
                'bg_idx' => $bgIdx,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'delete_bill', $targetTable, $targetIdx, [
                'bill_idx' => $billIdx,
                'bg_idx' => $bgIdx,
            ]);

            ApiResponse::success([
                'bill_idx' => $billIdx,
                'bg_idx' => $bgIdx,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '수금 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'attach_list') {
        $siteIdx = (int)($_GET['site_idx'] ?? $_GET['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $category = trim((string)($_GET['category'] ?? 'site_attach'));
        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

        if (!$tableExists($db, 'Tb_FileAttach')) {
            ApiResponse::success([
                'site_idx' => $siteIdx,
                'category' => $category,
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '첨부파일 목록 조회 성공');
            exit;
        }

        $table = 'Tb_FileAttach';
        $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
        $tableNameCol = $firstExistingColumn($db, $columnExists, $table, ['table_name', 'to_table', 'target_table']);
        $linkCol = $firstExistingColumn($db, $columnExists, $table, ['table_idx', 'ref_idx', 'target_idx', 'parent_idx']);
        $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx']);
        $memberCol = $firstExistingColumn($db, $columnExists, $table, ['member_idx']);
        $categoryCol = $firstExistingColumn($db, $columnExists, $table, ['category', 'subject', 'type', 'item_name']);
        $originalNameCol = $firstExistingColumn($db, $columnExists, $table, ['original_name', 'origin_name', 'file_name', 'name']);
        $filenameCol = $firstExistingColumn($db, $columnExists, $table, ['filename', 'save_name', 'stored_name', 'file_name']);
        $urlCol = $firstExistingColumn($db, $columnExists, $table, ['file_url', 'url', 'file_path', 'path']);
        $sizeCol = $firstExistingColumn($db, $columnExists, $table, ['file_size', 'size']);
        $createdCol = $firstExistingColumn($db, $columnExists, $table, ['created_at', 'regdate', 'registered_date', 'insert_date']);

        $where = [];
        $params = [];
        if ($columnExists($db, $table, 'is_deleted')) {
            $where[] = 'ISNULL(f.is_deleted,0)=0';
        }
        if ($columnExists($db, $table, 'service_code')) {
            $where[] = "ISNULL(f.service_code,'')=?";
            $params[] = (string)($context['service_code'] ?? 'shvq');
        }
        if ($columnExists($db, $table, 'tenant_id')) {
            $where[] = 'ISNULL(f.tenant_id,0)=?';
            $params[] = (int)($context['tenant_id'] ?? 0);
        }
        if ($category !== '' && $categoryCol !== null) {
            $where[] = 'LOWER(ISNULL(CAST(f.' . $categoryCol . ' AS NVARCHAR(100)), \'\')) = ?';
            $params[] = strtolower($category);
        }

        $scope = [];
        $scopeParams = [];
        if ($siteCol !== null) {
            $scope[] = 'ISNULL(TRY_CONVERT(INT,f.' . $siteCol . '),0)=?';
            $scopeParams[] = $siteIdx;
        }
        if ($memberCol !== null && (int)($site['member_idx'] ?? 0) > 0) {
            $scope[] = 'ISNULL(TRY_CONVERT(INT,f.' . $memberCol . '),0)=?';
            $scopeParams[] = (int)$site['member_idx'];
        }
        if ($tableNameCol !== null && $linkCol !== null) {
            $scope[] = "(LOWER(CAST(f.{$tableNameCol} AS NVARCHAR(100))) IN ('tb_site','site') AND ISNULL(TRY_CONVERT(INT,f.{$linkCol}),0)=?)";
            $scopeParams[] = $siteIdx;
        }
        if ($scope === []) {
            ApiResponse::success([
                'site_idx' => $siteIdx,
                'category' => $category,
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '첨부파일 목록 조회 성공');
            exit;
        }
        $where[] = '(' . implode(' OR ', $scope) . ')';

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $queryBaseParams = array_merge($params, $scopeParams);

        $countStmt = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' f' . $whereSql);
        $countStmt->execute($queryBaseParams);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $queryParams = array_merge($queryBaseParams, [$rowFrom, $rowTo]);

        $originalExpr = $originalNameCol !== null ? 'ISNULL(CAST(f.' . $originalNameCol . " AS NVARCHAR(255)),'')" : "CAST('' AS NVARCHAR(255))";
        $filenameExpr = $filenameCol !== null ? 'ISNULL(CAST(f.' . $filenameCol . " AS NVARCHAR(255)),'')" : $originalExpr;
        $urlExpr = $urlCol !== null ? 'ISNULL(CAST(f.' . $urlCol . " AS NVARCHAR(1200)),'')" : "CAST('' AS NVARCHAR(1200))";
        $sizeExpr = $sizeCol !== null ? 'ISNULL(TRY_CONVERT(BIGINT,f.' . $sizeCol . '),0)' : 'CAST(0 AS BIGINT)';
        $createdExpr = $createdCol !== null ? 'f.' . $createdCol : 'NULL';

        $listSql = "SELECT * FROM (\n"
            . " SELECT\n"
            . "   ISNULL(TRY_CONVERT(BIGINT,f.{$idxCol}),0) AS idx,\n"
            . "   {$originalExpr} AS original_name,\n"
            . "   {$filenameExpr} AS filename,\n"
            . "   {$urlExpr} AS file_url,\n"
            . "   {$sizeExpr} AS file_size,\n"
            . "   {$createdExpr} AS created_at,\n"
            . "   ROW_NUMBER() OVER (ORDER BY ISNULL(TRY_CONVERT(DATETIME, {$createdExpr}), '1900-01-01') DESC, f.{$idxCol} DESC) AS rn\n"
            . ' FROM ' . $table . ' f' . $whereSql
            . "\n) t WHERE t.rn BETWEEN ? AND ?";
        $stmt = $db->prepare($listSql);
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        foreach ($rows as &$row) {
            $row['file_url'] = $normalizePublicUrl((string)($row['file_url'] ?? ''));
            $row['created_at'] = $row['created_at'] ?? null;
            if (!array_key_exists('filename', $row) || trim((string)$row['filename']) === '') {
                $row['filename'] = (string)($row['original_name'] ?? '');
            }
        }
        unset($row);

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'category' => $category,
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ], 'OK', '첨부파일 목록 조회 성공');
        exit;
    }

    if ($todo === 'upload_attach') {
        $siteIdx = (int)($_POST['site_idx'] ?? $_POST['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }
        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $tenantId = (int)($context['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            ApiResponse::error('INVALID_SCOPE', 'tenant_id is required for upload', 422);
            exit;
        }

        $category = trim((string)($_POST['category'] ?? 'site_attach'));
        if ($category === '') {
            $category = 'site_attach';
        }

        $files = $extractUploadedFiles($_FILES);
        if ($files === []) {
            ApiResponse::error('INVALID_PARAM', 'files[] is required', 422);
            exit;
        }

        $storage = StorageService::forTenant($tenantId);
        $uploaded = [];
        $rejected = [];
        $metaSaved = 0;
        $firstFileIdx = 0;

        foreach ($files as $file) {
            if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $rejected[] = [
                    'name' => (string)($file['name'] ?? ''),
                    'reason' => 'upload_error',
                    'error_code' => (int)($file['error'] ?? 0),
                ];
                continue;
            }

            try {
                $result = $storage->put($category, $file, 'site_' . $siteIdx);
                $item = [
                    'original_name' => (string)($file['name'] ?? ''),
                    'filename' => (string)($result['filename'] ?? ''),
                    'file_url' => (string)($result['url'] ?? ''),
                    'file_size' => (int)($result['size'] ?? ($file['size'] ?? 0)),
                    'created_at' => date('Y-m-d H:i:s'),
                ];

                if ($tableExists($db, 'Tb_FileAttach')) {
                    $cols = [];
                    $vals = [];
                    $add = static function (string $col, mixed $val) use (&$cols, &$vals, $db, $columnExists): void {
                        if ($columnExists($db, 'Tb_FileAttach', $col)) {
                            $cols[] = $col;
                            $vals[] = $val;
                        }
                    };

                    $add('table_name', 'Tb_Site');
                    $add('to_table', 'Tb_Site');
                    $add('target_table', 'Tb_Site');
                    $add('table_idx', $siteIdx);
                    $add('site_idx', $siteIdx);
                    $add('ref_idx', $siteIdx);
                    $add('target_idx', $siteIdx);
                    $add('member_idx', (int)($site['member_idx'] ?? 0));
                    $add('category', $category);
                    $add('subject', $category);
                    $add('type', $category);
                    $add('file_name', (string)($file['name'] ?? ''));
                    $add('original_name', (string)($file['name'] ?? ''));
                    $add('origin_name', (string)($file['name'] ?? ''));
                    $add('filename', (string)($result['filename'] ?? ''));
                    $add('save_name', (string)($result['filename'] ?? ''));
                    $add('stored_name', (string)($result['filename'] ?? ''));
                    $add('file_url', (string)($result['url'] ?? ''));
                    $add('url', (string)($result['url'] ?? ''));
                    $add('file_path', (string)($result['path'] ?? ''));
                    $add('path', (string)($result['path'] ?? ''));
                    $add('file_size', (int)($result['size'] ?? ($file['size'] ?? 0)));
                    $add('mime_type', (string)($result['mime'] ?? ($file['type'] ?? '')));
                    $add('file_ext', strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)));
                    $add('employee_idx', (int)($context['employee_idx'] ?? 0));
                    $add('writer_idx', (int)($context['employee_idx'] ?? 0));
                    $add('created_by', (int)($context['user_pk'] ?? 0));
                    $add('service_code', (string)($context['service_code'] ?? 'shvq'));
                    $add('tenant_id', $tenantId);
                    $add('is_deleted', 0);
                    $add('created_at', date('Y-m-d H:i:s'));
                    $add('regdate', date('Y-m-d H:i:s'));

                    if ($cols !== []) {
                        $ph = implode(', ', array_fill(0, count($cols), '?'));
                        $stmt = $db->prepare('INSERT INTO Tb_FileAttach (' . implode(', ', $cols) . ') VALUES (' . $ph . ')');
                        $stmt->execute($vals);
                        $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
                        $fileIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
                        if ($fileIdx > 0 && $firstFileIdx <= 0) {
                            $firstFileIdx = $fileIdx;
                        }
                        $item['file_idx'] = $fileIdx;
                        $metaSaved++;
                    }
                }

                $uploaded[] = $item;
            } catch (Throwable $e) {
                $rejected[] = [
                    'name' => (string)($file['name'] ?? ''),
                    'reason' => 'upload_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        if ($uploaded === []) {
            ApiResponse::error('UPLOAD_FAILED', '업로드된 파일이 없습니다', 422, ['rejected' => $rejected]);
            exit;
        }

        $targetTable = $metaSaved > 0 ? 'Tb_FileAttach' : 'Tb_Site';
        $targetIdx = $metaSaved > 0 ? $firstFileIdx : $siteIdx;
        $writeSvcAuditLog($db, $tableExists, $columnExists, 'upload_attach', [
            'service_code' => (string)($context['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($context['tenant_id'] ?? 0),
            'target_table' => $targetTable,
            'target_idx' => $targetIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'site attachments uploaded',
            'site_idx' => $siteIdx,
            'category' => $category,
            'uploaded_count' => count($uploaded),
            'rejected_count' => count($rejected),
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'upload_attach', $targetTable, $targetIdx, [
            'site_idx' => $siteIdx,
            'category' => $category,
            'uploaded_count' => count($uploaded),
            'rejected_count' => count($rejected),
        ]);

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'category' => $category,
            'uploaded' => $uploaded,
            'uploaded_count' => count($uploaded),
            'rejected' => $rejected,
            'rejected_count' => count($rejected),
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '첨부파일 업로드 성공');
        exit;
    }

    if ($todo === 'delete_attach') {
        $attachIds = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['file_idx'] ?? ($_POST['idx'] ?? '')));
        if ($attachIds === []) {
            ApiResponse::error('INVALID_PARAM', 'file_idx or idx_list is required', 422);
            exit;
        }
        if (!$tableExists($db, 'Tb_FileAttach')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_FileAttach table is missing', 503);
            exit;
        }

        $siteIdx = (int)($_POST['site_idx'] ?? 0);
        $site = null;
        if ($siteIdx > 0) {
            $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
            if ($site === null) {
                ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
                exit;
            }
        }

        $ph = implode(',', array_fill(0, count($attachIds), '?'));
        $where = ["idx IN ({$ph})"];
        $params = $attachIds;
        $siteCol = $firstExistingColumn($db, $columnExists, 'Tb_FileAttach', ['site_idx']);
        if ($siteIdx > 0 && $siteCol !== null) {
            $where[] = 'ISNULL(TRY_CONVERT(INT,' . $siteCol . '),0)=?';
            $params[] = $siteIdx;
        }

        if ($columnExists($db, 'Tb_FileAttach', 'is_deleted')) {
            $setSql = ['is_deleted = 1'];
            $setParams = [];
            if ($columnExists($db, 'Tb_FileAttach', 'deleted_at')) { $setSql[] = 'deleted_at = ?'; $setParams[] = date('Y-m-d H:i:s'); }
            if ($columnExists($db, 'Tb_FileAttach', 'deleted_by')) { $setSql[] = 'deleted_by = ?'; $setParams[] = (int)($context['user_pk'] ?? 0); }
            $stmt = $db->prepare('UPDATE Tb_FileAttach SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute(array_merge($setParams, $params));
            $affected = (int)$stmt->rowCount();
        } else {
            $stmt = $db->prepare('DELETE FROM Tb_FileAttach WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $affected = (int)$stmt->rowCount();
        }

        $targetIdx = $attachIds[0] ?? 0;
        $writeSvcAuditLog($db, $tableExists, $columnExists, 'delete_attach', [
            'service_code' => (string)($context['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($context['tenant_id'] ?? 0),
            'target_table' => 'Tb_FileAttach',
            'target_idx' => $targetIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'site attachment deleted',
            'site_idx' => $siteIdx,
            'idx_list' => $attachIds,
            'deleted_count' => $affected,
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'delete_attach', 'Tb_FileAttach', $targetIdx, [
            'site_idx' => $siteIdx,
            'idx_list' => $attachIds,
            'deleted_count' => $affected,
        ]);

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'idx_list' => $attachIds,
            'deleted_count' => $affected,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '첨부파일 삭제 성공');
        exit;
    }

    if ($todo === 'contact_list') {
        $siteIdx = (int)($_GET['site_idx'] ?? $_GET['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

        if (!$tableExists($db, 'Tb_PhoneBook')) {
            ApiResponse::success([
                'site_idx' => $siteIdx,
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '현장 연락처 목록 조회 성공');
            exit;
        }

        $table = 'Tb_PhoneBook';
        $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
        $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx']);
        $nameCol = $firstExistingColumn($db, $columnExists, $table, ['name', 'contact_name']);
        $positionCol = $firstExistingColumn($db, $columnExists, $table, ['part', 'position', 'job_title', 'job_grade', 'rank']);
        $phoneCol = $firstExistingColumn($db, $columnExists, $table, ['hp', 'phone', 'tel']);
        $emailCol = $firstExistingColumn($db, $columnExists, $table, ['email']);
        $memoCol = $firstExistingColumn($db, $columnExists, $table, ['comment', 'memo', 'note']);
        $createdCol = $firstExistingColumn($db, $columnExists, $table, ['created_at', 'regdate', 'registered_date']);
        $isHiddenCol = $firstExistingColumn($db, $columnExists, $table, ['is_hidden']);
        $folderIdxCol = $firstExistingColumn($db, $columnExists, $table, ['branch_folder_idx', 'folder_idx']);

        if ($siteCol === null) {
            ApiResponse::success([
                'site_idx' => $siteIdx,
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '현장 연락처 목록 조회 성공');
            exit;
        }

        $where = ['ISNULL(TRY_CONVERT(INT,p.' . $siteCol . '),0)=?'];
        $params = [$siteIdx];
        if ($columnExists($db, $table, 'is_deleted')) { $where[] = 'ISNULL(p.is_deleted,0)=0'; }
        if ($columnExists($db, $table, 'service_code')) { $where[] = "ISNULL(p.service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
        if ($columnExists($db, $table, 'tenant_id')) { $where[] = 'ISNULL(p.tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }
        $folderIdxFilter = FmsInputValidator::int($_GET, 'folder_idx', false, 0);
        if ($folderIdxFilter !== null && $folderIdxCol !== null) {
            $where[] = 'ISNULL(TRY_CONVERT(INT,p.' . $folderIdxCol . '),0)=?';
            $params[] = $folderIdxFilter;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $countStmt = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' p' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $queryParams = array_merge($params, [$rowFrom, $rowTo]);

        $nameExpr = $nameCol !== null ? 'ISNULL(CAST(p.' . $nameCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";
        $positionExpr = $positionCol !== null ? 'ISNULL(CAST(p.' . $positionCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";
        $phoneExpr = $phoneCol !== null ? 'ISNULL(CAST(p.' . $phoneCol . " AS NVARCHAR(60)),'')" : "CAST('' AS NVARCHAR(60))";
        $emailExpr = $emailCol !== null ? 'ISNULL(CAST(p.' . $emailCol . " AS NVARCHAR(200)),'')" : "CAST('' AS NVARCHAR(200))";
        $memoExpr = $memoCol !== null ? 'ISNULL(CAST(p.' . $memoCol . " AS NVARCHAR(2000)),'')" : "CAST('' AS NVARCHAR(2000))";
        $createdExpr = $createdCol !== null ? 'p.' . $createdCol : 'NULL';
        $isHiddenExpr = $isHiddenCol !== null ? 'ISNULL(TRY_CONVERT(INT,p.' . $isHiddenCol . '),0)' : 'CAST(0 AS INT)';
        $folderIdxExpr = $folderIdxCol !== null ? 'ISNULL(TRY_CONVERT(INT,p.' . $folderIdxCol . '),0)' : 'CAST(0 AS INT)';

        $listSql = "SELECT * FROM (\n"
            . " SELECT\n"
            . "   ISNULL(TRY_CONVERT(BIGINT,p.{$idxCol}),0) AS idx,\n"
            . "   {$nameExpr} AS name,\n"
            . "   {$positionExpr} AS position,\n"
            . "   {$positionExpr} AS rank,\n"
            . "   {$phoneExpr} AS phone,\n"
            . "   {$phoneExpr} AS tel,\n"
            . "   {$emailExpr} AS email,\n"
            . "   {$memoExpr} AS memo,\n"
            . "   {$isHiddenExpr} AS is_hidden,\n"
            . "   {$folderIdxExpr} AS folder_idx,\n"
            . "   {$createdExpr} AS created_at,\n"
            . "   ROW_NUMBER() OVER (ORDER BY ISNULL(TRY_CONVERT(DATETIME, {$createdExpr}), '1900-01-01') DESC, p.{$idxCol} DESC) AS rn\n"
            . ' FROM ' . $table . ' p' . $whereSql
            . "\n) t WHERE t.rn BETWEEN ? AND ?";
        $stmt = $db->prepare($listSql);
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $folderKey = (string)((int)($row['folder_idx'] ?? 0));
                if (!isset($grouped[$folderKey])) {
                    $grouped[$folderKey] = [];
                }
                $grouped[$folderKey][] = $row;
            }
        }

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'data' => is_array($rows) ? $rows : [],
            'grouped' => $grouped,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ], 'OK', '현장 연락처 목록 조회 성공');
        exit;
    }

    if ($todo === 'toggle_contact_hidden') {
        if (!$tableExists($db, 'Tb_PhoneBook')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_PhoneBook table is missing', 503);
            exit;
        }

        $contactIdx = (int)($_POST['contact_idx'] ?? $_POST['idx'] ?? 0);
        if ($contactIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'contact_idx is required', 422);
            exit;
        }

        $isHidden = FmsInputValidator::int($_POST, 'is_hidden', false, 0, 1);
        if ($isHidden === null) {
            ApiResponse::error('INVALID_PARAM', 'is_hidden is required (0 or 1)', 422);
            exit;
        }

        $idxCol = $firstExistingColumn($db, $columnExists, 'Tb_PhoneBook', ['idx', 'id']) ?? 'idx';
        $hiddenCol = $firstExistingColumn($db, $columnExists, 'Tb_PhoneBook', ['is_hidden']);
        if ($hiddenCol === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_PhoneBook.is_hidden column is missing', 503);
            exit;
        }

        $where = [$idxCol . ' = ?'];
        $params = [$contactIdx];
        if ($columnExists($db, 'Tb_PhoneBook', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        if ($columnExists($db, 'Tb_PhoneBook', 'service_code')) {
            $where[] = "ISNULL(service_code,'')=?";
            $params[] = (string)($context['service_code'] ?? 'shvq');
        }
        if ($columnExists($db, 'Tb_PhoneBook', 'tenant_id')) {
            $where[] = 'ISNULL(tenant_id,0)=?';
            $params[] = (int)($context['tenant_id'] ?? 0);
        }
        $findStmt = $db->prepare('SELECT TOP 1 ' . $idxCol . ' AS idx FROM Tb_PhoneBook WHERE ' . implode(' AND ', $where));
        $findStmt->execute($params);
        $found = $findStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($found)) {
            ApiResponse::error('NOT_FOUND', '연락처를 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = [$hiddenCol . ' = ?'];
        $setParams = [$isHidden];
        if ($columnExists($db, 'Tb_PhoneBook', 'updated_at')) { $setSql[] = 'updated_at = ?'; $setParams[] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, 'Tb_PhoneBook', 'updated_by')) { $setSql[] = 'updated_by = ?'; $setParams[] = (int)($context['user_pk'] ?? 0); }
        $setParams[] = $contactIdx;

        $stmt = $db->prepare('UPDATE Tb_PhoneBook SET ' . implode(', ', $setSql) . ' WHERE ' . $idxCol . ' = ?');
        $stmt->execute($setParams);

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'toggle_contact_hidden', [
            'service_code' => (string)($context['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($context['tenant_id'] ?? 0),
            'target_table' => 'Tb_PhoneBook',
            'target_idx' => $contactIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'contact hidden toggled',
            'contact_idx' => $contactIdx,
            'is_hidden' => $isHidden,
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'toggle_contact_hidden', 'Tb_PhoneBook', $contactIdx, [
            'contact_idx' => $contactIdx,
            'is_hidden' => $isHidden,
        ]);

        ApiResponse::success([
            'contact_idx' => $contactIdx,
            'is_hidden' => $isHidden,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '연락처 숨김 상태 변경 성공');
        exit;
    }

    if ($todo === 'delete_contact' || $todo === 'contact_delete') {
        if (!$tableExists($db, 'Tb_PhoneBook')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_PhoneBook table is missing', 503);
            exit;
        }

        $contactIdx = (int)($_POST['contact_idx'] ?? $_POST['idx'] ?? 0);
        if ($contactIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'contact_idx or idx is required', 422);
            exit;
        }

        $siteIdx = (int)($_POST['site_idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $table = 'Tb_PhoneBook';
        $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
        $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx']);
        if ($siteCol === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_PhoneBook.site_idx column is missing', 503);
            exit;
        }

        $where = [
            $idxCol . ' = ?',
            'ISNULL(TRY_CONVERT(INT,' . $siteCol . '),0)=?',
        ];
        $params = [$contactIdx, $siteIdx];
        if ($columnExists($db, $table, 'is_deleted')) { $where[] = 'ISNULL(is_deleted,0)=0'; }
        if ($columnExists($db, $table, 'service_code')) { $where[] = "ISNULL(service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
        if ($columnExists($db, $table, 'tenant_id')) { $where[] = 'ISNULL(tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }

        $findStmt = $db->prepare('SELECT TOP 1 ' . $idxCol . ' AS idx FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
        $findStmt->execute($params);
        $found = $findStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($found)) {
            ApiResponse::error('NOT_FOUND', '연락처를 찾을 수 없습니다', 404);
            exit;
        }

        try {
            $db->beginTransaction();
            $deleteMode = 'hard';
            $affected = 0;

            if ($columnExists($db, $table, 'is_deleted')) {
                $deleteMode = 'soft';
                $setSql = ['is_deleted = 1'];
                $setParams = [];
                if ($columnExists($db, $table, 'deleted_at')) { $setSql[] = 'deleted_at = ?'; $setParams[] = date('Y-m-d H:i:s'); }
                if ($columnExists($db, $table, 'deleted_by')) { $setSql[] = 'deleted_by = ?'; $setParams[] = (int)($context['user_pk'] ?? 0); }
                if ($columnExists($db, $table, 'updated_at')) { $setSql[] = 'updated_at = ?'; $setParams[] = date('Y-m-d H:i:s'); }
                if ($columnExists($db, $table, 'updated_by')) { $setSql[] = 'updated_by = ?'; $setParams[] = (int)($context['user_pk'] ?? 0); }

                $stmt = $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
                $stmt->execute(array_merge($setParams, $params));
                $affected = (int)$stmt->rowCount();
            } else {
                $stmt = $db->prepare('DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
                $stmt->execute($params);
                $affected = (int)$stmt->rowCount();
            }

            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'delete_contact', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => $table,
                'target_idx' => $contactIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'contact deleted',
                'site_idx' => $siteIdx,
                'contact_idx' => $contactIdx,
                'delete_mode' => $deleteMode,
                'deleted_count' => $affected,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'delete_contact', $table, $contactIdx, [
                'site_idx' => $siteIdx,
                'contact_idx' => $contactIdx,
                'delete_mode' => $deleteMode,
                'deleted_count' => $affected,
            ]);

            ApiResponse::success([
                'site_idx' => $siteIdx,
                'contact_idx' => $contactIdx,
                'deleted_count' => $affected,
                'delete_mode' => $deleteMode,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '현장 연락처 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'floor_plan_list') {
        $siteIdx = (int)($_GET['site_idx'] ?? $_GET['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

        if ($tableExists($db, 'Tb_CAD_Drawing')) {
            $table = 'Tb_CAD_Drawing';
            $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
            $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx', 'idx_site']);
            $nameCol = $firstExistingColumn($db, $columnExists, $table, ['name', 'drawing_name', 'title']);
            $fileNameCol = $firstExistingColumn($db, $columnExists, $table, ['file_name', 'origin_name', 'original_name']);
            $fileUrlCol = $firstExistingColumn($db, $columnExists, $table, ['file_url', 'url', 'file_path', 'path']);
            $typeCol = $firstExistingColumn($db, $columnExists, $table, ['drawing_type', 'type', 'category']);
            $versionCol = $firstExistingColumn($db, $columnExists, $table, ['version_no', 'version']);
            $memoCol = $firstExistingColumn($db, $columnExists, $table, ['memo', 'comment', 'note']);
            $statusCol = $firstExistingColumn($db, $columnExists, $table, ['status', 'drawing_status']);
            $createdCol = $firstExistingColumn($db, $columnExists, $table, ['created_at', 'regdate', 'registered_date']);
            $updatedCol = $firstExistingColumn($db, $columnExists, $table, ['updated_at', 'update_date']);

            if ($siteCol !== null) {
                $where = ['ISNULL(TRY_CONVERT(INT,d.' . $siteCol . '),0)=?'];
                $params = [$siteIdx];
                if ($columnExists($db, $table, 'is_deleted')) { $where[] = 'ISNULL(d.is_deleted,0)=0'; }
                if ($columnExists($db, $table, 'service_code')) { $where[] = "ISNULL(d.service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
                if ($columnExists($db, $table, 'tenant_id')) { $where[] = 'ISNULL(d.tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }
                $whereSql = ' WHERE ' . implode(' AND ', $where);

                $countStmt = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' d' . $whereSql);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                $rowFrom = ($page - 1) * $limit + 1;
                $rowTo = $rowFrom + $limit - 1;
                $queryParams = array_merge($params, [$rowFrom, $rowTo]);

                $nameExpr = $nameCol !== null ? 'ISNULL(CAST(d.' . $nameCol . " AS NVARCHAR(255)),'')" : "CAST('' AS NVARCHAR(255))";
                $fileNameExpr = $fileNameCol !== null ? 'ISNULL(CAST(d.' . $fileNameCol . " AS NVARCHAR(255)),'')" : $nameExpr;
                $urlExpr = $fileUrlCol !== null ? 'ISNULL(CAST(d.' . $fileUrlCol . " AS NVARCHAR(1000)),'')" : "CAST('' AS NVARCHAR(1000))";
                $typeExpr = $typeCol !== null ? 'ISNULL(CAST(d.' . $typeCol . " AS NVARCHAR(80)),'')" : "CAST('' AS NVARCHAR(80))";
                $versionExpr = $versionCol !== null ? 'ISNULL(TRY_CONVERT(INT,d.' . $versionCol . '),0)' : 'CAST(0 AS INT)';
                $memoExpr = $memoCol !== null ? 'ISNULL(CAST(d.' . $memoCol . " AS NVARCHAR(2000)),'')" : "CAST('' AS NVARCHAR(2000))";
                $statusExpr = $statusCol !== null ? 'ISNULL(CAST(d.' . $statusCol . " AS NVARCHAR(40)),'')" : "CAST('' AS NVARCHAR(40))";
                $createdExpr = $createdCol !== null ? 'd.' . $createdCol : 'NULL';
                $updatedExpr = $updatedCol !== null ? 'd.' . $updatedCol : 'NULL';

                $listSql = "SELECT * FROM (\n"
                    . " SELECT\n"
                    . "   ISNULL(TRY_CONVERT(BIGINT,d.{$idxCol}),0) AS idx,\n"
                    . "   {$nameExpr} AS name,\n"
                    . "   {$nameExpr} AS title,\n"
                    . "   {$fileNameExpr} AS file_name,\n"
                    . "   {$urlExpr} AS file_url,\n"
                    . "   {$urlExpr} AS url,\n"
                    . "   {$typeExpr} AS drawing_type,\n"
                    . "   {$versionExpr} AS version_no,\n"
                    . "   {$statusExpr} AS status,\n"
                    . "   {$memoExpr} AS memo,\n"
                    . "   {$createdExpr} AS created_at,\n"
                    . "   {$updatedExpr} AS updated_at,\n"
                    . "   ROW_NUMBER() OVER (ORDER BY ISNULL(TRY_CONVERT(DATETIME, {$createdExpr}), '1900-01-01') DESC, d.{$idxCol} DESC) AS rn\n"
                    . ' FROM ' . $table . ' d' . $whereSql
                    . "\n) t WHERE t.rn BETWEEN ? AND ?";
                $stmt = $db->prepare($listSql);
                $stmt->execute($queryParams);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                ApiResponse::success([
                    'site_idx' => $siteIdx,
                    'data' => is_array($rows) ? $rows : [],
                    'total' => $total,
                    'page' => $page,
                    'pages' => max(1, (int)ceil($total / $limit)),
                    'limit' => $limit,
                ], 'OK', '도면 목록 조회 성공');
                exit;
            }
        }

        if ($tableExists($db, 'Tb_FileAttach')) {
            $table = 'Tb_FileAttach';
            $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
            $nameCol = $firstExistingColumn($db, $columnExists, $table, ['file_name', 'origin_name', 'original_name', 'name']);
            $urlCol = $firstExistingColumn($db, $columnExists, $table, ['file_url', 'url', 'file_path', 'path']);
            $memoCol = $firstExistingColumn($db, $columnExists, $table, ['memo', 'comment', 'description']);
            $createdCol = $firstExistingColumn($db, $columnExists, $table, ['created_at', 'regdate', 'registered_date']);
            $tableNameCol = $firstExistingColumn($db, $columnExists, $table, ['table_name', 'to_table', 'target_table']);
            $linkCol = $firstExistingColumn($db, $columnExists, $table, ['table_idx', 'ref_idx', 'target_idx', 'parent_idx']);
            $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx']);

            $where = [];
            $params = [];
            if ($columnExists($db, $table, 'is_deleted')) { $where[] = 'ISNULL(f.is_deleted,0)=0'; }
            if ($columnExists($db, $table, 'service_code')) { $where[] = "ISNULL(f.service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
            if ($columnExists($db, $table, 'tenant_id')) { $where[] = 'ISNULL(f.tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }

            $scopeConds = [];
            $scopeParams = [];
            if ($siteCol !== null) {
                $scopeConds[] = 'ISNULL(TRY_CONVERT(INT,f.' . $siteCol . '),0)=?';
                $scopeParams[] = $siteIdx;
            }
            if ($tableNameCol !== null && $linkCol !== null) {
                $scopeConds[] = "(LOWER(CAST(f.{$tableNameCol} AS NVARCHAR(120))) IN ('tb_cad_drawing','cad_drawing','drawing','tb_site','site') AND ISNULL(TRY_CONVERT(INT,f.{$linkCol}),0)=?)";
                $scopeParams[] = $siteIdx;
            }

            if ($scopeConds !== []) {
                $where[] = '(' . implode(' OR ', $scopeConds) . ')';
                $params = array_merge($params, $scopeParams);
                $whereSql = ' WHERE ' . implode(' AND ', $where);

                $countStmt = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' f' . $whereSql);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                $rowFrom = ($page - 1) * $limit + 1;
                $rowTo = $rowFrom + $limit - 1;
                $queryParams = array_merge($params, [$rowFrom, $rowTo]);

                $nameExpr = $nameCol !== null ? 'ISNULL(CAST(f.' . $nameCol . " AS NVARCHAR(255)),'')" : "CAST('' AS NVARCHAR(255))";
                $urlExpr = $urlCol !== null ? 'ISNULL(CAST(f.' . $urlCol . " AS NVARCHAR(1000)),'')" : "CAST('' AS NVARCHAR(1000))";
                $memoExpr = $memoCol !== null ? 'ISNULL(CAST(f.' . $memoCol . " AS NVARCHAR(2000)),'')" : "CAST('' AS NVARCHAR(2000))";
                $createdExpr = $createdCol !== null ? 'f.' . $createdCol : 'NULL';

                $listSql = "SELECT * FROM (\n"
                    . " SELECT\n"
                    . "   ISNULL(TRY_CONVERT(BIGINT,f.{$idxCol}),0) AS idx,\n"
                    . "   {$nameExpr} AS name,\n"
                    . "   {$nameExpr} AS file_name,\n"
                    . "   {$urlExpr} AS file_url,\n"
                    . "   {$urlExpr} AS url,\n"
                    . "   {$memoExpr} AS memo,\n"
                    . "   {$createdExpr} AS created_at,\n"
                    . "   ROW_NUMBER() OVER (ORDER BY ISNULL(TRY_CONVERT(DATETIME, {$createdExpr}), '1900-01-01') DESC, f.{$idxCol} DESC) AS rn\n"
                    . ' FROM ' . $table . ' f' . $whereSql
                    . "\n) t WHERE t.rn BETWEEN ? AND ?";
                $stmt = $db->prepare($listSql);
                $stmt->execute($queryParams);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($rows)) {
                    $rows = [];
                }
                foreach ($rows as &$row) {
                    $rawUrl = trim((string)($row['file_url'] ?? $row['url'] ?? ''));
                    if ($rawUrl === '') {
                        continue;
                    }
                    if (preg_match('#^https?://#i', $rawUrl) !== 1 && !str_starts_with($rawUrl, '//')) {
                        $path = str_replace('\\', '/', $rawUrl);
                        if (!str_starts_with($path, '/')) {
                            $path = '/' . ltrim($path, '/');
                        }
                        $rawUrl = $path;
                    } elseif (str_starts_with($rawUrl, '//')) {
                        $rawUrl = 'https:' . $rawUrl;
                    }
                    $row['file_url'] = $rawUrl;
                    $row['url'] = $rawUrl;
                }
                unset($row);

                ApiResponse::success([
                    'site_idx' => $siteIdx,
                    'data' => $rows,
                    'total' => $total,
                    'page' => $page,
                    'pages' => max(1, (int)ceil($total / $limit)),
                    'limit' => $limit,
                ], 'OK', '도면 목록 조회 성공');
                exit;
            }
        }

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'data' => [],
            'total' => 0,
            'page' => $page,
            'pages' => 1,
            'limit' => $limit,
        ], 'OK', '도면 목록 조회 성공');
        exit;
    }

    if ($todo === 'subcontract_list') {
        $siteIdx = (int)($_GET['site_idx'] ?? $_GET['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));
        $estimateIds = $loadSiteEstimateIds($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);

        $table = null;
        foreach (['Tb_Product_Contract', 'Tb_Subcontract'] as $candidate) {
            if ($tableExists($db, $candidate)) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            ApiResponse::success([
                'site_idx' => $siteIdx,
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '도급 목록 조회 성공');
            exit;
        }

        $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
        $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx', 'idx_site']);
        $estimateCol = $firstExistingColumn($db, $columnExists, $table, ['estimate_idx', 'site_estimate_idx', 'est_idx']);
        $nameCol = $firstExistingColumn($db, $columnExists, $table, ['name', 'contract_name', 'title']);
        $companyCol = $firstExistingColumn($db, $columnExists, $table, ['company_name', 'partner_name', 'vendor_name', 'customer_name']);
        $statusCol = $firstExistingColumn($db, $columnExists, $table, ['status', 'contract_status']);
        $startCol = $firstExistingColumn($db, $columnExists, $table, ['start_date', 'contract_date']);
        $endCol = $firstExistingColumn($db, $columnExists, $table, ['end_date', 'completion_date']);
        $amountCol = $firstExistingColumn($db, $columnExists, $table, ['amount', 'total_amount', 'contract_amount', 'supply_amount']);
        $memoCol = $firstExistingColumn($db, $columnExists, $table, ['memo', 'note', 'comment']);
        $createdCol = $firstExistingColumn($db, $columnExists, $table, ['created_at', 'regdate', 'registered_date']);

        $scopeConds = [];
        $scopeParams = [];
        if ($siteCol !== null) {
            $scopeConds[] = 'ISNULL(TRY_CONVERT(INT,t.' . $siteCol . '),0)=?';
            $scopeParams[] = $siteIdx;
        }
        if ($estimateCol !== null && $estimateIds !== []) {
            $ph = implode(', ', array_fill(0, count($estimateIds), '?'));
            $scopeConds[] = 'ISNULL(TRY_CONVERT(INT,t.' . $estimateCol . '),0) IN (' . $ph . ')';
            $scopeParams = array_merge($scopeParams, $estimateIds);
        }
        if ($scopeConds === []) {
            ApiResponse::success([
                'site_idx' => $siteIdx,
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '도급 목록 조회 성공');
            exit;
        }

        $where = ['(' . implode(' OR ', $scopeConds) . ')'];
        $params = $scopeParams;
        if ($columnExists($db, $table, 'is_deleted')) { $where[] = 'ISNULL(t.is_deleted,0)=0'; }
        if ($columnExists($db, $table, 'service_code')) { $where[] = "ISNULL(t.service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
        if ($columnExists($db, $table, 'tenant_id')) { $where[] = 'ISNULL(t.tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $countStmt = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' t' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $queryParams = array_merge($params, [$rowFrom, $rowTo]);

        $nameExpr = $nameCol !== null ? 'ISNULL(CAST(t.' . $nameCol . " AS NVARCHAR(255)),'')" : "CAST('' AS NVARCHAR(255))";
        $companyExpr = $companyCol !== null ? 'ISNULL(CAST(t.' . $companyCol . " AS NVARCHAR(255)),'')" : "CAST('' AS NVARCHAR(255))";
        $statusExpr = $statusCol !== null ? 'ISNULL(CAST(t.' . $statusCol . " AS NVARCHAR(60)),'')" : "CAST('' AS NVARCHAR(60))";
        $startExpr = $startCol !== null ? 't.' . $startCol : 'NULL';
        $endExpr = $endCol !== null ? 't.' . $endCol : 'NULL';
        $amountExpr = $amountCol !== null ? 'ISNULL(TRY_CONVERT(BIGINT,t.' . $amountCol . '),0)' : 'CAST(0 AS BIGINT)';
        $memoExpr = $memoCol !== null ? 'ISNULL(CAST(t.' . $memoCol . " AS NVARCHAR(2000)),'')" : "CAST('' AS NVARCHAR(2000))";
        $createdExpr = $createdCol !== null ? 't.' . $createdCol : 'NULL';

        $listSql = "SELECT * FROM (\n"
            . " SELECT\n"
            . "   ISNULL(TRY_CONVERT(BIGINT,t.{$idxCol}),0) AS idx,\n"
            . "   {$nameExpr} AS name,\n"
            . "   {$companyExpr} AS company_name,\n"
            . "   {$statusExpr} AS status,\n"
            . "   {$startExpr} AS start_date,\n"
            . "   {$endExpr} AS end_date,\n"
            . "   {$amountExpr} AS amount,\n"
            . "   {$memoExpr} AS memo,\n"
            . "   {$createdExpr} AS created_at,\n"
            . "   ROW_NUMBER() OVER (ORDER BY ISNULL(TRY_CONVERT(DATETIME, {$createdExpr}), '1900-01-01') DESC, t.{$idxCol} DESC) AS rn\n"
            . ' FROM ' . $table . ' t' . $whereSql
            . "\n) x WHERE x.rn BETWEEN ? AND ?";
        $stmt = $db->prepare($listSql);
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'data' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ], 'OK', '도급 목록 조회 성공');
        exit;
    }

    if ($todo === 'access_log_list') {
        $siteIdx = (int)($_GET['site_idx'] ?? $_GET['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

        $table = null;
        foreach (['Tb_Site_Access_inout', 'Tb_SiteAccessInout', 'Tb_AccessLog', 'Tb_Access'] as $candidate) {
            if ($tableExists($db, $candidate)) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            ApiResponse::success([
                'site_idx' => $siteIdx,
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '출입 기록 조회 성공');
            exit;
        }

        $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
        $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx', 'idx_site']);
        if ($siteCol === null) {
            ApiResponse::success([
                'site_idx' => $siteIdx,
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '출입 기록 조회 성공');
            exit;
        }

        $personCol = $firstExistingColumn($db, $columnExists, $table, ['person_name', 'user_name', 'name', 'employee_name', 'visitor_name']);
        $inCol = $firstExistingColumn($db, $columnExists, $table, ['in_time', 'checkin_time', 'enter_time']);
        $outCol = $firstExistingColumn($db, $columnExists, $table, ['out_time', 'checkout_time', 'leave_time']);
        $accessCol = $firstExistingColumn($db, $columnExists, $table, ['access_time', 'visit_time', 'created_at', 'regdate']);
        $statusCol = $firstExistingColumn($db, $columnExists, $table, ['status', 'access_status']);
        $memoCol = $firstExistingColumn($db, $columnExists, $table, ['memo', 'note', 'comment']);
        $createdCol = $firstExistingColumn($db, $columnExists, $table, ['created_at', 'regdate', 'registered_date']);

        $where = ['ISNULL(TRY_CONVERT(INT,a.' . $siteCol . '),0)=?'];
        $params = [$siteIdx];
        if ($columnExists($db, $table, 'is_deleted')) { $where[] = 'ISNULL(a.is_deleted,0)=0'; }
        if ($columnExists($db, $table, 'service_code')) { $where[] = "ISNULL(a.service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
        if ($columnExists($db, $table, 'tenant_id')) { $where[] = 'ISNULL(a.tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' a' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $queryParams = array_merge($params, [$rowFrom, $rowTo]);

        $personExpr = $personCol !== null ? 'ISNULL(CAST(a.' . $personCol . " AS NVARCHAR(120)),'')" : "CAST('' AS NVARCHAR(120))";
        $inExpr = $inCol !== null ? 'a.' . $inCol : 'NULL';
        $outExpr = $outCol !== null ? 'a.' . $outCol : 'NULL';
        $accessExpr = $accessCol !== null ? 'a.' . $accessCol : ($createdCol !== null ? 'a.' . $createdCol : 'NULL');
        $statusExpr = $statusCol !== null ? 'ISNULL(CAST(a.' . $statusCol . " AS NVARCHAR(40)),'')" : "CAST('' AS NVARCHAR(40))";
        $memoExpr = $memoCol !== null ? 'ISNULL(CAST(a.' . $memoCol . " AS NVARCHAR(2000)),'')" : "CAST('' AS NVARCHAR(2000))";
        $createdExpr = $createdCol !== null ? 'a.' . $createdCol : $accessExpr;

        $listSql = "SELECT * FROM (\n"
            . " SELECT\n"
            . "   ISNULL(TRY_CONVERT(BIGINT,a.{$idxCol}),0) AS idx,\n"
            . "   {$personExpr} AS person_name,\n"
            . "   {$inExpr} AS in_time,\n"
            . "   {$outExpr} AS out_time,\n"
            . "   {$accessExpr} AS access_time,\n"
            . "   {$statusExpr} AS status,\n"
            . "   {$memoExpr} AS memo,\n"
            . "   {$createdExpr} AS created_at,\n"
            . "   ROW_NUMBER() OVER (ORDER BY ISNULL(TRY_CONVERT(DATETIME, {$accessExpr}), '1900-01-01') DESC, a.{$idxCol} DESC) AS rn\n"
            . ' FROM ' . $table . ' a' . $whereSql
            . "\n) x WHERE x.rn BETWEEN ? AND ?";
        $stmt = $db->prepare($listSql);
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'site_idx' => $siteIdx,
            'data' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ], 'OK', '출입 기록 조회 성공');
        exit;
    }

    if ($todo === 'insert_floor_plan' || $todo === 'floor_plan_insert') {
        $siteIdx = (int)($_POST['site_idx'] ?? $_POST['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $table = null;
        foreach (['Tb_CAD_Drawing', 'Tb_FileAttach'] as $candidate) {
            if ($tableExists($db, $candidate)) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_CAD_Drawing or Tb_FileAttach table is missing', 503);
            exit;
        }

        $title = FmsInputValidator::string($_POST, 'name', 255, false);
        if ($title === '') {
            $title = FmsInputValidator::string($_POST, 'title', 255, false);
        }
        $drawingType = FmsInputValidator::string($_POST, 'drawing_type', 80, false);
        if ($drawingType === '') {
            $drawingType = FmsInputValidator::string($_POST, 'type', 80, false);
        }
        $status = FmsInputValidator::string($_POST, 'status', 40, false);
        $memo = FmsInputValidator::string($_POST, 'memo', 2000, false);
        $versionNo = FmsInputValidator::int($_POST, 'version_no', false, 0) ?? 1;
        $urlInput = trim((string)($_POST['file_url'] ?? $_POST['url'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'site_floor_plan'));
        if ($category === '') {
            $category = 'site_floor_plan';
        }

        $files = $extractUploadedFiles($_FILES);
        if ($files === [] && $urlInput === '' && $title === '') {
            ApiResponse::error('INVALID_PARAM', 'name/title, file_url/url, or files[] is required', 422);
            exit;
        }
        $tenantId = (int)($context['tenant_id'] ?? 0);
        if ($files !== [] && $tenantId <= 0) {
            ApiResponse::error('INVALID_SCOPE', 'tenant_id is required for upload', 422);
            exit;
        }

        $insertFloorPlanRow = static function (string $targetTable, array $meta) use ($db, $columnExists, $firstExistingColumn, $context, $site, $siteIdx, $category): int {
            $cols = [];
            $vals = [];
            $add = static function (string $col, mixed $val) use (&$cols, &$vals, $db, $targetTable, $columnExists): void {
                if ($columnExists($db, $targetTable, $col)) {
                    $cols[] = $col;
                    $vals[] = $val;
                }
            };

            if ($targetTable === 'Tb_CAD_Drawing') {
                $siteCol = $firstExistingColumn($db, $columnExists, $targetTable, ['site_idx', 'idx_site']);
                $nameCol = $firstExistingColumn($db, $columnExists, $targetTable, ['name', 'drawing_name', 'title']);
                $fileNameCol = $firstExistingColumn($db, $columnExists, $targetTable, ['file_name', 'origin_name', 'original_name']);
                $urlCol = $firstExistingColumn($db, $columnExists, $targetTable, ['file_url', 'url', 'file_path', 'path']);
                $typeCol = $firstExistingColumn($db, $columnExists, $targetTable, ['drawing_type', 'type', 'category']);
                $versionCol = $firstExistingColumn($db, $columnExists, $targetTable, ['version_no', 'version']);
                $statusCol = $firstExistingColumn($db, $columnExists, $targetTable, ['status', 'drawing_status']);
                $memoCol = $firstExistingColumn($db, $columnExists, $targetTable, ['memo', 'comment', 'note']);

                if ($siteCol !== null) { $add($siteCol, $siteIdx); }
                if ($nameCol !== null && (string)($meta['title'] ?? '') !== '') { $add($nameCol, (string)$meta['title']); }
                if ($fileNameCol !== null && (string)($meta['file_name'] ?? '') !== '') { $add($fileNameCol, (string)$meta['file_name']); }
                if ($urlCol !== null && (string)($meta['file_url'] ?? '') !== '') { $add($urlCol, (string)$meta['file_url']); }
                if ($typeCol !== null && (string)($meta['drawing_type'] ?? '') !== '') { $add($typeCol, (string)$meta['drawing_type']); }
                if ($versionCol !== null) { $add($versionCol, (int)($meta['version_no'] ?? 1)); }
                if ($statusCol !== null && (string)($meta['status'] ?? '') !== '') { $add($statusCol, (string)$meta['status']); }
                if ($memoCol !== null && (string)($meta['memo'] ?? '') !== '') { $add($memoCol, (string)$meta['memo']); }
            } else {
                $url = (string)($meta['file_url'] ?? '');
                $path = (string)($meta['file_path'] ?? $url);

                $add('table_name', 'Tb_CAD_Drawing');
                $add('to_table', 'Tb_CAD_Drawing');
                $add('target_table', 'Tb_CAD_Drawing');
                $add('table_idx', $siteIdx);
                $add('site_idx', $siteIdx);
                $add('ref_idx', $siteIdx);
                $add('target_idx', $siteIdx);
                $add('member_idx', (int)($site['member_idx'] ?? 0));
                $add('category', $category);
                $add('subject', $category);
                $add('type', (string)($meta['drawing_type'] ?? ''));
                $add('name', (string)($meta['title'] ?? ''));
                $add('file_name', (string)($meta['file_name'] ?? ''));
                $add('original_name', (string)($meta['original_name'] ?? $meta['file_name'] ?? ''));
                $add('origin_name', (string)($meta['original_name'] ?? $meta['file_name'] ?? ''));
                $add('filename', (string)($meta['filename'] ?? $meta['file_name'] ?? ''));
                $add('save_name', (string)($meta['filename'] ?? $meta['file_name'] ?? ''));
                $add('stored_name', (string)($meta['filename'] ?? $meta['file_name'] ?? ''));
                $add('file_url', $url);
                $add('url', $url);
                $add('file_path', $path);
                $add('path', $path);
                $add('file_size', (int)($meta['file_size'] ?? 0));
                $add('mime_type', (string)($meta['mime_type'] ?? ''));
                $add('file_ext', strtolower((string)pathinfo((string)($meta['file_name'] ?? ''), PATHINFO_EXTENSION)));
                $add('memo', (string)($meta['memo'] ?? ''));
            }

            $add('employee_idx', (int)($context['employee_idx'] ?? 0));
            $add('writer_idx', (int)($context['employee_idx'] ?? 0));
            $add('service_code', (string)($context['service_code'] ?? 'shvq'));
            $add('tenant_id', (int)($context['tenant_id'] ?? 0));
            $add('is_deleted', 0);
            $add('created_by', (int)($context['user_pk'] ?? 0));
            $add('created_at', date('Y-m-d H:i:s'));
            $add('regdate', date('Y-m-d H:i:s'));

            if ($cols === []) {
                return 0;
            }

            $ph = implode(', ', array_fill(0, count($cols), '?'));
            $stmt = $db->prepare('INSERT INTO ' . $targetTable . ' (' . implode(', ', $cols) . ') VALUES (' . $ph . ')');
            $stmt->execute($vals);
            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            return (int)($idStmt ? $idStmt->fetchColumn() : 0);
        };

        $inserted = [];
        $rejected = [];

        try {
            $db->beginTransaction();

            if ($files !== []) {
                $storage = StorageService::forTenant($tenantId);

                foreach ($files as $file) {
                    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        $rejected[] = [
                            'name' => (string)($file['name'] ?? ''),
                            'reason' => 'upload_error',
                            'error_code' => (int)($file['error'] ?? 0),
                        ];
                        continue;
                    }

                    try {
                        $result = $storage->put($category, $file, 'site_' . $siteIdx);
                        $titleValue = $title !== '' ? $title : (string)($file['name'] ?? '');
                        $fileUrl = $normalizePublicUrl((string)($result['url'] ?? $result['path'] ?? ''));
                        $insertedIdx = $insertFloorPlanRow($table, [
                            'title' => $titleValue,
                            'drawing_type' => $drawingType,
                            'status' => $status,
                            'memo' => $memo,
                            'version_no' => $versionNo,
                            'file_name' => (string)($file['name'] ?? ''),
                            'original_name' => (string)($file['name'] ?? ''),
                            'filename' => (string)($result['filename'] ?? $file['name'] ?? ''),
                            'file_url' => $fileUrl,
                            'file_path' => (string)($result['path'] ?? ''),
                            'file_size' => (int)($result['size'] ?? ($file['size'] ?? 0)),
                            'mime_type' => (string)($result['mime'] ?? ($file['type'] ?? '')),
                        ]);

                        $inserted[] = [
                            'idx' => $insertedIdx,
                            'name' => $titleValue,
                            'file_name' => (string)($file['name'] ?? ''),
                            'file_url' => $fileUrl,
                        ];
                    } catch (Throwable $e) {
                        $rejected[] = [
                            'name' => (string)($file['name'] ?? ''),
                            'reason' => 'upload_failed',
                            'message' => $e->getMessage(),
                        ];
                    }
                }
            } else {
                $resolvedName = $title !== '' ? $title : basename($urlInput);
                $resolvedUrl = $normalizePublicUrl($urlInput);
                $insertedIdx = $insertFloorPlanRow($table, [
                    'title' => $resolvedName,
                    'drawing_type' => $drawingType,
                    'status' => $status,
                    'memo' => $memo,
                    'version_no' => $versionNo,
                    'file_name' => $resolvedName,
                    'original_name' => $resolvedName,
                    'filename' => $resolvedName,
                    'file_url' => $resolvedUrl,
                    'file_path' => $urlInput,
                    'file_size' => 0,
                    'mime_type' => '',
                ]);
                $inserted[] = [
                    'idx' => $insertedIdx,
                    'name' => $resolvedName,
                    'file_name' => $resolvedName,
                    'file_url' => $resolvedUrl,
                ];
            }

            if ($inserted === []) {
                $db->rollBack();
                ApiResponse::error('INSERT_FAILED', '도면 등록에 실패했습니다', 422, ['rejected' => $rejected]);
                exit;
            }

            $db->commit();

            $targetIdx = (int)($inserted[0]['idx'] ?? 0);
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'insert_floor_plan', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => $table,
                'target_idx' => $targetIdx > 0 ? $targetIdx : $siteIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'floor plan inserted',
                'site_idx' => $siteIdx,
                'inserted_count' => count($inserted),
                'rejected_count' => count($rejected),
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'insert_floor_plan', $table, $targetIdx > 0 ? $targetIdx : $siteIdx, [
                'site_idx' => $siteIdx,
                'inserted_count' => count($inserted),
            ]);

            ApiResponse::success([
                'site_idx' => $siteIdx,
                'data' => $inserted,
                'inserted_count' => count($inserted),
                'rejected' => $rejected,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '도면 등록 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'delete_floor_plan' || $todo === 'floor_plan_delete') {
        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['floor_plan_idx'] ?? $_POST['idx'] ?? $_POST['file_idx'] ?? ''));
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list or floor_plan_idx is required', 422);
            exit;
        }

        $siteIdx = (int)($_POST['site_idx'] ?? 0);
        $ph = implode(',', array_fill(0, count($idxList), '?'));
        $targetTable = null;
        $targetIdxCol = 'idx';
        $targetSiteCol = null;

        foreach (['Tb_CAD_Drawing', 'Tb_FileAttach'] as $candidate) {
            if (!$tableExists($db, $candidate)) {
                continue;
            }
            $idxCol = $firstExistingColumn($db, $columnExists, $candidate, ['idx', 'id']) ?? 'idx';
            $siteCol = $firstExistingColumn($db, $columnExists, $candidate, ['site_idx', 'idx_site']);
            $where = ["{$idxCol} IN ({$ph})"];
            $params = $idxList;
            if ($siteIdx > 0 && $siteCol !== null) {
                $where[] = 'ISNULL(TRY_CONVERT(INT,' . $siteCol . '),0)=?';
                $params[] = $siteIdx;
            }
            if ($columnExists($db, $candidate, 'is_deleted')) { $where[] = 'ISNULL(is_deleted,0)=0'; }
            if ($columnExists($db, $candidate, 'service_code')) { $where[] = "ISNULL(service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
            if ($columnExists($db, $candidate, 'tenant_id')) { $where[] = 'ISNULL(tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }
            $stmt = $db->prepare('SELECT COUNT(*) FROM ' . $candidate . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $matched = (int)$stmt->fetchColumn();
            if ($matched > 0) {
                $targetTable = $candidate;
                $targetIdxCol = $idxCol;
                $targetSiteCol = $siteCol;
                break;
            }
        }

        if ($targetTable === null) {
            ApiResponse::error('NOT_FOUND', '삭제할 도면 데이터를 찾을 수 없습니다', 404);
            exit;
        }

        try {
            $db->beginTransaction();
            $where = ["{$targetIdxCol} IN ({$ph})"];
            $params = $idxList;
            if ($siteIdx > 0 && $targetSiteCol !== null) {
                $where[] = 'ISNULL(TRY_CONVERT(INT,' . $targetSiteCol . '),0)=?';
                $params[] = $siteIdx;
            }
            if ($columnExists($db, $targetTable, 'service_code')) { $where[] = "ISNULL(service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
            if ($columnExists($db, $targetTable, 'tenant_id')) { $where[] = 'ISNULL(tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }

            $deleteMode = 'hard';
            if ($columnExists($db, $targetTable, 'is_deleted')) {
                $deleteMode = 'soft';
                $setSql = ['is_deleted = 1'];
                $setParams = [];
                if ($columnExists($db, $targetTable, 'deleted_at')) { $setSql[] = 'deleted_at = ?'; $setParams[] = date('Y-m-d H:i:s'); }
                if ($columnExists($db, $targetTable, 'deleted_by')) { $setSql[] = 'deleted_by = ?'; $setParams[] = (int)($context['user_pk'] ?? 0); }
                $stmt = $db->prepare('UPDATE ' . $targetTable . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
                $stmt->execute(array_merge($setParams, $params));
                $deleted = (int)$stmt->rowCount();
            } else {
                $stmt = $db->prepare('DELETE FROM ' . $targetTable . ' WHERE ' . implode(' AND ', $where));
                $stmt->execute($params);
                $deleted = (int)$stmt->rowCount();
            }

            $db->commit();

            $targetIdx = $idxList[0] ?? 0;
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'delete_floor_plan', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => $targetTable,
                'target_idx' => (int)$targetIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'floor plan deleted',
                'idx_list' => $idxList,
                'delete_mode' => $deleteMode,
                'deleted_count' => $deleted,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'delete_floor_plan', $targetTable, (int)$targetIdx, [
                'idx_list' => $idxList,
                'delete_mode' => $deleteMode,
            ]);

            ApiResponse::success([
                'idx_list' => $idxList,
                'deleted_count' => $deleted,
                'delete_mode' => $deleteMode,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '도면 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'insert_subcontract' || $todo === 'subcontract_insert') {
        $siteIdx = (int)($_POST['site_idx'] ?? $_POST['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }
        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $table = null;
        foreach (['Tb_Product_Contract', 'Tb_Subcontract'] as $candidate) {
            if ($tableExists($db, $candidate)) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Product_Contract or Tb_Subcontract table is missing', 503);
            exit;
        }

        $name = FmsInputValidator::string($_POST, 'name', 255, false);
        if ($name === '') {
            $name = FmsInputValidator::string($_POST, 'contract_name', 255, false);
        }
        if ($name === '') {
            $name = FmsInputValidator::string($_POST, 'title', 255, false);
        }
        if ($name === '') {
            ApiResponse::error('INVALID_PARAM', 'name/contract_name/title is required', 422);
            exit;
        }

        $company = FmsInputValidator::string($_POST, 'company_name', 255, false);
        if ($company === '') {
            $company = FmsInputValidator::string($_POST, 'partner_name', 255, false);
        }
        $status = FmsInputValidator::string($_POST, 'status', 60, false);
        $startDate = FmsInputValidator::string($_POST, 'start_date', 20, false);
        if ($startDate === '') {
            $startDate = FmsInputValidator::string($_POST, 'contract_date', 20, false);
        }
        $endDate = FmsInputValidator::string($_POST, 'end_date', 20, false);
        $amount = $parseIntAmount($_POST['amount'] ?? $_POST['total_amount'] ?? $_POST['contract_amount'] ?? 0);
        $memo = FmsInputValidator::string($_POST, 'memo', 2000, false);
        if ($memo === '') {
            $memo = FmsInputValidator::string($_POST, 'note', 2000, false);
        }
        $estimateIdx = FmsInputValidator::int($_POST, 'estimate_idx', false, 0) ?? 0;

        $cols = [];
        $vals = [];
        $add = static function (string $col, mixed $val) use (&$cols, &$vals, $db, $columnExists, $table): void {
            if ($columnExists($db, $table, $col)) {
                $cols[] = $col;
                $vals[] = $val;
            }
        };

        $nameCol = $firstExistingColumn($db, $columnExists, $table, ['name', 'contract_name', 'title']);
        $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx', 'idx_site']);
        $estimateCol = $firstExistingColumn($db, $columnExists, $table, ['estimate_idx', 'site_estimate_idx', 'est_idx']);
        $companyCol = $firstExistingColumn($db, $columnExists, $table, ['company_name', 'partner_name', 'vendor_name', 'customer_name']);
        $statusCol = $firstExistingColumn($db, $columnExists, $table, ['status', 'contract_status']);
        $startCol = $firstExistingColumn($db, $columnExists, $table, ['start_date', 'contract_date']);
        $endCol = $firstExistingColumn($db, $columnExists, $table, ['end_date', 'completion_date']);
        $amountCol = $firstExistingColumn($db, $columnExists, $table, ['amount', 'total_amount', 'contract_amount', 'supply_amount']);
        $memoCol = $firstExistingColumn($db, $columnExists, $table, ['memo', 'note', 'comment']);

        if ($nameCol !== null) { $add($nameCol, $name); }
        if ($siteCol !== null) { $add($siteCol, $siteIdx); }
        if ($estimateCol !== null && $estimateIdx > 0) { $add($estimateCol, $estimateIdx); }
        if ($companyCol !== null && $company !== '') { $add($companyCol, $company); }
        if ($statusCol !== null && $status !== '') { $add($statusCol, $status); }
        if ($startCol !== null && $startDate !== '') { $add($startCol, $startDate); }
        if ($endCol !== null && $endDate !== '') { $add($endCol, $endDate); }
        if ($amountCol !== null) { $add($amountCol, $amount); }
        if ($memoCol !== null && $memo !== '') { $add($memoCol, $memo); }
        $add('service_code', (string)($context['service_code'] ?? 'shvq'));
        $add('tenant_id', (int)($context['tenant_id'] ?? 0));
        $add('is_deleted', 0);
        $add('created_by', (int)($context['user_pk'] ?? 0));
        $add('created_at', date('Y-m-d H:i:s'));
        $add('regdate', date('Y-m-d H:i:s'));

        if ($cols === []) {
            ApiResponse::error('SCHEMA_NOT_READY', 'insertable subcontract columns are missing', 503);
            exit;
        }

        try {
            $db->beginTransaction();
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            $stmt = $db->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . $ph . ')');
            $stmt->execute($vals);
            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            $subcontractIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'insert_subcontract', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => $table,
                'target_idx' => $subcontractIdx > 0 ? $subcontractIdx : $siteIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'subcontract inserted',
                'site_idx' => $siteIdx,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'insert_subcontract', $table, $subcontractIdx > 0 ? $subcontractIdx : $siteIdx, [
                'site_idx' => $siteIdx,
            ]);

            ApiResponse::success([
                'site_idx' => $siteIdx,
                'subcontract_idx' => $subcontractIdx,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '도급 등록 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'update_subcontract' || $todo === 'subcontract_update') {
        $subcontractIdx = (int)($_POST['subcontract_idx'] ?? $_POST['idx'] ?? 0);
        if ($subcontractIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'subcontract_idx or idx is required', 422);
            exit;
        }

        $table = null;
        foreach (['Tb_Product_Contract', 'Tb_Subcontract'] as $candidate) {
            if ($tableExists($db, $candidate)) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Product_Contract or Tb_Subcontract table is missing', 503);
            exit;
        }

        $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
        $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx', 'idx_site']);

        $where = ["{$idxCol} = ?"];
        $whereParams = [$subcontractIdx];
        if ($siteCol !== null && array_key_exists('site_idx', $_POST)) {
            $where[] = 'ISNULL(TRY_CONVERT(INT,' . $siteCol . '),0)=?';
            $whereParams[] = (int)($_POST['site_idx'] ?? 0);
        }
        if ($columnExists($db, $table, 'is_deleted')) { $where[] = 'ISNULL(is_deleted,0)=0'; }
        if ($columnExists($db, $table, 'service_code')) { $where[] = "ISNULL(service_code,'')=?"; $whereParams[] = (string)($context['service_code'] ?? 'shvq'); }
        if ($columnExists($db, $table, 'tenant_id')) { $where[] = 'ISNULL(tenant_id,0)=?'; $whereParams[] = (int)($context['tenant_id'] ?? 0); }

        $findStmt = $db->prepare('SELECT TOP 1 ' . $idxCol . ' AS idx FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
        $findStmt->execute($whereParams);
        $found = $findStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($found)) {
            ApiResponse::error('NOT_FOUND', '도급 정보를 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = [];
        $setParams = [];
        $nameCol = $firstExistingColumn($db, $columnExists, $table, ['name', 'contract_name', 'title']);
        $companyCol = $firstExistingColumn($db, $columnExists, $table, ['company_name', 'partner_name', 'vendor_name', 'customer_name']);
        $statusCol = $firstExistingColumn($db, $columnExists, $table, ['status', 'contract_status']);
        $startCol = $firstExistingColumn($db, $columnExists, $table, ['start_date', 'contract_date']);
        $endCol = $firstExistingColumn($db, $columnExists, $table, ['end_date', 'completion_date']);
        $amountCol = $firstExistingColumn($db, $columnExists, $table, ['amount', 'total_amount', 'contract_amount', 'supply_amount']);
        $memoCol = $firstExistingColumn($db, $columnExists, $table, ['memo', 'note', 'comment']);
        $estimateCol = $firstExistingColumn($db, $columnExists, $table, ['estimate_idx', 'site_estimate_idx', 'est_idx']);

        if ($nameCol !== null) {
            if (array_key_exists('name', $_POST)) {
                $setSql[] = $nameCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'name', 255, false);
            } elseif (array_key_exists('contract_name', $_POST)) {
                $setSql[] = $nameCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'contract_name', 255, false);
            } elseif (array_key_exists('title', $_POST)) {
                $setSql[] = $nameCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'title', 255, false);
            }
        }
        if ($companyCol !== null) {
            if (array_key_exists('company_name', $_POST)) {
                $setSql[] = $companyCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'company_name', 255, false);
            } elseif (array_key_exists('partner_name', $_POST)) {
                $setSql[] = $companyCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'partner_name', 255, false);
            }
        }
        if ($statusCol !== null && array_key_exists('status', $_POST)) {
            $setSql[] = $statusCol . ' = ?';
            $setParams[] = FmsInputValidator::string($_POST, 'status', 60, false);
        }
        if ($startCol !== null) {
            if (array_key_exists('start_date', $_POST)) {
                $setSql[] = $startCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'start_date', 20, false);
            } elseif (array_key_exists('contract_date', $_POST)) {
                $setSql[] = $startCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'contract_date', 20, false);
            }
        }
        if ($endCol !== null) {
            if (array_key_exists('end_date', $_POST)) {
                $setSql[] = $endCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'end_date', 20, false);
            } elseif (array_key_exists('completion_date', $_POST)) {
                $setSql[] = $endCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'completion_date', 20, false);
            }
        }
        if ($amountCol !== null && (array_key_exists('amount', $_POST) || array_key_exists('total_amount', $_POST) || array_key_exists('contract_amount', $_POST))) {
            $rawAmount = $_POST['amount'] ?? $_POST['total_amount'] ?? $_POST['contract_amount'] ?? 0;
            $setSql[] = $amountCol . ' = ?';
            $setParams[] = $parseIntAmount($rawAmount);
        }
        if ($memoCol !== null) {
            if (array_key_exists('memo', $_POST)) {
                $setSql[] = $memoCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'memo', 2000, false);
            } elseif (array_key_exists('note', $_POST)) {
                $setSql[] = $memoCol . ' = ?';
                $setParams[] = FmsInputValidator::string($_POST, 'note', 2000, false);
            }
        }
        if ($estimateCol !== null && array_key_exists('estimate_idx', $_POST)) {
            $setSql[] = $estimateCol . ' = ?';
            $setParams[] = FmsInputValidator::int($_POST, 'estimate_idx', false, 0) ?? 0;
        }
        if ($siteCol !== null && array_key_exists('site_idx', $_POST)) {
            $setSql[] = $siteCol . ' = ?';
            $setParams[] = FmsInputValidator::int($_POST, 'site_idx', false, 1);
        }

        if ($setSql === []) {
            ApiResponse::error('INVALID_PARAM', 'no updatable fields provided', 422);
            exit;
        }
        if ($columnExists($db, $table, 'updated_at')) { $setSql[] = 'updated_at = ?'; $setParams[] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, $table, 'updated_by')) { $setSql[] = 'updated_by = ?'; $setParams[] = (int)($context['user_pk'] ?? 0); }

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute(array_merge($setParams, $whereParams));
            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'update_subcontract', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => $table,
                'target_idx' => $subcontractIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'subcontract updated',
                'subcontract_idx' => $subcontractIdx,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'update_subcontract', $table, $subcontractIdx, [
                'subcontract_idx' => $subcontractIdx,
            ]);

            ApiResponse::success([
                'subcontract_idx' => $subcontractIdx,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '도급 수정 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'delete_subcontract' || $todo === 'subcontract_delete') {
        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['subcontract_idx'] ?? $_POST['idx'] ?? ''));
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list or subcontract_idx is required', 422);
            exit;
        }

        $table = null;
        foreach (['Tb_Product_Contract', 'Tb_Subcontract'] as $candidate) {
            if ($tableExists($db, $candidate)) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Product_Contract or Tb_Subcontract table is missing', 503);
            exit;
        }

        $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
        $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx', 'idx_site']);
        $siteIdx = (int)($_POST['site_idx'] ?? 0);
        $ph = implode(',', array_fill(0, count($idxList), '?'));
        $where = ["{$idxCol} IN ({$ph})"];
        $params = $idxList;
        if ($siteIdx > 0 && $siteCol !== null) {
            $where[] = 'ISNULL(TRY_CONVERT(INT,' . $siteCol . '),0)=?';
            $params[] = $siteIdx;
        }
        if ($columnExists($db, $table, 'service_code')) { $where[] = "ISNULL(service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
        if ($columnExists($db, $table, 'tenant_id')) { $where[] = 'ISNULL(tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }

        try {
            $db->beginTransaction();
            $deleteMode = 'hard';
            if ($columnExists($db, $table, 'is_deleted')) {
                $deleteMode = 'soft';
                $setSql = ['is_deleted = 1'];
                $setParams = [];
                if ($columnExists($db, $table, 'deleted_at')) { $setSql[] = 'deleted_at = ?'; $setParams[] = date('Y-m-d H:i:s'); }
                if ($columnExists($db, $table, 'deleted_by')) { $setSql[] = 'deleted_by = ?'; $setParams[] = (int)($context['user_pk'] ?? 0); }
                $stmt = $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
                $stmt->execute(array_merge($setParams, $params));
                $deleted = (int)$stmt->rowCount();
            } else {
                $stmt = $db->prepare('DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
                $stmt->execute($params);
                $deleted = (int)$stmt->rowCount();
            }
            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'delete_subcontract', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => $table,
                'target_idx' => (int)($idxList[0] ?? 0),
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'subcontract deleted',
                'idx_list' => $idxList,
                'deleted_count' => $deleted,
                'delete_mode' => $deleteMode,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'delete_subcontract', $table, (int)($idxList[0] ?? 0), [
                'idx_list' => $idxList,
                'deleted_count' => $deleted,
            ]);

            ApiResponse::success([
                'idx_list' => $idxList,
                'deleted_count' => $deleted,
                'delete_mode' => $deleteMode,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '도급 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'insert_access_log' || $todo === 'access_log_insert') {
        $siteIdx = (int)($_POST['site_idx'] ?? $_POST['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }
        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $table = null;
        foreach (['Tb_Site_Access_inout', 'Tb_SiteAccessInout', 'Tb_AccessLog', 'Tb_Access'] as $candidate) {
            if ($tableExists($db, $candidate)) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'access log table is missing', 503);
            exit;
        }

        $personName = FmsInputValidator::string($_POST, 'person_name', 120, false);
        if ($personName === '') {
            $personName = FmsInputValidator::string($_POST, 'name', 120, false);
        }
        if ($personName === '') {
            $personName = FmsInputValidator::string($_POST, 'visitor_name', 120, false);
        }
        if ($personName === '') {
            ApiResponse::error('INVALID_PARAM', 'person_name/name is required', 422);
            exit;
        }

        $inTime = FmsInputValidator::string($_POST, 'in_time', 30, false);
        $outTime = FmsInputValidator::string($_POST, 'out_time', 30, false);
        $accessTime = FmsInputValidator::string($_POST, 'access_time', 30, false);
        if ($accessTime === '') {
            $accessTime = date('Y-m-d H:i:s');
        }
        $status = FmsInputValidator::string($_POST, 'status', 40, false);
        $memo = FmsInputValidator::string($_POST, 'memo', 2000, false);

        $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx', 'idx_site']);
        if ($siteCol === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'site foreign key column is missing in access log table', 503);
            exit;
        }
        $personCol = $firstExistingColumn($db, $columnExists, $table, ['person_name', 'user_name', 'name', 'employee_name', 'visitor_name']);
        $inCol = $firstExistingColumn($db, $columnExists, $table, ['in_time', 'checkin_time', 'enter_time']);
        $outCol = $firstExistingColumn($db, $columnExists, $table, ['out_time', 'checkout_time', 'leave_time']);
        $accessCol = $firstExistingColumn($db, $columnExists, $table, ['access_time', 'visit_time']);
        $statusCol = $firstExistingColumn($db, $columnExists, $table, ['status', 'access_status']);
        $memoCol = $firstExistingColumn($db, $columnExists, $table, ['memo', 'note', 'comment']);

        $cols = [];
        $vals = [];
        $add = static function (string $col, mixed $val) use (&$cols, &$vals, $db, $columnExists, $table): void {
            if ($columnExists($db, $table, $col)) {
                $cols[] = $col;
                $vals[] = $val;
            }
        };
        $add($siteCol, $siteIdx);
        if ($personCol !== null) { $add($personCol, $personName); }
        if ($inCol !== null && $inTime !== '') { $add($inCol, $inTime); }
        if ($outCol !== null && $outTime !== '') { $add($outCol, $outTime); }
        if ($accessCol !== null) { $add($accessCol, $accessTime); }
        if ($statusCol !== null && $status !== '') { $add($statusCol, $status); }
        if ($memoCol !== null && $memo !== '') { $add($memoCol, $memo); }
        $add('service_code', (string)($context['service_code'] ?? 'shvq'));
        $add('tenant_id', (int)($context['tenant_id'] ?? 0));
        $add('is_deleted', 0);
        $add('created_by', (int)($context['user_pk'] ?? 0));
        $add('created_at', date('Y-m-d H:i:s'));
        $add('regdate', date('Y-m-d H:i:s'));

        if ($cols === []) {
            ApiResponse::error('SCHEMA_NOT_READY', 'insertable access log columns are missing', 503);
            exit;
        }

        try {
            $db->beginTransaction();
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            $stmt = $db->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . $ph . ')');
            $stmt->execute($vals);
            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            $logIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'insert_access_log', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => $table,
                'target_idx' => $logIdx > 0 ? $logIdx : $siteIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'access log inserted',
                'site_idx' => $siteIdx,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'insert_access_log', $table, $logIdx > 0 ? $logIdx : $siteIdx, [
                'site_idx' => $siteIdx,
            ]);

            ApiResponse::success([
                'site_idx' => $siteIdx,
                'access_log_idx' => $logIdx,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '출입 기록 등록 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'delete_access_log' || $todo === 'access_log_delete') {
        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['access_log_idx'] ?? $_POST['idx'] ?? ''));
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list or access_log_idx is required', 422);
            exit;
        }

        $table = null;
        foreach (['Tb_Site_Access_inout', 'Tb_SiteAccessInout', 'Tb_AccessLog', 'Tb_Access'] as $candidate) {
            if ($tableExists($db, $candidate)) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'access log table is missing', 503);
            exit;
        }

        $idxCol = $firstExistingColumn($db, $columnExists, $table, ['idx', 'id']) ?? 'idx';
        $siteCol = $firstExistingColumn($db, $columnExists, $table, ['site_idx', 'idx_site']);
        $siteIdx = (int)($_POST['site_idx'] ?? 0);
        $ph = implode(',', array_fill(0, count($idxList), '?'));
        $where = ["{$idxCol} IN ({$ph})"];
        $params = $idxList;
        if ($siteIdx > 0 && $siteCol !== null) {
            $where[] = 'ISNULL(TRY_CONVERT(INT,' . $siteCol . '),0)=?';
            $params[] = $siteIdx;
        }
        if ($columnExists($db, $table, 'service_code')) { $where[] = "ISNULL(service_code,'')=?"; $params[] = (string)($context['service_code'] ?? 'shvq'); }
        if ($columnExists($db, $table, 'tenant_id')) { $where[] = 'ISNULL(tenant_id,0)=?'; $params[] = (int)($context['tenant_id'] ?? 0); }

        try {
            $db->beginTransaction();
            $deleteMode = 'hard';
            if ($columnExists($db, $table, 'is_deleted')) {
                $deleteMode = 'soft';
                $setSql = ['is_deleted = 1'];
                $setParams = [];
                if ($columnExists($db, $table, 'deleted_at')) { $setSql[] = 'deleted_at = ?'; $setParams[] = date('Y-m-d H:i:s'); }
                if ($columnExists($db, $table, 'deleted_by')) { $setSql[] = 'deleted_by = ?'; $setParams[] = (int)($context['user_pk'] ?? 0); }
                $stmt = $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
                $stmt->execute(array_merge($setParams, $params));
                $deleted = (int)$stmt->rowCount();
            } else {
                $stmt = $db->prepare('DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
                $stmt->execute($params);
                $deleted = (int)$stmt->rowCount();
            }
            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'delete_access_log', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => $table,
                'target_idx' => (int)($idxList[0] ?? 0),
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'access log deleted',
                'idx_list' => $idxList,
                'deleted_count' => $deleted,
                'delete_mode' => $deleteMode,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'delete_access_log', $table, (int)($idxList[0] ?? 0), [
                'idx_list' => $idxList,
                'deleted_count' => $deleted,
            ]);

            ApiResponse::success([
                'idx_list' => $idxList,
                'deleted_count' => $deleted,
                'delete_mode' => $deleteMode,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '출입 기록 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'search') {
        $q = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
        if ($q === '') {
            ApiResponse::success(['data' => [], 'total' => 0], 'OK', '검색어 없음');
            exit;
        }

        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $where = ['s.' . $siteNameCol . ' LIKE ?'];
        $params = ['%' . $q . '%'];
        if ($siteNumberCol !== null) {
            $where[] = 'ISNULL(s.' . $siteNumberCol . ", '') LIKE ?";
            $params[] = '%' . $q . '%';
        }

        if ($tableExists($db, 'Tb_Members') && $columnExists($db, 'Tb_Site', 'member_idx')) {
            $where[] = 'ISNULL(m.name, \'\') LIKE ?';
            $params[] = '%' . $q . '%';
        }

        $joinMember = $tableExists($db, 'Tb_Members') && $columnExists($db, 'Tb_Site', 'member_idx')
            ? 'LEFT JOIN Tb_Members m ON s.member_idx = m.idx'
            : 'LEFT JOIN (SELECT CAST(NULL AS INT) AS idx, CAST(NULL AS NVARCHAR(1)) AS name) m ON 1=0';

        $isDeleted = $columnExists($db, 'Tb_Site', 'is_deleted') ? ' AND ISNULL(s.is_deleted,0)=0' : '';

        $siteNumberExpr = $siteNumberCol !== null
            ? 'ISNULL(CAST(s.' . $siteNumberCol . " AS NVARCHAR(60)), '')"
            : "CAST('' AS NVARCHAR(60))";

        $sql = "SELECT TOP ({$limit}) s.idx, ISNULL(s.{$siteNameCol}, '') AS site_name, ISNULL(m.name, '') AS member_name, {$siteNumberExpr} AS site_number\n"
            . "FROM Tb_Site s {$joinMember}\n"
            . 'WHERE (' . implode(' OR ', $where) . ')' . $isDeleted . ' ORDER BY s.idx DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'data' => is_array($rows) ? $rows : [],
            'total' => is_array($rows) ? count($rows) : 0,
            'q' => $q,
        ], 'OK', '현장 검색 성공');
        exit;
    }

    if ($todo === 'insert') {
        $name = FmsInputValidator::string($_POST, 'site_name', 150, false);
        if ($name === '' && array_key_exists('name', $_POST)) {
            $name = FmsInputValidator::string($_POST, 'name', 150, true);
        }
        if ($name === '') {
            ApiResponse::error('INVALID_PARAM', 'site_name or name is required', 422);
            exit;
        }

        $status = trim((string)($_POST['site_status'] ?? $_POST['status'] ?? '예정'));
        $status = $normalizeSiteStatus($status);
        $status = FmsInputValidator::oneOf($status, 'site_status', ['예정', '진행', '중지', '완료'], false);

        $memberIdx = FmsInputValidator::int($_POST, 'member_idx', false, 1);
        $headIdx = FmsInputValidator::int($_POST, 'head_idx', false, 1);
        $siteNumber = $siteNumberCol !== null ? FmsInputValidator::string($_POST, 'site_number', 60, false) : '';
        $legacySiteCode = FmsInputValidator::string($_POST, 'site_code', 60, false);
        if ($siteNumber === '' && $legacySiteCode !== '') {
            $siteNumber = $legacySiteCode;
        }
        $construction = $siteConstructionCol !== null ? FmsInputValidator::string($_POST, 'construction', 255, false) : '';
        $employeeIdx = $siteEmployeeCol !== null ? FmsInputValidator::int($_POST, 'employee_idx', false, 0) : null;
        $employee1Idx = $siteEmployee1Col !== null ? FmsInputValidator::int($_POST, 'employee1_idx', false, 0) : null;
        $phonebookIdx = $sitePhonebookCol !== null ? FmsInputValidator::int($_POST, 'phonebook_idx', false, 0) : null;
        $region = $siteRegionCol !== null ? FmsInputValidator::string($_POST, 'region', 120, false) : '';
        $targetTeam = $siteTargetTeamCol !== null ? FmsInputValidator::string($_POST, 'target_team', 120, false) : '';
        $constructionDate = FmsInputValidator::string($_POST, array_key_exists('construction_date', $_POST) ? 'construction_date' : 'start_date', 20, false);
        $completionDate = FmsInputValidator::string($_POST, array_key_exists('completion_date', $_POST) ? 'completion_date' : 'end_date', 20, false);
        $qtyInputKey = array_key_exists('total_qty', $_POST) ? 'total_qty' : 'external_employee';
        $totalQty = ($siteTotalQtyCol !== null || $siteExternalEmployeeCol !== null)
            ? FmsInputValidator::int($_POST, $qtyInputKey, false, 0)
            : null;
        $warrantyPeriod = $siteWarrantyCol !== null ? FmsInputValidator::int($_POST, 'warranty_period', false, 0) : null;

        $validateCoordinates($_POST);

        $fieldMap = [
            $siteNameCol => $name,
            'address' => FmsInputValidator::string($_POST, 'address', 255, false),
            'address_detail' => FmsInputValidator::string($_POST, 'address_detail', 255, false),
            'zipcode' => FmsInputValidator::string($_POST, 'zipcode', 20, false),
            'manager_name' => FmsInputValidator::string($_POST, 'manager_name', 80, false),
            'manager_tel' => FmsInputValidator::string($_POST, 'manager_tel', 40, false),
            'memo' => FmsInputValidator::string($_POST, 'memo', 2000, false),
            'latitude' => FmsInputValidator::string($_POST, 'latitude', 30, false),
            'lat' => FmsInputValidator::string($_POST, 'lat', 30, false),
            'longitude' => FmsInputValidator::string($_POST, 'longitude', 30, false),
            'lng' => FmsInputValidator::string($_POST, 'lng', 30, false),
        ];
        if ($siteNumberCol !== null) {
            $fieldMap[$siteNumberCol] = $siteNumber;
        }
        if ($legacySiteCode !== '' && $siteNumberCol !== 'site_code' && $columnExists($db, 'Tb_Site', 'site_code')) {
            $fieldMap['site_code'] = $legacySiteCode;
        }
        if ($siteConstructionCol !== null) {
            $fieldMap[$siteConstructionCol] = $construction;
        }
        if ($siteRegionCol !== null) {
            $fieldMap[$siteRegionCol] = $region;
        }
        if ($siteTargetTeamCol !== null) {
            $fieldMap[$siteTargetTeamCol] = $targetTeam;
        }
        if ($siteConstructionDateCol !== null) {
            $fieldMap[$siteConstructionDateCol] = $constructionDate;
        }
        if ($siteCompletionDateCol !== null) {
            $fieldMap[$siteCompletionDateCol] = $completionDate;
        }
        if ($siteEmployeeCol !== null && $employeeIdx !== null) {
            $fieldMap[$siteEmployeeCol] = $employeeIdx;
        }
        if ($siteEmployee1Col !== null && $employee1Idx !== null) {
            $fieldMap[$siteEmployee1Col] = $employee1Idx;
        }
        if ($sitePhonebookCol !== null && $phonebookIdx !== null) {
            $fieldMap[$sitePhonebookCol] = $phonebookIdx;
        }
        if ($totalQty !== null) {
            if ($siteTotalQtyCol !== null) {
                $fieldMap[$siteTotalQtyCol] = $totalQty;
            }
            if ($siteExternalEmployeeCol !== null) {
                $fieldMap[$siteExternalEmployeeCol] = $totalQty;
            }
        }
        if ($siteWarrantyCol !== null && $warrantyPeriod !== null) {
            $fieldMap[$siteWarrantyCol] = $warrantyPeriod;
        }

        $columns = [];
        $values = [];

        foreach ($fieldMap as $column => $value) {
            if (!$columnExists($db, 'Tb_Site', $column)) {
                continue;
            }
            if ($column === $siteNameCol || $value !== '') {
                $columns[] = $column;
                $values[] = $value;
            }
        }

        if ($columnExists($db, 'Tb_Site', $siteStatusCol)) {
            $columns[] = $siteStatusCol;
            $values[] = $status;
        }
        if ($memberIdx !== null && $columnExists($db, 'Tb_Site', 'member_idx')) {
            $columns[] = 'member_idx';
            $values[] = $memberIdx;
        }
        if ($headIdx !== null && $columnExists($db, 'Tb_Site', 'head_idx')) {
            $columns[] = 'head_idx';
            $values[] = $headIdx;
        }
        if ($columnExists($db, 'Tb_Site', 'is_deleted')) { $columns[] = 'is_deleted'; $values[] = 0; }
        if ($columnExists($db, 'Tb_Site', 'created_by')) { $columns[] = 'created_by'; $values[] = (int)($context['user_pk'] ?? 0); }
        if ($columnExists($db, 'Tb_Site', 'created_at')) { $columns[] = 'created_at'; $values[] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, 'Tb_Site', 'regdate')) { $columns[] = 'regdate'; $values[] = date('Y-m-d H:i:s'); }

        if ($columns === []) {
            ApiResponse::error('SCHEMA_ERROR', 'insertable columns not found', 500);
            exit;
        }

        try {
            $db->beginTransaction();
            $ph = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $db->prepare('INSERT INTO Tb_Site (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
            $stmt->execute($values);
            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            $siteIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
            if ($siteIdx <= 0) {
                throw new RuntimeException('failed to resolve inserted site idx');
            }
            $db->commit();

            $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'insert', [
                'service_code' => (string)($context['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($context['tenant_id'] ?? 0),
                'target_table' => 'Tb_Site',
                'target_idx' => $siteIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'site inserted',
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'insert', 'Tb_Site', $siteIdx, ['site_idx' => $siteIdx]);

            ApiResponse::success([
                'idx' => $siteIdx,
                'item' => $site,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '현장 등록 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'update') {
        $siteIdx = (int)($_POST['idx'] ?? $_POST['site_idx'] ?? $_GET['idx'] ?? $_GET['site_idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx or site_idx is required', 422);
            exit;
        }

        $current = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($current === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $validateCoordinates($_POST);

        $setSql = [];
        $params = [];

        $updateIfProvided = static function (string $inputKey, string $column, string $type, int $maxLen = 255) use (&$setSql, &$params, $db, $columnExists): void {
            if (!array_key_exists($inputKey, $_POST) || !$columnExists($db, 'Tb_Site', $column)) {
                return;
            }

            if ($type === 'string') {
                $value = FmsInputValidator::string($_POST, $inputKey, $maxLen, false);
            } else {
                $value = trim((string)$_POST[$inputKey]);
            }

            $setSql[] = $column . ' = ?';
            $params[] = $value;
        };

        $updateIfProvided('site_name', $siteNameCol, 'string', 150);
        $updateIfProvided('name', $siteNameCol, 'string', 150);
        if ($siteNumberCol !== null && (array_key_exists('site_number', $_POST) || array_key_exists('site_code', $_POST))) {
            $siteNumberInputKey = array_key_exists('site_number', $_POST) ? 'site_number' : 'site_code';
            $setSql[] = $siteNumberCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, $siteNumberInputKey, 60, false);
        }
        if ($siteNumberCol !== 'site_code') {
            $updateIfProvided('site_code', 'site_code', 'string', 60);
        }
        $updateIfProvided('address', 'address', 'string', 255);
        $updateIfProvided('address_detail', 'address_detail', 'string', 255);
        $updateIfProvided('zipcode', 'zipcode', 'string', 20);
        $updateIfProvided('manager_name', 'manager_name', 'string', 80);
        $updateIfProvided('manager_tel', 'manager_tel', 'string', 40);
        $updateIfProvided('memo', 'memo', 'string', 2000);
        if ($siteConstructionCol !== null && array_key_exists('construction', $_POST) && $columnExists($db, 'Tb_Site', $siteConstructionCol)) {
            $setSql[] = $siteConstructionCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'construction', 255, false);
        }
        if ($siteTargetTeamCol !== null && array_key_exists('target_team', $_POST) && $columnExists($db, 'Tb_Site', $siteTargetTeamCol)) {
            $setSql[] = $siteTargetTeamCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'target_team', 120, false);
        }
        if ((array_key_exists('start_date', $_POST) || array_key_exists('construction_date', $_POST))
            && $siteConstructionDateCol !== null
            && $columnExists($db, 'Tb_Site', $siteConstructionDateCol)
        ) {
            $inputKey = array_key_exists('construction_date', $_POST) ? 'construction_date' : 'start_date';
            $setSql[] = $siteConstructionDateCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, $inputKey, 20, false);
        }
        if ((array_key_exists('end_date', $_POST) || array_key_exists('completion_date', $_POST))
            && $siteCompletionDateCol !== null
            && $columnExists($db, 'Tb_Site', $siteCompletionDateCol)
        ) {
            $inputKey = array_key_exists('completion_date', $_POST) ? 'completion_date' : 'end_date';
            $setSql[] = $siteCompletionDateCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, $inputKey, 20, false);
        }
        $updateIfProvided('latitude', 'latitude', 'string', 30);
        $updateIfProvided('lat', 'lat', 'string', 30);
        $updateIfProvided('longitude', 'longitude', 'string', 30);
        $updateIfProvided('lng', 'lng', 'string', 30);
        if ($siteEmployeeCol !== null && array_key_exists('employee_idx', $_POST) && $columnExists($db, 'Tb_Site', $siteEmployeeCol)) {
            $setSql[] = $siteEmployeeCol . ' = ?';
            $params[] = FmsInputValidator::int($_POST, 'employee_idx', false, 0);
        }
        if ($siteEmployee1Col !== null && array_key_exists('employee1_idx', $_POST) && $columnExists($db, 'Tb_Site', $siteEmployee1Col)) {
            $setSql[] = $siteEmployee1Col . ' = ?';
            $params[] = FmsInputValidator::int($_POST, 'employee1_idx', false, 0);
        }
        if ($sitePhonebookCol !== null && array_key_exists('phonebook_idx', $_POST) && $columnExists($db, 'Tb_Site', $sitePhonebookCol)) {
            $setSql[] = $sitePhonebookCol . ' = ?';
            $params[] = FmsInputValidator::int($_POST, 'phonebook_idx', false, 0);
        }
        if ($siteRegionCol !== null && array_key_exists('region', $_POST) && $columnExists($db, 'Tb_Site', $siteRegionCol)) {
            $setSql[] = $siteRegionCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'region', 120, false);
        }
        if (($siteTotalQtyCol !== null || $siteExternalEmployeeCol !== null)
            && (array_key_exists('total_qty', $_POST) || array_key_exists('external_employee', $_POST))
        ) {
            $qtyInputKey = array_key_exists('total_qty', $_POST) ? 'total_qty' : 'external_employee';
            $totalQty = FmsInputValidator::int($_POST, $qtyInputKey, false, 0);
            if ($siteTotalQtyCol !== null && $columnExists($db, 'Tb_Site', $siteTotalQtyCol)) {
                $setSql[] = $siteTotalQtyCol . ' = ?';
                $params[] = $totalQty;
            }
            if ($siteExternalEmployeeCol !== null && $siteExternalEmployeeCol !== $siteTotalQtyCol && $columnExists($db, 'Tb_Site', $siteExternalEmployeeCol)) {
                $setSql[] = $siteExternalEmployeeCol . ' = ?';
                $params[] = $totalQty;
            }
        }
        if ($siteWarrantyCol !== null && array_key_exists('warranty_period', $_POST) && $columnExists($db, 'Tb_Site', $siteWarrantyCol)) {
            $setSql[] = $siteWarrantyCol . ' = ?';
            $params[] = FmsInputValidator::int($_POST, 'warranty_period', false, 0);
        }

        if ((array_key_exists('site_status', $_POST) || array_key_exists('status', $_POST)) && $columnExists($db, 'Tb_Site', $siteStatusCol)) {
            $status = trim((string)($_POST['site_status'] ?? $_POST['status'] ?? ''));
            $status = $normalizeSiteStatus($status);
            $status = FmsInputValidator::oneOf($status, 'site_status', ['예정', '진행', '중지', '완료'], false);
            $setSql[] = $siteStatusCol . ' = ?';
            $params[] = $status;
        }

        if (array_key_exists('member_idx', $_POST) && $columnExists($db, 'Tb_Site', 'member_idx')) {
            $memberIdx = FmsInputValidator::int($_POST, 'member_idx', false, 1);
            $setSql[] = 'member_idx = ?';
            $params[] = $memberIdx;
        }
        if (array_key_exists('head_idx', $_POST) && $columnExists($db, 'Tb_Site', 'head_idx')) {
            $headIdx = FmsInputValidator::int($_POST, 'head_idx', false, 1);
            $setSql[] = 'head_idx = ?';
            $params[] = $headIdx;
        }

        if ($setSql === []) {
            ApiResponse::error('INVALID_PARAM', 'no updatable fields provided', 422);
            exit;
        }

        if ($columnExists($db, 'Tb_Site', 'updated_at')) { $setSql[] = 'updated_at = ?'; $params[] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, 'Tb_Site', 'updated_by')) { $setSql[] = 'updated_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }

        $where = ['idx = ?'];
        if ($columnExists($db, 'Tb_Site', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $params[] = $siteIdx;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE Tb_Site SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $db->commit();

            $item = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
            $shadowId = $enqueueShadow($db, $security, $context, 'update', 'Tb_Site', $siteIdx, ['site_idx' => $siteIdx]);

            ApiResponse::success([
                'idx' => $siteIdx,
                'item' => $item,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '현장 정보 수정 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'delete') {
        if ($roleLevel < 4) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, ['required' => 4, 'current' => $roleLevel]);
            exit;
        }

        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['idx'] ?? ''));
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list is required', 422);
            exit;
        }

        $ph = implode(',', array_fill(0, count($idxList), '?'));

        try {
            $db->beginTransaction();

            $mode = 'hard';
            $deleted = 0;

            if ($columnExists($db, 'Tb_Site', 'is_deleted')) {
                $mode = 'soft';
                $setSql = ['is_deleted = 1'];
                $params = [];
                if ($columnExists($db, 'Tb_Site', 'deleted_at')) { $setSql[] = 'deleted_at = GETDATE()'; }
                if ($columnExists($db, 'Tb_Site', 'deleted_by')) { $setSql[] = 'deleted_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }
                $params = array_merge($params, $idxList);

                $stmt = $db->prepare('UPDATE Tb_Site SET ' . implode(', ', $setSql) . " WHERE idx IN ({$ph})");
                $stmt->execute($params);
                $deleted = (int)$stmt->rowCount();
            } else {
                $stmt = $db->prepare("DELETE FROM Tb_Site WHERE idx IN ({$ph})");
                $stmt->execute($idxList);
                $deleted = (int)$stmt->rowCount();
            }

            $db->commit();

            $shadowId = $enqueueShadow($db, $security, $context, 'delete', 'Tb_Site', $idxList[0] ?? 0, ['idx_list' => $idxList]);

            ApiResponse::success([
                'idx_list' => $idxList,
                'deleted_count' => $deleted,
                'delete_mode' => $mode,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '현장 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'est_list') {
        $hasIndividualTable = $tableExists($db, 'Tb_SiteEstimate');
        $hasGroupTable = $tableExists($db, 'Tb_SiteEstimateG');

        $siteIdx = (int)($_GET['site_idx'] ?? $_GET['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        $rows = [];
        $individualCount = 0;
        $groupCount = 0;
        $sourceErrors = [];

        if ($hasIndividualTable) {
            try {
                $siteFk = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', ['site_idx', 'idx_site']);
                if ($siteFk !== null) {
                    $where = ["{$siteFk} = ?"];
                    $params = [$siteIdx];
                    if ($columnExists($db, 'Tb_SiteEstimate', 'is_deleted')) {
                        $where[] = 'ISNULL(is_deleted,0)=0';
                    }

                    $stmt = $db->prepare('SELECT * FROM Tb_SiteEstimate WHERE ' . implode(' AND ', $where) . ' ORDER BY idx DESC');
                    $stmt->execute($params);
                    $individualRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (is_array($individualRows)) {
                        foreach ($individualRows as $row) {
                            if (!is_array($row)) {
                                continue;
                            }
                            $row['type'] = 'individual';
                            $row['estimate_type'] = 'individual';
                            if (!array_key_exists('estimate_status', $row) && array_key_exists('status', $row)) {
                                $row['estimate_status'] = $row['status'];
                            }
                            $rows[] = $row;
                            $individualCount++;
                        }
                    }
                }
            } catch (Throwable $e) {
                $sourceErrors['individual'] = $e->getMessage();
            }
        }

        if ($hasGroupTable) {
            try {
                $groupSiteFk = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimateG', ['site_idx', 'idx_site']);
                if ($groupSiteFk !== null) {
                    $where = ["{$groupSiteFk} = ?"];
                    $params = [$siteIdx];
                    if ($columnExists($db, 'Tb_SiteEstimateG', 'is_deleted')) {
                        $where[] = 'ISNULL(is_deleted,0)=0';
                    }

                    $stmt = $db->prepare('SELECT * FROM Tb_SiteEstimateG WHERE ' . implode(' AND ', $where) . ' ORDER BY idx DESC');
                    $stmt->execute($params);
                    $groupRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (is_array($groupRows)) {
                        foreach ($groupRows as $row) {
                            if (!is_array($row)) {
                                continue;
                            }
                            $row['type'] = 'group';
                            $row['estimate_type'] = 'group';
                            if (!array_key_exists('estimate_title', $row)) {
                                $row['estimate_title'] = (string)($row['name'] ?? $row['title'] ?? '');
                            }
                            if (!array_key_exists('name', $row)) {
                                $row['name'] = (string)($row['estimate_title'] ?? '');
                            }
                            if (!array_key_exists('estimate_no', $row)) {
                                $row['estimate_no'] = (string)($row['number'] ?? '');
                            }
                            if (!array_key_exists('estimate_status', $row) && array_key_exists('status', $row)) {
                                $row['estimate_status'] = $row['status'];
                            }
                            if (!array_key_exists('site_idx', $row)) {
                                $row['site_idx'] = $siteIdx;
                            }
                            $rows[] = $row;
                            $groupCount++;
                        }
                    }
                }
            } catch (Throwable $e) {
                $sourceErrors['group'] = $e->getMessage();
            }
        }

        $tsToInt = static function (mixed $value): int {
            if (!is_scalar($value)) {
                return 0;
            }
            $raw = trim((string)$value);
            if ($raw === '' || $raw === '1900-01-01' || str_starts_with($raw, '1900-01-01')) {
                return 0;
            }
            $ts = strtotime($raw);
            return $ts === false ? 0 : (int)$ts;
        };
        usort($rows, static function (array $a, array $b) use ($tsToInt): int {
            foreach (['updated_at', 'regdate', 'registered_date', 'created_at', 'insert_date'] as $dateCol) {
                $at = $tsToInt($a[$dateCol] ?? '');
                $bt = $tsToInt($b[$dateCol] ?? '');
                if ($at !== $bt) {
                    return $bt <=> $at;
                }
            }
            return (int)($b['idx'] ?? 0) <=> (int)($a['idx'] ?? 0);
        });

        $response = [
            'site_idx' => $siteIdx,
            'data' => $rows,
            'total' => count($rows),
            'individual_count' => $individualCount,
            'group_count' => $groupCount,
        ];
        if (shvEnvBool('APP_DEBUG', false) && $sourceErrors !== []) {
            $response['source_errors'] = $sourceErrors;
        }

        ApiResponse::success($response, 'OK', '현장 견적 목록 조회 성공');
        exit;
    }

    if ($todo === 'est_detail' || $todo === 'estimate_detail' || $todo === 'detail_est') {
        $estimateIdx = (int)($_GET['estimate_idx'] ?? $_GET['est_idx'] ?? $_GET['idx'] ?? 0);
        if ($estimateIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'estimate_idx is required', 422);
            exit;
        }

        $estimate = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
        if ($estimate === null) {
            ApiResponse::error('NOT_FOUND', '견적을 찾을 수 없습니다', 404);
            exit;
        }

        $attachments = $loadEstimateAttachments($db, $tableExists, $columnExists, $firstExistingColumn, $normalizePublicUrl, $context, $estimateIdx);
        $estimate['attachments'] = $attachments;
        $estimate['attach_list'] = $attachments;

        $payload = $estimate;
        $payload['estimate'] = $estimate;
        $payload['items'] = is_array($estimate['items'] ?? null) ? $estimate['items'] : [];
        $payload['attachments'] = $attachments;
        $payload['attach_list'] = $attachments;

        ApiResponse::success($payload, 'OK', '현장 견적 상세 조회 성공');
        exit;
    }

    if ($todo === 'est_file_list' || $todo === 'estimate_file_list') {
        $estimateIdx = (int)($_GET['estimate_idx'] ?? $_GET['est_idx'] ?? $_GET['idx'] ?? 0);
        if ($estimateIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'estimate_idx is required', 422);
            exit;
        }

        $estimate = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
        if ($estimate === null) {
            ApiResponse::error('NOT_FOUND', '견적을 찾을 수 없습니다', 404);
            exit;
        }

        $attachments = $loadEstimateAttachments($db, $tableExists, $columnExists, $firstExistingColumn, $normalizePublicUrl, $context, $estimateIdx);
        ApiResponse::success([
            'estimate_idx' => $estimateIdx,
            'data' => $attachments,
            'total' => count($attachments),
        ], 'OK', '견적 첨부파일 목록 조회 성공');
        exit;
    }

    if ($todo === 'insert_est') {
        if (!$tableExists($db, 'Tb_SiteEstimate')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_SiteEstimate table is missing', 503);
            exit;
        }

        $siteIdx = (int)($_POST['site_idx'] ?? $_POST['idx'] ?? 0);
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'site_idx is required', 422);
            exit;
        }

        if ($loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx) === null) {
            ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
            exit;
        }

        $siteFk = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', ['site_idx', 'idx_site']);
        if ($siteFk === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'site foreign key column is missing in Tb_SiteEstimate', 503);
            exit;
        }

        $estimateNo = FmsInputValidator::string($_POST, 'estimate_no', 60, false);
        if ($estimateNo === '') {
            $estimateNo = $nextEstimateNo($db, $columnExists);
        }

        $status = $normalizeEstimateStatus((string)($_POST['estimate_status'] ?? $_POST['status'] ?? 'DRAFT'));
        $currencyCode = strtoupper(FmsInputValidator::string($_POST, 'currency_code', 10, false));
        if ($currencyCode === '') {
            $currencyCode = 'KRW';
        }
        $taxRate = FmsInputValidator::decimal((string)($_POST['tax_rate'] ?? ''), 'tax_rate', true, 0, 100);
        if ($taxRate === null) {
            $taxRate = 10.0;
        }

        $columns = [$siteFk];
        $values = [$siteIdx];

        $addIfProvided = static function (string $inputKey, array $candidates, int $maxLen = 255) use (&$columns, &$values, $db, $columnExists, $firstExistingColumn): void {
            if (!array_key_exists($inputKey, $_POST)) {
                return;
            }
            $column = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', $candidates);
            if ($column === null || in_array($column, $columns, true)) {
                return;
            }
            $columns[] = $column;
            $values[] = FmsInputValidator::string($_POST, $inputKey, $maxLen, false);
        };

        $addIfProvided('estimate_title', ['estimate_title', 'title', 'estimate_name', 'name'], 200);
        $addIfProvided('title', ['title', 'estimate_title', 'estimate_name', 'name'], 200);
        $addIfProvided('estimate_date', ['estimate_date', 'est_date', 'date'], 20);
        $addIfProvided('contract_date', ['contract_date'], 20);
        $addIfProvided('memo', ['memo', 'note'], 2000);
        if (array_key_exists('employee_idx', $_POST)) {
            $estimateEmployeeCol = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', ['employee_idx', 'reg_employee_idx', 'writer_idx']);
            if ($estimateEmployeeCol !== null && !in_array($estimateEmployeeCol, $columns, true)) {
                $columns[] = $estimateEmployeeCol;
                $values[] = FmsInputValidator::int($_POST, 'employee_idx', false, 0) ?? 0;
            }
        }
        if (array_key_exists('order_amount', $_POST)) {
            $orderAmountCol = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', ['order_amount', 'contract_amount', 'target_amount']);
            if ($orderAmountCol !== null && !in_array($orderAmountCol, $columns, true)) {
                $columns[] = $orderAmountCol;
                $values[] = $parseIntAmount($_POST['order_amount']);
            }
        }

        if ($columnExists($db, 'Tb_SiteEstimate', 'estimate_no')) {
            $columns[] = 'estimate_no';
            $values[] = $estimateNo;
        }
        if ($columnExists($db, 'Tb_SiteEstimate', $estimateStatusCol)) {
            $columns[] = $estimateStatusCol;
            $values[] = $status;
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'currency_code')) {
            $columns[] = 'currency_code';
            $values[] = $currencyCode;
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'tax_rate')) {
            $columns[] = 'tax_rate';
            $values[] = $taxRate;
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'version_no')) {
            $columns[] = 'version_no';
            $values[] = 1;
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'is_deleted')) {
            $columns[] = 'is_deleted';
            $values[] = 0;
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'created_at')) {
            $columns[] = 'created_at';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'regdate')) {
            $columns[] = 'regdate';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'created_by')) {
            $columns[] = 'created_by';
            $values[] = (int)($context['user_pk'] ?? 0);
        }

        $items = $parseItems();

        try {
            $db->beginTransaction();
            $ph = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $db->prepare('INSERT INTO Tb_SiteEstimate (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
            $stmt->execute($values);

            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            $estimateIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
            if ($estimateIdx <= 0) {
                throw new RuntimeException('failed to resolve inserted estimate idx');
            }

            $itemInserted = $insertEstimateItems($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx, $siteIdx, $items, (int)($context['user_pk'] ?? 0));
            $totals = $recalcEstimateTotals($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);

            $db->commit();

            $estimate = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
            $shadowId = $enqueueShadow($db, $security, $context, 'insert_est', 'Tb_SiteEstimate', $estimateIdx, ['site_idx' => $siteIdx]);

            ApiResponse::success([
                'estimate_idx' => $estimateIdx,
                'site_idx' => $siteIdx,
                'item_inserted_count' => $itemInserted,
                'totals' => $totals,
                'item' => $estimate,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '현장 견적 등록 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'update_est') {
        if (!$tableExists($db, 'Tb_SiteEstimate')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_SiteEstimate table is missing', 503);
            exit;
        }

        $estimateIdx = (int)($_POST['estimate_idx'] ?? $_POST['est_idx'] ?? $_POST['idx'] ?? 0);
        if ($estimateIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'estimate_idx is required', 422);
            exit;
        }

        $current = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
        if ($current === null) {
            ApiResponse::error('NOT_FOUND', '견적을 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = [];
        $params = [];

        $setIfProvided = static function (string $inputKey, array $candidates, int $maxLen = 255) use (&$setSql, &$params, $db, $columnExists, $firstExistingColumn): void {
            if (!array_key_exists($inputKey, $_POST)) {
                return;
            }
            $column = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', $candidates);
            if ($column === null) {
                return;
            }
            $value = FmsInputValidator::string($_POST, $inputKey, $maxLen, false);
            $setSql[] = $column . ' = ?';
            $params[] = $value;
        };

        $setIfProvided('estimate_title', ['estimate_title', 'title', 'estimate_name', 'name'], 200);
        $setIfProvided('title', ['title', 'estimate_title', 'estimate_name', 'name'], 200);
        $setIfProvided('estimate_no', ['estimate_no'], 60);
        $setIfProvided('estimate_date', ['estimate_date', 'est_date', 'date'], 20);
        $setIfProvided('contract_date', ['contract_date'], 20);
        $setIfProvided('memo', ['memo', 'note'], 2000);
        if (array_key_exists('employee_idx', $_POST)) {
            $estimateEmployeeCol = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', ['employee_idx', 'reg_employee_idx', 'writer_idx']);
            if ($estimateEmployeeCol !== null) {
                $setSql[] = $estimateEmployeeCol . ' = ?';
                $params[] = FmsInputValidator::int($_POST, 'employee_idx', false, 0) ?? 0;
            }
        }
        if (array_key_exists('order_amount', $_POST)) {
            $orderAmountCol = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', ['order_amount', 'contract_amount', 'target_amount']);
            if ($orderAmountCol !== null) {
                $setSql[] = $orderAmountCol . ' = ?';
                $params[] = $parseIntAmount($_POST['order_amount']);
            }
        }

        if (array_key_exists('estimate_status', $_POST) || array_key_exists('status', $_POST)) {
            $status = $normalizeEstimateStatus((string)($_POST['estimate_status'] ?? $_POST['status'] ?? ''));
            $prevStatus = $normalizeEstimateStatus((string)($current[$estimateStatusCol] ?? 'DRAFT'));
            if (!$estimateStatusTransitionAllowed($prevStatus, $status)) {
                ApiResponse::error('BUSINESS_RULE_VIOLATION', '견적 상태 전환이 허용되지 않습니다', 409, ['from' => $prevStatus, 'to' => $status]);
                exit;
            }
            if ($columnExists($db, 'Tb_SiteEstimate', $estimateStatusCol)) {
                $setSql[] = $estimateStatusCol . ' = ?';
                $params[] = $status;
            }
        }

        if (array_key_exists('currency_code', $_POST) && $columnExists($db, 'Tb_SiteEstimate', 'currency_code')) {
            $currency = strtoupper(FmsInputValidator::string($_POST, 'currency_code', 10, false));
            if ($currency !== '') {
                $setSql[] = 'currency_code = ?';
                $params[] = $currency;
            }
        }
        if (array_key_exists('tax_rate', $_POST) && $columnExists($db, 'Tb_SiteEstimate', 'tax_rate')) {
            $taxRate = FmsInputValidator::decimal((string)$_POST['tax_rate'], 'tax_rate', false, 0, 100);
            $setSql[] = 'tax_rate = ?';
            $params[] = $taxRate;
        }

        if (array_key_exists('site_idx', $_POST)) {
            $siteFk = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', ['site_idx', 'idx_site']);
            if ($siteFk !== null) {
                $siteIdx = FmsInputValidator::int($_POST, 'site_idx', false, 1);
                if ($siteIdx !== null && $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx) === null) {
                    ApiResponse::error('NOT_FOUND', '현장을 찾을 수 없습니다', 404);
                    exit;
                }
                $setSql[] = $siteFk . ' = ?';
                $params[] = $siteIdx;
            }
        }

        $items = $parseItems();
        $replaceItems = FmsInputValidator::bool($_POST, 'replace_items', false) || FmsInputValidator::bool($_POST, 'items_replace', false);

        if ($setSql === [] && $items === []) {
            ApiResponse::error('INVALID_PARAM', 'no updatable fields/items provided', 422);
            exit;
        }

        try {
            $db->beginTransaction();

            if ($setSql !== []) {
                if ($columnExists($db, 'Tb_SiteEstimate', 'updated_at')) { $setSql[] = 'updated_at = ?'; $params[] = date('Y-m-d H:i:s'); }
                if ($columnExists($db, 'Tb_SiteEstimate', 'updated_by')) { $setSql[] = 'updated_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }

                $where = ['idx = ?'];
                if ($columnExists($db, 'Tb_SiteEstimate', 'is_deleted')) {
                    $where[] = 'ISNULL(is_deleted,0)=0';
                }
                $params[] = $estimateIdx;

                $stmt = $db->prepare('UPDATE Tb_SiteEstimate SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
                $stmt->execute($params);
            }

            $itemDeleted = 0;
            $itemInserted = 0;
            if ($items !== []) {
                if ($replaceItems) {
                    $itemDeleted = $deleteEstimateItemsByEstimateIds($db, $tableExists, $columnExists, $firstExistingColumn, [$estimateIdx], (int)($context['user_pk'] ?? 0));
                }

                $siteIdxForItems = (int)($current['site_idx'] ?? $current['idx_site'] ?? 0);
                $itemInserted = $insertEstimateItems($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx, $siteIdxForItems, $items, (int)($context['user_pk'] ?? 0));
            }

            $versionNo = $bumpEstimateVersion($db, $columnExists, $estimateIdx);
            $totals = $recalcEstimateTotals($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
            $db->commit();

            $estimate = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
            $shadowId = $enqueueShadow($db, $security, $context, 'update_est', 'Tb_SiteEstimate', $estimateIdx, ['estimate_idx' => $estimateIdx]);

            ApiResponse::success([
                'estimate_idx' => $estimateIdx,
                'item_deleted_count' => $itemDeleted,
                'item_inserted_count' => $itemInserted,
                'replace_items' => $replaceItems,
                'version_no' => $versionNo,
                'totals' => $totals,
                'item' => $estimate,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '현장 견적 수정 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'delete_estimate') {
        if (!$tableExists($db, 'Tb_SiteEstimate')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_SiteEstimate table is missing', 503);
            exit;
        }

        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['estimate_idx'] ?? $_POST['idx'] ?? ''));
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list is required', 422);
            exit;
        }

        $force = FmsInputValidator::bool($_POST, 'force', false);

        if (!$force && $tableExists($db, 'Tb_Bill') && $columnExists($db, 'Tb_Bill', 'estimate_idx')) {
            $ph = implode(',', array_fill(0, count($idxList), '?'));
            $stmt = $db->prepare("SELECT COUNT(*) FROM Tb_Bill WHERE estimate_idx IN ({$ph})");
            $stmt->execute($idxList);
            $billCnt = (int)$stmt->fetchColumn();
            if ($billCnt > 0) {
                ApiResponse::error('BUSINESS_RULE_VIOLATION', '연결된 청구 데이터가 있어 삭제할 수 없습니다', 409, ['bill_count' => $billCnt, 'hint' => 'force=1로 강제 삭제 가능']);
                exit;
            }
        }

        $ph = implode(',', array_fill(0, count($idxList), '?'));

        try {
            $db->beginTransaction();

            $mode = 'hard';
            if ($columnExists($db, 'Tb_SiteEstimate', 'is_deleted')) {
                $mode = 'soft';
                $setSql = ['is_deleted = 1'];
                $params = [];
                if ($columnExists($db, 'Tb_SiteEstimate', 'deleted_at')) { $setSql[] = 'deleted_at = GETDATE()'; }
                if ($columnExists($db, 'Tb_SiteEstimate', 'deleted_by')) { $setSql[] = 'deleted_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }
                $params = array_merge($params, $idxList);
                $stmt = $db->prepare('UPDATE Tb_SiteEstimate SET ' . implode(', ', $setSql) . " WHERE idx IN ({$ph})");
                $stmt->execute($params);
                $estimateDeleted = (int)$stmt->rowCount();
            } else {
                $stmt = $db->prepare("DELETE FROM Tb_SiteEstimate WHERE idx IN ({$ph})");
                $stmt->execute($idxList);
                $estimateDeleted = (int)$stmt->rowCount();
            }

            $itemDeleted = $deleteEstimateItemsByEstimateIds($db, $tableExists, $columnExists, $firstExistingColumn, $idxList, (int)($context['user_pk'] ?? 0));
            $db->commit();

            $shadowId = $enqueueShadow($db, $security, $context, 'delete_estimate', 'Tb_SiteEstimate', $idxList[0] ?? 0, ['idx_list' => $idxList]);

            ApiResponse::success([
                'idx_list' => $idxList,
                'estimate_deleted_count' => $estimateDeleted,
                'estimate_item_deleted_count' => $itemDeleted,
                'delete_mode' => $mode,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '견적 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'copy_est') {
        $estimateIdx = (int)($_POST['estimate_idx'] ?? $_POST['idx'] ?? 0);
        if ($estimateIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'estimate_idx is required', 422);
            exit;
        }

        $source = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
        if ($source === null) {
            ApiResponse::error('NOT_FOUND', '원본 견적을 찾을 수 없습니다', 404);
            exit;
        }

        $siteIdx = (int)($_POST['target_site_idx'] ?? $_POST['site_idx'] ?? ($source['site_idx'] ?? $source['idx_site'] ?? 0));
        if ($siteIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'target_site_idx/site_idx is required', 422);
            exit;
        }

        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);
        if ($site === null) {
            ApiResponse::error('NOT_FOUND', '대상 현장을 찾을 수 없습니다', 404);
            exit;
        }

        $siteFk = $firstExistingColumn($db, $columnExists, 'Tb_SiteEstimate', ['site_idx', 'idx_site']);
        if ($siteFk === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'site foreign key column is missing in Tb_SiteEstimate', 503);
            exit;
        }

        $columns = [$siteFk];
        $values = [$siteIdx];

        $copyCols = [
            'estimate_title', 'title', 'estimate_name', 'name',
            'estimate_date', 'est_date', 'date',
            'contract_date', 'memo', 'note',
            'currency_code', 'tax_rate', 'supply_amount', 'vat_amount', 'total_amount',
        ];
        foreach ($copyCols as $column) {
            if ($columnExists($db, 'Tb_SiteEstimate', $column) && array_key_exists($column, $source)) {
                $columns[] = $column;
                $values[] = $source[$column];
            }
        }

        if ($columnExists($db, 'Tb_SiteEstimate', 'estimate_no')) {
            $columns[] = 'estimate_no';
            $values[] = $nextEstimateNo($db, $columnExists);
        }
        if ($columnExists($db, 'Tb_SiteEstimate', $estimateStatusCol)) {
            $columns[] = $estimateStatusCol;
            $values[] = 'DRAFT';
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'version_no')) {
            $columns[] = 'version_no';
            $values[] = 1;
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'is_deleted')) { $columns[] = 'is_deleted'; $values[] = 0; }
        if ($columnExists($db, 'Tb_SiteEstimate', 'created_by')) { $columns[] = 'created_by'; $values[] = (int)($context['user_pk'] ?? 0); }
        if ($columnExists($db, 'Tb_SiteEstimate', 'created_at')) { $columns[] = 'created_at'; $values[] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, 'Tb_SiteEstimate', 'regdate')) { $columns[] = 'regdate'; $values[] = date('Y-m-d H:i:s'); }

        try {
            $db->beginTransaction();
            $ph = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $db->prepare('INSERT INTO Tb_SiteEstimate (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
            $stmt->execute($values);

            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            $newEstimateIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
            if ($newEstimateIdx <= 0) {
                throw new RuntimeException('failed to resolve copied estimate idx');
            }

            $sourceItems = is_array($source['items'] ?? null) ? $source['items'] : [];
            $itemInserted = $insertEstimateItems($db, $tableExists, $columnExists, $firstExistingColumn, $newEstimateIdx, $siteIdx, $sourceItems, (int)($context['user_pk'] ?? 0));
            $totals = $recalcEstimateTotals($db, $tableExists, $columnExists, $firstExistingColumn, $newEstimateIdx);

            $db->commit();

            $item = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $newEstimateIdx);
            $shadowId = $enqueueShadow($db, $security, $context, 'copy_est', 'Tb_SiteEstimate', $newEstimateIdx, ['source_estimate_idx' => $estimateIdx]);

            ApiResponse::success([
                'source_estimate_idx' => $estimateIdx,
                'estimate_idx' => $newEstimateIdx,
                'site_idx' => $siteIdx,
                'item_inserted_count' => $itemInserted,
                'totals' => $totals,
                'item' => $item,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '견적 복제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'recalc_est') {
        $estimateIdx = (int)($_POST['estimate_idx'] ?? $_POST['idx'] ?? 0);
        if ($estimateIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'estimate_idx is required', 422);
            exit;
        }

        $estimate = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
        if ($estimate === null) {
            ApiResponse::error('NOT_FOUND', '견적을 찾을 수 없습니다', 404);
            exit;
        }

        try {
            $db->beginTransaction();
            $versionNo = $bumpEstimateVersion($db, $columnExists, $estimateIdx);
            $totals = $recalcEstimateTotals($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
            $db->commit();

            $item = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
            $shadowId = $enqueueShadow($db, $security, $context, 'recalc_est', 'Tb_SiteEstimate', $estimateIdx, ['estimate_idx' => $estimateIdx]);

            ApiResponse::success([
                'estimate_idx' => $estimateIdx,
                'version_no' => $versionNo,
                'totals' => $totals,
                'item' => $item,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '견적 합계 재계산 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'upsert_est_items') {
        $estimateIdx = (int)($_POST['estimate_idx'] ?? $_POST['idx'] ?? 0);
        if ($estimateIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'estimate_idx is required', 422);
            exit;
        }

        $estimate = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
        if ($estimate === null) {
            ApiResponse::error('NOT_FOUND', '견적을 찾을 수 없습니다', 404);
            exit;
        }

        $items = $parseItems();
        if ($items === []) {
            ApiResponse::error('INVALID_PARAM', 'items is required', 422);
            exit;
        }

        $replaceItems = FmsInputValidator::bool($_POST, 'replace_items', false);

        $siteIdx = (int)($estimate['site_idx'] ?? $estimate['idx_site'] ?? 0);

        try {
            $db->beginTransaction();
            $deleted = 0;
            if ($replaceItems) {
                $deleted = $deleteEstimateItemsByEstimateIds($db, $tableExists, $columnExists, $firstExistingColumn, [$estimateIdx], (int)($context['user_pk'] ?? 0));
            }

            $inserted = $insertEstimateItems($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx, $siteIdx, $items, (int)($context['user_pk'] ?? 0));
            $versionNo = $bumpEstimateVersion($db, $columnExists, $estimateIdx);
            $totals = $recalcEstimateTotals($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
            $db->commit();

            $item = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
            $shadowId = $enqueueShadow($db, $security, $context, 'upsert_est_items', 'Tb_EstimateItem', $estimateIdx, ['estimate_idx' => $estimateIdx]);

            ApiResponse::success([
                'estimate_idx' => $estimateIdx,
                'replace_items' => $replaceItems,
                'item_deleted_count' => $deleted,
                'item_inserted_count' => $inserted,
                'version_no' => $versionNo,
                'totals' => $totals,
                'item' => $item,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '견적 항목 일괄 반영 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'update_est_item') {
        if (!$tableExists($db, 'Tb_EstimateItem')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_EstimateItem table is missing', 503);
            exit;
        }

        $itemIdx = (int)($_POST['item_idx'] ?? $_POST['idx'] ?? 0);
        if ($itemIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'item_idx is required', 422);
            exit;
        }

        $setSql = [];
        $params = [];
        $allowed = [
            'item_name', 'name', 'spec', 'unit', 'qty', 'unit_price',
            'supply_amount', 'vat_amount', 'total_amount', 'currency_code', 'tax_rate', 'memo', 'sort_no'
        ];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $_POST) || !$columnExists($db, 'Tb_EstimateItem', $field)) {
                continue;
            }
            $setSql[] = $field . ' = ?';
            $params[] = trim((string)$_POST[$field]);
        }

        if ($setSql === []) {
            ApiResponse::error('INVALID_PARAM', 'no updatable fields provided', 422);
            exit;
        }

        if ($columnExists($db, 'Tb_EstimateItem', 'updated_at')) { $setSql[] = 'updated_at = ?'; $params[] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, 'Tb_EstimateItem', 'updated_by')) { $setSql[] = 'updated_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }

        $params[] = $itemIdx;
        $where = 'idx = ?';
        if ($columnExists($db, 'Tb_EstimateItem', 'is_deleted')) {
            $where .= ' AND ISNULL(is_deleted,0)=0';
        }

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE Tb_EstimateItem SET ' . implode(', ', $setSql) . ' WHERE ' . $where);
            $stmt->execute($params);

            $estimateFk = $estimateItemFkColumn($db, $columnExists, $firstExistingColumn);
            $estimateIdx = 0;
            if ($estimateFk !== null) {
                $readStmt = $db->prepare('SELECT TOP 1 ' . $estimateFk . ' FROM Tb_EstimateItem WHERE idx = ?');
                $readStmt->execute([$itemIdx]);
                $estimateIdx = (int)$readStmt->fetchColumn();
                if ($estimateIdx > 0) {
                    $bumpEstimateVersion($db, $columnExists, $estimateIdx);
                    $totals = $recalcEstimateTotals($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
                } else {
                    $totals = ['supply_amount' => 0, 'vat_amount' => 0, 'total_amount' => 0];
                }
            } else {
                $totals = ['supply_amount' => 0, 'vat_amount' => 0, 'total_amount' => 0];
            }

            $db->commit();

            $shadowId = $enqueueShadow($db, $security, $context, 'update_est_item', 'Tb_EstimateItem', $itemIdx, ['item_idx' => $itemIdx, 'estimate_idx' => $estimateIdx]);
            ApiResponse::success([
                'item_idx' => $itemIdx,
                'estimate_idx' => $estimateIdx,
                'totals' => $totals,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '견적 항목 수정 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'delete_est_item') {
        if (!$tableExists($db, 'Tb_EstimateItem')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_EstimateItem table is missing', 503);
            exit;
        }

        $itemIdx = (int)($_POST['item_idx'] ?? $_POST['idx'] ?? 0);
        if ($itemIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'item_idx is required', 422);
            exit;
        }

        try {
            $db->beginTransaction();

            $estimateFk = $estimateItemFkColumn($db, $columnExists, $firstExistingColumn);
            $estimateIdx = 0;
            if ($estimateFk !== null) {
                $stmt = $db->prepare('SELECT TOP 1 ' . $estimateFk . ' FROM Tb_EstimateItem WHERE idx = ?');
                $stmt->execute([$itemIdx]);
                $estimateIdx = (int)$stmt->fetchColumn();
            }

            if ($columnExists($db, 'Tb_EstimateItem', 'is_deleted')) {
                $setSql = ['is_deleted = 1'];
                if ($columnExists($db, 'Tb_EstimateItem', 'deleted_at')) { $setSql[] = 'deleted_at = GETDATE()'; }
                if ($columnExists($db, 'Tb_EstimateItem', 'deleted_by')) { $setSql[] = 'deleted_by = ' . (int)($context['user_pk'] ?? 0); }
                $stmt = $db->prepare('UPDATE Tb_EstimateItem SET ' . implode(', ', $setSql) . ' WHERE idx = ?');
                $stmt->execute([$itemIdx]);
                $deleted = (int)$stmt->rowCount();
            } else {
                $stmt = $db->prepare('DELETE FROM Tb_EstimateItem WHERE idx = ?');
                $stmt->execute([$itemIdx]);
                $deleted = (int)$stmt->rowCount();
            }

            $versionNo = 0;
            $totals = ['supply_amount' => 0, 'vat_amount' => 0, 'total_amount' => 0];
            if ($estimateIdx > 0) {
                $versionNo = $bumpEstimateVersion($db, $columnExists, $estimateIdx);
                $totals = $recalcEstimateTotals($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
            }

            $db->commit();

            $shadowId = $enqueueShadow($db, $security, $context, 'delete_est_item', 'Tb_EstimateItem', $itemIdx, ['item_idx' => $itemIdx, 'estimate_idx' => $estimateIdx]);

            ApiResponse::success([
                'item_idx' => $itemIdx,
                'deleted_count' => $deleted,
                'estimate_idx' => $estimateIdx,
                'version_no' => $versionNo,
                'totals' => $totals,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '견적 항목 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'approve_est') {
        $estimateIdx = (int)($_POST['estimate_idx'] ?? $_POST['idx'] ?? 0);
        if ($estimateIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'estimate_idx is required', 422);
            exit;
        }

        $estimate = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
        if ($estimate === null) {
            ApiResponse::error('NOT_FOUND', '견적을 찾을 수 없습니다', 404);
            exit;
        }

        $nextStatus = $normalizeEstimateStatus((string)($_POST['status'] ?? $_POST['estimate_status'] ?? 'APPROVED'));
        $prevStatus = $normalizeEstimateStatus((string)($estimate[$estimateStatusCol] ?? 'DRAFT'));

        if (!$estimateStatusTransitionAllowed($prevStatus, $nextStatus)) {
            ApiResponse::error('BUSINESS_RULE_VIOLATION', '견적 상태 전환이 허용되지 않습니다', 409, ['from' => $prevStatus, 'to' => $nextStatus]);
            exit;
        }

        if (!$columnExists($db, 'Tb_SiteEstimate', $estimateStatusCol)) {
            ApiResponse::error('SCHEMA_NOT_READY', 'status column missing in Tb_SiteEstimate', 503);
            exit;
        }

        $setSql = [$estimateStatusCol . ' = ?'];
        $params = [$nextStatus];
        if ($columnExists($db, 'Tb_SiteEstimate', 'approved_at')) {
            $setSql[] = 'approved_at = ?';
            $params[] = $nextStatus === 'APPROVED' ? date('Y-m-d H:i:s') : null;
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'approved_by')) {
            $setSql[] = 'approved_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_SiteEstimate', 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        $params[] = $estimateIdx;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE Tb_SiteEstimate SET ' . implode(', ', $setSql) . ' WHERE idx = ?');
            $stmt->execute($params);
            $versionNo = $bumpEstimateVersion($db, $columnExists, $estimateIdx);
            $db->commit();

            $item = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
            $shadowId = $enqueueShadow($db, $security, $context, 'approve_est', 'Tb_SiteEstimate', $estimateIdx, ['status' => $nextStatus]);

            ApiResponse::success([
                'estimate_idx' => $estimateIdx,
                'from_status' => $prevStatus,
                'to_status' => $nextStatus,
                'version_no' => $versionNo,
                'item' => $item,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '견적 상태 변경 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'est_pdf_data') {
        $estimateIdx = (int)($_GET['estimate_idx'] ?? $_GET['idx'] ?? 0);
        if ($estimateIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'estimate_idx is required', 422);
            exit;
        }

        $estimate = $loadEstimate($db, $tableExists, $columnExists, $firstExistingColumn, $estimateIdx);
        if ($estimate === null) {
            ApiResponse::error('NOT_FOUND', '견적을 찾을 수 없습니다', 404);
            exit;
        }

        $siteIdx = (int)($estimate['site_idx'] ?? $estimate['idx_site'] ?? 0);
        $site = $loadSite($db, $tableExists, $columnExists, $firstExistingColumn, $siteIdx);

        $totals = [
            'supply_amount' => (float)($estimate['supply_amount'] ?? 0),
            'vat_amount' => (float)($estimate['vat_amount'] ?? 0),
            'total_amount' => (float)($estimate['total_amount'] ?? 0),
            'currency_code' => (string)($estimate['currency_code'] ?? 'KRW'),
            'tax_rate' => (float)($estimate['tax_rate'] ?? 10),
        ];

        ApiResponse::success([
            'estimate' => $estimate,
            'site' => $site,
            'items' => $estimate['items'] ?? [],
            'totals' => $totals,
            'meta' => [
                'generated_at' => date('c'),
                'template' => 'site_estimate_v1',
            ],
        ], 'OK', 'PDF 출력 데이터 조회 성공');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo: ' . $todoRaw, 400, ['todo' => $todoRaw]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_PARAM', $e->getMessage(), 422);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : '서버 오류가 발생했습니다',
        500
    );
}
