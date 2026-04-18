<?php
declare(strict_types=1);

final class GroupwareService
{
    private PDO $db;
    private array $security;
    private AuditLogger $audit;

    private array $tableExistsCache = [];
    private array $columnExistsCache = [];
    private array $userNameCache = [];

    private const TABLE_DEPARTMENT = 'Tb_GwDepartment';
    private const TABLE_EMPLOYEE = 'Tb_GwEmployee';
    private const TABLE_EMPLOYEE_CARD = 'Tb_EmployeeCard';
    private const TABLE_SETTINGS = 'Tb_GwSettings';
    private const TABLE_PHONEBOOK = 'Tb_GwPhoneBook';
    private const TABLE_ATTENDANCE = 'Tb_GwAttendance';
    private const TABLE_HOLIDAY = 'Tb_GwHoliday';
    private const TABLE_OVERTIME = 'Tb_GwOvertime';

    private const TABLE_CHAT_ROOM = 'Tb_GwChatRoom';
    private const TABLE_CHAT_MEMBER = 'Tb_GwChatRoomMember';
    private const TABLE_CHAT_MESSAGE = 'Tb_GwChatMessage';

    private const TABLE_APPROVAL_DOC = 'Tb_GwApprovalDoc';
    private const TABLE_APPROVAL_LINE = 'Tb_GwApprovalLine';
    private const TABLE_APPROVAL_COMMENT = 'Tb_GwApprovalComment';
    private const ROLE_APPROVER_MAX_IDX = 3;

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $this->security = $security;
        $this->audit = new AuditLogger($db, $security);
    }

    public function resolveScope(array $context, string $serviceCode = '', int $tenantId = 0): array
    {
        $roleLevel = (int)($context['role_level'] ?? 0);

        $resolvedServiceCode = trim($serviceCode);
        if ($resolvedServiceCode === '' || $roleLevel < 1 || $roleLevel > 5) {
            $resolvedServiceCode = trim((string)($context['service_code'] ?? 'shvq'));
            if ($resolvedServiceCode === '') {
                $resolvedServiceCode = 'shvq';
            }
        }

        $resolvedTenantId = $tenantId;
        if ($resolvedTenantId <= 0 || $roleLevel < 1 || $roleLevel > 4) {
            $resolvedTenantId = (int)($context['tenant_id'] ?? 0);
        }

        return [
            'service_code' => $resolvedServiceCode,
            'tenant_id' => max(0, $resolvedTenantId),
        ];
    }

    public function requiredTablesByDomain(string $domain): array
    {
        $domain = strtolower(trim($domain));

        if ($domain === 'employee' || $domain === 'hr') {
            return [
                self::TABLE_DEPARTMENT,
                self::TABLE_EMPLOYEE,
                self::TABLE_EMPLOYEE_CARD,
                self::TABLE_SETTINGS,
                self::TABLE_PHONEBOOK,
                self::TABLE_ATTENDANCE,
                self::TABLE_HOLIDAY,
                self::TABLE_OVERTIME,
            ];
        }

        if ($domain === 'chat') {
            return [
                self::TABLE_CHAT_ROOM,
                self::TABLE_CHAT_MEMBER,
                self::TABLE_CHAT_MESSAGE,
            ];
        }

        if ($domain === 'approval') {
            return [
                self::TABLE_APPROVAL_DOC,
                self::TABLE_APPROVAL_LINE,
                self::TABLE_APPROVAL_COMMENT,
            ];
        }

        return [
            self::TABLE_DEPARTMENT,
            self::TABLE_EMPLOYEE,
            self::TABLE_EMPLOYEE_CARD,
            self::TABLE_SETTINGS,
            self::TABLE_PHONEBOOK,
            self::TABLE_ATTENDANCE,
            self::TABLE_HOLIDAY,
            self::TABLE_OVERTIME,
            self::TABLE_CHAT_ROOM,
            self::TABLE_CHAT_MEMBER,
            self::TABLE_CHAT_MESSAGE,
            self::TABLE_APPROVAL_DOC,
            self::TABLE_APPROVAL_LINE,
            self::TABLE_APPROVAL_COMMENT,
        ];
    }

    public function missingTables(array $tables): array
    {
        $missing = [];
        foreach ($tables as $table) {
            if (!is_string($table) || trim($table) === '') {
                continue;
            }
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }

        return array_values(array_unique($missing));
    }

    public function dashboardSummary(array $scope, int $userPk): array
    {
        $counts = [
            'department_count' => $this->countRows(self::TABLE_DEPARTMENT, $scope, 'ISNULL(is_deleted, 0) = 0'),
            'employee_count' => $this->countRows(self::TABLE_EMPLOYEE, $scope, 'ISNULL(is_deleted, 0) = 0'),
            'phonebook_count' => $this->countRows(self::TABLE_PHONEBOOK, $scope, 'ISNULL(is_deleted, 0) = 0'),
            'attendance_today_count' => $this->countRows(
                self::TABLE_ATTENDANCE,
                $scope,
                'work_date = CAST(GETDATE() AS DATE)'
            ),
            'holiday_requested_count' => $this->countRows(
                self::TABLE_HOLIDAY,
                $scope,
                "ISNULL(is_deleted, 0) = 0 AND status = 'REQUESTED'"
            ),
            'overtime_requested_count' => $this->countRows(
                self::TABLE_OVERTIME,
                $scope,
                "ISNULL(is_deleted, 0) = 0 AND status = 'REQUESTED'"
            ),
            'chat_room_count' => $this->countRows(self::TABLE_CHAT_ROOM, $scope, 'ISNULL(is_deleted, 0) = 0'),
            'approval_submitted_count' => $this->countRows(
                self::TABLE_APPROVAL_DOC,
                $scope,
                "ISNULL(is_deleted, 0) = 0 AND status = 'SUBMITTED'"
            ),
            'approval_completed_count' => $this->countRows(
                self::TABLE_APPROVAL_DOC,
                $scope,
                "ISNULL(is_deleted, 0) = 0 AND status IN ('APPROVED', 'REJECTED', 'CANCELED')"
            ),
        ];

        $counts['chat_unread_count'] = $this->chatUnreadCount($scope, $userPk);
        $counts['approval_pending_my_count'] = $this->approvalPendingMyCount($scope, $userPk);

        return [
            'scope' => $scope,
            'counts' => $counts,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function listDepartments(array $scope): array
    {
        if (!$this->tableExists(self::TABLE_DEPARTMENT)) {
            return [];
        }

        $sql = "SELECT
                    idx,
                    ISNULL(parent_idx, 0) AS parent_idx,
                    ISNULL(dept_code, '') AS dept_code,
                    ISNULL(dept_name, '') AS dept_name,
                    ISNULL(dept_type, '') AS dept_type,
                    ISNULL(address, '') AS address,
                    ISNULL(tel, '') AS tel,
                    ISNULL(depth, 0) AS depth,
                    ISNULL(sort_order, 0) AS sort_order,
                    CAST(ISNULL(is_active, 1) AS INT) AS is_active,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                WHERE service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0
                ORDER BY ISNULL(sort_order, 0) ASC, idx ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function saveDepartment(array $scope, array $payload, int $actorUserPk): array
    {
        $deptId = (int)($payload['idx'] ?? $payload['dept_id'] ?? 0);
        $parentIdx = (int)($payload['parent_idx'] ?? 0);
        $deptCode = trim((string)($payload['dept_code'] ?? ''));
        $deptName = trim((string)($payload['dept_name'] ?? ''));
        $deptType = trim((string)($payload['dept_type'] ?? ''));
        $address = trim((string)($payload['address'] ?? ''));
        $tel = trim((string)($payload['tel'] ?? ''));
        $sortOrder = (int)($payload['sort_order'] ?? 0);
        $depthInput = $this->normalizeNullableInt($payload['depth'] ?? null);
        $isActive = $this->toBoolInt($payload['is_active'] ?? 1);

        if ($deptName === '') {
            throw new InvalidArgumentException('dept_name is required');
        }

        if ($deptId > 0 && $parentIdx > 0 && $deptId === $parentIdx) {
            throw new InvalidArgumentException('parent_idx cannot be self');
        }

        if ($parentIdx > 0 && $this->getDepartmentById($scope, $parentIdx) === null) {
            throw new RuntimeException('parent department not found');
        }

        $depth = $depthInput !== null
            ? max(0, $depthInput)
            : $this->resolveDepartmentDepth($scope, $parentIdx);

        if ($deptId > 0) {
            $sql = "UPDATE dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                    SET parent_idx = ?,
                        dept_code = ?,
                        dept_name = ?,
                        dept_type = ?,
                        address = ?,
                        tel = ?,
                        depth = ?,
                        sort_order = ?,
                        is_active = ?,
                        updated_by = ?,
                        updated_at = GETDATE()
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $parentIdx > 0 ? $parentIdx : null,
                $deptCode,
                $deptName,
                $deptType,
                $address,
                $tel,
                $depth,
                $sortOrder,
                $isActive,
                $actorUserPk > 0 ? $actorUserPk : null,
                $deptId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('department not found');
            }

            $this->audit->log('groupware.department.update', $actorUserPk, 'OK', 'Department updated', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'dept_id' => $deptId,
            ]);

            $saved = $this->getDepartmentById($scope, $deptId);
            if ($saved === null) {
                throw new RuntimeException('department reload failed');
            }

            return $saved;
        }

        $sql = "INSERT INTO dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                (service_code, tenant_id, parent_idx, dept_code, dept_name, dept_type, address, tel, depth, sort_order, is_active, created_by, updated_by, created_at, updated_at)
                OUTPUT INSERTED.idx
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $parentIdx > 0 ? $parentIdx : null,
            $deptCode,
            $deptName,
            $deptType,
            $address,
            $tel,
            $depth,
            $sortOrder,
            $isActive,
            $actorUserPk > 0 ? $actorUserPk : null,
            $actorUserPk > 0 ? $actorUserPk : null,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $newId = (int)($row['idx'] ?? 0);
        if ($newId <= 0) {
            throw new RuntimeException('department insert failed');
        }

        $this->audit->log('groupware.department.insert', $actorUserPk, 'OK', 'Department created', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'dept_id' => $newId,
        ]);

        $saved = $this->getDepartmentById($scope, $newId);
        if ($saved === null) {
            throw new RuntimeException('department reload failed');
        }

        return $saved;
    }

    public function deleteDepartment(array $scope, int $deptId, int $actorUserPk): bool
    {
        if ($deptId <= 0) {
            throw new InvalidArgumentException('dept_id is required');
        }

        try {
            $this->db->beginTransaction();

            $moveSql = "UPDATE dbo." . $this->qi(self::TABLE_EMPLOYEE) . "
                        SET dept_idx = NULL,
                            updated_by = ?,
                            updated_at = GETDATE()
                        WHERE service_code = ?
                          AND tenant_id = ?
                          AND dept_idx = ?
                          AND ISNULL(is_deleted, 0) = 0";
            $moveStmt = $this->db->prepare($moveSql);
            $moveStmt->execute([
                $actorUserPk > 0 ? $actorUserPk : null,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
                $deptId,
            ]);

            $sql = "UPDATE dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                    SET is_deleted = 1,
                        is_active = 0,
                        updated_by = ?,
                        updated_at = GETDATE()
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $actorUserPk > 0 ? $actorUserPk : null,
                $deptId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            if ($stmt->rowCount() <= 0) {
                $this->db->rollBack();
                return false;
            }

            $this->db->commit();

            $this->audit->log('groupware.department.delete', $actorUserPk, 'OK', 'Department deleted', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'dept_id' => $deptId,
            ]);

            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function reorderDepartments(array $scope, $orderedIds, int $actorUserPk, ?int $parentIdx = null): int
    {
        if (!$this->tableExists(self::TABLE_DEPARTMENT)) {
            throw new RuntimeException('department table not found');
        }

        $deptIds = $this->parseIntList($orderedIds);
        if ($deptIds === []) {
            throw new InvalidArgumentException('ordered_ids is required');
        }

        $parentFilterSql = '';
        $parentFilterParams = [];
        if ($parentIdx !== null) {
            $parentFilterSql = ' AND ISNULL(parent_idx, 0) = ?';
            $parentFilterParams[] = max(0, $parentIdx);
        }

        $updatedCount = 0;

        try {
            $this->db->beginTransaction();

            $sql = "UPDATE dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                    SET sort_order = ?,
                        updated_by = ?,
                        updated_at = GETDATE()
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0" . $parentFilterSql;

            $stmt = $this->db->prepare($sql);
            $order = 1;
            foreach ($deptIds as $deptId) {
                $params = [
                    $order,
                    $actorUserPk > 0 ? $actorUserPk : null,
                    $deptId,
                    (string)$scope['service_code'],
                    (int)$scope['tenant_id'],
                ];
                if ($parentFilterParams !== []) {
                    $params[] = $parentFilterParams[0];
                }
                $stmt->execute($params);
                $updatedCount += $stmt->rowCount();
                $order++;
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->audit->log('groupware.department.reorder', $actorUserPk, 'OK', 'Department reorder applied', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'parent_idx' => $parentIdx,
            'count' => $updatedCount,
        ]);

        return $updatedCount;
    }

    public function moveDepartment(array $scope, int $deptId, int $parentIdx, int $actorUserPk): array
    {
        if (!$this->tableExists(self::TABLE_DEPARTMENT)) {
            throw new RuntimeException('department table not found');
        }
        if ($deptId <= 0) {
            throw new InvalidArgumentException('dept_id is required');
        }
        if ($deptId === $parentIdx && $parentIdx > 0) {
            throw new InvalidArgumentException('parent_idx cannot be self');
        }

        $current = $this->getDepartmentById($scope, $deptId);
        if ($current === null) {
            throw new RuntimeException('department not found');
        }

        $parentDept = null;
        if ($parentIdx > 0) {
            $parentDept = $this->getDepartmentById($scope, $parentIdx);
            if ($parentDept === null) {
                throw new RuntimeException('parent department not found');
            }

            $cycleSql = "WITH Anc AS (
                            SELECT idx, ISNULL(parent_idx, 0) AS parent_idx
                            FROM dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                            WHERE idx = ?
                              AND service_code = ?
                              AND tenant_id = ?
                              AND ISNULL(is_deleted, 0) = 0
                            UNION ALL
                            SELECT p.idx, ISNULL(p.parent_idx, 0) AS parent_idx
                            FROM dbo." . $this->qi(self::TABLE_DEPARTMENT) . " p
                            INNER JOIN Anc a ON p.idx = a.parent_idx
                            WHERE p.service_code = ?
                              AND p.tenant_id = ?
                              AND ISNULL(p.is_deleted, 0) = 0
                              AND ISNULL(a.parent_idx, 0) > 0
                        )
                        SELECT COUNT(1) AS cnt
                        FROM Anc
                        WHERE idx = ?";
            $cycleStmt = $this->db->prepare($cycleSql);
            $cycleStmt->execute([
                $parentIdx,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
                $deptId,
            ]);
            $cycleCount = (int)$cycleStmt->fetchColumn();
            if ($cycleCount > 0) {
                throw new RuntimeException('invalid parent hierarchy');
            }
        }

        $oldDepth = (int)($current['depth'] ?? 0);
        $newDepth = $this->resolveDepartmentDepth($scope, $parentIdx);
        $depthDelta = $newDepth - $oldDepth;

        $oldParentIdx = (int)($current['parent_idx'] ?? 0);
        $newSortOrder = $oldParentIdx !== $parentIdx
            ? $this->nextDepartmentSortOrder($scope, $parentIdx)
            : (int)($current['sort_order'] ?? 0);

        try {
            $this->db->beginTransaction();

            $rootSql = "UPDATE dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                        SET parent_idx = ?,
                            depth = ?,
                            sort_order = ?,
                            updated_by = ?,
                            updated_at = GETDATE()
                        WHERE idx = ?
                          AND service_code = ?
                          AND tenant_id = ?
                          AND ISNULL(is_deleted, 0) = 0";
            $rootStmt = $this->db->prepare($rootSql);
            $rootStmt->execute([
                $parentIdx > 0 ? $parentIdx : null,
                $newDepth,
                $newSortOrder,
                $actorUserPk > 0 ? $actorUserPk : null,
                $deptId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            if ($rootStmt->rowCount() <= 0) {
                throw new RuntimeException('department not found');
            }

            if ($depthDelta !== 0) {
                $childSql = "WITH Desc AS (
                                SELECT idx
                                FROM dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                                WHERE parent_idx = ?
                                  AND service_code = ?
                                  AND tenant_id = ?
                                  AND ISNULL(is_deleted, 0) = 0
                                UNION ALL
                                SELECT c.idx
                                FROM dbo." . $this->qi(self::TABLE_DEPARTMENT) . " c
                                INNER JOIN Desc d ON c.parent_idx = d.idx
                                WHERE c.service_code = ?
                                  AND c.tenant_id = ?
                                  AND ISNULL(c.is_deleted, 0) = 0
                            )
                            UPDATE t
                            SET depth = ISNULL(t.depth, 0) + ?,
                                updated_by = ?,
                                updated_at = GETDATE()
                            FROM dbo." . $this->qi(self::TABLE_DEPARTMENT) . " t
                            INNER JOIN Desc d ON d.idx = t.idx
                            OPTION (MAXRECURSION 100)";
                $childStmt = $this->db->prepare($childSql);
                $childStmt->execute([
                    $deptId,
                    (string)$scope['service_code'],
                    (int)$scope['tenant_id'],
                    (string)$scope['service_code'],
                    (int)$scope['tenant_id'],
                    $depthDelta,
                    $actorUserPk > 0 ? $actorUserPk : null,
                ]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->audit->log('groupware.department.move', $actorUserPk, 'OK', 'Department moved', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'dept_id' => $deptId,
            'parent_idx' => $parentIdx,
            'depth' => $newDepth,
        ]);

        $saved = $this->getDepartmentById($scope, $deptId);
        if ($saved === null) {
            throw new RuntimeException('department reload failed');
        }

        return $saved;
    }

    public function updateDepartmentBranchInfo(array $scope, int $deptId, array $payload, int $actorUserPk): array
    {
        if ($deptId <= 0) {
            throw new InvalidArgumentException('dept_id is required');
        }
        if (!$this->tableExists(self::TABLE_DEPARTMENT)) {
            throw new RuntimeException('department table not found');
        }

        $deptType = trim((string)($payload['dept_type'] ?? ''));
        $address = trim((string)($payload['address'] ?? ''));
        $tel = trim((string)($payload['tel'] ?? ''));

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                SET dept_type = ?,
                    address = ?,
                    tel = ?,
                    updated_by = ?,
                    updated_at = GETDATE()
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $deptType,
            $address,
            $tel,
            $actorUserPk > 0 ? $actorUserPk : null,
            $deptId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        if ($stmt->rowCount() <= 0) {
            throw new RuntimeException('department not found');
        }

        $this->audit->log('groupware.department.branch_info.update', $actorUserPk, 'OK', 'Department branch info updated', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'dept_id' => $deptId,
        ]);

        $saved = $this->getDepartmentById($scope, $deptId);
        if ($saved === null) {
            throw new RuntimeException('department reload failed');
        }

        return $saved;
    }

    public function getSettings(array $scope, string $settingGroup = 'groupware'): array
    {
        if (!$this->tableExists(self::TABLE_SETTINGS)) {
            return [
                'setting_group' => $this->normalizeSettingGroup($settingGroup),
                'items' => [],
                'map' => [],
            ];
        }

        $settingGroup = $this->normalizeSettingGroup($settingGroup);

        $sql = "SELECT
                    idx,
                    ISNULL(setting_group, 'groupware') AS setting_group,
                    ISNULL(setting_key, '') AS setting_key,
                    ISNULL(setting_value, '') AS setting_value,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_SETTINGS) . "
                WHERE service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0
                  AND ISNULL(setting_group, 'groupware') = ?
                ORDER BY setting_key ASC, idx ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $settingGroup,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $map = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['setting_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $map[$key] = (string)($row['setting_value'] ?? '');
        }

        return [
            'setting_group' => $settingGroup,
            'items' => $rows,
            'map' => $map,
        ];
    }

    public function saveSettings(array $scope, array $payload, int $actorUserPk): array
    {
        if (!$this->tableExists(self::TABLE_SETTINGS)) {
            throw new RuntimeException('settings table not found');
        }

        $settingGroup = $this->normalizeSettingGroup((string)($payload['setting_group'] ?? 'groupware'));
        $pairs = [];

        if (array_key_exists('settings', $payload)) {
            $settingsValue = $payload['settings'];
            if (is_string($settingsValue)) {
                $decoded = json_decode(trim($settingsValue), true);
                if (is_array($decoded)) {
                    $settingsValue = $decoded;
                }
            }
            if (is_array($settingsValue)) {
                foreach ($settingsValue as $key => $value) {
                    $normalizedKey = trim((string)$key);
                    if ($normalizedKey === '') {
                        continue;
                    }
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    $pairs[$normalizedKey] = trim((string)$value);
                }
            }
        }

        $singleKey = trim((string)($payload['setting_key'] ?? ''));
        if ($singleKey !== '') {
            $singleValue = $payload['setting_value'] ?? '';
            if (is_array($singleValue)) {
                $singleValue = json_encode($singleValue, JSON_UNESCAPED_UNICODE);
            }
            $pairs[$singleKey] = trim((string)$singleValue);
        }

        if ($pairs === []) {
            throw new InvalidArgumentException('settings payload is required');
        }

        try {
            $this->db->beginTransaction();

            $updateSql = "UPDATE dbo." . $this->qi(self::TABLE_SETTINGS) . "
                          SET setting_value = ?,
                              is_deleted = 0,
                              updated_by = ?,
                              updated_at = GETDATE()
                          WHERE service_code = ?
                            AND tenant_id = ?
                            AND ISNULL(setting_group, 'groupware') = ?
                            AND setting_key = ?";
            $updateStmt = $this->db->prepare($updateSql);

            $insertSql = "INSERT INTO dbo." . $this->qi(self::TABLE_SETTINGS) . "
                          (service_code, tenant_id, setting_group, setting_key, setting_value, is_deleted, created_by, updated_by, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, 0, ?, ?, GETDATE(), GETDATE())";
            $insertStmt = $this->db->prepare($insertSql);

            foreach ($pairs as $settingKey => $settingValue) {
                $updateStmt->execute([
                    $settingValue,
                    $actorUserPk > 0 ? $actorUserPk : null,
                    (string)$scope['service_code'],
                    (int)$scope['tenant_id'],
                    $settingGroup,
                    $settingKey,
                ]);

                if ($updateStmt->rowCount() <= 0) {
                    $insertStmt->execute([
                        (string)$scope['service_code'],
                        (int)$scope['tenant_id'],
                        $settingGroup,
                        $settingKey,
                        $settingValue,
                        $actorUserPk > 0 ? $actorUserPk : null,
                        $actorUserPk > 0 ? $actorUserPk : null,
                    ]);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->audit->log('groupware.settings.save', $actorUserPk, 'OK', 'Groupware settings saved', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'setting_group' => $settingGroup,
            'keys' => array_values(array_keys($pairs)),
        ]);

        return $this->getSettings($scope, $settingGroup);
    }

    public function listEmployees(array $scope, array $filters = []): array
    {
        return $this->listEmployeesByHidden($scope, $filters, 0);
    }

    public function listHiddenEmployees(array $scope, array $filters = []): array
    {
        return $this->listEmployeesByHidden($scope, $filters, 1);
    }

    public function toggleHidden(array $scope, int $employeeId, bool $hidden, int $actorUserPk): array
    {
        if ($employeeId <= 0) {
            throw new InvalidArgumentException('employee_id is required');
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_EMPLOYEE) . "
                SET is_hidden = ?,
                    updated_by = ?,
                    updated_at = GETDATE()
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $hidden ? 1 : 0,
            $actorUserPk > 0 ? $actorUserPk : null,
            $employeeId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        if ($stmt->rowCount() <= 0) {
            throw new RuntimeException('employee not found');
        }

        $this->audit->log('groupware.employee.hidden.toggle', $actorUserPk, 'OK', 'Employee hidden state changed', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'employee_id' => $employeeId,
            'is_hidden' => $hidden ? 1 : 0,
        ]);

        $saved = $this->getEmployeeById($scope, $employeeId);
        if ($saved === null) {
            throw new RuntimeException('employee reload failed');
        }

        return $saved;
    }

    private function listEmployeesByHidden(array $scope, array $filters, int $isHidden): array
    {
        if (!$this->tableExists(self::TABLE_EMPLOYEE)) {
            return [];
        }

        $where = [
            'e.service_code = ?',
            'e.tenant_id = ?',
            'ISNULL(e.is_deleted, 0) = 0',
            'ISNULL(e.is_hidden, 0) = ?',
        ];
        $params = [
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $isHidden === 1 ? 1 : 0,
        ];

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(e.emp_name LIKE ? OR e.emp_no LIKE ? OR e.phone LIKE ? OR e.email LIKE ?)';
            $sp = '%' . $search . '%';
            $params[] = $sp;
            $params[] = $sp;
            $params[] = $sp;
            $params[] = $sp;
        }

        $unassigned = $this->toBoolInt($filters['unassigned'] ?? 0);
        if ($unassigned === 1) {
            $where[] = 'ISNULL(e.dept_idx, 0) = 0';
        } else {
            $deptIdx = (int)($filters['dept_idx'] ?? 0);
            if ($deptIdx > 0) {
                $where[] = 'e.dept_idx = ?';
                $params[] = $deptIdx;
            }
        }

        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status === 'INACTIVE') {
            $status = 'RESIGNED';
        }
        if ($status !== '') {
            if ($status === 'RESIGNED') {
                $where[] = "UPPER(ISNULL(e.status, 'ACTIVE')) IN ('RESIGNED', 'INACTIVE')";
            } else {
                $where[] = 'UPPER(ISNULL(e.status, \'ACTIVE\')) = ?';
                $params[] = $status;
            }
        }

        $limit = min(500, max(1, (int)($filters['limit'] ?? 200)));

        $sql = "SELECT TOP {$limit}
                    e.idx,
                    ISNULL(e.user_idx, 0) AS user_idx,
                    ISNULL(e.emp_no, '') AS emp_no,
                    ISNULL(e.emp_name, '') AS emp_name,
                    ISNULL(e.dept_idx, 0) AS dept_idx,
                    ISNULL(e.position_name, '') AS position_name,
                    ISNULL(e.job_title, '') AS job_title,
                    ISNULL(e.phone, '') AS phone,
                    ISNULL(e.email, '') AS email,
                    CAST(ISNULL(e.is_hidden, 0) AS INT) AS is_hidden,
                    CASE
                        WHEN UPPER(ISNULL(e.status, 'ACTIVE')) = 'INACTIVE' THEN 'RESIGNED'
                        ELSE UPPER(ISNULL(e.status, 'ACTIVE'))
                    END AS status,
                    e.hire_date,
                    e.leave_date,
                    e.created_at,
                    e.updated_at,
                    ISNULL(e.photo_url, '') AS photo_url,
                    ISNULL(d.dept_name, '') AS dept_name
                FROM dbo." . $this->qi(self::TABLE_EMPLOYEE) . " e
                LEFT JOIN dbo." . $this->qi(self::TABLE_DEPARTMENT) . " d
                  ON d.idx = e.dept_idx
                 AND d.service_code = e.service_code
                 AND d.tenant_id = e.tenant_id
                 AND ISNULL(d.is_deleted, 0) = 0
                WHERE " . implode(' AND ', $where) . "
                ORDER BY e.idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['user_name'] = $this->resolveUserDisplayName((int)($row['user_idx'] ?? 0));
        }
        unset($row);

        return $rows;
    }

    public function deleteEmployee(array $scope, int $employeeId, int $actorUserPk): bool
    {
        if ($employeeId <= 0 || !$this->tableExists(self::TABLE_EMPLOYEE)) {
            throw new InvalidArgumentException('employee_id is required');
        }

        $setClauses = [
            'is_deleted = 1',
            "status = 'RESIGNED'",
            'updated_by = ?',
            'updated_at = GETDATE()',
        ];

        if ($this->columnExists(self::TABLE_EMPLOYEE, 'deleted_at')) {
            $setClauses[] = 'deleted_at = GETDATE()';
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_EMPLOYEE) . "
                SET " . implode(', ', $setClauses) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $actorUserPk > 0 ? $actorUserPk : null,
            $employeeId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        if ($stmt->rowCount() <= 0) {
            return false;
        }

        $this->audit->log('groupware.employee.delete', $actorUserPk, 'OK', 'Employee deleted (soft)', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id'    => (int)$scope['tenant_id'],
            'employee_id'  => $employeeId,
            'status'       => 'RESIGNED',
        ]);

        return true;
    }

    public function getEmployeeById(array $scope, int $employeeId): ?array
    {
        if ($employeeId <= 0 || !$this->tableExists(self::TABLE_EMPLOYEE)) {
            return null;
        }

        $sql = "SELECT TOP 1
                    e.idx,
                    ISNULL(e.user_idx, 0) AS user_idx,
                    ISNULL(e.emp_no, '') AS emp_no,
                    ISNULL(e.emp_name, '') AS emp_name,
                    ISNULL(e.dept_idx, 0) AS dept_idx,
                    ISNULL(e.position_name, '') AS position_name,
                    ISNULL(e.job_title, '') AS job_title,
                    ISNULL(e.phone, '') AS phone,
                    ISNULL(e.email, '') AS email,
                    ISNULL(e.tel, '') AS tel,
                    ISNULL(e.personal_email, '') AS personal_email,
                    ISNULL(e.address, '') AS address,
                    ISNULL(e.social_number, '') AS social_number,
                    ISNULL(e.employment_type, '') AS employment_type,
                    ISNULL(e.work_gubun, '') AS work_gubun,
                    ISNULL(e.license, '') AS license,
                    ISNULL(e.career_note, '') AS career_note,
                    e.last_promotion,
                    ISNULL(e.emp_memo, '') AS emp_memo,
                    ISNULL(e.salary_basic, 0) AS salary_basic,
                    ISNULL(e.salary_qualification, 0) AS salary_qualification,
                    ISNULL(e.salary_part_position, 0) AS salary_part_position,
                    ISNULL(e.salary_position, 0) AS salary_position,
                    ISNULL(e.salary_overtime_fix, 0) AS salary_overtime_fix,
                    ISNULL(e.salary_work, 0) AS salary_work,
                    ISNULL(e.salary_meal, 0) AS salary_meal,
                    ISNULL(e.salary_car, 0) AS salary_car,
                    ISNULL(e.salary_etc, 0) AS salary_etc,
                    ISNULL(e.bank_name, '') AS bank_name,
                    ISNULL(e.bank_depositor, '') AS bank_depositor,
                    ISNULL(e.bank_account, '') AS bank_account,
                    ISNULL(e.card_name, '') AS card_name,
                    ISNULL(e.card_number, '') AS card_number,
                    ISNULL(e.card_memo, '') AS card_memo,
                    CAST(ISNULL(e.is_hidden, 0) AS INT) AS is_hidden,
                    CASE
                        WHEN UPPER(ISNULL(e.status, 'ACTIVE')) = 'INACTIVE' THEN 'RESIGNED'
                        ELSE UPPER(ISNULL(e.status, 'ACTIVE'))
                    END AS status,
                    e.hire_date,
                    e.leave_date,
                    e.created_at,
                    e.updated_at,
                    ISNULL(e.photo_url, '') AS photo_url,
                    ISNULL(d.dept_name, '') AS dept_name
                FROM dbo." . $this->qi(self::TABLE_EMPLOYEE) . " e
                LEFT JOIN dbo." . $this->qi(self::TABLE_DEPARTMENT) . " d
                  ON d.idx = e.dept_idx
                 AND d.service_code = e.service_code
                 AND d.tenant_id = e.tenant_id
                 AND ISNULL(d.is_deleted, 0) = 0
                WHERE e.idx = ?
                  AND e.service_code = ?
                  AND e.tenant_id = ?
                  AND ISNULL(e.is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $employeeId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $row['user_name'] = $this->resolveUserDisplayName((int)($row['user_idx'] ?? 0));
        return $row;
    }

    public function saveEmployee(array $scope, array $payload, int $actorUserPk): array
    {
        $employeeId = (int)($payload['idx'] ?? $payload['employee_id'] ?? 0);
        $userIdx = (int)($payload['user_idx'] ?? 0);
        $empNo = trim((string)($payload['emp_no'] ?? ''));
        $empName = trim((string)($payload['emp_name'] ?? $payload['name'] ?? ''));
        $deptIdx = (int)($payload['dept_idx'] ?? 0);
        $positionName = trim((string)($payload['position_name'] ?? ''));
        $jobTitle = trim((string)($payload['job_title'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $tel = trim((string)($payload['tel'] ?? ''));
        $personalEmail = trim((string)($payload['personal_email'] ?? ''));
        $address = trim((string)($payload['address'] ?? ''));
        $socialNumber = trim((string)($payload['social_number'] ?? ''));
        $employmentType = trim((string)($payload['employment_type'] ?? ''));
        $workGubun = trim((string)($payload['work_gubun'] ?? ''));
        $license = trim((string)($payload['license'] ?? ''));
        $careerNote = trim((string)($payload['career_note'] ?? ''));
        $lastPromotion = $this->normalizeDate($payload['last_promotion'] ?? null);
        $empMemo = trim((string)($payload['emp_memo'] ?? ''));
        $salaryBasic = $this->normalizeNullableInt($payload['salary_basic'] ?? null);
        $salaryQualification = $this->normalizeNullableInt($payload['salary_qualification'] ?? null);
        $salaryPartPosition = $this->normalizeNullableInt($payload['salary_part_position'] ?? null);
        $salaryPosition = $this->normalizeNullableInt($payload['salary_position'] ?? null);
        $salaryOvertimeFix = $this->normalizeNullableInt($payload['salary_overtime_fix'] ?? null);
        $salaryWork = $this->normalizeNullableInt($payload['salary_work'] ?? null);
        $salaryMeal = $this->normalizeNullableInt($payload['salary_meal'] ?? null);
        $salaryCar = $this->normalizeNullableInt($payload['salary_car'] ?? null);
        $salaryEtc = $this->normalizeNullableInt($payload['salary_etc'] ?? null);
        $bankName = trim((string)($payload['bank_name'] ?? ''));
        $bankDepositor = trim((string)($payload['bank_depositor'] ?? ''));
        $bankAccount = trim((string)($payload['bank_account'] ?? ''));
        $cardName = trim((string)($payload['card_name'] ?? ''));
        $cardNumber = trim((string)($payload['card_number'] ?? ''));
        $cardMemo = trim((string)($payload['card_memo'] ?? ''));
        $photoUrl = trim((string)($payload['photo_url'] ?? ''));
        $status = strtoupper(trim((string)($payload['status'] ?? 'ACTIVE')));
        if ($status === 'INACTIVE') {
            $status = 'RESIGNED';
        }
        $hireDate = $this->normalizeDate($payload['hire_date'] ?? null);
        $leaveDate = $this->normalizeDate($payload['leave_date'] ?? null);

        if ($empName === '') {
            throw new InvalidArgumentException('emp_name is required');
        }

        if (!in_array($status, ['ACTIVE', 'RESIGNED', 'LEAVE'], true)) {
            $status = 'ACTIVE';
        }

        if ($employeeId > 0) {
            $sql = "UPDATE dbo." . $this->qi(self::TABLE_EMPLOYEE) . "
                    SET user_idx = ?,
                        emp_no = ?,
                        emp_name = ?,
                        dept_idx = ?,
                        position_name = ?,
                        job_title = ?,
                        phone = ?,
                        email = ?,
                        tel = ?,
                        personal_email = ?,
                        address = ?,
                        social_number = ?,
                        employment_type = ?,
                        work_gubun = ?,
                        license = ?,
                        career_note = ?,
                        last_promotion = ?,
                        emp_memo = ?,
                        salary_basic = ?,
                        salary_qualification = ?,
                        salary_part_position = ?,
                        salary_position = ?,
                        salary_overtime_fix = ?,
                        salary_work = ?,
                        salary_meal = ?,
                        salary_car = ?,
                        salary_etc = ?,
                        bank_name = ?,
                        bank_depositor = ?,
                        bank_account = ?,
                        card_name = ?,
                        card_number = ?,
                        card_memo = ?,
                        photo_url = ?,
                        status = ?,
                        hire_date = ?,
                        leave_date = ?,
                        updated_by = ?,
                        updated_at = GETDATE()
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $userIdx > 0 ? $userIdx : null,
                $empNo,
                $empName,
                $deptIdx > 0 ? $deptIdx : null,
                $positionName,
                $jobTitle,
                $phone,
                $email,
                $tel,
                $personalEmail,
                $address,
                $socialNumber,
                $employmentType,
                $workGubun,
                $license,
                $careerNote,
                $lastPromotion,
                $empMemo,
                $salaryBasic,
                $salaryQualification,
                $salaryPartPosition,
                $salaryPosition,
                $salaryOvertimeFix,
                $salaryWork,
                $salaryMeal,
                $salaryCar,
                $salaryEtc,
                $bankName,
                $bankDepositor,
                $bankAccount,
                $cardName,
                $cardNumber,
                $cardMemo,
                $photoUrl,
                $status,
                $hireDate,
                $leaveDate,
                $actorUserPk > 0 ? $actorUserPk : null,
                $employeeId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('employee not found');
            }

            $this->audit->log('groupware.employee.update', $actorUserPk, 'OK', 'Employee updated', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'employee_id' => $employeeId,
            ]);

            $saved = $this->getEmployeeById($scope, $employeeId);
            if ($saved === null) {
                throw new RuntimeException('employee reload failed');
            }

            return $saved;
        }

        $sql = "INSERT INTO dbo." . $this->qi(self::TABLE_EMPLOYEE) . "
                (service_code, tenant_id, user_idx, emp_no, emp_name, dept_idx, position_name, job_title, phone, email, tel, personal_email, address, social_number, employment_type, work_gubun, license, career_note, last_promotion, emp_memo, salary_basic, salary_qualification, salary_part_position, salary_position, salary_overtime_fix, salary_work, salary_meal, salary_car, salary_etc, bank_name, bank_depositor, bank_account, card_name, card_number, card_memo, photo_url, status, hire_date, leave_date, is_deleted, created_by, updated_by, created_at, updated_at)
                OUTPUT INSERTED.idx
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, GETDATE(), GETDATE())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $userIdx > 0 ? $userIdx : null,
            $empNo,
            $empName,
            $deptIdx > 0 ? $deptIdx : null,
            $positionName,
            $jobTitle,
            $phone,
            $email,
            $tel,
            $personalEmail,
            $address,
            $socialNumber,
            $employmentType,
            $workGubun,
            $license,
            $careerNote,
            $lastPromotion,
            $empMemo,
            $salaryBasic,
            $salaryQualification,
            $salaryPartPosition,
            $salaryPosition,
            $salaryOvertimeFix,
            $salaryWork,
            $salaryMeal,
            $salaryCar,
            $salaryEtc,
            $bankName,
            $bankDepositor,
            $bankAccount,
            $cardName,
            $cardNumber,
            $cardMemo,
            $photoUrl,
            $status,
            $hireDate,
            $leaveDate,
            $actorUserPk > 0 ? $actorUserPk : null,
            $actorUserPk > 0 ? $actorUserPk : null,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $newId = (int)($row['idx'] ?? 0);
        if ($newId <= 0) {
            throw new RuntimeException('employee insert failed');
        }

        $this->audit->log('groupware.employee.insert', $actorUserPk, 'OK', 'Employee created', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'employee_id' => $newId,
        ]);

        $saved = $this->getEmployeeById($scope, $newId);
        if ($saved === null) {
            throw new RuntimeException('employee reload failed');
        }

        return $saved;
    }

    public function updateEmployeePhoto(array $scope, int $employeeId, string $photoUrl, int $actorUserPk): array
    {
        if ($employeeId <= 0) {
            throw new InvalidArgumentException('employee_id is required');
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_EMPLOYEE) . "
                SET photo_url = ?,
                    updated_by = ?,
                    updated_at = GETDATE()
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            trim($photoUrl),
            $actorUserPk > 0 ? $actorUserPk : null,
            $employeeId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        if ($stmt->rowCount() <= 0) {
            throw new RuntimeException('employee not found');
        }

        $this->audit->log('groupware.employee.photo.update', $actorUserPk, 'OK', 'Employee photo updated', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'employee_id' => $employeeId,
            'photo_url' => trim($photoUrl),
        ]);

        $saved = $this->getEmployeeById($scope, $employeeId);
        if ($saved === null) {
            throw new RuntimeException('employee reload failed');
        }

        return $saved;
    }

    public function listEmployeeCards(array $scope, array $filters = []): array
    {
        if (!$this->tableExists(self::TABLE_EMPLOYEE_CARD)) {
            return [];
        }

        $where = [
            'service_code = ?',
            'tenant_id = ?',
            'ISNULL(is_deleted, 0) = 0',
        ];
        $params = [
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ];

        $employeeIdx = (int)($filters['employee_idx'] ?? 0);
        if ($employeeIdx > 0) {
            $where[] = 'employee_idx = ?';
            $params[] = $employeeIdx;
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(card_usage LIKE ? OR card_name LIKE ? OR card_number LIKE ? OR card_memo LIKE ?)';
            $sp = '%' . $search . '%';
            $params[] = $sp;
            $params[] = $sp;
            $params[] = $sp;
            $params[] = $sp;
        }

        $limit = min(500, max(1, (int)($filters['limit'] ?? 200)));

        $sql = "SELECT TOP {$limit}
                    idx,
                    ISNULL(employee_idx, 0) AS employee_idx,
                    ISNULL(card_usage, '') AS card_usage,
                    ISNULL(card_name, '') AS card_name,
                    ISNULL(card_number, '') AS card_number,
                    ISNULL(card_memo, '') AS card_memo,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_EMPLOYEE_CARD) . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function saveEmployeeCard(array $scope, array $payload, int $actorUserPk): array
    {
        if (!$this->tableExists(self::TABLE_EMPLOYEE_CARD)) {
            throw new RuntimeException('employee card table not found');
        }

        $rowId = (int)($payload['idx'] ?? $payload['card_id'] ?? 0);
        $employeeIdx = (int)($payload['employee_idx'] ?? 0);
        $cardUsage = trim((string)($payload['card_usage'] ?? ''));
        $cardName = trim((string)($payload['card_name'] ?? ''));
        $cardNumber = trim((string)($payload['card_number'] ?? ''));
        $cardMemo = trim((string)($payload['card_memo'] ?? ''));

        if ($employeeIdx <= 0) {
            throw new InvalidArgumentException('employee_idx is required');
        }
        if (!$this->employeeExistsById($scope, $employeeIdx)) {
            throw new RuntimeException('employee not found');
        }

        if ($rowId > 0) {
            $sql = "UPDATE dbo." . $this->qi(self::TABLE_EMPLOYEE_CARD) . "
                    SET employee_idx = ?,
                        card_usage = ?,
                        card_name = ?,
                        card_number = ?,
                        card_memo = ?,
                        updated_by = ?,
                        updated_at = GETDATE()
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $employeeIdx,
                $cardUsage,
                $cardName,
                $cardNumber,
                $cardMemo,
                $actorUserPk > 0 ? $actorUserPk : null,
                $rowId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('employee card not found');
            }

            $this->audit->log('groupware.employee.card.update', $actorUserPk, 'OK', 'Employee card updated', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'card_id' => $rowId,
                'employee_idx' => $employeeIdx,
            ]);

            $saved = $this->getEmployeeCardById($scope, $rowId);
            if ($saved === null) {
                throw new RuntimeException('employee card reload failed');
            }

            return $saved;
        }

        $sql = "INSERT INTO dbo." . $this->qi(self::TABLE_EMPLOYEE_CARD) . "
                (service_code, tenant_id, employee_idx, card_usage, card_name, card_number, card_memo, is_deleted, created_by, updated_by, created_at, updated_at)
                OUTPUT INSERTED.idx
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, GETDATE(), GETDATE())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $employeeIdx,
            $cardUsage,
            $cardName,
            $cardNumber,
            $cardMemo,
            $actorUserPk > 0 ? $actorUserPk : null,
            $actorUserPk > 0 ? $actorUserPk : null,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $newId = (int)($row['idx'] ?? 0);
        if ($newId <= 0) {
            throw new RuntimeException('employee card insert failed');
        }

        $this->audit->log('groupware.employee.card.insert', $actorUserPk, 'OK', 'Employee card created', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'card_id' => $newId,
            'employee_idx' => $employeeIdx,
        ]);

        $saved = $this->getEmployeeCardById($scope, $newId);
        if ($saved === null) {
            throw new RuntimeException('employee card reload failed');
        }

        return $saved;
    }

    public function deleteEmployeeCard(array $scope, int $rowId, int $actorUserPk): bool
    {
        if ($rowId <= 0) {
            throw new InvalidArgumentException('card_id is required');
        }
        if (!$this->tableExists(self::TABLE_EMPLOYEE_CARD)) {
            throw new RuntimeException('employee card table not found');
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_EMPLOYEE_CARD) . "
                SET is_deleted = 1,
                    deleted_by = ?,
                    deleted_at = GETDATE(),
                    updated_by = ?,
                    updated_at = GETDATE()
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $actorUserPk > 0 ? $actorUserPk : null,
            $actorUserPk > 0 ? $actorUserPk : null,
            $rowId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        if ($stmt->rowCount() <= 0) {
            return false;
        }

        $this->audit->log('groupware.employee.card.delete', $actorUserPk, 'OK', 'Employee card deleted', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'card_id' => $rowId,
        ]);

        return true;
    }

    public function listPhoneBook(array $scope, array $filters = []): array
    {
        if (!$this->tableExists(self::TABLE_PHONEBOOK)) {
            return [];
        }

        $where = [
            'service_code = ?',
            'tenant_id = ?',
            'ISNULL(is_deleted, 0) = 0',
        ];
        $params = [
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ];

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(contact_name LIKE ? OR company_name LIKE ? OR department_name LIKE ? OR position_name LIKE ? OR phone LIKE ? OR email LIKE ?)';
            $sp = '%' . $search . '%';
            $params[] = $sp;
            $params[] = $sp;
            $params[] = $sp;
            $params[] = $sp;
            $params[] = $sp;
            $params[] = $sp;
        }

        $limit = min(500, max(1, (int)($filters['limit'] ?? 200)));

        $sql = "SELECT TOP {$limit}
                    idx,
                    ISNULL(employee_idx, 0) AS employee_idx,
                    ISNULL(contact_name, '') AS contact_name,
                    ISNULL(company_name, '') AS company_name,
                    ISNULL(department_name, '') AS department_name,
                    ISNULL(position_name, '') AS position_name,
                    ISNULL(phone, '') AS phone,
                    ISNULL(email, '') AS email,
                    ISNULL(memo, '') AS memo,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_PHONEBOOK) . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function savePhoneBook(array $scope, array $payload, int $actorUserPk): array
    {
        $rowId = (int)($payload['idx'] ?? $payload['phonebook_id'] ?? 0);
        $employeeIdx = (int)($payload['employee_idx'] ?? 0);
        $contactName = trim((string)($payload['contact_name'] ?? ''));
        $companyName = trim((string)($payload['company_name'] ?? ''));
        $departmentName = trim((string)($payload['department_name'] ?? ''));
        $positionName = trim((string)($payload['position_name'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $memo = trim((string)($payload['memo'] ?? ''));

        if ($contactName === '') {
            throw new InvalidArgumentException('contact_name is required');
        }

        if ($rowId > 0) {
            $sql = "UPDATE dbo." . $this->qi(self::TABLE_PHONEBOOK) . "
                    SET employee_idx = ?,
                        contact_name = ?,
                        company_name = ?,
                        department_name = ?,
                        position_name = ?,
                        phone = ?,
                        email = ?,
                        memo = ?,
                        updated_by = ?,
                        updated_at = GETDATE()
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $employeeIdx > 0 ? $employeeIdx : null,
                $contactName,
                $companyName,
                $departmentName,
                $positionName,
                $phone,
                $email,
                $memo,
                $actorUserPk > 0 ? $actorUserPk : null,
                $rowId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('phonebook row not found');
            }

            $this->audit->log('groupware.phonebook.update', $actorUserPk, 'OK', 'Phonebook row updated', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'phonebook_id' => $rowId,
            ]);

            $saved = $this->getPhoneBookById($scope, $rowId);
            if ($saved === null) {
                throw new RuntimeException('phonebook row reload failed');
            }

            return $saved;
        }

        $sql = "INSERT INTO dbo." . $this->qi(self::TABLE_PHONEBOOK) . "
                (service_code, tenant_id, employee_idx, contact_name, company_name, department_name, position_name, phone, email, memo, is_deleted, created_by, updated_by, created_at, updated_at)
                OUTPUT INSERTED.idx
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, GETDATE(), GETDATE())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $employeeIdx > 0 ? $employeeIdx : null,
            $contactName,
            $companyName,
            $departmentName,
            $positionName,
            $phone,
            $email,
            $memo,
            $actorUserPk > 0 ? $actorUserPk : null,
            $actorUserPk > 0 ? $actorUserPk : null,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $newId = (int)($row['idx'] ?? 0);
        if ($newId <= 0) {
            throw new RuntimeException('phonebook row insert failed');
        }

        $this->audit->log('groupware.phonebook.insert', $actorUserPk, 'OK', 'Phonebook row created', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'phonebook_id' => $newId,
        ]);

        $saved = $this->getPhoneBookById($scope, $newId);
        if ($saved === null) {
            throw new RuntimeException('phonebook row reload failed');
        }

        return $saved;
    }

    public function deletePhoneBook(array $scope, int $rowId, int $actorUserPk): bool
    {
        if ($rowId <= 0) {
            throw new InvalidArgumentException('phonebook_id is required');
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_PHONEBOOK) . "
                SET is_deleted = 1,
                    updated_by = ?,
                    updated_at = GETDATE()
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $actorUserPk > 0 ? $actorUserPk : null,
            $rowId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        if ($stmt->rowCount() <= 0) {
            return false;
        }

        $this->audit->log('groupware.phonebook.delete', $actorUserPk, 'OK', 'Phonebook row deleted', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'phonebook_id' => $rowId,
        ]);

        return true;
    }

    public function listAttendance(array $scope, array $filters = []): array
    {
        if (!$this->tableExists(self::TABLE_ATTENDANCE)) {
            return [];
        }

        $where = [
            'a.service_code = ?',
            'a.tenant_id = ?',
        ];
        $params = [
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ];

        $employeeIdx = (int)($filters['employee_idx'] ?? 0);
        if ($employeeIdx > 0) {
            $where[] = 'a.employee_idx = ?';
            $params[] = $employeeIdx;
        }

        $startDate = $this->normalizeDate($filters['start_date'] ?? null);
        if ($startDate !== null) {
            $where[] = 'a.work_date >= ?';
            $params[] = $startDate;
        }

        $endDate = $this->normalizeDate($filters['end_date'] ?? null);
        if ($endDate !== null) {
            $where[] = 'a.work_date <= ?';
            $params[] = $endDate;
        }

        $limit = min(1000, max(1, (int)($filters['limit'] ?? 200)));

        $sql = "SELECT TOP {$limit}
                    a.idx,
                    a.employee_idx,
                    a.work_date,
                    a.check_in,
                    a.check_out,
                    ISNULL(a.work_minutes, 0) AS work_minutes,
                    ISNULL(a.status, 'NORMAL') AS status,
                    ISNULL(a.note, '') AS note,
                    a.created_at,
                    a.updated_at,
                    ISNULL(e.emp_name, '') AS employee_name
                FROM dbo." . $this->qi(self::TABLE_ATTENDANCE) . " a
                LEFT JOIN dbo." . $this->qi(self::TABLE_EMPLOYEE) . " e
                  ON e.idx = a.employee_idx
                 AND e.service_code = a.service_code
                 AND e.tenant_id = a.tenant_id
                 AND ISNULL(e.is_deleted, 0) = 0
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.work_date DESC, a.idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function saveAttendance(array $scope, array $payload, int $actorUserPk): array
    {
        $rowId = (int)($payload['idx'] ?? $payload['attendance_id'] ?? 0);
        $employeeIdx = (int)($payload['employee_idx'] ?? 0);
        $workDate = $this->normalizeDate($payload['work_date'] ?? null);
        $checkIn = $this->normalizeDateTime($payload['check_in'] ?? null);
        $checkOut = $this->normalizeDateTime($payload['check_out'] ?? null);
        $status = strtoupper(trim((string)($payload['status'] ?? 'NORMAL')));
        $note = trim((string)($payload['note'] ?? ''));

        if ($employeeIdx <= 0 || $workDate === null) {
            throw new InvalidArgumentException('employee_idx/work_date is required');
        }

        if (!in_array($status, ['NORMAL', 'LATE', 'ABSENT', 'HOLIDAY', 'ETC'], true)) {
            $status = 'NORMAL';
        }

        $workMinutes = $this->calcWorkMinutes($checkIn, $checkOut);

        if ($rowId <= 0) {
            $existing = $this->findAttendanceByEmployeeAndDate($scope, $employeeIdx, $workDate);
            if ($existing !== null) {
                $rowId = (int)$existing['idx'];
            }
        }

        if ($rowId > 0) {
            $sql = "UPDATE dbo." . $this->qi(self::TABLE_ATTENDANCE) . "
                    SET check_in = ?,
                        check_out = ?,
                        work_minutes = ?,
                        status = ?,
                        note = ?,
                        updated_by = ?,
                        updated_at = GETDATE()
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $checkIn,
                $checkOut,
                $workMinutes,
                $status,
                $note,
                $actorUserPk > 0 ? $actorUserPk : null,
                $rowId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('attendance row not found');
            }

            $this->audit->log('groupware.attendance.update', $actorUserPk, 'OK', 'Attendance updated', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'attendance_id' => $rowId,
            ]);

            $saved = $this->getAttendanceById($scope, $rowId);
            if ($saved === null) {
                throw new RuntimeException('attendance row reload failed');
            }

            return $saved;
        }

        $sql = "INSERT INTO dbo." . $this->qi(self::TABLE_ATTENDANCE) . "
                (service_code, tenant_id, employee_idx, work_date, check_in, check_out, work_minutes, status, note, created_by, updated_by, created_at, updated_at)
                OUTPUT INSERTED.idx
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $employeeIdx,
            $workDate,
            $checkIn,
            $checkOut,
            $workMinutes,
            $status,
            $note,
            $actorUserPk > 0 ? $actorUserPk : null,
            $actorUserPk > 0 ? $actorUserPk : null,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $newId = (int)($row['idx'] ?? 0);
        if ($newId <= 0) {
            throw new RuntimeException('attendance row insert failed');
        }

        $this->audit->log('groupware.attendance.insert', $actorUserPk, 'OK', 'Attendance created', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'attendance_id' => $newId,
        ]);

        $saved = $this->getAttendanceById($scope, $newId);
        if ($saved === null) {
            throw new RuntimeException('attendance row reload failed');
        }

        return $saved;
    }

    public function listHoliday(array $scope, array $filters = []): array
    {
        if (!$this->tableExists(self::TABLE_HOLIDAY)) {
            return [];
        }

        $where = [
            'h.service_code = ?',
            'h.tenant_id = ?',
            'ISNULL(h.is_deleted, 0) = 0',
        ];
        $params = [
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ];

        $employeeIdx = (int)($filters['employee_idx'] ?? 0);
        if ($employeeIdx > 0) {
            $where[] = 'h.employee_idx = ?';
            $params[] = $employeeIdx;
        }

        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status !== '') {
            $where[] = 'UPPER(ISNULL(h.status, \'REQUESTED\')) = ?';
            $params[] = $status;
        }

        $startDate = $this->normalizeDate($filters['start_date'] ?? null);
        if ($startDate !== null) {
            $where[] = 'h.end_date >= ?';
            $params[] = $startDate;
        }

        $endDate = $this->normalizeDate($filters['end_date'] ?? null);
        if ($endDate !== null) {
            $where[] = 'h.start_date <= ?';
            $params[] = $endDate;
        }

        $limit = min(500, max(1, (int)($filters['limit'] ?? 200)));

        $sql = "SELECT TOP {$limit}
                    h.idx,
                    h.employee_idx,
                    ISNULL(h.holiday_type, 'ANNUAL') AS holiday_type,
                    h.start_date,
                    h.end_date,
                    ISNULL(h.reason, '') AS reason,
                    ISNULL(h.status, 'REQUESTED') AS status,
                    ISNULL(h.approved_by, 0) AS approved_by,
                    h.approved_at,
                    h.created_at,
                    h.updated_at,
                    ISNULL(e.emp_name, '') AS employee_name
                FROM dbo." . $this->qi(self::TABLE_HOLIDAY) . " h
                LEFT JOIN dbo." . $this->qi(self::TABLE_EMPLOYEE) . " e
                  ON e.idx = h.employee_idx
                 AND e.service_code = h.service_code
                 AND e.tenant_id = h.tenant_id
                 AND ISNULL(e.is_deleted, 0) = 0
                WHERE " . implode(' AND ', $where) . "
                ORDER BY h.start_date DESC, h.idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function saveHoliday(array $scope, array $payload, int $actorUserPk): array
    {
        $rowId = (int)($payload['idx'] ?? $payload['holiday_id'] ?? 0);
        $employeeIdx = (int)($payload['employee_idx'] ?? 0);
        $holidayType = strtoupper(trim((string)($payload['holiday_type'] ?? 'ANNUAL')));
        $startDate = $this->normalizeDate($payload['start_date'] ?? null);
        $endDate = $this->normalizeDate($payload['end_date'] ?? null);
        $reason = trim((string)($payload['reason'] ?? ''));

        if ($employeeIdx <= 0 || $startDate === null || $endDate === null) {
            throw new InvalidArgumentException('employee_idx/start_date/end_date is required');
        }

        if ($startDate > $endDate) {
            throw new InvalidArgumentException('start_date must be less than or equal to end_date');
        }

        if (!in_array($holidayType, ['ANNUAL', 'HALF', 'SPECIAL', 'SICK', 'ETC'], true)) {
            $holidayType = 'ANNUAL';
        }

        if ($rowId > 0) {
            $sql = "UPDATE dbo." . $this->qi(self::TABLE_HOLIDAY) . "
                    SET employee_idx = ?,
                        holiday_type = ?,
                        start_date = ?,
                        end_date = ?,
                        reason = ?,
                        updated_by = ?,
                        updated_at = GETDATE()
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0
                      AND ISNULL(status, 'REQUESTED') IN ('REQUESTED', 'REJECTED')";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $employeeIdx,
                $holidayType,
                $startDate,
                $endDate,
                $reason,
                $actorUserPk > 0 ? $actorUserPk : null,
                $rowId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('holiday row not found or immutable');
            }

            $this->audit->log('groupware.holiday.update', $actorUserPk, 'OK', 'Holiday updated', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'holiday_id' => $rowId,
            ]);

            $saved = $this->getHolidayById($scope, $rowId);
            if ($saved === null) {
                throw new RuntimeException('holiday row reload failed');
            }

            return $saved;
        }

        $sql = "INSERT INTO dbo." . $this->qi(self::TABLE_HOLIDAY) . "
                (service_code, tenant_id, employee_idx, holiday_type, start_date, end_date, reason, status, is_deleted, created_by, updated_by, created_at, updated_at)
                OUTPUT INSERTED.idx
                VALUES (?, ?, ?, ?, ?, ?, ?, 'REQUESTED', 0, ?, ?, GETDATE(), GETDATE())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $employeeIdx,
            $holidayType,
            $startDate,
            $endDate,
            $reason,
            $actorUserPk > 0 ? $actorUserPk : null,
            $actorUserPk > 0 ? $actorUserPk : null,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $newId = (int)($row['idx'] ?? 0);
        if ($newId <= 0) {
            throw new RuntimeException('holiday row insert failed');
        }

        $this->audit->log('groupware.holiday.insert', $actorUserPk, 'OK', 'Holiday created', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'holiday_id' => $newId,
        ]);

        $saved = $this->getHolidayById($scope, $newId);
        if ($saved === null) {
            throw new RuntimeException('holiday row reload failed');
        }

        return $saved;
    }

    public function updateHolidayStatus(array $scope, int $holidayId, string $status, int $actorUserPk, int $roleLevel = 0): array
    {
        if ($holidayId <= 0) {
            throw new InvalidArgumentException('holiday_id is required');
        }

        $status = strtoupper(trim($status));
        if (!in_array($status, ['APPROVED', 'REJECTED', 'CANCELED'], true)) {
            throw new InvalidArgumentException('status must be APPROVED/REJECTED/CANCELED');
        }

        $state = $this->getHolidayStatusRow($scope, $holidayId);
        if ($state === null) {
            throw new RuntimeException('holiday row not found');
        }

        $currentStatus = strtoupper((string)($state['status'] ?? 'REQUESTED'));
        if ($currentStatus !== 'REQUESTED') {
            throw new RuntimeException('holiday row not found or already processed');
        }

        if ($status === 'CANCELED') {
            if (!$this->isApproverRole($roleLevel)) {
                $ownerUserPk = $this->resolveEmployeeUserPk($scope, (int)($state['employee_idx'] ?? 0));
                $createdBy = (int)($state['created_by'] ?? 0);
                $isOwner = $actorUserPk > 0
                    && ($actorUserPk === $ownerUserPk || $actorUserPk === $createdBy);
                if (!$isOwner) {
                    throw new RuntimeException('forbidden holiday cancel');
                }
            }
        } elseif (!$this->isApproverRole($roleLevel)) {
            throw new RuntimeException('insufficient role level');
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_HOLIDAY) . "
                SET status = ?,
                    approved_by = CASE WHEN ? IN ('APPROVED', 'REJECTED') THEN ? ELSE approved_by END,
                    approved_at = CASE WHEN ? IN ('APPROVED', 'REJECTED') THEN GETDATE() ELSE approved_at END,
                    updated_by = ?,
                    updated_at = GETDATE()
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0
                  AND ISNULL(status, 'REQUESTED') = 'REQUESTED'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $status,
            $status,
            $actorUserPk > 0 ? $actorUserPk : null,
            $status,
            $actorUserPk > 0 ? $actorUserPk : null,
            $holidayId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        if ($stmt->rowCount() <= 0) {
            throw new RuntimeException('holiday row not found or already processed');
        }

        $this->audit->log('groupware.holiday.status', $actorUserPk, 'OK', 'Holiday status changed', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'holiday_id' => $holidayId,
            'status' => $status,
        ]);

        $saved = $this->getHolidayById($scope, $holidayId);
        if ($saved === null) {
            throw new RuntimeException('holiday row reload failed');
        }

        return $saved;
    }

    public function listOvertime(array $scope, array $filters = []): array
    {
        if (!$this->tableExists(self::TABLE_OVERTIME)) {
            return [];
        }

        $where = [
            'o.service_code = ?',
            'o.tenant_id = ?',
            'ISNULL(o.is_deleted, 0) = 0',
        ];
        $params = [
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ];

        $employeeIdx = (int)($filters['employee_idx'] ?? 0);
        if ($employeeIdx > 0) {
            $where[] = 'o.employee_idx = ?';
            $params[] = $employeeIdx;
        }

        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status !== '') {
            $where[] = 'UPPER(ISNULL(o.status, \'REQUESTED\')) = ?';
            $params[] = $status;
        }

        $startDate = $this->normalizeDate($filters['start_date'] ?? null);
        if ($startDate !== null) {
            $where[] = 'o.work_date >= ?';
            $params[] = $startDate;
        }

        $endDate = $this->normalizeDate($filters['end_date'] ?? null);
        if ($endDate !== null) {
            $where[] = 'o.work_date <= ?';
            $params[] = $endDate;
        }

        $limit = min(500, max(1, (int)($filters['limit'] ?? 200)));

        $sql = "SELECT TOP {$limit}
                    o.idx,
                    o.employee_idx,
                    o.work_date,
                    o.start_time,
                    o.end_time,
                    ISNULL(o.minutes, 0) AS minutes,
                    ISNULL(o.reason, '') AS reason,
                    ISNULL(o.status, 'REQUESTED') AS status,
                    ISNULL(o.approved_by, 0) AS approved_by,
                    o.approved_at,
                    o.created_at,
                    o.updated_at,
                    ISNULL(e.emp_name, '') AS employee_name
                FROM dbo." . $this->qi(self::TABLE_OVERTIME) . " o
                LEFT JOIN dbo." . $this->qi(self::TABLE_EMPLOYEE) . " e
                  ON e.idx = o.employee_idx
                 AND e.service_code = o.service_code
                 AND e.tenant_id = o.tenant_id
                 AND ISNULL(e.is_deleted, 0) = 0
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.work_date DESC, o.idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function saveOvertime(array $scope, array $payload, int $actorUserPk): array
    {
        $rowId = (int)($payload['idx'] ?? $payload['overtime_id'] ?? 0);
        $employeeIdx = (int)($payload['employee_idx'] ?? 0);
        $workDate = $this->normalizeDate($payload['work_date'] ?? null);
        $startTime = $this->normalizeDateTime($payload['start_time'] ?? null);
        $endTime = $this->normalizeDateTime($payload['end_time'] ?? null);
        $reason = trim((string)($payload['reason'] ?? ''));

        if ($employeeIdx <= 0 || $workDate === null) {
            throw new InvalidArgumentException('employee_idx/work_date is required');
        }

        $minutes = $this->calcWorkMinutes($startTime, $endTime);

        if ($rowId > 0) {
            $sql = "UPDATE dbo." . $this->qi(self::TABLE_OVERTIME) . "
                    SET employee_idx = ?,
                        work_date = ?,
                        start_time = ?,
                        end_time = ?,
                        minutes = ?,
                        reason = ?,
                        updated_by = ?,
                        updated_at = GETDATE()
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0
                      AND ISNULL(status, 'REQUESTED') IN ('REQUESTED', 'REJECTED')";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $employeeIdx,
                $workDate,
                $startTime,
                $endTime,
                $minutes,
                $reason,
                $actorUserPk > 0 ? $actorUserPk : null,
                $rowId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('overtime row not found or immutable');
            }

            $this->audit->log('groupware.overtime.update', $actorUserPk, 'OK', 'Overtime updated', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'overtime_id' => $rowId,
            ]);

            $saved = $this->getOvertimeById($scope, $rowId);
            if ($saved === null) {
                throw new RuntimeException('overtime row reload failed');
            }

            return $saved;
        }

        $sql = "INSERT INTO dbo." . $this->qi(self::TABLE_OVERTIME) . "
                (service_code, tenant_id, employee_idx, work_date, start_time, end_time, minutes, reason, status, is_deleted, created_by, updated_by, created_at, updated_at)
                OUTPUT INSERTED.idx
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'REQUESTED', 0, ?, ?, GETDATE(), GETDATE())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $employeeIdx,
            $workDate,
            $startTime,
            $endTime,
            $minutes,
            $reason,
            $actorUserPk > 0 ? $actorUserPk : null,
            $actorUserPk > 0 ? $actorUserPk : null,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $newId = (int)($row['idx'] ?? 0);
        if ($newId <= 0) {
            throw new RuntimeException('overtime row insert failed');
        }

        $this->audit->log('groupware.overtime.insert', $actorUserPk, 'OK', 'Overtime created', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'overtime_id' => $newId,
        ]);

        $saved = $this->getOvertimeById($scope, $newId);
        if ($saved === null) {
            throw new RuntimeException('overtime row reload failed');
        }

        return $saved;
    }

    public function updateOvertimeStatus(array $scope, int $overtimeId, string $status, int $actorUserPk, int $roleLevel = 0): array
    {
        if ($overtimeId <= 0) {
            throw new InvalidArgumentException('overtime_id is required');
        }

        $status = strtoupper(trim($status));
        if (!in_array($status, ['APPROVED', 'REJECTED', 'CANCELED'], true)) {
            throw new InvalidArgumentException('status must be APPROVED/REJECTED/CANCELED');
        }

        $state = $this->getOvertimeStatusRow($scope, $overtimeId);
        if ($state === null) {
            throw new RuntimeException('overtime row not found');
        }

        $currentStatus = strtoupper((string)($state['status'] ?? 'REQUESTED'));
        if ($currentStatus !== 'REQUESTED') {
            throw new RuntimeException('overtime row not found or already processed');
        }

        if ($status === 'CANCELED') {
            if (!$this->isApproverRole($roleLevel)) {
                $ownerUserPk = $this->resolveEmployeeUserPk($scope, (int)($state['employee_idx'] ?? 0));
                $createdBy = (int)($state['created_by'] ?? 0);
                $isOwner = $actorUserPk > 0
                    && ($actorUserPk === $ownerUserPk || $actorUserPk === $createdBy);
                if (!$isOwner) {
                    throw new RuntimeException('forbidden overtime cancel');
                }
            }
        } elseif (!$this->isApproverRole($roleLevel)) {
            throw new RuntimeException('insufficient role level');
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_OVERTIME) . "
                SET status = ?,
                    approved_by = CASE WHEN ? IN ('APPROVED', 'REJECTED') THEN ? ELSE approved_by END,
                    approved_at = CASE WHEN ? IN ('APPROVED', 'REJECTED') THEN GETDATE() ELSE approved_at END,
                    updated_by = ?,
                    updated_at = GETDATE()
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0
                  AND ISNULL(status, 'REQUESTED') = 'REQUESTED'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $status,
            $status,
            $actorUserPk > 0 ? $actorUserPk : null,
            $status,
            $actorUserPk > 0 ? $actorUserPk : null,
            $overtimeId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        if ($stmt->rowCount() <= 0) {
            throw new RuntimeException('overtime row not found or already processed');
        }

        $this->audit->log('groupware.overtime.status', $actorUserPk, 'OK', 'Overtime status changed', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'overtime_id' => $overtimeId,
            'status' => $status,
        ]);

        $saved = $this->getOvertimeById($scope, $overtimeId);
        if ($saved === null) {
            throw new RuntimeException('overtime row reload failed');
        }

        return $saved;
    }

    public function listChatRooms(array $scope, int $userPk): array
    {
        if ($userPk <= 0 || !$this->tableExists(self::TABLE_CHAT_ROOM) || !$this->tableExists(self::TABLE_CHAT_MEMBER)) {
            return [];
        }

        $sql = "SELECT
                    r.idx,
                    ISNULL(r.room_type, 'GROUP') AS room_type,
                    ISNULL(r.room_name, '') AS room_name,
                    r.last_message_at,
                    r.updated_at,
                    ISNULL(m.last_read_message_idx, 0) AS last_read_message_idx,
                    (
                        SELECT COUNT(1)
                        FROM dbo." . $this->qi(self::TABLE_CHAT_MESSAGE) . " cm
                        WHERE cm.room_idx = r.idx
                          AND ISNULL(cm.is_deleted, 0) = 0
                          AND cm.idx > ISNULL(m.last_read_message_idx, 0)
                          AND ISNULL(cm.sender_user_idx, 0) <> ?
                    ) AS unread_count,
                    (
                        SELECT TOP 1 ISNULL(cm2.message_text, '')
                        FROM dbo." . $this->qi(self::TABLE_CHAT_MESSAGE) . " cm2
                        WHERE cm2.room_idx = r.idx
                          AND ISNULL(cm2.is_deleted, 0) = 0
                        ORDER BY cm2.idx DESC
                    ) AS last_message
                FROM dbo." . $this->qi(self::TABLE_CHAT_MEMBER) . " m
                INNER JOIN dbo." . $this->qi(self::TABLE_CHAT_ROOM) . " r
                   ON r.idx = m.room_idx
                WHERE m.user_idx = ?
                  AND m.left_at IS NULL
                  AND r.service_code = ?
                  AND r.tenant_id = ?
                  AND ISNULL(r.is_deleted, 0) = 0
                ORDER BY ISNULL(r.last_message_at, r.updated_at) DESC, r.idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $userPk,
            $userPk,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function getChatRoomDetail(array $scope, int $roomId, int $userPk, int $roleLevel): ?array
    {
        if ($roomId <= 0 || !$this->tableExists(self::TABLE_CHAT_ROOM)) {
            return null;
        }

        if (!$this->canAccessChatRoom($scope, $roomId, $userPk, $roleLevel)) {
            return null;
        }

        $sql = "SELECT TOP 1
                    idx,
                    ISNULL(room_type, 'GROUP') AS room_type,
                    ISNULL(room_name, '') AS room_name,
                    ISNULL(created_by, 0) AS created_by,
                    created_at,
                    updated_at,
                    last_message_at
                FROM dbo." . $this->qi(self::TABLE_CHAT_ROOM) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $roomId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($room)) {
            return null;
        }

        $memberSql = "SELECT
                        idx,
                        room_idx,
                        user_idx,
                        ISNULL(member_role, 'MEMBER') AS member_role,
                        joined_at,
                        left_at,
                        ISNULL(last_read_message_idx, 0) AS last_read_message_idx,
                        CAST(ISNULL(is_muted, 0) AS INT) AS is_muted
                      FROM dbo." . $this->qi(self::TABLE_CHAT_MEMBER) . "
                      WHERE room_idx = ?
                      ORDER BY idx ASC";
        $memberStmt = $this->db->prepare($memberSql);
        $memberStmt->execute([$roomId]);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($members)) {
            $members = [];
        }

        foreach ($members as &$member) {
            $member['user_name'] = $this->resolveUserDisplayName((int)($member['user_idx'] ?? 0));
        }
        unset($member);

        $room['members'] = $members;
        return $room;
    }

    public function createChatRoom(array $scope, int $actorUserPk, array $payload): array
    {
        $roomType = strtoupper(trim((string)($payload['room_type'] ?? 'GROUP')));
        if (!in_array($roomType, ['GROUP', 'DIRECT'], true)) {
            $roomType = 'GROUP';
        }

        $roomName = trim((string)($payload['room_name'] ?? ''));
        if ($roomName === '') {
            $roomName = $roomType === 'DIRECT' ? 'Direct Chat' : 'New Group';
        }

        $memberIds = $this->parseIntList($payload['member_ids'] ?? []);
        $memberIds[] = $actorUserPk;
        $memberIds = array_values(array_unique(array_filter($memberIds, static fn (int $id): bool => $id > 0)));
        if ($memberIds === []) {
            throw new InvalidArgumentException('member_ids is required');
        }

        try {
            $this->db->beginTransaction();

            $insertSql = "INSERT INTO dbo." . $this->qi(self::TABLE_CHAT_ROOM) . "
                          (service_code, tenant_id, room_type, room_name, created_by, is_deleted, created_at, updated_at, last_message_at)
                          OUTPUT INSERTED.idx
                          VALUES (?, ?, ?, ?, ?, 0, GETDATE(), GETDATE(), NULL)";
            $insertStmt = $this->db->prepare($insertSql);
            $insertStmt->execute([
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
                $roomType,
                $roomName,
                $actorUserPk > 0 ? $actorUserPk : null,
            ]);

            $row = $insertStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $roomId = (int)($row['idx'] ?? 0);
            if ($roomId <= 0) {
                throw new RuntimeException('chat room insert failed');
            }

            foreach ($memberIds as $memberId) {
                $this->upsertChatMember($roomId, $memberId, 'MEMBER');
            }

            $this->db->commit();

            $this->audit->log('groupware.chat.room_create', $actorUserPk, 'OK', 'Chat room created', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'room_id' => $roomId,
                'member_count' => count($memberIds),
            ]);

            $detail = $this->getChatRoomDetail($scope, $roomId, $actorUserPk, 5);
            if ($detail === null) {
                throw new RuntimeException('chat room reload failed');
            }

            return $detail;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function joinChatRoom(array $scope, int $roomId, int $targetUserPk, int $actorUserPk, int $roleLevel): bool
    {
        if ($roomId <= 0 || $targetUserPk <= 0) {
            throw new InvalidArgumentException('room_idx/target_user_idx is required');
        }

        if (!$this->canAccessChatRoom($scope, $roomId, $actorUserPk, $roleLevel)) {
            throw new RuntimeException('forbidden room access');
        }

        $this->upsertChatMember($roomId, $targetUserPk, 'MEMBER');

        $this->audit->log('groupware.chat.room_join', $actorUserPk, 'OK', 'Chat room member joined', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'room_id' => $roomId,
            'target_user_pk' => $targetUserPk,
        ]);

        return true;
    }

    public function leaveChatRoom(array $scope, int $roomId, int $targetUserPk, int $actorUserPk, int $roleLevel): bool
    {
        if ($roomId <= 0) {
            throw new InvalidArgumentException('room_idx is required');
        }

        if ($targetUserPk <= 0) {
            $targetUserPk = $actorUserPk;
        }

        if ($targetUserPk !== $actorUserPk && !$this->isApproverRole($roleLevel)) {
            throw new RuntimeException('insufficient role level');
        }

        if (!$this->canAccessChatRoom($scope, $roomId, $actorUserPk, $roleLevel)) {
            throw new RuntimeException('forbidden room access');
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_CHAT_MEMBER) . "
                SET left_at = GETDATE()
                WHERE room_idx = ?
                  AND user_idx = ?
                  AND left_at IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $roomId,
            $targetUserPk,
        ]);

        if ($stmt->rowCount() <= 0) {
            return false;
        }

        $this->audit->log('groupware.chat.room_leave', $actorUserPk, 'OK', 'Chat room member left', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'room_id' => $roomId,
            'target_user_pk' => $targetUserPk,
        ]);

        return true;
    }

    public function listChatMessages(array $scope, int $roomId, int $userPk, int $roleLevel, int $lastIdx = 0, int $limit = 100): array
    {
        if (!$this->canAccessChatRoom($scope, $roomId, $userPk, $roleLevel)) {
            throw new RuntimeException('forbidden room access');
        }

        $limit = min(500, max(1, $limit));
        $where = [
            'room_idx = ?',
            'service_code = ?',
            'tenant_id = ?',
            'ISNULL(is_deleted, 0) = 0',
        ];
        $params = [
            $roomId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ];

        if ($lastIdx > 0) {
            $where[] = 'idx > ?';
            $params[] = $lastIdx;
        }

        $sql = "SELECT TOP {$limit}
                    idx,
                    room_idx,
                    ISNULL(sender_user_idx, 0) AS sender_user_idx,
                    ISNULL(message_type, 'TEXT') AS message_type,
                    ISNULL(message_text, '') AS message_text,
                    ISNULL(payload_json, '') AS payload_json,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_CHAT_MESSAGE) . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY idx ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            $rows = [];
        }

        foreach ($rows as &$row) {
            $row['sender_name'] = $this->resolveUserDisplayName((int)($row['sender_user_idx'] ?? 0));
        }
        unset($row);

        return $rows;
    }

    public function sendChatMessage(array $scope, int $roomId, int $userPk, int $roleLevel, array $payload): array
    {
        if (!$this->canAccessChatRoom($scope, $roomId, $userPk, $roleLevel)) {
            throw new RuntimeException('forbidden room access');
        }

        $messageType = strtoupper(trim((string)($payload['message_type'] ?? 'TEXT')));
        if (!in_array($messageType, ['TEXT', 'SYSTEM', 'FILE'], true)) {
            $messageType = 'TEXT';
        }

        $messageText = trim((string)($payload['message_text'] ?? ''));
        if ($messageText === '' && $messageType === 'TEXT') {
            throw new InvalidArgumentException('message_text is required');
        }

        $payloadJson = $payload['payload_json'] ?? null;
        if (is_array($payloadJson) || is_object($payloadJson)) {
            $payloadJson = json_encode($payloadJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $payloadJson = is_string($payloadJson) ? trim($payloadJson) : '';

        $sql = "INSERT INTO dbo." . $this->qi(self::TABLE_CHAT_MESSAGE) . "
                (room_idx, service_code, tenant_id, sender_user_idx, message_type, message_text, payload_json, is_deleted, created_at, updated_at)
                OUTPUT INSERTED.idx
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, GETDATE(), GETDATE())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $roomId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $userPk,
            $messageType,
            $messageText,
            $payloadJson,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $messageId = (int)($row['idx'] ?? 0);
        if ($messageId <= 0) {
            throw new RuntimeException('message insert failed');
        }

        $updateRoomSql = "UPDATE dbo." . $this->qi(self::TABLE_CHAT_ROOM) . "
                          SET last_message_at = GETDATE(),
                              updated_at = GETDATE()
                          WHERE idx = ?";
        $updateRoomStmt = $this->db->prepare($updateRoomSql);
        $updateRoomStmt->execute([$roomId]);

        $saved = $this->getChatMessageById($scope, $roomId, $messageId);
        if ($saved === null) {
            throw new RuntimeException('message reload failed');
        }

        $this->audit->log('groupware.chat.message_send', $userPk, 'OK', 'Chat message sent', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'room_id' => $roomId,
            'message_id' => $messageId,
        ]);

        return $saved;
    }

    public function deleteChatMessage(array $scope, int $roomId, int $messageId, int $userPk, int $roleLevel): bool
    {
        if (!$this->canAccessChatRoom($scope, $roomId, $userPk, $roleLevel)) {
            throw new RuntimeException('forbidden room access');
        }

        $sql = "SELECT TOP 1 sender_user_idx
                FROM dbo." . $this->qi(self::TABLE_CHAT_MESSAGE) . "
                WHERE idx = ?
                  AND room_idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $messageId,
            $roomId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }

        $senderUserPk = (int)($row['sender_user_idx'] ?? 0);
        if ($senderUserPk !== $userPk && !$this->isApproverRole($roleLevel)) {
            throw new RuntimeException('insufficient role level');
        }

        $deleteSql = "UPDATE dbo." . $this->qi(self::TABLE_CHAT_MESSAGE) . "
                      SET is_deleted = 1,
                          message_text = '(deleted)',
                          updated_at = GETDATE()
                      WHERE idx = ?
                        AND room_idx = ?";
        $deleteStmt = $this->db->prepare($deleteSql);
        $deleteStmt->execute([
            $messageId,
            $roomId,
        ]);

        if ($deleteStmt->rowCount() <= 0) {
            return false;
        }

        $this->audit->log('groupware.chat.message_delete', $userPk, 'OK', 'Chat message deleted', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'room_id' => $roomId,
            'message_id' => $messageId,
        ]);

        return true;
    }

    public function markChatRead(array $scope, int $roomId, int $userPk, int $roleLevel, int $lastMessageIdx): bool
    {
        if (!$this->canAccessChatRoom($scope, $roomId, $userPk, $roleLevel)) {
            throw new RuntimeException('forbidden room access');
        }

        if ($lastMessageIdx <= 0) {
            $lastMessageIdx = $this->findChatRoomLastMessageIdx($scope, $roomId);
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_CHAT_MEMBER) . "
                SET last_read_message_idx = CASE
                    WHEN ISNULL(last_read_message_idx, 0) < ? THEN ?
                    ELSE ISNULL(last_read_message_idx, 0)
                END
                WHERE room_idx = ?
                  AND user_idx = ?
                  AND left_at IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $lastMessageIdx,
            $lastMessageIdx,
            $roomId,
            $userPk,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function chatUnreadCount(array $scope, int $userPk): int
    {
        if ($userPk <= 0
            || !$this->tableExists(self::TABLE_CHAT_ROOM)
            || !$this->tableExists(self::TABLE_CHAT_MEMBER)
            || !$this->tableExists(self::TABLE_CHAT_MESSAGE)
        ) {
            return 0;
        }

        $sql = "SELECT COUNT(1) AS cnt
                FROM dbo." . $this->qi(self::TABLE_CHAT_MESSAGE) . " cm
                INNER JOIN dbo." . $this->qi(self::TABLE_CHAT_ROOM) . " r ON r.idx = cm.room_idx
                INNER JOIN dbo." . $this->qi(self::TABLE_CHAT_MEMBER) . " m ON m.room_idx = cm.room_idx AND m.user_idx = ? AND m.left_at IS NULL
                WHERE r.service_code = ?
                  AND r.tenant_id = ?
                  AND ISNULL(r.is_deleted, 0) = 0
                  AND ISNULL(cm.is_deleted, 0) = 0
                  AND ISNULL(cm.sender_user_idx, 0) <> ?
                  AND cm.idx > ISNULL(m.last_read_message_idx, 0)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $userPk,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $userPk,
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function createApprovalDraft(array $scope, int $writerUserPk, array $payload): array
    {
        $title = trim((string)($payload['title'] ?? ''));
        $bodyText = trim((string)($payload['body_text'] ?? $payload['content'] ?? ''));
        $docType = strtoupper(trim((string)($payload['doc_type'] ?? 'GENERAL')));
        $approverIds = $this->parseIntList($payload['approver_ids'] ?? []);

        if ($title === '') {
            throw new InvalidArgumentException('title is required');
        }

        if (!in_array($docType, ['GENERAL', 'OFFICIAL'], true)) {
            $docType = 'GENERAL';
        }

        if ($approverIds === []) {
            throw new InvalidArgumentException('approver_ids is required');
        }

        try {
            $this->db->beginTransaction();

            $docNo = $this->nextApprovalDocNo($scope);

            $docSql = "INSERT INTO dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . "
                       (service_code, tenant_id, doc_no, doc_type, title, body_text, writer_user_idx, status, current_line_order, submitted_at, completed_at, is_deleted, created_at, updated_at)
                       OUTPUT INSERTED.idx
                       VALUES (?, ?, ?, ?, ?, ?, ?, 'DRAFT', 1, NULL, NULL, 0, GETDATE(), GETDATE())";
            $docStmt = $this->db->prepare($docSql);
            $docStmt->execute([
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
                $docNo,
                $docType,
                $title,
                $bodyText,
                $writerUserPk,
            ]);
            $docRow = $docStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $docId = (int)($docRow['idx'] ?? 0);
            if ($docId <= 0) {
                throw new RuntimeException('approval doc insert failed');
            }

            $lineSql = "INSERT INTO dbo." . $this->qi(self::TABLE_APPROVAL_LINE) . "
                        (doc_idx, line_order, approver_user_idx, decision_status, decided_at, comment_text, created_at, updated_at)
                        VALUES (?, ?, ?, 'PENDING', NULL, '', GETDATE(), GETDATE())";
            $lineStmt = $this->db->prepare($lineSql);
            $lineOrder = 1;
            foreach ($approverIds as $approverUserPk) {
                $lineStmt->execute([
                    $docId,
                    $lineOrder,
                    $approverUserPk,
                ]);
                $lineOrder++;
            }

            $this->db->commit();

            $this->audit->log('groupware.approval.create', $writerUserPk, 'OK', 'Approval draft created', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'doc_id' => $docId,
                'doc_no' => $docNo,
            ]);

            $doc = $this->getApprovalDocument($scope, $docId, $writerUserPk, 5);
            if ($doc === null) {
                throw new RuntimeException('approval doc reload failed');
            }

            return $doc;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function submitApproval(array $scope, int $docId, int $actorUserPk, int $roleLevel): array
    {
        $doc = $this->loadApprovalDocRow($scope, $docId);
        if ($doc === null) {
            throw new RuntimeException('approval doc not found');
        }

        if ((int)($doc['writer_user_idx'] ?? 0) !== $actorUserPk && !$this->isApproverRole($roleLevel)) {
            throw new RuntimeException('insufficient role level');
        }

        $status = strtoupper((string)($doc['status'] ?? ''));
        if (!in_array($status, ['DRAFT', 'REJECTED'], true)) {
            throw new RuntimeException('approval doc status is not submittable');
        }

        $line = $this->loadNextPendingApprovalLine($docId);
        if ($line === null) {
            throw new RuntimeException('approval line missing');
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . "
                SET status = 'SUBMITTED',
                    current_line_order = ?,
                    submitted_at = GETDATE(),
                    completed_at = NULL,
                    updated_at = GETDATE()
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (int)$line['line_order'],
            $docId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $this->audit->log('groupware.approval.submit', $actorUserPk, 'OK', 'Approval submitted', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'doc_id' => $docId,
        ]);

        $saved = $this->getApprovalDocument($scope, $docId, $actorUserPk, 5);
        if ($saved === null) {
            throw new RuntimeException('approval doc reload failed');
        }

        return $saved;
    }

    public function approvalAction(
        array $scope,
        int $docId,
        int $actorUserPk,
        int $roleLevel,
        string $action,
        string $comment
    ): array {
        $action = strtolower(trim($action));
        if (!in_array($action, ['approve', 'reject', 'cancel'], true)) {
            throw new InvalidArgumentException('invalid approval action');
        }

        $doc = $this->loadApprovalDocRow($scope, $docId);
        if ($doc === null) {
            throw new RuntimeException('approval doc not found');
        }

        $status = strtoupper((string)($doc['status'] ?? ''));

        try {
            $this->db->beginTransaction();

            if ($action === 'cancel') {
                if ((int)($doc['writer_user_idx'] ?? 0) !== $actorUserPk && !$this->isApproverRole($roleLevel)) {
                    throw new RuntimeException('insufficient role level');
                }

                if (!in_array($status, ['DRAFT', 'SUBMITTED', 'REJECTED'], true)) {
                    throw new RuntimeException('approval doc is not cancelable');
                }

                $cancelSql = "UPDATE dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . "
                              SET status = 'CANCELED',
                                  completed_at = GETDATE(),
                                  updated_at = GETDATE()
                              WHERE idx = ?
                                AND service_code = ?
                                AND tenant_id = ?
                                AND ISNULL(is_deleted, 0) = 0";
                $cancelStmt = $this->db->prepare($cancelSql);
                $cancelStmt->execute([
                    $docId,
                    (string)$scope['service_code'],
                    (int)$scope['tenant_id'],
                ]);

                $this->insertApprovalComment($docId, $actorUserPk, 'CANCEL', $comment);
            } else {
                if ($status !== 'SUBMITTED') {
                    throw new RuntimeException('approval doc is not in SUBMITTED status');
                }

                $line = $this->loadNextPendingApprovalLine($docId);
                if ($line === null) {
                    throw new RuntimeException('pending approval line not found');
                }

                $currentLineOrder = (int)($doc['current_line_order'] ?? 0);
                $lineOrder = (int)($line['line_order'] ?? 0);
                if ($currentLineOrder <= 0 || $lineOrder <= 0) {
                    throw new RuntimeException('approval current line is invalid');
                }
                if ($lineOrder !== $currentLineOrder) {
                    throw new RuntimeException('approval line order mismatch');
                }

                $lineApproverPk = (int)($line['approver_user_idx'] ?? 0);
                if ($lineApproverPk !== $actorUserPk && !$this->isApproverRole($roleLevel)) {
                    throw new RuntimeException('insufficient role level');
                }

                $decision = strtoupper($action === 'approve' ? 'APPROVED' : 'REJECTED');
                $lineSql = "UPDATE dbo." . $this->qi(self::TABLE_APPROVAL_LINE) . "
                            SET decision_status = ?,
                                decided_at = GETDATE(),
                                comment_text = ?,
                                updated_at = GETDATE()
                            WHERE idx = ?
                              AND ISNULL(decision_status, 'PENDING') = 'PENDING'";
                $lineStmt = $this->db->prepare($lineSql);
                $lineStmt->execute([
                    $decision,
                    $comment,
                    (int)$line['idx'],
                ]);
                if ($lineStmt->rowCount() <= 0) {
                    throw new RuntimeException('approval line already processed');
                }

                if ($decision === 'REJECTED') {
                    $docSql = "UPDATE dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . "
                               SET status = 'REJECTED',
                                   completed_at = GETDATE(),
                                   updated_at = GETDATE()
                               WHERE idx = ?";
                    $docStmt = $this->db->prepare($docSql);
                    $docStmt->execute([$docId]);
                    $this->insertApprovalComment($docId, $actorUserPk, 'REJECT', $comment);
                } else {
                    $nextLine = $this->loadNextPendingApprovalLine($docId);
                    if ($nextLine === null) {
                        $docSql = "UPDATE dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . "
                                   SET status = 'APPROVED',
                                       current_line_order = NULL,
                                       completed_at = GETDATE(),
                                       updated_at = GETDATE()
                                   WHERE idx = ?";
                        $docStmt = $this->db->prepare($docSql);
                        $docStmt->execute([$docId]);
                    } else {
                        $docSql = "UPDATE dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . "
                                   SET current_line_order = ?,
                                       updated_at = GETDATE()
                                   WHERE idx = ?";
                        $docStmt = $this->db->prepare($docSql);
                        $docStmt->execute([
                            (int)$nextLine['line_order'],
                            $docId,
                        ]);
                    }

                    $this->insertApprovalComment($docId, $actorUserPk, 'APPROVE', $comment);
                }
            }

            $this->db->commit();

            $this->audit->log('groupware.approval.action', $actorUserPk, 'OK', 'Approval action applied', [
                'service_code' => (string)$scope['service_code'],
                'tenant_id' => (int)$scope['tenant_id'],
                'doc_id' => $docId,
                'action' => $action,
            ]);

            $saved = $this->getApprovalDocument($scope, $docId, $actorUserPk, 5);
            if ($saved === null) {
                throw new RuntimeException('approval doc reload failed');
            }

            return $saved;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function addApprovalComment(array $scope, int $docId, int $userPk, int $roleLevel, string $comment): array
    {
        if (trim($comment) === '') {
            throw new InvalidArgumentException('comment is required');
        }

        $doc = $this->loadApprovalDocRow($scope, $docId);
        if ($doc === null) {
            throw new RuntimeException('approval doc not found');
        }

        if (!$this->canAccessApprovalDoc($docId, $userPk, $roleLevel, (int)($doc['writer_user_idx'] ?? 0))) {
            throw new RuntimeException('forbidden approval access');
        }

        $this->insertApprovalComment($docId, $userPk, 'COMMENT', $comment);

        $this->audit->log('groupware.approval.comment', $userPk, 'OK', 'Approval comment added', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'doc_id' => $docId,
        ]);

        $saved = $this->getApprovalDocument($scope, $docId, $userPk, 5);
        if ($saved === null) {
            throw new RuntimeException('approval doc reload failed');
        }

        return $saved;
    }

    public function listApprovalReq(array $scope, int $userPk): array
    {
        if ($userPk <= 0 || !$this->tableExists(self::TABLE_APPROVAL_DOC) || !$this->tableExists(self::TABLE_APPROVAL_LINE)) {
            return [];
        }

        $sql = "SELECT
                    d.idx,
                    d.doc_no,
                    ISNULL(d.doc_type, 'GENERAL') AS doc_type,
                    ISNULL(d.title, '') AS title,
                    ISNULL(d.writer_user_idx, 0) AS writer_user_idx,
                    ISNULL(d.status, 'DRAFT') AS status,
                    ISNULL(l.line_order, 0) AS line_order,
                    d.submitted_at,
                    d.created_at,
                    d.updated_at
                FROM dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . " d
                INNER JOIN dbo." . $this->qi(self::TABLE_APPROVAL_LINE) . " l
                  ON l.doc_idx = d.idx
                WHERE d.service_code = ?
                  AND d.tenant_id = ?
                  AND ISNULL(d.is_deleted, 0) = 0
                  AND ISNULL(d.status, 'DRAFT') = 'SUBMITTED'
                  AND ISNULL(d.current_line_order, 0) = ISNULL(l.line_order, 0)
                  AND l.approver_user_idx = ?
                  AND ISNULL(l.decision_status, 'PENDING') = 'PENDING'
                ORDER BY d.submitted_at DESC, d.idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $userPk,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['writer_name'] = $this->resolveUserDisplayName((int)($row['writer_user_idx'] ?? 0));
        }
        unset($row);

        return $rows;
    }

    public function listApprovalDone(array $scope, int $userPk): array
    {
        if ($userPk <= 0 || !$this->tableExists(self::TABLE_APPROVAL_DOC) || !$this->tableExists(self::TABLE_APPROVAL_LINE)) {
            return [];
        }

        $sql = "SELECT
                    d.idx,
                    d.doc_no,
                    ISNULL(d.doc_type, 'GENERAL') AS doc_type,
                    ISNULL(d.title, '') AS title,
                    ISNULL(d.writer_user_idx, 0) AS writer_user_idx,
                    ISNULL(d.status, 'DRAFT') AS status,
                    ISNULL(l.decision_status, 'PENDING') AS my_decision,
                    l.decided_at,
                    d.completed_at,
                    d.updated_at
                FROM dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . " d
                INNER JOIN dbo." . $this->qi(self::TABLE_APPROVAL_LINE) . " l
                  ON l.doc_idx = d.idx
                WHERE d.service_code = ?
                  AND d.tenant_id = ?
                  AND ISNULL(d.is_deleted, 0) = 0
                  AND l.approver_user_idx = ?
                  AND ISNULL(l.decision_status, 'PENDING') IN ('APPROVED', 'REJECTED')
                ORDER BY ISNULL(l.decided_at, d.updated_at) DESC, d.idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $userPk,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['writer_name'] = $this->resolveUserDisplayName((int)($row['writer_user_idx'] ?? 0));
        }
        unset($row);

        return $rows;
    }

    public function listApprovalAll(array $scope, array $filters = []): array
    {
        if (!$this->tableExists(self::TABLE_APPROVAL_DOC)) {
            return [];
        }

        $where = [
            'service_code = ?',
            'tenant_id = ?',
            'ISNULL(is_deleted, 0) = 0',
        ];
        $params = [
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ];

        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status !== '') {
            $where[] = 'UPPER(ISNULL(status, \'DRAFT\')) = ?';
            $params[] = $status;
        } else {
            $where[] = "ISNULL(status, 'DRAFT') IN ('SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELED')";
        }

        $docType = strtoupper(trim((string)($filters['doc_type'] ?? '')));
        if ($docType !== '') {
            $where[] = 'UPPER(ISNULL(doc_type, \'GENERAL\')) = ?';
            $params[] = $docType;
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(title LIKE ? OR doc_no LIKE ?)';
            $sp = '%' . $search . '%';
            $params[] = $sp;
            $params[] = $sp;
        }

        $limit = min(500, max(1, (int)($filters['limit'] ?? 200)));

        $sql = "SELECT TOP {$limit}
                    idx,
                    ISNULL(doc_no, '') AS doc_no,
                    ISNULL(doc_type, 'GENERAL') AS doc_type,
                    ISNULL(title, '') AS title,
                    ISNULL(writer_user_idx, 0) AS writer_user_idx,
                    ISNULL(status, 'DRAFT') AS status,
                    ISNULL(current_line_order, 0) AS current_line_order,
                    submitted_at,
                    completed_at,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ISNULL(updated_at, created_at) DESC, idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['writer_name'] = $this->resolveUserDisplayName((int)($row['writer_user_idx'] ?? 0));
        }
        unset($row);

        return $rows;
    }

    public function listApprovalOfficial(array $scope, array $filters = []): array
    {
        $filters['doc_type'] = 'OFFICIAL';
        return $this->listApprovalAll($scope, $filters);
    }

    public function getApprovalDocument(array $scope, int $docId, int $userPk, int $roleLevel): ?array
    {
        if ($docId <= 0 || !$this->tableExists(self::TABLE_APPROVAL_DOC)) {
            return null;
        }

        $doc = $this->loadApprovalDocRow($scope, $docId);
        if ($doc === null) {
            return null;
        }

        $writerUserPk = (int)($doc['writer_user_idx'] ?? 0);
        if (!$this->canAccessApprovalDoc($docId, $userPk, $roleLevel, $writerUserPk)) {
            return null;
        }

        $doc['writer_name'] = $this->resolveUserDisplayName($writerUserPk);

        $lineSql = "SELECT
                        idx,
                        doc_idx,
                        line_order,
                        approver_user_idx,
                        ISNULL(decision_status, 'PENDING') AS decision_status,
                        decided_at,
                        ISNULL(comment_text, '') AS comment_text,
                        created_at,
                        updated_at
                    FROM dbo." . $this->qi(self::TABLE_APPROVAL_LINE) . "
                    WHERE doc_idx = ?
                    ORDER BY line_order ASC, idx ASC";
        $lineStmt = $this->db->prepare($lineSql);
        $lineStmt->execute([$docId]);
        $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($lines)) {
            $lines = [];
        }

        foreach ($lines as &$line) {
            $line['approver_name'] = $this->resolveUserDisplayName((int)($line['approver_user_idx'] ?? 0));
        }
        unset($line);

        $commentSql = "SELECT
                           idx,
                           doc_idx,
                           user_idx,
                           ISNULL(comment_type, 'COMMENT') AS comment_type,
                           ISNULL(comment_text, '') AS comment_text,
                           created_at
                       FROM dbo." . $this->qi(self::TABLE_APPROVAL_COMMENT) . "
                       WHERE doc_idx = ?
                       ORDER BY idx ASC";
        $commentStmt = $this->db->prepare($commentSql);
        $commentStmt->execute([$docId]);
        $comments = $commentStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($comments)) {
            $comments = [];
        }

        foreach ($comments as &$comment) {
            $comment['user_name'] = $this->resolveUserDisplayName((int)($comment['user_idx'] ?? 0));
        }
        unset($comment);

        $doc['lines'] = $lines;
        $doc['comments'] = $comments;

        return $doc;
    }

    public function approvalPendingMyCount(array $scope, int $userPk): int
    {
        if ($userPk <= 0 || !$this->tableExists(self::TABLE_APPROVAL_DOC) || !$this->tableExists(self::TABLE_APPROVAL_LINE)) {
            return 0;
        }

        $sql = "SELECT COUNT(1) AS cnt
                FROM dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . " d
                INNER JOIN dbo." . $this->qi(self::TABLE_APPROVAL_LINE) . " l ON l.doc_idx = d.idx
                WHERE d.service_code = ?
                  AND d.tenant_id = ?
                  AND ISNULL(d.is_deleted, 0) = 0
                  AND ISNULL(d.status, 'DRAFT') = 'SUBMITTED'
                  AND l.approver_user_idx = ?
                  AND ISNULL(l.decision_status, 'PENDING') = 'PENDING'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $userPk,
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function getDepartmentById(array $scope, int $deptId): ?array
    {
        if ($deptId <= 0) {
            return null;
        }

        $sql = "SELECT TOP 1
                    idx,
                    ISNULL(parent_idx, 0) AS parent_idx,
                    ISNULL(dept_code, '') AS dept_code,
                    ISNULL(dept_name, '') AS dept_name,
                    ISNULL(dept_type, '') AS dept_type,
                    ISNULL(address, '') AS address,
                    ISNULL(tel, '') AS tel,
                    ISNULL(depth, 0) AS depth,
                    ISNULL(sort_order, 0) AS sort_order,
                    CAST(ISNULL(is_active, 1) AS INT) AS is_active,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $deptId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function resolveDepartmentDepth(array $scope, int $parentIdx): int
    {
        if ($parentIdx <= 0) {
            return 0;
        }

        $parent = $this->getDepartmentById($scope, $parentIdx);
        if ($parent === null) {
            return 0;
        }

        return max(0, (int)($parent['depth'] ?? 0)) + 1;
    }

    private function nextDepartmentSortOrder(array $scope, int $parentIdx): int
    {
        if (!$this->tableExists(self::TABLE_DEPARTMENT)) {
            return 1;
        }

        $sql = "SELECT ISNULL(MAX(sort_order), 0) + 1 AS next_sort
                FROM dbo." . $this->qi(self::TABLE_DEPARTMENT) . "
                WHERE service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0
                  AND ISNULL(parent_idx, 0) = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            max(0, $parentIdx),
        ]);

        $next = (int)$stmt->fetchColumn();
        return $next > 0 ? $next : 1;
    }

    private function normalizeSettingGroup(string $settingGroup): string
    {
        $settingGroup = trim($settingGroup);
        if ($settingGroup === '') {
            return 'groupware';
        }

        if (mb_strlen($settingGroup, 'UTF-8') > 60) {
            return mb_substr($settingGroup, 0, 60, 'UTF-8');
        }

        return $settingGroup;
    }

    private function getPhoneBookById(array $scope, int $rowId): ?array
    {
        if ($rowId <= 0) {
            return null;
        }

        $sql = "SELECT TOP 1
                    idx,
                    ISNULL(employee_idx, 0) AS employee_idx,
                    ISNULL(contact_name, '') AS contact_name,
                    ISNULL(company_name, '') AS company_name,
                    ISNULL(department_name, '') AS department_name,
                    ISNULL(position_name, '') AS position_name,
                    ISNULL(phone, '') AS phone,
                    ISNULL(email, '') AS email,
                    ISNULL(memo, '') AS memo,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_PHONEBOOK) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $rowId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function getAttendanceById(array $scope, int $rowId): ?array
    {
        if ($rowId <= 0) {
            return null;
        }

        $sql = "SELECT TOP 1
                    a.idx,
                    a.employee_idx,
                    a.work_date,
                    a.check_in,
                    a.check_out,
                    ISNULL(a.work_minutes, 0) AS work_minutes,
                    ISNULL(a.status, 'NORMAL') AS status,
                    ISNULL(a.note, '') AS note,
                    a.created_at,
                    a.updated_at,
                    ISNULL(e.emp_name, '') AS employee_name
                FROM dbo." . $this->qi(self::TABLE_ATTENDANCE) . " a
                LEFT JOIN dbo." . $this->qi(self::TABLE_EMPLOYEE) . " e
                  ON e.idx = a.employee_idx
                 AND e.service_code = a.service_code
                 AND e.tenant_id = a.tenant_id
                 AND ISNULL(e.is_deleted, 0) = 0
                WHERE a.idx = ?
                  AND a.service_code = ?
                  AND a.tenant_id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $rowId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function findAttendanceByEmployeeAndDate(array $scope, int $employeeIdx, string $workDate): ?array
    {
        $sql = "SELECT TOP 1 idx
                FROM dbo." . $this->qi(self::TABLE_ATTENDANCE) . "
                WHERE service_code = ?
                  AND tenant_id = ?
                  AND employee_idx = ?
                  AND work_date = ?
                ORDER BY idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $employeeIdx,
            $workDate,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function getHolidayById(array $scope, int $rowId): ?array
    {
        if ($rowId <= 0) {
            return null;
        }

        $sql = "SELECT TOP 1
                    h.idx,
                    h.employee_idx,
                    ISNULL(h.holiday_type, 'ANNUAL') AS holiday_type,
                    h.start_date,
                    h.end_date,
                    ISNULL(h.reason, '') AS reason,
                    ISNULL(h.status, 'REQUESTED') AS status,
                    ISNULL(h.approved_by, 0) AS approved_by,
                    h.approved_at,
                    h.created_at,
                    h.updated_at,
                    ISNULL(e.emp_name, '') AS employee_name
                FROM dbo." . $this->qi(self::TABLE_HOLIDAY) . " h
                LEFT JOIN dbo." . $this->qi(self::TABLE_EMPLOYEE) . " e
                  ON e.idx = h.employee_idx
                 AND e.service_code = h.service_code
                 AND e.tenant_id = h.tenant_id
                 AND ISNULL(e.is_deleted, 0) = 0
                WHERE h.idx = ?
                  AND h.service_code = ?
                  AND h.tenant_id = ?
                  AND ISNULL(h.is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $rowId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function getOvertimeById(array $scope, int $rowId): ?array
    {
        if ($rowId <= 0) {
            return null;
        }

        $sql = "SELECT TOP 1
                    o.idx,
                    o.employee_idx,
                    o.work_date,
                    o.start_time,
                    o.end_time,
                    ISNULL(o.minutes, 0) AS minutes,
                    ISNULL(o.reason, '') AS reason,
                    ISNULL(o.status, 'REQUESTED') AS status,
                    ISNULL(o.approved_by, 0) AS approved_by,
                    o.approved_at,
                    o.created_at,
                    o.updated_at,
                    ISNULL(e.emp_name, '') AS employee_name
                FROM dbo." . $this->qi(self::TABLE_OVERTIME) . " o
                LEFT JOIN dbo." . $this->qi(self::TABLE_EMPLOYEE) . " e
                  ON e.idx = o.employee_idx
                 AND e.service_code = o.service_code
                 AND e.tenant_id = o.tenant_id
                 AND ISNULL(e.is_deleted, 0) = 0
                WHERE o.idx = ?
                  AND o.service_code = ?
                  AND o.tenant_id = ?
                  AND ISNULL(o.is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $rowId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function getHolidayStatusRow(array $scope, int $holidayId): ?array
    {
        $sql = "SELECT TOP 1
                    idx,
                    employee_idx,
                    ISNULL(status, 'REQUESTED') AS status,
                    ISNULL(created_by, 0) AS created_by
                FROM dbo." . $this->qi(self::TABLE_HOLIDAY) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $holidayId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function getOvertimeStatusRow(array $scope, int $overtimeId): ?array
    {
        $sql = "SELECT TOP 1
                    idx,
                    employee_idx,
                    ISNULL(status, 'REQUESTED') AS status,
                    ISNULL(created_by, 0) AS created_by
                FROM dbo." . $this->qi(self::TABLE_OVERTIME) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $overtimeId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function resolveEmployeeUserPk(array $scope, int $employeeIdx): int
    {
        if ($employeeIdx <= 0) {
            return 0;
        }

        $sql = "SELECT TOP 1 ISNULL(user_idx, 0) AS user_idx
                FROM dbo." . $this->qi(self::TABLE_EMPLOYEE) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $employeeIdx,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return (int)($row['user_idx'] ?? 0);
    }

    private function canAccessChatRoom(array $scope, int $roomId, int $userPk, int $roleLevel): bool
    {
        if ($this->isApproverRole($roleLevel)) {
            $sql = "SELECT COUNT(1)
                    FROM dbo." . $this->qi(self::TABLE_CHAT_ROOM) . "
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $roomId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);
            return (int)$stmt->fetchColumn() > 0;
        }

        if ($userPk <= 0) {
            return false;
        }

        $sql = "SELECT COUNT(1)
                FROM dbo." . $this->qi(self::TABLE_CHAT_MEMBER) . " m
                INNER JOIN dbo." . $this->qi(self::TABLE_CHAT_ROOM) . " r ON r.idx = m.room_idx
                WHERE m.room_idx = ?
                  AND m.user_idx = ?
                  AND m.left_at IS NULL
                  AND r.service_code = ?
                  AND r.tenant_id = ?
                  AND ISNULL(r.is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $roomId,
            $userPk,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function upsertChatMember(int $roomId, int $userPk, string $role): void
    {
        $checkSql = "SELECT TOP 1 idx, left_at
                     FROM dbo." . $this->qi(self::TABLE_CHAT_MEMBER) . "
                     WHERE room_idx = ?
                       AND user_idx = ?";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([
            $roomId,
            $userPk,
        ]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            $updateSql = "UPDATE dbo." . $this->qi(self::TABLE_CHAT_MEMBER) . "
                          SET member_role = ?,
                              joined_at = CASE WHEN joined_at IS NULL THEN GETDATE() ELSE joined_at END,
                              left_at = NULL
                          WHERE idx = ?";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([
                $role,
                (int)$existing['idx'],
            ]);
            return;
        }

        $insertSql = "INSERT INTO dbo." . $this->qi(self::TABLE_CHAT_MEMBER) . "
                      (room_idx, user_idx, member_role, joined_at, left_at, last_read_message_idx, is_muted)
                      VALUES (?, ?, ?, GETDATE(), NULL, 0, 0)";
        $insertStmt = $this->db->prepare($insertSql);
        $insertStmt->execute([
            $roomId,
            $userPk,
            $role,
        ]);
    }

    private function getChatMessageById(array $scope, int $roomId, int $messageId): ?array
    {
        $sql = "SELECT TOP 1
                    idx,
                    room_idx,
                    ISNULL(sender_user_idx, 0) AS sender_user_idx,
                    ISNULL(message_type, 'TEXT') AS message_type,
                    ISNULL(message_text, '') AS message_text,
                    ISNULL(payload_json, '') AS payload_json,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_CHAT_MESSAGE) . "
                WHERE idx = ?
                  AND room_idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $messageId,
            $roomId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $row['sender_name'] = $this->resolveUserDisplayName((int)($row['sender_user_idx'] ?? 0));
        return $row;
    }

    private function findChatRoomLastMessageIdx(array $scope, int $roomId): int
    {
        $sql = "SELECT ISNULL(MAX(idx), 0)
                FROM dbo." . $this->qi(self::TABLE_CHAT_MESSAGE) . "
                WHERE room_idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $roomId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function loadApprovalDocRow(array $scope, int $docId): ?array
    {
        $sql = "SELECT TOP 1
                    idx,
                    service_code,
                    tenant_id,
                    doc_no,
                    doc_type,
                    title,
                    body_text,
                    writer_user_idx,
                    status,
                    current_line_order,
                    submitted_at,
                    completed_at,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $docId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function loadNextPendingApprovalLine(int $docId): ?array
    {
        $sql = "SELECT TOP 1
                    idx,
                    doc_idx,
                    line_order,
                    approver_user_idx,
                    decision_status
                FROM dbo." . $this->qi(self::TABLE_APPROVAL_LINE) . "
                WHERE doc_idx = ?
                  AND ISNULL(decision_status, 'PENDING') = 'PENDING'
                ORDER BY line_order ASC, idx ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$docId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function canAccessApprovalDoc(int $docId, int $userPk, int $roleLevel, int $writerUserPk): bool
    {
        if ($this->isApproverRole($roleLevel)) {
            return true;
        }

        if ($userPk <= 0) {
            return false;
        }

        if ($writerUserPk === $userPk) {
            return true;
        }

        $sql = "SELECT COUNT(1)
                FROM dbo." . $this->qi(self::TABLE_APPROVAL_LINE) . "
                WHERE doc_idx = ?
                  AND approver_user_idx = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $docId,
            $userPk,
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function insertApprovalComment(int $docId, int $userPk, string $commentType, string $commentText): void
    {
        $sql = "INSERT INTO dbo." . $this->qi(self::TABLE_APPROVAL_COMMENT) . "
                (doc_idx, user_idx, comment_type, comment_text, created_at)
                VALUES (?, ?, ?, ?, GETDATE())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $docId,
            $userPk,
            strtoupper(trim($commentType)) === '' ? 'COMMENT' : strtoupper(trim($commentType)),
            trim($commentText),
        ]);
    }

    private function nextApprovalDocNo(array $scope): string
    {
        $prefix = 'APV-' . date('Ymd') . '-';

        $sql = "SELECT TOP 1 doc_no
                FROM dbo." . $this->qi(self::TABLE_APPROVAL_DOC) . "
                WHERE service_code = ?
                  AND tenant_id = ?
                  AND doc_no LIKE ?
                ORDER BY idx DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $prefix . '%',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $lastDocNo = trim((string)($row['doc_no'] ?? ''));

        $seq = 1;
        if ($lastDocNo !== '' && str_starts_with($lastDocNo, $prefix)) {
            $tail = substr($lastDocNo, strlen($prefix));
            if (ctype_digit((string)$tail)) {
                $seq = ((int)$tail) + 1;
            }
        }

        return $prefix . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
    }

    private function resolveUserDisplayName(int $userPk): string
    {
        if ($userPk <= 0) {
            return '';
        }

        if (isset($this->userNameCache[$userPk])) {
            return (string)$this->userNameCache[$userPk];
        }

        $table = $this->normalizeIdentifier((string)($this->security['auth']['user_table'] ?? 'Tb_Users'), 'Tb_Users');
        $pkCol = $this->normalizeIdentifier((string)($this->security['auth']['user_pk_column'] ?? 'idx'), 'idx');
        $nameCandidates = ['name', 'user_name', 'nickname'];
        $loginCandidates = [
            (string)($this->security['auth']['user_login_column'] ?? 'id'),
            'login_id',
            'id',
        ];

        $nameCol = null;
        foreach ($nameCandidates as $candidate) {
            if ($this->columnExists($table, $candidate)) {
                $nameCol = $candidate;
                break;
            }
        }

        $loginCol = null;
        foreach ($loginCandidates as $candidate) {
            $candidate = $this->normalizeIdentifier($candidate, 'id');
            if ($this->columnExists($table, $candidate)) {
                $loginCol = $candidate;
                break;
            }
        }

        if (!$this->tableExists($table) || !$this->columnExists($table, $pkCol) || ($nameCol === null && $loginCol === null)) {
            $this->userNameCache[$userPk] = '#' . $userPk;
            return (string)$this->userNameCache[$userPk];
        }

        $selectExpr = $nameCol !== null
            ? 'ISNULL(' . $this->qi($nameCol) . ", '') AS display_name"
            : 'ISNULL(' . $this->qi((string)$loginCol) . ", '') AS display_name";

        $sql = 'SELECT TOP 1 ' . $selectExpr
             . ' FROM dbo.' . $this->qi($table)
             . ' WHERE ' . $this->qi($pkCol) . ' = ?';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userPk]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $displayName = trim((string)($row['display_name'] ?? ''));
        if ($displayName === '' && $loginCol !== null && $nameCol !== null) {
            $fallbackSql = 'SELECT TOP 1 ISNULL(' . $this->qi($loginCol) . ", '') AS display_name"
                         . ' FROM dbo.' . $this->qi($table)
                         . ' WHERE ' . $this->qi($pkCol) . ' = ?';
            $fallbackStmt = $this->db->prepare($fallbackSql);
            $fallbackStmt->execute([$userPk]);
            $fallbackRow = $fallbackStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $displayName = trim((string)($fallbackRow['display_name'] ?? ''));
        }

        if ($displayName === '') {
            $displayName = '#' . $userPk;
        }

        $this->userNameCache[$userPk] = $displayName;
        return $displayName;
    }

    private function employeeExistsById(array $scope, int $employeeId): bool
    {
        if ($employeeId <= 0 || !$this->tableExists(self::TABLE_EMPLOYEE)) {
            return false;
        }

        $sql = 'SELECT TOP 1 idx FROM dbo.' . $this->qi(self::TABLE_EMPLOYEE)
            . ' WHERE idx = ?'
            . ' AND service_code = ?'
            . ' AND tenant_id = ?'
            . ' AND ISNULL(is_deleted, 0) = 0';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $employeeId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function getEmployeeCardById(array $scope, int $rowId): ?array
    {
        if ($rowId <= 0 || !$this->tableExists(self::TABLE_EMPLOYEE_CARD)) {
            return null;
        }

        $sql = "SELECT TOP 1
                    idx,
                    ISNULL(employee_idx, 0) AS employee_idx,
                    ISNULL(card_usage, '') AS card_usage,
                    ISNULL(card_name, '') AS card_name,
                    ISNULL(card_number, '') AS card_number,
                    ISNULL(card_memo, '') AS card_memo,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_EMPLOYEE_CARD) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $rowId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function countRows(string $table, array $scope, string $extraWhere = '', array $params = []): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        $sql = 'SELECT COUNT(1) AS cnt FROM dbo.' . $this->qi($table)
             . ' WHERE service_code = ? AND tenant_id = ?';
        $bind = [
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ];

        if (trim($extraWhere) !== '') {
            $sql .= ' AND ' . $extraWhere;
            $bind = array_merge($bind, $params);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);

        return (int)$stmt->fetchColumn();
    }

    private function tableExists(string $tableName): bool
    {
        $tableName = $this->normalizeIdentifier($tableName, '');
        if ($tableName === '') {
            return false;
        }

        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return (bool)$this->tableExistsCache[$tableName];
        }

        try {
            $stmt = $this->db->prepare('SELECT CASE WHEN OBJECT_ID(?, \'U\') IS NULL THEN 0 ELSE 1 END AS exists_yn');
            $stmt->execute(['dbo.' . $tableName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $exists = (int)($row['exists_yn'] ?? 0) === 1;
        } catch (Throwable) {
            $exists = false;
        }

        $this->tableExistsCache[$tableName] = $exists;
        return $exists;
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $tableName = $this->normalizeIdentifier($tableName, '');
        $columnName = $this->normalizeIdentifier($columnName, '');
        if ($tableName === '' || $columnName === '') {
            return false;
        }

        $cacheKey = strtolower($tableName . '.' . $columnName);
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return (bool)$this->columnExistsCache[$cacheKey];
        }

        try {
            $stmt = $this->db->prepare('SELECT CASE WHEN COL_LENGTH(?, ?) IS NULL THEN 0 ELSE 1 END AS exists_yn');
            $stmt->execute(['dbo.' . $tableName, $columnName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $exists = (int)($row['exists_yn'] ?? 0) === 1;
        } catch (Throwable) {
            $exists = false;
        }

        $this->columnExistsCache[$cacheKey] = $exists;
        return $exists;
    }

    private function normalizeIdentifier(string $value, string $default): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $default;
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $trimmed) !== 1) {
            return $default;
        }

        return $trimmed;
    }

    private function qi(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    private function normalizeDate($value): ?string
    {
        $str = trim((string)$value);
        if ($str === '') {
            return null;
        }

        $ts = strtotime($str);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }

    private function normalizeDateTime($value): ?string
    {
        $str = trim((string)$value);
        if ($str === '') {
            return null;
        }

        $ts = strtotime($str);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function normalizeNullableInt($value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int)round($value);
        }

        $str = trim((string)$value);
        if ($str === '') {
            return null;
        }

        $str = str_replace([',', ' '], '', $str);
        if (!preg_match('/^-?[0-9]+$/', $str)) {
            return null;
        }

        return (int)$str;
    }

    private function parseIntList($value): array
    {
        $items = [];

        if (is_array($value)) {
            $source = $value;
        } else {
            $source = preg_split('/[\s,]+/', trim((string)$value)) ?: [];
        }

        foreach ($source as $token) {
            $token = trim((string)$token);
            if ($token === '' || !ctype_digit($token)) {
                continue;
            }

            $id = (int)$token;
            if ($id > 0) {
                $items[$id] = true;
            }
        }

        return array_map('intval', array_keys($items));
    }

    private function calcWorkMinutes(?string $startDateTime, ?string $endDateTime): int
    {
        if ($startDateTime === null || $endDateTime === null) {
            return 0;
        }

        $startTs = strtotime($startDateTime);
        $endTs = strtotime($endDateTime);
        if ($startTs === false || $endTs === false || $endTs <= $startTs) {
            return 0;
        }

        return (int)floor(($endTs - $startTs) / 60);
    }

    private function toBoolInt($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $str = strtolower(trim((string)$value));
        if (in_array($str, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return 1;
        }

        return 0;
    }

    private function isApproverRole(int $roleLevel): bool
    {
        return $roleLevel >= 1 && $roleLevel <= self::ROLE_APPROVER_MAX_IDX;
    }
}
