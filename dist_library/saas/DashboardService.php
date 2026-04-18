<?php
declare(strict_types=1);

final class DashboardService
{
    private PDO $db;
    private array $tableExistsCache = [];
    private array $columnExistsCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function summary(string $serviceCode, int $tenantId = 0): array
    {
        $serviceCode = trim($serviceCode);
        if ($serviceCode === '') {
            $serviceCode = 'shvq';
        }

        return [
            'scope' => [
                'service_code' => $serviceCode,
                'tenant_id' => max(0, $tenantId),
            ],
            'core' => [
                'users_total' => $this->countDomainTable('Tb_Users'),
                'tenants_total' => $this->countByService('Tb_SvcTenant', $serviceCode),
                'tenant_users_total' => $this->countTenantUsers($serviceCode, $tenantId),
            ],
            'erp' => [
                'members_total' => $this->countDomainTable('Tb_Members'),
                'sites_total' => $this->countDomainTable('Tb_Site'),
                'pjt_total' => $this->countDomainTable('Tb_PjtPlan'),
                'estimate_total' => $this->countDomainTable('Tb_SiteEstimate'),
            ],
            'integration' => [
                'provider_accounts_total' => $this->countServiceTenantScoped('Tb_IntProviderAccount', $serviceCode, $tenantId),
                'devices_total' => $this->countServiceTenantScoped('Tb_IntDevice', $serviceCode, $tenantId),
                'sync_errors_total' => $this->countServiceTenantScoped('Tb_IntErrorQueue', $serviceCode, $tenantId),
            ],
            'notification' => [
                'pending_total' => $this->countNotifyQueue($serviceCode, $tenantId, 'PENDING'),
                'retrying_total' => $this->countNotifyQueue($serviceCode, $tenantId, 'RETRYING'),
            ],
            'shadow_write' => [
                'open_total' => $this->countShadowQueue($serviceCode, $tenantId, ['PENDING', 'RETRYING']),
                'failed_total' => $this->countShadowQueue($serviceCode, $tenantId, ['FAILED']),
                'resolved_total' => $this->countShadowQueue($serviceCode, $tenantId, ['RESOLVED']),
            ],
            'auth' => [
                'login_success_24h' => $this->countAuthAuditLast24Hours('OK'),
                'login_fail_24h' => $this->countAuthAuditLast24Hours('FAIL'),
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function countAuthAuditLast24Hours(string $mode): int
    {
        if (!$this->tableExists('Tb_AuthAuditLog')) {
            return 0;
        }

        $table = $this->normalizeTableName('Tb_AuthAuditLog');
        if ($mode === 'OK') {
            $sql = "SELECT COUNT(1) AS cnt
                    FROM {$table}
                    WHERE action_key = 'auth.login'
                      AND result_code = 'OK'
                      AND created_at >= DATEADD(HOUR, -24, GETDATE())";
            return $this->fetchCount($sql);
        }

        $sql = "SELECT COUNT(1) AS cnt
                FROM {$table}
                WHERE action_key = 'auth.login'
                  AND result_code <> 'OK'
                  AND created_at >= DATEADD(HOUR, -24, GETDATE())";
        return $this->fetchCount($sql);
    }

    private function countShadowQueue(string $serviceCode, int $tenantId, array $statuses): int
    {
        if (!$this->tableExists('Tb_IntErrorQueue')) {
            return 0;
        }

        $table = $this->normalizeTableName('Tb_IntErrorQueue');
        $where = [
            "provider = :provider",
            "job_type = :job_type",
            "service_code = :service_code",
        ];
        $params = [
            'provider' => 'shadow',
            'job_type' => 'shadow_write',
            'service_code' => $serviceCode,
        ];

        if ($tenantId > 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        if ($statuses !== []) {
            $statusPlaceholders = [];
            foreach ($statuses as $idx => $status) {
                $key = 'status_' . $idx;
                $statusPlaceholders[] = ':' . $key;
                $params[$key] = strtoupper((string)$status);
            }
            $where[] = 'status IN (' . implode(', ', $statusPlaceholders) . ')';
        }

        $sql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE " . implode(' AND ', $where);
        return $this->fetchCount($sql, $params);
    }

    private function countNotifyQueue(string $serviceCode, int $tenantId, string $status): int
    {
        if (!$this->tableExists('Tb_NotifyQueue')) {
            return 0;
        }

        $table = $this->normalizeTableName('Tb_NotifyQueue');
        $where = [
            "service_code = :service_code",
            "status = :status",
        ];
        $params = [
            'service_code' => $serviceCode,
            'status' => strtoupper($status),
        ];

        if ($tenantId > 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $sql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE " . implode(' AND ', $where);
        return $this->fetchCount($sql, $params);
    }

    private function countServiceTenantScoped(string $tableName, string $serviceCode, int $tenantId): int
    {
        if (!$this->tableExists($tableName)) {
            return 0;
        }

        $table = $this->normalizeTableName($tableName);
        $where = ['service_code = :service_code'];
        $params = ['service_code' => $serviceCode];

        if ($tenantId > 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $sql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE " . implode(' AND ', $where);
        return $this->fetchCount($sql, $params);
    }

    private function countTenantUsers(string $serviceCode, int $tenantId): int
    {
        if (!$this->tableExists('Tb_SvcTenantUser') || !$this->tableExists('Tb_SvcTenant')) {
            return 0;
        }

        $tenantUserTable = $this->normalizeTableName('Tb_SvcTenantUser');
        $tenantTable = $this->normalizeTableName('Tb_SvcTenant');

        $where = [
            "t.service_code = :service_code",
            "tu.status = 'ACTIVE'",
        ];
        $params = ['service_code' => $serviceCode];

        if ($tenantId > 0) {
            $where[] = 'tu.tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $sql = "SELECT COUNT(1) AS cnt
                FROM {$tenantUserTable} tu
                INNER JOIN {$tenantTable} t ON t.idx = tu.tenant_id
                WHERE " . implode(' AND ', $where);

        return $this->fetchCount($sql, $params);
    }

    private function countByService(string $tableName, string $serviceCode): int
    {
        if (!$this->tableExists($tableName)) {
            return 0;
        }

        $table = $this->normalizeTableName($tableName);
        $sql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE service_code = :service_code";
        return $this->fetchCount($sql, ['service_code' => $serviceCode]);
    }

    private function countDomainTable(string $tableName): int
    {
        if (!$this->tableExists($tableName)) {
            return 0;
        }

        $table = $this->normalizeTableName($tableName);
        if ($this->columnExists($tableName, 'is_deleted')) {
            $sql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE is_deleted = 0";
            return $this->fetchCount($sql);
        }

        $sql = "SELECT COUNT(1) AS cnt FROM {$table}";
        return $this->fetchCount($sql);
    }

    private function countTable(string $tableName): int
    {
        if (!$this->tableExists($tableName)) {
            return 0;
        }

        $table = $this->normalizeTableName($tableName);
        $sql = "SELECT COUNT(1) AS cnt FROM {$table}";
        return $this->fetchCount($sql);
    }

    private function fetchCount(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['cnt'] ?? 0);
        } catch (Throwable) {
            return -1;
        }
    }

    private function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return $this->tableExistsCache[$tableName];
        }

        try {
            $sql = "SELECT CASE WHEN OBJECT_ID(:object_name, 'U') IS NULL THEN 0 ELSE 1 END AS exists_yn";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['object_name' => 'dbo.' . $tableName]);
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
        $cacheKey = $tableName . '.' . $columnName;
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        try {
            $sql = "SELECT CASE WHEN COL_LENGTH(:table_name, :column_name) IS NULL THEN 0 ELSE 1 END AS exists_yn";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'table_name' => 'dbo.' . $tableName,
                'column_name' => $columnName,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $exists = (int)($row['exists_yn'] ?? 0) === 1;
        } catch (Throwable) {
            $exists = false;
        }

        $this->columnExistsCache[$cacheKey] = $exists;
        return $exists;
    }

    private function normalizeTableName(string $table): string
    {
        $trimmed = trim($table);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $trimmed)) {
            throw new InvalidArgumentException('Invalid table name: ' . $table);
        }
        return '[dbo].[' . $trimmed . ']';
    }
}
