<?php
declare(strict_types=1);
if (($_GET['tk'] ?? '') !== 'shvq_mig_20260413') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/env.php';
$dsn = "sqlsrv:Server=".shvEnv('DB_HOST').",".shvEnv('DB_PORT').";Database=CSM_C004732_V2;Encrypt=0;TrustServerCertificate=1";
$pdo = new PDO($dsn, shvEnv('DB_USER'), shvEnv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$out = [];

/* Tb_IntProviderAccount 컬럼 */
$out['v2_columns'] = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='Tb_IntProviderAccount' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);

/* V1 Tb_Mail_Accounts 컬럼 */
try {
    $out['v1_columns'] = $pdo->query("SELECT COLUMN_NAME FROM [CSM_C004732].INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='Tb_Mail_Accounts' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $out['v1_columns'] = $e->getMessage(); }

/* 이경노 사용자 정보 */
try {
    $out['user_kyungno'] = $pdo->query("SELECT TOP 3 idx, login_id, name FROM dbo.Tb_Users WHERE login_id LIKE '%no1%' OR name LIKE '%이경노%'")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $out['user_kyungno'] = $e->getMessage(); }

/* V1 Tb_Mail_Accounts 샘플 - user 컬럼 확인 */
try {
    $out['v1_sample'] = $pdo->query("SELECT TOP 3 * FROM [CSM_C004732].dbo.Tb_Mail_Accounts")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $out['v1_sample'] = $e->getMessage(); }

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
