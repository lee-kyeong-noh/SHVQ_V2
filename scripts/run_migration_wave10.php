<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
header('Content-Type: application/json; charset=utf-8');
if (trim((string)($_GET['token'] ?? '')) !== 'shvq_migrate_wave10') { http_response_code(403); echo '{"ok":false}'; exit; }

$db = DbConnection::get();
$results = [];

try {
    /* ── 1) Tb_IntDevice 누락 컬럼 추가 ── */
    $addCol = function(string $col, string $def) use ($db, &$results) {
        try {
            $st = $db->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='Tb_IntDevice' AND COLUMN_NAME=?");
            $st->execute([$col]);
            if ((int)$st->fetchColumn() === 0) {
                $db->exec("ALTER TABLE Tb_IntDevice ADD [{$col}] {$def}");
                $results[] = "ADDED: {$col}";
            } else {
                $results[] = "EXISTS: {$col}";
            }
        } catch (Throwable $e) { $results[] = "COL_ERR {$col}: " . $e->getMessage(); }
    };

    $addCol('device_type',      "VARCHAR(50) NOT NULL DEFAULT 'device'");
    $addCol('device_name',      "NVARCHAR(200) NOT NULL DEFAULT ''");
    $addCol('adapter',          "VARCHAR(50) NOT NULL DEFAULT 'smartthings'");
    $addCol('is_deleted',       "BIT NOT NULL DEFAULT 0");
    $addCol('manufacturer',     "NVARCHAR(100) NOT NULL DEFAULT ''");
    $addCol('model',            "NVARCHAR(100) NOT NULL DEFAULT ''");
    $addCol('firmware_version', "NVARCHAR(100) NOT NULL DEFAULT ''");
    $addCol('last_event_at',    "DATETIME NULL");

    /* ── 2) V1 teniq_db → V2 device_type/device_name/adapter UPDATE ── */
    $st = $db->query("SELECT CASE WHEN DB_ID('teniq_db') IS NULL THEN 0 ELSE 1 END");
    if ((int)$st->fetchColumn() === 1) {
        /* V1 컬럼 존재 확인 헬퍼 */
        $v1col = function(string $col) use ($db): bool {
            $st = $db->prepare("SELECT COUNT(1) FROM teniq_db.INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='Tb_IotDevice' AND COLUMN_NAME=?");
            $st->execute([$col]);
            return (int)$st->fetchColumn() > 0;
        };

        $hasType = $v1col('device_type');
        $hasName = $v1col('device_name');
        $hasAdapter = $v1col('adapter');
        $hasCaps = $v1col('capabilities_json');

        /* device_type UPDATE */
        if ($hasType) {
            try {
                $st = $db->query("
                    UPDATE t SET t.device_type = s.device_type
                    FROM Tb_IntDevice t
                    INNER JOIN teniq_db.dbo.Tb_IotDevice s
                        ON t.service_code = s.service_code AND t.tenant_id = s.tenant_id AND t.external_id = s.external_id
                    WHERE (t.device_type = 'device' OR t.device_type = 'switch' OR t.device_type = '')
                      AND ISNULL(s.device_type, '') <> ''
                ");
                $results[] = 'DEVICE_TYPE updated: ' . $st->rowCount();
            } catch (Throwable $e) { $results[] = 'DEVICE_TYPE err: ' . $e->getMessage(); }
        } else {
            $results[] = 'SKIP: V1 device_type column not found';
        }

        /* device_name UPDATE */
        if ($hasName) {
            try {
                $st = $db->query("
                    UPDATE t SET t.device_name = s.device_name
                    FROM Tb_IntDevice t
                    INNER JOIN teniq_db.dbo.Tb_IotDevice s
                        ON t.service_code = s.service_code AND t.tenant_id = s.tenant_id AND t.external_id = s.external_id
                    WHERE (t.device_name = '' OR t.device_name IS NULL)
                      AND ISNULL(s.device_name, '') <> ''
                ");
                $results[] = 'DEVICE_NAME updated: ' . $st->rowCount();
            } catch (Throwable $e) { $results[] = 'DEVICE_NAME err: ' . $e->getMessage(); }
        }

        /* adapter UPDATE */
        if ($hasAdapter) {
            try {
                $st = $db->query("
                    UPDATE t SET t.adapter = s.adapter
                    FROM Tb_IntDevice t
                    INNER JOIN teniq_db.dbo.Tb_IotDevice s
                        ON t.service_code = s.service_code AND t.tenant_id = s.tenant_id AND t.external_id = s.external_id
                    WHERE (t.adapter = 'smartthings' OR t.adapter = '')
                      AND ISNULL(s.adapter, '') <> ''
                ");
                $results[] = 'ADAPTER updated: ' . $st->rowCount();
            } catch (Throwable $e) { $results[] = 'ADAPTER err: ' . $e->getMessage(); }
        }

        /* capability_json UPDATE */
        if ($hasCaps) {
            try {
                $capCol = 'capability_json';
                $stCheck = $db->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='Tb_IntDevice' AND COLUMN_NAME=?");
                $stCheck->execute([$capCol]);
                if ((int)$stCheck->fetchColumn() === 0) $capCol = 'raw_json';

                $st = $db->query("
                    UPDATE t SET t.[{$capCol}] = s.capabilities_json
                    FROM Tb_IntDevice t
                    INNER JOIN teniq_db.dbo.Tb_IotDevice s
                        ON t.service_code = s.service_code AND t.tenant_id = s.tenant_id AND t.external_id = s.external_id
                    WHERE (t.[{$capCol}] IS NULL OR t.[{$capCol}] = '')
                      AND ISNULL(s.capabilities_json, '') <> ''
                ");
                $results[] = 'CAPABILITIES updated: ' . $st->rowCount();
            } catch (Throwable $e) { $results[] = 'CAPABILITIES err: ' . $e->getMessage(); }
        }
    } else {
        $results[] = 'SKIP: teniq_db not found';
    }

    /* ── 3) 검증: device_type 분포 ── */
    try {
        $st = $db->query("SELECT device_type, COUNT(*) AS cnt FROM Tb_IntDevice GROUP BY device_type ORDER BY cnt DESC");
        $results[] = 'TYPE_DIST: ' . json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { $results[] = 'TYPE_DIST err: ' . $e->getMessage(); }

    /* ── 4) 총 건수 ── */
    try { $st = $db->query("SELECT COUNT(*) FROM Tb_IntDevice"); $results[] = 'TOTAL: ' . $st->fetchColumn(); } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'results' => $results], JSON_UNESCAPED_UNICODE);
}
