<?php
declare(strict_types=1);
/**
 * SHVQ V2 — Member API (FMS 사업장)
 *
 * todo=list                  GET
 * todo=group_list            GET
 * todo=employee_list         GET
 * todo=region_list           GET
 * todo=pjt_property_list     GET
 * todo=hogi_list             GET
 * todo=get_required          GET
 * todo=save_member_required  POST
 * todo=save_member_regions   POST
 * todo=save_item_property    POST
 * todo=branch_folder_list    GET
 * todo=branch_folder_insert  POST
 * todo=branch_folder_update  POST
 * todo=branch_folder_delete  POST
 * todo=bill_list             GET
 * todo=mail_list             GET
 * todo=attach_list           GET
 * todo=detail                GET
 * todo=check_dup             GET
 * todo=insert                POST
 * todo=update                POST
 * todo=update_branch_settings POST
 * todo=member_inline_update  POST
 * todo=link_head             POST
 * todo=unlink_head           POST
 * todo=update_link_status    POST
 * todo=member_delete         POST
 * todo=restore               POST
 * todo=member_bulk_action    POST
 * todo=inline_update_contact POST
 * todo=assign_contact_folder POST
 * todo=move_contact          POST
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
    if ($todo === 'update_branch_settings') {
        $todo = 'update';
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

    $nameCol = $firstExistingColumn($db, $columnExists, 'Tb_Members', ['name', 'member_name', 'branch_name']) ?? 'name';
    $statusCol = $firstExistingColumn($db, $columnExists, 'Tb_Members', ['member_status', 'status']) ?? 'member_status';
    $cardCol = $firstExistingColumn($db, $columnExists, 'Tb_Members', ['card_number', 'biz_number', 'business_number']) ?? 'card_number';

    if (!$tableExists($db, 'Tb_Members')) {
        ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members table is missing', 503);
        exit;
    }

    $loadMember = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, int $idx) use ($nameCol): ?array {
        if ($idx <= 0) {
            return null;
        }

        $where = ['m.idx = ?'];
        if ($columnExistsFn($pdo, 'Tb_Members', 'is_deleted')) {
            $where[] = 'ISNULL(m.is_deleted,0)=0';
        }

        $joinHead = $tableExistsFn($pdo, 'Tb_HeadOffice') && $columnExistsFn($pdo, 'Tb_Members', 'head_idx')
            ? 'LEFT JOIN Tb_HeadOffice h ON m.head_idx = h.idx'
            : '';

        $headExpr = $joinHead !== '' ? 'ISNULL(h.name, \'\')' : "CAST('' AS NVARCHAR(200))";
        $sql = "SELECT m.*, {$headExpr} AS head_name, ISNULL(m.{$nameCol}, '') AS member_display_name FROM Tb_Members m {$joinHead} WHERE " . implode(' AND ', $where);

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

    $validateHeadIdx = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, ?int $headIdx): void {
        if ($headIdx === null || $headIdx <= 0) {
            return;
        }
        if (!$tableExistsFn($pdo, 'Tb_HeadOffice')) {
            throw new InvalidArgumentException('head_idx validation table is missing');
        }

        $where = ['idx = ?'];
        if ($columnExistsFn($pdo, 'Tb_HeadOffice', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM Tb_HeadOffice WHERE ' . implode(' AND ', $where));
        $stmt->execute([$headIdx]);
        if ((int)$stmt->fetchColumn() < 1) {
            throw new InvalidArgumentException('head_idx is invalid');
        }
    };

    $findByCard = static function (PDO $pdo, callable $columnExistsFn, string $cardColumn, string $cardNumber, int $excludeIdx = 0): ?array {
        $digits = preg_replace('/\D+/', '', $cardNumber) ?? '';
        if ($digits === '' || !$columnExistsFn($pdo, 'Tb_Members', $cardColumn)) {
            return null;
        }

        $where = ["REPLACE(REPLACE(ISNULL({$cardColumn},''),'-',''),' ','') = ?"];
        $params = [$digits];
        if ($excludeIdx > 0) {
            $where[] = 'idx <> ?';
            $params[] = $excludeIdx;
        }
        if ($columnExistsFn($pdo, 'Tb_Members', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $stmt = $pdo->prepare('SELECT TOP 1 idx, ' . $cardColumn . ' AS card_number FROM Tb_Members WHERE ' . implode(' AND ', $where) . ' ORDER BY idx DESC');
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

    $statusTransitionAllowed = static function (string $fromStatus, string $toStatus): bool {
        $from = trim($fromStatus);
        $to = trim($toStatus);
        if ($to === '' || $from === '' || $from === $to) {
            return true;
        }

        $map = [
            '예정' => ['운영', '중지'],
            '운영' => ['중지', '종료'],
            '중지' => ['운영', '종료'],
            '종료' => [],
        ];

        if (!array_key_exists($from, $map)) {
            return true;
        }

        return in_array($to, $map[$from], true);
    };

    $writeSvcAuditLog = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $todoName, array $payload, string $targetTable = 'Tb_Members'): void {
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
        $add('api_name', 'Member.php');
        $add('todo', $todoName);
        $add('target_table', $targetTable);
        $add('target_idx', (int)($payload['target_idx'] ?? 0));
        $add('actor_user_pk', (int)($payload['actor_user_pk'] ?? 0));
        $add('actor_login_id', (string)($payload['actor_login_id'] ?? ''));
        $add('status', 'SUCCESS');
        $add('message', (string)($payload['message'] ?? 'member api write'));
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

    $enqueueShadow = static function (PDO $pdo, array $securityCfg, array $ctx, string $todoName, int $targetIdx, array $payload = [], string $targetTable = 'Tb_Members'): int {
        try {
            $svc = new ShadowWriteQueueService($pdo, $securityCfg);
            return $svc->enqueueJob([
                'service_code' => (string)($ctx['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($ctx['tenant_id'] ?? 0),
                'api' => 'dist_process/saas/Member.php',
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

    $serviceCode = (string)($context['service_code'] ?? 'shvq');
    $tenantId = (int)($context['tenant_id'] ?? 0);
    $roleLevel = (int)($context['role_level'] ?? 0);
    $memberEmployeeCol = $firstExistingColumn($db, $columnExists, 'Tb_Members', ['employee_idx']);
    $memberRegisteredDateCol = $firstExistingColumn($db, $columnExists, 'Tb_Members', ['registered_date', 'regdate', 'created_at']);
    $phoneBookTable = 'Tb_PhoneBook';
    $headOrgTable = 'Tb_HeadOrgFolder';
    $branchOrgTable = 'Tb_BranchOrgFolder';
    $billGroupTable = 'Tb_BillGroup';
    $billTable = 'Tb_Bill';
    $mailLinkTable = 'Tb_Site_Mail';
    $fileAttachTable = 'Tb_FileAttach';
    $memberHogiTable = 'Tb_MemberHogi';

    $phoneBookExists = $tableExists($db, $phoneBookTable);
    $phoneMemberCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['member_idx']) : null;
    $phoneNameCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['name', 'contact_name']) : null;
    $phoneHpCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['hp', 'phone']) : null;
    $phoneEmailCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['email']) : null;
    $phoneWorkStatusCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['work_status']) : null;
    $phoneMainWorkCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['main_work']) : null;
    $phoneJobGradeCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['job_grade']) : null;
    $phoneJobTitleCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['job_title']) : null;
    $phoneBranchFolderCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['branch_folder_idx']) : null;
    $phoneUpdatedAtCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['updated_at']) : null;
    $phoneUpdatedByCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['updated_by']) : null;
    $phoneIsDeletedCol = $phoneBookExists ? $firstExistingColumn($db, $columnExists, $phoneBookTable, ['is_deleted']) : null;

    $loadPhoneBookContact = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $table, int $contactIdx, ?string $isDeletedCol): ?array {
        if ($contactIdx <= 0 || !$tableExistsFn($pdo, $table)) {
            return null;
        }

        $where = ['idx = ?'];
        if ($isDeletedCol !== null && $columnExistsFn($pdo, $table, $isDeletedCol)) {
            $where[] = 'ISNULL(' . $isDeletedCol . ',0)=0';
        }

        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute([$contactIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

    $loadHeadOrgFolder = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $table, int $folderIdx): ?array {
        if ($folderIdx <= 0 || !$tableExistsFn($pdo, $table)) {
            return null;
        }

        $where = ['idx = ?'];
        if ($columnExistsFn($pdo, $table, 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute([$folderIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

    $loadBranchOrgFolder = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $table, int $folderIdx): ?array {
        if ($folderIdx <= 0 || !$tableExistsFn($pdo, $table)) {
            return null;
        }

        $where = ['idx = ?'];
        if ($columnExistsFn($pdo, $table, 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute([$folderIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

    $qi = static function (string $column): string {
        return '[' . str_replace(']', ']]', $column) . ']';
    };

    $upsertSystemSetting = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        callable $firstColFn,
        callable $qiFn,
        string $svcCode,
        int $tId,
        int $actorUserPk,
        string $settingKey,
        string $settingValue,
        string $settingType = 'json',
        string $settingGroup = 'member'
    ): int {
        if (!$tableExistsFn($pdo, 'Tb_UserSettings')) {
            throw new RuntimeException('Tb_UserSettings table is missing');
        }

        $idxCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['idx', 'id']);
        $serviceCodeCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['service_code']);
        $tenantIdCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['tenant_id']);
        $settingGroupCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['setting_group']);
        $settingKeyCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['setting_key']);
        $settingValueCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['setting_value']);
        $settingTypeCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['setting_type']);
        $userIdCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['user_id']);
        $userIdxCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['user_idx']);
        $employeeIdxCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['employee_idx']);
        $memberIdxCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['member_idx']);
        $isDeletedCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['is_deleted']);
        $createdAtCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['created_at']);
        $createdByCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['created_by']);
        $updatedAtCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['updated_at', 'updated_date']);
        $updatedByCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['updated_by']);
        $regdateCol = $firstColFn($pdo, $columnExistsFn, 'Tb_UserSettings', ['regdate']);

        if ($settingKeyCol === null || $settingValueCol === null) {
            throw new RuntimeException('Tb_UserSettings setting_key/setting_value columns are missing');
        }

        $systemUserId = '__SYSTEM__:' . $settingKey;
        $scopeWhere = [$qiFn($settingKeyCol) . ' = ?'];
        $scopeParams = [$settingKey];

        if ($serviceCodeCol !== null) {
            $scopeWhere[] = $qiFn($serviceCodeCol) . ' = ?';
            $scopeParams[] = $svcCode;
        }
        if ($tenantIdCol !== null) {
            $scopeWhere[] = 'ISNULL(' . $qiFn($tenantIdCol) . ', 0) = ?';
            $scopeParams[] = $tId;
        }
        if ($settingGroupCol !== null) {
            $scopeWhere[] = $qiFn($settingGroupCol) . ' = ?';
            $scopeParams[] = $settingGroup;
        }
        if ($userIdCol !== null) {
            $scopeWhere[] = $qiFn($userIdCol) . ' = ?';
            $scopeParams[] = $systemUserId;
        }
        if ($userIdxCol !== null) {
            $scopeWhere[] = 'ISNULL(' . $qiFn($userIdxCol) . ', 0) = 0';
        }
        if ($employeeIdxCol !== null) {
            $scopeWhere[] = 'ISNULL(' . $qiFn($employeeIdxCol) . ', 0) = 0';
        }
        if ($memberIdxCol !== null) {
            $scopeWhere[] = 'ISNULL(' . $qiFn($memberIdxCol) . ', 0) = 0';
        }
        if ($isDeletedCol !== null) {
            $scopeWhere[] = 'ISNULL(' . $qiFn($isDeletedCol) . ', 0) = 0';
        }

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
            if ($settingTypeCol !== null) {
                $setSql[] = $qiFn($settingTypeCol) . ' = ?';
                $params[] = $settingType;
            }
            if ($isDeletedCol !== null) {
                $setSql[] = $qiFn($isDeletedCol) . ' = 0';
            }
            if ($updatedByCol !== null) {
                $setSql[] = $qiFn($updatedByCol) . ' = ?';
                $params[] = $actorUserPk;
            }
            if ($updatedAtCol !== null) {
                $setSql[] = $qiFn($updatedAtCol) . ' = GETDATE()';
            }

            $params[] = $rowIdx;
            $updateSql = 'UPDATE Tb_UserSettings SET ' . implode(', ', $setSql) . ' WHERE ' . $qiFn($idxCol) . ' = ?';
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($params);
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
        if ($tenantIdCol !== null) { $addVal($tenantIdCol, $tId); }
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
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute($params);

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

    $loadMemberRequiredSettings = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $svcCode, int $tId): array {
        $defaults = [
            'name' => 0,
            'card_number' => 0,
            'ceo_name' => 0,
            'business_type' => 0,
            'business_class' => 0,
            'tel' => 0,
            'hp' => 0,
            'email' => 0,
            'address' => 0,
            'employee_idx' => 0,
        ];

        if ($tableExistsFn($pdo, 'Tb_UserSettings')
            && $columnExistsFn($pdo, 'Tb_UserSettings', 'setting_key')
            && $columnExistsFn($pdo, 'Tb_UserSettings', 'setting_value')
        ) {
            $where = ["setting_key IN ('MEMBER_REQUIRED','member_required')"];
            $params = [];

            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'service_code')) {
                $where[] = 'service_code = ?';
                $params[] = $svcCode;
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'tenant_id')) {
                $where[] = 'ISNULL(tenant_id,0) = ?';
                $params[] = $tId;
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'is_deleted')) {
                $where[] = 'ISNULL(is_deleted,0)=0';
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'user_id')) {
                $where[] = "(ISNULL(user_id,'') IN ('', '__SYSTEM__:MEMBER_REQUIRED') OR user_id LIKE '__SYSTEM__:%')";
            }

            $orderParts = [];
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'updated_at')) {
                $orderParts[] = 'updated_at DESC';
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'regdate')) {
                $orderParts[] = 'regdate DESC';
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'idx')) {
                $orderParts[] = 'idx DESC';
            }
            if ($orderParts === []) {
                $orderParts[] = '(SELECT NULL)';
            }

            $sql = 'SELECT TOP 1 setting_value FROM Tb_UserSettings WHERE ' . implode(' AND ', $where)
                . ' ORDER BY ' . implode(', ', $orderParts);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $raw = $stmt->fetchColumn();
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($defaults as $key => $base) {
                        if (array_key_exists($key, $decoded)) {
                            $defaults[$key] = ((int)$decoded[$key]) === 1 ? 1 : 0;
                        }
                    }
                    return $defaults;
                }
            }
        }

        if (defined('MEMBER_REQUIRED')) {
            $constantValue = MEMBER_REQUIRED;
            if (is_string($constantValue)) {
                $decoded = json_decode($constantValue, true);
                if (is_array($decoded)) {
                    foreach ($defaults as $key => $base) {
                        if (array_key_exists($key, $decoded)) {
                            $defaults[$key] = ((int)$decoded[$key]) === 1 ? 1 : 0;
                        }
                    }
                    return $defaults;
                }
            } elseif (is_array($constantValue)) {
                foreach ($defaults as $key => $base) {
                    if (array_key_exists($key, $constantValue)) {
                        $defaults[$key] = ((int)$constantValue[$key]) === 1 ? 1 : 0;
                    }
                }
                return $defaults;
            }
        }

        return $defaults;
    };

    $loadMemberRegionsSettings = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $svcCode, int $tId): array {
        $normalize = static function (array $raw): array {
            $normalized = [];
            foreach ($raw as $key => $row) {
                $regionKey = trim((string)$key);
                if ($regionKey === '') {
                    continue;
                }

                $name = '';
                $on = 1;
                if (is_array($row)) {
                    $name = trim((string)($row['name'] ?? ''));
                    $on = ((int)($row['on'] ?? 1)) === 1 ? 1 : 0;
                } else {
                    $name = trim((string)$row);
                }

                if ($name === '') {
                    continue;
                }

                $normalized[$regionKey] = [
                    'name' => $name,
                    'on' => $on,
                ];
            }

            if ($normalized !== []) {
                ksort($normalized, SORT_NATURAL);
            }

            return $normalized;
        };

        if ($tableExistsFn($pdo, 'Tb_UserSettings')
            && $columnExistsFn($pdo, 'Tb_UserSettings', 'setting_key')
            && $columnExistsFn($pdo, 'Tb_UserSettings', 'setting_value')
        ) {
            $where = ["setting_key IN ('MEMBER_REGIONS','member_regions')"];
            $params = [];

            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'service_code')) {
                $where[] = 'service_code = ?';
                $params[] = $svcCode;
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'tenant_id')) {
                $where[] = 'ISNULL(tenant_id,0) = ?';
                $params[] = $tId;
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'is_deleted')) {
                $where[] = 'ISNULL(is_deleted,0)=0';
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'user_id')) {
                $where[] = "(ISNULL(user_id,'') IN ('', '__SYSTEM__:MEMBER_REGIONS') OR user_id LIKE '__SYSTEM__:%')";
            }

            $orderParts = [];
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'updated_at')) {
                $orderParts[] = 'updated_at DESC';
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'regdate')) {
                $orderParts[] = 'regdate DESC';
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'idx')) {
                $orderParts[] = 'idx DESC';
            }
            if ($orderParts === []) {
                $orderParts[] = '(SELECT NULL)';
            }

            $sql = 'SELECT TOP 1 setting_value FROM Tb_UserSettings WHERE ' . implode(' AND ', $where)
                . ' ORDER BY ' . implode(', ', $orderParts);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $raw = $stmt->fetchColumn();
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $normalized = $normalize($decoded);
                    if ($normalized !== []) {
                        return $normalized;
                    }
                }
            }
        }

        if (defined('MEMBER_REGIONS')) {
            $constantValue = MEMBER_REGIONS;
            if (is_string($constantValue)) {
                $decoded = json_decode($constantValue, true);
                if (is_array($decoded)) {
                    $normalized = $normalize($decoded);
                    if ($normalized !== []) {
                        return $normalized;
                    }
                }
            } elseif (is_array($constantValue)) {
                $normalized = $normalize($constantValue);
                if ($normalized !== []) {
                    return $normalized;
                }
            }
        }

        return [];
    };

    $normalizeColor = static function (mixed $value): string {
        $color = strtoupper(trim((string)$value));
        if ($color === '') {
            return '#94A3B8';
        }

        if (preg_match('/^#[0-9A-F]{6}$/', $color) === 1) {
            return $color;
        }
        if (preg_match('/^[0-9A-F]{6}$/', $color) === 1) {
            return '#' . $color;
        }
        if (preg_match('/^#[0-9A-F]{3}$/', $color) === 1) {
            return '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
        }
        if (preg_match('/^[0-9A-F]{3}$/', $color) === 1) {
            return '#' . $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
        }

        return '#94A3B8';
    };

    $loadItemPropertySettings = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        string $svcCode,
        int $tId,
        callable $normalizeColorFn
    ): array {
        $property = ['0' => '없음'];
        $colors = ['0' => '#94A3B8'];

        $readSetting = static function (string $settingKey) use ($pdo, $tableExistsFn, $columnExistsFn, $svcCode, $tId): array {
            if (!$tableExistsFn($pdo, 'Tb_UserSettings')
                || !$columnExistsFn($pdo, 'Tb_UserSettings', 'setting_key')
                || !$columnExistsFn($pdo, 'Tb_UserSettings', 'setting_value')
            ) {
                return [];
            }

            $where = ["setting_key IN (?, ?)"];
            $params = [$settingKey, strtolower($settingKey)];

            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'service_code')) {
                $where[] = 'service_code = ?';
                $params[] = $svcCode;
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'tenant_id')) {
                $where[] = 'ISNULL(tenant_id,0) = ?';
                $params[] = $tId;
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'is_deleted')) {
                $where[] = 'ISNULL(is_deleted,0)=0';
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'user_id')) {
                $where[] = "(ISNULL(user_id,'') IN ('', ?) OR user_id LIKE '__SYSTEM__:%')";
                $params[] = '__SYSTEM__:' . strtoupper($settingKey);
            }

            $orderParts = [];
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'updated_at')) {
                $orderParts[] = 'updated_at DESC';
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'regdate')) {
                $orderParts[] = 'regdate DESC';
            }
            if ($columnExistsFn($pdo, 'Tb_UserSettings', 'idx')) {
                $orderParts[] = 'idx DESC';
            }
            if ($orderParts === []) {
                $orderParts[] = '(SELECT NULL)';
            }

            $sql = 'SELECT TOP 1 setting_value FROM Tb_UserSettings WHERE ' . implode(' AND ', $where)
                . ' ORDER BY ' . implode(', ', $orderParts);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $raw = $stmt->fetchColumn();
            if (!is_string($raw) || trim($raw) === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        };

        $propertyRaw = $readSetting('ITEM_PROPERTY');
        if ($propertyRaw === [] && defined('ITEM_PROPERTY')) {
            $constantValue = ITEM_PROPERTY;
            if (is_string($constantValue)) {
                $decoded = json_decode($constantValue, true);
                if (is_array($decoded)) {
                    $propertyRaw = $decoded;
                }
            } elseif (is_array($constantValue)) {
                $propertyRaw = $constantValue;
            }
        }

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
        if (!array_key_exists('0', $property) || trim((string)$property['0']) === '') {
            $property['0'] = '없음';
        }
        ksort($property, SORT_NATURAL);

        $colorRaw = $readSetting('ITEM_PROPERTY_COLORS');
        if ($colorRaw === [] && defined('ITEM_PROPERTY_COLORS')) {
            $constantValue = ITEM_PROPERTY_COLORS;
            if (is_string($constantValue)) {
                $decoded = json_decode($constantValue, true);
                if (is_array($decoded)) {
                    $colorRaw = $decoded;
                }
            } elseif (is_array($constantValue)) {
                $colorRaw = $constantValue;
            }
        }

        foreach ($colorRaw as $key => $value) {
            $colorKey = trim((string)$key);
            if ($colorKey === '') {
                continue;
            }
            $colors[$colorKey] = $normalizeColorFn($value);
        }
        foreach ($property as $key => $_name) {
            if (!array_key_exists($key, $colors)) {
                $colors[$key] = '#94A3B8';
            }
        }
        $colors['0'] = $normalizeColorFn($colors['0'] ?? '#94A3B8');
        ksort($colors, SORT_NATURAL);

        return [
            'properties' => $property,
            'colors' => $colors,
        ];
    };

    $loadMemberHogiRows = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        callable $firstColFn,
        string $table,
        int $memberIdx,
        string $svcCode,
        int $tId
    ): array {
        if ($memberIdx <= 0 || !$tableExistsFn($pdo, $table)) {
            return [];
        }

        $idxCol = $firstColFn($pdo, $columnExistsFn, $table, ['idx', 'id']) ?? 'idx';
        $memberCol = $firstColFn($pdo, $columnExistsFn, $table, ['member_idx', 'idx_member']);
        if ($memberCol === null) {
            return [];
        }

        $pjtAttrCol = $firstColFn($pdo, $columnExistsFn, $table, ['pjt_attr', 'attribute', 'attr_name', 'name', 'hogi_name']);
        $optionCol = $firstColFn($pdo, $columnExistsFn, $table, ['option_val', 'option_value', 'attr_value', 'value', 'name', 'hogi_name']);
        $charCol = $firstColFn($pdo, $columnExistsFn, $table, ['char_match', 'match_char', 'match_text', 'char']);
        $sortCol = $firstColFn($pdo, $columnExistsFn, $table, ['sort_order', 'sort', 'display_order', 'order_no']);
        $createdCol = $firstColFn($pdo, $columnExistsFn, $table, ['created_at', 'regdate', 'registered_date']);

        $where = ['ISNULL(TRY_CONVERT(INT,' . $memberCol . '),0)=?'];
        $params = [$memberIdx];
        if ($columnExistsFn($pdo, $table, 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        if ($columnExistsFn($pdo, $table, 'service_code')) {
            $where[] = "ISNULL(service_code,'') = ?";
            $params[] = $svcCode;
        }
        if ($columnExistsFn($pdo, $table, 'tenant_id')) {
            $where[] = 'ISNULL(tenant_id,0)=?';
            $params[] = $tId;
        }

        $pjtExpr = $pjtAttrCol !== null
            ? "ISNULL(CAST({$pjtAttrCol} AS NVARCHAR(120)),'')"
            : "CAST('' AS NVARCHAR(120))";
        $optionExpr = $optionCol !== null
            ? "ISNULL(CAST({$optionCol} AS NVARCHAR(120)),'')"
            : "CAST('' AS NVARCHAR(120))";
        $charExpr = $charCol !== null
            ? "ISNULL(CAST({$charCol} AS NVARCHAR(30)),'')"
            : "CAST('' AS NVARCHAR(30))";
        $sortExpr = $sortCol !== null
            ? "ISNULL(TRY_CONVERT(INT,{$sortCol}),0)"
            : 'CAST(0 AS INT)';
        $createdExpr = $createdCol !== null ? $createdCol : 'NULL';

        $orderParts = [];
        if ($sortCol !== null) {
            $orderParts[] = 'ISNULL(TRY_CONVERT(INT,' . $sortCol . '),0) ASC';
        }
        $orderParts[] = $idxCol . ' ASC';
        $orderSql = implode(', ', $orderParts);

        $sql = 'SELECT '
            . "ISNULL(TRY_CONVERT(BIGINT,{$idxCol}),0) AS idx, "
            . "{$pjtExpr} AS pjt_attr, "
            . "{$optionExpr} AS option_val, "
            . "{$charExpr} AS char_match, "
            . "{$sortExpr} AS sort_order, "
            . "{$createdExpr} AS created_at "
            . 'FROM ' . $table
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $orderSql;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $sortOrder = (int)($row['sort_order'] ?? 0);
            if ($sortOrder <= 0) {
                $sortOrder = $i + 1;
            }
            $normalized[] = [
                'idx' => (int)($row['idx'] ?? 0),
                'pjt_attr' => trim((string)($row['pjt_attr'] ?? '')),
                'option_val' => trim((string)($row['option_val'] ?? '')),
                'char_match' => trim((string)($row['char_match'] ?? '')),
                'sort_order' => $sortOrder,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        return $normalized;
    };

    $syncMemberHogiRows = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        callable $firstColFn,
        string $table,
        int $memberIdx,
        array $rows,
        string $svcCode,
        int $tId,
        int $actorUserPk
    ): array {
        $result = [
            'table' => $table,
            'deleted_count' => 0,
            'saved_count' => 0,
            'supported' => false,
        ];

        if ($memberIdx <= 0 || !$tableExistsFn($pdo, $table)) {
            return $result;
        }

        $idxCol = $firstColFn($pdo, $columnExistsFn, $table, ['idx', 'id']) ?? 'idx';
        $memberCol = $firstColFn($pdo, $columnExistsFn, $table, ['member_idx', 'idx_member']);
        if ($memberCol === null) {
            return $result;
        }
        $result['supported'] = true;

        $pjtAttrCol = $firstColFn($pdo, $columnExistsFn, $table, ['pjt_attr', 'attribute', 'attr_name']);
        $optionCol = $firstColFn($pdo, $columnExistsFn, $table, ['option_val', 'option_value', 'attr_value', 'value']);
        $charCol = $firstColFn($pdo, $columnExistsFn, $table, ['char_match', 'match_char', 'match_text', 'char']);
        $sortCol = $firstColFn($pdo, $columnExistsFn, $table, ['sort_order', 'sort', 'display_order', 'order_no']);
        $nameCol = $firstColFn($pdo, $columnExistsFn, $table, ['hogi_name', 'name']);
        $isDeletedCol = $firstColFn($pdo, $columnExistsFn, $table, ['is_deleted']);
        $serviceCodeCol = $firstColFn($pdo, $columnExistsFn, $table, ['service_code']);
        $tenantIdCol = $firstColFn($pdo, $columnExistsFn, $table, ['tenant_id']);
        $createdAtCol = $firstColFn($pdo, $columnExistsFn, $table, ['created_at']);
        $createdByCol = $firstColFn($pdo, $columnExistsFn, $table, ['created_by']);
        $updatedAtCol = $firstColFn($pdo, $columnExistsFn, $table, ['updated_at']);
        $updatedByCol = $firstColFn($pdo, $columnExistsFn, $table, ['updated_by']);
        $deletedAtCol = $firstColFn($pdo, $columnExistsFn, $table, ['deleted_at']);
        $deletedByCol = $firstColFn($pdo, $columnExistsFn, $table, ['deleted_by']);
        $regdateCol = $firstColFn($pdo, $columnExistsFn, $table, ['regdate']);

        $where = ['ISNULL(TRY_CONVERT(INT,' . $memberCol . '),0)=?'];
        $whereParams = [$memberIdx];
        if ($serviceCodeCol !== null) {
            $where[] = "ISNULL({$serviceCodeCol},'') = ?";
            $whereParams[] = $svcCode;
        }
        if ($tenantIdCol !== null) {
            $where[] = 'ISNULL(' . $tenantIdCol . ',0)=?';
            $whereParams[] = $tId;
        }

        if ($isDeletedCol !== null) {
            $setSql = [$isDeletedCol . ' = 1'];
            $setParams = [];
            if ($deletedAtCol !== null) { $setSql[] = $deletedAtCol . ' = ?'; $setParams[] = date('Y-m-d H:i:s'); }
            if ($deletedByCol !== null) { $setSql[] = $deletedByCol . ' = ?'; $setParams[] = $actorUserPk; }
            if ($updatedAtCol !== null) { $setSql[] = $updatedAtCol . ' = ?'; $setParams[] = date('Y-m-d H:i:s'); }
            if ($updatedByCol !== null) { $setSql[] = $updatedByCol . ' = ?'; $setParams[] = $actorUserPk; }

            $stmt = $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute(array_merge($setParams, $whereParams));
            $result['deleted_count'] = (int)$stmt->rowCount();
        } else {
            $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute($whereParams);
            $result['deleted_count'] = (int)$stmt->rowCount();
        }

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }

            $pjtAttr = trim((string)($row['pjt_attr'] ?? $row['attribute'] ?? ''));
            $optionVal = trim((string)($row['option_val'] ?? $row['option_value'] ?? $row['value'] ?? ''));
            $charMatch = trim((string)($row['char_match'] ?? $row['match_char'] ?? ''));
            $sortOrder = (int)($row['sort_order'] ?? $row['sort'] ?? ($i + 1));
            if ($sortOrder <= 0) {
                $sortOrder = $i + 1;
            }
            if ($pjtAttr === '' && $optionVal === '' && $charMatch === '') {
                continue;
            }

            $columns = [];
            $values = [];
            $add = static function (?string $column, mixed $value) use (&$columns, &$values): void {
                if ($column === null || $column === '') {
                    return;
                }
                $columns[] = $column;
                $values[] = $value;
            };

            $add($memberCol, $memberIdx);
            $add($pjtAttrCol, $pjtAttr);
            $add($optionCol, $optionVal);
            $add($charCol, $charMatch);
            $add($sortCol, $sortOrder);
            if ($nameCol !== null) {
                $fallbackName = $optionVal !== '' ? $optionVal : $pjtAttr;
                $add($nameCol, $fallbackName);
            }
            if ($serviceCodeCol !== null) { $add($serviceCodeCol, $svcCode); }
            if ($tenantIdCol !== null) { $add($tenantIdCol, $tId); }
            if ($isDeletedCol !== null) { $add($isDeletedCol, 0); }
            if ($createdByCol !== null) { $add($createdByCol, $actorUserPk); }
            if ($createdAtCol !== null) { $add($createdAtCol, date('Y-m-d H:i:s')); }
            if ($regdateCol !== null) { $add($regdateCol, date('Y-m-d H:i:s')); }

            if ($columns === []) {
                continue;
            }

            $ph = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
            $stmt->execute($values);
            $result['saved_count']++;
        }

        return $result;
    };

    $updateItemAttributesByProperty = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        callable $firstColFn,
        callable $qiFn,
        array $propertyMap,
        int $actorUserPk
    ): int {
        if (!$tableExistsFn($pdo, 'Tb_Item')) {
            return 0;
        }

        $attributeCol = $firstColFn($pdo, $columnExistsFn, 'Tb_Item', ['attribute']);
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
            "ISNULL(CONVERT(NVARCHAR(40), " . $qiFn($attributeCol) . "), '') <> ''",
            'ISNULL(TRY_CONVERT(INT, ' . $qiFn($attributeCol) . '), 0) > 0',
        ];
        $whereParams = [];
        if ($columnExistsFn($pdo, 'Tb_Item', 'is_deleted')) {
            $where[] = 'ISNULL(' . $qiFn('is_deleted') . ',0)=0';
        }
        if ($validKeys !== []) {
            $placeholders = implode(', ', array_fill(0, count($validKeys), '?'));
            $where[] = 'TRY_CONVERT(INT, ' . $qiFn($attributeCol) . ') NOT IN (' . $placeholders . ')';
            $whereParams = $validKeys;
        }

        $setSql = [$qiFn($attributeCol) . " = '0'"];
        $setParams = [];
        if ($columnExistsFn($pdo, 'Tb_Item', 'updated_by')) {
            $setSql[] = $qiFn('updated_by') . ' = ?';
            $setParams[] = $actorUserPk;
        }
        if ($columnExistsFn($pdo, 'Tb_Item', 'updated_at')) {
            $setSql[] = $qiFn('updated_at') . ' = GETDATE()';
        }

        $sql = 'UPDATE Tb_Item SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($setParams, $whereParams));
        return (int)$stmt->rowCount();
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

            if ($key === '' || $label === '') {
                continue;
            }
            if ($rawValue === null) {
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

    $buildMemberDetailSections = static function (array $row) use ($buildDetailRows): array {
        $address = trim((string)($row['address'] ?? ''));
        $addressDetail = trim((string)($row['address_detail'] ?? ''));
        $addressCombined = trim($address . ($addressDetail !== '' ? ' ' . $addressDetail : ''));

        $sections = [];

        $basicRows = $buildDetailRows([
            ['name', '사업장명', $row['name'] ?? $row['member_name'] ?? $row['branch_name'] ?? ''],
            ['head_name', '본사', $row['head_name'] ?? ''],
            ['member_status', '상태', $row['member_status'] ?? $row['status'] ?? ''],
            ['ceo', '대표자', $row['ceo'] ?? ''],
            ['card_number', '사업자번호', $row['card_number'] ?? $row['biz_number'] ?? $row['business_number'] ?? ''],
            ['cooperation_contract', '협력계약', $row['cooperation_contract'] ?? ''],
            ['region', '지역', $row['region'] ?? ''],
        ]);
        if ($basicRows !== []) {
            $sections[] = [
                'key' => 'basic',
                'title' => '기본정보',
                'rows' => $basicRows,
            ];
        }

        $contactRows = $buildDetailRows([
            ['tel', '전화번호', $row['tel'] ?? '', 'phone'],
            ['email', '이메일', $row['email'] ?? '', 'email'],
            ['manager_name', '담당자', $row['manager_name'] ?? ''],
            ['manager_tel', '담당자 전화', $row['manager_tel'] ?? '', 'phone'],
            ['address', '주소', $addressCombined],
            ['zipcode', '우편번호', $row['zipcode'] ?? ''],
        ]);
        if ($contactRows !== []) {
            $sections[] = [
                'key' => 'contact',
                'title' => '연락/주소',
                'rows' => $contactRows,
            ];
        }

        $manageRows = $buildDetailRows([
            ['registered_date', '등록일', $row['registered_date'] ?? $row['regdate'] ?? $row['created_at'] ?? '', 'date'],
            ['memo', '메모', $row['memo'] ?? '', 'memo'],
        ]);
        if ($manageRows !== []) {
            $sections[] = [
                'key' => 'manage',
                'title' => '관리정보',
                'rows' => $manageRows,
            ];
        }

        return $sections;
    };

    $writeTodos = [
        'insert', 'update', 'member_inline_update', 'member_delete', 'restore', 'member_bulk_action',
        'inline_update_contact', 'assign_contact_folder', 'move_contact', 'link_head', 'unlink_head', 'update_link_status',
        'save_member_required', 'save_member_regions', 'save_item_property',
        'branch_folder_insert', 'branch_folder_update', 'branch_folder_delete',
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
            'idx' => 'm.idx',
            'name' => 'm.' . $nameCol,
            'status' => 'm.' . $statusCol,
            'card_number' => 'm.' . $cardCol,
        ];
        $sortExpr = $sortMap[$sortRaw] ?? 'm.idx';

        $where = ['1=1'];
        $params = [];

        if (!$includeDeleted && $columnExists($db, 'Tb_Members', 'is_deleted')) {
            $where[] = 'ISNULL(m.is_deleted,0)=0';
        }

        if ($search !== '') {
            $sp = '%' . $search . '%';
            $where[] = '(m.' . $nameCol . ' LIKE ? OR m.ceo LIKE ? OR m.' . $cardCol . ' LIKE ? OR m.tel LIKE ? OR m.address LIKE ?)';
            array_push($params, $sp, $sp, $sp, $sp, $sp);
        }

        $statusFilter = trim((string)($_GET['member_status'] ?? $_GET['status'] ?? ''));
        if ($statusFilter !== '' && $columnExists($db, 'Tb_Members', $statusCol)) {
            $where[] = 'm.' . $statusCol . ' = ?';
            $params[] = $statusFilter;
        }

        $headFilter = FmsInputValidator::int($_GET, 'head_idx', false, 1);
        if ($headFilter !== null && $columnExists($db, 'Tb_Members', 'head_idx')) {
            $where[] = 'm.head_idx = ?';
            $params[] = $headFilter;
        }

        $employeeMode = strtolower(trim((string)($_GET['employee'] ?? '')));
        $employeeIdxFilter = FmsInputValidator::int($_GET, 'employee_idx', false, 1);
        if ($employeeMode !== '' || $employeeIdxFilter !== null) {
            if ($memberEmployeeCol === null) {
                ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members.employee_idx column is missing', 503);
                exit;
            }

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
                $where[] = 'ISNULL(m.' . $memberEmployeeCol . ',0) = ?';
                $params[] = $targetEmployeeIdx;
            } else {
                $where[] = '1=0';
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare('SELECT COUNT(*) FROM Tb_Members m ' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $newCount = 0;
        if ($memberRegisteredDateCol !== null) {
            $newWhere = $where;
            $newWhere[] = "CONVERT(VARCHAR(7), TRY_CONVERT(DATE, m.{$memberRegisteredDateCol}), 23) = CONVERT(VARCHAR(7), GETDATE(), 23)";
            $newCountStmt = $db->prepare('SELECT COUNT(*) FROM Tb_Members m WHERE ' . implode(' AND ', $newWhere));
            $newCountStmt->execute($params);
            $newCount = (int)$newCountStmt->fetchColumn();
        }

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $queryParams = array_merge($params, [$rowFrom, $rowTo]);

        $joinHead = $tableExists($db, 'Tb_HeadOffice') && $columnExists($db, 'Tb_Members', 'head_idx')
            ? 'LEFT JOIN Tb_HeadOffice h ON m.head_idx = h.idx'
            : '';
        $headExpr = $joinHead !== '' ? 'ISNULL(h.name, \'\')' : "CAST('' AS NVARCHAR(200))";
        $siteCountExpr = ($tableExists($db, 'Tb_Site') && $columnExists($db, 'Tb_Site', 'member_idx'))
            ? '(SELECT COUNT(*) FROM Tb_Site s WHERE s.member_idx = m.idx'
                . ($columnExists($db, 'Tb_Site', 'is_deleted') ? ' AND ISNULL(s.is_deleted,0)=0' : '')
                . ')'
            : 'CAST(0 AS INT)';
        $linkStatusExpr = $columnExists($db, 'Tb_Members', 'link_status')
            ? "ISNULL(m.link_status, N'연결')"
            : "N'연결'";

        $listSql = "SELECT * FROM (\n"
            . " SELECT m.*, ISNULL(m.{$nameCol}, '') AS member_display_name, {$headExpr} AS head_name,\n"
            . "        {$siteCountExpr} AS site_count, {$linkStatusExpr} AS link_status,\n"
            . "        ROW_NUMBER() OVER (ORDER BY {$sortExpr} {$orderExpr}, m.idx DESC) AS rn\n"
            . " FROM Tb_Members m {$joinHead} {$whereSql}\n"
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
            'new_count' => $newCount,
        ], 'OK', '사업장 목록 조회 성공');
        exit;
    }

    if ($todo === 'get_required' || $todo === 'member_required') {
        $required = $loadMemberRequiredSettings($db, $tableExists, $columnExists, $serviceCode, $tenantId);

        ApiResponse::success([
            'required' => $required,
            'data' => $required,
        ], 'OK', '고객 등록 필수항목 조회 성공');
        exit;
    }

    if ($todo === 'group_list') {
        if (!$tableExists($db, 'Tb_MemberGroup')) {
            ApiResponse::success([
                'data' => [],
            ], 'OK', '그룹 목록 조회 성공');
            exit;
        }

        $groupIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_MemberGroup', ['idx', 'id']);
        $groupNameCol = $firstExistingColumn($db, $columnExists, 'Tb_MemberGroup', ['name', 'group_name']);
        if ($groupIdxCol === null || $groupNameCol === null) {
            ApiResponse::success([
                'data' => [],
            ], 'OK', '그룹 목록 조회 성공');
            exit;
        }

        $orderParts = [];
        if ($columnExists($db, 'Tb_MemberGroup', 'sort_order')) {
            $orderParts[] = 'ISNULL(sort_order, 0) ASC';
        }
        $orderParts[] = $groupIdxCol . ' ASC';

        $where = [];
        if ($columnExists($db, 'Tb_MemberGroup', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $sql = 'SELECT ' . $groupIdxCol . ' AS idx, ' . $groupNameCol . ' AS name'
            . ($columnExists($db, 'Tb_MemberGroup', 'sort_order') ? ', ISNULL(sort_order,0) AS sort_order' : ', CAST(0 AS INT) AS sort_order')
            . ' FROM Tb_MemberGroup'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY ' . implode(', ', $orderParts);
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'data' => is_array($rows) ? $rows : [],
        ], 'OK', '그룹 목록 조회 성공');
        exit;
    }

    if ($todo === 'employee_list') {
        $myEmployeeIdx = $resolveContextEmployeeIdx($db, $tableExists, $columnExists, $context);

        if (!$tableExists($db, 'Tb_Employee')) {
            ApiResponse::success([
                'data' => [],
                'my_employee_idx' => $myEmployeeIdx,
            ], 'OK', '담당자 목록 조회 성공');
            exit;
        }

        $empIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['idx', 'id']);
        $empNameCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['name', 'employee_name']);
        if ($empIdxCol === null || $empNameCol === null) {
            ApiResponse::success([
                'data' => [],
                'my_employee_idx' => $myEmployeeIdx,
            ], 'OK', '담당자 목록 조회 성공');
            exit;
        }

        $empWorkCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['work', 'department', 'dept', 'team_name']);
        $empStatusCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['status']);
        $statusParam = trim((string)($_GET['status'] ?? $_POST['status'] ?? ''));

        $where = [];
        $params = [];
        if ($columnExists($db, 'Tb_Employee', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        if ($columnExists($db, 'Tb_Employee', 'hold_flag')) {
            $where[] = 'ISNULL(hold_flag,0)=0';
        }
        if ($statusParam !== '' && $empStatusCol !== null) {
            $where[] = $empStatusCol . ' = ?';
            $params[] = $statusParam;
        }

        $orderParts = [];
        if ($columnExists($db, 'Tb_Employee', 'sort_order')) {
            $orderParts[] = 'ISNULL(sort_order, 0) ASC';
        }
        if ($columnExists($db, 'Tb_Employee', 'work')) {
            $orderParts[] = 'work ASC';
        }
        $orderParts[] = $empNameCol . ' ASC';
        $orderParts[] = $empIdxCol . ' ASC';

        $sql = 'SELECT '
            . $empIdxCol . ' AS idx, '
            . $empNameCol . ' AS name, '
            . ($empWorkCol !== null ? $empWorkCol : "CAST('' AS NVARCHAR(120))") . ' AS work'
            . ($empStatusCol !== null ? ', ' . $empStatusCol . ' AS status' : ", CAST('' AS NVARCHAR(40)) AS status")
            . ' FROM Tb_Employee'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY ' . implode(', ', $orderParts);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'data' => is_array($rows) ? $rows : [],
            'my_employee_idx' => $myEmployeeIdx,
        ], 'OK', '담당자 목록 조회 성공');
        exit;
    }

    if ($todo === 'region_list') {
        $regionsMap = $loadMemberRegionsSettings($db, $tableExists, $columnExists, $serviceCode, $tenantId);
        $regions = [];
        foreach ($regionsMap as $key => $row) {
            $regionKey = trim((string)$key);
            if ($regionKey === '') {
                continue;
            }

            $regions[] = [
                'idx' => ctype_digit($regionKey) ? (int)$regionKey : 0,
                'key' => $regionKey,
                'name' => (string)($row['name'] ?? ''),
                'on' => ((int)($row['on'] ?? 0)) === 1 ? 1 : 0,
            ];
        }

        ApiResponse::success([
            'data' => $regions,
            'map' => $regionsMap,
        ], 'OK', '권역 목록 조회 성공');
        exit;
    }

    if ($todo === 'pjt_property_list') {
        $settings = $loadItemPropertySettings($db, $tableExists, $columnExists, $serviceCode, $tenantId, $normalizeColor);
        $properties = is_array($settings['properties'] ?? null) ? $settings['properties'] : ['0' => '없음'];
        $colors = is_array($settings['colors'] ?? null) ? $settings['colors'] : ['0' => '#94A3B8'];

        ApiResponse::success([
            'properties' => $properties,
            'colors' => $colors,
            'data' => $properties,
        ], 'OK', 'PJT 속성 목록 조회 성공');
        exit;
    }

    if ($todo === 'hogi_list') {
        $memberIdx = (int)($_GET['member_idx'] ?? $_GET['idx'] ?? 0);
        if ($memberIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'member_idx is required', 422);
            exit;
        }

        $member = $loadMember($db, $tableExists, $columnExists, $memberIdx);
        if ($member === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $hogiEnabledCol = $firstExistingColumn($db, $columnExists, 'Tb_Members', ['hogi_enabled', 'is_hogi', 'use_hogi']);
        $hogiEnabled = $hogiEnabledCol !== null
            ? (((int)($member[$hogiEnabledCol] ?? 0)) === 1 ? 1 : 0)
            : 0;

        $hogiRows = $loadMemberHogiRows(
            $db,
            $tableExists,
            $columnExists,
            $firstExistingColumn,
            $memberHogiTable,
            $memberIdx,
            $serviceCode,
            $tenantId
        );

        $settings = $loadItemPropertySettings($db, $tableExists, $columnExists, $serviceCode, $tenantId, $normalizeColor);
        $properties = is_array($settings['properties'] ?? null) ? $settings['properties'] : ['0' => '없음'];
        $pjtAttrs = [];
        foreach ($properties as $key => $label) {
            $name = trim((string)$label);
            if ($name === '' || $name === '없음') {
                continue;
            }
            $pjtAttrs[] = ['key' => (string)$key, 'name' => $name];
        }

        ApiResponse::success([
            'member_idx' => $memberIdx,
            'hogi_enabled' => $hogiEnabled,
            'pjt_attrs' => $pjtAttrs,
            'data' => $hogiRows,
            'list' => $hogiRows,
            'total' => count($hogiRows),
        ], 'OK', '호기 목록 조회 성공');
        exit;
    }

    if ($todo === 'save_member_required') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required_max' => 2, 'current' => $roleLevel]);
            exit;
        }

        $raw = trim((string)($_POST['data'] ?? ''));
        $decoded = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($decoded)) {
            ApiResponse::error('INVALID_PARAM', 'data must be valid JSON object', 422);
            exit;
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            $field = trim((string)$key);
            if ($field === '') {
                continue;
            }
            $normalized[$field] = ((int)$value) === 1 ? 1 : 0;
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

        $targetIdx = $upsertSystemSetting(
            $db,
            $tableExists,
            $columnExists,
            $firstExistingColumn,
            $qi,
            $serviceCode,
            $tenantId,
            (int)($context['user_pk'] ?? 0),
            'MEMBER_REQUIRED',
            $json,
            'json',
            'member'
        );

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'save_member_required', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => 'Tb_UserSettings',
            'target_idx' => $targetIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'member required settings saved',
            'keys' => array_values(array_keys($normalized)),
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'save_member_required', $targetIdx, [
            'setting_key' => 'MEMBER_REQUIRED',
            'data' => $normalized,
        ], 'Tb_UserSettings');

        ApiResponse::success([
            'setting_key' => 'MEMBER_REQUIRED',
            'item' => $normalized,
            'target_idx' => $targetIdx,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '고객 등록 필수항목 저장 완료');
        exit;
    }

    if ($todo === 'save_member_regions') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required_max' => 2, 'current' => $roleLevel]);
            exit;
        }

        $raw = trim((string)($_POST['regions'] ?? ''));
        $decoded = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($decoded)) {
            ApiResponse::error('INVALID_PARAM', 'regions must be valid JSON object', 422);
            exit;
        }

        $normalized = [];
        foreach ($decoded as $key => $item) {
            $regionKey = trim((string)$key);
            if ($regionKey === '') {
                continue;
            }

            $name = '';
            $on = 1;
            if (is_array($item)) {
                $name = trim((string)($item['name'] ?? ''));
                $on = ((int)($item['on'] ?? 1)) === 1 ? 1 : 0;
            } else {
                $name = trim((string)$item);
            }
            if ($name === '') {
                continue;
            }

            $normalized[$regionKey] = [
                'name' => $name,
                'on' => $on,
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

        $targetIdx = $upsertSystemSetting(
            $db,
            $tableExists,
            $columnExists,
            $firstExistingColumn,
            $qi,
            $serviceCode,
            $tenantId,
            (int)($context['user_pk'] ?? 0),
            'MEMBER_REGIONS',
            $json,
            'json',
            'member'
        );

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'save_member_regions', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => 'Tb_UserSettings',
            'target_idx' => $targetIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'member regions settings saved',
            'region_count' => count($normalized),
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'save_member_regions', $targetIdx, [
            'setting_key' => 'MEMBER_REGIONS',
            'regions' => $normalized,
        ], 'Tb_UserSettings');

        ApiResponse::success([
            'setting_key' => 'MEMBER_REGIONS',
            'item' => $normalized,
            'target_idx' => $targetIdx,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '권역 저장 완료');
        exit;
    }

    if ($todo === 'save_item_property') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required_max' => 2, 'current' => $roleLevel]);
            exit;
        }

        $propertyRaw = trim((string)($_POST['property'] ?? ''));
        $propertyDecoded = $propertyRaw === '' ? null : json_decode($propertyRaw, true);
        if (!is_array($propertyDecoded)) {
            ApiResponse::error('INVALID_PARAM', 'property must be valid JSON object', 422);
            exit;
        }

        $colorsRaw = trim((string)($_POST['colors'] ?? '{}'));
        $colorsDecoded = $colorsRaw === '' ? [] : json_decode($colorsRaw, true);
        if (!is_array($colorsDecoded)) {
            ApiResponse::error('INVALID_PARAM', 'colors must be valid JSON object', 422);
            exit;
        }

        $updateItems = FmsInputValidator::bool($_POST, 'update_items', false);

        $property = [];
        foreach ($propertyDecoded as $key => $label) {
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
        if (!array_key_exists('0', $property) || trim((string)$property['0']) === '') {
            $property['0'] = '없음';
        }
        ksort($property, SORT_NATURAL);

        $colors = [];
        foreach ($colorsDecoded as $key => $value) {
            $colorKey = trim((string)$key);
            if ($colorKey === '') {
                continue;
            }
            $colors[$colorKey] = $normalizeColor($value);
        }
        foreach ($property as $key => $_name) {
            if (!array_key_exists($key, $colors)) {
                $colors[$key] = '#94A3B8';
            }
        }
        $colors['0'] = $normalizeColor($colors['0'] ?? '#94A3B8');
        ksort($colors, SORT_NATURAL);

        $propertyJson = json_encode($property, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $colorsJson = json_encode($colors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($propertyJson) || !is_string($colorsJson)) {
            throw new RuntimeException('failed to encode item property settings');
        }

        $targetIdxA = $upsertSystemSetting(
            $db,
            $tableExists,
            $columnExists,
            $firstExistingColumn,
            $qi,
            $serviceCode,
            $tenantId,
            (int)($context['user_pk'] ?? 0),
            'ITEM_PROPERTY',
            $propertyJson,
            'json',
            'material'
        );

        $targetIdxB = $upsertSystemSetting(
            $db,
            $tableExists,
            $columnExists,
            $firstExistingColumn,
            $qi,
            $serviceCode,
            $tenantId,
            (int)($context['user_pk'] ?? 0),
            'ITEM_PROPERTY_COLORS',
            $colorsJson,
            'json',
            'material'
        );

        $updatedItems = 0;
        if ($updateItems) {
            $updatedItems = $updateItemAttributesByProperty(
                $db,
                $tableExists,
                $columnExists,
                $firstExistingColumn,
                $qi,
                $property,
                (int)($context['user_pk'] ?? 0)
            );
        }
        $targetIdx = $targetIdxB > 0 ? $targetIdxB : $targetIdxA;

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'save_item_property', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => 'Tb_UserSettings',
            'target_idx' => $targetIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
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
        ], 'Tb_UserSettings');

        ApiResponse::success([
            'properties' => $property,
            'property' => $property,
            'colors' => $colors,
            'update_items' => $updateItems ? 1 : 0,
            'updated_items' => $updatedItems,
            'target_idx' => $targetIdx,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', 'PJT 속성 저장 완료');
        exit;
    }

    if ($todo === 'branch_folder_list') {
        if (!$tableExists($db, $branchOrgTable)) {
            ApiResponse::error('SCHEMA_NOT_READY', $branchOrgTable . ' table is missing', 503);
            exit;
        }

        $memberIdx = FmsInputValidator::int($_GET, 'member_idx', true, 1);
        $member = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
        if ($member === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $where = ['member_idx = ?'];
        $params = [(int)$memberIdx];
        if ($columnExists($db, $branchOrgTable, 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $stmt = $db->prepare(
            "SELECT idx, member_idx, ISNULL(parent_idx, 0) AS parent_idx, name, ISNULL(depth,1) AS depth, ISNULL(sort_order,0) AS sort_order\n"
            . "FROM {$branchOrgTable} WHERE " . implode(' AND ', $where) . "\n"
            . 'ORDER BY ISNULL(depth,1) ASC, ISNULL(parent_idx,0) ASC, ISNULL(sort_order,0) ASC, idx ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'member_idx' => (int)$memberIdx,
            'data' => is_array($rows) ? $rows : [],
        ], 'OK', '하부조직 폴더 목록 조회 성공');
        exit;
    }

    if ($todo === 'branch_folder_insert') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required_max' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, $branchOrgTable)) {
            ApiResponse::error('SCHEMA_NOT_READY', $branchOrgTable . ' table is missing', 503);
            exit;
        }

        $memberIdx = FmsInputValidator::int($_POST, 'member_idx', true, 1);
        $parentIdxRaw = FmsInputValidator::int($_POST, 'parent_idx', false, 0);
        $parentIdx = $parentIdxRaw !== null && $parentIdxRaw > 0 ? (int)$parentIdxRaw : 0;
        $name = FmsInputValidator::string($_POST, 'name', 120, true);

        $member = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
        if ($member === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $parentDepth = 0;
        if ($parentIdx > 0) {
            $parent = $loadBranchOrgFolder($db, $tableExists, $columnExists, $branchOrgTable, $parentIdx);
            if ($parent === null) {
                ApiResponse::error('INVALID_PARAM', 'parent_idx is invalid', 422);
                exit;
            }
            if ((int)($parent['member_idx'] ?? 0) !== (int)$memberIdx) {
                ApiResponse::error('BUSINESS_RULE_VIOLATION', 'member_idx와 parent_idx 소속이 다릅니다', 409);
                exit;
            }
            $parentDepth = max(0, (int)($parent['depth'] ?? 0));
        }

        $depth = $parentDepth + 1;
        if ($depth > 3) {
            ApiResponse::error('BUSINESS_RULE_VIOLATION', '폴더는 최대 3단계까지만 생성할 수 있습니다', 409);
            exit;
        }

        $sortWhere = ['member_idx = ?'];
        $sortParams = [(int)$memberIdx];
        if ($parentIdx > 0) {
            $sortWhere[] = 'ISNULL(parent_idx,0) = ?';
            $sortParams[] = $parentIdx;
        } else {
            $sortWhere[] = 'ISNULL(parent_idx,0) = 0';
        }
        if ($columnExists($db, $branchOrgTable, 'is_deleted')) {
            $sortWhere[] = 'ISNULL(is_deleted,0)=0';
        }
        $sortStmt = $db->prepare('SELECT ISNULL(MAX(sort_order),0)+1 FROM ' . $branchOrgTable . ' WHERE ' . implode(' AND ', $sortWhere));
        $sortStmt->execute($sortParams);
        $sortOrder = (int)$sortStmt->fetchColumn();
        if ($sortOrder <= 0) {
            $sortOrder = 1;
        }

        $columns = [];
        $values = [];
        $addCol = static function (string $column, mixed $value) use (&$columns, &$values, $columnExists, $db, $branchOrgTable): void {
            if ($columnExists($db, $branchOrgTable, $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        };
        $addCol('member_idx', (int)$memberIdx);
        $addCol('parent_idx', $parentIdx);
        $addCol('name', $name);
        $addCol('depth', $depth);
        $addCol('sort_order', $sortOrder);
        $addCol('is_deleted', 0);
        $addCol('created_by', (int)($context['user_pk'] ?? 0));
        $addCol('created_at', date('Y-m-d H:i:s'));
        $addCol('regdate', date('Y-m-d H:i:s'));

        if ($columns === [] || !in_array('name', $columns, true)) {
            ApiResponse::error('SCHEMA_ERROR', $branchOrgTable . ' insertable columns are missing', 500);
            exit;
        }

        $ph = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $db->prepare('INSERT INTO ' . $branchOrgTable . ' (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
        $stmt->execute($values);
        $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
        $newIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
        $item = $newIdx > 0 ? $loadBranchOrgFolder($db, $tableExists, $columnExists, $branchOrgTable, $newIdx) : null;

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'branch_folder_insert', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => $branchOrgTable,
            'target_idx' => $newIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'branch folder inserted',
            'member_idx' => (int)$memberIdx,
            'parent_idx' => $parentIdx,
        ], $branchOrgTable);
        $shadowId = $enqueueShadow($db, $security, $context, 'branch_folder_insert', $newIdx, [
            'member_idx' => (int)$memberIdx,
            'parent_idx' => $parentIdx,
            'name' => $name,
        ], $branchOrgTable);

        ApiResponse::success([
            'idx' => $newIdx,
            'member_idx' => (int)$memberIdx,
            'item' => $item,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '하부조직 폴더 추가 성공');
        exit;
    }

    if ($todo === 'branch_folder_update') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required_max' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, $branchOrgTable)) {
            ApiResponse::error('SCHEMA_NOT_READY', $branchOrgTable . ' table is missing', 503);
            exit;
        }

        $idx = FmsInputValidator::int($_POST, 'idx', true, 1);
        $name = FmsInputValidator::string($_POST, 'name', 120, true);
        $folder = $loadBranchOrgFolder($db, $tableExists, $columnExists, $branchOrgTable, (int)$idx);
        if ($folder === null) {
            ApiResponse::error('NOT_FOUND', '폴더를 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = ['name = ?'];
        $params = [$name];
        if ($columnExists($db, $branchOrgTable, 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, $branchOrgTable, 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }
        $params[] = (int)$idx;

        $stmt = $db->prepare('UPDATE ' . $branchOrgTable . ' SET ' . implode(', ', $setSql) . ' WHERE idx = ?');
        $stmt->execute($params);
        $item = $loadBranchOrgFolder($db, $tableExists, $columnExists, $branchOrgTable, (int)$idx);

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'branch_folder_update', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => $branchOrgTable,
            'target_idx' => (int)$idx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'branch folder updated',
            'member_idx' => (int)($folder['member_idx'] ?? 0),
        ], $branchOrgTable);
        $shadowId = $enqueueShadow($db, $security, $context, 'branch_folder_update', (int)$idx, [
            'idx' => (int)$idx,
            'name' => $name,
        ], $branchOrgTable);

        ApiResponse::success([
            'idx' => (int)$idx,
            'item' => $item,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '하부조직 폴더 수정 성공');
        exit;
    }

    if ($todo === 'branch_folder_delete') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required_max' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, $branchOrgTable)) {
            ApiResponse::error('SCHEMA_NOT_READY', $branchOrgTable . ' table is missing', 503);
            exit;
        }

        $idx = FmsInputValidator::int($_POST, 'idx', true, 1);
        $folder = $loadBranchOrgFolder($db, $tableExists, $columnExists, $branchOrgTable, (int)$idx);
        if ($folder === null) {
            ApiResponse::error('NOT_FOUND', '폴더를 찾을 수 없습니다', 404);
            exit;
        }

        $childWhere = ['ISNULL(parent_idx,0) = ?'];
        $childParams = [(int)$idx];
        if ($columnExists($db, $branchOrgTable, 'is_deleted')) {
            $childWhere[] = 'ISNULL(is_deleted,0)=0';
        }
        $childStmt = $db->prepare('SELECT COUNT(*) FROM ' . $branchOrgTable . ' WHERE ' . implode(' AND ', $childWhere));
        $childStmt->execute($childParams);
        if ((int)$childStmt->fetchColumn() > 0) {
            ApiResponse::error('BUSINESS_RULE_VIOLATION', '하위 폴더가 있어 삭제할 수 없습니다', 409);
            exit;
        }

        if ($columnExists($db, $branchOrgTable, 'is_deleted')) {
            $setSql = ['is_deleted = 1'];
            $params = [];
            if ($columnExists($db, $branchOrgTable, 'deleted_at')) {
                $setSql[] = 'deleted_at = ?';
                $params[] = date('Y-m-d H:i:s');
            }
            if ($columnExists($db, $branchOrgTable, 'deleted_by')) {
                $setSql[] = 'deleted_by = ?';
                $params[] = (int)($context['user_pk'] ?? 0);
            }
            if ($columnExists($db, $branchOrgTable, 'updated_at')) {
                $setSql[] = 'updated_at = ?';
                $params[] = date('Y-m-d H:i:s');
            }
            if ($columnExists($db, $branchOrgTable, 'updated_by')) {
                $setSql[] = 'updated_by = ?';
                $params[] = (int)($context['user_pk'] ?? 0);
            }
            $params[] = (int)$idx;
            $stmt = $db->prepare('UPDATE ' . $branchOrgTable . ' SET ' . implode(', ', $setSql) . ' WHERE idx = ? AND ISNULL(is_deleted,0)=0');
            $stmt->execute($params);
        } else {
            $stmt = $db->prepare('DELETE FROM ' . $branchOrgTable . ' WHERE idx = ?');
            $stmt->execute([(int)$idx]);
        }

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'branch_folder_delete', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => $branchOrgTable,
            'target_idx' => (int)$idx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'branch folder deleted',
            'member_idx' => (int)($folder['member_idx'] ?? 0),
        ], $branchOrgTable);
        $shadowId = $enqueueShadow($db, $security, $context, 'branch_folder_delete', (int)$idx, [
            'idx' => (int)$idx,
            'member_idx' => (int)($folder['member_idx'] ?? 0),
        ], $branchOrgTable);

        ApiResponse::success([
            'idx' => (int)$idx,
            'deleted' => true,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '하부조직 폴더 삭제 성공');
        exit;
    }

    if ($todo === 'bill_list') {
        $memberIdx = FmsInputValidator::int($_GET, 'member_idx', true, 1);
        $member = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
        if ($member === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

        if (!$tableExists($db, $billGroupTable)) {
            ApiResponse::success([
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '수금현황 조회 성공');
            exit;
        }

        $bgMemberCol = $firstExistingColumn($db, $columnExists, $billGroupTable, ['member_idx']);
        if ($bgMemberCol === null) {
            ApiResponse::success([
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '수금현황 조회 성공');
            exit;
        }

        $bgEstimateCol = $firstExistingColumn($db, $columnExists, $billGroupTable, ['estimate_idx']);
        $bgEmployeeCol = $firstExistingColumn($db, $columnExists, $billGroupTable, ['employee_idx']);

        $joinBill = '';
        $billIdxCol = null;
        $billNumberCol = null;
        $billAmountCol = null;
        $billStatusCol = null;
        $billBringDateCol = null;
        $billDepositDateCol = null;
        $billDepositAmountCol = null;
        if ($tableExists($db, $billTable)) {
            $billReferCol = $firstExistingColumn($db, $columnExists, $billTable, ['refer_idx', 'billgroup_idx', 'bg_idx']);
            $billIdxCol = $firstExistingColumn($db, $columnExists, $billTable, ['idx', 'id']);
            $billNumberCol = $firstExistingColumn($db, $columnExists, $billTable, ['number', 'seq', 'bill_no']);
            $billAmountCol = $firstExistingColumn($db, $columnExists, $billTable, ['amount', 'bill_amount']);
            $billStatusCol = $firstExistingColumn($db, $columnExists, $billTable, ['status', 'bill_status']);
            $billBringDateCol = $firstExistingColumn($db, $columnExists, $billTable, ['bring_date', 'bill_date', 'insert_date', 'regdate']);
            $billDepositDateCol = $firstExistingColumn($db, $columnExists, $billTable, ['deposit_date', 'paid_date']);
            $billDepositAmountCol = $firstExistingColumn($db, $columnExists, $billTable, ['deposit_amount', 'paid_amount']);

            if ($billReferCol !== null) {
                $joinBill = " LEFT JOIN {$billTable} b ON b.{$billReferCol} = bg.idx";
                if ($columnExists($db, $billTable, 'is_deleted')) {
                    $joinBill .= ' AND ISNULL(b.is_deleted,0)=0';
                }
            }
        }

        $where = ['bg.' . $bgMemberCol . ' = ?'];
        $params = [(int)$memberIdx];
        if ($columnExists($db, $billGroupTable, 'is_deleted')) {
            $where[] = 'ISNULL(bg.is_deleted,0)=0';
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $estNameExpr = ($tableExists($db, 'Tb_SiteEstimate') && $bgEstimateCol !== null && $columnExists($db, 'Tb_SiteEstimate', 'name'))
            ? '(SELECT TOP 1 name FROM Tb_SiteEstimate WHERE idx = bg.' . $bgEstimateCol . ')'
            : "CAST('' AS NVARCHAR(200))";
        $empNameExpr = ($tableExists($db, 'Tb_Employee') && $bgEmployeeCol !== null && $columnExists($db, 'Tb_Employee', 'name'))
            ? '(SELECT TOP 1 name FROM Tb_Employee WHERE idx = bg.' . $bgEmployeeCol . ')'
            : "CAST('' AS NVARCHAR(120))";

        $billIdxExpr = ($joinBill !== '' && $billIdxCol !== null) ? 'ISNULL(b.' . $billIdxCol . ', 0)' : 'CAST(0 AS INT)';
        $billNoExpr = ($joinBill !== '' && $billNumberCol !== null) ? 'ISNULL(CAST(b.' . $billNumberCol . " AS NVARCHAR(30)), '')" : "CAST('' AS NVARCHAR(30))";
        $billAmountExpr = ($joinBill !== '' && $billAmountCol !== null) ? 'ISNULL(TRY_CONVERT(BIGINT,b.' . $billAmountCol . '),0)' : 'CAST(0 AS BIGINT)';
        $paidAmountExpr = ($joinBill !== '' && $billDepositAmountCol !== null) ? 'ISNULL(TRY_CONVERT(BIGINT,b.' . $billDepositAmountCol . '),0)' : 'CAST(0 AS BIGINT)';
        $billDateExpr = ($joinBill !== '' && $billBringDateCol !== null) ? 'b.' . $billBringDateCol : 'NULL';
        $paidDateExpr = ($joinBill !== '' && $billDepositDateCol !== null) ? 'b.' . $billDepositDateCol : 'NULL';
        $billStatusExpr = ($joinBill !== '' && $billStatusCol !== null) ? 'ISNULL(CAST(b.' . $billStatusCol . " AS NVARCHAR(40)),'')" : "CAST('' AS NVARCHAR(40))";

        $countSql = 'SELECT COUNT(*) FROM ' . $billGroupTable . ' bg' . $joinBill . $whereSql;
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $queryParams = array_merge($params, [$rowFrom, $rowTo]);
        $orderExpr = 'bg.idx DESC' . (($joinBill !== '' && $billIdxCol !== null) ? ', ISNULL(b.' . $billIdxCol . ',0) ASC' : '');

        $listSql = "SELECT * FROM (\n"
            . " SELECT\n"
            . "   bg.idx AS bg_idx,\n"
            . "   {$estNameExpr} AS est_name,\n"
            . "   {$empNameExpr} AS emp_name,\n"
            . "   {$billIdxExpr} AS bill_idx,\n"
            . "   {$billNoExpr} AS bill_no,\n"
            . "   {$billAmountExpr} AS bill_amount,\n"
            . "   {$paidAmountExpr} AS paid_amount,\n"
            . "   {$billDateExpr} AS bill_date,\n"
            . "   {$paidDateExpr} AS paid_date,\n"
            . "   {$billStatusExpr} AS bill_status,\n"
            . "   ROW_NUMBER() OVER (ORDER BY {$orderExpr}) AS rn\n"
            . " FROM {$billGroupTable} bg{$joinBill}{$whereSql}\n"
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
        ], 'OK', '수금현황 조회 성공');
        exit;
    }

    if ($todo === 'mail_list') {
        $memberIdx = FmsInputValidator::int($_GET, 'member_idx', true, 1);
        $member = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
        if ($member === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

        if (!$tableExists($db, $mailLinkTable)) {
            ApiResponse::success([
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '메일 목록 조회 성공');
            exit;
        }

        $mailIdxCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['idx', 'id']) ?? 'idx';
        $mailSiteCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['site_idx']);
        $mailMemberCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['member_idx']);
        $mailCacheCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['mail_cache_id']);
        $mailSubjectCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['subject', 'title']);
        $mailFromCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['from_address', 'sender', 'from_name', 'from_email']);
        $mailToCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['to_address', 'recipient', 'to_name', 'to_email']);
        $mailEmployeeCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['employee_idx']);
        $mailSendDateCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['send_date', 'reg_date', 'registered_date', 'created_at', 'linked_at', 'regdate']);
        $mailCreatedAtCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['created_at', 'registered_date', 'regdate', 'reg_date', 'linked_at']);
        $mailRegdateCol = $firstExistingColumn($db, $columnExists, $mailLinkTable, ['regdate', 'registered_date', 'reg_date', 'created_at', 'linked_at']);

        $siteTableExists = $tableExists($db, 'Tb_Site');
        $siteIdxCol = $siteTableExists ? $firstExistingColumn($db, $columnExists, 'Tb_Site', ['idx', 'id']) : null;
        $siteMemberCol = $siteTableExists ? $firstExistingColumn($db, $columnExists, 'Tb_Site', ['member_idx']) : null;
        $siteNameCol = $siteTableExists ? $firstExistingColumn($db, $columnExists, 'Tb_Site', ['site_name', 'name']) : null;

        $siteJoin = '';
        $siteNameExpr = "CAST('' AS NVARCHAR(200))";
        if ($siteTableExists && $mailSiteCol !== null && $siteIdxCol !== null) {
            $siteJoin = " LEFT JOIN Tb_Site s ON s.{$siteIdxCol} = sm.{$mailSiteCol}";
            if ($columnExists($db, 'Tb_Site', 'is_deleted')) {
                $siteJoin .= ' AND ISNULL(s.is_deleted,0)=0';
            }
            if ($siteNameCol !== null) {
                $siteNameExpr = "ISNULL(CAST(s.{$siteNameCol} AS NVARCHAR(200)), '')";
            }
        }

        $cacheJoin = '';
        $cacheSubjectCol = null;
        $cacheFromCol = null;
        $cacheToCol = null;
        $cacheDateCol = null;
        if ($mailCacheCol !== null) {
            foreach (['Tb_Mail', 'Tb_Mail_MessageCache'] as $cacheTable) {
                if (!$tableExists($db, $cacheTable)) {
                    continue;
                }

                $cacheIdxCol = $firstExistingColumn($db, $columnExists, $cacheTable, ['id', 'idx']);
                if ($cacheIdxCol === null) {
                    continue;
                }

                $cacheSubjectCol = $firstExistingColumn($db, $columnExists, $cacheTable, ['subject', 'title']);
                $cacheFromCol = $firstExistingColumn($db, $columnExists, $cacheTable, ['from_address', 'sender', 'from_email']);
                $cacheToCol = $firstExistingColumn($db, $columnExists, $cacheTable, ['to_address', 'recipient', 'to_email', 'to_name']);
                $cacheDateCol = $firstExistingColumn($db, $columnExists, $cacheTable, ['date', 'received_at', 'sent_at', 'created_at']);

                $cacheJoin = " LEFT JOIN {$cacheTable} mc ON mc.{$cacheIdxCol} = sm.{$mailCacheCol}";
                if ($columnExists($db, $cacheTable, 'is_deleted')) {
                    $cacheJoin .= ' AND ISNULL(mc.is_deleted,0)=0';
                }
                if ($columnExists($db, $cacheTable, 'service_code')) {
                    $cacheJoin .= ' AND ISNULL(mc.service_code, \'\') = ?';
                }
                if ($columnExists($db, $cacheTable, 'tenant_id')) {
                    $cacheJoin .= ' AND ISNULL(mc.tenant_id, 0) = ?';
                }
                break;
            }
        }

        $employeeJoin = '';
        $employeeNameExpr = "CAST('' AS NVARCHAR(120))";
        $employeeIdxExpr = 'CAST(0 AS INT)';
        if ($mailEmployeeCol !== null) {
            $employeeIdxExpr = 'ISNULL(TRY_CONVERT(INT, sm.' . $mailEmployeeCol . '), 0)';
            if ($tableExists($db, 'Tb_Employee')) {
                $employeeIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['idx', 'id']);
                $employeeNameCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['name', 'employee_name']);
                if ($employeeIdxCol !== null && $employeeNameCol !== null) {
                    $employeeJoin = " LEFT JOIN Tb_Employee e ON e.{$employeeIdxCol} = sm.{$mailEmployeeCol}";
                    if ($columnExists($db, 'Tb_Employee', 'is_deleted')) {
                        $employeeJoin .= ' AND ISNULL(e.is_deleted,0)=0';
                    }
                    $employeeNameExpr = "ISNULL(CAST(e.{$employeeNameCol} AS NVARCHAR(120)), '')";
                }
            }
        }

        $buildTextExpr = static function (?string $primaryExpr, ?string $secondaryExpr, string $fallback): string {
            if ($primaryExpr !== null && $secondaryExpr !== null) {
                return "ISNULL(NULLIF({$primaryExpr}, ''), ISNULL({$secondaryExpr}, {$fallback}))";
            }
            if ($primaryExpr !== null) {
                return "ISNULL({$primaryExpr}, {$fallback})";
            }
            if ($secondaryExpr !== null) {
                return "ISNULL({$secondaryExpr}, {$fallback})";
            }
            return $fallback;
        };

        $subjectExpr = $buildTextExpr(
            $mailSubjectCol !== null ? 'CAST(sm.' . $mailSubjectCol . ' AS NVARCHAR(500))' : null,
            ($cacheJoin !== '' && $cacheSubjectCol !== null) ? 'CAST(mc.' . $cacheSubjectCol . ' AS NVARCHAR(500))' : null,
            "CAST('' AS NVARCHAR(500))"
        );
        $fromExpr = $buildTextExpr(
            $mailFromCol !== null ? 'CAST(sm.' . $mailFromCol . ' AS NVARCHAR(320))' : null,
            ($cacheJoin !== '' && $cacheFromCol !== null) ? 'CAST(mc.' . $cacheFromCol . ' AS NVARCHAR(320))' : null,
            "CAST('' AS NVARCHAR(320))"
        );
        $toExpr = $buildTextExpr(
            $mailToCol !== null ? 'CAST(sm.' . $mailToCol . ' AS NVARCHAR(320))' : null,
            ($cacheJoin !== '' && $cacheToCol !== null) ? 'CAST(mc.' . $cacheToCol . ' AS NVARCHAR(320))' : null,
            "CAST('' AS NVARCHAR(320))"
        );

        $sendDateExpr = $mailSendDateCol !== null
            ? 'sm.' . $mailSendDateCol
            : (($cacheJoin !== '' && $cacheDateCol !== null) ? 'mc.' . $cacheDateCol : 'NULL');
        $createdAtExpr = $mailCreatedAtCol !== null ? 'sm.' . $mailCreatedAtCol : $sendDateExpr;
        $regdateExpr = $mailRegdateCol !== null ? 'sm.' . $mailRegdateCol : $sendDateExpr;
        $siteIdxExpr = $mailSiteCol !== null ? 'ISNULL(TRY_CONVERT(INT, sm.' . $mailSiteCol . '), 0)' : 'CAST(0 AS INT)';

        $where = [];
        $params = [];
        if ($columnExists($db, $mailLinkTable, 'is_deleted')) {
            $where[] = 'ISNULL(sm.is_deleted,0)=0';
        }
        if ($columnExists($db, $mailLinkTable, 'service_code')) {
            $where[] = 'ISNULL(sm.service_code, \'\') = ?';
            $params[] = $serviceCode;
        }
        if ($columnExists($db, $mailLinkTable, 'tenant_id')) {
            $where[] = 'ISNULL(sm.tenant_id,0) = ?';
            $params[] = $tenantId;
        }

        $memberScoped = false;
        if ($siteJoin !== '' && $siteMemberCol !== null) {
            $where[] = 'ISNULL(s.' . $siteMemberCol . ',0) = ?';
            $params[] = (int)$memberIdx;
            $memberScoped = true;
        } elseif ($mailMemberCol !== null) {
            $where[] = 'ISNULL(sm.' . $mailMemberCol . ',0) = ?';
            $params[] = (int)$memberIdx;
            $memberScoped = true;
        } elseif ($mailSiteCol !== null && $siteTableExists && $siteIdxCol !== null && $siteMemberCol !== null) {
            $subWhere = ['ISNULL(' . $siteMemberCol . ',0)=?'];
            if ($columnExists($db, 'Tb_Site', 'is_deleted')) {
                $subWhere[] = 'ISNULL(is_deleted,0)=0';
            }
            $where[] = 'ISNULL(sm.' . $mailSiteCol . ',0) IN (SELECT ' . $siteIdxCol . ' FROM Tb_Site WHERE ' . implode(' AND ', $subWhere) . ')';
            $params[] = (int)$memberIdx;
            $memberScoped = true;
        }

        if (!$memberScoped) {
            ApiResponse::success([
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '메일 목록 조회 성공');
            exit;
        }

        $cacheJoinParams = [];
        if ($cacheJoin !== '') {
            if (str_contains($cacheJoin, 'mc.service_code')) {
                $cacheJoinParams[] = $serviceCode;
            }
            if (str_contains($cacheJoin, 'mc.tenant_id')) {
                $cacheJoinParams[] = $tenantId;
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $fromSql = ' FROM ' . $mailLinkTable . ' sm' . $siteJoin . $cacheJoin . $employeeJoin;
        $countSql = 'SELECT COUNT(*)' . $fromSql . $whereSql;
        $countStmt = $db->prepare($countSql);
        $countStmt->execute(array_merge($cacheJoinParams, $params));
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $queryParams = array_merge($cacheJoinParams, $params, [$rowFrom, $rowTo]);

        $orderExpr = "ISNULL(TRY_CONVERT(DATETIME, {$sendDateExpr}), '1900-01-01') DESC, sm.{$mailIdxCol} DESC";
        $listSql = "SELECT * FROM (\n"
            . " SELECT\n"
            . '   ISNULL(TRY_CONVERT(BIGINT, sm.' . $mailIdxCol . "),0) AS idx,\n"
            . "   {$siteIdxExpr} AS site_idx,\n"
            . "   {$siteNameExpr} AS site_name,\n"
            . "   {$subjectExpr} AS subject,\n"
            . "   {$subjectExpr} AS title,\n"
            . "   {$fromExpr} AS from_name,\n"
            . "   {$fromExpr} AS sender,\n"
            . "   {$toExpr} AS to_name,\n"
            . "   {$toExpr} AS receiver,\n"
            . "   {$employeeIdxExpr} AS employee_idx,\n"
            . "   {$employeeNameExpr} AS employee_name,\n"
            . "   {$sendDateExpr} AS send_date,\n"
            . "   {$createdAtExpr} AS created_at,\n"
            . "   {$regdateExpr} AS regdate,\n"
            . "   ROW_NUMBER() OVER (ORDER BY {$orderExpr}) AS rn\n"
            . $fromSql
            . $whereSql
            . "\n) t WHERE t.rn BETWEEN ? AND ?";

        $stmt = $db->prepare($listSql);
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'data' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ], 'OK', '메일 목록 조회 성공');
        exit;
    }

    if ($todo === 'attach_list') {
        $memberIdx = FmsInputValidator::int($_GET, 'member_idx', true, 1);
        $member = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
        if ($member === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

        if (!$tableExists($db, $fileAttachTable)) {
            ApiResponse::success([
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '첨부파일 목록 조회 성공');
            exit;
        }

        $fileIdxCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['idx', 'id']) ?? 'idx';
        $fileTableNameCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['table_name', 'to_table', 'target_table']);
        $fileLinkCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['table_idx', 'ref_idx', 'target_idx', 'parent_idx']);
        $fileMemberCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['member_idx']);
        $fileSiteCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['site_idx']);
        $fileNameCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['file_name', 'origin_name', 'original_name', 'name']);
        $filePathCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['file_url', 'url', 'file_path', 'path']);
        $fileCategoryCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['category', 'subject', 'item_name', 'type']);
        $fileMemoCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['memo', 'comment', 'description']);
        $fileWriterNameCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['writer_name', 'employee_name', 'user_name']);
        $fileEmployeeCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['employee_idx', 'writer_idx']);
        $fileCreatedByCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['created_by', 'user_idx', 'writer_id']);
        $fileCreatedAtCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['created_at', 'regdate', 'registered_date', 'insert_date']);
        $fileRegdateCol = $firstExistingColumn($db, $columnExists, $fileAttachTable, ['regdate', 'registered_date', 'created_at', 'insert_date']);

        $siteTableExists = $tableExists($db, 'Tb_Site');
        $siteIdxCol = $siteTableExists ? $firstExistingColumn($db, $columnExists, 'Tb_Site', ['idx', 'id']) : null;
        $siteMemberCol = $siteTableExists ? $firstExistingColumn($db, $columnExists, 'Tb_Site', ['member_idx']) : null;
        $siteNameCol = $siteTableExists ? $firstExistingColumn($db, $columnExists, 'Tb_Site', ['site_name', 'name']) : null;

        $siteScopeSql = '';
        if ($siteTableExists && $siteIdxCol !== null && $siteMemberCol !== null) {
            $siteScopeSql = 'SELECT ' . $siteIdxCol . ' FROM Tb_Site WHERE ISNULL(' . $siteMemberCol . ',0)=?';
            if ($columnExists($db, 'Tb_Site', 'is_deleted')) {
                $siteScopeSql .= ' AND ISNULL(is_deleted,0)=0';
            }
        }

        $writerJoin = '';
        $writerNameExpr = "CAST('' AS NVARCHAR(120))";
        if ($fileWriterNameCol !== null) {
            $writerNameExpr = 'ISNULL(CAST(f.' . $fileWriterNameCol . " AS NVARCHAR(120)), '')";
        } elseif ($fileEmployeeCol !== null && $tableExists($db, 'Tb_Employee')) {
            $empIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['idx', 'id']);
            $empNameCol = $firstExistingColumn($db, $columnExists, 'Tb_Employee', ['name', 'employee_name']);
            if ($empIdxCol !== null && $empNameCol !== null) {
                $writerJoin = ' LEFT JOIN Tb_Employee ew ON ew.' . $empIdxCol . ' = f.' . $fileEmployeeCol;
                if ($columnExists($db, 'Tb_Employee', 'is_deleted')) {
                    $writerJoin .= ' AND ISNULL(ew.is_deleted,0)=0';
                }
                $writerNameExpr = 'ISNULL(CAST(ew.' . $empNameCol . " AS NVARCHAR(120)), '')";
            }
        } elseif ($fileCreatedByCol !== null && $tableExists($db, 'Tb_Users')) {
            $userIdxCol = $firstExistingColumn($db, $columnExists, 'Tb_Users', ['idx', 'id']);
            $userNameCol = $firstExistingColumn($db, $columnExists, 'Tb_Users', ['name', 'user_name', 'login_id', 'id']);
            if ($userIdxCol !== null && $userNameCol !== null) {
                $writerJoin = ' LEFT JOIN Tb_Users uw ON uw.' . $userIdxCol . ' = f.' . $fileCreatedByCol;
                if ($columnExists($db, 'Tb_Users', 'is_deleted')) {
                    $writerJoin .= ' AND ISNULL(uw.is_deleted,0)=0';
                }
                $writerNameExpr = 'ISNULL(CAST(uw.' . $userNameCol . " AS NVARCHAR(120)), '')";
            }
        }

        $fileNameExpr = $fileNameCol !== null
            ? 'ISNULL(CAST(f.' . $fileNameCol . " AS NVARCHAR(255)), '')"
            : "CAST('' AS NVARCHAR(255))";
        $fileUrlExpr = $filePathCol !== null
            ? 'ISNULL(CAST(f.' . $filePathCol . " AS NVARCHAR(1000)), '')"
            : "CAST('' AS NVARCHAR(1000))";
        $categoryExpr = $fileCategoryCol !== null
            ? 'ISNULL(CAST(f.' . $fileCategoryCol . " AS NVARCHAR(200)), '')"
            : ($fileTableNameCol !== null ? 'ISNULL(CAST(f.' . $fileTableNameCol . " AS NVARCHAR(200)), '')" : "CAST('' AS NVARCHAR(200))");
        $memoExpr = $fileMemoCol !== null
            ? 'ISNULL(CAST(f.' . $fileMemoCol . " AS NVARCHAR(2000)), '')"
            : "CAST('' AS NVARCHAR(2000))";
        $createdAtExpr = $fileCreatedAtCol !== null ? 'f.' . $fileCreatedAtCol : 'NULL';
        $regdateExpr = $fileRegdateCol !== null ? 'f.' . $fileRegdateCol : $createdAtExpr;
        $siteNameExpr = ($fileSiteCol !== null && $siteTableExists && $siteIdxCol !== null && $siteNameCol !== null)
            ? '(SELECT TOP 1 ' . $siteNameCol . ' FROM Tb_Site ss WHERE ss.' . $siteIdxCol . ' = f.' . $fileSiteCol . ')'
            : "CAST('' AS NVARCHAR(200))";

        $where = [];
        $params = [];
        if ($columnExists($db, $fileAttachTable, 'is_deleted')) {
            $where[] = 'ISNULL(f.is_deleted,0)=0';
        }
        if ($columnExists($db, $fileAttachTable, 'service_code')) {
            $where[] = 'ISNULL(f.service_code, \'\') = ?';
            $params[] = $serviceCode;
        }
        if ($columnExists($db, $fileAttachTable, 'tenant_id')) {
            $where[] = 'ISNULL(f.tenant_id,0) = ?';
            $params[] = $tenantId;
        }

        $scopeWhere = [];
        $scopeParams = [];

        if ($fileMemberCol !== null) {
            $scopeWhere[] = 'ISNULL(f.' . $fileMemberCol . ',0)=?';
            $scopeParams[] = (int)$memberIdx;
        }
        if ($fileSiteCol !== null && $siteScopeSql !== '') {
            $scopeWhere[] = 'ISNULL(f.' . $fileSiteCol . ',0) IN (' . $siteScopeSql . ')';
            $scopeParams[] = (int)$memberIdx;
        }
        if ($fileTableNameCol !== null && $fileLinkCol !== null) {
            $scopeWhere[] = "(LOWER(CAST(f.{$fileTableNameCol} AS NVARCHAR(100))) IN ('tb_members','member','member_branch') AND ISNULL(TRY_CONVERT(INT, f.{$fileLinkCol}),0)=?)";
            $scopeParams[] = (int)$memberIdx;
            if ($siteScopeSql !== '') {
                $scopeWhere[] = "(LOWER(CAST(f.{$fileTableNameCol} AS NVARCHAR(100))) IN ('tb_site','site') AND ISNULL(TRY_CONVERT(INT, f.{$fileLinkCol}),0) IN ({$siteScopeSql}))";
                $scopeParams[] = (int)$memberIdx;
            }
        }

        if ($scopeWhere === []) {
            ApiResponse::success([
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ], 'OK', '첨부파일 목록 조회 성공');
            exit;
        }

        $where[] = '(' . implode(' OR ', $scopeWhere) . ')';
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $queryBaseParams = array_merge($params, $scopeParams);

        $countSql = 'SELECT COUNT(*) FROM ' . $fileAttachTable . ' f' . $writerJoin . $whereSql;
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($queryBaseParams);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $queryParams = array_merge($queryBaseParams, [$rowFrom, $rowTo]);

        $orderExpr = ($fileCreatedAtCol !== null ? "ISNULL(TRY_CONVERT(DATETIME, f.{$fileCreatedAtCol}), '1900-01-01') DESC, " : '')
            . 'f.' . $fileIdxCol . ' DESC';
        $listSql = "SELECT * FROM (\n"
            . " SELECT\n"
            . '   ISNULL(TRY_CONVERT(BIGINT, f.' . $fileIdxCol . "),0) AS idx,\n"
            . "   {$fileNameExpr} AS file_name,\n"
            . "   {$fileNameExpr} AS original_name,\n"
            . "   {$fileNameExpr} AS name,\n"
            . "   {$fileUrlExpr} AS file_url,\n"
            . "   {$fileUrlExpr} AS url,\n"
            . "   {$categoryExpr} AS category,\n"
            . "   {$categoryExpr} AS subject,\n"
            . "   {$writerNameExpr} AS writer_name,\n"
            . "   {$writerNameExpr} AS employee_name,\n"
            . "   {$siteNameExpr} AS site_name,\n"
            . "   {$createdAtExpr} AS created_at,\n"
            . "   {$regdateExpr} AS regdate,\n"
            . "   {$memoExpr} AS memo,\n"
            . "   ROW_NUMBER() OVER (ORDER BY {$orderExpr}) AS rn\n"
            . ' FROM ' . $fileAttachTable . ' f' . $writerJoin . $whereSql . "\n"
            . ") t WHERE t.rn BETWEEN ? AND ?";

        $stmt = $db->prepare($listSql);
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        foreach ($rows as &$row) {
            $rawUrl = trim((string)($row['file_url'] ?? $row['url'] ?? ''));
            $normalizedUrl = '';
            if ($rawUrl !== '') {
                if (preg_match('#^https?://#i', $rawUrl) === 1) {
                    $normalizedUrl = $rawUrl;
                } elseif (str_starts_with($rawUrl, '//')) {
                    $normalizedUrl = 'https:' . $rawUrl;
                } else {
                    $path = str_replace('\\', '/', $rawUrl);
                    if (str_starts_with($path, './')) {
                        $path = substr($path, 1);
                    }
                    if ($path !== '' && !str_starts_with($path, '/')) {
                        $path = '/' . ltrim($path, '/');
                    }
                    $normalizedUrl = $path;
                }
            }

            $row['file_url'] = $normalizedUrl;
            if (!isset($row['url']) || trim((string)$row['url']) === '') {
                $row['url'] = $normalizedUrl;
            }
        }
        unset($row);

        ApiResponse::success([
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ], 'OK', '첨부파일 목록 조회 성공');
        exit;
    }

    if ($todo === 'detail') {
        $idx = (int)($_GET['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx is required', 422);
            exit;
        }

        $row = $loadMember($db, $tableExists, $columnExists, $idx);
        if ($row === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $row['detail_sections'] = $buildMemberDetailSections($row);
        ApiResponse::success($row, 'OK', '사업장 상세 조회 성공');
        exit;
    }

    if ($todo === 'check_dup') {
        $cardNumber = trim((string)($_GET['card_number'] ?? $_POST['card_number'] ?? ''));
        $excludeIdx = (int)($_GET['exclude_idx'] ?? $_POST['exclude_idx'] ?? $_GET['idx'] ?? $_POST['idx'] ?? 0);

        if ($cardNumber !== '') {
            FmsInputValidator::bizNumber($cardNumber, 'card_number', false);
        }

        $existing = $cardNumber === '' ? null : $findByCard($db, $columnExists, $cardCol, $cardNumber, $excludeIdx);
        echo json_encode([
            'success' => true,
            'ok' => true,
            'code' => 'OK',
            'message' => '사업자번호 중복검사 완료',
            'exists' => is_array($existing),
            'data' => $existing,
            'card_number' => preg_replace('/\D+/', '', $cardNumber) ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($todo === 'insert') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }

        $name = FmsInputValidator::string($_POST, 'name', 120, true);
        $ceo = FmsInputValidator::string($_POST, 'ceo', 80, false);
        $cardNumber = FmsInputValidator::bizNumber((string)($_POST['card_number'] ?? ''), 'card_number', true);
        $tel = FmsInputValidator::phone((string)($_POST['tel'] ?? ''), 'tel', true);
        $email = FmsInputValidator::email((string)($_POST['email'] ?? ''), 'email', true);
        $status = trim((string)($_POST['member_status'] ?? $_POST['status'] ?? '예정'));
        $status = FmsInputValidator::oneOf($status, 'member_status', ['예정', '운영', '중지', '종료'], false);

        if ($cardNumber !== '') {
            $dup = $findByCard($db, $columnExists, $cardCol, $cardNumber);
            if ($dup !== null) {
                ApiResponse::error('DUPLICATE_CARD_NUMBER', '이미 등록된 사업자번호입니다', 409, ['existing' => $dup]);
                exit;
            }
        }

        $headIdx = FmsInputValidator::int($_POST, 'head_idx', false, 1);
        $validateHeadIdx($db, $tableExists, $columnExists, $headIdx);

        $fieldMap = [
            $nameCol => $name,
            'ceo' => $ceo,
            $cardCol => $cardNumber,
            'tel' => $tel,
            'email' => $email,
            'address' => FmsInputValidator::string($_POST, 'address', 255, false),
            'address_detail' => FmsInputValidator::string($_POST, 'address_detail', 255, false),
            'zipcode' => FmsInputValidator::string($_POST, 'zipcode', 20, false),
            'cooperation_contract' => FmsInputValidator::string($_POST, 'cooperation_contract', 50, false),
            'manager_name' => FmsInputValidator::string($_POST, 'manager_name', 80, false),
            'manager_tel' => FmsInputValidator::string($_POST, 'manager_tel', 40, false),
            'memo' => FmsInputValidator::string($_POST, 'memo', 2000, false),
            'region' => FmsInputValidator::string($_POST, 'region', 50, false),
        ];

        $columns = [];
        $values = [];

        foreach ($fieldMap as $column => $value) {
            if (!$columnExists($db, 'Tb_Members', $column)) {
                continue;
            }
            if ($column === $nameCol || $value !== '') {
                $columns[] = $column;
                $values[] = $value;
            }
        }

        if ($columnExists($db, 'Tb_Members', $statusCol)) {
            $columns[] = $statusCol;
            $values[] = $status;
        }
        if ($headIdx !== null && $columnExists($db, 'Tb_Members', 'head_idx')) {
            $columns[] = 'head_idx';
            $values[] = $headIdx;
        }
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $columns[] = 'is_deleted';
            $values[] = 0;
        }
        if ($columnExists($db, 'Tb_Members', 'regdate')) {
            $columns[] = 'regdate';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_Members', 'registered_date')) {
            $columns[] = 'registered_date';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_Members', 'created_at')) {
            $columns[] = 'created_at';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_Members', 'created_by')) {
            $columns[] = 'created_by';
            $values[] = (int)($context['user_pk'] ?? 0);
        }

        if ($columns === []) {
            ApiResponse::error('SCHEMA_ERROR', 'insertable columns not found', 500);
            exit;
        }

        try {
            $db->beginTransaction();
            $ph = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $db->prepare('INSERT INTO Tb_Members (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
            $stmt->execute($values);

            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            $newIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
            if ($newIdx <= 0) {
                throw new RuntimeException('failed to resolve inserted idx');
            }
            $db->commit();

            $row = $loadMember($db, $tableExists, $columnExists, $newIdx);
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'insert', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_idx' => $newIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'member inserted',
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'insert', $newIdx, ['member_idx' => $newIdx]);
            DevLogService::tryLog('FMS', 'Member insert', 'Member insert todo executed', 1);

            ApiResponse::success([
                'idx' => $newIdx,
                'item' => $row,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '사업장 등록 성공');
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

        $current = $loadMember($db, $tableExists, $columnExists, $idx);
        if ($current === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = [];
        $params = [];

        $updateIfProvided = static function (string $field, string $column, string $type, int $maxLen = 255) use (&$setSql, &$params, $db, $columnExists): void {
            if (!array_key_exists($field, $_POST) || !$columnExists($db, 'Tb_Members', $column)) {
                return;
            }

            if ($type === 'string') {
                $value = FmsInputValidator::string($_POST, $field, $maxLen, false);
            } elseif ($type === 'phone') {
                $value = FmsInputValidator::phone((string)$_POST[$field], $field, true);
            } elseif ($type === 'email') {
                $value = FmsInputValidator::email((string)$_POST[$field], $field, true);
            } elseif ($type === 'biz') {
                $value = FmsInputValidator::bizNumber((string)$_POST[$field], $field, true);
            } else {
                $value = trim((string)$_POST[$field]);
            }

            $setSql[] = $column . ' = ?';
            $params[] = $value;
        };

        $updateIfProvided('name', $nameCol, 'string', 120);
        $updateIfProvided('ceo', 'ceo', 'string', 80);
        $updateIfProvided('card_number', $cardCol, 'biz', 20);
        $updateIfProvided('tel', 'tel', 'phone', 40);
        $updateIfProvided('email', 'email', 'email', 120);
        $updateIfProvided('address', 'address', 'string', 255);
        $updateIfProvided('address_detail', 'address_detail', 'string', 255);
        $updateIfProvided('zipcode', 'zipcode', 'string', 20);
        $updateIfProvided('cooperation_contract', 'cooperation_contract', 'string', 50);
        $updateIfProvided('manager_name', 'manager_name', 'string', 80);
        $updateIfProvided('manager_tel', 'manager_tel', 'string', 40);
        $updateIfProvided('memo', 'memo', 'string', 2000);
        $updateIfProvided('region', 'region', 'string', 50);

        $hogiEnabledCol = $firstExistingColumn($db, $columnExists, 'Tb_Members', ['hogi_enabled', 'is_hogi', 'use_hogi']);
        if (array_key_exists('hogi_enabled', $_POST) && $hogiEnabledCol !== null) {
            $setSql[] = $hogiEnabledCol . ' = ?';
            $params[] = FmsInputValidator::bool($_POST, 'hogi_enabled', false) ? 1 : 0;
        }

        $hogiListRequested = array_key_exists('hogi_list', $_POST) || array_key_exists('hogi_data', $_POST);
        $hogiRows = [];
        if ($hogiListRequested) {
            $rawHogi = $_POST['hogi_list'] ?? $_POST['hogi_data'] ?? [];
            if (is_string($rawHogi)) {
                $rawText = trim($rawHogi);
                if ($rawText === '') {
                    $rawHogi = [];
                } else {
                    $decoded = json_decode($rawText, true);
                    if (!is_array($decoded)) {
                        ApiResponse::error('INVALID_PARAM', 'hogi_list must be valid JSON array', 422);
                        exit;
                    }
                    $rawHogi = $decoded;
                }
            }
            if (!is_array($rawHogi)) {
                ApiResponse::error('INVALID_PARAM', 'hogi_list must be JSON array', 422);
                exit;
            }

            $isAssoc = static function (array $arr): bool {
                return $arr !== [] && array_keys($arr) !== range(0, count($arr) - 1);
            };
            if ($rawHogi !== [] && $isAssoc($rawHogi)) {
                $rawHogi = [$rawHogi];
            }

            foreach ($rawHogi as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pjtAttr = trim((string)($row['pjt_attr'] ?? $row['attribute'] ?? $row['name'] ?? ''));
                $optionVal = trim((string)($row['option_val'] ?? $row['option_value'] ?? $row['value'] ?? ''));
                $charMatch = trim((string)($row['char_match'] ?? $row['match_char'] ?? ''));
                $sortOrder = (int)($row['sort_order'] ?? $row['sort'] ?? ($i + 1));
                if ($sortOrder <= 0) {
                    $sortOrder = $i + 1;
                }
                if ($pjtAttr === '' && $optionVal === '' && $charMatch === '') {
                    continue;
                }
                $hogiRows[] = [
                    'pjt_attr' => $pjtAttr,
                    'option_val' => $optionVal,
                    'char_match' => $charMatch,
                    'sort_order' => $sortOrder,
                ];
            }
        }

        if (array_key_exists('card_number', $_POST)) {
            $card = trim((string)$_POST['card_number']);
            if ($card !== '') {
                $dup = $findByCard($db, $columnExists, $cardCol, $card, $idx);
                if ($dup !== null) {
                    ApiResponse::error('DUPLICATE_CARD_NUMBER', '이미 등록된 사업자번호입니다', 409, ['existing' => $dup]);
                    exit;
                }
            }
        }

        if (array_key_exists('head_idx', $_POST) && $columnExists($db, 'Tb_Members', 'head_idx')) {
            $headIdx = FmsInputValidator::int($_POST, 'head_idx', false, 1);
            $validateHeadIdx($db, $tableExists, $columnExists, $headIdx);
            $setSql[] = 'head_idx = ?';
            $params[] = $headIdx;
        }

        if ((array_key_exists('member_status', $_POST) || array_key_exists('status', $_POST)) && $columnExists($db, 'Tb_Members', $statusCol)) {
            $nextStatus = trim((string)($_POST['member_status'] ?? $_POST['status'] ?? ''));
            $nextStatus = FmsInputValidator::oneOf($nextStatus, 'member_status', ['예정', '운영', '중지', '종료'], false);

            $prevStatus = trim((string)($current[$statusCol] ?? ''));
            if (!$statusTransitionAllowed($prevStatus, $nextStatus)) {
                ApiResponse::error('BUSINESS_RULE_VIOLATION', '상태 전환이 허용되지 않습니다', 409, [
                    'from' => $prevStatus,
                    'to' => $nextStatus,
                ]);
                exit;
            }

            $setSql[] = $statusCol . ' = ?';
            $params[] = $nextStatus;
        }

        if ($columnExists($db, 'Tb_Members', 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_Members', 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        $memberUpdateNeeded = ($setSql !== []);
        if (!$memberUpdateNeeded && !$hogiListRequested) {
            ApiResponse::error('INVALID_PARAM', 'no updatable fields provided', 422);
            exit;
        }

        $where = ['idx = ?'];
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $updateParams = $params;
        $updateParams[] = $idx;

        try {
            $db->beginTransaction();
            if ($memberUpdateNeeded) {
                $stmt = $db->prepare('UPDATE Tb_Members SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
                $stmt->execute($updateParams);
            }

            $hogiSyncResult = [
                'supported' => false,
                'deleted_count' => 0,
                'saved_count' => 0,
            ];
            if ($hogiListRequested) {
                $hogiSyncResult = $syncMemberHogiRows(
                    $db,
                    $tableExists,
                    $columnExists,
                    $firstExistingColumn,
                    $memberHogiTable,
                    $idx,
                    $hogiRows,
                    $serviceCode,
                    $tenantId,
                    (int)($context['user_pk'] ?? 0)
                );
            }

            $db->commit();

            $row = $loadMember($db, $tableExists, $columnExists, $idx);
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'update', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_idx' => $idx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'member updated',
                'hogi_sync_requested' => $hogiListRequested ? 1 : 0,
                'hogi_saved_count' => (int)($hogiSyncResult['saved_count'] ?? 0),
                'hogi_deleted_count' => (int)($hogiSyncResult['deleted_count'] ?? 0),
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'update', $idx, [
                'member_idx' => $idx,
                'hogi_sync_requested' => $hogiListRequested ? 1 : 0,
                'hogi_saved_count' => (int)($hogiSyncResult['saved_count'] ?? 0),
                'hogi_deleted_count' => (int)($hogiSyncResult['deleted_count'] ?? 0),
            ]);

            ApiResponse::success([
                'idx' => $idx,
                'item' => $row,
                'hogi_sync' => $hogiSyncResult,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '사업장 수정 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'member_inline_update') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }

        $idx = (int)($_POST['idx'] ?? $_GET['idx'] ?? 0);
        $field = strtolower(trim((string)($_POST['field'] ?? $_POST['column'] ?? '')));
        $rawValue = $_POST['value'] ?? null;

        if ($idx <= 0 || $field === '' || $rawValue === null) {
            ApiResponse::error('INVALID_PARAM', 'idx/field/value is required', 422);
            exit;
        }

        $current = $loadMember($db, $tableExists, $columnExists, $idx);
        if ($current === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $fieldMap = [
            'name' => [$nameCol, 'string', 120],
            'member_name' => [$nameCol, 'string', 120],
            'branch_name' => [$nameCol, 'string', 120],
            'ceo' => ['ceo', 'string', 80],
            'card_number' => [$cardCol, 'biz', 20],
            'tel' => ['tel', 'phone', 40],
            'email' => ['email', 'email', 120],
            'address' => ['address', 'string', 255],
            'address_detail' => ['address_detail', 'string', 255],
            'zipcode' => ['zipcode', 'string', 20],
            'head_idx' => ['head_idx', 'int_nullable', 0],
            'member_status' => [$statusCol, 'status', 20],
            'status' => [$statusCol, 'status', 20],
            'memo' => ['memo', 'string', 2000],
        ];

        if (!isset($fieldMap[$field])) {
            ApiResponse::error('INVALID_PARAM', 'unsupported field: ' . $field, 422);
            exit;
        }

        [$column, $type, $maxLen] = $fieldMap[$field];
        if (!$columnExists($db, 'Tb_Members', $column)) {
            ApiResponse::error('INVALID_PARAM', 'field is not available in schema', 422);
            exit;
        }

        $value = null;
        if ($type === 'string') {
            $value = FmsInputValidator::string(['v' => $rawValue], 'v', (int)$maxLen, false);
            if ($column === $nameCol && $value === '') {
                ApiResponse::error('INVALID_PARAM', 'name cannot be empty', 422);
                exit;
            }
        } elseif ($type === 'biz') {
            $value = FmsInputValidator::bizNumber((string)$rawValue, 'card_number', true);
            if ($value !== '') {
                $dup = $findByCard($db, $columnExists, $cardCol, $value, $idx);
                if ($dup !== null) {
                    ApiResponse::error('DUPLICATE_CARD_NUMBER', '이미 등록된 사업자번호입니다', 409, ['existing' => $dup]);
                    exit;
                }
            }
        } elseif ($type === 'phone') {
            $value = FmsInputValidator::phone((string)$rawValue, 'tel', true);
        } elseif ($type === 'email') {
            $value = FmsInputValidator::email((string)$rawValue, 'email', true);
        } elseif ($type === 'int_nullable') {
            $value = FmsInputValidator::int(['v' => $rawValue], 'v', false, 1);
            $validateHeadIdx($db, $tableExists, $columnExists, $value);
        } elseif ($type === 'status') {
            $next = FmsInputValidator::oneOf(trim((string)$rawValue), 'member_status', ['예정', '운영', '중지', '종료'], false);
            $prev = trim((string)($current[$statusCol] ?? ''));
            if (!$statusTransitionAllowed($prev, $next)) {
                ApiResponse::error('BUSINESS_RULE_VIOLATION', '상태 전환이 허용되지 않습니다', 409, ['from' => $prev, 'to' => $next]);
                exit;
            }
            $value = $next;
        }

        $setSql = [$column . ' = ?'];
        $params = [$value];

        if ($columnExists($db, 'Tb_Members', 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_Members', 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        $where = ['idx = ?'];
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $params[] = $idx;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE Tb_Members SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $db->commit();

            $row = $loadMember($db, $tableExists, $columnExists, $idx);
            $shadowId = $enqueueShadow($db, $security, $context, 'member_inline_update', $idx, ['field' => $field]);

            ApiResponse::success([
                'idx' => $idx,
                'field' => $field,
                'column' => $column,
                'value' => $value,
                'item' => $row,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '사업장 인라인 수정 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'inline_update_contact') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$phoneBookExists) {
            ApiResponse::error('SCHEMA_NOT_READY', $phoneBookTable . ' table is missing', 503);
            exit;
        }

        $contactIdx = (int)($_POST['contact_idx'] ?? $_POST['idx'] ?? 0);
        $field = strtolower(trim((string)($_POST['field'] ?? '')));
        $rawValue = $_POST['value'] ?? null;
        if ($contactIdx <= 0 || $field === '' || $rawValue === null) {
            ApiResponse::error('INVALID_PARAM', 'contact_idx/field/value is required', 422);
            exit;
        }

        $fieldMap = [];
        if ($phoneJobGradeCol !== null) { $fieldMap['job_grade'] = [$phoneJobGradeCol, 'string', 50]; }
        if ($phoneJobTitleCol !== null) { $fieldMap['job_title'] = [$phoneJobTitleCol, 'string', 50]; }
        if ($phoneHpCol !== null) { $fieldMap['hp'] = [$phoneHpCol, 'phone', 40]; }
        if ($phoneEmailCol !== null) { $fieldMap['email'] = [$phoneEmailCol, 'email', 200]; }
        if ($phoneWorkStatusCol !== null) { $fieldMap['work_status'] = [$phoneWorkStatusCol, 'string', 20]; }
        if ($phoneMainWorkCol !== null) { $fieldMap['main_work'] = [$phoneMainWorkCol, 'string', 200]; }
        if ($phoneNameCol !== null) { $fieldMap['name'] = [$phoneNameCol, 'string_required', 120]; }

        if (!isset($fieldMap[$field])) {
            ApiResponse::error('INVALID_PARAM', 'unsupported field: ' . $field, 422);
            exit;
        }

        $contactRow = $loadPhoneBookContact($db, $tableExists, $columnExists, $phoneBookTable, $contactIdx, $phoneIsDeletedCol);
        if ($contactRow === null) {
            ApiResponse::error('NOT_FOUND', '연락처를 찾을 수 없습니다', 404);
            exit;
        }

        [$column, $type, $maxLen] = $fieldMap[$field];
        $value = null;
        if ($type === 'string') {
            $value = FmsInputValidator::string(['v' => $rawValue], 'v', (int)$maxLen, false);
        } elseif ($type === 'string_required') {
            $value = FmsInputValidator::string(['v' => $rawValue], 'v', (int)$maxLen, true);
        } elseif ($type === 'phone') {
            $value = FmsInputValidator::phone((string)$rawValue, 'hp', true);
        } elseif ($type === 'email') {
            $value = FmsInputValidator::email((string)$rawValue, 'email', true);
        }

        $setSql = [$column . ' = ?'];
        $params = [$value];
        if ($phoneUpdatedAtCol !== null) {
            $setSql[] = $phoneUpdatedAtCol . ' = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($phoneUpdatedByCol !== null) {
            $setSql[] = $phoneUpdatedByCol . ' = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        $where = ['idx = ?'];
        if ($phoneIsDeletedCol !== null) {
            $where[] = 'ISNULL(' . $phoneIsDeletedCol . ',0)=0';
        }
        $params[] = $contactIdx;

        $stmt = $db->prepare('UPDATE ' . $phoneBookTable . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);

        $updatedRow = $loadPhoneBookContact($db, $tableExists, $columnExists, $phoneBookTable, $contactIdx, $phoneIsDeletedCol);
        $writeSvcAuditLog($db, $tableExists, $columnExists, 'inline_update_contact', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_idx' => $contactIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'phonebook inline field updated',
            'field' => $field,
        ], $phoneBookTable);
        $shadowId = $enqueueShadow($db, $security, $context, 'inline_update_contact', $contactIdx, [
            'contact_idx' => $contactIdx,
            'field' => $field,
        ], $phoneBookTable);

        ApiResponse::success([
            'contact_idx' => $contactIdx,
            'field' => $field,
            'column' => $column,
            'value' => $value,
            'item' => $updatedRow,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '연락처 인라인 수정 성공');
        exit;
    }

    if ($todo === 'assign_contact_folder') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$phoneBookExists) {
            ApiResponse::error('SCHEMA_NOT_READY', $phoneBookTable . ' table is missing', 503);
            exit;
        }
        if ($phoneBranchFolderCol === null) {
            ApiResponse::error('SCHEMA_NOT_READY', $phoneBookTable . '.branch_folder_idx column is missing', 503);
            exit;
        }

        $contactIdx = (int)($_POST['contact_idx'] ?? $_POST['idx'] ?? 0);
        $folderIdx = FmsInputValidator::int($_POST, 'folder_idx', false, 0);
        if ($contactIdx <= 0 || $folderIdx === null) {
            ApiResponse::error('INVALID_PARAM', 'contact_idx and folder_idx are required', 422);
            exit;
        }
        $folderIdx = max(0, (int)$folderIdx);

        $contactRow = $loadPhoneBookContact($db, $tableExists, $columnExists, $phoneBookTable, $contactIdx, $phoneIsDeletedCol);
        if ($contactRow === null) {
            ApiResponse::error('NOT_FOUND', '연락처를 찾을 수 없습니다', 404);
            exit;
        }

        if ($folderIdx > 0) {
            $folderRow = $loadHeadOrgFolder($db, $tableExists, $columnExists, $headOrgTable, $folderIdx);
            if ($folderRow === null) {
                ApiResponse::error('INVALID_PARAM', 'folder_idx is invalid', 422, ['folder_idx' => $folderIdx]);
                exit;
            }

            if ($phoneMemberCol !== null && $columnExists($db, 'Tb_Members', 'head_idx')) {
                $memberIdx = (int)($contactRow[$phoneMemberCol] ?? 0);
                if ($memberIdx > 0) {
                    $memberWhere = ['idx = ?'];
                    if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
                        $memberWhere[] = 'ISNULL(is_deleted,0)=0';
                    }
                    $mStmt = $db->prepare('SELECT head_idx FROM Tb_Members WHERE ' . implode(' AND ', $memberWhere));
                    $mStmt->execute([$memberIdx]);
                    $memberHeadIdx = (int)$mStmt->fetchColumn();
                    $folderHeadIdx = (int)($folderRow['head_idx'] ?? 0);
                    if ($memberHeadIdx > 0 && $folderHeadIdx > 0 && $memberHeadIdx !== $folderHeadIdx) {
                        ApiResponse::error('BUSINESS_RULE_VIOLATION', '연락처 사업장과 폴더 본사가 일치하지 않습니다', 409, [
                            'member_head_idx' => $memberHeadIdx,
                            'folder_head_idx' => $folderHeadIdx,
                        ]);
                        exit;
                    }
                }
            }
        }

        $setSql = [$phoneBranchFolderCol . ' = ?'];
        $params = [$folderIdx > 0 ? $folderIdx : null];
        if ($phoneUpdatedAtCol !== null) {
            $setSql[] = $phoneUpdatedAtCol . ' = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($phoneUpdatedByCol !== null) {
            $setSql[] = $phoneUpdatedByCol . ' = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }
        $params[] = $contactIdx;

        $where = ['idx = ?'];
        if ($phoneIsDeletedCol !== null) {
            $where[] = 'ISNULL(' . $phoneIsDeletedCol . ',0)=0';
        }
        $stmt = $db->prepare('UPDATE ' . $phoneBookTable . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);

        $updatedRow = $loadPhoneBookContact($db, $tableExists, $columnExists, $phoneBookTable, $contactIdx, $phoneIsDeletedCol);
        $writeSvcAuditLog($db, $tableExists, $columnExists, 'assign_contact_folder', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_idx' => $contactIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'phonebook folder assigned',
            'folder_idx' => $folderIdx,
        ], $phoneBookTable);
        $shadowId = $enqueueShadow($db, $security, $context, 'assign_contact_folder', $contactIdx, [
            'contact_idx' => $contactIdx,
            'folder_idx' => $folderIdx,
        ], $phoneBookTable);

        ApiResponse::success([
            'contact_idx' => $contactIdx,
            'folder_idx' => $folderIdx,
            'item' => $updatedRow,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '연락처 폴더 지정 성공');
        exit;
    }

    if ($todo === 'move_contact') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$phoneBookExists) {
            ApiResponse::error('SCHEMA_NOT_READY', $phoneBookTable . ' table is missing', 503);
            exit;
        }
        if ($phoneMemberCol === null) {
            ApiResponse::error('SCHEMA_NOT_READY', $phoneBookTable . '.member_idx column is missing', 503);
            exit;
        }
        if ($phoneBranchFolderCol === null) {
            ApiResponse::error('SCHEMA_NOT_READY', $phoneBookTable . '.branch_folder_idx column is missing', 503);
            exit;
        }

        $contactIdx = (int)($_POST['contact_idx'] ?? $_POST['idx'] ?? 0);
        $targetMemberIdx = FmsInputValidator::int($_POST, 'target_member_idx', true, 1);
        $targetFolderIdx = FmsInputValidator::int($_POST, 'target_folder_idx', false, 0);
        $targetFolderIdx = max(0, (int)($targetFolderIdx ?? 0));
        if ($contactIdx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'contact_idx is required', 422);
            exit;
        }

        $contactRow = $loadPhoneBookContact($db, $tableExists, $columnExists, $phoneBookTable, $contactIdx, $phoneIsDeletedCol);
        if ($contactRow === null) {
            ApiResponse::error('NOT_FOUND', '연락처를 찾을 수 없습니다', 404);
            exit;
        }

        $memberWhere = ['idx = ?'];
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $memberWhere[] = 'ISNULL(is_deleted,0)=0';
        }
        $memberSelect = 'idx';
        if ($columnExists($db, 'Tb_Members', 'head_idx')) {
            $memberSelect .= ', head_idx';
        }
        $mStmt = $db->prepare('SELECT ' . $memberSelect . ' FROM Tb_Members WHERE ' . implode(' AND ', $memberWhere));
        $mStmt->execute([(int)$targetMemberIdx]);
        $targetMemberRow = $mStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($targetMemberRow)) {
            ApiResponse::error('INVALID_PARAM', 'target_member_idx is invalid', 422, ['target_member_idx' => (int)$targetMemberIdx]);
            exit;
        }

        if ($targetFolderIdx > 0) {
            $folderRow = $loadHeadOrgFolder($db, $tableExists, $columnExists, $headOrgTable, $targetFolderIdx);
            if ($folderRow === null) {
                ApiResponse::error('INVALID_PARAM', 'target_folder_idx is invalid', 422, ['target_folder_idx' => $targetFolderIdx]);
                exit;
            }
            $memberHeadIdx = (int)($targetMemberRow['head_idx'] ?? 0);
            $folderHeadIdx = (int)($folderRow['head_idx'] ?? 0);
            if ($memberHeadIdx > 0 && $folderHeadIdx > 0 && $memberHeadIdx !== $folderHeadIdx) {
                ApiResponse::error('BUSINESS_RULE_VIOLATION', '대상 사업장 본사와 폴더 본사가 일치하지 않습니다', 409, [
                    'member_head_idx' => $memberHeadIdx,
                    'folder_head_idx' => $folderHeadIdx,
                ]);
                exit;
            }
        }

        $setSql = [
            $phoneMemberCol . ' = ?',
            $phoneBranchFolderCol . ' = ?',
        ];
        $params = [
            (int)$targetMemberIdx,
            $targetFolderIdx > 0 ? $targetFolderIdx : null,
        ];
        if ($phoneUpdatedAtCol !== null) {
            $setSql[] = $phoneUpdatedAtCol . ' = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($phoneUpdatedByCol !== null) {
            $setSql[] = $phoneUpdatedByCol . ' = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }
        $params[] = $contactIdx;

        $where = ['idx = ?'];
        if ($phoneIsDeletedCol !== null) {
            $where[] = 'ISNULL(' . $phoneIsDeletedCol . ',0)=0';
        }
        $stmt = $db->prepare('UPDATE ' . $phoneBookTable . ' SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);

        $updatedRow = $loadPhoneBookContact($db, $tableExists, $columnExists, $phoneBookTable, $contactIdx, $phoneIsDeletedCol);
        $writeSvcAuditLog($db, $tableExists, $columnExists, 'move_contact', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_idx' => $contactIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'message' => 'phonebook contact moved',
            'target_member_idx' => (int)$targetMemberIdx,
            'target_folder_idx' => $targetFolderIdx,
        ], $phoneBookTable);
        $shadowId = $enqueueShadow($db, $security, $context, 'move_contact', $contactIdx, [
            'contact_idx' => $contactIdx,
            'target_member_idx' => (int)$targetMemberIdx,
            'target_folder_idx' => $targetFolderIdx,
        ], $phoneBookTable);

        ApiResponse::success([
            'contact_idx' => $contactIdx,
            'target_member_idx' => (int)$targetMemberIdx,
            'target_folder_idx' => $targetFolderIdx,
            'item' => $updatedRow,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '연락처 이동 성공');
        exit;
    }

    if ($todo === 'link_head') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, 'Tb_HeadOffice')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_HeadOffice table is missing', 503);
            exit;
        }
        if (!$columnExists($db, 'Tb_Members', 'head_idx')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members.head_idx column is missing', 503);
            exit;
        }
        if (!$columnExists($db, 'Tb_Members', 'link_status')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members.link_status column is missing', 503);
            exit;
        }

        $memberIdx = FmsInputValidator::int($_POST, 'member_idx', true, 1);
        $headIdx = FmsInputValidator::int($_POST, 'head_idx', true, 1);

        $memberRow = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
        if ($memberRow === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }
        $validateHeadIdx($db, $tableExists, $columnExists, $headIdx);

        $setSql = ['head_idx = ?', 'link_status = ?'];
        $params = [(int)$headIdx, '요청'];
        if ($columnExists($db, 'Tb_Members', 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_Members', 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        $where = ['idx = ?'];
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $params[] = (int)$memberIdx;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE Tb_Members SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $db->commit();

            $row = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'link_head', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_idx' => (int)$memberIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'member linked to head office',
                'head_idx' => (int)$headIdx,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'link_head', (int)$memberIdx, [
                'member_idx' => (int)$memberIdx,
                'head_idx' => (int)$headIdx,
                'link_status' => '요청',
            ]);

            ApiResponse::success([
                'member_idx' => (int)$memberIdx,
                'head_idx' => (int)$headIdx,
                'link_status' => '요청',
                'item' => $row,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '본사 연결 요청 처리 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'unlink_head') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$columnExists($db, 'Tb_Members', 'head_idx')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members.head_idx column is missing', 503);
            exit;
        }
        if (!$columnExists($db, 'Tb_Members', 'link_status')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members.link_status column is missing', 503);
            exit;
        }

        $memberIdx = FmsInputValidator::int($_POST, 'member_idx', true, 1);
        $memberRow = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
        if ($memberRow === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = ['head_idx = 0', 'link_status = NULL'];
        $params = [];
        if ($columnExists($db, 'Tb_Members', 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_Members', 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        $where = ['idx = ?'];
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $params[] = (int)$memberIdx;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE Tb_Members SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $db->commit();

            $row = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'unlink_head', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_idx' => (int)$memberIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'member unlinked from head office',
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'unlink_head', (int)$memberIdx, [
                'member_idx' => (int)$memberIdx,
                'head_idx' => 0,
                'link_status' => null,
            ]);

            ApiResponse::success([
                'member_idx' => (int)$memberIdx,
                'head_idx' => 0,
                'link_status' => null,
                'item' => $row,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '본사 연결 해제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'update_link_status') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$columnExists($db, 'Tb_Members', 'link_status')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members.link_status column is missing', 503);
            exit;
        }

        $memberIdx = FmsInputValidator::int($_POST, 'member_idx', true, 1);
        $linkStatus = FmsInputValidator::oneOf(
            trim((string)($_POST['link_status'] ?? '')),
            'link_status',
            ['요청', '연결', '중단'],
            false
        );

        $memberRow = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
        if ($memberRow === null) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = ['link_status = ?'];
        $params = [$linkStatus];
        if ($columnExists($db, 'Tb_Members', 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_Members', 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        $where = ['idx = ?'];
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $params[] = (int)$memberIdx;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE Tb_Members SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $db->commit();

            $row = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'update_link_status', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_idx' => (int)$memberIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'message' => 'member link status updated',
                'link_status' => $linkStatus,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'update_link_status', (int)$memberIdx, [
                'member_idx' => (int)$memberIdx,
                'link_status' => $linkStatus,
            ]);

            ApiResponse::success([
                'member_idx' => (int)$memberIdx,
                'link_status' => $linkStatus,
                'item' => $row,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '연결 상태 변경 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'member_delete') {
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

            $mode = 'hard';
            $deleted = 0;
            if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
                $mode = 'soft';
                $setSql = ['is_deleted = 1'];
                $params = [];

                if ($columnExists($db, 'Tb_Members', 'deleted_at')) {
                    $setSql[] = 'deleted_at = GETDATE()';
                }
                if ($columnExists($db, 'Tb_Members', 'deleted_by')) {
                    $setSql[] = 'deleted_by = ?';
                    $params[] = (int)($context['user_pk'] ?? 0);
                }

                $params = array_merge($params, $idxList);
                $stmt = $db->prepare('UPDATE Tb_Members SET ' . implode(', ', $setSql) . " WHERE idx IN ({$ph})");
                $stmt->execute($params);
                $deleted = (int)$stmt->rowCount();
            } else {
                $stmt = $db->prepare("DELETE FROM Tb_Members WHERE idx IN ({$ph})");
                $stmt->execute($idxList);
                $deleted = (int)$stmt->rowCount();
            }

            $db->commit();

            $shadowId = $enqueueShadow($db, $security, $context, 'member_delete', $idxList[0] ?? 0, ['idx_list' => $idxList]);

            ApiResponse::success([
                'idx_list' => $idxList,
                'requested_count' => count($idxList),
                'deleted_count' => $deleted,
                'delete_mode' => $mode,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '사업장 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'restore') {
        if ($roleLevel < 1 || $roleLevel > 4) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, ['required' => 4, 'current' => $roleLevel]);
            exit;
        }
        if (!$columnExists($db, 'Tb_Members', 'is_deleted')) {
            ApiResponse::error('UNSUPPORTED', 'restore is unavailable on hard-delete schema', 409);
            exit;
        }

        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['idx'] ?? ''));
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list is required', 422);
            exit;
        }

        $ph = implode(',', array_fill(0, count($idxList), '?'));
        $setSql = ['is_deleted = 0'];
        if ($columnExists($db, 'Tb_Members', 'deleted_at')) { $setSql[] = 'deleted_at = NULL'; }
        if ($columnExists($db, 'Tb_Members', 'deleted_by')) { $setSql[] = 'deleted_by = NULL'; }
        if ($columnExists($db, 'Tb_Members', 'updated_at')) { $setSql[] = 'updated_at = GETDATE()'; }

        $stmt = $db->prepare('UPDATE Tb_Members SET ' . implode(', ', $setSql) . " WHERE idx IN ({$ph})");
        $stmt->execute($idxList);
        $restored = (int)$stmt->rowCount();

        $shadowId = $enqueueShadow($db, $security, $context, 'restore', $idxList[0] ?? 0, ['idx_list' => $idxList]);

        ApiResponse::success([
            'idx_list' => $idxList,
            'restored_count' => $restored,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '사업장 복구 성공');
        exit;
    }

    if ($todo === 'member_bulk_action') {
        if ($roleLevel < 1 || $roleLevel > 4) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, ['required' => 4, 'current' => $roleLevel]);
            exit;
        }

        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? '');
        if ($action === '' || $idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'action and idx_list are required', 422);
            exit;
        }

        if ($action === 'delete') {
            $_POST['todo'] = 'member_delete';
            $todo = 'member_delete';
        } elseif ($action === 'restore') {
            $_POST['todo'] = 'restore';
            $todo = 'restore';
        } else {
            ApiResponse::error('INVALID_PARAM', 'unsupported bulk action', 422, ['action' => $action]);
            exit;
        }

        ApiResponse::success([
            'action' => $action,
            'idx_list' => $idxList,
            'message' => 'member_bulk_action은 member_delete/restore를 사용하세요',
        ], 'OK', 'bulk action routed');
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
