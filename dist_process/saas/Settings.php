<?php
declare(strict_types=1);
/**
 * SHVQ V2 — FMS Settings API
 *
 * todo=save_member_required POST
 * todo=save_member_regions  POST
 * todo=save_item_property   POST
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/security/FmsInputValidator.php';
require_once __DIR__ . '/../../dist_library/saas/ShadowWriteQueueService.php';
require_once __DIR__ . '/../../dist_library/saas/DevLogService.php';

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
    $writeTodos = ['save_member_required', 'save_member_regions', 'save_item_property'];

    if (!in_array($todo, $writeTodos, true)) {
        ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo: ' . $todoRaw, 400, ['todo' => $todoRaw]);
        exit;
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
        exit;
    }
    if (!$auth->validateCsrf()) {
        ApiResponse::error('CSRF_TOKEN_INVALID', '보안 검증 실패', 403);
        exit;
    }

    $roleLevel = (int)($context['role_level'] ?? 0);
    if ($roleLevel < 1 || $roleLevel > 2) {
        ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required_max' => 2, 'current' => $roleLevel]);
        exit;
    }

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
            $column = trim((string)$candidate);
            if ($column !== '' && $columnExistsFn($pdo, $table, $column)) {
                return $column;
            }
        }
        return null;
    };

    $qi = static function (string $column): string {
        return '[' . str_replace(']', ']]', $column) . ']';
    };

    $ensureUserSettingsSchema = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn) use (&$columnExistsCache, &$tableExistsCache): void {
        $table = 'Tb_UserSettings';
        if (!$tableExistsFn($pdo, $table)) {
            $sql = "CREATE TABLE dbo.Tb_UserSettings (\n"
                . "    idx INT IDENTITY(1,1) PRIMARY KEY,\n"
                . "    service_code NVARCHAR(50) NOT NULL DEFAULT '',\n"
                . "    tenant_id INT NOT NULL DEFAULT 0,\n"
                . "    setting_group NVARCHAR(50) NOT NULL DEFAULT 'member',\n"
                . "    setting_key NVARCHAR(120) NOT NULL DEFAULT '',\n"
                . "    setting_value NVARCHAR(MAX) NOT NULL DEFAULT '',\n"
                . "    setting_type NVARCHAR(20) NOT NULL DEFAULT 'json',\n"
                . "    user_id NVARCHAR(80) NOT NULL DEFAULT '',\n"
                . "    user_idx INT NOT NULL DEFAULT 0,\n"
                . "    employee_idx INT NOT NULL DEFAULT 0,\n"
                . "    member_idx INT NOT NULL DEFAULT 0,\n"
                . "    updated_by INT NOT NULL DEFAULT 0,\n"
                . "    is_deleted BIT NOT NULL DEFAULT 0,\n"
                . "    regdate DATETIME NOT NULL DEFAULT GETDATE(),\n"
                . "    updated_at DATETIME NULL\n"
                . ")";
            $pdo->exec($sql);
            $tableExistsCache[strtolower($table)] = true;
        }

        $ddlMap = [
            'service_code' => "ALTER TABLE dbo.Tb_UserSettings ADD service_code NVARCHAR(50) NOT NULL DEFAULT ''",
            'tenant_id' => "ALTER TABLE dbo.Tb_UserSettings ADD tenant_id INT NOT NULL DEFAULT 0",
            'setting_group' => "ALTER TABLE dbo.Tb_UserSettings ADD setting_group NVARCHAR(50) NOT NULL DEFAULT 'member'",
            'setting_key' => "ALTER TABLE dbo.Tb_UserSettings ADD setting_key NVARCHAR(120) NOT NULL DEFAULT ''",
            'setting_value' => "ALTER TABLE dbo.Tb_UserSettings ADD setting_value NVARCHAR(MAX) NOT NULL DEFAULT ''",
            'setting_type' => "ALTER TABLE dbo.Tb_UserSettings ADD setting_type NVARCHAR(20) NOT NULL DEFAULT 'json'",
            'updated_by' => "ALTER TABLE dbo.Tb_UserSettings ADD updated_by INT NOT NULL DEFAULT 0",
            'is_deleted' => "ALTER TABLE dbo.Tb_UserSettings ADD is_deleted BIT NOT NULL DEFAULT 0",
            'regdate' => "ALTER TABLE dbo.Tb_UserSettings ADD regdate DATETIME NOT NULL DEFAULT GETDATE()",
            'updated_at' => "ALTER TABLE dbo.Tb_UserSettings ADD updated_at DATETIME NULL",
        ];

        foreach ($ddlMap as $column => $ddl) {
            if ($columnExistsFn($pdo, $table, $column)) {
                continue;
            }
            try {
                $pdo->exec($ddl);
                $columnExistsCache[strtolower($table . '.' . $column)] = true;
            } catch (Throwable) {
                // Ignore DDL race/constraint conflicts; runtime code is adaptive.
            }
        }

        try {
            $pdo->exec("CREATE INDEX IX_Tb_UserSettings_scope_key ON dbo.Tb_UserSettings(service_code, tenant_id, setting_group, setting_key)");
        } catch (Throwable) {
            // Index may already exist.
        }
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

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $add('service_code', (string)($payload['service_code'] ?? 'shvq'));
        $add('tenant_id', (int)($payload['tenant_id'] ?? 0));
        $add('api_name', 'Settings.php');
        $add('todo', $todoName);
        $add('target_table', (string)($payload['target_table'] ?? 'Tb_UserSettings'));
        $add('target_idx', (int)($payload['target_idx'] ?? 0));
        $add('actor_user_pk', (int)($payload['actor_user_pk'] ?? 0));
        $add('actor_login_id', (string)($payload['actor_login_id'] ?? ''));
        $add('status', (string)($payload['status'] ?? 'SUCCESS'));
        $add('message', (string)($payload['message'] ?? 'settings saved'));
        $add('detail_json', $payloadJson);
        $add('created_at', date('Y-m-d H:i:s'));
        $add('regdate', date('Y-m-d H:i:s'));

        if ($columns === []) {
            return;
        }

        $ph = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare('INSERT INTO Tb_SvcAuditLog (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
        $stmt->execute($values);
    };

    $enqueueShadow = static function (PDO $pdo, array $securityCfg, array $ctx, string $todoName, int $targetIdx, array $payload = []): int {
        try {
            $svc = new ShadowWriteQueueService($pdo, $securityCfg);
            return $svc->enqueueJob([
                'service_code' => (string)($ctx['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($ctx['tenant_id'] ?? 0),
                'api' => 'dist_process/saas/Settings.php',
                'todo' => $todoName,
                'target_table' => 'Tb_UserSettings',
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

    $jsonDecodeArray = static function (mixed $raw, string $fieldName): array {
        if (is_array($raw)) {
            return $raw;
        }

        $text = trim((string)$raw);
        if ($text === '') {
            throw new InvalidArgumentException($fieldName . ' is required');
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException($fieldName . ' must be valid JSON object');
        }

        return $decoded;
    };

    $normalizeColor = static function (mixed $raw): string {
        $value = strtoupper(trim((string)$raw));
        if (preg_match('/^#[0-9A-F]{6}$/', $value)) {
            return $value;
        }
        if (preg_match('/^[0-9A-F]{6}$/', $value)) {
            return '#' . $value;
        }
        return '#FFFFFF';
    };

    $ensureUserSettingsSchema($db, $tableExists, $columnExists);
    if (!$tableExists($db, 'Tb_UserSettings')) {
        ApiResponse::error('SCHEMA_NOT_READY', 'Tb_UserSettings table is missing', 503);
        exit;
    }

    $idxCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['idx', 'id']);
    $serviceCodeCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['service_code']);
    $tenantIdCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['tenant_id']);
    $settingGroupCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['setting_group']);
    $settingKeyCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['setting_key']);
    $settingValueCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['setting_value']);
    $settingTypeCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['setting_type']);
    $userIdCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['user_id']);
    $userIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['user_idx']);
    $employeeIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['employee_idx']);
    $memberIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['member_idx']);
    $isDeletedCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['is_deleted']);
    $createdAtCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['created_at']);
    $createdByCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['created_by']);
    $updatedAtCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['updated_at', 'updated_date']);
    $updatedByCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['updated_by']);
    $regdateCol = $firstExistingColumn($db, $columnExists, 'Tb_UserSettings', ['regdate']);

    if ($settingKeyCol === null || $settingValueCol === null) {
        ApiResponse::error('SCHEMA_NOT_READY', 'Tb_UserSettings setting_key/setting_value columns are missing', 503);
        exit;
    }

    $serviceCode = (string)($context['service_code'] ?? 'shvq');
    $tenantId = (int)($context['tenant_id'] ?? 0);
    $actorUserPk = (int)($context['user_pk'] ?? 0);

    $upsertSetting = static function (
        string $settingGroup,
        string $settingKey,
        string $settingValue,
        string $settingType
    ) use (
        $db,
        $qi,
        $idxCol,
        $serviceCodeCol,
        $tenantIdCol,
        $settingGroupCol,
        $settingKeyCol,
        $settingValueCol,
        $settingTypeCol,
        $userIdCol,
        $userIdxCol,
        $employeeIdxCol,
        $memberIdxCol,
        $isDeletedCol,
        $createdAtCol,
        $createdByCol,
        $updatedAtCol,
        $updatedByCol,
        $regdateCol,
        $serviceCode,
        $tenantId,
        $actorUserPk
    ): int {
        $systemUserId = '__SYSTEM__:' . $settingKey;
        $scopeWhere = [$qi($settingKeyCol) . ' = ?'];
        $scopeParams = [$settingKey];

        if ($serviceCodeCol !== null) {
            $scopeWhere[] = $qi($serviceCodeCol) . ' = ?';
            $scopeParams[] = $serviceCode;
        }
        if ($tenantIdCol !== null) {
            $scopeWhere[] = 'ISNULL(' . $qi($tenantIdCol) . ', 0) = ?';
            $scopeParams[] = $tenantId;
        }
        if ($settingGroupCol !== null) {
            $scopeWhere[] = $qi($settingGroupCol) . ' = ?';
            $scopeParams[] = $settingGroup;
        }
        if ($userIdCol !== null) {
            $scopeWhere[] = $qi($userIdCol) . ' = ?';
            $scopeParams[] = $systemUserId;
        }
        if ($userIdxCol !== null) {
            $scopeWhere[] = 'ISNULL(' . $qi($userIdxCol) . ', 0) = 0';
        }
        if ($employeeIdxCol !== null) {
            $scopeWhere[] = 'ISNULL(' . $qi($employeeIdxCol) . ', 0) = 0';
        }
        if ($memberIdxCol !== null) {
            $scopeWhere[] = 'ISNULL(' . $qi($memberIdxCol) . ', 0) = 0';
        }
        if ($isDeletedCol !== null) {
            $scopeWhere[] = 'ISNULL(' . $qi($isDeletedCol) . ', 0) = 0';
        }

        $rowIdx = 0;
        if ($idxCol !== null) {
            $findSql = 'SELECT TOP 1 ' . $qi($idxCol) . ' AS row_idx FROM Tb_UserSettings WHERE ' . implode(' AND ', $scopeWhere)
                . ' ORDER BY ' . $qi($idxCol) . ' DESC';
            $findStmt = $db->prepare($findSql);
            $findStmt->execute($scopeParams);
            $row = $findStmt->fetch(PDO::FETCH_ASSOC);
            $rowIdx = is_array($row) ? (int)($row['row_idx'] ?? 0) : 0;
        }

        if ($rowIdx > 0 && $idxCol !== null) {
            $setSql = [$qi($settingValueCol) . ' = ?'];
            $params = [$settingValue];
            if ($settingTypeCol !== null) {
                $setSql[] = $qi($settingTypeCol) . ' = ?';
                $params[] = $settingType;
            }
            if ($isDeletedCol !== null) {
                $setSql[] = $qi($isDeletedCol) . ' = 0';
            }
            if ($updatedByCol !== null) {
                $setSql[] = $qi($updatedByCol) . ' = ?';
                $params[] = $actorUserPk;
            }
            if ($updatedAtCol !== null) {
                $setSql[] = $qi($updatedAtCol) . ' = GETDATE()';
            }

            $params[] = $rowIdx;
            $updateSql = 'UPDATE Tb_UserSettings SET ' . implode(', ', $setSql) . ' WHERE ' . $qi($idxCol) . ' = ?';
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute($params);
            return $rowIdx;
        }

        $columns = [];
        $valuesSql = [];
        $params = [];

        $addVal = static function (string $column, mixed $value) use (&$columns, &$valuesSql, &$params, $qi): void {
            $columns[] = $qi($column);
            $valuesSql[] = '?';
            $params[] = $value;
        };
        $addRaw = static function (string $column, string $valueSql) use (&$columns, &$valuesSql, $qi): void {
            $columns[] = $qi($column);
            $valuesSql[] = $valueSql;
        };

        $addVal($settingKeyCol, $settingKey);
        $addVal($settingValueCol, $settingValue);
        if ($settingTypeCol !== null) { $addVal($settingTypeCol, $settingType); }
        if ($serviceCodeCol !== null) { $addVal($serviceCodeCol, $serviceCode); }
        if ($tenantIdCol !== null) { $addVal($tenantIdCol, $tenantId); }
        if ($settingGroupCol !== null) { $addVal($settingGroupCol, $settingGroup); }
        if ($userIdCol !== null) { $addVal($userIdCol, $systemUserId); }
        if ($userIdxCol !== null) { $addVal($userIdxCol, 0); }
        if ($employeeIdxCol !== null) { $addVal($employeeIdxCol, 0); }
        if ($memberIdxCol !== null) { $addVal($memberIdxCol, 0); }
        if ($createdByCol !== null) { $addVal($createdByCol, $actorUserPk); }
        if ($updatedByCol !== null) { $addVal($updatedByCol, $actorUserPk); }
        if ($isDeletedCol !== null) { $addRaw($isDeletedCol, '0'); }
        if ($createdAtCol !== null) { $addRaw($createdAtCol, 'GETDATE()'); }
        if ($regdateCol !== null) { $addRaw($regdateCol, 'GETDATE()'); }
        if ($updatedAtCol !== null) { $addRaw($updatedAtCol, 'GETDATE()'); }

        $insertSql = 'INSERT INTO Tb_UserSettings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $valuesSql) . ')';
        $insertStmt = $db->prepare($insertSql);
        $insertStmt->execute($params);

        if ($idxCol === null) {
            return 0;
        }

        $findSql = 'SELECT TOP 1 ' . $qi($idxCol) . ' AS row_idx FROM Tb_UserSettings WHERE ' . implode(' AND ', $scopeWhere)
            . ' ORDER BY ' . $qi($idxCol) . ' DESC';
        $findStmt = $db->prepare($findSql);
        $findStmt->execute($scopeParams);
        $row = $findStmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int)($row['row_idx'] ?? 0) : 0;
    };

    $updateItemAttributes = static function (array $propertyMap) use (
        $db,
        $qi,
        $tableExists,
        $columnExists,
        $firstExistingColumn,
        $actorUserPk
    ): int {
        if (!$tableExists($db, 'Tb_Item')) {
            return 0;
        }

        $attributeCol = $firstExistingColumn($db, $columnExists, 'Tb_Item', ['attribute']);
        if ($attributeCol === null) {
            return 0;
        }

        $validKeys = [];
        foreach (array_keys($propertyMap) as $k) {
            $num = (int)$k;
            if ($num > 0) {
                $validKeys[] = $num;
            }
        }
        $validKeys = array_values(array_unique($validKeys));

        $where = [
            "ISNULL(CONVERT(NVARCHAR(40), " . $qi($attributeCol) . "), '') <> ''",
            'ISNULL(TRY_CONVERT(INT, ' . $qi($attributeCol) . '), 0) > 0',
        ];
        $whereParams = [];
        $setParams = [];

        if ($columnExists($db, 'Tb_Item', 'is_deleted')) {
            $where[] = 'ISNULL(' . $qi('is_deleted') . ',0)=0';
        }

        if ($validKeys !== []) {
            $placeholders = implode(', ', array_fill(0, count($validKeys), '?'));
            $where[] = 'TRY_CONVERT(INT, ' . $qi($attributeCol) . ') NOT IN (' . $placeholders . ')';
            $whereParams = $validKeys;
        }

        $setSql = [$qi($attributeCol) . " = '0'"];
        if ($columnExists($db, 'Tb_Item', 'updated_by')) {
            $setSql[] = $qi('updated_by') . ' = ?';
            $setParams[] = $actorUserPk;
        }
        if ($columnExists($db, 'Tb_Item', 'updated_at')) {
            $setSql[] = $qi('updated_at') . ' = GETDATE()';
        }

        $sql = 'UPDATE Tb_Item SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where);
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($setParams, $whereParams));
        return (int)$stmt->rowCount();
    };

    if ($todo === 'save_member_required') {
        $raw = $jsonDecodeArray($_POST['data'] ?? '', 'data');
        $normalized = [];
        foreach ($raw as $key => $value) {
            $name = trim((string)$key);
            if ($name === '') {
                continue;
            }
            $normalized[$name] = ((int)$value) === 1 ? 1 : 0;
        }
        if ($normalized === []) {
            ApiResponse::error('INVALID_PARAM', 'data is empty', 422);
            exit;
        }
        ksort($normalized, SORT_NATURAL);

        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('failed to encode member required settings');
        }

        $db->beginTransaction();
        $targetIdx = $upsertSetting('member', 'MEMBER_REQUIRED', $json, 'json');
        $db->commit();

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'save_member_required', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => 'Tb_UserSettings',
            'target_idx' => $targetIdx,
            'actor_user_pk' => $actorUserPk,
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'status' => 'SUCCESS',
            'message' => 'member required settings saved',
            'keys' => array_values(array_keys($normalized)),
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'save_member_required', $targetIdx, [
            'setting_key' => 'MEMBER_REQUIRED',
            'data' => $normalized,
        ]);

        ApiResponse::success([
            'setting_key' => 'MEMBER_REQUIRED',
            'item' => $normalized,
            'target_idx' => $targetIdx,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '고객 등록 필수항목 저장 완료');
        exit;
    }

    if ($todo === 'save_member_regions') {
        $raw = $jsonDecodeArray($_POST['regions'] ?? '', 'regions');
        $normalized = [];

        foreach ($raw as $key => $row) {
            $regionKey = trim((string)$key);
            if ($regionKey === '') {
                continue;
            }

            $regionName = '';
            $regionOn = 1;
            if (is_array($row)) {
                $regionName = trim((string)($row['name'] ?? ''));
                $regionOn = ((int)($row['on'] ?? 1)) === 1 ? 1 : 0;
            } else {
                $regionName = trim((string)$row);
            }

            if ($regionName === '') {
                continue;
            }

            $normalized[$regionKey] = [
                'name' => $regionName,
                'on' => $regionOn,
            ];
        }

        if ($normalized === []) {
            ApiResponse::error('INVALID_PARAM', 'regions is empty', 422);
            exit;
        }
        ksort($normalized, SORT_NATURAL);

        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('failed to encode member regions settings');
        }

        $db->beginTransaction();
        $targetIdx = $upsertSetting('member', 'MEMBER_REGIONS', $json, 'json');
        $db->commit();

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'save_member_regions', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => 'Tb_UserSettings',
            'target_idx' => $targetIdx,
            'actor_user_pk' => $actorUserPk,
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'status' => 'SUCCESS',
            'message' => 'member regions settings saved',
            'region_count' => count($normalized),
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'save_member_regions', $targetIdx, [
            'setting_key' => 'MEMBER_REGIONS',
            'regions' => $normalized,
        ]);

        ApiResponse::success([
            'setting_key' => 'MEMBER_REGIONS',
            'item' => $normalized,
            'target_idx' => $targetIdx,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '권역 저장 완료');
        exit;
    }

    if ($todo === 'save_item_property') {
        $propertyRaw = $jsonDecodeArray($_POST['property'] ?? '', 'property');
        $colorsRaw = $jsonDecodeArray($_POST['colors'] ?? '{}', 'colors');
        $updateItems = FmsInputValidator::bool($_POST, 'update_items', false);

        $property = [];
        foreach ($propertyRaw as $key => $label) {
            $propertyKey = trim((string)$key);
            if ($propertyKey === '') {
                continue;
            }
            $name = trim((string)$label);
            if ($name === '') {
                continue;
            }
            $property[$propertyKey] = $name;
        }
        if ($property === []) {
            ApiResponse::error('INVALID_PARAM', 'property is empty', 422);
            exit;
        }
        ksort($property, SORT_NATURAL);

        $colors = [];
        foreach ($colorsRaw as $key => $value) {
            $colorKey = trim((string)$key);
            if ($colorKey === '') {
                continue;
            }
            $colors[$colorKey] = $normalizeColor($value);
        }
        if ($colors === []) {
            foreach ($property as $key => $_name) {
                $colors[$key] = '#FFFFFF';
            }
        }

        $propertyJson = json_encode($property, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $colorsJson = json_encode($colors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($propertyJson) || !is_string($colorsJson)) {
            throw new RuntimeException('failed to encode item property settings');
        }

        $updatedItems = 0;
        $db->beginTransaction();
        $targetIdxA = $upsertSetting('material', 'ITEM_PROPERTY', $propertyJson, 'json');
        $targetIdxB = $upsertSetting('material', 'ITEM_PROPERTY_COLORS', $colorsJson, 'json');
        if ($updateItems) {
            $updatedItems = $updateItemAttributes($property);
        }
        $db->commit();

        $targetIdx = $targetIdxB > 0 ? $targetIdxB : $targetIdxA;

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'save_item_property', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => 'Tb_UserSettings',
            'target_idx' => $targetIdx,
            'actor_user_pk' => $actorUserPk,
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'status' => 'SUCCESS',
            'message' => 'item property settings saved',
            'property_count' => count($property),
            'update_items' => $updateItems ? 1 : 0,
            'updated_items' => $updatedItems,
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'save_item_property', $targetIdx, [
            'property' => $property,
            'colors' => $colors,
            'update_items' => $updateItems ? 1 : 0,
            'updated_items' => $updatedItems,
        ]);

        ApiResponse::success([
            'property' => $property,
            'colors' => $colors,
            'update_items' => $updateItems ? 1 : 0,
            'updated_items' => $updatedItems,
            'target_idx' => $targetIdx,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', 'PJT 속성 저장 완료');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo: ' . $todoRaw, 400, ['todo' => $todoRaw]);
} catch (InvalidArgumentException $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    ApiResponse::error('INVALID_PARAM', $e->getMessage(), 422);
} catch (RuntimeException $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    ApiResponse::error('BUSINESS_RULE_VIOLATION', $e->getMessage(), 409);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[Settings API] ' . $e->getMessage());
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : '서버 오류가 발생했습니다',
        500
    );
}
