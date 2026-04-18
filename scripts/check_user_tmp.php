<?php
declare(strict_types=1);
if (($_GET['tk'] ?? '') !== 'shvq_mig_20260413') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/env.php';
$dsn = "sqlsrv:Server=".shvEnv('DB_HOST').",".shvEnv('DB_PORT').";Database=CSM_C004732_V2;Encrypt=0;TrustServerCertificate=1";
$pdo = new PDO($dsn, shvEnv('DB_USER'), shvEnv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/* Tb_Users 컬럼 확인 */
$cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='Tb_Users' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);

/* 이경노 레코드 전체 */
$levelCol = shvEnv('AUTH_USER_LEVEL_COLUMN', 'authority_idx');
$users = $pdo->query("SELECT TOP 5 * FROM dbo.Tb_Users ORDER BY idx DESC")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['columns' => $cols, 'users' => $users, 'level_col' => $levelCol], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
