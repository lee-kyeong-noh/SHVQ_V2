<?php
declare(strict_types=1);

require_once __DIR__ . '/ShadowWriteQueueService.php';

final class TenantService
{
    private PDO $db;
    private array $security;
    private ShadowWriteQueueService $shadowQueue;
    private array $tableExistsCache = [];

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $this->security = $security;
        $this->shadowQueue = new ShadowWriteQueueService($db, $security);
    }

    public function schemaReady(): bool
    {
        return $this->missingTables() === [];
    }

    public function missingTables(): array
    {
        $required = ['Tb_SvcTenant', 'Tb_SvcTenantUser', 'Tb_SvcRole'];
        $missing = [];
        foreach ($required as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    public function listTenants(string $serviceCode, bool $includeInactive = true): array
    {
        if (!$this->tableExists('Tb_SvcTenant')) {
            return [];
        }

        $serviceCode = trim($serviceCode);
        if ($serviceCode === '') {
            $serviceCode = 'shvq';
        }

        $where = ['t.service_code = :service_code'];
        $params = ['service_code' => $serviceCode];
        if (!$includeInactive) {
            $where[] = "t.status = 'ACTIVE'";
        }

        $sql = "SELECT
                    t.idx,
                    t.service_code,
                    t.tenant_code,
                    t.tenant_name,
                    t.status,
                    t.plan_code,
                    t.timezone_name,
                    t.is_default,
                    t.created_at,
                    t.updated_at,
                    (
                        SELECT COUNT(1)
                        FROM dbo.Tb_SvcTenantUser tu
                        WHERE tu.tenant_id = t.idx
                          AND tu.status = 'ACTIVE'
                    ) AS user_count
                FROM dbo.Tb_SvcTenant t
                WHERE " . implode(' AND ', $where) . "
                ORDER BY CASE WHEN t.is_default = 1 THEN 0 ELSE 1 END, t.idx ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function getTenant(int $tenantId): ?array
    {
        if ($tenantId <= 0 || !$this->tableExists('Tb_SvcTenant')) {
            return null;
        }

        $userCountSelect = "CAST(0 AS INT) AS user_count";
        if ($this->tableExists('Tb_SvcTenantUser')) {
            $userCountSelect = "(
                SELECT COUNT(1)
                FROM dbo.Tb_SvcTenantUser tu
                WHERE tu.tenant_id = t.idx
                  AND tu.status = 'ACTIVE'
            ) AS user_count";
        }

        $sql = "SELECT TOP 1
                    t.idx,
                    t.service_code,
                    t.tenant_code,
                    t.tenant_name,
                    t.status,
                    t.plan_code,
                    t.timezone_name,
                    t.is_default,
                    t.created_at,
                    t.updated_at,
                    {$userCountSelect}
                FROM dbo.Tb_SvcTenant t
                WHERE t.idx = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function getTenantByCode(string $serviceCode, string $tenantCode): ?array
    {
        if (!$this->tableExists('Tb_SvcTenant')) {
            return null;
        }

        $serviceCode = trim($serviceCode);
        $tenantCode = trim($tenantCode);
        if ($serviceCode === '') {
            $serviceCode = 'shvq';
        }
        if ($tenantCode === '') {
            return null;
        }

        $userCountSelect = "CAST(0 AS INT) AS user_count";
        if ($this->tableExists('Tb_SvcTenantUser')) {
            $userCountSelect = "(
                SELECT COUNT(1)
                FROM dbo.Tb_SvcTenantUser tu
                WHERE tu.tenant_id = t.idx
                  AND tu.status = 'ACTIVE'
            ) AS user_count";
        }

        $sql = "SELECT TOP 1
                    t.idx,
                    t.service_code,
                    t.tenant_code,
                    t.tenant_name,
                    t.status,
                    t.plan_code,
                    t.timezone_name,
                    t.is_default,
                    t.created_at,
                    t.updated_at,
                    {$userCountSelect}
                FROM dbo.Tb_SvcTenant t
                WHERE t.service_code = :service_code
                  AND t.tenant_code = :tenant_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'service_code' => $serviceCode,
            'tenant_code' => $tenantCode,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function listTenantUsers(int $tenantId): array
    {
        if (!$this->tableExists('Tb_SvcTenantUser')) {
            return [];
        }

        $userTable = $this->normalizeIdentifier((string)($this->security['auth']['user_table'] ?? 'Tb_Users'), 'Tb_Users');
        $userPkColumn = $this->normalizeIdentifier((string)($this->security['auth']['user_pk_column'] ?? 'idx'), 'idx');
        $userLoginColumn = $this->normalizeIdentifier((string)($this->security['auth']['user_login_column'] ?? 'id'), 'id');

        $loginSelect = "CAST('' AS NVARCHAR(120)) AS login_id";
        $userJoin = '';
        if ($this->tableExists($userTable)
            && $this->columnExists($userTable, $userPkColumn)
            && $this->columnExists($userTable, $userLoginColumn)
        ) {
            $quotedUserTable = $this->quoteIdentifier($userTable);
            $quotedUserPk = $this->quoteIdentifier($userPkColumn);
            $quotedUserLogin = $this->quoteIdentifier($userLoginColumn);
            $userJoin = "LEFT JOIN dbo.{$quotedUserTable} u ON u.{$quotedUserPk} = tu.user_idx";
            $loginSelect = "u.{$quotedUserLogin} AS login_id";
        }

        $sql = "SELECT
                    tu.idx,
                    tu.tenant_id,
                    tu.user_idx,
                    tu.role_id,
                    tu.status,
                    tu.created_at,
                    tu.updated_at,
                    {$loginSelect},
                    t.service_code
                FROM dbo.Tb_SvcTenantUser tu
                INNER JOIN dbo.Tb_SvcTenant t ON t.idx = tu.tenant_id
                {$userJoin}
                WHERE tu.tenant_id = :tenant_id
                ORDER BY tu.idx ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function createTenant(
        string $serviceCode,
        string $tenantCode,
        string $tenantName,
        string $planCode,
        bool $isDefault,
        int $actorUserPk
    ): array {
        if (!$this->schemaReady()) {
            throw new RuntimeException('TENANT_SCHEMA_NOT_READY');
        }

        $serviceCode = trim($serviceCode);
        if ($serviceCode === '') {
            $serviceCode = 'shvq';
        }
        $tenantCode = trim($tenantCode);
        $tenantName = trim($tenantName);
        $planCode = trim($planCode);
        if ($planCode === '') {
            $planCode = 'basic';
        }

        if ($tenantCode === '' || $tenantName === '') {
            throw new InvalidArgumentException('tenant_code/tenant_name is required');
        }

        $txStarted = false;
        try {
            $this->db->beginTransaction();
            $txStarted = true;

            $insertSql = "INSERT INTO dbo.Tb_SvcTenant
                          (service_code, tenant_code, tenant_name, status, plan_code, timezone_name, is_default, created_at, updated_at)
                          OUTPUT INSERTED.idx
                          VALUES
                          (:service_code, :tenant_code, :tenant_name, 'ACTIVE', :plan_code, 'Asia/Seoul', :is_default, GETDATE(), GETDATE())";
            $insertStmt = $this->db->prepare($insertSql);
            $insertStmt->execute([
                'service_code' => $serviceCode,
                'tenant_code' => $tenantCode,
                'tenant_name' => $tenantName,
                'plan_code' => $planCode,
                'is_default' => $isDefault ? 1 : 0,
            ]);
            $row = $insertStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $tenantId = (int)($row['idx'] ?? 0);
            if ($tenantId <= 0) {
                throw new RuntimeException('tenant insert failed');
            }

            if ($isDefault) {
                $this->normalizeDefaultTenant($serviceCode, $tenantId);
            }

            $this->db->commit();

            return [
                'tenant_id' => $tenantId,
                'service_code' => $serviceCode,
                'tenant_code' => $tenantCode,
            ];
        } catch (Throwable $e) {
            if ($txStarted && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->recordWriteFailure('create_tenant', $serviceCode, 0, [
                'tenant_code' => $tenantCode,
                'tenant_name' => $tenantName,
                'plan_code' => $planCode,
                'is_default' => $isDefault ? 1 : 0,
                'actor_user_pk' => $actorUserPk,
            ], $e);
            throw $e;
        }
    }

    public function updateTenantStatus(int $tenantId, string $status, int $actorUserPk): bool
    {
        if (!$this->schemaReady()) {
            throw new RuntimeException('TENANT_SCHEMA_NOT_READY');
        }

        $status = strtoupper(trim($status));
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            throw new InvalidArgumentException('status must be ACTIVE or INACTIVE');
        }

        $tenant = $this->findTenantMetaById($tenantId);
        if ($tenant === null) {
            return false;
        }

        try {
            $sql = "UPDATE dbo.Tb_SvcTenant
                    SET status = :status,
                        updated_at = GETDATE()
                    WHERE idx = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'status' => $status,
                'tenant_id' => $tenantId,
            ]);

            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $this->recordWriteFailure('update_tenant_status', (string)$tenant['service_code'], $tenantId, [
                'status' => $status,
                'actor_user_pk' => $actorUserPk,
            ], $e);
            throw $e;
        }
    }

    public function assignTenantUser(int $tenantId, int $userIdx, int $roleId, string $status, int $actorUserPk): bool
    {
        if (!$this->schemaReady()) {
            throw new RuntimeException('TENANT_SCHEMA_NOT_READY');
        }

        if ($tenantId <= 0 || $userIdx <= 0) {
            throw new InvalidArgumentException('tenant_id/user_idx is required');
        }

        $status = strtoupper(trim($status));
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            throw new InvalidArgumentException('status must be ACTIVE or INACTIVE');
        }

        $tenant = $this->findTenantMetaById($tenantId);
        if ($tenant === null) {
            return false;
        }

        if ($roleId <= 0) {
            $roleId = $this->resolveDefaultRoleId();
        }
        if ($roleId <= 0) {
            throw new RuntimeException('role_id is invalid');
        }

        try {
            /* named placeholder를 USING 절에만 각 1회씩 선언하고,
               MATCHED/NOT MATCHED 절에서는 source.column 으로 참조.
               sqlsrv PDO에서 동일 placeholder 중복 바인딩 오류 방지. */
            $sql = "MERGE dbo.Tb_SvcTenantUser AS target
                    USING (
                        SELECT :tenant_id AS tenant_id,
                               :user_idx  AS user_idx,
                               :role_id   AS role_id,
                               :status    AS status_val
                    ) AS source
                    ON target.tenant_id = source.tenant_id
                   AND target.user_idx  = source.user_idx
                    WHEN MATCHED THEN
                        UPDATE SET role_id    = source.role_id,
                                   status     = source.status_val,
                                   updated_at = GETDATE()
                    WHEN NOT MATCHED THEN
                        INSERT (tenant_id, user_idx, role_id, status, created_at, updated_at)
                        VALUES (source.tenant_id, source.user_idx,
                                source.role_id,   source.status_val,
                                GETDATE(), GETDATE());";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'tenant_id' => $tenantId,
                'user_idx'  => $userIdx,
                'role_id'   => $roleId,
                'status'    => $status,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->recordWriteFailure('assign_tenant_user', (string)$tenant['service_code'], $tenantId, [
                'user_idx' => $userIdx,
                'role_id' => $roleId,
                'status' => $status,
                'actor_user_pk' => $actorUserPk,
            ], $e);
            throw $e;
        }
    }

    public function initDefaultTenant(
        string $serviceCode,
        string $tenantCode,
        string $tenantName,
        string $planCode,
        int $actorUserPk
    ): array {
        if (!$this->schemaReady()) {
            throw new RuntimeException('TENANT_SCHEMA_NOT_READY');
        }

        $serviceCode = trim($serviceCode);
        if ($serviceCode === '') {
            $serviceCode = 'shvq';
        }
        $tenantCode = trim($tenantCode);
        if ($tenantCode === '') {
            $tenantCode = 'shvision';
        }
        $tenantName = trim($tenantName);
        if ($tenantName === '') {
            $tenantName = 'SH Vision';
        }
        $planCode = trim($planCode);
        if ($planCode === '') {
            $planCode = 'basic';
        }

        $created = false;
        $tenant = $this->getTenantByCode($serviceCode, $tenantCode);

        try {
            if ($tenant === null) {
                $createdInfo = $this->createTenant(
                    $serviceCode,
                    $tenantCode,
                    $tenantName,
                    $planCode,
                    true,
                    $actorUserPk
                );
                $tenantId = (int)($createdInfo['tenant_id'] ?? 0);
                if ($tenantId <= 0) {
                    throw new RuntimeException('default tenant create failed');
                }
                $created = true;
            } else {
                $tenantId = (int)($tenant['idx'] ?? 0);
            }

            if ($tenantId <= 0) {
                throw new RuntimeException('default tenant not found');
            }

            $this->normalizeDefaultTenant($serviceCode, $tenantId);

            $roleId = $this->ensureTenantAdminRoleId();
            if ($roleId <= 0) {
                throw new RuntimeException('tenant admin role not found');
            }

            $seed = $this->seedTenantAdminUsers($tenantId, $roleId);
            $loaded = $this->getTenant($tenantId);
            if ($loaded === null) {
                throw new RuntimeException('default tenant load failed');
            }

            return [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'tenant_code' => (string)($loaded['tenant_code'] ?? $tenantCode),
                'tenant_name' => (string)($loaded['tenant_name'] ?? $tenantName),
                'created' => $created,
                'is_default' => (int)($loaded['is_default'] ?? 0) === 1,
                'role_id' => $roleId,
                'seed' => $seed,
            ];
        } catch (Throwable $e) {
            $this->recordWriteFailure('init_default', $serviceCode, 0, [
                'tenant_code' => $tenantCode,
                'tenant_name' => $tenantName,
                'plan_code' => $planCode,
                'actor_user_pk' => $actorUserPk,
            ], $e);
            throw $e;
        }
    }

    private function normalizeDefaultTenant(string $serviceCode, int $selectedTenantId): void
    {
        $sql = "UPDATE dbo.Tb_SvcTenant
                SET is_default = CASE WHEN idx = :tenant_id THEN 1 ELSE 0 END,
                    updated_at = GETDATE()
                WHERE service_code = :service_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $selectedTenantId,
            'service_code' => $serviceCode,
        ]);
    }

    private function ensureTenantAdminRoleId(): int
    {
        if (!$this->tableExists('Tb_SvcRole')) {
            return 0;
        }

        $selectSql = "SELECT TOP 1 idx
                      FROM dbo.Tb_SvcRole
                      WHERE role_key = :role_key
                      ORDER BY idx ASC";
        $selectStmt = $this->db->prepare($selectSql);
        $selectStmt->execute(['role_key' => 'tenant_admin']);
        $row = $selectStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $roleId = (int)($row['idx'] ?? 0);
        if ($roleId > 0) {
            return $roleId;
        }

        try {
            $insertSql = "INSERT INTO dbo.Tb_SvcRole
                          (role_key, role_name, priority, status, created_at, updated_at)
                          OUTPUT INSERTED.idx
                          VALUES
                          (:role_key, :role_name, :priority, :status, GETDATE(), GETDATE())";
            $insertStmt = $this->db->prepare($insertSql);
            $insertStmt->execute([
                'role_key' => 'tenant_admin',
                'role_name' => 'Tenant Admin',
                'priority' => 10,
                'status' => 'ACTIVE',
            ]);
            $inserted = $insertStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $roleId = (int)($inserted['idx'] ?? 0);
        } catch (Throwable) {
            $roleId = 0;
        }

        if ($roleId > 0) {
            return $roleId;
        }

        // INSERT 후 재조회 — 동시 삽입 경쟁 시 re-prepare로 안전하게 처리
        $retryStmt = $this->db->prepare($selectSql);
        $retryStmt->execute(['role_key' => 'tenant_admin']);
        $row = $retryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $roleId = (int)($row['idx'] ?? 0);
        if ($roleId > 0) {
            return $roleId;
        }

        return $this->resolveDefaultRoleId();
    }

    private function seedTenantAdminUsers(int $tenantId, int $roleId): array
    {
        if ($tenantId <= 0 || $roleId <= 0 || !$this->tableExists('Tb_SvcTenantUser')) {
            return [
                'admin_user_total' => 0,
                'inserted' => 0,
                'updated' => 0,
            ];
        }

        $adminUserIds = $this->resolveAdminUserIds();
        $inserted = 0;
        $updated = 0;

        foreach ($adminUserIds as $userIdx) {
            $existsSql = "SELECT TOP 1 idx
                          FROM dbo.Tb_SvcTenantUser
                          WHERE tenant_id = :tenant_id
                            AND user_idx = :user_idx";
            $existsStmt = $this->db->prepare($existsSql);
            $existsStmt->execute([
                'tenant_id' => $tenantId,
                'user_idx' => $userIdx,
            ]);
            $exists = is_array($existsStmt->fetch(PDO::FETCH_ASSOC));

            $mergeSql = "MERGE dbo.Tb_SvcTenantUser AS target
                         USING (
                            SELECT :tenant_id AS tenant_id,
                                   :user_idx  AS user_idx,
                                   :role_id   AS role_id,
                                   :status    AS status_val
                         ) AS source
                         ON target.tenant_id = source.tenant_id
                        AND target.user_idx  = source.user_idx
                         WHEN MATCHED THEN
                            UPDATE SET role_id    = source.role_id,
                                       status     = source.status_val,
                                       updated_at = GETDATE()
                         WHEN NOT MATCHED THEN
                            INSERT (tenant_id, user_idx, role_id, status, created_at, updated_at)
                            VALUES (source.tenant_id, source.user_idx,
                                    source.role_id, source.status_val,
                                    GETDATE(), GETDATE());";
            $mergeStmt = $this->db->prepare($mergeSql);
            $mergeStmt->execute([
                'tenant_id' => $tenantId,
                'user_idx' => $userIdx,
                'role_id' => $roleId,
                'status' => 'ACTIVE',
            ]);

            if ($exists) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        return [
            'admin_user_total' => count($adminUserIds),
            'inserted' => $inserted,
            'updated' => $updated,
        ];
    }

    private function resolveAdminUserIds(): array
    {
        $userTable = $this->normalizeIdentifier((string)($this->security['auth']['user_table'] ?? 'Tb_Users'), 'Tb_Users');
        $userPkColumn = $this->normalizeIdentifier((string)($this->security['auth']['user_pk_column'] ?? 'idx'), 'idx');
        $userLevelColumn = $this->normalizeIdentifier((string)($this->security['auth']['user_level_column'] ?? 'user_level'), 'user_level');

        if (
            !$this->tableExists($userTable)
            || !$this->columnExists($userTable, $userPkColumn)
            || !$this->columnExists($userTable, $userLevelColumn)
        ) {
            return [];
        }

        $quotedUserTable = $this->quoteIdentifier($userTable);
        $quotedPk = $this->quoteIdentifier($userPkColumn);
        $quotedLevel = $this->quoteIdentifier($userLevelColumn);

        $sql = "SELECT {$quotedPk} AS user_idx
                FROM dbo.{$quotedUserTable}
                WHERE TRY_CONVERT(INT, {$quotedLevel}) >= :min_level
                ORDER BY TRY_CONVERT(INT, {$quotedLevel}) DESC, {$quotedPk} ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['min_level' => 5]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ids = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row['user_idx'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    private function findTenantMetaById(int $tenantId): ?array
    {
        $stmt = $this->db->prepare("SELECT TOP 1 idx, service_code FROM dbo.Tb_SvcTenant WHERE idx = :tenant_id");
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function resolveDefaultRoleId(): int
    {
        if (!$this->tableExists('Tb_SvcRole')) {
            return 0;
        }

        try {
            $stmt = $this->db->query("SELECT TOP 1 idx FROM dbo.Tb_SvcRole WHERE status = 'ACTIVE' ORDER BY priority ASC, idx ASC");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['idx'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function recordWriteFailure(
        string $todo,
        string $serviceCode,
        int $tenantId,
        array $payload,
        Throwable $e
    ): void {
        if (!(bool)($this->security['shadow_write']['enabled'] ?? true)) {
            return;
        }

        try {
            $this->shadowQueue->enqueueFailure([
                'service_code' => $serviceCode !== '' ? $serviceCode : 'shvq',
                'tenant_id' => max(0, $tenantId),
                'api' => 'saas/Tenant.php',
                'todo' => $todo,
                'error_message' => $e->getMessage(),
                'meta' => $payload,
                'recorded_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // Best-effort: main write exception is more important than queue insert failure.
        }
    }

    private function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return $this->tableExistsCache[$tableName];
        }
        try {
            $stmt = $this->db->prepare("SELECT CASE WHEN OBJECT_ID(:table_name, 'U') IS NULL THEN 0 ELSE 1 END AS exists_yn");
            $stmt->execute(['table_name' => 'dbo.' . $tableName]);
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
        $stmt = $this->db->prepare("SELECT CASE WHEN COL_LENGTH(:table_name, :column_name) IS NULL THEN 0 ELSE 1 END AS exists_yn");
        $stmt->execute([
            'table_name' => 'dbo.' . $tableName,
            'column_name' => $columnName,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return (int)($row['exists_yn'] ?? 0) === 1;
    }

    private function normalizeIdentifier(string $value, string $default): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/^[A-Za-z0-9_]+$/', $trimmed) !== 1) {
            return $default;
        }
        return $trimmed;
    }

    private function quoteIdentifier(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }
}
