<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
header('Content-Type: application/json; charset=utf-8');
if (trim((string)($_GET['token'] ?? '')) !== 'shvq_migrate_wave9b') { http_response_code(403); echo '{"ok":false}'; exit; }

$db = DbConnection::get();
$results = [];

try {
    /* ── 1) V2 Tb_IntDevice에 누락 컬럼 추가 ── */
    $addCol = function(string $col, string $def) use ($db, &$results) {
        try {
            $st = $db->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='Tb_IntDevice' AND COLUMN_NAME=?");
            $st->execute([$col]);
            if ((int)$st->fetchColumn() === 0) {
                $db->exec("ALTER TABLE Tb_IntDevice ADD {$col} {$def}");
                $results[] = "ADDED COLUMN: {$col}";
            } else {
                $results[] = "EXISTS COLUMN: {$col}";
            }
        } catch (Throwable $e) { $results[] = "COL ERROR {$col}: " . $e->getMessage(); }
    };

    $addCol('device_type',       "VARCHAR(50) NOT NULL DEFAULT 'switch'");
    $addCol('device_name',       "NVARCHAR(200) NOT NULL DEFAULT ''");
    $addCol('adapter',           "VARCHAR(50) NOT NULL DEFAULT 'smartthings'");
    $addCol('is_deleted',        "BIT NOT NULL DEFAULT 0");
    $addCol('manufacturer',      "NVARCHAR(100) NOT NULL DEFAULT ''");
    $addCol('model',             "NVARCHAR(100) NOT NULL DEFAULT ''");
    $addCol('firmware_version',  "NVARCHAR(100) NOT NULL DEFAULT ''");
    $addCol('last_event_at',     "DATETIME NULL");

    /* ── 2) V1 teniq_db → V2 device_type/device_name 업데이트 ── */
    $st = $db->query("SELECT CASE WHEN DB_ID('teniq_db') IS NULL THEN 0 ELSE 1 END");
    if ((int)$st->fetchColumn() === 1) {
        /* V1에서 device_type, device_name, adapter 가져와서 V2에 UPDATE */
        try {
            $st = $db->query("
                UPDATE t SET
                    t.device_type = ISNULL(s.device_type, 'switch'),
                    t.device_name = ISNULL(s.device_name, ''),
                    t.adapter = ISNULL(s.adapter, 'smartthings')
                FROM Tb_IntDevice t
                INNER JOIN teniq_db.dbo.Tb_IotDevice s
                    ON t.service_code = s.service_code
                    AND t.tenant_id = s.tenant_id
                    AND t.external_id = s.external_id
                WHERE t.device_type = 'switch' OR t.device_type = ''
            ");
            $results[] = 'DEVICE_TYPE updated: ' . $st->rowCount();
        } catch (Throwable $e) {
            $results[] = 'DEVICE_TYPE update error: ' . $e->getMessage();
        }

        /* capabilities_json → capability_json 이관 (비어있으면) */
        try {
            $st = $db->query("
                UPDATE t SET t.capability_json = s.capabilities_json
                FROM Tb_IntDevice t
                INNER JOIN teniq_db.dbo.Tb_IotDevice s
                    ON t.service_code = s.service_code
                    AND t.tenant_id = s.tenant_id
                    AND t.external_id = s.external_id
                WHERE (t.capability_json IS NULL OR t.capability_json = '')
                  AND ISNULL(s.capabilities_json, '') <> ''
            ");
            $results[] = 'CAPABILITIES updated: ' . $st->rowCount();
        } catch (Throwable $e) {
            $results[] = 'CAPABILITIES error: ' . $e->getMessage();
        }
    } else {
        $results[] = 'SKIP: teniq_db not found';
    }

    /* ── 3) 검증: device_type 분포 ── */
    try {
        $st = $db->query("SELECT device_type, COUNT(*) AS cnt FROM Tb_IntDevice GROUP BY device_type ORDER BY cnt DESC");
        $typeCounts = $st->fetchAll(PDO::FETCH_ASSOC);
        $results[] = 'TYPE_DIST: ' . json_encode($typeCounts);
    } catch (Throwable $e) { $results[] = 'TYPE_DIST error: ' . $e->getMessage(); }

    /* ── 4) 총 건수 ── */
    try {
        $st = $db->query("SELECT COUNT(*) FROM Tb_IntDevice");
        $results[] = 'TOTAL_DEVICES: ' . $st->fetchColumn();
    } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'results' => $results], JSON_UNESCAPED_UNICODE);
}
