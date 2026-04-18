<?php
declare(strict_types=1);

final class ShadowReplayService
{
    private PDO $db;
    private ShadowWriteQueueService $queue;
    private TenantService $tenantService;

    public function __construct(PDO $db, ShadowWriteQueueService $queue, TenantService $tenantService)
    {
        $this->db = $db;
        $this->queue = $queue;
        $this->tenantService = $tenantService;
    }

    public function replayOneByIdx(int $idx, int $operatorUserPk = 0): array
    {
        $claimed = $this->queue->claimByIdx($idx);
        if ($claimed === null) {
            return [
                'ok' => false,
                'code' => 'QUEUE_ITEM_NOT_FOUND_OR_NOT_REPLAYABLE',
                'idx' => $idx,
            ];
        }

        return $this->replayClaimed($claimed, $operatorUserPk);
    }

    public function replayBatch(int $limit, array $scope, int $operatorUserPk = 0): array
    {
        $limit = max(1, min($limit, 100));
        $processed = 0;
        $resolved = 0;
        $failed = 0;
        $items = [];

        for ($i = 0; $i < $limit; $i++) {
            $claimed = $this->queue->claimNextPending($scope);
            if ($claimed === null) {
                break;
            }

            $processed++;
            $result = $this->replayClaimed($claimed, $operatorUserPk);
            if ((bool)($result['ok'] ?? false)) {
                $resolved++;
            } else {
                $failed++;
            }
            $items[] = $result;
        }

        return [
            'processed' => $processed,
            'resolved' => $resolved,
            'failed' => $failed,
            'items' => $items,
        ];
    }

    private function replayClaimed(array $claimed, int $operatorUserPk): array
    {
        $idx = (int)($claimed['idx'] ?? 0);
        $payload = is_array($claimed['payload'] ?? null) ? (array)$claimed['payload'] : [];
        $todo = trim((string)($payload['todo'] ?? ''));
        $api = trim((string)($payload['api'] ?? ''));

        try {
            $execResult = $this->executePayload($payload, $operatorUserPk);
            $note = sprintf(
                'replayed_ok todo=%s api=%s operator_user_pk=%d at=%s',
                $todo !== '' ? $todo : '-',
                $api !== '' ? $api : '-',
                $operatorUserPk,
                date('Y-m-d H:i:s')
            );
            $this->queue->markResolvedByReplay($idx, $note);

            return [
                'ok' => true,
                'idx' => $idx,
                'todo' => $todo,
                'api' => $api,
                'result' => $execResult,
            ];
        } catch (Throwable $e) {
            $queueResult = $this->queue->markRetryFailure($idx, $e->getMessage());
            return [
                'ok' => false,
                'idx' => $idx,
                'todo' => $todo,
                'api' => $api,
                'error' => $e->getMessage(),
                'queue' => $queueResult,
            ];
        }
    }

    private function executePayload(array $payload, int $operatorUserPk): array
    {
        if (!$this->tenantService->schemaReady()) {
            throw new RuntimeException('TENANT_SCHEMA_NOT_READY');
        }

        $todo = trim((string)($payload['todo'] ?? ''));
        if ($todo === '') {
            throw new RuntimeException('PAYLOAD_TODO_REQUIRED');
        }

        if ($todo === 'create_tenant') {
            return $this->replayCreateTenant($payload, $operatorUserPk);
        }
        if ($todo === 'update_tenant_status') {
            return $this->replayUpdateTenantStatus($payload, $operatorUserPk);
        }
        if ($todo === 'assign_tenant_user') {
            return $this->replayAssignTenantUser($payload, $operatorUserPk);
        }

        throw new RuntimeException('UNSUPPORTED_REPLAY_TODO:' . $todo);
    }

    private function replayCreateTenant(array $payload, int $operatorUserPk): array
    {
        $serviceCode = trim((string)($payload['service_code'] ?? 'shvq'));
        if ($serviceCode === '') {
            $serviceCode = 'shvq';
        }

        $tenantCode = trim((string)($payload['tenant_code'] ?? ''));
        $tenantName = trim((string)($payload['tenant_name'] ?? ''));
        $planCode = trim((string)($payload['plan_code'] ?? 'basic'));
        $isDefault = (bool)($payload['is_default'] ?? false);
        $actorUserPk = (int)($payload['actor_user_pk'] ?? $operatorUserPk);

        if ($tenantCode === '' || $tenantName === '') {
            throw new RuntimeException('REPLAY_INVALID_PAYLOAD:create_tenant');
        }

        $existing = $this->findTenantByCode($serviceCode, $tenantCode);
        if ($existing !== null) {
            return [
                'idempotent' => true,
                'tenant_id' => (int)($existing['idx'] ?? 0),
                'service_code' => $serviceCode,
                'tenant_code' => $tenantCode,
            ];
        }

        $created = $this->tenantService->createTenant(
            $serviceCode,
            $tenantCode,
            $tenantName,
            $planCode !== '' ? $planCode : 'basic',
            $isDefault,
            $actorUserPk
        );

        return [
            'idempotent' => false,
            'created' => $created,
        ];
    }

    private function replayUpdateTenantStatus(array $payload, int $operatorUserPk): array
    {
        $tenantId = (int)($payload['tenant_id'] ?? 0);
        $status = trim((string)($payload['status'] ?? ''));
        $actorUserPk = (int)($payload['actor_user_pk'] ?? $operatorUserPk);

        if ($tenantId <= 0 || $status === '') {
            throw new RuntimeException('REPLAY_INVALID_PAYLOAD:update_tenant_status');
        }

        $tenant = $this->findTenantById($tenantId);
        if ($tenant === null) {
            throw new RuntimeException('TENANT_NOT_FOUND:' . $tenantId);
        }

        $this->tenantService->updateTenantStatus($tenantId, $status, $actorUserPk);
        return [
            'tenant_id' => $tenantId,
            'status' => strtoupper($status),
            'updated' => true,
        ];
    }

    private function replayAssignTenantUser(array $payload, int $operatorUserPk): array
    {
        $tenantId = (int)($payload['tenant_id'] ?? 0);
        $userIdx = (int)($payload['user_idx'] ?? 0);
        $roleId = (int)($payload['role_id'] ?? 0);
        $status = trim((string)($payload['status'] ?? 'ACTIVE'));
        $actorUserPk = (int)($payload['actor_user_pk'] ?? $operatorUserPk);

        if ($tenantId <= 0 || $userIdx <= 0) {
            throw new RuntimeException('REPLAY_INVALID_PAYLOAD:assign_tenant_user');
        }

        $ok = $this->tenantService->assignTenantUser($tenantId, $userIdx, $roleId, $status, $actorUserPk);
        if (!$ok) {
            throw new RuntimeException('TENANT_NOT_FOUND:' . $tenantId);
        }

        return [
            'tenant_id' => $tenantId,
            'user_idx' => $userIdx,
            'role_id' => $roleId,
            'status' => strtoupper($status),
            'assigned' => true,
        ];
    }

    private function findTenantByCode(string $serviceCode, string $tenantCode): ?array
    {
        $sql = "SELECT TOP 1 idx, service_code, tenant_code
                FROM dbo.Tb_SvcTenant
                WHERE service_code = :service_code
                  AND tenant_code = :tenant_code
                ORDER BY idx ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'service_code' => $serviceCode,
            'tenant_code' => $tenantCode,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function findTenantById(int $tenantId): ?array
    {
        $sql = "SELECT TOP 1 idx, service_code
                FROM dbo.Tb_SvcTenant
                WHERE idx = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

