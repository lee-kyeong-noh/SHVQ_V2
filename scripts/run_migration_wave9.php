<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';

header('Content-Type: application/json; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ''));
if ($token !== 'shvq_migrate_wave9_2026') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN']);
    exit;
}

$db = DbConnection::get();
$results = [];

try {
    /* V1 DB 확인 */
    $st = $db->query("SELECT CASE WHEN DB_ID('teniq_db') IS NULL THEN 0 ELSE 1 END");
    if ((int)$st->fetchColumn() !== 1) {
        echo json_encode(['ok' => true, 'results' => ['teniq_db not found'], 'migrated' => false]);
        exit;
    }

    /* ── 1) ProviderAccount: V1 → V2 ──
       V1: provider_user_key, account_email, account_label, owner_user_id
       V2: account_key, display_name, user_pk(INT), idle_enabled
    */
    try {
        $st = $db->query("
            INSERT INTO Tb_IntProviderAccount
                (service_code, tenant_id, provider, account_key, display_name, is_primary, status, raw_json, created_at, updated_at)
            SELECT
                s.service_code, s.tenant_id, s.provider,
                ISNULL(s.provider_user_key, ''),
                ISNULL(s.account_label, ''),
                s.is_primary, s.status, s.raw_json, s.created_at, s.updated_at
            FROM teniq_db.dbo.Tb_IotProviderAccount s
            WHERE NOT EXISTS (
                SELECT 1 FROM Tb_IntProviderAccount t
                WHERE t.service_code = s.service_code
                  AND t.tenant_id = s.tenant_id
                  AND t.provider = s.provider
                  AND t.account_key = ISNULL(s.provider_user_key, '')
            )
        ");
        $results[] = 'ACCOUNTS migrated: ' . $st->rowCount();
    } catch (Throwable $e) {
        $results[] = 'ACCOUNTS error: ' . $e->getMessage();
    }

    /* ── 2) Device: V1 → V2 ──
       V1: external_id, device_name, device_label, device_type, capabilities_json, is_deleted
       V2: external_id, device_label, capability_json (device_name/device_type 없음, is_deleted 없음)
    */
    try {
        $st = $db->query("
            INSERT INTO Tb_IntDevice
                (service_code, tenant_id, provider, provider_account_idx, external_id, device_label,
                 location_id, location_name, room_id, room_name,
                 capability_json, last_state, health_state, is_active, last_sync_at,
                 created_at, updated_at)
            SELECT
                s.service_code, s.tenant_id, s.provider, ISNULL(s.provider_account_idx, 0),
                s.external_id, ISNULL(s.device_label, s.device_name),
                ISNULL(s.location_id, ''), ISNULL(s.location_name, ''),
                ISNULL(s.room_id, ''), ISNULL(s.room_name, ''),
                ISNULL(s.capabilities_json, ''), ISNULL(s.last_state, ''), ISNULL(s.health_state, ''),
                s.is_active, s.last_sync_at,
                s.created_at, s.updated_at
            FROM teniq_db.dbo.Tb_IotDevice s
            WHERE ISNULL(s.is_deleted, 0) = 0
              AND NOT EXISTS (
                SELECT 1 FROM Tb_IntDevice t
                WHERE t.service_code = s.service_code
                  AND t.tenant_id = s.tenant_id
                  AND t.external_id = s.external_id
            )
        ");
        $results[] = 'DEVICES migrated: ' . $st->rowCount();
    } catch (Throwable $e) {
        $results[] = 'DEVICES error: ' . $e->getMessage();
    }

    /* ── 3) SyncLog → SyncCheckpoint ──
       V1: sync_type, total_count, fail_count, message
       V2: sync_scope, cursor_value, status, last_error
    */
    try {
        $st = $db->query("
            INSERT INTO Tb_IntSyncCheckpoint
                (service_code, tenant_id, provider_account_idx, sync_scope, cursor_value,
                 status, last_success_at, last_error, updated_at)
            SELECT
                s.service_code, s.tenant_id, ISNULL(s.provider_account_idx, 0),
                ISNULL(s.sync_type, 'device_sync'),
                CAST(s.total_count AS NVARCHAR(100)),
                CASE WHEN s.fail_count = 0 THEN 'OK' ELSE 'ERROR' END,
                s.created_at,
                CASE WHEN s.fail_count > 0 THEN LEFT(s.message, 500) ELSE '' END,
                s.created_at
            FROM teniq_db.dbo.Tb_IotSyncLog s
            WHERE NOT EXISTS (
                SELECT 1 FROM Tb_IntSyncCheckpoint t
                WHERE t.service_code = s.service_code
                  AND t.tenant_id = s.tenant_id
                  AND t.provider_account_idx = ISNULL(s.provider_account_idx, 0)
                  AND t.sync_scope = ISNULL(s.sync_type, 'device_sync')
                  AND t.updated_at = s.created_at
            )
        ");
        $results[] = 'SYNC_LOGS migrated: ' . $st->rowCount();
    } catch (Throwable $e) {
        $results[] = 'SYNC_LOGS error: ' . $e->getMessage();
    }

    /* ── 4) 검증 ── */
    $counts = [];
    foreach (['Tb_IntProviderAccount','Tb_IntDevice','Tb_IntSyncCheckpoint'] as $t) {
        try { $st = $db->query("SELECT COUNT(*) FROM {$t}"); $counts[$t] = (int)$st->fetchColumn(); } catch (Throwable $e) { $counts[$t] = -1; }
    }

    echo json_encode(['ok' => true, 'results' => $results, 'counts' => $counts, 'migrated' => true], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'results' => $results], JSON_UNESCAPED_UNICODE);
}
