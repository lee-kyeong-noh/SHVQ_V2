<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
header('Content-Type: application/json; charset=utf-8');
if (trim((string)($_GET['token'] ?? '')) !== 'shvq_migrate_wave11') { http_response_code(403); echo '{"ok":false}'; exit; }

$db = DbConnection::get();
$results = [];

try {
    /* ── 1) Tb_IntDevice 컬럼 추가 ── */
    $addCol = function(string $tbl, string $col, string $def) use ($db, &$results) {
        try {
            $st = $db->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=? AND COLUMN_NAME=?");
            $st->execute([$tbl, $col]);
            if ((int)$st->fetchColumn() === 0) {
                $db->exec("ALTER TABLE [{$tbl}] ADD [{$col}] {$def}");
                $results[] = "ADDED {$tbl}.{$col}";
            } else {
                $results[] = "EXISTS {$tbl}.{$col}";
            }
        } catch (Throwable $e) { $results[] = "COL_ERR {$tbl}.{$col}: " . $e->getMessage(); }
    };
    $addCol('Tb_IntDevice', 'sort_order', 'INT NOT NULL DEFAULT 9999');
    $addCol('Tb_IntDevice', 'is_hidden', 'BIT NOT NULL DEFAULT 0');

    /* ── 2) 신규 테이블 생성 ── */
    $createTable = function(string $name, string $ddl) use ($db, &$results) {
        try {
            $st = $db->prepare("SELECT CASE WHEN OBJECT_ID(:t,'U') IS NULL THEN 0 ELSE 1 END");
            $st->execute([':t' => $name]);
            if ((int)$st->fetchColumn() === 0) {
                $db->exec($ddl);
                $results[] = "CREATED: {$name}";
            } else {
                $results[] = "EXISTS: {$name}";
            }
        } catch (Throwable $e) { $results[] = "TBL_ERR {$name}: " . $e->getMessage(); }
    };

    $createTable('Tb_IntCommandLog', "CREATE TABLE dbo.Tb_IntCommandLog (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id INT NOT NULL DEFAULT 0,
        device_idx INT NOT NULL DEFAULT 0,
        command VARCHAR(50) NOT NULL DEFAULT '',
        trigger_type VARCHAR(30) NOT NULL DEFAULT 'manual',
        request_json NVARCHAR(MAX) NOT NULL DEFAULT '',
        response_json NVARCHAR(MAX) NOT NULL DEFAULT '',
        result VARCHAR(20) NOT NULL DEFAULT 'unknown',
        error_message NVARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT GETDATE()
    )");

    $createTable('Tb_IntEventLog', "CREATE TABLE dbo.Tb_IntEventLog (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id INT NOT NULL DEFAULT 0,
        provider VARCHAR(30) NOT NULL DEFAULT 'smartthings',
        device_external_id NVARCHAR(120) NOT NULL DEFAULT '',
        capability NVARCHAR(120) NOT NULL DEFAULT '',
        attribute NVARCHAR(120) NOT NULL DEFAULT '',
        event_value NVARCHAR(500) NOT NULL DEFAULT '',
        event_timestamp DATETIME NULL,
        device_label NVARCHAR(200) NOT NULL DEFAULT '',
        location_id NVARCHAR(120) NOT NULL DEFAULT '',
        room_id NVARCHAR(120) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT GETDATE()
    )");

    $createTable('Tb_IntLockCredentialMap', "CREATE TABLE dbo.Tb_IntLockCredentialMap (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id INT NOT NULL DEFAULT 0,
        device_idx INT NOT NULL DEFAULT 0,
        credential_id NVARCHAR(120) NOT NULL DEFAULT '',
        auth_type VARCHAR(30) NOT NULL DEFAULT '',
        label NVARCHAR(200) NOT NULL DEFAULT '',
        employee_idx INT NOT NULL DEFAULT 0,
        employee_name NVARCHAR(100) NOT NULL DEFAULT '',
        notes NVARCHAR(500) NOT NULL DEFAULT '',
        is_deleted BIT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    )");

    $createTable('Tb_IntDoorlockTempPassword', "CREATE TABLE dbo.Tb_IntDoorlockTempPassword (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id INT NOT NULL DEFAULT 0,
        device_idx INT NOT NULL DEFAULT 0,
        password_id NVARCHAR(120) NOT NULL DEFAULT '',
        name NVARCHAR(200) NOT NULL DEFAULT '',
        type VARCHAR(30) NOT NULL DEFAULT 'temporary',
        effective_time NVARCHAR(50) NOT NULL DEFAULT '',
        invalid_time NVARCHAR(50) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    )");

    /* ── 3) V1 이벤트 로그 이관 ── */
    $st = $db->query("SELECT CASE WHEN DB_ID('teniq_db') IS NULL THEN 0 ELSE 1 END");
    if ((int)$st->fetchColumn() === 1) {
        $st = $db->query("SELECT CASE WHEN OBJECT_ID('teniq_db.dbo.Tb_IotEventLog','U') IS NULL THEN 0 ELSE 1 END");
        if ((int)$st->fetchColumn() === 1) {
            try {
                $st = $db->query("
                    INSERT INTO Tb_IntEventLog (service_code, tenant_id, provider, device_external_id, capability, attribute, event_value, event_timestamp, device_label, created_at)
                    SELECT TOP 5000 service_code, tenant_id, provider, device_external_id,
                           capability, attribute, event_value, event_timestamp,
                           ISNULL(device_label,''), created_at
                    FROM teniq_db.dbo.Tb_IotEventLog
                    WHERE NOT EXISTS (SELECT 1 FROM Tb_IntEventLog t WHERE t.service_code = teniq_db.dbo.Tb_IotEventLog.service_code AND t.created_at = teniq_db.dbo.Tb_IotEventLog.created_at AND t.device_external_id = teniq_db.dbo.Tb_IotEventLog.device_external_id)
                    ORDER BY created_at DESC
                ");
                $results[] = 'EVENTS migrated: ' . $st->rowCount();
            } catch (Throwable $e) { $results[] = 'EVENTS err: ' . $e->getMessage(); }
        } else {
            $results[] = 'SKIP: Tb_IotEventLog not found in teniq_db';
        }

        /* 도어락 매핑 이관 */
        $st = $db->query("SELECT CASE WHEN OBJECT_ID('teniq_db.dbo.Tb_IotLockCredentialMap','U') IS NULL THEN 0 ELSE 1 END");
        if ((int)$st->fetchColumn() === 1) {
            try {
                $st = $db->query("
                    INSERT INTO Tb_IntLockCredentialMap (service_code, tenant_id, device_idx, credential_id, auth_type, label, employee_name, created_at)
                    SELECT service_code, tenant_id, device_idx, credential_id, auth_type, label, ISNULL(employee_name,''), created_at
                    FROM teniq_db.dbo.Tb_IotLockCredentialMap
                    WHERE NOT EXISTS (SELECT 1 FROM Tb_IntLockCredentialMap t WHERE t.credential_id = teniq_db.dbo.Tb_IotLockCredentialMap.credential_id AND t.device_idx = teniq_db.dbo.Tb_IotLockCredentialMap.device_idx)
                ");
                $results[] = 'LOCK_MAP migrated: ' . $st->rowCount();
            } catch (Throwable $e) { $results[] = 'LOCK_MAP err: ' . $e->getMessage(); }
        }
    }

    /* ── 4) 검증 ── */
    $counts = [];
    foreach (['Tb_IntCommandLog','Tb_IntEventLog','Tb_IntLockCredentialMap','Tb_IntDoorlockTempPassword'] as $t) {
        try { $st = $db->query("SELECT COUNT(*) FROM {$t}"); $counts[$t] = (int)$st->fetchColumn(); } catch (Throwable $e) { $counts[$t] = -1; }
    }
    $results[] = 'COUNTS: ' . json_encode($counts);

    echo json_encode(['ok' => true, 'results' => $results, 'counts' => $counts], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'results' => $results], JSON_UNESCAPED_UNICODE);
}
