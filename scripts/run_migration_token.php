<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
header('Content-Type: application/json; charset=utf-8');
if (trim((string)($_GET['token'] ?? '')) !== 'shvq_migrate_token') { http_response_code(403); echo '{"ok":false}'; exit; }

$db = DbConnection::get();
$results = [];

try {
    /* ── 1) V2 Tb_IntProviderToken 테이블 생성 ── */
    $st = $db->prepare("SELECT CASE WHEN OBJECT_ID(:t,'U') IS NULL THEN 0 ELSE 1 END");
    $st->execute([':t' => 'Tb_IntProviderToken']);
    if ((int)$st->fetchColumn() === 0) {
        $db->exec("CREATE TABLE dbo.Tb_IntProviderToken (
            idx INT IDENTITY(1,1) PRIMARY KEY,
            service_code VARCHAR(30) NOT NULL DEFAULT 'shvq',
            tenant_id INT NOT NULL DEFAULT 0,
            provider VARCHAR(30) NOT NULL DEFAULT 'smartthings',
            provider_account_idx INT NULL,
            access_token NVARCHAR(MAX) NOT NULL DEFAULT '',
            refresh_token NVARCHAR(MAX) NOT NULL DEFAULT '',
            token_hint NVARCHAR(200) NOT NULL DEFAULT '',
            scope NVARCHAR(255) NOT NULL DEFAULT '',
            expires_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            created_by NVARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT GETDATE(),
            updated_at DATETIME NOT NULL DEFAULT GETDATE()
        )");
        $results[] = 'CREATED: Tb_IntProviderToken';
    } else {
        $results[] = 'EXISTS: Tb_IntProviderToken';
    }

    /* ── 2) V1 teniq_db 확인 ── */
    $st = $db->query("SELECT CASE WHEN DB_ID('teniq_db') IS NULL THEN 0 ELSE 1 END");
    if ((int)$st->fetchColumn() !== 1) {
        echo json_encode(['ok' => true, 'results' => array_merge($results, ['SKIP: teniq_db not found'])]);
        exit;
    }

    /* V1 Tb_IotProviderToken 확인 */
    $st = $db->query("SELECT CASE WHEN OBJECT_ID('teniq_db.dbo.Tb_IotProviderToken','U') IS NULL THEN 0 ELSE 1 END");
    if ((int)$st->fetchColumn() !== 1) {
        echo json_encode(['ok' => true, 'results' => array_merge($results, ['SKIP: Tb_IotProviderToken not found in teniq_db'])]);
        exit;
    }

    /* V1 컬럼 확인 헬퍼 */
    $v1col = function(string $col) use ($db): bool {
        $st = $db->prepare("SELECT COUNT(1) FROM teniq_db.INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='Tb_IotProviderToken' AND COLUMN_NAME=?");
        $st->execute([$col]);
        return (int)$st->fetchColumn() > 0;
    };

    /* V1 토큰 건수 확인 */
    $st = $db->query("SELECT COUNT(*) FROM teniq_db.dbo.Tb_IotProviderToken WHERE status='ACTIVE'");
    $v1Count = (int)$st->fetchColumn();
    $results[] = 'V1 active tokens: ' . $v1Count;

    /* ── 3) 이관 ── */
    $paIdx = $v1col('provider_account_idx') ? 's.provider_account_idx' : 'NULL';
    $tokenHint = $v1col('token_hint') ? 's.token_hint' : "''";
    $scope = $v1col('scope') ? 's.scope' : "''";
    $expiresAt = $v1col('expires_at') ? 's.expires_at' : 'NULL';
    $createdBy = $v1col('created_by') ? 's.created_by' : "''";
    $refreshToken = $v1col('refresh_token') ? 's.refresh_token' : "''";

    try {
        $st = $db->query("
            INSERT INTO Tb_IntProviderToken
                (service_code, tenant_id, provider, provider_account_idx,
                 access_token, refresh_token, token_hint, scope, expires_at,
                 status, created_by, created_at, updated_at)
            SELECT
                s.service_code, s.tenant_id, s.provider, {$paIdx},
                s.access_token, {$refreshToken}, {$tokenHint}, {$scope}, {$expiresAt},
                s.status, {$createdBy}, s.created_at, s.updated_at
            FROM teniq_db.dbo.Tb_IotProviderToken s
            WHERE s.status = 'ACTIVE'
              AND NOT EXISTS (
                SELECT 1 FROM Tb_IntProviderToken t
                WHERE t.service_code = s.service_code
                  AND t.tenant_id = s.tenant_id
                  AND t.provider = s.provider
                  AND t.access_token = s.access_token
              )
        ");
        $results[] = 'TOKENS migrated: ' . $st->rowCount();
    } catch (Throwable $e) {
        $results[] = 'TOKEN error: ' . $e->getMessage();
    }

    /* ── 4) 검증 ── */
    try {
        $st = $db->query("SELECT provider, status, COUNT(*) AS cnt FROM Tb_IntProviderToken GROUP BY provider, status");
        $results[] = 'TOKEN_DIST: ' . json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { $results[] = 'DIST error: ' . $e->getMessage(); }

    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'results' => $results], JSON_UNESCAPED_UNICODE);
}
