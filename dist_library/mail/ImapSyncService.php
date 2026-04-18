<?php
declare(strict_types=1);

namespace SHVQ\Mail;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use SHVQ\Integration\DTO\EventEnvelope;

require_once __DIR__ . '/MailAdapter.php';
require_once __DIR__ . '/../saas/Integration/DTO/EventEnvelope.php';

final class ImapSyncService
{
    private PDO $db;
    private MailAdapter $adapter;
    private array $tableExistsCache = [];

    public function __construct(PDO $db, ?MailAdapter $adapter = null)
    {
        $this->db = $db;
        $this->adapter = $adapter ?? new MailAdapter($db);
    }

    public function runIncremental(
        string $serviceCode,
        int $tenantId,
        int $accountIdx = 0,
        string $folderKey = 'INBOX',
        int $limit = 500,
        bool $emitEvents = true
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);
        $accountIdx = max(0, $accountIdx);
        $folderKey = trim($folderKey) !== '' ? trim($folderKey) : 'INBOX';
        $limit = max(1, min($limit, 1000));

        if ($tenantId <= 0) {
            throw new InvalidArgumentException('tenant_id is required');
        }

        $accounts = $this->resolveAccounts($serviceCode, $tenantId, $accountIdx);
        if ($accounts === []) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $result = [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'folder_key' => $folderKey,
            'account_total' => count($accounts),
            'synced_accounts' => 0,
            'messages_synced' => 0,
            'events_emitted' => 0,
            'error_count' => 0,
            'items' => [],
            'synced_at' => date('c'),
        ];

