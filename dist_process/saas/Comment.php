<?php
declare(strict_types=1);
/**
 * SHVQ V2 — Comment API (특기사항 채팅)
 *
 * todo=list    GET
 * todo=insert  POST
 * todo=send    POST (legacy alias)
 * todo=delete  POST
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

    $todoRaw = trim((string)($_GET['todo'] ?? $_POST['todo'] ?? 'list'));
    $todo = strtolower($todoRaw);
    if ($todo === 'send') {
        $todo = 'insert';
    }

    $db = DbConnection::get();
    $security = require __DIR__ . '/../../config/security.php';

    $tableName = 'Tb_Comment';
    $apiName = 'Comment.php';
    $shadowApiPath = 'dist_process/saas/Comment.php';

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

    $quoteIdentifier = static function (string $column): string {
        return '[' . str_replace(']', ']]', $column) . ']';
    };

    if (!$tableExists($db, $tableName)) {
        ApiResponse::error('SCHEMA_NOT_READY', $tableName . ' table is missing', 503);
        exit;
    }

    $toTableCol = $firstExistingColumn($db, $columnExists, $tableName, ['to_table', 'target_table']);
    $toIdxCol = $firstExistingColumn($db, $columnExists, $tableName, ['to_idx', 'target_idx']);
    $commentCol = $firstExistingColumn($db, $columnExists, $tableName, ['comment', 'message', 'contents']);
    $msgTypeCol = $firstExistingColumn($db, $columnExists, $tableName, ['msg_type', 'message_type', 'type']);
    $userIdCol = $firstExistingColumn($db, $columnExists, $tableName, ['user_id', 'login_id', 'writer_login_id']);
    $userNameCol = $firstExistingColumn($db, $columnExists, $tableName, ['user_name', 'name', 'writer_name']);
    $userWorkCol = $firstExistingColumn($db, $columnExists, $tableName, ['user_work', 'work', 'department']);
    $userPkCol = $firstExistingColumn($db, $columnExists, $tableName, ['user_idx', 'writer_user_idx']);
    $employeeIdxCol = $firstExistingColumn($db, $columnExists, $tableName, ['employee_idx', 'writer_employee_idx']);
    $serviceCodeCol = $firstExistingColumn($db, $columnExists, $tableName, ['service_code']);
    $tenantIdCol = $firstExistingColumn($db, $columnExists, $tableName, ['tenant_id']);
    $pjtKeyCol = $firstExistingColumn($db, $columnExists, $tableName, ['pjt_key']);

    $sortCol = $firstExistingColumn($db, $columnExists, $tableName, ['sort']);
    $isReadCol = $firstExistingColumn($db, $columnExists, $tableName, ['is_read']);
    $isPushCol = $firstExistingColumn($db, $columnExists, $tableName, ['is_push']);
    $chatbotCol = $firstExistingColumn($db, $columnExists, $tableName, ['chatbot']);

    $isDeletedCol = $firstExistingColumn($db, $columnExists, $tableName, ['is_deleted']);
    $createdAtCol = $firstExistingColumn($db, $columnExists, $tableName, ['created_at', 'reg_date', 'regdate']);
    $updatedAtCol = $firstExistingColumn($db, $columnExists, $tableName, ['updated_at', 'lastupdate_date']);
    $updatedByCol = $firstExistingColumn($db, $columnExists, $tableName, ['updated_by']);
    $deletedAtCol = $firstExistingColumn($db, $columnExists, $tableName, ['deleted_at']);
    $deletedByCol = $firstExistingColumn($db, $columnExists, $tableName, ['deleted_by']);

    if ($toTableCol === null || $toIdxCol === null || $commentCol === null) {
        ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Comment required columns are missing', 503, [
            'required' => ['to_table', 'to_idx', 'comment'],
        ]);
        exit;
    }

    $writeSvcAuditLog = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $api, string $todoName, string $targetTable, array $payload): void {
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
        $add('api_name', $api);
        $add('todo', $todoName);
        $add('target_table', $targetTable);
        $add('target_idx', (int)($payload['target_idx'] ?? 0));
        $add('actor_user_pk', (int)($payload['actor_user_pk'] ?? 0));
        $add('actor_login_id', (string)($payload['actor_login_id'] ?? ''));
        $add('status', (string)($payload['status'] ?? 'SUCCESS'));
        $add('message', (string)($payload['message'] ?? 'comment api write'));
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

    $enqueueShadow = static function (PDO $pdo, array $securityCfg, array $ctx, string $apiPath, string $todoName, string $targetTable, int $targetIdx, array $payload = []): int {
        try {
            $svc = new ShadowWriteQueueService($pdo, $securityCfg);
            return $svc->enqueueJob([
                'service_code' => (string)($ctx['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($ctx['tenant_id'] ?? 0),
                'api' => $apiPath,
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

    $emitCommentEvent = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        callable $firstColFn,
        callable $quoteFn,
        string $serviceCode,
        int $tenantId,
        string $eventType,
        string $actorId,
        string $resourceType,
        string $resourceId,
        string $eventValue
    ): int {
        if (!$tableExistsFn($pdo, 'Tb_EventStream')) {
            return 0;
        }

        $serviceCodeCol2 = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['service_code']);
        $tenantIdCol2 = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['tenant_id']);
        $providerCol = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['provider']);
        $eventIdCol = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['event_id']);
        $eventTypeCol = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['event_type']);
        $actorTypeCol = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['actor_type']);
        $actorIdCol = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['actor_id']);
        $resourceTypeCol = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['resource_type']);
        $resourceIdCol = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['resource_id']);
        $eventTimeCol = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['event_time']);
        $eventValueCol = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['event_value']);
        $createdAtCol2 = $firstColFn($pdo, $columnExistsFn, 'Tb_EventStream', ['created_at']);

        if ($serviceCodeCol2 === null || $tenantIdCol2 === null || $providerCol === null || $eventIdCol === null || $eventTypeCol === null) {
            return 0;
        }

        $columns = [];
        $valuesSql = [];
        $params = [];
        $addVal = static function (string $column, mixed $value) use (&$columns, &$valuesSql, &$params, $quoteFn): void {
            $columns[] = $quoteFn($column);
            $valuesSql[] = '?';
            $params[] = $value;
        };
        $addRaw = static function (string $column, string $raw) use (&$columns, &$valuesSql, $quoteFn): void {
            $columns[] = $quoteFn($column);
            $valuesSql[] = $raw;
        };

        $eventId = $eventType . ':' . $resourceType . ':' . $resourceId . ':' . date('YmdHis') . ':' . bin2hex(random_bytes(4));

        $addVal($serviceCodeCol2, $serviceCode);
        $addVal($tenantIdCol2, $tenantId);
        $addVal($providerCol, 'comment');
        $addVal($eventIdCol, $eventId);
        $addVal($eventTypeCol, $eventType);
        if ($actorTypeCol !== null) {
            $addVal($actorTypeCol, 'user');
        }
        if ($actorIdCol !== null) {
            $addVal($actorIdCol, $actorId);
        }
        if ($resourceTypeCol !== null) {
            $addVal($resourceTypeCol, $resourceType);
        }
        if ($resourceIdCol !== null) {
            $addVal($resourceIdCol, $resourceId);
        }
        if ($eventTimeCol !== null) {
            $addRaw($eventTimeCol, 'GETDATE()');
        }
        if ($eventValueCol !== null) {
            $addVal($eventValueCol, mb_substr($eventValue, 0, 900, 'UTF-8'));
        }
        if ($createdAtCol2 !== null) {
            $addRaw($createdAtCol2, 'GETDATE()');
        }

        if ($columns === []) {
            return 0;
        }

        $sql = 'INSERT INTO Tb_EventStream (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $valuesSql) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $idStmt = $pdo->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
        return (int)($idStmt ? $idStmt->fetchColumn() : 0);
    };

    $serviceCode = (string)($context['service_code'] ?? 'shvq');
    $tenantId = (int)($context['tenant_id'] ?? 0);
    $roleLevel = (int)($context['role_level'] ?? 0);
    $actorUserPk = (int)($context['user_pk'] ?? 0);

    $resolveActorProfile = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, callable $firstColFn, array $ctx): array {
        $userPk = (int)($ctx['user_pk'] ?? 0);
        $loginId = trim((string)($ctx['login_id'] ?? ''));
        $name = trim((string)($ctx['user_name'] ?? $ctx['name'] ?? ''));
        $work = '';
        $employeeIdx = (int)($ctx['employee_idx'] ?? $ctx['emp_idx'] ?? 0);

        if ($userPk > 0 && $tableExistsFn($pdo, 'Tb_Users')) {
            $userLoginCol = $firstColFn($pdo, $columnExistsFn, 'Tb_Users', ['id', 'login_id', 'user_id']);
            $userNameCol2 = $firstColFn($pdo, $columnExistsFn, 'Tb_Users', ['name', 'user_name']);
            $userWorkCol2 = $firstColFn($pdo, $columnExistsFn, 'Tb_Users', ['work', 'department', 'part']);
            $userEmpCol2 = $firstColFn($pdo, $columnExistsFn, 'Tb_Users', ['employee_idx', 'emp_idx']);

            $cols = ['idx'];
            if ($userLoginCol !== null) { $cols[] = $userLoginCol; }
            if ($userNameCol2 !== null) { $cols[] = $userNameCol2; }
            if ($userWorkCol2 !== null) { $cols[] = $userWorkCol2; }
            if ($userEmpCol2 !== null) { $cols[] = $userEmpCol2; }

            $stmt = $pdo->prepare('SELECT TOP 1 ' . implode(', ', array_unique($cols)) . ' FROM Tb_Users WHERE idx = ?');
            $stmt->execute([$userPk]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if ($loginId === '' && $userLoginCol !== null) {
                    $loginId = trim((string)($row[$userLoginCol] ?? ''));
                }
                if ($name === '' && $userNameCol2 !== null) {
                    $name = trim((string)($row[$userNameCol2] ?? ''));
                }
                if ($work === '' && $userWorkCol2 !== null) {
                    $work = trim((string)($row[$userWorkCol2] ?? ''));
                }
                if ($employeeIdx <= 0 && $userEmpCol2 !== null) {
                    $employeeIdx = (int)($row[$userEmpCol2] ?? 0);
                }
            }
        }

        if ($employeeIdx > 0 && $tableExistsFn($pdo, 'Tb_Employee')) {
            $empNameCol = $firstColFn($pdo, $columnExistsFn, 'Tb_Employee', ['name']);
            $empWorkCol = $firstColFn($pdo, $columnExistsFn, 'Tb_Employee', ['work', 'department', 'part']);

            $cols = ['idx'];
            if ($empNameCol !== null) { $cols[] = $empNameCol; }
            if ($empWorkCol !== null) { $cols[] = $empWorkCol; }

            $stmt = $pdo->prepare('SELECT TOP 1 ' . implode(', ', array_unique($cols)) . ' FROM Tb_Employee WHERE idx = ?');
            $stmt->execute([$employeeIdx]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if ($name === '' && $empNameCol !== null) {
                    $name = trim((string)($row[$empNameCol] ?? ''));
                }
                if ($work === '' && $empWorkCol !== null) {
                    $work = trim((string)($row[$empWorkCol] ?? ''));
                }
            }
        }

        if ($name === '') {
            $name = $loginId !== '' ? $loginId : ('USER-' . ($userPk > 0 ? (string)$userPk : '0'));
        }

        return [
            'login_id' => $loginId,
            'name' => $name,
            'work' => $work,
            'employee_idx' => max(0, $employeeIdx),
        ];
    };

    $actor = $resolveActorProfile($db, $tableExists, $columnExists, $firstExistingColumn, $context);

    $loadCommentByIdx = static function (PDO $pdo, int $idx) use (
        $tableName,
        $commentCol,
        $msgTypeCol,
        $userIdCol,
        $userNameCol,
        $userWorkCol,
        $employeeIdxCol,
        $createdAtCol,
        $isDeletedCol
    ): ?array {
        if ($idx <= 0) {
            return null;
        }

        $where = ['c.idx = ?'];
        if ($isDeletedCol !== null) {
            $where[] = 'ISNULL(c.' . $isDeletedCol . ', 0) = 0';
        }

        $datetimeExpr = $createdAtCol !== null
            ? 'CONVERT(VARCHAR(16), c.' . $createdAtCol . ', 120)'
            : "CAST('' AS VARCHAR(16))";
        $timeExpr = $createdAtCol !== null
            ? 'CONVERT(VARCHAR(5), c.' . $createdAtCol . ', 108)'
            : "CAST('' AS VARCHAR(5))";

        $sql = "SELECT\n"
            . "  c.idx,\n"
            . '  ISNULL(CAST(c.' . $commentCol . " AS NVARCHAR(MAX)), '') AS comment,\n"
            . ($msgTypeCol !== null ? '  ISNULL(CAST(c.' . $msgTypeCol . " AS NVARCHAR(20)), 'text')" : "  CAST('text' AS NVARCHAR(20))") . " AS msg_type,\n"
            . ($userIdCol !== null ? '  ISNULL(CAST(c.' . $userIdCol . " AS NVARCHAR(80)), '')" : "  CAST('' AS NVARCHAR(80))") . " AS user_id,\n"
            . ($userNameCol !== null ? '  ISNULL(CAST(c.' . $userNameCol . " AS NVARCHAR(120)), '')" : "  CAST('' AS NVARCHAR(120))") . " AS user_name,\n"
            . ($userWorkCol !== null ? '  ISNULL(CAST(c.' . $userWorkCol . " AS NVARCHAR(120)), '')" : "  CAST('' AS NVARCHAR(120))") . " AS user_work,\n"
            . ($employeeIdxCol !== null ? '  ISNULL(c.' . $employeeIdxCol . ', 0)' : '  CAST(0 AS INT)') . " AS employee_idx,\n"
            . '  ' . $timeExpr . " AS [time],\n"
            . '  ' . $datetimeExpr . " AS [datetime]\n"
            . 'FROM ' . $tableName . ' c\n'
            . 'WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

    $writeTodos = ['insert', 'delete'];
    if (in_array($todo, $writeTodos, true)) {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }
        if (!$auth->validateCsrf()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', '보안 검증 실패', 403);
            exit;
        }
    }

    if ($todo === 'list') {
        $toTable = FmsInputValidator::string($_GET, 'to_table', 80, true);
        if (preg_match('/^[A-Za-z0-9_]+$/', $toTable) !== 1) {
            ApiResponse::error('INVALID_PARAM', 'to_table is invalid', 422);
            exit;
        }

        $toIdx = FmsInputValidator::int($_GET, 'to_idx', true, 1);
        $pjtKey = $pjtKeyCol !== null ? FmsInputValidator::string($_GET, 'pjt_key', 120, false) : '';
        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(2000, max(1, (int)($_GET['limit'] ?? 500)));

        $where = ['c.' . $toTableCol . ' = ?', 'c.' . $toIdxCol . ' = ?'];
        $params = [$toTable, (int)$toIdx];
        if ($pjtKeyCol !== null && $pjtKey !== '') {
            $where[] = 'ISNULL(CAST(c.' . $pjtKeyCol . " AS NVARCHAR(120)),'') = ?";
            $params[] = $pjtKey;
        }
        if ($serviceCodeCol !== null) {
            $where[] = 'c.' . $serviceCodeCol . ' = ?';
            $params[] = $serviceCode;
        }
        if ($tenantIdCol !== null) {
            $where[] = 'ISNULL(c.' . $tenantIdCol . ',0) = ?';
            $params[] = $tenantId;
        }
        if ($isDeletedCol !== null) {
            $where[] = 'ISNULL(c.' . $isDeletedCol . ',0)=0';
        }
        $whereSql = implode(' AND ', $where);

        $photoExpr = "CAST('' AS NVARCHAR(260))";
        if ($tableExists($db, 'Tb_Employee') && $columnExists($db, 'Tb_Employee', 'member_photo')) {
            if ($employeeIdxCol !== null) {
                $photoExpr = '(SELECT TOP 1 member_photo FROM Tb_Employee e WHERE e.idx = c.' . $employeeIdxCol . ')';
            } elseif ($userNameCol !== null && $columnExists($db, 'Tb_Employee', 'name')) {
                $photoExpr = '(SELECT TOP 1 member_photo FROM Tb_Employee e WHERE e.name = c.' . $userNameCol . ')';
            }
        }

        $datetimeExpr = $createdAtCol !== null
            ? 'CONVERT(VARCHAR(16), c.' . $createdAtCol . ', 120)'
            : "CAST('' AS VARCHAR(16))";
        $timeExpr = $createdAtCol !== null
            ? 'CONVERT(VARCHAR(5), c.' . $createdAtCol . ', 108)'
            : "CAST('' AS VARCHAR(5))";

        $countStmt = $db->prepare('SELECT COUNT(*) FROM ' . $tableName . ' c WHERE ' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;

        $sql = "SELECT * FROM (\n"
            . "  SELECT\n"
            . "    c.idx,\n"
            . '    ISNULL(CAST(c.' . $commentCol . " AS NVARCHAR(MAX)), '') AS comment,\n"
            . ($msgTypeCol !== null ? '    ISNULL(CAST(c.' . $msgTypeCol . " AS NVARCHAR(20)), 'text')" : "    CAST('text' AS NVARCHAR(20))") . " AS msg_type,\n"
            . ($userIdCol !== null ? '    ISNULL(CAST(c.' . $userIdCol . " AS NVARCHAR(80)), '')" : "    CAST('' AS NVARCHAR(80))") . " AS user_id,\n"
            . ($userNameCol !== null ? '    ISNULL(CAST(c.' . $userNameCol . " AS NVARCHAR(120)), '')" : "    CAST('' AS NVARCHAR(120))") . " AS user_name,\n"
            . ($userWorkCol !== null ? '    ISNULL(CAST(c.' . $userWorkCol . " AS NVARCHAR(120)), '')" : "    CAST('' AS NVARCHAR(120))") . " AS user_work,\n"
            . ($employeeIdxCol !== null ? '    ISNULL(c.' . $employeeIdxCol . ', 0)' : '    CAST(0 AS INT)') . " AS employee_idx,\n"
            . '    ' . $photoExpr . " AS photo,\n"
            . '    ' . $timeExpr . " AS [time],\n"
            . '    ' . $datetimeExpr . " AS [datetime],\n"
            . '    ROW_NUMBER() OVER (ORDER BY c.idx ASC) AS rn\n'
            . '  FROM ' . $tableName . ' c\n'
            . '  WHERE ' . $whereSql . "\n"
            . ') t WHERE t.rn BETWEEN ? AND ?';

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$rowFrom, $rowTo]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $photoBaseUrl = rtrim((string)shvEnv('EMPLOYEE_PHOTO_BASE_URL', 'https://img.shv.kr/employee/'), '/') . '/';
        if (is_array($rows)) {
            foreach ($rows as &$row) {
                $rawPhoto = trim((string)($row['photo'] ?? ''));
                if ($rawPhoto === '') {
                    $row['photo'] = '';
                    $row['photo_url'] = '';
                    continue;
                }

                if (preg_match('#^https?://#i', $rawPhoto) === 1) {
                    $path = (string)(parse_url($rawPhoto, PHP_URL_PATH) ?? '');
                    $filename = basename($path);
                    if ($filename !== '' && $filename !== '.' && $filename !== '..') {
                        $row['photo'] = $filename;
                    }
                    $row['photo_url'] = $rawPhoto;
                    continue;
                }

                $normalizedPath = ltrim(str_replace('\\', '/', $rawPhoto), '/');
                $filename = basename($normalizedPath);
                if ($filename !== '' && $filename !== '.' && $filename !== '..') {
                    $row['photo'] = $filename;
                } else {
                    $row['photo'] = '';
                }
                $row['photo_url'] = $photoBaseUrl . $normalizedPath;
            }
            unset($row);
        }

        ApiResponse::success([
            'data' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ], 'OK', '특기사항 목록 조회 성공');
        exit;
    }

    if ($todo === 'insert') {
        $toTable = FmsInputValidator::string($_POST + $_GET, 'to_table', 80, true);
        if (preg_match('/^[A-Za-z0-9_]+$/', $toTable) !== 1) {
            ApiResponse::error('INVALID_PARAM', 'to_table is invalid', 422);
            exit;
        }
        $toIdx = FmsInputValidator::int($_POST + $_GET, 'to_idx', true, 1);

        $comment = trim((string)($_POST['comment'] ?? $_GET['comment'] ?? ''));
        $msgType = trim((string)($_POST['msg_type'] ?? $_GET['msg_type'] ?? 'text'));
        $msgType = FmsInputValidator::oneOf($msgType, 'msg_type', ['text', 'image', 'file'], false);
        $pjtKey = $pjtKeyCol !== null ? FmsInputValidator::string($_POST + $_GET, 'pjt_key', 120, false) : '';

        $uploadFile = null;
        if (isset($_FILES['file']) && is_array($_FILES['file'])) {
            $uploadFile = $_FILES['file'];
        } elseif (isset($_FILES['image']) && is_array($_FILES['image'])) {
            $uploadFile = $_FILES['image'];
        }

        if (is_array($uploadFile) && (int)($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploadDir = dirname(__DIR__, 2) . '/uploads/comment/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('failed to create comment upload directory');
            }

            $originalName = basename((string)($uploadFile['name'] ?? 'file'));
            $tmpPath = (string)($uploadFile['tmp_name'] ?? '');
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $safeExt = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'dat';
            $serverName = $toTable . '_' . (int)$toIdx . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $safeExt;
            $destPath = $uploadDir . $serverName;

            if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !move_uploaded_file($tmpPath, $destPath)) {
                throw new RuntimeException('failed to upload attachment file');
            }

            $msgType = in_array($safeExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true) ? 'image' : 'file';
            $comment = json_encode([
                'name' => $originalName,
                'server' => $serverName,
                'size' => (int)($uploadFile['size'] ?? 0),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($comment)) {
                $comment = '';
            }
        }

        if ($msgType === 'text' && $comment === '') {
            ApiResponse::error('INVALID_PARAM', 'comment is required', 422);
            exit;
        }

        if (!$tableExists($db, $toTable)) {
            ApiResponse::error('INVALID_PARAM', 'target table does not exist', 422, ['to_table' => $toTable]);
            exit;
        }

        $targetWhere = ['idx = ?'];
        if ($columnExists($db, $toTable, 'is_deleted')) {
            $targetWhere[] = 'ISNULL(is_deleted,0)=0';
        }
        $targetStmt = $db->prepare('SELECT COUNT(*) FROM ' . $toTable . ' WHERE ' . implode(' AND ', $targetWhere));
        $targetStmt->execute([(int)$toIdx]);
        if ((int)$targetStmt->fetchColumn() < 1) {
            ApiResponse::error('NOT_FOUND', 'target record does not exist', 404, ['to_table' => $toTable, 'to_idx' => (int)$toIdx]);
            exit;
        }

        $columns = [];
        $valueSql = [];
        $params = [];
        $addVal = static function (string $column, mixed $value) use (&$columns, &$valueSql, &$params): void {
            $columns[] = $column;
            $valueSql[] = '?';
            $params[] = $value;
        };
        $addRaw = static function (string $column, string $rawSql) use (&$columns, &$valueSql): void {
            $columns[] = $column;
            $valueSql[] = $rawSql;
        };

        $addVal($toTableCol, $toTable);
        $addVal($toIdxCol, (int)$toIdx);
        $addVal($commentCol, $comment);
        if ($msgTypeCol !== null) { $addVal($msgTypeCol, $msgType); }
        if ($userIdCol !== null) { $addVal($userIdCol, (string)($actor['login_id'] !== '' ? $actor['login_id'] : (string)$actorUserPk)); }
        if ($userNameCol !== null) { $addVal($userNameCol, (string)$actor['name']); }
        if ($userWorkCol !== null) { $addVal($userWorkCol, (string)$actor['work']); }
        if ($userPkCol !== null) { $addVal($userPkCol, $actorUserPk); }
        if ($employeeIdxCol !== null) { $addVal($employeeIdxCol, (int)$actor['employee_idx']); }
        if ($serviceCodeCol !== null) { $addVal($serviceCodeCol, $serviceCode); }
        if ($tenantIdCol !== null) { $addVal($tenantIdCol, $tenantId); }
        if ($pjtKeyCol !== null && $pjtKey !== '') { $addVal($pjtKeyCol, $pjtKey); }

        if ($sortCol !== null) { $addVal($sortCol, 0); }
        if ($isReadCol !== null) { $addVal($isReadCol, 'N'); }
        if ($isPushCol !== null) { $addVal($isPushCol, 'N'); }
        if ($chatbotCol !== null) { $addVal($chatbotCol, 'N'); }
        if ($isDeletedCol !== null) { $addRaw($isDeletedCol, '0'); }
        if ($createdAtCol !== null) { $addRaw($createdAtCol, 'GETDATE()'); }
        if ($updatedAtCol !== null) { $addRaw($updatedAtCol, 'GETDATE()'); }

        $sql = 'INSERT INTO ' . $tableName . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $valueSql) . ')';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
        $newIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
        $item = $loadCommentByIdx($db, $newIdx);

        $eventIdx = $emitCommentEvent(
            $db,
            $tableExists,
            $columnExists,
            $firstExistingColumn,
            $quoteIdentifier,
            $serviceCode,
            $tenantId,
            'comment.insert',
            (string)($actor['login_id'] !== '' ? $actor['login_id'] : (string)$actorUserPk),
            $toTable,
            (string)((int)$toIdx),
            $msgType === 'text' ? $comment : 'attachment'
        );

        $writeSvcAuditLog($db, $tableExists, $columnExists, $apiName, 'insert', $tableName, [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_idx' => $newIdx,
            'actor_user_pk' => $actorUserPk,
            'actor_login_id' => (string)($actor['login_id'] ?? ''),
            'message' => 'comment inserted',
            'to_table' => $toTable,
            'to_idx' => (int)$toIdx,
            'msg_type' => $msgType,
            'event_stream_idx' => $eventIdx,
        ]);

        $shadowId = $enqueueShadow($db, $security, $context, $shadowApiPath, 'insert', $tableName, $newIdx, [
            'idx' => $newIdx,
            'to_table' => $toTable,
            'to_idx' => (int)$toIdx,
            'msg_type' => $msgType,
        ]);

        ApiResponse::success([
            'idx' => $newIdx,
            'item' => $item,
            'event_stream_idx' => $eventIdx,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '특기사항 등록 성공');
        exit;
    }

    if ($todo === 'delete') {
        $idx = FmsInputValidator::int($_POST, 'idx', true, 1);
        $row = $loadCommentByIdx($db, (int)$idx);
        if ($row === null) {
            ApiResponse::error('NOT_FOUND', '특기사항을 찾을 수 없습니다', 404);
            exit;
        }

        $rowUserId = trim((string)($row['user_id'] ?? ''));
        $rowEmployeeIdx = (int)($row['employee_idx'] ?? 0);
        $isAdmin = $roleLevel >= 1 && $roleLevel <= 2;
        $isOwner = false;

        if ($rowUserId !== '' && $rowUserId === (string)($actor['login_id'] ?? '')) {
            $isOwner = true;
        }
        if (!$isOwner && $rowEmployeeIdx > 0 && $rowEmployeeIdx === (int)($actor['employee_idx'] ?? 0)) {
            $isOwner = true;
        }
        if (!$isOwner && $userPkCol !== null) {
            $ownerUserPkStmt = $db->prepare('SELECT TOP 1 ISNULL(' . $userPkCol . ',0) AS owner_user_pk FROM ' . $tableName . ' WHERE idx = ?');
            $ownerUserPkStmt->execute([(int)$idx]);
            $ownerUserPk = (int)$ownerUserPkStmt->fetchColumn();
            if ($ownerUserPk > 0 && $ownerUserPk === $actorUserPk) {
                $isOwner = true;
            }
        }

        if (!$isOwner && !$isAdmin) {
            ApiResponse::error('FORBIDDEN', '삭제 권한이 없습니다', 403);
            exit;
        }

        $msgType = trim((string)($row['msg_type'] ?? 'text'));
        $commentRaw = (string)($row['comment'] ?? '');
        if (in_array($msgType, ['image', 'file'], true) && $commentRaw !== '') {
            $decoded = json_decode($commentRaw, true);
            if (is_array($decoded)) {
                $serverFile = trim((string)($decoded['server'] ?? ''));
                if ($serverFile !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $serverFile) === 1) {
                    $path = dirname(__DIR__, 2) . '/uploads/comment/' . $serverFile;
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            }
        }

        if ($isDeletedCol !== null) {
            $setSql = [$isDeletedCol . ' = 1'];
            $params = [];
            if ($deletedAtCol !== null) {
                $setSql[] = $deletedAtCol . ' = GETDATE()';
            }
            if ($deletedByCol !== null) {
                $setSql[] = $deletedByCol . ' = ?';
                $params[] = $actorUserPk;
            }
            if ($updatedAtCol !== null) {
                $setSql[] = $updatedAtCol . ' = GETDATE()';
            }
            if ($updatedByCol !== null) {
                $setSql[] = $updatedByCol . ' = ?';
                $params[] = $actorUserPk;
            }
            $params[] = (int)$idx;

            $stmt = $db->prepare('UPDATE ' . $tableName . ' SET ' . implode(', ', $setSql) . ' WHERE idx = ? AND ISNULL(' . $isDeletedCol . ',0)=0');
            $stmt->execute($params);
        } else {
            $stmt = $db->prepare('DELETE FROM ' . $tableName . ' WHERE idx = ?');
            $stmt->execute([(int)$idx]);
        }

        $eventIdx = $emitCommentEvent(
            $db,
            $tableExists,
            $columnExists,
            $firstExistingColumn,
            $quoteIdentifier,
            $serviceCode,
            $tenantId,
            'comment.delete',
            (string)($actor['login_id'] !== '' ? $actor['login_id'] : (string)$actorUserPk),
            'Tb_Comment',
            (string)((int)$idx),
            'deleted'
        );

        $writeSvcAuditLog($db, $tableExists, $columnExists, $apiName, 'delete', $tableName, [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_idx' => (int)$idx,
            'actor_user_pk' => $actorUserPk,
            'actor_login_id' => (string)($actor['login_id'] ?? ''),
            'message' => 'comment deleted',
            'event_stream_idx' => $eventIdx,
        ]);

        $shadowId = $enqueueShadow($db, $security, $context, $shadowApiPath, 'delete', $tableName, (int)$idx, [
            'idx' => (int)$idx,
            'deleted' => true,
        ]);

        ApiResponse::success([
            'idx' => (int)$idx,
            'deleted' => true,
            'event_stream_idx' => $eventIdx,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '특기사항 삭제 성공');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo 입니다', 400, ['todo' => $todo]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_PARAM', $e->getMessage(), 422);
} catch (RuntimeException $e) {
    ApiResponse::error('RUNTIME_ERROR', $e->getMessage(), 409);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}
