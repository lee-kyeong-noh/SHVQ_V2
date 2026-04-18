<?php
declare(strict_types=1);
/**
 * SHVQ V2 — PhoneBook API (연락처/발주담당)
 *
 * todo=list    GET
 * todo=detail  GET
 * todo=insert  POST
 * todo=update  POST
 * todo=delete  POST
 * todo=move    POST
 * todo=toggle_hidden POST
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

    $db = DbConnection::get();
    $security = require __DIR__ . '/../../config/security.php';

    $tableName = 'Tb_PhoneBook';
    $apiName = 'PhoneBook.php';
    $shadowApiPath = 'dist_process/saas/PhoneBook.php';

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

    if (!$tableExists($db, $tableName)) {
        ApiResponse::error('SCHEMA_NOT_READY', $tableName . ' table is missing', 503);
        exit;
    }

    $memberCol = $firstExistingColumn($db, $columnExists, $tableName, ['member_idx']);
    $siteCol = $firstExistingColumn($db, $columnExists, $tableName, ['site_idx']);
    $nameCol = $firstExistingColumn($db, $columnExists, $tableName, ['name', 'contact_name']) ?? 'name';
    $sosokCol = $firstExistingColumn($db, $columnExists, $tableName, ['sosok', 'company_name']);
    $partCol = $firstExistingColumn($db, $columnExists, $tableName, ['part', 'department_name']);
    $hpCol = $firstExistingColumn($db, $columnExists, $tableName, ['hp', 'phone']);
    $emailCol = $firstExistingColumn($db, $columnExists, $tableName, ['email']);
    $commentCol = $firstExistingColumn($db, $columnExists, $tableName, ['comment', 'memo']);
    $employeeCol = $firstExistingColumn($db, $columnExists, $tableName, ['employee_idx']);
    $branchFolderCol = $firstExistingColumn($db, $columnExists, $tableName, ['branch_folder_idx']);
    $workStatusCol = $firstExistingColumn($db, $columnExists, $tableName, ['work_status']);
    $mainWorkCol = $firstExistingColumn($db, $columnExists, $tableName, ['main_work']);
    $historyLogCol = $firstExistingColumn($db, $columnExists, $tableName, ['history_log']);
    $jobGradeCol = $firstExistingColumn($db, $columnExists, $tableName, ['job_grade']);
    $jobTitleCol = $firstExistingColumn($db, $columnExists, $tableName, ['job_title']);
    $isHiddenCol = $firstExistingColumn($db, $columnExists, $tableName, ['is_hidden']);
    $companyCol = $firstExistingColumn($db, $columnExists, $tableName, ['company_idx']);

    $isDeletedCol = $firstExistingColumn($db, $columnExists, $tableName, ['is_deleted']);
    $createdAtCol = $firstExistingColumn($db, $columnExists, $tableName, ['created_at']);
    $createdByCol = $firstExistingColumn($db, $columnExists, $tableName, ['created_by']);
    $updatedAtCol = $firstExistingColumn($db, $columnExists, $tableName, ['updated_at']);
    $updatedByCol = $firstExistingColumn($db, $columnExists, $tableName, ['updated_by']);
    $deletedAtCol = $firstExistingColumn($db, $columnExists, $tableName, ['deleted_at']);
    $deletedByCol = $firstExistingColumn($db, $columnExists, $tableName, ['deleted_by']);
    $regdateCol = $firstExistingColumn($db, $columnExists, $tableName, ['regdate']);
    $registeredDateCol = $firstExistingColumn($db, $columnExists, $tableName, ['registered_date']);
    $hasSoftDelete = $isDeletedCol !== null;

    $loadByIdx = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, int $idx) use (
        $tableName,
        $memberCol,
        $isDeletedCol,
        $hasSoftDelete,
        $nameCol
    ): ?array {
        if ($idx <= 0) {
            return null;
        }

        $joinMember = $memberCol !== null && $tableExistsFn($pdo, 'Tb_Members')
            ? 'LEFT JOIN Tb_Members m ON p.' . $memberCol . ' = m.idx'
            : '';
        $memberNameExpr = $joinMember !== '' && $columnExistsFn($pdo, 'Tb_Members', 'name')
            ? 'ISNULL(m.name, \'\')'
            : "CAST('' AS NVARCHAR(120))";

        $where = ['p.idx = ?'];
        if ($hasSoftDelete && $isDeletedCol !== null) {
            $where[] = 'ISNULL(p.' . $isDeletedCol . ',0)=0';
        }

        $sql = "SELECT p.*, {$memberNameExpr} AS member_name, ISNULL(p.{$nameCol}, '') AS contact_display_name\n"
            . "FROM {$tableName} p {$joinMember}\n"
            . 'WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

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
        $add('message', (string)($payload['message'] ?? 'phonebook api write'));
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

    $roleLevel = (int)($context['role_level'] ?? 0);
    $serviceCode = (string)($context['service_code'] ?? 'shvq');
    $tenantId = (int)($context['tenant_id'] ?? 0);

    $writeTodos = ['insert', 'update', 'delete', 'move', 'toggle_hidden'];
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
        $search = trim((string)($_GET['search'] ?? ''));
        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));
        $includeDeleted = FmsInputValidator::bool($_GET, 'include_deleted', false);

        $memberFilter = $memberCol !== null ? FmsInputValidator::int($_GET, 'member_idx', false, 0) : null;
        $siteFilter = $siteCol !== null ? FmsInputValidator::int($_GET, 'site_idx', false, 0) : null;
        $isHiddenFilter = $isHiddenCol !== null ? FmsInputValidator::int($_GET, 'is_hidden', false, 0, 1) : null;

        $sortRaw = strtolower(trim((string)($_GET['sort'] ?? 'idx')));
        $orderRaw = strtolower(trim((string)($_GET['order'] ?? 'desc')));
        $orderExpr = $orderRaw === 'asc' ? 'ASC' : 'DESC';

        $sortMap = ['idx' => 'p.idx'];
        if ($nameCol !== null) { $sortMap['name'] = 'p.' . $nameCol; }
        if ($hpCol !== null) { $sortMap['hp'] = 'p.' . $hpCol; }
        if ($memberCol !== null) { $sortMap['member_idx'] = 'p.' . $memberCol; }
        if ($siteCol !== null) { $sortMap['site_idx'] = 'p.' . $siteCol; }
        if ($regdateCol !== null) { $sortMap['regdate'] = 'p.' . $regdateCol; }
        $sortExpr = $sortMap[$sortRaw] ?? 'p.idx';

        $where = ['1=1'];
        $params = [];

        if (!$includeDeleted && $hasSoftDelete && $isDeletedCol !== null) {
            $where[] = 'ISNULL(p.' . $isDeletedCol . ',0)=0';
        }

        if ($memberCol !== null && $memberFilter !== null) {
            $where[] = 'ISNULL(p.' . $memberCol . ',0) = ?';
            $params[] = $memberFilter;
        }

        if ($siteCol !== null && $siteFilter !== null) {
            $where[] = 'ISNULL(p.' . $siteCol . ',0) = ?';
            $params[] = $siteFilter;
        }

        if ($isHiddenCol !== null && $isHiddenFilter !== null) {
            $where[] = 'ISNULL(p.' . $isHiddenCol . ',0) = ?';
            $params[] = $isHiddenFilter;
        }

        if ($search !== '') {
            $sp = '%' . $search . '%';
            $searchConds = [];
            if ($nameCol !== null) { $searchConds[] = 'p.' . $nameCol . ' LIKE ?'; $params[] = $sp; }
            if ($sosokCol !== null) { $searchConds[] = 'p.' . $sosokCol . ' LIKE ?'; $params[] = $sp; }
            if ($partCol !== null) { $searchConds[] = 'p.' . $partCol . ' LIKE ?'; $params[] = $sp; }
            if ($hpCol !== null) { $searchConds[] = 'p.' . $hpCol . ' LIKE ?'; $params[] = $sp; }
            if ($emailCol !== null) { $searchConds[] = 'p.' . $emailCol . ' LIKE ?'; $params[] = $sp; }
            if ($searchConds !== []) {
                $where[] = '(' . implode(' OR ', $searchConds) . ')';
            }
        }

        $joinMember = $memberCol !== null && $tableExists($db, 'Tb_Members')
            ? 'LEFT JOIN Tb_Members m ON p.' . $memberCol . ' = m.idx'
            : '';
        $memberNameExpr = $joinMember !== '' && $columnExists($db, 'Tb_Members', 'name')
            ? 'ISNULL(m.name, \'\')'
            : "CAST('' AS NVARCHAR(120))";

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare('SELECT COUNT(*) FROM ' . $tableName . ' p ' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $queryParams = array_merge($params, [$rowFrom, $rowTo]);

        $listSql = "SELECT * FROM (\n"
            . " SELECT p.*, {$memberNameExpr} AS member_name, ROW_NUMBER() OVER (ORDER BY {$sortExpr} {$orderExpr}, p.idx DESC) AS rn\n"
            . " FROM {$tableName} p {$joinMember} {$whereSql}\n"
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
        ], 'OK', '연락처 목록 조회 성공');
        exit;
    }

    if ($todo === 'detail') {
        $idx = (int)($_GET['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx is required', 422);
            exit;
        }

        $row = $loadByIdx($db, $tableExists, $columnExists, $idx);
        if ($row === null) {
            ApiResponse::error('NOT_FOUND', '연락처 데이터를 찾을 수 없습니다', 404);
            exit;
        }

        ApiResponse::success($row, 'OK', '연락처 상세 조회 성공');
        exit;
    }

    if ($todo === 'insert') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }

        $memberIdx = $memberCol !== null ? FmsInputValidator::int($_POST, 'member_idx', true, 1) : null;
        $siteIdx = $siteCol !== null ? FmsInputValidator::int($_POST, 'site_idx', false, 0) : null;
        $name = FmsInputValidator::string($_POST, 'name', 120, true);
        $sosok = $sosokCol !== null ? FmsInputValidator::string($_POST, 'sosok', 120, false) : '';
        $part = $partCol !== null ? FmsInputValidator::string($_POST, 'part', 120, false) : '';
        $hp = $hpCol !== null ? FmsInputValidator::phone((string)($_POST['hp'] ?? ''), 'hp', true) : '';
        $email = $emailCol !== null ? FmsInputValidator::email((string)($_POST['email'] ?? ''), 'email', true) : '';
        $comment = $commentCol !== null ? FmsInputValidator::string($_POST, 'comment', 4000, false) : '';
        $employeeIdx = $employeeCol !== null ? FmsInputValidator::int($_POST, 'employee_idx', false, 0) : null;
        $branchFolderIdx = $branchFolderCol !== null ? FmsInputValidator::int($_POST, 'branch_folder_idx', false, 0) : null;
        $workStatus = $workStatusCol !== null ? FmsInputValidator::string($_POST, 'work_status', 20, false) : '';
        $mainWork = $mainWorkCol !== null ? FmsInputValidator::string($_POST, 'main_work', 200, false) : '';
        $historyLog = $historyLogCol !== null ? FmsInputValidator::string($_POST, 'history_log', 4000, false) : '';
        $jobGrade = $jobGradeCol !== null ? FmsInputValidator::string($_POST, 'job_grade', 50, false) : '';
        $jobTitle = $jobTitleCol !== null ? FmsInputValidator::string($_POST, 'job_title', 50, false) : '';
        $isHidden = $isHiddenCol !== null ? FmsInputValidator::int($_POST, 'is_hidden', false, 0, 1) : null;
        $companyIdx = $companyCol !== null ? FmsInputValidator::int($_POST, 'company_idx', false, 0) : null;

        if ($memberCol !== null && $tableExists($db, 'Tb_Members') && $columnExists($db, 'Tb_Members', 'idx') && $memberIdx !== null && $memberIdx > 0) {
            $memberWhere = ['idx = ?'];
            if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
                $memberWhere[] = 'ISNULL(is_deleted,0)=0';
            }
            $mStmt = $db->prepare('SELECT COUNT(*) FROM Tb_Members WHERE ' . implode(' AND ', $memberWhere));
            $mStmt->execute([$memberIdx]);
            if ((int)$mStmt->fetchColumn() < 1) {
                ApiResponse::error('INVALID_PARAM', 'member_idx is invalid', 422, ['member_idx' => $memberIdx]);
                exit;
            }
        }

        $fieldMap = [];
        if ($memberCol !== null && $columnExists($db, $tableName, $memberCol) && $memberIdx !== null) { $fieldMap[$memberCol] = $memberIdx; }
        if ($siteCol !== null && $columnExists($db, $tableName, $siteCol) && $siteIdx !== null) { $fieldMap[$siteCol] = $siteIdx; }
        if ($nameCol !== null && $columnExists($db, $tableName, $nameCol)) { $fieldMap[$nameCol] = $name; }
        if ($sosokCol !== null && $columnExists($db, $tableName, $sosokCol)) { $fieldMap[$sosokCol] = $sosok; }
        if ($partCol !== null && $columnExists($db, $tableName, $partCol)) { $fieldMap[$partCol] = $part; }
        if ($hpCol !== null && $columnExists($db, $tableName, $hpCol)) { $fieldMap[$hpCol] = $hp; }
        if ($emailCol !== null && $columnExists($db, $tableName, $emailCol)) { $fieldMap[$emailCol] = $email; }
        if ($commentCol !== null && $columnExists($db, $tableName, $commentCol)) { $fieldMap[$commentCol] = $comment; }
        if ($employeeCol !== null && $columnExists($db, $tableName, $employeeCol) && $employeeIdx !== null) { $fieldMap[$employeeCol] = $employeeIdx; }
        if ($branchFolderCol !== null && $columnExists($db, $tableName, $branchFolderCol) && $branchFolderIdx !== null) { $fieldMap[$branchFolderCol] = $branchFolderIdx; }
        if ($workStatusCol !== null && $columnExists($db, $tableName, $workStatusCol)) { $fieldMap[$workStatusCol] = $workStatus; }
        if ($mainWorkCol !== null && $columnExists($db, $tableName, $mainWorkCol)) { $fieldMap[$mainWorkCol] = $mainWork; }
        if ($historyLogCol !== null && $columnExists($db, $tableName, $historyLogCol)) { $fieldMap[$historyLogCol] = $historyLog; }
        if ($jobGradeCol !== null && $columnExists($db, $tableName, $jobGradeCol)) { $fieldMap[$jobGradeCol] = $jobGrade; }
        if ($jobTitleCol !== null && $columnExists($db, $tableName, $jobTitleCol)) { $fieldMap[$jobTitleCol] = $jobTitle; }
        if ($isHiddenCol !== null && $columnExists($db, $tableName, $isHiddenCol) && $isHidden !== null) { $fieldMap[$isHiddenCol] = $isHidden; }
        if ($companyCol !== null && $columnExists($db, $tableName, $companyCol) && $companyIdx !== null) { $fieldMap[$companyCol] = $companyIdx; }

        if ($hasSoftDelete && $isDeletedCol !== null) { $fieldMap[$isDeletedCol] = 0; }
        if ($createdAtCol !== null) { $fieldMap[$createdAtCol] = date('Y-m-d H:i:s'); }
        if ($createdByCol !== null) { $fieldMap[$createdByCol] = (int)($context['user_pk'] ?? 0); }
        if ($updatedAtCol !== null) { $fieldMap[$updatedAtCol] = date('Y-m-d H:i:s'); }
        if ($updatedByCol !== null) { $fieldMap[$updatedByCol] = (int)($context['user_pk'] ?? 0); }
        if ($regdateCol !== null) { $fieldMap[$regdateCol] = date('Y-m-d H:i:s'); }
        if ($registeredDateCol !== null) { $fieldMap[$registeredDateCol] = date('Y-m-d H:i:s'); }

        if ($fieldMap === []) {
            ApiResponse::error('SCHEMA_ERROR', 'insertable columns not found', 500);
            exit;
        }

        try {
            $db->beginTransaction();

            $columns = array_keys($fieldMap);
            $values = array_values($fieldMap);
            $ph = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $db->prepare('INSERT INTO ' . $tableName . ' (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
            $stmt->execute($values);

            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            $newIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
            if ($newIdx <= 0) {
                throw new RuntimeException('failed to resolve inserted idx');
            }

            $db->commit();

            $row = $loadByIdx($db, $tableExists, $columnExists, $newIdx);
            $writeSvcAuditLog($db, $tableExists, $columnExists, $apiName, 'insert', $tableName, [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_idx' => $newIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'phonebook inserted',
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, $shadowApiPath, 'insert', $tableName, $newIdx, ['idx' => $newIdx]);
            DevLogService::tryLog('FMS', 'PhoneBook insert', 'PhoneBook insert todo executed', 1);

            ApiResponse::success([
                'idx' => $newIdx,
                'item' => $row,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '연락처 등록 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'update') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }

        $idx = (int)($_POST['idx'] ?? $_GET['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx is required', 422);
            exit;
        }

        $current = $loadByIdx($db, $tableExists, $columnExists, $idx);
        if ($current === null) {
            ApiResponse::error('NOT_FOUND', '연락처 데이터를 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = [];
        $params = [];

        if ($memberCol !== null && array_key_exists('member_idx', $_POST) && $columnExists($db, $tableName, $memberCol)) {
            $memberIdx = FmsInputValidator::int($_POST, 'member_idx', true, 1);
            if ($memberIdx !== null && $tableExists($db, 'Tb_Members') && $columnExists($db, 'Tb_Members', 'idx')) {
                $memberWhere = ['idx = ?'];
                if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
                    $memberWhere[] = 'ISNULL(is_deleted,0)=0';
                }
                $mStmt = $db->prepare('SELECT COUNT(*) FROM Tb_Members WHERE ' . implode(' AND ', $memberWhere));
                $mStmt->execute([$memberIdx]);
                if ((int)$mStmt->fetchColumn() < 1) {
                    ApiResponse::error('INVALID_PARAM', 'member_idx is invalid', 422, ['member_idx' => $memberIdx]);
                    exit;
                }
            }
            $setSql[] = $memberCol . ' = ?';
            $params[] = $memberIdx;
        }

        if ($siteCol !== null && array_key_exists('site_idx', $_POST) && $columnExists($db, $tableName, $siteCol)) {
            $siteIdx = FmsInputValidator::int($_POST, 'site_idx', false, 0);
            $setSql[] = $siteCol . ' = ?';
            $params[] = $siteIdx;
        }

        if ($nameCol !== null && array_key_exists('name', $_POST) && $columnExists($db, $tableName, $nameCol)) {
            $setSql[] = $nameCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'name', 120, true);
        }

        if ($sosokCol !== null && array_key_exists('sosok', $_POST) && $columnExists($db, $tableName, $sosokCol)) {
            $setSql[] = $sosokCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'sosok', 120, false);
        }

        if ($partCol !== null && array_key_exists('part', $_POST) && $columnExists($db, $tableName, $partCol)) {
            $setSql[] = $partCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'part', 120, false);
        }

        if ($hpCol !== null && array_key_exists('hp', $_POST) && $columnExists($db, $tableName, $hpCol)) {
            $setSql[] = $hpCol . ' = ?';
            $params[] = FmsInputValidator::phone((string)$_POST['hp'], 'hp', true);
        }

        if ($emailCol !== null && array_key_exists('email', $_POST) && $columnExists($db, $tableName, $emailCol)) {
            $setSql[] = $emailCol . ' = ?';
            $params[] = FmsInputValidator::email((string)$_POST['email'], 'email', true);
        }

        if ($commentCol !== null && array_key_exists('comment', $_POST) && $columnExists($db, $tableName, $commentCol)) {
            $setSql[] = $commentCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'comment', 4000, false);
        }

        if ($employeeCol !== null && array_key_exists('employee_idx', $_POST) && $columnExists($db, $tableName, $employeeCol)) {
            $setSql[] = $employeeCol . ' = ?';
            $params[] = FmsInputValidator::int($_POST, 'employee_idx', false, 0);
        }

        if ($branchFolderCol !== null && array_key_exists('branch_folder_idx', $_POST) && $columnExists($db, $tableName, $branchFolderCol)) {
            $setSql[] = $branchFolderCol . ' = ?';
            $params[] = FmsInputValidator::int($_POST, 'branch_folder_idx', false, 0);
        }

        if ($workStatusCol !== null && array_key_exists('work_status', $_POST) && $columnExists($db, $tableName, $workStatusCol)) {
            $setSql[] = $workStatusCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'work_status', 20, false);
        }

        if ($mainWorkCol !== null && array_key_exists('main_work', $_POST) && $columnExists($db, $tableName, $mainWorkCol)) {
            $setSql[] = $mainWorkCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'main_work', 200, false);
        }

        if ($historyLogCol !== null && array_key_exists('history_log', $_POST) && $columnExists($db, $tableName, $historyLogCol)) {
            $setSql[] = $historyLogCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'history_log', 4000, false);
        }

        if ($jobGradeCol !== null && array_key_exists('job_grade', $_POST) && $columnExists($db, $tableName, $jobGradeCol)) {
            $setSql[] = $jobGradeCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'job_grade', 50, false);
        }

        if ($jobTitleCol !== null && array_key_exists('job_title', $_POST) && $columnExists($db, $tableName, $jobTitleCol)) {
            $setSql[] = $jobTitleCol . ' = ?';
            $params[] = FmsInputValidator::string($_POST, 'job_title', 50, false);
        }

        if ($isHiddenCol !== null && array_key_exists('is_hidden', $_POST) && $columnExists($db, $tableName, $isHiddenCol)) {
            $setSql[] = $isHiddenCol . ' = ?';
            $params[] = FmsInputValidator::int($_POST, 'is_hidden', false, 0, 1);
        }

        if ($companyCol !== null && array_key_exists('company_idx', $_POST) && $columnExists($db, $tableName, $companyCol)) {
            $setSql[] = $companyCol . ' = ?';
            $params[] = FmsInputValidator::int($_POST, 'company_idx', false, 0);
        }

        if ($updatedAtCol !== null) {
            $setSql[] = $updatedAtCol . ' = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($updatedByCol !== null) {
            $setSql[] = $updatedByCol . ' = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        if ($setSql === []) {
            ApiResponse::error('INVALID_PARAM', 'no updatable fields provided', 422);
            exit;
        }

        $where = ['idx = ?'];
        if ($hasSoftDelete && $isDeletedCol !== null) {
            $where[] = 'ISNULL(' . $isDeletedCol . ',0)=0';
        }
        $params[] = $idx;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE ' . $tableName . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $db->commit();

            $row = $loadByIdx($db, $tableExists, $columnExists, $idx);
            $writeSvcAuditLog($db, $tableExists, $columnExists, $apiName, 'update', $tableName, [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_idx' => $idx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'phonebook updated',
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, $shadowApiPath, 'update', $tableName, $idx, ['idx' => $idx]);
            DevLogService::tryLog('FMS', 'PhoneBook update', 'PhoneBook update todo executed', 1);

            ApiResponse::success([
                'idx' => $idx,
                'item' => $row,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '연락처 수정 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'delete') {
        if ($roleLevel < 1 || $roleLevel > 4) {
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

            $deleted = 0;
            $mode = 'hard';

            if ($hasSoftDelete && $isDeletedCol !== null) {
                $mode = 'soft';
                $setSql = [$isDeletedCol . ' = 1'];
                $params = [];

                if ($deletedAtCol !== null) { $setSql[] = $deletedAtCol . ' = GETDATE()'; }
                if ($deletedByCol !== null) { $setSql[] = $deletedByCol . ' = ?'; $params[] = (int)($context['user_pk'] ?? 0); }
                if ($updatedAtCol !== null) { $setSql[] = $updatedAtCol . ' = GETDATE()'; }
                if ($updatedByCol !== null) { $setSql[] = $updatedByCol . ' = ?'; $params[] = (int)($context['user_pk'] ?? 0); }

                $params = array_merge($params, $idxList);
                $stmt = $db->prepare('UPDATE ' . $tableName . ' SET ' . implode(', ', $setSql) . " WHERE idx IN ({$ph})");
                $stmt->execute($params);
                $deleted = (int)$stmt->rowCount();
            } else {
                $stmt = $db->prepare("DELETE FROM {$tableName} WHERE idx IN ({$ph})");
                $stmt->execute($idxList);
                $deleted = (int)$stmt->rowCount();
            }

            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, $apiName, 'delete', $tableName, [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_idx' => $idxList[0] ?? 0,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'phonebook deleted',
                'idx_list' => $idxList,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, $shadowApiPath, 'delete', $tableName, $idxList[0] ?? 0, ['idx_list' => $idxList]);
            DevLogService::tryLog('FMS', 'PhoneBook delete', 'PhoneBook delete todo executed', 1);

            ApiResponse::success([
                'idx_list' => $idxList,
                'requested_count' => count($idxList),
                'deleted_count' => $deleted,
                'delete_mode' => $mode,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '연락처 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'toggle_hidden') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if ($isHiddenCol === null || !$columnExists($db, $tableName, $isHiddenCol)) {
            ApiResponse::error('SCHEMA_NOT_READY', 'is_hidden column is missing', 503);
            exit;
        }

        $idx = (int)($_POST['idx'] ?? $_POST['contact_idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx is required', 422);
            exit;
        }
        $isHidden = FmsInputValidator::int($_POST, 'is_hidden', true, 0, 1);

        $current = $loadByIdx($db, $tableExists, $columnExists, $idx);
        if ($current === null) {
            ApiResponse::error('NOT_FOUND', '연락처 데이터를 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = [$isHiddenCol . ' = ?'];
        $params = [(int)$isHidden];
        if ($updatedAtCol !== null) {
            $setSql[] = $updatedAtCol . ' = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($updatedByCol !== null) {
            $setSql[] = $updatedByCol . ' = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        $where = ['idx = ?'];
        if ($hasSoftDelete && $isDeletedCol !== null) {
            $where[] = 'ISNULL(' . $isDeletedCol . ',0)=0';
        }
        $params[] = $idx;

        $stmt = $db->prepare('UPDATE ' . $tableName . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);

        $row = $loadByIdx($db, $tableExists, $columnExists, $idx);
        $writeSvcAuditLog($db, $tableExists, $columnExists, $apiName, 'toggle_hidden', $tableName, [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_idx' => $idx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'phonebook hidden toggled',
            'is_hidden' => (int)$isHidden,
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, $shadowApiPath, 'toggle_hidden', $tableName, $idx, [
            'idx' => $idx,
            'is_hidden' => (int)$isHidden,
        ]);
        DevLogService::tryLog('FMS', 'PhoneBook toggle_hidden', 'PhoneBook toggle_hidden todo executed', 1);

        ApiResponse::success([
            'idx' => $idx,
            'is_hidden' => (int)$isHidden,
            'item' => $row,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '연락처 숨김 상태 변경 성공');
        exit;
    }

    if ($todo === 'move') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }

        if ($memberCol === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'member_idx column is missing', 503);
            exit;
        }

        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['idx'] ?? ''));
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list is required', 422);
            exit;
        }

        $targetMemberIdx = FmsInputValidator::int($_POST, 'target_member_idx', true, 1);
        $targetSiteIdx = $siteCol !== null
            ? FmsInputValidator::int($_POST, 'target_site_idx', false, 0)
            : null;

        if ($targetMemberIdx !== null && $tableExists($db, 'Tb_Members') && $columnExists($db, 'Tb_Members', 'idx')) {
            $memberWhere = ['idx = ?'];
            if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
                $memberWhere[] = 'ISNULL(is_deleted,0)=0';
            }
            $mStmt = $db->prepare('SELECT COUNT(*) FROM Tb_Members WHERE ' . implode(' AND ', $memberWhere));
            $mStmt->execute([$targetMemberIdx]);
            if ((int)$mStmt->fetchColumn() < 1) {
                ApiResponse::error('INVALID_PARAM', 'target_member_idx is invalid', 422, ['target_member_idx' => $targetMemberIdx]);
                exit;
            }
        }

        $setSql = [$memberCol . ' = ?'];
        $params = [$targetMemberIdx];

        if ($siteCol !== null) {
            $setSql[] = $siteCol . ' = ?';
            $params[] = $targetSiteIdx ?? 0;
        }
        if ($updatedAtCol !== null) {
            $setSql[] = $updatedAtCol . ' = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($updatedByCol !== null) {
            $setSql[] = $updatedByCol . ' = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        $ph = implode(',', array_fill(0, count($idxList), '?'));
        $where = "idx IN ({$ph})";
        if ($hasSoftDelete && $isDeletedCol !== null) {
            $where .= ' AND ISNULL(' . $isDeletedCol . ',0)=0';
        }
        $params = array_merge($params, $idxList);

        $stmt = $db->prepare('UPDATE ' . $tableName . ' SET ' . implode(', ', $setSql) . ' WHERE ' . $where);
        $stmt->execute($params);
        $moved = (int)$stmt->rowCount();

        $writeSvcAuditLog($db, $tableExists, $columnExists, $apiName, 'move', $tableName, [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_idx' => $idxList[0] ?? 0,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'phonebook moved',
            'idx_list' => $idxList,
            'target_member_idx' => $targetMemberIdx,
            'target_site_idx' => $targetSiteIdx ?? 0,
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, $shadowApiPath, 'move', $tableName, $idxList[0] ?? 0, [
            'idx_list' => $idxList,
            'target_member_idx' => $targetMemberIdx,
            'target_site_idx' => $targetSiteIdx ?? 0,
        ]);
        DevLogService::tryLog('FMS', 'PhoneBook move', 'PhoneBook move todo executed', 1);

        ApiResponse::success([
            'idx_list' => $idxList,
            'target_member_idx' => $targetMemberIdx,
            'target_site_idx' => $targetSiteIdx ?? 0,
            'moved_count' => $moved,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '연락처 이동 성공');
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