        foreach ($accounts as $account) {
            $aIdx = (int)($account['idx'] ?? 0);
            $cursor = $this->loadCursor($serviceCode, $tenantId, $aIdx, $folderKey);

            try {
                $batch = $this->adapter->syncMessagesIncremental($aIdx, $folderKey, $cursor, $limit);
                $messages = is_array($batch['items'] ?? null) ? $batch['items'] : [];
                $nextCursor = (string)($batch['cursor'] ?? '');

                $emitted = 0;
                if ($emitEvents) {
                    foreach ($messages as $msg) {
                        $event = $this->messageToEventEnvelope($serviceCode, $tenantId, $aIdx, $folderKey, $msg);
                        $this->enqueueEventRaw($event);
                        $emitted++;
                    }
                }

                $this->saveCursor($serviceCode, $tenantId, $aIdx, $folderKey, $nextCursor, 'SUCCESS', '');

                $result['synced_accounts']++;
                $result['messages_synced'] += count($messages);
                $result['events_emitted'] += $emitted;
                $result['items'][] = [
                    'ok' => true,
                    'account_idx' => $aIdx,
                    'folder_key' => $folderKey,
                    'cursor' => $nextCursor,
                    'messages_synced' => count($messages),
                    'events_emitted' => $emitted,
                    'has_more' => (bool)($batch['has_more'] ?? false),
                    'strategy' => (string)($batch['strategy'] ?? 'imap_uid_search'),
                    'warnings' => is_array($batch['warnings'] ?? null) ? $batch['warnings'] : [],
                ];
            } catch (\Throwable $e) {
                $result['error_count']++;
                $this->saveCursor($serviceCode, $tenantId, $aIdx, $folderKey, (string)$cursor, 'ERROR', $e->getMessage());
                $result['items'][] = [
                    'ok' => false,
                    'account_idx' => $aIdx,
                    'folder_key' => $folderKey,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function resolveAccounts(string $serviceCode, int $tenantId, int $accountIdx): array
    {
        if (!$this->tableExists('Tb_IntProviderAccount')) {
            return [];
        }

        $where = [
            "provider = 'mail'",
            "status = 'ACTIVE'",
            'service_code = :service_code',
            'tenant_id = :tenant_id',
        ];
        $params = [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
        ];

        if ($accountIdx > 0) {
            $where[] = 'idx = :account_idx';
            $params['account_idx'] = $accountIdx;
        }

        $sql = 'SELECT idx, service_code, tenant_id, account_key
                FROM dbo.Tb_IntProviderAccount
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY is_primary DESC, idx ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    private function checkpointScope(string $folderKey): string
    {
        return 'mail.folder.' . strtolower(trim($folderKey));
    }

    private function loadCursor(string $serviceCode, int $tenantId, int $accountIdx, string $folderKey): ?string
    {
        if (!$this->tableExists('Tb_IntSyncCheckpoint')) {
            return null;
        }

        $sql = "SELECT TOP 1 cursor_value
                FROM dbo.Tb_IntSyncCheckpoint
                WHERE service_code = :service_code
                  AND tenant_id = :tenant_id
                  AND provider_account_idx = :account_idx
                  AND sync_scope = :sync_scope
                ORDER BY idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'account_idx' => $accountIdx,
            'sync_scope' => $this->checkpointScope($folderKey),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $cursor = trim((string)($row['cursor_value'] ?? ''));
        return $cursor !== '' ? $cursor : null;
    }

    private function saveCursor(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folderKey,
        string $cursor,
        string $status,
        string $lastError
    ): void {
        if (!$this->tableExists('Tb_IntSyncCheckpoint')) {
            return;
        }

        $status = strtoupper(trim($status));
        if (!in_array($status, ['SUCCESS', 'ERROR', 'RETRYING'], true)) {
            $status = 'SUCCESS';
        }

        $sql = "MERGE dbo.Tb_IntSyncCheckpoint AS target
                USING (
                    SELECT
                        :service_code AS service_code,
                        :tenant_id AS tenant_id,
                        :provider_account_idx AS provider_account_idx,
                        :sync_scope AS sync_scope
                ) AS src
                ON target.service_code = src.service_code
                   AND target.tenant_id = src.tenant_id
                   AND target.provider_account_idx = src.provider_account_idx
                   AND target.sync_scope = src.sync_scope
                WHEN MATCHED THEN
                    UPDATE SET
                        cursor_value = :cursor_value,
                        status = :status,
                        last_error = :last_error,
                        last_success_at = CASE WHEN :status = 'SUCCESS' THEN GETDATE() ELSE target.last_success_at END,
                        updated_at = GETDATE()
                WHEN NOT MATCHED THEN
                    INSERT (
                        service_code, tenant_id, provider_account_idx, sync_scope,
                        cursor_value, status, last_error, last_success_at, created_at, updated_at
                    ) VALUES (
                        :service_code, :tenant_id, :provider_account_idx, :sync_scope,
                        :cursor_value, :status, :last_error,
                        CASE WHEN :status = 'SUCCESS' THEN GETDATE() ELSE NULL END,
                        GETDATE(), GETDATE()
                    );";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'provider_account_idx' => $accountIdx,
            'sync_scope' => $this->checkpointScope($folderKey),
            'cursor_value' => $cursor,
            'status' => $status,
            'last_error' => mb_substr($lastError, 0, 1000),
        ]);
    }

    /** @param array<string,mixed> $message */
    private function messageToEventEnvelope(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folderKey,
        array $message
    ): EventEnvelope {
        $uid = (string)($message['uid'] ?? '0');
        $eventType = 'mail.message.synced';
        $idempotencyKey = 'mail:' . $tenantId . ':' . md5($folderKey . ':' . $uid);

        return new EventEnvelope(
            'mail',
            $serviceCode,
            $tenantId,
            $accountIdx,
            $eventType,
            'mail_message',
            $uid,
            $message,
            (string)($message['message_id'] ?? ''),
            (string)($message['date'] ?? date('c')),
            date('c'),
            $idempotencyKey
        );
    }

    private function enqueueEventRaw(EventEnvelope $event): void
    {
        if (!$this->tableExists('Tb_EventRaw')) {
            return;
        }

        $payloadJson = json_encode($event->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $sql = "IF NOT EXISTS (
                    SELECT 1
                    FROM dbo.Tb_EventRaw
                    WHERE service_code = :service_code
                      AND tenant_id = :tenant_id
                      AND provider = :provider
                      AND idempotency_key = :idempotency_key
                )
                BEGIN
                    INSERT INTO dbo.Tb_EventRaw (
                        service_code,
                        tenant_id,
                        provider,
                        provider_account_idx,
                        external_event_id,
                        event_type,
                        resource_type,
                        resource_id,
                        occurred_at,
                        received_at,
                        payload_json,
                        idempotency_key,
                        status,
                        created_at,
                        updated_at
                    ) VALUES (
                        :service_code,
                        :tenant_id,
                        :provider,
                        :provider_account_idx,
                        :external_event_id,
                        :event_type,
                        :resource_type,
                        :resource_id,
                        :occurred_at,
                        :received_at,
                        :payload_json,
                        :idempotency_key,
                        'RECEIVED',
                        GETDATE(),
                        GETDATE()
                    )
                END";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'service_code' => $event->serviceCode(),
            'tenant_id' => $event->tenantId(),
            'provider' => $event->provider(),
            'provider_account_idx' => $event->accountIdx(),
            'external_event_id' => $event->externalEventId(),
            'event_type' => $event->eventType(),
            'resource_type' => $event->resourceType(),
            'resource_id' => $event->resourceId(),
            'occurred_at' => date('Y-m-d H:i:s', strtotime($event->occurredAt())),
            'received_at' => date('Y-m-d H:i:s', strtotime($event->receivedAt())),
            'payload_json' => $payloadJson,
            'idempotency_key' => $event->idempotencyKey(),
        ]);
    }

    private function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return $this->tableExistsCache[$tableName];
        }

        $stmt = $this->db->prepare('SELECT CASE WHEN OBJECT_ID(:obj, :type) IS NULL THEN 0 ELSE 1 END AS exists_flag');
        $stmt->execute([
            'obj' => 'dbo.' . $tableName,
            'type' => 'U',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $exists = ((int)($row['exists_flag'] ?? 0)) === 1;
        $this->tableExistsCache[$tableName] = $exists;

        return $exists;
    }
}
